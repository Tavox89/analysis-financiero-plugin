# Current Mobile Architecture

## Vista general real

El plugin arranca desde `analysis-financiero-plugin.php`, registra un autoloader simple y levanta `ASDLabs\\Finance\\Core\\Plugin::boot()`.

Los modulos cargados hoy son:

- `SchemaInstaller`
- `Legacy\\AnalysisModule`
- `Admin\\Menu`
- `Admin\\Assets`
- `Admin\\CrudController`
- `Api\\Routes`
- `Integrations\\Woo\\Module`

## Arquitectura por capas

### 1. Capa core

Archivos clave:

- `src/Core/Plugin.php`
- `src/Core/Schema.php`
- `src/Core/Tables.php`
- `src/Core/SchemaInstaller.php`

Responsabilidades:

- versionado de esquema
- creacion de tablas propias
- bootstrap de modulos

### 2. Capa admin

Archivos clave:

- `src/Admin/Menu.php`
- `src/Admin/Assets.php`
- `src/Admin/CrudController.php`

Responsabilidades:

- menu y pantallas del backoffice
- formularios y acciones `admin_post`
- bloqueo de formularios en contexto fiscal historico
- receipts/print views

### 3. Capa de dominio financiero

Archivos clave dentro de `src/Finance/`:

- `AccountsRepository`
- `ContactsRepository`
- `DocumentsRepository`
- `PaymentsRepository`
- `PaymentAllocationService`
- `InstallmentPlansRepository`
- `InstallmentsRepository`
- `EmployeeProfilesRepository`
- `EmployeeAdvancesRepository`
- `PayrollPeriodsRepository`
- `CommitmentSettlementService`
- `ContactOverviewService`
- `OverviewService`
- `PendingCollectionsService`
- `PayrollQueueService`
- `PaymentMethodsService`
- `FiscalYearService`
- `ReceiptService`
- `SourceLinksRepository`
- `IntegrationStatusService`

Responsabilidades:

- persistencia
- reglas de dominio
- saldos
- colas operativas
- nomina y compromisos
- branding y recibos

### 4. Capa API

Archivo clave:

- `src/Api/Routes.php`

Responsabilidades:

- exponer recursos REST internos
- disparar operaciones del dominio
- registrar eventos/hook de negocio

### 5. Capa de integracion

Archivos clave:

- `src/Integrations/Woo/Module.php`
- `src/Integrations/Woo/OrderSyncService.php`
- `src/Integrations/Woo/ProfileOrderSettlementService.php`

Responsabilidades:

- leer pedidos Woo/OpenPOS
- sincronizar pedidos gestionados con el core
- aplicar abonos o saldo a favor sobre pedidos

### 6. Capa legacy

Archivos clave:

- `src/Legacy/AnalysisModule.php`
- `includes/class-afp-reports.php`
- `includes/class-afp-ajax.php`
- `includes/class-afp-settings.php`

Responsabilidades:

- mantener reportes legacy
- no es la base recomendable para movil

## Tablas reales

Confirmadas en `src/Core/Schema.php`:

- `asdl_fin_accounts`
- `asdl_fin_contacts`
- `asdl_fin_documents`
- `asdl_fin_document_files`
- `asdl_fin_source_links`
- `asdl_fin_payments`
- `asdl_fin_payment_allocations`
- `asdl_fin_employee_profiles`
- `asdl_fin_employee_advances`
- `asdl_fin_payroll_periods`
- `asdl_fin_installment_plans`
- `asdl_fin_installments`
- `asdl_fin_rules`
- `asdl_fin_events`

## Hooks y eventos relevantes

Confirmados en `Routes.php` y `CrudController.php`:

- `asdl_fin_account_created`
- `asdl_fin_contact_created`
- `asdl_fin_profile_linked`
- `asdl_fin_profile_promoted`
- `asdl_fin_document_created`
- `asdl_fin_document_updated`
- `asdl_fin_payment_recorded`
- `asdl_fin_payment_allocated`
- `asdl_fin_profile_payment_applied`
- `asdl_fin_profile_credit_applied`
- `asdl_fin_employee_profile_saved`
- `asdl_fin_salary_advance_saved`
- `asdl_fin_payroll_period_created`
- `asdl_fin_payroll_period_paid`
- `asdl_fin_installment_plan_created`
- `asdl_fin_commitment_payment_applied`
- `asdl_fin_rule_created`
- `asdl_fin_sync_completed`

## Metadatos relevantes

### `contacts`

- `profile_origin`
- `wp_user_id`
- `is_customer`
- `is_employee`
- `is_supplier`

### `documents`

- `document_type`
- `source_type`
- `financial_intent`
- `balance_nature`
- `payment_status`
- `manual_override`
- `meta_json` con traza de clasificacion

### `source_links`

- `provider`
- `object_type`
- `external_id`
- `override_locked`
- `sync_hash`
- `last_synced_at`

### `employee_profiles.meta_json`

- `birth_date`
- `hire_date`
- `contract_type`
- `contract_start_date`
- `contract_end_date`
- `contract_attachment_id`
- `termination_type`
- `termination_date`
- `termination_reason`

### `installment_plans.meta_json`

- `commitment_origin`
- `settlement_direction`
- `collection_mode`
- `target_installment_amount`

## Endpoints y puntos de sincronizacion relevantes

### REST

Todos los endpoints viven bajo `/wp-json/asdl-fin/v1`.

Los mas importantes para movil hoy son:

- `GET /me`
- `GET /me/permissions`
- `GET /health`
- `GET /dashboard`
- `GET /payment-methods`
- `GET /fiscal-years`
- `GET /contacts`
- `GET /contacts/{id}`
- `GET /contacts/{id}/pending-orders`
- `GET/POST /contacts/{id}/commitments`
- `POST /contacts/{id}/settle-orders`
- `POST /contacts/{id}/apply-credit`
- `GET/POST /contacts/{id}/employee-profile`
- `GET/POST /contacts/{id}/salary-advances`
- `GET /salary-advances/{id}`
- `GET/POST /contacts/{id}/payroll-periods`
- `GET /payroll-periods/{id}`
- `POST /payroll-periods/{id}/mark-paid`
- `GET /payroll/queue`
- `GET /documents`
- `GET/POST /documents/{id}/files`
- `GET /payments`
- `GET /payments/{id}`
- `GET /receipts/{type}/{id}`
- `GET /installment-plans`
- `GET /installment-plans/{id}`
- `POST /installment-plans/{id}/apply-payment`

### Sincronizacion real

- Woo/OpenPOS se leen con `OrderSyncService::list_orders()`
- un pedido entra al core cuando se sincroniza o cuando un flujo financiero lo toca
- `ProfileOrderSettlementService` hace abonos por antiguedad o compensacion de saldo
- `source_links` guarda la relacion entre documento financiero y origen operativo

## Dependencias y acoplamientos importantes

- acoplamiento fuerte con WooCommerce
- OpenPOS depende de deteccion por meta/clase sobre pedidos Woo
- autenticacion actual depende del sistema REST de WordPress
- permisos REST ya combinan capacidades propias del plugin con fallback de administracion WordPress
- archivos dependen de Media Library
- reportes legacy siguen fuera del dominio nuevo
