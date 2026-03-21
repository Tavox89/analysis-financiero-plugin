<?php

namespace ASDLabs\Finance\Finance;

use WP_Error;

final class AccountsRepository extends BaseRepository {
	protected $table_key = 'accounts';

	public function all( $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb = $this->db();
		$limit = max( 1, (int) $limit );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	public function options() {
		$options = array();

		foreach ( $this->all( 200 ) as $account ) {
			$options[ (int) $account['id'] ] = $account['name'];
		}

		return $options;
	}

	public function find( $account_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$account_id = absint( $account_id );
		if ( $account_id <= 0 ) {
			return null;
		}

		return $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$account_id
			),
			ARRAY_A
		);
	}

	public function create( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_accounts_missing', 'La tabla de cuentas aun no esta disponible.' );
		}

		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return $this->error( 'asdl_fin_account_name', 'Debes indicar el nombre de la cuenta.' );
		}

		$currency = $this->sanitize_currency_code(
			$data['currency'] ?? '',
			'USD',
			'asdl_fin_account_currency',
			'Debes seleccionar una moneda valida para la cuenta.'
		);
		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		$wpdb = $this->db();
		$now  = $this->now();

		$result = $wpdb->insert(
			$this->table(),
			array(
				'code'              => sanitize_text_field( $data['code'] ?? '' ),
				'name'              => $name,
				'account_type'      => sanitize_key( $data['account_type'] ?? 'operating' ),
				'currency'          => $currency,
				'status'            => sanitize_key( $data['status'] ?? 'active' ),
				'current_balance'   => 0,
				'year_total'        => 0,
				'lifetime_total'    => 0,
				'debt_total'        => 0,
				'consumption_total' => 0,
				'notes'             => sanitize_textarea_field( $data['notes'] ?? '' ),
				'meta_json'         => null,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_account_insert', 'No se pudo guardar la cuenta.' );
		}

		return (int) $wpdb->insert_id;
	}
}
