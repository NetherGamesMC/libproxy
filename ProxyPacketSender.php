<?php

declare(strict_types=1);


namespace libproxy;


use libproxy\protocol\ForwardPacket;
use pocketmine\network\mcpe\PacketSender;

class ProxyPacketSender implements PacketSender
{

    /** @var int */
    private int $socketId;
    /** @var ProxyNetworkInterface */
    private ProxyNetworkInterface $handler;

    /** @var bool */
    private bool $closed = false;

    public function __construct(int $socketId, ProxyNetworkInterface $handler)
    {
        $this->socketId = $socketId;
        $this->handler = $handler;
    }

    public function send(string $payload, bool $immediate, ?int $receiptId): void
    {
        if (!$this->closed) {
            $pk = new ForwardPacket();
            $pk->payload = $payload;

            $this->handler->putPacket($this->socketId, $pk, $receiptId);
        }
    }

    public function close(string $reason = "unknown reason"): void
    {
        if (!$this->closed) {
            $this->closed = true;

            // We don't need to call onClientDisconnect() when player is already being kicked by the server.
            $this->handler->close($this->socketId, $reason, false, true);
        }
    }
}