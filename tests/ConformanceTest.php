<?php

// Runs the vendored language-neutral conformance scenarios (conformance/README.md) with a
// scripted fake transport, a virtual clock and a fixed random source.

declare(strict_types=1);

namespace SurSolver\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurSolver\Client;
use SurSolver\Clock;
use SurSolver\Exception\ApiException;
use SurSolver\Exception\SolveTimeoutException;
use SurSolver\Exception\SurSolverException;
use SurSolver\Exception\TransportException;
use SurSolver\TaskResult;
use SurSolver\Transport\Request;
use SurSolver\Transport\Response;
use SurSolver\Transport\Transport;
use SurSolver\Transport\TransportFailure;

final class VirtualClock implements Clock
{
    /** @var list<int> */
    public array $sleeps = [];
    private float $now = 0.0;

    public function now(): float
    {
        return $this->now;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = (int) round($seconds * 1000);
        $this->now += $seconds;
    }
}

final class ScriptedTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];
    /** @var list<string> */
    public array $failures = [];

    /** @param list<array<string, mixed>> $script */
    public function __construct(private readonly array $script)
    {
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $step = count($this->requests);
        $entry = $this->script[$step - 1] ?? null;
        if ($entry === null) {
            $this->mismatch($step, "unexpected {$request->method} {$request->url}");
        }
        $expected = Json::object($entry['expect']);
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        if ($expected['method'] !== $request->method || $expected['path'] !== $path) {
            $this->mismatch($step, 'expected ' . Json::text($expected['method']) . ' ' . Json::text($expected['path']) . ", got {$request->method} {$path}");
        }
        if (($expected['noQuery'] ?? false) === true && parse_url($request->url, PHP_URL_QUERY) !== null) {
            $this->mismatch($step, 'unexpected query');
        }
        if (array_key_exists('json', $expected)) {
            $body = json_decode((string) $request->body, true);
            if (!Json::equal($expected['json'], $body)) {
                $this->mismatch($step, "body {$request->body} != " . json_encode($expected['json']));
            }
        }
        $headers = array_change_key_case($request->headers, CASE_LOWER);
        foreach (Json::object($expected['headers'] ?? []) as $name => $want) {
            $actual = $headers[$name] ?? null;
            $matches = $actual !== null && (is_array($want)
                ? preg_match('/' . str_replace('/', '\/', Json::text($want['matches'])) . '/', $actual) === 1
                : $actual === $want);
            if (!$matches) {
                $this->mismatch($step, "header {$name} mismatch: " . var_export($actual, true));
            }
        }
        $fail = $entry['fail'] ?? null;
        if ($fail === 'request_timeout') {
            throw new TransportFailure('Operation timed out', timedOut: true);
        }
        if ($fail !== null) {
            throw new TransportFailure($fail === 'connection_reset' ? 'Connection reset by peer' : 'Empty reply from server');
        }

        return self::respond(Json::object($entry['respond']));
    }

    private function mismatch(int $step, string $message): never
    {
        $this->failures[] = "request {$step}: {$message}";
        throw new TransportFailure('harness mismatch');
    }

    /** @param array<string, mixed> $respond */
    private static function respond(array $respond): Response
    {
        $status = is_int($respond['status'] ?? null) ? $respond['status'] : 200;
        if (isset($respond['respondFrom'])) {
            $fixture = Json::object(Json::object(Json::file('recorded/' . Json::text($respond['respondFrom'])))['response']);
            if (!array_key_exists('status', $respond)) {
                $status = (int) $fixture['status'];
            }
            $body = $fixture['body_is_json'] === true ? Json::encode($fixture['body']) : Json::text($fixture['body']);

            return new Response($status, $body);
        }
        if (array_key_exists('json', $respond)) {
            return new Response($status, Json::encode($respond['json']));
        }

        return new Response($status, Json::text($respond['text'] ?? ''));
    }
}

/** JSON helpers for the harness. Scenario objects decode to associative arrays. */
final class Json
{
    public const string ROOT = __DIR__ . '/../conformance/';

    public static function file(string $relative): mixed
    {
        return json_decode((string) file_get_contents(self::ROOT . $relative), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('expected a JSON object');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public static function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('expected a JSON string');
        }

        return $value;
    }

