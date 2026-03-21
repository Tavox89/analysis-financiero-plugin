<?php

namespace ASDLabs\Finance\Finance;

use DateInterval;
use DateTimeImmutable;
use WP_Error;

final class ServiceProfilesRepository extends BaseRepository {
	protected $table_key = 'service_profiles';

	public function frequency_options() {
		return array(
			'weekly'    => 'Semanal',
			'biweekly'  => 'Quincenal',
			'monthly'   => 'Mensual',
			'quarterly' => 'Trimestral',
			'yearly'    => 'Anual',
		);
	}

	public function status_options() {
		return array(
			'active'   => 'Activo',
			'paused'   => 'Pausado',
			'inactive' => 'Inactivo',
		);
	}

	public function find( $profile_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$profile_id = absint( $profile_id );
		if ( $profile_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$profile_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_row( $row );
	}

	public function all_with_contacts( $limit = 100, $status = '' ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$limit          = max( 1, min( 300, (int) $limit ) );
		$status         = sanitize_key( (string) $status );
		$contacts_table = \ASDLabs\Finance\Core\Tables::name( 'contacts' );
		$where          = array( '1=1' );
		$params         = array();

		if ( '' !== $status && isset( $this->status_options()[ $status ] ) ) {
			$where[]  = 'sp.status = %s';
			$params[] = $status;
		}

		$params[] = $limit;

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT
					sp.*,
					c.display_name AS contact_display_name,
					c.email AS contact_email,
					c.payment_terms AS contact_payment_terms,
					c.profile_origin AS contact_profile_origin,
					c.is_supplier AS contact_is_supplier
				FROM {$this->table()} sp
				LEFT JOIN {$contacts_table} c ON c.id = sp.contact_id
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY
					CASE sp.status
						WHEN 'active' THEN 0
						WHEN 'paused' THEN 1
						ELSE 2
					END,
					CASE
						WHEN sp.next_issue_date IS NULL OR sp.next_issue_date = '' THEN 1
						ELSE 0
					END,
					sp.next_issue_date ASC,
					sp.id DESC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_joined_row' ), $rows );
	}

	public function for_contact( $contact_id, $limit = 100, $status = '' ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return array();
		}

		$limit          = max( 1, min( 300, (int) $limit ) );
		$status         = sanitize_key( (string) $status );
		$contacts_table = \ASDLabs\Finance\Core\Tables::name( 'contacts' );
		$where          = array( 'sp.contact_id = %d' );
		$params         = array( $contact_id );

		if ( '' !== $status && isset( $this->status_options()[ $status ] ) ) {
			$where[]  = 'sp.status = %s';
			$params[] = $status;
		}

		$params[] = $limit;

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT
					sp.*,
					c.display_name AS contact_display_name,
					c.email AS contact_email,
					c.payment_terms AS contact_payment_terms,
					c.profile_origin AS contact_profile_origin,
					c.is_supplier AS contact_is_supplier
				FROM {$this->table()} sp
				LEFT JOIN {$contacts_table} c ON c.id = sp.contact_id
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY
					CASE sp.status
						WHEN 'active' THEN 0
						WHEN 'paused' THEN 1
						ELSE 2
					END,
					CASE
						WHEN sp.next_issue_date IS NULL OR sp.next_issue_date = '' THEN 1
						ELSE 0
					END,
					sp.next_issue_date ASC,
					sp.id DESC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_joined_row' ), $rows );
	}

	public function due_queue( $as_of_date = '', $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$as_of_date = $this->sanitize_date( $as_of_date ) ?: gmdate( 'Y-m-d' );
		$limit      = max( 1, min( 200, (int) $limit ) );

		return $this->query_queue(
			"sp.status = 'active' AND sp.next_issue_date IS NOT NULL AND sp.next_issue_date <> '' AND sp.next_issue_date <= %s",
			array( $as_of_date, $limit )
		);
	}

	public function upcoming_queue( $from_date = '', $to_date = '', $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$from_date = $this->sanitize_date( $from_date ) ?: gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$to_date   = $this->sanitize_date( $to_date ) ?: gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		$limit     = max( 1, min( 200, (int) $limit ) );

		if ( $from_date > $to_date ) {
			$temp      = $from_date;
			$from_date = $to_date;
			$to_date   = $temp;
		}

		return $this->query_queue(
			"sp.status = 'active' AND sp.next_issue_date IS NOT NULL AND sp.next_issue_date <> '' AND sp.next_issue_date >= %s AND sp.next_issue_date <= %s",
			array( $from_date, $to_date, $limit )
		);
	}

