<?php

declare(strict_types=1);

namespace Vinktar\Tests\Spec;

/**
 * Reads the vendored contract in `spec/`. It is copied from the server's published spec and must
 * never be edited here: the tests exist to fail when the SDK and that copy disagree.
 */
final class SpecFile
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        $path = __DIR__.'/../../spec/'.$name;
        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException("spec file missing: {$name}");
        }
        $data = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \RuntimeException("spec file is not an object: {$name}");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The fixture's cases, keyed by name so PHPUnit reports each one by what it checks.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(string $name, string $key = 'cases'): array
    {
        $out = [];
        $cases = self::load($name)[$key] ?? [];
        if (!\is_array($cases)) {
            return $out;
        }
        foreach ($cases as $i => $case) {
            if (!\is_array($case)) {
                continue;
            }
            /** @var array<string, mixed> $case */
            $label = \is_string($case['name'] ?? null) ? $case['name'] : (string) $i;
            $out[$label] = [$case];
        }

        return $out;
    }
}
