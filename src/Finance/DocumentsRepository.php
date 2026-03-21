<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class DocumentsRepository extends BaseRepository {
	protected $table_key = 'documents';

	public function all( $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb  = $this->db();
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

	public function list_admin( array $args = array() ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb               = $this->db();
		$contacts_table     = Tables::name( 'contacts' );
		$source_links_table = Tables::name( 'source_links' );
		$limit              = max( 1, (int) ( $args['limit'] ?? 50 ) );
		$contact_id         = absint( $args['contact_id'] ?? 0 );
		$document_type      = sanitize_key( (string) ( $args['document_type'] ?? '' ) );
		$open_only          = ! empty( $args['open_only'] );
		$financial_status   = sanitize_key( (string) ( $args['financial_status'] ?? '' ) );
		$exclude_types      = array();
		$where              = array( '1=1' );
		$params             = array();
		$last_payment_sql   = $this->last_payment_date_subquery( 'd.id' );

		foreach ( (array) ( $args['exclude_document_types'] ?? array() ) as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' === $type ) {
				continue;
			}

			$exclude_types[] = $type;
		}

		if ( $contact_id > 0 ) {
			$where[]  = 'd.contact_id = %d';
			$params[] = $contact_id;
		}

		if ( '' !== $document_type ) {
			$where[]  = 'd.document_type = %s';
			$params[] = $document_type;
		}

		if ( ! empty( $exclude_types ) ) {
			$where[] = 'd.document_type NOT IN (' . implode( ', ', array_fill( 0, count( $exclude_types ), '%s' ) ) . ')';
			$params  = array_merge( $params, $exclude_types );
		}

		if ( $open_only ) {
			$where[] = 'd.balance > 0';
		}

		if ( '' !== $financial_status ) {
			$where[]  = 'd.financial_status = %s';
			$params[] = $financial_status;
		}

		$where_sql = implode( ' AND ', $where );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, c.display_name AS contact_display_name, c.email AS contact_email,
					sl.provider AS linked_provider, sl.object_type AS linked_object_type,
					sl.external_id AS linked_external_id, sl.external_ref AS linked_external_ref,
					{$last_payment_sql} AS last_payment_date
				FROM {$this->table()} d
				LEFT JOIN {$contacts_table} c ON c.id = d.contact_id
				LEFT JOIN {$source_links_table} sl ON sl.id = (
					SELECT sl2.id
					FROM {$source_links_table} sl2
					WHERE sl2.document_id = d.id
					ORDER BY sl2.id DESC
					LIMIT 1
				)
				WHERE {$where_sql}
				ORDER BY COALESCE(d.issue_date, DATE(d.created_at)) DESC, d.id DESC
				LIMIT %d",
				...array_merge( $params, array( $limit ) )
			),
			ARRAY_A
		);
	}

	public function list_for_api( array $args = array() ) {
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit     = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset    = ( $page - 1 ) * $limit;
		$search    = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$filters   = array(
			'search'           => $search,
			'contact_id'       => absint( $args['contact_id'] ?? 0 ),
			'wp_user_id'       => absint( $args['wp_user_id'] ?? 0 ),
			'document_type'    => sanitize_key( (string) ( $args['document_type'] ?? '' ) ),
			'source_type'      => sanitize_key( (string) ( $args['source_type'] ?? '' ) ),
			'payment_status'   => sanitize_key( (string) ( $args['payment_status'] ?? '' ) ),
			'balance_nature'   => sanitize_key( (string) ( $args['balance_nature'] ?? '' ) ),
			'financial_status' => sanitize_key( (string) ( $args['financial_status'] ?? '' ) ),
			'open_only'        => $this->normalize_bool_filter( $args['open_only'] ?? null ),
			'range_from'       => $this->sanitize_date( $args['range_from'] ?? '' ),
			'range_to'         => $this->sanitize_date( $args['range_to'] ?? '' ),
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
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(d.document_number LIKE %s OR d.title LIKE %s OR d.external_reference LIKE %s OR c.display_name LIKE %s OR c.email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		foreach ( array( 'document_type', 'source_type', 'payment_status', 'balance_nature', 'financial_status' ) as $filter_key ) {
			if ( '' === $filters[ $filter_key ] ) {
				continue;
			}

			$where[] = 'd.' . $filter_key . ' = %s';
			$params[] = $filters[ $filter_key ];
		}

		if ( $filters['contact_id'] > 0 ) {
			$where[] = 'd.contact_id = %d';
			$params[] = $filters['contact_id'];
		}

		if ( $filters['wp_user_id'] > 0 ) {
			$where[] = 'd.wp_user_id = %d';
			$params[] = $filters['wp_user_id'];
		}

		if ( null !== $filters['open_only'] && 1 === $filters['open_only'] ) {
			$where[] = 'd.balance > 0';
		}

		if ( ! empty( $filters['range_from'] ) ) {
			$where[] = 'd.issue_date >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[] = 'd.issue_date <= %s';
			$params[] = $filters['range_to'];
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$this->table()} d LEFT JOIN {$contacts_table} c ON c.id = d.contact_id WHERE {$where_sql}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, c.display_name AS contact_display_name, c.email AS contact_email, c.profile_origin AS contact_profile_origin, c.wp_user_id AS contact_wp_user_id
				FROM {$this->table()} d
				LEFT JOIN {$contacts_table} c ON c.id = d.contact_id
				WHERE {$where_sql}
				ORDER BY d.id DESC
				LIMIT %d OFFSET %d",
				...array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		$items     = array_map( array( $this, 'map_document_for_api' ), $rows );

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

	public function find( $document_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$document_id = absint( $document_id );
		if ( $document_id <= 0 ) {
			return null;
		}

		return $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$document_id
			),
			ARRAY_A
		);
	}

	public function for_contact( $contact_id, $limit = 50, $open_only = false ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$contact_id       = absint( $contact_id );
		$limit            = max( 1, (int) $limit );
		$wpdb             = $this->db();
		$contacts_table   = Tables::name( 'contacts' );
		$source_links_table = Tables::name( 'source_links' );
		$where_sql        = 'WHERE d.contact_id = %d';
		$last_payment_sql = $this->last_payment_date_subquery( 'd.id' );

		if ( $open_only ) {
			$where_sql .= ' AND d.balance > 0';
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, c.display_name AS contact_display_name, c.email AS contact_email,
					sl.provider AS linked_provider, sl.object_type AS linked_object_type,
					sl.external_id AS linked_external_id, sl.external_ref AS linked_external_ref,
					{$last_payment_sql} AS last_payment_date
				FROM {$this->table()} d
				LEFT JOIN {$contacts_table} c ON c.id = d.contact_id
				LEFT JOIN {$source_links_table} sl ON sl.id = (
					SELECT sl2.id
					FROM {$source_links_table} sl2
					WHERE sl2.document_id = d.id
					ORDER BY sl2.id DESC
					LIMIT 1
				)
				{$where_sql}
				ORDER BY COALESCE(d.issue_date, DATE(d.created_at)) DESC, d.id DESC
				LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);
	}

	private function last_payment_date_subquery( $document_ref ) {
		$payments_table            = Tables::name( 'payments' );
		$payment_allocations_table = Tables::name( 'payment_allocations' );

		return "(SELECT MAX(COALESCE(p.payment_date, DATE(a.created_at)))
			FROM {$payment_allocations_table} a
			LEFT JOIN {$payments_table} p ON p.id = a.payment_id
			WHERE a.document_id = {$document_ref})";
	}

	public function open_options( $contact_id = 0, $limit = 200 ) {
		$options   = array();
		$documents = $contact_id > 0 ? $this->for_contact( $contact_id, $limit, true ) : $this->all_open( $limit );

		foreach ( $documents as $document ) {
			$options[ (int) $document['id'] ] = sprintf(
				'%s - %s (%s)',
				$document['document_number'] ?: 'DOC',
				$document['title'],
				number_format_i18n( (float) $document['balance'], 2 )
			);
		}

		return $options;
	}

	public function set_payment_progress( $document_id, $paid_total, $balance, $payment_status ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$result = $this->db()->update(
			$this->table(),
			array(
				'paid_total'     => (float) $paid_total,
				'balance'        => (float) $balance,
				'payment_status' => sanitize_key( $payment_status ),
				'updated_at'     => $this->now(),
			),
			array( 'id' => absint( $document_id ) ),
			array( '%f', '%f', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function set_financial_status( $document_id, $financial_status, array $changes = array(), array $meta_updates = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$document = $this->find( $document_id );
		if ( empty( $document['id'] ) ) {
			return false;
		}

		$meta = json_decode( (string) ( $document['meta_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();

		foreach ( $meta_updates as $meta_key => $meta_value ) {
			$meta[ sanitize_key( (string) $meta_key ) ] = $meta_value;
		}

		$payload = array(
			'financial_status' => sanitize_key( (string) $financial_status ),
			'meta_json'        => wp_json_encode( $meta ),
			'updated_at'       => $this->now(),
		);
		$formats = array( '%s', '%s', '%s' );

		foreach ( $changes as $field => $value ) {
			switch ( $field ) {
				case 'paid_total':
				case 'balance':
					$payload[ $field ] = (float) $value;
					$formats[]         = '%f';
					break;
				case 'payment_status':
					$payload[ $field ] = sanitize_key( (string) $value );
					$formats[]         = '%s';
					break;
			}
		}

		$result = $this->db()->update(
			$this->table(),
			$payload,
			array( 'id' => absint( $document_id ) ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	public function apply_order_assumption_state( $document_id, array $changes, array $trace = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$document = $this->find( $document_id );
		if ( empty( $document['id'] ) ) {
			return false;
		}

		$meta                         = $this->decode_meta_json( $document['meta_json'] ?? '' );
		$meta['order_assumption']     = $trace;
		$meta['order_assumption']['active'] = 1;

		$payload = array(
			'financial_intent' => sanitize_key( (string) ( $changes['financial_intent'] ?? 'internal_consumption' ) ),
			'balance_nature'   => sanitize_key( (string) ( $changes['balance_nature'] ?? 'neutral' ) ),
			'financial_status' => sanitize_key( (string) ( $changes['financial_status'] ?? 'posted' ) ),
			'payment_status'   => sanitize_key( (string) ( $changes['payment_status'] ?? 'paid' ) ),
			'paid_total'       => round( (float) ( $changes['paid_total'] ?? $document['total'] ?? 0 ), 6 ),
			'balance'          => round( (float) ( $changes['balance'] ?? 0 ), 6 ),
			'manual_override'  => array_key_exists( 'manual_override', $changes ) ? ( ! empty( $changes['manual_override'] ) ? 1 : 0 ) : 1,
			'category_key'     => sanitize_key( (string) ( $changes['category_key'] ?? ( $document['category_key'] ?? '' ) ) ),
			'subcategory_key'  => sanitize_key( (string) ( $changes['subcategory_key'] ?? '' ) ),
			'notes'            => sanitize_textarea_field( (string) ( $changes['notes'] ?? ( $document['notes'] ?? '' ) ) ),
			'meta_json'        => $this->encode_meta_json( $meta ),
			'updated_at'       => $this->now(),
		);

		if ( 'posted' === $payload['financial_status'] && empty( $document['posted_at'] ) ) {
			$payload['posted_at'] = $this->now();
		}

		return $this->persist_update( $document_id, $payload );
	}

	public function restore_order_assumption_state( $document_id, array $snapshot, array $trace = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$document = $this->find( $document_id );
		if ( empty( $document['id'] ) ) {
			return false;
		}

		$meta = $this->decode_meta_json( $document['meta_json'] ?? '' );
		if ( ! empty( $trace ) ) {
			$meta['order_assumption_reversal'] = $trace;
		}
		if ( isset( $meta['order_assumption'] ) ) {
			unset( $meta['order_assumption'] );
		}

		$payload = array(
			'financial_intent' => sanitize_key( (string) ( $snapshot['financial_intent'] ?? $document['financial_intent'] ?? 'income' ) ),
			'balance_nature'   => sanitize_key( (string) ( $snapshot['balance_nature'] ?? $document['balance_nature'] ?? 'receivable' ) ),
			'financial_status' => sanitize_key( (string) ( $snapshot['financial_status'] ?? $document['financial_status'] ?? 'posted' ) ),
			'payment_status'   => sanitize_key( (string) ( $snapshot['payment_status'] ?? $document['payment_status'] ?? 'pending' ) ),
			'paid_total'       => round( (float) ( $snapshot['paid_total'] ?? $document['paid_total'] ?? 0 ), 6 ),
			'balance'          => round( (float) ( $snapshot['balance'] ?? $document['balance'] ?? 0 ), 6 ),
			'manual_override'  => array_key_exists( 'manual_override', $snapshot ) ? ( ! empty( $snapshot['manual_override'] ) ? 1 : 0 ) : (int) ( $document['manual_override'] ?? 0 ),
			'category_key'     => sanitize_key( (string) ( $snapshot['category_key'] ?? $document['category_key'] ?? '' ) ),
			'subcategory_key'  => sanitize_key( (string) ( $snapshot['subcategory_key'] ?? $document['subcategory_key'] ?? '' ) ),
			'notes'            => sanitize_textarea_field( (string) ( $snapshot['notes'] ?? $document['notes'] ?? '' ) ),
			'meta_json'        => $this->encode_meta_json( $meta ),
			'updated_at'       => $this->now(),
		);

		if ( array_key_exists( 'posted_at', $snapshot ) ) {
			$payload['posted_at'] = ! empty( $snapshot['posted_at'] ) ? sanitize_text_field( (string) $snapshot['posted_at'] ) : null;
		}

		return $this->persist_update( $document_id, $payload );
	}

	public function update_from_sync( $document_id, array $data, array $options = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$document_id             = absint( $document_id );
		$preserve_classification = ! empty( $options['preserve_classification'] );
		$preserve_payment        = ! empty( $options['preserve_payment'] );
		$existing                = $this->find( $document_id );

		if ( empty( $existing ) ) {
			return false;
		}

		if ( ! empty( $data['wp_user_id'] ) && empty( $data['contact_id'] ) ) {
			$contact_type       = 'salary_expense' === sanitize_key( $data['document_type'] ?? $existing['document_type'] ) ? 'employee' : 'mixed';
			$data['contact_id'] = ( new ContactsRepository() )->find_or_create_from_wp_user( absint( $data['wp_user_id'] ), $contact_type );
		}

		$classification = null;

		if ( ! $preserve_classification ) {
			$classification = ( new ClassificationService() )->apply( $this->build_classification_context( array_merge( $existing, $data ) ) );
		}

		$currency = $this->normalize_currency_code( $data['currency'] ?? '', $existing['currency'] ?? 'USD' );
		if ( '' === $currency ) {
			$currency = 'USD';
		}

		$payload = array(
			'document_number'    => sanitize_text_field( $data['document_number'] ?? $existing['document_number'] ),
			'document_type'      => sanitize_key( $data['document_type'] ?? $existing['document_type'] ),
			'source_type'        => sanitize_key( $data['source_type'] ?? $existing['source_type'] ),
			'account_id'         => $preserve_classification ? ( ! empty( $existing['account_id'] ) ? absint( $existing['account_id'] ) : null ) : ( ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null ),
			'contact_id'         => $preserve_classification ? ( ! empty( $existing['contact_id'] ) ? absint( $existing['contact_id'] ) : null ) : ( ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : null ),
			'wp_user_id'         => $preserve_classification ? ( ! empty( $existing['wp_user_id'] ) ? absint( $existing['wp_user_id'] ) : null ) : ( ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : null ),
			'title'              => $preserve_classification ? sanitize_text_field( $existing['title'] ) : sanitize_text_field( $data['title'] ?? $existing['title'] ),
			'external_reference' => sanitize_text_field( $data['external_reference'] ?? $existing['external_reference'] ),
			'issue_date'         => $this->sanitize_date( $data['issue_date'] ?? $existing['issue_date'] ),
			'due_date'           => $this->sanitize_date( $data['due_date'] ?? $existing['due_date'] ),
			'currency'           => $currency,
			'total'              => isset( $data['total'] ) ? (float) $data['total'] : (float) $existing['total'],
			'operational_status' => sanitize_key( $data['operational_status'] ?? $existing['operational_status'] ),
			'financial_status'   => sanitize_key( $data['financial_status'] ?? $existing['financial_status'] ),
			'notes'              => $preserve_classification ? sanitize_textarea_field( $existing['notes'] ) : sanitize_textarea_field( $data['notes'] ?? $existing['notes'] ),
			'updated_at'         => $this->now(),
		);

		if ( ! $preserve_classification ) {
			$payload['financial_intent'] = sanitize_key( $classification['financial_intent'] ?? $existing['financial_intent'] );
			$payload['balance_nature']   = sanitize_key( $classification['balance_nature'] ?? $existing['balance_nature'] );
			$payload['category_key']     = sanitize_key( $classification['category_key'] ?? $existing['category_key'] );
			$payload['subcategory_key']  = sanitize_key( $classification['subcategory_key'] ?? $existing['subcategory_key'] );
			$payload['manual_override']  = ! empty( $data['manual_override'] ) ? 1 : (int) $existing['manual_override'];
			$payload['meta_json']        = $this->encode_meta_json( $this->merge_classification_trace( $existing['meta_json'] ?? '', $classification ) );
		}

		if ( ! $preserve_payment ) {
			$payload['paid_total']     = isset( $data['paid_total'] ) ? (float) $data['paid_total'] : (float) $existing['paid_total'];
			$payload['balance']        = isset( $data['balance'] ) ? (float) $data['balance'] : (float) $existing['balance'];
			$payload['payment_status'] = sanitize_key( $data['payment_status'] ?? $existing['payment_status'] );
		}

		if ( 'posted' === $payload['financial_status'] ) {
			$payload['posted_at'] = ! empty( $existing['posted_at'] ) ? $existing['posted_at'] : $this->now();
		}

		return $this->persist_update( $document_id, $payload );
	}

	public function update_manual( $document_id, array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_documents_missing', 'La tabla de movimientos aun no esta disponible.' );
		}

		$document_id = absint( $document_id );
		if ( $document_id <= 0 ) {
			return $this->error( 'asdl_fin_document_missing', 'No se encontro el movimiento solicitado.' );
		}

		$existing = $this->find( $document_id );
		if ( empty( $existing ) ) {
			return $this->error( 'asdl_fin_document_missing', 'No se encontro el movimiento solicitado.' );
		}

		if ( ! empty( $data['wp_user_id'] ) && empty( $data['contact_id'] ) ) {
			$contact_type       = 'salary_expense' === sanitize_key( $existing['document_type'] ) ? 'employee' : 'mixed';
			$data['contact_id'] = ( new ContactsRepository() )->find_or_create_from_wp_user( absint( $data['wp_user_id'] ), $contact_type );
		}

		$manual_override = ! empty( $data['manual_override'] );
		$classification  = ( new ClassificationService() )->apply( $this->build_classification_context( array_merge( $existing, $data ) ) );
		$payload         = array(
			'account_id'         => array_key_exists( 'account_id', $data ) ? ( ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null ) : ( ! empty( $existing['account_id'] ) ? absint( $existing['account_id'] ) : null ),
			'contact_id'         => array_key_exists( 'contact_id', $data ) ? ( ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : null ) : ( ! empty( $existing['contact_id'] ) ? absint( $existing['contact_id'] ) : null ),
			'wp_user_id'         => array_key_exists( 'wp_user_id', $data ) ? ( ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : null ) : ( ! empty( $existing['wp_user_id'] ) ? absint( $existing['wp_user_id'] ) : null ),
			'title'              => sanitize_text_field( $data['title'] ?? $existing['title'] ),
			'external_reference' => sanitize_text_field( $data['external_reference'] ?? $existing['external_reference'] ),
			'issue_date'         => $this->sanitize_date( $data['issue_date'] ?? $existing['issue_date'] ),
			'due_date'           => $this->sanitize_date( $data['due_date'] ?? $existing['due_date'] ),
			'financial_status'   => sanitize_key( $data['financial_status'] ?? $existing['financial_status'] ),
			'financial_intent'   => sanitize_key( $classification['financial_intent'] ?? $existing['financial_intent'] ),
			'balance_nature'     => sanitize_key( $classification['balance_nature'] ?? $existing['balance_nature'] ),
			'category_key'       => sanitize_key( $classification['category_key'] ?? $existing['category_key'] ),
			'subcategory_key'    => sanitize_key( $classification['subcategory_key'] ?? $existing['subcategory_key'] ),
			'manual_override'    => $manual_override ? 1 : 0,
			'notes'              => sanitize_textarea_field( $data['notes'] ?? $existing['notes'] ),
			'meta_json'          => $this->encode_meta_json( $this->merge_classification_trace( $existing['meta_json'] ?? '', $classification ) ),
			'updated_at'         => $this->now(),
		);

		if ( 'posted' === $payload['financial_status'] ) {
			$payload['posted_at'] = ! empty( $existing['posted_at'] ) ? $existing['posted_at'] : $this->now();
		}

		if ( ! $this->persist_update( $document_id, $payload ) ) {
			return $this->error( 'asdl_fin_document_update', 'No se pudo actualizar el movimiento.' );
		}

		return $document_id;
	}

	public function create( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_documents_missing', 'La tabla de documentos aun no esta disponible.' );
		}

		if ( ! empty( $data['wp_user_id'] ) && empty( $data['contact_id'] ) ) {
			$contact_type       = 'salary_expense' === sanitize_key( $data['document_type'] ?? '' ) ? 'employee' : 'mixed';
			$data['contact_id'] = ( new ContactsRepository() )->find_or_create_from_wp_user( absint( $data['wp_user_id'] ), $contact_type );
		}

		$classification = ( new ClassificationService() )->apply( $this->build_classification_context( $data ) );

		$title = sanitize_text_field( $data['title'] ?? '' );
		if ( '' === $title ) {
			return $this->error( 'asdl_fin_document_title', 'Debes indicar el titulo o concepto del documento.' );
		}

		$currency = $this->sanitize_currency_code(
			$data['currency'] ?? '',
			'USD',
			'asdl_fin_document_currency',
			'Debes seleccionar una moneda valida para el movimiento.'
		);
		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		$total        = max( 0, (float) ( $data['total'] ?? 0 ) );
		$paid_total   = max( 0, (float) ( $data['paid_total'] ?? 0 ) );
		$payment_status = sanitize_key( $data['payment_status'] ?? 'pending' );

		if ( 'paid' === $payment_status && $paid_total < $total ) {
			$paid_total = $total;
		}

		$balance = max( 0, $total - $paid_total );

		if ( $balance > 0 && 'paid' === $payment_status ) {
			$payment_status = 'partial';
		}

		if ( 0 === $balance && 'partial' === $payment_status ) {
			$payment_status = 'paid';
		}

		$wpdb            = $this->db();
		$now             = $this->now();
		$document_number = sanitize_text_field( $data['document_number'] ?? '' );

		if ( '' === $document_number ) {
			$document_number = $this->generate_document_number();
		}

		$result = $wpdb->insert(
			$this->table(),
			array(
				'document_number'    => $document_number,
				'document_type'      => sanitize_key( $data['document_type'] ?? 'manual_document' ),
				'source_type'        => sanitize_key( $data['source_type'] ?? 'manual' ),
				'account_id'         => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null,
				'contact_id'         => ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : null,
				'wp_user_id'         => ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : null,
				'parent_document_id' => ! empty( $data['parent_document_id'] ) ? absint( $data['parent_document_id'] ) : null,
				'title'              => $title,
				'external_reference' => sanitize_text_field( $data['external_reference'] ?? '' ),
				'issue_date'         => $this->sanitize_date( $data['issue_date'] ?? '' ),
				'due_date'           => $this->sanitize_date( $data['due_date'] ?? '' ),
				'currency'           => $currency,
				'total'              => $total,
				'paid_total'         => $paid_total,
				'balance'            => $balance,
				'operational_status' => sanitize_key( $data['operational_status'] ?? '' ),
				'financial_status'   => sanitize_key( $data['financial_status'] ?? 'draft' ),
				'payment_status'     => $payment_status,
				'financial_intent'   => sanitize_key( $classification['financial_intent'] ?? 'neutral' ),
				'balance_nature'     => sanitize_key( $classification['balance_nature'] ?? 'neutral' ),
				'category_key'       => sanitize_key( $classification['category_key'] ?? '' ),
				'subcategory_key'    => sanitize_key( $classification['subcategory_key'] ?? '' ),
				'manual_override'    => ! empty( $data['manual_override'] ) ? 1 : 0,
				'posted_at'          => 'posted' === sanitize_key( $data['financial_status'] ?? '' ) ? $now : null,
				'notes'              => sanitize_textarea_field( $data['notes'] ?? '' ),
				'meta_json'          => $this->encode_meta_json( $this->merge_classification_trace( '', $classification ) ),
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_document_insert', 'No se pudo guardar el documento.' );
		}

		return (int) $wpdb->insert_id;
	}

	private function generate_document_number() {
		return sprintf( 'DOC-%s-%04d', gmdate( 'YmdHis' ), wp_rand( 0, 9999 ) );
	}

	private function all_open( $limit = 200 ) {
		$wpdb  = $this->db();
		$limit = max( 1, (int) $limit );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE balance > 0
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	private function map_document_for_api( array $row ) {
		$row['contact'] = ! empty( $row['contact_id'] ) ? array(
			'id'             => (int) $row['contact_id'],
			'display_name'   => sanitize_text_field( (string) ( $row['contact_display_name'] ?? '' ) ),
			'email'          => sanitize_email( (string) ( $row['contact_email'] ?? '' ) ),
			'profile_origin' => sanitize_key( (string) ( $row['contact_profile_origin'] ?? '' ) ),
			'wp_user_id'     => ! empty( $row['contact_wp_user_id'] ) ? (int) $row['contact_wp_user_id'] : 0,
		) : null;
		$row['is_open'] = (float) ( $row['balance'] ?? 0 ) > 0;

		unset( $row['contact_display_name'], $row['contact_email'], $row['contact_profile_origin'], $row['contact_wp_user_id'] );

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

	private function build_classification_context( array $data ) {
		$context = $data;

		if ( ! empty( $data['contact_id'] ) ) {
			$contact = ( new ContactsRepository() )->find( absint( $data['contact_id'] ) );
			if ( ! empty( $contact['contact_type'] ) ) {
				$context['contact_type'] = $contact['contact_type'];
			}
		}

		if ( ! empty( $data['account_id'] ) ) {
			$account = ( new AccountsRepository() )->find( absint( $data['account_id'] ) );
			if ( ! empty( $account['account_type'] ) ) {
				$context['account_type'] = $account['account_type'];
			}
		}

		return $context;
	}

	private function persist_update( $document_id, array $payload ) {
		$result = $this->db()->update(
			$this->table(),
			$payload,
			array( 'id' => absint( $document_id ) ),
			$this->formats_for_payload( $payload ),
			array( '%d' )
		);

		return false !== $result;
	}

	private function merge_classification_trace( $existing_meta_json, array $classification ) {
		$meta = $this->decode_meta_json( $existing_meta_json );

		if ( ! empty( $classification['classification_trace'] ) ) {
			$meta['classification'] = $classification['classification_trace'];
		}

		return $meta;
	}

	private function formats_for_payload( array $payload ) {
		$formats = array();

		foreach ( array_keys( $payload ) as $column ) {
			switch ( $column ) {
				case 'account_id':
				case 'contact_id':
				case 'wp_user_id':
				case 'parent_document_id':
				case 'manual_override':
					$formats[] = '%d';
					break;
				case 'total':
				case 'paid_total':
				case 'balance':
					$formats[] = '%f';
					break;
				default:
					$formats[] = '%s';
					break;
			}
		}

		return $formats;
	}

	private function decode_meta_json( $meta_json ) {
		$decoded = json_decode( (string) $meta_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function encode_meta_json( array $meta ) {
		return empty( $meta ) ? null : wp_json_encode( $meta );
	}
}
