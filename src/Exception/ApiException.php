<?php

declare(strict_types=1);

namespace SurSolver\Exception;

use SurSolver\ErrorCodes;

/**
 * The API answered errorId 1. Catch the subclasses for the codes you handle; this class itself
 * means ERROR_INTERNAL_ERROR, ERROR_FAILED on create, or a code newer than this package.
 */
class ApiException extends SurSolverException
{
    private const array TASK_FAILURE_CODES = [
        ErrorCodes::ERROR_FAILED, ErrorCodes::ERROR_TASK_TIMEOUT, ErrorCodes::ERROR_TASK_EXPIRED,
        ErrorCodes::ERROR_WRONG_USERAGENT,
    ];

    private const array CLASS_BY_CODE = [
        ErrorCodes::ERROR_WRONG_CLIENT_KEY => AuthenticationException::class,
        ErrorCodes::ERROR_KEY_DOES_NOT_EXIST => AuthenticationException::class,
        ErrorCodes::ERROR_INVALID_REQUEST => InvalidTaskException::class,
        ErrorCodes::ERROR_TYPE_NOT_SUPPORTED => InvalidTaskException::class,
        ErrorCodes::ERROR_WRONG_USERAGENT => InvalidTaskException::class,
        ErrorCodes::ERROR_ZERO_BALANCE => InsufficientBalanceException::class,
        ErrorCodes::ERROR_NO_SLOT_AVAILABLE => NoSlotAvailableException::class,
        ErrorCodes::ERROR_TASK_NOT_FOUND => TaskNotFoundException::class,
    ];

    /**
     * @param string $errorCode The errorCode exactly as sent, including codes newer than this package
     *     (Exception::getCode() stays the unused integer 0).
     * @param string $description The errorDescription as sent.
     * @param string|null $taskId Set for getTaskResult and for solve() after the task was accepted.
     * @param list<string> $supportedUserAgents The list sent with ERROR_WRONG_USERAGENT; empty otherwise.
     */
    final public function __construct(
        string $operation,
        public readonly string $errorCode,
        public readonly string $description,
        public readonly ?string $taskId = null,
        public readonly array $supportedUserAgents = [],
    ) {
        $task = $taskId === null ? '' : " (task {$taskId})";
        parent::__construct("{$errorCode}: {$description}{$task}", $operation);
    }

    /** True only for ERROR_NO_SLOT_AVAILABLE. */
    public function isRetryable(): bool
    {
        return $this->errorCode === ErrorCodes::ERROR_NO_SLOT_AVAILABLE;
    }

    /**
     * The error for a code; the class depends on the operation and the code.
     *
     * @param list<string> $supported
     */
    public static function fromCode(string $operation, string $code, string $description, ?string $taskId, array $supported): self
    {
        $class = $operation === 'getTaskResult' && in_array($code, self::TASK_FAILURE_CODES, true)
            ? TaskFailedException::class
            : (self::CLASS_BY_CODE[$code] ?? self::class);

        return new $class($operation, $code, $description, $taskId, $supported);
    }
}
