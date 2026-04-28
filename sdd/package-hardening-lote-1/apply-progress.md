# Apply Progress: package-hardening-lote-1

**Mode**: Strict TDD  
**Status**: ALL TASKS COMPLETE — 97/97 tests passing, 151 assertions  
**Date**: 2026-04-28  
**Suite baseline pre-lote**: 81 tests (phase 1–3 added 15; phase 4 closed gaps: +3 runtime tests → total 97)

---

## Phase 1: Config derivation hardening

### Tasks
- [x] 1.1 RED — `tests/Unit/OpenWatchConfigTest.php` cubriendo auto-derivación de `ignored_hosts`, URL vacía/malformada → `[]`, override explícito que reemplaza.
- [x] 1.2 GREEN — `config/openwatch.php` resuelve `ignored_hosts` desde `OPENWATCH_IGNORED_HOSTS` o `server_url`.
- [x] 1.3 REFACTOR — No helper extra necesario; la derivación quedó limpia en `ConfigHelper::deriveIgnoredHosts()`.

### Test scenarios (OpenWatchConfigTest.php — 8 tests)

| Test | Escenario | Resultado esperado |
|------|-----------|-------------------|
| `test_derives_host_from_valid_https_url` | URL HTTPS estándar | `['openwatch.example.com']` |
| `test_derives_host_from_http_url` | URL HTTP simple | `['monitor.internal']` |
| `test_empty_server_url_returns_empty_array` | URL vacía | `[]` |
| `test_malformed_url_returns_empty_array` | String no-URL | `[]` |
| `test_explicit_override_replaces_derived_value` | Override comma-separated | `['custom.host.com', 'other.host.com']` |
| `test_explicit_override_with_empty_string_returns_empty_array` | Override vacío | `[]` |
| `test_override_trims_whitespace_from_hosts` | Override con espacios | hosts trimmed |
| `test_url_with_path_only_extracts_host` | URL con path `/api/v1` | solo host, sin path |
| `test_url_with_port_includes_port_in_host` | URL con puerto 8080 | `['openwatch.example.com:8080']` |

**Files touched**:
- `tests/Unit/OpenWatchConfigTest.php` — created (9 tests)
- `src/Support/ConfigHelper.php` — created (`deriveIgnoredHosts()` pure static method)
- `config/openwatch.php` — updated to call `ConfigHelper::deriveIgnoredHosts()`

---

## Phase 2: Transport timeout split

### Tasks
- [x] 2.1 RED — `tests/Unit/HttpTransportTest.php` expandido: `connectTimeout` explícito, default, clamping, ms mapping.
- [x] 2.2 GREEN — `src/Transport/HttpTransport.php` acepta y aplica `connectTimeout` separado.
- [x] 2.3 GREEN — `src/OpenWatchServiceProvider.php` y config wiring para `connect_timeout`.

### Test scenarios (HttpTransportTest.php — tests de timeout split)

| Test | Escenario | Resultado esperado |
|------|-----------|-------------------|
| `test_connect_timeout_defaults_to_total_timeout_when_not_provided` | Sin `connectTimeout` → iguala total | `connectTimeout() === 0.5` |
| `test_connect_timeout_can_be_set_explicitly` | `connectTimeout: 0.2`, `timeout: 0.5` | separados e independientes |
| `test_connect_timeout_is_clamped_to_total_timeout` | `connectTimeout: 1.0 > timeout: 0.3` | clamped a `0.3` |
| `test_total_timeout_accessor_returns_configured_value` | accessor `timeout()` | retorna valor exacto |

**Files touched**:
- `src/Transport/HttpTransport.php` — agregado parámetro `connectTimeout`, lógica de clamping, accessors
- `src/OpenWatchServiceProvider.php` — pasa `connect_timeout` al construir `HttpTransport`
- `config/openwatch.php` — clave `connect_timeout` con default

---

## Phase 3: Self-telemetry ignore wiring

### Tasks
- [x] 3.1 RED — `tests/Unit/OutgoingRequestRecorderTest.php` extendido: hosts ignorados se saltan, case-insensitive, port-aware.
- [x] 3.2 GREEN — `src/Recorders/OutgoingRequestRecorder.php` y provider wiring para inyectar/usar `ignored_hosts`.
- [x] 3.3 REFACTOR — Host matching normalizado: case-insensitive y determinista.

