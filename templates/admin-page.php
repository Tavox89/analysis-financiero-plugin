<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div class="wrap afp-wrap">
    <h1>Analisis Financiero</h1>
    <p>Selecciona un rango de fechas para realizar el analisis.</p>

    <?php
    $settings = AFP_Settings::get_settings();
    $all_statuses = wc_get_order_statuses();
    ?>

    <div class="afp-panel afp-panel-controls">
        <div class="afp-controls">
            <div class="afp-field">
                <label for="afp_start_date"><strong>Fecha Inicio:</strong></label>
                <input type="date" id="afp_start_date" />
            </div>
            <div class="afp-field">
                <label for="afp_end_date"><strong>Fecha Fin:</strong></label>
                <input type="date" id="afp_end_date" />
            </div>
            <div class="afp-field afp-field-wide">
                <label for="afp_exclude_categories"><strong>Excluir categorias (opcional):</strong></label>
                <input type="text" id="afp_exclude_categories" placeholder="slug-o-id, slug-2" />
                <p class="description">Si indicas categorias, se aplicara el modo de exclusion configurado en Ajustes.</p>
            </div>
            <div class="afp-field afp-field-wide">
                <strong>Estados para ventas:</strong>
                <div class="afp-checkboxes">
                    <?php foreach ( $all_statuses as $status_key => $label ) : ?>
                        <label>
                            <input type="checkbox" class="afp-sales-status" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $settings['sales_statuses'], true ) ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="description">Selecciona los estados que se cuentan como ventas.</p>
            </div>
            <div class="afp-field afp-field-wide">
                <strong>Estados para pendientes:</strong>
                <div class="afp-checkboxes">
                    <?php foreach ( $all_statuses as $status_key => $label ) : ?>
                        <label>
                            <input type="checkbox" class="afp-pending-status" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $settings['pending_statuses'], true ) ); ?> />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="description">Selecciona los estados que cuentan como facturas por cobrar.</p>
            </div>
            <div class="afp-field afp-actions">
                <button class="button button-primary" id="afp_btn_load">Cargar Analisis</button>
                <button class="button" id="afp_btn_export" disabled>Exportar CSV</button>
                <span id="afp_loader" class="afp-loader" style="display:none;">Cargando...</span>
            </div>
        </div>

        <div class="afp-presets">
            <button class="button" data-range="today">Hoy</button>
            <button class="button" data-range="yesterday">Ayer</button>
            <button class="button" data-range="last7">Ultimos 7 dias</button>
            <button class="button" data-range="thismonth">Mes actual</button>
            <button class="button" data-range="lastmonth">Mes anterior</button>
        </div>
    </div>

    <div id="afp_results" style="display:none;">
        <div class="afp-panel">
            <div class="afp-section-header">
                <h2>Indicadores clave</h2>
            </div>
            <div class="afp-cards">
                <div class="afp-card afp-card-sales">
                    <div class="afp-card-label">Ventas Netas</div>
                    <div class="afp-card-value" id="afp_card_ventas_netas">0</div>
                    <div class="afp-card-meta">Pedidos: <span id="afp_metric_pedidos">0</span></div>
                </div>
                <div class="afp-card afp-card-cost">
                    <div class="afp-card-label">Inversion</div>
                    <div class="afp-card-value" id="afp_card_inversion">0</div>
                    <div class="afp-card-meta">Costo prom. unidad: <span id="afp_metric_costo_promedio">0</span></div>
                </div>
                <div class="afp-card afp-card-profit">
                    <div class="afp-card-label">Ganancia Bruta</div>
                    <div class="afp-card-value" id="afp_card_ganancia_bruta">0</div>
                    <div class="afp-card-meta">Margen bruto: <span id="afp_metric_margen_bruto_pct">0</span>%</div>
                </div>
                <div class="afp-card afp-card-net">
                    <div class="afp-card-label">Ganancia Neta</div>
                    <div class="afp-card-value" id="afp_card_ganancia_neta">0</div>
                    <div class="afp-card-meta">ROI actual: <span id="afp_card_roi_actual">0</span>%</div>
                </div>
            </div>

            <div class="afp-metrics">
                <div class="afp-metric afp-metric-units">
                    <div class="afp-metric-label">Unidades</div>
                    <div class="afp-metric-value" id="afp_metric_unidades">0</div>
                </div>
                <div class="afp-metric afp-metric-refund">
                    <div class="afp-metric-label">Tasa reembolso</div>
                    <div class="afp-metric-value" id="afp_metric_tasa_reembolso">0</div>
                </div>
                <div class="afp-metric afp-metric-unit-sales">
                    <div class="afp-metric-label">Ventas por unidad</div>
                    <div class="afp-metric-value" id="afp_metric_venta_promedio">0</div>
                </div>
                <div class="afp-metric afp-metric-pending">
                    <div class="afp-metric-label">Pedidos pendientes</div>
                    <div class="afp-metric-value" id="afp_metric_pedidos_pendientes">0</div>
                </div>
                <div class="afp-metric afp-metric-netmargin">
                    <div class="afp-metric-label">Margen neto</div>
                    <div class="afp-metric-value" id="afp_metric_margen_neto_pct">0</div>
                </div>
            </div>
        </div>

        <div id="afp_alert" class="afp-alert" style="display:none;">
            <strong>Alerta:</strong> El margen neto esta por debajo del <span id="afp_alert_threshold">0</span>%.
        </div>

        <div class="afp-panel">
            <div class="afp-section-header">
                <h2>Ventas diarias</h2>
                <span class="afp-section-meta" id="afp_chart_range"></span>
            </div>
            <div class="afp-chart-wrap">
                <canvas id="afp_sales_chart" height="240"></canvas>
            </div>
        </div>

        <div class="afp-panel">
            <div class="afp-section-header">
                <h2>Comparativo con periodo anterior</h2>
                <span class="afp-section-meta" id="afp_compare_range"></span>
            </div>
            <table class="widefat striped afp-table">
                <thead>
                    <tr>
                        <th>Indicador</th>
                        <th>Actual</th>
                        <th>Periodo anterior</th>
                        <th>Variacion</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Ventas Netas</td>
                        <td id="afp_compare_ventas_netas"></td>
                        <td id="afp_compare_ventas_netas_prev"></td>
                        <td id="afp_compare_ventas_netas_delta" class="afp-delta"></td>
                    </tr>
                    <tr>
                        <td>Ganancia Bruta</td>
                        <td id="afp_compare_ganancia_bruta"></td>
                        <td id="afp_compare_ganancia_bruta_prev"></td>
                        <td id="afp_compare_ganancia_bruta_delta" class="afp-delta"></td>
                    </tr>
                    <tr>
                        <td>Pedidos</td>
                        <td id="afp_compare_pedidos"></td>
                        <td id="afp_compare_pedidos_prev"></td>
                        <td id="afp_compare_pedidos_delta" class="afp-delta"></td>
                    </tr>
                    <tr>
                        <td>Unidades</td>
                        <td id="afp_compare_unidades"></td>
                        <td id="afp_compare_unidades_prev"></td>
                        <td id="afp_compare_unidades_delta" class="afp-delta"></td>
                    </tr>
                    <tr>
                        <td>Margen bruto %</td>
                        <td id="afp_compare_margen"></td>
                        <td id="afp_compare_margen_prev"></td>
                        <td id="afp_compare_margen_delta" class="afp-delta"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="afp-panel">
            <div class="afp-section-header">
                <h2>Resumen General</h2>
            </div>
            <table class="widefat striped afp-table">
            <thead>
                <tr>
                    <th style="width: 40%;">Concepto</th>
                    <th style="width: 20%;">Monto (US$)</th>
                    <th style="width: 40%;">Explicacion</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Ventas Brutas</td>
                    <td><span id="afp_ventas_brutas">0</span></td>
                    <td>Total de ventas segun la base configurada</td>
                </tr>
                <tr>
                    <td>Reembolsos del periodo</td>
                    <td><span id="afp_reembolsos">0</span></td>
                    <td>Devoluciones realizadas en el rango de fechas</td>
                </tr>
                <tr>
                    <td>Ventas Netas</td>
                    <td><span id="afp_ventas_netas">0</span></td>
                    <td>Ventas Brutas - Reembolsos</td>
                </tr>
                <tr>
                    <td>Total Costo Productos Vendidos (Inversion)</td>
                    <td><span id="afp_costo_productos">0</span></td>
                    <td>Costo total de productos vendidos</td>
                </tr>
                <tr>
                    <td>Ganancia Bruta</td>
                    <td><span id="afp_ganancia_bruta">0</span></td>
                    <td>Ventas Netas - Inversion</td>
                </tr>
                <tr>
                    <td>Gastos Totales Adicionales</td>
                    <td>
                        <input type="number" id="afp_gastos_adicionales" step="0.01" value="0" />
                    </td>
                    <td>Gastos operativos y administrativos no contemplados en costos</td>
                </tr>
                <tr>
                    <td>Costos Operativos</td>
                    <td>
                        <input type="number" id="afp_costos_operativos" step="0.01" value="0" />
                    </td>
                    <td>Gastos operativos varios</td>
                </tr>
                <tr>
                    <td>Costos Administrativos</td>
                    <td>
                        <input type="number" id="afp_costos_administrativos" step="0.01" value="0" />
                    </td>
                    <td>Costos administrativos (personal, oficina, etc.)</td>
                </tr>
                <tr class="afp-total-row">
                    <td>Total Gastos y Costos</td>
                    <td><span id="afp_total_gastos_costos">0</span></td>
                    <td>Suma de inversion + costos adicionales</td>
                </tr>
                <tr class="afp-highlight-row">
                    <td>Ganancia Neta Real</td>
                    <td><span id="afp_ganancia_neta">0</span></td>
                    <td>Ganancia Bruta - (Gastos Adicionales + Operativos + Administrativos)</td>
                </tr>
                <tr>
                    <td>Facturas Pendientes de Pago</td>
                    <td><span id="afp_pendiente_pago">0</span></td>
                    <td>Monto total en estado Pendiente u On-Hold</td>
                </tr>
                <tr class="afp-warning-row">
                    <td>Ganancia Neta Real Proyectada</td>
                    <td><span id="afp_ganancia_neta_proyectada">0</span></td>
                    <td>Ganancia Neta + Cobros pendientes</td>
                </tr>
                <tr>
                    <td>ROI Actual (%)</td>
                    <td><span id="afp_roi_actual">0</span>%</td>
                    <td>
                        (Ganancia Neta / Inversion) * 100
                        <span id="afp_roi_actual_explain" class="afp-roi-comment"></span>
                    </td>
                </tr>
                <tr class="afp-info-row">
                    <td>ROI Proyectado (%)</td>
                    <td><span id="afp_roi_proyectado">0</span>%</td>
                    <td>
                        (Ganancia Neta Proyectada / Inversion) * 100
                        <span id="afp_roi_proyectado_explain" class="afp-roi-comment"></span>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>

        <div class="afp-grid">
            <div class="afp-panel">
                <h3>Top 5 dias mas vendidos</h3>
                <table class="widefat striped afp-table afp-table-small">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Monto (US$)</th>
                        </tr>
                    </thead>
                    <tbody id="afp_top_ventas"></tbody>
                </table>
            </div>
            <div class="afp-panel">
                <h3>Top 5 dias menos vendidos</h3>
                <table class="widefat striped afp-table afp-table-small">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Monto (US$)</th>
                        </tr>
                    </thead>
                    <tbody id="afp_bottom_ventas"></tbody>
                </table>
            </div>
        </div>

        <div class="afp-grid afp-grid-wide">
            <div class="afp-panel">
                <h3>Top productos</h3>
                <table class="widefat striped afp-table afp-table-medium">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Unidades</th>
                            <th>Ventas (US$)</th>
                        </tr>
                    </thead>
                    <tbody id="afp_top_productos"></tbody>
                </table>
            </div>
            <div class="afp-panel">
                <h3>Top categorias</h3>
                <table class="widefat striped afp-table afp-table-medium">
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th>Unidades</th>
                            <th>Ventas (US$)</th>
                        </tr>
                    </thead>
                    <tbody id="afp_top_categorias"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
