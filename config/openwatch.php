<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenWatch Server URL
    |--------------------------------------------------------------------------
    | Base URL of the OpenWatch service-server that receives telemetry events.
    |
    | Example: https://openwatch.yourdomain.com
    |
    | Env: OPENWATCH_SERVER_URL
    */
    'server_url' => env('OPENWATCH_SERVER_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | OpenWatch Ingestion Token
    |--------------------------------------------------------------------------
    | Bearer token used to authenticate with the OpenWatch ingest endpoint.
    |
    | This is an INGESTION KEY generated in the OpenWatch server UI:
    |   OpenWatch UI → Your Application → Environment → Ingestion Keys → New Key
    |
    | Each environment (local, staging, production) should have its own key.
    | The key is shown ONCE at creation time — copy it immediately.
    |
    | Env: OPENWATCH_TOKEN
    */
    'token' => env('OPENWATCH_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Enable / Disable OpenWatch
    |--------------------------------------------------------------------------
    | Set to false to disable telemetry without removing config.
    | The package also auto-disables if server_url or token is missing.
    |
    | Env: OPENWATCH_ENABLED  (default: true)
    */
    'enabled' => env('OPENWATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    | Maximum seconds to wait for the OpenWatch server on each event delivery.
    | Keep this low (≤ 0.5s) so a slow server never impacts response time.
    |
    | Env: OPENWATCH_TIMEOUT  (default: 0.1 = 100ms)
    */
    'timeout' => env('OPENWATCH_TIMEOUT', 0.1),
];
