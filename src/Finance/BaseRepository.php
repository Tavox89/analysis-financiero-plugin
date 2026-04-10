<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use WP_Error;

abstract class BaseRepository {
	protected $table_key = '';

	protected function db() {
		global $wpdb;

		return $wpdb;
	}

	protected function table() {
		return Tables::name( $this->table_key );
	}

	protected function now() {
		return current_time( 'mysql' );
	}

	protected function has_table() {
		$wpdb      = $this->db();
		$table     = $this->table();
		$sql       = $wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
		$table_row = $wpdb->get_var( $sql );

		return $table === $table_row;
	}

	protected function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	protected function normalize_currency_code( $value, $fallback = '' ) {
		return ( new CurrenciesService() )->normalize_code( $value, $fallback );
	}

	protected function sanitize_currency_code( $value, $fallback = '', $error_code = 'asdl_fin_currency', $error_message = 'Debes seleccionar una moneda valida del catalogo.' ) {
		$currency = $this->normalize_currency_code( $value, $fallback );

		if ( '' === $currency ) {
			return $this->error( $error_code, $error_message );
		}

		return $currency;
	}

	protected function error( $code, $message ) {
		return new WP_Error( $code, $message );
	}

	protected function normalize_money_amount( $amount, $currency = '' ) {
		return MoneyStateService::normalize_amount( $amount, $currency );
	}

	protected function normalize_balance_amount( $amount, $currency = '' ) {
		return MoneyStateService::normalize_balance( $amount, $currency );
	}

	protected function money_balance_is_zero( $amount, $currency = '' ) {
		return MoneyStateService::balance_is_zero( $amount, $currency );
	}

	protected function money_is_paid_like( $paid_total, $balance, $currency = '' ) {
		return MoneyStateService::is_paid_like( $paid_total, $balance, $currency );
	}

	protected function begin_transaction() {
		return false !== $this->db()->query( 'START TRANSACTION' );
	}

	protected function commit_transaction() {
		return false !== $this->db()->query( 'COMMIT' );
	}

	protected function rollback_transaction() {
		return false !== $this->db()->query( 'ROLLBACK' );
	}

	public function exists() {
		return $this->has_table();
	}
}
