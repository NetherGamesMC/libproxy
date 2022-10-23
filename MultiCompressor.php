<?php

declare(strict_types=1);


namespace libproxy;


use ErrorException;
use GlobalLogger;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\SingletonTrait;
use RuntimeException;
use function zstd_uncompress;

class MultiCompressor implements Compressor
{
    public const ZSTD_COMPRESSION_LEVEL = -1;

    public const METHOD_ZLIB = 0x00;
    public const METHOD_ZSTD = 0x01;

    use SingletonTrait;

    public function willCompress(string $data): bool
    {
        return true;
    }

    public function decompress(string $payload): string
    {
        $stream = new BinaryStream($payload);

        try {
            $method = $stream->getByte();

            try {
                $result = match ($method) {
                    self::METHOD_ZLIB => ZlibCompressor::getInstance()->decompress($stream->getRemaining()),
                    self::METHOD_ZSTD => zstd_uncompress($stream->getRemaining()),
                    default => throw new DecompressionException("Decompression method not found"),
                };
            } catch (ErrorException $exception) {
                throw new DecompressionException('Failed to decompress data', 0, $exception);
            }
        } catch (BinaryDataException $exception) {
            throw new DecompressionException("Decompression method is invalid");
        }

        if ($result === false) {
            throw new DecompressionException("Failed to decompress data");
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
        return ZlibCompressor::getInstance()->compress($payload);
    }
}
