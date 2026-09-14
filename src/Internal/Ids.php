<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Identifiers: which ids are usable, and the ids the SDK mints.
 *
 * The blocked list mirrors `spec/blocked-ids.json` and is pinned to it by a test. Those ids are
 * refused here as well as by the server so the mistake surfaces on the first call, in the
 * developer's own logs, rather than as one enormous "user" in a chart weeks later.
 *
 * @internal
 */
final class Ids
{
    public const BLOCKED = [
        'anonymous', 'guest', 'distinct_id', 'distinctid', 'device_id', 'deviceid', 'user_id', 'userid',
        'id', 'undefined', 'null', 'nan', 'none', 'true', 'false', '0', '[object object]',
    ];

    private static int $lastMs = 0;
    private static int $sequence = 0;

    /** Compared case-insensitively against the trimmed value; the empty string is blocked too. */
    public static function isBlocked(string $id): bool
    {
        $trimmed = trim($id);

        return $trimmed === '' || \in_array(strtolower($trimmed), self::BLOCKED, true);
    }

    /** A user id the server accepts: a non-empty, non-blocked string of at most 255 characters. */
    public static function validUserId(mixed $value): ?string
    {
        if (\is_int($value) || (\is_float($value) && is_finite($value))) {
            $value = (string) $value;
        }
        if (!\is_string($value)) {
            return null;
        }
        $id = trim($value);
        if (self::isBlocked($id) || (\strlen($id) > 255 && preg_match_all('/./su', $id) > 255)) {
            return null;
        }

        return $id;
    }

    /** An id safe to take from a header: 1 to 64 characters of `[A-Za-z0-9._-]`, never a blocked value. */
    public static function pickId(mixed $value): ?string
    {
        if (\is_int($value)) {
            $value = (string) $value;
        }
        if (!\is_string($value)) {
            return null;
        }
        $id = trim($value);
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $id) !== 1 || self::isBlocked($id)) {
            return null;
        }

        return $id;
    }

    /** RFC 9562 UUIDv7: 48-bit unix milliseconds, a 12-bit counter that keeps ids minted in one millisecond in order, random bits. */
    public static function uuidv7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        if ($ms === self::$lastMs) {
            self::$sequence = (self::$sequence + 1) & 0xFFF;
        } else {
            self::$lastMs = $ms;
            self::$sequence = random_int(0, 0xFFF);
        }
        $bytes = random_bytes(16);
        $time = pack('J', $ms);
        $bytes = substr($time, 2, 6).$bytes[6].substr($bytes, 7);
        $bytes[6] = \chr(0x70 | (self::$sequence >> 8));
        $bytes[7] = \chr(self::$sequence & 0xFF);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /** 32 lowercase hex characters, the error-event id shape. */
    public static function hexId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
