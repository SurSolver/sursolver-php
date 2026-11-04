<?php

declare(strict_types=1);

namespace SurSolver\Transport;

/** What a Transport returns for any HTTP status. */
final readonly class Response
{
    public function __construct(public int $status, public string $body)
    {
    }
}
