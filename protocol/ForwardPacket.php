<?php

declare(strict_types=1);


namespace libproxy\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;

class ForwardPacket extends ProxyPacket
{
    public const int NETWORK_ID = ProxyProtocolInfo::FORWARD_PACKET;

    /** @var string */
    public string $payload;

    public function encodePayload(ByteBufferWriter $out): void
    {
        $out->writeByteArray($this->payload);
    }

    public function decodePayload(ByteBufferReader $in): void
    {
        $this->payload = $in->readByteArray($in->getUnreadLength());
    }
}