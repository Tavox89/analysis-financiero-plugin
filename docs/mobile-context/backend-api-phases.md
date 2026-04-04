# Backend API Phases

## Estado consolidado actual

El backend movil ya paso de fase de apertura a fase de consolidacion.

Estado:

- fase documental base: cerrada
- fase de auth canonica: cerrada
- fase de namespace canonico: cerrada
- fase de endpoints principales para movil: cerrada
- fase de config publica por ambiente: cerrada
- fase de hardening contra conflictos de auth externos: activa y documentada

## Fase vigente

### Fase 7. Consolidacion canonica

Objetivo:

- mantener `clubsams-control/v1` como contrato oficial
- evitar regresiones hacia `asdl-fin/v1` como contrato principal
- sostener documentacion viva

Checklist vigente:

1. `public/app-config` expuesto
2. `auth/login`, `auth/refresh`, `auth/logout`, `auth/me` activos
3. `route_groups` alineados con `CapabilityManager`
4. hardening para conflictos con otros plugins de auth
5. docs canonicas actualizadas

## Fase 8. Endurecimiento operativo

Objetivo:

- mejorar observabilidad y estabilidad del backend movil en ambientes reales

Backlog recomendado:

1. loggear y revisar cualquier `503` en rutas canonicas con token valido
2. monitorear conflictos con middlewares REST externos
3. cerrar dominio real de inventario
4. ampliar auditoria de sesiones por dispositivo

## Fase 9. Paridad para cliente web administrativo

Objetivo:

- reutilizar el mismo backend canonico para una futura app web interna

Backlog recomendado:

1. sostener `public/app-config` como bootstrap unico
2. mantener auth por tokens y sesiones por dispositivo
3. ampliar feature flags por ambiente

## Regla de trabajo

Si cambia cualquiera de estas areas:

- auth
- namespace
- endpoints
- payloads
- permisos
- tablas de sesiones
- config publica por ambiente

entonces actualizar minimo:

- `README.md`
- `docs/api-overview.md`
- `docs/mobile-context/clubsams-control-v1-backend.md`
- `docs/mobile-context/staff-auth-provisioning.md`
- `docs/mobile-context/handoff-brief.md`
