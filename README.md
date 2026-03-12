# Analisis Financiero WooCommerce

Plugin administrativo para analizar ventas, costos y rentabilidad en WooCommerce con filtros opcionales por categoria.

## Funcionalidades principales
- Resumen financiero con ventas brutas, reembolsos, ventas netas, inversion y ROI.
- Filtro opcional para excluir categorias (por slug o ID).
- Top de dias, productos y categorias.
- Exportacion a CSV.
- Ajustes configurables desde el menu lateral.
- Filtro por estados de pedido en cada ejecucion (ventas y pendientes).
- Indicadores adicionales: pedidos, unidades, margen bruto, tasa de reembolso.
- Comparativo automatico con el periodo anterior.
- Grafica de ventas diarias (line chart) con comparativo.
- Alertas cuando el margen neto baja del umbral configurado.
- Preset de fechas para Mes anterior.

## Ajustes disponibles
- Fuente de costo (YITH `yith_cog_cost`, SkyVerge `*_wc_cog_cost*` o personalizada).
- Estados de pedido para ventas y pendientes.
- Base de ventas (total de pedido o solo items).
- Modo de exclusion (pedido completo o solo items).
- Cache de reportes (minutos).
- Limite de tops.

## Uso rapido
1. Ve a WooCommerce > Analisis Financiero.
2. Selecciona un rango de fechas.
3. (Opcional) Escribe categorias a excluir.
4. Presiona "Cargar Analisis".

## Notas
- El calculo de costos depende de la meta configurada.
- Compatible con HPOS al usar `wc_get_orders`.
