<?php

declare(strict_types=1);

namespace Vinktar\Transport;

/**
 * Sends one request to ingest. Pass your own as the `transport` option to route through a proxy or
 * to record what would be sent in a test.
 *
 * One attempt, bounded by `$timeoutMs` from start to the last byte of the response. Never follow a
 * redirect: it would carry the body and the `X-Vinktar-Key` header to wherever it points. Return
 * status 0 when there was no HTTP answer at all. An exception is treated as no answer.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $url, array $headers, string $body, int $timeoutMs): Response;
}
