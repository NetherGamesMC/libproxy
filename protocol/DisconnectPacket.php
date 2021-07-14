<?php

declare(strict_types=1);


namespace libproxy\protocol;


class DisconnectPacket extends ProxyPacket
{
    public const NETWORK_ID = ProxyProtocolInfo::DISCONNECT_PACKET;

    public function encodePayload(ProxyPacketSerializer $out): void
    {

    }

    public function decodePayload(ProxyPacketSerializer $in): void
    {

    }
}