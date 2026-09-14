<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * The process-wide handlers, installed only when asked (`captureErrors` or `registerHandlers()`),
 * because installing one changes what the process does.
 *
 * One set for the whole process, shared by every client that asked, so two clients do not fight
 * over `set_exception_handler()`. Like {@see Shutdown}, it keeps clients in a weak map and nothing
 * about anyone.
 *
 * What each handler does, and what it leaves exactly as it was:
 *
 * - **Uncaught exceptions** are reported and flushed. Then the handler that was installed before
 *   the SDK runs, or, when there was none, the exception is thrown again, so PHP prints it and ends
 *   with the same exit code it would have without the SDK.
 * - **Warnings and errors raised with `trigger_error()`** are reported when `error_reporting()`
 *   includes them, which also means an `@`-silenced call is left alone. Notices and deprecations
 *   become breadcrumbs. The previous error handler still runs, and PHP's own handling (logging,
 *   display) continues unless that handler stopped it.
 * - **Fatal errors** (out of memory, a timeout, a compile error) are found at shutdown with
 *   `error_get_last()`. A small memory reserve is released first, and an out-of-memory error gets a
 *   little more room, so the report can still be built.
 *
 * @internal
 */
final class Handlers
{
    public const FATAL = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR;
    public const REPORTED = \E_WARNING | \E_CORE_WARNING | \E_COMPILE_WARNING | \E_USER_WARNING | \E_USER_ERROR | \E_RECOVERABLE_ERROR;

    private const RESERVE_BYTES = 32 * 1024;
    private const OOM_HEADROOM_BYTES = 5 * 1024 * 1024;

    /** @var \WeakMap<object, \Closure(object, string, array<string, mixed>): void>|null */
    private static ?\WeakMap $clients = null;
    /** @var callable|null */
    private static $previousException;
    /** @var callable|null */
    private static $previousError;
    private static ?string $reserve = null;
    private static bool $dispatching = false;
    private static bool $rethrown = false;

    /**
     * @param \Closure(object, string, array<string, mixed>): void $handle called with the client, the kind
     *                                                                     (`exception`, `error`, `fatal`) and its details
     */
    public static function register(object $client, \Closure $handle): void
    {
        $clients = self::$clients;
        if ($clients === null) {
            $clients = self::$clients = new \WeakMap();
            self::install();
        }
        $clients[$client] = $handle;
    }

    private static function install(): void
    {
        self::$reserve = str_repeat("\0", self::RESERVE_BYTES);
        self::$previousException = set_exception_handler(static function (\Throwable $error): void {
            self::onException($error);
        });
        self::$previousError = set_error_handler(static fn (int $type, string $message, string $file = '', int $line = 0): bool => self::onError($type, $message, $file, $line));
        register_shutdown_function(static function (): void {
            self::onShutdown();
        });
    }

    private static function onException(\Throwable $error): void
    {
        self::dispatch('exception', ['error' => $error]);

        if (self::$previousException !== null) {
            (self::$previousException)($error);

            return;
        }
        // PHP's own behaviour, reproduced: an exception nobody handles is printed and ends the script.
        // Thrown from inside the exception handler, it is exactly that. The fatal error it leaves
        // behind is this same exception, and is not reported a second time at shutdown.
        self::$rethrown = true;
        throw $error;
    }

    private static function onError(int $type, string $message, string $file, int $line): bool
    {
        // error_reporting() is what the application asked for, and what `@` lowers for one call.
        if ((error_reporting() & $type) !== 0) {
            $trace = \array_slice(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS), 2);
            self::dispatch('error', ['type' => $type, 'message' => $message, 'file' => $file, 'line' => $line, 'trace' => $trace]);
        }

        if (self::$previousError !== null) {
            return (self::$previousError)($type, $message, $file, $line) !== false;
        }

        // False: PHP's standard handling (the log, the display) carries on as it would have.
        return false;
    }

    private static function onShutdown(): void
    {
        // The reserve exists to be given back here, before anything else is allocated.
        if (self::$reserve !== null) {
            self::$reserve = null;
        }
        $error = error_get_last();
        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }
        if (self::$rethrown && str_starts_with($error['message'], 'Uncaught ')) {
            return;
        }
        if (str_starts_with($error['message'], 'Allowed memory size of')) {
            $limit = self::bytes((string) \ini_get('memory_limit'));
            if ($limit > 0) {
                ini_set('memory_limit', (string) ($limit + self::OOM_HEADROOM_BYTES));
            }
        }
        self::dispatch('fatal', $error);
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function dispatch(string $kind, array $details): void
    {
        // An error raised while reporting an error is left to PHP.
        if (self::$dispatching || self::$clients === null) {
            return;
        }
        self::$dispatching = true;
        try {
            foreach (self::$clients as $client => $handle) {
                try {
                    $handle($client, $kind, $details);
                } catch (\Throwable) {
                    // The next client still reports.
                }
            }
        } finally {
            self::$dispatching = false;
        }
    }

    /** The name the server groups a PHP error under: `E_WARNING`, `E_ERROR`, … */
    public static function typeName(int $type): string
    {
        return match ($type) {
            \E_ERROR => 'E_ERROR',
            \E_WARNING => 'E_WARNING',
            \E_PARSE => 'E_PARSE',
            \E_NOTICE => 'E_NOTICE',
            \E_CORE_ERROR => 'E_CORE_ERROR',
            \E_CORE_WARNING => 'E_CORE_WARNING',
            \E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            \E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            \E_USER_ERROR => 'E_USER_ERROR',
            \E_USER_WARNING => 'E_USER_WARNING',
            \E_USER_NOTICE => 'E_USER_NOTICE',
            \E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            \E_DEPRECATED => 'E_DEPRECATED',
            \E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }

    private static function bytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return -1;
        }
        $unit = strtolower($limit[\strlen($limit) - 1]);
        $number = (int) $limit;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
