<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * What a 202 body says the server did not keep.
 *
 * A 202 means durably queued, not "everything you sent is stored": per-record rejections, dropped
 * traits, ignored identifies and suppressed errors all ride in the body of a success. The SDK is the
 * only party positioned to say so when it happens, in the developer's own logs.
 *
 * @internal
 */
final class Diagnostics
{
    private const IDENTIFY_HINTS = [
        'missing_user_id' => 'the entry had no user_id',
        'blocked_id' => 'that id is on the blocked list and is never a real person',
        'blocked_device_id' => 'that device id is on the blocked list',
        'no_op' => 'nothing to do: the link was already known and no traits were sent',
    ];

    /** How many per-record rejections to spell out before summarising. */
    private const MAX_LISTED = 5;

    /**
     * @return list<string>
     */
    public static function lines(mixed $body): array
    {
        if (!\is_array($body)) {
            return [];
        }
        $lines = [];

        foreach (self::list($body['identify_ignored'] ?? null) as $entry) {
            $code = self::text($entry['code'] ?? 'unknown');
            $lines[] = \sprintf('server ignored identify("%s"): %s (%s)', self::text($entry['user_id'] ?? ''), self::IDENTIFY_HINTS[$code] ?? $code, $code);
        }
        foreach (self::list($body['traits_dropped'] ?? null) as $entry) {
            $lines[] = \sprintf('server dropped trait "%s" for "%s" (%s)', self::text($entry['key'] ?? ''), self::text($entry['user_id'] ?? ''), self::text($entry['code'] ?? 'unknown'));
        }

        $rejected = (int) (is_numeric($body['rejected'] ?? null) ? $body['rejected'] : 0);
        if ($rejected > 0) {
            $errors = self::list($body['errors'] ?? null);
            $shown = array_map(static fn (array $e): string => '#'.self::text($e['index'] ?? '?').' '.self::text($e['code'] ?? 'unknown'), \array_slice($errors, 0, self::MAX_LISTED));
            $more = \count($errors) > self::MAX_LISTED ? ', and '.(\count($errors) - self::MAX_LISTED).' more' : '';
            $lines[] = \sprintf('server rejected %d item%s: %s%s', $rejected, $rejected === 1 ? '' : 's', implode(', ', $shown), $more);
        }

        $suppressed = (int) (is_numeric($body['suppressed'] ?? null) ? $body['suppressed'] : 0);
        if ($suppressed > 0) {
            $lines[] = \sprintf('server suppressed %d error%s as known noise (not stored, not billed)', $suppressed, $suppressed === 1 ? '' : 's');
        }

        return $lines;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, \is_array(...))) : [];
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
