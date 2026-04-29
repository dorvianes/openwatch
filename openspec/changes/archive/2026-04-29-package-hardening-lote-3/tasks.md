# Tasks: package-hardening-lote-3

## Phase 1: Timestamp contract

- [x] 1.1 RED — Create `tests/Unit/EventTimestampTest.php` for `EventTimestamp::format(null)` returning a non-empty ISO-8601 string with timezone offset per `event-contract`.
- [x] 1.2 GREEN — Update `src/Support/EventTimestamp.php` only if 1.1 fails, preserving current float and `DateTimeInterface` formatting behavior.
- [x] 1.3 VERIFY — Run `./vendor/bin/phpunit tests/Unit/EventTimestampTest.php`.

## Phase 2: Flat recorder payload contract

- [x] 2.1 RED — Extend `tests/Unit/OutgoingRequestRecorderTest.php` to assert top-level `type` and `occurred_at`, current `meta.app_name` / `meta.app_env` shape, optional `meta.scheme`, and absence of `id`, `payload`, `context`, `schema_version`.
- [x] 2.2 GREEN — Touch `src/Recorders/OutgoingRequestRecorder.php` only if 2.1 fails; keep omitted-start-time behavior and flat payloads.
- [x] 2.3 RED — Add the same flat-contract and forbidden-key assertions to `tests/Unit/RequestRecorderTest.php`, `tests/Unit/ExceptionRecorderTest.php`, and `tests/Unit/QueryRecorderTest.php` for `request`, `exception`, and `query` events.
- [x] 2.4 GREEN — Update only the failing recorder source files under `src/Recorders/` if 2.3 proves a gap; no `EventFactory`, envelope, or metadata rename work.
- [x] 2.5 VERIFY — Run `./vendor/bin/phpunit tests/Unit/OutgoingRequestRecorderTest.php tests/Unit/RequestRecorderTest.php tests/Unit/ExceptionRecorderTest.php tests/Unit/QueryRecorderTest.php`.

## Phase 3: Batch wire shape protection

- [x] 3.1 RED — Strengthen `tests/Unit/HttpTransportTest.php` so `buildBatchCurlOptions()` asserts the decoded body has exactly one top-level key `events`, preserves each event `type`, and forbids per-item `payload`, `context`, `id`, and `schema_version`.
- [x] 3.2 GREEN — Modify `src/Transport/HttpTransport.php` only if 3.1 fails, keeping the batch body exactly `{"events":[...]}` without inner envelopes.
- [x] 3.3 VERIFY — Run `./vendor/bin/phpunit tests/Unit/HttpTransportTest.php` and then full `./vendor/bin/phpunit`.
