<?php

declare(strict_types=1);

namespace Vinktar\Tests\Support;

use Vinktar\Client;
use Vinktar\Transport\Response;
use Vinktar\Transport\Transport;

/**
 * Runs one case of `spec/fixtures/hostile.json` against the public API, as a strict application
 * would meet it: this file declares strict types, so a scalar the SDK's signature does not accept is
 * a TypeError before the SDK has run a line, and every PHP error of every level (deprecations and
 * notices included) becomes an ErrorException for the length of the case.
 *
 * It returns what went wrong as sentences rather than asserting, so the same code runs under
 * PHPUnit and in a child process (`tests/Fixtures/hostile.php`) for the cases that would take the
 * test runner down with them.
 */
final class HostileRunner
{
    public const CAPABILITIES = ['common', 'backend'];

    private const WRITE_KEY = 'vnk_sk_hostile';

    /** @var list<string> */
    private array $failures = [];

    /** @var list<array{int, string, string, int}> what the application's error handler was called with */
    private array $handlerCalls = [];

    /**
     * Why this SDK does not run a case, or null when it does.
     *
     * @param array<string, mixed> $case
     */
    public static function skipReason(array $case): ?string
    {
        $capability = \is_string($case['capability'] ?? null) ? $case['capability'] : '';
        if (!\in_array($capability, self::CAPABILITIES, true)) {
            return "capability {$capability}: this SDK runs ".implode(' and ', self::CAPABILITIES);
        }
        $options = $case['options'] ?? null;
        if (\is_array($options) && ($options['$runtime'] ?? null) === 'frozenGlobals') {
            return 'frozenGlobals: the SDK patches no globals in PHP';
        }

        return null;
    }

