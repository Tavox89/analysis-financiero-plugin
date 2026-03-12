<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AFP_Ajax {
    public function __construct() {
        add_action( 'wp_ajax_afp_get_data', array( $this, 'handle_get_data' ) );
        add_action( 'wp_ajax_afp_export_csv', array( $this, 'handle_export_csv' ) );
    }

    public function handle_get_data() {
        check_ajax_referer( 'afp_nonce', 'security' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'No tienes permisos suficientes para realizar esta accion.' );
        }

        $params = $this->get_request_params();
        if ( is_wp_error( $params ) ) {
            wp_send_json_error( $params->get_error_message() );
        }

        $data = AFP_Reports::get_summary(
            $params['start_date'],
            $params['end_date'],
            $params['exclude_ids'],
            array(
                'sales_statuses' => $params['sales_statuses'],
                'pending_statuses' => $params['pending_statuses'],
            )
        );

        wp_send_json_success( $data );
    }

    public function handle_export_csv() {
        check_ajax_referer( 'afp_nonce', 'security' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No tienes permisos suficientes para realizar esta accion.' );
        }

        $params = $this->get_request_params();
        if ( is_wp_error( $params ) ) {
            wp_die( $params->get_error_message() );
        }

        $data = AFP_Reports::get_summary(
            $params['start_date'],
            $params['end_date'],
            $params['exclude_ids'],
            array(
                'sales_statuses' => $params['sales_statuses'],
                'pending_statuses' => $params['pending_statuses'],
            )
        );

        $filename = sprintf(
            'analisis-financiero-%s-a-%s.csv',
            $params['start_date'],
            $params['end_date']
        );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        fputcsv( $output, array( 'Seccion', 'Concepto', 'Valor' ) );

        if ( isset( $data['totals'] ) ) {
            fputcsv( $output, array( 'Resumen', 'Ventas Brutas', $data['totals']['ventas_brutas'] ) );
            fputcsv( $output, array( 'Resumen', 'Reembolsos', $data['totals']['reembolsos'] ) );
            fputcsv( $output, array( 'Resumen', 'Ventas Netas', $data['totals']['ventas_netas'] ) );
            fputcsv( $output, array( 'Resumen', 'Inversion', $data['totals']['inversion'] ) );
            fputcsv( $output, array( 'Resumen', 'Pedidos', $data['totals']['pedidos'] ) );
            fputcsv( $output, array( 'Resumen', 'Unidades', $data['totals']['unidades'] ) );
            fputcsv( $output, array( 'Resumen', 'Margen Bruto %', $data['totals']['margen_bruto_pct'] ) );
            fputcsv( $output, array( 'Resumen', 'Tasa Reembolso %', $data['totals']['tasa_reembolso'] ) );
            fputcsv( $output, array( 'Resumen', 'Costo Promedio Unidad', $data['totals']['costo_promedio_unidad'] ) );
            fputcsv( $output, array( 'Resumen', 'Venta Promedio Unidad', $data['totals']['venta_promedio_unidad'] ) );
        }

        fputcsv( $output, array( 'Resumen', 'Pendiente de pago', $data['pendiente'] ) );
        fputcsv( $output, array( 'Resumen', 'Pedidos pendientes', $data['pendiente_count'] ) );

        if ( ! empty( $data['compare'] ) && ! empty( $data['compare']['totals'] ) ) {
            $compare = $data['compare'];
            fputcsv( $output, array( 'Comparativo', 'Periodo anterior', $compare['range']['start'] . ' a ' . $compare['range']['end'] ) );
            fputcsv( $output, array( 'Comparativo', 'Ventas Netas', $compare['totals']['ventas_netas'] ) );
            fputcsv( $output, array( 'Comparativo', 'Inversion', $compare['totals']['inversion'] ) );
            fputcsv( $output, array( 'Comparativo', 'Pedidos', $compare['totals']['pedidos'] ) );
            fputcsv( $output, array( 'Comparativo', 'Unidades', $compare['totals']['unidades'] ) );
        }

        fputcsv( $output, array( 'Top dias', 'Fecha', 'Total' ) );
        foreach ( $data['totals']['top_mas_vendido'] as $row ) {
            fputcsv( $output, array( 'Top dias', $row['fecha'], $row['total'] ) );
        }

        fputcsv( $output, array( 'Bottom dias', 'Fecha', 'Total' ) );
        foreach ( $data['totals']['top_menos_vendido'] as $row ) {
            fputcsv( $output, array( 'Bottom dias', $row['fecha'], $row['total'] ) );
        }

        fputcsv( $output, array( 'Top productos', 'Producto', 'Unidades', 'Total' ) );
        foreach ( $data['productos'] as $row ) {
            fputcsv( $output, array( 'Top productos', $row['nombre'], $row['cantidad'], $row['total'] ) );
        }

        fputcsv( $output, array( 'Top categorias', 'Categoria', 'Unidades', 'Total' ) );
        foreach ( $data['categorias'] as $row ) {
            fputcsv( $output, array( 'Top categorias', $row['nombre'], $row['cantidad'], $row['total'] ) );
        }

        fclose( $output );
        exit;
    }

    private function get_request_params() {
        $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        $exclude_raw = isset( $_POST['exclude_categories'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude_categories'] ) ) : '';

        if ( ! $this->is_valid_date( $start_date ) || ! $this->is_valid_date( $end_date ) ) {
            return new WP_Error( 'afp_invalid_dates', 'Fechas invalidas. Usa el formato YYYY-MM-DD.' );
        }

        if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
            return new WP_Error( 'afp_date_range', 'La fecha inicio no puede ser mayor que la fecha fin.' );
        }

        $settings = AFP_Settings::get_settings();
        $sales_statuses = $settings['sales_statuses'];
        $pending_statuses = $settings['pending_statuses'];

        if ( isset( $_POST['sales_statuses'] ) ) {
            $sales_statuses = AFP_Settings::sanitize_status_list( wp_unslash( $_POST['sales_statuses'] ), $settings['sales_statuses'] );
        }

        if ( isset( $_POST['pending_statuses'] ) ) {
            $pending_statuses = AFP_Settings::sanitize_status_list( wp_unslash( $_POST['pending_statuses'] ), $settings['pending_statuses'] );
        }

        return array(
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'exclude_ids' => AFP_Reports::parse_exclude_categories( $exclude_raw ),
            'sales_statuses' => $sales_statuses,
            'pending_statuses' => $pending_statuses,
        );
    }

    private function is_valid_date( $date ) {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
    }
}
