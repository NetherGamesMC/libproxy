<?php

declare(strict_types=1);


namespace libproxy\protocol;


final class ProxyProtocolInfo
{
    public const int LOGIN_PACKET = 0x01;
    public const int DISCONNECT_PACKET = 0x02;
    public const int FORWARD_PACKET = 0x03;
    public const int FORWARD_RECEIPT_PACKET = 0x04;
    public const int ACK_PACKET = 0x05;
}