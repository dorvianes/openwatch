# Tasks: package-async-worker-lote-1

## Phase 1: Async config

- [x] 1.1 RED — Add config tests for `batching.async.enabled`, `connection`, `queue` defaults and env overrides.
- [x] 1.2 GREEN — Add async config keys in `config/openwatch.php`.

## Phase 2: Queue dispatch

- [x] 2.1 RED — Add `BatchFlusher` tests using `Queue::fake()` for async dispatch vs sync send.
- [x] 2.2 GREEN — Implement `OpenWatchSendBatchJob` and dispatch path in `BatchFlusher`.
- [x] 2.3 GREEN — Add fallback sync if dispatch throws.

## Phase 3: Job execution

- [x] 3.1 RED — Add tests verifying the Job calls `sendBatch()` and swallows transport failures.
- [x] 3.2 GREEN — Implement job handle behavior.

## Phase 4: Documentation and demo

- [x] 4.1 Update package README with async queue setup and operational notes.
- [x] 4.2 Update `cliente-demo` env/example/docs for async local testing.

## Phase 5: Verification

- [x] 5.1 Run targeted PHPUnit suites.
- [x] 5.2 Run full package suite and keep it green.
