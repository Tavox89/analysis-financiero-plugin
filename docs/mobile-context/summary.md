# Mobile Context Summary

Base auditada y revalidada el 2026-03-19 sobre el plugin `Finanzas ASD`, version visible `2.0.0-alpha94`, con esquema objetivo `2026.03.19-alpha9`.

## Resumen ejecutivo

`Finanzas ASD` es una suite financiera para WordPress/WooCommerce con tablas propias y una capa de sincronizacion selectiva con Woo/OpenPOS. El sistema no duplica el pedido operativo por defecto: el pedido sigue viviendo en Woo/OpenPOS y entra al core financiero solo cuando un flujo lo gestiona de forma financiera.

El backend actual ya tiene un nucleo financiero real para:

- perfiles enlazados a `wp_user` y perfiles externos
- perfiles de proveedor ya clasificables como `servicios`, `productos` o `mixto`
- movimientos/documentos financieros
- cobros, pagos y asignaciones
- catalogos centrales de metodos de pago y monedas
- saldo a favor y compensacion interna
- compromisos por cobrar y por pagar
- empleados, adelantos y nomina
- descuento manual desde nomina con destino explicito y modal de deudas abiertas del empleado
- dashboard administrativo y vistas por perfil
- integracion operativa con Woo/OpenPOS
- una API REST interna usable, con base movil ya expuesta para identidad, permisos, metodos de pago, ejercicio fiscal, listas filtrables de perfiles/movimientos/pagos y cobranza por perfil trazable
- formularios guiados en admin para compromisos, ficha laboral y nomina, con reglas mas cercanas al dominio real

## Proposito del sistema

El plugin busca centralizar la operacion financiera en una sola base:

- que el pedido comercial siga en Woo/OpenPOS
- que la cobranza, pagos, compromisos y nomina vivan en tablas propias
- que la operacion diaria use lenguaje administrativo claro
- que la suite pueda integrarse con otros canales sin perder control financiero

## Modulos reales detectados

Confirmados en `analysis-financiero-plugin.php`, `src/Core/Plugin.php`, `src/Admin/Menu.php` y servicios del core:

- Dashboard
- Movimientos
- Perfiles y terceros
- Servicios
- Cuentas
- Cobros y pagos
- Compromisos
- Nomina
- Integraciones
- Automatizaciones
- Reportes legacy
- Configuracion
- API y documentacion
- Compras y Mercancia por recibir solo como base visual, no como modulo funcional

## Que ya funciona hoy

Confirmado en codigo y checklist:

- perfiles internos y externos
- promocion de perfil externo a usuario WP
- lectura de pedidos Woo/OpenPOS por perfil
- abono a pedidos por antiguedad desde perfil
- aplicacion de saldo a favor a pedidos abiertos
- registro de movimientos financieros manuales
- registro de pagos y asignaciones
- compromisos con cuotas
- compromisos por cobrar y por pagar
- adelantos de sueldo
- periodos de nomina y cierre de nomina
- ficha laboral con estado visible de `Listo para nomina` o `Falta configurar nomina`
- compromisos guiados con proyeccion automatica y mejor contexto para flujos por nomina
- recibos estructurados e imprimibles en admin y por API
- soportes de movimientos ya consultables por API
- anulacion operativa conservadora de pagos, adelantos, documentos y compromisos bajo reglas de proteccion
- servicios puntuales y recurrentes gestionados desde su modulo propio, ya separados de `Movimientos`
- contexto de ejercicio fiscal en admin

## Que depende de Woo/OpenPOS u otros componentes

- WooCommerce es obligatorio para pedidos, clientes y flujo comercial
- OpenPOS se detecta sobre pedidos Woo via metadatos y clases
- WordPress sigue siendo la fuente de identidad para usuarios internos
- Media Library se usa para contrato firmado y comprobantes
- el modulo legacy de reportes sigue apoyandose en clases de `includes/`

## Fuente de verdad de los datos

La fuente de verdad no es unica; esta separada por dominio:

- pedidos operativos: Woo/OpenPOS
- perfiles financieros: tabla `contacts`
- documentos/movimientos: tabla `documents`
- pagos y asignaciones: tablas `payments` y `payment_allocations`
- compromisos y cuotas: tablas `installment_plans` e `installments`
- empleados/adelantos/nomina: tablas `employee_profiles`, `employee_advances`, `payroll_periods`
- vinculos con origen operativo: tabla `source_links`

En otras palabras:

- la operacion comercial original sigue en Woo/OpenPOS
- la verdad financiera vive en las tablas propias del plugin
