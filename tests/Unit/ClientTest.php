<?php

declare(strict_types=1);

namespace Vinktar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vinktar\Client;
use Vinktar\Internal\ExceptionBuilder;
use Vinktar\Tests\Support\RecordingTransport;
use Vinktar\Tests\Support\StallingTransport;
use Vinktar\Version;

/**
 * What the scenarios do not pin: the shape of a request, a constructor that never throws, what a
 * capture may cost the caller in time, and the frames that are the SDK's own.
 */
final class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('VINKTAR_KEY');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function client(RecordingTransport $transport, array $options = []): Client
    {
        return new Client([...self::quiet(), 'writeKey' => 'vnk_sk_unit', 'transport' => $transport, 'autoFlush' => false, ...$options]);
    }

    /**
     * @return array{logger: \Closure(): void}
     */
    private static function quiet(): array
    {
        return ['logger' => static function (): void {}];
    }

    public function testEveryRequestNamesTheLibrarySoItsClientReportIsFiledUnderIt(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport);
        $client->captureMessage('same');
        $client->captureMessage('same');
        $client->track('seen');

        self::assertTrue($client->flush());
        self::assertCount(2, $transport->requests);
        foreach ($transport->requests as $request) {
            self::assertSame(['$lib' => Version::LIB, '$lib_version' => Version::VERSION], $request['body']['context'] ?? null);
        }
        $report = $transport->requests[0]['body']['client_report'] ?? null;
        self::assertIsArray($report);
        self::assertSame([['reason' => 'deduplicated', 'category' => 'error', 'quantity' => 1]], $report['discarded'] ?? null);
    }

    public function testEmptyObjectsStayObjectsOnTheWire(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport, ['gzip' => false, 'transport' => $transport]);
        $client->track('bare');
        $client->flush();

        $sent = $transport->records('/v1/batch', 'batch');
        self::assertCount(1, $sent);
        self::assertSame([], $sent[0]['payload']);
        self::assertIsArray($sent[0]['context']);
        self::assertSame(Version::LIB, $sent[0]['context']['$lib'] ?? null);
    }

    public function testLargeBodiesAreCompressed(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport);
        $client->track('big', ['text' => str_repeat('x', 250), 'more' => str_repeat('y', 250), 'again' => str_repeat('z', 250), 'last' => str_repeat('w', 250)]);
        $client->track('small');
        self::assertTrue($client->flush());

        self::assertTrue($transport->requests[0]['gzip']);
        self::assertSame('gzip', $transport->requests[0]['headers']['content-encoding'] ?? null);
        self::assertSame(['big', 'small'], array_column($transport->records('/v1/batch', 'batch'), 'name'));
    }

    public function testADisabledClientSaysNothing(): void
    {
        $lines = [];
        $disabled = new Client(['enabled' => false, 'logger' => static function (string $level, string $message) use (&$lines): void {
            $lines[] = "{$level}: {$message}";
        }]);
        $disabled->track('ignored');
        self::assertTrue($disabled->flush());
        self::assertSame([], $lines);
    }

    public function testAnEnabledClientWithoutAKeyIsInertAndSaysSoOnce(): void
    {
        $disabled = new Client(['enabled' => false, ...self::quiet()]);
        $disabled->track('ignored');
        self::assertTrue($disabled->flush());
        self::assertTrue($disabled->close());

        $lines = [];
        $transport = new RecordingTransport();
        $keyless = new Client(['transport' => $transport, 'flushAt' => 1, 'logger' => static function (string $level, string $message) use (&$lines): void {
            $lines[] = [$level, $message];
        }]);
        $keyless->track('ignored');
        $keyless->identify('user_1', ['plan' => 'pro']);
        self::assertSame('', $keyless->captureException(new \RuntimeException('ignored')));
        self::assertTrue($keyless->flush());
        self::assertTrue($keyless->close());

        self::assertSame([], $transport->requests);
        self::assertSame([['error', '[vinktar] no write key: pass ["writeKey" => ...] or set VINKTAR_KEY. Nothing will be sent']], $lines);
    }

    public function testTheConstructorTakesAnythingAndNeverThrows(): void
    {
        // None of these has a logger, so what they have to say goes to error_log(): not into the test run.
        $errorLog = ini_set('error_log', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            foreach ([null, 42, new \stdClass(), ['logger' => 'not callable', 'transport' => 42, 'flushAt' => 'many', 'initialScope' => 7, 'beforeSend' => [null]]] as $options) {
                // Through reflection, because the point is a caller that ignores the documented type.
                $client = (new \ReflectionClass(Client::class))->newInstance($options);
                $client->track('ignored');
                self::assertTrue($client->flush());
            }
        } finally {
            ini_set('error_log', (string) $errorLog);
        }
    }

    public function testACaptureThatFlushesIsBoundedAndTheNextOneDoesNotTryAgain(): void
    {
        $transport = new StallingTransport();
        $client = new Client([...self::quiet(), 'writeKey' => 'vnk_sk_unit', 'transport' => $transport, 'autoFlush' => false, 'flushAt' => 1, 'requestTimeoutMs' => 1000, 'shutdownTimeout' => 100]);

        $started = microtime(true);
        $client->track('first');
        $client->track('second');
        $client->track('third');
        $took = (microtime(true) - $started) * 1000;

        // One attempt, cut to the capture's budget; after it the category is held and nothing is tried.
        self::assertSame(1, $transport->attempts);
        self::assertLessThan(250, $took);
    }

    public function testRequestHeadersWithNoValueDoNotCostTheError(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport);
        $client->scope()->setRequest(['method' => 'get', 'url' => 'https://example.com/a?b=1', 'headers' => ['Accept' => [], 'Referer' => [null], 'User-Agent' => ['curl/8'], 'Cookie' => 42]]);
        self::assertNotSame('', $client->captureException(new \RuntimeException('with a request')));
        $client->flush();

        $errors = $transport->records('/v1/errors', 'errors');
        self::assertCount(1, $errors);
        self::assertSame(['method' => 'GET', 'url' => 'https://example.com/a', 'headers' => ['user-agent' => 'curl/8']], $errors[0]['request'] ?? null);
    }

    public function testHeadersWithNoValueAreNotAnId(): void
    {
        $client = $this->client(new RecordingTransport());
        $scope = $client->scopeFromHeaders(['X-Vinktar-Device-Id' => [], 'x-vinktar-session-id' => [null, 'later'], 'HTTP_X_VINKTAR_DEVICE_ID' => ['device-1']]);

        self::assertSame('device-1', $scope->deviceId());
        self::assertNull($scope->sessionId());
    }

    public function testTheClientAndTheScopeTreatTheSameArgumentsTheSameWay(): void
    {
        $client = $this->client(new RecordingTransport());
        $viaClient = $client->enterScope();
        $client->setTag('plan', 3);
        self::call($client, 'setTags', ['beta' => true, 'bad' => [], 'worse' => new \stdClass()]);
        $client->register(['a' => 1]);

        $viaScope = $client->enterScope();
        $viaScope->setTag('plan', 3);
        self::call($viaScope, 'setTags', ['beta' => true, 'bad' => [], 'worse' => new \stdClass()]);
        $viaScope->register(['a' => 1]);

        self::assertSame(['plan' => '3', 'beta' => 'true'], $viaClient->tags());
        self::assertSame($viaClient->tags(), $viaScope->tags());
        self::assertSame($viaClient->properties(), $viaScope->properties());
    }

    public function testFlushAfterCloseAnswersWithCloseAndSendsNothingMore(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(503, ['error' => 'storage_unavailable']);
        $client = $this->client($transport, ['shutdownTimeout' => 200]);
        $client->track('lost');

        self::assertFalse($client->close());
        self::assertFalse($client->flush());
        $client->track('after');
        self::assertFalse($client->close());
        self::assertCount(1, $transport->requests);
    }

    public function testTheSdksOwnFramesAreNeverInApp(): void
    {
        $root = \dirname(__DIR__, 2);
        $builder = new ExceptionBuilder($root);
        self::assertFalse($builder->isInApp($root.'/src/Client.php'));
        self::assertFalse($builder->isInApp('/srv/app/vendor/acme/lib/src/Thing.php'));
        self::assertTrue($builder->isInApp($root.'/tests/Unit/ClientTest.php'));
    }

    public function testCapturedExceptionsCarryTheCauseChainAndCrashLastFrames(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport, ['projectRoot' => \dirname(__DIR__, 2)]);
        try {
            self::failWithCause();
        } catch (\Throwable $e) {
            self::assertNotSame('', $client->captureException($e));
        }
        $client->flush();

        $errors = $transport->records('/v1/errors', 'errors');
        self::assertCount(1, $errors);
        $exceptions = $errors[0]['exceptions'];
        self::assertIsArray($exceptions);
        self::assertSame([\RuntimeException::class, \LogicException::class], array_column($exceptions, 'type'));
        self::assertIsArray($exceptions[0]);
        $frames = $exceptions[0]['stack'];
        self::assertIsArray($frames);
        $crash = $frames[\count($frames) - 1];
        self::assertIsArray($crash);
        self::assertSame(self::class.'::failWithCause', $crash['function'] ?? null);
        self::assertSame('tests/Unit/ClientTest.php', $crash['file'] ?? null);
        self::assertTrue($crash['in_app'] ?? null);
        self::assertSame(['type' => 'manual', 'handled' => true, 'synthetic' => false], $errors[0]['mechanism']);
    }

    /** A call with arguments the documented types do not allow, which is what is being tested. */
    private static function call(object $target, string $method, mixed ...$args): mixed
    {
        return $target->{$method}(...$args);
    }

    private static function failWithCause(): never
    {
        throw new \RuntimeException('outer', 0, new \LogicException('inner'));
    }
}
