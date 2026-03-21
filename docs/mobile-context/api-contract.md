# API Contract Audit

Revision validada:

- plugin visible: `2.0.0-alpha94`
- backend movil: fases 1 a 6 ya sembradas

## Estado actual del contrato

La API REST existe y es real, pero hoy es una API administrativa interna, no una API movil cerrada y formalizada.

Base confirmada:

- namespace: `/wp-json/asdl-fin/v1`
- implementacion: `src/Api/Routes.php`
- autenticacion actual: la propia de WordPress REST
- base de auth recomendada para MVP movil: `Application Passwords`
- permisos actuales: capacidades propias del plugin con fallback de administracion WordPress

Conclusion rapida:

- sirve para backoffice
- ya puede servir como base operativa para un MVP movil staff-only
- ya cubre el bloque principal del MVP movil
- ya expone tambien una primera capa post-MVP para compromisos, adelantos y nomina
- aun faltan uniformar errores, filtros y algunas rutas fuera del bloque movil principal

## Autenticacion real hoy

La API asume que el request ya viene autenticado por WordPress REST.

Para una app movil interna, hoy lo razonable seria usar:

- `Application Passwords` de WordPress como MVP de autenticacion

Lo que existe hoy:

- `GET /me`
- `GET /me/permissions`
- capacidades granulares del plugin para acceso movil y modulos
- `asdl_fin_access_mobile` como permiso base de acceso
- envelope `data/meta` ya aplicado al bloque movil principal

Lo que no existe hoy:

- login REST propio
- refresh tokens
- provisionamiento automatizado de credenciales
- roles moviles dedicados preconfigurados

## Endpoints confirmados

### Core

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| GET | `/me` | Confirmado |
| GET | `/me/permissions` | Confirmado |
| GET | `/health` | Confirmado |
| GET | `/dashboard` | Confirmado |
| GET | `/payment-methods` | Confirmado |
| GET | `/currencies` | Confirmado |
| GET | `/fiscal-years` | Confirmado |
| GET | `/receipts/{type}/{id}` | Confirmado |
| GET | `/integrations/status` | Confirmado |
| POST | `/sync/orders` | Confirmado |

Query params confirmados para `/dashboard`:

- `fiscal_year`
- `range_from`
- `range_to`

Respuesta confirmada para `/me`:

- `user.id`
- `user.username`
- `user.display_name`
- `user.email`
- `user.roles`
- `permissions`
- `auth.provider`
- `auth.application_passwords_available`
- `auth.application_passwords_for_user`

Respuesta confirmada para `/me/permissions`:

- `capabilities`
- `permissions`
- `route_groups`

Respuesta confirmada para `/payment-methods`:

- `data.items[]`
- `meta.count`
- `meta.selectable`

Cada item expone:

- `key`
- `label`
- `kind`
- `selectable`

Respuesta confirmada para `/currencies`:

- `data.items[]`
- `meta.count`

Cada item expone:

- `code`
- `label`
- `kind`

Respuesta confirmada para `/fiscal-years`:

- `data.selected`
- `data.active`
- `data.years`
- `data.settings`
- `meta.count`
- `meta.selected_year`
- `meta.read_only`

Respuesta confirmada para `/receipts/{type}/{id}`:

- `data.receipt`
- `data.branding`
- `meta.receipt_type`
- `meta.entity_id`
- `meta.printable`

Tipos confirmados por codigo:

- `payment`
- `salary_advance`
- `payroll_period`

### Cuentas

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| GET | `/accounts` | Confirmado |
| POST | `/accounts` | Confirmado |

Payload confirmado para crear:

- `name` requerido
- `code`
- `account_type`
- `currency`
- `status`
- `notes`

### Perfiles

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| GET | `/contacts` | Confirmado |
| POST | `/contacts` | Confirmado |
| GET | `/contacts/{id}` | Confirmado |
| GET | `/contacts/{id}/pending-orders` | Confirmado |
| GET | `/contacts/{id}/commitments` | Confirmado |
| POST | `/contacts/{id}/commitments` | Confirmado |

