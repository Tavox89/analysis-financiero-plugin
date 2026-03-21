# First Install Checklist

## Objetivo

Validar la primera instalacion funcional del MVP financiero con perfiles, adelantos y nomina por periodo.

## Antes de activar

- confirmar que WooCommerce este activo
- confirmar que OpenPOS este activo solo si vas a probar pedidos POS reales
- tener al menos un pedido Woo de prueba
- tener al menos un pedido OpenPOS de prueba si aplica
- tener un usuario WordPress que sirva como empleado para probar sueldos

## 1. Activacion inicial

- activar el plugin
- revisar en `Finanzas ASD > Dashboard`:
  - el menu lateral debe aparecer completo
  - la version de esquema debe mostrarse sin quedar en `pendiente`
  - el badge debe indicar `MVP operativo`
- abrir `Finanzas ASD > Configuracion`
- revisar `Ejercicio fiscal`
- revisar `Metodos de pago`
- revisar `Comprobantes`
- si hace falta, agregar uno nuevo desde el modal sin salir de la pantalla
- abrir `Finanzas ASD > Compras`
- abrir `Finanzas ASD > Mercancia por recibir`

Resultado esperado:
- no errores fatales
- tablas creadas
- dashboard visible
- el catalogo de metodos debe verse en lista y permitir agregar uno sin escribirlo luego manualmente en cobros, pagos o nomina
- `Comprobantes` debe venir activo por defecto usando el logo del sitio
- debe permitir cambiar a `Sin logo` o seleccionar un `Logo personalizado`
- ambos modulos deben mostrar `Fase de construccion`
- el menu lateral debe incluir `Nomina`

## 1.1 Ejercicio fiscal y modo consulta

- en `Finanzas ASD > Configuracion`, definir:
  - mes de inicio
  - dia de inicio
  - ejercicio activo
- volver al dashboard
- cambiar el selector superior a otro ejercicio fiscal distinto del activo

Resultado esperado:
- el toolbar debe mostrar el ejercicio activo y el ejercicio consultado
- debe aparecer un aviso claro de `Modo consulta historica`
- formularios operativos de movimientos, cobros, compromisos y nomina deben quedar bloqueados
- al volver al ejercicio activo, las acciones deben habilitarse otra vez

## 2. Cuentas y perfiles

- crear una cuenta operativa
- ir a `Finanzas ASD > Perfiles y terceros`
- vincular un usuario WordPress existente como perfil cliente
- crear un contacto externo proveedor
- verificar que ambos aparezcan listados

Resultado esperado:
- registros visibles en sus tablas
- detalle de perfil accesible
- el perfil del usuario debe mostrar origen `Usuario WP`
- el proveedor externo debe quedar como `Externo`
- el detalle del perfil debe mostrar pestañas de `Pedidos` y `Consumo`

## 2.1 Centro de perfil

- abrir el detalle del perfil enlazado al usuario
- revisar la seccion `Pedidos`
- aplicar un rango de fechas
- revisar la seccion `Consumo e interacciones`

Resultado esperado:
- si el usuario tiene pedidos Woo/OpenPOS, deben aparecer listados
- cada pedido debe mostrar origen, estado, total y enlace si ya esta sincronizado al core
- el consumo por periodo debe reflejar el rango aplicado

## 3. Automatizaciones avanzadas (opcional)

- esta prueba se puede saltar en la primera instalacion
- entrar en `Finanzas ASD > Automatizaciones`
- crear una automatizacion de ejemplo:
  - alcance: `Origen / documento`
  - condicion: `Origen especifico = WooCommerce`
  - accion: `Nueva categoria = sales`
  - accion: `Nueva subcategoria = web_sale`
- dejarla activa

Resultado esperado:
- la automatizacion aparece en la tabla
- se puede activar y desactivar
- si no creas ninguna, el plugin debe seguir operando normalmente

## 4. Gasto externo simple

- entrar en `Finanzas ASD > Movimientos`
- crear un `Nuevo gasto externo`
- cargar:
  - concepto
  - fecha
  - monto
  - perfil o contacto externo opcional
  - comprobante adjunto

Resultado esperado:
- el movimiento se registra
- aparece en el listado
- el detalle muestra categoria, naturaleza y trazabilidad de clasificacion
- el comprobante aparece en `Comprobantes y soportes`

## 5. Servicio

- ir a `Finanzas ASD > Servicios`
- crear un `Nuevo servicio`
- crear tambien un `Servicio recurrente`
- revisar la `Cola operativa de servicios`

Resultado esperado:
- el servicio puntual queda registrado desde su propio modulo, no desde `Movimientos`
- el servicio recurrente queda configurado con proxima emision
- al emitir desde la cola, nace un `service_expense` real por pagar
- se refleja en el dashboard y en el perfil si fue vinculado

