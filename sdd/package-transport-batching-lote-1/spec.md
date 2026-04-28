# Spec: package-transport-batching-lote-1

## Overview

Especificación formal del lote de batching en memoria. Convierte el envío síncrono
por-evento en un único HTTP batch al final del ciclo de ejecución (request web o
comando/job CLI).

## Requirements

### R1 — EventBuffer
- R1.1: `push(array $event)` acumula eventos en orden FIFO.
- R1.2: `flush()` devuelve todos los eventos y limpia el buffer.
- R1.3: `isEmpty()` retorna `true` cuando no hay eventos.
- R1.4: `count()` refleja la cantidad actual de eventos.
- R1.5: Si se supera `maxEvents`, los eventos adicionales se descartan silenciosamente
  y se incrementa un contador interno `droppedCount`.

### R2 — HttpTransport::sendBatch()
- R2.1: POST a `/api/ingest/batch` con payload `{ "events": [...] }`.
- R2.2: Batch vacío (array vacío) → retorna `false` sin hacer ninguna llamada HTTP.
- R2.3: En batch con eventos, retorna el resultado del transporte (true/false).

### R3 — Recorder wiring (feature flag)
- R3.1: Con `batching.enabled = true`, `QueryRecorder::record()` hace `push()` al buffer
  en vez de `send()` inmediato.
- R3.2: Con `batching.enabled = false`, `QueryRecorder::record()` mantiene envío síncrono.
- R3.3: Con `batching.enabled = true`, `OutgoingRequestRecorder::record()` hace `push()`.
- R3.4: Con `batching.enabled = false`, `OutgoingRequestRecorder::record()` mantiene envío síncrono.
- R3.5: `ExceptionRecorder` siempre hace envío síncrono, independientemente del flag.

### R4 — BatchFlusher
- R4.1: `flush()` toma todos los eventos del buffer y los envía via `sendBatch()`.
- R4.2: Si el buffer está vacío, `flush()` es no-op (no se llama `sendBatch()`).
- R4.3: Si `batchingEnabled = false`, `flush()` es no-op completo (buffer intacto).
- R4.4: Llamar `flush()` dos veces es seguro — la segunda vez el buffer está vacío.
- R4.5: Excepciones del transporte se absorben silenciosamente (no rompen la app anfitriona).

### R5 — Lifecycle hooks

#### R5.1 — Web/API: RecordRequest middleware
- `RecordRequest::terminate()` llama `BatchFlusher::flush()` cuando se inyecta un flusher.
- Sin flusher inyectado, `terminate()` es no-op (retrocompatibilidad).

#### R5.2 — CLI/jobs: terminating callback
- Con `batching.enabled = true`, el ServiceProvider registra un callback via `app()->terminating()`.
- El callback llama `BatchFlusher::flush()`.
- El callback absorbe excepciones silenciosamente.
- Si el callback se invoca dos veces (bug en host), la segunda vez es no-op (buffer vacío).
- Con `batching.enabled = false`, el ServiceProvider NO registra el callback.

## Scenarios

### SC-01: Buffer push y flush en orden
```
DADO un EventBuffer vacío
CUANDO se hace push de evento A y luego evento B
ENTONCES flush() retorna [A, B] en ese orden
Y el buffer queda vacío
```

### SC-02: Buffer max_events drop
```
DADO un EventBuffer con maxEvents = 2
CUANDO se hacen push de 3 eventos
ENTONCES all() / flush() retorna los primeros 2
Y droppedCount = 1
```

### SC-03: sendBatch vacío es no-op
```
DADO HttpTransport configurado
CUANDO se llama sendBatch([])
ENTONCES retorna false
Y no se realiza ninguna llamada HTTP
```

### SC-04: sendBatch con eventos
```
DADO HttpTransport configurado
CUANDO se llama sendBatch([evento1, evento2])
ENTONCES POST a /api/ingest/batch con body {"events": [evento1, evento2]}
```

### SC-05: QueryRecorder con batching on
```
DADO QueryRecorder con batching enabled y buffer inyectado
CUANDO se registra una query
ENTONCES el evento se acumula en el buffer
Y NO se hace ninguna llamada HTTP directa
```

### SC-06: ExceptionRecorder siempre síncrono
```
DADO ExceptionRecorder (sin buffer)
CUANDO se registra una excepción
ENTONCES se hace envío HTTP inmediato
```

### SC-07: Flush idempotente (doble flush)
```
DADO BatchFlusher con buffer con 1 evento
CUANDO se llama flush() dos veces
ENTONCES sendBatch() se llama exactamente una vez
```

### SC-08: CLI terminating callback
```
DADO ServiceProvider con batching enabled
Y buffer con N eventos
CUANDO el proceso termina (app()->terminating() dispara)
ENTONCES flush() envía los N eventos en un único batch
Y el buffer queda vacío
```

### SC-09: CLI terminating callback idempotente
```
DADO el callback registrado y el buffer con 1 evento
CUANDO el callback se invoca dos veces
ENTONCES solo se envía 1 batch
```

### SC-10: CLI terminating callback swallows exceptions
```
DADO un transporte que lanza RuntimeException en sendBatch()
CUANDO el callback terminating se invoca
ENTONCES la excepción no se propaga
```
