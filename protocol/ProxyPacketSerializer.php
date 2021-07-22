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
        return $this->get($this->getShort());
    }

    public function putIp(string $ip): void
    {
        $this->putShort(strlen($ip));
        $this->put($ip);
    }
}
