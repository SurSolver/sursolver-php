<?php

declare(strict_types=1);

namespace SurSolver\Exception;

/** Base of every error the client throws. Messages never contain the API key. */
abstract class SurSolverException extends \RuntimeException
{
    /**
     * @param string|null $operation The call that failed (createTask, getTaskResult, getBalance,
     *     listTaskTypes, listServices); null for configuration errors and solve timeouts.
     */
    public function __construct(string $message, public readonly ?string $operation = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
