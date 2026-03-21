# Domain Map

## Entidades reales

### Perfil

Tabla: `contacts`

Representa la extension financiera de una persona o tercero.

Puede ser:

- enlazado a `wp_user`
- externo

Puede tener varios roles simultaneos:

- cliente
- empleado
- proveedor

### Documento

Tabla: `documents`

Es la entidad tecnica central del core financiero.

Se usa para:

- venta gestionada
- gasto
- servicio
- sueldo
- ajuste
- deuda de la empresa con el perfil
- otros movimientos manuales

En UI normalmente se habla de `Movimiento`.

### Pago

Tabla: `payments`

Representa:

- cobro
- pago
- ajuste

No se aplica solo; necesita asignaciones o flujos especiales.

### Asignacion de pago

Tabla: `payment_allocations`

Une un pago con un documento y actualiza:

- `available_amount` del pago
- `paid_total`
- `balance`
- `payment_status` del documento

### Compromiso

Tabla: `installment_plans`

Es un acuerdo estructurado con saldo, frecuencia y cuotas.

Puede ser:

- por cobrar
- por pagar

Puede cobrarse o pagarse de forma:

- manual
- descuento por nomina
- pago por nomina
- mixto

### Cuota

Tabla: `installments`

Representa cada vencimiento de un compromiso.

### Perfil laboral

Tabla: `employee_profiles`

Extiende al perfil cuando este es empleado.

Incluye:

- sueldo base
- frecuencia
- proximo pago
- estado laboral
- contrato

### Adelanto de sueldo

Tabla: `employee_advances`

Es un anticipo al empleado. Al crearse, genera un `payment` real de tipo `disbursement`.

### Periodo de nomina

Tabla: `payroll_periods`

Representa una corrida de pago:

- bruto
- descuentos
- neto
- metodo de pago
- pago procesado o pendiente

### Cuenta

Tabla: `accounts`

Representa caja, fondo o cuenta operativa.

### Vinculo externo

Tabla: `source_links`

Conecta un documento financiero con un origen externo, hoy principalmente pedidos Woo/OpenPOS.

### Regla

Tabla: `rules`

Clasificacion automatica configurable.

### Evento

Tabla: `events`

Bitacora de acciones del core.

## Relaciones reales

- un `Perfil` puede tener muchos `Documentos`
- un `Perfil` puede tener muchos `Pagos`
- un `Perfil` puede tener muchos `Compromisos`
- un `Perfil` empleado puede tener un `Perfil laboral`
- un `Perfil` empleado puede tener muchos `Adelantos`
- un `Perfil` empleado puede tener muchos `Periodos de nomina`
- un `Pago` puede asignarse a muchos `Documentos`
- un `Compromiso` puede tener muchas `Cuotas`
- un `Documento` puede tener cero o mas `Vinculos externos`

## Estados reales

### Documentos

- `financial_status`: `draft`, `posted`, `void`
- `payment_status`: `pending`, `partial`, `paid`, `overdue`
- `balance_nature`: `receivable`, `payable`, `neutral`

### Pagos

- `payment_type`: `collection`, `disbursement`, `adjustment`
- `status`: normalmente `posted`

### Compromisos

- `status`: `active`, `paused`, `closed`, `inactive`
- `settlement_direction`: `receivable`, `payable`
- `collection_mode`: `manual`, `payroll_deduction`, `payroll_disbursement`, `mixed`

### Empleados

- `employment_status`: `active`, `paused`, `ended`
- contrato derivado via meta:
  - activo
  - por renovar
  - vencido
  - finalizado

### Adelantos

- `status`: `active`, `partial`, `settled`
- `recovery_mode`: `next_payroll`, `manual`

### Nomina

- `status`: `planned`, `paid`

## Flujos de negocio principales

### 1. Pedido Woo/OpenPOS pendiente

1. El pedido existe en Woo/OpenPOS.
2. Se lee desde `OrderSyncService`.
3. Si no hay gestion financiera, sigue solo como pedido operativo.
4. Si recibe abono o tratamiento financiero, se sincroniza al core y nace un documento.
5. El documento se enlaza con `source_links`.

### 2. Abono desde perfil

1. Se registra un `payment` de tipo `collection`.
2. Se sincronizan los pedidos abiertos del perfil.
3. Se asigna por antiguedad al documento enlazado de cada pedido.
4. Si un pedido queda en cero, Woo puede pasar a completado.

### 3. Saldo a favor y compensacion

1. La empresa puede deber dinero al perfil mediante documentos `payable`.
2. Ese saldo aparece como `Saldo a favor`.
3. `apply-credit` puede usar:
   - pagos disponibles
   - documentos por pagar
4. Se crean pagos de compensacion interna cuando hace falta.

### 4. Compromiso

1. Se crea un plan con saldo y cuotas.
2. Puede ser por cobrar o por pagar.
3. Puede tener documento enlazado o vivir solo como acuerdo.
4. Los pagos al compromiso actualizan cuotas y, si aplica, documento enlazado.

### 5. Nomina

1. El empleado necesita perfil laboral valido.
2. Se genera un periodo de nomina.
3. El sistema preview:
   - adelantos elegibles
   - compromisos descontables
   - compromisos por pagar al empleado
4. Al marcar pagado:
   - crea documento de sueldo
   - crea pago real si aplica
   - asigna adelantos
   - aplica compromisos
   - actualiza proximo pago

## Como se modelan ingresos, gastos, pagos, perfiles, cuentas y reportes

- ingresos y egresos: `documents` con clasificacion
- pagos y cobros: `payments`
- aplicacion del dinero: `payment_allocations`
- perfiles: `contacts`
- cuentas: `accounts`
- reportes modernos: aun parciales en core
- reportes legacy: viven en `includes/` y `Legacy\\AnalysisModule`

## Limitaciones actuales del modelo

- el pedido operativo sigue siendo Woo y la API movil no expone un contrato completo para pedidos
- los catalogos importantes aun no tienen API propia
- el contexto fiscal esta mas maduro en admin que en REST
- recibos y adjuntos existen, pero no como contrato REST movil
- varias operaciones importantes existen, pero la API no tiene CRUD completo ni permisos granulares