## 6. Empleado

- ir a `Finanzas ASD > Perfiles y terceros`
- abrir o vincular un usuario WordPress como empleado

Resultado esperado:
- el empleado se gestiona desde `Perfiles y terceros`, no desde `Movimientos`
- debe quedar visible el bloque `Sueldo, adelantos y nomina`

## 6.1 Configuracion laboral del empleado

- abrir un perfil marcado como empleado
- ir a `Sueldo, adelantos y nomina`
- guardar sueldo base, frecuencia y dia de pago

Resultado esperado:
- debe mostrarse el resumen laboral con sueldo base y proximo pago
- la configuracion debe persistir al recargar
- si el perfil no tenia rol empleado, debe mantenerse como empleado despues de guardar

## 6.2 Adelantos de sueldo

- abrir el mismo perfil de empleado
- en `Sueldo, adelantos y nomina`, registrar un adelanto
- indicar monto, fecha y modo de descuento

Resultado esperado:
- el adelanto debe aparecer en la tabla de adelantos del perfil
- debe aumentar el conteo de adelantos activos
- el saldo por descontar debe reflejar el monto registrado
- no debe duplicarse como gasto de nomina automaticamente

## 6.3 Nomina por periodo

- abrir el mismo perfil de empleado
- generar un periodo de nomina
- si el perfil tiene compromisos con `Descuento por sueldo`, revisar tambien ese descuento previsto
- revisar bruto, adelantos descontados, compromisos descontados y neto
- procesar el pago del periodo

Resultado esperado:
- el periodo debe aparecer en la tabla de nomina con estado `Pendiente`
- al procesarlo debe pasar a `Pagado`
- debe crearse el movimiento salarial correspondiente
- debe crearse el pago neto si aplica
- los adelantos con modo `Descontar en proximo pago` deben bajar su saldo o quedar compensados
- los compromisos configurados con `Descuento por sueldo` deben reducir su saldo y reflejar el descuento en la tabla de nomina
- el resumen laboral debe actualizar `Ultimo pago` y `Proximo pago`

## 6.4 Compromisos del perfil

- abrir el perfil del mismo usuario
- entrar en la seccion `Compromisos`
- crear un compromiso simple
- registrar luego un `Abono a compromiso`
- crear otro compromiso con:
  - `Sentido`: `La empresa paga al perfil`
  - `Origen`: `Deuda de la empresa`
  - `Frecuencia`: `Semanal`
  - `Cuota deseada`: `100`
- si el perfil es empleado, generar luego un periodo de nomina para revisar ese pago programado

Resultado esperado:
- el compromiso debe aparecer en la tabla del perfil
- el saldo pendiente debe bajar luego del abono
- si el compromiso esta enlazado a un movimiento, el movimiento tambien debe reflejar el nuevo saldo
- el metodo de pago debe salir del catalogo y no como texto libre
- el compromiso `La empresa paga al perfil` debe aumentar el `Saldo a favor` mientras siga abierto
- si se gestiona por `Pago por nomina`, el periodo de nomina debe reflejarlo como pago adicional, no como descuento

## 7. Cobro o pago + asignacion

- crear un cobro o pago en `Cobros y pagos`
- asignarlo a uno de los movimientos abiertos

Resultado esperado:
- baja el saldo disponible del pago
- baja el saldo del movimiento
- si se cubre completo, el estado debe quedar `Pagado`

## 7.1 Abono desde perfil sobre pedidos Woo

- abrir un perfil con pedidos pendientes
- en `Pedidos pendientes por cobrar`, registrar un abono

Resultado esperado:
- el sistema debe tomar primero los pedidos mas viejos
- los pedidos cubiertos por completo deben pasar a `completed` en Woo
- si el abono alcanza parcialmente el siguiente pedido, ese pedido debe seguir abierto
- si sobra dinero, debe quedar como saldo sin aplicar dentro del pago creado

## 7.1.1 Aplicar saldo a favor

- si el perfil aun no tiene credito:
  - abrir el perfil
  - en `Pedidos pendientes por cobrar`, usar `Registrar saldo a favor`
  - cargar concepto, fecha y monto

Resultado esperado:
- el perfil debe aumentar `Saldo a favor`
- el registro debe volver al mismo perfil, no a `Movimientos`
- el nuevo saldo debe quedar disponible para pago manual, compromiso o compensacion

- abrir un perfil que tenga `Saldo a favor` y pedidos pendientes
- en `Pedidos pendientes por cobrar`, usar `Aplicar saldo a favor`
- indicar el monto a cruzar

