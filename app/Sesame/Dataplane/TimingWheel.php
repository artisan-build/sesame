<?php

namespace App\Sesame\Dataplane;

/**
 * Hashed timing wheel with 100 ms slots. It holds only event IDs and their
 * account; the events themselves live in Postgres (and the WAL before
 * that), which is what lets a restart rebuild the wheel.
 */
final class TimingWheel
{
    private const int SLOT_MS = 100;

    /** @var array<int, array<string, string>> slot => [event id => account id] */
    private array $slots = [];

    private ?int $cursor = null;

    private int $size = 0;

    public function add(string $eventId, string $accountId, int $dueMs): void
    {
        $slot = intdiv($dueMs, self::SLOT_MS);
        if ($this->cursor !== null && $slot < $this->cursor) {
            $slot = $this->cursor;
        }
        if (! isset($this->slots[$slot][$eventId])) {
            $this->size++;
        }
        $this->slots[$slot][$eventId] = $accountId;
    }

    /**
     * Remove and return up to $limit events due at or before $nowMs, earliest first.
     *
     * @return array<string, string> event id => account id
     */
    public function popDue(int $nowMs, int $limit): array
    {
        $now = intdiv($nowMs, self::SLOT_MS);
        if ($this->cursor === null) {
            $this->cursor = $this->slots === [] ? $now : min(array_keys($this->slots));
        }
        $due = [];
        while ($this->cursor <= $now && count($due) < $limit) {
            if (isset($this->slots[$this->cursor])) {
                foreach ($this->slots[$this->cursor] as $id => $account) {
                    if (count($due) >= $limit) {
                        break 2;
                    }
                    $due[$id] = $account;
                    unset($this->slots[$this->cursor][$id]);
                    $this->size--;
                }
                unset($this->slots[$this->cursor]);
            }
            $this->cursor++;
        }

        return $due;
    }

    public function size(): int
    {
        return $this->size;
    }
}
