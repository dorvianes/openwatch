# Proposal: package-hardening-lote-3

## Intent
Protect and explicitly test the current flat wire event contract for the OpenWatch package without breaking ingestion, deferring larger envelope migrations until server coordination is possible.

## Scope

### In Scope
- Protect the current flat event wire contract for all 4 event types (`request`, `exception`, `query`, `outgoing_request`).
- Add or keep unit tests asserting required top-level fields for each recorder.
- Add direct unit test for `EventTimestamp::format(null)` returning a current ISO-8601 string.
- Assert batch body shape `{ "events": [...] }` without extra inner envelopes.
- Explicitly prevent the introduction of `id`, `payload`, `context`, or `schema_version` in this phase.

### Out of Scope
- Introducing v1 envelope with top-level `id`, `payload`, `context`.
- `schema_version` or `event_version` fields.
- `EventFactory` or shared event builder refactor.
- Renaming `meta` to `context`.
- Server-side ingest modifications.
- Multi-repo synchronization.

## Capabilities

### New Capabilities
None

### Modified Capabilities
- `event-contract`: Shift requirements to protect the existing flat shape and defer the envelope structure.
- `batching`: Assert that events inside a batch remain flat, deferring the idea of "batches contain v1 envelopes".

## Approach
Write and enforce tests that reflect the current reality of the wire payloads sent to `HttpTransport`. We will explicitly test `EventTimestamp` handling of null to guarantee the `occurred_at` constraint. We will run `phpunit` to verify these tests pass without needing destructive application logic changes, making only minimal tweaks if required to satisfy the explicitly stated flat contract. We avoid structural refactoring.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `tests/Unit/Recorders/*` | Modified | Add/strengthen flat payload assertions |
| `tests/Unit/HttpTransportTest.php` | Modified | Validate batch wrapper shape and content preservation |
| `tests/Unit/Support/EventTimestampTest.php` | New | Ensure `EventTimestamp::format(null)` is explicitly tested |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Test duplication | Medium | Focus tests strictly on the boundary payload array shape, not internal logic. |
| Unintended schema change | Low | Explicitly forbidding `id/payload` prevents altering what the server receives. |

## Rollback Plan
Revert changes to `tests/` and any minor adjustments in `src/`. No database migrations or server changes exist, so a simple git reset is completely safe.

## Dependencies
- None

## Success Criteria
- [ ] `./vendor/bin/phpunit` runs successfully with new tests asserting flat payload contracts.
- [ ] No `id`, `payload`, or `schema_version` keys are emitted by any recorder or transport.