<?php

declare(strict_types=1);

namespace Vinktar\Tests\Spec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vinktar\Tests\Support\HostileRunner;

/**
 * `spec/fixtures/hostile.json`: whatever the application hands the SDK, every call returns, nothing
 * is thrown, and PHP raises nothing, on a host that turns every error level into an exception.
 *
 * {@see HostileRunner} does the work. Most cases run here. One whose regression is a fatal error
 * (a value that serialises to a fresh copy of itself, forever) runs in a child process with a low
 * memory limit, so it fails this test instead of ending the test run; so does the one that installs
 * the process-wide error handler, which a test process cannot take back out.
 */
final class HostileTest extends TestCase
{
    /** Cases this SDK does not run, and why. Anything else in the fixture runs. */
    private const SKIPPED = [
        'a key the SDK refuses: inert, logged once, never thrown' => 'capability browser: this SDK runs common and backend',
        'globals that cannot be patched' => 'frozenGlobals: the SDK patches no globals in PHP',
    ];

    private const EXECUTED = 26;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        return array_filter(SpecFile::cases('fixtures/hostile.json'), static fn (array $row): bool => HostileRunner::skipReason($row[0]) === null);
    }

    public function testEveryCaseRunsOrIsSkippedForAStatedReason(): void
    {
        self::assertSame(1, SpecFile::load('fixtures/hostile.json')['version'] ?? null);

        $skipped = [];
        foreach (SpecFile::cases('fixtures/hostile.json') as $name => [$case]) {
            $reason = HostileRunner::skipReason($case);
            if ($reason !== null) {
                $skipped[$name] = $reason;
            }
        }
        self::assertSame(self::SKIPPED, $skipped);
        self::assertCount(self::EXECUTED, self::cases());
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testCase(array $case): void
    {
        if (HostileRunner::needsItsOwnProcess($case)) {
            self::assertIsString($case['name'] ?? null);
            [$code, $output] = self::runInChild($case['name']);
            self::assertSame(0, $code, $output);

            return;
        }

        self::assertSame([], (new HostileRunner())->run($case));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function runInChild(string $name): array
    {
        $command = [\PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'display_errors=stderr', '-d', 'log_errors=0', __DIR__.'/../Fixtures/hostile.php', $name];
        // Errors go to a file: a fatal error's trace can be larger than a pipe nobody is reading yet holds.
        $errors = tempnam(sys_get_temp_dir(), 'vinktar-hostile-');
        self::assertIsString($errors);
        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes);
            self::assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($process);

            return [$code, substr(trim($output."\n".file_get_contents($errors)), -4000)];
        } finally {
            unlink($errors);
        }
    }
}
