<?php

declare(strict_types=1);


namespace libproxy;


use libproxy\protocol\ProxyPacketSerializer;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\SingletonTrait;
use function strlen;
use function zlib_decode;
use function zstd_compress;
use function zstd_uncompress;

class ProxyCompressor implements Compressor
{
    public const ZSTD_COMPRESSION_LEVEL = -1;

    public const COMPRESSION_ZSTD = 1;
    public const COMPRESSION_ZLIB = 2;

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
     * Decompression is done on the main thread normally, we're decompressing this on the proxy thread when async is enabled
     *
     * @param string $payload
     * @return string
     */
    public function decompress(string $payload): string
    {
        if ($this->asyncDecompress) {
            return $payload;
        }

        try {
            $stream = new ProxyPacketSerializer($payload);
            $compressionMethod = $stream->getByte();

            if ($compressionMethod === self::COMPRESSION_ZSTD) {
                $result = zstd_uncompress($stream->getRemaining());
            } elseif ($compressionMethod === self::COMPRESSION_ZLIB) {
                $result = zlib_decode($stream->getRemaining(), ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE);
            } else {
                $result = false;
            }

            if ($result === false) {
                throw new DecompressionException("Failed to decompress data");
            }
        } catch (BinaryDataException $exception) {
            throw new DecompressionException("Compression method not found", 0, $exception);
        }

        return $result;
    }

    /**
     * The proxy needs to know the length of the string before compression for allocating buffers (JAVA)
     * @see decompress() doesn't need this as it's not send back by the Proxy, since we don't need it
     *
     * @param string $payload
     * @return string
     */
    public function compress(string $payload): string
    {
        $result = zstd_compress($payload, self::ZSTD_COMPRESSION_LEVEL);

        if ($result === false) {
            throw new AssumptionFailedError("ZSTD compression failed");
        }

        return Binary::writeInt(strlen($payload)) . $result;
    }
}