<?php

declare(strict_types=1);


namespace libproxy\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;

class DisconnectPacket extends ProxyPacket
{
    public const int NETWORK_ID = ProxyProtocolInfo::DISCONNECT_PACKET;

    /** @var string */
    public string $reason;

    public function encodePayload(ByteBufferWriter $out): void
    {
        $out->writeByteArray($this->reason);
    }

    public function decodePayload(ByteBufferReader $in): void
    {
        $this->reason = $in->readByteArray($in->getUnreadLength());
    }
}