<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Recorders\OutgoingRequestRecorder;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

class OutgoingRequestRecorderTest extends TestCase
{
    /**
     * @return array{OutgoingRequestRecorder, object}
     */
    private function makeRecorderWithCapture(): array
    {
        $capture = new class {
            public array $payloads = [];
        };

        $transport = new class($capture) extends HttpTransport {
            public function __construct(private readonly object $capture)
            {
                // no parent call — override send()
            }

            public function send(array $payload): bool
            {
                $this->capture->payloads[] = $payload;
                return true;
            }
        };

        return [new OutgoingRequestRecorder($transport), $capture];
    }

    public function test_payload_has_type_outgoing_request(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record('GET', 'https://api.example.com/users', 200, 50.0);

        $this->assertSame('outgoing_request', $capture->payloads[0]['type']);
    }

    public function test_payload_includes_method_host_path_status_duration_occurred_at(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record('POST', 'https://api.example.com/events', 201, 123.45);

        $payload = $capture->payloads[0];
        $this->assertSame('POST', $payload['method']);
        $this->assertSame('api.example.com', $payload['host']);
        $this->assertSame('/events', $payload['path']);
        $this->assertSame(201, $payload['status']);
        $this->assertSame(123.45, $payload['duration_ms']);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    // -------------------------------------------------------------------------
    // Canonical timestamp (event time, not send time)
    // -------------------------------------------------------------------------

    public function test_occurred_at_is_valid_iso8601(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record('GET', 'https://api.example.com/health', 200, 10.0);

        $value = $capture->payloads[0]['occurred_at'];
        $this->assertIsString($value);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $value,
            'occurred_at must be an ISO-8601 string with timezone offset',
        );
    }

    public function test_occurred_at_reflects_start_time_when_provided(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();

        // Start time 1 second in the past
        $startTime = microtime(true) - 1.0;
        $recorder->record('GET', 'https://api.example.com/', 200, 50.0, null, $startTime);

        $occurred = new \DateTimeImmutable($capture->payloads[0]['occurred_at']);
        $now      = new \DateTimeImmutable();

        $this->assertLessThan(
            $now->getTimestamp(),
            $occurred->getTimestamp(),
            'occurred_at should be at the event start time, not the current (send) time',
        );
    }

    public function test_threshold_skips_fast_requests(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record('GET', 'https://api.example.com/', 200, 30.0, 100.0);

        $this->assertEmpty($capture->payloads);
    }

    public function test_threshold_sends_slow_requests(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record('GET', 'https://api.example.com/', 200, 200.0, 100.0);

        $this->assertCount(1, $capture->payloads);
    }

    public function test_fails_silently_on_transport_error(): void
    {
        $transport = new class extends HttpTransport {
            public function __construct() {}
            public function send(array $payload): bool
            {
                throw new \RuntimeException('network failure');
            }
        };

        $recorder = new OutgoingRequestRecorder($transport);

        $this->expectNotToPerformAssertions();
        $recorder->record('GET', 'https://api.example.com/', 200, 50.0);
    }

    public function test_query_string_is_stripped_from_url(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record('GET', 'https://api.example.com/users?token=secret&page=2', 200, 10.0);

        $payload = $capture->payloads[0];
        $this->assertSame('/users', $payload['path']);
        $this->assertStringNotContainsString('secret', $payload['url']);
    }

    // -------------------------------------------------------------------------
    // Ignored hosts (Phase 3)
    // -------------------------------------------------------------------------

    private function makeRecorderWithIgnoredHosts(array $ignoredHosts): array
    {
        $capture = new class {
            public array $payloads = [];
        };

        $transport = new class($capture) extends HttpTransport {
            public function __construct(private readonly object $capture)
            {
                // no parent call — override send()
            }

            public function send(array $payload): bool
            {
                $this->capture->payloads[] = $payload;
                return true;
            }
        };

        return [new OutgoingRequestRecorder($transport, $ignoredHosts), $capture];
    }

    public function test_ignored_host_is_skipped(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithIgnoredHosts(['openwatch.example.com']);
        $recorder->record('POST', 'https://openwatch.example.com/api/ingest', 202, 30.0);

        $this->assertEmpty($capture->payloads, 'Request to ignored host must not be recorded');
    }

    public function test_non_ignored_host_is_recorded(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithIgnoredHosts(['openwatch.example.com']);
        $recorder->record('GET', 'https://api.external.com/users', 200, 50.0);

        $this->assertCount(1, $capture->payloads);
    }

    public function test_ignored_host_matching_is_case_insensitive(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithIgnoredHosts(['OpenWatch.Example.COM']);
        $recorder->record('GET', 'https://openwatch.example.com/api/ingest', 200, 10.0);

        $this->assertEmpty($capture->payloads);
    }

    public function test_empty_ignored_hosts_list_records_everything(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithIgnoredHosts([]);
        $recorder->record('GET', 'https://openwatch.example.com/api/ingest', 200, 10.0);

        $this->assertCount(1, $capture->payloads);
    }

    public function test_ignored_host_with_port_matches_url_with_same_port(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithIgnoredHosts(['openwatch.example.com:8080']);
        $recorder->record('POST', 'https://openwatch.example.com:8080/api/ingest', 202, 20.0);

        $this->assertEmpty($capture->payloads);
    }
}
