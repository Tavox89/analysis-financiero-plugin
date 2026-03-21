# Screens And Flows

## Navegacion general recomendada

1. Login
2. Dashboard
3. Perfiles y terceros
4. Detalle de perfil
5. Pendientes / cobrar
6. Movimientos
7. Pagos
8. Ajustes de sesion

## Pantallas recomendadas

### 1. Login

Tipo:

- escritura

Backend necesario:

- auth WordPress para app staff

Estado real:

- no existe endpoint propio de login dentro del plugin
- el acceso del MVP usa `Application Passwords` de WordPress
- la validacion inicial recomendada es `GET /me`

### 2. Dashboard

Tipo:

- lectura

Entidad principal:

- resumen operativo

Endpoint actual:

- `GET /dashboard`

Notas:

- necesita rango/ejercicio fiscal para quedar bien en movil

### 3. Lista de perfiles y terceros

Tipo:

- lectura

Entidad principal:

- `contacts`

Endpoint actual:

- `GET /contacts?search=&limit=`

### 4. Detalle de perfil

Tipo:

- lectura central

Entidad principal:

- `contact`

Endpoint actual:

- `GET /contacts/{id}`

Informacion util:

- resumen
- pedidos
- consumo
- movimientos
- pagos
- compromisos
- historial

Lectura operativa complementaria:

- `GET /contacts/{id}/pending-orders`

### 5. Cobrar pedidos del perfil

Tipo:

- escritura

Endpoint actual:

- `POST /contacts/{id}/settle-orders`

Flujo:

1. abrir perfil
2. ver pedidos pendientes
3. ingresar monto, metodo, fecha, referencia
4. confirmar
5. ver resultado con pedidos cerrados o parciales

### 6. Aplicar saldo a favor

Tipo:

- escritura

Endpoint actual:

- `POST /contacts/{id}/apply-credit`

Flujo:

1. abrir perfil
2. ver saldo a favor utilizable
3. ingresar monto
4. confirmar
5. ver compensacion y pedidos impactados

### 7. Lista de movimientos

Tipo:

- lectura

Endpoint actual:

- `GET /documents`

### 8. Detalle de movimiento

Tipo:

- lectura

Endpoint actual:

- `GET /documents/{id}`

Soporte complementario:

- `GET /documents/{id}/files`

### 9. Pagos recientes o lista de pagos

Tipo:

- lectura

Endpoint actual:

- `GET /payments`

Detalle recomendado:

- `GET /payments/{id}`

### 10. Receipts

Tipo:

- lectura / soporte operativo

Endpoint actual:

- `GET /receipts/{type}/{id}`

### 11. Compromisos del perfil

Tipo:

- lectura y escritura post-MVP

Endpoint actual:

- `GET /contacts/{id}/commitments`
- `POST /contacts/{id}/commitments`
- `GET /installment-plans/{id}`
- `POST /installment-plans/{id}/apply-payment`

### 12. Empleado / nomina

Tipo:

- lectura clara en MVP y operacion parcial post-MVP

Endpoints actuales:

- `GET /contacts/{id}/employee-profile`
- `GET /contacts/{id}/salary-advances`
- `GET /salary-advances/{id}`
- `GET /contacts/{id}/payroll-periods`
- `GET /payroll-periods/{id}`
- `GET /payroll/queue`

### 13. Cola de nomina

Tipo:

- lectura operativa post-MVP

Endpoint actual:

- `GET /payroll/queue`

## Relacion entre pantallas, entidades y endpoints

| Pantalla | Entidad | Endpoint principal | Tipo |
| --- | --- | --- | --- |
| Dashboard | resumen | `GET /dashboard` | lectura |
| Perfiles y terceros | contact | `GET /contacts` | lectura |
| Detalle perfil | contact + agregados | `GET /contacts/{id}` | lectura |
| Cobrar perfil | profile orders | `POST /contacts/{id}/settle-orders` | escritura |
| Aplicar saldo | credit/order settlement | `POST /contacts/{id}/apply-credit` | escritura |
| Movimientos | document | `GET /documents` | lectura |
| Detalle movimiento | document | `GET /documents/{id}` | lectura |
| Pagos | payment | `GET /payments` | lectura |
| Detalle pago | payment | `GET /payments/{id}` | lectura |
| Compromisos perfil | installment plan | `GET /contacts/{id}/commitments` | lectura/escritura |
| Empleado | employee profile | `GET /contacts/{id}/employee-profile` | lectura |
| Cola nomina | payroll queue | `GET /payroll/queue` | lectura |

## Flujos principales recomendados

### Flujo 1. Buscar perfil y cobrar

1. Login
2. Dashboard o Perfiles y terceros
3. Buscar perfil
4. Ver detalle
5. Ir a pendientes
6. Registrar abono
7. Confirmar resultado

### Flujo 2. Aplicar saldo a favor

1. Buscar perfil
2. Revisar `Saldo a favor`
3. Aplicar saldo
4. Confirmar pedidos impactados

### Flujo 3. Consultar historial financiero

1. Buscar perfil
2. Abrir detalle
3. Revisar movimientos, pagos y compromisos

### Flujo 4. Ver movimiento especifico

1. Dashboard o lista de movimientos
2. Abrir movimiento
3. Ver saldos, origen y vinculos

## Pantallas solo lectura vs editables

### Solo lectura recomendadas en MVP

- dashboard
- lista de perfiles
- detalle de perfil
- lista de movimientos
- detalle de movimiento
- lista de pagos
- empleado/nomina

### Editables recomendadas en MVP

- registrar abono desde perfil
- aplicar saldo a favor desde perfil

### Editables a dejar fuera de V1

- crear compromiso complejo
- alta de nomina
- alta de adelanto
- asignacion manual generica
