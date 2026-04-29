# Proposal: package-async-worker-lote-1

## Intent

Extender el package OpenWatch para despachar batches de eventos mediante la cola estándar de Laravel, evitando un daemon propio y reduciendo el impacto del envío sobre el request principal.

## Scope

### In Scope
- Job `OpenWatchSendBatchJob`
- async como extensión del batching existente
- config `batching.async.*`
- dispatch a queue estándar de Laravel
- documentación en README
- soporte/configuración en `cliente-demo` para pruebas locales

### Out of Scope
- daemon propio
- persistencia local custom
- drivers async alternativos (file/sqlite/etc.)
- UI del server para administrar colas

## Capabilities

### New
- `async-batch-dispatch`

### Modified
- `batching`
- `documentation`

## Success Criteria

- con async habilitado, `BatchFlusher` despacha un Job y no llama `sendBatch()` en proceso actual
- el Job usa la queue/conexión configurada
- exceptions siguen síncronas
- README explica claramente cómo usar `queue:work`
- `cliente-demo` queda listo para probar async localmente
