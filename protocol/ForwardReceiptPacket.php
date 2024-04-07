<?php

declare(strict_types=1);


namespace libproxy\protocol;


class ForwardReceiptPacket extends ForwardPacket
{
    public const NETWORK_ID = ProxyProtocolInfo::FORWARD_RECEIPT_PACKET;

    public int $receiptId;

    public function encodePayload(ProxyPacketSerializer $out): void
    {
        $out->putUnsignedVarInt($this->receiptId);

        parent::encodePayload($out);
    }

    public function decodePayload(ProxyPacketSerializer $in): void
    {
        $this->receiptId = $in->getUnsignedVarInt();

        parent::decodePayload($in);
    }
}