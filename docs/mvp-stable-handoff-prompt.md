# Cierre MVP estable y prompt de handoff

Fecha de cierre: 2026-06-06
Version local del plugin: 2.10.3
Repo activo: analysis-financiero-plugin
Dominio operativo: clubsamsve.com

## Estado MVP

El MVP queda cerrado como una version estable del modulo financiero operativo. El foco actual queda congelado en cobros, saldo a favor, precio dual, cierre extraordinario, compromisos, nomina, reportes y sincronizacion Woo/OpenPOS. El siguiente ciclo debe tratar nuevos modulos, como ordenes de compra, sin reabrir esta logica salvo que aparezca una reproduccion real o una auditoria de datos historicos.

## Reglas de negocio consolidadas

- Los pedidos abiertos por cobrar se gestionan desde Finanzas, no desde edicion manual normal de Woo, cuando ya tienen documento financiero o allocations.
- El sync Woo/OpenPOS no debe reabrir ni recalcular pagos internos ya gestionados por Finanzas solo por `_op_remain_paid`.
- En abonos normales, el monto capturado representa dinero nuevo recibido.
- La opcion de usar saldo a favor dentro del abono suma credito disponible al dinero nuevo para la vista previa y ejecucion.
- Si el saldo a favor proviene de pago real elegible en USD/divisa, puede participar como neto que activa precio dual.
- Si el pedido ya tiene precio dual aplicado, el saldo a favor se aplica normal y no duplica descuento.
- Los ajustes tecnicos no pueden convertirse en saldo a favor usable.
- Metodos tecnicos excluidos del credito usable: `salary_advance`, `dual_price_discount`, `internal_compensation`, `extraordinary_profile_closure`.
- El cierre extraordinario solo aplica en `Pedidos especificos`, con exactamente un pedido seleccionado.
- El cierre extraordinario puede ejecutarse sin metodo de pago real si no entra dinero nuevo.
- El cierre extraordinario requiere motivo, nota administrativa, confirmacion y aprobacion TOTP.
- Administradores tambien requieren TOTP para cierre extraordinario, casos historicos especiales y overrides de compromisos en nomina.
- Compromisos por cobrar son contexto operativo hasta que exista pago efectivo o deuda respaldada; no inflan el total principal.
- Adelantos de sueldo son recuperacion por nomina y no deben inflar el chip principal de deuda.
- El reporte de deudores debe usar el resultado completo, no solo la pagina visible del dashboard, y debe permitir impresion/guardar PDF via navegador y exportacion CSV editable.

## Flujo de abono y precio dual

1. El operador abre el perfil y entra en `Pedidos pendientes por cobrar`.
2. El campo principal es `Monto nuevo recibido`.
3. Si existe saldo a favor aplicable, aparece `Usar saldo a favor en este abono`.
4. La UI muestra `Dinero nuevo`, `Saldo a favor usado` y `Total para vista previa`.
5. El preview calcula sobre dinero nuevo mas saldo a favor incluido.
6. Si moneda/metodo califican para precio dual, el planner calcula descuento solo sobre tramos elegibles.
7. Si el pedido ya tenia descuento dual o descuento distinto detectado, el planner no concede otro descuento sobre ese item.
8. En `Pedidos especificos`, si sobra dinero nuevo despues de cubrir seleccion, el operador decide si ajusta al monto exacto, guarda excedente como saldo a favor o aplica excedente por antiguedad.
9. El batch valida saldo del documento, pago principal, credito y ajuste tecnico antes de crear allocations.
10. Si un lote dual falla con errores, disponibilidad sobrante peligrosa queda en cuarentena para no crear saldos falsos.

## Flujo de cierre extraordinario

1. El operador entra por `Pedidos especificos`.
2. Debe marcar exactamente un pedido.
3. Debe recalcular la vista para que la seleccion quede congelada.
4. Activa `Cerrar diferencia extraordinariamente`.
5. Completa motivo, referencia/nota administrativa y confirmacion.
6. La vista muestra el panel de aprobacion operativa.
7. La ejecucion requiere `approval_token`.
8. El batch crea ajuste tecnico/administrativo sin `main_payment_id` cuando no hubo dinero real.
9. La trazabilidad conserva seleccion, firma de preview, lote, documentos y aprobacion.

## Flujo de saldo a favor

El saldo a favor tiene dos usos distintos:

- Cruce interno puro desde la seccion de saldo a favor: compensa saldo existente sin registrar dinero nuevo.
- Uso dentro de abono nuevo: suma credito disponible al efectivo capturado para resolver pedidos y, si es elegible, participa en precio dual.

La UI debe seguir comunicando esta diferencia para evitar que el operador confunda dinero nuevo recibido con credito preexistente.

## Trazabilidad e idempotencia

- La `preview_signature` congela la vista previa y evita ejecutar contra un plan cambiado.
- El batch reutiliza firmas existentes para no duplicar corridas activas o completadas.
- Los eventos y metadatos deben conservar `selection_mode`, `selected_item_keys`, `preview_signature`, `approval_token` resumido y resultado de lote.
- El sync preserva documentos con allocations.
- Los creditos elegibles se filtran desde `CreditEligibilityService`.
- Las prevalidaciones deben fallar antes de allocations si el documento o los fondos cambiaron.

