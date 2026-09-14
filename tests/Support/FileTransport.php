<?php

declare(strict_types=1);

namespace Vinktar\Tests\Support;

use Vinktar\Transport\Response;
use Vinktar\Transport\Transport;

/**
 * Writes every request body to a file, one JSON document per line, and answers 202. For a test that
 * runs the SDK in a child process and reads what it sent afterwards.
 */
final class FileTransport implements Transport
{
    public function __construct(private readonly string $path)
    {
    }

    public function send(string $url, array $headers, string $body, int $timeoutMs): Response
    {
        $headers = array_change_key_case($headers, \CASE_LOWER);
        $text = ($headers['content-encoding'] ?? '') === 'gzip' ? gzdecode($body) : $body;
        $path = parse_url($url, \PHP_URL_PATH);
        file_put_contents($this->path, json_encode(['path' => $path, 'body' => json_decode((string) $text, true)], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND | \LOCK_EX);

        return new Response(202, [], '{"received":1,"rejected":0,"errors":[]}');
    }
}
