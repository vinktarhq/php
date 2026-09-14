<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * The tally of what the SDK itself threw away, sent as `client_report` so a customer can see "the
 * SDK dropped N events for reason R" in the product instead of wondering where they went.
 *
 * Snapshot and commit, not drain: taking the tally before a request and clearing it would lose the
 * counts on any failure between the two, and the report of a struggling client is the one worth
 * having. `commit()` subtracts what was delivered, so counts recorded meanwhile are neither lost nor
 * sent twice.
 *
 * @internal
 */
final class Reports
{
    /** @var array<string, int> "reason|category" => quantity */
    private array $counts = [];

    public function record(string $reason, string $category, int $quantity = 1): void
    {
        if ($quantity < 1) {
            return;
        }
        $key = $reason.'|'.$category;
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $quantity;
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }

    /**
     * Does not clear. See commit().
     *
     * @return array{body: array{discarded: list<array{reason: string, category: string, quantity: int}>}, taken: array<string, int>}|null
     */
    public function snapshot(): ?array
    {
        if ($this->counts === []) {
            return null;
        }
        $discarded = [];
        foreach ($this->counts as $key => $quantity) {
            [$reason, $category] = explode('|', $key, 2) + [1 => ''];
            $discarded[] = ['reason' => $reason, 'category' => $category, 'quantity' => $quantity];
        }

        return ['body' => ['discarded' => $discarded], 'taken' => $this->counts];
    }

    /**
     * Only when the request carrying the snapshot was accepted. A refused body took the report with
     * it, so the counts stay for the next request.
     *
     * @param array<string, int> $taken
     */
    public function commit(array $taken): void
    {
        foreach ($taken as $key => $quantity) {
            $remaining = ($this->counts[$key] ?? 0) - $quantity;
            if ($remaining > 0) {
                $this->counts[$key] = $remaining;
            } else {
                unset($this->counts[$key]);
            }
        }
    }
}
