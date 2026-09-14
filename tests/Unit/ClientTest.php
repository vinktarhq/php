<?php

declare(strict_types=1);

namespace Vinktar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vinktar\Client;
use Vinktar\Internal\ExceptionBuilder;
use Vinktar\Tests\Support\RecordingTransport;
use Vinktar\Version;

/**
 * What the scenarios do not pin: the shape of a request, the constructor's one exception, and the
 * frames that are the SDK's own.
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

    public function testAnEnabledClientWithoutAKeyThrowsAndADisabledOneDoesNot(): void
    {
        $disabled = new Client(['enabled' => false, ...self::quiet()]);
        $disabled->track('ignored');
        self::assertTrue($disabled->flush());
        self::assertTrue($disabled->close());

        $this->expectException(\InvalidArgumentException::class);
        new Client(self::quiet());
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

    private static function failWithCause(): never
    {
        throw new \RuntimeException('outer', 0, new \LogicException('inner'));
    }
}
