<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Jobs\OpenWatchSendBatchJob;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

/**
 * Task 3.1 — OpenWatchSendBatchJob handle() behavior.
 *
 * Design decisions:
 * - Implements ShouldQueue so a host Laravel app serializes and enqueues it.
 * - Uses Queueable trait for onConnection()/onQueue() fluent API.
 * - handle() calls transport->sendBatch($events)
 * - handle() swallows transport exceptions silently
 * - connection and queue properties are set correctly
 */
class OpenWatchSendBatchJobTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function spyTransport(bool $shouldThrow = false): array
    {
        $capture = new class {
            public array $batches = [];
        };

        $transport = new class($capture, $shouldThrow) extends HttpTransport {
            public function __construct(
                public readonly object $capture,
                public readonly bool   $shouldThrow,
            ) {
                // bypass parent
            }

            public function sendBatch(array $events): bool
            {
                if ($this->shouldThrow) {
                    throw new \RuntimeException('Transport failure');
                }
                $this->capture->batches[] = $events;
                return true;
            }
        };

        return [$transport, $capture];
    }

    // -------------------------------------------------------------------------
    // Scenario: Job implements ShouldQueue (Laravel queue wiring contract)
    // -------------------------------------------------------------------------

    public function test_job_implements_should_queue_interface(): void
    {
        $job = new OpenWatchSendBatchJob([]);

        $this->assertInstanceOf(
            ShouldQueue::class,
            $job,
            'OpenWatchSendBatchJob must implement ShouldQueue so a host Laravel app ' .
            'serializes and dispatches it via the configured connection/queue.',
        );
    }

    // -------------------------------------------------------------------------
    // Scenario: Job calls sendBatch with the events it was constructed with
    // -------------------------------------------------------------------------

    public function test_handle_calls_send_batch_with_buffered_events(): void
    {
        [$transport, $capture] = $this->spyTransport();

        $events = [
            ['type' => 'query', 'sql' => 'select 1'],
            ['type' => 'outgoing_request', 'host' => 'api.test'],
        ];

        $job = new OpenWatchSendBatchJob($events);
        $job->handle($transport);

        $this->assertCount(1, $capture->batches);
        $this->assertSame($events, $capture->batches[0]);
    }

    public function test_handle_calls_send_batch_once_per_invocation(): void
    {
        [$transport, $capture] = $this->spyTransport();

        $job = new OpenWatchSendBatchJob([['type' => 'query']]);
        $job->handle($transport);
        $job->handle($transport);

        // Each handle() call sends once — two calls = two batches
        $this->assertCount(2, $capture->batches);
    }

    // -------------------------------------------------------------------------
    // Scenario: Job swallows transport failures — must not rethrow
    // -------------------------------------------------------------------------

    public function test_handle_swallows_transport_exception(): void
    {
        [$transport] = $this->spyTransport(shouldThrow: true);

        $job = new OpenWatchSendBatchJob([['type' => 'query']]);

        // Must not throw to the caller
        $this->expectNotToPerformAssertions();
        $job->handle($transport);
    }

    // -------------------------------------------------------------------------
    // Scenario: Job carries the correct connection and queue properties
    // -------------------------------------------------------------------------

    public function test_job_stores_events_as_public_property(): void
    {
        $events = [['type' => 'query', 'sql' => 'select users']];
        $job    = new OpenWatchSendBatchJob($events);

        $this->assertSame($events, $job->events);
    }

    public function test_on_connection_sets_connection_property(): void
    {
        $job = (new OpenWatchSendBatchJob([]))->onConnection('redis');

        $this->assertSame('redis', $job->connection);
    }

    public function test_on_queue_sets_queue_property(): void
    {
        $job = (new OpenWatchSendBatchJob([]))->onQueue('openwatch');

        $this->assertSame('openwatch', $job->queue);
    }

    public function test_on_connection_defaults_to_null(): void
    {
        $job = new OpenWatchSendBatchJob([]);

        $this->assertNull($job->connection);
    }

    public function test_on_queue_defaults_to_null(): void
    {
        $job = new OpenWatchSendBatchJob([]);

        $this->assertNull($job->queue);
    }
}
