<?php

namespace Dorvianes\OpenWatch\Buffer;

/**
 * In-memory event buffer for batching telemetry events before sending.
 *
 * - Bounded by a configurable max; excess events are silently dropped.
 * - droppedCount() tracks how many events were discarded so operators can observe pressure.
 * - flush() returns all accumulated events and clears the buffer (idempotent).
 * - All operations are synchronous; no I/O, no side effects.
 */
class EventBuffer
{
    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    private int $dropped = 0;

    public function __construct(private int $maxEvents = 1000) {}

    /**
     * Add an event to the buffer.
     * If the buffer is already at capacity, the event is silently dropped
     * and the dropped counter is incremented.
     *
     * @param array<string, mixed> $event
     */
    public function push(array $event): void
    {
        if (count($this->events) >= $this->maxEvents) {
            $this->dropped++;
            return;
        }

        $this->events[] = $event;
    }

    /**
     * Return all buffered events without clearing the buffer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * Return all buffered events AND clear the buffer.
     * Calling flush() on an empty buffer is a no-op returning [].
     *
     * @return array<int, array<string, mixed>>
     */
    public function flush(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    /**
     * Returns true when the buffer holds no events.
     */
    public function isEmpty(): bool
    {
        return empty($this->events);
    }

    /**
     * Number of events currently held in the buffer.
     */
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Number of events that were dropped because the buffer was at capacity.
     * This counter is NOT reset by flush() — it is diagnostic and cumulative.
     */
    public function droppedCount(): int
    {
        return $this->dropped;
    }
}
