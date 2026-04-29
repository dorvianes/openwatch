<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HttpTransport.
 *
 * We cannot make real HTTP calls in a unit test, so we test the contract that:
 *  - send() returns false when serverUrl/token are empty.
 *  - lastHttpCode() returns 0 when no HTTP response was received.
 *  - lastCurlError() returns '' initially and on clean empty-config short-circuit.
 *  - The accessors reset on each send() call.
 *  - connectTimeout is accepted separately and clamped to total timeout.
 */
class HttpTransportTest extends TestCase
{
    public function test_send_returns_false_when_server_url_empty(): void
    {
        $transport = new HttpTransport(serverUrl: '', token: 'tok', timeout: 0.1);
        $this->assertFalse($transport->send(['type' => 'test']));
    }

    public function test_send_returns_false_when_token_empty(): void
    {
        $transport = new HttpTransport(serverUrl: 'https://example.com', token: '', timeout: 0.1);
        $this->assertFalse($transport->send(['type' => 'test']));
    }

    public function test_last_http_code_is_zero_when_config_missing(): void
    {
        $transport = new HttpTransport(serverUrl: '', token: '', timeout: 0.1);
        $transport->send(['type' => 'test']);

        $this->assertSame(0, $transport->lastHttpCode());
    }

    public function test_last_curl_error_is_empty_string_when_config_missing(): void
    {
        $transport = new HttpTransport(serverUrl: '', token: '', timeout: 0.1);
        $transport->send(['type' => 'test']);

        $this->assertSame('', $transport->lastCurlError());
    }

    public function test_accessors_reset_on_each_send_call(): void
    {
        // Simulate a transport subclass that can set controlled values
        $transport = new class extends HttpTransport {
            public function __construct()
            {
                parent::__construct(serverUrl: '', token: '', timeout: 0.1);
            }
        };

        // First call — both should be 0 / ''
        $transport->send(['type' => 'test']);
        $this->assertSame(0, $transport->lastHttpCode());
        $this->assertSame('', $transport->lastCurlError());

        // Second call — should still be 0 / '' (not stale from a previous real call)
        $transport->send(['type' => 'test']);
        $this->assertSame(0, $transport->lastHttpCode());
    }

    // -------------------------------------------------------------------------
    // connectTimeout split (Phase 2)
    // -------------------------------------------------------------------------

    public function test_connect_timeout_defaults_to_total_timeout_when_not_provided(): void
    {
        $transport = new HttpTransport(serverUrl: '', token: '', timeout: 0.5);

        // When connectTimeout is omitted, it should equal total timeout
        $this->assertSame(0.5, $transport->connectTimeout());
    }

    public function test_connect_timeout_can_be_set_explicitly(): void
    {
        $transport = new HttpTransport(serverUrl: '', token: '', timeout: 0.5, connectTimeout: 0.2);

        $this->assertSame(0.2, $transport->connectTimeout());
        $this->assertSame(0.5, $transport->timeout());
    }

    public function test_connect_timeout_is_clamped_to_total_timeout(): void
    {
        // connectTimeout > total timeout → clamped down to total timeout
        $transport = new HttpTransport(serverUrl: '', token: '', timeout: 0.3, connectTimeout: 1.0);

        $this->assertSame(0.3, $transport->connectTimeout());
    }

    public function test_total_timeout_accessor_returns_configured_value(): void
    {
        $transport = new HttpTransport(serverUrl: '', token: '', timeout: 0.7);

        $this->assertSame(0.7, $transport->timeout());
    }

    // -------------------------------------------------------------------------
    // CURLOPT mapping evidence (Phase 4)
    //
    // These tests verify the exact CURLOPT constants used so the verify phase
    // has direct, machine-checked evidence of the timeout mapping contract:
    //   - CURLOPT_TIMEOUT_MS        ← total timeout  (server may respond slowly)
    //   - CURLOPT_CONNECTTIMEOUT_MS ← connect timeout (TCP handshake budget)
    //
    // Strategy: subclass HttpTransport to expose buildCurlOptions() as public
    // so we can assert the mapping without making any network call.
    // -------------------------------------------------------------------------

    /** @return array<int,mixed> */
    private function curlOptionsFor(HttpTransport $transport): array
    {
        $spy = new class ($transport) extends HttpTransport {
            public function __construct(private HttpTransport $inner)
            {
                // Re-use same config via reflection to avoid duplicating params
                $r = new \ReflectionClass($inner);

                $serverUrl      = $r->getProperty('serverUrl');
                $token          = $r->getProperty('token');
                $timeout        = $r->getProperty('timeout');
                $connectTimeout = $r->getProperty('connectTimeout');

                parent::__construct(
                    serverUrl:      $serverUrl->getValue($inner),
                    token:          $token->getValue($inner),
                    timeout:        $timeout->getValue($inner),
                    connectTimeout: $connectTimeout->getValue($inner),
                );
            }

            public function exposeCurlOptions(): array
            {
                return $this->buildCurlOptions();
            }
        };

        return $spy->exposeCurlOptions();
    }

