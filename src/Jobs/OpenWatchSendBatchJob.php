<?php

namespace Dorvianes\OpenWatch\Jobs;

use Dorvianes\OpenWatch\Transport\HttpTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Queued Job that sends a batch of buffered OpenWatch events via the HTTP transport.
 *
 * Design decisions:
 * - Implements ShouldQueue so a host Laravel app will serialize and enqueue it
 *   on the configured connection/queue via the standard dispatch() pipeline.
 * - Uses the Queueable trait which exposes the onConnection()/onQueue() fluent
 *   API consumed by BatchFlusher — no custom property management needed.
 * - Receives the events array at dispatch time (no DB or cache dependency).
 * - Calls HttpTransport::sendBatch() in handle().
 * - Swallows transport failures silently — telemetry loss is preferred over
 *   poisoning the host app's queue with retries on a monitoring side-car.
 *
 * @property-read array $events
 */
class OpenWatchSendBatchJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array<string, mixed>> $events
     */
    public function __construct(
        public readonly array $events,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(HttpTransport $transport): void
    {
        try {
            $transport->sendBatch($this->events);
        } catch (Throwable) {
            // Silent failure — transport issues must never destabilize the queue.
        }
    }
}
