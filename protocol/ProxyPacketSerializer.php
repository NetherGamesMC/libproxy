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
        $ip = $this->get($this->getShort());
        if ($ip === "127.0.0.1" || $ip === "localhost" || filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        throw new BinaryDataException("Invalid IP provided");
    }

    public function putIp(string $ip): void
    {
        if ($ip === "127.0.0.1" || $ip === "localhost" || filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->putShort(strlen($ip));
            $this->put($ip);
        }

        throw new BinaryDataException("Invalid IP provided");
    }
}
