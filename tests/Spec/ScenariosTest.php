<?php

declare(strict_types=1);

namespace Vinktar\Tests\Spec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour scenarios in `spec/fixtures/scenarios.json` that apply to a server SDK (`common`
 * and `backend`), to be run against the public API with a recording transport. Browser scenarios
 * (a page's persisted device) do not apply and are not listed.
 *
 * Until the client exists, each one is reported as incomplete, so the list of what is still owed
 * is printed by every test run.
 */
final class ScenariosTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function scenarios(): array
    {
        return array_filter(
            SpecFile::cases('fixtures/scenarios.json', 'scenarios'),
            static fn (array $row): bool => \in_array($row[0]['capability'] ?? null, ['common', 'backend'], true),
        );
    }

    public function testTheFixtureVersionIsTheOneThisRunnerUnderstands(): void
    {
        self::assertSame(1, SpecFile::load('fixtures/scenarios.json')['version'] ?? null);
        self::assertNotEmpty(self::scenarios());
    }

    /**
     * @param array<string, mixed> $scenario
     */
    #[DataProvider('scenarios')]
    public function testScenario(array $scenario): void
    {
        self::assertIsArray($scenario['steps'] ?? null);
        self::markTestIncomplete('runs against the public API once the client exists');
    }
}
