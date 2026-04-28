# Apply Progress: package-hardening-lote-2

## Status: COMPLETE ✅

**Mode**: Strict TDD  
**Suite target**: `tests/Unit/SqlNormalizerTest.php` + `tests/Unit/QueryRecorderTest.php`  
**Suite target**: `tests/Unit/QueryRecorderTest.php` (13 tests) — OK  
**Suite full**: 152 tests, 237 assertions — OK  
**Last run**: 2026-04-28 (PHP 8.4.16 / PHPUnit 11.5.55)  
**Gap fix**: +1 test `test_fails_silently_on_normalizer_error` + seam `protected normalizeSql()`

---

## TDD Cycle Evidence

| Task | RED (test first) | GREEN (implementation) | TRIANGULATE / REFACTOR | Result |
|------|-----------------|------------------------|------------------------|--------|
| 1.1 — SqlNormalizerTest.php | 7 tests written covering: whitespace collapse, trim, `IN (?, ?, ?) → IN (?+)`, single-placeholder `IN (?)`, non-placeholder `IN ('a','b')`, mixed `IN (?, 1, ?)`, empty string, idempotence. Suite FAILED on missing `SqlNormalizer`. | `src/Support/SqlNormalizer.php` creado con `normalize(string $sql): string` (trim + collapse + regex `IN (?+)`). | Triangulación: mixed IN list (`?, 1, ?`) añadida para forzar que el regex NO sea greedy; idempotencia verificada double-normalize. | ✅ 7/7 pass |
| 1.2 — SqlNormalizer implementation | — (test-first ya en 1.1) | Implementación conservadora: `preg_replace('/\s+/', ' ')` + `trim()` + regex `/\bIN\s*\(\s*(\?\s*,\s*)*\?\s*\)/i` → `IN (?+)`. | Regex scoped: solo lista 100 % `?`, preserva literales y listas mixtas. Sin dependencias de Laravel/PDO. | ✅ |
| 2.1 — QueryRecorderTest.php (normalización) | 3 tests nuevos en sección "SQL normalization": `test_sync_payload_sql_is_normalized`, `test_sync_payload_sql_collapses_whitespace`, `test_batched_payload_sql_is_normalized`. Suite FAILED antes del paso 2.2. | `QueryRecorder::record()` aplica `SqlNormalizer::normalize($event->sql)` antes de construir payload `sql` — tanto sync como batched. | Fail-silent contract verificado con `test_fails_silently_on_transport_error` (ya existente). Nuevo test batched usa `EventBuffer` real + transport spy. | ✅ 3/3 pass |
| 2.2 — QueryRecorder integration | — (test-first ya en 2.1) | Una línea cambiada en `QueryRecorder.php`: `'sql' => SqlNormalizer::normalize($event->sql)`. Sin cambios en el contrato público. | Riesgo del design (falso positivo de regex) mitigado por el test `does_not_touch_in_list_with_string_literals` y `does_not_touch_mixed_in_list`. | ✅ |
| 3.1 — Suite targeted | — | — | `vendor/bin/phpunit tests/Unit/SqlNormalizerTest.php tests/Unit/QueryRecorderTest.php` → **20 tests, 25 assertions, OK** | ✅ |
| 3.2 — Full suite green | — | — | `vendor/bin/phpunit` → **151 tests, 237 assertions, OK** — ninguna regresión en lotes previos | ✅ |

---

## Files Changed

| File | Action | Descripción |
|------|--------|-------------|
| `src/Support/SqlNormalizer.php` | Created | Clase pura con método estático `normalize()`. Trim + collapse whitespace + IN (?+) regex conservador. |
| `src/Recorders/QueryRecorder.php` | Modified | Aplica `SqlNormalizer::normalize()` a `$event->sql` antes de asignarlo a `payload['sql']`, en sync y batched path. |
| `tests/Unit/SqlNormalizerTest.php` | Created | 7 tests cubriendo todos los escenarios de spec.md §domain:sql-normalization. |
| `tests/Unit/QueryRecorderTest.php` | Modified | +1 test `test_fails_silently_on_normalizer_error` — verifica fail-silent ante falla del normalizador via seam. |
| `src/Recorders/QueryRecorder.php` | Modified | Extraído seam `protected normalizeSql(string $sql): string` para hacer la llamada al normalizador testeable. |

---

## Spec Coverage

| Spec Scenario | Test | Status |
|---------------|------|--------|
| Whitespace collapse (spaces + newlines) | `test_collapses_multiple_spaces_and_newlines` | ✅ |
| Trim leading/trailing | `test_trims_leading_and_trailing_whitespace` | ✅ |
| `IN (?, ?, ?)` → `IN (?+)` | `test_normalizes_in_list_with_multiple_placeholders` | ✅ |
| `IN (?)` → `IN (?+)` | `test_normalizes_in_list_with_single_placeholder` | ✅ |
| Non-placeholder `IN` preserved | `test_does_not_touch_in_list_with_string_literals` | ✅ |
| Empty string — no exception | `test_empty_string_returns_empty_string_without_exception` | ✅ |
| Idempotence | `test_idempotence_normalize_twice_equals_once` | ✅ |
| Sync path normalized SQL | `test_sync_payload_sql_is_normalized` | ✅ |
| Sync path whitespace collapse | `test_sync_payload_sql_collapses_whitespace` | ✅ |
| Batched path normalized SQL | `test_batched_payload_sql_is_normalized` | ✅ |
| Fail-silent contract preserved | `test_fails_silently_on_transport_error` + `test_fails_silently_on_normalizer_error` | ✅ |

---

## Deviations from Design

Ninguna — implementación 1:1 con design.md.

## Issues Found

Ninguno.

---

## Tasks Summary

```
Phase 1: SqlNormalizer core
  [x] 1.1 RED  — SqlNormalizerTest.php (7 scenarios)
  [x] 1.2 GREEN — SqlNormalizer.php implementado

Phase 2: QueryRecorder integration
  [x] 2.1 RED  — QueryRecorderTest.php extendido (3 tests nuevos)
  [x] 2.2 GREEN — SqlNormalizer integrado en QueryRecorder

Phase 3: Verification
  [x] 3.1 Suite targeted: 13/13 ✅
  [x] 3.2 Full suite: 152/152 ✅
  [x] GAP FIX: test_fails_silently_on_normalizer_error + seam normalizeSql ✅
```

**7/7 tasks complete (+ gap fix). Ready for verify.**
