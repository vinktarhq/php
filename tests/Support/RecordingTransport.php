<?php

declare(strict_types=1);

namespace Vinktar\Tests\Support;

use Vinktar\Transport\Response;
use Vinktar\Transport\Transport;

/**
 * A stand-in for ingest: records every request, and answers from a script. Once the script runs
 * out, every request gets a 202 that accepted everything.
 */
final class RecordingTransport implements Transport
{
    /** @var list<array{url: string, path: string, headers: array<string, string>, body: array<string, mixed>, gzip: bool}> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $script = [];

    /**
     * @param array<string, string> $headers
     */
    public function respond(int $status, mixed $body = null, array $headers = []): void
    {
        $this->script[] = new Response($status, $headers, $body === null ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function fail(\Throwable $error): void
    {
        $this->script[] = $error;
    }

    public function send(string $url, array $headers, string $body, int $timeoutMs): Response
    {
        $headers = array_change_key_case($headers, \CASE_LOWER);
        $gzip = ($headers['content-encoding'] ?? '') === 'gzip';
        $text = $gzip ? gzdecode($body) : $body;
        $decoded = json_decode(\is_string($text) ? $text : '', true, 512, \JSON_THROW_ON_ERROR);
        $path = parse_url($url, \PHP_URL_PATH);

        $next = array_shift($this->script) ?? new Response(202, [], '{"received":1,"rejected":0,"errors":[]}');
        if ($next instanceof \Throwable) {
            throw $next;
        }
        /** @var array<string, mixed> $decoded */
        $this->requests[] = ['url' => $url, 'path' => \is_string($path) ? $path : '', 'headers' => $headers, 'body' => $decoded, 'gzip' => $gzip];

        return $next;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(string $path, string $key): array
    {
        $out = [];
        foreach ($this->requests as $request) {
            if ($request['path'] !== $path || !\is_array($request['body'][$key] ?? null)) {
                continue;
            }
            foreach ($request['body'][$key] as $record) {
                if (\is_array($record)) {
                    /** @var array<string, mixed> $record */
                    $out[] = $record;
                }
            }
        }

        return $out;
    }
}
