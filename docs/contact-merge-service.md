# ContactMergeService

## Objetivo

`ContactMergeService` permite que un flujo externo aprobado, como la normalizacion de clientes de ASD Documento Cliente, una varios perfiles financieros cuando varios usuarios WordPress representan a la misma persona.

La llave financiera sigue siendo `contacts.id`. El servicio no elimina documentos, pagos ni planes: reparenta las filas existentes hacia el contacto ganador y conserva los IDs originales.

## Contrato

- `dryRun($winner_contact_id, $loser_contact_ids, $winner_wp_user_id, $context)`: valida bloqueos, calcula conteos por tabla y devuelve el plan de merge sin escribir.
- `execute($winner_contact_id, $loser_contact_ids, $winner_wp_user_id, $context)`: repite el dry-run y ejecuta el merge solo si no hay bloqueos.

`$context` puede incluir:

- `case_id`: caso de normalizacion que origina la accion.
- `reason`: motivo operativo registrado por el operador.
- `document_id`: cedula/RIF final propuesto.
- `display_name`, `legal_name`, `email`, `phone`: datos finales del contacto ganador.
- `moved_order_ids`: pedidos Woo/OpenPOS movidos por Documento Cliente antes del merge financiero.
- `actor_user_id`: usuario administrador que ejecuto la accion.

## Tablas reparentadas

- `documents`
- `payments`
- `service_profiles`
- `installment_plans`
- `employee_advances`
- `payroll_periods`
- `order_settlement_batches`
- `order_assumption_batches`
- `employee_profiles`, solo cuando hay un unico perfil de empleado compatible.

Los contactos perdedores quedan con `status = merged`, `wp_user_id = NULL` y una nota de auditoria. No se borran fisicamente.

## Bloqueos

El servicio bloquea cuando:

- falta el contacto ganador o un contacto perdedor.
- hay mas de un perfil de empleado entre los contactos seleccionados.
- el `winner_wp_user_id` ya esta enlazado a otro perfil de empleado fuera del grupo.
- hay lotes financieros activos.
- hay documentos financieros distintos al documento final propuesto.

## Refresh posterior

Despues del merge se invalidan snapshots de contacto, dashboard, cuentas por cobrar, cuentas por pagar, payroll e historico. Tambien se invalidan vistas cacheadas de pedidos, se refrescan documentos con `source_links`, los pedidos movidos recibidos en `moved_order_ids` y la identidad historica del contacto ganador.

## Regla operativa

Este servicio debe usarse detras de un preflight visible para el operador. No esta pensado para merges masivos ciegos: el origen debe conservar snapshot, actor, caso y razon antes de llamar `execute`.
