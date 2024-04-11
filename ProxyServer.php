<?php

declare(strict_types=1);

namespace libproxy;

use ErrorException;
use libproxy\protocol\AckPacket;
use libproxy\protocol\DisconnectPacket;
use libproxy\protocol\ForwardPacket;
use libproxy\protocol\ForwardReceiptPacket;
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
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketRateLimiter;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\Packet as BedrockPacket;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use pocketmine\network\PacketHandlingException;
use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\AttachableThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use Socket;
use function array_keys;
use function base64_encode;
use function bin2hex;
use function chr;
use function getenv;
use function ord;
use function socket_read;
use function strlen;
use function substr;
use function zstd_uncompress;

class ProxyServer
{
    /** @var PthreadsChannelReader */
    private PthreadsChannelReader $mainToThreadReader;
    /** @var SnoozeAwarePthreadsChannelWriter */
    private SnoozeAwarePthreadsChannelWriter $threadToMainWriter;
    private QuicheServerSocket $serverSocket;

    /** @var SleeperHandler */
    private SleeperHandler $sleeperHandler;

    /** @var array<int, QueueWriter> */
    private array $streamWriters = [];
    /** @var array<int, BiDirectionalQuicheStream> */
    private array $streams = [];

    /** @phpstan-var array<int, PacketRateLimiter> */
    private array $gamePacketLimiter = [];
    /** @phpstan-var array<int, PacketRateLimiter> */
    private array $batchPacketLimiter = [];
    /** @phpstan-var array<int, int> */
    private array $protocolId = [];

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
                $this->streams[$streamIdentifier] = $stream;

                $pk = new LoginPacket();
                $pk->ip = $peerAddress->getAddress();
                $pk->port = $peerAddress->getPort();
                $this->sendToMainBuffer($streamIdentifier, $pk);

                $stream->addShutdownReadingCallback(function (bool $peerClosed) use ($streamIdentifier): void {
                    if ($peerClosed) {
                        if (isset($this->streamWriters[$streamIdentifier])) { // check if the stream is still open
                            $this->shutdownStream($streamIdentifier, 'client disconnect', false);
                        }
                    } else {
                        $this->onStreamShutdown($streamIdentifier);
                    }
                });

