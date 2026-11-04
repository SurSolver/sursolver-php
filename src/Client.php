<?php

declare(strict_types=1);

namespace SurSolver;

use SurSolver\Exception\ApiException;
use SurSolver\Exception\ConfigurationException;
use SurSolver\Exception\NoSlotAvailableException;
use SurSolver\Exception\ProtocolException;
use SurSolver\Exception\SolveTimeoutException;
use SurSolver\Exception\SurSolverException;
use SurSolver\Exception\TransportException;
use SurSolver\Transport\CurlTransport;
use SurSolver\Transport\Request;
use SurSolver\Transport\Response;
use SurSolver\Transport\Transport;
use SurSolver\Transport\TransportFailure;

/**
 * Client of the SurSolver captcha-solving API. Durations are in seconds.
 *
 * The API key travels only in request bodies and never appears in exceptions, var_dump() or
 * print_r() output.
 */
final class Client
{
    public const string VERSION = '0.1.0';
    public const string DEFAULT_BASE_URL = 'https://api.sursolver.com';
    public const float DEFAULT_REQUEST_TIMEOUT = 30.0;
    public const float DEFAULT_POLL_INTERVAL = 3.0;
    public const float DEFAULT_SOLVE_TIMEOUT = 210.0;
    public const int DEFAULT_MAX_SUBMIT_RETRIES = 1;
    /** Upper bound of the random delay added to a submit retry, in seconds. */
    public const float SUBMIT_RETRY_JITTER = 1.0;

    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Returns the API key. A closure keeps the key out of var_dump(), print_r(), var_export() and
     * serialize() (closures cannot be serialized).
     *
     * @var \Closure(): string
     */
    private readonly \Closure $apiKey;
    private readonly string $baseUrl;
    private readonly ?string $appId;
    private readonly string $userAgent;
    private readonly Transport $transport;
    private readonly Clock $clock;
    /** @var \Closure(): float */
    private readonly \Closure $random;

