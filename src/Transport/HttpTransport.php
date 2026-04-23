<?php

namespace Dorvianes\OpenWatch\Transport;

use Throwable;

class HttpTransport
{
    public function __construct(
        private string $serverUrl,
        private string $token,
        private float $timeout = 0.1,
    ) {}

    public function send(array $payload): bool
    {
        if (empty($this->serverUrl) || empty($this->token)) {
            return false;
        }

        try {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL            => rtrim($this->serverUrl, '/') . '/api/ingest',
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->token,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => (int) ($this->timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->timeout * 1000),
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 202;
        } catch (Throwable) {
            // Silent failure — never break the client app.
            return false;
        }
    }
}
