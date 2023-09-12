<?php

namespace libproxy;

use Exception;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\Server;
use ReflectionClass;
use function method_exists;

class PMUtils
{
    public static function getPacketSerializerContext(Server $server): PacketSerializerContext
    {
        if (method_exists($server, 'getPacketSerializerContext')) {
            return $server->getPacketSerializerContext(TypeConverter::getInstance());
        }

        $packetSerializerContext = self::getRaklibInterfacePropertyValue($server, 'packetSerializerContext');
        if ($packetSerializerContext instanceof PacketSerializerContext) {
            return $packetSerializerContext;
        }

        throw new Exception("PacketSerializerContext isn't valid");
    }

    public static function getPacketBroadcaster(Server $server): PacketBroadcaster
    {
        if (method_exists($server, 'getPacketBroadcaster')) {
            return $server->getPacketBroadcaster(self::getPacketSerializerContext($server));
        }

        $packetBroadcaster = self::getRaklibInterfacePropertyValue($server, 'packetBroadcaster');
        if ($packetBroadcaster instanceof PacketBroadcaster) {
            return $packetBroadcaster;
        }

        throw new Exception("PacketBroadcaster isn't valid");
    }

    public static function getEntityEventBroadcaster(Server $server): EntityEventBroadcaster
    {
        if (method_exists($server, 'getEntityEventBroadcaster')) {
            return $server->getEntityEventBroadcaster(self::getPacketBroadcaster($server), TypeConverter::getInstance());
        }

        $entityEventBroadcaster = self::getRaklibInterfacePropertyValue($server, 'entityEventBroadcaster');
        if ($entityEventBroadcaster instanceof EntityEventBroadcaster) {
            return $entityEventBroadcaster;
        }

        throw new Exception("EntityEventBroadcaster isn't valid");
    }

    private static function getRaklibInterfacePropertyValue(Server $server, string $propertyName): mixed
    {
        $interface = self::getRaklibInterface($server);
        $reflection = new ReflectionClass($interface);
        $property = $reflection->getProperty($propertyName);

        return $property->getValue($interface);
    }

    /**
     * @throws Exception
     */
    private static function getRaklibInterface(Server $server): RakLibInterface
    {
        foreach ($server->getNetwork()->getInterfaces() as $interface) {
            if ($interface instanceof RakLibInterface) {
                return $interface;
            }
        }

        throw new Exception("Raklib interface hasn't been registered");
    }
}