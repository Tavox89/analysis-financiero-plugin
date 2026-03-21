<?php

namespace ASDLabs\Finance\Finance;

final class EmployeeAdvancesRepository extends BaseRepository {
	protected $table_key = 'employee_advances';

	public function find( $advance_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$advance_id = absint( $advance_id );
		if ( $advance_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$advance_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_row( $row );
	}

	public function for_contact( $contact_id, $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$contact_id = absint( $contact_id );
		$limit      = max( 1, min( 200, (int) $limit ) );

		if ( $contact_id <= 0 ) {
			return array();
		}

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE contact_id = %d
				ORDER BY issued_at DESC, id DESC
				LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_row' ), $rows );
	}

	public function find_by_payment_id( $payment_id ) {
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
				WHERE payment_id = %d
				LIMIT 1",
				$payment_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_row( $row );
	}

	public function summary_for_contact( $contact_id ) {
		$empty = array(
			'advance_count'    => 0,
			'active_count'     => 0,
			'total_amount'     => 0,
			'recovered_amount' => 0,
			'balance_total'    => 0,
		);

		if ( ! $this->has_table() ) {
			return $empty;
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return $empty;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT
					COUNT(*) AS advance_count,
					COALESCE(SUM(CASE WHEN status IN ('active', 'partial') THEN 1 ELSE 0 END), 0) AS active_count,
					COALESCE(SUM(total_amount), 0) AS total_amount,
					COALESCE(SUM(recovered_amount), 0) AS recovered_amount,
					COALESCE(SUM(balance), 0) AS balance_total
				FROM {$this->table()}
				WHERE contact_id = %d",
				$contact_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'advance_count'    => isset( $row['advance_count'] ) ? (int) $row['advance_count'] : 0,
			'active_count'     => isset( $row['active_count'] ) ? (int) $row['active_count'] : 0,
			'total_amount'     => isset( $row['total_amount'] ) ? (float) $row['total_amount'] : 0,
			'recovered_amount' => isset( $row['recovered_amount'] ) ? (float) $row['recovered_amount'] : 0,
			'balance_total'    => isset( $row['balance_total'] ) ? (float) $row['balance_total'] : 0,
		);
	}

	public function eligible_for_payroll( $contact_id, $scheduled_payment_date ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$contact_id             = absint( $contact_id );
		$scheduled_payment_date = $this->sanitize_date( $scheduled_payment_date ?? '' ) ?: gmdate( 'Y-m-d' );

		if ( $contact_id <= 0 ) {
			return array();
		}

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE contact_id = %d
				AND status IN ('active', 'partial')
				AND recovery_mode = 'next_payroll'
				AND balance > 0
				AND ( expected_recovery_date IS NULL OR expected_recovery_date = '' OR expected_recovery_date <= %s )
				ORDER BY issued_at ASC, id ASC",
				$contact_id,
				$scheduled_payment_date
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_row' ), $rows );
	}

	public function create_for_contact( $contact_id, array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_employee_advances_missing', 'La tabla de adelantos aun no esta disponible.' );
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_salary_advance_contact', 'Debes indicar el perfil del empleado.' );
		}

		$contacts         = new ContactsRepository();
		$contact          = $contacts->find( $contact_id );
		$employee_profile = ( new EmployeeProfilesRepository() )->find_by_contact_id( $contact_id );

		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_salary_advance_contact', 'No se encontro el perfil del empleado.' );
		}

		if ( empty( $contact['is_employee'] ) ) {
			return $this->error( 'asdl_fin_salary_advance_employee', 'Este perfil debe estar marcado como empleado antes de registrar adelantos.' );
		}

		if ( empty( $employee_profile['id'] ) ) {
			return $this->error( 'asdl_fin_salary_advance_profile', 'Primero guarda la configuracion laboral del empleado.' );
		}

		$payload = $this->sanitize_payload( $contact, $employee_profile, $data );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( $payload['total_amount'] <= 0 ) {
			return $this->error( 'asdl_fin_salary_advance_amount', 'Debes indicar un monto valido para el adelanto.' );
		}

		$this->begin_transaction();

		$payment_result = ( new PaymentsRepository() )->create(
			array(
				'payment_type' => 'disbursement',
				'account_id'   => $payload['source_account_id'],
				'contact_id'   => $payload['contact_id'],
				'status'       => 'posted',
				'payment_date' => $payload['issued_at'],
				'currency'     => $payload['currency'],
				'total'        => $payload['total_amount'],
				'method_key'   => 'salary_advance',
				'reference'    => $payload['reference'],
				'notes'        => $payload['notes'],
			)
		);

		if ( is_wp_error( $payment_result ) ) {
			$this->rollback_transaction();
			return $payment_result;
		}

