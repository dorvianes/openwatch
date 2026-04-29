# Delta for batching

## ADDED Requirements

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
