# Mobile MVP Proposal

## Objetivo del MVP movil

Construir una app interna para iPhone primero, luego Android, dirigida a:

- administradores
- personal autorizado
- staff de tienda con permisos definidos despues

La app no debe replicar todo el panel admin.
Debe resolver lo operativo mas frecuente y sensible.

## Alcance recomendado de la version 1

### Entra en V1

1. Login con usuario WordPress autorizado
2. Dashboard resumido
3. Lista de perfiles
4. Detalle de perfil
5. Vista de pedidos/pendientes por perfil
6. Registrar abono sobre pedidos desde un perfil
7. Aplicar saldo a favor desde un perfil
8. Lista y detalle de movimientos
9. Lista de pagos registrados
10. Consulta de compromisos del perfil
11. Ver receipts basicos
12. Ver soportes del movimiento

### Opcional en V1 si el backend se ajusta un poco

13. Crear movimiento simple
14. Crear pago simple

### Fuera de V1

- CRUD completo de automatizaciones
- compras
- mercancia por recibir
- reportes legacy
- configuracion avanzada
- nomina operativa completa
- subida de archivos avanzada

## Funcionalidades prioritarias por valor

### Prioridad alta

- autenticacion
- dashboard
- perfiles
- pendientes por cobrar
- abonos
- saldo a favor

### Prioridad media

- movimientos solo lectura
- pagos solo lectura
- compromisos solo lectura

### Prioridad baja para MVP

- alta de compromisos
- alta de adelantos
- alta de nomina

## Pagos y abonos desde perfil/contacto en el MVP

### Decision

Si, es razonable incluirlo.

### Motivo

El backend ya tiene flujos de negocio claros y endpoints especificos:

- `POST /contacts/{id}/settle-orders`
- `POST /contacts/{id}/apply-credit`
- `GET /contacts/{id}/pending-orders`

Eso permite una UI movil mucho mas segura que exponer toda la logica de pagos genericos.

### Restriccion recomendada

En V1 movil no conviene exponer:

- asignacion manual generica de cualquier pago a cualquier documento
- escritura libre de nomina
- compromisos complejos

La app debe enfocarse en:

- cobrar pedidos pendientes
- aplicar saldo a favor
- consultar contexto del perfil
- abrir receipts y soportes cuando existan

## Propuesta concreta de V1

### Modulo 1. Auth y sesion

- login staff
- cierre de sesion
- informacion del usuario actual

### Modulo 2. Dashboard

- pendientes por cobrar
- totales basicos
- accesos rapidos

### Modulo 3. Perfiles

- buscar perfil
- ver saldo
- ver pedidos abiertos
- ver historial corto

### Modulo 4. Cobranza

- registrar abono
- aplicar saldo a favor
- ver resultado de aplicacion

### Modulo 5. Movimientos

- listado resumido
- detalle de movimiento

## Priorizacion valor vs complejidad

| Area | Valor | Complejidad | Decision |
| --- | --- | --- | --- |
| Perfil + cobranza | Muy alta | Media | Entra |
| Dashboard | Alta | Baja | Entra |
| Movimientos lectura | Media | Baja | Entra |
| Compromisos lectura | Media | Media | Puede entrar |
| Pagos genericos escritura | Media | Alta | Mejor no en V1 |
| Nomina escritura | Alta para negocio, pero sensible | Alta | Fase siguiente |

## Recomendacion final de MVP

El primer MVP movil debe ser una app de consulta + cobranza operativa sobre perfiles.

Eso ya aprovecha lo mas maduro del backend actual sin obligar a endurecer de entrada todos los modulos.
