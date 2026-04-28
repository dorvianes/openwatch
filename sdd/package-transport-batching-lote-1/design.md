# Design: package-transport-batching-lote-1

## Overview

Este lote introduce batching en memoria como mejora intermedia entre el modelo actual síncrono por evento y un futuro worker asíncrono. El objetivo es bajar el costo por request sin sumar persistencia local ni procesos extra.

## Design Decisions

### 1. EventBuffer singleton por ejecución
- Nueva clase `EventBuffer` en `src/Support/` o `src/Buffer/`.
- Mantiene una lista ordenada de eventos acumulados.
- Operaciones mínimas: `push()`, `all()`, `flush()`, `isEmpty()`, `count()`.

### 2. Qué eventos se bachean
- `QueryRecorder` → buffer
- `OutgoingRequestRecorder` → buffer
- `RequestRecorder` → puede seguir post-response, pero en este lote el flush principal ocurre también en terminate.
- `ExceptionRecorder` → sigue síncrono, fuera del batch

### 3. Lifecycle hooks
- Web/API: flush en `RecordRequest::terminate()`
- CLI/jobs: flush al terminar el proceso de aplicación (`app()->terminating()` o hook equivalente desde provider)
- El diseño debe evitar doble flush; si ya se vació el buffer, un segundo flush es no-op.

### 4. Transport contract
- `HttpTransport` agrega `sendBatch(array $events): bool`
- POST a `/api/ingest/batch`
- payload: `{ "events": [...] }`
- batch vacío → no-op, devuelve `false` o `true` según contrato conservador a definir en spec/tests (preferible no-op true o false explícito, pero consistente)

### 5. Config
- `batching.enabled` (default `false` para rollout seguro)
- `batching.max_events` (default conservador, por ejemplo 1000)
- si el buffer supera el máximo, descarta silenciosamente eventos posteriores y registra contador interno

## Testing Strategy

- tests unitarios para `EventBuffer`
- tests de wiring para recorders cuando batching está on/off
- tests de `HttpTransport::sendBatch()`
- tests de flush idempotente en terminate / terminating
- sin integration tests pesados en este lote

## Risks

- procesos long-running podrían reutilizar singleton si no se limpia correctamente
- flush doble si se enganchan múltiples hooks sin guard
- pérdida de eventos si el proceso muere antes del flush

## Rollout Note

Feature flag off por defecto para rollback/control. Cuando esté estabilizado, se puede evaluar cambiar el default.
