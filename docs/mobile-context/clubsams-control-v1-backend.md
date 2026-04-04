# ClubSams Control V1 Backend

Revision actual:

- plugin visible: `2.0.8`
- schema vigente: `2026.03.22-alpha12`

## Objetivo

Esta capa deja al plugin listo para servir como backend canonico de `staff interno` para:

- app movil iPhone-first
- futura app web administrativa

El namespace objetivo es:

- `/wp-json/clubsams-control/v1`

El namespace legado se mantiene operativo durante migracion:

- `/wp-json/asdl-fin/v1`

## Auth movil dedicado

Se implemento un flujo propio de sesiones por dispositivo dentro del plugin.

### Rutas nuevas

- `GET /clubsams-control/v1/public/app-config`
- `POST /clubsams-control/v1/auth/login`
- `POST /clubsams-control/v1/auth/refresh`
- `POST /clubsams-control/v1/auth/logout`
- `GET /clubsams-control/v1/auth/me`
- `GET /clubsams-control/v1/auth/permissions`

### Modelo

- bootstrap del cliente via `public/app-config`
- login con `username + password normal de WordPress`
- despues del login la app usa el header protegido definido por `public/app-config`
- rotacion de `refresh_token`
- sesiones multiples por usuario permitidas
- invalidacion por cambio de credenciales via `auth_state_hash`
- compatibilidad intacta con `Application Passwords` solo para clientes que sigan en `/asdl-fin/v1`

### Config publica por ambiente

`GET /public/app-config` devuelve:

- `environmentKey`
- `environmentLabel`
- `apiBaseUrl`
- `apiNamespace`
- `auth.loginPath`
- `auth.refreshPath`
- `auth.logoutPath`
- `auth.mePath`
- `auth.accessTokenHeaderName`
- `branding`
- `featureFlags`

### Tabla

- `wp_asdl_fin_mobile_sessions`

Campos principales:

- `user_id`
- `device_name`
- `platform`
- `app_version`
- `access_token_hash`
- `refresh_token_hash`
- `auth_state_hash`
- `access_expires_at`
- `refresh_expires_at`
- `last_used_at`
- `last_ip`
- `user_agent`
- `revoked_at`

## Regla de acceso movil

La autorizacion sigue saliendo de `CapabilityManager`.

Puede entrar si cumple una de estas condiciones:

- rol `administrator`
- rol `shop_manager`
- capability `asdl_fin_access_mobile`

Los permisos por modulo siguen siendo los mismos del plugin financiero. El auth nuevo no crea un sistema paralelo de autorizacion.

## Compatibilidad de rutas

Rutas maduras mapeadas al namespace canonico:

- `GET /auth/me` -> equivalente funcional de `/asdl-fin/v1/me`
- `GET /auth/permissions` -> equivalente funcional de `/asdl-fin/v1/me/permissions`
- `GET /dashboard` -> proxy de `/asdl-fin/v1/dashboard`
- `GET /profiles` -> proxy de `/asdl-fin/v1/contacts`
- `GET /profiles/{id}` -> proxy de `/asdl-fin/v1/contacts/{id}`
- `GET /profiles/{id}/pending-orders` -> proxy de `/asdl-fin/v1/contacts/{id}/pending-orders`
- `POST /profiles/{id}/collections/preview` -> proxy de `/asdl-fin/v1/contacts/{id}/settle-orders/preview`
- `POST /profiles/{id}/collections` -> proxy de `/asdl-fin/v1/contacts/{id}/settle-orders`
- `POST /profiles/{id}/apply-credit` -> proxy de `/asdl-fin/v1/contacts/{id}/apply-credit`
- `GET /documents` -> proxy de `/asdl-fin/v1/documents`
- `GET /documents/{id}/attachments` -> proxy de `/asdl-fin/v1/documents/{id}/files`
- `GET /payments` -> proxy de `/asdl-fin/v1/payments`
- `GET /integrations/status` -> proxy de `/asdl-fin/v1/integrations/status`

## Endpoints nuevos mobile-first

Implementados como contrato limpio en `clubsams-control/v1`:

- `GET /cash/summary`
- `GET /cash/movements`
- `POST /cash/collections`
- `POST /cash/manual-deliveries`
- `POST /cash/voids`
- `GET /finance/overview`
- `GET /finance/receivables`
- `GET /finance/payables`
- `GET /finance/comparison`
- `GET /inventory/summary`
- `GET /inventory/expirations`
- `GET /inventory/incoming`
- `GET /inventory/usd-report`
- `GET /audit/events`

## Estado real del milestone

Listo:

- auth con tokens opacos por dispositivo
- middleware bearer token para `clubsams-control/v1`
- `public/app-config` para bootstrap por ambiente
- namespace canonico nuevo
- proxys de compatibilidad a logica madura existente
- overview financiero, receivables, payables y comparison
- cash summary, movements, collections, manual deliveries y voids
- auditoria REST

Hardening reciente:

- prioridad temprana en `determine_current_user`
- filtro `rest_authentication_errors` para evitar interferencia de otros plugins de auth en `clubsams-control/v1`
- el namespace canonico ya no depende del usuario global autenticado de WordPress para validar la sesion movil

Mock / placeholder controlado:

- inventario sigue sin dominio backend real
- por eso los endpoints de inventario responden contrato valido con `meta.available=false`

## Siguiente paso operativo

El cliente movil ya debe consumir este contrato canonico:

1. `POST /clubsams-control/v1/auth/login`
2. guardar `access_token + refresh_token`
3. usar `X-Clubsams-Access-Token: <access_token>` como header preferido
4. mantener compatibilidad con `Authorization: Bearer ...` cuando el servidor lo permita
4. usar `POST /auth/refresh` cuando expire el access token
5. usar `POST /auth/logout` al cerrar sesion
