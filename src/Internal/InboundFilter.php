<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * What the server suppresses, mirrored from `spec/inbound-filter.json` and asserted set-equal by a
 * test.
 *
 * That cuts both ways: known noise is dropped before it costs a request, and the SDK must never
 * produce one of these strings for an error someone wanted kept.
 *
 * @internal
 */
final class InboundFilter
{
    public const DENY_MESSAGES = [
        'ResizeObserver loop limit exceeded',
        'ResizeObserver loop completed with undelivered notifications',
        'Script error.',
        'Non-Error promise rejection captured',
        'Non-Error exception captured',
    ];

    /** A stack made entirely of these is a browser extension, not an application. */
    public const NON_APP_FRAME_SCHEMES = [
        'chrome-extension://',
        'moz-extension://',
        'safari-extension://',
        'safari-web-extension://',
        'chrome://',
        'about:',
    ];

    /**
     * @param list<string> $files the file of every frame
     */
    public static function isSuppressed(string $message, array $files): bool
    {
        foreach (self::DENY_MESSAGES as $deny) {
            if (str_contains($message, $deny)) {
                return true;
            }
        }
        if ($files === []) {
            return false;
        }
        foreach ($files as $file) {
            if (!self::isNonAppFile($file)) {
                return false;
            }
        }

        return true;
    }

    public static function isNonAppFile(string $file): bool
    {
        foreach (self::NON_APP_FRAME_SCHEMES as $scheme) {
            if (str_starts_with($file, $scheme)) {
                return true;
            }
        }

        return false;
    }
}