		$payload['payment_id'] = (int) $payment_result;

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
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_salary_advance_insert', 'No se pudo guardar el adelanto de sueldo.' );
		}

		$this->commit_transaction();

		return (int) $this->db()->insert_id;
	}

	public function apply_recovery( $advance_id, $amount, array $context = array() ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_employee_advances_missing', 'La tabla de adelantos aun no esta disponible.' );
		}

		$advance = $this->find( $advance_id );
		if ( empty( $advance['id'] ) ) {
			return $this->error( 'asdl_fin_salary_advance_missing', 'No se encontro el adelanto solicitado.' );
		}

		$amount = max( 0, (float) $amount );
		if ( $amount <= 0 ) {
			return $this->error( 'asdl_fin_salary_advance_recovery_amount', 'Debes indicar un monto valido para descontar el adelanto.' );
		}

		$applied   = min( $amount, (float) $advance['balance'] );
		$recovered = (float) $advance['recovered_amount'] + $applied;
		$balance   = max( 0, (float) $advance['total_amount'] - $recovered );
		$status    = $balance <= 0 ? 'settled' : 'partial';

		$result = $this->db()->update(
			$this->table(),
			array(
				'recovered_amount' => $recovered,
				'balance'          => $balance,
				'status'           => $status,
				'updated_at'       => $this->now(),
				'meta_json'        => ! empty( $context ) ? wp_json_encode( $context ) : null,
			),
			array( 'id' => (int) $advance['id'] ),
			array( '%f', '%f', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_salary_advance_recovery_update', 'No se pudo actualizar el adelanto.' );
		}

		return $this->find( $advance_id );
	}

	public function set_status( $advance_id, $status, array $meta_updates = array(), $balance = null ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$advance = $this->find( $advance_id );
		if ( empty( $advance['id'] ) ) {
			return false;
		}

		$meta = json_decode( (string) ( $advance['meta_json'] ?? '' ), true );
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

		if ( null !== $balance ) {
			$payload['balance'] = max( 0, (float) $balance );
			$formats[]          = '%f';
		}

		$result = $this->db()->update(
			$this->table(),
			$payload,
			array( 'id' => absint( $advance_id ) ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	private function sanitize_payload( array $contact, array $employee_profile, array $data ) {
		$total_amount           = max( 0, (float) ( $data['total_amount'] ?? 0 ) );
		$issued_at              = $this->sanitize_date( $data['issued_at'] ?? '' ) ?: gmdate( 'Y-m-d' );
		$expected_recovery_date = $this->sanitize_date( $data['expected_recovery_date'] ?? '' );
		$recovery_mode          = sanitize_key( $data['recovery_mode'] ?? 'next_payroll' );
		$currency               = $this->sanitize_currency_code(
			$data['currency'] ?? '',
			$employee_profile['salary_currency'] ?? 'USD',
			'asdl_fin_salary_advance_currency',
			'Debes seleccionar una moneda valida para el adelanto.'
		);
		$source_account_id      = ! empty( $data['source_account_id'] ) ? absint( $data['source_account_id'] ) : ( ! empty( $employee_profile['default_account_id'] ) ? absint( $employee_profile['default_account_id'] ) : null );

		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		if ( ! in_array( $recovery_mode, array( 'next_payroll', 'manual' ), true ) ) {
			$recovery_mode = 'next_payroll';
		}

		if ( ! $expected_recovery_date && ! empty( $employee_profile['next_payment_date'] ) ) {
			$expected_recovery_date = $employee_profile['next_payment_date'];
		}

		return array(
			'contact_id'             => (int) $contact['id'],
			'wp_user_id'             => ! empty( $contact['wp_user_id'] ) ? absint( $contact['wp_user_id'] ) : null,
			'employee_profile_id'    => (int) $employee_profile['id'],
			'payment_id'             => null,
			'status'                 => 'active',
			'total_amount'           => $total_amount,
			'recovered_amount'       => 0,
			'balance'                => $total_amount,
			'currency'               => $currency,
			'issued_at'              => $issued_at,
			'expected_recovery_date' => $expected_recovery_date,
			'recovery_mode'          => $recovery_mode,
			'source_account_id'      => $source_account_id,
			'reference'              => sanitize_text_field( $data['reference'] ?? '' ),
			'notes'                  => sanitize_textarea_field( $data['notes'] ?? '' ),
			'meta_json'              => null,
		);
	}

	private function hydrate_row( array $row ) {
		$row['id']                  = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['contact_id']          = isset( $row['contact_id'] ) ? (int) $row['contact_id'] : 0;
		$row['wp_user_id']          = ! empty( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;
		$row['employee_profile_id'] = ! empty( $row['employee_profile_id'] ) ? (int) $row['employee_profile_id'] : 0;
		$row['payment_id']          = ! empty( $row['payment_id'] ) ? (int) $row['payment_id'] : 0;
		$row['source_account_id']   = ! empty( $row['source_account_id'] ) ? (int) $row['source_account_id'] : 0;
		$row['total_amount']        = isset( $row['total_amount'] ) ? (float) $row['total_amount'] : 0;
		$row['recovered_amount']    = isset( $row['recovered_amount'] ) ? (float) $row['recovered_amount'] : 0;
		$row['balance']             = isset( $row['balance'] ) ? (float) $row['balance'] : 0;
		$row['currency']            = $this->normalize_currency_code( $row['currency'] ?? '', 'USD' );

		return $row;
	}

	private function formats() {
		return array( '%d', '%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );
	}
}
