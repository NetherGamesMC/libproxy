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
use pocketmine\network\mcpe\raklib\SnoozeAwarePthreadsChannelWriter;
use pocketmine\network\PacketHandlingException;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use Socket;
use Threaded;
use ThreadedLogger;
use function min;
use function socket_accept;
use function socket_close;
use function socket_getpeername;
use function socket_last_error;
use function socket_read;
use function socket_recv;
use function socket_select;
use function socket_shutdown;
use function socket_strerror;
use function socket_write;
use function strlen;
use function zstd_uncompress;

class ProxyServer
{
    private const SERVER_SOCKET = -1;
    private const NOTIFY_SOCKET = -2;

    private const MAX_FRAME_LENGTH = 65535;

    private const LENGTH_NEEDED = 0;
    private const BUFFER = 1;

    /** @var ThreadedLogger */
    private ThreadedLogger $logger;
    /** @var PthreadsChannelReader */
    private PthreadsChannelReader $mainToThreadReader;
    /** @var SnoozeAwarePthreadsChannelWriter */
    private SnoozeAwarePthreadsChannelWriter $threadToMainWriter;
    /** @var bool */
    private bool $asyncDecompress;

    /** @var Socket */
    private Socket $serverSocket;
    /** @var Socket */
    private Socket $notifySocket;

    /** @var Socket[] */
    private array $sockets = [];
    /** @var string[] */
    private array $socketBuffer = [];

    /** @var int */
    private int $socketId = 0;

    public function __construct(ThreadedLogger $logger, Socket $serverSocket, Threaded $mainToThreadBuffer, Threaded $threadToMainBuffer, SleeperNotifier $notifier, Socket $notifySocket, bool $asyncDecompress)
    {
        $this->logger = $logger;
        $this->serverSocket = $serverSocket;
        $this->notifySocket = $notifySocket;
        $this->asyncDecompress = $asyncDecompress;

        $this->mainToThreadReader = new PthreadsChannelReader($mainToThreadBuffer);
        $this->threadToMainWriter = new SnoozeAwarePthreadsChannelWriter($threadToMainBuffer, $notifier);
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

    public function tickProcessor(): void
    {
        $read = $this->sockets;
        $read[self::SERVER_SOCKET] = $this->serverSocket;
        $read[self::NOTIFY_SOCKET] = $this->notifySocket;

        $write = null;
        $except = null;

        /** @phpstan-ignore-next-line */
        $select = socket_select($read, $write, $except, 5);
        if ($select !== false && $select > 0) {
            foreach ($read as $socketId => $socket) {
                /** @var int $socketId */
                if ($socketId === self::NOTIFY_SOCKET) {
                    socket_read($socket, self::MAX_FRAME_LENGTH); //clean socket
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
            $stream = new ProxyPacketSerializer($payload);
            $socketId = $stream->getLInt();

            if (($pk = ProxyPacketPool::getInstance()->getPacket($payload, $stream->getOffset())) === null) {
                throw new PacketHandlingException('Packet does not exist');
            } else {
                try {
                    $pk->decode($stream);
                } catch (BinaryDataException $e) {
                    $this->logger->debug('Closed socket with id(' . $socketId . ') because packet was invalid.');
                    $this->closeSocket($socketId);
                    return;
                }

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
            }
        }
    }

    private function closeSocket(int $socketId, bool $notify = true): void
    {
        if (($socket = $this->getSocket($socketId)) !== null) {
            try {
                socket_shutdown($socket);
            } catch (ErrorException $exception) {
                $this->logger->debug('Socket is not connected anymore.');
            }
            socket_close($socket);
            unset($this->sockets[$socketId], $this->socketBuffer[$socketId]);
        }

        $this->logger->debug("Disconnected socket with id " . $socketId);

        if ($notify) {
            $this->putPacket($socketId, new DisconnectPacket());
        }
    }

    public function getSocket(int $socketId): ?Socket
    {
        return $this->sockets[$socketId] ?? null;
    }

    private function putPacket(int $socketId, ProxyPacket $pk): void
    {
        $serializer = new ProxyPacketSerializer();
        $serializer->putLInt($socketId);

        $pk->encode($serializer);

        $this->threadToMainWriter->write($serializer->getBuffer());
    }

    private function onServerSocketReceive(): void
    {
        $socket = socket_accept($this->serverSocket);
        if ($socket === false) {
            $this->logger->debug("Couldn't accept new socket request: " . socket_strerror(socket_last_error($this->serverSocket)));
        } else {
            try {
                if (socket_getpeername($socket, $ip, $port)) {
                    $this->sockets[$socketId = $this->socketId++] = $socket;

                    $this->logger->debug('Socket(' . $socketId . ') created a session from ' . $ip . ':' . $port);

                    $pk = new LoginPacket();
                    $pk->ip = $ip;
                    $pk->port = $port;

                    $this->putPacket($socketId, $pk);
                } else {
                    $this->logger->debug('New socket request already disconnected: ' . socket_strerror(socket_last_error($this->serverSocket)));
                }
            } catch (ErrorException $exception) {
                $this->logger->debug('New socket request already disconnected: ' . socket_strerror(socket_last_error($this->serverSocket)));
            }
        }
    }

    private function onSocketReceive(int $socketId): void
    {
        if (isset($this->socketBuffer[$socketId])) {
            /** @var int $length */
            [$length, $buffer] = $this->socketBuffer[$socketId];

            $rawFrameData = $this->get($socketId, $length, $buffer);
        } elseif (($rawFrameLength = $this->get($socketId, 4)) !== null) {
            try {
                $packetLength = Binary::readInt($rawFrameLength);
            } catch (BinaryDataException $exception) {
                $this->closeSocket($socketId);
                $this->logger->logException($exception);
                return;
            }

            $rawFrameData = $this->get($socketId, $packetLength);
        } else {
            $this->closeSocket($socketId);
            $this->logger->debug('Socket(' . $socketId . ') returned invalid frame data length');
            return;
        }

        if ($rawFrameData === '') {
            return; //frame is incomplete;
        }

        if ($rawFrameData === null) {
            $this->closeSocket($socketId);
            $this->logger->debug('Socket(' . $socketId . ') returned invalid frame data');
        } else {
            unset($this->socketBuffer[$socketId]);

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

    private function get(int $socketId, int $remainingLength, string $previousBuffer = ''): ?string
    {
        /** @var Socket $socket */
        $socket = $this->getSocket($socketId);

        try {
            $length = min(self::MAX_FRAME_LENGTH, $remainingLength);
            $receivedLength = socket_recv($socket, $buffer, $length, MSG_DONTWAIT);

            if ($receivedLength === false) {
                return null;
            }
            if ($receivedLength === $remainingLength) {
                return $previousBuffer . $buffer;
            }

            $this->socketBuffer[$socketId] = [
                self::LENGTH_NEEDED => $remainingLength - $receivedLength,
                self::BUFFER => $previousBuffer . $buffer,
            ];

            return '';
        } catch (ErrorException $exception) {
            return null;
        }
    }
}