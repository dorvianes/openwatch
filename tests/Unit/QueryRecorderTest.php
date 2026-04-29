<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Recorders\QueryRecorder;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

class QueryRecorderTest extends TestCase
{
    /**
     * @return array{QueryRecorder, object}
     */
    private function makeRecorderWithCapture(): array
    {
        $capture = new class {
            public array $payloads = [];
        };

        $transport = new class($capture) extends HttpTransport {
            public function __construct(private readonly object $capture)
            {
                // no parent call — we override send() entirely
            }

            public function send(array $payload): bool
            {
                $this->capture->payloads[] = $payload;
                return true;
            }
        };

        return [new QueryRecorder($transport), $capture];
    }

    /**
     * Build a minimal QueryExecuted-like anonymous object that mimics the
     * public properties the recorder accesses (sql, connectionName, time).
     * We cannot use the real QueryExecuted in a pure unit test context because
     * illuminate/database is not required by the package — only illuminate/support is.
     */
    private function fakeQueryEvent(string $sql = 'select * from users', float $time = 12.5, string $connection = 'mysql'): object
    {
        return new class($sql, $time, $connection) {
            public string $sql;
            public string $connectionName;
            public float  $time;

            public function __construct(string $sql, float $time, string $connection)
            {
                $this->sql            = $sql;
                $this->time           = $time;
                $this->connectionName = $connection;
            }
        };
    }

    public function test_payload_has_type_query(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record($this->fakeQueryEvent());

        $this->assertSame('query', $capture->payloads[0]['type']);
    }

    public function test_payload_preserves_flat_wire_contract(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record($this->fakeQueryEvent());

        $payload = $capture->payloads[0];

        $this->assertSame('query', $payload['type']);
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('app_name', $payload['meta']);
        $this->assertArrayHasKey('app_env', $payload['meta']);
        $this->assertFlatEventHasNoDeferredEnvelopeKeys($payload);
    }

    public function test_payload_includes_sql_connection_duration_occurred_at(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record($this->fakeQueryEvent('select 1', 33.5, 'pgsql'));

        $payload = $capture->payloads[0];
        $this->assertSame('select 1', $payload['sql']);
        $this->assertSame('pgsql', $payload['connection']);
        $this->assertSame(33.5, $payload['duration_ms']);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function test_payload_does_not_include_bindings(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record($this->fakeQueryEvent());

        $payload = $capture->payloads[0];
        $this->assertArrayNotHasKey('bindings', $payload);
    }

    public function test_payload_includes_meta_keys(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record($this->fakeQueryEvent());

        $payload = $capture->payloads[0];
        $this->assertArrayHasKey('meta', $payload);
    }

    public function test_slow_query_threshold_skips_fast_queries(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        // Threshold 100ms, query takes 50ms → should NOT send
        $recorder->record($this->fakeQueryEvent('select 1', 50.0), 100.0);

        $this->assertEmpty($capture->payloads);
    }

    public function test_slow_query_threshold_sends_slow_queries(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        // Threshold 100ms, query takes 200ms → should send
        $recorder->record($this->fakeQueryEvent('select * from orders', 200.0), 100.0);

        $this->assertCount(1, $capture->payloads);
        $this->assertSame('query', $capture->payloads[0]['type']);
    }

    public function test_no_threshold_sends_all_queries(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record($this->fakeQueryEvent('select 1', 1.0), null);

        $this->assertCount(1, $capture->payloads);
    }

    public function test_fails_silently_on_transport_error(): void
    {
        $transport = new class extends HttpTransport {
            public function __construct() {}
            public function send(array $payload): bool
            {
                throw new \RuntimeException('network error');
            }
        };

        $recorder = new QueryRecorder($transport);

        // Must not throw
        $this->expectNotToPerformAssertions();
        $recorder->record($this->fakeQueryEvent());
    }

    // -------------------------------------------------------------------------
    // SQL normalization — sync path (task 2.1)
    // -------------------------------------------------------------------------

    public function test_sync_payload_sql_is_normalized(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();

        $noisySql = "select  *\n from\tusers where id in (?, ?, ?)";
        $recorder->record($this->fakeQueryEvent($noisySql));

        $this->assertSame('select * from users where id in (?+)', $capture->payloads[0]['sql']);
    }

    public function test_sync_payload_sql_collapses_whitespace(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();

        $recorder->record($this->fakeQueryEvent("  SELECT  1  "));

        $this->assertSame('SELECT 1', $capture->payloads[0]['sql']);
    }

    // -------------------------------------------------------------------------
    // SQL normalization — batched path (task 2.1)
    // -------------------------------------------------------------------------

    public function test_batched_payload_sql_is_normalized(): void
    {
        $spy = new class {
            public array $payloads = [];
        };
        $transport = new class($spy) extends HttpTransport {
            public function __construct(private readonly object $spy) {}
            public function send(array $payload): bool
            {
                $this->spy->payloads[] = $payload;
                return true;
            }
        };

        $buffer   = new EventBuffer();
        $recorder = new QueryRecorder($transport, buffer: $buffer, batchingEnabled: true);

        $noisySql = "select  *\n from orders where id in (?, ?, ?)";
        $recorder->record($this->fakeQueryEvent($noisySql));

        $buffered = $buffer->all();
        $this->assertCount(1, $buffered);
        $this->assertSame('select * from orders where id in (?+)', $buffered[0]['sql']);
    }

    // -------------------------------------------------------------------------
    // Fail-silent contract — normalization failure (gap lote-2)
    // -------------------------------------------------------------------------

    public function test_fails_silently_on_normalizer_error(): void
    {
        $transport = new class extends HttpTransport {
            public function __construct() {}
            public function send(array $payload): bool
            {
                return true;
            }
        };

        // Subclass that forces the normalizer step to throw
        $recorder = new class($transport) extends QueryRecorder {
            protected function normalizeSql(string $sql): string
            {
                throw new \RuntimeException('normalizer exploded');
            }
        };

        // Must not throw — fail-silent contract covers normalizer failures too
        $this->expectNotToPerformAssertions();
        $recorder->record($this->fakeQueryEvent());
    }

    // -------------------------------------------------------------------------
    // Canonical timestamp (task 4.1)
    // -------------------------------------------------------------------------

    public function test_occurred_at_is_valid_iso8601(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record($this->fakeQueryEvent());

        $value = $capture->payloads[0]['occurred_at'];
        $this->assertIsString($value);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $value,
            'occurred_at must be an ISO-8601 string with timezone offset',
        );
    }

    private function assertFlatEventHasNoDeferredEnvelopeKeys(array $payload): void
    {
        foreach (['id', 'payload', 'context', 'schema_version'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $payload);
        }
    }
}
