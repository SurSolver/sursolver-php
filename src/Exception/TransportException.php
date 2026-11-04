<?php

declare(strict_types=1);

namespace SurSolver\Exception;

/**
 * No usable answer: a connection failure, a timeout, a non-200 status, or a body that is not the
 * API's envelope. It says nothing about the task.
 */
final class TransportException extends SurSolverException
{
    /**
     * @param int|null $status The HTTP status when a response arrived.
     * @param string|null $serverCode error.code of the API's internal envelope, when present.
     * @param int|null $retryAfterSeconds error.retry_after_seconds of the internal envelope, when present.
     */
    public function __construct(
        string $operation,
        string $reason,
        public readonly ?int $status = null,
        public readonly ?string $serverCode = null,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        $note = $operation === 'createTask'
            ? ' The task may have been created and charged; check getBalance() before submitting again.'
            : '';
        parent::__construct("{$operation} failed: {$reason}.{$note}", $operation, $previous);
    }

    /**
     * True for createTask: the request may have been accepted and charged before the answer was
     * lost. Check getBalance() before submitting again.
     */
    public function mayHaveCreatedTask(): bool
    {
        return $this->operation === 'createTask';
    }
}
