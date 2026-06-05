# Proposal: Package Contract Hardening (v1)

## Intent

Formalize the telemetry event payload schema emitted by the package. Currently, event recorders produce ad-hoc array structures. Establishing a strict contract (e.g., separating envelope from payload) prevents malformed data, centralizes common metadata, and prepares the groundwork for robust server-side validation.

## Scope

### In Scope
- Introduce a strict top-level event envelope (`id`, `type`, `occurred_at`, `payload`, `context`).
- Update all existing recorders (`QueryRecorder`, `ExceptionRecorder`, `RequestRecorder`, `OutgoingRequestRecorder`) to comply with this structure.
- Centralize `app_name` and `app_env` metadata population into an `EventFactory` or single normalizer.

### Out of Scope
- OpenTelemetry (OTEL) compatibility mapping (deferred).
- Server-side validation logic (to be handled in a separate server-side change).
- Adding new event types or speculative metrics.

## Capabilities

### New Capabilities
- `event-contract`: Defines the strict schema for OpenWatch events (envelope vs payload).

### Modified Capabilities
- `batching`: The payload structure sent in batches changes to conform to the new event contract.

## Approach

Create an `EventFactory` that takes specific domain data and returns a standardized array structure. Recorders will pass their type-specific data to this factory instead of building arrays manually. The transport and buffer will accept arrays that conform to the new contract. 

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `src/Recorders/` | Modified | Recorders will delegate structure creation to the new factory. |
| `src/Support/` | New | Add `EventFactory` to construct valid events. |
| `tests/Unit/` | Modified | Update tests to assert the new nested structure. |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Breaking server ingestion | Low | This change formalizes the contract, ensuring the server knows what to expect before strict server-side validation is implemented. |
| Performance overhead | Low | Use simple factory methods instead of heavy serialization classes. |

## Rollback Plan

Revert the change in Git. Since this is an internal package structure update and no external persistent storage relies on the old format yet, a clean git revert is sufficient.

## Dependencies

- None

## Success Criteria

- [ ] All generated telemetry events have a top-level `id`, `type`, `payload`, `context`, and `occurred_at`.
- [ ] Recorders no longer manually fetch `config('app.name')`.
- [ ] All unit tests pass with the updated structure.
