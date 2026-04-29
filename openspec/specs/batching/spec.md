# Spec: batching

> Promoted from change `package-async-worker-lote-1` — 2026-04-29

## Domain: batching

### Requirement: Sync flush remains default
When `batching.enabled = true` and `batching.async.enabled = false`, flush MUST call `sendBatch()` in-process.

#### Scenario: Sync flush sends batch directly
- GIVEN batching enabled and async disabled
- WHEN flush is invoked with buffered events
- THEN `sendBatch()` is called once and no Job is dispatched

#### Scenario: Empty buffer in sync mode is no-op
- GIVEN batching enabled and async disabled with empty buffer
- WHEN flush is invoked
- THEN no HTTP call is made and no Job is dispatched

### Requirement: Async flush dispatches a Laravel Job
When batching and async are both enabled, flush MUST dispatch a Job carrying the events and MUST NOT call `sendBatch()` directly in the current process.

#### Scenario: Async flush dispatches a job
- GIVEN batching enabled and async enabled with buffered events
- WHEN flush is invoked
- THEN exactly one batch Job is dispatched and in-process send is skipped

#### Scenario: Async flush honors queue configuration
- GIVEN async connection and queue are configured
- WHEN the Job is dispatched
- THEN it uses the configured connection and queue

#### Scenario: Async flush with empty buffer does not dispatch
- GIVEN batching and async enabled with empty buffer
- WHEN flush is invoked
- THEN no Job is dispatched

### Requirement: Async is ignored when batching is disabled

#### Scenario: Async enabled but batching off
- GIVEN batching disabled and async enabled
- WHEN flush is invoked
- THEN no Job is dispatched and no HTTP call is made

### Requirement: Batch wrapper remains current wire shape

Batch sends MUST serialize the request body as exactly one outer object with the key `events`. This slice MUST NOT add a batch-level schema wrapper or per-event envelope.

#### Scenario: batch payload wrapper is exactly events

- GIVEN a non-empty list of flat events
- WHEN the batch payload is serialized for `/api/ingest/batch`
- THEN the decoded JSON object MUST have exactly the top-level key `events`
- AND `events` MUST contain the original event list

#### Scenario: batched event type is preserved

- GIVEN flat events include top-level `type` values
- WHEN the events are sent as a batch
- THEN each event inside `events` MUST retain its original top-level `type`
- AND no event MUST be wrapped under `payload` or `context`

### Requirement: Batch contract defers envelope migration

Batched events MUST remain the same flat events accepted by single-event transport. Introducing `id`, `payload`, `context`, `schema_version`, or `EventFactory` is explicitly deferred.

#### Scenario: batch items do not gain deferred fields

- GIVEN events are prepared for batch transport in this slice
- WHEN the batch body is inspected
- THEN each event item MUST NOT require `id`, `payload`, `context`, or `schema_version`
