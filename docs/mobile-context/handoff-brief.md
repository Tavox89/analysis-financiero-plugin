# Mobile Handoff Brief

Base consolidada para cualquier repo cliente que consuma el backend movil actual.

## Estado actual del plugin

Version visible auditada:

- `2.0.7`

Schema auditado:

- `2026.03.22-alpha12`

## Decision clave actual

El backend movil vigente ya no gira alrededor de `Application Passwords`.

Contrato actual:

- namespace canonico: `/wp-json/clubsams-control/v1`
- auth propia por sesiones de dispositivo
- config publica por ambiente con `GET /public/app-config`
- `asdl-fin/v1` queda como compatibilidad heredada

## Rutas mas importantes

### Config y auth

- `GET /public/app-config`
- `POST /auth/login`
- `POST /auth/refresh`
- `POST /auth/logout`
- `GET /auth/me`
- `GET /auth/permissions`

### Operacion principal

- `GET /dashboard`
- `GET /profiles`
- `GET /profiles/{id}`
- `GET /profiles/{id}/pending-orders`
- `POST /profiles/{id}/collections/preview`
- `POST /profiles/{id}/collections`
- `POST /profiles/{id}/apply-credit`
- `GET /cash/summary`
- `GET /cash/movements`
- `GET /finance/overview`
- `GET /finance/receivables`
- `GET /finance/payables`
- `GET /finance/comparison`
- `GET /audit/events`
- `GET /integrations/status`

### Contrato parcial / placeholder

- `GET /inventory/summary`
- `GET /inventory/expirations`
- `GET /inventory/incoming`
- `GET /inventory/usd-report`

Inventario ya tiene namespace y contrato, pero puede responder `meta.available=false`.

## Reglas de acceso

Puede entrar si cumple una de estas condiciones:

- `administrator`
- `shop_manager`
- capability `asdl_fin_access_mobile`

La UI cliente debe consumir:

- `permissions`
- `route_groups`

## Riesgos actuales

1. inventario sigue incompleto a nivel de dominio real
2. si existe otro plugin que use `Bearer`, el namespace canonico debe permanecer aislado
3. cualquier cambio en auth, namespace, payloads o permisos obliga a actualizar docs canonicas

## Documentos canonicos para continuidad

Leer y mantener:

1. `README.md`
2. `docs/api-overview.md`
3. `docs/mobile-context/clubsams-control-v1-backend.md`
4. `docs/mobile-context/staff-auth-provisioning.md`

Los documentos viejos que hablen de `Application Passwords` como flujo principal deben tratarse como historicos si aun no fueron reescritos.
