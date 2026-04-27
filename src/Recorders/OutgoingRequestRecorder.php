<?php

namespace Dorvianes\OpenWatch\Recorders;

use Dorvianes\OpenWatch\Support\EventTimestamp;
use Dorvianes\OpenWatch\Transport\HttpTransport;

class OutgoingRequestRecorder
{
    public function __construct(private HttpTransport $transport) {}

    /**
     * Record an outgoing HTTP request event.
     *
     * Accepts the response object from Laravel's HTTP client plus timing data.
     * Intentionally omits auth headers, request/response bodies, and sensitive params.
     *
     * @param  string     $method       HTTP verb
     * @param  string     $url          Full URL (used to extract host/path; sensitive params are dropped)
     * @param  int        $status       HTTP status code
     * @param  float      $durationMs   Round-trip duration in milliseconds
     * @param  float|null $thresholdMs  Optional filter — skip requests under this duration
     */
    public function record(
        string $method,
        string $url,
        int $status,
        float $durationMs,
        ?float $thresholdMs = null,
        ?float $startTime = null,
    ): void {
        try {
            $durationMs = round($durationMs, 2);

            if ($thresholdMs !== null && $durationMs < $thresholdMs) {
                return;
            }

            $parsed = $this->parseUrl($url);

            $payload = [
                'type'        => 'outgoing_request',
                'host'        => $parsed['host'],
                'method'      => strtoupper($method),
                'path'        => $parsed['path'],
                'status'      => $status,
                'duration_ms' => $durationMs,
                // occurred_at reflects when the outgoing request started (event time), not send time
                'occurred_at' => EventTimestamp::format($startTime),
                'url'         => $parsed['safe_url'],
                'meta'        => [
                    'scheme'   => $parsed['scheme'],
                    'app_name' => function_exists('config') ? config('app.name') : null,
                    'app_env'  => function_exists('config') ? config('app.env') : null,
                ],
            ];

            $this->transport->send($payload);
        } catch (\Throwable) {
            // Fail silently — never break the client application
        }
    }

    /**
     * Parse a URL into safe components, stripping the query string entirely
     * (query params can contain tokens, API keys, or PII).
     */
    private function parseUrl(string $url): array
    {
        $parts  = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '/';

        // Safe URL: scheme + host (+ optional port) + path only — no query or fragment
        $safeUrl = $scheme . '://' . $host . $port . $path;

        return [
            'scheme'   => $scheme,
            'host'     => $host . $port,
            'path'     => $path,
            'safe_url' => $safeUrl,
        ];
    }
}
