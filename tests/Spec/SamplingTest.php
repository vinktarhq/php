<?php

declare(strict_types=1);

namespace Vinktar\Tests\Spec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vinktar\Internal\Sampling;

/** The same unit must hash identically in every Vinktar SDK, or one funnel gets two populations. */
final class SamplingTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        return SpecFile::cases('fixtures/sampling.json');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testHashesLikeEveryOtherSdk(array $case): void
    {
        self::assertIsString($case['unit']);
        self::assertIsFloat($case['hash']);
        self::assertEqualsWithDelta($case['hash'], Sampling::hash($case['unit']), 1e-12);
    }

    public function testRatesAtTheEdges(): void
    {
        self::assertTrue(Sampling::sampled('anyone', 1.0));
        self::assertFalse(Sampling::sampled('anyone', 0.0));
        self::assertTrue(Sampling::sampled('', 0.01));
        // user_123 hashes to 0.0768…, so it is inside a 10% sample and outside a 5% one.
        self::assertTrue(Sampling::sampled('user_123', 0.10));
        self::assertFalse(Sampling::sampled('user_123', 0.05));
    }
}
