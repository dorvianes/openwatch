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

    public function test_payload_preserves_flat_wire_contract(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/test', 'GET');
        $response = new Response('OK', 200);

        $recorder->record($request, $response, microtime(true));

        $payload = $capture->payloads[0];

        $this->assertSame('request', $payload['type']);
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('app_name', $payload['meta']);
        $this->assertArrayHasKey('app_env', $payload['meta']);
        $this->assertFlatEventHasNoDeferredEnvelopeKeys($payload);
    }

    public function test_normal_request_does_not_include_livewire_metadata(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request  = Request::create('http://localhost/dashboard', 'GET');
        $response = new Response('OK', 200);

        $recorder->record($request, $response, microtime(true));

        $payload = $capture->payloads[0];
        $this->assertSame('request', $payload['type']);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayNotHasKey('livewire', $payload['meta']);
    }

    public function test_livewire_request_adds_safe_metadata_under_meta(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request = Request::create(
            'http://localhost/livewire/update',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'],
            json_encode([
                'components' => [
                    [
                        'snapshot' => json_encode([
                            'memo' => ['id' => 'cmp-123', 'name' => 'dashboard.stats'],
                            'checksum' => 'checksum-value',
                        ]),
                        'updates' => ['search' => 'openwatch', 'filters.status' => 'active'],
                        'calls' => [
                            ['method' => 'refreshStats', 'params' => ['secret-token']],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );
        $response = new Response(json_encode(['components' => []], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);

        $recorder->record($request, $response, microtime(true));

        $livewire = $capture->payloads[0]['meta']['livewire'];

        $this->assertSame(true, $livewire['detected']);
        $this->assertSame('/livewire/update', $livewire['endpoint']);
        $this->assertSame(1, $livewire['component_count']);
        $this->assertSame([
            ['name' => 'dashboard.stats', 'id' => 'cmp-123'],
        ], $livewire['components']);
        $this->assertSame(['refreshStats'], $livewire['calls']);
        $this->assertSame(2, $livewire['updates_count']);
        $this->assertSame(['search', 'filters.status'], $livewire['update_keys']);
    }

    public function test_livewire_metadata_does_not_leak_sensitive_payload_values(): void
    {
        [$recorder, $capture] = $this->makeRecorderWithCapture();
        $request = Request::create(
            'http://localhost/livewire/message/profile.editor',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'fingerprint' => ['id' => 'legacy-id', 'name' => 'profile.editor'],
                'serverMemo' => ['data' => ['csrf_token' => 'csrf-secret', 'password' => 'plain-secret']],
                'updates' => [
                    ['type' => 'callMethod', 'payload' => ['method' => 'save', 'params' => ['top-secret-param']]],
                    ['type' => 'syncInput', 'payload' => ['name' => 'password', 'value' => 'plain-secret']],
                ],
                '_token' => 'csrf-secret',
            ], JSON_THROW_ON_ERROR),
        );
        $response = new Response('<div>full html should not be captured</div>', 200);

        $recorder->record($request, $response, microtime(true));

        $encodedLivewireMetadata = json_encode($capture->payloads[0]['meta']['livewire'], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('csrf-secret', $encodedLivewireMetadata);
        $this->assertStringNotContainsString('plain-secret', $encodedLivewireMetadata);
        $this->assertStringNotContainsString('top-secret-param', $encodedLivewireMetadata);
        $this->assertStringNotContainsString('full html should not be captured', $encodedLivewireMetadata);
        $this->assertSame(['save'], $capture->payloads[0]['meta']['livewire']['calls']);
        $this->assertSame(1, $capture->payloads[0]['meta']['livewire']['updates_count']);
        $this->assertSame(['password'], $capture->payloads[0]['meta']['livewire']['update_keys']);
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

    private function assertFlatEventHasNoDeferredEnvelopeKeys(array $payload): void
    {
        foreach (['id', 'payload', 'context', 'schema_version'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $payload);
        }
    }
}
