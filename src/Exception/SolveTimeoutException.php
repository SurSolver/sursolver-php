<?php

declare(strict_types=1);

namespace SurSolver\Exception;

/**
 * solve() reached its deadline. The task may still finish and stays charged; poll it with
 * getTaskResult($taskId) if you still need the result.
 */
final class SolveTimeoutException extends SurSolverException
{
    /**
     * @param float $timeout The deadline that passed, in seconds.
     * @param SurSolverException|null $lastError The final poll's transient failure, when there was one.
     */
    public function __construct(
        public readonly string $taskId,
        public readonly float $timeout,
        public readonly ?SurSolverException $lastError,
    ) {
        parent::__construct(
            "task {$taskId} was not ready within {$timeout} s; it may still finish and stays charged",
            null,
            $lastError,
        );
    }
}
