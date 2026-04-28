<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Recorders\OutgoingRequestRecorder;
use Dorvianes\OpenWatch\Recorders\QueryRecorder;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for recorder → buffer wiring under feature flag.
 *
 * When batching is ENABLED:
 *   - record() pushes into EventBuffer instead of calling transport.send()
 *
 * When batching is DISABLED (default):
 *   - record() still calls transport.send() immediately (existing behavior preserved)
 */
class RecorderBatchingTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Spy transport that captures send() calls */
    private function spyTransport(): object
    {
        $capture = new class {
            public array $payloads = [];
        };

        $transport = new class($capture) extends HttpTransport {
            public function __construct(public readonly object $capture)
            {
                // bypass parent constructor
            }

            public function send(array $payload): bool
            {
                $this->capture->payloads[] = $payload;
                return true;
            }
        };

        return (object) ['transport' => $transport, 'capture' => $capture];
    }

    private function fakeQueryEvent(string $sql = 'select 1', float $time = 10.0, string $conn = 'mysql'): object
    {
        return new class($sql, $time, $conn) {
            public string $sql;
            public float  $time;
            public string $connectionName;

            public function __construct(string $sql, float $time, string $conn)
            {
                $this->sql            = $sql;
                $this->time           = $time;
                $this->connectionName = $conn;
            }
        };
    }

    // -------------------------------------------------------------------------
    // QueryRecorder — batching ENABLED
    // -------------------------------------------------------------------------

    public function test_query_recorder_pushes_to_buffer_when_batching_enabled(): void
    {
        $spy    = $this->spyTransport();
        $buffer = new EventBuffer();

        $recorder = new QueryRecorder($spy->transport, buffer: $buffer, batchingEnabled: true);
        $recorder->record($this->fakeQueryEvent('select * from users', 25.0));

        // Event must be in buffer
        $this->assertSame(1, $buffer->count());
        $this->assertSame('query', $buffer->all()[0]['type']);

        // Transport must NOT have been called
        $this->assertEmpty($spy->capture->payloads);
    }

    public function test_query_recorder_does_not_push_to_buffer_when_batching_disabled(): void
    {
        $spy    = $this->spyTransport();
        $buffer = new EventBuffer();

        $recorder = new QueryRecorder($spy->transport, buffer: $buffer, batchingEnabled: false);
        $recorder->record($this->fakeQueryEvent());

        // Buffer must remain empty
        $this->assertTrue($buffer->isEmpty());

        // Transport must have been called directly
        $this->assertCount(1, $spy->capture->payloads);
    }

    public function test_query_recorder_defaults_to_synchronous_without_buffer(): void
    {
        // Original constructor signature (no buffer, no flag) → synchronous
        $spy      = $this->spyTransport();
        $recorder = new QueryRecorder($spy->transport);
        $recorder->record($this->fakeQueryEvent());

        $this->assertCount(1, $spy->capture->payloads);
    }

    // -------------------------------------------------------------------------
    // QueryRecorder — threshold filter still applies when batching is enabled
    // -------------------------------------------------------------------------

    public function test_query_recorder_applies_threshold_before_buffering(): void
    {
        $spy    = $this->spyTransport();
        $buffer = new EventBuffer();

        $recorder = new QueryRecorder($spy->transport, buffer: $buffer, batchingEnabled: true);
        // threshold 100ms, query is only 50ms — should be skipped entirely
        $recorder->record($this->fakeQueryEvent('select 1', 50.0), thresholdMs: 100.0);

        $this->assertTrue($buffer->isEmpty());
        $this->assertEmpty($spy->capture->payloads);
    }

    // -------------------------------------------------------------------------
    // OutgoingRequestRecorder — batching ENABLED
    // -------------------------------------------------------------------------

    public function test_outgoing_recorder_pushes_to_buffer_when_batching_enabled(): void
    {
        $spy    = $this->spyTransport();
        $buffer = new EventBuffer();

        $recorder = new OutgoingRequestRecorder(
            $spy->transport,
            ignoredHosts:    [],
            buffer:          $buffer,
            batchingEnabled: true,
        );

        $recorder->record('GET', 'https://api.example.com/users', 200, 45.0);

        $this->assertSame(1, $buffer->count());
        $this->assertSame('outgoing_request', $buffer->all()[0]['type']);
        $this->assertEmpty($spy->capture->payloads);
    }

    public function test_outgoing_recorder_sends_synchronously_when_batching_disabled(): void
    {
        $spy    = $this->spyTransport();
        $buffer = new EventBuffer();

        $recorder = new OutgoingRequestRecorder(
            $spy->transport,
            ignoredHosts:    [],
            buffer:          $buffer,
            batchingEnabled: false,
        );

        $recorder->record('GET', 'https://api.example.com/users', 200, 45.0);

        $this->assertTrue($buffer->isEmpty());
        $this->assertCount(1, $spy->capture->payloads);
    }

    public function test_outgoing_recorder_defaults_to_synchronous_without_buffer(): void
    {
        $spy      = $this->spyTransport();
        $recorder = new OutgoingRequestRecorder($spy->transport);
        $recorder->record('POST', 'https://api.example.com/orders', 201, 120.0);

        $this->assertCount(1, $spy->capture->payloads);
    }

    // -------------------------------------------------------------------------
    // Ignored host filter still applies when batching is enabled
    // -------------------------------------------------------------------------

    public function test_outgoing_recorder_ignores_host_even_when_batching_enabled(): void
    {
        $spy    = $this->spyTransport();
        $buffer = new EventBuffer();

        $recorder = new OutgoingRequestRecorder(
            $spy->transport,
            ignoredHosts:    ['api.example.com'],
            buffer:          $buffer,
            batchingEnabled: true,
        );

        $recorder->record('GET', 'https://api.example.com/data', 200, 30.0);

        $this->assertTrue($buffer->isEmpty());
        $this->assertEmpty($spy->capture->payloads);
    }
}
