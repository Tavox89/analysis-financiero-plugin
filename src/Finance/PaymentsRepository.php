<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class PaymentsRepository extends BaseRepository {
	protected $table_key = 'payments';

	public function all( $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb  = $this->db();
		$limit = max( 1, (int) $limit );
		$contacts_table = Tables::name( 'contacts' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, c.display_name AS contact_display_name, c.email AS contact_email
				FROM {$this->table()} p
				LEFT JOIN {$contacts_table} c ON c.id = p.contact_id
				ORDER BY p.id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_payment_row' ), $rows );
	}

	public function list_for_api( array $args = array() ) {
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit   = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset  = ( $page - 1 ) * $limit;
		$search  = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$filters = array(
			'search'        => $search,
			'contact_id'    => absint( $args['contact_id'] ?? 0 ),
			'payment_type'  => sanitize_key( (string) ( $args['payment_type'] ?? '' ) ),
			'status'        => sanitize_key( (string) ( $args['status'] ?? '' ) ),
			'method_key'    => sanitize_key( (string) ( $args['method_key'] ?? '' ) ),
			'available_only'=> $this->normalize_bool_filter( $args['available_only'] ?? null ),
			'range_from'    => $this->sanitize_date( $args['range_from'] ?? '' ),
			'range_to'      => $this->sanitize_date( $args['range_to'] ?? '' ),
		);

		if ( ! $this->has_table() ) {
			return array(
				'items' => array(),
				'meta'  => array(
					'count'       => 0,
					'total'       => 0,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => 0,
					'filters'     => $filters,
				),
			);
		}

		$wpdb           = $this->db();
		$contacts_table = Tables::name( 'contacts' );
		$where          = array( '1=1' );
		$params         = array();

		if ( '' !== $search ) {
			$search_sql = $this->build_token_search_clause( $search, array( 'p.search_index', 'c.search_index' ), $params );
			if ( '' !== $search_sql ) {
				$where[] = '(' . $search_sql . ')';
			}
		}

		foreach ( array( 'payment_type', 'status', 'method_key' ) as $filter_key ) {
			if ( '' === $filters[ $filter_key ] ) {
				continue;
			}

			$where[] = 'p.' . $filter_key . ' = %s';
			$params[] = $filters[ $filter_key ];
		}

		if ( $filters['contact_id'] > 0 ) {
			$where[] = 'p.contact_id = %d';
			$params[] = $filters['contact_id'];
		}

		if ( null !== $filters['available_only'] && 1 === $filters['available_only'] ) {
			$where[] = "p.status = 'posted'";
			$where[] = 'p.available_amount > 0';
			$where[] = "COALESCE(p.method_key, '') NOT IN ('salary_advance', 'dual_price_discount', 'internal_compensation', 'extraordinary_profile_closure')";
		}

		if ( ! empty( $filters['range_from'] ) ) {
			$where[] = 'p.payment_date >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[] = 'p.payment_date <= %s';
			$params[] = $filters['range_to'];
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$this->table()} p LEFT JOIN {$contacts_table} c ON c.id = p.contact_id WHERE {$where_sql}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, c.display_name AS contact_display_name, c.email AS contact_email, c.profile_origin AS contact_profile_origin, c.wp_user_id AS contact_wp_user_id
				FROM {$this->table()} p
				LEFT JOIN {$contacts_table} c ON c.id = p.contact_id
				WHERE {$where_sql}
				ORDER BY p.id DESC
				LIMIT %d OFFSET %d",
				...array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		$items     = array_map( array( $this, 'map_payment_for_api' ), $rows );

		return array(
			'items' => $items,
			'meta'  => array(
				'count'       => count( $items ),
				'total'       => $total,
				'page'        => $page,
				'limit'       => $limit,
				'total_pages' => $total > 0 ? (int) ceil( $total / $limit ) : 0,
				'filters'     => $filters,
			),
		);
	}

	public function find( $payment_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$payment_id = absint( $payment_id );
		if ( $payment_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$payment_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->normalize_payment_row( $row );
	}

	public function for_contact( $contact_id, $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$contact_id = absint( $contact_id );
		$limit      = max( 1, (int) $limit );
		$wpdb       = $this->db();
		$contacts_table = Tables::name( 'contacts' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, c.display_name AS contact_display_name, c.email AS contact_email
				FROM {$this->table()} p
				LEFT JOIN {$contacts_table} c ON c.id = p.contact_id
				WHERE p.contact_id = %d
				ORDER BY p.id DESC
				LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_payment_row' ), $rows );
	}

	public function available_options( $contact_id = 0, $limit = 200 ) {
		$options  = array();
		$payments = $contact_id > 0 ? $this->for_contact_available( $contact_id, $limit ) : $this->all_available( $limit );

		foreach ( $payments as $payment ) {
			$options[ (int) $payment['id'] ] = sprintf(
				'%s - %s (%s)',
				$payment['payment_number'],
				$this->label_for_type( $payment['payment_type'] ),
				number_format_i18n( (float) $payment['available_amount'], 2 )
			);
		}

		return $options;
	}

	public function set_available_amount( $payment_id, $available_amount ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$payment = $this->find( $payment_id );
		if ( empty( $payment['id'] ) ) {
			return false;
		}

		$next_amount = max( 0, (float) $available_amount );
		if ( 'salary_advance' === sanitize_key( (string) ( $payment['method_key'] ?? '' ) ) ) {
			$next_amount = 0;
		}

		$result = $this->db()->update(
			$this->table(),
			array(
				'available_amount' => $next_amount,
				'updated_at'       => $this->now(),
			),
			array( 'id' => absint( $payment_id ) ),
			array( '%f', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function set_status( $payment_id, $status, array $meta_updates = array(), $available_amount = null ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$payment = $this->find( $payment_id );
		if ( empty( $payment['id'] ) ) {
			return false;
		}

		$meta = json_decode( (string) ( $payment['meta_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();

		foreach ( $meta_updates as $meta_key => $meta_value ) {
			$meta[ sanitize_key( (string) $meta_key ) ] = $meta_value;
		}

		$payload = array(
			'status'     => sanitize_key( (string) $status ),
			'meta_json'  => wp_json_encode( $meta ),
			'updated_at' => $this->now(),
		);
		$formats = array( '%s', '%s', '%s' );

		if ( null !== $available_amount ) {
			$next_amount = max( 0, (float) $available_amount );
			if ( 'salary_advance' === sanitize_key( (string) ( $payment['method_key'] ?? '' ) ) ) {
				$next_amount = 0;
			}

			$payload['available_amount'] = $next_amount;
			$formats[]                   = '%f';
		}

		$result = $this->db()->update(
			$this->table(),
			$payload,
			array( 'id' => absint( $payment_id ) ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	public function create( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_payments_missing', 'La tabla de pagos aun no esta disponible.' );
		}

		$total = max( 0, (float) ( $data['total'] ?? 0 ) );
		if ( $total <= 0 ) {
			return $this->error( 'asdl_fin_payment_total', 'Debes indicar un monto valido para el pago o abono.' );
		}

		$method_key = sanitize_key( $data['method_key'] ?? '' );
		if ( '' === $method_key ) {
			return $this->error( 'asdl_fin_payment_method_required', 'Debes seleccionar un metodo de pago valido del catalogo.' );
		}

		if ( ! ( new PaymentMethodsService() )->is_valid_key( $method_key ) ) {
			return $this->error( 'asdl_fin_payment_method', 'Debes seleccionar un metodo de pago valido del catalogo.' );
		}

		$currency = $this->sanitize_currency_code(
			$data['currency'] ?? '',
			'USD',
			'asdl_fin_payment_currency',
			'Debes seleccionar una moneda valida para el pago.'
		);
		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		$wpdb = $this->db();
		$now  = $this->now();
		$available_amount = 'salary_advance' === $method_key ? 0 : $total;
		$payment_number   = sprintf( 'PAY-%s-%04d', gmdate( 'YmdHis' ), wp_rand( 0, 9999 ) );
		$reference        = sanitize_text_field( $data['reference'] ?? '' );

		$result = $wpdb->insert(
			$this->table(),
			array(
				'payment_number'   => $payment_number,
				'payment_type'     => sanitize_key( $data['payment_type'] ?? 'collection' ),
				'account_id'       => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null,
				'contact_id'       => ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : null,
				'status'           => sanitize_key( $data['status'] ?? 'posted' ),
				'payment_date'     => $this->sanitize_date( $data['payment_date'] ?? '' ),
				'currency'         => $currency,
				'total'            => $total,
				'available_amount' => $available_amount,
				'method_key'       => $method_key,
				'reference'        => $reference,
				'search_index'     => $this->build_payment_search_index(
					array(
						'payment_number' => $payment_number,
						'reference'      => $reference,
					)
				),
				'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
				'meta_json'        => $this->sanitize_meta_json( $data['meta'] ?? ( $data['meta_json'] ?? null ) ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_payment_insert', 'No se pudo guardar el pago.' );
		}

		return (int) $wpdb->insert_id;
	}

	private function sanitize_meta_json( $meta ) {
		if ( is_string( $meta ) ) {
			$decoded = json_decode( $meta, true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return null;
		}

		return wp_json_encode( $meta );
	}

	private function build_payment_search_index( array $row ) {
		return $this->build_search_index(
			array(
				$row['id'] ?? '',
				$row['payment_number'] ?? '',
				$row['reference'] ?? '',
			)
		);
	}

	private function for_contact_available( $contact_id, $limit = 200 ) {
		$wpdb  = $this->db();
		$limit = max( 1, (int) $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE contact_id = %d
				AND status = 'posted'
				AND available_amount > 0
				AND COALESCE(method_key, '') NOT IN ('salary_advance', 'dual_price_discount', 'internal_compensation', 'extraordinary_profile_closure')
				ORDER BY id DESC
				LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_payment_row' ), $rows );
	}

	private function all_available( $limit = 200 ) {
		$wpdb  = $this->db();
		$limit = max( 1, (int) $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE status = 'posted'
				AND available_amount > 0
				AND COALESCE(method_key, '') NOT IN ('salary_advance', 'dual_price_discount', 'internal_compensation', 'extraordinary_profile_closure')
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_payment_row' ), $rows );
	}

	private function label_for_type( $payment_type ) {
		switch ( sanitize_key( (string) $payment_type ) ) {
			case 'disbursement':
				return 'Pago';
			case 'adjustment':
				return 'Ajuste';
			case 'collection':
			default:
				return 'Cobro';
		}
	}

	private function map_payment_for_api( array $row ) {
		$row        = $this->normalize_payment_row( $row );
		$method_key = sanitize_key( (string) ( $row['method_key'] ?? '' ) );

		$row['contact'] = ! empty( $row['contact_id'] ) ? array(
			'id'             => (int) $row['contact_id'],
			'display_name'   => sanitize_text_field( (string) ( $row['contact_display_name'] ?? '' ) ),
			'email'          => sanitize_email( (string) ( $row['contact_email'] ?? '' ) ),
			'profile_origin' => sanitize_key( (string) ( $row['contact_profile_origin'] ?? '' ) ),
			'wp_user_id'     => ! empty( $row['contact_wp_user_id'] ) ? (int) $row['contact_wp_user_id'] : 0,
		) : null;
		$row['has_available_amount'] = (float) ( $row['available_amount'] ?? 0 ) > 0;
		$row['is_salary_advance']    = 'salary_advance' === $method_key;
		$row['credit_eligible']      = CreditEligibilityService::is_usable_payment( $row );

		unset( $row['contact_display_name'], $row['contact_email'], $row['contact_profile_origin'], $row['contact_wp_user_id'] );

		return $row;
	}

	private function normalize_payment_row( array $row ) {
		$row['id']               = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['account_id']       = ! empty( $row['account_id'] ) ? (int) $row['account_id'] : 0;
		$row['contact_id']       = ! empty( $row['contact_id'] ) ? (int) $row['contact_id'] : 0;
		$row['total']            = isset( $row['total'] ) ? (float) $row['total'] : 0;
		$row['available_amount'] = isset( $row['available_amount'] ) ? (float) $row['available_amount'] : 0;
		$row['currency']         = $this->normalize_currency_code( $row['currency'] ?? '', 'USD' );

		if ( 'salary_advance' === sanitize_key( (string) ( $row['method_key'] ?? '' ) ) ) {
			$row['available_amount'] = 0;
		}

		return $row;
	}

	private function normalize_bool_filter( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		$normalized = strtolower( sanitize_text_field( (string) $value ) );
		if ( in_array( $normalized, array( '1', 'true', 'yes' ), true ) ) {
			return 1;
		}

		if ( in_array( $normalized, array( '0', 'false', 'no' ), true ) ) {
			return 0;
		}

		return null;
	}
}
