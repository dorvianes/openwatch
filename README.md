# OpenWatch Laravel Package

Laravel package that automatically records HTTP requests and exceptions and ships them to an [OpenWatch](https://github.com/dorvianes/openwatch) service-server for analysis.

---

## Requirements

- PHP 8.2+
- Laravel 11+
- An OpenWatch service-server instance (self-hosted)

---

## Installation

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

### 4. Generate real telemetry

Visit any route in your app — the package will automatically capture the request and send it to the server. No additional code is required.

---

## Configuration reference

| Key | Env variable | Default | Description |
|-----|-------------|---------|-------------|
| `server_url` | `OPENWATCH_SERVER_URL` | `""` | Base URL of the OpenWatch server |
| `token` | `OPENWATCH_TOKEN` | `""` | Ingestion key (per-environment) |
| `enabled` | `OPENWATCH_ENABLED` | `true` | Master switch |
| `timeout` | `OPENWATCH_TIMEOUT` | `0.1` | HTTP timeout in seconds (keep ≤ 0.5s) |

The package **auto-disables** and fails silently when `server_url` or `token` is empty — your app is never affected.

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
