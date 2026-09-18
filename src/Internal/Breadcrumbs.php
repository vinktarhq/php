<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * The trail of what happened before an error, as a ring buffer bounded in count and per message, so
 * one logged blob cannot become the size of the whole error budget.
 *
 * @internal
 *
 * @phpstan-type Crumb array{timestamp: string, category: string, message: string, level?: string, data?: array<string, mixed>}
 */
final class Breadcrumbs
{
    public const MAX_MESSAGE_BYTES = 1024;
    private const LEVELS = ['debug', 'info', 'warning', 'error'];

    /** @var list<Crumb> */
    private array $items = [];
    private readonly int $max;

    public function __construct(int $max)
    {
        $this->max = max(0, min($max, Limits::MAX_BREADCRUMBS));
    }

    /**
     * @param Crumb $crumb
     */
    public function add(array $crumb): void
    {
        if ($this->max === 0) {
            return;
        }
        $this->items[] = $crumb;
        if (\count($this->items) > $this->max) {
            $this->items = \array_slice($this->items, -$this->max);
        }
    }

    /**
     * @return list<Crumb>
     */
    public function list(): array
    {
        return $this->items;
    }

    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * Shape whatever the caller passed into a breadcrumb the server accepts, or null when it is not one.
     *
     * @return Crumb|null
     */
    public static function shape(mixed $input, Normalizer $normalizer): ?array
    {
        if (!\is_array($input)) {
            return null;
        }
        $message = \is_string($input['message'] ?? null) ? $input['message'] : '';
        $category = \is_string($input['category'] ?? null) && $input['category'] !== '' ? $input['category'] : 'custom';
        $data = $input['data'] ?? null;
        if ($message === '' && !\is_array($data)) {
            return null;
        }
        $crumb = [
            'timestamp' => \is_string($input['timestamp'] ?? null) ? $input['timestamp'] : Clock::iso(),
            'category' => Bytes::truncate($category, 64),
            'message' => Scrub::capped($message, self::MAX_MESSAGE_BYTES),
        ];
        if (\in_array($input['level'] ?? null, self::LEVELS, true)) {
            $crumb['level'] = $input['level'];
        }
        if (\is_array($data)) {
            $crumb['data'] = $normalizer->normalize($data);
        }

        return $crumb;
    }
}
