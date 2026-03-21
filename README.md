# Finanzas ASD

Suite financiera modular de ASD Labs para WordPress y WooCommerce.

## Estado actual

El plugin ya incluye base funcional de:

- dashboard financiero
- movimientos
- perfiles y terceros
- cuentas
- cobros y pagos
- compromisos
- nomina general
- automatizaciones avanzadas opcionales
- sincronizacion WooCommerce / OpenPOS
- reportes legacy como referencia funcional
- API REST propia
- centro de perfil con pedidos Woo/OpenPOS y consumo por rango
- perfil administrativo cargado por bloques runtime (`header`, tarjetas, resumen de pedidos, operativa, estado de cuenta, pagos, historial y nomina)
- gestion bajo demanda de pedidos Woo sin duplicarlos operativamente
- abono por antiguedad sobre pedidos pendientes desde el perfil
- abonos a pedidos con preview obligatoria y runner por lotes para casos pesados o con precio dual
- compensacion interna de saldo a favor contra pedidos pendientes del perfil
- servicios puntuales y recurrentes ya gestionados desde `Servicios`, separados del modulo general de `Movimientos`
- servicios integrados tambien dentro del perfil de proveedor o tercero para operar cuentas por pagar sin salir del contexto
- configuracion laboral de empleado con sueldo base, frecuencia y proximo pago
- adelantos de sueldo con saldo pendiente y descuento previsto para futura nomina
- nomina por periodo con pago procesado y compensacion automatica de adelantos activos
- compromisos por perfil con abonos manuales y descuentos por sueldo
- comprobantes imprimibles para pagos, adelantos, compromisos y nomina
- anulacion operativa conservadora de pagos, adelantos, documentos y compromisos bajo reglas de proteccion
- logo configurable en comprobantes con fallback al logo o icono del sitio
- sueldos gestionados desde `Perfiles y terceros`, no como alta manual independiente

## Concepto base

En interfaz administrativa se usa la palabra `Movimiento`.
Internamente el modelo sigue usando `Documento` como entidad tecnica central.

## Modulos principales

- `Dashboard`
- `Movimientos`
- `Perfiles y terceros`
- `Servicios`
- `Cuentas`
- `Compras`
- `Mercancia por recibir`
- `Cobros y pagos`
- `Compromisos`
- `Nomina`
- `Automatizaciones`
- `Reportes`
- `Integraciones`
- `API y documentacion`
- `Configuracion`

## Integraciones activas en esta etapa

- WooCommerce
- OpenPOS
- API propia

## Base futura ya contemplada

- gestor propio de ordenes de compra
- panel de mercancia por recibir

## Documentacion interna

- `docs/vision.md`
- `docs/domain-model.md`
- `docs/menu-and-ux.md`
- `docs/api-overview.md`
- `docs/sync-architecture.md`
- `docs/first-install-checklist.md`
- `docs/mobile-context/summary.md`
- `docs/mobile-context/api-contract.md`
- `docs/mobile-context/mobile-readiness.md`
- `docs/mobile-context/mobile-mvp.md`
- `docs/mobile-context/handoff-brief.md`
- `docs/mobile-context/backend-api-phases.md`
- `docs/mobile-context/staff-auth-provisioning.md`

## Primera prueba recomendada

Seguir:

- `docs/first-install-checklist.md`

## Backend movil

La base oficial para preparar el backend antes de abrir el repo de la app movil esta en:

- `docs/mobile-context/backend-api-phases.md`
- `docs/mobile-context/api-contract.md`
- `docs/mobile-context/handoff-brief.md`
- `docs/mobile-context/staff-auth-provisioning.md`

La regla actual es simple:

- primero se endurece el backend/API
- luego se abre y estructura el frontend movil en otro repo

Estado actual del carril movil:

- fases 1 a 6 del backend movil ya cubiertas
- el siguiente trabajo ya es handoff, hardening y continuidad documental

La base de acceso del MVP movil queda asi:

- identidad: `wp_user`
- auth: `Application Passwords` de WordPress
- permisos: capacidades propias del plugin + capacidades WordPress
- gate base: `asdl_fin_access_mobile`
- contrato base: `GET /me`, `GET /me/permissions`, `GET /payment-methods`, `GET /currencies`, `GET /fiscal-years`, `GET /contacts`, `GET /contacts/{id}`, `GET /contacts/{id}/pending-orders`, `GET /documents`, `GET /documents/{id}/files`, `GET /payments`, `GET /receipts/{type}/{id}`, `POST /contacts/{id}/settle-orders`, `POST /contacts/{id}/apply-credit`, `POST /documents/{id}/files`
- capa post-MVP ya expuesta: `GET/POST /contacts/{id}/commitments`, `GET /installment-plans/{id}`, `POST /installment-plans/{id}/cancel`, `GET /salary-advances/{id}`, `POST /salary-advances/{id}/cancel`, `GET /payroll-periods/{id}`, `GET /payroll/queue`, `GET /payments/{id}`, `POST /payments/{id}/cancel`, `POST /documents/{id}/cancel`

## Secuencia obligatoria de continuidad

Si una sesion nueva retoma este proyecto, debe leer y verificar en este orden:

1. `README.md`
2. `docs/mobile-context/handoff-brief.md`
3. `docs/mobile-context/backend-api-phases.md`
4. `docs/mobile-context/api-contract.md`
5. `docs/mobile-context/gaps-and-open-questions.md`
6. `docs/first-install-checklist.md`
7. version visible en `analysis-financiero-plugin.php`
8. version de esquema en `src/Core/Schema.php`

## Regla de cumplimiento

Si se modifica cualquiera de estas areas:

- endpoints
- payloads
- respuestas
- permisos
- auth
- tablas
- ejercicio fiscal
- receipts
- archivos
- flujo de cobranza/compromisos/nomina

entonces tambien hay que actualizar, segun aplique:

- `docs/api-overview.md`
- `docs/mobile-context/api-contract.md`
- `docs/mobile-context/mobile-mvp.md`
- `docs/mobile-context/handoff-brief.md`
- `docs/mobile-context/staff-auth-provisioning.md`

## Regla de versionado

- subir `ASDL_FINANCE_VERSION` cuando cambie funcionalidad visible o el comportamiento del plugin
- subir `Schema::VERSION` solo cuando cambien tablas o migraciones

## Nota tecnica del refactor admin

- el `dashboard` y las listas admin cargan con `shell + runtime blocks`
- el perfil ya no depende del bloque monolitico `contact-full` como ruta principal
- el flujo normal de abono a pedidos del perfil usa preview + runner AJAX; `admin-post` queda como compatibilidad legada
