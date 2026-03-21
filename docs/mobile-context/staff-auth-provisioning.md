# Staff Auth Provisioning

## Objetivo

Documentar el flujo operativo oficial para dar acceso movil interno al staff usando la autenticacion nativa de WordPress.

## Decision actual

Para el MVP movil interno, la autenticacion recomendada es:

- `wp_user` como identidad unica
- `Application Passwords` de WordPress como credencial de app
- capacidades propias de `Finanzas ASD` para autorizar modulos y acciones

No se crea un sistema paralelo de usuarios ni un login REST propio en esta etapa.

## Requisitos minimos para un usuario movil

1. existir como usuario WordPress
2. tener acceso al backend donde se gestionara la credencial
3. tener la capacidad `asdl_fin_access_mobile`
4. tener las capacidades funcionales que correspondan a su rol:
   - dashboard
   - perfiles
   - pagos/cobranza
   - compromisos
   - nomina
   - integraciones

## Flujo recomendado para provisionar acceso

1. crear o identificar el `wp_user`
2. asignar rol WordPress base segun el caso
3. asignar capacidades del plugin necesarias para el usuario
4. entrar al perfil del usuario en WordPress
5. generar una nueva `Application Password`
6. nombrarla con criterio operativo, por ejemplo:
   - `Finanzas ASD iPhone Gustavo`
   - `Finanzas ASD iPad Caja 01`
7. guardar la credencial de forma segura en la app o gestor interno
8. validar acceso con:
   - `GET /wp-json/asdl-fin/v1/me`
   - `GET /wp-json/asdl-fin/v1/me/permissions`

## Rotacion y revocacion

La revocacion o rotacion se hace desde el perfil del usuario WordPress:

- revocar la `Application Password` si se pierde el equipo
- generar una nueva si cambia de dispositivo
- eliminarla si el usuario ya no debe usar la app

No hace falta tocar tablas del plugin para esto.

## Reglas operativas

- una credencial por dispositivo o contexto cuando sea posible
- no reutilizar una misma credencial entre varios empleados
- si cambia el rol o permiso del usuario, validar de nuevo `/me/permissions`
- si se da de baja al usuario WordPress, el acceso movil tambien queda cortado

## Verificacion minima

Antes de entregar acceso a produccion, validar:

1. autenticacion correcta por WordPress REST
2. `GET /me` devuelve el usuario esperado
3. `GET /me/permissions` devuelve solo lo que debe usar
4. el usuario puede abrir los modulos permitidos y recibe error en los no autorizados

## Limites actuales

- no hay login propio con refresh token
- no hay emision automatica desde el plugin
- la provision sigue siendo operativa/manual desde WordPress

Eso es suficiente para el MVP movil interno de staff.
