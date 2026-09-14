<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * HTTP response to what to do about it.
 *
 * Pure, so the whole transport policy is testable without a socket. `spec/fixtures/responses.json`
 * is the table, and four of its rows lose data when wrong: any 2xx means accepted (drop the batch),
 * 503 means NOT stored (keep it), 429 is never retried inline (hold the categories, send later),
 * and a redirect is never followed (it would carry the write key and the body to wherever it
 * points).
 *
 * The body decides first: its error code says why, where a header only says how long.
 * `Retry-After` and `X-RateLimit-Categories` refine the wait and the scope of a hold when present;
 * the policy never depends on them being there.
 *
 * @internal
 */
final class ResponsePolicy
{
    /**
     * The longest a monthly cap is held at a time. The server's `Retry-After` points at the next
     * month; waiting that long would also wait through a plan upgrade, and one refused request every
     * six hours costs nothing.
     */
    public const MONTHLY_HOLD_SECONDS = 21_600;

    /** What a 503 waits when the server gives no `Retry-After`. */
    public const UNAVAILABLE_WAIT_SECONDS = 10;

    /**
     * @param int $retryAfter `Retry-After`, already resolved to seconds by {@see parseRetryAfter()}
     */
    public static function decide(int $status, mixed $body, int $retryAfter = 0, ?string $rateLimitCategories = null): Decision
    {
        $payload = \is_array($body) ? $body : [];
        // Two 400s come from a listener in front of the controllers and use `message`; everything
        // else uses `error`. Reading only `error` mishandles the SDK's own bad gzip.
        $raw = $payload['error'] ?? $payload['message'] ?? '';
        $code = \is_scalar($raw) ? (string) $raw : '';
        if ($code === '') {
            $code = 'http_'.$status;
        }

        // Ingest answers 202. A proxy in front of it may answer 200 or 204, and resending what was
        // accepted counts it twice.
        if ($status >= 200 && $status < 300) {
            return new Decision(Decision::DROP, 0, 'ok');
        }

        // A redirect is a misconfigured host. Nothing sent to it lands where it should, and
        // following it would hand the body and the write key to another location.
        if ($status >= 300 && $status < 400) {
            return new Decision(Decision::SHUTDOWN, 0, 'redirect');
        }

        if ($status === 400) {
            // Our own compression produced something the server could not inflate. Sending the same
            // batch uncompressed recovers it; dropping it loses data for a bug on our side.
            if (preg_match('/gzip/i', $code) === 1) {
                return new Decision(Decision::DEGRADE, 0, $code, degrade: 'disableGzip');
            }

            // Anything else at 400 is our payload being wrong. The same bytes fail identically.
            return new Decision(Decision::DROP, 0, $code);
        }

        // A bad key is configuration, not a transient failure. Buffering against a key that will
        // never work is a memory leak that never drains.
        if ($status === 401 || $status === 403) {
            return new Decision(Decision::SHUTDOWN, 0, $code);
        }

        if ($status === 413) {
            return new Decision(Decision::SPLIT, 0, $code);
        }

        if ($status === 429) {
            $billing = $code === 'monthly_cap_exceeded' || $code === 'monthly_error_cap_exceeded';
            $named = self::parseRateLimitCategories($rateLimitCategories);
            // A category header without its seconds part is not a zero wait.
            $serverWait = $named !== null && $named['seconds'] > 0 ? $named['seconds'] : $retryAfter;
            $wait = $billing
                ? ($serverWait > 0 ? min($serverWait, self::MONTHLY_HOLD_SECONDS) : self::MONTHLY_HOLD_SECONDS)
                : $serverWait;
            $categories = $named !== null && $named['categories'] !== [] ? $named['categories'] : null;

            return new Decision(Decision::HOLD, $wait, $code, categories: $categories, billing: $billing);
        }

        // Any other 4xx: the request itself is wrong; the same bytes fail identically.
        if ($status >= 400 && $status < 500) {
            return new Decision(Decision::DROP, 0, $code);
        }

        // THE BATCH WAS NOT STORED. Treating 503 as success loses data.
        if ($status === 503) {
            return new Decision(Decision::RETRY, $retryAfter > 0 ? $retryAfter : self::UNAVAILABLE_WAIT_SECONDS, $code);
        }

        return new Decision(Decision::RETRY, $retryAfter, $code);
    }

    /** A request that never reached the server, or never got an answer. */
    public static function networkFailure(): Decision
    {
        return new Decision(Decision::RETRY, 0, 'network');
    }

    /**
     * The categories a hold covers. It starts from what the endpoint governs; the server may narrow
     * that to the categories it names, never widen it. A header naming nothing the endpoint
     * governs is ignored rather than trusted: a batch response must not pause error reporting.
     *
     * @param list<string>      $endpoint
     * @param list<string>|null $named
     *
     * @return list<string>
     */
    public static function holdCategories(array $endpoint, ?array $named): array
    {
        if ($named === null || $named === []) {
            return $endpoint;
        }
        $narrowed = array_values(array_filter($endpoint, static fn (string $category): bool => \in_array($category, $named, true)));

        return $narrowed !== [] ? $narrowed : $endpoint;
    }

    /**
     * The categories each endpoint's records belong to.
     *
     * @return list<string>
     */
    public static function endpointCategories(string $path): array
    {
        return match ($path) {
            '/v1/errors' => ['error'],
            '/v1/identify' => ['identify'],
            default => ['event', 'identify'],
        };
    }

    /**
     * `Retry-After` is delta-seconds or an HTTP-date, and a server behind a CDN can produce either.
     * Anything unparseable is 0: use the local schedule.
     */
    public static function parseRetryAfter(?string $header, ?int $now = null): int
    {
        if ($header === null || trim($header) === '') {
            return 0;
        }
        $header = trim($header);
        if (is_numeric($header)) {
            return max(0, (int) ceil((float) $header));
        }
        $at = strtotime($header);
        if ($at === false) {
            return 0;
        }

        return max(0, $at - ($now ?? time()));
    }

    /**
     * `X-RateLimit-Categories: <seconds>:<cat>;<cat>`. A missing or bad seconds part is 0.
     *
     * @return array{seconds: int, categories: list<string>}|null
     */
    public static function parseRateLimitCategories(?string $header): ?array
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        $parts = explode(':', $header, 2);
        $rawSeconds = trim($parts[0]);
        $seconds = is_numeric($rawSeconds) ? max(0, (int) ceil((float) $rawSeconds)) : 0;
        $categories = array_values(array_filter(array_map('trim', explode(';', $parts[1] ?? '')), static fn (string $c): bool => $c !== ''));

        return ['seconds' => $seconds, 'categories' => $categories];
    }
}