Query params confirmados para `GET /contacts`:

- `page`
- `limit`
- `search`
- `status`
- `profile_origin`
- `origin`
- `role`
- `is_customer`
- `is_employee`
- `is_supplier`

Payload confirmado para crear:

- `display_name` requerido
- `legal_name`
- `profile_origin`
- `wp_user_id`
- `is_customer`
- `is_employee`
- `is_supplier`
- `email`
- `phone`
- `document_id`
- `payment_terms`
- `status`
- `notes`

Respuesta detalle de perfil:

- `contact`
- `filters`
- `summary`
- `documents`
- `open_documents`
- `payments`
- `allocations`
- `plans`
- `employee_profile`
- `salary_advances`
- `salary_advance_summary`
- `payroll_periods`
- `payroll_summary`
- `pending_orders`
- `pending_order_summary`
- `orders`
- `order_summary`
- `consumption_timeline`

Observacion:

- este endpoint ya es valioso para movil porque concentra mucho contexto por perfil
- ya usa envelope `data/meta`
- la respuesta sigue siendo grande, asi que conviene tratarlo como pantalla detalle y no como listado reutilizable
- el detalle ya acepta `fiscal_year`, `range_from`, `range_to` y `order_limit`

Lectura operativa complementaria ya disponible:

- `GET /contacts/{id}/pending-orders`
  - devuelve `contact`
  - devuelve `items`
  - devuelve `summary`
  - devuelve `meta.count`
  - devuelve `meta.total`
  - devuelve `meta.limit`
- `GET /contacts/{id}/commitments`
  - filtros confirmados: `limit`, `status`, `settlement_direction`, `collection_mode`
  - devuelve `contact`
  - devuelve `items`
  - devuelve `summary`
  - devuelve `meta.count`
  - devuelve `meta.total`
  - devuelve `meta.limit`

Meta confirmada en `GET /contacts/{id}`:

- `meta.filters.range_from`
- `meta.filters.range_to`
- `meta.filters.fiscal_year`
- `meta.filters.order_limit`
- `meta.fiscal_year`
- `meta.read_only`

### Operacion por perfil

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| POST | `/contacts/{id}/settle-orders/preview` | Confirmado |
| POST | `/contacts/{id}/settle-orders` | Confirmado |
| POST | `/contacts/{id}/apply-credit` | Confirmado |

Respuesta confirmada para `settle-orders/preview`:

- `data.requires_preview`
- `data.mode`
- `data.currency`
- `data.payment_method`
- `data.discount`
- `data.rate_snapshot`
- `data.summary`
- `data.items`
- `data.preview_signature`
- `meta.preview`

Payload confirmado para `settle-orders`:

- `total` requerido
- `account_id`
- `payment_date`
- `currency`
- `method_key`
- `reference`
- `notes`
- `client_operation_id` opcional
- `dual_discount_preview_confirmed` requerido solo cuando el metodo activa precio dual en admin
- `dual_discount_preview_signature` requerido solo cuando el metodo activa precio dual en admin

Respuesta confirmada:

- `data.message`
- `data.operation.type`
- `data.operation.operation_id`
- `data.operation.client_operation_id` opcional
- `data.operation.contact_id`
- `data.operation.payment_id`
- `data.result.contact_id`
- `data.result.payment_id`
- `data.result.requested_total`
- `data.result.applied_total`
- `data.result.unapplied_total`
- `data.result.closed_order_ids`
- `data.result.partial_order_ids`
- `data.result.allocations`
- `data.result.dual_discount_applied` cuando aplica
- `data.result.dual_discount_total` cuando aplica
- `meta.idempotency_enforced`
- `meta.traceable_by`

Payload confirmado para `apply-credit`:

- `total` requerido
- `account_id`
- `payment_date`
- `currency`
- `reference`
- `notes`
- `client_operation_id` opcional

Respuesta confirmada:

- `data.message`
- `data.operation.type`
- `data.operation.operation_id`
- `data.operation.client_operation_id` opcional
- `data.operation.contact_id`
- `data.operation.payment_ids`
- `data.result.contact_id`
- `data.result.requested_total`
- `data.result.applied_total`
- `data.result.unapplied_total`
- `data.result.closed_order_ids`
- `data.result.partial_order_ids`
- `data.result.allocations`
- `data.result.source_payments`
- `data.result.source_documents`
- `data.result.compensation_payment_ids`
- `meta.idempotency_enforced`
- `meta.traceable_by`

### Empleados

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| GET | `/contacts/{id}/employee-profile` | Confirmado |
| POST | `/contacts/{id}/employee-profile` | Confirmado |
| GET | `/contacts/{id}/salary-advances` | Confirmado |
| POST | `/contacts/{id}/salary-advances` | Confirmado |
| GET | `/salary-advances/{id}` | Confirmado |
| POST | `/salary-advances/{id}/cancel` | Confirmado |
| GET | `/contacts/{id}/payroll-periods` | Confirmado |
| POST | `/contacts/{id}/payroll-periods` | Confirmado |
| GET | `/payroll-periods/{id}` | Confirmado |
| POST | `/payroll-periods/{id}/mark-paid` | Confirmado |
| GET | `/payroll/queue` | Confirmado |

Payload confirmado para `employee-profile`:

- `salary_amount` requerido
- `salary_currency`
- `pay_frequency`
- `payday_mode`
- `payday_value` o `payday_value_monthly`
- `cycle_anchor_date`
- `effective_from`
- `next_payment_date`
- `last_payment_date`
- `default_account_id`
- `notes`
- `birth_date`
- `hire_date`
- `contract_type`
- `contract_start_date`
- `contract_end_date`
- `contract_attachment_id`
- `termination_type`
- `termination_date`
- `termination_reason`
- `employment_status`

Payload confirmado para `salary-advances`:

- `total_amount` requerido
- `issued_at`
- `expected_recovery_date`
- `recovery_mode`
- `currency`
- `source_account_id`
- `reference`
- `notes`

Respuesta confirmada para `GET /salary-advances/{id}`:

- `data.salary_advance`
- `data.contact`
- `data.payment`
- `data.receipt`
- `meta.salary_advance_id`
- `meta.traceable_by`

Payload confirmado para cancelar `salary-advance`:

- `cancel_reason` requerido
- `reason` como alias tolerado

Regla actual:

- solo se puede anular si el adelanto no tiene monto recuperado

Payload confirmado para `payroll-periods`:

- `scheduled_payment_date`
- `period_start`
- `period_end`
- `gross_amount`
- `other_deduction_amount`
- `payment_account_id`
- `payment_method_key`
- `currency`
- `title`
- `notes`

Respuesta confirmada para `GET /payroll-periods/{id}`:

- `data.payroll_period`
- `data.contact`
- `data.employee_profile`
- `data.document`
- `data.receipt`
- `meta.payroll_period_id`
- `meta.traceable_by`

Payload confirmado para `mark-paid`:

- `paid_at`
- `payment_account_id`
- `payment_method_key`
- `reference`
- `notes`

Respuesta confirmada para `GET /payroll/queue`:

- `data.items`
- `data.summary`
- `meta.count`
- `meta.limit`
- `meta.filters`

### Movimientos

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| GET | `/documents` | Confirmado |
| POST | `/documents` | Confirmado |
| GET | `/documents/{id}` | Confirmado |
| POST | `/documents/{id}` | Confirmado |
| POST | `/documents/{id}/cancel` | Confirmado |
| GET | `/documents/{id}/files` | Confirmado |
| POST | `/documents/{id}/files` | Confirmado |

Query params confirmados para `GET /documents`:

- `page`
- `limit`
- `search`
- `contact_id`
- `wp_user_id`
- `document_type`
- `source_type`
- `payment_status`
- `balance_nature`
- `financial_status`
- `open_only`
- `range_from`
- `range_to`

