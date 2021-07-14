<?php

declare(strict_types=1);

namespace libproxy\protocol;

use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use function filter_var;
use function strlen;
use const FILTER_VALIDATE_IP;

class ProxyPacketSerializer extends BinaryStream
{
    public function getIp(): string
    {
        if (($ipLength = $this->getShort()) <= 15 && filter_var($ip = $this->get($ipLength), FILTER_VALIDATE_IP)) {
            return $ip;
        }

        throw new BinaryDataException("Invalid IP provided");
    }

    public function putIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->putShort(strlen($ip));
            $this->put($ip);
        }

        throw new BinaryDataException("Invalid IP provided");
    }
}
