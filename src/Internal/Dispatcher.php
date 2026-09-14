<?php

declare(strict_types=1);

namespace Vinktar\Internal;

use Vinktar\Transport\Response;
use Vinktar\Transport\Transport;
use Vinktar\Version;

/**
 * The send loop: pick a chunk, build a request, send it, act on the answer. The whole policy for a
 * 413, a 429 or a dropped connection lives here once, over a {@see Transport} that only moves bytes.
 *
 * Two queues, two endpoints, one loop. Events and errors are held and retried independently, so a
 * throttled event stream never delays a crash report.
 *
 * Synchronous, and it never sleeps: a hold or a backoff is a time before which nothing in those
 * categories is sent, and a flush during it answers false at once. A deadline bounds a whole flush,
 * which is how `close()` and the shutdown flush keep their promise about time.
 *
 * What `flush()` answers: true only when every record that was queued when it started was accepted
 * by the server. A record still held, retrying, refused, rejected inside a 202, or given up on makes
 * it false. Records queued while it runs are the next flush's business.
 *
 * @internal
 */
final class Dispatcher
{
    public const BATCH_CATEGORIES = ['event', 'identify'];
    public const ERROR_CATEGORIES = ['error'];
    public const GZIP_THRESHOLD_BYTES = 1024;

    public readonly Queue $events;
    public readonly Queue $errors;
    public readonly Reports $reports;
    public readonly Backoff $backoff;

    private bool $gzip;
    private bool $stopped = false;
    private bool $billingWarned = false;

    /**
     * @param (callable(): float)|null      $now       milliseconds
     * @param (callable(): float)|null      $random
     * @param (\Closure(string): void)|null $onBilling a monthly cap was reached
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Logger $logger,
        private readonly string $host,
        private readonly string $writeKey,
        int $maxQueueSize,
        int $maxPendingErrors,
        int $maxQueueBytes,
        int $maxPendingErrorBytes,
        bool $gzip,
        private readonly int $requestTimeoutMs,
        ?callable $now = null,
        ?callable $random = null,
        private readonly ?\Closure $onBilling = null,
    ) {
        $this->reports = new Reports();
        $this->events = new Queue($maxQueueSize, $this->reports, $maxQueueBytes);
        $this->errors = new Queue($maxPendingErrors, $this->reports, $maxPendingErrorBytes);
        $this->backoff = new Backoff($now, $random);
        $this->gzip = $gzip;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function pending(): int
    {
        return $this->events->count() + $this->errors->count();
    }

    /** Nothing else will ever be sent. */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /** Give up on everything queued, counted under $reason. */
    public function abandon(string $reason): int
    {
        return $this->errors->discardAll($reason) + $this->events->discardAll($reason);
    }

    /**
     * @param float|null $deadline unix seconds after which no further request is started
     */
    public function flush(?float $deadline = null): bool
    {
        try {
            return $this->drain($deadline);
        } catch (\Throwable $error) {
            $this->logger->warn('flush failed unexpectedly', ['error' => $error->getMessage()]);

            return false;
        }
    }

    private function drain(?float $deadline): bool
    {
        if ($this->stopped) {
            return false;
        }
        $owedErrors = $this->errors->peek();
        $owedEvents = $this->events->peek();

        // Nothing queued, but drops to report: a run where every error was a suppressed repeat is
        // exactly the run whose counts matter. They go out on their own.
        if ($owedErrors === [] && $owedEvents === []) {
            return $this->reports->isEmpty() ? true : $this->sendReport($deadline);
        }

        // Errors first: they are rarer, smaller, and the thing someone is paged about.
        $errors = $this->drainQueue($this->errors, $owedErrors, '/v1/errors', self::ERROR_CATEGORIES, Limits::MAX_ERROR_ITEMS, $deadline);
        $events = $this->drainQueue($this->events, $owedEvents, '/v1/batch', self::BATCH_CATEGORIES, Limits::MAX_BATCH_ITEMS, $deadline);

        // A refused key or a redirect during this flush stops everything, whatever was accepted before it.
        return $errors && $events && !$this->isStopped();
    }

