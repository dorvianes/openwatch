# Design: package-async-worker-lote-1

## Overview

El worker async se apoya completamente en la infraestructura de Laravel Queue del proyecto host. El package no introduce un daemon ni almacenamiento propio: solo despacha un Job estándar cuando batching y async están habilitados.

## Design Decisions

### 1. Async depende del batching
- Si `batching.enabled = false`, async no hace nada.
- Async es una estrategia de flush del batch, no un sistema independiente.

### 2. Job dedicado
- Nueva clase `src/Jobs/OpenWatchSendBatchJob.php`
- recibe `array $events`
- en `handle()` llama `HttpTransport::sendBatch($events)`
- puede definir `tries/backoff` conservadores o dejar defaults mínimos

### 3. Config nueva
- `batching.async.enabled` (bool, default false)
- `batching.async.connection` (string|null)
- `batching.async.queue` (string|null)

### 4. BatchFlusher decide sync vs async
- Si batching enabled + async enabled → dispatch Job
- Si no → `sendBatch()` en proceso actual
- si el dispatch falla, fallback síncrono silencioso para no perder el lote

### 5. HTTP vs CLI/jobs
- En HTTP, si Laravel lo soporta, puede considerarse `afterResponse()`; si no, dispatch estándar.
- En CLI/jobs, dispatch estándar sobre la cola del host.

### 6. Exceptions siguen síncronas
- no se meten al worker en este lote

## Package Testing

- tests de `BatchFlusher` con `Queue::fake()`
- tests del Job llamando `sendBatch()`
- tests de config async
- tests de fallback sync si el dispatch falla

## Demo / Docs

- `README.md` debe documentar setup async
- `cliente-demo` debe exponer env/config para async y explicar que hace falta correr `php artisan queue:work`

## Risks

- si el host no corre worker, los jobs no salen
- payloads grandes en colas externas tienen límites
- fallback sync puede ocultar problemas operativos si no queda documentado
