<?php

declare(strict_types=1);


namespace libproxy;

use ErrorException;
use libproxy\protocol\DisconnectPacket;
use libproxy\protocol\ForwardPacket;
use libproxy\protocol\LoginPacket;
use libproxy\protocol\ProxyPacket;
use libproxy\protocol\ProxyPacketPool;
use libproxy\protocol\ProxyPacketSerializer;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\network\PacketHandlingException;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\Thread;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use RuntimeException;
use Socket;
use Threaded;
use ThreadedLogger;
use Throwable;
use function array_keys;
use function error_get_last;
use function gc_collect_cycles;
use function gc_enable;
use function gc_mem_caches;
use function ini_set;
use function min;
use function register_shutdown_function;
use function socket_accept;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_getpeername;
use function socket_last_error;
use function socket_listen;
use function socket_read;
use function socket_recv;
use function socket_select;
use function socket_set_option;
use function socket_strerror;
use function socket_write;
use function strlen;
use function var_dump;
use function zstd_uncompress;
use const AF_INET;
use const MSG_WAITALL;
use const PTHREADS_INHERIT_NONE;
use const SO_RCVBUF;
use const SO_REUSEADDR;
use const SO_SNDBUF;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;

class ProxyServer extends Thread
{
    public const SERVER_SOCKET = -1;
    public const NOTIFY_SOCKET = -2;

    /** @var string|null */
    public ?string $crashInfo = null;
    /** @var ThreadedLogger */
    protected ThreadedLogger $logger;
    /** @var bool */
    protected bool $cleanShutdown = false;
    /** @var bool */
    protected bool $ready = false;
    /** @var PthreadsChannelReader */
    protected PthreadsChannelReader $mainToThreadReader;
    /** @var PthreadsChannelWriter */
    protected PthreadsChannelWriter $threadToMainWriter;
    /** @var string */
    private string $serverIp;
    /** @var int */
    private int $serverPort;
    /** @var bool */
    private bool $asyncDecompress;

    /** @var Socket */
    private Socket $serverSocket;
    /** @var Socket */
    private Socket $notifySocket;
    /** @var Socket[] */
    private array $sockets = [];

    /** @var int */
    private int $socketId = 0;

    public function __construct(string $serverIp, int $serverPort, ThreadedLogger $logger, Threaded $mainToThreadBuffer, Threaded $threadToMainBuffer, SleeperNotifier $notifier, Socket $notifySocket, bool $asyncDecompress)
    {
        $this->serverIp = $serverIp;
        $this->serverPort = $serverPort;
        $this->logger = $logger;
        $this->notifySocket = $notifySocket;
        $this->asyncDecompress = $asyncDecompress;

        $this->mainToThreadReader = new PthreadsChannelReader($mainToThreadBuffer);
        $this->threadToMainWriter = new PthreadsChannelWriter($threadToMainBuffer, $notifier);
    }

    /**
     * @return void
     */
    public function shutdownHandler(): void
    {
        if ($this->cleanShutdown !== true) {
            $error = error_get_last();

            if ($error !== null) {
                $this->logger->emergency('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
                $this->setCrashInfo($error['message']);
            } else {
                $this->logger->emergency('Proxy shutdown unexpectedly');
            }
        }
    }

    private function setCrashInfo(string $info): void
    {
        $this->synchronized(function (string $info): void {
            $this->crashInfo = $info;
            $this->notify();
        }, $info);
    }

    public function getCrashInfo(): ?string
    {
        return $this->crashInfo;
    }

    public function startAndWait(int $options = PTHREADS_INHERIT_NONE): void
    {
        $this->start($options);
        $this->synchronized(function (): void {
            while (!$this->ready and $this->crashInfo === null) {
                $this->wait();
            }
            if ($this->crashInfo !== null) {
                throw new RuntimeException("Proxy failed to start: $this->crashInfo");
            }
        });
    }

    protected function onRun(): void
    {
        try {
            gc_enable();
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');

            register_shutdown_function([$this, 'shutdownHandler']);

            $this->serverSocket = $this->createServerSocket();

            $this->synchronized(function (): void {
                $this->ready = true;
                $this->notify();
            });

            while (!$this->isKilled) {
                $this->tickProcessor();
                var_dump(gc_mem_caches());
            }

            $this->waitShutdown();
            $this->cleanShutdown = true;
        } catch (Throwable $e) {
            $this->setCrashInfo($e->getMessage());
            $this->logger->logException($e);
        }
    }

    private function createServerSocket(): Socket
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($serverSocket === false) {
            throw new RuntimeException("Failed to create socket: " . socket_strerror(socket_last_error()));
        }
        if (!socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            throw new RuntimeException("Failed to set option on socket: " . socket_strerror(socket_last_error($serverSocket)));
        }
        if (!socket_bind($serverSocket, $this->serverIp, $this->serverPort)) {
            throw new RuntimeException("Failed to bind to socket: " . socket_strerror(socket_last_error($serverSocket)));
        }
        if (!socket_listen($serverSocket, 10)) {
            throw new RuntimeException("Failed to listen to socket: " . socket_strerror(socket_last_error($serverSocket)));
        }
        if (!socket_set_option($serverSocket, SOL_SOCKET, SO_SNDBUF, 8 * 1024 * 1024) || !socket_set_option($serverSocket, SOL_SOCKET, SO_RCVBUF, 8 * 1024 * 1024)) {
            throw new RuntimeException("Failed to set option on socket: " . socket_strerror(socket_last_error($serverSocket)));
        }

        return $serverSocket;
    }

