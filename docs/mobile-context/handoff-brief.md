# Mobile Handoff Brief

Base consolidada para otro agente o repo movil.

## Estado actual del plugin

El plugin actual es `Finanzas ASD`, una suite financiera modular para WordPress/WooCommerce con tablas propias.

Version visible auditada:

- `2.0.0-alpha94`

Schema objetivo auditado:

- `2026.03.19-alpha9`

El sistema ya maneja:

- perfiles internos y externos
- movimientos financieros
- pagos y asignaciones
- saldo a favor
- compromisos por cobrar y por pagar
- empleados, adelantos y nomina
- sincronizacion selectiva con Woo/OpenPOS

## Contexto de negocio

La app movil sera interna para staff autorizado.

No es una app publica para clientes.
La identidad de acceso debe seguir siendo el usuario WordPress.
El flujo de acceso del MVP usa `Application Passwords` de WordPress y validacion inicial con `GET /me`.

## Arquitectura relevante

- pedidos operativos: Woo/OpenPOS
- core financiero: tablas `asdl_fin_*`
- API REST: `src/Api/Routes.php`
- sincronizacion financiera: `OrderSyncService`
- cobranza por perfil: `ProfileOrderSettlementService`
- nomina: `EmployeeProfilesRepository`, `EmployeeAdvancesRepository`, `PayrollPeriodsRepository`
- compromisos: `InstallmentPlansRepository`, `CommitmentSettlementService`

## Modelo de dominio detectado

La entidad tecnica central es `Documento`, aunque la UI use `Movimiento`.

Entidades clave:

- Perfil
- Documento
- Pago
- Asignacion
- Compromiso
- Cuota
- Perfil laboral
- Adelanto
- Periodo de nomina
- Vinculo externo

Un pedido Woo no se duplica por defecto. Solo entra al core cuando se gestiona financieramente.

## Estado real de la API

La API existe bajo:

- `/wp-json/asdl-fin/v1`

Rutas operativas mas importantes:

- `GET /me`
- `GET /me/permissions`
- `GET /dashboard`
- `GET /payment-methods`
- `GET /currencies`
- `GET /fiscal-years`
- `GET /receipts/{type}/{id}`
- `GET /contacts`
- `GET /contacts/{id}`
- `GET /contacts/{id}/pending-orders`
- `GET /contacts/{id}/commitments`
- `POST /contacts/{id}/commitments`
- `POST /contacts/{id}/settle-orders`
- `POST /contacts/{id}/settle-orders/preview`
- `GET /contacts/{id}/payroll-open-debts`
- `POST /contacts/{id}/apply-credit`
- `GET /documents`
- `GET /documents/{id}/files`
- `POST /documents/{id}/files`
- `GET /payments`
- `GET /payments/{id}`
- `POST /payments/{id}/cancel`
- `GET /salary-advances/{id}`
- `POST /salary-advances/{id}/cancel`
- `GET /payroll-periods/{id}`
- `GET /payroll/queue`
- `GET /installment-plans/{id}`
- `POST /installment-plans/{id}/cancel`
- `POST /documents/{id}/cancel`

Tambien existen rutas de:

- employee profile
- salary advances
- payroll periods
- commitments

Limite actual:

- la autenticacion movil recomendada es `Application Passwords`
- ya existen `/me` y `/me/permissions`
- ya existen capacidades propias del plugin para acceso movil y modulos
- ya existen catalogos base de metodos de pago y ejercicios fiscales
- el bloque principal del MVP ya usa envelope `data/meta`
- `contacts`, `documents` y `payments` ya tienen filtros y paginacion base
- `settle-orders` y `apply-credit` ya devuelven `operation_id` para trazabilidad
- `settle-orders/preview` ya expone simulacion y `preview_signature` para confirmar abonos con precio dual antes de ejecutar
- `payroll-open-debts` ya expone las deudas abiertas del empleado para preparar descuentos manuales desde la cola de nomina
- receipts y soportes de documentos ya tienen contrato REST base
- el flujo operativo para emitir/revocar `Application Passwords` quedo documentado
- la capa post-MVP ya expone detalle y operacion real para compromisos, adelantos, pagos y nomina
- la capa de anulacion operativa ya existe para pagos, adelantos, documentos y compromisos, con reglas conservadoras de bloqueo
- las fases 1 a 6 del backend movil ya quedaron sembradas; lo siguiente ya no es “abrir la API”, sino handoff, hardening y espejo funcional en la app

## Propuesta de MVP movil

El MVP movil recomendado es una app interna de consulta + cobranza por perfil.

Alcance recomendado:

- login staff
- dashboard
- perfiles
- detalle de perfil
- pedidos pendientes
- registrar abono por perfil
- aplicar saldo a favor
- ver movimientos
- ver pagos

No replicar todo el admin.

## Pagos y abonos desde movil

Si, es viable incluirlos en el MVP si se limitan a flujos por perfil.

Lo viable hoy:

- `settle-orders`
- `apply-credit`

Lo que no conviene exponer aun:

- asignacion manual generica
- nomina completa
- compromisos complejos

## Riesgos mas importantes

1. el envelope `data/meta` ya cubre el bloque movil principal y varias rutas secundarias, pero no toda la API
2. los errores HTTP y la taxonomia de errores siguen siendo mas WordPress que movil
3. archivos binarios siguen dependiendo de la API media nativa de WordPress y no de un upload propio del plugin
4. todavia faltan roles/capacidades internas mas afinados para staff real
5. la expansion post-MVP aun no esta cerrada para compromisos avanzados y nomina movil
6. si la app luego quiere replicar compromisos por nomina, debe respetar la misma regla del admin: no habilitar gestion por nomina si el empleado no esta listo para nomina
7. las anulaciones moviles no deben asumirse como libres: el backend ya bloquea cancelaciones con recuperacion, asignaciones o nomina ligada

## Siguiente fase recomendada

### Fase 7. Handoff al repo movil

Entrada recomendada:

1. Fase 1 a Fase 6 cerradas
2. usar `api-contract.md`, `screens-and-flows.md` y `staff-auth-provisioning.md` como contrato base
3. abrir el repo movil con foco en auth staff, dashboard, perfiles, cobranza y capa post-MVP gradual
4. seguir revisando documentacion si el backend cambia dominio, permisos o payloads

## Decision honesta

Si se puede empezar un MVP movil real con este backend para staff interno. La base ya alcanza para dashboard, perfiles, movimientos, pagos y cobranza por perfil; lo que falta antes de ampliar alcance es soporte complementario, no rehacer el nucleo.
