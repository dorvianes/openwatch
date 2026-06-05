# Exploration: package-contract-hardening-v1

**Date**: 2026-04-29  
**Mode**: Hybrid target (filesystem + Engram; Engram may be ambiguous from workspace root)  
**Repo in scope**: `C:\Proyectos\Dorvian v1\openwatch\package`  
**Verdict**: the intended first slice should be renamed/split to `package-hardening-lote-3`; do **not** implement the current broad envelope proposal as the first slice.

---

## Current State

The real package currently emits a **flat wire payload**, not an envelope:

| Event type | Source | Current top-level shape | Delivery |
|---|---|---|---|
| `request` | `src/Recorders/RequestRecorder.php` | `type`, request fields, `occurred_at`, `started_at`, `finished_at`, `meta{app_name,app_env}` | sync `send()` |
| `exception` | `src/Recorders/ExceptionRecorder.php` | `type`, exception fields, optional request context, previous chain, `occurred_at`, `meta{app_name,app_env}` | sync `send()` |
| `query` | `src/Recorders/QueryRecorder.php` | `type`, `sql`, `connection`, `duration_ms`, `occurred_at`, `meta{app_name,app_env}` | sync `send()` or buffered batch |
| `outgoing_request` | `src/Recorders/OutgoingRequestRecorder.php` | `type`, `host`, `method`, `path`, `status`, `duration_ms`, `occurred_at`, `url`, `meta{scheme,app_name,app_env}` | sync `send()` or buffered batch |

Transport state verified in code:

- `HttpTransport::send()` posts the flat event directly to `/api/ingest`.
- `HttpTransport::sendBatch()` posts `{ "events": [ ...flat events... ] }` to `/api/ingest/batch`.
- `EventTimestamp::format(null)` is already explicitly implemented as current time with timezone offset.
- `OutgoingRequestRecorder` calls `EventTimestamp::format($startTime)` where `$startTime` may be `null`; existing test coverage verifies the emitted `occurred_at` is an ISO-8601 string when no start time is supplied.
- `HttpTransportTest::test_send_batch_wraps_events_in_events_key()` already asserts the batch wrapper key and preserves per-event `type` values.

Completed/prior package slices visible in repo:

- `package-hardening-lote-1`: ignored host derivation + connect timeout separation (`sdd/` only).
- `package-hardening-lote-2`: SQL normalization (`sdd/` only).
- `package-transport-batching-lote-1`: batch endpoint and buffer groundwork (`sdd/` only).
- `package-async-worker-lote-1`: archived OpenSpec change for async batch Job dispatch; promoted specs exist under `openspec/specs/batching` and `openspec/specs/config`.

---

## Re-run Findings vs Previous Exploration

The earlier exploration correctly warned that `package-contract-hardening-v1` was too broad, but two previously proposed “narrow” gaps are no longer good first-slice targets as written:

1. **Outgoing `occurred_at` null path is not an implementation gap now.**  
   `EventTimestamp::format(null)` already returns current time, and `OutgoingRequestRecorderTest::test_occurred_at_is_valid_iso8601()` covers recording without a supplied start time.

2. **Batch payload wrapper is not a missing test now.**  
   `HttpTransportTest::test_send_batch_wraps_events_in_events_key()` already checks the outer `events` key and validates the event `type` values survive inside the batch body.

3. **The broad envelope proposal/spec drifted beyond the current package/server contract.**  
   The active proposal/spec require top-level `id`, `payload`, and `context`, but real code, README snippets, and existing tests are built around flat payloads with `meta`. Implementing the envelope first would be a wire-contract break unless coordinated with the ingest server.

---

## Explicit Scope Answers

### 1. Is the first slice still best scoped as a small package-only hardening increment?

**Yes.** The next package slice should stay package-only and small. It should codify and protect the **current** flat payload/batch contract instead of introducing a new envelope. No server, demo app, or multi-repo change is justified for this first slice.

### 2. Should this change keep the name `package-contract-hardening-v1`, or be split/renamed?

**Split/rename.** Do not keep `package-contract-hardening-v1` as the executable first slice. Use `package-hardening-lote-3` for the next implementation slice, with a tighter title such as “current wire contract hardening”.

Reason: the existing name and proposal imply a v1 envelope migration. That is not the right first slice and would encourage implementing `id/payload/context` before server coordination.

### 3. Which exact behaviors should be in scope NOW, and which should be deferred?

#### In scope NOW — `package-hardening-lote-3`

