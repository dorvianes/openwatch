<?php

namespace Dorvianes\OpenWatch\Transport;

use Throwable;

class HttpTransport
{
    /** HTTP status code from the last send() call. 0 = no response (curl error / timeout). */
    private int $lastHttpCode = 0;

    /** cURL error message from the last send() call. Empty when no error occurred. */
    private string $lastCurlError = '';

    public function __construct(
        private string $serverUrl,
        private string $token,
        private float $timeout = 0.1,
    ) {}

    public function send(array $payload): bool
    {
        $this->lastHttpCode  = 0;
        $this->lastCurlError = '';

        if (empty($this->serverUrl) || empty($this->token)) {
            return false;
        }

        try {
            $ch = curl_init();

            curl_setopt_array($ch, [
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
                CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->timeout * 1000),
            ]);

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
