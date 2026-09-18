<?php

declare(strict_types=1);

namespace Vinktar\Tests\Support;

use Vinktar\Transport\Response;
use Vinktar\Transport\Transport;

/**
 * An ingest host that never answers: every request takes the whole timeout it was given, then fails
 * the way a connection that timed out does.
 */
final class StallingTransport implements Transport
{
    public int $attempts = 0;

    public function send(string $url, array $headers, string $body, int $timeoutMs): Response
    {
        ++$this->attempts;
        usleep($timeoutMs * 1000);

        return Response::none();
    }
}
