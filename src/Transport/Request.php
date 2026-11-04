<?php

declare(strict_types=1);

namespace SurSolver\Transport;

/** One HTTP request handed to a Transport. */
final readonly class Request
{
    /**
     * @param 'GET'|'POST' $method
     * @param array<string, string> $headers Accept, User-Agent, and Content-Type with a body
     * @param string|null $body UTF-8 JSON body
     * @param float $timeout how long the request may take in total, in seconds
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public float $timeout,
    ) {
    }
}
