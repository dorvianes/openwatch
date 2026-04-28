<?php

namespace Dorvianes\OpenWatch\Recorders;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Support\EventTimestamp;
use Dorvianes\OpenWatch\Transport\HttpTransport;

class QueryRecorder
{
    public function __construct(
        private HttpTransport $transport,
        private ?EventBuffer  $buffer          = null,
        private bool          $batchingEnabled = false,
    ) {}

    /**
     * Record a database query event.
     *
     * When batching is enabled and a buffer is provided, the event is pushed
     * to the buffer for deferred batch delivery.
     * Otherwise, the event is sent synchronously via transport.
     *
     * Accepts Illuminate\Database\Events\QueryExecuted or any object
     * with public properties: sql, connectionName, time.
     *
     * @param  object     $event        QueryExecuted-compatible object
     * @param  float|null $thresholdMs  Optional slow-query filter in milliseconds
     */
    public function record(object $event, ?float $thresholdMs = null): void
    {
        try {
            // Capture event time immediately — before threshold check or processing
            $capturedAt = EventTimestamp::now();
            $durationMs = round($event->time, 2);

            // If a threshold is configured, skip queries under it
            if ($thresholdMs !== null && $durationMs < $thresholdMs) {
                return;
            }

            $payload = [
                'type'        => 'query',
                'sql'         => $event->sql,
                'connection'  => $event->connectionName,
                'duration_ms' => $durationMs,
                // occurred_at reflects when the query fired (event time), not send time
                'occurred_at' => EventTimestamp::format($capturedAt),
                'meta'        => [
                    'app_name' => function_exists('config') ? config('app.name') : null,
                    'app_env'  => function_exists('config') ? config('app.env') : null,
                ],
            ];

            if ($this->batchingEnabled && $this->buffer !== null) {
                $this->buffer->push($payload);
            } else {
                $this->transport->send($payload);
            }
        } catch (\Throwable) {
            // Fail silently — never break the client application
        }
    }
}