    public static function equal(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::equal($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    /** The README key rule: lowercase without underscores, except inside a solution. */
    public static function normalize(mixed $value, bool $insideSolution = false): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $v): mixed => self::normalize($v, $insideSolution), $value);
        }
        if ($insideSolution) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $entry) {
            $out[strtolower(str_replace('_', '', (string) $key))] = self::normalize($entry, $key === 'solution');
        }

        return $out;
    }

    /** The result in wire spelling: snake_case property names; solutions untouched. */
    public static function wire(mixed $value): mixed
    {
        if ($value instanceof TaskResult) {
            return $value->solution === null ? ['status' => $value->status] : ['status' => $value->status, 'solution' => $value->solution];
        }
        if (is_object($value)) {
            $out = [];
            foreach (get_object_vars($value) as $name => $entry) {
                $out[strtolower((string) preg_replace('/[A-Z]/', '_$0', $name))] = self::wire($entry);
            }

            return $out;
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(self::wire(...), $value);
        }

        return $value;
    }

    public static function pointer(mixed $root, string $pointer): mixed
    {
        $current = $root;
        foreach (array_slice(explode('/', $pointer), 1) as $raw) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $raw);
            $current = is_array($current) ? ($current[array_is_list($current) ? (int) $segment : $segment] ?? null) : null;
        }

        return $current;
    }
}

final class ConformanceTest extends TestCase
{
    public const string TEST_API_KEY = 'sur_00000000000000000000000000000000';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function scenarios(): iterable
    {
        $files = glob(Json::ROOT . 'scenarios/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $sc = Json::object(json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
            // This client is synchronous and has no cancellation primitive, so the two cancellation
            // scenarios do not apply (like the Python sync client).
            if (isset(Json::object($sc['call'])['cancelAfterSleeps'])) {
                continue;
            }
            yield Json::text($sc['name']) => [$sc];
        }
    }

    /** @return array{string, array<string, mixed>} */
    private static function errorView(\Throwable $error): array
    {
        if (!$error instanceof SurSolverException) {
            return ['unexpected ' . $error::class . ': ' . $error->getMessage(), []];
        }
        $fields = $error->operation === null ? [] : ['operation' => $error->operation];
        if ($error instanceof SolveTimeoutException) {
            $fields['taskId'] = $error->taskId;
            $fields['lastErrorType'] = $error->lastError === null ? null : self::errorView($error->lastError)[0];
        } elseif ($error instanceof TransportException) {
            $fields['mayHaveCreatedTask'] = $error->mayHaveCreatedTask();
            if ($error->status !== null) {
                $fields['status'] = $error->status;
            }
            if ($error->serverCode !== null) {
                $fields['serverCode'] = $error->serverCode;
            }
            if ($error->retryAfterSeconds !== null) {
                $fields['retryAfterSeconds'] = $error->retryAfterSeconds;
            }
        } elseif ($error instanceof ApiException) {
            $fields += [
                'code' => $error->errorCode,
                'description' => $error->description,
                'retryable' => $error->isRetryable(),
                'taskId' => $error->taskId,
                'supportedUserAgents' => $error->supportedUserAgents,
            ];
        }
        $short = substr(strrchr($error::class, '\\') ?: $error::class, 1);

        return [substr($short, 0, -strlen('Exception')) . 'Error', $fields];
    }

