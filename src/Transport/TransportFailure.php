<?php

declare(strict_types=1);

namespace SurSolver\Transport;

/** Thrown by a Transport when no response arrived. */
final class TransportFailure extends \RuntimeException
{
    /** @param bool $timedOut true when Request::$timeout passed */
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
