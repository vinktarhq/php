<?php

declare(strict_types=1);

namespace Vinktar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vinktar\Client;
use Vinktar\Tests\Support\RecordingTransport;

/**
 * A long-running worker: thousands of jobs through one client, alternating people, some failing.
 * Nothing one job sets may reach another, and nothing may build up.
 */
final class IsolationTest extends TestCase
{
    private const JOBS = 3000;

    public function testThousandsOfJobsNeverShareAnActorOrProperties(): void
    {
        $transport = new RecordingTransport();
        $client = new Client(['writeKey' => 'vnk_sk_worker', 'transport' => $transport, 'autoFlush' => false, 'logger' => static function (): void {}, 'maxQueueSize' => 10_000, 'flushAt' => 500,
            // 600 failures of one type in a burst would otherwise meet the per-type valve (half of this per minute).
            'maxErrorsPerMinute' => 10_000]);

        for ($job = 0; $job < self::JOBS; ++$job) {
            $user = 'user-'.($job % 7);
            try {
                $client->withScope(static function () use ($client, $job, $user): void {
                    $client->setUser(['id' => $user]);
                    $client->register(['job' => $job]);
                    $client->setTag('job', (string) $job);
                    if ($job % 5 === 0) {
                        throw new \RuntimeException("job {$job} failed");
                    }
                    $client->track('job done', ['expected_user' => $user, 'expected_job' => $job]);
                });
            } catch (\RuntimeException $e) {
                // What a worker loop does: report, and move on to the next job, outside the job's scope.
                $client->captureException($e, ['tags' => ['job' => (string) $job]]);
            }
            if ($job % 250 === 249) {
                self::assertTrue($client->flush());
            }
        }
        self::assertTrue($client->flush());

        $events = $transport->records('/v1/batch', 'batch');
        self::assertCount(self::JOBS - intdiv(self::JOBS, 5), $events);
        foreach ($events as $event) {
            self::assertIsArray($event['payload']);
            self::assertSame($event['payload']['expected_user'], $event['user_id'] ?? null);
            self::assertSame($event['payload']['expected_job'], $event['payload']['job'] ?? null);
        }

        // The failures were captured after their job's scope ended, on the worker's own scope: no job's
        // user and no job's tag reached them.
        $errors = $transport->records('/v1/errors', 'errors');
        self::assertCount(intdiv(self::JOBS, 5), $errors);
        foreach ($errors as $error) {
            self::assertArrayNotHasKey('user_id', $error);
            self::assertIsArray($error['tags']);
            self::assertIsString($error['tags']['job'] ?? null);
            self::assertIsArray($error['exceptions']);
            self::assertIsArray($error['exceptions'][0]);
            self::assertSame('job '.$error['tags']['job'].' failed', $error['exceptions'][0]['value']);
        }

        self::assertNull($client->scope()->userId());
        self::assertSame([], $client->scope()->properties());
        self::assertSame([], $client->scope()->tags());
        self::assertSame([], $client->scope()->breadcrumbs());
    }

    public function testEnterScopeGivesEveryJobAFreshStart(): void
    {
        $transport = new RecordingTransport();
        $client = new Client(['writeKey' => 'vnk_sk_worker', 'transport' => $transport, 'autoFlush' => false, 'logger' => static function (): void {}, 'initialScope' => ['tags' => ['service' => 'worker']]]);

        foreach (['alice', 'bob', 'carol'] as $name) {
            $client->withScope(static function () use ($client, $name): void {
                $scope = $client->enterScope();
                self::assertNull($scope->userId());
                self::assertSame(['service' => 'worker'], $scope->tags());
                $client->setUser(['id' => $name]);
                $client->addBreadcrumb(['message' => "started {$name}"]);
                $client->captureMessage("done {$name}");
            });
        }
        $client->flush();

        foreach ($transport->records('/v1/errors', 'errors') as $error) {
            self::assertIsArray($error['exceptions']);
            self::assertIsArray($error['exceptions'][0]);
            self::assertIsString($error['exceptions'][0]['value'] ?? null);
            $name = substr($error['exceptions'][0]['value'], 5);
            self::assertSame($name, $error['user_id'] ?? null);
            self::assertIsArray($error['breadcrumbs']);
            self::assertSame(["started {$name}"], array_column($error['breadcrumbs'], 'message'));
        }
    }
}
