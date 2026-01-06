<?php

declare(strict_types=1);


namespace libproxy\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;

class LoginPacket extends ProxyPacket
{
    public const int NETWORK_ID = ProxyProtocolInfo::LOGIN_PACKET;

    /** @var string */
    public string $ip;
    /** @var int */
    public int $port;

    public function encodePayload(ByteBufferWriter $out): void
    {
        CommonTypes::putIp($out, $this->ip);
        LE::writeUnsignedShort($out, $this->port);
    }

    public function decodePayload(ByteBufferReader $in): void
    {
        $this->ip = CommonTypes::getIp($in);
        $this->port = LE::readUnsignedShort($in);
    }
}