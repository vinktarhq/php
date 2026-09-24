<?php

declare(strict_types=1);

namespace Vinktar\Internal;

use Vinktar\Transport\CurlTransport;
use Vinktar\Transport\Transport;

/**
 * The client's options, resolved once: defaults applied, numbers clamped, unknown keys and bad
 * values reported. Option names are the same as `@vinktarhq/node`'s.
 *
 * @internal
 */
final class Options
{
    public const DEFAULT_HOST = 'https://in.vinktar.com';

    private const KNOWN = [
        'writeKey', 'key', 'host', 'enabled', 'debug', 'analytics', 'errors', 'release', 'environment', 'enabledEnvironments',
        'serverName', 'initialScope', 'flushAt', 'maxQueueSize', 'requestTimeoutMs', 'gzip', 'shutdownTimeout', 'autoFlush',
        'maxBreadcrumbs', 'sampleRate', 'errorSampleRate', 'maxErrorsPerMinute', 'maxEventsPerMinute', 'dedupe', 'ignoreErrors',
        'superProperties', 'sendDefaultPii', 'redactedKeys', 'propertyDenylist', 'maxValueBytes', 'normalizeDepth',
        'includeRawStack', 'attachStacktrace', 'projectRoot', 'contextLines', 'beforeSend', 'beforeTrack', 'beforeBreadcrumb',
        'onError', 'logger', 'transport', 'captureErrors', 'previousHandlerLevels',
    ];

    /**
     * @param list<string>                                                                                                                          $enabledEnvironments
     * @param array{userId: string|null, deviceId: string|null, sessionId: string|null, tags: array<string, string>, context: array<string, mixed>} $initialScope
     * @param list<string>                                                                                                                          $ignoreErrors
     * @param array<string, mixed>                                                                                                                  $superProperties
     * @param list<string>                                                                                                                          $redactedKeys
     * @param list<string>                                                                                                                          $propertyDenylist
     * @param list<callable>                                                                                                                        $beforeSend
     * @param list<callable>                                                                                                                        $beforeTrack
     * @param list<callable>                                                                                                                        $beforeBreadcrumb
     * @param string|null                                                                                                                           $inert               why the client will not send, when it will not
     */
    private function __construct(
        public readonly string $writeKey,
        public readonly string $host,
        public readonly bool $analytics,
        public readonly bool $errors,
        public readonly string $release,
        public readonly string $environment,
        public readonly array $enabledEnvironments,
        public readonly string $serverName,
        public readonly array $initialScope,
        public readonly int $flushAt,
        public readonly int $maxQueueSize,
        public readonly int $requestTimeoutMs,
        public readonly bool $gzip,
        public readonly int $shutdownTimeout,
        public readonly bool $autoFlush,
        public readonly bool $captureErrors,
        public readonly ?int $previousHandlerLevels,
        public readonly int $maxBreadcrumbs,
        public readonly float $sampleRate,
        public readonly float $errorSampleRate,
        public readonly int $maxErrorsPerMinute,
        public readonly int $maxEventsPerMinute,
        public readonly bool $dedupe,
        public readonly array $ignoreErrors,
        public readonly array $superProperties,
        public readonly bool $sendDefaultPii,
        public readonly array $redactedKeys,
        public readonly array $propertyDenylist,
        public readonly int $maxValueBytes,
        public readonly int $normalizeDepth,
        public readonly bool $includeRawStack,
        public readonly bool $attachStacktrace,
        public readonly string $projectRoot,
        public readonly int $contextLines,
        public readonly array $beforeSend,
        public readonly array $beforeTrack,
        public readonly array $beforeBreadcrumb,
        public readonly ?\Closure $onError,
        public readonly Transport $transport,
        public readonly ?string $inert,
    ) {
    }

