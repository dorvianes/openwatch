# Design: package-hardening-lote-1

## Overview

Este lote introduce hardening pequeño y compatible sobre el package, sin cambiar su arquitectura básica. El objetivo es reducir riesgos latentes sin meter complejidad innecesaria.

## Design Decisions

### 1. ignored_hosts auto-derivado
- `config/openwatch.php` resolverá `ignored_hosts` a partir de:
  1. override explícito (`OPENWATCH_IGNORED_HOSTS`)
  2. si no existe override, derivar host desde `OPENWATCH_SERVER_URL`
- El override **reemplaza** el valor derivado; no se mezcla.
- Si la URL es vacía o inválida, el resultado es `[]`.

### 2. Split de timeouts
- Mantener `timeout` como timeout total.
- Agregar `connect_timeout` separado.
- El timeout de conexión efectivo nunca debe superar el timeout total.
- La API pública debe cambiar con impacto mínimo: parámetro opcional o wiring por config desde el ServiceProvider.

### 3. Aplicación del ignore
- `OutgoingRequestRecorder` recibirá la lista resuelta de hosts ignorados.
- Si el host del request coincide con alguno de `ignored_hosts`, no se envía telemetría.
- El matching debe ser determinista y case-insensitive; idealmente ignorando diferencias triviales de port si el host es el mismo.

## Testing Strategy

- `OpenWatchConfigTest` para resolución de `ignored_hosts`
- `HttpTransportTest` para split/clamp de timeouts
- `OutgoingRequestRecorderTest` para skip de hosts ignorados

## Implementation Order

1. Config derivation
2. Timeout split
3. Skip en OutgoingRequestRecorder
4. Verificación unitaria focalizada
