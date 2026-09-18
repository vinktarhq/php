<?php

declare(strict_types=1);

namespace Vinktar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vinktar\Internal\Normalizer;

/**
 * The bounds on the walk: a value's own code can throw, never end, or be enormous, and what comes
 * out is still one JSON-encodable property. The serialiser that returns a new serialiser forever
 * also runs in a child process, in HostileTest, where a regression is an exit code and not the end
 * of the test run.
 */
final class NormalizerTest extends TestCase
{
    public function testWhatAValueSerialisesToKeepsItsDepth(): void
    {
        $money = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['amount' => 42, 'currency' => ['code' => 'EUR', 'minor' => ['digits' => 2]]];
            }
        };

        self::assertSame(
            ['total' => ['amount' => 42, 'currency' => ['code' => 'EUR', 'minor' => '[Object(1)]']], 'same' => ['amount' => 42, 'currency' => ['code' => 'EUR', 'minor' => '[Object(1)]']]],
            (new Normalizer())->normalize(['total' => $money, 'same' => $money->jsonSerialize()]),
        );
    }

    public function testASerialiserThatNeverEndsIsGivenUpOn(): void
    {
        $endless = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return new self();
            }
        };
        $nested = new class($endless) implements \JsonSerializable {
            public function __construct(private readonly object $inner)
            {
            }

            public function jsonSerialize(): mixed
            {
                return ['inner' => $this->inner];
            }
        };

        $out = (new Normalizer())->normalize(['v' => $endless, 'nested' => $nested, 'after' => 'kept']);

        self::assertSame(['v' => Normalizer::UNREADABLE, 'nested' => ['inner' => Normalizer::UNREADABLE], 'after' => 'kept'], $out);
    }

    public function testCodeThatThrowsCostsOneProperty(): void
    {
        $throws = new class implements \JsonSerializable, \Stringable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('no');
            }

            public function __toString(): string
            {
                throw new \RuntimeException('no');
            }
        };
        $text = new class implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('no');
            }
        };

        $out = (new Normalizer())->normalize(['a' => $throws, 'b' => ['c' => $text], 'd' => 1]);

        self::assertSame(['a' => Normalizer::UNREADABLE, 'b' => ['c' => Normalizer::UNREADABLE], 'd' => 1], $out);
        self::assertSame(['plan' => 'pro'], Normalizer::tags(['bad' => $text, 'worse' => [], 'plan' => 'pro']));
    }

    public function testOneCallVisitsABoundedNumberOfValues(): void
    {
        $dropped = [];
        $out = (new Normalizer())->normalize(
            ['wide' => range(1, 50_000), 'after' => 'x', 'later' => [1, 2]],
            static function (string $key, string $reason) use (&$dropped): void {
                $dropped[] = [$key, $reason];
            },
        );

        self::assertIsArray($out['wide']);
        self::assertCount(10_000, $out['wide']);
        self::assertSame([['wide', 'truncated'], ['later', 'truncated']], $dropped);
        self::assertSame('x', $out['after']);
        self::assertSame([], $out['later']);
        self::assertNotFalse(json_encode($out));
    }

    public function testArraysThatHoldThemselvesAndNestingOfAnyDepthEndAtTheDepthLimit(): void
    {
        $self = ['child' => ['again' => null]];
        $self['child']['again'] = &$self;
        $deep = [];
        for ($i = 0; $i < 20_000; ++$i) {
            $deep = ['n' => $deep];
        }

        $out = (new Normalizer())->normalize(['self' => $self, 'deep' => $deep]);

        self::assertSame(['self' => ['child' => ['again' => '[Object(1)]']], 'deep' => ['n' => ['n' => '[Object(1)]']]], $out);
    }
}
