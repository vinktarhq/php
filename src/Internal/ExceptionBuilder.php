<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * A Throwable, or a PHP error, as the wire's exception chain.
 *
 * Thrown-first: the exception comes before its `getPrevious()`. Frames inside each are crash-last,
 * the opposite order, because the server groups on the last few in-app frames.
 *
 * PHP's trace describes calls, not frames: `getTrace()[0]` is the function the exception was thrown
 * in, with the file and line of the call INTO it. So each frame takes its location from one entry
 * and its function name from the next, and the exception's own file and line are the crash frame.
 *
 * Context lines: a few lines of source around the in-app frames nearest the crash, so an occurrence
 * can be read without opening the repository at that commit. Bounded: at most five frames per
 * report, files over a megabyte or lines past 10 000 skipped, a small cache that remembers misses,
 * and every line scrubbed and capped.
 *
 * @internal
 *
 * @phpstan-type Frame array{file: string, function?: string, line: int, in_app: bool, pre_context?: list<string>, context_line?: string, post_context?: list<string>}
 * @phpstan-type WireException array{type: string, value: string, stack: list<Frame>, stack_raw?: string}
 */
final class ExceptionBuilder
{
    private const MAX_FRAMES_WITH_SOURCE = 5;
    private const MAX_LINE_BYTES = 256;
    private const MAX_LINENO_FOR_SOURCE = 10_000;
    private const MAX_SOURCE_FILE_BYTES = 1024 * 1024;
    private const CACHE_SIZE = 32;

    /** @var array<string, list<string>|null> */
    private array $sources = [];
    private int $sourceBudget = 0;

    public function __construct(
        private readonly string $projectRoot,
        private readonly bool $includeRawStack = false,
        private readonly int $contextLines = 0,
    ) {
    }

    /**
     * @return list<WireException>
     */
    public function fromThrowable(\Throwable $error): array
    {
        $this->sourceBudget = self::MAX_FRAMES_WITH_SOURCE;
        $chain = [];
        $seen = new \SplObjectStorage();
        $current = $error;
        while ($current !== null && \count($chain) < Limits::MAX_EXCEPTIONS && !$seen->offsetExists($current)) {
            $seen->offsetSet($current, null);
            $exception = [
                'type' => Bytes::truncate($current::class, Limits::MAX_TYPE_BYTES),
                // Scrubbed before truncating: a token cut in half is still most of a token.
                'value' => Bytes::truncate(Scrub::secrets($current->getMessage()), Limits::MAX_MESSAGE_BYTES),
                'stack' => $this->stack($current->getTrace(), $current->getFile(), $current->getLine()),
            ];
            if ($this->includeRawStack) {
                $exception['stack_raw'] = Bytes::truncate(Scrub::secrets($current->getTraceAsString()), Limits::MAX_STACK_RAW_BYTES);
            }
            $chain[] = $exception;
            $current = $current->getPrevious();
        }

        return $chain;
    }

    /**
     * An error with no Throwable behind it: a message, a PHP warning, a fatal error.
     *
     * @param list<Frame> $stack
     *
     * @return list<WireException>
     */
    public static function fromMessage(string $message, array $stack = [], string $type = 'Message'): array
    {
        return [['type' => Bytes::truncate($type, Limits::MAX_TYPE_BYTES), 'value' => Bytes::truncate(Scrub::secrets($message), Limits::MAX_MESSAGE_BYTES), 'stack' => $stack]];
    }

    /**
     * Crash-last frames for a trace that is not an exception's: debug_backtrace() at a call site, or
     * an error handler's.
     *
     * @param list<array<string, mixed>> $trace
     *
     * @return list<Frame>
     */
    public function frames(array $trace, string $file, int $line): array
    {
        $this->sourceBudget = self::MAX_FRAMES_WITH_SOURCE;

        return $this->stack($trace, $file, $line);
    }

    public function isInApp(string $file): bool
    {
        if ($file === '' || $file === '[internal]') {
            return false;
        }
        $path = str_replace('\\', '/', $file);
        // The SDK's own frames (withScope, the scope store) are never the application's, wherever the
        // package is: in vendor/, a path repository, or a checkout of this repository.
        if (str_contains($path, '/vendor/') || str_starts_with($path, str_replace('\\', '/', \dirname(__DIR__)).'/')) {
            return false;
        }
        $root = str_replace('\\', '/', $this->projectRoot);

        return $root === '' || str_starts_with($path, $root.'/');
    }

    /**
     * Stable key for dedupe: the type, message and crash location, never the whole stack.
     *
     * @param list<WireException> $exceptions
     */
    public static function key(array $exceptions): string
    {
        $first = $exceptions[0] ?? null;
        if ($first === null) {
            return '';
        }
        $crash = $first['stack'] === [] ? null : $first['stack'][\count($first['stack']) - 1];

        return $first['type'].'|'.$first['value'].'|'.($crash['file'] ?? '').'|'.($crash['line'] ?? '').'|';
    }

