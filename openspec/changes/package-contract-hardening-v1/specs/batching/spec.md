# Delta for batching

## ADDED Requirements

### Requirement: Batches MUST contain event-contract envelopes

When batching is enabled, every buffered event flushed through the batch transport MUST comply with the `event-contract` v1 envelope. The batch HTTP body MUST remain an object with an `events` array and MUST NOT wrap individual events in an additional package-specific layer.

#### Scenario: Sync batch flush preserves event envelopes
- GIVEN batching is enabled and async is disabled
- WHEN buffered events are flushed synchronously
- THEN `sendBatch()` MUST receive an `events` list of v1 event envelopes
- AND each event MUST contain `id`, `type`, `occurred_at`, `payload`, and `context`

#### Scenario: Async batch job preserves event envelopes
- GIVEN batching and async are enabled
- WHEN buffered events are dispatched to the batch Job
- THEN the Job MUST carry v1 event envelopes unchanged
- AND the Job handler MUST send those envelopes through batch transport

#### Scenario: Empty batch remains a no-op
- GIVEN batching is enabled with no buffered events
- WHEN flush is invoked
- THEN no batch payload MUST be sent or dispatched
