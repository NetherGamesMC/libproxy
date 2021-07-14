<?php


declare(strict_types=1);

namespace libproxy;

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
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\BinaryDataException;
use Threaded;
use const PTHREADS_INHERIT_CONSTANTS;

final class ProxyNetworkInterface implements NetworkInterface
{

    /** @var Server */
    private Server $server;
    /** @var ProxyServer */
    private ProxyServer $proxy;
    /** @var SleeperNotifier */
    private SleeperNotifier $notifier;
    /** @var PthreadsChannelWriter */
    private PthreadsChannelWriter $mainToThreadWriter;
    /** @var PthreadsChannelReader */
    private PthreadsChannelReader $threadToMainReader;

    /** @var NetworkSession[] */
    private array $sessions = [];

    public function __construct(Server $server, int $port)
    {
        $this->server = $server;
        $this->notifier = new SleeperNotifier();

        $mainToThreadBuffer = new Threaded();
        $threadToMainBuffer = new Threaded();

        $this->proxy = new ProxyServer(
            $server->getIp(),
            $port,
            $server->getLogger(),
            $mainToThreadBuffer,
            $threadToMainBuffer,
            $this->notifier
        );

        $this->mainToThreadWriter = new PthreadsChannelWriter($mainToThreadBuffer);
        $this->threadToMainReader = new PthreadsChannelReader($threadToMainBuffer);
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
    private function onPacketReceive(string $payload): void
    {
        if (($pk = ProxyPacketPool::getInstance()->getPacket($payload)) === null) {
            throw new PacketHandlingException('Packet does not exist');
        } else {
            try {
                $socketId = $pk->decode(new ProxyPacketSerializer($payload));

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
                    $this->server->getLogger()->logException($exception);
                }
            } catch (BinaryDataException $exception) {
                throw PacketHandlingException::wrap($exception, "Error processing " . $pk->pid());
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
            EmptyCompressor::getInstance(),
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
    }

    public function setName(string $name): void
    {
        //NOPEH
    }

    public function tick(): void
    {
        //NOPEH
    }

    public function shutdown(): void
    {
        $this->server->getTickSleeper()->removeNotifier($this->notifier);
        $this->proxy->quit();
    }
}
