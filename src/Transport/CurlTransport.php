<?php

declare(strict_types=1);

namespace BugHQ\Transport;

use BugHQ\Config;

/**
 * Default transport: a blocking curl POST to `{host}/errors` with tight
 * timeouts, authenticated by the public ingest key in `X-BugHQ-Key`.
 */
final class CurlTransport implements Transport
{
    public function send(array $payload, Config $config): ?int
    {
        try {
            $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($body === false) {
                return null;
            }

            $ch = curl_init($config->host . '/errors');
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $config->connectTimeout,
                CURLOPT_TIMEOUT => $config->timeout,
                CURLOPT_USERAGENT => $config->userAgent,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-BugHQ-Key: ' . $config->key,
                ],
            ]);

            $ok = curl_exec($ch);
            // curl_close() is a no-op since PHP 8.0 and deprecated in 8.5 -
            // the handle is freed when it goes out of scope.
            return $ok === false ? null : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } catch (\Throwable) {
            // never let reporting throw
            return null;
        }
    }
}
