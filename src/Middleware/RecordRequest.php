<?php

namespace Dorvianes\OpenWatch\Middleware;

use Closure;
use Dorvianes\OpenWatch\Recorders\RequestRecorder;
use Dorvianes\OpenWatch\Support\BatchFlusher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordRequest
{
    public function __construct(
        private RequestRecorder $recorder,
        private ?BatchFlusher   $flusher = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $this->recorder->record($request, $response, $startTime);

        // Flush buffered events (batching lote-1). No-op when flusher is null
        // (batching disabled) or buffer is empty.
        $this->flusher?->flush();
    }
}
