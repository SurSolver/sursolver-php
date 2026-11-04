<?php

declare(strict_types=1);

namespace SurSolver;

/** One entry of GET /v1/services. */
final readonly class ServiceInfo
{
    /**
     * @param string $pricePerRequest USD price per task as a decimal string, e.g. "0.0006"
     * @param int|null $avgLatencyMs recent average latency in milliseconds, when known
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $displayName,
        public string $pricePerRequest,
        public string $status,
        public ?int $avgLatencyMs,
    ) {
    }
}
