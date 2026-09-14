<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * One record waiting to be sent, sealed: its JSON is fixed when it is queued, so nothing that
 * happens to the caller's array afterwards can change it, and a record that cannot be encoded is
 * refused on the way in instead of breaking the request that would have carried its neighbours.
 *
 * @internal
 */
final class Entry
{
    /** Top-level fields that are JSON objects on the wire, even when empty. */
    private const OBJECT_FIELDS = ['payload', 'context', 'tags', '$set', '$set_once', 'mechanism', 'request'];

    /** Attempts so far, so a chunk that keeps failing is eventually let go. */
    public int $attempts = 0;

    private function __construct(
        public readonly string $category,
        public readonly string $json,
    ) {
    }

    public function bytes(): int
    {
        return \strlen($this->json);
    }

    /**
     * The queue's own copy of a record, or null when it cannot be sent at all (a resource, NAN or
     * INF, a cycle, anything JSON cannot say).
     */
    public static function seal(string $category, mixed $item): ?self
    {
        if (!\is_array($item) || ($item !== [] && array_is_list($item))) {
            return null;
        }
        foreach (self::OBJECT_FIELDS as $field) {
            if (isset($item[$field]) && \is_array($item[$field])) {
                $item[$field] = (object) $item[$field];
            }
        }
        try {
            $json = json_encode($item, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            return null;
        }

        return new self($category, $json);
    }
}
