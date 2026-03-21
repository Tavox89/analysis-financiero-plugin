# API Overview

Revision actual:

- plugin visible: `2.0.0-alpha94`
- backend movil: fases 1 a 6 ya sembradas
- este archivo debe reflejar el contrato REST vigente y no solo la intencion original

Namespace REST inicial:

- `/wp-json/asdl-fin/v1/me`
- `/wp-json/asdl-fin/v1/me/permissions`
- `/wp-json/asdl-fin/v1/health`
- `/wp-json/asdl-fin/v1/dashboard`
- `/wp-json/asdl-fin/v1/payment-methods`
- `/wp-json/asdl-fin/v1/currencies`
- `/wp-json/asdl-fin/v1/fiscal-years`
- `/wp-json/asdl-fin/v1/accounts`
- `/wp-json/asdl-fin/v1/contacts`
- `/wp-json/asdl-fin/v1/contacts/{id}`
- `/wp-json/asdl-fin/v1/contacts/{id}/pending-orders`
- `/wp-json/asdl-fin/v1/contacts/{id}/commitments`
- `/wp-json/asdl-fin/v1/contacts/{id}/settle-orders`
- `/wp-json/asdl-fin/v1/contacts/{id}/settle-orders/preview`
- `/wp-json/asdl-fin/v1/contacts/{id}/payroll-open-debts`
- `/wp-json/asdl-fin/v1/contacts/{id}/apply-credit`
- `/wp-json/asdl-fin/v1/contacts/{id}/employee-profile`
- `/wp-json/asdl-fin/v1/contacts/{id}/salary-advances`
- `/wp-json/asdl-fin/v1/salary-advances/{id}`
- `/wp-json/asdl-fin/v1/salary-advances/{id}/cancel`
- `/wp-json/asdl-fin/v1/contacts/{id}/payroll-periods`
- `/wp-json/asdl-fin/v1/payroll-periods/{id}`
- `/wp-json/asdl-fin/v1/payroll-periods/{id}/mark-paid`
- `/wp-json/asdl-fin/v1/payroll/queue`
- `/wp-json/asdl-fin/v1/documents`
- `/wp-json/asdl-fin/v1/documents/{id}`
- `/wp-json/asdl-fin/v1/documents/{id}/cancel`
- `/wp-json/asdl-fin/v1/documents/{id}/files`
- `/wp-json/asdl-fin/v1/payments`
- `/wp-json/asdl-fin/v1/payments/{id}`
- `/wp-json/asdl-fin/v1/payments/{id}/cancel`
- `/wp-json/asdl-fin/v1/receipts/{type}/{id}`
- `/wp-json/asdl-fin/v1/rules`
- `/wp-json/asdl-fin/v1/payment-allocations`
- `/wp-json/asdl-fin/v1/installment-plans`
- `/wp-json/asdl-fin/v1/installment-plans/{id}`
- `/wp-json/asdl-fin/v1/installment-plans/{id}/cancel`
- `/wp-json/asdl-fin/v1/installment-plans/{id}/apply-payment`
- `/wp-json/asdl-fin/v1/integrations/status`
- `/wp-json/asdl-fin/v1/sync/orders`

## Autenticacion y permisos actuales

La API usa la autenticacion REST nativa de WordPress.

Para el MVP movil interno, la base recomendada es:

- `Application Passwords` de WordPress
- usuario `wp_user` como identidad unica
- capacidades propias del plugin para autorizar modulos y acciones
- `asdl_fin_access_mobile` como puerta base de acceso a la API movil

Puntos base ya disponibles:

- `GET /wp-json/asdl-fin/v1/me`
- `GET /wp-json/asdl-fin/v1/me/permissions`

Las capacidades del plugin separan:

- acceso movil
- dashboard
- cuentas
- perfiles
- documentos
- pagos y cobranza
- compromisos
- nomina
- integraciones
- automatizaciones

Las respuestas nuevas base para movil ya empiezan a usar un sobre simple:

- `data`
- `meta`