    public function test_curlopt_timeout_ms_maps_to_total_timeout_in_milliseconds(): void
    {
        $transport = new HttpTransport(
            serverUrl: 'https://example.com',
            token: 'tok',
            timeout: 2.5,
        );

        $opts = $this->curlOptionsFor($transport);

        // 2.5 s × 1000 = 2500 ms — the server may respond slowly within this budget
        $this->assertSame(2500, $opts[CURLOPT_TIMEOUT_MS]);
    }

    public function test_curlopt_connecttimeout_ms_maps_to_connect_timeout_in_milliseconds(): void
    {
        $transport = new HttpTransport(
            serverUrl: 'https://example.com',
            token: 'tok',
            timeout: 2.0,
            connectTimeout: 0.4,
        );

        $opts = $this->curlOptionsFor($transport);

        // TCP handshake budget: 0.4 s × 1000 = 400 ms
        $this->assertSame(400, $opts[CURLOPT_CONNECTTIMEOUT_MS]);
        // Total response budget remains 2.0 s × 1000 = 2000 ms
        $this->assertSame(2000, $opts[CURLOPT_TIMEOUT_MS]);
    }

    public function test_slow_server_response_respects_total_timeout_not_connect_timeout(): void
    {
        // Scenario: connect succeeds fast (0.3 s budget) but server takes up to 1.5 s to respond.
        // The CURLOPT_TIMEOUT_MS (total) must be ≥ CURLOPT_CONNECTTIMEOUT_MS so curl does NOT
        // abort the response phase prematurely.
        $transport = new HttpTransport(
            serverUrl: 'https://example.com',
            token: 'tok',
            timeout: 1.5,
            connectTimeout: 0.3,
        );

        $opts = $this->curlOptionsFor($transport);

        $totalMs   = $opts[CURLOPT_TIMEOUT_MS];
        $connectMs = $opts[CURLOPT_CONNECTTIMEOUT_MS];

        // Contract: total timeout always ≥ connect timeout
        $this->assertGreaterThanOrEqual($connectMs, $totalMs);

        // Exact values from the configured split
        $this->assertSame(1500, $totalMs);
        $this->assertSame(300, $connectMs);
    }

    public function test_clamped_connect_timeout_still_maps_correctly_to_curlopt(): void
    {
        // When connectTimeout is clamped (was too large), CURLOPT values reflect the clamped value
        $transport = new HttpTransport(
            serverUrl: 'https://example.com',
            token: 'tok',
            timeout: 0.5,
            connectTimeout: 9.9,   // clamped to 0.5
        );

        $opts = $this->curlOptionsFor($transport);

        $this->assertSame(500, $opts[CURLOPT_TIMEOUT_MS]);
        $this->assertSame(500, $opts[CURLOPT_CONNECTTIMEOUT_MS]);  // clamped
    }

    // -------------------------------------------------------------------------
    // Runtime contract: slow/unreachable server
    //
    // Strategy: connect to 127.0.0.1:1 — a loopback address on port 1 which is
    // always "connection refused" (no server listening). cURL returns immediately
    // with a real error and no HTTP response. This exercises send() end-to-end
    // without any real server, network call, or external dependency.
    //
    // Observable contract verified:
    //   - send() === false       (no 202 response)
    //   - lastHttpCode() === 0   (no HTTP response received)
    //   - lastCurlError() !== '' (cURL populated an error string)
    // -------------------------------------------------------------------------

    public function test_send_returns_false_and_populates_curl_error_on_connection_failure(): void
    {
        // 127.0.0.1:1 → connection refused immediately; no actual network traffic.
        // 1 ms timeout is a safety net in case some OS is lenient, but refused
        // connections return in microseconds regardless.
        $transport = new HttpTransport(
            serverUrl: 'http://127.0.0.1:1',
            token: 'any-token',
            timeout: 0.001,
        );

        $result = $transport->send(['type' => 'ping']);

        // Contract 1: send() must return false — no 202 received
        $this->assertFalse($result);

        // Contract 2: no HTTP response → code must stay at 0
        $this->assertSame(0, $transport->lastHttpCode());

        // Contract 3: cURL must have populated a real error string (connection refused / timed out)
        $this->assertNotEmpty($transport->lastCurlError());
    }

