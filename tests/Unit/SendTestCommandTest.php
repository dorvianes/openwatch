<?php

namespace Dorvianes\OpenWatch\Tests\Unit;

use Dorvianes\OpenWatch\Console\SendTestDiagnostics;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral coverage for SendTestDiagnostics — the pure logic extracted
 * from SendTestCommand. All four spec scenarios are exercised here without
 * needing a booted Laravel application.
 *
 * Spec scenarios covered:
 *  1. Disabled package (OPENWATCH_ENABLED=false) → FAILURE, no HTTP request
 *  2. Missing configuration (empty token) → FAILURE, prints remediation
 *  3. Successful round-trip (server returns 202) → SUCCESS, prints server URL + app/env
 *  4. Server rejects event (non-2xx) → FAILURE, prints diagnostic checklist
 */
class SendTestCommandTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a fake transport that always returns a fixed http code.
     */
    private function makeTransport(bool $ok, int $httpCode = 0): HttpTransport
    {
        return new class($ok, $httpCode) extends HttpTransport {
            private bool $sendOk;
            private int  $code;

            public function __construct(bool $ok, int $code)
            {
                // Skip parent constructor — no real curl needed
                $this->sendOk = $ok;
                $this->code   = $code;
            }

            public function send(array $payload): bool
            {
                return $this->sendOk;
            }

            public function lastHttpCode(): int
            {
                return $this->code;
            }

            public function lastCurlError(): string
            {
                return '';
            }
        };
    }

    private function makeDiagnostics(
        bool   $enabled     = true,
        string $serverUrl   = 'https://openwatch.example.com',
        string $token       = 'tok_test_1234',
        bool   $transportOk = true,
        int    $httpCode    = 202,
    ): SendTestDiagnostics {
        return new SendTestDiagnostics(
            transport:      $this->makeTransport($transportOk, $httpCode),
            enabled:        $enabled,
            serverUrl:      $serverUrl,
            token:          $token,
            appName:        'TestApp',
            appEnv:         'testing',
            exceptionWired: true,
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 1: Disabled package
    // -------------------------------------------------------------------------

    public function test_disabled_package_returns_disabled_status(): void
    {
        $diag   = $this->makeDiagnostics(enabled: false);
        $status = $diag->run();

        $this->assertSame(SendTestDiagnostics::STATUS_DISABLED, $status);
    }

    public function test_disabled_package_output_warns_operator(): void
    {
        $diag = $this->makeDiagnostics(enabled: false);
        $diag->run();

        $output = implode("\n", $diag->getOutputLines());
        $this->assertStringContainsString('OPENWATCH_ENABLED=true', $output);
    }

    public function test_disabled_package_does_not_reach_transport(): void
    {
        // Use a transport that would flip a flag if send() is ever called
        $transportCalled = false;
        $transport = new class($transportCalled) extends HttpTransport {
            public function __construct(private bool &$called)
            {
                // skip parent
            }

            public function send(array $payload): bool
            {
                $this->called = true;
                return false;
            }

            public function lastHttpCode(): int  { return 0; }
            public function lastCurlError(): string { return ''; }
        };

        $diag = new SendTestDiagnostics(
            transport:      $transport,
            enabled:        false,
            serverUrl:      'https://openwatch.example.com',
            token:          'tok_1234',
            appName:        'App',
            appEnv:         'testing',
            exceptionWired: false,
        );
        $diag->run();

        $this->assertFalse($transportCalled, 'Transport send() must NOT be called when package is disabled');
    }

    // -------------------------------------------------------------------------
    // Scenario 2: Missing configuration
    // -------------------------------------------------------------------------

    public function test_missing_token_returns_missing_config_status(): void
    {
        $diag   = $this->makeDiagnostics(token: '');
        $status = $diag->run();

        $this->assertSame(SendTestDiagnostics::STATUS_MISSING_CONFIG, $status);
    }

    public function test_missing_server_url_returns_missing_config_status(): void
    {
        $diag   = $this->makeDiagnostics(serverUrl: '');
        $status = $diag->run();

        $this->assertSame(SendTestDiagnostics::STATUS_MISSING_CONFIG, $status);
    }

    public function test_missing_config_output_contains_env_var_names(): void
    {
        $diag = $this->makeDiagnostics(token: '', serverUrl: '');
        $diag->run();

        $output = implode("\n", $diag->getOutputLines());
        $this->assertStringContainsString('OPENWATCH_SERVER_URL', $output);
        $this->assertStringContainsString('OPENWATCH_TOKEN', $output);
    }

    public function test_missing_config_output_contains_remediation_tip(): void
    {
        $diag = $this->makeDiagnostics(token: '');
        $diag->run();

        $output = implode("\n", $diag->getOutputLines());
        // Spec: prints remediation steps
        $this->assertStringContainsString('OPENWATCH_TOKEN', $output);
    }

    // -------------------------------------------------------------------------
    // Scenario 3: Successful round-trip
    // -------------------------------------------------------------------------

    public function test_success_returns_success_status(): void
    {
        $diag   = $this->makeDiagnostics(transportOk: true, httpCode: 202);
        $status = $diag->run();

        $this->assertSame(SendTestDiagnostics::STATUS_SUCCESS, $status);
    }

    public function test_success_output_contains_server_url(): void
    {
        $diag = $this->makeDiagnostics(serverUrl: 'https://openwatch.example.com', transportOk: true, httpCode: 202);
        $diag->run();

        $output = implode("\n", $diag->getOutputLines());
        $this->assertStringContainsString('openwatch.example.com', $output);
    }

    public function test_success_output_contains_app_and_env(): void
    {
        $diag = $this->makeDiagnostics(transportOk: true, httpCode: 202);
        $diag->run();

        $output = implode("\n", $diag->getOutputLines());
        $this->assertStringContainsString('TestApp', $output);
        $this->assertStringContainsString('testing', $output);
    }

    // -------------------------------------------------------------------------
    // Scenario 4: Server rejects event
    // -------------------------------------------------------------------------

    public function test_server_rejection_401_returns_rejected_status(): void
    {
        $diag   = $this->makeDiagnostics(transportOk: false, httpCode: 401);
        $status = $diag->run();

        $this->assertSame(SendTestDiagnostics::STATUS_REJECTED, $status);
    }

    public function test_server_rejection_422_returns_rejected_status(): void
    {
        $diag   = $this->makeDiagnostics(transportOk: false, httpCode: 422);
        $status = $diag->run();

        $this->assertSame(SendTestDiagnostics::STATUS_REJECTED, $status);
    }

    public function test_server_rejection_429_returns_rejected_status(): void
    {
        $diag   = $this->makeDiagnostics(transportOk: false, httpCode: 429);
        $status = $diag->run();

        $this->assertSame(SendTestDiagnostics::STATUS_REJECTED, $status);
    }

    public function test_server_rejection_422_output_contains_numbered_checklist(): void
    {
        $diag = $this->makeDiagnostics(transportOk: false, httpCode: 422);
        $diag->run();

        $output = implode("\n", $diag->getOutputLines());
        // Spec requires a numbered checklist on failure
        $this->assertMatchesRegularExpression('/1\./', $output, 'Failure output must include numbered checklist item 1');
        $this->assertMatchesRegularExpression('/2\./', $output, 'Failure output must include numbered checklist item 2');
    }

    public function test_server_rejection_429_output_contains_numbered_checklist(): void
    {
        $diag = $this->makeDiagnostics(transportOk: false, httpCode: 429);
        $diag->run();

        $output = implode("\n", $diag->getOutputLines());
        $this->assertMatchesRegularExpression('/1\./', $output);
        $this->assertMatchesRegularExpression('/2\./', $output);
    }

    public function test_unreachable_server_returns_unreachable_status(): void
    {
        $diag   = $this->makeDiagnostics(transportOk: false, httpCode: 0);
        $status = $diag->run();

        $this->assertSame(SendTestDiagnostics::STATUS_UNREACHABLE, $status);
    }

    // -------------------------------------------------------------------------
    // Legacy marker test — kept for backward compat
    // -------------------------------------------------------------------------

    public function test_exception_wiring_marker_is_accessible(): void
    {
        // The command calls RegistersExceptionReporting::isRegistered()
        $result = \Dorvianes\OpenWatch\Support\RegistersExceptionReporting::isRegistered();
        $this->assertIsBool($result);
    }
}
