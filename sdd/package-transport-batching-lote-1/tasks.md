# Tasks: package-transport-batching-lote-1

## Phase 1: Buffer core

- [x] 1.1 RED — Add `EventBufferTest.php` covering push/order/flush/empty/max-events/drop counter.
- [x] 1.2 GREEN — Implement `EventBuffer` with bounded in-memory storage.

## Phase 2: Transport batching

- [x] 2.1 RED — Add `HttpTransportTest.php` coverage for `sendBatch()` payload/endpoint/empty-batch behavior.
- [x] 2.2 GREEN — Implement `HttpTransport::sendBatch()` targeting `/api/ingest/batch`.

## Phase 3: Recorder wiring

- [x] 3.1 RED — Add tests proving QueryRecorder and OutgoingRequestRecorder push to buffer when batching is enabled.
- [x] 3.2 GREEN — Wire recorders to buffer under feature flag.
- [x] 3.3 GREEN — Keep ExceptionRecorder synchronous.

## Phase 4: Flush lifecycle

- [x] 4.1 RED — Add tests for terminate/terminating flush and no-op double flush.
- [x] 4.2 GREEN — Wire flush in request terminate and CLI/job app termination.

## Phase 5: Verification

- [x] 5.1 Run targeted PHPUnit suites.
- [x] 5.2 Run full package suite and keep it green.
