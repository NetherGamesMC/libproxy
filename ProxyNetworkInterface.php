<?php


declare(strict_types=1);

namespace libproxy;

use Error;
use Exception;
use libproxy\data\LatencyData;
use libproxy\data\TickSyncPacket;
use libproxy\protocol\DisconnectPacket;
use libproxy\protocol\ForwardPacket;
use libproxy\protocol\LoginPacket;
use libproxy\protocol\ProxyPacket;
use libproxy\protocol\ProxyPacketPool;
use libproxy\protocol\ProxyPacketSerializer;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\NetworkInterface;
use pocketmine\network\PacketHandlingException;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\TextFormat;
use RuntimeException;
use Socket;
use Threaded;
use WeakMap;
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
use const PTHREADS_INHERIT_ALL;
use const PTHREADS_INHERIT_CLASSES;
use const PTHREADS_INHERIT_CONSTANTS;
use const PTHREADS_INHERIT_INCLUDES;
use const SOCK_STREAM;

final class ProxyNetworkInterface implements NetworkInterface
{
    /** @var WeakMap<NetworkSession, LatencyData> */
    public static WeakMap $latencyMap;

    /** @var Server */
    private Server $server;
    /** @var ProxyThread */
    private ProxyThread $proxy;
    /** @var SleeperNotifier */
    private SleeperNotifier $notifier;
    /** @var Socket */
    private Socket $threadNotifier;
    /** @var PthreadsChannelWriter */
    private PthreadsChannelWriter $mainToThreadWriter;
    /** @var PthreadsChannelReader */
    private PthreadsChannelReader $threadToMainReader;

    /** @var int */
    private int $receiveBytes = 0;
    /** @var int */
    private int $sendBytes = 0;

    /** @var NetworkSession[] */
    private array $sessions = [];

    public function __construct(PluginBase $plugin, int $port)
    {
        $server = $plugin->getServer();

        $ret = @socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $ipc);
        if (!$ret) {
            $err = socket_last_error();
            if (($err !== SOCKET_EPROTONOSUPPORT && $err !== SOCKET_ENOPROTOOPT) || !@socket_create_pair(AF_INET, SOCK_STREAM, 0, $ipc)) {
                throw new Exception('Failed to open IPC socket: ' . trim(socket_strerror(socket_last_error())));
            }
        }

        /** @phpstan-ignore-next-line */
        self::$latencyMap = new WeakMap();

        /** @var Socket $threadNotifier */
        /** @var Socket $threadNotification */
        [$threadNotifier, $threadNotification] = $ipc;
        $this->threadNotifier = $threadNotifier;

        $this->server = $server;
        $this->notifier = new SleeperNotifier();

        $mainToThreadBuffer = new Threaded();
        $threadToMainBuffer = new Threaded();

        $this->proxy = new ProxyThread(
            $server->getIp(),
            $port,
            $server->getLogger(),
            $mainToThreadBuffer,
            $threadToMainBuffer,
            $this->notifier,
            $threadNotification,
        );

        $this->mainToThreadWriter = new PthreadsChannelWriter($mainToThreadBuffer);
        $this->threadToMainReader = new PthreadsChannelReader($threadToMainBuffer);

        PacketPool::getInstance()->registerPacket(new TickSyncPacket());

        $bandwidthTracker = $plugin->getServer()->getNetwork()->getBandwidthTracker();
        $plugin->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(function () use ($bandwidthTracker): void {
            $bandwidthTracker->add($this->sendBytes, $this->receiveBytes);
            $this->sendBytes = 0;
            $this->receiveBytes = 0;
        }), 20, 20);

        $server->getPluginManager()->registerEvents(new ProxyListener(), $plugin);
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
        $this->server->getTickSleeper()->addNotifier($this->notifier, function (): void {
            while (($payload = $this->threadToMainReader->read()) !== null) {
                $this->onPacketReceive($payload);
            }
        });
        $this->server->getLogger()->debug('Waiting for Proxy to start...');
        $this->proxy->startAndWait(PTHREADS_INHERIT_CONSTANTS); //HACK: MainLogger needs constants for exception logging & Composer need classes
        $this->server->getLogger()->debug('Proxy booted successfully');
    }

    /**
     * @throws PacketHandlingException
     */
    private function onPacketReceive(string $buffer): void
    {
        $stream = new ProxyPacketSerializer($buffer);
        $socketId = $stream->getLInt();

        if (($pk = ProxyPacketPool::getInstance()->getPacket($buffer, $stream->getOffset())) === null) {
            $offset = 0;
            throw new PacketHandlingException('Proxy packet with id (' . Binary::readUnsignedVarInt($buffer, $offset) . ') does not exist');
        }

        try {
            $pk->decode($stream);
        } catch (BinaryDataException $e) {
            $this->server->getLogger()->debug('Closed socket with id(' . $socketId . ') because packet was invalid.');
            $this->close($socketId, 'Invalid Packet');
            return;
        }

        if (!$stream->feof()) {
            $remains = substr($stream->getBuffer(), $stream->getOffset());
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
                        throw new PacketHandlingException('Socket with id (' . $socketId . ") doesn't have a session.");
                    }

                    $this->close($socketId);
                    break;
                case ForwardPacket::NETWORK_ID:
                    /** @var ForwardPacket $pk */
                    if (($session = $this->getSession($socketId)) === null) {
                        throw new PacketHandlingException('Socket with id (' . $socketId . ") doesn't have a session.");
                    }

                    $session->handleEncoded($pk->payload);
                    $this->receiveBytes += strlen($pk->payload);
                    break;
            }
        } catch (PacketHandlingException $exception) {
            $this->close($socketId, 'Error handling a Packet');
        }
    }

    public function close(int $socketId, string $reason = TextFormat::EOL): void
    {
        if (($session = $this->getSession($socketId)) !== null) {
            $session->onClientDisconnect('Socket disconnect');
        }

        unset($this->sessions[$socketId]);

        if ($reason !== TextFormat::EOL) {
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
        $serializer = new ProxyPacketSerializer();
        $serializer->putLInt($socketId);

        $pk->encode($serializer);

        $this->mainToThreadWriter->write($serializer->getBuffer());
        $this->sendBytes += strlen($serializer->getBuffer());

        try {
            socket_write($this->threadNotifier, "\x00"); // wakes up the socket_select function
        } catch (Error $exception) {
            $this->server->getLogger()->debug('Packet was send while the client was already shut down');
        }
    }

    public function createSession(int $socketId, string $ip, int $port): NetworkSession
    {
        return $this->sessions[$socketId] = new NetworkSession(
            $this->server,
            $this->server->getNetwork()->getSessionManager(),
            PacketPool::getInstance(),
            new ProxyPacketSender($socketId, $this),
            RakLibInterface::getBroadcaster($this->server, ProtocolInfo::CURRENT_PROTOCOL),
            MultiCompressor::getInstance(),
            $ip,
            $port
        );
    }

    public function setName(string $name): void
    {
        //NOPEH
    }

    /**
     * @throws Exception
     */
    public function tick(): void
    {
        if (!$this->proxy->isRunning()) {
            $e = $this->proxy->getCrashInfo();

            if ($e !== null) {
                throw new RuntimeException("Proxy crashed: $e");
            }

            throw new Exception('Proxy Thread crashed without crash information');
        }
    }

    public function shutdown(): void
    {
        $this->proxy->shutdown();
        socket_write($this->threadNotifier, "\x00");
        $this->proxy->quit();

        @socket_close($this->threadNotifier);
        $this->server->getTickSleeper()->removeNotifier($this->notifier);
    }
}