El bloque principal del contrato movil ya usa este sobre:

- `GET /me`
- `GET /me/permissions`
- `GET /dashboard`
- `GET /payment-methods`
- `GET /currencies`
- `GET /fiscal-years`
- `GET /contacts`
- `GET /contacts/{id}`
- `GET /contacts/{id}/pending-orders`
- `GET /contacts/{id}/commitments`
- `GET /documents`
- `GET /documents/{id}`
- `POST /documents/{id}/cancel`
- `GET /documents/{id}/files`
- `POST /documents/{id}/files`
- `GET /payments`
- `GET /payments/{id}`
- `POST /payments/{id}/cancel`
- `GET /receipts/{type}/{id}`
- `POST /contacts/{id}/settle-orders`
- `POST /contacts/{id}/settle-orders/preview`
- `GET /contacts/{id}/payroll-open-debts`
- `POST /contacts/{id}/apply-credit`

Rutas secundarias ya alineadas en esta etapa:

- `GET /accounts`
- `POST /accounts`
- `POST /contacts`
- `POST /documents`
- `POST /payments`
- `GET /contacts/{id}/employee-profile`
- `POST /contacts/{id}/employee-profile`
- `GET /contacts/{id}/salary-advances`
- `GET /salary-advances/{id}`
- `POST /contacts/{id}/salary-advances`
- `POST /salary-advances/{id}/cancel`
- `GET /contacts/{id}/payroll-periods`
- `GET /payroll-periods/{id}`
- `POST /contacts/{id}/payroll-periods`
- `POST /payroll-periods/{id}/mark-paid`
- `GET /payroll/queue`
- `GET /rules`
- `POST /rules`
- `GET /payment-allocations`
- `POST /payment-allocations`
- `GET /installment-plans`
- `GET /installment-plans/{id}`
- `POST /installment-plans`
- `POST /installment-plans/{id}/cancel`
- `POST /installment-plans/{id}/apply-payment`
- `GET /integrations/status`
- `POST /sync/orders`

Listados moviles endurecidos en esta etapa:

- `GET /contacts`
  - filtros: `search`, `status`, `profile_origin`, `origin`, `role`, `is_customer`, `is_employee`, `is_supplier`
  - paginacion: `page`, `limit`
- `GET /documents`
  - filtros: `search`, `contact_id`, `wp_user_id`, `document_type`, `source_type`, `payment_status`, `balance_nature`, `financial_status`, `open_only`, `range_from`, `range_to`
  - paginacion: `page`, `limit`
- `GET /payments`
  - filtros: `search`, `contact_id`, `payment_type`, `status`, `method_key`, `available_only`, `range_from`, `range_to`
  - paginacion: `page`, `limit`

Flujos operativos moviles ya endurecidos:

- `POST /contacts/{id}/settle-orders/preview`
  - devuelve `requires_preview`
  - en caso precio dual devuelve `preview_signature`
  - devuelve `summary` e `items` para simular el reparto antes de ejecutar
- `POST /contacts/{id}/settle-orders`
  - devuelve `operation.operation_id`
  - devuelve `result.requested_total`
  - devuelve `result.applied_total`
  - devuelve `result.unapplied_total`
  - en admin, si aplica precio dual, solo ejecuta tras confirmar una `preview_signature` valida
- `POST /contacts/{id}/apply-credit`
  - devuelve `operation.operation_id`
  - devuelve `result.requested_total`
  - devuelve `result.applied_total`
  - devuelve `result.unapplied_total`
  - devuelve `result.source_payments`
  - devuelve `result.source_documents`

Estado de continuidad:

- fases 1 a 6 del backend movil ya quedaron cubiertas
- desde aqui los cambios nuevos deben revalidar este archivo, `api-contract.md` y `handoff-brief.md`

## Anulaciones y reversion operativa

La API ya expone una primera capa de anulacion operativa conservadora:

- `POST /documents/{id}/cancel`
- `POST /payments/{id}/cancel`
- `POST /salary-advances/{id}/cancel`
- `POST /installment-plans/{id}/cancel`