    /**
     * @param array<string, mixed>                    $options
     * @param (callable(string): (string|false))|null $env     reads an environment variable
     */
    public static function resolve(array $options, Logger $logger, ?callable $env = null): self
    {
        $env ??= static fn (string $name): string|false => self::string(self::call('getenv', $name)) ?? false;
        $read = static function (string $name) use ($env): string {
            $value = $env($name);

            return \is_string($value) ? trim($value) : '';
        };

        foreach (array_keys($options) as $key) {
            if (!\in_array($key, self::KNOWN, true)) {
                $logger->warn("unknown option \"{$key}\" was ignored");
            }
        }

        $clampInt = static function (string $name, int $min, int $max, int $fallback) use ($options, $logger): int {
            $value = $options[$name] ?? null;
            if ($value === null) {
                return $fallback;
            }
            if (!\is_int($value) && !(\is_float($value) && is_finite($value))) {
                $logger->warn("{$name} must be a number; using {$fallback}");

                return $fallback;
            }
            $value = (int) round($value);
            if ($value < $min || $value > $max) {
                $clamped = min($max, max($min, $value));
                $logger->warn("{$name} {$value} is outside {$min} to {$max}; using {$clamped}");

                return $clamped;
            }

            return $value;
        };
        $clampRate = static function (string $name) use ($options, $logger): float {
            $value = $options[$name] ?? null;
            if ($value === null) {
                return 1.0;
            }
            if (!\is_int($value) && !\is_float($value)) {
                $logger->warn("{$name} must be a number between 0 and 1; using 1");

                return 1.0;
            }

            return (float) min(1, max(0, $value));
        };
        $bool = static fn (string $name, bool $fallback): bool => \is_bool($options[$name] ?? null) ? $options[$name] : $fallback;
        $text = static fn (string $name): string => \is_string($options[$name] ?? null) ? trim($options[$name]) : '';
        $strings = static function (string $name) use ($options, $logger): array {
            $value = $options[$name] ?? null;
            if ($value === null) {
                return [];
            }
            if (!\is_array($value)) {
                $logger->warn("{$name} must be a list of strings; ignored");

                return [];
            }

            return array_values(array_map(static fn (bool|float|int|string $v): string => (string) $v, array_filter($value, \is_scalar(...))));
        };
        $hooks = static fn (string $name): array => Hooks::listOf($options[$name] ?? null, static function (int $index) use ($name, $logger): void {
            $logger->warn("{$name}[{$index}] is not callable and was dropped");
        });

        $enabled = $bool('enabled', true);
        $writeKey = $text('writeKey');
        if ($writeKey === '') {
            $writeKey = $text('key');
        }
        if ($writeKey === '') {
            $writeKey = $read('VINKTAR_KEY');
        }
        if ($writeKey !== '' && !str_starts_with($writeKey, 'vnk_pk_') && !str_starts_with($writeKey, 'vnk_sk_')) {
            $logger->warn('the write key does not look like a Vinktar key (vnk_pk_… or vnk_sk_…)');
        }

        $inert = $enabled ? null : 'enabled is false';
        // A client switched off on purpose needs no key. One meant to send without one is a
        // deployment mistake, said once, at startup, as an error, where someone is looking. It is
        // not thrown: the application this is installed in did nothing wrong and keeps running.
        $keyless = $writeKey === '' && $enabled;
        if ($keyless) {
            $logger->error('no write key: pass ["writeKey" => ...] or set VINKTAR_KEY. Nothing will be sent');
            $inert = 'no write key';
        }

        $environment = $text('environment');
        if ($environment === '') {
            $environment = $read('VINKTAR_ENVIRONMENT');
        }
        if ($environment === '') {
            $environment = $read('APP_ENV');
        }
        if ($environment === '') {
            $environment = 'production';
        }
        $enabledEnvironments = $strings('enabledEnvironments');
        if ($enabledEnvironments !== [] && !\in_array($environment, $enabledEnvironments, true)) {
            $inert ??= "environment \"{$environment}\" is not in enabledEnvironments";
        }

        $flushAt = $clampInt('flushAt', 1, 1000, 20);
        $maxQueueSize = $clampInt('maxQueueSize', 1, 100_000, 1000);
        if ($maxQueueSize < $flushAt) {
            $logger->warn("maxQueueSize {$maxQueueSize} is below flushAt {$flushAt}; raised to match");
            $maxQueueSize = $flushAt;
        }

        $host = $text('host');
        if ($host === '') {
            $host = $read('VINKTAR_HOST');
        }
        $host = rtrim($host, '/');
        if ($host === '') {
            $host = self::DEFAULT_HOST;
        } elseif (preg_match('#^https?://#i', $host) !== 1) {
            $logger->warn("host \"{$host}\" is not an http(s) URL; using ".self::DEFAULT_HOST);
            $host = self::DEFAULT_HOST;
        }

        $release = $text('release');
        if ($release === '') {
            $release = $read('VINKTAR_RELEASE');
        }

        $serverName = $text('serverName');
        if ($serverName === '') {
            $serverName = self::string(self::call('gethostname')) ?? '';
        }

        $transport = $options['transport'] ?? null;
        if ($transport !== null && !$transport instanceof Transport) {
            $logger->warn('transport must implement '.Transport::class.'; using cURL');
            $transport = null;
        }
        if ($transport === null && !\function_exists('curl_init')) {
            $logger->error('ext-curl is not loaded, so nothing can be sent; install it or pass a transport');
            $inert ??= 'ext-curl is not loaded';
        }

        $onError = $options['onError'] ?? null;

        $previousHandlerLevels = $options['previousHandlerLevels'] ?? null;
        if ($previousHandlerLevels !== null && !\is_int($previousHandlerLevels)) {
            $logger->warn('previousHandlerLevels must be an error level mask such as E_ALL; ignored');
            $previousHandlerLevels = null;
        }

        $resolved = new self(
            writeKey: $writeKey,
            host: $host,
            analytics: $bool('analytics', true),
            errors: $bool('errors', true),
            release: $release,
            environment: $environment,
            enabledEnvironments: $enabledEnvironments,
            serverName: $serverName,
            initialScope: self::initialScope($options['initialScope'] ?? null, $logger),
            flushAt: $flushAt,
            maxQueueSize: $maxQueueSize,
            requestTimeoutMs: $clampInt('requestTimeoutMs', 500, 60_000, 5_000),
            gzip: $bool('gzip', true),
            shutdownTimeout: $clampInt('shutdownTimeout', 100, 60_000, 2_000),
            autoFlush: $bool('autoFlush', true),
            captureErrors: $bool('captureErrors', false),
            previousHandlerLevels: $previousHandlerLevels,
            maxBreadcrumbs: $clampInt('maxBreadcrumbs', 0, Limits::MAX_BREADCRUMBS, Limits::MAX_BREADCRUMBS),
            sampleRate: $clampRate('sampleRate'),
            errorSampleRate: $clampRate('errorSampleRate'),
            maxErrorsPerMinute: $clampInt('maxErrorsPerMinute', 1, 10_000, 100),
            maxEventsPerMinute: $clampInt('maxEventsPerMinute', 1, 600_000, 6000),
            dedupe: $bool('dedupe', true),
            ignoreErrors: $strings('ignoreErrors'),
            superProperties: self::map($options['superProperties'] ?? null),
            sendDefaultPii: $bool('sendDefaultPii', false),
            redactedKeys: $strings('redactedKeys'),
            propertyDenylist: $strings('propertyDenylist'),
            maxValueBytes: $clampInt('maxValueBytes', 16, Limits::MAX_STRING_BYTES, Limits::MAX_STRING_BYTES),
            normalizeDepth: $clampInt('normalizeDepth', 1, Limits::MAX_DEPTH, Limits::MAX_DEPTH),
            includeRawStack: $bool('includeRawStack', false),
            attachStacktrace: $bool('attachStacktrace', false),
            projectRoot: $text('projectRoot') !== '' ? rtrim($text('projectRoot'), '/\\') : self::projectRoot(),
            contextLines: $clampInt('contextLines', 0, 20, 5),
            beforeSend: $hooks('beforeSend'),
            beforeTrack: $hooks('beforeTrack'),
            beforeBreadcrumb: $hooks('beforeBreadcrumb'),
            onError: \is_callable($onError) ? \Closure::fromCallable($onError) : null,
            transport: $transport ?? new CurlTransport(),
            inert: $inert,
        );

        // Switched off on purpose is a decision, not a problem: only debug output mentions it.
        if ($inert !== null && !$keyless) {
            $logger->debug("inert: {$inert}");
        }

        return $resolved;
    }

