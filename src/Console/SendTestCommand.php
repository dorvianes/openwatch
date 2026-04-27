<?php

namespace Dorvianes\OpenWatch\Console;

use Dorvianes\OpenWatch\Support\RegistersExceptionReporting;
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
        $diagnostics = new SendTestDiagnostics(
            transport:      $this->transport,
            enabled:        (bool) config('openwatch.enabled', true),
            serverUrl:      (string) (config('openwatch.server_url') ?? ''),
            token:          (string) (config('openwatch.token') ?? ''),
            appName:        (string) (config('app.name') ?? 'app'),
            appEnv:         (string) (config('app.env') ?? 'production'),
            exceptionWired: RegistersExceptionReporting::isRegistered(),
        );

        $status = $diagnostics->run();

        foreach ($diagnostics->getOutputLines() as $line) {
            $this->line($line);
        }

        return $status === SendTestDiagnostics::STATUS_SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
