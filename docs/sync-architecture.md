# Sync Architecture

La sincronizacion es una capacidad nativa del core.

## Base tecnica

- cada documento puede tener uno o varios vinculos externos
- los vinculos viven en `source_links`
- cada sincronizacion debe guardar identificador externo, proveedor, hash y fecha
- cada documento puede tener override manual sin perder su origen
- un vinculo puede bloquear override con `override_locked`
- la API expone `/integrations/status` para revisar deteccion y actividad base
- la API expone `/sync/orders` para sincronizacion manual Woo/OpenPOS
- el motor de reglas clasifica al sincronizar, salvo cuando existe override manual persistente
- un pedido Woo u OpenPOS solo entra al core cuando se gestiona financieramente o cuando se usa una sincronizacion manual de backfill
- el pedido operativo sigue vivo en Woo; el core crea una capa financiera enlazada, no una duplicacion operativa obligatoria
- si un pedido ya tiene asignaciones financieras parciales, el sistema restringe cambios sensibles de estado en Woo

## Proveedores previstos

- WooCommerce
- OpenPOS
- integraciones externas futuras

## Modulos internos futuros

- gestor propio de compras
- panel de mercancia por recibir

## Regla principal

El sistema financiero consume y normaliza datos.
No reemplaza el origen operativo.
