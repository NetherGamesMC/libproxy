<?php

declare(strict_types=1);

namespace libproxy\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use function strlen;

class CommonTypes
{
    /**
     * @throws DataDecodeException
     */
    public static function getIp(ByteBufferReader $in): string
    {
        return $in->readByteArray(LE::readUnsignedShort($in));
    }

    public static function putIp(ByteBufferWriter $out, string $ip): void
    {
        LE::writeUnsignedShort($out, strlen($ip));
        $out->writeByteArray($ip);
    }
}
