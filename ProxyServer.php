<?php

declare(strict_types=1);

namespace libproxy;

use libproxy\protocol\DisconnectPacket;
use libproxy\protocol\ForwardPacket;
use libproxy\protocol\LoginPacket;
use libproxy\protocol\ProxyPacket;
use libproxy\protocol\ProxyPacketPool;
use libproxy\protocol\ProxyPacketSerializer;
use NetherGames\Quiche\io\QueueWriter;
use NetherGames\Quiche\QuicheConnection;
use NetherGames\Quiche\socket\QuicheServerSocket;
use NetherGames\Quiche\SocketAddress;
use NetherGames\Quiche\stream\BiDirectionalQuicheStream;
use NetherGames\Quiche\stream\QuicheStream;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use pocketmine\network\PacketHandlingException;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\AttachableThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use Socket;
use function array_keys;
use function getenv;
use function socket_read;
use function spl_object_id;
use function strlen;
use function substr;

class ProxyServer
{
    public const PING_SYNC_INTERVAL = 2;

    /** @var PthreadsChannelReader */
    private PthreadsChannelReader $mainToThreadReader;
    /** @var SnoozeAwarePthreadsChannelWriter */
    private SnoozeAwarePthreadsChannelWriter $threadToMainWriter;
    private QuicheServerSocket $serverSocket;

    /** @var SleeperHandler */
    private SleeperHandler $sleeperHandler;

    /** @var array<int, QueueWriter> */
    private array $streamWriters = [];
    /** @var array<int, int> */
    private array $connectionIdByStreamId = [];
    /** @var array<int, QuicheConnection> */
    private array $connections = [];
    /** @var array<int, BiDirectionalQuicheStream> */
    private array $streams = [];

    /** @phpstan-var array<int, string> */
    private array $socketBuffer = [];
    /** @phpstan-var array<int, int> */
    private array $socketBufferLengthNeeded = [];

    private int $streamIdentifier = 0;

    public function __construct(
        private readonly AttachableThreadSafeLogger $logger,
        SocketAddress                               $serverAddress,
        ThreadSafeArray                             $mainToThreadBuffer,
        ThreadSafeArray                             $threadToMainBuffer,
        SleeperHandlerEntry                         $sleeperEntry,
        Socket                                      $notifySocket
    )
    {
        $this->sleeperHandler = new SleeperHandler();
        $this->serverSocket = $this->createServerSocket($serverAddress, $notifySocket);

        $this->mainToThreadReader = new PthreadsChannelReader($mainToThreadBuffer);
        $this->threadToMainWriter = new SnoozeAwarePthreadsChannelWriter($threadToMainBuffer, $sleeperEntry->createNotifier());
    }

    private function createServerSocket(SocketAddress $socketAddress, Socket $notifySocket): QuicheServerSocket
    {
        $serverSocket = new QuicheServerSocket([$socketAddress], function (QuicheConnection $connection, ?QuicheStream $stream): void {
            if ($stream instanceof BiDirectionalQuicheStream) {
                $streamIdentifier = $this->streamIdentifier++;
                $peerAddress = $connection->getPeerAddress();

                $this->streamWriters[$streamIdentifier] = $stream->setupWriter();
                $this->connectionIdByStreamId[$streamIdentifier] = spl_object_id($connection);
                $this->streams[$streamIdentifier] = $stream;

                $pk = new LoginPacket();
                $pk->ip = $peerAddress->getAddress();
                $pk->port = $peerAddress->getPort();
                $this->sendToMainBuffer($streamIdentifier, $pk);

                $stream->setShutdownReadingCallback(function (bool $peerClosed) use ($streamIdentifier): void {
                    if ($peerClosed) {
                        if (isset($this->streamWriters[$streamIdentifier])) { // check if the stream is still open
                            $this->shutdownStream($streamIdentifier, 'client disconnect', false);
                        }
                    } else {
                        $this->onStreamShutdown($streamIdentifier);
                    }
                });

                $stream->setOnDataArrival(function (string $data) use ($streamIdentifier): void {
                    $this->onDataReceive($streamIdentifier, $data);
                });
            } else if ($stream === null) {
                $this->connections[$connectionId = spl_object_id($connection)] = $connection;

                $connection->setPeerCloseCallback(function () use ($connectionId): void {
                    unset($this->connections[$connectionId]);
                });
            } else {
                throw new \RuntimeException('Invalid stream type');
            }
        });

        $keyPath = getenv("KEY_PATH");
        $certPath = getenv("CERT_PATH");

        if ($keyPath === false || $certPath === false || !file_exists($keyPath) || !file_exists($certPath)) {
            throw new \RuntimeException("KEY_PATH or CERT_PATH not set");
        }

        $serverConfig = $serverSocket->getConfig();
        $serverConfig->loadPrivKeyFromFile($keyPath);
        $serverConfig->loadCertChainFromFile($certPath);
        $serverConfig->setApplicationProtos(['ng']);
        $serverConfig->setVerifyPeer(false);
        $serverConfig->enableBidirectionalStreams();
        $serverConfig->setInitialMaxData(10000000);
        $serverConfig->setMaxIdleTimeout(2000);

        $serverSocket->registerSocket($notifySocket, function () use ($notifySocket): void {
            socket_read($notifySocket, 65535); //clean socket
            $this->pushSockets();
        });

        return $serverSocket;
    }