    /**
     * The options of a client that does nothing, built from constants alone: what the constructor
     * falls back to when the real options could not be resolved, so it has no way to fail itself.
     */
    public static function inert(string $reason): self
    {
        return new self(
            writeKey: '',
            host: self::DEFAULT_HOST,
            analytics: false,
            errors: false,
            release: '',
            environment: 'production',
            enabledEnvironments: [],
            serverName: '',
            initialScope: ['userId' => null, 'deviceId' => null, 'sessionId' => null, 'tags' => [], 'context' => []],
            flushAt: 20,
            maxQueueSize: 1000,
            requestTimeoutMs: 5_000,
            gzip: false,
            shutdownTimeout: 2_000,
            autoFlush: false,
            captureErrors: false,
            previousHandlerLevels: null,
            maxBreadcrumbs: Limits::MAX_BREADCRUMBS,
            sampleRate: 1.0,
            errorSampleRate: 1.0,
            maxErrorsPerMinute: 100,
            maxEventsPerMinute: 6000,
            dedupe: true,
            ignoreErrors: [],
            superProperties: [],
            sendDefaultPii: false,
            redactedKeys: [],
            propertyDenylist: [],
            maxValueBytes: Limits::MAX_STRING_BYTES,
            normalizeDepth: Limits::MAX_DEPTH,
            includeRawStack: false,
            attachStacktrace: false,
            projectRoot: '',
            contextLines: 0,
            beforeSend: [],
            beforeTrack: [],
            beforeBreadcrumb: [],
            onError: null,
            transport: new CurlTransport(),
            inert: $reason,
        );
    }

