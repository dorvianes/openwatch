<?php

namespace Dorvianes\OpenWatch\Support;

use Dorvianes\OpenWatch\Buffer\EventBuffer;
use Dorvianes\OpenWatch\Jobs\OpenWatchSendBatchJob;
use Dorvianes\OpenWatch\Transport\HttpTransport;
use Throwable;

/**
 * Coordinates flushing the EventBuffer via HttpTransport::sendBatch()
 * or, when async is enabled, via a dispatched Laravel Queue Job.
 *
 * Design constraints:
 * - When batching is disabled, flush() is a no-op.
 * - When the buffer is empty, flush() is a no-op.
 * - When async is enabled, dispatch a Job and skip in-process send.
 * - If dispatch fails, fall back to sync sendBatch() silently.
 * - Calling flush() twice is safe — second call hits an empty buffer.
 */
class BatchFlusher
{
    public function __construct(
        private HttpTransport  $transport,
        private EventBuffer    $buffer,
        private bool           $batchingEnabled = true,
        private bool           $asyncEnabled    = false,
        private ?string        $asyncConnection = null,
        private ?string        $asyncQueue      = null,
        /** @var callable(object): void|null */
        private mixed          $dispatcher      = null,
    ) {}

    /**
     * Flush all buffered events — either via Job dispatch (async) or
     * in-process sendBatch() (sync). Idempotent and exception-safe.
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

        if ($this->asyncEnabled) {
            $this->flushAsync($events);
        } else {
            $this->flushSync($events);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function flushAsync(array $events): void
    {
        $job = (new OpenWatchSendBatchJob($events))
            ->onConnection($this->asyncConnection)
            ->onQueue($this->asyncQueue);

        try {
            $this->doDispatch($job);
        } catch (Throwable) {
            // Dispatch failed (no queue connection, driver not available, etc.)
            // Fall back to sync send so the batch is not silently dropped.
            $this->flushSync($events);
        }
    }

    private function flushSync(array $events): void
    {
        try {
            $this->transport->sendBatch($events);
        } catch (Throwable) {
            // Silent failure — never break the host application.
        }
    }

    private function doDispatch(OpenWatchSendBatchJob $job): void
    {
        if ($this->dispatcher !== null) {
            ($this->dispatcher)($job);
            return;
        }

        // Default: use Laravel's global dispatch() helper if available.
        if (function_exists('dispatch')) {
            dispatch($job);
            return;
        }

        // No dispatcher available — fall through to silence (caller will catch).
        throw new \RuntimeException('No dispatcher available and dispatch() helper not found.');
    }
}
