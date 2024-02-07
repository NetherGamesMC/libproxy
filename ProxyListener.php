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

        /** @var NetworkStackLatencyPacket $packet USED FOR PING CALCULATIONS */
        if ($packet->pid() == NetworkStackLatencyPacket::NETWORK_ID) {
            if ($packet->timestamp === 0 && $packet->needResponse) {
                if (($player = $origin->getPlayer()) !== null && $player->isConnected()) {
                    $origin->sendDataPacket(NetworkStackLatencyPacket::response(0));
                }
                $event->cancel();
            }
        }
    }
}
