<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * A bounded, ordered buffer of records waiting to be sent.
 *
 * Bounded by count and by bytes, because a worker can run for months: an unbounded queue trades a
 * visible dropped-record count for an invisible memory leak. Overflow drops the oldest; in analytics
 * the newest data is what someone is waiting to see.
 *
 * Events and identify entries share one queue so ordering survives: an identify must not overtake
 * the events it explains. Errors get their own, because they go to a different endpoint with a
 * different rate limit.
 *
 * Leased, not taken, while in flight: a chunk being sent stays queued until the server's answer
 * decides its fate, so a failure anywhere between building the request and reading the response
 * cannot lose it. The bounds apply to what is waiting.
 *
 * @internal
 */
final class Queue
{
    /** @var list<Entry> */
    private array $items = [];
    /** @var \SplObjectStorage<Entry, null> */
    private \SplObjectStorage $leased;
    private int $size = 0;
    private int $leasedSize = 0;

    public function __construct(
        private readonly int $maxItems,
        private readonly Reports $reports,
        private readonly int $maxBytes = \PHP_INT_MAX,
    ) {
        $this->leased = new \SplObjectStorage();
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** Seal and append. False when the record cannot be encoded; nothing is queued then. */
    public function push(string $category, mixed $item): bool
    {
        $entry = Entry::seal($category, $item);
        if ($entry === null) {
            return false;
        }
        $this->items[] = $entry;
        $this->size += $entry->bytes();
        $this->trim();

        return true;
    }

    /**
     * @param list<Entry> $entries
     */
    public function lease(array $entries): void
    {
        foreach ($entries as $entry) {
            if ($this->leased->offsetExists($entry) || !\in_array($entry, $this->items, true)) {
                continue;
            }
            $this->leased->offsetSet($entry, null);
            $this->leasedSize += $entry->bytes();
        }
    }

    /**
     * @param list<Entry> $entries
     */
    public function release(array $entries): void
    {
        foreach ($entries as $entry) {
            if (!$this->leased->offsetExists($entry)) {
                continue;
            }
            $this->leased->offsetUnset($entry);
            $this->leasedSize -= $entry->bytes();
        }
        // What arrived during the request may now be over the bounds.
        $this->trim();
    }

    /**
     * Remove exactly these entries, wherever they are now.
     *
     * @param list<Entry> $entries
     */
    public function remove(array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $kept = [];
        foreach ($this->items as $entry) {
            if (!\in_array($entry, $entries, true)) {
                $kept[] = $entry;
                continue;
            }
            $this->size -= $entry->bytes();
            if ($this->leased->offsetExists($entry)) {
                $this->leased->offsetUnset($entry);
                $this->leasedSize -= $entry->bytes();
            }
        }
        $this->items = $kept;
    }

    /**
     * @return list<Entry>
     */
    public function peek(): array
    {
        return $this->items;
    }

    /** Empty the queue, counting every record under $reason. */
    public function discardAll(string $reason): int
    {
        $count = \count($this->items);
        foreach ($this->items as $entry) {
            $this->reports->record($reason, $entry->category);
        }
        $this->items = [];
        $this->leased = new \SplObjectStorage();
        $this->size = 0;
        $this->leasedSize = 0;

        return $count;
    }

    private function trim(): void
    {
        while (\count($this->items) - \count($this->leased) > $this->maxItems || $this->size - $this->leasedSize > $this->maxBytes) {
            // The oldest entry not in flight. One in flight is decided by the server's answer.
            $index = null;
            foreach ($this->items as $i => $entry) {
                if (!$this->leased->offsetExists($entry)) {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                return;
            }
            $evicted = $this->items[$index];
            array_splice($this->items, $index, 1);
            $this->size -= $evicted->bytes();
            $this->reports->record('queue_overflow', $evicted->category);
        }
    }
}
