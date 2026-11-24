<?php

declare(strict_types=1);

namespace PikaRelay\Tests;

use PikaRelay\Clock;

final class FixedClock extends Clock
{
    /** @var int */
    public $now;

    public function __construct(int $now)
    {
        $this->now = $now;
    }

    public function nowMs(): int
    {
        return $this->now;
    }

    public function advance(int $ms): void
    {
        $this->now += $ms;
    }
}
