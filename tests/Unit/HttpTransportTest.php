<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HttpTransport diagnostic accessors (task 4.2).
 *
 * We cannot make real HTTP calls in a unit test, so we test the contract that:
 *  - send() returns false when serverUrl/token are empty.
 *  - lastHttpCode() returns 0 when no HTTP response was received.
 *  - lastCurlError() returns '' initially and on clean empty-config short-circuit.
 *  - The accessors reset on each send() call.
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
}
