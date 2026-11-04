<?php

declare(strict_types=1);

namespace SurSolver\Transport;

/**
 * The default transport: ext-curl without redirect following, so a request body carrying the API
 * key is not replayed to another location.
 */
final class CurlTransport implements Transport
{
    /** @param array<int, mixed> $curlOptions extra CURLOPT_* settings (proxies, TLS); redirects stay off */
    public function __construct(private readonly array $curlOptions = [])
    {
    }

    public function send(Request $request): Response
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        $handle = curl_init();
        $options = $this->curlOptions + [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        $options[CURLOPT_URL] = $request->url;
        $options[CURLOPT_CUSTOMREQUEST] = $request->method;
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_FOLLOWLOCATION] = false;
        // CURLOPT_TIMEOUT_MS bounds the whole exchange, body included.
        $options[CURLOPT_TIMEOUT_MS] = max(1, (int) round($request->timeout * 1000));
        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if ($errno !== 0 || !is_string($body)) {
            throw new TransportFailure($error === '' ? 'no response' : $error, $errno === CURLE_OPERATION_TIMEDOUT);
        }

        return new Response($status, $body);
    }
}
