# Menu And UX

## Menu principal

- Dashboard
- Movimientos
- Perfiles y terceros
- Servicios
- Cuentas
- Compras
- Mercancia por recibir
- Cobros y pagos
- Compromisos
- Nomina
- Automatizaciones
- Reportes
- Integraciones
- API y documentacion
- Configuracion

## Regla de lenguaje

- usar palabras claras para el usuario final
- evitar claves tecnicas en tablas y pantallas
- separar lenguaje operativo de lenguaje financiero
- usar `Movimientos` en UI y `Documento` solo como termino interno de dominio
- usar `Perfiles y terceros` para usuarios enlazados, proveedores y terceros externos; `Contacto externo` solo cuando no exista usuario
- explicar cada modulo con un subtitulo corto

## Criterio visual

- interfaz limpia y sobria
- tarjetas con contexto breve
- formularios simples
- tablas con lectura rapida
- estados visibles mediante badges
- detalle de movimiento con reclasificacion manual y bloqueo de sincronizacion automatica cuando aplique
- centro de perfil con resumen, pedidos, consumo por rango y base para empleado
- `Servicios` como modulo propio para `service_expense`, con alta puntual, configuracion recurrente y cola operativa, sin mezclar su alta rapida dentro de `Movimientos`
- dentro del perfil del proveedor o tercero deben verse `Cuentas por pagar` y `Servicios` como bloques separados de gestion
