# Design: Package User Context (v1)

## Technical Approach

Create a centralized `UserContextEnricher` support class, bound as a singleton in the service container. It resolves context from Laravel's `auth` and `request` facades lazily at call time. All four recorders will receive this enricher via constructor injection and use it to append `user_id`, `user_type`, and `ip` to the `meta` block before emitting any telemetry event. All facade calls are wrapped in `try/catch` to ensure absolute resilience.

## Architecture Decisions

### Decision: Singleton Service Container Binding

**Choice**: Bind `UserContextEnricher` as a singleton in the `OpenWatchServiceProvider`.
**Alternatives considered**: Instantiating it in every recorder, or resolving context redundantly in each method.
**Rationale**: Ensures consistent user context resolution throughout the application lifecycle. Caching the context internally during a single request prevents multiple identical lookups for batch events (e.g., hundreds of queries).

### Decision: Lazy Context Resolution (Call-Time)

**Choice**: Resolve user and IP at call-time (`enrich()` or `getUserContext()`), rather than in the constructor.
**Alternatives considered**: Resolve in the constructor during dependency injection.
**Rationale**: At the time the recorders (and thus the enricher) are instantiated in the Service Provider, the HTTP request, session, and `auth` facades are typically not fully booted. Resolving lazily ensures context is available when the event actually occurs.

### Decision: Separate Support Class (vs Middleware Injection)

**Choice**: Use an independent `UserContextEnricher` support class injected into recorders.
**Alternatives considered**: Extract the context inside `RecordRequest` middleware and pass it down.
**Rationale**: Events like queries and exceptions are frequently fired from CLI commands, queued jobs, or early boot stages where HTTP middleware never runs. A standalone enricher ensures we safely resolve (or gracefully fallback to null) in any environment.

### Decision: Meta Block Payload Structure

**Choice**: Append `user_id`, `user_type`, and `ip` to the existing `meta` property object.
**Alternatives considered**: Create new top-level schema fields.
**Rationale**: Preserves the flat wire contract payload structure. It defers the schema validation and handling to the ingest server, treating these fields as standard enriched metadata without breaking existing backend ingestion logic.

## Data Flow

    Application Event ──→ Recorder (Request/Exception/Query/Outgoing)
                               │
                               ├──→ UserContextEnricher::enrich($meta)
                               │      ├── auth()->user()
                               │      └── request()->ip()
                               │
                               └──→ Emits Payload (with enriched meta) ──→ Transport

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/Support/UserContextEnricher.php` | Create | Support class with `enrich()` and `getUserContext()` methods. |
| `src/Recorders/RequestRecorder.php` | Modify | Inject `UserContextEnricher` via constructor, call `$this->enricher->enrich($meta)`. |
| `src/Recorders/ExceptionRecorder.php` | Modify | Inject `UserContextEnricher` via constructor, call `$this->enricher->enrich($meta)`. |
| `src/Recorders/QueryRecorder.php` | Modify | Inject `UserContextEnricher` via constructor, call `$this->enricher->enrich($meta)`. |
| `src/Recorders/OutgoingRequestRecorder.php` | Modify | Inject `UserContextEnricher` via constructor, call `$this->enricher->enrich($meta)`. |
| `src/OpenWatchServiceProvider.php` | Modify | Bind `UserContextEnricher` as singleton; inject it into the four recorders during their registration. |

## Interfaces / Contracts

```php
namespace Dorvianes\OpenWatch\Support;

class UserContextEnricher
{
    /**
     * Resolves context and merges it into the provided meta array.
     */
    public function enrich(array $meta): array;

    /**
     * Retrieves the isolated user context array.
     */
    public function getUserContext(): array;
}
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `UserContextEnricher` behavior | Test authenticated user yields correct `id`/`type`, unauthenticated yields `null`. |
| Unit | `UserContextEnricher` environment | Test HTTP request context yields IP, CLI/queued yields `null`. |
| Unit | `UserContextEnricher` resilience | Mock facades to throw exceptions and ensure it resolves to `null` silently. |
| Integration | Recorders | Ensure the `meta` block output matches expected values and includes `user_id`, `user_type`, and `ip`. |

## Migration / Rollout

No migration required. The fields are safely appended to the `meta` object.

## Open Questions

- None
