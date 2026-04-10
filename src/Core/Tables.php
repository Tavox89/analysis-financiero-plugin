<?php

namespace ASDLabs\Finance\Core;

final class Tables {
	private static $map = array(
		'accounts'             => 'asdl_fin_accounts',
		'contacts'             => 'asdl_fin_contacts',
		'documents'            => 'asdl_fin_documents',
		'document_files'       => 'asdl_fin_document_files',
		'source_links'         => 'asdl_fin_source_links',
		'payments'             => 'asdl_fin_payments',
		'payment_allocations'  => 'asdl_fin_payment_allocations',
		'employee_profiles'    => 'asdl_fin_employee_profiles',
		'employee_advances'    => 'asdl_fin_employee_advances',
		'payroll_periods'      => 'asdl_fin_payroll_periods',
		'service_profiles'     => 'asdl_fin_service_profiles',
		'installment_plans'    => 'asdl_fin_installment_plans',
		'installments'         => 'asdl_fin_installments',
		'commerce_order_index' => 'asdl_fin_commerce_order_index',
		'commerce_contact_rollups' => 'asdl_fin_commerce_contact_rollups',
		'historical_resolution_batches' => 'asdl_fin_historical_resolution_batches',
		'historical_resolution_items' => 'asdl_fin_historical_resolution_items',
		'order_settlement_batches' => 'asdl_fin_order_settlement_batches',
		'order_settlement_batch_items' => 'asdl_fin_order_settlement_batch_items',
		'order_assumption_batches' => 'asdl_fin_order_assumption_batches',
		'order_assumption_batch_items' => 'asdl_fin_order_assumption_batch_items',
		'integrity_cases'      => 'asdl_fin_integrity_cases',
		'rules'                => 'asdl_fin_rules',
		'events'               => 'asdl_fin_events',
		'mobile_sessions'      => 'asdl_fin_mobile_sessions',
		'report_snapshots'     => 'asdl_fin_report_snapshots',
	);

	public static function name( $logical_name ) {
		global $wpdb;

		if ( ! isset( self::$map[ $logical_name ] ) ) {
			return $wpdb->prefix . 'asdl_fin_' . sanitize_key( $logical_name );
		}

		return $wpdb->prefix . self::$map[ $logical_name ];
	}

	public static function all() {
		$tables = array();

		foreach ( array_keys( self::$map ) as $logical_name ) {
			$tables[ $logical_name ] = self::name( $logical_name );
		}

		return $tables;
	}
}
