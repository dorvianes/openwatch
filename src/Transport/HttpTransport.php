<?php

namespace Dorvianes\OpenWatch\Transport;

use Throwable;

class HttpTransport
{
    /** HTTP status code from the last send() call. 0 = no response (curl error / timeout). */
    private int $lastHttpCode = 0;

    /** cURL error message from the last send() call. Empty when no error occurred. */
    private string $lastCurlError = '';

    /** Resolved connect timeout (≤ total timeout). */
    private float $connectTimeout;

    public function __construct(
        private string $serverUrl,
        private string $token,
        private float $timeout = 0.1,
        ?float $connectTimeout = null,
    ) {
        // Clamp: connect timeout must never exceed total timeout
        $this->connectTimeout = min($connectTimeout ?? $timeout, $timeout);
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    public function send(array $payload): bool
    {
        $this->lastHttpCode  = 0;
        $this->lastCurlError = '';

        if (empty($this->serverUrl) || empty($this->token)) {
            return false;
        }

        try {
            $ch = curl_init();

            curl_setopt_array($ch, $this->buildCurlOptions($payload));

            curl_exec($ch);
            $this->lastHttpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->lastCurlError = curl_error($ch);
            curl_close($ch);

            return $this->lastHttpCode === 202;
        } catch (Throwable $e) {
            $this->lastCurlError = $e->getMessage();
            // Silent failure — never break the client app.
            return false;
        }
    }

    /**
     * Send multiple events in a single HTTP POST to /api/ingest/batch.
     *
     * Returns false immediately (no-op) when:
     *  - serverUrl or token are not configured
     *  - the events list is empty
     *
     * @param  array<int, array<string, mixed>> $events
     */
    public function sendBatch(array $events): bool
    {
        if (empty($this->serverUrl) || empty($this->token)) {
            return false;
        }

        if (empty($events)) {
            return false;
        }

        try {
            $ch = curl_init();

            curl_setopt_array($ch, $this->buildBatchCurlOptions($events));

            curl_exec($ch);
            $this->lastHttpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->lastCurlError = curl_error($ch);
            curl_close($ch);

            return $this->lastHttpCode === 202;
        } catch (Throwable $e) {
            $this->lastCurlError = $e->getMessage();
            return false;
        }
    }

    /**
     * Builds the cURL options array for a given payload.
     *
     * Extracted as a protected method so unit tests can verify the exact mapping
     * of timeout values to CURLOPT constants without making real network calls:
     *   - CURLOPT_TIMEOUT_MS        = total timeout (ms) — guards slow server responses
     *   - CURLOPT_CONNECTTIMEOUT_MS = connect timeout (ms) — guards slow TCP handshakes
     *
     * @param  array<string,mixed> $payload
     * @return array<int,mixed>
     */
    protected function buildCurlOptions(array $payload = []): array
    {
        return [
            CURLOPT_URL               => rtrim($this->serverUrl, '/') . '/api/ingest',
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode($payload),
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT_MS        => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
        ];
    }

    /**
     * Builds the cURL options array for a batch payload targeting /api/ingest/batch.
     * Payload structure: { "events": [...] }
     *
     * @param  array<int, array<string, mixed>> $events
     * @return array<int, mixed>
     */
    protected function buildBatchCurlOptions(array $events): array
    {
        return [
            CURLOPT_URL               => rtrim($this->serverUrl, '/') . '/api/ingest/batch',
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode(['events' => $events]),
            CURLOPT_HTTPHEADER        => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT_MS        => (int) ($this->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
        ];
    }

    /**
     * HTTP status code returned by the last send() call.
     * Returns 0 when no HTTP response was received (timeout, DNS failure, etc.).
     */
    public function lastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    /**
     * cURL error string from the last send() call. Empty on success.
     */
    public function lastCurlError(): string
    {
        return $this->lastCurlError;
    }
}
