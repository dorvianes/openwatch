# Changelog

All notable changes to `dorvianes/openwatch` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases before `0.1.6` predate this file; their contents are described by the
tagged commits and by the upgrade notes in the README.

## [Unreleased]

## [0.1.6] - 2026-11-18

### Added

- **Fatal errors are now reported.** Memory exhaustion, `max_execution_time` and
  parse errors terminate the process without producing a `Throwable`, so
  Laravel's `report()` hook never saw them — a large share of the real 500s was
  invisible. A shutdown handler now reads `error_get_last()` and sends the event
  as `class = Dorvianes\OpenWatch\Support\FatalErrorException`, with `file` and
  `line` but no trace (a trace captured at shutdown only describes the handler).
  - Only registered when the package is enabled **and** configured.
  - Never duplicates: anything Laravel already reported is skipped by `file:line`.
  - Holds a 64 KiB memory reserve, released at shutdown, and skips the code
    snippet when less than 8 MiB of the memory limit is still free.
  - Never throws: telemetry cannot change how your application exits.

### Changed

- **Default HTTP timeout raised from `0.1` s to `1.0` s.** An exception payload
  (up to 30 trace frames, a code snippet and the previous-exception chain) does
  not reach a remote server over TLS within 100 ms, so events were aborted
  mid-flight and dropped **silently** — the transport only logged a rejection when
  the server answered with a non-`202` code, and a timed-out request never gets
  that far. `OPENWATCH_TIMEOUT` still wins;
  raise it further on slow links. Applications that published the config file
  keep their own value — set `OPENWATCH_TIMEOUT=1.0` to adopt the new default.

### Known limitations

- A fatal error ends the worker process, so in a queue worker or Octane the event
  is sent from a dying process: the payload is deliberately minimal and delivery is
  best-effort. There is no HTTP request to attach as context in that case.
- Ingestion still requires HTTP `202`. A server answering `200`/`204`, or a 5xx,
  is treated as a dropped event with only a debug log.

[Unreleased]: https://github.com/dorvianes/openwatch/compare/v0.1.6...HEAD
[0.1.6]: https://github.com/dorvianes/openwatch/compare/v0.1.5...v0.1.6
