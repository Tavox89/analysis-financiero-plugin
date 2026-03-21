# Domain Model

## Entidades principales del MVP

- `Cuenta`: fondos, cajas, centros operativos o cuentas de control.
- `Perfil`: extension financiera enlazada a un `wp_user` cuando el actor es cliente o empleado.
- `Perfil laboral`: configuracion base del empleado con sueldo, frecuencia y proxima fecha de pago.
- `Adelanto de sueldo`: anticipo descontable asociado al empleado, con saldo pendiente para futura nomina.
- `Periodo de nomina`: semana, quincena o mes a pagar con bruto, descuentos, neto y pago procesado.
- `Contacto externo`: proveedor, servicio externo o tercero sin usuario de WordPress.
- `Documento`: venta, gasto, servicio, sueldo, ajuste o prestamo.
- `Pago`: cobro, pago o abono.
- `Aplicacion de pago`: relacion entre un pago y uno o varios documentos.
- `Plan de cuotas`: acuerdo estructurado con monto, frecuencia y saldo.
- `Cuota`: vencimiento individual dentro de un plan.
- `Vinculo externo`: relacion entre un documento y su origen operativo.
- `Regla`: clasificacion automatica y overrides.
- `Evento`: bitacora interna del core.

## Base futura ya contemplada

- `Orden de compra`: modulo propio para compras y recepciones futuras.
- `Mercancia por recibir`: panel operativo de entradas pendientes y recepciones parciales.

## Jerarquia de clasificacion MVP

1. override manual persistente
2. regla por origen o documento
3. regla por perfil
4. regla por cuenta
5. regla por categoria
6. fallback por tipo de movimiento

## Estados base

- Documento: `draft`, `posted`, `void`
- Pago: `pending`, `partial`, `paid`, `overdue`
- Naturaleza: `receivable`, `payable`, `neutral`
- Intencion: `income`, `expense`, `salary`, `service`, `adjustment`, `loan`, `internal_consumption`

## Regla principal

El documento financiero es el centro del modelo.
El origen operativo sigue existiendo, pero el core financiero lo normaliza.

## Lenguaje visual

En interfaz administrativa se usa la palabra `Movimiento`.
Internamente el modelo sigue usando `Documento` como entidad tecnica central.
