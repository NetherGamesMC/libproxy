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
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\log\AttachableThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Utils;
use raklib\generic\SocketException;
use Socket;
use function base64_encode;
use function bin2hex;
use function chr;
use function count;
use function min;
use function ord;
use function socket_accept;
use function socket_close;
use function socket_getpeername;
use function socket_last_error;
use function socket_read;
use function socket_recv;
use function socket_select;
use function socket_set_option;
use function socket_shutdown;
use function socket_strerror;
use function socket_write;
use function strlen;
use function substr;
use function trim;
use function zstd_uncompress;
use const MSG_DONTWAIT;
use const MSG_WAITALL;
use const SO_LINGER;
use const SO_RCVTIMEO;
use const SO_SNDTIMEO;
use const SOCKET_EWOULDBLOCK;
use const SOL_SOCKET;

class ProxyServer
{
    private const SERVER_SOCKET = -1;
    private const NOTIFY_SOCKET = -2;

    private const MAX_FRAME_LENGTH = 65535;

    private const LENGTH_NEEDED = 0;
    private const BUFFER = 1;

    /** @var AttachableThreadSafeLogger */
    private AttachableThreadSafeLogger $logger;
    /** @var PthreadsChannelReader */
    private PthreadsChannelReader $mainToThreadReader;
    /** @var SnoozeAwarePthreadsChannelWriter */
    private SnoozeAwarePthreadsChannelWriter $threadToMainWriter;

    /** @var Socket */
    private Socket $serverSocket;
    /** @var Socket */
    private Socket $notifySocket;

    /** @var Socket[] */
    private array $sockets = [];

    /** @phpstan-var array<int, PacketRateLimiter> */
    private array $gamePacketLimiter = [];
    /** @phpstan-var array<int, PacketRateLimiter> */
    private array $batchPacketLimiter = [];
    /** @phpstan-var array<int, int> */
    private array $protocolId = [];

    /** @phpstan-var array<int, array<int|string>> */
    private array $socketBuffer = [];

    /** @var int */
    private int $socketId = 0;

    public function __construct(AttachableThreadSafeLogger $logger, Socket $serverSocket, ThreadSafeArray $mainToThreadBuffer, ThreadSafeArray $threadToMainBuffer, SleeperHandlerEntry $sleeperEntry, Socket $notifySocket)
    {
        $this->logger = $logger;
        $this->serverSocket = $serverSocket;
        $this->notifySocket = $notifySocket;

        $this->mainToThreadReader = new PthreadsChannelReader($mainToThreadBuffer);
        $this->threadToMainWriter = new SnoozeAwarePthreadsChannelWriter($threadToMainBuffer, $sleeperEntry->createNotifier());
    }

    public function waitShutdown(): void
    {
        foreach ($this->sockets as $socketId => $socket) {
            $this->closeSocket($socketId, "server shutdown", true);
        }

        while (count($this->sockets) > 0) {
            $this->tickProcessor();
        }

        @socket_close($this->serverSocket);
        @socket_close($this->notifySocket);
    }

    private function closeSocket(int $socketId, string $reason, bool $fromMain = false): void
    {
        if (($socket = $this->getSocket($socketId)) !== null) {
            try {
                socket_shutdown($socket);
            } catch (ErrorException $exception) {
                $this->logger->debug('Socket is not connected anymore.');
            }
            socket_close($socket);
            unset(
                $this->sockets[$socketId],
                $this->socketBuffer[$socketId],
                $this->gamePacketLimiter[$socketId],
                $this->batchPacketLimiter[$socketId],
                $this->protocolId[$socketId]
            );
        }

        $this->logger->debug("Disconnected socket with id " . $socketId);

        if (!$fromMain) {
            $pk = new DisconnectPacket();
            $pk->reason = $reason;

            $this->putPacket($socketId, $pk);
        }
    }

    public function getSocket(int $socketId): ?Socket
    {
        return $this->sockets[$socketId] ?? null;
    }

    private function getGamePacketLimiter(int $streamIdentifier): PacketRateLimiter
    {
        return $this->gamePacketLimiter[$streamIdentifier] ??= new PacketRateLimiter("Game Packets", 2, 100);
    }

