<?php


declare(strict_types=1);

namespace libproxy;

use ErrorException;
use Exception;
use libproxy\protocol\DisconnectPacket;
use libproxy\protocol\ForwardPacket;
use libproxy\protocol\LoginPacket;
use libproxy\protocol\ProxyPacket;
use libproxy\protocol\ProxyPacketPool;
use libproxy\protocol\ProxyPacketSerializer;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\NetworkInterface;
use pocketmine\network\PacketHandlingException;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\Binary;
use pocketmine\utils\Utils;
use RuntimeException;
use Socket;
use Threaded;
use function bin2hex;
use function socket_close;
use function socket_create_pair;
use function socket_write;
use function strlen;
use function substr;
use const AF_INET;
use const AF_UNIX;
use const PTHREADS_INHERIT_CONSTANTS;
use const SOCK_STREAM;

final class ProxyNetworkInterface implements NetworkInterface
{

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

    /** @var NetworkSession[] */
    private array $sessions = [];

    public function __construct(PluginBase $plugin, int $port)
    {
        $server = $plugin->getServer();

        if (socket_create_pair(Utils::getOS() === Utils::OS_LINUX ? AF_UNIX : AF_INET, SOCK_STREAM, 0, $pair)) {
            /** @var Socket $threadNotifier */
            /** @var Socket $threadNotification */
            [$threadNotifier, $threadNotification] = $pair;
            $this->threadNotifier = $threadNotifier;

            $this->server = $server;
            $this->notifier = new SleeperNotifier();

            $mainToThreadBuffer = new Threaded();
            $threadToMainBuffer = new Threaded();

            $asyncDecompress = $server->getConfigGroup()->getPropertyBool("network.async-compression", true);
            ZstdCompressor::setInstance(new ZstdCompressor($asyncDecompress));

            $this->proxy = new ProxyThread(
                $server->getIp(),
                $port,
                $server->getLogger(),
                $mainToThreadBuffer,
                $threadToMainBuffer,
                $this->notifier,
                $threadNotification,
                $asyncDecompress,
            );

            $this->mainToThreadWriter = new PthreadsChannelWriter($mainToThreadBuffer);
            $this->threadToMainReader = new PthreadsChannelReader($threadToMainBuffer);

            $server->getPluginManager()->registerEvents(new ProxyListener(), $plugin);
        } else {
            $server->getLogger()->emergency('Notifier Socket could not be created');
        }
    }

    public function start(): void
    {
        $this->server->getTickSleeper()->addNotifier($this->notifier, function (): void {
            while (($payload = $this->threadToMainReader->read()) !== null) {
                $this->onPacketReceive($payload);
            }
        });
        $this->server->getLogger()->debug('Waiting for NetSys to start...');
        $this->proxy->startAndWait(PTHREADS_INHERIT_CONSTANTS); //HACK: MainLogger needs constants for exception logging
        $this->server->getLogger()->debug('NetSys booted successfully');
    }

    /**
     * @throws PacketHandlingException
     */
    private function onPacketReceive(string $buffer): void
    {
        if (($pk = ProxyPacketPool::getInstance()->getPacket($buffer)) === null) {
            $offset = 0;
            throw new PacketHandlingException('Proxy packet with id (' . Binary::readUnsignedVarInt($buffer, $offset) . ') does not exist');
        } else {
            try {
                $socketId = $pk->decode($stream = new ProxyPacketSerializer($buffer));
            } catch (PacketDecodeException $e) {
                throw PacketHandlingException::wrap($e);
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
                        } else {
                            $this->close($socketId, false);
                        }
                        break;
                    case ForwardPacket::NETWORK_ID:
                        /** @var ForwardPacket $pk */
                        if (($session = $this->getSession($socketId)) === null) {
                            throw new PacketHandlingException('Socket with id (' . $socketId . ") doesn't have a session.");
                        } else {
                            $session->handleEncoded($pk->payload);
                        }
                        break;
                }
            } catch (PacketHandlingException $exception) {
                $this->close($socketId);
            }
        }
    }

    public function getSession(int $socketId): ?NetworkSession
    {
        return $this->sessions[$socketId] ?? null;
    }

    public function createSession(int $socketId, string $ip, int $port): NetworkSession
    {
        return $this->sessions[$socketId] = new NetworkSession(
            $this->server,
            $this->server->getNetwork()->getSessionManager(),
            PacketPool::getInstance(),
            new ProxyPacketSender($socketId, $this),
            RakLibInterface::getBroadcaster($this->server, ProtocolInfo::CURRENT_PROTOCOL),
            ZstdCompressor::getInstance(),
            $ip,
            $port
        );
    }

    public function close(int $socketId, bool $notify = true): void
    {
        if (($session = $this->getSession($socketId)) !== null) {
            $session->onClientDisconnect('Socket disconnect');
        }

        unset($this->sessions[$socketId]);

        if ($notify) {
            $this->putPacket($socketId, new DisconnectPacket());
        }
    }

    public function putPacket(int $socketId, ProxyPacket $pk): void
    {
        $serializer = new ProxyPacketSerializer();

        $pk->encode($socketId, $serializer);

        $this->mainToThreadWriter->write($serializer->getBuffer());

        try {
            socket_write($this->threadNotifier, "\x00"); // wakes up the socket_select function
        } catch (ErrorException $exception){
            $this->server->getLogger()->debug('Packet was send while the client was already shut down');
        }
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
        $this->server->getTickSleeper()->removeNotifier($this->notifier);
        $this->proxy->quit();

        socket_close($this->threadNotifier);
    }
}
