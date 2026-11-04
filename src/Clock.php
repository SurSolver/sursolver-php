<?php

declare(strict_types=1);

namespace SurSolver;

/** Lets tests drive time without waiting. Times are in seconds. */
interface Clock
{
    public function now(): float;

    public function sleep(float $seconds): void;
}
