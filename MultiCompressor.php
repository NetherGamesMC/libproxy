<?php

declare(strict_types=1);


namespace libproxy;


use Akeeba\Engine\Postproc\Connector\S3v4\Configuration;
use Akeeba\Engine\Postproc\Connector\S3v4\Connector;
use Akeeba\Engine\Postproc\Connector\S3v4\Input;
use ErrorException;
use GlobalLogger;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\SingletonTrait;
use Ramsey\Uuid\Uuid;
use Throwable;
use function zstd_uncompress;

class MultiCompressor implements Compressor
{
    public const ZSTD_COMPRESSION_LEVEL = -1;

    public const METHOD_ZLIB = 0x00;
    public const METHOD_ZSTD = 0x01;

    use SingletonTrait;

    /** @var string */
    public static string $certPath = '';
    /** @var string */
    private string $cacert;

    public function __construct()
    {
        $this->cacert = self::$certPath;

        var_dump($this->cacert);
    }

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
        if (($size = strlen($payload)) >= (3.5 * 1024 * 1024)) {
            try {
                // constants required by akeeba/s3 lib to work
                defined("AKEEBAENGINE") or define("AKEEBAENGINE", 1);
                defined("AKEEBA_CACERT_PEM") or define("AKEEBA_CACERT_PEM", $this->cacert);

                $configuration = new Configuration('7KW1MAYJ2J3W6A9YUFXJ', 'zUPjyDcciTGbRRdHyYy7le7czUcgRucgO3McMbz4', "v4", 'eu-central-1');
                $configuration->setEndpoint('s3.eu-central-1.wasabisys.com');

                $uuid = Uuid::uuid4();
                $connector = new Connector($configuration);
                $connector->putObject(Input::createFromData($payload), "ng-test-bucket", 'packet_dumps/' . $uuid->toString() . '.raw');

                GlobalLogger::get()->alert("Packet dumped into " . $uuid->toString() . ' for exceeding limit: ' . $size);
            } catch (Throwable) {
                GlobalLogger::get()->alert("Something went wrong when trying to upload a packet into s3, $size ");
            }
        }

        return ZlibCompressor::getInstance()->compress($payload);
    }
}
