<?php

namespace Dorvianes\OpenWatch\Recorders;

use Dorvianes\OpenWatch\Support\EventTimestamp;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExceptionRecorder
{
    /** Maximum number of trace frames to include in the payload. */
    private const MAX_TRACE_FRAMES = 30;

    /** Lines of context to capture above and below the failing line. */
    private const SNIPPET_CONTEXT_LINES = 7;

    public function __construct(private HttpTransport $transport) {}

    public function record(Throwable $exception, ?Request $request = null): void
    {
        // Capture event time immediately — before any processing that could add delay
        $capturedAt = EventTimestamp::now();

        $payload = [
            'type'    => 'exception',
            'class'   => get_class($exception),
            'message' => mb_substr($exception->getMessage(), 0, 500),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
            'trace'   => $this->buildTrace($exception),
            'snippet' => $this->extractSnippet($exception->getFile(), $exception->getLine()),
            'request' => $request !== null ? $this->buildRequestContext($request) : null,
            'previous' => $this->buildPreviousChain($exception),
            // occurred_at reflects when the exception was caught (event time), not send time
            'occurred_at' => EventTimestamp::format($capturedAt),
            // Non-canonical metadata — helps the server correlate events to app/env
            'meta'        => [
                'app_name' => function_exists('config') ? config('app.name') : null,
                'app_env'  => function_exists('config') ? config('app.env') : null,
            ],
        ];

        $ok = $this->transport->send($payload);

        // Emit a debug-level log when transport rejects with a non-2xx response
        // so developers can diagnose ingestion key or server issues without noise.
        if (! $ok) {
            $httpCode = $this->transport->lastHttpCode();
            if ($httpCode > 0) {
                try {
                    Log::debug('[OpenWatch] Exception transport rejected with HTTP ' . $httpCode, [
                        'exception_class' => get_class($exception),
                    ]);
                } catch (Throwable) {
                    // Logging not available — stay silent.
                }
            }
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Build a chain of previous (chained) exceptions, up to 5 levels deep.
     * Returns null if there is no previous exception.
     *
     * @return list<array{class: string, message: string, file: string, line: int}>|null
     */
    private function buildPreviousChain(Throwable $exception): ?array
    {
        $previous = $exception->getPrevious();

        if ($previous === null) {
            return null;
        }

        $chain = [];
        $depth = 0;

        while ($previous !== null && $depth < 5) {
            $chain[] = [
                'class'   => get_class($previous),
                'message' => mb_substr($previous->getMessage(), 0, 500),
                'file'    => $previous->getFile(),
                'line'    => $previous->getLine(),
            ];
            $previous = $previous->getPrevious();
            $depth++;
        }

        return $chain ?: null;
    }

    /**
     * Build a trimmed, structured trace array — at most MAX_TRACE_FRAMES frames.
     * Each frame keeps only the fields that are useful for display.
     *
     * @return list<array{file?: string, line?: int, class?: string, function?: string}>
     */
    private function buildTrace(Throwable $exception): array
    {
        $raw    = $exception->getTrace();
        $frames = array_slice($raw, 0, self::MAX_TRACE_FRAMES);

        return array_map(static function (array $frame): array {
            $out = [];
            if (isset($frame['file'])) {
                $out['file'] = $frame['file'];
            }
            if (isset($frame['line'])) {
                $out['line'] = (int) $frame['line'];
            }
            if (isset($frame['class'])) {
                $out['class'] = $frame['class'];
            }
            if (isset($frame['function'])) {
                $out['function'] = $frame['function'];
            }

            return $out;
        }, $frames);
    }

    /**
     * Extract a small code snippet around the failing line.
     * Returns null silently on any failure (file unreadable, etc.).
     *
     * @return array{lines: array<int, string>, error_line: int}|null
     */
    private function extractSnippet(string $file, int $errorLine): ?array
    {
        try {
            if (! is_file($file) || ! is_readable($file)) {
                return null;
            }

            $all = file($file, FILE_IGNORE_NEW_LINES);

            if ($all === false) {
                return null;
            }

            $first  = max(0, $errorLine - self::SNIPPET_CONTEXT_LINES - 1);
            $last   = min(count($all) - 1, $errorLine + self::SNIPPET_CONTEXT_LINES - 1);
            $slice  = array_slice($all, $first, $last - $first + 1, true);

            // Re-key so array keys are 1-based line numbers
            $lines = [];
            foreach ($slice as $zeroIndex => $text) {
                $lines[$zeroIndex + 1] = $text;
            }

            return [
                'lines'      => $lines,
                'error_line' => $errorLine,
            ];
        } catch (\Throwable) {
            // Fail silently — snippet is best-effort
            return null;
        }
    }

    /**
     * Build a richer request context block.
     *
     * @return array<string, mixed>
     */
    private function buildRequestContext(Request $request): array
    {
        $path = $request->path() === '/' ? '/' : '/' . $request->path();

        $context = [
            'method' => $request->method(),
            'path'   => $path,
            'url'    => $request->fullUrl(),
            'ip'     => $request->ip(),
        ];

        // Route name — available only when the router has matched a named route
        try {
            $routeName = $request->route()?->getName();
            if ($routeName !== null && $routeName !== '') {
                $context['route'] = $routeName;
            }
        } catch (\Throwable) {
            // Not always available (e.g. during early boot)
        }

        // User agent
        $ua = $request->userAgent();
        if ($ua !== null && $ua !== '') {
            $context['user_agent'] = mb_substr($ua, 0, 200);
        }

        return $context;
    }
}
