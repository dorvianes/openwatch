# Design: package-hardening-lote-2

## Overview

Este lote mejora la calidad de datos del package antes de enviarlos al server. La normalización será deliberadamente conservadora: suficiente para agrupar mejor queries similares, pero evitando heurísticas agresivas que rompan SQL válido o expongan datos.

## Design Decisions

### 1. SqlNormalizer puro y sin dependencias
- Nueva clase `src/Support/SqlNormalizer.php`
- método principal: `normalize(string $sql): string`
- sin acceso a Laravel, PDO ni bindings

### 2. Reglas de normalización
- trim inicial/final
- colapsar whitespace múltiple a un solo espacio
- detectar `IN (...)` cuyo contenido son exclusivamente placeholders `?` separados por comas y normalizar a `IN (?+)`
- matching case-insensitive para `IN`

### 3. Lo que NO se toca
- no se reescriben literales de string
- no se reescriben listas mixtas (`IN (?, 1, ?)`)
- no se alteran bindings porque nunca se usan
- no se intenta canonicalizar keywords SQL globalmente

### 4. Integración en QueryRecorder
- aplicar `SqlNormalizer::normalize($event->sql)` justo antes de armar `payload['sql']`
- mantener intacto el contrato fail-silent del recorder
- aplicar tanto en modo síncrono como en batching

## Testing Strategy

- `SqlNormalizerTest.php`
  - whitespace collapse
  - trim
  - `IN (?+)`
  - no tocar `IN ('a','b')`
  - idempotencia
  - string vacía
- `QueryRecorderTest.php`
  - payload normalizado en sync
  - payload normalizado en batch
  - fail-silent sigue vigente

## Risks

- falsos positivos de regex si el patrón de `IN (...)` es demasiado agresivo
- over-normalization si se intenta tocar más de lo acordado

## Rollout Note

Cambio interno del package, sin necesidad de flag adicional si los tests dan confianza suficiente.
