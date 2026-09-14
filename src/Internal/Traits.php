<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Traits, parsed to exactly what the server stores. `spec/fixtures/traits.json` is the contract
 * and a test runs every case in it.
 *
 * Three operations: `$set` (last write wins), `$set_once` (first write wins) and `$unset`. What the
 * input can get wrong is reported, never resolved silently:
 *
 *   - Reserved spellings. `email` is `$email` on the server; the bare spelling is rewritten. An
 *     unknown `$` key is dropped as `reserved_key`, so that namespace stays the server's.
 *   - Scalars only. `null` is not storable (it reads back the same as an absent key), so it is
 *     `invalid_type`, and removing a key is what `$unset` is for.
 *   - Oversize values are dropped, not truncated. A truncated email is a wrong email.
 *   - Contradictions. The same key in `$set` and `$set_once`, or in a write and `$unset`, keeps the
 *     `$set` and reports the loser as `conflicting_op`.
 *
 * @internal
 *
 * @phpstan-type TraitValue string|int|float|bool
 * @phpstan-type Drop array{key: string, code: string}
 * @phpstan-type Parsed array{set: array<string, TraitValue>, setOnce: array<string, TraitValue>, unset: list<string>, drops: list<Drop>}
 */
final class Traits
{
    public const RESERVED = ['$email', '$name', '$username', '$avatar', '$created'];

    public const ALIASES = [
        'email' => '$email',
        'name' => '$name',
        'username' => '$username',
        'avatar' => '$avatar',
        'created' => '$created',
        'createdat' => '$created',
    ];

    /** `$email` and `email` both become `$email`; an unknown `$key` is null. */
    public static function canonicalKey(string $key): ?string
    {
        if (str_starts_with($key, '$')) {
            return \in_array($key, self::RESERVED, true) ? $key : null;
        }

        return self::ALIASES[strtolower(str_replace('_', '', $key))] ?? $key;
    }

    /**
     * @param array{'$set'?: mixed, '$set_once'?: mixed, '$unset'?: mixed} $ops
     *
     * @return Parsed
     */
    public static function parse(array $ops): array
    {
        $drops = [];
        $budget = Limits::MAX_TRAITS_PER_REQUEST;

        $set = self::parseMap($ops['$set'] ?? null, $budget, $drops);
        $setOnce = self::parseMap($ops['$set_once'] ?? null, $budget, $drops);
        $unset = self::parseUnset($ops['$unset'] ?? null, $budget, $drops);

        // `$set` wins every collision. The losing operation is reported, never applied.
        foreach (array_keys($setOnce) as $key) {
            if (\array_key_exists($key, $set)) {
                unset($setOnce[$key]);
                $drops[] = ['key' => (string) $key, 'code' => 'conflicting_op'];
            }
        }
        $surviving = [];
        foreach ($unset as $key) {
            if (\array_key_exists($key, $set) || \array_key_exists($key, $setOnce)) {
                $drops[] = ['key' => $key, 'code' => 'conflicting_op'];
                continue;
            }
            $surviving[] = $key;
        }

        return ['set' => $set, 'setOnce' => $setOnce, 'unset' => $surviving, 'drops' => $drops];
    }

    /**
     * @param list<Drop> $drops
     *
     * @return array<string, TraitValue>
     */
    private static function parseMap(mixed $input, int &$budget, array &$drops): array
    {
        $out = [];
        // A list is not a map of keys to values, and neither is anything that is not an array.
        if (!\is_array($input) || ($input !== [] && array_is_list($input))) {
            return $out;
        }

        foreach ($input as $rawKey => $value) {
            $rawKey = (string) $rawKey;
            $key = self::canonicalKey($rawKey);
            if ($key === null) {
                $drops[] = ['key' => $rawKey, 'code' => 'reserved_key'];
                continue;
            }
            if (\strlen($key) > Limits::MAX_TRAIT_KEY_BYTES) {
                $drops[] = ['key' => $rawKey, 'code' => 'key_too_large'];
                continue;
            }
            if (\is_string($value)) {
                if (\strlen($value) > Limits::MAX_TRAIT_VALUE_BYTES) {
                    $drops[] = ['key' => $key, 'code' => 'value_too_large'];
                    continue;
                }
            } elseif (\is_float($value)) {
                if (!is_finite($value)) {
                    $drops[] = ['key' => $key, 'code' => 'invalid_type'];
                    continue;
                }
            } elseif (!\is_int($value) && !\is_bool($value)) {
                $drops[] = ['key' => $key, 'code' => 'invalid_type'];
                continue;
            }
            if ($budget <= 0) {
                $drops[] = ['key' => $key, 'code' => 'too_many_keys'];
                continue;
            }

            $out[$key] = $value;
            --$budget;
        }

        return $out;
    }

    /**
     * @param list<Drop> $drops
     *
     * @return list<string>
     */
    private static function parseUnset(mixed $input, int &$budget, array &$drops): array
    {
        $out = [];
        if (!\is_array($input) || !array_is_list($input)) {
            return $out;
        }

        foreach ($input as $raw) {
            if (!\is_string($raw) || $raw === '') {
                $drops[] = ['key' => '', 'code' => 'invalid_type'];
                continue;
            }
            $key = self::canonicalKey($raw);
            if ($key === null) {
                $drops[] = ['key' => $raw, 'code' => 'reserved_key'];
                continue;
            }
            if (\strlen($key) > Limits::MAX_TRAIT_KEY_BYTES) {
                $drops[] = ['key' => $raw, 'code' => 'key_too_large'];
                continue;
            }
            if ($budget <= 0) {
                $drops[] = ['key' => $key, 'code' => 'too_many_keys'];
                continue;
            }
            if (\in_array($key, $out, true)) {
                continue;
            }

            $out[] = $key;
            --$budget;
        }

        return $out;
    }
}