    /**
     * The unit error sampling hashes on: one issue, across occurrences.
     *
     * @param list<WireException> $exceptions
     */
    public static function issueKey(array $exceptions): string
    {
        $first = $exceptions[0] ?? null;
        if ($first === null) {
            return '';
        }
        $crash = $first['stack'] === [] ? null : $first['stack'][\count($first['stack']) - 1];

        return $first['type'].'|'.($crash['file'] ?? '').'|'.($crash['line'] ?? '');
    }

    /**
     * @param array<array-key, mixed> $trace
     *
     * @return list<Frame>
     */
    private function stack(array $trace, string $file, int $line): array
    {
        /** @var list<array{file: string, line: int, function: string|null}> $raw crash-first */
        $raw = [];
        foreach ($trace as $call) {
            $function = \is_array($call) ? self::functionOf($call) : null;
            $raw[] = ['file' => $file, 'line' => $line, 'function' => $function];
            $file = \is_array($call) && \is_string($call['file'] ?? null) ? $call['file'] : '[internal]';
            $line = \is_array($call) && \is_int($call['line'] ?? null) ? $call['line'] : 0;
        }
        $raw[] = ['file' => $file, 'line' => $line, 'function' => null];

        // Crash last, keeping the frames nearest the crash.
        $raw = \array_slice(array_reverse($raw), -Limits::MAX_FRAMES);

        $frames = [];
        $withSource = [];
        for ($i = \count($raw) - 1; $i >= 0; --$i) {
            if ($this->sourceBudget > 0 && $this->contextLines > 0 && $this->isInApp($raw[$i]['file']) && $raw[$i]['line'] > 0) {
                $lines = $this->linesOf($raw[$i]['file'], $raw[$i]['line']);
                if ($lines !== null) {
                    $withSource[$i] = $lines;
                    --$this->sourceBudget;
                }
            }
        }
        foreach ($raw as $i => $entry) {
            $frames[] = $this->frame($entry['file'], $entry['line'], $entry['function'], $withSource[$i] ?? null);
        }

        return $frames;
    }

    /**
     * @param list<string>|null $lines the whole file, when context lines are attached
     *
     * @return Frame
     */
    private function frame(string $file, int $line, ?string $function, ?array $lines): array
    {
        $inApp = $this->isInApp($file);
        $root = str_replace('\\', '/', $this->projectRoot);
        $shown = str_replace('\\', '/', $file);
        // In-app paths are made relative to the project, so a release built in one directory and run
        // in another still groups and matches its source.
        $shown = $inApp && $root !== '' && str_starts_with($shown, $root.'/') ? substr($shown, \strlen($root) + 1) : $file;

        $frame = ['file' => Bytes::truncate($shown, Limits::MAX_FRAME_STRING_BYTES)];
        if ($function !== null) {
            $frame['function'] = Bytes::truncate($function, Limits::MAX_FRAME_STRING_BYTES);
        }
        $frame['line'] = max(0, $line);
        $frame['in_app'] = $inApp;

        if ($lines !== null) {
            $index = $line - 1;
            $clean = static fn (string $text): string => Bytes::truncate(Scrub::secrets($text), self::MAX_LINE_BYTES);
            $frame['pre_context'] = array_map($clean, \array_slice($lines, max(0, $index - $this->contextLines), min($index, $this->contextLines)));
            $frame['context_line'] = $clean($lines[$index]);
            $frame['post_context'] = array_map($clean, \array_slice($lines, $index + 1, $this->contextLines));
        }

        return $frame;
    }

    /**
     * The file's lines when it can be read and has line $line, from a small cache that also remembers
     * files it could not read.
     *
     * @return list<string>|null
     */
    private function linesOf(string $file, int $line): ?array
    {
        if ($line > self::MAX_LINENO_FOR_SOURCE) {
            return null;
        }
        if (!\array_key_exists($file, $this->sources)) {
            $lines = null;
            if (is_file($file) && is_readable($file) && (int) filesize($file) <= self::MAX_SOURCE_FILE_BYTES) {
                $read = file($file, \FILE_IGNORE_NEW_LINES);
                $lines = \is_array($read) ? $read : null;
            }
            if (\count($this->sources) >= self::CACHE_SIZE) {
                unset($this->sources[array_key_first($this->sources)]);
            }
            $this->sources[$file] = $lines;
        }
        $lines = $this->sources[$file];

        return $lines !== null && isset($lines[$line - 1]) ? $lines : null;
    }

    /**
     * @param array<array-key, mixed> $call
     */
    private static function functionOf(array $call): ?string
    {
        $function = \is_string($call['function'] ?? null) ? $call['function'] : null;
        if ($function === null) {
            return null;
        }
        if (\is_string($call['class'] ?? null)) {
            $type = \is_string($call['type'] ?? null) ? $call['type'] : '::';

            return $call['class'].$type.$function;
        }

        return $function;
    }
}
