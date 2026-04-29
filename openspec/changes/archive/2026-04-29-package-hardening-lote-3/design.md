# Design: package-hardening-lote-3

## Technical Approach

This slice hardens the package's existing **flat wire contract** with tests first, not a schema redesign. The current code already sends flat recorder payloads through `HttpTransport::send()` and batches via `HttpTransport::sendBatch()` as `{ "events": [...] }`; implementation should only change if a new RED test proves a gap. The primary work is boundary-level PHPUnit coverage for timestamps, batch shape, event `type` preservation, and minimal shared metadata assertions.

## Architecture Decisions

| Decision | Choice | Alternatives considered | Rationale |
|---|---|---|---|
| Preserve flat payloads | Keep recorder payloads as top-level event fields plus `meta` | Introduce `id/payload/context` envelope now | Current repo, tests, and ingest transport already use flat events; changing now risks breaking server ingestion. |
| Test hardening over refactor | Add/strengthen PHPUnit assertions around existing boundaries | Create `EventFactory` or shared event builder | The proposal is a first small package-only slice; refactoring all recorders would increase blast radius without changing behavior. |
| Direct timestamp contract | Add `EventTimestamp::format(null)` unit coverage | Rely only on recorder-level `outgoing_request` test | `OutgoingRequestRecorder` accepts omitted `$startTime`; direct support test makes the null behavior explicit and reusable. |
| Batch wrapper protection | Keep `sendBatch()` body exactly `{events: [...]}` and assert no extra inner envelope | Wrap each batch item in a new schema envelope | Existing `HttpTransport::buildBatchCurlOptions()` already encodes `['events' => $events]`; this slice protects that contract. |

## Data Flow

Single event:

    Recorder ──flat payload──→ HttpTransport::send() ──JSON event──→ /api/ingest

Batch event:

    Query/Outgoing Recorder ──flat payload──→ EventBuffer
    EventBuffer::all() ──events[]──→ HttpTransport::sendBatch()
    sendBatch() ──{"events":[flat events]}──→ /api/ingest/batch

For `outgoing_request`, omitted `$startTime` flows to `EventTimestamp::format(null)`, which currently returns an ISO-8601 timestamp using current time and timezone offset.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `tests/Unit/EventTimestampTest.php` | Create | Direct RED test that `EventTimestamp::format(null)` returns a non-empty ISO-8601 string with timezone offset. |
| `tests/Unit/OutgoingRequestRecorderTest.php` | Modify | Strengthen omitted-start-time contract and assert flat keys remain top-level without `id`, `payload`, `context`, or `schema_version`. |
| `tests/Unit/HttpTransportTest.php` | Modify | Strengthen batch wrapper test to assert only top-level `events`, event `type` preservation, and no per-event envelope wrapping. |
| `tests/Unit/RequestRecorderTest.php` | Modify | Minimal shared-field assertions if low-churn: `type`, `occurred_at`, `meta.app_name`, `meta.app_env`, and forbidden envelope keys. |
| `tests/Unit/ExceptionRecorderTest.php` | Modify | Same minimal flat-contract assertions for exception payload. |
| `tests/Unit/QueryRecorderTest.php` | Modify | Same minimal flat-contract assertions for query payload, including batched payload if reuse is simple. |
| `src/Support/EventTimestamp.php` | Modify only if tests fail | Preserve current null fallback behavior. |
| `src/Recorders/*.php`, `src/Transport/HttpTransport.php` | Modify only if tests fail | No planned structural changes. |

## Interfaces / Contracts

No new public interfaces. Contract protected by tests:

- Events remain flat arrays with top-level `type`.
- Shared metadata remains under `meta.app_name` and `meta.app_env`; `outgoing_request` may also include `meta.scheme`.
- Emitted events in this slice MUST NOT add `id`, `payload`, `context`, `schema_version`, or per-event batch envelopes.
- Batch request body remains exactly one outer wrapper key: `events`.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `EventTimestamp::format(null)` | New PHPUnit test; assert parseable ISO-8601 string with timezone offset. |
| Unit | Recorder flat payloads | Capture transport payloads with existing anonymous `HttpTransport` spies; assert top-level required fields and forbidden envelope keys. |
| Unit | Batch wrapper and type preservation | Expose `buildBatchCurlOptions()` via existing test helper; decode JSON and assert wrapper/content shape. |
| Integration/E2E | Not available | Project config exposes unit suite only. |

Strict TDD: add failing PHPUnit tests first, run `./vendor/bin/phpunit` for evidence, then make only minimal source changes if required. Do not build.

## Migration / Rollout

No migration required. This is a package-only contract-hardening slice with no server, database, config, or multi-repo rollout.

## Deferred

- `id/payload/context` event envelope.
- `schema_version` or `event_version` fields.
- `EventFactory` or shared event-builder refactor.
- Renaming `meta` to `context`.
- Server-side ingest validation and multi-repo synchronization.

## Open Questions

None blocking.
