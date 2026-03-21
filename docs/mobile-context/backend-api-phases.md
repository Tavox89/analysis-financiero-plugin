# Backend API Phases

## Objetivo

Dejar el backend de `Finanzas ASD` listo para que otro repo pueda construir la app movil sin depender del admin de WordPress ni de contexto oral de una sesion previa.

## Estado consolidado actual

- Fase 0 cerrada
- Fase 1 cerrada
- Fase 2 cerrada
- Fase 3 cerrada
- Fase 4 cerrada
- Fase 5 cerrada
- Fase 6 cerrada

El backend movil ya no esta en fase de apertura de contrato base. Desde este punto, el trabajo siguiente es:

- handoff al repo movil
- hardening de permisos/errores
- mantener documentacion viva si cambian dominio, payloads o reglas operativas

## Regla de trabajo

Hay dos carriles que pueden convivir:

- carril A: readiness del backend movil
- carril B: correcciones de UX/logica del plugin

Si un cambio del carril B toca dominio, endpoints, payloads, permisos, ejercicio fiscal o flujo financiero, hay que revalidar el carril A y actualizar documentacion.

## Fase 0. Base documental y continuidad

Estado:

- lista

Entregables:

- `docs/mobile-context/summary.md`
- `docs/mobile-context/current-architecture.md`
- `docs/mobile-context/domain-map.md`
- `docs/mobile-context/api-contract.md`
- `docs/mobile-context/mobile-readiness.md`
- `docs/mobile-context/mobile-mvp.md`
- `docs/mobile-context/screens-and-flows.md`
- `docs/mobile-context/gaps-and-open-questions.md`
- `docs/mobile-context/recommended-next-backend-actions.md`
- `docs/mobile-context/handoff-brief.md`

Objetivo:

- dejar contexto oficial para otra sesion o agente

## Fase 1. Auth y acceso movil

Objetivo:

- usar la identidad WordPress sin crear otro sistema de usuarios

Estado:

- base tecnica implementada

Decision base:

- `Application Passwords` para el MVP

Backlog:

1. definir el flujo operativo para emitir credenciales de app al staff
2. documentar y estandarizar el flujo operativo para emitir/revocar `Application Passwords`
3. crear `GET /me`
4. crear `GET /me/permissions`
5. crear capacidades propias del plugin:
   - `asdl_fin_access_mobile`
   - capacidades por modulo

Criterio de cierre:

- un usuario WP autorizado puede autenticarse desde app
- la app puede saber quien es y que puede hacer

Avance actual:

- `GET /me` implementado
- `GET /me/permissions` implementado
- capacidades propias del plugin implementadas
- flujo operativo de provisionamiento para staff documentado en `docs/mobile-context/staff-auth-provisioning.md`

Dependencias:

- ninguna externa critica

## Fase 2. Contrato REST minimo estable

Objetivo:

- que la app consuma un contrato claro y no la forma casual del admin

Estado:

- base tecnica implementada

Backlog:

1. exponer `GET /payment-methods`
2. exponer `GET /fiscal-years`
3. hacer que `GET /dashboard` acepte:
   - `range_from`
   - `range_to`
   - `fiscal_year`
4. normalizar respuestas:
   - `data`
   - `meta`
   - `errors`
5. normalizar paginacion y filtros basicos

Criterio de cierre:

- dashboard, catalogos y contexto fiscal quedan listos para consumo movil

Avance actual:

- `GET /payment-methods` implementado
- `GET /fiscal-years` implementado
- `GET /dashboard` ya acepta `fiscal_year`, `range_from` y `range_to`
- el bloque base nuevo ya usa envelope `data/meta`
- pendiente: extender normalizacion y filtros al resto de endpoints clave

Dependencias:

- Fase 1

## Fase 3. Flujos operativos del MVP movil

Objetivo:

- soportar consulta y cobranza desde perfil

Estado:

- base tecnica implementada

Backlog:

1. revisar y endurecer:
   - `GET /contacts`
   - `GET /contacts/{id}`
   - `GET /documents`
   - `GET /documents/{id}`
   - `GET /payments`
2. endurecer:
   - `POST /contacts/{id}/settle-orders`
   - `POST /contacts/{id}/apply-credit`
3. definir errores operativos claros
4. revisar idempotencia y trazabilidad

Criterio de cierre:

- la app ya puede:
  - ver dashboard
  - buscar perfil
  - ver detalle de perfil
  - cobrar pedidos
  - aplicar saldo a favor

Avance actual:

- `GET /contacts` ya usa envelope `data/meta`
- `GET /contacts/{id}` ya respeta contexto fiscal y responde con envelope `data/meta`
- `GET /documents` ya expone filtros y paginacion base para movil
- `GET /documents/{id}` ya devuelve envelope estable y resumen del perfil relacionado
- `GET /payments` ya expone filtros y paginacion base para movil
- `POST /contacts/{id}/settle-orders` ya devuelve operacion trazable con `operation_id`
- `POST /contacts/{id}/apply-credit` ya devuelve operacion trazable con `operation_id`
- pendiente: documentar receipts/archivos y endurecer mas endpoints secundarios fuera del MVP

Dependencias:

- Fase 2

## Fase 4. Soporte complementario para movil

Objetivo:

- dejar lista la capa auxiliar que la app necesitara muy pronto

