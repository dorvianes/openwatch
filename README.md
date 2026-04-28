# OpenWatch Laravel Package

Laravel package that automatically records HTTP requests and exceptions and ships them to an [OpenWatch](https://github.com/dorvianes/openwatch) service-server for analysis.

---

## Requirements

- PHP 8.2+
- Laravel 11+
- An OpenWatch service-server instance (self-hosted)

---

## Installation

### Option A — Install directly from GitHub (without Packagist)

Add the repository to your application's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/dorvianes/openwatch.git"
    }
  ]
}
```

Then require the package:

```bash
composer require dorvianes/openwatch:^0.1.0
```

### Option B — If the package is available through Packagist later

```bash
composer require dorvianes/openwatch
```

Publish the config file:

```bash
php artisan vendor:publish --tag=openwatch-config
```

---

## Onboarding flow

> Follow these steps in order. The package stays silently disabled until both `OPENWATCH_SERVER_URL` and `OPENWATCH_TOKEN` are set, so it is safe to install first and configure later.

### 1. Create your app and environment in the OpenWatch UI

Open the OpenWatch server UI and:

1. **Create an Application** — e.g. "My Laravel App"
2. **Create an Environment** — e.g. `local`, `staging`, `production`
3. Navigate to **Ingestion Keys** for that environment → click **New Key**
4. **Copy the token immediately** — it is shown only once

### 2. Configure your `.env`

```dotenv
OPENWATCH_SERVER_URL=https://openwatch.yourdomain.com
OPENWATCH_TOKEN=ow_live_xxxxxxxxxxxxxxxxxxxx   # ingestion key from step 1
OPENWATCH_ENABLED=true
OPENWATCH_TIMEOUT=0.1
```

### 3. Verify connectivity

```bash
php artisan openwatch:send-test
```

A green `✓` confirms the server accepted the event (HTTP 202). If it fails, the command prints a troubleshooting checklist.

### 4. Enable exception reporting

Exception capture requires one additional opt-in step in your `bootstrap/app.php`.
Use the official package helper — it wires into Laravel's exception reporting hook cleanly and never breaks your app if OpenWatch is unavailable:

```php
use Dorvianes\OpenWatch\Support\RegistersExceptionReporting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        RegistersExceptionReporting::register($exceptions);
    })->create();
```

`RegistersExceptionReporting::register()`:
- Resolves the request if one is available (e.g. during an HTTP request), passes `null` otherwise (e.g. CLI commands, queue jobs).
- Swallows every OpenWatch-side failure — your app's own error reporting continues normally.
- Does **not** suppress Laravel's default reporting; your logs and error trackers still receive the exception.

### 5. Generate real telemetry

Visit any route in your app — the package will automatically capture the request and send it to the server. No additional code is required.

---

## Configuration reference

| Key | Env variable | Default | Description |
|-----|-------------|---------|-------------|
| `server_url` | `OPENWATCH_SERVER_URL` | `""` | Base URL of the OpenWatch server |
| `token` | `OPENWATCH_TOKEN` | `""` | Ingestion key (per-environment) |
| `enabled` | `OPENWATCH_ENABLED` | `true` | Master switch |
| `timeout` | `OPENWATCH_TIMEOUT` | `0.1` | HTTP timeout in seconds (keep ≤ 0.5s) |
| `batching.enabled` | `OPENWATCH_BATCHING_ENABLED` | `false` | Buffer events and send as a single batch per request |
| `batching.max_events` | `OPENWATCH_BATCHING_MAX_EVENTS` | `1000` | Maximum events held in memory per request |
| `batching.async.enabled` | `OPENWATCH_ASYNC_ENABLED` | `false` | Dispatch a Queue Job instead of flushing in-process |
| `batching.async.connection` | `OPENWATCH_ASYNC_CONNECTION` | `null` | Queue connection (null = app default) |
| `batching.async.queue` | `OPENWATCH_ASYNC_QUEUE` | `null` | Queue name (null = app default) |

The package **auto-disables** and fails silently when `server_url` or `token` is empty — your app is never affected.

---

## Batching

When `OPENWATCH_BATCHING_ENABLED=true`, events are buffered in memory and sent as a single HTTP POST to `/api/ingest/batch` at the end of each request (or when the PHP process terminates for CLI/jobs).

This reduces network overhead on high-traffic applications compared to the default per-event mode.

### Async batching (Queue Worker)

When both batching and async are enabled, the flush is offloaded to a Laravel Queue Job (`OpenWatchSendBatchJob`) instead of running in-process. This removes all telemetry latency from the request lifecycle.

**Setup:**

```dotenv
# .env
OPENWATCH_BATCHING_ENABLED=true
OPENWATCH_ASYNC_ENABLED=true
OPENWATCH_ASYNC_CONNECTION=redis       # optional — leave empty for app default
OPENWATCH_ASYNC_QUEUE=openwatch        # optional — leave empty for default queue
```

> **IMPORTANT — Queue worker required:**
> You must run `php artisan queue:work` (or `queue:listen`) in the host application for the Job to be processed. Without a running worker, events will accumulate in the queue but will **not** be delivered to the OpenWatch server.

```bash
# Start the queue worker in the host app
php artisan queue:work --queue=openwatch
```

**Fallback behavior:** If the Job dispatch fails (e.g., Redis is unavailable), the batch is automatically sent synchronously in the same process. Events are never silently dropped.

---

## What gets recorded

### HTTP Requests

Every web and API request after the response is sent:

| Field | Description |
|-------|-------------|
| `type` | `"request"` |
| `method` | HTTP verb |
| `path` | Request path |
| `host` | Server hostname |
| `status` | HTTP status code |
| `duration_ms` | Total request duration |
| `ip` | Client IP |
| `user_agent_class` | `browser` / `bot` / `api-client` / `other` / `unknown` |
| `memory_peak_mb` | Peak PHP memory usage |
| `occurred_at` | ISO 8601 timestamp |
| `meta.app_name` | Value of `APP_NAME` |
| `meta.app_env` | Value of `APP_ENV` |

### Exceptions

Unhandled exceptions are recorded with:

| Field | Description |
|-------|-------------|
| `type` | `"exception"` |
| `class` | Exception class name |
| `message` | Truncated at 500 chars |
| `file` / `line` | Origin location |
| `request.path` / `request.method` | Associated request |
| `occurred_at` | ISO 8601 timestamp |
| `meta.app_name` / `meta.app_env` | App telemetry context |

---

## Silent failure guarantee

All network errors, timeouts, and HTTP error responses are caught internally. The package **never throws** and **never breaks your application**.

---

## Artisan commands

| Command | Description |
|---------|-------------|
| `openwatch:send-test` | Send a synthetic event to verify configuration and connectivity |

---

## License

MIT
