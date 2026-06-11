# Tasks: Package User Context (v1)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~300-350 |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | exception-ok |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Full implementation | PR 1 | All files, tests, integration — single PR acceptable at ~330 lines |

## Phase 1: Foundation

- [x] 1.1 Create `src/Support/UserContextEnricher.php` with `getUserContext(): array` returning `user_id`, `user_type`, `ip` — all wrapped in try/catch, returning `null` on failure
- [x] 1.2 Create `src/Support/UserContextEnricher.php` with `enrich(array $meta): array` that merges user context into provided meta array

## Phase 2: Core Implementation

- [x] 2.1 Register `UserContextEnricher` as singleton in `src/OpenWatchServiceProvider.php` boot method
- [x] 2.2 Inject `UserContextEnricher` into `src/Recorders/RequestRecorder.php` constructor, call `$this->enricher->enrich($meta)` before building event
- [x] 2.3 Inject `UserContextEnricher` into `src/Recorders/ExceptionRecorder.php` constructor, call `$this->enricher->enrich($meta)` before building event
- [x] 2.4 Inject `UserContextEnricher` into `src/Recorders/QueryRecorder.php` constructor, call `$this->enricher->enrich($meta)` before building event
- [x] 2.5 Inject `UserContextEnricher` into `src/Recorders/OutgoingRequestRecorder.php` constructor, call `$this->enricher->enrich($meta)` before building event

## Phase 3: Testing

- [x] 3.1 Create `tests/Unit/UserContextEnricherTest.php` — test authenticated user yields correct `user_id` and `user_type`
- [x] 3.2 `UserContextEnricherTest` — test unauthenticated request yields `null` for `user_id` and `user_type`
- [x] 3.3 `UserContextEnricherTest` — test HTTP context yields client IP, CLI/queued context yields `null` for `ip`
- [x] 3.4 `UserContextEnricherTest` — mock facades to throw exceptions, verify all fields resolve to `null` silently
- [x] 3.5 Update `tests/Unit/RequestRecorderTest.php` — assert `meta.user_id`, `meta.user_type`, `meta.ip` are present in emitted events
- [x] 3.6 Update `tests/Unit/ExceptionRecorderTest.php` — assert `meta.user_id`, `meta.user_type`, `meta.ip` are present in emitted events
- [x] 3.7 Update `tests/Unit/QueryRecorderTest.php` — assert `meta.user_id`, `meta.user_type`, `meta.ip` are present in emitted events
- [x] 3.8 Update `tests/Unit/OutgoingRequestRecorderTest.php` — assert `meta.user_id`, `meta.user_type`, `meta.ip` are present in emitted events

## Phase 4: Verification

- [x] 4.1 Run `./vendor/bin/phpunit` — all 200+ tests pass, 377+ assertions green