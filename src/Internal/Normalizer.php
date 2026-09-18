<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * The one normaliser, for event payloads, event context, error context, breadcrumb data and tags
 * alike, so a limit configured once applies everywhere.
 *
 * Everything here is about not losing the event. The server refuses an oversize string rather
 * than truncating it, and refuses the whole event for one bad value, so trimming locally is the
 * difference between a slightly shorter field and no data at all. What comes out is always
 * JSON-encodable: no resources, no NAN or INF, no cycles.
 *
 * It is also about surviving the value. A value's own code runs here (`jsonSerialize()`,
 * `__toString()`), and it can throw, or return a fresh copy of itself forever. So the walk is
 * bounded three ways, none of which depends on what the value says about itself: the depth the
 * server accepts, a fixed number of `jsonSerialize()` results followed for one value, and a budget
 * of nodes for one call. Past any of them the value becomes a placeholder. Without that, the way
 * out of a self-replicating serialiser is the memory limit, which no `catch` sees.
 *
 * @internal
 */
final class Normalizer
{
    public const REDACTED = '[redacted]';

    public const UNREADABLE = '[Unreadable]';

    /** `jsonSerialize()` results followed for one value before giving up on it. */
    private const MAX_UNWRAPS = 8;
    /** Nested values visited in one normalize() call. An event the server accepts has far fewer. */
    private const MAX_NODES = 10_000;

    private const SENSITIVE = '/pass|token|secret|auth|api[-_]?key|cookie|credential|card|cvv|ssn/i';

    /**
     * @param list<string> $redactedKeys     extra key fragments to redact, on top of the built-in set
     * @param list<string> $propertyDenylist top-level keys dropped outright, by exact name
     */
    public function __construct(
        private readonly int $maxStringBytes = Limits::MAX_STRING_BYTES,
        private readonly int $maxDepth = Limits::MAX_DEPTH,
        private readonly int $maxProperties = Limits::MAX_PROPERTIES_PER_EVENT,
        private readonly array $redactedKeys = [],
        private readonly array $propertyDenylist = [],
    ) {
    }