    /** A request carrying only the client report. True: there was nothing else to answer for. */
    private function sendReport(?float $deadline): bool
    {
        if ($this->backoff->isHeld(self::BATCH_CATEGORIES) || self::expired($deadline)) {
            return true;
        }
        $built = $this->build('/v1/batch', [], true);
        $delivery = $this->send('/v1/batch', $built['body'], $this->gzip, $deadline);
        if ($delivery['status'] >= 200 && $delivery['status'] < 300 && $built['taken'] !== null) {
            $this->reports->commit($built['taken']);
        }

        return true;
    }

    /**
     * @param list<Entry>  $owed
     * @param list<string> $categories
     */
    private function drainQueue(Queue $queue, array $owed, string $endpoint, array $categories, int $maxItems, ?float $deadline): bool
    {
        if ($owed === []) {
            return true;
        }
        /** @var list<Entry> $accepted */
        $accepted = [];
        $limit = $maxItems;

        while (!$this->stopped && !$this->backoff->isHeld($categories) && !self::expired($deadline)) {
            $remaining = array_values(array_filter($queue->peek(), static fn (Entry $entry): bool => \in_array($entry, $owed, true)));
            if ($remaining === []) {
                break;
            }
            $chunk = self::fitBytes(\array_slice($remaining, 0, $limit));
            $result = $this->sendChunk($queue, $chunk, $endpoint, $categories, $deadline);

            if ($result['kind'] === 'answered') {
                array_push($accepted, ...$result['accepted']);
                $limit = $maxItems;
                continue;
            }
            if ($result['kind'] === 'split') {
                if (\count($chunk) === 1) {
                    // One record that will never fit. Nothing to halve; let it go, and say so.
                    $queue->remove($chunk);
                    $this->reports->record('invalid', $chunk[0]->category);
                    $this->logger->warn("dropped one {$endpoint} record the server refused as too large");
                    $limit = $maxItems;
                    continue;
                }
                $limit = max(1, intdiv(\count($chunk), 2));
                continue;
            }
            // Held, backing off, or stopped: whatever is left is not delivered by this flush.
            break;
        }

        foreach ($owed as $entry) {
            if (!\in_array($entry, $accepted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Entry>  $entries
     * @param list<string> $categories
     *
     * @return array{kind: 'answered', accepted: list<Entry>}|array{kind: 'split'|'retry'|'stop'}
     */
    private function sendChunk(Queue $queue, array $entries, string $endpoint, array $categories, ?float $deadline): array
    {
        // Split before sending when the chunk is over the byte ceiling: a 413 costs a round trip.
        if (\count($entries) > 1 && array_sum(array_map(static fn (Entry $e): int => $e->bytes(), $entries)) > Limits::MAX_REQUEST_BYTES) {
            return ['kind' => 'split'];
        }

        $queue->lease($entries);
        try {
            $built = $this->build($endpoint, $entries, true);
            $gzip = $this->gzip;

            while (true) {
                $delivery = $this->send($endpoint, $built['body'], $gzip, $deadline);
                $decision = $delivery['status'] === 0
                    ? ResponsePolicy::networkFailure()
                    : ResponsePolicy::decide($delivery['status'], $delivery['body'], $delivery['retryAfter'], $delivery['categories']);

                switch ($decision->action) {
                    case Decision::DROP:
                        $this->backoff->succeeded($categories);
                        $queue->remove($entries);
                        if ($decision->code === 'ok') {
                            if ($built['taken'] !== null) {
                                $this->reports->commit($built['taken']);
                            }
                            foreach (Diagnostics::lines($delivery['body']) as $line) {
                                $this->logger->warn($line);
                            }
                            $rejected = self::rejectedEntries($endpoint, $entries, $delivery['body']);
                            foreach ($rejected as $entry) {
                                $this->reports->record('invalid', $entry->category);
                            }

                            return ['kind' => 'answered', 'accepted' => array_values(array_filter($entries, static fn (Entry $e): bool => !\in_array($e, $rejected, true)))];
                        }
                        // Refused outright. The report rode in the refused body, so it is not committed.
                        $this->refused($endpoint, $decision->code, $entries);

                        return ['kind' => 'answered', 'accepted' => []];

                    case Decision::DEGRADE:
                        if ($gzip) {
                            $this->logger->warn('the server could not inflate a compressed request; compression is off for this client');
                            $this->gzip = false;
                            $gzip = false;
                            continue 2;
                        }
                        // Already uncompressed and still "invalid gzip": the body is the problem.
                        $this->backoff->succeeded($categories);
                        $queue->remove($entries);
                        $this->refused($endpoint, $decision->code, $entries);

                        return ['kind' => 'answered', 'accepted' => []];

                    case Decision::SHUTDOWN:
                        $this->stopped = true;
                        if ($decision->code === 'redirect') {
                            $this->logger->error("the ingest host answered with a redirect ({$delivery['status']}); it was not followed, and nothing more will be sent from this client. Point host at the ingest URL itself");
                        } else {
                            $this->logger->error("the write key was refused ({$decision->code}); nothing more will be sent");
                            // A refused key will never work, so its queue can never be delivered.
                            $this->abandon('send_error');
                        }

                        return ['kind' => 'stop'];

                    case Decision::SPLIT:
                        return ['kind' => 'split'];

                    case Decision::HOLD:
                        $held = ResponsePolicy::holdCategories($categories, $decision->categories);
                        $this->backoff->hold($held, $decision->wait);
                        if ($decision->billing) {
                            if (!$this->billingWarned) {
                                $this->billingWarned = true;
                                $this->logger->error("the monthly cap was reached ({$decision->code}); sending pauses, and is tried again at most every six hours");
                                if ($this->onBilling !== null) {
                                    ($this->onBilling)($decision->code);
                                }
                            }
                        } else {
                            $this->logger->debug("rate limited on {$endpoint} ({$decision->code}); holding ".implode(', ', $held));
                        }

                        return ['kind' => 'retry'];

                    default: // Decision::RETRY
                        $network = $decision->code === 'network';
                        $attempts = 0;
                        foreach ($entries as $entry) {
                            ++$entry->attempts;
                            $attempts = max($attempts, $entry->attempts);
                        }
                        $budget = $network ? Backoff::MAX_NETWORK_ATTEMPTS : Backoff::MAX_ATTEMPTS;
                        if ($attempts >= $budget) {
                            $this->logger->warn($network
                                ? \count($entries)." record(s) dropped after {$attempts} attempts with no answer from the server"
                                : \count($entries)." record(s) dropped after {$attempts} failed attempts ({$decision->code})");
                            foreach ($entries as $entry) {
                                $this->reports->record('send_error', $entry->category);
                            }
                            $queue->remove($entries);
                            $this->backoff->succeeded($categories);

                            return ['kind' => 'answered', 'accepted' => []];
                        }
                        $this->backoff->hold($categories, $decision->wait, floor: true);

                        return ['kind' => 'retry'];
                }
            }
        } finally {
            $queue->release($entries);
        }
    }

    /**
     * @param list<Entry> $entries
     */
    private function refused(string $endpoint, string $code, array $entries): void
    {
        $this->logger->warn("the server refused a {$endpoint} request ({$code}); ".\count($entries).' record(s) dropped');
        foreach ($entries as $entry) {
            $this->reports->record('invalid', $entry->category);
        }
    }

    /**
     * One attempt, bounded, never throwing.
     *
     * @return array{status: int, body: mixed, retryAfter: int, categories: string|null}
     */
    private function send(string $endpoint, string $body, bool $gzip, ?float $deadline): array
    {
        $timeout = $this->requestTimeoutMs;
        if ($deadline !== null) {
            $timeout = (int) max(1, min($timeout, floor(($deadline - microtime(true)) * 1000)));
        }
        $headers = [
            'Content-Type' => 'application/json',
            'X-Vinktar-Key' => $this->writeKey,
            'User-Agent' => Version::LIB.'/'.Version::VERSION,
        ];
        if ($gzip && \strlen($body) >= self::GZIP_THRESHOLD_BYTES && \function_exists('gzencode')) {
            $compressed = gzencode($body, 6);
            if (\is_string($compressed)) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }
        }

        try {
            $response = $this->transport->send($this->host.$endpoint, $headers, $body, $timeout);
        } catch (\Throwable $error) {
            $this->logger->debug('the transport threw; treated as no answer', ['error' => $error->getMessage()]);
            $response = Response::none();
        }

        $parsed = null;
        if ($response->body !== '') {
            try {
                $parsed = json_decode($response->body, true, 64, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $parsed = null;
            }
        }

        return [
            'status' => $response->status,
            'body' => $parsed,
            'retryAfter' => ResponsePolicy::parseRetryAfter($response->header('retry-after')),
            'categories' => $response->header('x-ratelimit-categories'),
        ];
    }

    /**
     * @param list<Entry> $entries
     *
     * @return array{body: string, taken: array<string, int>|null}
     */
    private function build(string $endpoint, array $entries, bool $withReport): array
    {
        $parts = [];
        if ($endpoint === '/v1/batch') {
            $batch = [];
            $identify = [];
            foreach ($entries as $entry) {
                if ($entry->category === 'identify') {
                    $identify[] = $entry->json;
                } else {
                    $batch[] = $entry->json;
                }
            }
            $parts[] = '"batch":['.implode(',', $batch).']';
            if ($identify !== []) {
                $parts[] = '"identify":['.implode(',', $identify).']';
            }
        } else {
            $parts[] = '"errors":['.implode(',', array_map(static fn (Entry $e): string => $e->json, $entries)).']';
        }

        // Who sent the request. The server files the client report under it, and without it every
        // count this SDK reports lands under an empty library name.
        $parts[] = '"context":'.json_encode(['$lib' => Version::LIB, '$lib_version' => Version::VERSION], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $taken = null;
        if ($withReport) {
            $report = $this->reports->snapshot();
            if ($report !== null) {
                $parts[] = '"client_report":'.json_encode($report['body'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
                $taken = $report['taken'];
            }
        }

        return ['body' => '{'.implode(',', $parts).'}', 'taken' => $taken];
    }

    private static function expired(?float $deadline): bool
    {
        return $deadline !== null && microtime(true) >= $deadline;
    }

    /**
     * At most as many leading entries as fit the request ceiling, and always at least one.
     *
     * @param list<Entry> $entries
     *
     * @return list<Entry>
     */
    private static function fitBytes(array $entries): array
    {
        $bytes = 0;
        $count = 0;
        foreach ($entries as $entry) {
            if ($count > 0 && $bytes + $entry->bytes() > Limits::MAX_REQUEST_BYTES) {
                break;
            }
            $bytes += $entry->bytes();
            ++$count;
        }

        return \array_slice($entries, 0, $count);
    }

    /**
     * The entries a 202 says were not kept. `errors[].index` counts positions in the body's `batch`
     * array (identify entries are reported separately) or its `errors` array.
     *
     * @param list<Entry> $entries
     *
     * @return list<Entry>
     */
    private static function rejectedEntries(string $endpoint, array $entries, mixed $body): array
    {
        if (!\is_array($body) || !\is_array($body['errors'] ?? null)) {
            return [];
        }
        $positional = $endpoint === '/v1/batch'
            ? array_values(array_filter($entries, static fn (Entry $e): bool => $e->category !== 'identify'))
            : $entries;
        $out = [];
        foreach ($body['errors'] as $rejection) {
            $index = \is_array($rejection) ? ($rejection['index'] ?? null) : null;
            if (\is_int($index) && isset($positional[$index]) && !\in_array($positional[$index], $out, true)) {
                $out[] = $positional[$index];
            }
        }

        return $out;
    }
}
