# Apply Progress: package-hardening-lote-3

**Mode**: Strict TDD
**Scope**: Package-only flat wire contract hardening

## Completed Tasks

- [x] 1.1 RED — Created `tests/Unit/EventTimestampTest.php` for `EventTimestamp::format(null)` returning a non-empty ISO-8601 string with timezone offset.
- [x] 1.2 GREEN — No source changes required; existing `src/Support/EventTimestamp.php` already satisfies the null timestamp contract.
- [x] 1.3 VERIFY — Ran `./vendor/bin/phpunit tests/Unit/EventTimestampTest.php` successfully.
- [x] 2.1 RED — Extended `tests/Unit/OutgoingRequestRecorderTest.php` with flat top-level `type` / `occurred_at`, `meta` shape, `meta.scheme`, and forbidden envelope-key assertions.
- [x] 2.2 GREEN — No source changes required for `OutgoingRequestRecorder`.
- [x] 2.3 RED — Added flat-contract and forbidden-key assertions to `RequestRecorderTest`, `ExceptionRecorderTest`, and `QueryRecorderTest`.
- [x] 2.4 GREEN — No recorder source changes required.
- [x] 2.5 VERIFY — Ran recorder PHPUnit targets successfully.
- [x] 3.1 RED — Strengthened `HttpTransportTest` batch wrapper assertion to require exactly `{ "events": [...] }`, preserve event `type`, and forbid per-item deferred envelope keys.
- [x] 3.2 GREEN — No `HttpTransport` source changes required.
- [x] 3.3 VERIFY — Ran `HttpTransportTest` and full PHPUnit suite successfully.

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1–1.3 | `tests/Unit/EventTimestampTest.php` | Unit | N/A (new test file) | ✅ Written first for null timestamp support | ✅ `./vendor/bin/phpunit tests/Unit/EventTimestampTest.php` passed: 1 test, 3 assertions | ➖ Single explicit null-fallback scenario from spec | ➖ None needed; no source changes |
| 2.1–2.5 | `tests/Unit/OutgoingRequestRecorderTest.php`, `tests/Unit/RequestRecorderTest.php`, `tests/Unit/ExceptionRecorderTest.php`, `tests/Unit/QueryRecorderTest.php` | Unit | ✅ Baseline relevant suite passed: 76 tests, 129 assertions | ✅ Added recorder flat-contract assertions before any source change | ✅ Recorder targets passed: 57 tests, 123 assertions | ✅ Covered all four event types plus outgoing `meta.scheme` edge | ➖ None needed; production code already matched design |
| 3.1–3.3 | `tests/Unit/HttpTransportTest.php` | Unit | ✅ Baseline relevant suite passed: 76 tests, 129 assertions | ✅ Strengthened batch wrapper and per-item forbidden-key assertions before any source change | ✅ `./vendor/bin/phpunit tests/Unit/HttpTransportTest.php` passed: 23 tests, 51 assertions | ✅ Batch test uses multiple event types to prove type preservation and exact events list | ➖ None needed; production code already matched design |

## Test Summary

- **Relevant baseline before changes**: `./vendor/bin/phpunit tests/Unit/OutgoingRequestRecorderTest.php tests/Unit/RequestRecorderTest.php tests/Unit/ExceptionRecorderTest.php tests/Unit/QueryRecorderTest.php tests/Unit/HttpTransportTest.php` → 76 tests, 129 assertions.
- **Timestamp target**: `./vendor/bin/phpunit tests/Unit/EventTimestampTest.php` → 1 test, 3 assertions.
- **Recorder targets**: `./vendor/bin/phpunit tests/Unit/OutgoingRequestRecorderTest.php tests/Unit/RequestRecorderTest.php tests/Unit/ExceptionRecorderTest.php tests/Unit/QueryRecorderTest.php` → 57 tests, 123 assertions.
- **Transport target**: `./vendor/bin/phpunit tests/Unit/HttpTransportTest.php` → 23 tests, 51 assertions.
- **Full suite**: `./vendor/bin/phpunit` → 192 tests, 338 assertions.
- **Total tests added**: 6.
- **Layers used**: Unit only.
- **Approval tests**: None — no production refactor performed.
- **Pure functions created**: 0.

## Files Changed

| File | Action | What Was Done |
|------|--------|---------------|
| `tests/Unit/EventTimestampTest.php` | Created | Added direct null timestamp contract test. |
| `tests/Unit/OutgoingRequestRecorderTest.php` | Modified | Added flat wire contract assertions for `outgoing_request`. |
| `tests/Unit/RequestRecorderTest.php` | Modified | Added flat wire contract assertions for `request`. |
| `tests/Unit/ExceptionRecorderTest.php` | Modified | Added flat wire contract assertions for `exception`. |
| `tests/Unit/QueryRecorderTest.php` | Modified | Added flat wire contract assertions for `query`. |
| `tests/Unit/HttpTransportTest.php` | Modified | Strengthened batch body exact-shape and per-item no-envelope assertions. |
| `openspec/changes/package-hardening-lote-3/tasks.md` | Modified | Marked all implementation and verification tasks complete. |
| `sdd/package-hardening-lote-3/apply-progress.md` | Created | Recorded strict TDD evidence and verification results. |

## Deviations from Design

- The design table named `tests/Unit/Support/EventTimestampTest.php`, but the existing PHPUnit unit test convention is flat under `tests/Unit/`; the implemented file is `tests/Unit/EventTimestampTest.php` so it is auto-discovered with the current suite.

## Issues Found

- None.

## Remaining Tasks

- None — all tasks in `package-hardening-lote-3` are complete.

## Status

11/11 tasks complete. Ready for verify.
