# Proposal: Current Wire Contract Hardening

## Intent

Codify and protect the package's CURRENT flat wire contract with explicit unit test assertions. The `event-contract` and `batching` specs are already promoted; gaps remain in direct test coverage. This change adds the missing PHPUnit assertions to make the contract enforceable, preventing accidental wire-contract drift before a future coordinated envelope migration.

## Scope

### In Scope

- Add `EventTimestampTest` with direct `format(null)` ISO-8601 contract assertion
- Add per-recorder field-existence tests for request, exception, query, outgoing_request
- Assert batch wrapper remains `{ "events": [...] }` without extra envelope
- Document `meta.app_name`, `meta.app_env`, `meta.scheme` as current metadata shape
- Explicitly assert NO `id`, `payload`, `context`, `schema_version` in emitted events

### Out of Scope

- v1 envelope (id/payload/context), `EventFactory`, `schema_version`
- Renaming `meta` to `context`
- Server-side validation or ingest changes
- Multi-repo sync with `cliente-demo` or service server

## Capabilities

### New Capabilities

None — all required specs (`event-contract`, `batching`, `config`) already exist and are promoted.

### Modified Capabilities

None — spec-level requirements do not change. This is test-hardening only: adding missing PHPUnit assertions to enforce already-specified contract behaviors.

## Approach

Approach A from exploration: test-only hardening, zero production code changes.

1. **`EventTimestampTest`** — new test class asserting `format(null)` returns non-null ISO-8601 with timezone offset
2. **Recorder field-existence assertions** — add per-recorder tests verifying required top-level keys per event type
3. **Batch wrapper assertion** — verify existing `HttpTransportTest` coverage; add only if gaps found
4. **Envelope-field prohibition** — each test fixture and assertion MUST NOT reference `id`, `payload`, `context`, or `schema_version`

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `tests/Unit/Support/EventTimestampTest.php` | New | Direct null-format contract test |
| `tests/Unit/Recorders/RequestRecorderTest.php` | Modified | Field-existence assertions |
| `tests/Unit/Recorders/ExceptionRecorderTest.php` | Modified | Field-existence assertions |
| `tests/Unit/Recorders/QueryRecorderTest.php` | Modified | Field-existence assertions |
| `tests/Unit/Recorders/OutgoingRequestRecorderTest.php` | Modified | Field-existence assertions |
| `tests/Unit/Transport/HttpTransportTest.php` | Verified | Batch wrapper already covered |
| `src/Support/EventTimestamp.php` | None | Already correct |
| `src/Recorders/*.php` | None | No production code changes |
| `src/Transport/HttpTransport.php` | None | No production code changes |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Test duplication with existing assertions | Low | Audit existing tests first; add only missing coverage |
| Broad `package-contract-hardening-v1` mistakenly executed | Low | Supersede broad proposal; rename to lote-3 |

## Rollback Plan

Revert test files via `git revert`. No production code is modified — rollback is a single commit reversal with zero runtime impact.

## Dependencies

- `package-contract-hardening-v1` proposal marked superseded/archived
- PHPUnit 11 (`./vendor/bin/phpunit`)

## Success Criteria

- [ ] `EventTimestampTest::test_format_null_returns_iso8601_with_timezone()` passes
- [ ] Each recorder test asserts required top-level fields for its event type
- [ ] No `id`, `payload`, `context`, `schema_version` appear in any event test fixture or assertion
- [ ] Batch test asserts `events` key with no extra wrapper
- [ ] All 18+ existing tests continue passing; zero production code changes