    /**
     * Whether a case needs a process of its own: one whose regression is a fatal error no test can
     * catch, or one that installs the process-wide handlers.
     *
     * @param array<string, mixed> $case
     */
    public static function needsItsOwnProcess(array $case): bool
    {
        $host = \is_array($case['host'] ?? null) ? $case['host'] : [];

        return \in_array('errorHandler(levels)', $host, true) || str_contains(json_encode($case, \JSON_THROW_ON_ERROR), 'hostile(self_replicating)');
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return list<string> what went wrong; empty when the case passed
     */
    public function run(array $case): array
    {
        $this->failures = [];
        putenv('VINKTAR_KEY');

        $raised = [];
        // A client built from options that are not options writes to error_log(); keep that out of the run.
        $errorLog = ini_set('error_log', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        set_error_handler(static function (int $type, string $message, string $file, int $line) use (&$raised): bool {
            // The convention every strict handler follows: `@` lowers error_reporting() for one call.
            if ((error_reporting() & $type) === 0) {
                return false;
            }
            $raised[] = "PHP raised \"{$message}\" ({$type}) at {$file}:{$line}";
            throw new \ErrorException($message, 0, $type, $file, $line);
        });

        try {
            $given = $case['options'] ?? [];
            $runtime = \is_array($given) ? ($given['$runtime'] ?? null) : null;
            $transport = $runtime === 'unreachableHost' ? new StallingTransport() : new RecordingTransport();
            $logged = [];
            $options = $this->options($given, $transport, $logged);

            $client = $this->guard('new Client()', static fn (): Client => self::construct($options));
            if ($client instanceof Client) {
                $this->steps($client, $client, self::list($case['steps'] ?? []));
                $host = \is_array($case['host'] ?? null) ? $case['host'] : [];
                if (\in_array('errorHandler(levels)', $host, true)) {
                    $this->errorHandlerLevels(\is_array($options) ? $options : []);
                }
                // A case is over when its client has closed; whatever it still owed is attempted here.
                $this->guard('close', static fn (): bool => $client->close());
                $this->expectations(\is_array($case['expect'] ?? null) ? $case['expect'] : [], $transport, $logged);
            }
        } finally {
            restore_error_handler();
            if (\is_string($errorLog)) {
                ini_set('error_log', $errorLog);
            }
        }

        return [...$this->failures, ...$raised];
    }

    /**
     * The test's defaults with the case's options over them. Options that are not an array are
     * handed to the constructor as they are.
     *
     * @param array<string, int> $logged lines per level, counted by the logger this returns
     */
    private function options(mixed $given, Transport $transport, array &$logged): mixed
    {
        if (!\is_array($given)) {
            return self::materialise($given);
        }
        $throws = static function (): never {
            throw new \RuntimeException('a callback that always throws');
        };
        $options = [
            'writeKey' => self::WRITE_KEY,
            'transport' => $transport,
            'autoFlush' => false,
            // identify() with nothing to link and no traits is not sent, so the root scope has a device.
            'initialScope' => ['deviceId' => 'hostile-device'],
            'logger' => static function (string $level) use (&$logged): void {
                $logged[$level] = ($logged[$level] ?? 0) + 1;
            },
        ];
        if (($given['$runtime'] ?? null) === 'unreachableHost') {
            // Every capture tries to send, against the shortest timeouts the client accepts.
            $options = [...$options, 'flushAt' => 1, 'requestTimeoutMs' => 500, 'shutdownTimeout' => 100];
        }
        foreach ($given as $key => $value) {
            if ($key === '$runtime') {
                continue;
            }
            if ($key === 'hooks' && $value === 'allThrow') {
                $options = [...$options, 'beforeTrack' => $throws, 'beforeSend' => $throws, 'beforeBreadcrumb' => $throws];
            } elseif (\in_array($key, ['logger', 'onError'], true) && $value === 'throws') {
                $options[$key] = $throws;
            } elseif ($key === 'transport' && $value === 'throws') {
                $options[$key] = new class implements Transport {
                    public function send(string $url, array $headers, string $body, int $timeoutMs): Response
                    {
                        throw new \RuntimeException('a transport that always throws');
                    }
                };
            } elseif ($key === 'writeKey' && $value === null) {
                unset($options['writeKey']);
            } else {
                $options[(string) $key] = self::materialise($value);
            }
        }

        return $options;
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function steps(Client $client, object $target, array $steps): void
    {
        foreach ($steps as $step) {
            $this->step($client, $target, $step);
        }
    }

    /**
     * @param array<string, mixed> $step
     */
    private function step(Client $client, object $target, array $step): void
    {
        $do = \is_string($step['do'] ?? null) ? $step['do'] : '';
        $capability = $step['capability'] ?? null;
        if (\is_string($capability) && !\in_array($capability, self::CAPABILITIES, true)) {
            return;
        }
        $nested = self::list($step['steps'] ?? []);
        $args = self::arguments($do, \is_array($step['args'] ?? null) ? array_values($step['args']) : []);

        if ($do === 'scope') {
            $scope = $this->guard('scope', static fn (): object => $client->scope());
            if (\is_object($scope)) {
                $this->steps($client, $scope, $nested);
            }

            return;
        }
        // A method this SDK does not have: framework adapters (`request`), a promise to wait for.
        if (!method_exists($target, $do)) {
            return;
        }

        $call = static fn (): mixed => $target->{$do}(...$args);
        if ($do === 'withScope' && !\array_key_exists('args', $step)) {
            $call = fn (): mixed => $client->withScope(function () use ($client, $nested): void {
                $this->steps($client, $client, $nested);
            });
        } elseif ($do === 'enterScope' && !\array_key_exists('args', $step)) {
            // A request boundary, entered inside its own unit of work as a framework hook would be.
            $call = fn (): mixed => $client->withScope(function () use ($client, $nested): void {
                $client->enterScope();
                $this->steps($client, $client, $nested);
            });
        } elseif ($do === 'captureException' && \is_array($args[1] ?? null) && \is_array($args[1]['request'] ?? null)) {
            // The request an error is served for lives on the scope here, not in the hint.
            $request = [];
            foreach ($args[1]['request'] as $field => $value) {
                $request[(string) $field] = $value;
            }
            $call = static function () use ($client, $target, $do, $args, $request): mixed {
                $client->scope()->setRequest($request);

                return $target->{$do}(...$args);
            };
        }

        $started = microtime(true);
        $result = $this->guard($do, $call);
        $took = (microtime(true) - $started) * 1000;

        if (\is_int($step['withinMs'] ?? null) && $took > $step['withinMs']) {
            $this->failures[] = \sprintf('%s() blocked for %d ms; the case allows %d', $do, $took, $step['withinMs']);
        }
        if (\array_key_exists('returns', $step) && $result !== $step['returns']) {
            $this->failures[] = "{$do}() returned ".var_export($result, true).'; the case expects '.var_export($step['returns'], true);
        }
    }

    /**
     * `errorHandler(levels)`: an application whose error handler was registered for warnings only,
     * and throws. With the SDK capturing errors it is called for exactly what PHP called it for
     * before, with the same arguments, and for nothing else.
     *
     * @param array<array-key, mixed> $options
     */
    private function errorHandlerLevels(array $options): void
    {
        $handler = function (int $type, string $message, string $file, int $line): bool {
            $this->handlerCalls[] = [$type, $message, $file, $line];
            throw new \ErrorException($message, 0, $type, $file, $line);
        };

        set_error_handler($handler, \E_WARNING);
        try {
            $before = $this->probeErrorLevels();
            if (\count($before['E_WARNING']['calls']) !== 1 || $before['E_USER_DEPRECATED']['calls'] !== []) {
                $this->failures[] = 'errorHandler(levels): the probe itself is wrong: '.json_encode($before);
            }
            $client = $this->guard('new Client(captureErrors)', static fn (): Client => self::construct([...$options, 'captureErrors' => true]));
            $after = $this->probeErrorLevels();
            foreach ($before as $name => $expected) {
                if ($after[$name] !== $expected) {
                    $this->failures[] = "errorHandler(levels): {$name} reached the application's handler as ".json_encode($after[$name]).' with the SDK installed, and as '.json_encode($expected).' without it';
                }
            }
            if ($client instanceof Client) {
                $this->guard('close', static fn (): bool => $client->close());
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * One error of each level, raised from the same lines every time, and what the application's
     * handler was called with for it.
     *
     * @return array<string, array{calls: list<array{int, string, string, int}>, thrown: string|null}>
     */
    private function probeErrorLevels(): array
    {
        $probes = [
            'E_WARNING' => static function (): void {
                unlink(__DIR__.'/no-such-file');
            },
            'E_USER_WARNING' => static function (): void {
                trigger_error('hostile: a warning the application raised', \E_USER_WARNING);
            },
            'E_USER_DEPRECATED' => static function (): void {
                trigger_error('hostile: a deprecation', \E_USER_DEPRECATED);
            },
        ];
        $seen = [];
        foreach ($probes as $name => $probe) {
            $this->handlerCalls = [];
            $thrown = null;
            try {
                $probe();
            } catch (\Throwable $error) {
                $thrown = $error::class.': '.$error->getMessage();
            }
            $seen[$name] = ['calls' => $this->handlerCalls, 'thrown' => $thrown];
        }

        return $seen;
    }

    /**
     * @param array<array-key, mixed> $expect
     * @param array<string, int>      $logged
     */
    private function expectations(array $expect, Transport $transport, array $logged): void
    {
        $requests = $transport instanceof RecordingTransport ? $transport->requests : [];
        foreach (\is_array($expect['requests'] ?? null) ? $expect['requests'] : [] as $endpoint => $count) {
            $carrying = array_filter($requests, static fn (array $r): bool => $r['path'] === (string) $endpoint
                && array_filter([$r['body']['batch'] ?? [], $r['body']['identify'] ?? [], $r['body']['errors'] ?? []]) !== []);
            if (\count($carrying) !== $count) {
                $this->failures[] = \count($carrying)." request(s) to {$endpoint}; the case expects ".var_export($count, true);
            }
        }
        foreach (self::list($expect['sent'] ?? []) as $match) {
            $key = isset($match['identify']) ? 'identify' : 'item';
            $found = false;
            foreach ($requests as $request) {
                if ($request['path'] !== ($match['endpoint'] ?? null)) {
                    continue;
                }
                $records = $key === 'identify' ? ($request['body']['identify'] ?? []) : ($request['body']['batch'] ?? $request['body']['errors'] ?? []);
                foreach (\is_array($records) ? $records : [] as $record) {
                    $found = $found || self::wireMatches($match[$key] ?? [], $record);
                }
            }
            if (!$found) {
                $this->failures[] = 'never sent: '.json_encode($match);
            }
        }
        if (\is_array($expect['logged'] ?? null)) {
            $level = \is_string($expect['logged']['level'] ?? null) ? $expect['logged']['level'] : '';
            $count = $expect['logged']['count'] ?? null;
            if (($logged[$level] ?? 0) !== $count) {
                $this->failures[] = ($logged[$level] ?? 0)." line(s) logged at {$level}; the case expects ".var_export($count, true);
            }
        }
    }

    /**
     * Run one call the way the application would make it, and turn anything it throws into a failure.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T|null
     */
    private function guard(string $what, callable $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $error) {
            $this->failures[] = "{$what} threw ".$error::class.': '.$error->getMessage();

            return null;
        }
    }

    /** Whatever the case says the options are, array or not, past the constructor's documented type. */
    private static function construct(mixed $options): Client
    {
        return (new \ReflectionClass(Client::class))->newInstance($options);
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
        // setContext(name, value) elsewhere is setContext([name => value]) here.
        if ($do === 'setContext' && \count($args) === 2 && \is_string($args[0]) && !str_starts_with($args[0], 'hostile(')) {
            $args = [[$args[0] => $args[1]]];
        }

        return array_map(self::materialise(...), $args);
    }

    /** Every `hostile(kind)` and `error(message)` token, at any depth, as the PHP value it names. */
    private static function materialise(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(self::materialise(...), $value);
        }
        if (!\is_string($value)) {
            return $value;
        }
        if (preg_match('/^error\((.*)\)$/', $value, $m) === 1) {
            return new \RuntimeException($m[1]);
        }
        if (preg_match('/^hostile\((\w+)\)$/', $value, $m) !== 1) {
            return $value;
        }

        return self::hostile($m[1]);
    }

    private static function hostile(string $kind): mixed
    {
        switch ($kind) {
            case 'cycle':
                // Both of PHP's cycles: an object that holds itself, and an array that does, by reference.
                $object = new \stdClass();
                $object->child = new \stdClass();
                $object->child->again = $object;
                $array = ['object' => $object, 'child' => ['again' => null]];
                $array['child']['again'] = &$array;

                return $array;
            case 'throwing_accessor':
                return new ThrowingAccessor();
            case 'self_replicating':
                return new SelfReplicating();
            case 'no_json_form':
                $object = new \stdClass();
                $object->nan = \NAN;
                $object->inf = \INF;
                $object->closure = static fn (): int => 1;
                $object->resource = fopen('php://memory', 'r');

                return $object;
            case 'deep':
                $deep = [];
                for ($i = 0; $i < 20_000; ++$i) {
                    $deep = ['n' => $deep];
                }

                return $deep;
            case 'huge':
                return str_repeat('x', 5 * 1024 * 1024);
            case 'bad_text':
                return "bad \xC3\x28 text \xFF";
            case 'null':
                return null;
            case 'integer':
                return 42;
            case 'object':
                return new \stdClass();
            case 'list_of_null':
                return [null];
            default:
                throw new \LogicException("hostile.json names a kind this runner cannot make: {$kind}");
        }
    }

    /** Partial deep match, as in the scenarios: null means absent or empty. */
    private static function wireMatches(mixed $expected, mixed $actual): bool
    {
        if ($expected === null) {
            return $actual === null || $actual === '' || $actual === [];
        }
        if (\is_array($expected)) {
            if (!\is_array($actual)) {
                return false;
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

/** Reading it as a string or as JSON throws. */
final class ThrowingAccessor implements \JsonSerializable, \Stringable
{
    public function __toString(): string
    {
        throw new \RuntimeException('__toString() throws');
    }

    public function jsonSerialize(): mixed
    {
        throw new \RuntimeException('jsonSerialize() throws');
    }
}

/** Its JSON form is another one of itself, forever, and never the same instance twice. */
final class SelfReplicating implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return new self();
    }
}
