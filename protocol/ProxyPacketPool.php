<?php

declare(strict_types=1);


namespace libproxy\protocol;


use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use SplFixedArray;

class ProxyPacketPool
{
    /** @var self|null */
    protected static ?ProxyPacketPool $instance = null;
    /** @var SplFixedArray<ProxyPacket> */
    protected SplFixedArray $pool;

    public function __construct()
    {
        $this->pool = new SplFixedArray(256);

        $this->registerPacket(new LoginPacket());
        $this->registerPacket(new DisconnectPacket());
        $this->registerPacket(new ForwardPacket());
        $this->registerPacket(new ForwardReceiptPacket());
        $this->registerPacket(new AckPacket());
    }

    public function registerPacket(ProxyPacket $packet): void
    {
        $this->pool[$packet->pid()] = clone $packet;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * @throws DataDecodeException
     */
    public function getPacket(ByteBufferReader $stream): ?ProxyPacket
    {
        return $this->getPacketById(VarInt::readUnsignedInt($stream));
    }

    public function getPacketById(int $pid): ?ProxyPacket
    {
        return isset($this->pool[$pid]) ? clone $this->pool[$pid] : null;
    }
}