                $stream->setOnDataArrival(function (string $data) use ($streamIdentifier): void {
                    if (isset($this->streams[$streamIdentifier])) {
                        $this->onDataReceive($streamIdentifier, $data);
                    }
                });
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
        $serverConfig->setEnableActiveMigration(false);
        $serverConfig->discoverPMTUD(true);
        $serverConfig->setMaxRecvUdpPayloadSize(1350);
        $serverConfig->setMaxSendUdpPayloadSize(1350);

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
        unset(
            $this->streamWriters[$streamIdentifier],
            $this->streams[$streamIdentifier],
            $this->gamePacketLimiter[$streamIdentifier],
            $this->batchPacketLimiter[$streamIdentifier],
            $this->protocolId[$streamIdentifier]
        );
    }

    public function getTickSleeper(): SleeperHandler
    {
        return $this->sleeperHandler;
    }

    private function getStreamWriter(int $streamIdentifier): ?QueueWriter
    {
        return $this->streamWriters[$streamIdentifier] ?? null;
    }

    private function getGamePacketLimiter(int $streamIdentifier): PacketRateLimiter
    {
        return $this->gamePacketLimiter[$streamIdentifier] ??= new PacketRateLimiter("Game Packets", 2, 100);
    }

    private function getBatchPacketLimiter(int $streamIdentifier): PacketRateLimiter
    {
        return $this->batchPacketLimiter[$streamIdentifier] ??= new PacketRateLimiter("Batch Packets", 2, 100);
    }

    private function shutdownStream(int $streamIdentifier, string $reason, bool $fromMain): void
    {
        if (!$fromMain) {
            $pk = new DisconnectPacket();
            $pk->reason = $reason;

            $this->sendToMainBuffer($streamIdentifier, $pk);
        }

        if (($stream = $this->streams[$streamIdentifier] ?? null) !== null) {
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
                    $this->sendPayload($streamIdentifier, $pk->payload);
                    break;
                case ForwardReceiptPacket::NETWORK_ID:
                    /** @var ForwardReceiptPacket $pk */
                    $this->sendPayloadWithReceipt($streamIdentifier, $pk->payload, $pk->receiptId);
                    break;
            }
        }
    }

    private function sendPayloadWithReceipt(int $streamIdentifier, string $payload, int $receiptId): void
    {
        if (($writer = $this->getStreamWriter($streamIdentifier)) === null) {
            $this->shutdownStream($streamIdentifier, 'stream not found', false);
            return;
        }

        $writer->writeWithPromise(Binary::writeInt(strlen($payload)) . $payload)->onResult(function() use ($streamIdentifier, $receiptId): void{
            $pk = new AckPacket();
            $pk->receiptId = $receiptId;

            $this->sendToMainBuffer($streamIdentifier, $pk);
        });
    }

    /**
     * Sends a payload to the client
     */
    private function sendPayload(int $streamIdentifier, string $payload): void
    {
        if (($writer = $this->getStreamWriter($streamIdentifier)) === null) {
            $this->shutdownStream($streamIdentifier, 'stream not found', false);
            return;
        }

        $writer->write(Binary::writeInt(strlen($payload)) . $payload);
    }

    /**
     * Sends a data packet to the main thread.
     */
    private function sendDataPacketToMain(int $streamIdentifier, string $payload): void
    {
        $pk = new ForwardPacket();
        $pk->payload = $payload;

        $this->sendToMainBuffer($streamIdentifier, $pk);
    }

    /**
     * Returns the protocol ID for the given socket identifier.
     */
    private function getProtocolId(int $streamIdentifier): int
    {
        return $this->protocolId[$streamIdentifier] ?? ProtocolInfo::CURRENT_PROTOCOL;
    }

    /**
     * Sends a data packet to the client using a single packet in a batch.
     */
    private function sendDataPacket(int $streamIdentifier, BedrockPacket $packet): void
    {
        $packetSerializer = PacketSerializer::encoder($protocolId = $this->getProtocolId($streamIdentifier));
        $packet->encode($packetSerializer);

        $stream = new BinaryStream();
        PacketBatch::encodeRaw($stream, [$packetSerializer->getBuffer()]);
        $payload = ($protocolId >= ProtocolInfo::PROTOCOL_1_20_60 ? chr(CompressionAlgorithm::ZLIB) : '') . ZlibCompressor::getInstance()->compress($stream->getBuffer());

        $this->sendPayload($streamIdentifier, $payload);
    }

    private function decodePacket(int $streamIdentifier, BedrockPacket $packet, string $buffer): void
    {
        $stream = PacketSerializer::decoder($this->protocolId[$streamIdentifier] ?? ProtocolInfo::CURRENT_PROTOCOL, $buffer, 0);
        try {
            $packet->decode($stream);
        } catch (PacketDecodeException $e) {
            throw PacketHandlingException::wrap($e);
        }
        if (!$stream->feof()) {
            $remains = substr($stream->getBuffer(), $stream->getOffset());
            $this->logger->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": " . bin2hex($remains));
        }
    }

    /**
     * Returns true if the packet was handled successfully, false if it should be sent to the main thread.
     *
     * @return bool whether the packet was handled successfully
     */
    private function handleDataPacket(int $streamIdentifier, BedrockPacket $packet, string $buffer): bool
    {
        if ($packet->pid() == NetworkStackLatencyPacket::NETWORK_ID) {
            /** @var NetworkStackLatencyPacket $packet USED FOR PING CALCULATIONS */
            $this->decodePacket($streamIdentifier, $packet, $buffer);

            if ($packet->timestamp === 0 && $packet->needResponse) {
                try {
                    $this->sendDataPacket($streamIdentifier, NetworkStackLatencyPacket::response(0));
                } catch (PacketHandlingException $e) {
                    // ignore, client probably disconnected
                }
                return true;
            }
        } else if ($packet->pid() === RequestNetworkSettingsPacket::NETWORK_ID) {
            /** @var RequestNetworkSettingsPacket $packet USED TO GET PROTOCOLID */
            $this->decodePacket($streamIdentifier, $packet, $buffer);

            $this->protocolId[$streamIdentifier] = $packet->getProtocolVersion();
        }

        return false;
    }

    /**
     * @param int $streamIdentifier
     * @param string $payload
     * @return void
     * @see NetworkSession::handleEncoded($payload)
     *
     */
    private function onFullDataReceive(int $streamIdentifier, string $payload): void
    {
        try {
            $this->getBatchPacketLimiter($streamIdentifier)->decrement();

            if (strlen($payload) < 1) {
                throw new PacketHandlingException("No bytes in payload");
            }

            $compressionType = ord($payload[0]);
            $compressed = substr($payload, 1);

            try {
                $decompressed = match ($compressionType) {
                    CompressionAlgorithm::NONE => $compressed,
                    CompressionAlgorithm::ZLIB => ZlibCompressor::getInstance()->decompress($compressed),
                    CompressionAlgorithm::NONE - 1 => ($d = zstd_uncompress($compressed)) === false ? throw new DecompressionException("Failed to decompress packet") : $d,
                    default => throw new PacketHandlingException("Packet compressed with unexpected compression type $compressionType")
                };
            } catch (ErrorException|DecompressionException $e) {
                $this->logger->debug("Failed to decompress packet: " . base64_encode($compressed));
                throw PacketHandlingException::wrap($e, "Compressed packet batch decode error");
            }

            try {
                $stream = new BinaryStream($decompressed);
                $count = 0;
                foreach (PacketBatch::decodeRaw($stream) as $buffer) {
                    $this->getGamePacketLimiter($streamIdentifier)->decrement();
                    if (++$count > 100) {
                        throw new PacketHandlingException("Too many packets in batch");
                    }
                    $packet = PacketPool::getInstance()->getPacket($buffer);
                    if ($packet === null) {
                        $this->logger->debug("Unknown packet: " . base64_encode($buffer));
                        throw new PacketHandlingException("Unknown packet received");
                    }
                    try {
                        if (!$this->handleDataPacket($streamIdentifier, $packet, $buffer)) {
                            $this->sendDataPacketToMain($streamIdentifier, $buffer);
                        }
                    } catch (PacketHandlingException $e) {
                        $this->logger->debug($packet->getName() . ": " . base64_encode($buffer));
                        throw PacketHandlingException::wrap($e, "Error processing " . $packet->getName());
                    }
                }
            } catch (PacketDecodeException|BinaryDataException $e) {
                $this->logger->logException($e);
                throw PacketHandlingException::wrap($e, "Packet batch decode error");
            }
        } catch (PacketHandlingException $e) {
            $this->logger->logException($e);
            $this->shutdownStream($streamIdentifier, "invalid packet", false);
        }
    }

    private function onDataReceive(int $streamIdentifier, string $data): void
    {
        if (isset($this->socketBuffer[$streamIdentifier])) {
            $this->socketBuffer[$streamIdentifier] .= $data;
        } else {
            $this->socketBuffer[$streamIdentifier] = $data;
        }

        while (true) {
            $buffer = $this->socketBuffer[$streamIdentifier];
            $length = strlen($buffer);
            $lengthNeeded = $this->socketBufferLengthNeeded[$streamIdentifier] ?? null;

            if ($lengthNeeded === null) {
                if ($length < 4) { // first 4 bytes are the length of the packet
                    return; // wait for more data
                } else {
                    try {
                        $packetLength = Binary::readInt(substr($buffer, 0, 4));
                    } catch (BinaryDataException $exception) {
                        $this->shutdownStream($streamIdentifier, 'invalid packet', false);
                        return;
                    }

                    $this->socketBufferLengthNeeded[$streamIdentifier] = $packetLength;
                    $this->socketBuffer[$streamIdentifier] = substr($buffer, 4);
                }
            } else if ($length >= $lengthNeeded) {
                $this->onFullDataReceive($streamIdentifier, substr($buffer, 0, $lengthNeeded));

                $this->socketBuffer[$streamIdentifier] = substr($buffer, $lengthNeeded);
                unset($this->socketBufferLengthNeeded[$streamIdentifier]);
            } else {
                return; // wait for more data
            }
        }
    }
}