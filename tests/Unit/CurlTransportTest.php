<?php

declare(strict_types=1);

namespace Vinktar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vinktar\Client;
use Vinktar\Transport\CurlTransport;
use Vinktar\Version;

/**
 * The cURL transport against a real HTTP server (`php -S`), for what only a socket can show: a
 * redirect not followed, a stalled body cut off at the deadline, headers read back, and the client's
 * close() keeping its bound when ingest hangs.
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static int $port = 0;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        self::$port = self::freePort();
        $log = tempnam(sys_get_temp_dir(), 'vinktar-router-');
        self::assertIsString($log);
        self::$log = $log;

        $env = getenv();
        $env['VINKTAR_TEST_LOG'] = self::$log;
        // Several workers, so a stalled request does not hold up the next test's.
        $env['PHP_CLI_SERVER_WORKERS'] = '4';
        $server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/../Fixtures/ingest-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env,
        );
        self::assertIsResource($server);
        self::$server = $server;

        $until = microtime(true) + 5;
        while (microtime(true) < $until) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.1);
            if (\is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('the test server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            // The built-in server's workers are children of the process proc_open started, and outlive
            // it when only the parent is stopped.
            $pid = proc_get_status(self::$server)['pid'];
            exec('pkill -TERM -P '.(int) $pid);
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (is_file(self::$log)) {
            unlink(self::$log);
        }
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
    }

    public function testSendsTheBodyAndHeadersAndReadsTheAnswer(): void
    {
        $body = (string) gzencode('{"batch":[{"name":"sent"}]}');
        $response = (new CurlTransport())->send(self::url('ok'), ['Content-Type' => 'application/json', 'X-Vinktar-Key' => 'vnk_sk_curl', 'Content-Encoding' => 'gzip'], $body, 2000);

        self::assertSame(202, $response->status);
        self::assertSame('{"received":1,"rejected":0,"errors":[]}', $response->body);
        self::assertSame('application/json', $response->header('Content-Type'));

        $hits = self::hits();
        self::assertCount(1, $hits);
        self::assertSame('vnk_sk_curl', $hits[0]['key']);
        self::assertSame('gzip', $hits[0]['encoding']);
        self::assertSame(['batch' => [['name' => 'sent']]], $hits[0]['body']);
        // No "Expect: 100-continue" round trip before the body.
        self::assertNull($hits[0]['expect']);
    }

    public function testNeverFollowsARedirect(): void
    {
        $response = (new CurlTransport())->send(self::url('redirect'), ['X-Vinktar-Key' => 'vnk_sk_curl'], '{"batch":[]}', 2000);

        self::assertSame(307, $response->status);
        self::assertSame('/target/v1/batch', $response->header('location'));
        self::assertSame(['/redirect/v1/batch'], array_column(self::hits(), 'uri'));
    }

    public function testReadsTheRateLimitHeaders(): void
    {
        $response = (new CurlTransport())->send(self::url('ratelimit'), [], '{}', 2000);

        self::assertSame(429, $response->status);
        self::assertSame('12', $response->header('Retry-After'));
        self::assertSame('12:event;identify', $response->header('x-ratelimit-categories'));
    }

    public function testAStalledBodyIsNoAnswerOnceTheDeadlinePasses(): void
    {
        $started = microtime(true);
        $response = (new CurlTransport())->send(self::url('stall'), [], '{}', 700);

        self::assertSame(0, $response->status);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testSlowHeadersAreNoAnswerOnceTheDeadlinePasses(): void
    {
        $started = microtime(true);
        $response = (new CurlTransport())->send(self::url('slow'), [], '{}', 700);

        self::assertSame(0, $response->status);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testNothingListeningIsNoAnswer(): void
    {
        $started = microtime(true);
        $response = (new CurlTransport())->send('http://127.0.0.1:'.self::freePort().'/v1/batch', [], '{}', 2000);

        self::assertSame(0, $response->status);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testTheReusedHandleSendsOneRequestAfterAnother(): void
    {
        $transport = new CurlTransport();
        self::assertSame(429, $transport->send(self::url('ratelimit'), [], '{}', 2000)->status);
        self::assertSame(202, $transport->send(self::url('ok'), [], '{"batch":[]}', 2000)->status);
        self::assertSame(307, $transport->send(self::url('redirect'), [], '{}', 2000)->status);
    }

    public function testTheClientCompressesAndDeliversEndToEnd(): void
    {
        // flushAt above the 30 tracked, so everything goes in the one request flush() sends.
        $client = new Client(['writeKey' => 'vnk_sk_curl', 'host' => 'http://127.0.0.1:'.self::$port.'/ok', 'autoFlush' => false, 'flushAt' => 50, 'logger' => static function (): void {}]);
        for ($i = 0; $i < 30; ++$i) {
            $client->track('curl event', ['i' => $i, 'filler' => str_repeat('x', 100)]);
        }

        self::assertTrue($client->flush());
        $hits = self::hits();
        self::assertCount(1, $hits);
        self::assertSame('gzip', $hits[0]['encoding']);
        self::assertSame(Version::LIB.'/'.Version::VERSION, $hits[0]['agent']);
        $body = $hits[0]['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['batch'] ?? null);
        self::assertCount(30, $body['batch']);
    }

    public function testCloseKeepsItsBoundWhenIngestHangs(): void
    {
        $client = new Client(['writeKey' => 'vnk_sk_curl', 'host' => 'http://127.0.0.1:'.self::$port.'/slow', 'autoFlush' => false, 'shutdownTimeout' => 800, 'requestTimeoutMs' => 500, 'logger' => static function (): void {}]);
        $client->track('never answered');

        $started = microtime(true);
        self::assertFalse($client->close());
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    private static function url(string $mode): string
    {
        return 'http://127.0.0.1:'.self::$port.'/'.$mode.'/v1/batch';
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function hits(): array
    {
        $hits = [];
        foreach (file(self::$log, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $hit = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($hit);
            /** @var array<string, mixed> $hit */
            $hits[] = $hit;
        }

        return $hits;
    }
}
