<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Recorders\RequestRecorder;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class RequestRecorderTest extends TestCase
{
    /**
     * @return array{RequestRecorder, object}
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

        return [new RequestRecorder($transport), $capture];
    }

    public function test_payload_has_type_request(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/test', 'GET');
        $response = new Response('OK', 200);

        $recorder->record($request, $response, microtime(true));

        $this->assertSame('request', $capture->payloads[0]['type']);
    }

    public function test_payload_includes_method_path_status_duration_occurred_at(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/hello', 'POST');
        $response = new Response('', 201);

        $recorder->record($request, $response, microtime(true));

        $payload = $capture->payloads[0];
        $this->assertSame('POST', $payload['method']);
        $this->assertSame('/hello', $payload['path']);
        $this->assertSame(201, $payload['status']);
        $this->assertArrayHasKey('duration_ms', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    // -------------------------------------------------------------------------
    // Canonical timestamp (event time, not send time)
    // -------------------------------------------------------------------------

    public function test_occurred_at_is_valid_iso8601(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        $recorder->record($request, $response, microtime(true));

        $value = $capture->payloads[0]['occurred_at'];
        $this->assertIsString($value);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $value,
            'occurred_at must be an ISO-8601 string with timezone offset',
        );
    }

    public function test_occurred_at_reflects_start_time_not_send_time(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        // Capture a start time that is clearly in the past (1 second ago)
        $startTime = microtime(true) - 1.0;

        $recorder->record($request, $response, $startTime);

        $occurred = new \DateTimeImmutable($capture->payloads[0]['occurred_at']);
        $now      = new \DateTimeImmutable();

        // occurred_at must be before now (it was captured 1 second ago)
        $this->assertLessThan(
            $now->getTimestamp(),
            $occurred->getTimestamp(),
            'occurred_at should be at the event start time, not the current (send) time',
        );
    }

    // -------------------------------------------------------------------------
    // Nested timestamp ordering (started_at / finished_at consistency)
    // -------------------------------------------------------------------------

    public function test_duration_ms_is_non_negative(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        $recorder->record($request, $response, microtime(true));

        $this->assertGreaterThanOrEqual(0.0, $capture->payloads[0]['duration_ms']);
    }

    // -------------------------------------------------------------------------
    // Nested timestamp ordering (started_at / finished_at)
    // -------------------------------------------------------------------------

    public function test_payload_contains_started_at_and_finished_at(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        $recorder->record($request, $response, microtime(true));

        $this->assertArrayHasKey('started_at', $capture->payloads[0]);
        $this->assertArrayHasKey('finished_at', $capture->payloads[0]);
    }

    public function test_started_at_is_valid_iso8601(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        $recorder->record($request, $response, microtime(true));

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $capture->payloads[0]['started_at'],
        );
    }

    public function test_finished_at_is_valid_iso8601(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        $recorder->record($request, $response, microtime(true));

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $capture->payloads[0]['finished_at'],
        );
    }

    public function test_started_at_lte_finished_at(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        $recorder->record($request, $response, microtime(true));

        $started  = new \DateTimeImmutable($capture->payloads[0]['started_at']);
        $finished = new \DateTimeImmutable($capture->payloads[0]['finished_at']);

        $this->assertLessThanOrEqual(
            $finished->getTimestamp(),
            $started->getTimestamp(),
            'started_at must be <= finished_at',
        );
    }

    public function test_started_at_and_finished_at_share_same_timezone_offset(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        $recorder->record($request, $response, microtime(true));

        $started  = new \DateTimeImmutable($capture->payloads[0]['started_at']);
        $finished = new \DateTimeImmutable($capture->payloads[0]['finished_at']);
        $occurred = new \DateTimeImmutable($capture->payloads[0]['occurred_at']);

        $this->assertSame(
            $started->getOffset(),
            $finished->getOffset(),
            'started_at and finished_at must share the same timezone offset',
        );
        $this->assertSame(
            $started->getOffset(),
            $occurred->getOffset(),
            'started_at and occurred_at must share the same timezone offset',
        );
    }

    public function test_started_at_equals_occurred_at(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/', 'GET');
        $response = new Response('', 200);

        $startTime = microtime(true) - 1.0;
        $recorder->record($request, $response, $startTime);

        // Both occurred_at and started_at must reflect the same captured start time
        $this->assertSame(
            $capture->payloads[0]['occurred_at'],
            $capture->payloads[0]['started_at'],
            'occurred_at and started_at must reflect the same event start time',
        );
    }
}