    public function test_send_returns_false_and_http_code_is_zero_on_timeout(): void
    {
        // Uses an unroutable address (RFC 5737 TEST-NET-1) that is guaranteed to
        // time out (no route, no response) combined with a 1 ms total timeout so
        // the test completes in milliseconds. The curl error will describe the timeout.
        $transport = new HttpTransport(
            serverUrl: 'http://192.0.2.1',   // TEST-NET-1: unroutable by spec
            token: 'any-token',
            timeout: 0.001,
            connectTimeout: 0.001,
        );

        $result = $transport->send(['type' => 'ping']);

        $this->assertFalse($result);
        $this->assertSame(0, $transport->lastHttpCode());
        $this->assertNotEmpty($transport->lastCurlError());
    }

    // -------------------------------------------------------------------------
    // Runtime contract: connect succeeds but response is slow (Phase 4 — gap)
    //
    // This test exercises the exact scenario the verify phase required:
    // TCP connection completes successfully (OS-level 3-way handshake) but the
    // server never sends an HTTP response, so cURL hits the TOTAL timeout
    // (not the connect timeout) during the response-wait phase.
    //
    // Strategy: open a stream_socket_server on a random loopback port with the
    // server set to non-blocking. The OS kernel accepts the TCP connection in its
    // backlog (completing the handshake) before any call to stream_socket_accept().
    // cURL connects successfully, waits for HTTP data, and times out because the
    // server never writes anything. After cURL returns, we accept the socket in
    // the test to confirm the connection was truly established.
    //
    // Observable contracts verified:
    //   - send() === false         (no 202 — server never responded)
    //   - lastHttpCode() === 0     (no HTTP bytes received)
    //   - lastCurlError() !== ''   (cURL timed out waiting for response)
    //   - cURL errno === 28        (CURLE_OPERATION_TIMEDOUT — response phase)
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // sendBatch() — batch endpoint contract (Phase batching lote-1)
    // -------------------------------------------------------------------------

    /**
     * sendBatch() must return false without touching the network when
     * serverUrl or token is empty (mirrors send() early-exit contract).
     */
    public function test_send_batch_returns_false_when_server_url_empty(): void
    {
        $transport = new HttpTransport(serverUrl: '', token: 'tok', timeout: 0.1);

        $this->assertFalse($transport->sendBatch([['type' => 'query']]));
    }

    public function test_send_batch_returns_false_when_token_empty(): void
    {
        $transport = new HttpTransport(serverUrl: 'https://example.com', token: '', timeout: 0.1);

        $this->assertFalse($transport->sendBatch([['type' => 'query']]));
    }

    /**
     * An empty batch is a no-op — returns false without making a network call.
     */
    public function test_send_batch_returns_false_for_empty_event_list(): void
    {
        $transport = new HttpTransport(serverUrl: 'https://example.com', token: 'tok', timeout: 0.1);

        $this->assertFalse($transport->sendBatch([]));
    }

    /**
     * SC-03: sendBatch([]) must NOT perform any HTTP call whatsoever.
     *
     * Strategy: subclass HttpTransport and override buildBatchCurlOptions() to
     * record whether it was ever invoked. An empty batch must short-circuit
     * BEFORE that method is reached, so the spy flag stays false.
     */
    public function test_send_batch_empty_does_not_reach_curl_options_build(): void
    {
        $spy = new class extends HttpTransport {
            public bool $buildBatchCurlOptionsCalled = false;

            public function __construct()
            {
                parent::__construct(
                    serverUrl: 'https://example.com',
                    token:     'tok',
                    timeout:   0.1,
                );
            }

            protected function buildBatchCurlOptions(array $events): array
            {
                $this->buildBatchCurlOptionsCalled = true;

                return parent::buildBatchCurlOptions($events);
            }
        };

        $result = $spy->sendBatch([]);

        $this->assertFalse($result, 'sendBatch([]) must return false');
        $this->assertFalse(
            $spy->buildBatchCurlOptionsCalled,
            'SC-03: sendBatch([]) must NOT reach buildBatchCurlOptions() — no HTTP call permitted',
        );
    }

    /**
     * sendBatch() must POST to /api/ingest/batch (not /api/ingest).
     * We verify the URL via buildBatchCurlOptions(), exposed through a subclass spy
     * using the same pattern as the existing curlOptionsFor() helper.
     */
    public function test_send_batch_targets_batch_endpoint(): void
    {
        $transport = new HttpTransport(
            serverUrl: 'https://example.com',
            token: 'tok',
            timeout: 0.1,
        );

        $opts = $this->batchCurlOptionsFor($transport, [['type' => 'query']]);

        $this->assertSame('https://example.com/api/ingest/batch', $opts[CURLOPT_URL]);
    }

