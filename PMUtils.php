<?php

namespace libproxy;

use Exception;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\Server;
use function method_exists;

class PMUtils
{
    public static function getPacketBroadcaster(Server $server): PacketBroadcaster
    {
        if (method_exists($server, 'getPacketBroadcaster')) {
            return $server->getPacketBroadcaster(ProtocolInfo::CURRENT_PROTOCOL);
        }

        return new StandardPacketBroadcaster($server, ProtocolInfo::CURRENT_PROTOCOL);
    }

    public static function getEntityEventBroadcaster(Server $server, PacketBroadcaster $packetBroadcaster): EntityEventBroadcaster
    {
        if (method_exists($server, 'getEntityEventBroadcaster')) {
            return $server->getEntityEventBroadcaster($packetBroadcaster, TypeConverter::getInstance());
        }

        return new StandardEntityEventBroadcaster($packetBroadcaster, TypeConverter::getInstance());
    }
}