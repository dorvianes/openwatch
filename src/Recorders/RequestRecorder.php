<?php

namespace Dorvianes\OpenWatch\Recorders;

use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RequestRecorder
{
    public function __construct(private HttpTransport $transport) {}

    public function record(Request $request, SymfonyResponse $response, float $startTime): void
    {
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        $ua = $request->userAgent() ?? '';
        $userAgentClass = $this->classifyUserAgent($ua);

        $payload = [
            'type'             => 'request',
            'method'           => $request->method(),
            'path'             => $request->path() === '/' ? '/' : '/' . $request->path(),
            'host'             => $request->getHost(),
            'status'           => $response->getStatusCode(),
            'duration_ms'      => $durationMs,
            'ip'               => $request->ip(),
            'user_agent_class' => $userAgentClass,
            'memory_peak_mb'   => round(memory_get_peak_usage(true) / 1048576, 2),
            'occurred_at'      => now()->toIso8601String(),
            // Non-canonical metadata — helps the server correlate events to app/env
            'meta'             => [
                'app_name' => config('app.name'),
                'app_env'  => config('app.env'),
            ],
        ];

        $this->transport->send($payload);
    }

    private function classifyUserAgent(string $ua): string
    {
        if ($ua === '') {
            return 'unknown';
        }
        if (str_contains($ua, 'bot') || str_contains($ua, 'Bot') || str_contains($ua, 'spider')) {
            return 'bot';
        }
        if (str_contains($ua, 'curl') || str_contains($ua, 'python') || str_contains($ua, 'axios') || str_contains($ua, 'Go-http')) {
            return 'api-client';
        }
        if (str_contains($ua, 'Mozilla') || str_contains($ua, 'Chrome') || str_contains($ua, 'Safari') || str_contains($ua, 'Firefox')) {
            return 'browser';
        }
        return 'other';
    }
}
