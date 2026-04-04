# Staff Auth Provisioning

## Objetivo

Documentar el flujo oficial para dar acceso movil interno al staff usando el backend canonico de `clubsams-control/v1`.

## Decision vigente

La autenticacion oficial para clientes nuevos ya no es `Application Password`.

Flujo actual:

- `wp_user` como identidad unica
- login con `username + password`
- sesion por dispositivo con `access_token + refresh_token`
- permisos y `route_groups` saliendo de `CapabilityManager`

Compatibilidad:

- `Application Passwords` pueden seguir existiendo para clientes o herramientas legadas sobre `asdl-fin/v1`
- no son el flujo canonico de la app movil nueva

## Requisitos minimos para un usuario movil

1. existir como usuario WordPress
2. poder autenticarse con su contrasena normal
3. cumplir una de estas condiciones:
   - rol `administrator`
   - rol `shop_manager`
   - capability `asdl_fin_access_mobile`
4. tener las capacidades funcionales que correspondan a su rol

## Provisionamiento recomendado

1. crear o identificar el `wp_user`
2. asignar rol base o capabilities funcionales
3. validar acceso al admin con usuario y contrasena
4. validar login canonico con:
   - `POST /wp-json/clubsams-control/v1/auth/login`
5. validar identidad y permisos con:
   - `GET /wp-json/clubsams-control/v1/auth/me`
   - `GET /wp-json/clubsams-control/v1/auth/permissions`

## Sesiones por dispositivo

Cada login genera una sesion en:

- `wp_asdl_fin_mobile_sessions`

Campos relevantes:

- `user_id`
- `device_name`
- `platform`
- `app_version`
- `access_token_hash`
- `refresh_token_hash`
- `auth_state_hash`
- `access_expires_at`
- `refresh_expires_at`
- `revoked_at`

## Rotacion y revocacion

La revocacion principal ya no depende de borrar `Application Passwords`.

Ahora:

- `POST /auth/logout` revoca la sesion actual
- cambio de password del usuario invalida sesiones via `auth_state_hash`
- una sesion tambien puede revocarse al expirar o por perdida de acceso movil

## Verificacion minima

Antes de entregar acceso a produccion, validar:

1. `POST /auth/login` devuelve `access_token`
2. `GET /auth/me` devuelve el usuario esperado
3. `GET /auth/permissions` devuelve solo lo que debe usar
4. la UI cliente respeta `route_groups`

## Nota operativa sobre plugins de auth externos

Si existe otro plugin que tambien use `Authorization: Bearer`, `clubsams-control/v1` debe seguir respondiendo con su propio auth.

El plugin ya incorpora hardening para:

- priorizar su auth en `determine_current_user`
- neutralizar interferencias en `rest_authentication_errors` para `clubsams-control/v1`

## Limites actuales

- el sistema sigue siendo staff-only
- no existe SSO externo
- no existe emision automatica de usuarios; la identidad sigue siendo WordPress
