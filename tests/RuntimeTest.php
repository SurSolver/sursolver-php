<?php

// Behaviour the language-neutral scenarios cannot cover: the real curl transport against a local
// server, and var_dump/print_r redaction.

declare(strict_types=1);

namespace SurSolver\Tests;

use PHPUnit\Framework\TestCase;
use SurSolver\Client;
use SurSolver\Exception\AuthenticationException;
use SurSolver\Exception\TransportException;
use SurSolver\TaskTypes;

final class RuntimeTest extends TestCase
{
    private const string KEY = ConformanceTest::TEST_API_KEY;
    private const array TASK = [
        'type' => TaskTypes::TURNSTILE_TOKEN_PROXY_LESS,
        'websiteURL' => 'https://example.com/',
        'websiteKey' => 'k',
        'userAgent' => 'ua',
    ];

    /** @var resource|null */
    private $server = null;
    private string $record = '';

    /** Starts `php -S` with tests/router.php in $mode and returns its base URL. */
    private function serve(string $mode): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->record = (string) tempnam(sys_get_temp_dir(), 'sursolver');
        $env = ['SURSOLVER_MODE' => $mode, 'SURSOLVER_RECORD' => $this->record];
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        self::assertIsResource($server);
        $this->server = $server;
        // Wait until the server accepts connections (startup only; no test logic depends on time).
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
            if ($socket !== false) {
                fclose($socket);
                break;
            }
            usleep(20_000);
        }

        return "http://127.0.0.1:{$port}";
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->record !== '') {
            @unlink($this->record);
        }
    }

    /** @return list<array{line: string, headers: array<string, string>, body: string}> */
    private function requests(): array
    {
        $out = [];
        foreach (file($this->record, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array{line: string, headers: array<string, string>, body: string} $request */
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $out[] = $request;
        }

        return $out;
    }

    public function testKeyTravelsOnlyInTheBodyWithTheSdkUserAgent(): void
    {
        $client = new Client(self::KEY, baseUrl: $this->serve('task'));

        self::assertSame('tsk_4f7a9c1e2b8d3a6f5c0e9d21', $client->createTask(self::TASK));

        $request = $this->requests()[0];
        self::assertSame('POST /createTask', $request['line']);
        self::assertStringStartsWith('sursolver-php/' . Client::VERSION, $request['headers']['user-agent']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertStringNotContainsString(self::KEY, implode("\n", $request['headers']));
        self::assertSame(self::KEY, json_decode($request['body'], true)['clientKey'] ?? null);
    }

    public function testRequestTimeoutIsATransportException(): void
    {
        // A real 100 ms wait: the behaviour under test is curl's own deadline handling.
        $client = new Client(self::KEY, baseUrl: $this->serve('hang'), requestTimeout: 0.1);

        try {
            $client->getBalance();
            self::fail('getBalance returned');
        } catch (TransportException $error) {
            self::assertStringContainsString('timed out after 100 ms', $error->getMessage());
            self::assertFalse($error->mayHaveCreatedTask());
            self::assertNull($error->status);
        }
    }

    public function testRedirectIsNotFollowed(): void
    {
        $client = new Client(self::KEY, baseUrl: $this->serve('redirect'));

        try {
            $client->createTask(self::TASK);
            self::fail('createTask returned');
        } catch (TransportException $error) {
            self::assertSame(307, $error->status);
            self::assertTrue($error->mayHaveCreatedTask());
            self::assertStringContainsString('charged', $error->getMessage());
        }
        self::assertCount(1, $this->requests());
    }

    public function testApiErrorsAndDumpsNeverShowTheKey(): void
    {
        $client = new Client(self::KEY, baseUrl: $this->serve('auth'));

        try {
            $client->getBalance();
            self::fail('getBalance returned');
        } catch (AuthenticationException $error) {
            self::assertStringNotContainsString(self::KEY, (string) $error);
        }
        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString(self::KEY, $dump . print_r($client, true) . var_export($client, true));
    }

    public function testConstructionErrorTraceHidesTheKey(): void
    {
        try {
            new Client(self::KEY, baseUrl: 'http://example.com');
            self::fail('constructed');
        } catch (\SurSolver\Exception\ConfigurationException $error) {
            self::assertStringNotContainsString(self::KEY, (string) $error);
        }
    }
}
