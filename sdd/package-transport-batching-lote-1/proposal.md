# Proposal: package-transport-batching-lote-1

## Intent

Reducir el impacto en rendimiento del package en la app anfitriona evitando múltiples round-trips HTTP síncronos por request, mediante batching en memoria simple y conservador.

## Scope

### In Scope
- `EventBuffer` en memoria
- batching de queries y outgoing requests
- flush al final del request
- flush al terminar comandos/jobs
- feature flag para activar o desactivar batching
- endpoint batch dedicado `/api/ingest/batch`

### Out of Scope
- worker separado
- persistencia local de eventos
- retries sofisticados
- excepciones asíncronas (siguen síncronas)

## Capabilities

### New
- `event-batching`

### Modified
- `event-transport`
- `query-recorder`
- `outgoing-recorder`

## Success Criteria

- múltiples eventos del mismo ciclo producen un único envío batch
- queries y outgoing requests dejan de hacer round-trip inmediato por evento
- exceptions mantienen envío inmediato
- el batching se puede apagar fácilmente por config