- Preserve the current flat event wire contract for all four event types.
- Add/keep unit tests that assert required top-level fields for each recorder’s emitted payload.
- Add direct unit coverage for `EventTimestamp::format(null)` returning a non-null ISO-8601 timestamp with timezone offset, even though recorder-level coverage already exists.
- Keep batch body shape as exactly `{ "events": [...] }` and assert events are passed through without adding an extra wrapper.
- Document in the proposal/spec that `meta.app_name` and `meta.app_env` remain the current shared metadata location; `outgoing_request` may include `meta.scheme`.
- Explicitly assert this slice must not introduce `id`, `payload`, `context`, or `schema_version` into emitted events.

#### Deferred

- New v1 envelope with top-level `id`, `payload`, and `context`.
- `EventFactory` / shared event builder refactor across all recorders.
- `schema_version` or `event_version` fields.
- Server-side strict validation or ingest migrations.
- Renaming `meta` to `context`.
- Any multi-repo synchronization with `cliente-demo` or the service server.
- OpenTelemetry mapping.

### 4. Does any part of the previous broad envelope proposal remain justified for the FIRST slice?

**Only the motivation remains justified, not the implementation envelope.** The useful part is the desire to make event payload contracts explicit and test-protected. The specific broad implementation — `id`, `payload`, `context`, `EventFactory`, and batch envelope migration — is not justified for the first slice because it conflicts with the package’s current flat wire shape and would risk breaking ingestion.

---

## Affected Areas

- `openspec/changes/package-contract-hardening-v1/proposal.md` — should be replaced or superseded by a narrower `package-hardening-lote-3` proposal.
- `openspec/changes/package-contract-hardening-v1/specs/event-contract/spec.md` — current envelope requirements should not drive the first slice.
- `openspec/changes/package-contract-hardening-v1/specs/batching/spec.md` — current “batches contain v1 envelopes” requirement should be deferred/reworked.
- `src/Support/EventTimestamp.php` — candidate for direct null-format contract tests only.
- `src/Recorders/*.php` — current flat payload behavior should be protected, not refactored, in the first slice.
- `src/Transport/HttpTransport.php` — batch wrapper behavior is already implemented; keep it stable.
- `tests/Unit/*RecorderTest.php` and `tests/Unit/HttpTransportTest.php` — primary place for first-slice hardening.

---

## Approaches

### Approach A — Rename to `package-hardening-lote-3` and protect the current flat wire contract

- **Pros**: non-breaking, package-only, aligns with existing lote history, mostly test/spec hardening, avoids server coordination.
- **Cons**: does not deliver the cleaner envelope architecture yet.
- **Effort**: Low.

### Approach B — Keep `package-contract-hardening-v1` but narrow its proposal/spec in place

- **Pros**: avoids creating a new change folder.
- **Cons**: name remains misleading; existing artifacts already describe a broad envelope migration and are easy to misapply.
- **Effort**: Low-Medium.

### Approach C — Implement the current broad envelope proposal

- **Pros**: cleaner long-term event model if server accepts it.
- **Cons**: likely wire-contract break; touches all recorders and batching at once; not justified as a first slice.
- **Effort**: Medium-High.

---

## Recommendation

Proceed with **Approach A**:

1. Treat `package-contract-hardening-v1` as an umbrella/superseded exploration artifact, not the implementation change.
2. Create/continue a smaller executable change named **`package-hardening-lote-3`**.
3. Rewrite the proposal/spec around the **current flat wire contract**, with explicit deferral of `id/payload/context`, `EventFactory`, and version fields.
4. Keep strict TDD: any missing contract assertion should be added with PHPUnit before/alongside implementation, using `./vendor/bin/phpunit`; do not build.

---

## Risks

- If the broad spec remains active, an apply phase may accidentally implement a breaking envelope migration.
- Existing tests already cover parts of the suggested narrow scope; the next phase must avoid duplicating assertions without adding contract value.
- A future envelope/version migration will need server-side agreement; package-only changes cannot safely invent a new wire shape.
- Engram persistence may fail from the workspace root because multiple projects are detected; filesystem OpenSpec artifacts remain the reliable source for this exploration.

---

## Ready for Proposal

**Yes — but for `package-hardening-lote-3`, not for the existing broad `package-contract-hardening-v1` proposal.**

The orchestrator should either create a new `package-hardening-lote-3` change or explicitly supersede/rewrite the current proposal/spec before any design/tasks/apply phase.
