<?php

namespace Dorvianes\OpenWatch\Support;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Transport\HttpTransport;

/**
 * Coordinates flushing the EventBuffer via HttpTransport::sendBatch().
 *
 * Design constraints:
 * - When batching is disabled, flush() is a no-op.
 * - When the buffer is empty, flush() is a no-op (no HTTP call made).
 * - Calling flush() twice is safe — second call hits an empty buffer and is a no-op.
 */
class BatchFlusher
{
    public function __construct(
        private HttpTransport $transport,
        private EventBuffer   $buffer,
        private bool          $batchingEnabled = true,
    ) {}

    /**
     * Flush all buffered events to the server via a single sendBatch() call.
     * Idempotent: safe to call multiple times; subsequent calls after the first
     * are no-ops because the buffer is empty.
     */
    public function flush(): void
    {
        if (! $this->batchingEnabled) {
            return;
        }

        $events = $this->buffer->flush();

        if (empty($events)) {
            return;
        }

        try {
            $this->transport->sendBatch($events);
        } catch (\Throwable) {
            // Silent failure — never break the host application
        }
    }
}