    private function tickProcessor(): void
    {
        $read = (array)$this->sockets;
        $read[self::SERVER_SOCKET] = $this->serverSocket;
        $read[self::NOTIFY_SOCKET] = $this->notifySocket;

        $write = null;
        $except = null;

        /** @phpstan-ignore-next-line */
        $select = socket_select($read, $write, $except, 5);
        if ($select !== false && $select > 0) {
            /** @var int[] $socketIds */
            $socketIds = array_keys($read);

            foreach ($socketIds as $socketId) {
                if ($socketId === self::NOTIFY_SOCKET) {
                    socket_read($this->notifySocket, 65535); //clean socket
                    $this->pushSockets();
                } elseif ($socketId === self::SERVER_SOCKET) {
                    $this->onServerSocketReceive();
                } else {
                    $this->onSocketReceive($socketId);
                }
            }
        }
    }

    private function pushSockets(): void
    {
        while (($payload = $this->mainToThreadReader->read()) !== null) {
            if (($pk = ProxyPacketPool::getInstance()->getPacket($payload)) === null) {
                throw new PacketHandlingException('Packet does not exist');
            } else {
                try {
                    $socketId = $pk->decode(new ProxyPacketSerializer($payload));

                    try {
                        switch ($pk->pid()) {
                            case DisconnectPacket::NETWORK_ID:
                                /** @var DisconnectPacket $pk */
                                if ($this->getSocket($socketId) !== null) {
                                    $this->closeSocket($socketId, false);
                                }
                                break;
                            case ForwardPacket::NETWORK_ID:
                                /** @var ForwardPacket $pk */
                                if (($socket = $this->getSocket($socketId)) === null) {
                                    throw new PacketHandlingException('Socket with id (' . $socketId . ") doesn't exist.");
                                } else {
                                    try {
                                        if (socket_write($socket, Binary::writeInt(strlen($pk->payload)) . $pk->payload) === false) {
                                            throw new PacketHandlingException('Socket with id (' . $socketId . ") isn't writable.");
                                        }
                                    } catch (ErrorException $exception) {
                                        throw PacketHandlingException::wrap($exception, 'Socket with id (' . $socketId . ") isn't writable.");
                                    }
                                }
                                break;
                        }
                    } catch (PacketHandlingException $exception) {
                        $this->closeSocket($socketId);
                    }
                } catch (BinaryDataException $exception) {
                    throw PacketHandlingException::wrap($exception, "Error processing " . $pk->pid());
                }
            }
        }
    }

    public function getSocket(int $socketId): ?Socket
    {
        return ((array)$this->sockets)[$socketId] ?? null;
    }

    private function closeSocket(int $socketId, bool $notify = true): void
    {
        if (($socket = $this->getSocket($socketId)) !== null) {
            socket_close($socket);
            unset($this->sockets[$socketId]);
        }

        $this->logger->debug("Disconnected socket with id " . $socketId);

        if ($notify) {
            $this->putPacket($socketId, new DisconnectPacket());
        }
    }

    private function putPacket(int $socketId, ProxyPacket $pk): void
    {
        $serializer = new ProxyPacketSerializer();

        $pk->encode($socketId, $serializer);

        $this->threadToMainWriter->write($serializer->getBuffer());
    }

    private function onServerSocketReceive(): void
    {
        $socket = socket_accept($this->serverSocket);
        if ($socket === false) {
            $this->logger->debug("Couldn't accept new socket request: " . socket_strerror(socket_last_error($this->serverSocket)));
        } elseif (socket_getpeername($socket, $ip, $port)) {
            $this->sockets[$socketId = $this->socketId++] = $socket;

            $pk = new LoginPacket();
            $pk->ip = $ip;
            $pk->port = $port;

            $this->putPacket($socketId, $pk);
        } else {
            $this->logger->debug('New socket request already disconnected: ' . socket_strerror(socket_last_error($this->serverSocket)));
        }
    }

    private function onSocketReceive(int $socketId): void
    {
        /** @var Socket $socket */
        $socket = $this->getSocket($socketId);

        if (($rawFrameLength = $this->get($socket, 4)) === null) {
            $this->closeSocket($socketId);
            $this->logger->debug('Socket(' . $socketId . ') returned invalid frame data length');
        } else {
            try {
                $packetLength = Binary::readInt($rawFrameLength);
            } catch (BinaryDataException $exception) {
                $this->closeSocket($socketId);
                $this->logger->logException($exception);
                return;
            }
            $rawFrameData = $this->get($socket, $packetLength);

            if ($rawFrameData === null) {
                $this->closeSocket($socketId);
                $this->logger->debug('Socket(' . $socketId . ') returned invalid frame data');
            } else {
                if ($this->asyncDecompress) {
                    if (($payload = zstd_uncompress($rawFrameData)) === false) {
                        $this->closeSocket($socketId);
                        $this->logger->emergency('Socket with id (' . $socketId . ') data could not be decompressed.');
                        return;
                    }
                } else {
                    $payload = $rawFrameData;
                }

                $pk = new ForwardPacket();
                $pk->payload = $payload;

                $this->putPacket($socketId, $pk);
            }
        }
    }

    private function get(Socket $socket, int $remainingLength): ?string
    {
        try {
            $packet = '';
            $buffer = '';

            while ($remainingLength > 0) {
                $length = min(65535, $remainingLength);
                $receivedLength = socket_recv($socket, $buffer, $length, MSG_WAITALL);

                if ($receivedLength === false || $receivedLength !== $length) {
                    return null;
                }

                $packet .= $buffer;
                $remainingLength -= $length;
            }

            return $packet;
        } catch (ErrorException $exception){
            return null;
        }
    }

    public function waitShutdown(): void
    {
        $this->tickProcessor();

        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        socket_close($this->serverSocket);
        socket_close($this->notifySocket);
    }
}