# Tasks: package-hardening-lote-3

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 100–150 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | exception-ok |
| Chain strategy | not-applicable |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: not-applicable
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Add missing field assertions and fix gaps | PR 1 | All tests; no production code |

## Phase 1: Gap Audit & Test Additions

- [x] 1.1 Verify `EventTimestampTest` covers `format(null)` → already exists at `tests/Unit/EventTimestampTest.php:10` — no change needed
- [x] 1.2 Add `test_payload_meta_includes_scheme` to `OutgoingRequestRecorderTest` asserting `meta.scheme` is present and equals `'https'` or `'http'`
- [x] 1.3 Add `test_payload_omits_forbidden_envelope_keys` to `OutgoingRequestRecorderTest` asserting no `id`, `payload`, `context`, `schema_version` in emitted event
- [x] 1.4 Add `test_payload_omits_forbidden_envelope_keys` to `QueryRecorderTest` asserting no `id`, `payload`, `context`, `schema_version` (already exists in Request/Exception — align Query)
- [x] 1.5 Add `test_occurred_at_reflects_event_time_not_send_time` to `OutgoingRequestRecorderTest` (mirrors existing Query/Exception coverage) — already covered by `test_occurred_at_is_valid_iso8601` + `test_occurred_at_reflects_start_time_when_provided`

## Phase 2: Batch Contract Assertion

- [x] 2.1 Add `test_batch_body_is_events_key_only` to `HttpTransportTest` asserting `json_decode($body)` has exactly one top-level key `'events'` with no extra wrapper
- [x] 2.2 Add `test_batch_event_items_contain_no_envelope_keys` to `HttpTransportTest` asserting each item in `events` array lacks `id`, `payload`, `context`, `schema_version`

## Phase 3: Run Full Suite

- [x] 3.1 Run `./vendor/bin/phpunit` — all tests must pass
- [x] 3.2 Verify zero production code changes: `git diff --stat src/`