<?php

declare(strict_types=1);


namespace libproxy;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\NetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use function in_array;
use function method_exists;

class ProxyListener implements Listener
{
    /**
     * @param DataPacketReceiveEvent $event
     *
     * @priority LOWEST
     */
    public function onDataPacketReceive(DataPacketReceiveEvent $event): void
    {
        $origin = $event->getOrigin();
        $packet = $event->getPacket();

        switch ($packet->pid()) {
            case NetworkStackLatencyPacket::NETWORK_ID:
                /** @var NetworkStackLatencyPacket $packet USED FOR PING CALCULATIONS */
                if ($packet->timestamp === 0 && $packet->needResponse) {
                    if (($player = $origin->getPlayer()) !== null && $player->isConnected()) {
                        $origin->sendDataPacket(NetworkStackLatencyPacket::response(0));
                    }
                    $event->cancel();
                }
                break;
            case RequestNetworkSettingsPacket::NETWORK_ID:
                /** @var RequestNetworkSettingsPacket $packet USED TO SIMULATE VANILLA BEHAVIOUR, SINCE IT'S NOT USED BY US */
                if (!in_array($protocolVersion = $packet->getProtocolVersion(), ProtocolInfo::ACCEPTED_PROTOCOL, true)) {
                    $origin->disconnectIncompatibleProtocol($protocolVersion);
                }

                if (method_exists($origin, 'setProtocolId')) {
                    $origin->setProtocolId($packet->getProtocolVersion());
                }

                $origin->sendDataPacket(NetworkSettingsPacket::create(
                    NetworkSettingsPacket::COMPRESS_EVERYTHING,
                    CompressionAlgorithm::ZLIB,
                    false,
                    0,
                    0
                ), true);

                $event->cancel();
                break;
        }

    }
}