	public function summary_counts( $as_of_date = '', $window_end = '' ) {
		$empty = array(
			'total_count'        => 0,
			'active_count'       => 0,
			'paused_count'       => 0,
			'inactive_count'     => 0,
			'due_count'          => 0,
			'upcoming_count'     => 0,
			'due_amount_total'   => 0.0,
			'upcoming_amount_total' => 0.0,
		);

		if ( ! $this->has_table() ) {
			return $empty;
		}

		$as_of_date = $this->sanitize_date( $as_of_date ) ?: gmdate( 'Y-m-d' );
		$window_end = $this->sanitize_date( $window_end ) ?: gmdate( 'Y-m-d', strtotime( '+30 days' ) );

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT
					COUNT(*) AS total_count,
					COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_count,
					COALESCE(SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END), 0) AS paused_count,
					COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive_count,
					COALESCE(SUM(CASE WHEN status = 'active' AND next_issue_date IS NOT NULL AND next_issue_date <> '' AND next_issue_date <= %s THEN 1 ELSE 0 END), 0) AS due_count,
					COALESCE(SUM(CASE WHEN status = 'active' AND next_issue_date IS NOT NULL AND next_issue_date <> '' AND next_issue_date > %s AND next_issue_date <= %s THEN 1 ELSE 0 END), 0) AS upcoming_count,
					COALESCE(SUM(CASE WHEN status = 'active' AND next_issue_date IS NOT NULL AND next_issue_date <> '' AND next_issue_date <= %s THEN amount ELSE 0 END), 0) AS due_amount_total,
					COALESCE(SUM(CASE WHEN status = 'active' AND next_issue_date IS NOT NULL AND next_issue_date <> '' AND next_issue_date > %s AND next_issue_date <= %s THEN amount ELSE 0 END), 0) AS upcoming_amount_total
				FROM {$this->table()}",
				$as_of_date,
				$as_of_date,
				$window_end,
				$as_of_date,
				$as_of_date,
				$window_end
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'total_count'           => isset( $row['total_count'] ) ? (int) $row['total_count'] : 0,
			'active_count'          => isset( $row['active_count'] ) ? (int) $row['active_count'] : 0,
			'paused_count'          => isset( $row['paused_count'] ) ? (int) $row['paused_count'] : 0,
			'inactive_count'        => isset( $row['inactive_count'] ) ? (int) $row['inactive_count'] : 0,
			'due_count'             => isset( $row['due_count'] ) ? (int) $row['due_count'] : 0,
			'upcoming_count'        => isset( $row['upcoming_count'] ) ? (int) $row['upcoming_count'] : 0,
			'due_amount_total'      => isset( $row['due_amount_total'] ) ? (float) $row['due_amount_total'] : 0.0,
			'upcoming_amount_total' => isset( $row['upcoming_amount_total'] ) ? (float) $row['upcoming_amount_total'] : 0.0,
		);
	}

	public function summary_for_contact( $contact_id, $as_of_date = '', $window_end = '' ) {
		$empty = array(
			'total_count'            => 0,
			'active_count'           => 0,
			'paused_count'           => 0,
			'inactive_count'         => 0,
			'due_count'              => 0,
			'upcoming_count'         => 0,
			'due_amount_total'       => 0.0,
			'upcoming_amount_total'  => 0.0,
		);

		if ( ! $this->has_table() ) {
			return $empty;
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return $empty;
		}

		$as_of_date = $this->sanitize_date( $as_of_date ) ?: gmdate( 'Y-m-d' );
		$window_end = $this->sanitize_date( $window_end ) ?: gmdate( 'Y-m-d', strtotime( '+30 days' ) );

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT
					COUNT(*) AS total_count,
					COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_count,
					COALESCE(SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END), 0) AS paused_count,
					COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive_count,
					COALESCE(SUM(CASE WHEN status = 'active' AND next_issue_date IS NOT NULL AND next_issue_date <> '' AND next_issue_date <= %s THEN 1 ELSE 0 END), 0) AS due_count,
					COALESCE(SUM(CASE WHEN status = 'active' AND next_issue_date IS NOT NULL AND next_issue_date <> '' AND next_issue_date > %s AND next_issue_date <= %s THEN 1 ELSE 0 END), 0) AS upcoming_count,
					COALESCE(SUM(CASE WHEN status = 'active' AND next_issue_date IS NOT NULL AND next_issue_date <> '' AND next_issue_date <= %s THEN amount ELSE 0 END), 0) AS due_amount_total,
					COALESCE(SUM(CASE WHEN status = 'active' AND next_issue_date IS NOT NULL AND next_issue_date <> '' AND next_issue_date > %s AND next_issue_date <= %s THEN amount ELSE 0 END), 0) AS upcoming_amount_total
				FROM {$this->table()}
				WHERE contact_id = %d",
				$as_of_date,
				$as_of_date,
				$window_end,
				$as_of_date,
				$as_of_date,
				$window_end,
				$contact_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'total_count'           => isset( $row['total_count'] ) ? (int) $row['total_count'] : 0,
			'active_count'          => isset( $row['active_count'] ) ? (int) $row['active_count'] : 0,
			'paused_count'          => isset( $row['paused_count'] ) ? (int) $row['paused_count'] : 0,
			'inactive_count'        => isset( $row['inactive_count'] ) ? (int) $row['inactive_count'] : 0,
			'due_count'             => isset( $row['due_count'] ) ? (int) $row['due_count'] : 0,
			'upcoming_count'        => isset( $row['upcoming_count'] ) ? (int) $row['upcoming_count'] : 0,
			'due_amount_total'      => isset( $row['due_amount_total'] ) ? (float) $row['due_amount_total'] : 0.0,
			'upcoming_amount_total' => isset( $row['upcoming_amount_total'] ) ? (float) $row['upcoming_amount_total'] : 0.0,
		);
	}

	public function create( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_service_profiles_missing', 'La tabla de servicios recurrentes aun no esta disponible.' );
		}

		$payload = $this->sanitize_payload( $data );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$result = $this->db()->insert(
			$this->table(),
			array_merge(
				$payload,
				array(
					'created_at' => $this->now(),
					'updated_at' => $this->now(),
				)
			),
			array_merge( $this->formats(), array( '%s', '%s' ) )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_service_profile_insert', 'No se pudo guardar la configuracion del servicio recurrente.' );
		}

		return (int) $this->db()->insert_id;
	}

	public function set_status( $profile_id, $status ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_service_profiles_missing', 'La tabla de servicios recurrentes aun no esta disponible.' );
		}

		$profile = $this->find( $profile_id );
		if ( empty( $profile['id'] ) ) {
			return $this->error( 'asdl_fin_service_profile_missing', 'No se encontro el servicio recurrente solicitado.' );
		}

		$status = sanitize_key( (string) $status );
		if ( ! isset( $this->status_options()[ $status ] ) ) {
			return $this->error( 'asdl_fin_service_profile_status', 'Debes indicar un estado valido para el servicio recurrente.' );
		}

		$result = $this->db()->update(
			$this->table(),
			array(
				'status'     => $status,
				'updated_at' => $this->now(),
			),
			array( 'id' => (int) $profile['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_service_profile_status_update', 'No se pudo actualizar el estado del servicio recurrente.' );
		}

		return (int) $profile['id'];
	}

	public function generate_document( $profile_id, array $args = array() ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_service_profiles_missing', 'La tabla de servicios recurrentes aun no esta disponible.' );
		}

		$profile = $this->find( $profile_id );
		if ( empty( $profile['id'] ) ) {
			return $this->error( 'asdl_fin_service_profile_missing', 'No se encontro el servicio recurrente solicitado.' );
		}

		if ( 'active' !== ( $profile['status'] ?? '' ) ) {
			return $this->error( 'asdl_fin_service_profile_inactive', 'Solo puedes emitir servicios recurrentes activos.' );
		}

		$issue_date = $this->sanitize_date( $args['issue_date'] ?? '' ) ?: ( $profile['next_issue_date'] ?? '' );
		if ( ! $issue_date ) {
			return $this->error( 'asdl_fin_service_profile_issue_date', 'El servicio recurrente no tiene fecha de emision disponible.' );
		}

		$occurrence_id = sprintf( '%d:%s', (int) $profile['id'], $issue_date );
		$links         = new SourceLinksRepository();
		$existing_link = $links->find_by_provider_object_external( 'services', 'service_profile_occurrence', $occurrence_id );

		if ( ! empty( $existing_link['document_id'] ) ) {
			return $this->error( 'asdl_fin_service_profile_duplicate', 'Ya existe una emision para este servicio en esa fecha.' );
		}

		$due_date = $issue_date;
		$days     = max( 0, (int) ( $profile['default_due_days'] ?? 0 ) );
		if ( $days > 0 ) {
			$due_date = $this->shift_date( $issue_date, $days . ' days' );
		}

		$document_notes = sanitize_textarea_field( (string) ( $profile['notes'] ?? '' ) );
		if ( '' !== $document_notes ) {
			$document_notes .= "\n\n";
		}
		$document_notes .= sprintf(
			'Servicio emitido automaticamente desde la configuracion recurrente #%d.',
			(int) $profile['id']
		);

		$document_id = ( new DocumentsRepository() )->create(
			array(
				'document_type'      => 'service_expense',
				'source_type'        => 'manual',
				'financial_status'   => 'posted',
				'payment_status'     => 'pending',
				'contact_id'         => (int) ( $profile['contact_id'] ?? 0 ),
				'account_id'         => ! empty( $profile['account_id'] ) ? (int) $profile['account_id'] : null,
				'title'              => sanitize_text_field( (string) ( $profile['title'] ?? '' ) ),
				'external_reference' => sanitize_text_field( (string) ( $profile['external_reference'] ?? '' ) ),
				'issue_date'         => $issue_date,
				'due_date'           => $due_date,
				'currency'           => sanitize_text_field( (string) ( $profile['currency'] ?? 'USD' ) ),
				'total'              => (float) ( $profile['amount'] ?? 0 ),
				'paid_total'         => 0,
				'notes'              => $document_notes,
			)
		);

		if ( is_wp_error( $document_id ) ) {
			return $document_id;
		}

		$links->upsert(
			array(
				'document_id'  => (int) $document_id,
				'provider'     => 'services',
				'object_type'  => 'service_profile_occurrence',
				'external_id'  => $occurrence_id,
				'external_ref' => sanitize_text_field( (string) ( $profile['title'] ?? '' ) ),
				'meta_json'    => array(
					'service_profile_id' => (int) $profile['id'],
					'issue_date'         => $issue_date,
				),
			)
		);

		$next_issue_date = $this->calculate_next_issue_date( $profile['frequency_key'] ?? 'monthly', $issue_date );
		$this->db()->update(
			$this->table(),
			array(
				'last_issued_date' => $issue_date,
				'next_issue_date'  => $next_issue_date,
				'updated_at'       => $this->now(),
			),
			array( 'id' => (int) $profile['id'] ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'profile_id'       => (int) $profile['id'],
			'document_id'      => (int) $document_id,
			'contact_id'       => (int) ( $profile['contact_id'] ?? 0 ),
			'issue_date'       => $issue_date,
			'next_issue_date'  => $next_issue_date,
		);
	}

	private function query_queue( $where_sql, array $params ) {
		$contacts_table = \ASDLabs\Finance\Core\Tables::name( 'contacts' );
		$rows           = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT
					sp.*,
					c.display_name AS contact_display_name,
					c.email AS contact_email,
					c.payment_terms AS contact_payment_terms,
					c.profile_origin AS contact_profile_origin,
					c.is_supplier AS contact_is_supplier
				FROM {$this->table()} sp
				LEFT JOIN {$contacts_table} c ON c.id = sp.contact_id
				WHERE {$where_sql}
				ORDER BY sp.next_issue_date ASC, sp.id ASC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_joined_row' ), $rows );
	}

	private function sanitize_payload( array $data ) {
		$contact_id = absint( $data['contact_id'] ?? 0 );
		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_service_profile_contact', 'Debes seleccionar un proveedor o tercero base para el servicio.' );
		}

		$contact = ( new ContactsRepository() )->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_service_profile_contact', 'No se encontro el proveedor o tercero seleccionado.' );
		}

		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return $this->error( 'asdl_fin_service_profile_title', 'Debes indicar el nombre del servicio recurrente.' );
		}

		$currency = $this->sanitize_currency_code(
			$data['currency'] ?? '',
			'USD',
			'asdl_fin_service_profile_currency',
			'Debes seleccionar una moneda valida para el servicio recurrente.'
		);
		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		$amount = max( 0, (float) ( $data['amount'] ?? 0 ) );
		if ( $amount <= 0 ) {
			return $this->error( 'asdl_fin_service_profile_amount', 'Debes indicar un monto base valido para el servicio recurrente.' );
		}

		$frequency_key = sanitize_key( (string) ( $data['frequency_key'] ?? 'monthly' ) );
		if ( ! isset( $this->frequency_options()[ $frequency_key ] ) ) {
			return $this->error( 'asdl_fin_service_profile_frequency', 'Debes seleccionar una frecuencia valida para el servicio recurrente.' );
		}

		$status = sanitize_key( (string) ( $data['status'] ?? 'active' ) );
		if ( ! isset( $this->status_options()[ $status ] ) ) {
			$status = 'active';
		}

		$start_date = $this->sanitize_date( $data['start_date'] ?? '' );
		if ( ! $start_date ) {
			return $this->error( 'asdl_fin_service_profile_start_date', 'Debes indicar la primera fecha de emision del servicio recurrente.' );
		}

		$next_issue_date = $this->sanitize_date( $data['next_issue_date'] ?? '' ) ?: $start_date;

		return array(
			'contact_id'         => $contact_id,
			'account_id'         => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null,
			'title'              => $title,
			'external_reference' => sanitize_text_field( (string) ( $data['external_reference'] ?? '' ) ),
			'service_category'   => sanitize_key( (string) ( $data['service_category'] ?? 'general' ) ) ?: 'general',
			'amount'             => $amount,
			'currency'           => $currency,
			'frequency_key'      => $frequency_key,
			'start_date'         => $start_date,
			'next_issue_date'    => $next_issue_date,
			'last_issued_date'   => null,
			'default_due_days'   => max( 0, (int) ( $data['default_due_days'] ?? 0 ) ),
			'status'             => $status,
			'payment_terms'      => sanitize_text_field( (string) ( $data['payment_terms'] ?? ( $contact['payment_terms'] ?? '' ) ) ),
			'notes'              => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			'meta_json'          => null,
		);
	}

	private function hydrate_joined_row( array $row ) {
		$row = $this->hydrate_row( $row );

		$row['contact_display_name']   = sanitize_text_field( (string) ( $row['contact_display_name'] ?? '' ) );
		$row['contact_email']          = sanitize_email( (string) ( $row['contact_email'] ?? '' ) );
		$row['contact_payment_terms']  = sanitize_text_field( (string) ( $row['contact_payment_terms'] ?? '' ) );
		$row['contact_profile_origin'] = sanitize_key( (string) ( $row['contact_profile_origin'] ?? '' ) );
		$row['contact_is_supplier']    = ! empty( $row['contact_is_supplier'] );
		$row['is_due']                 = ! empty( $row['next_issue_date'] ) && $row['next_issue_date'] <= gmdate( 'Y-m-d' );

		return $row;
	}

	private function hydrate_row( array $row ) {
		$row['id']               = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['contact_id']       = isset( $row['contact_id'] ) ? (int) $row['contact_id'] : 0;
		$row['account_id']       = ! empty( $row['account_id'] ) ? (int) $row['account_id'] : 0;
		$row['amount']           = isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
		$row['default_due_days'] = isset( $row['default_due_days'] ) ? (int) $row['default_due_days'] : 0;
		$row['status']           = sanitize_key( (string) ( $row['status'] ?? 'active' ) );
		$row['frequency_key']    = sanitize_key( (string) ( $row['frequency_key'] ?? 'monthly' ) );
		$row['currency']         = sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) );

		return $row;
	}

	private function calculate_next_issue_date( $frequency_key, $current_date ) {
		$current_date = $this->sanitize_date( $current_date );
		if ( ! $current_date ) {
			return null;
		}

		try {
			$date = new DateTimeImmutable( $current_date );

			switch ( sanitize_key( (string) $frequency_key ) ) {
				case 'weekly':
					return $date->add( new DateInterval( 'P7D' ) )->format( 'Y-m-d' );
				case 'biweekly':
					return $date->add( new DateInterval( 'P14D' ) )->format( 'Y-m-d' );
				case 'quarterly':
					return $date->modify( '+3 months' )->format( 'Y-m-d' );
				case 'yearly':
					return $date->modify( '+1 year' )->format( 'Y-m-d' );
				case 'monthly':
				default:
					return $date->modify( '+1 month' )->format( 'Y-m-d' );
			}
		} catch ( \Exception $exception ) {
			return null;
		}
	}

	private function shift_date( $date, $modifier ) {
		$date = $this->sanitize_date( $date );
		if ( ! $date ) {
			return null;
		}

		try {
			return ( new DateTimeImmutable( $date ) )->modify( $modifier )->format( 'Y-m-d' );
		} catch ( \Exception $exception ) {
			return $date;
		}
	}

	private function formats() {
		return array(
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%f',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%s',
			'%s',
			'%s',
		);
	}
}
