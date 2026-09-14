<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Byte-accurate string handling.
 *
 * The server counts bytes and refuses an oversize value outright rather than truncating it, so
 * every cap is applied here in bytes, and a cut never lands inside a UTF-8 sequence: half a
 * character is invalid UTF-8, and invalid UTF-8 cannot be JSON-encoded at all.
 *
 * @internal
 */
final class Bytes
{
    public static function truncate(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (\strlen($value) <= $maxBytes) {
            return $value;
        }
        $cut = $maxBytes;
        // The byte at $cut is the first one left out. While it continues a sequence, the character
        // it belongs to started earlier and would be split, so the cut moves back to its first byte.
        while ($cut > 0 && (\ord($value[$cut]) & 0xC0) === 0x80) {
            --$cut;
        }

        return substr($value, 0, $cut);
    }
}
