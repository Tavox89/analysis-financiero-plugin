<?php
/**
 * Plugin Name: Análisis Financiero Clubsams - Zona B
 * Description: Plugin para generar un análisis financiero en WooCommerce, separando Clubsams y Zona B con cuatro consultas y datos adicionales.
 * Version: 1.0
 * Author: Tu Nombre
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class AnalysisFinancieroPlugin {

    public function __construct() {
        // Cargamos la acción para agregar el menú en el Admin de WooCommerce
        add_action('admin_menu', array($this, 'add_financial_analysis_submenu'));
        // Encolamos scripts solo en la página de análisis financiero
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        // Acción AJAX para obtener datos
        add_action('wp_ajax_analysis_financiero_data', array($this, 'ajax_analysis_financiero_data'));
    }

    /**
     * 1. Agrega un Submenú dentro de WooCommerce
     */
    public function add_financial_analysis_submenu() {
        add_submenu_page(
            'woocommerce',                     // Página padre (WooCommerce)
            'Análisis Financiero',             // Título de la página
            'Análisis Financiero',             // Título del menú
            'manage_woocommerce',              // Capabilidad requerida
            'analysis-financiero',             // Slug de la página
            array($this, 'render_financial_analysis_page') // Función de contenido
        );
    }

    /**
     * 2. Renderiza la página principal de "Análisis Financiero"
     */
    public function render_financial_analysis_page() {
        ?>
        <div class="wrap">
            <h1>Análisis Financiero (Clubsams - Zona B)</h1>
            <p>Selecciona un rango de fechas para realizar el análisis:</p>

            <div style="margin-bottom: 1em;">
                <label for="analysis_start_date"><strong>Fecha Inicio:</strong></label>
                <input type="date" id="analysis_start_date" />
                
                <label for="analysis_end_date" style="margin-left: 1em;"><strong>Fecha Fin:</strong></label>
                <input type="date" id="analysis_end_date" />

                <button class="button button-primary" id="btnLoadAnalysis" style="margin-left: 1em;">
                    Cargar Análisis
                </button>
                <span id="analysisLoader" style="margin-left: 1em; display: none;">Cargando...</span>
            </div>

            <!-- Contenedor para las tablas de resultados -->
            <div id="analysisResults" style="display: none;">

                <!-- ========== CLUBSAMS ========== -->
                <h2 style="margin-top:2em;">Análisis Financiero - ClubSams</h2>
                <table class="widefat striped" style="max-width: 800px;">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Concepto</th>
                            <th style="width: 20%;">Monto (US$)</th>
                            <th style="width: 40%;">Explicación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Ventas Reales (ClubSams)</td>
                            <td><span id="cs_ventas_reales">0</span></td>
                            <td>Ventas totales sin productos Zona B (órdenes completadas)</td>
                        </tr>
                        <tr>
                            <td>Total Costo Productos Vendidos (Inversión)</td>
                            <td><span id="cs_costo_productos">0</span></td>
                            <td>Costo total de productos vendidos (sin Zona B)</td>
                        </tr>
                        <tr>
                            <td>Ganancia Bruta</td>
                            <td><span id="cs_ganancia_bruta">0</span></td>
                            <td>Ventas - Inversión</td>
                        </tr>
                        <tr>
                            <td>Gastos Totales Adicionales</td>
                            <td>
                                <input type="number" id="cs_gastos_adicionales" step="0.01" value="0" style="width:100%;">
                            </td>
                            <td>Incluye gastos en operativos y administrativos NO contemplados en costos</td>
                        </tr>
                        <tr>
                            <td>Costos Operativos</td>
                            <td>
                                <input type="number" id="cs_costos_operativos" step="0.01" value="0" style="width:100%;">
                            </td>
                            <td>Gastos operativos varios</td>
                        </tr>
                        <tr>
                            <td>Costos Administrativos</td>
                            <td>
                                <input type="number" id="cs_costos_administrativos" step="0.01" value="0" style="width:100%;">
                            </td>
                            <td>Costos administrativos (personal, oficina, etc.)</td>
                        </tr>
                        <tr style="background-color:#eee;">
                            <td>Total Gastos y Costos</td>
                            <td><span id="cs_total_gastos_costos">0</span></td>
                            <td>Suma de inversión + costos adicionales</td>
                        </tr>
                        <tr style="background-color:#d9f7be;">
                            <td>Ganancia Neta Real (ClubSams)</td>
                            <td><span id="cs_ganancia_neta">0</span></td>
                            <td>Ganancia Bruta - (Gastos Adicionales + Operativos + Administrativos)</td>
                        </tr>
                        <tr>
                            <td>Facturas Pendientes de Pago (ClubSams)</td>
                            <td><span id="cs_pendiente_pago">0</span></td>
                            <td>Monto total en estado Pendiente o On-Hold</td>
                        </tr>
                        <tr style="background-color:#fff3cd;">
                            <td>Ganancia Neta Real Proyectada (ClubSams)</td>
                            <td><span id="cs_ganancia_neta_proyectada">0</span></td>
                            <td>Ganancia Neta + Cobros pendientes</td>
                        </tr>
                        <tr>
                            <td>ROI Actual (%)</td>
                            <td><span id="cs_roi_actual">0</span>%</td>
                            <td>
                                (Ganancia Neta / Inversión) * 100
                                <span id="cs_roi_actual_explain" style="font-style: italic; margin-left:5px;"></span>
                            </td>
                        </tr>
                        <tr style="background-color:#f0f9ff;">
                            <td>ROI Proyectado (%)</td>
                            <td><span id="cs_roi_proyectado">0</span>%</td>
                            <td>
                                (Ganancia Neta Proyectada / Inversión) * 100
                                <span id="cs_roi_proyectado_explain" style="font-style: italic; margin-left:5px;"></span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- ========== ZONA B ========== -->
                <h2 style="margin-top:2em;">Análisis Financiero - Zona B</h2>
                <table class="widefat striped" style="max-width: 800px;">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Concepto</th>
                            <th style="width: 20%;">Monto (US$)</th>
                            <th style="width: 40%;">Explicación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Ventas Reales (Zona B)</td>
                            <td><span id="zb_ventas_reales">0</span></td>
                            <td>Ventas totales de productos Zona B (órdenes completadas)</td>
                        </tr>
                        <tr>
                            <td>Total Costo Productos Vendidos (Inversión)</td>
                            <td><span id="zb_costo_productos">0</span></td>
                            <td>Costo total de productos vendidos (Zona B)</td>
                        </tr>
                        <tr>
                            <td>Ganancia Bruta</td>
                            <td><span id="zb_ganancia_bruta">0</span></td>
                            <td>Ventas - Inversión</td>
                        </tr>
                        <tr>
                            <td>Gastos Totales Adicionales</td>
                            <td>
                                <input type="number" id="zb_gastos_adicionales" step="0.01" value="0" style="width:100%;">
                            </td>
                            <td>Incluye gastos en operativos y administrativos NO contemplados en costos</td>
                        </tr>
                        <tr>
                            <td>Costos Operativos</td>
                            <td>
                                <input type="number" id="zb_costos_operativos" step="0.01" value="0" style="width:100%;">
                            </td>
                            <td>Gastos operativos varios</td>
                        </tr>
                        <tr>
                            <td>Costos Administrativos</td>
                            <td>
                                <input type="number" id="zb_costos_administrativos" step="0.01" value="0" style="width:100%;">
                            </td>
                            <td>Costos administrativos (personal, oficina, etc.)</td>
                        </tr>
                        <tr style="background-color:#eee;">
                            <td>Total Gastos y Costos</td>
                            <td><span id="zb_total_gastos_costos">0</span></td>
                            <td>Suma de inversión + costos adicionales</td>
                        </tr>
                        <tr style="background-color:#d9f7be;">
                            <td>Ganancia Neta Real (Zona B)</td>
                            <td><span id="zb_ganancia_neta">0</span></td>
                            <td>Ganancia Bruta - (Gastos Adicionales + Operativos + Administrativos)</td>
                        </tr>
                        <tr>
                            <td>Facturas Pendientes de Pago (Zona B)</td>
                            <td><span id="zb_pendiente_pago">0</span></td>
                            <td>Monto total en estado Pendiente o On-Hold</td>
                        </tr>
                        <tr style="background-color:#fff3cd;">
                            <td>Ganancia Neta Real Proyectada (Zona B)</td>
                            <td><span id="zb_ganancia_neta_proyectada">0</span></td>
                            <td>Ganancia Neta + Cobros pendientes</td>
                        </tr>
                        <tr>
                            <td>ROI Actual (%)</td>
                            <td><span id="zb_roi_actual">0</span>%</td>
                            <td>
                                (Ganancia Neta / Inversión) * 100
                                <span id="zb_roi_actual_explain" style="font-style: italic; margin-left:5px;"></span>
                            </td>
                        </tr>
                        <tr style="background-color:#f0f9ff;">
                            <td>ROI Proyectado (%)</td>
                            <td><span id="zb_roi_proyectado">0</span>%</td>
                            <td>
                                (Ganancia Neta Proyectada / Inversión) * 100
                                <span id="zb_roi_proyectado_explain" style="font-style: italic; margin-left:5px;"></span>
                            </td>
                        </tr>
                    </tbody>
                </table>

            </div> <!-- /analysisResults -->

            <!-- Script para manejar eventos y cálculos en tiempo real -->
            <script>
            (function($){
                // Función para determinar el comentario del ROI según el porcentaje
                function getROIComment(roi) {
                    if (roi < 0) {
                        return "(Pérdidas)";
                    } else if (roi < 10) {
                        return "(Baja rentabilidad)";
                    } else if (roi < 30) {
                        return "(Rentabilidad media)";
                    } else {
                        return "(Alta rentabilidad)";
                    }
                }

                const btnLoad = $('#btnLoadAnalysis');
                const loader = $('#analysisLoader');
                const resultsContainer = $('#analysisResults');

                // Al cambiar cualquiera de los 3 costos, recalculamos totales
                $('#cs_gastos_adicionales, #cs_costos_operativos, #cs_costos_administrativos').on('input', recalcClubSams);
                $('#zb_gastos_adicionales, #zb_costos_operativos, #zb_costos_administrativos').on('input', recalcZonaB);

                btnLoad.on('click', function(){
                    const startDate = $('#analysis_start_date').val();
                    const endDate = $('#analysis_end_date').val();

                    if(!startDate || !endDate){
                        alert('Por favor selecciona ambas fechas.');
                        return;
                    }

                    loader.show();
                    resultsContainer.hide();

                    // Realizamos llamada AJAX para obtener datos
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'analysis_financiero_data',
                            start_date: startDate,
                            end_date: endDate,
                            security: '<?php echo wp_create_nonce("analysis_financiero_nonce"); ?>'
                        },
                        success: function(response){
                            loader.hide();
                            if(response.success){
                                const data = response.data;

                                // ========== Llenar datos ClubSams ==========
                                let ventas_clubsams = parseFloat(data.club_sams.ventas) || 0;
                                let inversion_clubsams = parseFloat(data.club_sams.inversion) || 0;
                                let pendiente_clubsams = parseFloat(data.club_sams_pendiente) || 0;

                                $('#cs_ventas_reales').text(ventas_clubsams.toFixed(2));
                                $('#cs_costo_productos').text(inversion_clubsams.toFixed(2));
                                $('#cs_pendiente_pago').text(pendiente_clubsams.toFixed(2));

                                // Ganancia Bruta (antes de gastos adicionales)
                                const cs_ganancia_bruta = ventas_clubsams - inversion_clubsams;
                                $('#cs_ganancia_bruta').text(cs_ganancia_bruta.toFixed(2));

                                // Recalcular con los inputs (gastos, costos operativos, etc.)
                                recalcClubSams();

                                // ========== Llenar datos Zona B ==========
                                let ventas_zonab = parseFloat(data.zona_b.ventas) || 0;
                                let inversion_zonab = parseFloat(data.zona_b.inversion) || 0;
                                let pendiente_zonab = parseFloat(data.zona_b_pendiente) || 0;

                                $('#zb_ventas_reales').text(ventas_zonab.toFixed(2));
                                $('#zb_costo_productos').text(inversion_zonab.toFixed(2));
                                $('#zb_pendiente_pago').text(pendiente_zonab.toFixed(2));

                                // Ganancia Bruta (antes de gastos adicionales)
                                const zb_ganancia_bruta = ventas_zonab - inversion_zonab;
                                $('#zb_ganancia_bruta').text(zb_ganancia_bruta.toFixed(2));

                                // Recalcular con los inputs
                                recalcZonaB();

                                resultsContainer.show();

                            } else {
                                alert('Error al obtener datos: ' + (response.data ? response.data : 'Desconocido'));
                            }
                        },
                        error: function(err){
                            loader.hide();
                            alert('Error en la petición AJAX.');
                            console.log(err);
                        }
                    });
                });

                function recalcClubSams(){
                    let ventas = parseFloat($('#cs_ventas_reales').text()) || 0;
                    let inversion = parseFloat($('#cs_costo_productos').text()) || 0;
                    let gananciaBruta = ventas - inversion;

                    let gastosAdic = parseFloat($('#cs_gastos_adicionales').val()) || 0;
                    let costosOp = parseFloat($('#cs_costos_operativos').val()) || 0;
                    let costosAdm = parseFloat($('#cs_costos_administrativos').val()) || 0;

                    // Total de gastos y costos = inversion + extras
                    let totalGastosCostos = inversion + gastosAdic + costosOp + costosAdm;
                    $('#cs_total_gastos_costos').text(totalGastosCostos.toFixed(2));

                    // Ganancia neta real = Ganancia bruta - (gastosAdic + costosOp + costosAdm)
                    let gananciaNeta = gananciaBruta - (gastosAdic + costosOp + costosAdm);
                    $('#cs_ganancia_neta').text(gananciaNeta.toFixed(2));

                    // Facturas pendientes
                    let pendiente = parseFloat($('#cs_pendiente_pago').text()) || 0;
                    let gananciaNetaProy = gananciaNeta + pendiente;
                    $('#cs_ganancia_neta_proyectada').text(gananciaNetaProy.toFixed(2));

                    // ROI actual = (Ganancia Neta / Inversión) * 100
                    let roiActual = 0;
                    if(inversion > 0){
                        roiActual = (gananciaNeta / inversion) * 100;
                    }
                    $('#cs_roi_actual').text(roiActual.toFixed(2));
                    $('#cs_roi_actual_explain').text( getROIComment(roiActual) );

                    // ROI proyectado = (Ganancia Neta Proyectada / Inversión) * 100
                    let roiProy = 0;
                    if(inversion > 0){
                        roiProy = (gananciaNetaProy / inversion) * 100;
                    }
                    $('#cs_roi_proyectado').text(roiProy.toFixed(2));
                    $('#cs_roi_proyectado_explain').text( getROIComment(roiProy) );
                }

                function recalcZonaB(){
                    let ventas = parseFloat($('#zb_ventas_reales').text()) || 0;
                    let inversion = parseFloat($('#zb_costo_productos').text()) || 0;
                    let gananciaBruta = ventas - inversion;

                    let gastosAdic = parseFloat($('#zb_gastos_adicionales').val()) || 0;
                    let costosOp = parseFloat($('#zb_costos_operativos').val()) || 0;
                    let costosAdm = parseFloat($('#zb_costos_administrativos').val()) || 0;

                    // Total de gastos y costos = inversion + extras
                    let totalGastosCostos = inversion + gastosAdic + costosOp + costosAdm;
                    $('#zb_total_gastos_costos').text(totalGastosCostos.toFixed(2));

                    // Ganancia neta real
                    let gananciaNeta = gananciaBruta - (gastosAdic + costosOp + costosAdm);
                    $('#zb_ganancia_neta').text(gananciaNeta.toFixed(2));

                    // Facturas pendientes
                    let pendiente = parseFloat($('#zb_pendiente_pago').text()) || 0;
                    let gananciaNetaProy = gananciaNeta + pendiente;
                    $('#zb_ganancia_neta_proyectada').text(gananciaNetaProy.toFixed(2));

                    // ROI actual
                    let roiActual = 0;
                    if(inversion > 0){
                        roiActual = (gananciaNeta / inversion) * 100;
                    }
                    $('#zb_roi_actual').text(roiActual.toFixed(2));
                    $('#zb_roi_actual_explain').text( getROIComment(roiActual) );

                    // ROI proyectado
                    let roiProy = 0;
                    if(inversion > 0){
                        roiProy = (gananciaNetaProy / inversion) * 100;
                    }
                    $('#zb_roi_proyectado').text(roiProy.toFixed(2));
                    $('#zb_roi_proyectado_explain').text( getROIComment(roiProy) );
                }

            })(jQuery);
            </script>
        <?php
    }

    /**
     * 3. Encolamos CSS/JS adicionales si lo deseamos (opcional)
     */
    public function enqueue_admin_assets($hook) {
        // Solo cargamos en la página analysis-financiero
        if ( isset($_GET['page']) && $_GET['page'] === 'analysis-financiero' ) {
            // Aquí podrías encolar CSS o JS adicionales
            // wp_enqueue_style( 'analysis-financiero-css', plugins_url('assets/admin.css', __FILE__) );
            // wp_enqueue_script( 'analysis-financiero-js', plugins_url('assets/admin.js', __FILE__), array('jquery'), '1.0', true );
        }
    }

    /**
     * 4. Acción AJAX: Ejecutar las 4 consultas y devolver resultados en JSON
     */
    public function ajax_analysis_financiero_data() {
        check_ajax_referer('analysis_financiero_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('No tienes permisos suficientes para realizar esta acción.');
        }

        global $wpdb;
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date   = sanitize_text_field($_POST['end_date']);

        // Ajusta tu prefijo de tabla si es diferente a "MlkpHecO_"
        // ========================== CONSULTAS ==========================

        // 1. Ventas, Inversión y Ganancia Real - ClubSams
        $club_sams_sql = "
            SELECT
              ventas.total_ventas_clubsams AS ventas,
              costos.inversion_total AS inversion
            FROM (
              SELECT 
                SUM(pm.meta_value) AS total_ventas_clubsams
              FROM MlkpHecO_posts p
              INNER JOIN MlkpHecO_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
              WHERE p.post_type = 'shop_order' 
                AND p.post_status = 'wc-completed'
                AND DATE(p.post_date) BETWEEN '%s' AND '%s'
                AND NOT EXISTS (
                  SELECT 1 FROM MlkpHecO_woocommerce_order_items oi
                  INNER JOIN MlkpHecO_woocommerce_order_itemmeta oim_product 
                    ON oi.order_item_id = oim_product.order_item_id 
                    AND oim_product.meta_key = '_product_id'
                  LEFT JOIN MlkpHecO_postmeta parent_meta 
                    ON oim_product.meta_value = parent_meta.post_id 
                    AND parent_meta.meta_key = '_parent_id'
                  WHERE oi.order_id = p.ID 
                    AND (
                      oim_product.meta_value IN (
                        SELECT object_id 
                        FROM MlkpHecO_term_relationships tr
                        INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                        WHERE tt.taxonomy = 'product_cat' 
                          AND t.slug = 'productos-zona-b'
                      )
                      OR parent_meta.meta_value IN (
                        SELECT object_id 
                        FROM MlkpHecO_term_relationships tr
                        INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                        WHERE tt.taxonomy = 'product_cat' 
                          AND t.slug = 'productos-zona-b'
                      )
                    )
                )
            ) AS ventas,
            (
              SELECT SUM(oim_qty.meta_value * pm_cost.meta_value) AS inversion_total
              FROM MlkpHecO_posts o
              INNER JOIN MlkpHecO_woocommerce_order_items oi ON o.ID = oi.order_id
              INNER JOIN MlkpHecO_woocommerce_order_itemmeta oim_product 
                ON oi.order_item_id = oim_product.order_item_id 
                AND oim_product.meta_key = '_product_id'
              INNER JOIN MlkpHecO_woocommerce_order_itemmeta oim_qty 
                ON oi.order_item_id = oim_qty.order_item_id 
                AND oim_qty.meta_key = '_qty'
              INNER JOIN MlkpHecO_postmeta pm_cost 
                ON oim_product.meta_value = pm_cost.post_id 
                AND pm_cost.meta_key = 'yith_cog_cost'
              LEFT JOIN MlkpHecO_postmeta parent_meta 
                ON oim_product.meta_value = parent_meta.post_id 
                AND parent_meta.meta_key = '_parent_id'
              WHERE o.post_type = 'shop_order' 
                AND o.post_status = 'wc-completed'
                AND DATE(o.post_date) BETWEEN '%s' AND '%s'
                AND NOT EXISTS (
                  SELECT 1 
                  FROM MlkpHecO_term_relationships tr
                  INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                  WHERE tt.taxonomy = 'product_cat' 
                    AND t.slug = 'productos-zona-b' 
                    AND tr.object_id IN (oim_product.meta_value, parent_meta.meta_value)
                )
            ) AS costos
        ";

        $club_sams_sql_prepared = $wpdb->prepare($club_sams_sql, $start_date, $end_date, $start_date, $end_date);
        $club_sams_data = $wpdb->get_row($club_sams_sql_prepared);

        // 2. Pedidos Pendientes de Pago - ClubSams
        $pendiente_clubsams_sql = "
            SELECT SUM(pm.meta_value) AS total_pendiente_clubsams
            FROM MlkpHecO_posts p
            INNER JOIN MlkpHecO_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            WHERE p.post_type = 'shop_order' 
              AND p.post_status IN ('wc-pending', 'wc-on-hold')
              AND DATE(p.post_date) BETWEEN '%s' AND '%s'
              AND NOT EXISTS (
                SELECT 1 FROM MlkpHecO_woocommerce_order_items oi
                INNER JOIN MlkpHecO_woocommerce_order_itemmeta oim_product 
                  ON oi.order_item_id = oim_product.order_item_id 
                  AND oim_product.meta_key = '_product_id'
                WHERE oi.order_id = p.ID 
                  AND oim_product.meta_value IN (
                    SELECT object_id 
                    FROM MlkpHecO_term_relationships tr
                    INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                    WHERE t.slug = 'productos-zona-b'
                  )
              );
        ";
        $pendiente_clubsams_sql_prepared = $wpdb->prepare($pendiente_clubsams_sql, $start_date, $end_date);
        $pendiente_clubsams_data = $wpdb->get_var($pendiente_clubsams_sql_prepared);

        // 3. Ventas, Inversión y Ganancia Real - Zona B
        $zona_b_sql = "
            SELECT
              ventas_zona.total_ventas_zonab AS ventas,
              costos_zona.inversion_total AS inversion
            FROM (
              SELECT 
                SUM(pm.meta_value) AS total_ventas_zonab
              FROM MlkpHecO_posts p
              INNER JOIN MlkpHecO_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
              WHERE p.post_type = 'shop_order'
                AND p.post_status = 'wc-completed'
                AND DATE(p.post_date) BETWEEN '%s' AND '%s'
                AND EXISTS (
                  SELECT 1 FROM MlkpHecO_woocommerce_order_items oi
                  INNER JOIN MlkpHecO_woocommerce_order_itemmeta oim_product 
                    ON oi.order_item_id = oim_product.order_item_id 
                    AND oim_product.meta_key = '_product_id'
                  LEFT JOIN MlkpHecO_postmeta parent_meta 
                    ON oim_product.meta_value = parent_meta.post_id 
                    AND parent_meta.meta_key = '_parent_id'
                  WHERE oi.order_id = p.ID
                    AND (
                      oim_product.meta_value IN (
                        SELECT object_id 
                        FROM MlkpHecO_term_relationships tr
                        INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                        WHERE tt.taxonomy = 'product_cat' 
                          AND t.slug = 'productos-zona-b'
                      )
                      OR parent_meta.meta_value IN (
                        SELECT object_id 
                        FROM MlkpHecO_term_relationships tr
                        INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                        WHERE tt.taxonomy = 'product_cat' 
                          AND t.slug = 'productos-zona-b'
                      )
                    )
                )
            ) AS ventas_zona,
            (
              SELECT SUM(oim_qty.meta_value * pm_cost.meta_value) AS inversion_total
              FROM MlkpHecO_posts AS o
              INNER JOIN MlkpHecO_woocommerce_order_items AS oi ON o.ID = oi.order_id
              INNER JOIN MlkpHecO_woocommerce_order_itemmeta AS oim_product 
                ON oi.order_item_id = oim_product.order_item_id 
                AND oim_product.meta_key = '_product_id'
              INNER JOIN MlkpHecO_woocommerce_order_itemmeta AS oim_qty 
                ON oi.order_item_id = oim_qty.order_item_id 
                AND oim_qty.meta_key = '_qty'
              INNER JOIN MlkpHecO_postmeta AS pm_cost 
                ON oim_product.meta_value = pm_cost.post_id 
                AND pm_cost.meta_key = 'yith_cog_cost'
              LEFT JOIN MlkpHecO_postmeta AS parent_meta 
                ON oim_product.meta_value = parent_meta.post_id 
                AND parent_meta.meta_key = '_parent_id'
              WHERE
                o.post_type = 'shop_order'
                AND o.post_status = 'wc-completed'
                AND DATE(o.post_date) BETWEEN '%s' AND '%s'
                AND EXISTS (
                  SELECT 1 
                  FROM MlkpHecO_term_relationships tr
                  INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                  WHERE tt.taxonomy = 'product_cat' 
                    AND t.slug = 'productos-zona-b' 
                    AND tr.object_id IN (oim_product.meta_value, parent_meta.meta_value)
                )
            ) AS costos_zona
        ";

        $zona_b_sql_prepared = $wpdb->prepare($zona_b_sql, $start_date, $end_date, $start_date, $end_date);
        $zona_b_data = $wpdb->get_row($zona_b_sql_prepared);

        // 4. Pedidos Pendientes de Pago - Zona B
        $pendiente_zonab_sql = "
            SELECT 
              SUM(pm.meta_value) AS total_pendiente_zonab
            FROM MlkpHecO_posts p
            INNER JOIN MlkpHecO_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
            WHERE 
              p.post_type = 'shop_order'
              AND p.post_status IN ('wc-pending', 'wc-on-hold')
              AND DATE(p.post_date) BETWEEN '%s' AND '%s'
              AND EXISTS (
                SELECT 1
                FROM MlkpHecO_woocommerce_order_items oi
                INNER JOIN MlkpHecO_woocommerce_order_itemmeta oim_product 
                  ON oi.order_item_id = oim_product.order_item_id 
                  AND oim_product.meta_key = '_product_id'
                LEFT JOIN MlkpHecO_postmeta parent_meta 
                  ON oim_product.meta_value = parent_meta.post_id 
                  AND parent_meta.meta_key = '_parent_id'
                WHERE oi.order_id = p.ID
                  AND (
                    oim_product.meta_value IN (
                      SELECT object_id 
                      FROM MlkpHecO_term_relationships tr
                      INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                      INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                      WHERE tt.taxonomy = 'product_cat' 
                        AND t.slug = 'productos-zona-b'
                    )
                    OR parent_meta.meta_value IN (
                      SELECT object_id 
                      FROM MlkpHecO_term_relationships tr
                      INNER JOIN MlkpHecO_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                      INNER JOIN MlkpHecO_terms t ON tt.term_id = t.term_id
                      WHERE tt.taxonomy = 'product_cat' 
                        AND t.slug = 'productos-zona-b'
                    )
                  )
              );
        ";
        $pendiente_zonab_sql_prepared = $wpdb->prepare($pendiente_zonab_sql, $start_date, $end_date);
        $pendiente_zonab_data = $wpdb->get_var($pendiente_zonab_sql_prepared);

        // ========================== PROCESAMOS RESULTADOS ==========================
        // Estructuramos la respuesta
        $response = array(
            'club_sams' => array(
                'ventas'    => $club_sams_data ? floatval($club_sams_data->ventas) : 0,
                'inversion' => $club_sams_data ? floatval($club_sams_data->inversion) : 0
            ),
            'club_sams_pendiente' => $pendiente_clubsams_data ? floatval($pendiente_clubsams_data) : 0,
            'zona_b' => array(
                'ventas'    => $zona_b_data ? floatval($zona_b_data->ventas) : 0,
                'inversion' => $zona_b_data ? floatval($zona_b_data->inversion) : 0
            ),
            'zona_b_pendiente' => $pendiente_zonab_data ? floatval($pendiente_zonab_data) : 0
        );

        wp_send_json_success($response);
    }

}

// Instanciamos la clase del plugin
new AnalysisFinancieroPlugin();
