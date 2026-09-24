<?php

declare(strict_types=1);

namespace Vinktar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vinktar\Client;
use Vinktar\Tests\Support\RecordingTransport;

/**
 * A FrankenPHP worker, or Octane, or Symfony's runtime: one process serves request after request,
 * with `$_SERVER` replaced for each, and one client lives through all of them.
 */
final class WorkerModeTest extends TestCase
{
    private const REQUESTS = [
        ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/checkout?coupon=A1', 'HTTP_USER_AGENT' => 'alice-browser', 'HTTP_X_VINKTAR_DEVICE_ID' => 'dev-alice-0001', 'HTTP_X_VINKTAR_SESSION_ID' => 'ses-alice-0001', 'user' => 'alice'],
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/pricing', 'HTTP_USER_AGENT' => 'bob-browser'],
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account', 'HTTP_USER_AGENT' => 'carol-browser', 'HTTP_X_VINKTAR_DEVICE_ID' => 'dev-carol-0003', 'user' => 'carol'],
        ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/docs', 'HTTP_USER_AGENT' => 'dan-browser'],
    ];

    /** @var array<array-key, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    public function testEveryRequestCarriesItsOwnVisitorAndNothingFromTheOneBefore(): void
    {
        $transport = new RecordingTransport();
        $client = new Client(['writeKey' => 'vnk_sk_worker', 'transport' => $transport, 'autoFlush' => false, 'logger' => static function (): void {}]);

        foreach (self::REQUESTS as $request) {
            $_SERVER = $request;
            $sent = \count($transport->requests);

            // The README's worker loop: the request inside withScope(), flush() once it has been answered.
            $client->withScope(static function () use ($client): void {
                /** @var array<string, string> $server */
                $server = $_SERVER;
                $client->scopeFromHeaders($server);
                $client->scope()->setRequest(['method' => $server['REQUEST_METHOD'], 'url' => $server['REQUEST_URI'], 'headers' => ['user-agent' => $server['HTTP_USER_AGENT']]]);
                if (isset($server['user'])) {
                    $client->identify($server['user']);
                }
                $client->setTag('route', $server['REQUEST_URI']);
                $client->addBreadcrumb(['message' => 'served '.$server['REQUEST_URI']]);
                $client->track('page_served', ['path' => $server['REQUEST_URI']]);
                try {
                    throw new \RuntimeException('the payment provider timed out');
                } catch (\RuntimeException $e) {
                    $client->captureException($e, ['handled' => false]);
                }
            });
            self::assertTrue($client->flush());

            $this->assertRequestSentOnlyItsOwn($request, \array_slice($transport->requests, $sent));
        }

        self::assertNull($client->scope()->userId());
        self::assertNull($client->scope()->deviceId());
        self::assertSame([], $client->scope()->breadcrumbs());
    }

    public function testAListenerThatEntersAScopePerRequestStartsEachOneFresh(): void
    {
        $transport = new RecordingTransport();
        $client = new Client(['writeKey' => 'vnk_sk_worker', 'transport' => $transport, 'autoFlush' => false, 'logger' => static function (): void {}, 'initialScope' => ['tags' => ['service' => 'web']]]);

        // Octane's RequestReceived and Symfony's kernel.request: nothing wraps the request, so the scope is entered.
        foreach (self::REQUESTS as $request) {
            $_SERVER = $request;
            $sent = \count($transport->requests);

            $server = $_SERVER;
            $client->enterScope();
            $client->scopeFromHeaders($server);
            if (isset($server['user'])) {
                $client->identify($server['user']);
            }
            $client->track('page_served', ['path' => $server['REQUEST_URI']]);
            self::assertTrue($client->flush());

            $events = self::records(\array_slice($transport->requests, $sent), '/v1/batch', 'batch');
            self::assertCount(1, $events);
            self::assertSame(['path' => $request['REQUEST_URI']], $events[0]['payload'] ?? null);
            self::assertSame($request['user'] ?? null, $events[0]['user_id'] ?? null);
            self::assertSame($request['HTTP_X_VINKTAR_DEVICE_ID'] ?? null, $events[0]['device_id'] ?? null);
            self::assertSame($request['HTTP_X_VINKTAR_SESSION_ID'] ?? null, $events[0]['session_id'] ?? null);
        }
        self::assertSame(['service' => 'web'], $client->scope()->tags());
    }

    public function testAClientMadeForEachRequestSendsWhatItHeldWhenItIsReleased(): void
    {
        $transport = new RecordingTransport();

        foreach (['/a', '/b', '/c'] as $path) {
            (static function () use ($transport, $path): void {
                $client = new Client(['writeKey' => 'vnk_sk_worker', 'transport' => $transport, 'logger' => static function (): void {}]);
                $client->track('page_served', ['path' => $path]);
            })();
        }
        (static function () use ($transport): void {
            $client = new Client(['writeKey' => 'vnk_sk_worker', 'transport' => $transport, 'autoFlush' => false, 'logger' => static function (): void {}]);
            $client->track('page_served', ['path' => '/not-sent']);
        })();

        $payloads = array_column($transport->records('/v1/batch', 'batch'), 'payload');
        self::assertSame([['path' => '/a'], ['path' => '/b'], ['path' => '/c']], $payloads);
    }

    /**
     * @param array<string, string>                                 $request
     * @param list<array{path: string, body: array<string, mixed>}> $requests
     */
    private function assertRequestSentOnlyItsOwn(array $request, array $requests): void
    {
        $path = $request['REQUEST_URI'];
        self::assertIsString($path);
        $user = $request['user'] ?? null;
        $device = $request['HTTP_X_VINKTAR_DEVICE_ID'] ?? null;

        $events = self::records($requests, '/v1/batch', 'batch');
        self::assertCount(1, $events);
        self::assertSame(['path' => $path], $events[0]['payload'] ?? null);
        self::assertSame($user, $events[0]['user_id'] ?? null);
        self::assertSame($device, $events[0]['device_id'] ?? null);
        self::assertSame($request['HTTP_X_VINKTAR_SESSION_ID'] ?? null, $events[0]['session_id'] ?? null);

        $errors = self::records($requests, '/v1/errors', 'errors');
        if ($path === '/docs') {
            // The same failure for the same (anonymous) visitor as /pricing moments ago: counted, not sent.
            self::assertSame([], $errors);
            $report = $requests[0]['body']['client_report'] ?? null;
            self::assertIsArray($report);
            self::assertSame([['reason' => 'deduplicated', 'category' => 'error', 'quantity' => 1]], $report['discarded'] ?? null);

            return;
        }
        self::assertCount(1, $errors);
        $error = $errors[0];
        self::assertSame($user, $error['user_id'] ?? null);
        self::assertSame($device, $error['device_id'] ?? null);
        self::assertSame(['route' => $path], $error['tags'] ?? null);
        self::assertSame(['served '.$path], array_column(\is_array($error['breadcrumbs'] ?? null) ? $error['breadcrumbs'] : [], 'message'));
        self::assertSame(['method' => $request['REQUEST_METHOD'], 'url' => explode('?', $path)[0], 'headers' => ['user-agent' => $request['HTTP_USER_AGENT']]], $error['request'] ?? null);
    }

    /**
     * @param list<array{path: string, body: array<string, mixed>}> $requests
     *
     * @return list<array<string, mixed>>
     */
    private static function records(array $requests, string $path, string $key): array
    {
        $out = [];
        foreach ($requests as $request) {
            if ($request['path'] === $path && \is_array($request['body'][$key] ?? null)) {
                foreach ($request['body'][$key] as $record) {
                    self::assertIsArray($record);
                    /** @var array<string, mixed> $record */
                    $out[] = $record;
                }
            }
        }

        return $out;
    }
}
