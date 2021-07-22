<?php

declare(strict_types=1);

namespace libproxy\protocol;

use pocketmine\utils\BinaryStream;
use function strlen;

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
