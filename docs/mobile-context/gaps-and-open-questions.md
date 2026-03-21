# Gaps And Open Questions

## Vacios reales detectados

### 1. Autenticacion movil

Ya existe base oficial:

- `Application Passwords` como criterio de MVP
- `GET /me`
- `GET /me/permissions`
- capacidades propias del plugin

Decision pendiente:

- dejar `Application Passwords` como solucion suficiente para el MVP
- o construir auth propia mas adelante
- mantener documentado y operado el provisionamiento, rotacion y revocacion para staff

### 2. Permisos

La API ya no depende solo de capacidades admin.

Ahora existe una base de capacidades del plugin, pero aun falta:

- mapearlas a roles internos reales
- decidir cuales recibiran acceso movil
- revisar si algunos endpoints necesitan permisos aun mas finos

### 3. Contrato REST incompleto

Faltan:

- CRUD completo
- paginacion consistente
- filtros consistentes
- contratos de error
- catalogos

### 4. Contexto fiscal por API

La base principal ya consume ejercicio fiscal, pero faltan endpoints secundarios por alinear con el mismo criterio.

### 5. Files y receipts

Ya existe contrato REST base para:

- contrato firmado
- comprobantes
- receipts imprimibles

Limite actual:

- el upload binario sigue apoyandose en la API media nativa de WordPress
- no toda la API secundaria usa el mismo envelope ni el mismo nivel de detalle

## Contradicciones entre docs y codigo

### API docs

- `docs/api-overview.md` describe intencion y rutas, pero no contrato formal
- `docs/api-overview.md` ya lista auth base y rutas clave, pero sigue sin documentar payloads, errores ni respuestas con detalle

### Vision/Menu

- `Compras` y `Mercancia por recibir` aparecen en docs y menu, pero hoy no son modulos funcionales del core

### Dominio vs API

- el dominio ya soporta credito, nomina y compromisos con bastante profundidad
- la API ya expone catalogos base y receipts/archivos, pero todavia no cubre todo el dominio secundario con el mismo nivel de contrato

## Carencias del backend para soportar movil

- falta documentar el flujo operativo de auth staff
- faltan endpoints secundarios con contrato mas uniforme
- no hay versionado real del contrato mas alla de `v1`

## Riesgos tecnicos

### 1. App acoplada a respuestas administrativas

`GET /contacts/{id}` devuelve mucho contenido mezclado. Sirve para arrancar, pero a largo plazo puede hacer la app fragil.

### 2. Escrituras demasiado abiertas

`POST /documents`, `POST /payments`, `POST /installment-plans` exponen mas complejidad de la necesaria para movil.

### 3. Permisos demasiado altos

La base ya no esta amarrada solo a administradores, pero aun falta traducir capacidades a roles internos reales de tienda.

### 4. Dashboard y secundarios aun desiguales

El bloque principal ya esta endurecido y parte de las rutas secundarias ya se alineo al contrato movil, pero todavia quedan diferencias en errores, filtros y consistencia total del envelope.

### 5. Upload binario indirecto

Contratos y comprobantes ya pueden vincularse por API del plugin, pero el archivo se sigue subiendo por la API media nativa de WordPress.

## Preguntas abiertas que conviene resolver antes de la app

1. La app movil sera solo para admin/gerencia al inicio o tambien para cajeros y cobradores?
2. El MVP movil seguira usando solo `Application Passwords` o luego necesitara token propio?
3. La app seguira usando la API media nativa para archivos o conviene encapsular ese paso?
4. Los movimientos moviles seran solo de consulta o tambien alta simple?
5. Nomina entra en el MVP movil o se deja fuera de la primera version?
