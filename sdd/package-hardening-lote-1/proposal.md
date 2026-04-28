# Proposal: package-hardening-lote-1

## Intent

Endurecer el package OpenWatch en tres puntos de bajo riesgo y alto valor: evitar auto-captura latente del propio server, separar timeouts de conexión y timeout total, y reforzar cobertura unitaria alrededor de esas reglas.

## Scope

### In Scope
- `ignored_hosts` auto-derivado desde `server_url`
- override opcional manual para `ignored_hosts`
- separación entre `connect_timeout` y `timeout` total en `HttpTransport`
- tests unitarios/quirúrgicos de config, outgoing recorder y transport

### Out of Scope
- batching
- circuit breaker
- normalización SQL
- integration tests grandes con orchestra/testbench

## Capabilities

### Modified
- `configuration`
- `http-transport`
- `outgoing-recorder`

## Success Criteria

- el host del `server_url` queda ignorado por defecto en outgoing telemetry
- un override manual permite controlar explícitamente `ignored_hosts`
- `HttpTransport` distingue timeout de conexión y timeout total
- los tests unitarios relevantes pasan sin depender de red real
