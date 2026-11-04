<?php

// Live smoke test against the real API; costs nothing. Run: composer smoke
// Reads the public catalog and calls getBalance() with a fake key (expects AuthenticationException).
// Set SURSOLVER_API_KEY to also read your real balance; SURSOLVER_BASE_URL overrides the origin.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SurSolver\Client;
use SurSolver\Exception\AuthenticationException;

$baseUrl = getenv('SURSOLVER_BASE_URL') ?: Client::DEFAULT_BASE_URL;
$client = new Client('sur_00000000000000000000000000000000', baseUrl: $baseUrl);

$catalog = $client->listTaskTypes();
echo 'listTaskTypes: ' . count($catalog->taskTypes) . ' types, ' . count($catalog->errorCodes) . " error codes\n";
echo 'listServices: ' . count($client->listServices()) . " active services\n";
try {
    $client->getBalance();
    fwrite(STDERR, "getBalance accepted a fake key\n");
    exit(1);
} catch (AuthenticationException $error) {
    echo "getBalance (fake key): {$error->getMessage()}\n";
}

$apiKey = getenv('SURSOLVER_API_KEY');
if (is_string($apiKey) && $apiKey !== '') {
    echo 'getBalance (your key): ' . (new Client($apiKey, baseUrl: $baseUrl))->getBalance() . " USD\n";
}
echo "smoke OK\n";
