# Package — Memory Bootstrap

## Identidad

- Repo: `package/`
- Proyecto Engram objetivo: `package`
- Rol: paquete Laravel `dorvianes/openwatch`

## Qué sabemos de este repo

- Instrumenta aplicaciones Laravel y envía eventos al `service-server`
- Tiene transport, config y recorders como núcleo técnico
- `cliente-demo` lo consume localmente para validar integración real
- Su documentación pública afecta directamente la instalación/configuración del producto

## Memoria histórica que conceptualmente le pertenece

- bootstrap del package dentro del sistema OpenWatch
- `ExceptionRecorder v1`
- helper `RegistersExceptionReporting`
- alineación con ingestion keys
- query/outgoing instrumentation del lado package
- publicación GitHub, releases y evolución de README/config

## Qué guardar acá a partir de ahora

- cambios en transport/recorders/config/jobs/docs del package
- decisiones de API/configuración pública
- bugs del paquete y su causa raíz
- testing capabilities y límites del paquete
- cambios de release/tag/upgrade del package

## Qué NO guardar acá

- decisiones internas de dashboard/UI del server
- wiring puramente local de la demo, salvo que impacte contrato del package
- arquitectura global del producto salvo resumen mínimo

## Regla práctica

Si el cambio responde a “¿cómo instrumenta, configura o distribuye telemetría el paquete?” entonces pertenece a `package`.