    /**
     * @param array<array-key, mixed>                                                  $props
     * @param (callable(string, 'too_many_properties'|'truncated'|'depth'): void)|null $onDrop called for everything trimmed or discarded
     *
     * @return array<string, mixed>
     */
    public function normalize(array $props, ?callable $onDrop = null): array
    {
        $out = [];
        $count = 0;
        $budget = self::MAX_NODES;
        foreach ($props as $key => $value) {
            $key = (string) $key;
            if ($count >= $this->maxProperties) {
                if ($onDrop !== null) {
                    $onDrop($key, 'too_many_properties');
                }
                continue;
            }
            // The caller's own instruction, so it is not reported the way a processing limit is.
            if (\in_array($key, $this->propertyDenylist, true)) {
                continue;
            }
            $out[$key] = $this->visit($key, $value, 1, new \SplObjectStorage(), $onDrop, $budget);
            ++$count;
        }

        return $out;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $path   the objects on the way down to this value, for cycles
     * @param int                              $budget nodes left for this normalize() call
     */
    private function visit(string $key, mixed $value, int $depth, \SplObjectStorage $path, ?callable $onDrop, int &$budget): mixed
    {
        if (self::isSensitiveKey($key, $this->redactedKeys)) {
            return self::REDACTED;
        }
        if (\is_string($value)) {
            if (\strlen($value) > $this->maxStringBytes && $onDrop !== null) {
                $onDrop($key, 'truncated');
            }

            return Bytes::truncate($value, $this->maxStringBytes);
        }
        if (\is_float($value)) {
            // NAN and INF are not JSON and would make the whole request body unencodable.
            return is_finite($value) ? $value : null;
        }
        if ($value === null || \is_int($value) || \is_bool($value)) {
            return $value;
        }
        if (\is_array($value)) {
            // The server counts payload and context themselves as depth 1, so a value that would land
            // at depth 4 is refused. Collapse it rather than lose the event, and say so. This is also
            // what ends an array that holds itself by reference.
            if ($depth >= $this->maxDepth) {
                if ($onDrop !== null) {
                    $onDrop($key, 'depth');
                }

                return (array_is_list($value) ? '[Array(' : '[Object(').\count($value).')]';
            }

            return $this->children($key, $value, $depth, $path, $onDrop, $budget);
        }
        if (!\is_object($value)) {
            return '[Resource]';
        }

        try {
            return $this->object($key, $value, $depth, $path, $onDrop, $budget);
        } catch (\Throwable) {
            // The value's own code threw. One unreadable property, not a lost event.
            return self::UNREADABLE;
        }
    }

    /**
     * @param \SplObjectStorage<object, mixed> $path
     */
    private function object(string $key, object $value, int $depth, \SplObjectStorage $path, ?callable $onDrop, int &$budget): mixed
    {
        if ($value::class === 'CurlHandle') {
            return '[Resource]';
        }
        if ($value instanceof \Closure) {
            return '[Function]';
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \Throwable) {
            return ['name' => $value::class, 'message' => Bytes::truncate($value->getMessage(), $this->maxStringBytes)];
        }
        if ($path->offsetExists($value)) {
            return '[Circular]';
        }

        if ($value instanceof \JsonSerializable) {
            // Followed in a loop, not by recursing: what it returns is the same value in another
            // form and sits at the same depth, so depth alone would never end a serialiser that
            // returns a new serialiser.
            $form = $value;
            $seen = [];
            try {
                for ($unwraps = 0; $form instanceof \JsonSerializable; ++$unwraps) {
                    if ($unwraps >= self::MAX_UNWRAPS || --$budget < 0 || $path->offsetExists($form)) {
                        return self::UNREADABLE;
                    }
                    $path->offsetSet($form, null);
                    $seen[] = $form;
                    $form = $form->jsonSerialize();
                }

                return $this->visit($key, $form, $depth, $path, $onDrop, $budget);
            } finally {
                foreach ($seen as $object) {
                    $path->offsetUnset($object);
                }
            }
        }
        if ($value instanceof \Stringable) {
            return $this->visit($key, (string) $value, $depth, $path, $onDrop, $budget);
        }

        $vars = get_object_vars($value);
        if ($depth >= $this->maxDepth) {
            if ($onDrop !== null) {
                $onDrop($key, 'depth');
            }

            return '[Object('.\count($vars).')]';
        }
        $path->offsetSet($value, null);
        try {
            return $this->children($key, $vars, $depth, $path, $onDrop, $budget);
        } finally {
            $path->offsetUnset($value);
        }
    }

    /**
     * @param array<array-key, mixed>          $values
     * @param \SplObjectStorage<object, mixed> $path
     *
     * @return array<array-key, mixed>
     */
    private function children(string $key, array $values, int $depth, \SplObjectStorage $path, ?callable $onDrop, int &$budget): array
    {
        $list = array_is_list($values);
        $out = [];
        foreach ($values as $childKey => $child) {
            if (--$budget < 0) {
                // Out of nodes: the rest of this container is left out, and said so.
                if ($onDrop !== null) {
                    $onDrop($key, 'truncated');
                }
                break;
            }
            $label = $list ? $key.'['.$childKey.']' : (string) $childKey;
            $out[$childKey] = $this->visit($label, $child, $depth + 1, $path, $onDrop, $budget);
        }

        return $out;
    }

    /**
     * @param list<string> $extra
     */
    public static function isSensitiveKey(string $key, array $extra = []): bool
    {
        if (preg_match(self::SENSITIVE, $key) === 1) {
            return true;
        }
        $lower = strtolower($key);
        foreach ($extra as $fragment) {
            if ($fragment !== '' && str_contains($lower, strtolower($fragment))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The server counts an event's payload keys and context keys together against one cap and refuses
     * the whole event over it. Context is the SDK's own and comes first; payload keys past the room
     * that is left are dropped here, each one reported.
     *
     * @param array<string, mixed>          $payload
     * @param array<string, mixed>          $context
     * @param (callable(string): void)|null $onDrop
     *
     * @return array<string, mixed>
     */
    public static function capCombined(array $payload, array $context, int $max = Limits::MAX_PROPERTIES_PER_EVENT, ?callable $onDrop = null): array
    {
        $room = max(0, $max - \count($context));
        if (\count($payload) <= $room) {
            return $payload;
        }
        $out = [];
        $index = 0;
        foreach ($payload as $key => $value) {
            if ($index < $room) {
                $out[$key] = $value;
            } elseif ($onDrop !== null) {
                $onDrop($key);
            }
            ++$index;
        }

        return $out;
    }

    /**
     * Tags are their own shape: flat, string-valued, and capped tighter than properties.
     *
     * @param array<array-key, mixed>       $tags
     * @param (callable(string): void)|null $onDrop
     *
     * @return array<string, string>
     */
    public static function tags(array $tags, ?callable $onDrop = null): array
    {
        $out = [];
        $count = 0;
        foreach ($tags as $key => $value) {
            $key = (string) $key;
            if ($count >= Limits::MAX_TAGS) {
                if ($onDrop !== null) {
                    $onDrop($key);
                }
                continue;
            }
            $text = Input::tagValue($value);
            if ($text === null) {
                continue;
            }
            $out[Bytes::truncate($key, Limits::MAX_TAG_KEY_BYTES)] = self::isSensitiveKey($key) ? self::REDACTED : Bytes::truncate($text, Limits::MAX_TAG_VALUE_BYTES);
            ++$count;
        }

        return $out;
    }
}
