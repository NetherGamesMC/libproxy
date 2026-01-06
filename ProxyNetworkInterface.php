<?php


declare(strict_types=1);

namespace libproxy;

use Error;
use Exception;
use libproxy\data\LatencyData;
use libproxy\data\TickSyncPacket;
use libproxy\protocol\AckPacket;
use libproxy\protocol\DisconnectPacket;
use libproxy\protocol\ForwardPacket;
use libproxy\protocol\LoginPacket;
use libproxy\protocol\ProxyPacket;
use libproxy\protocol\ProxyPacketPool;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\FilterNoisyPacketException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\network\NetworkInterface;
use pocketmine\network\PacketHandlingException;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\thread\ThreadCrashException;
use Socket;
use ThreadedArray;
use WeakMap;
use function base64_encode;
use function bin2hex;
use function socket_close;
use function socket_create_pair;
use function socket_last_error;
use function socket_strerror;
use function socket_write;
use function strlen;
use function substr;
use function trim;
use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
use const SOCKET_ENOPROTOOPT;
use const SOCKET_EPROTONOSUPPORT;

final class ProxyNetworkInterface implements NetworkInterface
{
    /** @var WeakMap<NetworkSession, LatencyData> */
    public static WeakMap $latencyMap;

    /** @var Server */
    private Server $server;
    /** @var ProxyThread */
    private ProxyThread $proxy;
    /** @var SleeperHandlerEntry */
    private SleeperHandlerEntry $sleeperEntry;
    /** @var Socket */
    private Socket $threadNotifier;
    /** @var PthreadsChannelWriter */
    private PthreadsChannelWriter $mainToThreadWriter;
    /** @var PthreadsChannelReader */
    private PthreadsChannelReader $threadToMainReader;

    /** @var PacketBroadcaster */
    private PacketBroadcaster $packetBroadcaster;
    /** @var EntityEventBroadcaster */
    private EntityEventBroadcaster $entityEventBroadcaster;

    /** @var int */
    private int $receiveBytes = 0;
    /** @var int */
    private int $sendBytes = 0;

    /** @var NetworkSession[] */
    private array $sessions = [];

    public function __construct(PluginBase $plugin, int $port, ?string $composerPath = null)
    {
        $server = $plugin->getServer();

        $ret = @socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $ipc);
        if (!$ret) {
            $err = socket_last_error();
            if (($err !== SOCKET_EPROTONOSUPPORT && $err !== SOCKET_ENOPROTOOPT) || !@socket_create_pair(AF_INET, SOCK_STREAM, 0, $ipc)) {
                throw new Exception('Failed to open IPC socket: ' . trim(socket_strerror(socket_last_error())));
            }
        }

        self::$latencyMap = new WeakMap();

        /** @var list{Socket, Socket} $ipc */
        [$threadNotifier, $threadNotification] = $ipc;
        $this->threadNotifier = $threadNotifier;

        $this->server = $server;
        $this->sleeperEntry = $plugin->getServer()->getTickSleeper()->addNotifier(function (): void {
            while (($payload = $this->threadToMainReader->read()) !== null) {
                $this->onPacketReceive($payload);
            }
        });

        $mainToThreadBuffer = new ThreadSafeArray();
        $threadToMainBuffer = new ThreadSafeArray();

        $this->proxy = new ProxyThread(
            $composerPath,
            $server->getIp(),
            $port,
            $server->getLogger(),
            $mainToThreadBuffer,
            $threadToMainBuffer,
            $this->sleeperEntry,
            $threadNotification,
        );

        $this->mainToThreadWriter = new PthreadsChannelWriter($mainToThreadBuffer);
        $this->threadToMainReader = new PthreadsChannelReader($threadToMainBuffer);

        $this->packetBroadcaster = PMUtils::getPacketBroadcaster($server);
        $this->entityEventBroadcaster = PMUtils::getEntityEventBroadcaster($server);

        PacketPool::getInstance()->registerPacket(new TickSyncPacket());

