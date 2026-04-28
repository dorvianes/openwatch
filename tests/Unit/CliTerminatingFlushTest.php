<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Support\BatchFlusher;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Direct test for the CLI/job terminating flush hook.
 *
 * Design requirement (design.md §3 Lifecycle hooks):
 *   CLI/jobs: flush al terminar el proceso de aplicación via app()->terminating()
 *
 * The ServiceProvider registers a terminating callback that calls
 * BatchFlusher::flush(). We test the callback behaviour WITHOUT needing
 * a real Laravel app container — we simulate the callback directly.
 * This keeps the test fast and decoupled.
 */
class CliTerminatingFlushTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array{HttpTransport, object} */
    private function spyTransport(): array
    {
        $capture = new class {
            public array $batches = [];
        };

        $transport = new class($capture) extends HttpTransport {
            public function __construct(public readonly object $capture)
            {
                // bypass parent constructor
            }

            public function sendBatch(array $events): bool
            {
                if (empty($events)) {
                    return false;
                }
                $this->capture->batches[] = $events;
                return true;
            }
        };

        return [$transport, $capture];
    }

    /**
     * Simulate the terminating callback registered by bootCliFlush():
     *
     *   $this->app->terminating(function () {
     *       $this->app->make(BatchFlusher::class)->flush();
     *   });
     *
     * We create the BatchFlusher directly (as the container would) and invoke
     * flush() — exactly what the callback does.
     */
    private function makeTerminatingCallback(BatchFlusher $flusher): \Closure
    {
        return function () use ($flusher) {
            try {
                $flusher->flush();
            } catch (\Throwable) {
                // mirrors the silent-failure guard in bootCliFlush
            }
        };
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When batching is enabled and the buffer has events, the terminating
     * callback must flush all events via sendBatch().
     */
    public function test_terminating_callback_flushes_buffered_events(): void
    {
        [$transport, $capture] = $this->spyTransport();
        $buffer = new EventBuffer();

        $buffer->push(['type' => 'query', 'sql' => 'select 1']);
        $buffer->push(['type' => 'query', 'sql' => 'select 2']);

        $flusher  = new BatchFlusher($transport, $buffer, batchingEnabled: true);
        $callback = $this->makeTerminatingCallback($flusher);

        // Simulate Laravel calling the registered terminating callbacks
        $callback();

        $this->assertCount(1, $capture->batches, 'Expected exactly one batch call');
        $this->assertCount(2, $capture->batches[0], 'Batch must contain both events');
    }

    /**
     * After the terminating callback fires, the buffer must be empty —
     * important for long-running processes (queue workers) that reuse the singleton.
     */
    public function test_terminating_callback_clears_buffer(): void
    {
        [$transport] = $this->spyTransport();
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'outgoing_request', 'url' => 'https://api.example.com']);

        $flusher  = new BatchFlusher($transport, $buffer, batchingEnabled: true);
        $callback = $this->makeTerminatingCallback($flusher);

        $callback();

        $this->assertTrue($buffer->isEmpty(), 'Buffer must be empty after terminating flush');
    }

    /**
     * When batching is DISABLED, the terminating callback must not send anything
     * even if the buffer has events.
     */
    public function test_terminating_callback_is_noop_when_batching_disabled(): void
    {
        [$transport, $capture] = $this->spyTransport();
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select count(*) from users']);

        $flusher  = new BatchFlusher($transport, $buffer, batchingEnabled: false);
        $callback = $this->makeTerminatingCallback($flusher);

        $callback();

        $this->assertEmpty($capture->batches, 'No batches must be sent when batching is disabled');
        $this->assertFalse($buffer->isEmpty(), 'Buffer must remain untouched when batching is disabled');
    }

    /**
     * Invoking the terminating callback twice (e.g. multiple hooks accidentally registered)
     * must only result in one batch — the second invocation hits an empty buffer.
     */
    public function test_terminating_callback_called_twice_is_idempotent(): void
    {
        [$transport, $capture] = $this->spyTransport();
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select now()']);

        $flusher  = new BatchFlusher($transport, $buffer, batchingEnabled: true);
        $callback = $this->makeTerminatingCallback($flusher);

        $callback(); // first — flushes
        $callback(); // second — buffer empty, no-op

        $this->assertCount(1, $capture->batches, 'Second callback invocation must be a no-op');
    }

    /**
     * A transport exception inside the terminating callback must NOT propagate —
     * mirrors the silent-failure guard in bootCliFlush().
     */
    public function test_terminating_callback_swallows_transport_exceptions(): void
    {
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select 1']);

        $throwingTransport = new class extends HttpTransport {
            public function __construct()
            {
                // bypass parent
            }

            public function sendBatch(array $events): bool
            {
                throw new \RuntimeException('network error');
            }
        };

        $flusher  = new BatchFlusher($throwingTransport, $buffer, batchingEnabled: true);
        $callback = $this->makeTerminatingCallback($flusher);

        // Must not throw — the callback absorbs exceptions silently
        $callback();

        // The buffer is drained before sendBatch() is called, so even when the
        // transport throws, the buffer is empty after the callback completes.
        $this->assertTrue($buffer->isEmpty(), 'Buffer must be empty even when transport throws');
    }
}
