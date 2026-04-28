# Tasks: package-hardening-lote-1

## Phase 1: Config derivation hardening

- [x] 1.1 RED — Add `tests/Unit/OpenWatchConfigTest.php` covering auto-derived `ignored_hosts` from `server_url`, empty/malformed URL => `[]`, and explicit override replacement.
- [x] 1.2 GREEN — Update `config/openwatch.php` to resolve `ignored_hosts` from `OPENWATCH_IGNORED_HOSTS` or `server_url`.
- [x] 1.3 REFACTOR — Extract a tiny helper only if config derivation becomes noisy.

## Phase 2: Transport timeout split

- [x] 2.1 RED — Expand `tests/Unit/HttpTransportTest.php` to cover explicit `connectTimeout`, defaulting, clamping, and ms mapping.
- [x] 2.2 GREEN — Update `src/Transport/HttpTransport.php` to accept and apply separate `connectTimeout`.
- [x] 2.3 GREEN — Update `src/OpenWatchServiceProvider.php` and config wiring for `connect_timeout`.

## Phase 3: Self-telemetry ignore wiring

- [x] 3.1 RED — Extend `tests/Unit/OutgoingRequestRecorderTest.php` to verify ignored hosts are skipped.
- [x] 3.2 GREEN — Update `src/Recorders/OutgoingRequestRecorder.php` and provider wiring to inject/use `ignored_hosts`.
- [x] 3.3 REFACTOR — Normalize host matching to keep ignore behavior deterministic.

## Phase 4: Focused verification

- [x] 4.1 Add timeout/failure contract assertion in `HttpTransportTest.php`.
- [x] 4.2 Run targeted PHPUnit suites and keep them network-free.
