<?php

namespace ASDLabs\Finance\Finance;

final class CommerceOrderIndexRepository extends BaseRepository {
	protected $table_key = 'commerce_order_index';

	public function find_by_provider_external( $provider, $external_order_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$provider          = sanitize_key( (string) $provider );
		$external_order_id = absint( $external_order_id );
		if ( '' === $provider || $external_order_id <= 0 ) {
			return null;
		}

		return $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE provider = %s
				AND external_order_id = %d
				LIMIT 1",
				$provider,
				$external_order_id
			),
			ARRAY_A
		);
	}

	public function upsert( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_commerce_index_missing', 'La tabla del indice historico comercial aun no esta disponible.' );
		}

		$provider          = sanitize_key( (string) ( $data['provider'] ?? '' ) );
		$external_order_id = absint( $data['external_order_id'] ?? 0 );
		$fiscal_year       = (int) ( $data['fiscal_year'] ?? 0 );
		$issue_date        = $this->sanitize_date( $data['issue_date'] ?? '' );

		if ( '' === $provider || $external_order_id <= 0 || $fiscal_year <= 0 || empty( $issue_date ) ) {
			return $this->error( 'asdl_fin_commerce_index_required', 'El indice historico necesita proveedor, pedido, ejercicio y fecha validos.' );
		}

		$existing = $this->find_by_provider_external( $provider, $external_order_id );
		$now      = $this->now();

		$historical_resolution_status = sanitize_key(
			(string) (
				$data['historical_resolution_status']
				?? ( $existing['historical_resolution_status'] ?? '' )
			)
		);
		$operationally_collectible    = ! empty( $data['operationally_collectible'] ) ? 1 : 0;
		if ( 'administratively_closed' === $historical_resolution_status ) {
			$operationally_collectible = 0;
		}

		$payload = array(
			'provider'                       => $provider,
			'external_order_id'              => $external_order_id,
			'order_number'                   => sanitize_text_field( (string) ( $data['order_number'] ?? '' ) ),
			'group_key'                      => sanitize_text_field( (string) ( $data['group_key'] ?? '' ) ),
			'contact_id'                     => ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : null,
			'wp_user_id'                     => ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : null,
			'customer_email'                 => sanitize_email( (string) ( $data['customer_email'] ?? '' ) ),
			'display_name'                   => sanitize_text_field( (string) ( $data['display_name'] ?? '' ) ),
			'issue_date'                     => $issue_date,
			'fiscal_year'                    => $fiscal_year,
			'status'                         => sanitize_key( (string) ( $data['status'] ?? '' ) ),
			'currency'                       => strtoupper( sanitize_text_field( (string) ( $data['currency'] ?? '' ) ) ),
			'gross_total'                    => round( (float) ( $data['gross_total'] ?? 0 ), 6 ),
			'paid_total'                     => round( (float) ( $data['paid_total'] ?? 0 ), 6 ),
			'balance'                        => round( (float) ( $data['balance'] ?? 0 ), 6 ),
			'item_count'                     => max( 0, (int) ( $data['item_count'] ?? 0 ) ),
			'is_open'                        => ! empty( $data['is_open'] ) ? 1 : 0,
			'operationally_collectible'      => $operationally_collectible,
			'is_historical_carryforward'     => ! empty( $data['is_historical_carryforward'] ) ? 1 : 0,
			'historical_resolution_status'   => $historical_resolution_status,
			'historical_resolution_note'     => array_key_exists( 'historical_resolution_note', $data ) ? sanitize_textarea_field( (string) ( $data['historical_resolution_note'] ?? '' ) ) : ( $existing['historical_resolution_note'] ?? null ),
			'historical_resolution_batch_id' => array_key_exists( 'historical_resolution_batch_id', $data ) ? ( ! empty( $data['historical_resolution_batch_id'] ) ? absint( $data['historical_resolution_batch_id'] ) : null ) : ( ! empty( $existing['historical_resolution_batch_id'] ) ? absint( $existing['historical_resolution_batch_id'] ) : null ),
			'document_id'                    => ! empty( $data['document_id'] ) ? absint( $data['document_id'] ) : null,
			'source_link_id'                 => ! empty( $data['source_link_id'] ) ? absint( $data['source_link_id'] ) : null,
			'meta_json'                      => array_key_exists( 'meta_json', $data ) ? wp_json_encode( $data['meta_json'] ) : ( $existing['meta_json'] ?? null ),
			'updated_at'                     => $now,
		);

		if ( empty( $payload['group_key'] ) ) {
			$payload['group_key'] = $provider . ':' . $external_order_id;
		}

		$formats = array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%f', '%f', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' );

		if ( ! empty( $existing['id'] ) ) {
			$result = $this->db()->update(
				$this->table(),
				$payload,
				array( 'id' => (int) $existing['id'] ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				return $this->error( 'asdl_fin_commerce_index_update', 'No se pudo actualizar el indice historico del pedido.' );
			}

			return (int) $existing['id'];
		}

		$payload['created_at'] = $now;
		$formats[]             = '%s';

		$result = $this->db()->insert( $this->table(), $payload, $formats );
		if ( false === $result ) {
			return $this->error( 'asdl_fin_commerce_index_insert', 'No se pudo guardar el indice historico del pedido.' );
		}

		return (int) $this->db()->insert_id;
	}

	public function delete_by_provider_external( $provider, $external_order_id ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$result = $this->db()->delete(
			$this->table(),
			array(
				'provider'          => sanitize_key( (string) $provider ),
				'external_order_id' => absint( $external_order_id ),
			),
			array( '%s', '%d' )
		);

		return false !== $result;
	}

	public function delete_for_year( $fiscal_year ) {
		if ( ! $this->has_table() ) {
			return 0;
		}

		$result = $this->db()->delete(
			$this->table(),
			array( 'fiscal_year' => absint( $fiscal_year ) ),
			array( '%d' )
		);

		return false === $result ? 0 : (int) $result;
	}

	public function mark_year_as_carryforward( $fiscal_year ) {
		if ( ! $this->has_table() ) {
			return 0;
		}

		$result = $this->db()->update(
			$this->table(),
			array(
				'is_historical_carryforward' => 1,
				'updated_at'                 => $this->now(),
			),
			array( 'fiscal_year' => absint( $fiscal_year ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false === $result ? 0 : (int) $result;
	}

	public function global_summary() {
		if ( ! $this->has_table() ) {
			return array(
				'total_orders'   => 0,
				'indexed_years'  => 0,
				'oldest_date'    => '',
				'latest_date'    => '',
				'collectible_balance_total' => 0.0,
			);
		}

		$row = $this->db()->get_row(
			"SELECT
				COUNT(*) AS total_orders,
				COUNT(DISTINCT fiscal_year) AS indexed_years,
				MIN(issue_date) AS oldest_date,
				MAX(issue_date) AS latest_date,
				COALESCE(SUM(CASE WHEN operationally_collectible = 1 THEN balance ELSE 0 END), 0) AS collectible_balance_total
			FROM {$this->table()}",
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	public function list_year_stats() {
		if ( ! $this->has_table() ) {
			return array();
		}

		return $this->db()->get_results(
			"SELECT
				fiscal_year,
				COUNT(*) AS order_count,
				COALESCE(SUM(balance), 0) AS balance_total,
				COALESCE(SUM(CASE WHEN operationally_collectible = 1 THEN balance ELSE 0 END), 0) AS collectible_balance_total,
				COALESCE(SUM(CASE WHEN historical_resolution_status = 'administratively_closed' THEN balance ELSE 0 END), 0) AS administratively_closed_balance,
				MIN(issue_date) AS oldest_date,
				MAX(issue_date) AS latest_date,
				MAX(updated_at) AS updated_at
			FROM {$this->table()}
			GROUP BY fiscal_year
			ORDER BY fiscal_year DESC",
			ARRAY_A
		);
	}

	public function summarize_collectible_orders( array $args = array() ) {
		if ( ! $this->has_table() ) {
			return array(
				'pending_total'            => 0.0,
				'order_count'              => 0,
				'group_count'              => 0,
				'linked_profiles'          => 0,
				'unlinked_groups'          => 0,
				'in_range_pending_total'   => 0.0,
				'historical_pending_total' => 0.0,
				'in_range_count'           => 0,
				'historical_count'         => 0,
			);
		}

		$where = $this->build_collectible_where( $args, true );
		$sql   = "SELECT
			COALESCE(SUM(balance), 0) AS pending_total,
			COUNT(*) AS order_count,
			COUNT(DISTINCT group_key) AS group_count,
			COUNT(DISTINCT CASE WHEN contact_id > 0 THEN group_key WHEN wp_user_id > 0 THEN group_key ELSE NULL END) AS linked_profiles,
			COUNT(DISTINCT CASE WHEN contact_id > 0 OR wp_user_id > 0 THEN NULL ELSE group_key END) AS unlinked_groups,
			COALESCE(SUM(CASE WHEN {$where['range_case_sql']} THEN balance ELSE 0 END), 0) AS in_range_pending_total,
			COALESCE(SUM(CASE WHEN {$where['range_case_sql']} THEN 1 ELSE 0 END), 0) AS in_range_count,
			COALESCE(SUM(CASE WHEN {$where['range_case_sql']} THEN 0 ELSE balance END), 0) AS historical_pending_total,
			COALESCE(SUM(CASE WHEN {$where['range_case_sql']} THEN 0 ELSE 1 END), 0) AS historical_count
			FROM {$this->table()}
			WHERE {$where['sql']}";

		return $this->db()->get_row(
			$this->db()->prepare( $sql, $where['params'] ),
			ARRAY_A
		);
	}

	public function list_collectible_orders( array $args = array() ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		$limit = $limit > 0 ? max( 1, min( 5000, $limit ) ) : 0;
		$where = $this->build_collectible_where( $args, true );
		$sql   = "SELECT *
			FROM {$this->table()}
			WHERE {$where['sql']}
			ORDER BY issue_date ASC, id ASC";

		if ( $limit > 0 ) {
			$sql .= $this->db()->prepare( ' LIMIT %d', $limit );
		}

		return $this->db()->get_results(
			$this->db()->prepare( $sql, $where['params'] ),
			ARRAY_A
		);
	}

	public function summarize_resolution_candidates( array $args = array() ) {
		if ( ! $this->has_table() ) {
			return array(
				'item_count' => 0,
				'balance_total' => 0.0,
				'year_count' => 0,
			);
		}

		$where = $this->build_resolution_where( $args, 0 );
		$sql   = "SELECT
			COUNT(*) AS item_count,
			COALESCE(SUM(balance), 0) AS balance_total,
			COUNT(DISTINCT fiscal_year) AS year_count
			FROM {$this->table()}
			WHERE {$where['sql']}";

		return $this->db()->get_row(
			$this->db()->prepare( $sql, $where['params'] ),
			ARRAY_A
		);
	}

	public function list_resolution_candidates( array $args = array(), $cursor_id = 0, $limit = 200 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$limit = max( 1, min( 500, (int) $limit ) );
		$where = $this->build_resolution_where( $args, $cursor_id );
		$sql   = "SELECT *
			FROM {$this->table()}
			WHERE {$where['sql']}
			ORDER BY id ASC
			LIMIT %d";
		$params = array_merge( $where['params'], array( $limit ) );

		return $this->db()->get_results(
			$this->db()->prepare( $sql, $params ),
			ARRAY_A
		);
	}

	public function apply_historical_resolution( array $row_ids, $batch_id, $reason_key, $note ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$row_ids = array_values( array_filter( array_map( 'absint', $row_ids ) ) );
		if ( empty( $row_ids ) ) {
			return true;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $row_ids ), '%d' ) );
		$sql          = "UPDATE {$this->table()}
			SET operationally_collectible = 0,
				historical_resolution_status = %s,
				historical_resolution_note = %s,
				historical_resolution_batch_id = %d,
				meta_json = meta_json,
				updated_at = %s
			WHERE id IN ({$placeholders})";
		$params       = array_merge(
			array(
				'administratively_closed',
				trim( $reason_key . ( '' !== $note ? ' | ' . $note : '' ) ),
				absint( $batch_id ),
				$this->now(),
			),
			$row_ids
		);

		return false !== $this->db()->query( $this->db()->prepare( $sql, $params ) );
	}

	public function diagnostics_for_year( $fiscal_year ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$fiscal_year = absint( $fiscal_year );
		if ( $fiscal_year <= 0 ) {
			return array();
		}

		$sql = "SELECT
			COALESCE(SUM(CASE WHEN contact_id IS NULL OR contact_id = 0 THEN 1 ELSE 0 END), 0) AS missing_contact_count,
			COALESCE(SUM(CASE WHEN source_link_id IS NULL OR source_link_id = 0 THEN 1 ELSE 0 END), 0) AS missing_source_link_count,
			COALESCE(SUM(CASE WHEN document_id IS NULL OR document_id = 0 THEN 1 ELSE 0 END), 0) AS missing_document_count,
			COALESCE(SUM(CASE WHEN customer_email = '' THEN 1 ELSE 0 END), 0) AS missing_email_count
			FROM {$this->table()}
			WHERE fiscal_year = %d";

		return $this->db()->get_row(
			$this->db()->prepare( $sql, $fiscal_year ),
			ARRAY_A
		);
	}

	public function summarize_orders_for_identity( array $args = array() ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$where = $this->build_identity_where( $args, false, false );
		$sql   = "SELECT
			COUNT(*) AS order_count,
			COALESCE(SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END), 0) AS open_order_count,
			COALESCE(SUM(gross_total), 0) AS orders_total,
			COALESCE(SUM(CASE WHEN is_open = 1 THEN balance ELSE 0 END), 0) AS open_order_total,
			COALESCE(SUM(CASE WHEN provider = 'woocommerce' THEN 1 ELSE 0 END), 0) AS web_order_count,
			COALESCE(SUM(CASE WHEN provider = 'openpos' THEN 1 ELSE 0 END), 0) AS pos_order_count,
			COALESCE(SUM(CASE WHEN document_id > 0 THEN 1 ELSE 0 END), 0) AS synced_order_count,
			COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_order_count
			FROM {$this->table()}
			WHERE {$where['sql']}";

		return $this->db()->get_row(
			$this->db()->prepare( $sql, $where['params'] ),
			ARRAY_A
		);
	}

	public function list_orders_for_identity( array $args = array() ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$limit = isset( $args['limit'] ) ? max( 1, min( 5000, (int) $args['limit'] ) ) : 100;
		$where = $this->build_identity_where(
			$args,
			! empty( $args['open_only'] ),
			! empty( $args['collectible_only'] )
		);
		$sql   = "SELECT *
			FROM {$this->table()}
			WHERE {$where['sql']}
			ORDER BY issue_date DESC, id DESC
			LIMIT %d";
		$params = array_merge( $where['params'], array( $limit ) );

		return $this->db()->get_results(
			$this->db()->prepare( $sql, $params ),
			ARRAY_A
		);
	}

	private function build_collectible_where( array $args, $only_collectible ) {
		$where      = array();
		$params     = array();
		$range_from = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to   = $this->sanitize_date( $args['range_to'] ?? '' );
		$source     = sanitize_key( (string) ( $args['source'] ?? 'all' ) );
		$contact_id = absint( $args['contact_id'] ?? 0 );
		$wp_user_id = absint( $args['wp_user_id'] ?? 0 );
		$email      = sanitize_email( (string) ( $args['email'] ?? '' ) );

		$where[] = 'is_open = 1';
		if ( $only_collectible ) {
			$where[] = 'operationally_collectible = 1';
		}

		if ( '' !== $source && 'all' !== $source ) {
			$where[]  = 'provider = %s';
			$params[] = $source;
		}

		if ( $range_from ) {
			$where[]  = 'issue_date >= %s';
			$params[] = $range_from;
		}

		if ( $range_to ) {
			$where[]  = 'issue_date <= %s';
			$params[] = $range_to;
		}

		if ( $contact_id > 0 ) {
			$where[]  = 'contact_id = %d';
			$params[] = $contact_id;
		} elseif ( $wp_user_id > 0 ) {
			$where[]  = 'wp_user_id = %d';
			$params[] = $wp_user_id;
		} elseif ( '' !== $email ) {
			$where[]  = 'customer_email = %s';
			$params[] = $email;
		}

		$range_case_sql = '1=1';
		if ( $range_from && $range_to ) {
			$range_case_sql = $this->db()->prepare( 'issue_date >= %s AND issue_date <= %s', $range_from, $range_to );
		} elseif ( $range_from ) {
			$range_case_sql = $this->db()->prepare( 'issue_date >= %s', $range_from );
		} elseif ( $range_to ) {
			$range_case_sql = $this->db()->prepare( 'issue_date <= %s', $range_to );
		}

		return array(
			'sql'            => implode( ' AND ', $where ),
			'params'         => $params,
			'range_case_sql' => $range_case_sql,
		);
	}

	private function build_resolution_where( array $args, $cursor_id ) {
		$where        = array(
			'is_open = 1',
			'operationally_collectible = 1',
		);
		$params       = array();
		$year_from    = absint( $args['fiscal_year_from'] ?? 0 );
		$year_to      = absint( $args['fiscal_year_to'] ?? 0 );
		$contact_id   = absint( $args['contact_id'] ?? 0 );
		$provider     = sanitize_key( (string) ( $args['provider'] ?? '' ) );
		$search       = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$min_balance  = isset( $args['min_balance'] ) ? (float) $args['min_balance'] : null;
		$max_balance  = isset( $args['max_balance'] ) ? (float) $args['max_balance'] : null;
		$no_payments  = ! empty( $args['only_without_paid'] );
		$selected_ids = array_values( array_filter( array_map( 'absint', isset( $args['selected_row_ids'] ) && is_array( $args['selected_row_ids'] ) ? $args['selected_row_ids'] : array() ) ) );

		if ( $year_from > 0 ) {
			$where[]  = 'fiscal_year >= %d';
			$params[] = $year_from;
		}

		if ( $year_to > 0 ) {
			$where[]  = 'fiscal_year <= %d';
			$params[] = $year_to;
		}

		if ( $contact_id > 0 ) {
			$where[]  = 'contact_id = %d';
			$params[] = $contact_id;
		}

		if ( '' !== $provider && 'all' !== $provider ) {
			$where[]  = 'provider = %s';
			$params[] = $provider;
		}

		if ( '' !== $search ) {
			$like     = '%' . $this->db()->esc_like( $search ) . '%';
			$where[]  = '(order_number LIKE %s OR customer_email LIKE %s OR display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( null !== $min_balance ) {
			$where[]  = 'balance >= %f';
			$params[] = $min_balance;
		}

		if ( null !== $max_balance && $max_balance > 0 ) {
			$where[]  = 'balance <= %f';
			$params[] = $max_balance;
		}

		if ( $no_payments ) {
			$where[] = 'paid_total <= 0.00001';
		}

		if ( ! empty( $selected_ids ) ) {
			$where[] = 'id IN (' . implode( ', ', array_fill( 0, count( $selected_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $selected_ids );
		}

		if ( $cursor_id > 0 ) {
			$where[]  = 'id > %d';
			$params[] = absint( $cursor_id );
		}

		return array(
			'sql'    => implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	private function build_identity_where( array $args, $open_only, $collectible_only ) {
		$where      = array( '1=1' );
		$params     = array();
		$contact_id = absint( $args['contact_id'] ?? 0 );
		$wp_user_id = absint( $args['wp_user_id'] ?? 0 );
		$email      = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$group_key  = sanitize_text_field( (string) ( $args['group_key'] ?? '' ) );
		$range_from = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to   = $this->sanitize_date( $args['range_to'] ?? '' );
		$source     = sanitize_key( (string) ( $args['source'] ?? 'all' ) );

		if ( '' !== $group_key ) {
			$where[]  = 'group_key = %s';
			$params[] = $group_key;
		} elseif ( $contact_id > 0 ) {
			$where[]  = 'contact_id = %d';
			$params[] = $contact_id;
		} elseif ( $wp_user_id > 0 ) {
			$where[]  = 'wp_user_id = %d';
			$params[] = $wp_user_id;
		} elseif ( '' !== $email ) {
			$where[]  = 'customer_email = %s';
			$params[] = $email;
		}

		if ( $open_only ) {
			$where[] = 'is_open = 1';
		}

		if ( $collectible_only ) {
			$where[] = 'operationally_collectible = 1';
		}

		if ( '' !== $source && 'all' !== $source ) {
			$where[]  = 'provider = %s';
			$params[] = $source;
		}

		if ( $range_from ) {
			$where[]  = 'issue_date >= %s';
			$params[] = $range_from;
		}

		if ( $range_to ) {
			$where[]  = 'issue_date <= %s';
			$params[] = $range_to;
		}

		return array(
			'sql'    => implode( ' AND ', $where ),
			'params' => $params,
		);
	}
}