    /**
     * An identity here applies to the root scope only. A fresh scope and reset() never bring it back.
     *
     * @return array{userId: string|null, deviceId: string|null, sessionId: string|null, tags: array<string, string>, context: array<string, mixed>}
     */
    private static function initialScope(mixed $value, Logger $logger): array
    {
        $scope = \is_array($value) ? $value : [];
        $id = static function (string $name, callable $validate) use ($scope, $logger): ?string {
            if (!isset($scope[$name])) {
                return null;
            }
            $valid = $validate($scope[$name]);
            if ($valid === null) {
                $logger->warn("initialScope.{$name} is not a usable id and was ignored");
            }

            return \is_string($valid) ? $valid : null;
        };
        $tags = [];
        foreach (self::map($scope['tags'] ?? null) as $key => $tag) {
            if (\is_scalar($tag)) {
                $tags[$key] = (string) $tag;
            }
        }

        return [
            'userId' => $id('userId', Ids::validUserId(...)),
            'deviceId' => $id('deviceId', Ids::pickId(...)),
            'sessionId' => $id('sessionId', Ids::pickId(...)),
            'tags' => $tags,
            'context' => self::map($scope['context'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** The Composer project the SDK is installed in, or the working directory. */
    private static function projectRoot(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class)) {
                $real = self::string(self::call('realpath', \Composer\InstalledVersions::getRootPackage()['install_path']));
                if ($real !== null) {
                    return $real;
                }
            }
        } catch (\Throwable) {
            // Composer's own data is missing or not what it should be; the working directory will do.
        }

        return self::string(self::call('getcwd')) ?? '';
    }

    /**
     * A function the host may have switched off. One named in `disable_functions` does not exist in
     * PHP 8, and calling it is an Error rather than a warning, so it is looked for first. `@`,
     * because several of these warn under `open_basedir`.
     */
    private static function call(string $function, mixed ...$args): mixed
    {
        if (!\function_exists($function)) {
            return null;
        }
        try {
            return @$function(...$args);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
