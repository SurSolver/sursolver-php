<?php

declare(strict_types=1);

namespace SurSolver;

/** What solve() returns. Tokens and cookies are single-use. */
final readonly class SolveResult
{
    /** @param array<string, mixed> $solution passed through untouched */
    public function __construct(public string $taskId, public array $solution)
    {
    }
}