    /**
     * Builds a client; throws ConfigurationException for an unusable option (nothing is sent).
     *
     * @param Transport|null $transport replaces the HTTP transport (proxies, custom TLS, tests)
     * @param Clock|null $clock replaces the real clock (tests)
     * @param (\Closure(): float)|null $random replaces the jitter source; returns values in [0, 1)
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly float $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT,
        private readonly float $pollInterval = self::DEFAULT_POLL_INTERVAL,
        private readonly float $solveTimeout = self::DEFAULT_SOLVE_TIMEOUT,
        private readonly int $maxSubmitRetries = self::DEFAULT_MAX_SUBMIT_RETRIES,
        ?string $appId = null,
        ?string $userAgentSuffix = null,
        ?Transport $transport = null,
        ?Clock $clock = null,
        ?\Closure $random = null,
    ) {
        if (trim($apiKey) === '') {
            throw new ConfigurationException('apiKey must be a non-empty string');
        }
        if (trim($apiKey) !== $apiKey) {
            throw new ConfigurationException('apiKey has leading or trailing whitespace');
        }
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        self::requirePositive('requestTimeout', $requestTimeout);
        self::requirePositive('pollInterval', $pollInterval);
        self::requirePositive('solveTimeout', $solveTimeout);
        if ($maxSubmitRetries < 0) {
            throw new ConfigurationException('maxSubmitRetries must not be negative');
        }
        $userAgent = 'sursolver-php/' . self::VERSION;
        if ($userAgentSuffix !== null) {
            if (preg_match('/\A[\x20-\x7e]+\z/', $userAgentSuffix) !== 1) {
                throw new ConfigurationException('userAgentSuffix must be non-empty printable ASCII');
            }
            $userAgent .= ' ' . $userAgentSuffix;
        }
        $this->apiKey = static fn (): string => $apiKey;
        $this->appId = $appId === null || trim($appId) === '' ? null : trim($appId);
        $this->userAgent = $userAgent;
        $this->transport = $transport ?? new CurlTransport();
        $this->clock = $clock ?? new SystemClock();
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /** @return array{baseUrl: string} never the API key */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl];
    }

    /**
     * Submits a task and returns its id. The task is charged from this point. It is never retried:
     * after a TransportException the task may exist (check getBalance() before submitting again).
     *
     * @param array<string, mixed> $task "type" plus that type's fields, sent verbatim. Browser types require "userAgent".
     */
    public function createTask(array $task): string
    {
        $payload = ['clientKey' => ($this->apiKey)(), 'task' => $task];
        if ($this->appId !== null) {
            $payload['appId'] = $this->appId;
        }
        $body = $this->solverCall('createTask', '/createTask', $payload);
        $taskId = $body->taskId ?? null;
        if (!is_string($taskId) || $taskId === '') {
            throw new ProtocolException('createTask', 'errorId 0 without a taskId');
        }

        return $taskId;
    }

    /** Reads a task's state once. A terminal failure throws TaskFailedException. */
    public function getTaskResult(string $taskId): TaskResult
    {
        $body = $this->solverCall('getTaskResult', '/getTaskResult', ['clientKey' => ($this->apiKey)(), 'taskId' => $taskId], $taskId);
        $status = $body->status ?? null;
        if ($status === TaskResult::PROCESSING) {
            return new TaskResult(TaskResult::PROCESSING, null);
        }
        if ($status === TaskResult::READY) {
            $solution = $body->solution ?? null;
            if (!$solution instanceof \stdClass) {
                throw new ProtocolException('getTaskResult', 'status ready without a solution object');
            }

            return new TaskResult(TaskResult::READY, self::objectToArray($solution));
        }

        throw new ProtocolException('getTaskResult', 'unknown status ' . json_encode($status));
    }

    /**
     * The cash balance in USD as the JSON number the API sends. Display it; do not do money
     * arithmetic on it (the dashboard's GET /v1/balance has the exact decimal).
     */
    public function getBalance(): float
    {
        $balance = $this->solverCall('getBalance', '/getBalance', ['clientKey' => ($this->apiKey)()])->balance ?? null;
        if (!(is_int($balance) || is_float($balance)) || !is_finite((float) $balance)) {
            throw new ProtocolException('getBalance', 'errorId 0 without a numeric balance');
        }

        return (float) $balance;
    }

    /**
     * Creates a task and polls it until it is ready, failed, or the deadline passes.
     *
     * createTask is resubmitted only after ERROR_NO_SLOT_AVAILABLE (nothing was created), at most
     * $maxSubmitRetries times; any other submit failure is thrown at once. Polls that fail with a
     * TransportException or ERROR_INTERNAL_ERROR are retried until the deadline; any other error ends
     * the call and carries the task id. On the deadline SolveTimeoutException is thrown; the task may
     * still finish.
     *
     * @param array<string, mixed> $task
     * @param (callable(string): void)|null $onTaskCreated called once with the task id as soon as the task is accepted
     */
    public function solve(array $task, ?float $pollInterval = null, ?float $solveTimeout = null, ?callable $onTaskCreated = null): SolveResult
    {
        $interval = $pollInterval ?? $this->pollInterval;
        $timeout = $solveTimeout ?? $this->solveTimeout;
        self::requirePositive('pollInterval', $interval);
        self::requirePositive('solveTimeout', $timeout);
        $deadline = $this->clock->now() + $timeout;

        for ($retries = 0; ; $retries++) {
            try {
                $taskId = $this->createTask($task);
                break;
            } catch (NoSlotAvailableException $busy) {
                if ($retries >= $this->maxSubmitRetries) {
                    throw $busy;
                }
                $jitterMs = floor(($this->random)() * self::SUBMIT_RETRY_JITTER * 1000);
                $this->clock->sleep($interval + $jitterMs / 1000);
            }
        }
        if ($onTaskCreated !== null) {
            $onTaskCreated($taskId);
        }

        while (true) {
            $lastError = null;
            try {
                $result = $this->getTaskResult($taskId);
                if ($result->isReady()) {
                    return new SolveResult($taskId, $result->solution ?? []);
                }
            } catch (TransportException $error) {
                $lastError = $error;
            } catch (ApiException $error) {
                if ($error->errorCode !== ErrorCodes::ERROR_INTERNAL_ERROR) {
                    throw $error;
                }
                $lastError = $error;
            }
            $this->clock->sleep($interval);
            if ($this->clock->now() >= $deadline) {
                throw new SolveTimeoutException($taskId, $timeout, $lastError);
            }
        }
    }

    /** The public task-type catalog: accepted names, fields, proxy modes and result keys. */
    public function listTaskTypes(): TaskTypeCatalog
    {
        $operation = 'listTaskTypes';
        $data = $this->catalogCall($operation, '/v1/task-types');
        $taskTypes = $data->task_types ?? null;
        $errorCodes = $data->error_codes ?? null;
        if (!is_array($taskTypes) || !is_array($errorCodes)) {
            throw new ProtocolException($operation, 'data without task_types and error_codes arrays');
        }
        $entries = [];
        foreach ($taskTypes as $raw) {
            $entry = self::entry($operation, $raw);
            $entries[] = new TaskTypeInfo(
                pageSlug: self::optionalText($operation, $entry, 'page_slug'),
                displayName: self::text($operation, $entry, 'display_name'),
                taskType: self::text($operation, $entry, 'task_type'),
                taskTypeProxyless: self::optionalText($operation, $entry, 'task_type_proxyless'),
                aliases: self::texts($operation, $entry->aliases ?? null, 'aliases'),
                proxy: self::text($operation, $entry, 'proxy'),
                required: self::groups($operation, $entry, 'required'),
                anyOf: self::groups($operation, $entry, 'any_of'),
                optional: self::texts($operation, $entry->optional ?? null, 'optional'),
                solutionKeys: self::texts($operation, $entry->solution_keys ?? null, 'solution_keys'),
            );
        }

        return new TaskTypeCatalog($entries, self::texts($operation, $errorCodes, 'error_codes'));
    }

    /**
     * Active services with their current USD price per task (decimal strings).
     *
     * @return list<ServiceInfo>
     */
    public function listServices(): array
    {
        $operation = 'listServices';
        $services = $this->catalogCall($operation, '/v1/services')->services ?? null;
        if (!is_array($services)) {
            throw new ProtocolException($operation, 'data without a services array');
        }
        $out = [];
        foreach ($services as $raw) {
            $entry = self::entry($operation, $raw);
            $noLatency = property_exists($entry, 'avg_latency_ms') && $entry->avg_latency_ms === null;
            $out[] = new ServiceInfo(
                id: self::integer($operation, $entry, 'id'),
                slug: self::text($operation, $entry, 'slug'),
                displayName: self::text($operation, $entry, 'display_name'),
                pricePerRequest: self::text($operation, $entry, 'price_per_request'),
                status: self::text($operation, $entry, 'status'),
                avgLatencyMs: $noLatency ? null : self::integer($operation, $entry, 'avg_latency_ms'),
            );
        }

        return $out;
    }

    private static function requirePositive(string $name, float $value): void
    {
        if (!($value > 0) || !is_finite($value)) {
            throw new ConfigurationException("{$name} must be a positive number of seconds");
        }
    }

    private static function normalizeBaseUrl(string $raw): string
    {
        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new ConfigurationException('baseUrl is not a valid absolute URL');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopbackHttp = $scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true);
        if ($scheme !== 'https' && !$loopbackHttp) {
            throw new ConfigurationException('baseUrl must use https:// (plain http:// only for localhost)');
        }
        if (isset($parts['user']) || isset($parts['pass']) || str_contains($raw, '?') || str_contains($raw, '#')) {
            throw new ConfigurationException('baseUrl must not carry credentials, a query or a fragment');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port . rtrim($parts['path'] ?? '', '/');
    }

    private function request(string $method, string $path, ?string $body): Request
    {
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return new Request($method === 'POST' ? 'POST' : 'GET', $this->baseUrl . $path, $headers, $body, $this->requestTimeout);
    }

    private function send(string $operation, Request $request): Response
    {
        try {
            $response = $this->transport->send($request);
        } catch (TransportFailure $failure) {
            $reason = $failure->timedOut
                ? 'request timed out after ' . (int) round($request->timeout * 1000) . ' ms'
                : 'connection failed';
            throw new TransportException($operation, $reason, previous: $failure);
        }
        if ($response->status === 200) {
            return $response;
        }
        $envelope = self::decode($response->body);
        $detail = $envelope instanceof \stdClass ? ($envelope->error ?? null) : null;
        $serverCode = $detail instanceof \stdClass && is_string($detail->code ?? null) ? $detail->code : null;
        $retry = $detail instanceof \stdClass ? ($detail->retry_after_seconds ?? null) : null;
        $retryAfter = is_int($retry) || is_float($retry) ? (int) $retry : null;
        $reason = $serverCode === null ? "HTTP {$response->status}" : "HTTP {$response->status} ({$serverCode})";

        throw new TransportException($operation, $reason, $response->status, $serverCode, $retryAfter);
    }

    private static ?\stdClass $notJson = null;

    private static function notJson(): \stdClass
    {
        return self::$notJson ??= new \stdClass();
    }

    /** The decoded body (objects as stdClass), or the notJson() marker. */
    private static function decode(string $body): mixed
    {
        try {
            return json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::notJson();
        }
    }

    /**
     * POSTs to a solver endpoint; returns the errorId 0 body or throws the classified error.
     *
     * @param array<string, mixed> $payload
     */
    private function solverCall(string $operation, string $path, array $payload, ?string $taskId = null): \stdClass
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $response = $this->send($operation, $this->request('POST', $path, $encoded));
        $body = self::decode($response->body);
        $isEnvelope = $body instanceof \stdClass && $body !== self::notJson();
        $errorId = $isEnvelope ? ($body->errorId ?? null) : null;
        if (!$isEnvelope || !(is_int($errorId) || (is_float($errorId) && is_finite($errorId) && floor($errorId) === $errorId))) {
            $what = $body === self::notJson() ? 'not JSON' : 'not the solver envelope';
            throw new TransportException($operation, "HTTP 200 body is {$what}", 200);
        }
        if ($errorId == 0) {
            return $body;
        }
        if ($errorId != 1) {
            throw new ProtocolException($operation, 'errorId ' . json_encode($errorId));
        }
        $code = $body->errorCode ?? null;
        if (!is_string($code)) {
            throw new ProtocolException($operation, 'errorId 1 without a string errorCode');
        }
        $description = is_string($body->errorDescription ?? null) ? $body->errorDescription : '';
        $supported = is_array($body->supportedUserAgents ?? null)
            ? array_values(array_filter($body->supportedUserAgents, 'is_string'))
            : [];

        throw ApiException::fromCode($operation, $code, $description, $taskId, $supported);
    }

    /** GETs a public catalog endpoint and returns its data object. */
    private function catalogCall(string $operation, string $path): \stdClass
    {
        $envelope = self::decode($this->send($operation, $this->request('GET', $path, null))->body);
        if ($envelope instanceof \stdClass && $envelope !== self::notJson()
            && ($envelope->success ?? null) === true && ($envelope->data ?? null) instanceof \stdClass) {
            return $envelope->data;
        }

        throw new TransportException($operation, 'HTTP 200 body is not the API envelope', 200);
    }

    /** @return array<string, mixed> */
    private static function objectToArray(\stdClass $object): array
    {
        $out = [];
        foreach (get_object_vars($object) as $key => $value) {
            $out[(string) $key] = self::plain($value);
        }

        return $out;
    }

    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            return self::objectToArray($value);
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }

    private static function fail(string $operation, string $key, string $expected): ProtocolException
    {
        return new ProtocolException($operation, "catalog field {$key} is not {$expected}");
    }

    private static function entry(string $operation, mixed $raw): \stdClass
    {
        if (!$raw instanceof \stdClass) {
            throw new ProtocolException($operation, 'a catalog entry is not an object');
        }

        return $raw;
    }

    private static function text(string $operation, \stdClass $entry, string $key): string
    {
        $value = $entry->{$key} ?? null;
        if (!is_string($value)) {
            throw self::fail($operation, $key, 'a string');
        }

        return $value;
    }

    private static function optionalText(string $operation, \stdClass $entry, string $key): ?string
    {
        $value = $entry->{$key} ?? null;
        if ($value !== null && !is_string($value)) {
            throw self::fail($operation, $key, 'a string or null');
        }

        return $value;
    }

    private static function integer(string $operation, \stdClass $entry, string $key): int
    {
        $value = $entry->{$key} ?? null;
        if (!is_int($value)) {
            throw self::fail($operation, $key, 'an integer');
        }

        return $value;
    }

    /** @return list<string> */
    private static function texts(string $operation, mixed $raw, string $key): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw self::fail($operation, $key, 'a list of strings');
        }
        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                throw self::fail($operation, $key, 'a list of strings');
            }
            $out[] = $value;
        }

        return $out;
    }

    /** @return list<list<string>> */
    private static function groups(string $operation, \stdClass $entry, string $key): array
    {
        $raw = $entry->{$key} ?? null;
        if (!is_array($raw)) {
            throw self::fail($operation, $key, 'a list of string lists');
        }
        $out = [];
        foreach ($raw as $group) {
            try {
                $out[] = self::texts($operation, $group, $key);
            } catch (ProtocolException) {
                throw self::fail($operation, $key, 'a list of string lists');
            }
        }

        return $out;
    }
}
