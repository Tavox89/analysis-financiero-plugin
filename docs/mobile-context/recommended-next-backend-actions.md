# Recommended Next Backend Actions

## Objetivo

Preparar el plugin para que otro repo pueda construir una app movil interna sin pelear contra el backend.

## Estado actual

Las fases 1 a 6 del backend movil ya quedaron cubiertas en esta base.

Desde este punto, los “siguientes pasos” ya no son abrir el contrato minimo, sino:

- mantenerlo consistente
- endurecer lo sensible
- documentar cualquier cambio nuevo de forma inmediata

## Lo que sigue despues de la Fase 6

### 1. Handoff limpio al repo movil

Prioridad:

- muy alta

Acciones:

1. tomar `api-contract.md` como fuente de verdad
2. usar `staff-auth-provisioning.md` como flujo oficial de acceso
3. usar `screens-and-flows.md` para armar navegacion y prioridades
4. no consumir endpoints no documentados como contrato movil

### 2. Hardening de errores y permisos

Prioridad:

- alta

Acciones:

1. terminar de uniformar errores HTTP y payloads de error
2. afinar matriz real de capacidades de staff
3. revisar operaciones sensibles:
   - `settle-orders`
   - `apply-credit`
   - `mark-paid`
   - `apply-payment`

### 3. Endurecer reglas operativas que la app tendra que reflejar

Prioridad:

- alta

Acciones:

1. documentar mejor las reglas de compromisos por nomina
2. documentar mejor la elegibilidad de nomina del empleado
3. documentar claramente que el front movil no debe habilitar flujos por nomina si el empleado no esta listo

### 4. Decision tecnica pendiente sobre archivos

Prioridad:

- media

Acciones:

1. confirmar si la app seguira usando la media API nativa de WordPress
2. si no, encapsular uploads despues en una capa propia

### 5. Versionado explicito del contrato movil

Prioridad:

- media

Acciones:

1. decidir si el contrato seguira bajo el mismo namespace actual
2. o si se marcara una subcapa movil documentada por version

## Historico de fases ya cubiertas

### Fase 1. Base de auth y permisos moviles

Estado:

- base implementada

Quick wins cerrados:

1. `GET /me`
2. `GET /me/permissions`
3. capacidades propias del plugin

Estado historico:

- provisionamiento y revocacion de `Application Passwords` ya documentados para staff

## Fase 2. Endurecer el contrato movil minimo

Estado:

- base implementada

Quick wins:

1. Exponer `GET /payment-methods`
2. Exponer `GET /fiscal-years`
3. Hacer que `GET /dashboard` acepte:
   - `range_from`
   - `range_to`
   - `fiscal_year`

Impacto:

- muy alto valor
- bajo riesgo
- mejora inmediata para la app

Estado historico:

- `Application Passwords` ya quedaron documentadas como flujo oficial del MVP
- el mantenimiento pendiente aqui es seguir revalidando envelope y filtros cuando cambie el contrato

## Fase 3. CRUD y lectura movil enfocados

Prioridad:

1. listas moviles estables de perfiles, movimientos y pagos
2. detalle de perfil con contexto fiscal consistente
3. respuestas operativas trazables para cobranza por perfil
4. contrato claro para que la app no dependa del admin

Estado:

- base implementada para el MVP movil

Quedo cubierto:

1. `GET /contacts` con filtros y paginacion base
2. `GET /contacts/{id}` con envelope y contexto fiscal
3. `GET /documents` con filtros y paginacion base
4. `GET /documents/{id}` con envelope y perfil relacionado
5. `GET /payments` con filtros y paginacion base
6. `POST /contacts/{id}/settle-orders` con `operation_id`
7. `POST /contacts/{id}/apply-credit` con `operation_id`

No hace falta resolver todo el CRUD del admin para arrancar movil.

## Fase 4. Contrato de receipts y archivos

Estado actual:

- `GET /receipts/{type}/{id}`
- `GET /documents/{id}/files`
- `POST /documents/{id}/files`

Notas:

- ya quedo implementado el flujo base
- la app puede subir el binario con la API media nativa de WordPress y luego vincular el `attachment_id` al movimiento

## Fase 5. Limpieza de respuestas

Estado:

- base implementada

Estandarizar:

- `data`
- `meta`
- `errors`
- paginacion
- filtros

Quedo cubierto en esta etapa:

1. envelope `data/meta` extendido a rutas secundarias utiles
2. operaciones secundarias con `operation_id` y trazabilidad cuando aplica
3. listados secundarios administrativos ya mas alineados al contrato movil

Pendiente sano que sigue abierto:

1. terminar de uniformar errores
2. definir hasta donde se versiona el contrato REST de forma explicita

## Quick wins reales

1. Documentar auth staff con `Application Passwords`
2. Agregar endpoints de catalogos
3. Agregar filtros al dashboard REST
4. Documentar errores conocidos de cada endpoint operativo
5. Ajustar respuestas para consumo movil

## Cambios importantes de contrato

1. separar respuestas administrativas muy grandes en vistas moviles mas pequenas
2. mantener y extender permisos granulares
3. documentar estrategia oficial de auth
4. versionar el contrato movil de forma mas explicita

## Mejoras para seguridad y consistencia

1. validar capacidades por modulo
2. limitar endpoints de escritura al contexto correcto
3. conservar trazabilidad de usuario origen tambien desde API
4. normalizar errores HTTP
5. revisar idempotencia en operaciones de cobro/compensacion

## Criterio actual de mantenimiento

Cada vez que cambie el backend en cualquiera de estas areas:

- endpoints
- permisos
- auth
- payloads
- respuestas
- receipts
- archivos
- reglas operativas de compromisos o nomina

hay que actualizar como minimo:

1. `docs/api-overview.md`
2. `docs/mobile-context/api-contract.md`
3. `docs/mobile-context/handoff-brief.md`
4. `docs/mobile-context/summary.md`
