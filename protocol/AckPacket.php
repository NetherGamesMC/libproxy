<?php

declare(strict_types=1);


namespace libproxy\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;

class AckPacket extends ProxyPacket
{
    public const int NETWORK_ID = ProxyProtocolInfo::ACK_PACKET;

    public int $receiptId;

    public function encodePayload(ByteBufferWriter $out): void
    {
        VarInt::writeUnsignedInt($out, $this->receiptId);
    }

    public function decodePayload(ByteBufferReader $in): void
    {
        $this->receiptId = VarInt::readUnsignedInt($in);
    }
}