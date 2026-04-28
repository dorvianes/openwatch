<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for flush lifecycle:
 * - RecordRequest::terminate() flushes the buffer via sendBatch()
 * - App terminating hook also flushes
 * - Double flush is a no-op (idempotent)
 * - Feature flag gate: flush does nothing when batching is disabled
 */
class FlushLifecycleTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Spy transport for batch send tracking
    // -------------------------------------------------------------------------

    /** Returns [transport, capture] */
    private function spyTransport(): array
    {
        $capture = new class {
            public array $batches = [];
            public array $singles = [];
        };

        $transport = new class($capture) extends HttpTransport {
            public function __construct(public readonly object $capture)
            {
                // bypass parent
            }

            public function send(array $payload): bool
            {
                $this->capture->singles[] = $payload;
                return true;
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

    // -------------------------------------------------------------------------
    // BatchFlusher — core flush logic
    // -------------------------------------------------------------------------

    public function test_flush_sends_buffered_events_via_send_batch(): void
    {
        [$transport, $capture] = $this->spyTransport();
        $buffer  = new EventBuffer();

        $buffer->push(['type' => 'query', 'sql' => 'select 1']);
        $buffer->push(['type' => 'outgoing_request', 'host' => 'api.example.com']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher($transport, $buffer);
        $flusher->flush();

        $this->assertCount(1, $capture->batches);
        $this->assertCount(2, $capture->batches[0]);
    }

    public function test_flush_clears_buffer_after_send(): void
    {
        [$transport] = $this->spyTransport();
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher($transport, $buffer);
        $flusher->flush();

        $this->assertTrue($buffer->isEmpty());
    }

    public function test_flush_on_empty_buffer_is_noop_no_batch_sent(): void
    {
        [$transport, $capture] = $this->spyTransport();
        $buffer = new EventBuffer();

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher($transport, $buffer);
        $flusher->flush();

        $this->assertEmpty($capture->batches);
    }

    public function test_double_flush_second_call_sends_nothing(): void
    {
        [$transport, $capture] = $this->spyTransport();
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher($transport, $buffer);
        $flusher->flush(); // first — sends 1 batch
        $flusher->flush(); // second — buffer is empty, should be no-op

        $this->assertCount(1, $capture->batches);
    }

    public function test_flush_when_batching_disabled_is_noop(): void
    {
        [$transport, $capture] = $this->spyTransport();
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);

        // BatchFlusher with batching disabled — flush should do nothing
        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher(
            $transport,
            $buffer,
            batchingEnabled: false,
        );
        $flusher->flush();

        // Buffer not cleared, nothing sent
        $this->assertFalse($buffer->isEmpty());
        $this->assertEmpty($capture->batches);
    }

    // -------------------------------------------------------------------------
    // RecordRequest middleware — terminate triggers flush
    // -------------------------------------------------------------------------

    public function test_record_request_terminate_flushes_buffer(): void
    {
        [$transport, $capture] = $this->spyTransport();
        $buffer = new EventBuffer();
        $buffer->push(['type' => 'query']);
        $buffer->push(['type' => 'outgoing_request', 'host' => 'api.test']);

        $flusher = new \Dorvianes\OpenWatch\Support\BatchFlusher($transport, $buffer, batchingEnabled: true);

        // RecordRequest terminate now accepts an optional flusher
        $recorder = new \Dorvianes\OpenWatch\Recorders\RequestRecorder($transport);
        $middleware = new \Dorvianes\OpenWatch\Middleware\RecordRequest($recorder, $flusher);

        $request  = new \Illuminate\Http\Request();
        $response = new \Symfony\Component\HttpFoundation\Response();

        $middleware->terminate($request, $response);

        $this->assertCount(1, $capture->batches);
        $this->assertTrue($buffer->isEmpty());
    }
}