## Modulos relevantes del MVP

- `OrderSettlementPlannerService`: planifica abonos, seleccion especifica, saldo a favor, precio dual y cierre extraordinario.
- `OrderSettlementBatchService`: crea pagos/lotes, ejecuta allocations, aplica credito, valida TOTP y cuarentena saldos peligrosos.
- `CreditEligibilityService`: define que pagos pueden usarse como saldo a favor y cuales son tecnicos.
- `OrderSyncService`: sincroniza Woo/OpenPOS preservando estados financieros ya gestionados.
- `PendingCollectionsService`: construye cola de deudores, separa deuda real de compromisos/adelantos contextuales.
- `CommitmentExposureService`: clasifica compromisos entre recuperacion de deuda existente y exposicion adicional.
- `ApprovalBridge`: integra acciones sensibles con aprobacion TOTP.
- `Menu` y `finance-admin.js`: UI admin, preview, modales, reportes, aprobaciones y validaciones de operador.

## Prompt para abrir otro chat

Usa este prompt para continuar el desarrollo del plugin en un chat nuevo:

```text
Estoy trabajando en el repo local `/Users/gustavogonzalez/Documents/proyectos mac/analysis-financiero-plugin`, plugin WordPress `Finanzas ASD`, version actual `2.10.3`, usado operativamente en `clubsamsve.com`.

Necesito continuar el desarrollo sin romper el MVP financiero ya estabilizado. El modulo actual de cobros, abonos, saldo a favor, precio dual, cierre extraordinario, compromisos, nomina, reportes y sync Woo/OpenPOS debe tratarse como base estable.

Reglas actuales que debes preservar:
- Los pedidos gestionados por Finanzas no deben reabrirse ni recalcularse manualmente por Woo/OpenPOS si ya tienen documento financiero o allocations.
- El sync Woo/OpenPOS debe mostrar divergencias, no sobrescribir saldos internos gestionados por Finanzas.
- En abonos, el campo de monto representa dinero nuevo recibido.
- El saldo a favor dentro de un abono se muestra y calcula aparte, sumandose al dinero nuevo solo si el operador lo activa.
- El saldo a favor real en USD/divisa puede participar como neto para precio dual si el pedido no tiene descuento previo.
- Si el pedido ya tiene precio dual o descuento previo distinto, no se duplica descuento.
- Los pagos tecnicos no son saldo a favor usable: `salary_advance`, `dual_price_discount`, `internal_compensation`, `extraordinary_profile_closure`.
- El cierre extraordinario solo vive en `Pedidos especificos`, requiere exactamente un pedido seleccionado, puede no tener metodo si no hay pago real, y siempre requiere aprobacion TOTP incluso para administradores.
- Las acciones sensibles con TOTP actuales son cierre extraordinario, historico especial del ejercicio anterior y overrides de compromisos en nomina.
- Compromisos y adelantos de sueldo son contexto operativo y no deben inflar el total principal por cobrar.
- El reporte de deudores debe usar el resultado completo, no solo la pagina visible, y ofrecer vista imprimible/guardar PDF por navegador y CSV editable.
- Mantener trazabilidad de `selection_mode`, `selected_item_keys`, `preview_signature`, lote, eventos y aprobacion.

Antes de tocar codigo:
1. Revisa `git status`, version actual y archivos relevantes.
2. No reviertas cambios existentes sin confirmar.
3. Preserva patrones actuales del plugin y el diseño admin existente.
4. Si cambias comportamiento runtime de este plugin, sube version en `analysis-financiero-plugin.php`.
5. Usa `rg` para buscar y `apply_patch` para editar.

Para nuevos modulos, especialmente ordenes de compra:
- Disenar alrededor de documentos financieros, eventos, estados, trazabilidad y reportes existentes.
- Separar claramente intencion operativa, documento real, pago real y ajuste tecnico.
- Evitar que compromisos, proyecciones o planes inflen saldos reales hasta que exista documento o pago efectivo.
- Mantener idempotencia: si una corrida se repite, no debe duplicar documentos, pagos, allocations ni eventos.
- Incluir guards antes de crear registros: validar proveedor/perfil, moneda, total, estado, existencia de documento previo, firma o clave idempotente.
- Agregar tests de regresion para cada flujo sensible.

Primer objetivo del nuevo chat:
Analizar y planificar el modulo de ordenes de compra sobre la arquitectura actual del plugin, identificando entidades, estados, pantallas admin, integracion con documentos/pagos, reportes, permisos, auditoria e idempotencia antes de implementar.
```

## Validacion de cierre esperada

Antes de subir un cierre o nueva entrega:

- `php -l` sobre archivos PHP tocados.
- `node --check assets/js/finance-admin.js` si cambia JS admin.
- `git diff --check`.
- Ejecutar `for test in tests/*.php; do php "$test" || exit 1; done`.
- Confirmar version del plugin.
- Confirmar que `.DS_Store` no entra al commit.
