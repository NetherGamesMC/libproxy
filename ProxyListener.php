<?php

declare(strict_types=1);


namespace libproxy;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\TickSyncPacket;

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

        if ($packet->pid() === NetworkStackLatencyPacket::NETWORK_ID) {
            /** @var NetworkStackLatencyPacket $packet USED FOR PING CALCULATIONS */
            if ($packet->timestamp === 0 && $packet->needResponse) {
                if (($player = $origin->getPlayer()) !== null && $player->isConnected()) {
                    $origin->sendDataPacket(NetworkStackLatencyPacket::response(0));
                }
                $event->cancel();
            }
        }

        if($packet->pid() === TickSyncPacket::NETWORK_ID){
            /** @var TickSyncPacket $packet */
            $origin->updatePing($packet->getClientSendTime() + $packet->getServerReceiveTime());
        }
    }
}