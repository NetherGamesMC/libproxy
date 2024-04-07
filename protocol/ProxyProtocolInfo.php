<?php

declare(strict_types=1);


namespace libproxy\protocol;


final class ProxyProtocolInfo
{
    public const LOGIN_PACKET = 0x01;
    public const DISCONNECT_PACKET = 0x02;
    public const FORWARD_PACKET = 0x03;
    public const FORWARD_RECEIPT_PACKET = 0x04;
    public const ACK_PACKET = 0x05;
}