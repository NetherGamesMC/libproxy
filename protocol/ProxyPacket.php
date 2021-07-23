<?php

declare(strict_types=1);


namespace libproxy\protocol;


use pocketmine\utils\BinaryDataException;

abstract class ProxyPacket
{
    public const NETWORK_ID = 0;

    public function pid(): int
    {
        return $this::NETWORK_ID;
    }

    final public function encode(ProxyPacketSerializer $out): void
    {
        $out->putUnsignedVarInt(static::NETWORK_ID);
        $this->encodePayload($out);
    }

    abstract public function encodePayload(ProxyPacketSerializer $out): void;

    /**
     * @throws BinaryDataException
     */
    final public function decode(ProxyPacketSerializer $in): void
    {
        $in->getUnsignedVarInt();
        $this->decodePayload($in);
    }

    /**
     * @throws BinaryDataException
     */
    abstract public function decodePayload(ProxyPacketSerializer $in): void;
}