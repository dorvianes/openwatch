<?php

use Dorvianes\OpenWatch\Support\ConfigHelper;

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

    /*
    |--------------------------------------------------------------------------
    | HTTP Connect Timeout
    |--------------------------------------------------------------------------
    | Maximum seconds to wait for the TCP connection to the OpenWatch server.
    | When null, defaults to the total timeout value (safe default).
    | Must be ≤ timeout — if set higher it is clamped automatically.
    |
    | Env: OPENWATCH_CONNECT_TIMEOUT  (default: null = same as timeout)
    */
    'connect_timeout' => env('OPENWATCH_CONNECT_TIMEOUT', null),

    /*
    |--------------------------------------------------------------------------
    | Capture Database Queries
    |--------------------------------------------------------------------------
    | Set to false to disable query telemetry without removing config.
    |
    | Env: OPENWATCH_CAPTURE_QUERIES  (default: true)
    */
    'capture_queries' => env('OPENWATCH_CAPTURE_QUERIES', true),

    /*
    |--------------------------------------------------------------------------
    | Slow Query Threshold
    |--------------------------------------------------------------------------
    | When set, only queries whose execution time exceeds this threshold
    | (in milliseconds) are sent to the server. Leave null to send all queries.
    |
    | Env: OPENWATCH_SLOW_QUERY_THRESHOLD_MS  (default: null = send all)
    */
    'slow_query_threshold_ms' => env('OPENWATCH_SLOW_QUERY_THRESHOLD_MS', null),

    /*
    |--------------------------------------------------------------------------
    | Capture Outgoing HTTP Requests
    |--------------------------------------------------------------------------
    | Set to false to disable outgoing HTTP request telemetry.
    |
    | Env: OPENWATCH_CAPTURE_OUTGOING_REQUESTS  (default: true)
    */
    'capture_outgoing_requests' => env('OPENWATCH_CAPTURE_OUTGOING_REQUESTS', true),

    /*
    |--------------------------------------------------------------------------
    | Slow Outgoing Request Threshold
    |--------------------------------------------------------------------------
    | When set, only outgoing HTTP requests whose duration exceeds this threshold
    | (in milliseconds) are sent to the server. Leave null to send all.
    |
    | Env: OPENWATCH_SLOW_OUTGOING_REQUEST_THRESHOLD_MS  (default: null = send all)
    */
    'slow_outgoing_request_threshold_ms' => env('OPENWATCH_SLOW_OUTGOING_REQUEST_THRESHOLD_MS', null),

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    | When enabled, queries and outgoing requests are accumulated in memory
    | and sent as a single POST to /api/ingest/batch at the end of the request
    | lifecycle (or when the application terminates for CLI/jobs).
    |
    | Default is OFF for safe rollout — enable when ready.
    |
    | Env: OPENWATCH_BATCHING_ENABLED  (default: false)
    | Env: OPENWATCH_BATCHING_MAX_EVENTS  (default: 1000)
    */
    'batching' => [
        'enabled'    => env('OPENWATCH_BATCHING_ENABLED', false),
        'max_events' => (int) env('OPENWATCH_BATCHING_MAX_EVENTS', 1000),

        /*
        |----------------------------------------------------------------------
        | Async Batch Worker
        |----------------------------------------------------------------------
        | When async.enabled is true AND batching.enabled is true, the batch
        | flush will dispatch a Laravel Queue Job (OpenWatchSendBatchJob) instead
        | of calling sendBatch() in-process.
        |
        | IMPORTANT: You must run `php artisan queue:work` in the host application
        | for the Job to be processed. Without a running queue worker, events will
        | accumulate in the queue but never be sent to the OpenWatch server.
        |
        | Env: OPENWATCH_ASYNC_ENABLED     (default: false)
        | Env: OPENWATCH_ASYNC_CONNECTION  (default: null = use default queue connection)
        | Env: OPENWATCH_ASYNC_QUEUE       (default: null = use default queue name)
        */
        'async' => [
            'enabled'    => env('OPENWATCH_ASYNC_ENABLED', false),
            'connection' => env('OPENWATCH_ASYNC_CONNECTION', null),
            'queue'      => env('OPENWATCH_ASYNC_QUEUE', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Hosts
    |--------------------------------------------------------------------------
    | Hosts that OpenWatch will NOT record outgoing HTTP telemetry for.
    | By default this is auto-derived from server_url so the package never
    | captures its own self-reporting traffic.
    |
    | Set OPENWATCH_IGNORED_HOSTS to a comma-separated list to override.
    | An empty string disables all host filtering.
    |
    | Env: OPENWATCH_IGNORED_HOSTS  (default: auto-derived from server_url)
    */
    'ignored_hosts' => ConfigHelper::deriveIgnoredHosts(
        serverUrl: env('OPENWATCH_SERVER_URL', ''),
        override:  getenv('OPENWATCH_IGNORED_HOSTS') !== false ? (string) getenv('OPENWATCH_IGNORED_HOSTS') : null,
    ),
];
