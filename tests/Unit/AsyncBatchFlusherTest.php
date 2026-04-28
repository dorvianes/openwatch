<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Jobs\OpenWatchSendBatchJob;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Task 2.1 — BatchFlusher async dispatch vs sync send.
 *
 * Design decisions:
 * - When batching.enabled + async.enabled → dispatch Job, skip in-process send
 * - When batching.enabled + async.disabled → sendBatch() in-process (existing behavior)
 * - When batching.disabled → no-op (existing behavior)
 * - When buffer is empty → no dispatch, no send
 * - Fallback sync if dispatch throws
 *
 * We use a spy callable dispatcher instead of Queue::fake() to keep tests
 * pure (no illuminate/queue dev dependency required in this package).
 */
class AsyncBatchFlusherTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Spy transport */
    private function spyTransport(): array
    {
        $capture = new class {
            public array $batches = [];
        };

        $transport = new class($capture) extends HttpTransport {
            public function __construct(public readonly object $capture)
            {
                // bypass parent
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

    /** Spy dispatcher — captures dispatched jobs */
    private function spyDispatcher(): array
    {
        $capture = new class {
            public array $dispatched = [];
        };

        $dispatcher = function (object $job) use ($capture): void {
            $capture->dispatched[] = $job;
        };

        return [$dispatcher, $capture];
    }

    /** Throwing dispatcher — simulates dispatch failure */
    private function throwingDispatcher(): callable
    {
        return function (object $job): void {
            throw new \RuntimeException('Queue connection unavailable');
        };
    }

    // -------------------------------------------------------------------------
    // Scenario: Sync flush sends batch directly (existing behavior preserved)
    // -------------------------------------------------------------------------

    public function test_sync_flush_calls_send_batch_when_async_disabled(): void
    {
        [$transport, $capture] = $this->spyTransport();
        [$dispatcher, $dispatchCapture] = $this->spyDispatcher();

        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select 1']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: true,
            asyncEnabled:    false,
        );

        $flusher->flush();

        $this->assertCount(1, $capture->batches);
        $this->assertEmpty($dispatchCapture->dispatched);
    }

    public function test_sync_flush_empty_buffer_is_noop(): void
    {
        [$transport, $capture] = $this->spyTransport();

        $buffer  = new EventBuffer();
        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: true,
            asyncEnabled:    false,
        );

        $flusher->flush();

        $this->assertEmpty($capture->batches);
    }

    // -------------------------------------------------------------------------
    // Scenario: Async flush dispatches a job
    // -------------------------------------------------------------------------

    public function test_async_flush_dispatches_job_and_skips_in_process_send(): void
    {
        [$transport, $transportCapture] = $this->spyTransport();
        [$dispatcher, $dispatchCapture] = $this->spyDispatcher();

        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select 1']);
        $buffer->push(['type' => 'outgoing_request', 'host' => 'api.test']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: true,
            asyncEnabled:    true,
            dispatcher:      $dispatcher,
        );

        $flusher->flush();

        // Exactly one Job dispatched
        $this->assertCount(1, $dispatchCapture->dispatched);
        $this->assertInstanceOf(OpenWatchSendBatchJob::class, $dispatchCapture->dispatched[0]);

        // In-process send NOT called
        $this->assertEmpty($transportCapture->batches);
    }

    public function test_async_flush_job_carries_buffered_events(): void
    {
        [$transport] = $this->spyTransport();
        [$dispatcher, $dispatchCapture] = $this->spyDispatcher();

        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select users']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: true,
            asyncEnabled:    true,
            dispatcher:      $dispatcher,
        );

        $flusher->flush();

        /** @var OpenWatchSendBatchJob $job */
        $job = $dispatchCapture->dispatched[0];
        $this->assertSame([['type' => 'query', 'sql' => 'select users']], $job->events);
    }

    // -------------------------------------------------------------------------
    // Scenario: Async flush honors queue configuration
    // -------------------------------------------------------------------------

    public function test_async_flush_job_uses_configured_connection_and_queue(): void
    {
        [$transport] = $this->spyTransport();
        [$dispatcher, $dispatchCapture] = $this->spyDispatcher();

        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled:   true,
            asyncEnabled:      true,
            asyncConnection:   'redis',
            asyncQueue:        'openwatch',
            dispatcher:        $dispatcher,
        );

        $flusher->flush();

        /** @var OpenWatchSendBatchJob $job */
        $job = $dispatchCapture->dispatched[0];
        $this->assertSame('redis', $job->connection);
        $this->assertSame('openwatch', $job->queue);
    }

    // -------------------------------------------------------------------------
    // Scenario: Async flush with empty buffer does not dispatch
    // -------------------------------------------------------------------------

    public function test_async_flush_empty_buffer_does_not_dispatch(): void
    {
        [$transport] = $this->spyTransport();
        [$dispatcher, $dispatchCapture] = $this->spyDispatcher();

        $buffer  = new EventBuffer();
        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: true,
            asyncEnabled:    true,
            dispatcher:      $dispatcher,
        );

        $flusher->flush();

        $this->assertEmpty($dispatchCapture->dispatched);
    }

    // -------------------------------------------------------------------------
    // Scenario: Async enabled but batching off — no dispatch, no send
    // -------------------------------------------------------------------------

    public function test_async_ignored_when_batching_disabled(): void
    {
        [$transport, $transportCapture] = $this->spyTransport();
        [$dispatcher, $dispatchCapture] = $this->spyDispatcher();

        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: false,
            asyncEnabled:    true,
            dispatcher:      $dispatcher,
        );

        $flusher->flush();

        $this->assertEmpty($dispatchCapture->dispatched);
        $this->assertEmpty($transportCapture->batches);
    }

    // -------------------------------------------------------------------------
    // Scenario: Fallback sync if dispatch throws
    // -------------------------------------------------------------------------

    public function test_async_flush_falls_back_to_sync_if_dispatch_throws(): void
    {
        [$transport, $transportCapture] = $this->spyTransport();

        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query', 'sql' => 'select 1']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: true,
            asyncEnabled:    true,
            dispatcher:      $this->throwingDispatcher(),
        );

        $flusher->flush();

        // Fallback sync send must have happened
        $this->assertCount(1, $transportCapture->batches);
    }

    public function test_async_flush_fallback_does_not_throw_to_caller(): void
    {
        [$transport] = $this->spyTransport();

        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: true,
            asyncEnabled:    true,
            dispatcher:      $this->throwingDispatcher(),
        );

        // Must not throw
        $this->expectNotToPerformAssertions();
        $flusher->flush();
    }
}
