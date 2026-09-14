<?php

declare(strict_types=1);

namespace Vinktar\Tests\Spec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vinktar\Client;
use Vinktar\Tests\Support\RecordingTransport;

/**
 * The behaviour scenarios in `spec/fixtures/scenarios.json` that apply to a server SDK (`common`
 * and `backend`), run against the public API with a recording transport. Only what reaches the
 * wire is asserted. Browser scenarios (a page's persisted device) do not apply.
 *
 * Concurrency is Fibers: each `parallel` branch runs in its own, and `yield` suspends it, so
 * branches interleave in a fixed order the way the fixture describes.
 */
final class ScenariosTest extends TestCase
{
    /** @var array<string, mixed> the result of a close started with `await: false`, per client */
    private array $closing = [];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function scenarios(): array
    {
        return array_filter(
            SpecFile::cases('fixtures/scenarios.json', 'scenarios'),
            static fn (array $row): bool => \in_array($row[0]['capability'] ?? null, ['common', 'backend'], true),
        );
    }

    public function testTheFixtureVersionIsTheOneThisRunnerUnderstands(): void
    {
        self::assertSame(1, SpecFile::load('fixtures/scenarios.json')['version'] ?? null);
        self::assertNotEmpty(self::scenarios());
    }

    /**
     * @param array<string, mixed> $scenario
     */
    #[DataProvider('scenarios')]
    public function testScenario(array $scenario): void
    {
        putenv('VINKTAR_KEY');
        $transport = new RecordingTransport();
        foreach (self::list($scenario['respond'] ?? []) as $response) {
            self::assertIsInt($response['status']);
            $headers = \is_array($response['headers'] ?? null) ? $response['headers'] : [];
            /** @var array<string, string> $headers */
            $transport->respond($response['status'], $response['body'] ?? null, $headers);
        }

        $names = ['default'];
        if (\is_array($scenario['clients'] ?? null) && $scenario['clients'] !== []) {
            $names = array_values(array_map(static fn (mixed $name): string => \is_string($name) ? $name : 'default', $scenario['clients']));
        }
        $clients = [];
        foreach ($names as $name) {
            $clients[$name] = new Client($this->options($scenario, $name, $transport));
        }
        $this->closing = [];

        $this->runSteps($clients, $names[0], self::list($scenario['steps'] ?? []));

        $keyToName = [];
        foreach ($names as $name) {
            $keyToName[self::keyFor($name)] = $name;
        }
        $items = [];
        $reports = [];
        $carrying = [];
        foreach ($transport->requests as $request) {
            $client = $keyToName[$request['headers']['x-vinktar-key'] ?? ''] ?? $names[0];
            $body = $request['body'];
            $list = static fn (string $key): array => self::list($body[$key] ?? []);
            if ($request['path'] === '/v1/batch') {
                foreach ($list('batch') as $value) {
                    $items[] = ['client' => $client, 'endpoint' => '/v1/batch', 'kind' => 'item', 'value' => $value];
                }
                foreach ($list('identify') as $value) {
                    $items[] = ['client' => $client, 'endpoint' => '/v1/batch', 'kind' => 'identify', 'value' => $value];
                }
            }
            if ($request['path'] === '/v1/errors') {
                foreach ($list('errors') as $value) {
                    $items[] = ['client' => $client, 'endpoint' => '/v1/errors', 'kind' => 'item', 'value' => $value];
                }
            }
            if ($list('batch') !== [] || $list('identify') !== [] || $list('errors') !== []) {
                $carrying[] = ['client' => $client, 'endpoint' => $request['path']];
            }
            $report = $body['client_report'] ?? null;
            if (\is_array($report)) {
                array_push($reports, ...self::list($report['discarded'] ?? []));
            }
        }

        $expect = \is_array($scenario['expect'] ?? null) ? $scenario['expect'] : [];
        $find = function (array $match) use ($items, $names): ?array {
            foreach ($items as $item) {
                if ($item['endpoint'] !== $match['endpoint'] || $item['client'] !== ($match['client'] ?? $names[0])) {
                    continue;
                }
                if (isset($match['identify'])) {
                    if ($item['kind'] !== 'identify' || !self::wireMatches($match['identify'], $item['value'])) {
                        continue;
                    }
                } elseif ($item['kind'] !== 'item' || !self::wireMatches($match['item'] ?? [], $item['value'])) {
                    continue;
                }
                if (\is_array($match['maxTopLevelKeys'] ?? null)) {
                    $keys = [];
                    $fields = \is_array($match['maxTopLevelKeys']['of'] ?? null) ? $match['maxTopLevelKeys']['of'] : [];
                    foreach (array_filter($fields, \is_string(...)) as $field) {
                        foreach (array_keys(\is_array($item['value'][$field] ?? null) ? $item['value'][$field] : []) as $key) {
                            $keys[$key] = true;
                        }
                    }
                    if (\count($keys) > $match['maxTopLevelKeys']['max']) {
                        continue;
                    }
                }

                return $item;
            }

            return null;
        };

        foreach (self::list($expect['sent'] ?? []) as $match) {
            self::assertNotNull($find($match), 'sent '.json_encode($match));
        }
        foreach (self::list($expect['notSent'] ?? []) as $match) {
            self::assertNull($find($match), 'not sent '.json_encode($match));
        }
        $requests = \is_array($expect['requests'] ?? null) ? $expect['requests'] : [];
        foreach ($requests as $endpoint => $count) {
            self::assertIsInt($count);
            $matching = array_filter($carrying, static fn (array $r): bool => $r['endpoint'] === (string) $endpoint && $r['client'] === $names[0]);
            self::assertCount($count, $matching, "requests to {$endpoint}");
        }
        $errorCounts = \is_array($expect['errorCount'] ?? null) ? $expect['errorCount'] : [];
        foreach ($errorCounts as $message => $count) {
            self::assertIsInt($count);
            $sent = array_filter($items, static fn (array $i): bool => $i['endpoint'] === '/v1/errors' && self::firstMessage($i['value']) === (string) $message);
            self::assertCount($count, $sent, "occurrences of \"{$message}\"");
        }
        foreach (self::list($expect['report'] ?? []) as $entry) {
            self::assertTrue(\in_array($entry, $reports, false), 'client report '.json_encode($entry).' in '.json_encode($reports));
        }
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    private function options(array $scenario, string $name, RecordingTransport $transport): array
    {
        $given = \is_array($scenario['options'] ?? null) ? $scenario['options'] : [];
        $hooks = $given['hooks'] ?? null;
        $writeKey = \array_key_exists('writeKey', $given) ? $given['writeKey'] : self::keyFor($name);
        unset($given['hooks'], $given['writeKey']);

        $options = ['transport' => $transport, 'logger' => static function (): void {}, 'autoFlush' => false];
        foreach ($given as $key => $value) {
            $options[(string) $key] = $value;
        }
        if ($writeKey !== null) {
            $options['writeKey'] = $writeKey;
        }
        if ($hooks === 'poisonProperty') {
            // A value JSON cannot encode, added at the top level where no normaliser looks again.
            $options['beforeTrack'] = static fn (array $event): array => ($event['name'] ?? null) === 'poison' ? [...$event, 'poison' => \NAN] : $event;
        }

        return $options;
    }

    /**
     * @param array<string, Client>      $clients
     * @param list<array<string, mixed>> $steps
     */
    private function runSteps(array $clients, string $fallback, array $steps): ?Returned
    {
        foreach ($steps as $step) {
            $result = $this->runStep($clients, $fallback, $step);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array<string, Client> $clients
     * @param array<string, mixed>  $step
     */
    private function runStep(array $clients, string $fallback, array $step): ?Returned
    {
        $do = \is_string($step['do'] ?? null) ? $step['do'] : '';
        $clientName = \is_string($step['client'] ?? null) ? $step['client'] : $fallback;
        $client = $clients[$clientName];
        $args = \is_array($step['args'] ?? null) ? array_values($step['args']) : [];
        $nested = fn (): ?Returned => $this->runSteps($clients, $fallback, self::list($step['steps'] ?? []));

        if ($do === 'value') {
            return new Returned($args[0] ?? null);
        }
        if ($do === 'throw') {
            throw new \RuntimeException(\is_string($args[0] ?? null) ? $args[0] : 'thrown');
        }
        if ($do === 'yield') {
            if (\Fiber::getCurrent() !== null) {
                \Fiber::suspend();
            }

            return null;
        }
        if ($do === 'parallel') {
            $fibers = [];
            foreach (self::list($step['branches'] ?? []) as $branch) {
                $fibers[] = new \Fiber(fn () => $this->runSteps($clients, $fallback, self::list($branch)));
            }
            foreach ($fibers as $fiber) {
                $fiber->start();
            }
            do {
                $running = false;
                foreach ($fibers as $fiber) {
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                    $running = $running || !$fiber->isTerminated();
                }
            } while ($running);

            return null;
        }
        if ($do === 'awaitClose') {
            if (\array_key_exists('returns', $step)) {
                self::assertSame($step['returns'], $this->closing[$clientName] ?? null, 'awaitClose returns');
            }

            return null;
        }

        $result = null;
        $threw = null;
        try {
            if ($do === 'withScope') {
                $result = $client->withScope(static fn () => $nested())?->value;
            } elseif ($do === 'enterScope') {
                // A request boundary, entered inside its own unit of work as a framework hook would be.
                $result = $client->withScope(static function () use ($client, $nested) {
                    $client->enterScope();

                    return $nested();
                })?->value;
            } else {
                $result = $client->{$do}(...self::arguments($do, $args));
                if ($do === 'close') {
                    $this->closing[$clientName] = $result;
                }
            }
        } catch (\Throwable $error) {
            $threw = $error;
        }

        if (isset($step['throws'])) {
            self::assertSame($step['throws'], $threw?->getMessage(), "{$do} throws");
        } elseif ($threw !== null) {
            throw $threw;
        }
        if (\array_key_exists('returns', $step) && ($step['await'] ?? true) !== false) {
            self::assertSame($step['returns'], $result, "{$do} returns");
        }

        return null;
    }

    /**
     * The fixture's JSON arguments, as PHP calls them.
     *
     * @param list<mixed> $args
     *
     * @return list<mixed>
     */
    private static function arguments(string $do, array $args): array
    {
        return array_map(static function (mixed $value, int $index) use ($do): mixed {
            // Skipped optional positions are written as null; identify's arrays mean "not given".
            if ($do === 'identify' && $index > 0 && $value === null) {
                return [];
            }
            if (\is_string($value) && preg_match('/^error\((.*)\)$/', $value, $m) === 1) {
                return new \RuntimeException($m[1]);
            }
            if (\is_string($value) && preg_match('/^props\((\d+)\)$/', $value, $m) === 1) {
                $props = [];
                for ($i = 0; $i < (int) $m[1]; ++$i) {
                    $props["p{$i}"] = 'v';
                }

                return $props;
            }

            return $value;
        }, $args, array_keys($args));
    }

    /** Partial deep match. null means absent or empty; lists match element-wise from the start. */
    private static function wireMatches(mixed $expected, mixed $actual): bool
    {
        if ($expected === null) {
            return $actual === null || $actual === '' || $actual === [];
        }
        if (\is_array($expected) && array_is_list($expected) && $expected !== []) {
            if (!\is_array($actual)) {
                return false;
            }
            foreach ($expected as $index => $value) {
                if (!self::wireMatches($value, $actual[$index] ?? null)) {
                    return false;
                }
            }

            return true;
        }
        if (\is_array($expected)) {
            if (!\is_array($actual)) {
                return array_filter($expected, static fn (mixed $v): bool => $v !== null) === [];
            }
            foreach ($expected as $key => $value) {
                if (!self::wireMatches($value, $actual[$key] ?? null)) {
                    return false;
                }
            }

            return true;
        }

        return $expected === $actual;
    }

    /**
     * An error's message: `exceptions[0].value`.
     *
     * @param array<string, mixed> $error
     */
    private static function firstMessage(array $error): ?string
    {
        $exceptions = $error['exceptions'] ?? null;
        $first = \is_array($exceptions) ? ($exceptions[0] ?? null) : null;

        return \is_array($first) && \is_string($first['value'] ?? null) ? $first['value'] : null;
    }

    private static function keyFor(string $name): string
    {
        return "vnk_sk_scenario_{$name}";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }
}

/** The value a nested `value` step asked the enclosing callback to return. */
final class Returned
{
    public function __construct(public readonly mixed $value)
    {
    }
}
