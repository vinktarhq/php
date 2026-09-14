<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * One shutdown function for the whole process, flushing every live client that asked for it.
 *
 * This is the one static in the package, and it holds no data about anyone: a weak map from
 * client to the closure that flushes it. A client that is garbage collected drops out of it, so a
 * long-running worker that creates clients does not accumulate them.
 *
 * @internal
 */
final class Shutdown
{
    /** @var \WeakMap<object, \Closure(object): void>|null */
    private static ?\WeakMap $clients = null;

    /**
     * @param \Closure(object): void $flush called with $client when the process shuts down
     */
    public static function register(object $client, \Closure $flush): void
    {
        if (self::$clients === null) {
            self::$clients = new \WeakMap();
            register_shutdown_function(static function (): void {
                foreach (self::$clients ?? [] as $registered => $callback) {
                    try {
                        $callback($registered);
                    } catch (\Throwable) {
                        // The next client still gets its flush.
                    }
                }
            });
        }
        self::$clients[$client] = $flush;
    }
}
