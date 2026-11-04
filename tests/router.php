<?php

// Router for `php -S` in RuntimeTest: records each request to SURSOLVER_RECORD and answers per
// SURSOLVER_MODE.

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$headers = function_exists('getallheaders') ? getallheaders() : [];
$record = [
    'line' => ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . $path,
    'headers' => array_change_key_case($headers, CASE_LOWER),
    'body' => (string) file_get_contents('php://input'),
];
file_put_contents((string) getenv('SURSOLVER_RECORD'), json_encode($record) . "\n", FILE_APPEND);

header('Content-Type: application/json');
switch (getenv('SURSOLVER_MODE')) {
    case 'task':
        echo '{"errorId":0,"taskId":"tsk_4f7a9c1e2b8d3a6f5c0e9d21"}';
        break;
    case 'hang':
        sleep(3);
        echo '{"errorId":0,"balance":1}';
        break;
    case 'redirect':
        http_response_code(307);
        header('Location: /elsewhere');
        break;
    case 'auth':
        echo '{"errorId":1,"errorCode":"ERROR_KEY_DOES_NOT_EXIST","errorDescription":"The clientKey does not match any active API key."}';
        break;
}