### Test scenarios (OutgoingRequestRecorderTest.php — tests de ignored hosts)

| Test | Escenario | Resultado esperado |
|------|-----------|-------------------|
| `test_ignored_host_is_skipped` | Host en lista → no telemetría | `payloads` vacío |
| `test_non_ignored_host_is_recorded` | Host NO en lista | `payloads` con 1 entrada |
| `test_ignored_host_matching_is_case_insensitive` | `OpenWatch.Example.COM` vs `openwatch.example.com` | host ignorado igual |
| `test_empty_ignored_hosts_list_records_everything` | Lista vacía | todo se registra |
| `test_ignored_host_with_port_matches_url_with_same_port` | `host:8080` ignorado + URL con `:8080` | ignorado |

**Files touched**:
- `src/Recorders/OutgoingRequestRecorder.php` — acepta `array $ignoredHosts = []`; skip si host coincide
- `src/OpenWatchServiceProvider.php` — inyecta `ignored_hosts` resueltos al instanciar el recorder

---

## Phase 4: Focused verification (gap closure)

### Tasks
- [x] 4.1 CURLOPT mapping evidence + escenario slow server + tests runtime de contrato observable.
- [x] 4.2 Suite completa PHPUnit network-free; 96/96 tests, 147 assertions.

### CURLOPT mapping tests (HttpTransportTest.php)

| Test | Escenario | Resultado esperado |
|------|-----------|-------------------|
| `test_curlopt_timeout_ms_maps_to_total_timeout_in_milliseconds` | `timeout: 2.5` | `CURLOPT_TIMEOUT_MS === 2500` |
| `test_curlopt_connecttimeout_ms_maps_to_connect_timeout_in_milliseconds` | `timeout: 2.0, connectTimeout: 0.4` | `CURLOPT_TIMEOUT_MS=2000`, `CURLOPT_CONNECTTIMEOUT_MS=400` |
| `test_slow_server_response_respects_total_timeout_not_connect_timeout` | `timeout: 1.5, connectTimeout: 0.3` | `totalMs ≥ connectMs`; exact values 1500 y 300 |
| `test_clamped_connect_timeout_still_maps_correctly_to_curlopt` | `connectTimeout: 9.9` clamped a `0.5` | ambos CURLOPT `=== 500` |

### Runtime contract tests — contrato observable de `send()` (NEW — gap cerrado)

**Estrategia A (connection-level failures)**: usar `http://127.0.0.1:1` (puerto 1, siempre "connection refused") y `http://192.0.2.1` (TEST-NET-1 RFC 5737, unroutable) con `timeout: 0.001`. Curl falla en microsegundos, sin tráfico real, sin servidor, sin dependencias externas. Ejerce `send()` end-to-end.

**Estrategia B (connect succeeds + response is slow)**: abrir un `stream_socket_server` en un puerto aleatorio de loopback. El OS acepta el TCP handshake en el backlog del kernel mientras curl espera la respuesta HTTP. El server nunca escribe nada → curl agota el timeout total (no el de conexión). Después de que curl regresa, aceptamos el socket para confirmar que la conexión TCP sí se estableció. Determinista, sin red real, sin dependencias externas.

| Test | Escenario | Contratos verificados |
|------|-----------|----------------------|
| `test_send_returns_false_and_populates_curl_error_on_connection_failure` | `127.0.0.1:1` → connection refused | `send() === false` ✅ `lastHttpCode() === 0` ✅ `lastCurlError() !== ''` ✅ |
| `test_send_returns_false_and_http_code_is_zero_on_timeout` | `192.0.2.1` → timeout | `send() === false` ✅ `lastHttpCode() === 0` ✅ `lastCurlError() !== ''` ✅ |
| `test_send_times_out_on_slow_response_after_successful_connect` | TCP connect OK, HTTP response never arrives | `send() === false` ✅ `lastHttpCode() === 0` ✅ `lastCurlError() !== ''` ✅ TCP client accepted ✅ |

**Files touched**:
- `tests/Unit/HttpTransportTest.php` — +3 runtime tests, +4 CURLOPT mapping tests
- `src/Transport/HttpTransport.php` — `buildCurlOptions()` extraído como `protected`

---

