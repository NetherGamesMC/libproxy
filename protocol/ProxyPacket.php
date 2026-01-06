<?php

declare(strict_types=1);


namespace libproxy\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;

abstract class ProxyPacket
{
    public const int NETWORK_ID = 0;

    public function pid(): int
    {
        return $this::NETWORK_ID;
    }

    final public function encode(ByteBufferWriter $out): void
    {
        VarInt::writeUnsignedInt($out, $this::NETWORK_ID);
        $this->encodePayload($out);
    }

    abstract public function encodePayload(ByteBufferWriter $out): void;

    /**
     * @throws DataDecodeException
     */
    final public function decode(ByteBufferReader $in): void
    {
        VarInt::readUnsignedInt($in);
        $this->decodePayload($in);
    }

    /**
     * @throws DataDecodeException
     */
    abstract public function decodePayload(ByteBufferReader $in): void;
}