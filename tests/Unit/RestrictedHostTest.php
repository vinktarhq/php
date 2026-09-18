<?php

declare(strict_types=1);

namespace Vinktar\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hosts that take things away: `open_basedir`, which turns a file read into a warning, and
 * `disable_functions`, which makes a function not exist. Both are ini settings a test cannot set
 * and unset, so each runs `tests/Fixtures/restricted.php` in its own process, where the
 * application's error handler throws for every level.
 */
final class RestrictedHostTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSourceOutsideOpenBasedirCostsTheFrameItsContextAndNothingElse(): void
    {
        $allowed = implode(\PATH_SEPARATOR, [\dirname(__DIR__, 2), (string) realpath(sys_get_temp_dir())]);
        [$code, $output, $paths] = $this->runScript('open-basedir', ['open_basedir='.$allowed]);

        self::assertSame("flushed\n", $output);
        self::assertSame(0, $code);
        self::assertSame(['/v1/errors', '/v1/batch'], $paths);
    }

    public function testFunctionsTheHostDisabledAreDoneWithout(): void
    {
        [$code, $output, $paths] = $this->runScript('disabled-functions', ['disable_functions=gethostname,getenv,getcwd,ini_set']);

        self::assertSame("flushed\n", $output);
        self::assertSame(0, $code);
        self::assertSame(['/v1/errors', '/v1/batch'], $paths);
    }

    /**
     * @param list<string> $ini
     *
     * @return array{0: int, 1: string, 2: list<mixed>} the exit code, the output, and the path of each request sent
     */
    private function runScript(string $mode, array $ini): array
    {
        $out = tempnam((string) realpath(sys_get_temp_dir()), 'vinktar-restricted-');
        self::assertIsString($out);
        $this->files[] = $out;

        $command = [\PHP_BINARY, '-d', 'display_errors=stdout', '-d', 'log_errors=0'];
        foreach ($ini as $setting) {
            array_push($command, '-d', $setting);
        }
        array_push($command, __DIR__.'/../Fixtures/restricted.php', $mode, $out);
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        $paths = [];
        foreach (file($out, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $request = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($request);
            $paths[] = $request['path'] ?? null;
        }

        return [$code, $output, $paths];
    }
}
