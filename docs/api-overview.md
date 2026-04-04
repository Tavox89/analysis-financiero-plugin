# API Overview

Revision actual:

- plugin visible: `2.0.7`
- schema vigente: `2026.03.22-alpha12`
- namespace canonico para clientes nuevos: `/wp-json/clubsams-control/v1`
- namespace legado operativo: `/wp-json/asdl-fin/v1`

## Resumen ejecutivo

La API del plugin ya no debe describirse solo como una API administrativa interna.

Hoy existen dos capas:

1. `asdl-fin/v1`
   - namespace historico
   - sigue operativo para compatibilidad
   - mantiene varias rutas administrativas y parte del dominio maduro
2. `clubsams-control/v1`
   - namespace canonico para movil y futura app web administrativa
   - auth propia por sesiones de dispositivo
   - envelope `data/meta/error`
   - `route_groups` y permisos listos para UI por capacidades

## Configuracion publica por ambiente

Nuevo endpoint publico:

- `GET /wp-json/clubsams-control/v1/public/app-config`

Uso:

- la app resuelve branding, namespace, auth paths y feature flags desde este endpoint
- el build solo necesita saber la instancia bootstrap

Campos clave:

- `environmentKey`
- `environmentLabel`
- `apiBaseUrl`
- `apiNamespace`
- `auth.loginPath`
- `auth.refreshPath`
- `auth.logoutPath`
- `auth.mePath`
- `featureFlags.allowEnvironmentOverride`
- `featureFlags.inventoryEnabled`
- `featureFlags.auditEnabled`

## Autenticacion vigente

### Canonica para clientes nuevos

- `POST /wp-json/clubsams-control/v1/auth/login`
- `POST /wp-json/clubsams-control/v1/auth/refresh`
- `POST /wp-json/clubsams-control/v1/auth/logout`
- `GET /wp-json/clubsams-control/v1/auth/me`
- `GET /wp-json/clubsams-control/v1/auth/permissions`

Modelo:

- login con `username + password`
- despues del login el cliente usa `Authorization: Bearer <access_token>`
- refresh token rotado por dispositivo
- invalidacion por cambio de credenciales via `auth_state_hash`
- multiples dispositivos permitidos
- `route_groups` sale del backend y no se inventa en frontend

### Compatibilidad legada

`asdl-fin/v1` puede seguir usando auth REST nativa y flujos previos para clientes viejos o tareas admin. Ya no es el flujo oficial recomendado para la app.

## Regla de acceso movil

Puede entrar si cumple una de estas condiciones:

- rol `administrator`
- rol `shop_manager`
- capability `asdl_fin_access_mobile`

La autorizacion funcional sigue saliendo de `CapabilityManager`.

## Rutas canonicas principales

### Auth y contexto

- `GET /public/app-config`
- `POST /auth/login`
- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/me`
- `GET /auth/permissions`
- `GET /health`

### Dashboard y perfiles

- `GET /dashboard`
- `GET /profiles`
- `GET /profiles/{id}`
- `GET /profiles/{id}/pending-orders`
- `POST /profiles/{id}/collections/preview`
- `POST /profiles/{id}/collections`
- `POST /profiles/{id}/apply-credit`

### Caja y finanzas

- `GET /cash/summary`
- `GET /cash/movements`
- `POST /cash/collections`
- `POST /cash/manual-deliveries`
- `POST /cash/voids`
- `GET /finance/overview`
- `GET /finance/receivables`
- `GET /finance/payables`
- `GET /finance/comparison`

### Integraciones, documentos y auditoria

- `GET /documents`
- `GET /documents/{id}/attachments`
- `GET /payments`
- `GET /integrations/status`
- `GET /audit/events`

### Inventario

- `GET /inventory/summary`
- `GET /inventory/expirations`
- `GET /inventory/incoming`
- `GET /inventory/usd-report`

Nota:

- inventario ya tiene contrato canonico
- mientras el dominio no este completo, puede responder con `meta.available=false`

## Compatibilidad con `asdl-fin/v1`

Rutas maduras siguen delegando internamente donde convenga:

- `/dashboard`
- `/profiles*`
- `/documents`
- `/payments`
- `/integrations/status`

La compatibilidad se resuelve en backend. El cliente nuevo no debe llamar directo a `asdl-fin/v1`.

## Hardening reciente

Punto importante documentado:

- `clubsams-control/v1` ya protege su auth frente a interferencias de otros plugins REST/JWT
- `MobileAuth\Module` toma prioridad temprana y filtra `rest_authentication_errors` para su namespace

Esto es especialmente relevante si existe otro plugin que también consume `Authorization: Bearer`.

## Documentos canonicos a mantener actualizados

Si cambia auth, namespace, payloads o permisos, actualizar minimo:

- `README.md`
- `docs/api-overview.md`
- `docs/mobile-context/clubsams-control-v1-backend.md`
- `docs/mobile-context/staff-auth-provisioning.md`
- `docs/mobile-context/handoff-brief.md`

Los documentos que sigan hablando de `Application Passwords` o de `asdl-fin/v1` como contrato principal deben considerarse historicos hasta que se reescriban.
