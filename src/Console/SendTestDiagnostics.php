<?php

namespace Dorvianes\OpenWatch\Console;

use Dorvianes\OpenWatch\Support\EventTimestamp;
use Dorvianes\OpenWatch\Transport\HttpTransport;

/**
 * Pure diagnostics logic for the openwatch:send-test command.
 *
 * Extracted from SendTestCommand so it can be unit-tested without
 * a booted Laravel application. All config values are injected.
 */
final class SendTestDiagnostics
{
    public const STATUS_SUCCESS          = 'success';
    public const STATUS_DISABLED         = 'disabled';
    public const STATUS_MISSING_CONFIG   = 'missing_config';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_UNREACHABLE      = 'unreachable';

    private string $status  = '';
    private int    $httpCode = 0;
    private string $curlError = '';

    /** Lines of output produced by run(). */
    private array $outputLines = [];

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly bool   $enabled,
        private readonly string $serverUrl,
        private readonly string $token,
        private readonly string $appName    = 'app',
        private readonly string $appEnv     = 'production',
        private readonly bool   $exceptionWired = false,
    ) {}

    /**
     * Execute the diagnostic flow.
     *
     * @return string One of the STATUS_* constants.
     */
    public function run(): string
    {
        $this->outputLines = [];

        $this->line('');
        $this->line('  OpenWatch — connection diagnostic');
        $this->line('');
        $this->line('  Configuration:');
        $this->line('    Enabled    : ' . ($this->enabled ? 'yes' : 'no (OPENWATCH_ENABLED=false)'));
        $this->line('    Server URL : ' . ($this->serverUrl ?: 'not set'));
        $this->line('    Token      : ' . ($this->token ? substr($this->token, 0, 4) . '...' . substr($this->token, -4) : 'not set'));
        $this->line('    App        : ' . $this->appName . ' [' . $this->appEnv . ']');
        $this->line('');

        // ── Guard: disabled ───────────────────────────────────────────────────
        if (! $this->enabled) {
            $this->line('  WARNING: OpenWatch is disabled. Set OPENWATCH_ENABLED=true to activate.');
            $this->status = self::STATUS_DISABLED;
            return $this->status;
        }

        // ── Guard: missing config ─────────────────────────────────────────────
        if (empty($this->serverUrl) || empty($this->token)) {
            $this->line('  ERROR: Missing required configuration. Set these in your .env:');
            $this->line('');
            if (empty($this->serverUrl)) {
                $this->line('    OPENWATCH_SERVER_URL=https://openwatch.yourdomain.com');
            }
            if (empty($this->token)) {
                $this->line('    OPENWATCH_TOKEN=<ingestion-key>');
            }
            $this->line('');
            $this->line('  Tip: OpenWatch UI -> Your App -> Environment -> Ingestion Keys -> New Key');
            $this->status = self::STATUS_MISSING_CONFIG;
            return $this->status;
        }

        // ── Check: exception wiring ───────────────────────────────────────────
        if (! $this->exceptionWired) {
            $this->line('  WARNING: Exception reporting is NOT wired.');
            $this->line('  Continuing with connectivity test...');
            $this->line('');
        }

        // ── Send synthetic event ──────────────────────────────────────────────
        $this->line('  Sending test event to: ' . rtrim($this->serverUrl, '/') . '/api/ingest');

        $payload = [
            'type'             => 'test',
            'method'           => 'GET',
            'path'             => '/openwatch-test',
            'host'             => 'cli',
            'status'           => 200,
            'duration_ms'      => 0.0,
            'ip'               => '127.0.0.1',
            'user_agent_class' => 'api-client',
            'memory_peak_mb'   => 0.0,
            'occurred_at'      => EventTimestamp::format(),
            'meta'             => [
                'app_name' => $this->appName,
                'app_env'  => $this->appEnv,
            ],
        ];

        $ok              = $this->transport->send($payload);
        $this->httpCode  = $this->transport->lastHttpCode();
        $this->curlError = $this->transport->lastCurlError();

        // ── Interpret result ──────────────────────────────────────────────────
        if ($ok) {
            $this->line('');
            $this->line('  SUCCESS: Test event accepted (HTTP 202).');
            $this->line('  Server : ' . rtrim($this->serverUrl, '/'));
            $this->line('  App    : ' . $this->appName . ' [' . $this->appEnv . ']');
            $this->line('');
            $this->status = self::STATUS_SUCCESS;
            return $this->status;
        }

        $this->line('');

        if ($this->httpCode === 0) {
            $this->line('  ERROR: Could not reach the server (no HTTP response).');
            $this->line('');
            if ($this->curlError !== '') {
                $this->line('  cURL error: ' . $this->curlError);
            }
            $this->line('  Checklist:');
            $this->line('    1. Is the server running at: ' . $this->serverUrl . ' ?');
            $this->line('    2. Can this machine reach that host? (firewall, VPN, DNS)');
            $this->line('    3. Try: curl -I ' . rtrim($this->serverUrl, '/') . '/api/up');
            $this->status = self::STATUS_UNREACHABLE;
        } elseif ($this->httpCode === 401) {
            $this->line('  ERROR: Authentication rejected (HTTP 401).');
            $this->line('');
            $this->line('  Checklist:');
            $this->line('    1. Is OPENWATCH_TOKEN a valid, non-expired ingestion key?');
            $this->line('    2. OpenWatch UI -> Your App -> Environment -> Ingestion Keys');
            $this->status = self::STATUS_REJECTED;
        } elseif ($this->httpCode === 422) {
            $this->line('  ERROR: Payload rejected by the server (HTTP 422 — validation error).');
            $this->line('');
            $this->line('  Checklist:');
            $this->line('    1. Ensure the package and server are on compatible versions.');
            $this->line('    2. Check server logs for validation details.');
            $this->line('    3. Verify OPENWATCH_TOKEN belongs to the correct app/environment.');
            $this->line('    4. Re-generate the ingestion key if recently rotated.');
            $this->status = self::STATUS_REJECTED;
        } elseif ($this->httpCode === 429) {
            $this->line('  ERROR: Rate limit exceeded (HTTP 429).');
            $this->line('');
            $this->line('  Checklist:');
            $this->line('    1. Wait a moment and try again.');
            $this->line('    2. Raise OPENWATCH_INGEST_RATE_LIMIT on the server.');
            $this->line('    3. Check server logs for per-token rate limit configuration.');
            $this->line('    4. Verify the token is not shared across high-traffic environments.');
            $this->status = self::STATUS_REJECTED;
        } else {
            $this->line('  ERROR: Unexpected HTTP ' . $this->httpCode . ' from the server.');
            $this->line('');
            $this->line('  Checklist:');
            $this->line('    1. Is the ingest endpoint healthy? Try: curl -I ' . rtrim($this->serverUrl, '/') . '/api/up');
            $this->line('    2. Check the server logs for errors.');
            $this->status = self::STATUS_REJECTED;
        }

        $this->line('');
        return $this->status;
    }

    /** Returns all output lines produced by run(). */
    public function getOutputLines(): array
    {
        return $this->outputLines;
    }

    public function lastHttpCode(): int
    {
        return $this->httpCode;
    }

    private function line(string $text): void
    {
        $this->outputLines[] = $text;
    }
}