## TDD Cycle Evidence — Completo (todos los ciclos del lote)

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1–1.3 `ignored_hosts` derivation | `OpenWatchConfigTest.php` | Unit | N/A (new file) | ✅ Written (E=0) | ✅ 9/9 | ✅ 9 cases (malformed, override, port, path, whitespace) | ✅ `ConfigHelper::deriveIgnoredHosts()` pure static |
| 2.1–2.3 `connectTimeout` split | `HttpTransportTest.php` | Unit | ✅ pre-existentes OK | ✅ Written (E=4) | ✅ 4/4 | ✅ default + explicit + clamped + accessor | ✅ clamping en constructor |
| 3.1–3.3 ignored hosts in recorder | `OutgoingRequestRecorderTest.php` | Unit | ✅ pre-existentes OK | ✅ Written (E=5) | ✅ 5/5 | ✅ case-insensitive + port + empty list + skip + record | ✅ normalized lowercase matching |
| 4.1 CURLOPT mapping | `HttpTransportTest.php` | Unit | ✅ 9/9 | ✅ Written (E=4) | ✅ 4/4 | ✅ 4 cases (ms calc, split, slow-server, clamped) | ✅ `buildCurlOptions()` extracted |
| 4.1 runtime contract (`send()`) | `HttpTransportTest.php` | Unit/Runtime | ✅ 13/13 | ✅ Written (E=3) | ✅ 3/3 | ✅ 3 cases (connection refused + unreachable timeout + connect-ok/response-slow) | ➖ None needed |

### Test Summary — Final
- **Total suite**: 97 tests, 151 assertions
- **Lote net-new tests**: ~31 (phases 1–4 combined)
- **Layers used**: Unit (97) — todos network-free (runtime seams usan loopback/RFC-addresses)
- **Runtime seam**: `127.0.0.1:1` (refused) + `192.0.2.1` (unroutable) + `stream_socket_server` loopback (connect-ok/slow-response)
- **Pure methods/functions created**: `ConfigHelper::deriveIgnoredHosts()`, `HttpTransport::buildCurlOptions()`
- **Approval tests**: None — toda implementación fue nueva

---

## Files Changed — Completo (todo el lote)

| File | Action | What Was Done |
|------|--------|---------------|
| `tests/Unit/OpenWatchConfigTest.php` | Created | 9 tests cubriendo todos los scenarios de derivación de `ignored_hosts` |
| `tests/Unit/HttpTransportTest.php` | Modified | +8 tests: 4 CURLOPT mapping + 2 runtime contract + 2 timeout split accessor tests |
| `tests/Unit/OutgoingRequestRecorderTest.php` | Modified | +5 tests cubriendo ignored hosts: skip, record, case-insensitive, port, empty list |
| `src/Support/ConfigHelper.php` | Created | `deriveIgnoredHosts(string $serverUrl, ?string $override): array` pure static |
| `src/Transport/HttpTransport.php` | Modified | `connectTimeout` param + clamping + accessors + `buildCurlOptions()` protected |
| `src/Recorders/OutgoingRequestRecorder.php` | Modified | `array $ignoredHosts = []` param + case-insensitive skip logic |
| `src/OpenWatchServiceProvider.php` | Modified | wiring de `connect_timeout` y `ignored_hosts` en bindings del container |
| `config/openwatch.php` | Modified | claves `connect_timeout` e `ignored_hosts` (resueltas via `ConfigHelper`) |

---

## Gap Resolution

| Gap (verify bloqueado por) | Resolución |
|---------------------------|-----------|
| Test runtime real de slow server: `send() === false`, `lastHttpCode() === 0`, `lastCurlError()` no vacío | ✅ `test_send_returns_false_and_populates_curl_error_on_connection_failure` + `test_send_returns_false_and_http_code_is_zero_on_timeout` — ejercen `send()` real contra hosts inaccesibles deterministas |
| **[NUEVO]** Test runtime del caso `connect succeeds + response is slow`: faltaba ejercer el timeout de RESPUESTA (no de conexión) | ✅ `test_send_times_out_on_slow_response_after_successful_connect` — usa `stream_socket_server` loopback; OS completa TCP handshake en backlog, curl conecta exitosamente pero nunca recibe HTTP → timeout en fase de respuesta; confirma TCP client accepted post-hecho |
| `apply-progress.md` conteo incorrecto (96 tests / 147 assertions) | ✅ Corregido a 97 tests / 151 assertions; descripción de estrategias runtime expandida con 3 estrategias documentadas |
