<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Recorders\ExceptionRecorder;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExceptionRecorderTest extends TestCase
{
    private function makeRequest(string $uri = '/test/path', string $method = 'GET'): Request
    {
        return Request::create('http://localhost' . $uri, $method);
    }

    /**
     * @return array{ExceptionRecorder, object}
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

        return [new ExceptionRecorder($transport), $capture];
    }

    public function test_payload_has_type_exception(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('boom'), $this->makeRequest());

        $this->assertSame('exception', $capture->payloads[0]['type']);
    }

    public function test_payload_includes_class_message_file_line(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('test message'), $this->makeRequest());

        $payload = $capture->payloads[0];
        $this->assertSame(RuntimeException::class, $payload['class']);
        $this->assertSame('test message', $payload['message']);
        $this->assertArrayHasKey('file', $payload);
        $this->assertArrayHasKey('line', $payload);
    }

    public function test_message_is_truncated_to_500_chars(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException(str_repeat('x', 1000)), $this->makeRequest());

        $this->assertSame(500, mb_strlen($capture->payloads[0]['message']));
    }

    public function test_message_shorter_than_500_chars_is_not_altered(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('short'), $this->makeRequest());

        $this->assertSame('short', $capture->payloads[0]['message']);
    }

    public function test_payload_previous_is_null_when_no_chained_exception(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('oops'), $this->makeRequest());

        // 'previous' key exists but is null when there is no chained exception
        $this->assertArrayHasKey('previous', $capture->payloads[0]);
        $this->assertNull($capture->payloads[0]['previous']);
    }

    public function test_payload_includes_trace_as_array(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('oops'), $this->makeRequest());

        $payload = $capture->payloads[0];
        $this->assertArrayHasKey('trace', $payload);
        $this->assertIsArray($payload['trace']);
    }

    public function test_trace_frames_are_capped_at_30(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('deep'), $this->makeRequest());

        $this->assertLessThanOrEqual(30, count($capture->payloads[0]['trace']));
    }

    public function test_trace_frames_contain_expected_keys(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('frame check'), $this->makeRequest());

        $frames = $capture->payloads[0]['trace'];
        if (! empty($frames)) {
            // Frames with a file always have file key; function is nearly always present
            $firstFrame = $frames[0];
            $this->assertTrue(
                array_key_exists('file', $firstFrame) || array_key_exists('function', $firstFrame),
                'At least file or function should be present in a trace frame',
            );
        } else {
            $this->markTestSkipped('Trace was empty — not enough stack depth in this context.');
        }
    }

    public function test_request_context_includes_path_method_url_ip(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(
            new RuntimeException('oops'),
            $this->makeRequest('/openwatch/scenarios/exception', 'GET'),
        );

        $req = $capture->payloads[0]['request'];
        $this->assertSame('/openwatch/scenarios/exception', $req['path']);
        $this->assertSame('GET', $req['method']);
        $this->assertArrayHasKey('url', $req);
        $this->assertArrayHasKey('ip', $req);
    }

    public function test_snippet_key_is_present_or_null(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('snippet'), $this->makeRequest());

        // snippet may be null (file may not be readable in test context) but key must exist
        $this->assertArrayHasKey('snippet', $capture->payloads[0]);
    }

    // -------------------------------------------------------------------------
    // Null-request support
    // -------------------------------------------------------------------------

    public function test_record_accepts_null_request_without_crashing(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('no request'), null);

        $this->assertCount(1, $capture->payloads);
    }

    public function test_payload_request_is_null_when_no_request_given(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('no request'), null);

        $this->assertArrayHasKey('request', $capture->payloads[0]);
        $this->assertNull($capture->payloads[0]['request']);
    }

    public function test_payload_type_is_exception_even_with_null_request(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('no request'), null);

        $this->assertSame('exception', $capture->payloads[0]['type']);
    }

    // -------------------------------------------------------------------------
    // Canonical timestamp (task 4.1)
    // -------------------------------------------------------------------------

    public function test_payload_has_occurred_at_key(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('ts'), $this->makeRequest());

        $this->assertArrayHasKey('occurred_at', $capture->payloads[0]);
    }

    public function test_occurred_at_is_valid_iso8601(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $recorder->record(new RuntimeException('ts'), $this->makeRequest());

        $value = $capture->payloads[0]['occurred_at'];
        $this->assertIsString($value);
        // ISO-8601 with timezone offset: YYYY-MM-DDTHH:MM:SS+00:00
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $value,
            'occurred_at must be an ISO-8601 string with timezone offset',
        );
    }

    public function test_occurred_at_reflects_event_time_not_send_time(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();

        $before = time();
        $recorder->record(new RuntimeException('timing'), $this->makeRequest());
        $after = time();

        $occurred = (new \DateTimeImmutable($capture->payloads[0]['occurred_at']))->getTimestamp();

        // occurred_at must fall within the window of the record() call
        $this->assertGreaterThanOrEqual($before, $occurred, 'occurred_at is before the call started');
        $this->assertLessThanOrEqual($after + 1, $occurred, 'occurred_at is after the call finished');
    }
}
