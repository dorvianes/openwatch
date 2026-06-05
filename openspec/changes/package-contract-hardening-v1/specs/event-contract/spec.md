# Event Contract Specification

## Purpose

Defines the v1 package-side telemetry event envelope emitted by all current OpenWatch recorders.

## Requirements

### Requirement: Events MUST use the v1 envelope

Every telemetry event emitted by the package MUST be an array with exactly these top-level contract fields: `id`, `type`, `occurred_at`, `payload`, and `context`. Event-specific data MUST live under `payload`. Shared application metadata MUST live under `context`.

#### Scenario: Request event is enveloped
- GIVEN request telemetry is recorded
- WHEN the event is sent or buffered
- THEN it MUST contain top-level `id`, `type`, `occurred_at`, `payload`, and `context`
- AND request-specific fields MUST be inside `payload`

#### Scenario: Query event is enveloped
- GIVEN query telemetry is recorded
- WHEN the event is sent or buffered
- THEN `type` MUST be `query`
- AND normalized SQL and query timing MUST be inside `payload`

### Requirement: Envelope fields MUST be valid

`id` MUST be a non-empty string generated per event. `type` MUST be one of `request`, `exception`, `query`, or `outgoing_request`. `occurred_at` MUST be a non-null ISO 8601 timestamp string. `payload` and `context` MUST be arrays.

#### Scenario: Supported event type is emitted
- GIVEN any current recorder creates an event
- WHEN the event contract is inspected
- THEN `type` MUST be one of the four supported event types
- AND `occurred_at` MUST NOT be null

#### Scenario: Outgoing request has no explicit start time
- GIVEN outgoing request telemetry is recorded without a supplied start time
- WHEN the event is created
- THEN `occurred_at` MUST still be a non-null ISO 8601 timestamp string

### Requirement: Context MUST carry shared app metadata

Every event `context` MUST include `app_name` and `app_env`. Each value MAY be null when unavailable. Recorders MUST NOT expose these shared fields inside event-specific `payload`.

#### Scenario: App metadata is unavailable
- GIVEN application config cannot provide app metadata
- WHEN any recorder creates an event
- THEN `context.app_name` MAY be null
- AND `context.app_env` MAY be null
