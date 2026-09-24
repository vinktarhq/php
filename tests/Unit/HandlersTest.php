<?php

declare(strict_types=1);

namespace Vinktar\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The process-wide handlers, each in a real PHP process: an installed handler changes how a script
 * ends, and only a separate process can show that the exit code and output are what PHP produces
 * without the SDK.
 */
final class HandlersTest extends TestCase
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

    public function testAnUncaughtExceptionIsReportedOnceAndEndsTheScriptAsPhpWould(): void
    {
        [$code, $output, $sent] = $this->runScript('exception');

        self::assertSame($this->baselineExitCode('exception'), $code);
        self::assertStringContainsString('Uncaught RuntimeException: uncaught in a function', $output);
        $errors = self::errors($sent);
        self::assertCount(1, $errors);
        self::assertSame('uncaught in a function', self::dig($errors[0], 'exceptions', 0, 'value'));
        self::assertSame(['type' => 'uncaughtException', 'handled' => false, 'synthetic' => false], $errors[0]['mechanism'] ?? null);
        self::assertSame('handler-user', $errors[0]['user_id'] ?? null);

        $crash = self::crashFrame($errors[0]);
        self::assertSame('fail_deep', $crash['function'] ?? null);
        self::assertSame('tests/Fixtures/handlers.php', $crash['file'] ?? null);
        self::assertIsString($crash['context_line'] ?? null);
        self::assertStringContainsString("throw new RuntimeException('uncaught in a function');", $crash['context_line']);
        self::assertIsArray($crash['pre_context'] ?? null);
        self::assertCount(5, $crash['pre_context']);
    }

    public function testAnExistingExceptionHandlerStillRuns(): void
    {
        [$code, $output, $sent] = $this->runScript('previous');

        self::assertStringContainsString('previous handler saw: uncaught in a function', $output);
        self::assertStringNotContainsString('Uncaught RuntimeException', $output);
        self::assertSame($this->baselineExitCode('previous'), $code);
        self::assertCount(1, self::errors($sent));
    }

    public function testEveryClientThatAskedReportsTheSameCrashOnce(): void
    {
        $second = $this->tempFile();
        [$code, , $sent] = $this->runScript('two-clients', $second);

        self::assertSame($this->baselineExitCode('two-clients'), $code);
        self::assertCount(1, self::errors($sent));
        self::assertCount(1, self::errors(self::read($second)));
    }

    public function testWarningsAreReportedUnlessSilencedAndTheScriptCarriesOn(): void
    {
        [$code, $output, $sent] = $this->runScript('warning');

        self::assertSame($this->baselineExitCode('warning'), $code);
        self::assertStringContainsString('continued', $output);
        // PHP's own handling still ran: the warning was printed as it would have been.
        self::assertStringContainsString('a warning the application raised', $output);

        $byMessage = [];
        foreach (self::errors($sent) as $error) {
            $value = self::dig($error, 'exceptions', 0, 'value');
            self::assertIsString($value);
            $byMessage[$value] = $error;
        }
        self::assertArrayHasKey('a warning the application raised', $byMessage);
        self::assertArrayNotHasKey('a silenced warning', $byMessage);
        self::assertArrayNotHasKey('a notice', $byMessage);

        $warning = $byMessage['a warning the application raised'];
        self::assertSame('E_USER_WARNING', self::dig($warning, 'exceptions', 0, 'type'));
        self::assertSame('warning', $warning['level'] ?? null);
        self::assertSame(['type' => 'onerror', 'handled' => true, 'synthetic' => false], $warning['mechanism'] ?? null);

        self::assertArrayHasKey('after the warnings', $byMessage);
        $crumbs = $byMessage['after the warnings']['breadcrumbs'] ?? null;
        self::assertIsArray($crumbs);
        self::assertSame(['a notice'], array_column($crumbs, 'message'));
    }

    public function testAnErrorHandlerRegisteredForOneLevelIsCalledForThatLevelOnly(): void
    {
        [$code, $output, $sent] = $this->runScript('narrow-previous');
        [$baselineCode, $baselineOutput] = $this->runScript('narrow-previous', null, false);

        // Line for line what the script prints with no SDK in it: the handler saw its warning, with
        // the same arguments, and PHP itself dealt with the deprecation and the notice.
        self::assertSame($baselineOutput, $output);
        self::assertSame($baselineCode, $code);
        self::assertSame(1, substr_count($output, 'previous error handler saw:'));
        self::assertStringContainsString('previous error handler saw: 512 a warning the application raised at handlers.php:', $output);

        $messages = array_map(static fn (array $error): mixed => self::dig($error, 'exceptions', 0, 'value'), self::errors($sent));
        self::assertSame(['a warning the application raised', 'after the warnings'], $messages);
    }

    public function testAnErrorHandlerWhoseLevelsNobodyDeclaredIsLeftAlone(): void
    {
        [$code, $output, $sent] = $this->runScript('undeclared-previous');
        [$baselineCode, $baselineOutput] = $this->runScript('undeclared-previous', null, false);

        self::assertSame($baselineOutput, $output);
        self::assertSame($baselineCode, $code);
        // Warnings are the application's handler's alone; everything else is still reported.
        $messages = array_map(static fn (array $error): mixed => self::dig($error, 'exceptions', 0, 'value'), self::errors($sent));
        self::assertSame(['after the warnings'], $messages);
    }

    public function testAFatalErrorIsReportedAtShutdownEvenOutOfMemory(): void
    {
        [$code, $output, $sent] = $this->runScript('fatal');

        self::assertSame($this->baselineExitCode('fatal'), $code);
        self::assertStringContainsString('Allowed memory size', $output);
        $errors = self::errors($sent);
        self::assertCount(1, $errors);
        self::assertSame('E_ERROR', self::dig($errors[0], 'exceptions', 0, 'type'));
        $value = self::dig($errors[0], 'exceptions', 0, 'value');
        self::assertIsString($value);
        self::assertStringStartsWith('Allowed memory size of', $value);
        self::assertSame('fatal', $errors[0]['level'] ?? null);
        self::assertSame(['type' => 'uncaughtException', 'handled' => false, 'synthetic' => true], $errors[0]['mechanism'] ?? null);
    }

    public function testAFatalErrorTheWorkerLivedThroughIsReportedOnce(): void
    {
        [, $output, $sent] = $this->runScript('survived');

        self::assertStringContainsString('continued', $output);
        $errors = self::errors($sent);
        self::assertCount(1, $errors);
        self::assertSame('E_ERROR', self::dig($errors[0], 'exceptions', 0, 'type'));
        self::assertSame('Uncaught RuntimeException: escaped the request in /app/worker.php:12', self::dig($errors[0], 'exceptions', 0, 'value'));
        self::assertSame('fatal', $errors[0]['level'] ?? null);
        self::assertSame(['type' => 'uncaughtException', 'handled' => false, 'synthetic' => true], $errors[0]['mechanism'] ?? null);
    }

    /**
     * @return array{0: int, 1: string, 2: list<array<string, mixed>>}
     */
    private function runScript(string $mode, ?string $second = null, bool $withSdk = true): array
    {
        $out = $this->tempFile();
        $command = [\PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', __DIR__.'/../Fixtures/handlers.php', $mode, $out];
        if ($second !== null) {
            $command[] = $second;
        }
        $env = getenv();
        if (!$withSdk) {
            $env['VINKTAR_NO_SDK'] = '1';
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, $stdout.$stderr, self::read($out)];
    }

    /** How the same script ends with no SDK in it: what the handlers must leave unchanged. */
    private function baselineExitCode(string $mode): int
    {
        [$code, , $sent] = $this->runScript($mode, null, false);
        self::assertSame([], $sent);

        return $code;
    }

    private function tempFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'vinktar-handlers-');
        self::assertIsString($file);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function read(string $file): array
    {
        $requests = [];
        foreach (file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $request = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($request);
            /** @var array<string, mixed> $request */
            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return list<array<string, mixed>>
     */
    private static function errors(array $requests): array
    {
        $errors = [];
        foreach ($requests as $request) {
            if (($request['path'] ?? null) !== '/v1/errors') {
                continue;
            }
            $list = self::dig($request, 'body', 'errors');
            self::assertIsArray($list);
            foreach ($list as $error) {
                self::assertIsArray($error);
                /** @var array<string, mixed> $error */
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $error
     *
     * @return array<array-key, mixed>
     */
    private static function crashFrame(array $error): array
    {
        $frames = self::dig($error, 'exceptions', 0, 'stack');
        self::assertIsArray($frames);
        self::assertNotEmpty($frames);
        $crash = $frames[array_key_last($frames)];
        self::assertIsArray($crash);

        return $crash;
    }

    /**
     * A value inside decoded JSON, or null where the path does not exist.
     *
     * @param array<array-key, mixed> $value
     */
    private static function dig(array $value, string|int ...$path): mixed
    {
        $current = $value;
        foreach ($path as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }
}
