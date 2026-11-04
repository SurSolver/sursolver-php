<?php

declare(strict_types=1);

namespace SurSolver;

/** One entry of GET /v1/task-types. */
final readonly class TaskTypeInfo
{
    /**
     * @param string|null $pageSlug solver page slug at https://sursolver.com/solvers/<slug>, when active
     * @param list<string> $aliases other accepted spellings
     * @param string $proxy none, optional or required
     * @param list<list<string>> $required groups of accepted spellings; supply one name per group
     * @param list<list<string>> $anyOf alternatives; supply every field of at least one
     * @param list<string> $optional optional fields
     * @param list<string> $solutionKeys keys of the solution object
     */
    public function __construct(
        public ?string $pageSlug,
        public string $displayName,
        public string $taskType,
        public ?string $taskTypeProxyless,
        public array $aliases,
        public string $proxy,
        public array $required,
        public array $anyOf,
        public array $optional,
        public array $solutionKeys,
    ) {
    }
}