        $bandwidthTracker = $plugin->getServer()->getNetwork()->getBandwidthTracker();
        $plugin->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(function () use ($bandwidthTracker): void {
            $bandwidthTracker->add($this->sendBytes, $this->receiveBytes);
            $this->sendBytes = 0;
            $this->receiveBytes = 0;
        }), 20, 20);
    }

    public static function handleRawLatency(NetworkSession $session, int $upstream, int $downstream): void
    {
        self::$latencyMap[$session] = $data = new LatencyData($upstream, $downstream);

        $session->updatePing($data->getLatency());
    }

    public static function getLatencyData(NetworkSession $session): ?LatencyData
    {
        return self::$latencyMap[$session] ?? null;
    }

    public function start(): void
    {
        $this->server->getLogger()->debug('Waiting for Proxy to start...');
        $this->proxy->startAndWait(NativeThread::INHERIT_CONSTANTS); //HACK: MainLogger needs constants for exception logging
        $this->server->getLogger()->debug('Proxy booted successfully');
    }

    /**
     * @throws PacketHandlingException
     * @throws DataDecodeException
     */
    private function onPacketReceive(string $buffer): void
    {
        $stream = new ByteBufferReader($buffer);
        $socketId = LE::readUnsignedInt($stream);

        $offset = $stream->getOffset();
        if (($pk = ProxyPacketPool::getInstance()->getPacket($stream)) === null) {
            throw new PacketHandlingException('Unknown ProxyPacket received from Proxy Thread');
        }
        $stream->setOffset($offset);

        try {
            $pk->decode($stream);
        } catch (DataDecodeException $e) {
            $this->server->getLogger()->debug('Closed socket with id(' . $socketId . ') because packet was invalid.');
            $this->close($socketId, 'Invalid Packet');
            return;
        }

        if ($stream->getUnreadLength() > 0) {
            $remains = substr($stream->getData(), $stream->getOffset());
            $this->server->getLogger()->debug('Still ' . strlen($remains) . ' bytes unread in ' . $pk->pid() . ': ' . bin2hex($remains));
        }

        try {
            switch ($pk->pid()) {
                case LoginPacket::NETWORK_ID:
                    /** @var LoginPacket $pk */
                    if ($this->getSession($socketId) === null) {
                        $this->createSession($socketId, $pk->ip, $pk->port);
                    } else {
                        throw new PacketHandlingException('Socket with id (' . $socketId . ') already has a session.');
                    }
                    break;
                case DisconnectPacket::NETWORK_ID:
                    /** @var DisconnectPacket $pk */
                    if ($this->getSession($socketId) === null) {
                        break;
                    }

                    $this->close($socketId, $pk->reason, true);
                    break;
                case ForwardPacket::NETWORK_ID:
                    /** @var ForwardPacket $pk */
                    if (($session = $this->getSession($socketId)) === null || !(fn() => $this->connected)->call($session)) {
                        break; // might be data arriving from the client after the server has closed the connection
                    }

                    if ((fn() => $this->checkRepeatedPacketFilter($pk->payload))->call($session)) {
                        break;
                    }

                    $packet = PacketPool::getInstance()->getPacket($pk->payload);
                    if ($packet === null) {
                        $session->getLogger()->debug("Unknown packet: " . base64_encode($pk->payload));
                        throw new PacketHandlingException("Unknown packet received");
                    }
                    try {
                        $session->handleDataPacket($packet, $pk->payload);
                    } catch (PacketHandlingException $e) {
                        $session->getLogger()->debug($packet->getName() . ": " . base64_encode($pk->payload));
                        throw PacketHandlingException::wrap($e, "Error processing " . $packet->getName());
                    } catch (FilterNoisyPacketException) {
                        (fn() => $this->noisyPacketBuffer = $pk->payload)->call($session);
                    }
                    $this->receiveBytes += strlen($pk->payload);
                    break;
                case AckPacket::NETWORK_ID:
                    /** @var AckPacket $pk */
                    if (($session = $this->getSession($socketId)) === null || !(fn() => $this->connected)->call($session)) {
                        break; // might be data arriving from the client after the server has closed the connection
                    }

                    $session->handleAckReceipt($pk->receiptId);
                    break;
            }
        } catch (PacketHandlingException|DataDecodeException $exception) {
            $this->close($socketId, 'Error handling a Packet (Server)');

            $this->server->getLogger()->logException($exception);
        }
    }

    public function tick(): void
    {
        if (!$this->proxy->isRunning()) {
            $e = $this->proxy->getCrashInfo();
            if ($e !== null) {
                throw new ThreadCrashException("Proxy crashed", $e);
            }
            throw new \Exception("Proxy Thread crashed without crash information");
        }
    }

    public function close(int $socketId, string $reason, bool $fromThread = false, bool $kicked = false): void
    {
        static $disconnectGuard = false;
        if ($disconnectGuard) {
            return;
        }

        $session = $this->getSession($socketId);
        unset($this->sessions[$socketId]);

        if (!$kicked && $session !== null) {
            /**
             * {@link NetworkSession::tryDisconnect()} is calling the current method when calls {@link ProxyPacketSender::close()}
             */
            $disconnectGuard = true;
            $session->onClientDisconnect($reason);
            $disconnectGuard = false;
        }

        if (!$fromThread) {
            $pk = new DisconnectPacket();
            $pk->reason = $reason;

            $this->putPacket($socketId, $pk);
        }
    }

    public function getSession(int $socketId): ?NetworkSession
    {
        return $this->sessions[$socketId] ?? null;
    }

    public function putPacket(int $socketId, ProxyPacket $pk): void
    {
        $serializer = new ByteBufferWriter();
        LE::writeUnsignedInt($serializer, $socketId);

        $pk->encode($serializer);

        $this->mainToThreadWriter->write($serializer->getData());
        $this->sendBytes += strlen($serializer->getData());

        try {
            socket_write($this->threadNotifier, "\x00"); // wakes up the socket_select function
        } catch (Error $exception) {
            $this->server->getLogger()->debug('Packet was send while the client was already shut down');
        }
    }

    public function createSession(int $socketId, string $ip, int $port): NetworkSession
    {
        $session = new NetworkSession(
            $this->server,
            $this->server->getNetwork()->getSessionManager(),
            PacketPool::getInstance(),
            new ProxyPacketSender($socketId, $this),
            $this->packetBroadcaster,
            $this->entityEventBroadcaster,
            ZlibCompressor::getInstance(),
            TypeConverter::getInstance(),
            $ip,
            $port
        );

        $this->sessions[$socketId] = $session;
        return $session;
    }

    public function setName(string $name): void
    {
        //NOPEH
    }

    public function shutdown(): void
    {
        $this->proxy->shutdown();
        socket_write($this->threadNotifier, "\x00");
        $this->proxy->quit();

        @socket_close($this->threadNotifier);
        $this->server->getTickSleeper()->removeNotifier($this->sleeperEntry->getNotifierId());
    }
}
