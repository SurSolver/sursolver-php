<?php

declare(strict_types=1);

namespace SurSolver;

/** The real clock: monotonic seconds and usleep. */
final class SystemClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }

    public function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1e6));
    }
}
