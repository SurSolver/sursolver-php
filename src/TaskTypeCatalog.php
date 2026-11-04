<?php

declare(strict_types=1);

namespace SurSolver;

/** The public task-type catalog. */
final readonly class TaskTypeCatalog
{
    /**
     * @param list<TaskTypeInfo> $taskTypes every task type
     * @param list<string> $errorCodes every errorCode the solver endpoints send
     */
    public function __construct(public array $taskTypes, public array $errorCodes)
    {
    }
}
