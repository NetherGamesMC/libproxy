<?php

declare(strict_types=1);


namespace libproxy\protocol;


class ForwardPacket extends ProxyPacket
{
    public const NETWORK_ID = ProxyProtocolInfo::FORWARD_PACKET;

    /** @var string */
    public string $payload;

    public function encodePayload(ProxyPacketSerializer $out): void
    {
        $out->put($this->payload);
    }

    public function decodePayload(ProxyPacketSerializer $in): void
    {
        $this->payload = $in->getRemaining();
    }
}