# Client error capture — fatal errors + send timeout

Repo: `openwatch` (client package `dorvianes/openwatch`, tag v0.1.5) · Branch: `main` (no new branch, per S3)
Backlog items: A1 (fatal errors) + A3 (timeout default) from `/home/dev/projects/openwatch/PENDIENTES.md`

## Specs

- **S1** — Fatal errors are not captured. Quote: «Errores fatales no se capturan | OOM, `max_execution_time` y parse errors no generan `Throwable`; Laravel nunca llama a `report()`. Suelen ser buena parte de los 500 reales» → next step: «`register_shutdown_function` + `error_get_last()` en el provider, con límite de memoria y sin tocar el worker mode». The capture must never break the host app and must never duplicate an error that was already reported.
- **S2** — The send timeout is too tight. Quote: «Timeout de 0.1 s demasiado justo | El payload de excepción (30 frames + snippet + cadena) no siempre entra en 100 ms; se pierde sin rastro» → next step: «Subir default (p. ej. 1–2 s) o desacoplar el envío del ciclo de la request». Delivered here: the default becomes **1.0 s**, tunable through `OPENWATCH_TIMEOUT`. Decoupling is A4, out of scope.
- **S3** — «no abras rama, vamos sobre esta msima rama» → work-unit commits land on `main`.
- **S4** — Also recorded in the backlog, not implemented here: (a) apps that use OpenWatch should be able to send **logs** for server-side analysis («me gustaria poder recuperar logs… tambien puedan enviar los logs, para poder analizarlos en el servidor, y sacar datos»); (b) there is no **retention/cleanup** plan for the data the server accumulates («no se si tenemos un plan para ir limpiando»); (c) server views are already slow and need **database indexes** («tema de indices de bbdd par las vistas del servidor, algunas se ponen lentas ya»).
- **S5** — The wire contract does not change: flat `type`, `occurred_at`, `meta`; success is exactly HTTP **202**.
- **S6** — The suite must stay at its baseline: **217 tests, 6 failures**, all `DocumentationComplianceTest` cases that read the absent `../cliente-demo` (backlog D2). No new failure is acceptable; those 6 are not this task's to fix.

## Tasks

| ID | Task | Spec | Route | Commit | Status |
| --- | --- | --- | --- | --- | --- |
| T1 | Raise the send timeout default to 1.0 s from a single source of truth; RED test for the default, GREEN implementation | S2, S5 | inline | C1 `66d4d1a` | done |
| T2 | Fatal-error capture: `FatalErrorCapture` with shutdown registration, already-reported guard, memory-headroom degradation; RED tests then GREEN | S1, S5 | inline | C2 `a5da564` | done |
| T3 | Wire the capture into the provider and mark reported errors from `RegistersExceptionReporting` | S1 | inline | C2 `a5da564` | done |
| T4 | Docs: config comments + README for both changes | S1, S2 | inline | C1, C2 | done |
| T5 | Backlog: add log ingestion, retention and index work to `PENDIENTES.md`; mark A1/A3 done | S4 | inline | C3 — no commit | done |
| T6 | Full suite parity against the S6 baseline, then the work-unit commits | S3, S5, S6 | inline | C1, C2 | done |

## Log

- **L1** (user, verbatim): «si, dale, hagamos eso del tirón, no abras rama, vamos sobre esta msima rama. Tambien ten en cuenta que me gustaria poder recuperar logs, tengo la idea de que las aplicaciones que usen openwatch, tambien puedan enviar los logs, para poder analizarlos en el servidor, y sacar datos. Tambien es importante que tengas en cuenta que actualmente en el servidor de penwatch se estan almacenando muchos datos, y no se si tenemos un plan para ir limpiando, tambien tema de indices de bbdd par las vistas del servidor, algunas se ponen lentas ya.»
- **L2** (baseline evidence): `vendor/bin/phpunit` on `8f3c113` → `Tests: 217, Assertions: 441, Failures: 6` — all `DocumentationComplianceTest`, missing `../cliente-demo`. Established as the S6 baseline.
- **L3** (RED/GREEN evidence): T1 RED — `Failed asserting that 0.1 is identical to 1.0` plus `Undefined constant HttpTransport::DEFAULT_TIMEOUT`; GREEN — 5/5 filtered tests. T2 RED — 11 tests, all `Class "…FatalErrorCapture" not found`; one implementation detour: `Exception::getTrace()` is final, so the empty trace is decided in `ExceptionRecorder` via `instanceof FatalErrorException` instead of an override; GREEN — 11/11, 31 assertions.
- **L4** (S6 parity after both units): `vendor/bin/phpunit` → `Tests: 230, Assertions: 475, Failures: 6` — the same six `DocumentationComplianceTest` failures, no new one. 230 = 217 baseline + 2 (T1) + 11 (T2).
- **L5** (C3 scope correction): `PENDIENTES.md` lives at the workspace root, which is not a git repository (the two repos are `openwatch/` and `openwatch.dorvian.es/`). T5 therefore produces no commit; the backlog change is only on disk and mirrored in Engram.
- **L6** (known gap): the call site that marks already-reported errors lives in `RegistersExceptionReporting::register()`, which needs `Illuminate\Foundation\Configuration\Exceptions`; that package is not installed, so the guard is unit-tested directly (`markReported`) and the single-line wiring is covered only by review. Same constraint the existing `RegistersExceptionReportingTest` documents.
