<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventBuffer.
 *
 * Covers: push, order preservation, flush, isEmpty, count,
 * max-events cap, dropped-events counter.
 */
class EventBufferTest extends TestCase
{
    // -------------------------------------------------------------------------
    // push / count / isEmpty
    // -------------------------------------------------------------------------

    public function test_new_buffer_is_empty(): void
    {
        $buffer = new EventBuffer();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame(0, $buffer->count());
    }

    public function test_push_makes_buffer_non_empty(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $this->assertFalse($buffer->isEmpty());
        $this->assertSame(1, $buffer->count());
    }

    public function test_count_reflects_number_of_pushed_events(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);
        $buffer->push(['type' => 'outgoing_request']);
        $buffer->push(['type' => 'request']);

        $this->assertSame(3, $buffer->count());
    }

    // -------------------------------------------------------------------------
    // all() — order preservation
    // -------------------------------------------------------------------------

    public function test_all_returns_events_in_insertion_order(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'first']);
        $buffer->push(['type' => 'second']);
        $buffer->push(['type' => 'third']);

        $events = $buffer->all();

        $this->assertCount(3, $events);
        $this->assertSame('first',  $events[0]['type']);
        $this->assertSame('second', $events[1]['type']);
        $this->assertSame('third',  $events[2]['type']);
    }

    public function test_all_does_not_clear_the_buffer(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $buffer->all();

        $this->assertSame(1, $buffer->count());
    }

    // -------------------------------------------------------------------------
    // flush()
    // -------------------------------------------------------------------------

    public function test_flush_returns_all_events(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select 1']);
        $buffer->push(['type' => 'outgoing_request', 'host' => 'api.example.com']);

        $flushed = $buffer->flush();

        $this->assertCount(2, $flushed);
        $this->assertSame('query',            $flushed[0]['type']);
        $this->assertSame('outgoing_request', $flushed[1]['type']);
    }

    public function test_flush_clears_the_buffer(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $buffer->flush();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame(0, $buffer->count());
    }

    public function test_flush_on_empty_buffer_returns_empty_array(): void
    {
        $buffer = new EventBuffer();

        $result = $buffer->flush();

        $this->assertSame([], $result);
    }

    public function test_flush_is_idempotent_second_call_returns_empty(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $buffer->flush();
        $second = $buffer->flush();

        $this->assertSame([], $second);
        $this->assertTrue($buffer->isEmpty());
    }

    // -------------------------------------------------------------------------
    // max_events cap + dropped counter
    // -------------------------------------------------------------------------

    public function test_events_beyond_max_are_silently_dropped(): void
    {
        $buffer = new EventBuffer(maxEvents: 3);

        $buffer->push(['type' => 'a']);
        $buffer->push(['type' => 'b']);
        $buffer->push(['type' => 'c']);
        $buffer->push(['type' => 'd']); // should be dropped

        $this->assertSame(3, $buffer->count());
    }

    public function test_dropped_counter_tracks_excess_events(): void
    {
        $buffer = new EventBuffer(maxEvents: 2);

        $buffer->push(['type' => 'a']);
        $buffer->push(['type' => 'b']);
        $buffer->push(['type' => 'c']); // dropped
        $buffer->push(['type' => 'd']); // dropped

        $this->assertSame(2, $buffer->droppedCount());
    }

    public function test_dropped_counter_is_zero_when_no_events_dropped(): void
    {
        $buffer = new EventBuffer(maxEvents: 100);

        $buffer->push(['type' => 'a']);
        $buffer->push(['type' => 'b']);

        $this->assertSame(0, $buffer->droppedCount());
    }

    public function test_flush_does_not_reset_dropped_counter(): void
    {
        // Dropped counter is diagnostic; it survives flush so operators can observe it.
        $buffer = new EventBuffer(maxEvents: 1);

        $buffer->push(['type' => 'a']);
        $buffer->push(['type' => 'b']); // dropped

        $buffer->flush();

        $this->assertSame(1, $buffer->droppedCount());
    }

    public function test_default_max_events_accepts_many_events(): void
    {
        // Default cap is 1000 — smoke test that normal usage never drops
        $buffer = new EventBuffer();

        for ($i = 0; $i < 100; $i++) {
            $buffer->push(['type' => 'query', 'i' => $i]);
        }

        $this->assertSame(100, $buffer->count());
        $this->assertSame(0, $buffer->droppedCount());
    }
}
