<?php

declare(strict_types=1);


namespace libproxy\protocol;


class DisconnectPacket extends ProxyPacket
{
    public const NETWORK_ID = ProxyProtocolInfo::DISCONNECT_PACKET;

    /** @var string */
    public string $reason;

    public function encodePayload(ProxyPacketSerializer $out): void
    {
        $out->put($this->reason);
    }

    public function decodePayload(ProxyPacketSerializer $in): void
    {
        $this->reason = $in->getRemaining();
    }
}