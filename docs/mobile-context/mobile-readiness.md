# Mobile Readiness

## Veredicto general

El backend actual ya soporta un MVP movil interno util y el bloque principal del contrato ya esta bastante encaminado. Ya tiene receipts, archivos base, contexto fiscal util para mobile, una parte importante de los endpoints secundarios alineada al contrato movil y una primera capa post-MVP para compromisos, adelantos, pagos y nomina.

La base funcional existe.
La base de API existe.
La experiencia movil segura y mantenible aun necesita ajustes.

## Lo que ya esta listo para consumir desde movil

### Lectura

- `me`
- `me/permissions`
- `health`
- `integrations/status`
- `receipts/{type}/{id}`
- lista de perfiles
- detalle de perfil con resumen financiero
- pedidos pendientes por perfil en endpoint dedicado
- lista de movimientos
- detalle de movimiento
- archivos vinculados a documentos
- lista de pagos
- lista de compromisos
- empleado:
  - detalle de perfil laboral
  - adelantos
  - periodos de nomina

### Escritura con valor real

- crear perfil externo
- crear cuenta
- crear movimiento
- actualizar movimiento puntual
- crear pago
- crear asignacion de pago
- crear compromiso
- aplicar pago a compromiso
- registrar adelanto
- crear periodo de nomina
- marcar periodo de nomina como pagado
- aplicar abono a pedidos de un perfil
- aplicar saldo a favor a pedidos de un perfil

## Lo que requiere ajustes menores

- varias listas secundarias aun necesitan filtros utiles
- falta terminar de llevar el mismo envelope/filtros/errores a toda la API
- falta seguir endureciendo el uso operativo de `Application Passwords`

## Lo que requiere refactor o contrato mas claro

- terminar de aterrizar permisos granulares a roles internos reales
- paginacion formal en endpoints que aun no pasaron por endurecimiento
- contrato de errores
- upload binario mas guiado si la app no quiere depender de la API media nativa

## Lo que no conviene incluir aun

- compras
- mercancia por recibir
- automatizaciones/reglas como modulo de uso movil inicial
- reportes legacy
- adjuntos complejos si no se cierra primero el contrato de archivos

## Evaluacion por area

### Dashboard

Util para movil. La base ya acepta ejercicio fiscal y rango y ya expone envelope `data/meta`.

### Perfiles

Es la mejor base actual para la app. El perfil ya concentra:

- saldos
- pedidos
- consumo
- movimientos
- compromisos
- pagos
- historial

### Cobranza desde perfil

Viable hoy.

Es una de las mejores piezas del backend para un MVP movil porque:

- tiene flujo de negocio claro
- usa endpoints directos
- no obliga a replicar todo el admin

### Movimientos

Viable como lectura y alta parcial.

Para movil no conviene exponer de entrada toda la complejidad del formulario administrativo.

### Compromisos

Viable como lectura en MVP y ya con escritura post-MVP desde el contexto del perfil.

La escritura ya es mas razonable ahora porque existe `GET/POST /contacts/{id}/commitments` y detalle dedicado.

### Nomina

La lectura es viable y ya existe cola general por API.

La escritura existe, pero en movil la dejaria fuera del MVP inicial salvo para personal muy especifico.

## Conclusion de readiness

Si se limita a staff autorizado y a un alcance realista, el plugin ya puede servir como backend de un MVP movil.

No esta listo aun para:

- muchos roles internos distintos
- uso intensivo fuera del admin
- una app con contrato REST maduro y estable en largo plazo
