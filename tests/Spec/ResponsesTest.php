<?php

declare(strict_types=1);

namespace Vinktar\Tests\Spec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vinktar\Internal\ResponsePolicy;

/**
 * Every row of `spec/fixtures/responses.json` through the decision function, headers included:
 * the action, the wait, the categories a hold covers, and whether it is a billing hold.
 */
final class ResponsesTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        return SpecFile::cases('fixtures/responses.json');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testDecidesLikeTheContract(array $case): void
    {
        self::assertIsInt($case['status']);
        self::assertIsString($case['action']);
        $headers = $case['headers'] ?? [];
        self::assertIsArray($headers);
        $retryAfter = self::header($headers, 'Retry-After');
        $categories = self::header($headers, 'X-RateLimit-Categories');

        $decision = $case['status'] === 0
            ? ResponsePolicy::networkFailure()
            : ResponsePolicy::decide($case['status'], $case['body'] ?? null, ResponsePolicy::parseRetryAfter($retryAfter, self::NOW), $categories);

        self::assertSame($case['action'], $decision->action);
        if (isset($case['degrade'])) {
            self::assertSame($case['degrade'], $decision->degrade);
        }
        if (\array_key_exists('wait', $case)) {
            // "escalating" means no server wait: the local schedule decides.
            self::assertSame($case['wait'] === 'escalating' ? 0 : $case['wait'], $decision->wait);
        }
        if (isset($case['categories'])) {
            $endpoint = \is_string($case['endpoint'] ?? null) ? $case['endpoint'] : '/v1/batch';
            self::assertSame($case['categories'], ResponsePolicy::holdCategories(ResponsePolicy::endpointCategories($endpoint), $decision->categories));
        }
        self::assertSame($case['billing'] ?? false, $decision->billing);
    }

    public function testRetryAfterForms(): void
    {
        self::assertSame(0, ResponsePolicy::parseRetryAfter(null));
        self::assertSame(0, ResponsePolicy::parseRetryAfter(''));
        self::assertSame(0, ResponsePolicy::parseRetryAfter('soon'));
        self::assertSame(0, ResponsePolicy::parseRetryAfter('-5'));
        self::assertSame(2, ResponsePolicy::parseRetryAfter('1.5'));
        self::assertSame(0, ResponsePolicy::parseRetryAfter(gmdate('D, d M Y H:i:s \G\M\T', self::NOW - 60), self::NOW));
    }

    /**
     * @param array<mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        if (preg_match('/^<<now\+(\d+)s as HTTP-date>>$/', $value, $m) === 1) {
            return gmdate('D, d M Y H:i:s \G\M\T', self::NOW + (int) $m[1]);
        }

        return $value;
    }
}
