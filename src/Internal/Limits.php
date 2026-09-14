<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Server caps, mirrored from `spec/limits.json` and asserted against it by a test.
 *
 * Enforced locally so a value that would be REJECTED is trimmed or dropped before it costs a
 * request. The server does not truncate an oversize string: it refuses the whole event, so
 * "send it and see" loses data.
 *
 * @internal
 */
final class Limits
{
    /** Compressed request ceiling. */
    public const MAX_REQUEST_BYTES = 5_242_880;

    /** Events and identify entries, combined, per /v1/batch request. */
    public const MAX_BATCH_ITEMS = 1000;
    public const MAX_PROPERTIES_PER_EVENT = 255;
    public const MAX_STRING_BYTES = 255;
    public const MAX_DEPTH = 3;

    /** Timestamps outside this window are rejected. */
    public const TIMESTAMP_PAST_SECONDS = 7 * 86_400;
    public const TIMESTAMP_FUTURE_SECONDS = 3_600;

    public const MAX_TRAIT_VALUE_BYTES = 255;
    public const MAX_TRAIT_KEY_BYTES = 128;
    public const MAX_TRAITS_PER_REQUEST = 100;
    public const MAX_TRAIT_REQUEST_BYTES = 8192;

    /** Errors per /v1/errors request. */
    public const MAX_ERROR_ITEMS = 50;
    public const MAX_EXCEPTIONS = 5;
    public const MAX_FRAMES = 50;
    public const MAX_TYPE_BYTES = 256;
    public const MAX_MESSAGE_BYTES = 8192;
    public const MAX_STACK_RAW_BYTES = 16384;
    public const MAX_FRAME_STRING_BYTES = 512;
    public const MAX_EXCEPTIONS_BYTES = 262_144;
    /** The server keeps only the last 50, so anything above this is bytes on the wire for nothing. */
    public const MAX_BREADCRUMBS = 50;
    public const MAX_BREADCRUMBS_BYTES = 32_768;
    public const MAX_TAGS = 32;
    public const MAX_TAG_KEY_BYTES = 32;
    public const MAX_TAG_VALUE_BYTES = 200;
    public const MAX_FINGERPRINT_PARTS = 8;
    public const MAX_FINGERPRINT_PART_BYTES = 128;

    /** The only levels the server stores. Anything else silently becomes `error`. */
    public const LEVELS = ['fatal', 'error', 'warning', 'info'];

    public const MECHANISMS = ['onerror', 'onunhandledrejection', 'uncaughtException', 'unhandledRejection', 'manual'];

    /** Why a record was not delivered, as client reports count it. The server discards any other reason. */
    public const REPORT_REASONS = ['queue_overflow', 'sample_rate', 'event_processor', 'before_send', 'send_error', 'ratelimit', 'invalid', 'deduplicated'];

    public const REPORT_CATEGORIES = ['event', 'identify', 'error'];
}
