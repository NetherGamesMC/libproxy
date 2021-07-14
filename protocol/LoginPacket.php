<?php

declare(strict_types=1);


namespace libproxy\protocol;


class LoginPacket extends ProxyPacket
{
    public const NETWORK_ID = ProxyProtocolInfo::LOGIN_PACKET;

    /** @var string */
    public string $ip;
    /** @var int */
    public int $port;

    public function encodePayload(ProxyPacketSerializer $out): void
    {
        $out->putIp($this->ip);
        $out->putShort($this->port);
    }

    public function decodePayload(ProxyPacketSerializer $in): void
    {
        $this->ip = $in->getIp();
        $this->port = $in->getShort();
    }
}