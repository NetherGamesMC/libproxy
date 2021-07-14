<?php

declare(strict_types=1);


namespace libproxy;


use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\utils\SingletonTrait;

class EmptyCompressor implements Compressor
{
    use SingletonTrait;

    public function willCompress(string $data): bool
    {
        return false;
    }

    public function decompress(string $payload): string
    {
        return $payload;
    }

    public function compress(string $payload): string
    {
        return $payload;
    }
}