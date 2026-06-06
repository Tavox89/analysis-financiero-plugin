<?php

namespace ASDLabs\Finance\Finance;

final class IntegrityCasesRepository extends BaseRepository {
	const STATUS_OPEN     = 'open';
	const STATUS_REVIEWED = 'reviewed';
	const STATUS_IGNORED  = 'ignored';
	const STATUS_RESOLVED = 'resolved';

	const SEVERITY_HIGH   = 'high';
	const SEVERITY_MEDIUM = 'medium';
	const SEVERITY_LOW    = 'low';

	protected $table_key = 'integrity_cases';

	public function find( $case_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$case_id = absint( $case_id );
		if ( $case_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$case_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->normalize_case_row( $row );
	}

	public function find_by_case_key( $case_key ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$case_key = sanitize_text_field( (string) $case_key );
		if ( '' === $case_key ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE case_key = %s
				LIMIT 1",
				$case_key
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->normalize_case_row( $row );
	}

	public function upsert_detected_case( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_integrity_cases_missing', 'La tabla de casos de integridad aun no esta disponible.' );
		}

		$case_key = sanitize_text_field( (string) ( $data['case_key'] ?? '' ) );
		if ( '' === $case_key ) {
			return $this->error( 'asdl_fin_integrity_case_key', 'El caso de integridad necesita una clave estable.' );
		}

		$existing = $this->find_by_case_key( $case_key );
		$now      = $this->now();
		$payload  = $this->build_case_payload( $data, $existing, $now );
		$formats  = array(
			'%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d',
			'%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
		);

		if ( ! empty( $existing['id'] ) ) {
			$result = $this->db()->update(
				$this->table(),
				$payload,
				array( 'id' => (int) $existing['id'] ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				return $this->error( 'asdl_fin_integrity_case_update', 'No se pudo actualizar el caso de integridad.' );
			}

			$case = $this->find( (int) $existing['id'] );
			if ( is_array( $case ) ) {
				$case['persistence_action'] = self::STATUS_RESOLVED === (string) ( $existing['status'] ?? '' ) ? 'reopened' : 'updated';
			}

			return $case;
		}

		$payload['created_at'] = $now;
		$formats[]             = '%s';

		$result = $this->db()->insert( $this->table(), $payload, $formats );
		if ( false === $result ) {
			return $this->error( 'asdl_fin_integrity_case_insert', 'No se pudo registrar el caso de integridad.' );
		}

		$case = $this->find( (int) $this->db()->insert_id );
		if ( is_array( $case ) ) {
			$case['persistence_action'] = 'created';
		}

		return $case;
	}

	public function update_status( $case_id, $status, $actor_user_id = null, $note = '' ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$case = $this->find( $case_id );
		if ( empty( $case['id'] ) ) {
			return false;
		}

		$next_status = $this->sanitize_status( $status );
		if ( '' === $next_status ) {
			return false;
		}

		$actor_user_id = null !== $actor_user_id ? absint( $actor_user_id ) : get_current_user_id();
		$result        = $this->db()->update(
			$this->table(),
			array(
				'status'            => $next_status,
				'status_changed_at' => $this->now(),
				'status_changed_by' => $actor_user_id > 0 ? $actor_user_id : null,
				'status_note'       => '' !== (string) $note ? sanitize_textarea_field( (string) $note ) : null,
				'updated_at'        => $this->now(),
			),
			array( 'id' => (int) $case['id'] ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function touch_last_scanned( $case_id ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$result = $this->db()->update(
			$this->table(),
			array(
				'last_scanned_at' => $this->now(),
				'updated_at'      => $this->now(),
			),
			array( 'id' => absint( $case_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function list_for_admin( array $args = array() ) {
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit   = max( 1, min( 200, (int) ( $args['limit'] ?? 25 ) ) );
		$offset  = ( $page - 1 ) * $limit;
		$filters = $this->normalize_filters( $args );

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

		$where = $this->build_where_sql( $filters );
		$total = (int) (
			empty( $where['params'] )
				? $this->db()->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE {$where['sql']}" )
				: $this->db()->get_var(
					$this->db()->prepare(
						"SELECT COUNT(*) FROM {$this->table()} WHERE {$where['sql']}",
						...$where['params']
					)
				)
		);

		$params = array_merge( $where['params'], array( $limit, $offset ) );
		$rows   = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE {$where['sql']}
				ORDER BY
					CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END ASC,
					CASE status WHEN 'open' THEN 1 WHEN 'reviewed' THEN 2 WHEN 'ignored' THEN 3 ELSE 4 END ASC,
					last_detected_at DESC,
					id DESC
				LIMIT %d OFFSET %d",
				...$params
			),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( $this, 'normalize_case_row' ), $rows ),
			'meta'  => array(
				'count'       => count( $rows ),
				'total'       => $total,
				'page'        => $page,
				'limit'       => $limit,
				'total_pages' => $total > 0 ? (int) ceil( $total / $limit ) : 0,
				'filters'     => $filters,
			),
		);
	}

	public function summarize( array $args = array() ) {
		$filters = $this->normalize_filters( $args );

		if ( ! $this->has_table() ) {
			return array(
				'total_cases'         => 0,
				'open_cases'          => 0,
				'reviewed_cases'      => 0,
				'ignored_cases'       => 0,
				'resolved_cases'      => 0,
				'active_high_cases'   => 0,
				'active_medium_cases' => 0,
				'active_low_cases'    => 0,
				'active_amount_total' => 0.0,
				'last_detected_at'    => '',
			);
		}

		$where = $this->build_where_sql( $filters );
		$sql   = "SELECT
			COUNT(*) AS total_cases,
			COALESCE(SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END), 0) AS open_cases,
			COALESCE(SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END), 0) AS reviewed_cases,
			COALESCE(SUM(CASE WHEN status = 'ignored' THEN 1 ELSE 0 END), 0) AS ignored_cases,
			COALESCE(SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END), 0) AS resolved_cases,
			COALESCE(SUM(CASE WHEN status <> 'resolved' AND severity = 'high' THEN 1 ELSE 0 END), 0) AS active_high_cases,
			COALESCE(SUM(CASE WHEN status <> 'resolved' AND severity = 'medium' THEN 1 ELSE 0 END), 0) AS active_medium_cases,
			COALESCE(SUM(CASE WHEN status <> 'resolved' AND severity = 'low' THEN 1 ELSE 0 END), 0) AS active_low_cases,
			COALESCE(SUM(CASE WHEN status <> 'resolved' THEN amount ELSE 0 END), 0) AS active_amount_total,
			MAX(last_detected_at) AS last_detected_at
			FROM {$this->table()}
			WHERE {$where['sql']}";

		$row = empty( $where['params'] )
			? $this->db()->get_row( $sql, ARRAY_A )
			: $this->db()->get_row( $this->db()->prepare( $sql, ...$where['params'] ), ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	public static function status_options() {
		return array(
			self::STATUS_OPEN     => 'Abierto',
			self::STATUS_REVIEWED => 'Revisado',
			self::STATUS_IGNORED  => 'Ignorado',
			self::STATUS_RESOLVED => 'Resuelto',
		);
	}

	public static function severity_options() {
		return array(
			self::SEVERITY_HIGH   => 'Alta',
			self::SEVERITY_MEDIUM => 'Media',
			self::SEVERITY_LOW    => 'Baja',
		);
	}

	private function build_case_payload( array $data, ?array $existing = null, $now = '' ) {
		$status        = ! empty( $existing['id'] ) ? (string) ( $existing['status'] ?? self::STATUS_OPEN ) : self::STATUS_OPEN;
		$reopened      = ! empty( $existing['id'] ) && self::STATUS_RESOLVED === $status;
		$actor_user_id = get_current_user_id();
		$severity      = $this->sanitize_severity( $data['severity'] ?? self::SEVERITY_MEDIUM );
		$status        = $this->sanitize_status( $status );

		$payload = array(
			'case_key'          => sanitize_text_field( (string) ( $data['case_key'] ?? '' ) ),
			'case_type'         => sanitize_key( (string) ( $data['case_type'] ?? '' ) ),
			'severity'          => '' !== $severity ? $severity : self::SEVERITY_MEDIUM,
			'status'            => $reopened ? self::STATUS_OPEN : ( '' !== $status ? $status : self::STATUS_OPEN ),
			'contact_id'        => ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : null,
			'contact_label'     => sanitize_text_field( (string) ( $data['contact_label'] ?? '' ) ),
			'external_order_id' => ! empty( $data['external_order_id'] ) ? absint( $data['external_order_id'] ) : null,
			'order_number'      => sanitize_text_field( (string) ( $data['order_number'] ?? '' ) ),
			'document_id'       => ! empty( $data['document_id'] ) ? absint( $data['document_id'] ) : null,
			'payment_id'        => ! empty( $data['payment_id'] ) ? absint( $data['payment_id'] ) : null,
			'batch_id'          => ! empty( $data['batch_id'] ) ? absint( $data['batch_id'] ) : null,
			'amount'            => round( max( 0, (float) ( $data['amount'] ?? 0 ) ), 6 ),
			'currency'          => strtoupper( sanitize_text_field( (string) ( $data['currency'] ?? '' ) ) ),
			'summary'           => sanitize_text_field( (string) ( $data['summary'] ?? '' ) ),
			'search_index'      => $this->build_case_search_index(
				array(
					'id'                => $existing['id'] ?? '',
					'case_key'          => $data['case_key'] ?? '',
					'case_type'         => $data['case_type'] ?? '',
					'contact_label'     => $data['contact_label'] ?? '',
					'order_number'      => $data['order_number'] ?? '',
					'summary'           => $data['summary'] ?? '',
					'batch_id'          => $data['batch_id'] ?? '',
					'external_order_id' => $data['external_order_id'] ?? '',
					'document_id'       => $data['document_id'] ?? '',
					'payment_id'        => $data['payment_id'] ?? '',
				)
			),
			'payload_json'      => ! empty( $data['payload'] ) ? wp_json_encode( $data['payload'] ) : null,
			'first_detected_at' => ! empty( $existing['first_detected_at'] ) ? sanitize_text_field( (string) $existing['first_detected_at'] ) : $now,
			'last_detected_at'  => $now,
			'last_scanned_at'   => $now,
			'status_changed_at' => $reopened ? $now : ( $existing['status_changed_at'] ?? null ),
			'status_changed_by' => $reopened && $actor_user_id > 0 ? $actor_user_id : ( ! empty( $existing['status_changed_by'] ) ? absint( $existing['status_changed_by'] ) : null ),
			'status_note'       => $reopened ? 'Reabierto automaticamente al volver a detectarse.' : ( $existing['status_note'] ?? null ),
			'updated_at'        => $now,
		);

		return $payload;
	}

	private function build_where_sql( array $filters ) {
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'case_type', 'severity', 'status' ) as $key ) {
			if ( '' === (string) ( $filters[ $key ] ?? '' ) ) {
				continue;
			}

			$where[]  = "{$key} = %s";
			$params[] = $filters[ $key ];
		}

		foreach ( array( 'contact_id', 'external_order_id', 'document_id', 'payment_id', 'batch_id' ) as $key ) {
			if ( empty( $filters[ $key ] ) ) {
				continue;
			}

			$where[]  = "{$key} = %d";
			$params[] = (int) $filters[ $key ];
		}

		if ( '' !== (string) ( $filters['contact_search'] ?? '' ) ) {
			$contact_sql = $this->build_token_search_clause( $filters['contact_search'], array( 'search_index' ), $params );
			if ( '' !== $contact_sql ) {
				$where[] = '(' . $contact_sql . ')';
			}
		}

		if ( '' !== (string) ( $filters['search'] ?? '' ) ) {
			$search_sql = $this->build_token_search_clause( $filters['search'], array( 'search_index' ), $params );
			if ( '' !== $search_sql ) {
				$where[] = '(' . $search_sql . ')';
			}
		}

		if ( ! empty( $filters['range_from'] ) ) {
			$where[]  = 'DATE(last_detected_at) >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[]  = 'DATE(last_detected_at) <= %s';
			$params[] = $filters['range_to'];
		}

		return array(
			'sql'    => implode( ' AND ', $where ),
			'params' => $params,
		);
	}

	private function normalize_filters( array $args ) {
		return array(
			'search'            => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'contact_search'    => sanitize_text_field( (string) ( $args['contact_search'] ?? '' ) ),
			'case_type'         => sanitize_key( (string) ( $args['case_type'] ?? '' ) ),
			'severity'          => $this->sanitize_severity( $args['severity'] ?? '' ),
			'status'            => $this->sanitize_status( $args['status'] ?? '' ),
			'contact_id'        => absint( $args['contact_id'] ?? 0 ),
			'external_order_id' => absint( $args['external_order_id'] ?? $args['order_id'] ?? 0 ),
			'document_id'       => absint( $args['document_id'] ?? 0 ),
			'payment_id'        => absint( $args['payment_id'] ?? 0 ),
			'batch_id'          => absint( $args['batch_id'] ?? 0 ),
			'range_from'        => $this->sanitize_date( $args['range_from'] ?? '' ),
			'range_to'          => $this->sanitize_date( $args['range_to'] ?? '' ),
		);
	}

	private function normalize_case_row( array $row ) {
		return array(
			'id'                => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'case_key'          => sanitize_text_field( (string) ( $row['case_key'] ?? '' ) ),
			'case_type'         => sanitize_key( (string) ( $row['case_type'] ?? '' ) ),
			'severity'          => '' !== $this->sanitize_severity( $row['severity'] ?? self::SEVERITY_MEDIUM ) ? $this->sanitize_severity( $row['severity'] ?? self::SEVERITY_MEDIUM ) : self::SEVERITY_MEDIUM,
			'status'            => '' !== $this->sanitize_status( $row['status'] ?? self::STATUS_OPEN ) ? $this->sanitize_status( $row['status'] ?? self::STATUS_OPEN ) : self::STATUS_OPEN,
			'contact_id'        => ! empty( $row['contact_id'] ) ? (int) $row['contact_id'] : 0,
			'contact_label'     => sanitize_text_field( (string) ( $row['contact_label'] ?? '' ) ),
			'external_order_id' => ! empty( $row['external_order_id'] ) ? (int) $row['external_order_id'] : 0,
			'order_number'      => sanitize_text_field( (string) ( $row['order_number'] ?? '' ) ),
			'document_id'       => ! empty( $row['document_id'] ) ? (int) $row['document_id'] : 0,
			'payment_id'        => ! empty( $row['payment_id'] ) ? (int) $row['payment_id'] : 0,
			'batch_id'          => ! empty( $row['batch_id'] ) ? (int) $row['batch_id'] : 0,
			'amount'            => isset( $row['amount'] ) ? (float) $row['amount'] : 0.0,
			'currency'          => sanitize_text_field( (string) ( $row['currency'] ?? '' ) ),
			'summary'           => sanitize_text_field( (string) ( $row['summary'] ?? '' ) ),
			'payload'           => $this->decode_payload( $row['payload_json'] ?? '' ),
			'first_detected_at' => sanitize_text_field( (string) ( $row['first_detected_at'] ?? '' ) ),
			'last_detected_at'  => sanitize_text_field( (string) ( $row['last_detected_at'] ?? '' ) ),
			'last_scanned_at'   => sanitize_text_field( (string) ( $row['last_scanned_at'] ?? '' ) ),
			'status_changed_at' => sanitize_text_field( (string) ( $row['status_changed_at'] ?? '' ) ),
			'status_changed_by' => ! empty( $row['status_changed_by'] ) ? (int) $row['status_changed_by'] : 0,
			'status_note'       => sanitize_textarea_field( (string) ( $row['status_note'] ?? '' ) ),
		);
	}

	private function decode_payload( $payload_json ) {
		$payload = json_decode( (string) $payload_json, true );

		return is_array( $payload ) ? $payload : array();
	}

	private function build_case_search_index( array $row ) {
		return $this->build_search_index(
			array(
				$row['id'] ?? '',
				$row['case_key'] ?? '',
				$row['case_type'] ?? '',
				$row['contact_label'] ?? '',
				$row['order_number'] ?? '',
				$row['summary'] ?? '',
				$row['batch_id'] ?? '',
				$row['external_order_id'] ?? '',
				$row['document_id'] ?? '',
				$row['payment_id'] ?? '',
			)
		);
	}

	private function sanitize_status( $status ) {
		$status = sanitize_key( (string) $status );

		return array_key_exists( $status, self::status_options() ) ? $status : '';
	}

	private function sanitize_severity( $severity ) {
		$severity = sanitize_key( (string) $severity );

		return array_key_exists( $severity, self::severity_options() ) ? $severity : '';
	}
}
