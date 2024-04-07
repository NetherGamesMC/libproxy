<?php

declare(strict_types=1);


namespace libproxy\protocol;


class AckPacket extends ProxyPacket
{
    public const NETWORK_ID = ProxyProtocolInfo::ACK_PACKET;

    public int $receiptId;

    public function encodePayload(ProxyPacketSerializer $out): void
    {
        $out->putUnsignedVarInt($this->receiptId);
    }

    public function decodePayload(ProxyPacketSerializer $in): void
    {
        $this->receiptId = $in->getUnsignedVarInt();
    }
}