    /** @param array<string, mixed> $sc */
    #[DataProvider('scenarios')]
    public function testScenario(array $sc): void
    {
        $call = Json::object($sc['call']);
        $args = Json::object($call['args'] ?? []);
        $expect = Json::object($sc['expect']);
        /** @var list<array<string, mixed>> $script */
        $script = $sc['script'] ?? [];
        $clock = new VirtualClock();
        $transport = new ScriptedTransport($script);
        $random = (float) ($sc['random'] ?? 0);
        $options = ['transport' => $transport, 'clock' => $clock, 'random' => static fn (): float => $random];
        $apiKey = self::TEST_API_KEY;
        foreach (Json::object($sc['client'] ?? []) as $name => $value) {
            match ($name) {
                'apiKey' => $apiKey = Json::text($value),
                'baseUrl' => $options['baseUrl'] = $value,
                'requestTimeoutMs' => $options['requestTimeout'] = (float) $value / 1000,
                'pollIntervalMs' => $options['pollInterval'] = (float) $value / 1000,
                'solveTimeoutMs' => $options['solveTimeout'] = (float) $value / 1000,
                'maxSubmitRetries' => $options['maxSubmitRetries'] = $value,
                'appId' => $options['appId'] = $value,
                'userAgentSuffix' => $options['userAgentSuffix'] = $value,
                default => self::fail("unknown client option {$name}"),
            };
        }

        $created = [];
        $result = null;
        $error = null;
        try {
            $client = new Client($apiKey, ...$options);
            $task = Json::object($args['task'] ?? []);
            $result = match ($call['method']) {
                'construct' => null,
                'createTask' => $client->createTask($task),
                'getTaskResult' => $client->getTaskResult(Json::text($args['taskId'])),
                'getBalance' => $client->getBalance(),
                'listTaskTypes' => $client->listTaskTypes(),
                'listServices' => $client->listServices(),
                'solve' => $client->solve(
                    $task,
                    pollInterval: isset($args['pollIntervalMs']) ? (float) $args['pollIntervalMs'] / 1000 : null,
                    solveTimeout: isset($args['solveTimeoutMs']) ? (float) $args['solveTimeoutMs'] / 1000 : null,
                    onTaskCreated: static function (string $id) use (&$created): void {
                        $created[] = $id;
                    },
                ),
                default => self::fail('unknown call'),
            };
        } catch (SurSolverException $caught) {
            $error = $caught;
        }

        self::assertSame([], $transport->failures, 'harness');
        self::assertCount(count($script), $transport->requests, 'scripted requests consumed');
        if (array_key_exists('sleeps', $expect)) {
            self::assertSame($expect['sleeps'], $clock->sleeps, 'sleeps');
        }
        if (array_key_exists('lastTaskId', $expect)) {
            self::assertSame($expect['lastTaskId'], end($created), 'onTaskCreated');
        }

        $outcome = Json::object($expect['outcome'] ?? []);
        if (isset($outcome['error'])) {
            $want = Json::object($outcome['error']);
            self::assertNotNull($error, 'expected ' . Json::text($want['type']) . ', got a result');
            [$type, $fields] = self::errorView($error);
            self::assertSame($want['type'], $type, (string) $error);
            foreach ($want as $name => $value) {
                if (in_array($name, ['type', 'messageIncludes', 'messageExcludes'], true)) {
                    continue;
                }
                self::assertTrue(array_key_exists($name, $fields) && Json::equal($value, $fields[$name]), "{$name} = " . var_export($fields[$name] ?? null, true));
            }
            $rendered = $error->getMessage() . "\n" . $error;
            foreach ((array) ($want['messageIncludes'] ?? []) as $text) {
                self::assertStringContainsString(Json::text($text), $rendered);
            }
            foreach ((array) ($want['messageExcludes'] ?? []) as $text) {
                self::assertStringNotContainsString(Json::text($text), $rendered);
            }
        } else {
            self::assertNull($error, 'unexpected error: ' . $error?->getMessage());
            $wire = Json::wire($result);
            if (array_key_exists('ok', $outcome)) {
                self::assertTrue(Json::equal(Json::normalize($outcome['ok']), Json::normalize($wire)), 'result ' . Json::encode($wire));
            }
            foreach ((array) ($expect['outcomeSubset'] ?? []) as $check) {
                $check = Json::object($check);
                $value = Json::pointer($wire, Json::text($check['pointer']));
                if (isset($check['length'])) {
                    self::assertIsArray($value);
                    self::assertCount((int) $check['length'], $value);
                }
                if (array_key_exists('equals', $check)) {
                    self::assertTrue(Json::equal(Json::normalize($check['equals']), Json::normalize($value)), Json::text($check['pointer']));
                }
            }
        }

        if (($expect['redaction'] ?? false) === true) {
            foreach ($transport->requests as $request) {
                self::assertStringNotContainsString($apiKey, $request->url);
                self::assertStringNotContainsString($apiKey, implode("\n", $request->headers));
                self::assertSame(1, substr_count((string) $request->body, $apiKey));
                self::assertSame($apiKey, Json::object(json_decode((string) $request->body, true))['clientKey']);
            }
            if ($error !== null) {
                self::assertStringNotContainsString($apiKey, (string) $error);
            }
        }
    }
}