Payload confirmado para crear:

- `title` requerido
- `document_number`
- `document_type`
- `source_type`
- `account_id`
- `contact_id`
- `wp_user_id`
- `parent_document_id`
- `external_reference`
- `issue_date`
- `due_date`
- `currency`
- `total`
- `paid_total`
- `operational_status`
- `financial_status`
- `payment_status`
- `manual_override`
- `notes`

La respuesta de detalle incluye:

- `document`
- `contact`
- `source_links`

La capa de archivos ya permite:

- listar soportes vinculados al movimiento
- vincular un `attachment_id` existente de WordPress al movimiento

Estrategia confirmada para la app:

1. subir el binario con la API media nativa de WordPress
2. tomar el `attachment_id`
3. llamar `POST /documents/{id}/files`

La respuesta de lista ya usa:

- `data.items[]`
- `meta.count`
- `meta.total`
- `meta.page`
- `meta.limit`
- `meta.total_pages`
- `meta.filters`

Cada item puede incluir:

- `contact`
- `is_open`

Payload confirmado para cancelar `document`:

- `cancel_reason` requerido
- `reason` como alias tolerado

Regla actual:

- solo se anula si no tiene asignaciones
- no debe tener compromisos activos ligados
- no debe ser documento de nomina

### Pagos y asignaciones

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| GET | `/payments` | Confirmado |
| POST | `/payments` | Confirmado |
| GET | `/payments/{id}` | Confirmado |
| POST | `/payments/{id}/cancel` | Confirmado |
| GET | `/payment-allocations` | Confirmado |
| POST | `/payment-allocations` | Confirmado |

Query params confirmados para `GET /payments`:

- `page`
- `limit`
- `search`
- `contact_id`
- `payment_type`
- `status`
- `method_key`
- `available_only`
- `range_from`
- `range_to`

Payload confirmado para crear `payment`:

- `total` requerido
- `payment_type`
- `account_id`
- `contact_id`
- `status`
- `payment_date`
- `currency`
- `method_key`
- `reference`
- `notes`

La respuesta de lista ya usa:

- `data.items[]`
- `meta.count`
- `meta.total`
- `meta.page`
- `meta.limit`
- `meta.total_pages`
- `meta.filters`

Cada item puede incluir:

- `contact`
- `has_available_amount`

Respuesta confirmada para `GET /payments/{id}`:

- `data.payment`
- `data.contact`
- `data.allocations`
- `data.commitment_applications`
- `data.receipt`
- `meta.payment_id`
- `meta.traceable_by`

Payload confirmado para cancelar `payment`:

- `cancel_reason` requerido
- `reason` como alias tolerado

Efecto actual:

- revierte asignaciones a documentos
- restaura `available_amount` y balances cuando aplica
- reabre pedidos vinculados cuando la anulacion impacta un documento operativo

Regla actual:

- no se anulan por esta ruta pagos ligados a nomina
- los adelantos de sueldo se anulan por su propio flujo

Payload confirmado para crear `payment-allocation`:

- `payment_id` requerido
- `document_id` requerido
- `amount` requerido
- `notes`

### Compromisos

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| GET | `/installment-plans` | Confirmado |
| POST | `/installment-plans` | Confirmado |
| GET | `/installment-plans/{id}` | Confirmado |
| POST | `/installment-plans/{id}/cancel` | Confirmado |
| POST | `/installment-plans/{id}/apply-payment` | Confirmado |

Payload confirmado para crear compromiso:

- `title` requerido
- `principal_amount`
- `total_amount`
- `target_installment_amount`
- `installment_count`
- `commitment_origin`
- `settlement_direction`
- `collection_mode`
- `plan_type`
- `document_id`
- `contact_id`
- `currency`
- `status`
- `start_date`
- `end_date`
- `frequency_key`
- `notes`

Payload confirmado para aplicar pago:

- `amount` requerido
- `payment_date`
- `create_payment`
- `payment_id`
- `payment_type`
- `account_id`
- `currency`
- `method_key`
- `reference`
- `notes`
- `origin`

