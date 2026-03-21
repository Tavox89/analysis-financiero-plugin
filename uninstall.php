<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'afp_settings' );
delete_option( 'asdl_fin_schema_version' );

if ( defined( 'MINUTE_IN_SECONDS' ) ) {
    global $wpdb;
    $like = $wpdb->esc_like( '_transient_afp_report_' ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

    $like_timeout = $wpdb->esc_like( '_transient_timeout_afp_report_' ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );

    $tables = array(
        $wpdb->prefix . 'asdl_fin_accounts',
        $wpdb->prefix . 'asdl_fin_contacts',
        $wpdb->prefix . 'asdl_fin_documents',
        $wpdb->prefix . 'asdl_fin_document_files',
        $wpdb->prefix . 'asdl_fin_source_links',
        $wpdb->prefix . 'asdl_fin_payments',
        $wpdb->prefix . 'asdl_fin_payment_allocations',
        $wpdb->prefix . 'asdl_fin_employee_profiles',
        $wpdb->prefix . 'asdl_fin_employee_advances',
        $wpdb->prefix . 'asdl_fin_payroll_periods',
        $wpdb->prefix . 'asdl_fin_installment_plans',
        $wpdb->prefix . 'asdl_fin_installments',
        $wpdb->prefix . 'asdl_fin_rules',
        $wpdb->prefix . 'asdl_fin_events',
        $wpdb->prefix . 'asdl_fin_commerce_order_index',
        $wpdb->prefix . 'asdl_fin_commerce_contact_rollups',
        $wpdb->prefix . 'asdl_fin_historical_resolution_batches',
        $wpdb->prefix . 'asdl_fin_historical_resolution_items',
        $wpdb->prefix . 'asdl_fin_order_settlement_batches',
        $wpdb->prefix . 'asdl_fin_order_settlement_batch_items',
    );

    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }
}
