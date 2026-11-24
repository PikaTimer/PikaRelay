<?php

declare(strict_types=1);

namespace PikaRelay;

/**
 * Source of the current time in epoch milliseconds. Tests substitute a fixed clock.
 */
class Clock
{
    public function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