    private function getBatchPacketLimiter(int $streamIdentifier): PacketRateLimiter
    {
        return $this->batchPacketLimiter[$streamIdentifier] ??= new PacketRateLimiter("Batch Packets", 2, 100);
    }

    private function putPacket(int $socketId, ProxyPacket $pk): void
    {
        $serializer = new ProxyPacketSerializer();
        $serializer->putLInt($socketId);

        $pk->encode($serializer);

        $this->threadToMainWriter->write($serializer->getBuffer());
    }

    public function tickProcessor(): void
    {
        $read = $this->sockets;
        $read[self::SERVER_SOCKET] = $this->serverSocket;
        $read[self::NOTIFY_SOCKET] = $this->notifySocket;

        $write = null;
        $except = null;

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
            }

            try {
                $pk->decode($stream);
            } catch (BinaryDataException $e) {
                $this->logger->debug('Closed socket with id(' . $socketId . ') because packet was invalid.');
                $this->closeSocket($socketId, 'Invalid Packet');
                return;
            }

            try {
                switch ($pk->pid()) {
                    case DisconnectPacket::NETWORK_ID:
                        /** @var DisconnectPacket $pk */
                        if ($this->getSocket($socketId) !== null) {
                            $this->closeSocket($socketId, $pk->reason, true);
                        }
                        break;
                    case ForwardPacket::NETWORK_ID:
                        /** @var ForwardPacket $pk */
                        $this->sendPayload($socketId, $pk->payload);
                        break;
                }
            } catch (PacketHandlingException $exception) {
                $this->closeSocket($socketId, $exception->getMessage());
            }
        }
    }

    /**
     * Sends a payload to the client
     */
    private function sendPayload(int $socketId, string $payload): void
    {
        if (($socket = $this->getSocket($socketId)) === null) {
            throw new PacketHandlingException('Socket with id (' . $socketId . ") doesn't exist.");
        }

        try {
            if (socket_write($socket, Binary::writeInt(strlen($payload)) . $payload) === false) {
                throw new PacketHandlingException('client disconnect');
            }
        } catch (ErrorException $exception) {
            throw PacketHandlingException::wrap($exception, 'client disconnect');
        }
    }

    /**
     * Sends a data packet to the main thread.
     */
    private function sendDataPacketToMain(int $socketId, string $payload): void
    {
        $pk = new ForwardPacket();
        $pk->payload = $payload;

        $this->putPacket($socketId, $pk);
    }

    /**
     * Returns the protocol ID for the given socket identifier.
     */
    private function getProtocolId(int $socketId): int
    {
        return $this->protocolId[$socketId] ?? ProtocolInfo::CURRENT_PROTOCOL;
    }

    /**
     * Sends a data packet to the client using a single packet in a batch.
     */
    private function sendDataPacket(int $socketId, BedrockPacket $packet): void
    {
        $packetSerializer = PacketSerializer::encoder($protocolId = $this->getProtocolId($socketId));
        $packet->encode($packetSerializer);

        $stream = new BinaryStream();
        PacketBatch::encodeRaw($stream, [$packetSerializer->getBuffer()]);
        $payload = ($protocolId >= ProtocolInfo::PROTOCOL_1_20_60 ? chr(CompressionAlgorithm::ZLIB) : '') . ZlibCompressor::getInstance()->compress($stream->getBuffer());

        $this->sendPayload($socketId, $payload);
    }

    private function decodePacket(int $socketId, BedrockPacket $packet, string $buffer): void
    {
        $stream = PacketSerializer::decoder($this->protocolId[$socketId] ?? ProtocolInfo::CURRENT_PROTOCOL, $buffer, 0);
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
    private function handleDataPacket(int $socketId, BedrockPacket $packet, string $buffer): bool
    {
        if ($packet->pid() == NetworkStackLatencyPacket::NETWORK_ID) {
            /** @var NetworkStackLatencyPacket $packet USED FOR PING CALCULATIONS */
            $this->decodePacket($socketId, $packet, $buffer);

            if ($packet->timestamp === 0 && $packet->needResponse) {
                try {
                    $this->sendDataPacket($socketId, NetworkStackLatencyPacket::response(0));
                } catch (PacketHandlingException $e) {
                    // ignore, the client probably disconnected
                }
                return true;
            }
        } else if ($packet->pid() === RequestNetworkSettingsPacket::NETWORK_ID) {
            /** @var RequestNetworkSettingsPacket $packet USED TO GET PROTOCOLID */
            $this->decodePacket($socketId, $packet, $buffer);

            $this->protocolId[$socketId] = $packet->getProtocolVersion();
        }

        return false;
    }

    /**
     * @see NetworkSession::handleEncoded($payload)
     */
    private function onFullDataReceive(int $socketId, string $payload): void
    {
        try {
            $this->getBatchPacketLimiter($socketId)->decrement();

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
                    $this->getGamePacketLimiter($socketId)->decrement();
                    if (++$count > 100) {
                        throw new PacketHandlingException("Too many packets in batch");
                    }
                    $packet = PacketPool::getInstance()->getPacket($buffer);
                    if ($packet === null) {
                        $this->logger->debug("Unknown packet: " . base64_encode($buffer));
                        throw new PacketHandlingException("Unknown packet received");
                    }
                    try {
                        if (!$this->handleDataPacket($socketId, $packet, $buffer)) {
                            $this->sendDataPacketToMain($socketId, $buffer);
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
            $this->closeSocket($socketId, $e->getMessage());
        }
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

                    socket_set_option($socket, SOL_SOCKET, SO_LINGER, ["l_onoff" => 1, "l_linger" => 0]);
                    socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => 4, "usec" => 0]);
                    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => 4, "usec" => 0]);

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
        $rawFrameData = null;

        try {
            if (isset($this->socketBuffer[$socketId])) {
                /** @var int $length */
                /** @var string $buffer */
                [$length, $buffer] = $this->socketBuffer[$socketId];

                $rawFrameData = $this->readBytes($socketId, $length, $buffer);
            } elseif (($rawFrameLength = $this->readBytes($socketId, 4)) !== null) {
                try {
                    $packetLength = Binary::readInt($rawFrameLength);
                } catch (BinaryDataException $exception) {
                    throw new SocketException('Not enough bytes to read', 0, $exception);
                }

                $this->socketBuffer[$socketId] = [
                    self::LENGTH_NEEDED => $packetLength,
                    self::BUFFER => '',
                ];

                $rawFrameData = $this->readBytes($socketId, $packetLength);
            }
        } catch (SocketException $exception) {
            $this->closeSocket($socketId, $exception->getMessage());
            $this->logger->debug('Socket(' . $socketId . ') returned ' . $exception->getMessage());
            return;
        }

        // A null frame data indicates that there is not enough bytes to read.
        if ($rawFrameData !== null) {
            unset($this->socketBuffer[$socketId]);
            $this->onFullDataReceive($socketId, $rawFrameData);
        }
    }

    private function readBytes(int $socketId, int $remainingLength, string $previousBuffer = ''): ?string
    {
        /** @var Socket $socket */
        $socket = $this->getSocket($socketId);

        $length = min(self::MAX_FRAME_LENGTH, $remainingLength);
        if (Utils::getOS() === Utils::OS_WINDOWS) {
            // Honestly I do not think this is a problem since we are not going to deploy
            // windows as our "production" nodes.
            $receivedLength = @socket_recv($socket, $buffer, $length, MSG_WAITALL);
        } else {
            $receivedLength = @socket_recv($socket, $buffer, $length, MSG_DONTWAIT);
        }

        if (!$receivedLength) {
            $errno = socket_last_error($socket);
            if ($errno === SOCKET_EWOULDBLOCK) {
                return null;
            }
            // Indicates that the socket was closed.
            if ($errno === 0) {
                throw new SocketException("client disconnect");
            }

            // Otherwise throw an exception as normal.
            throw new SocketException(strtolower(trim(socket_strerror($errno))) . " (errno $errno)", $errno);
        }

        if ($remainingLength === $receivedLength) {
            return $previousBuffer . $buffer;
        }

        $this->socketBuffer[$socketId] = [
            self::LENGTH_NEEDED => $remainingLength - $receivedLength,
            self::BUFFER => $previousBuffer . $buffer,
        ];

        return null;
    }
}