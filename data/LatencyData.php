<?php

declare(strict_types=1);

namespace libproxy\data;

/**
 * Player latency data, any program can access this data to manipulate specific things
 * (i.e. anticheat)
 */
class LatencyData
{
    public function __construct(
        private int $upstream,
        private int $downstream,
    )
    {

    }

    public function getLatency(): int
    {
        return $this->getUpstream() + $this->getDownstream();
    }

    public function getUpstream(): int
    {
        return $this->upstream;
    }

    public function getDownstream(): int
    {
        return $this->downstream;
    }
}