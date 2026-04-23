<?php

namespace Dorvianes\OpenWatch\Console;

use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Console\Command;

class SendTestCommand extends Command
{
    protected $signature   = 'openwatch:send-test';
    protected $description = 'Send a synthetic test event to the OpenWatch server (verifies connectivity and ingestion key)';

    public function __construct(private HttpTransport $transport)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $serverUrl = config('openwatch.server_url');
        $token     = config('openwatch.token');
        $enabled   = config('openwatch.enabled', true);

        if (! $enabled) {
            $this->warn('⚠  OpenWatch is disabled (OPENWATCH_ENABLED=false). No event sent.');
            return self::FAILURE;
        }

        if (empty($serverUrl) || empty($token)) {
            $this->error('✗ OpenWatch is not configured. Make sure these are set in your .env:');
            $this->line('');
            $this->line('  OPENWATCH_SERVER_URL=https://openwatch.yourdomain.com');
            $this->line('  OPENWATCH_TOKEN=<ingestion-key-from-server-ui>');
            $this->line('');
            $this->line('  ➜ OpenWatch UI → Your App → Environment → Ingestion Keys → New Key');
            return self::FAILURE;
        }

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
            'occurred_at'      => now()->toIso8601String(),
            'meta'             => [
                'app_name' => config('app.name'),
                'app_env'  => config('app.env'),
            ],
        ];

        $ok = $this->transport->send($payload);

        if ($ok) {
            $this->info('✓ OpenWatch: test event accepted by server (HTTP 202).');
            $this->line('  Server : ' . $serverUrl);
            $this->line('  App    : ' . config('app.name') . ' [' . config('app.env') . ']');
            return self::SUCCESS;
        }

        $this->error('✗ OpenWatch: server did not accept the event.');
        $this->line('');
        $this->line('  Checklist:');
        $this->line('  1. Is the server reachable at: ' . $serverUrl . ' ?');
        $this->line('  2. Is OPENWATCH_TOKEN a valid ingestion key (not expired / revoked)?');
        $this->line('  3. Does the key belong to the right application + environment in the UI?');
        $this->line('  4. Is the ingest endpoint responding at: ' . $serverUrl . '/api/ingest ?');
        return self::FAILURE;
    }
}
