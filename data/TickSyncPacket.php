<?php

declare(strict_types=1);

namespace libproxy\data;

use libproxy\ProxyNetworkInterface;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\PacketHandlerInterface;

class TickSyncPacket extends \pocketmine\network\mcpe\protocol\TickSyncPacket
{
    public function handle(PacketHandlerInterface $handler): bool
    {
        if (!($handler instanceof InGamePacketHandler)) {
            return false;
        }

        /** @var NetworkSession $session */
        $session = (function () {
            /** @noinspection PhpUndefinedFieldInspection */
            return $this->session;
        })->call($handler);

        ProxyNetworkInterface::handleRawLatency($session, $this->getClientSendTime(), $this->getServerReceiveTime());
        return true;
    }
}