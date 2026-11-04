<?php

declare(strict_types=1);

namespace SurSolver;

/**
 * One getTaskResult answer. Solution keys differ per type (token, gRecaptchaResponse, cf_clearance,
 * cookies, ...) and are passed through untouched. Tokens are single-use.
 */
final readonly class TaskResult
{
    public const string PROCESSING = 'processing';
    public const string READY = 'ready';

    /**
     * @param string $status self::PROCESSING or self::READY
     * @param array<string, mixed>|null $solution the solution when ready
     */
    public function __construct(public string $status, public ?array $solution)
    {
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }
}
