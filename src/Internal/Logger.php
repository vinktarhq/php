<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * The "nothing fails silently" channel.
 *
 * Every drop, refusal and no-op goes through here at warning level. Two things keep that from
 * becoming a liability in a loop: the same line is written once, and distinct lines are capped per
 * minute.
 *
 * The sink is a callable `(string $level, string $message, array $context): void`, or any object
 * with PSR-3's `log($level, $message, $context)`, so a PSR-3 logger plugs in without this package
 * requiring `psr/log`. The default writes warnings and errors with `error_log()`.
 *
 * @internal
 */
final class Logger
{
    private const MAX_REMEMBERED = 200;
    private const MAX_PER_MINUTE = 30;

    /** @var callable(string, string, array<string, mixed>): void */
    private $sink;

    /** @var array<string, true> */
    private array $seen = [];
    private float $windowStart = 0.0;
    private int $inWindow = 0;

    public function __construct(mixed $sink = null, private readonly bool $debugEnabled = false)
    {
        $this->sink = self::sinkFor($sink);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->emit('debug', $message, $context);
        }
    }

    /**
     * Something was dropped or refused. Once per distinct message, rate limited.
     *
     * @param array<string, mixed> $context
     */
    public function warn(string $message, array $context = []): void
    {
        if ($this->admit($message)) {
            $this->emit('warning', $message, $context);
        }
    }

    /**
     * The SDK cannot do its job at all: a refused key, a missing extension. Never suppressed.
     *
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->emit('error', $message, $context);
    }

    private function admit(string $message): bool
    {
        if (isset($this->seen[$message])) {
            return false;
        }
        $now = microtime(true);
        if ($now - $this->windowStart > 60) {
            $this->windowStart = $now;
            $this->inWindow = 0;
        }
        if ($this->inWindow >= self::MAX_PER_MINUTE) {
            return false;
        }
        ++$this->inWindow;
        if (\count($this->seen) >= self::MAX_REMEMBERED) {
            unset($this->seen[array_key_first($this->seen)]);
        }
        $this->seen[$message] = true;

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emit(string $level, string $message, array $context): void
    {
        try {
            ($this->sink)($level, '[vinktar] '.$message, $context);
        } catch (\Throwable) {
            // A sink that throws must not take the application down with it.
        }
    }

    /**
     * @return callable(string, string, array<string, mixed>): void
     */
    private static function sinkFor(mixed $sink): callable
    {
        if (\is_callable($sink)) {
            return static function (string $level, string $message, array $context) use ($sink): void {
                $sink($level, $message, $context);
            };
        }
        if (\is_object($sink) && method_exists($sink, 'log')) {
            return static function (string $level, string $message, array $context) use ($sink): void {
                $sink->log($level, $message, $context);
            };
        }

        return static function (string $level, string $message, array $context): void {
            if ($level === 'warning' || $level === 'error') {
                error_log($context === [] ? $message : $message.' '.json_encode($context, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
        };
    }
}
