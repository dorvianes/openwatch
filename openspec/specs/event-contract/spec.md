# Spec: event-contract

> Promoted from change `package-hardening-lote-3` — 2026-04-29

## Purpose

This specification protects the package's current flat wire event contract. It MUST NOT redesign emitted events into an envelope.

## Requirements

### Requirement: Flat recorder payloads

Recorder events MUST remain flat payloads with `type` and `occurred_at` at the top level. This slice MUST NOT introduce top-level `id`, `payload`, `context`, or `schema_version` fields, and MUST NOT introduce `EventFactory` or a shared event-builder redesign.

#### Scenario: outgoing_request remains flat

- GIVEN an outgoing HTTP request is recorded
- WHEN the recorder emits the event payload
- THEN the payload MUST include top-level `type` equal to `outgoing_request`
- AND it MUST include top-level `occurred_at`
- AND it MUST NOT wrap event data under `payload` or `context`

#### Scenario: envelope fields are deferred

- GIVEN any recorder emits an event in this slice
- WHEN the payload is inspected
- THEN top-level `id`, `payload`, `context`, and `schema_version` MUST be absent

### Requirement: Null timestamp fallback

`EventTimestamp::format(null)` MUST return a non-null ISO-8601 timestamp string with timezone offset.

#### Scenario: null timestamp formats as now

- GIVEN no captured timestamp is available
- WHEN `EventTimestamp::format(null)` is called
- THEN the result MUST be a non-empty string
- AND it MUST match ISO-8601 with timezone offset

### Requirement: Shared metadata stays in meta

Shared package metadata MAY be asserted only as the current `meta` object. Tests MUST NOT require renaming metadata to `context` or moving event data into a new schema.

#### Scenario: shared app metadata remains current shape

- GIVEN a recorder emits metadata
- WHEN the payload is inspected
- THEN `meta.app_name` and `meta.app_env` SHOULD remain under `meta`
- AND `outgoing_request` MAY include `meta.scheme`

## Deferred

Future work MAY define `id`, `payload`, `context`, `schema_version`, or `EventFactory`, but they MUST NOT be implemented or required until a dedicated envelope-migration change is planned and server-coordinated.
