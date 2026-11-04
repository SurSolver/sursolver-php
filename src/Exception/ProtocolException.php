<?php

declare(strict_types=1);

namespace SurSolver\Exception;

/** HTTP 200 JSON whose shape the API contract does not allow. */
final class ProtocolException extends SurSolverException
{
    public function __construct(string $operation, string $detail)
    {
        parent::__construct("{$operation} returned an unexpected response: {$detail}", $operation);
    }
}
