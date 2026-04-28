# Tasks: package-hardening-lote-2

## Phase 1: SqlNormalizer core

- [x] 1.1 RED — Add `tests/Unit/SqlNormalizerTest.php` covering whitespace collapse, trim, `IN (?+)`, non-placeholder `IN`, empty string, idempotence.
- [x] 1.2 GREEN — Implement `src/Support/SqlNormalizer.php` conservatively.

## Phase 2: QueryRecorder integration

- [x] 2.1 RED — Extend `tests/Unit/QueryRecorderTest.php` for normalized SQL in sync and batched paths.
- [x] 2.2 GREEN — Apply `SqlNormalizer::normalize()` in `QueryRecorder` before assigning payload `sql`.

## Phase 3: Verification

- [x] 3.1 Run targeted PHPUnit suites.
- [x] 3.2 Run full package suite and keep it green.
