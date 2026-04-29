# Spec: config

> Promoted from change `package-async-worker-lote-1` — 2026-04-29

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
