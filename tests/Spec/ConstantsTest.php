<?php

declare(strict_types=1);

namespace Vinktar\Tests\Spec;

use PHPUnit\Framework\TestCase;
use Vinktar\Internal\Ids;
use Vinktar\Internal\InboundFilter;
use Vinktar\Internal\Limits;
use Vinktar\Internal\Traits;

/**
 * Every number and list the SDK enforces, against the published contract. A value that drifts
 * fails a build rather than a customer's request.
 */
final class ConstantsTest extends TestCase
{
    public function testLimitsMatchTheContract(): void
    {
        $json = SpecFile::load('limits.json');
        $request = self::section($json, 'request');
        $batch = self::section($json, 'batch');
        $traits = self::section($json, 'traits');
        $errors = self::section($json, 'errors');
        $reports = self::section($json, 'reports');

        self::assertSame($request['maxBodyBytes'], Limits::MAX_REQUEST_BYTES);
        self::assertSame($batch['maxItems'], Limits::MAX_BATCH_ITEMS);
        self::assertSame($batch['maxPropertiesPerEvent'], Limits::MAX_PROPERTIES_PER_EVENT);
        self::assertSame($batch['maxStringBytes'], Limits::MAX_STRING_BYTES);
        self::assertSame($batch['maxNestingDepth'], Limits::MAX_DEPTH);
        self::assertSame(['past' => '-7 days', 'future' => '+1 hour'], $batch['timestampWindow']);
        self::assertSame(7 * 86_400, Limits::TIMESTAMP_PAST_SECONDS);
        self::assertSame(3_600, Limits::TIMESTAMP_FUTURE_SECONDS);

        self::assertSame($traits['maxValueBytes'], Limits::MAX_TRAIT_VALUE_BYTES);
        self::assertSame($traits['maxKeyBytes'], Limits::MAX_TRAIT_KEY_BYTES);
        self::assertSame($traits['maxKeysPerRequest'], Limits::MAX_TRAITS_PER_REQUEST);
        self::assertSame($traits['maxRequestBytes'], Limits::MAX_TRAIT_REQUEST_BYTES);

        self::assertSame($errors['maxItems'], Limits::MAX_ERROR_ITEMS);
        self::assertSame($errors['maxExceptions'], Limits::MAX_EXCEPTIONS);
        self::assertSame($errors['maxFrames'], Limits::MAX_FRAMES);
        self::assertSame($errors['maxTypeBytes'], Limits::MAX_TYPE_BYTES);
        self::assertSame($errors['maxMessageBytes'], Limits::MAX_MESSAGE_BYTES);
        self::assertSame($errors['maxStackRawBytes'], Limits::MAX_STACK_RAW_BYTES);
        self::assertSame($errors['maxFrameStringBytes'], Limits::MAX_FRAME_STRING_BYTES);
        self::assertSame($errors['maxExceptionsBytes'], Limits::MAX_EXCEPTIONS_BYTES);
        self::assertSame($errors['maxBreadcrumbs'], Limits::MAX_BREADCRUMBS);
        self::assertSame($errors['maxBreadcrumbsBytes'], Limits::MAX_BREADCRUMBS_BYTES);
        self::assertSame($errors['maxTags'], Limits::MAX_TAGS);
        self::assertSame($errors['maxTagKeyBytes'], Limits::MAX_TAG_KEY_BYTES);
        self::assertSame($errors['maxTagValueBytes'], Limits::MAX_TAG_VALUE_BYTES);
        self::assertSame($errors['maxFingerprintParts'], Limits::MAX_FINGERPRINT_PARTS);
        self::assertSame($errors['maxFingerprintPartBytes'], Limits::MAX_FINGERPRINT_PART_BYTES);
        self::assertSame($errors['levels'], Limits::LEVELS);
        self::assertSame($errors['mechanisms'], Limits::MECHANISMS);
        // PHP frames are built crash-last from getTrace(), causes thrown-first from getPrevious().
        self::assertSame('crash-last', $errors['frameOrder']);
        self::assertSame('thrown-first', $errors['exceptionOrder']);

        self::assertSame($reports['reasons'], Limits::REPORT_REASONS);
        self::assertSame($reports['categories'], Limits::REPORT_CATEGORIES);
    }

    public function testBlockedIdsAreTheListTheSdkRefuses(): void
    {
        $blocked = self::strings(SpecFile::load('blocked-ids.json')['blocked'] ?? null);
        self::assertEqualsCanonicalizing($blocked, Ids::BLOCKED);

        foreach ($blocked as $id) {
            self::assertTrue(Ids::isBlocked(' '.strtoupper($id).' '), $id);
        }
        self::assertTrue(Ids::isBlocked(''));
        self::assertTrue(Ids::isBlocked('   '));
        self::assertFalse(Ids::isBlocked('user_123'));
    }

    public function testInboundFilterMirrorsWhatTheServerSuppresses(): void
    {
        $json = SpecFile::load('inbound-filter.json');
        self::assertEqualsCanonicalizing(self::strings($json['denyMessageSubstrings'] ?? null), InboundFilter::DENY_MESSAGES);
        self::assertEqualsCanonicalizing(self::strings($json['nonAppFrameSchemes'] ?? null), InboundFilter::NON_APP_FRAME_SCHEMES);

        self::assertTrue(InboundFilter::isSuppressed('Uncaught: Script error.', ['/app/src/a.php']));
        self::assertTrue(InboundFilter::isSuppressed('boom', ['chrome-extension://x/a.js', 'about:blank']));
        self::assertFalse(InboundFilter::isSuppressed('boom', ['chrome-extension://x/a.js', '/app/src/a.php']));
        self::assertFalse(InboundFilter::isSuppressed('boom', []));
    }

    public function testReservedTraitsMatchTheCanonicalKeysAndAliases(): void
    {
        $json = SpecFile::load('reserved-traits.json');
        self::assertEqualsCanonicalizing(self::strings($json['canonical'] ?? null), Traits::RESERVED);
        self::assertEquals($json['aliases'] ?? null, Traits::ALIASES);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private static function section(array $json, string $key): array
    {
        $section = $json[$key] ?? null;
        self::assertIsArray($section, "limits.json has no {$key} section");

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(static fn (mixed $v): string => \is_string($v) ? $v : self::fail('expected a string'), $value));
    }
}