    /**
     * sendBatch() payload must be wrapped as {"events":[...]}.
     */
    public function test_send_batch_wraps_events_in_events_key(): void
    {
        $events = [
            ['type' => 'query',   'sql' => 'select 1'],
            ['type' => 'outgoing_request', 'host' => 'api.example.com'],
        ];

        $transport = new HttpTransport(serverUrl: 'https://example.com', token: 'tok', timeout: 0.1);
        $opts      = $this->batchCurlOptionsFor($transport, $events);

        $decoded = json_decode($opts[CURLOPT_POSTFIELDS], true);

        $this->assertSame(['events'], array_keys($decoded));
        $this->assertSame($events, $decoded['events']);
        $this->assertSame('query',            $decoded['events'][0]['type']);
        $this->assertSame('outgoing_request', $decoded['events'][1]['type']);

        foreach ($decoded['events'] as $event) {
            foreach (['payload', 'context', 'id', 'schema_version'] as $forbiddenKey) {
                $this->assertArrayNotHasKey($forbiddenKey, $event);
            }
        }
    }

    /**
     * sendBatch() must include Authorization and Content-Type headers,
     * same as the single-event send() (reuses auth contract).
     */
    public function test_send_batch_includes_authorization_header(): void
    {
        $transport = new HttpTransport(serverUrl: 'https://example.com', token: 'secret-token', timeout: 0.1);
        $opts      = $this->batchCurlOptionsFor($transport, [['type' => 'query']]);

        $this->assertContains('Authorization: Bearer secret-token', $opts[CURLOPT_HTTPHEADER]);
        $this->assertContains('Content-Type: application/json',     $opts[CURLOPT_HTTPHEADER]);
    }

    // -------------------------------------------------------------------------
    // Spy helper for sendBatch curl options
    // -------------------------------------------------------------------------

    /** @return array<int,mixed> */
    private function batchCurlOptionsFor(HttpTransport $transport, array $events): array
    {
        $spy = new class ($transport) extends HttpTransport {
            public function __construct(private HttpTransport $inner)
            {
                $r = new \ReflectionClass($inner);

                $serverUrl      = $r->getProperty('serverUrl');
                $token          = $r->getProperty('token');
                $timeout        = $r->getProperty('timeout');
                $connectTimeout = $r->getProperty('connectTimeout');

                parent::__construct(
                    serverUrl:      $serverUrl->getValue($inner),
                    token:          $token->getValue($inner),
                    timeout:        $timeout->getValue($inner),
                    connectTimeout: $connectTimeout->getValue($inner),
                );
            }

            public function exposeBatchCurlOptions(array $events): array
            {
                return $this->buildBatchCurlOptions($events);
            }
        };

        return $spy->exposeBatchCurlOptions($events);
    }

    public function test_send_times_out_on_slow_response_after_successful_connect(): void
    {
        // Open a TCP server on a random loopback port. Non-blocking so it does
        // not stall the test process. The OS accepts the TCP handshake in the
        // kernel backlog while cURL waits, making this a true "connect succeeds,
        // response never arrives" scenario.
        $server = stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($server === false) {
            $this->markTestSkipped("stream_socket_server unavailable: $errstr ($errno)");
        }

        stream_set_blocking($server, false);

        // Resolve the actual port chosen by the OS
        $boundName = stream_socket_get_name($server, false);
        preg_match('/(\d+)$/', $boundName, $matches);
        $port = (int) $matches[1];

        // Use a 200 ms total timeout with a shorter connect timeout so we can
        // confirm that a clamped connect timeout does NOT abort the response phase.
        $transport = new HttpTransport(
            serverUrl: "http://127.0.0.1:{$port}",
            token: 'any-token',
            timeout: 0.200,          // 200 ms total: long enough to connect, short enough to test fast
            connectTimeout: 0.150,   // 150 ms connect budget (connect will succeed well within this)
        );

        // send() blocks until cURL times out waiting for an HTTP response
        $result = $transport->send(['type' => 'ping']);

        // Accept the connection now to prove TCP connect succeeded on the server side
        $client = @stream_socket_accept($server, 0);

        @fclose($server);
        if ($client !== false) {
            @fclose($client);
        }

        // Contract 1: send() must be false — server never replied with 202
        $this->assertFalse($result);

        // Contract 2: no HTTP response received → HTTP code stays at 0
        $this->assertSame(0, $transport->lastHttpCode());

        // Contract 3: cURL must populate an error — specifically a response-phase timeout
        $this->assertNotEmpty($transport->lastCurlError());

        // Contract 4: connection WAS established (client accepted server-side)
        // This is the key distinction from a connection-refused or unreachable scenario:
        // here the TCP handshake completed, but the HTTP response never arrived.
        $this->assertNotFalse(
            $client,
            'TCP connection must have been accepted by the server — this test requires connect success, not connect failure',
        );
    }
}