Respuesta confirmada para `GET /installment-plans/{id}`:

- `data.commitment`
- `data.contact`
- `data.document`
- `data.installments`
- `data.summary`
- `meta.plan_id`
- `meta.traceable_by`

Payload confirmado para cancelar `installment-plan`:

- `cancel_reason` requerido
- `reason` como alias tolerado

Regla actual:

- solo se anula si no tiene cuotas ya aplicadas/pagadas

## Anulaciones

La capa de anulacion ya existe, pero es deliberadamente conservadora.

Rutas confirmadas:

- `POST /documents/{id}/cancel`
- `POST /payments/{id}/cancel`
- `POST /salary-advances/{id}/cancel`
- `POST /installment-plans/{id}/cancel`

Respuesta confirmada en estas rutas:

- `data.message`
- `data.operation`
- `data.result`
- `meta.traceable_by`

Limitacion actual:

- no existe aun un motor unico y general para anular cualquier objeto del dominio
- la anulacion hoy se hace por flujo especifico y con bloqueos de negocio fuertes

La capa post-MVP ahora tambien permite:

- listar y crear compromisos dentro del contexto del perfil con `GET/POST /contacts/{id}/commitments`
- consultar el detalle de un compromiso con cuotas, documento vinculado y resumen
- consultar el detalle de un adelanto concreto con su pago relacionado
- consultar el detalle de un periodo de nomina
- consultar la cola general de nomina para staff
- consultar el detalle de un pago con asignaciones y aplicaciones a compromisos

### Reglas

| Metodo | Ruta | Estado real |
| --- | --- | --- |
| GET | `/rules` | Confirmado |
| POST | `/rules` | Confirmado |

## Respuestas y errores

### Envelope base nuevo

Los endpoints orientados al MVP movil ya usan:

- `data`
- `meta`

### Respuesta tipica de lista

Los listados que ya pasaron por endurecimiento movil exponen:

- `data.items`
- `meta.count`
- `meta.total`
- `meta.page`
- `meta.limit`
- `meta.total_pages`
- `meta.filters`

Limitacion:

- todavia no existe este formato en toda la API
- sigue faltando un contrato de errores mas formal

### Respuesta tipica de alta

Muchas rutas devuelven:

- `id`
- `message`

Algunas devuelven `data`, otras no.

### Errores

El contrato usa `WP_Error`. Los errores confirmados traen al menos:

- `code`
- `message`
- opcionalmente `status`

No existe hoy una taxonomia documentada de errores para app movil.

## Vacios del contrato

Confirmados por codigo:

- no hay login propio
- no hay CRUD completo para casi ningun recurso
- no hay `DELETE`
- no hay endpoint de branding aislado; el branding viaja embebido en el receipt
- no hay upload binario propio dentro del namespace del plugin
- faltan filtros consistentes en varios endpoints secundarios
- el envelope `data/meta` ya cubre el bloque movil principal y varias rutas secundarias, pero aun no toda la API

## Diferencias entre docs y codigo

### Documentacion actual vs implementacion real

- `docs/api-overview.md` lista rutas y objetivos, pero no documenta todo el contrato ni los errores
- `docs/api-overview.md` ya cubre el bloque de receipts y archivos, pero la documentacion todavia no detalla todos los errores ni todos los endpoints secundarios

## Recomendaciones minimas para dejar el backend listo para movil

1. Exponer autenticacion interna razonable para app staff:
   - MVP: `Application Passwords`
   - luego: token propio
2. Mantener actualizado el flujo operativo de `Application Passwords`
3. Exponer catalogos:
   - payment methods
   - fiscal years
   - status/options
4. Completar CRUD minimo de:
   - profiles
   - documents
   - payments
   - commitments
   - payroll periods
5. Formalizar paginacion y filtros
6. Terminar de uniformar receipts, archivos y endpoints secundarios que aun no usan el mismo nivel de envelope/filtros/errores
