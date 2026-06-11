# Delta for event-contract

## MODIFIED Requirements

### Requirement: Shared metadata stays in meta

Shared package metadata MAY be asserted only as the current `meta` object. Tests MUST NOT require renaming metadata to `context` or moving event data into a new schema.
(Previously: Shared package metadata MAY be asserted only as the current meta object.)

#### Scenario: shared app metadata remains current shape

- GIVEN a recorder emits metadata
- WHEN the payload is inspected
- THEN `meta.app_name` and `meta.app_env` SHOULD remain under `meta`
- AND `meta` MAY contain `user_id`, `user_type`, and `ip`
- AND `outgoing_request` MAY include `meta.scheme`

#### Scenario: Livewire request metadata remains safe and compact

- GIVEN a `request` event is recorded for a detected Livewire request
- WHEN the payload is inspected
- THEN `meta.livewire.detected` MUST be `true`
- AND `meta.livewire.endpoint` SHOULD contain the request path signal
- AND `meta.livewire.component_count` MUST describe the number of included component summaries
- AND `meta.livewire.components` MAY include bounded component summaries with optional `name` and `id`
- AND `meta.livewire.calls` MAY include bounded Livewire method names
- AND `meta.livewire.updates_count` MUST describe the number of included update keys
- AND `meta.livewire.update_keys` MAY include bounded updated property names or paths
- AND `meta.livewire` MUST NOT include raw Livewire request payloads, component state values, update values, method parameters, CSRF tokens, snapshots, `serverMemo` contents, rendered HTML, or response bodies

#### Scenario: non-Livewire requests omit Livewire metadata

- GIVEN a `request` event is recorded for a non-Livewire request
- WHEN the payload is inspected
- THEN `meta.livewire` MUST be absent
