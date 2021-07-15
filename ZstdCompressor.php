<?php

declare(strict_types=1);


namespace libproxy;


use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\SingletonTrait;
use function zstd_compress;
use function zstd_uncompress;

class ZstdCompressor implements Compressor
{
    public const ZSTD_COMPRESSION_LEVEL = -1;

    /** @var bool */
    private bool $asyncDecompress;

    use SingletonTrait;

    public function __construct(bool $asyncDecompress = false)
    {
        $this->asyncDecompress = $asyncDecompress;
    }

    public function willCompress(string $data): bool
    {
        return true;
    }

    /**
     * Decompression is done on the main thread, we're decompressing this on the proxy thread
     * @param string $payload
     * @return string
     */
    public function decompress(string $payload): string
    {
        if ($this->asyncDecompress) {
            return $payload;
        }

        $result = zstd_uncompress($payload);

        if ($result === false) {
            throw new DecompressionException("Failed to decompress data");
        }

        return $result;
    }

    public function compress(string $payload): string
    {
        $result = zstd_compress($payload, self::ZSTD_COMPRESSION_LEVEL);

        if ($result === false) {
            throw new AssumptionFailedError("ZSTD compression failed");
        }

        return $result;
    }
}