    public function waitShutdown(): void
    {
        foreach (array_keys($this->streamWriters) as $streamIdentifier) {
            $this->shutdownStream($streamIdentifier, 'server shutdown', true);
        }

        $this->serverSocket->close(false, 0, 'server shutdown');
    }

    private function onStreamShutdown(int $streamIdentifier): void
    {
        unset($this->streamWriters[$streamIdentifier], $this->connectionIdByStreamId[$streamIdentifier], $this->streams[$streamIdentifier]);
    }

    public function getTickSleeper(): SleeperHandler
    {
        return $this->sleeperHandler;
    }

    private function getStreamWriter(int $streamIdentifier): ?QueueWriter
    {
        return $this->streamWriters[$streamIdentifier] ?? null;
    }

    private function shutdownStream(int $streamIdentifier, string $reason, bool $fromMain): void
    {
        if (!$fromMain) {
            $pk = new DisconnectPacket();
            $pk->reason = $reason;

            $this->sendToMainBuffer($streamIdentifier, $pk);
        }

        if (($stream = $this->streams[$streamIdentifier] ?? null) !== null) {
            if ($stream->isReadable()) {
                $stream->shutdownReading();
            }
            if ($stream->isWritable()) {
                $stream->gracefulShutdownWriting();
            }

            $this->onStreamShutdown($streamIdentifier);
        }
    }

    private function sendToMainBuffer(int $streamIdentifier, ProxyPacket $pk): void
    {
        $serializer = new ProxyPacketSerializer();
        $serializer->putLInt($streamIdentifier);

        $pk->encode($serializer);

        $this->threadToMainWriter->write($serializer->getBuffer());
    }

    public function tickProcessor(): void
    {
        $this->serverSocket->selectSockets(50);
    }

    private function pushSockets(): void
    {
        while (($payload = $this->mainToThreadReader->read()) !== null) {
            $stream = new ProxyPacketSerializer($payload);
            $streamIdentifier = $stream->getLInt();

            if (($pk = ProxyPacketPool::getInstance()->getPacket($payload, $stream->getOffset())) === null) {
                throw new PacketHandlingException('Packet does not exist');
            }

            try {
                $pk->decode($stream);
            } catch (BinaryDataException $e) {
                $this->logger->debug('Closed stream with id(' . $streamIdentifier . ') because server sent invalid packet');
                $this->shutdownStream($streamIdentifier, 'invalid packet', false);
                return;
            }

            switch ($pk->pid()) {
                case DisconnectPacket::NETWORK_ID:
                    /** @var DisconnectPacket $pk */
                    if ($this->getStreamWriter($streamIdentifier) !== null) {
                        $this->shutdownStream($streamIdentifier, $pk->reason, true);
                    }
                    break;
                case ForwardPacket::NETWORK_ID:
                    /** @var ForwardPacket $pk */
                    if (($writer = $this->getStreamWriter($streamIdentifier)) === null) {
                        $this->shutdownStream($streamIdentifier, 'stream not found', false);
                        return;
                    }

                    $writer->write(Binary::writeInt(strlen($pk->payload)) . $pk->payload);
                    break;
            }
        }
    }

    private function onDataReceive(int $socketIdentifier, string $data): void
    {
        if (isset($this->socketBuffer[$socketIdentifier])) {
            $this->socketBuffer[$socketIdentifier] .= $data;
        } else {
            $this->socketBuffer[$socketIdentifier] = $data;
        }

        while (true) {
            $buffer = $this->socketBuffer[$socketIdentifier];
            $length = strlen($buffer);
            $lengthNeeded = $this->socketBufferLengthNeeded[$socketIdentifier] ?? null;

            if ($lengthNeeded === null) {
                if ($length < 4) { // first 4 bytes are the length of the packet
                    return; // wait for more data
                } else {
                    try {
                        $packetLength = Binary::readInt(substr($buffer, 0, 4));
                    } catch (BinaryDataException $exception) {
                        $this->shutdownStream($socketIdentifier, 'invalid packet', false);
                        return;
                    }

                    $this->socketBufferLengthNeeded[$socketIdentifier] = $packetLength;
                    $this->socketBuffer[$socketIdentifier] = substr($buffer, 4);
                }
            } else if ($length >= $lengthNeeded) {
                $pk = new ForwardPacket();
                $pk->payload = substr($buffer, 0, $lengthNeeded);

                $this->sendToMainBuffer($socketIdentifier, $pk);

                $this->socketBuffer[$socketIdentifier] = substr($buffer, $lengthNeeded);
                unset($this->socketBufferLengthNeeded[$socketIdentifier]);
            } else {
                return; // wait for more data
            }
        }
    }
}