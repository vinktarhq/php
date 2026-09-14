<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * A Throwable, as the wire's exception chain.
 *
 * Thrown-first: the exception comes before its `getPrevious()`. Frames inside each are crash-last,
 * the opposite order, because the server groups on the last few in-app frames.
 *
 * PHP's trace describes calls, not frames: `getTrace()[0]` is the function the exception was thrown
 * in, with the file and line of the call INTO it. So each frame takes its location from one entry
 * and its function name from the next, and the exception's own file and line are the crash frame.
 *
 * @internal
 *
 * @phpstan-type Frame array{file: string, function?: string, line: int, in_app: bool}
 * @phpstan-type WireException array{type: string, value: string, stack: list<Frame>, stack_raw?: string}
 */
final class ExceptionBuilder
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly bool $includeRawStack = false,
    ) {
    }

    /**
     * @return list<WireException>
     */
    public function fromThrowable(\Throwable $error): array
    {
        $chain = [];
        $seen = new \SplObjectStorage();
        $current = $error;
        while ($current !== null && \count($chain) < Limits::MAX_EXCEPTIONS && !$seen->offsetExists($current)) {
            $seen->offsetSet($current, null);
            $exception = [
                'type' => Bytes::truncate($current::class, Limits::MAX_TYPE_BYTES),
                // Scrubbed before truncating: a token cut in half is still most of a token.
                'value' => Bytes::truncate(Scrub::secrets($current->getMessage()), Limits::MAX_MESSAGE_BYTES),
                'stack' => $this->frames($current),
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
     * A message with no Throwable behind it, for captureMessage().
     *
     * @param list<Frame> $stack
     *
     * @return list<WireException>
     */
    public static function fromMessage(string $message, array $stack = []): array
    {
        return [['type' => 'Message', 'value' => Bytes::truncate(Scrub::secrets($message), Limits::MAX_MESSAGE_BYTES), 'stack' => $stack]];
    }

    /**
     * Crash-last frames for a trace: an exception's, or debug_backtrace() for an attached stack.
     *
     * @param list<array<string, mixed>>|null $trace
     *
     * @return list<Frame>
     */
    public function frames(?\Throwable $error, ?array $trace = null, ?string $file = null, ?int $line = null): array
    {
        $trace ??= $error?->getTrace() ?? [];
        $file ??= $error?->getFile() ?? '';
        $line ??= $error?->getLine() ?? 0;

        $frames = [];
        foreach ($trace as $call) {
            $frames[] = $this->frame($file, $line, self::functionOf($call));
            $file = \is_string($call['file'] ?? null) ? $call['file'] : '[internal]';
            $line = \is_int($call['line'] ?? null) ? $call['line'] : 0;
        }
        $frames[] = $this->frame($file, $line, null);

        // getTrace() is crash-first; the wire wants the crash last, and keeps the frames nearest it.
        return \array_slice(array_reverse($frames), -Limits::MAX_FRAMES);
    }

    public function isInApp(string $file): bool
    {
        if ($file === '' || $file === '[internal]') {
            return false;
        }
        $path = str_replace('\\', '/', $file);
        if (str_contains($path, '/vendor/')) {
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
     * @return Frame
     */
    private function frame(string $file, int $line, ?string $function): array
    {
        $inApp = $this->isInApp($file);
        $root = str_replace('\\', '/', $this->projectRoot);
        $shown = str_replace('\\', '/', $file);
        // In-app paths are made relative to the project, so a release built in one directory and run
        // in another still groups and matches its source.
        if ($inApp && $root !== '' && str_starts_with($shown, $root.'/')) {
            $shown = substr($shown, \strlen($root) + 1);
        } else {
            $shown = $file;
        }
        $frame = ['file' => Bytes::truncate($shown, Limits::MAX_FRAME_STRING_BYTES), 'line' => max(0, $line), 'in_app' => $inApp];
        if ($function !== null) {
            $frame = ['file' => $frame['file'], 'function' => Bytes::truncate($function, Limits::MAX_FRAME_STRING_BYTES), 'line' => $frame['line'], 'in_app' => $inApp];
        }

        return $frame;
    }

    /**
     * @param array<string, mixed> $call
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
