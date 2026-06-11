# Proposal: Package User Context (v1)

## Intent

Production debugging requires knowing **who** triggered a telemetry event. Currently all 4 recorders emit events with `meta{app_name, app_env}` only — no user identity. Adding `user_id`, `user_type`, and `ip` to the meta block of every event closes this gap.

## Scope

### In Scope
- Extract authenticated user ID and type from Laravel's default auth guard
- Extract client IP from the current request
- Inject `user_id`, `user_type`, `ip` into the `meta` block of every event (all 4 recorders + future custom events)
- Centralized injection via a single `UserContextEnricher` support class — not per-recorder copy-paste
- Handle edge cases gracefully: unauthenticated → null fields, CLI/queued jobs → null IP

### Out of Scope
- Custom user events, user-agent fingerprinting, session tracking
- GDPR / PII masking (ingestion server responsibility)
- Changing the flat payload wire contract
- Multi-guard user resolution (default guard only)

## Capabilities

### New Capabilities
- `user-context-enrichment`: centralized resolution and injection of `user_id`, `user_type`, and `ip` into every event's `meta` block

### Modified Capabilities
- `event-contract`: `meta` block grows to include `user_id` (string|null), `user_type` (string|null), `ip` (string|null) — preserving the existing flat payload contract

## Approach

Create a `UserContextEnricher` support class bound as a singleton. It resolves context from `auth()->user()` and `request()->ip()`. Each recorder receives it via constructor injection and merges `$this->enricher->meta()` into its payload's `meta` block. Enrichment is idempotent and never throws — failures resolve to null.

`RecordRequest` middleware already has access to `$request->user()` and `$request->ip()` at terminate time; context is captured per-request naturally.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Support/UserContextEnricher.php` | New | Resolves user_id, user_type, ip from auth + request |
| `src/Recorders/RequestRecorder.php` | Modified | Inject enricher, merge context into meta |
| `src/Recorders/ExceptionRecorder.php` | Modified | Inject enricher, merge context into meta |
| `src/Recorders/QueryRecorder.php` | Modified | Inject enricher, merge context into meta |
| `src/Recorders/OutgoingRequestRecorder.php` | Modified | Inject enricher, merge context into meta |
| `src/OpenWatchServiceProvider.php` | Modified | Bind `UserContextEnricher` singleton, pass to recorders |
| `tests/Unit/` | Modified | Tests for enrichment behavior and edge cases |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Auth facade unavailable early in boot | Low | Wrap in try/catch, return null — never throw |
| PII leakage via user_id in logs/server | Low | Ingestion server handles masking; out of scope here |
| `request()->ip()` returns null on trust-proxy edge case | Low | Fall through to null — documented behavior |

## Rollback Plan

Revert the commit. The new `meta.user_id`, `meta.user_type`, `meta.ip` fields are additive — server ingestion that ignores unknown fields is unaffected. No DB migration, no config flag.

## Dependencies

- None (uses existing Laravel `auth()` and `request()` facades)

## Success Criteria

- [ ] All 4 recorder event types include `meta.user_id`, `meta.user_type`, `meta.ip` when running in an HTTP context with authenticated user
- [ ] CLI and queued-job events include null/absent user fields without errors
- [ ] Unauthenticated requests produce `user_id: null, user_type: null`
- [ ] `./vendor/bin/phpunit` passes all tests
