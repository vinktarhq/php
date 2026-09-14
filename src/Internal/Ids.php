<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Ids that are never a real person, mirrored from `spec/blocked-ids.json` and pinned to it by a
 * test.
 *
 * Refused here as well as by the server so the mistake surfaces on the first call, in the
 * developer's own logs, rather than as one enormous "user" in a chart weeks later. Compared
 * case-insensitively against the trimmed value; the empty string is blocked too.
 *
 * @internal
 */
final class Ids
{
    public const BLOCKED = [
        'anonymous', 'guest', 'distinct_id', 'distinctid', 'device_id', 'deviceid', 'user_id', 'userid',
        'id', 'undefined', 'null', 'nan', 'none', 'true', 'false', '0', '[object object]',
    ];

    public static function isBlocked(string $id): bool
    {
        $trimmed = trim($id);

        return $trimmed === '' || \in_array(strtolower($trimmed), self::BLOCKED, true);
    }
}
