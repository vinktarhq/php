<?php

declare(strict_types=1);

namespace Vinktar\Tests\Spec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vinktar\Internal\Traits;

/** Every case in `spec/fixtures/traits.json`: traits parse to exactly what the server stores. */
final class TraitsTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        return SpecFile::cases('fixtures/traits.json');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testParsesLikeTheServer(array $case): void
    {
        $in = self::expand($case['in']);
        $out = self::expand($case['out']);
        self::assertIsArray($in);
        self::assertIsArray($out);

        /** @var array{'$set'?: mixed, '$set_once'?: mixed, '$unset'?: mixed} $in */
        $result = Traits::parse($in);

        // Values are compared as the JSON the server receives, so 12, 1.5 and true keep their types.
        self::assertSame($out['set'], self::encode($result['set']));
        self::assertSame($out['setOnce'], self::encode($result['setOnce']));
        self::assertSame($out['unset'], $result['unset']);
        self::assertIsArray($out['drops']);
        self::assertEqualsCanonicalizing(self::drops($out['drops']), self::drops($result['drops']));
    }

    /**
     * `<<N x>>` is N copies of "x"; `<<N x quoted>>` is that string as JSON.
     */
    private static function expand(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(self::expand(...), $value);
        }
        if (\is_string($value) && preg_match('/^<<(\d+) x( quoted)?>>$/', $value, $m) === 1) {
            $text = str_repeat('x', (int) $m[1]);

            return isset($m[2]) ? json_encode($text, \JSON_THROW_ON_ERROR) : $text;
        }

        return $value;
    }

    /**
     * @param array<string, string|int|float|bool> $traits
     *
     * @return array<string, string>
     */
    private static function encode(array $traits): array
    {
        return array_map(static fn (string|int|float|bool $v): string => json_encode($v, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $traits);
    }

    /**
     * @param array<mixed> $drops
     *
     * @return list<string>
     */
    private static function drops(array $drops): array
    {
        $out = [];
        foreach ($drops as $drop) {
            self::assertIsArray($drop);
            self::assertIsString($drop['key']);
            self::assertIsString($drop['code']);
            $out[] = $drop['key'].'|'.$drop['code'];
        }

        return $out;
    }
}
