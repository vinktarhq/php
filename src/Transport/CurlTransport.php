<?php

declare(strict_types=1);

namespace Vinktar\Transport;

/**
 * The default transport: cURL, one attempt per call.
 *
 * - One deadline covers connecting, sending and reading the response, so a server that sends its
 *   headers and then stalls cannot hold a request past the bound.
 * - Redirects are never followed. A 3xx comes back as it is, and the client stops sending.
 * - Only http and https, including for anything a server might redirect to.
 * - The handle is reused, so a worker that flushes after every job keeps its connection.
 */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    public function send(string $url, array $headers, string $body, int $timeoutMs): Response
    {
        if ($url === '' || !\function_exists('curl_init')) {
            return Response::none();
        }
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) {
                return Response::none();
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        // Without this, cURL sends "Expect: 100-continue" for larger bodies and waits for an answer.
        $lines[] = 'Expect:';

        $received = [];
        $timeoutMs = max(1, $timeoutMs);
        curl_setopt_array($this->handle, [
            \CURLOPT_URL => $url,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_TIMEOUT_MS => $timeoutMs,
            \CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            // Millisecond timeouts below a second are ignored without this on resolvers that use signals.
            \CURLOPT_NOSIGNAL => true,
            \CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return \strlen($line);
            },
        ]);

        $result = curl_exec($this->handle);
        if (!\is_string($result)) {
            return Response::none();
        }

        /** @var array<string, string> $received */
        return new Response(curl_getinfo($this->handle, \CURLINFO_RESPONSE_CODE), $received, $result);
    }
}
