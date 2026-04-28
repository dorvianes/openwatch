# Proposal: package-hardening-lote-2

## Intent

Mejorar la agrupación de queries en OpenWatch normalizando SQL del lado del package antes de enviarlo al server, sin exponer bindings reales ni meter parsers complejos.

## Scope

### In Scope
- crear `SqlNormalizer`
- colapsar whitespace
- normalizar listas `IN (?, ?, ?)` a `IN (?+)`
- integrar la normalización en `QueryRecorder`
- tests unitarios del normalizer y del recorder

### Out of Scope
- parser SQL completo
- dependencias externas pesadas
- envío de bindings
- cambios del lado del server

## Capabilities

### New
- `sql-normalization`

### Modified
- `query-recording`

## Success Criteria

- queries equivalentes con distinto whitespace generan el mismo SQL normalizado
- listas variables `IN (...)` con placeholders colapsan a `IN (?+)`
- `QueryRecorder` envía SQL normalizado en caminos síncronos y batched
- la privacidad de bindings se mantiene intacta
