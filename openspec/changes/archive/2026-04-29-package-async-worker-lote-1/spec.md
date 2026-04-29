# Spec: package-async-worker-lote-1

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

## Domain: config

### Requirement: Async config keys MUST exist
The package MUST expose `batching.async.enabled`, `batching.async.connection`, and `batching.async.queue`, overridable by env vars.

#### Scenario: Defaults apply
- GIVEN no async env vars are set
- WHEN config is read
- THEN enabled is false and connection/queue are null

#### Scenario: Env overrides apply
- GIVEN env vars for enabled/connection/queue are set
- WHEN config is read
- THEN the configured values are reflected

### Requirement: Documentation and demo support MUST be updated

#### Scenario: README explains async worker setup
- GIVEN a developer enables async batching
- WHEN reading the README
- THEN they can see that `php artisan queue:work` is required in the host app

#### Scenario: cliente-demo exposes async config
- GIVEN a developer wants to test async locally in `cliente-demo`
- WHEN reviewing its env/example or docs
- THEN the async-related variables and queue worker requirement are visible