Reglas actuales confirmadas por codigo:

- `payments`: revierten asignaciones y aplicaciones solo si no estan ligados a nomina y si no son adelantos que deban anularse por su flujo propio
- `salary-advances`: solo se anulan si todavia no tienen recuperacion aplicada
- `documents`: solo se anulan si no tienen asignaciones ni compromisos activos ligados y si no pertenecen a nomina
- `installment-plans`: solo se anulan si no tienen cuotas ya aplicadas/pagadas

La intencion actual no es permitir reversiones agresivas, sino anulaciones auditables con motivo y protecciones para no romper saldos ni nomina.

## Objetivo

La API del plugin debe permitir:

- consultar la identidad autenticada y sus permisos efectivos
- consultar estado del core
- leer resumen del dashboard
- leer catalogo de metodos de pago
- leer ejercicios fiscales disponibles y saber si el contexto actual es de solo consulta
- leer y registrar cuentas
- leer y registrar perfiles financieros y contactos externos
- consultar el resumen financiero de un perfil, incluyendo pedidos y consumo por rango
- consultar una cola simplificada de pedidos pendientes por perfil para flujos de cobranza movil
- consultar y crear compromisos directamente desde el contexto del perfil
- aplicar abonos por antiguedad sobre pedidos Woo/OpenPOS pendientes de un perfil
- cruzar saldo a favor del perfil contra pedidos pendientes sin registrar entrada de caja
- consultar y guardar la configuracion laboral base del empleado
- consultar y registrar adelantos de sueldo descontables del empleado
- consultar el detalle de un adelanto concreto
- consultar y generar periodos de nomina del empleado
- consultar el detalle de un periodo de nomina concreto
- consultar la cola general de nomina
- procesar un periodo de nomina y descontar adelantos activos
- leer y registrar movimientos financieros
- consultar y actualizar un movimiento puntual
- consultar soportes de un movimiento y vincular archivos ya cargados en WordPress
- leer y registrar cobros, pagos y abonos
- consultar el detalle de un pago con asignaciones y aplicaciones a compromisos
- exponer comprobantes estructurados listos para mostrar o imprimir desde una app interna
- leer y registrar reglas de clasificacion
- leer y registrar asignaciones de pago
- leer y registrar compromisos, prestamos y acuerdos de pago
- consultar el detalle de un compromiso con sus cuotas y documento vinculado
- aplicar un abono directo sobre un compromiso existente
- consultar el estado de integraciones
- disparar sincronizaciones manuales de pedidos Woo/OpenPOS
- exponer puntos estables de integracion

## Receipts y archivos

El soporte movil de esta etapa ya contempla:

- `GET /receipts/{type}/{id}`
  - tipos confirmados por codigo:
    - `payment`
    - `salary_advance`
    - `payroll_period`
- `GET /documents/{id}/files`
- `POST /documents/{id}/files`

La subida binaria no se duplica dentro del plugin.
La estrategia recomendada es:

1. subir el archivo con la API media nativa de WordPress
2. tomar el `attachment_id`
3. vincularlo al movimiento con `POST /documents/{id}/files`

Para el staff movil interno, el flujo operativo de credenciales queda documentado en:

- `docs/mobile-context/staff-auth-provisioning.md`

## Eventos previstos

- `asdl_fin_account_created`
- `asdl_fin_contact_created`
- `asdl_fin_profile_linked`
- `asdl_fin_profile_promoted`
- `asdl_fin_profile_payment_applied`
- `asdl_fin_profile_credit_applied`
- `asdl_fin_employee_profile_saved`
- `asdl_fin_salary_advance_saved`
- `asdl_fin_payroll_period_created`
- `asdl_fin_payroll_period_paid`
- `asdl_fin_document_created`
- `asdl_fin_document_updated`
- `asdl_fin_payment_recorded`
- `asdl_fin_payment_allocated`
- `asdl_fin_installment_plan_created`
- `asdl_fin_rule_created`
- `asdl_fin_sync_completed`