Resultado esperado:
- el sistema debe usar primero credito ya disponible y luego credito por documentos si hace falta
- no debe registrarse como entrada de caja normal
- el saldo a favor del perfil debe bajar por el monto aplicado
- los pedidos cubiertos por completo deben pasar a `completed` en Woo
- si el cruce no cubre todo, el siguiente pedido debe seguir pendiente con saldo parcial
- el aviso final debe indicar cuanto se aplico y cuantos pedidos quedaron cerrados o parciales

## 7.2 Dashboard general de pendientes

- abrir `Finanzas ASD > Dashboard`
- revisar la seccion `Pendientes por cobrar`
- si aparece una persona sin perfil, usar la accion rapida para crear o enlazar perfil

Resultado esperado:
- los pedidos pendientes deben aparecer agrupados por persona o perfil
- debe mostrarse el total pendiente y la fecha mas antigua
- si creas o enlazas el perfil desde esa vista, debe abrir el perfil normal para seguir gestionando

## 7.3 Nomina general

- abrir `Finanzas ASD > Nomina`
- revisar la cola de empleados en el rango por defecto
- si un empleado no tiene periodo generado, usar `Generar periodo`
- si ya tiene periodo pendiente, usar `Procesar pago`

Resultado esperado:
- la vista debe listar empleados con proximo pago, bruto, descuentos y neto previsto
- debe permitir generar periodo sin entrar al perfil
- debe permitir procesar nomina con metodo, referencia y notas desde la vista general
- si el empleado tiene adelantos o compromisos con descuento por sueldo, esos montos deben reflejarse en la columna de descuentos

## 7.4 Comprobantes imprimibles

- abrir un comprobante desde:
  - `Cobros y pagos`
  - `Perfiles y terceros > Sueldo, adelantos y nomina`
  - `Perfiles y terceros > Compromisos` si ya existe un ultimo pago
- usar `Imprimir / Guardar PDF`

Resultado esperado:
- debe abrir una vista limpia con numero, persona, fecha, cuenta, metodo, referencia y detalle aplicado
- al imprimir debe ocultar el resto del admin
- debe poder guardarse como PDF desde el navegador sin layout roto
- si `Comprobantes` usa logo del sitio o uno personalizado, debe verse arriba del comprobante

## 8. Gestionar pedido Woo bajo demanda

- abrir el detalle de un perfil que tenga pedidos Woo
- en la tabla `Pedidos`, usar `Caso especial` sobre uno que aun no este enlazado
  Nota: esta accion ahora queda para gestion manual o reclasificacion especial de un pedido, no como flujo normal de cobranza

Resultado esperado:
- se crea un movimiento tipo `Venta de tienda`
- se crea `source_link`
- el pedido sigue existiendo en Woo sin duplicarse operativamente
- el movimiento aparece en `Movimientos`
- desde el perfil debe salir el enlace a ese movimiento

## 9. Gestionar pedido OpenPOS bajo demanda

- abrir un perfil con pedido OpenPOS o ir a `Integraciones`
- gestionar un pedido POS que aun no este enlazado

Resultado esperado:
- el origen del movimiento debe verse como `OpenPOS`
- el vinculo externo debe reflejar proveedor `OpenPOS`

## 9.1 Backfill por lote

- ir a `Integraciones`
- usar `Sincronizacion por lote`

Resultado esperado:
- la herramienta sigue disponible para traer historicos o revisar varios pedidos
- no es obligatoria para el flujo normal de gestion diaria

## 10. Override manual persistente

- abrir el detalle de un movimiento sincronizado
- activar `Gestion manual prioritaria`
- cambiar intencion, categoria o subcategoria
- guardar
- volver a sincronizar el mismo pedido

Resultado esperado:
- la clasificacion manual no debe perderse
- el vinculo externo debe quedar bloqueado para override

## 11. Coherencia Woo

- tomar un pedido sincronizado con saldo pendiente
- intentar moverlo a `completed` desde Woo

Resultado esperado:
- el sistema debe impedirlo o revertirlo

- luego completar el saldo con asignaciones
- volver a intentar o dejar que el sistema lo complete

Resultado esperado:
- el pedido puede quedar `completed`

## 12. Reportes heredados

- abrir `Finanzas ASD > Reportes`

Resultado esperado:
- la pantalla legacy debe seguir cargando
- no debe romperse el modulo heredado

## 13. Conversion de contacto externo

- abrir el detalle de un contacto externo que tenga correo
- usar `Convertir en usuario`

Resultado esperado:
- si el correo no existe en WordPress, se crea un usuario nuevo
- si el correo ya existe, el perfil se vincula a ese usuario
- el perfil debe pasar a origen `Usuario WP`

## Si algo falla

Revisar primero:

- `Finanzas ASD > Dashboard`
- `Finanzas ASD > Integraciones`
- `Finanzas ASD > API y documentacion`
- endpoint `/wp-json/asdl-fin/v1/health`
