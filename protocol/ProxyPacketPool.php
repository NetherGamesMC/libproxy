<?php

declare(strict_types=1);


namespace libproxy\protocol;


use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
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
     * @throws BinaryDataException
     */
    public function getPacket(string $buffer, int $offset): ?ProxyPacket
    {
        return $this->getPacketById(Binary::readUnsignedVarInt($buffer, $offset));
    }

    public function getPacketById(int $pid): ?ProxyPacket
    {
        return isset($this->pool[$pid]) ? clone $this->pool[$pid] : null;
    }
}