Backlog:

1. receipts por API
2. adjuntos/archivos por API si aplica
3. si hace falta, endpoint mas limpio para pedidos pendientes por perfil
4. documentar payloads/respuestas definitivas

Criterio de cierre:

- la app ya puede mostrar comprobantes y apoyarse mejor en archivos

Avance actual:

- `GET /receipts/{type}/{id}` implementado
- `GET /documents/{id}/files` implementado
- `POST /documents/{id}/files` implementado
- `GET /contacts/{id}/pending-orders` implementado como lectura operativa simplificada
- provisionamiento de `Application Passwords` documentado para staff

Dependencias:

- Fase 3

## Fase 5. Limpieza y endurecimiento de endpoints secundarios

Objetivo:

- reducir diferencias entre el bloque movil principal y las rutas secundarias ya existentes

Estado:

- base tecnica implementada

Backlog:

1. extender envelope `data/meta` a endpoints secundarios utiles para mobile
2. devolver operaciones trazables en escrituras secundarias
3. alinear rutas administrativas reutilizables con el contrato movil
4. actualizar documentacion y continuidad

Criterio de cierre:

- las rutas secundarias mas cercanas al dominio movil ya no responden con formato puramente administrativo

Avance actual:

- `GET /accounts`, `GET /rules`, `GET /payment-allocations` y `GET /installment-plans` ya usan envelope `data/meta`
- `POST /accounts`, `POST /contacts`, `POST /documents`, `POST /payments`, `POST /rules` y `POST /installment-plans` ya responden con envelope estable y metadatos de creacion
- `GET /contacts/{id}/employee-profile`, `POST /contacts/{id}/employee-profile`
- `GET /contacts/{id}/salary-advances`, `POST /contacts/{id}/salary-advances`
- `GET /contacts/{id}/payroll-periods`, `POST /contacts/{id}/payroll-periods`
- `POST /payroll-periods/{id}/mark-paid`
- `POST /payment-allocations`
- `POST /installment-plans/{id}/apply-payment`
- `GET /integrations/status` y `POST /sync/orders`
- las operaciones secundarias clave ya devuelven `operation_id` y metadatos de trazabilidad cuando aplica

Dependencias:

- Fase 4

## Fase 6. Expansion post-MVP

Objetivo:

- ampliar operaciones sin meter ruido al MVP inicial

Estado:

- base tecnica implementada

Backlog tentativo:

1. compromisos con escritura movil
2. adelantos con escritura movil
3. nomina movil parcial o total
4. movimientos simples desde app
5. mejoras de seguridad y auditoria

Avance actual:

- `GET/POST /contacts/{id}/commitments` implementado
- `GET /installment-plans/{id}` implementado
- `GET /salary-advances/{id}` implementado
- `GET /payroll-periods/{id}` implementado
- `GET /payroll/queue` implementado
- `GET /payments/{id}` implementado
- la app ya puede entrar a una capa post-MVP de detalle y operacion sin depender del admin para compromisos, adelantos y nomina

## Fase 7. Handoff y continuidad

Objetivo:

- arrancar el repo movil sin perder el contexto tecnico ni funcional

Backlog base:

1. usar `api-contract.md` como contrato tecnico inicial
2. usar `screens-and-flows.md` para estructura de pantallas
3. usar `staff-auth-provisioning.md` para provisionamiento de staff
4. revalidar docs cada vez que cambie:
   - dominio
   - payloads
   - permisos
   - receipts
   - archivos
   - flujos de cobranza, compromisos o nomina

Dependencias:

- Fase 5

## Fase 7. Handoff al repo movil

Objetivo:

- arrancar frontend con backend ya estable

Entradas minimas:

- Fase 1
- Fase 2
- Fase 3 cerradas

Entrada recomendada:

- Fase 4, Fase 5 y Fase 6 cerradas para reducir friccion del repo movil

Con eso se puede abrir el repo de la app para:

- auth
- dashboard
- perfiles
- detalle de perfil
- cobranza

## Reglas de versionado y documentacion

Cada vez que se toque el backend movil:

1. si cambia funcionalidad visible del plugin, subir `ASDL_FINANCE_VERSION`
2. si cambian tablas o migraciones, subir `Schema::VERSION`
3. si cambia cualquier endpoint, payload, respuesta, permiso o flujo REST:
   - actualizar `docs/mobile-context/api-contract.md`
   - actualizar `docs/api-overview.md`
   - actualizar `docs/mobile-context/handoff-brief.md` si cambia el alcance
4. si cambia el alcance del MVP movil:
   - actualizar `docs/mobile-context/mobile-mvp.md`
   - actualizar `docs/mobile-context/screens-and-flows.md`

## Protocolo de reanudacion de sesion

Antes de continuar en una sesion nueva:

1. leer `README.md`
2. leer `docs/mobile-context/handoff-brief.md`
3. leer `docs/mobile-context/backend-api-phases.md`
4. leer `docs/mobile-context/api-contract.md`
5. leer `docs/mobile-context/gaps-and-open-questions.md`
6. verificar version en:
   - `analysis-financiero-plugin.php`
   - `src/Core/Schema.php`
7. revisar si desde la ultima sesion cambiaron:
   - endpoints
   - tablas
   - permisos
   - contexto fiscal
   - flujos de cobranza/compromisos/nomina
