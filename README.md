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
- adopcion de documentos Woo/OpenPOS huerfanos compatibles antes de crear nuevos movimientos sincronizados
- abono por antiguedad sobre pedidos pendientes desde el perfil
- reporte imprimible y CSV editable de deudores desde `Pendientes por cobrar`, usando la cola completa de grupos y no solo la pagina visible
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

- backend canonico movil ya operativo bajo `clubsams-control/v1`
- auth propia por `access_token + refresh_token`
- `public/app-config` ya expuesto para bootstrap por ambiente
- `asdl-fin/v1` sigue como compatibilidad legada

La base oficial de acceso movil ahora queda asi:

- identidad: `wp_user`
- auth canonica: `POST /wp-json/clubsams-control/v1/auth/login`
- continuidad de sesion: `POST /auth/refresh`, `POST /auth/logout`, `GET /auth/me`
- gate base: `asdl_fin_access_mobile`
- permisos: capacidades propias del plugin + capacidades WordPress
- configuracion publica por ambiente: `GET /wp-json/clubsams-control/v1/public/app-config`
- contrato canonico: `clubsams-control/v1`

Documento canonico para backend movil actual:

- `docs/mobile-context/clubsams-control-v1-backend.md`

## Secuencia obligatoria de continuidad

Si una sesion nueva retoma este proyecto, debe leer y verificar en este orden:

1. `README.md`
2. `docs/mobile-context/handoff-brief.md`
3. `docs/mobile-context/backend-api-phases.md`
4. `docs/mobile-context/api-contract.md`
5. `docs/mobile-context/clubsams-control-v1-backend.md`
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
- `docs/mobile-context/handoff-brief.md`
- `docs/mobile-context/staff-auth-provisioning.md`
- `docs/mobile-context/clubsams-control-v1-backend.md`

## Regla de versionado

- subir `ASDL_FINANCE_VERSION` cuando cambie funcionalidad visible o el comportamiento del plugin
- subir `Schema::VERSION` solo cuando cambien tablas o migraciones

## Nota tecnica del refactor admin

- el `dashboard` y las listas admin cargan con `shell + runtime blocks`
- el perfil ya no depende del bloque monolitico `contact-full` como ruta principal
- el flujo normal de abono a pedidos del perfil usa preview + runner AJAX; `admin-post` queda como compatibilidad legada
- el cierre extraordinario por pedido se prepara desde `Pedidos especificos`; exige marcar un solo pedido y puede correr sin metodo cuando no se registrara un pago real nuevo
- si la seleccion especifica cambia y queda exactamente un pedido marcado, la UI recalcula automaticamente la vista; despues de recalcular, el checkbox conserva su estado local y muestra los campos administrativos antes de pedir TOTP
- en `Pedidos especificos`, si el monto recibido excede lo que consumen los pedidos marcados, la vista obliga a resolver el excedente: ajustar el abono al monto exacto, aplicarlo por antiguedad a otras facturas visibles en el preview, o guardarlo como saldo a favor
- el ajuste exacto en precio dual registra el efectivo real requerido y mantiene separado el descuento tecnico; el batch prevalida efectivo, descuento dual y saldo del documento antes de crear allocations
- el saldo a favor usado en abonos excluye remanentes tecnicos como `dual_price_discount`; si el credito viene de un cobro real elegible y el pedido aun no tiene precio dual, participa como parte del neto dual, pero si el pedido ya tiene descuento se aplica como credito normal
- en `Registrar abono`, el saldo a favor disponible se muestra dentro del control, la UI calcula en vivo `dinero nuevo + saldo a favor incluido` y el modal de vista previa conserva ese desglose tambien en `Pedidos especificos`
- si un lote dual termina con errores, los remanentes disponibles del pago principal quedan en cuarentena operativa y el ajuste tecnico dual queda sin disponibilidad para evitar que reaparezcan como saldo a favor automatico
- la aprobacion TOTP es la evidencia operativa fuerte del cierre extraordinario; la referencia manual queda fuera del flujo rapido y no bloquea el calculo del ajuste
- los administradores tambien deben validar TOTP en cierre extraordinario; si el plugin externo responde bypass, este plugin lo fuerza a aprobacion interactiva
- si el operador toca el boton principal o intenta validar TOTP con el cierre extraordinario incompleto, la UI mantiene el modal abierto y enumera motivo, nota administrativa o confirmacion como datos faltantes
- `Por cobrar` principal solo suma pedidos, documentos y prestamos reales; compromisos y adelantos de sueldo quedan como contexto operativo y no inflan la deuda cobrable.
- el reporte de deudores excluye la columna `Planificado`, imprime desde navegador y exporta CSV compatible con Excel para el resultado completo.
- el precio dual de abonos se activa automaticamente en la UI cuando el descuento global esta activo, la moneda es USD y el metodo califica; el operador puede apagarlo manualmente para registrar un abono normal
- la referencia AJAX de precio dual queda atada al metodo y moneda consultados para que una respuesta anterior no apague el descuento automatico al cambiar rapido a un metodo elegible
- la tabla de `Pendientes por cobrar` carga la cola completa fresca para filtrar y paginar en cliente; el reporte de deudores usa el mismo snapshot completo con `skip_cache` para imprimir o guardar PDF
