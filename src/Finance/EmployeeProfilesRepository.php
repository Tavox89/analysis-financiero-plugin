<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use DateInterval;
use DateTimeImmutable;

final class EmployeeProfilesRepository extends BaseRepository {
	protected $table_key = 'employee_profiles';

	public function summary_counts() {
		$empty = array(
			'total_count'        => 0,
			'active_count'       => 0,
			'paused_count'       => 0,
			'ended_count'        => 0,
			'configured_count'   => 0,
			'unconfigured_count' => 0,
		);

		if ( ! $this->has_table() ) {
			return $empty;
		}

		$contacts_table = Tables::name( 'contacts' );
		$row = $this->db()->get_row(
			"SELECT
				COUNT(c.id) AS total_count,
				COALESCE(SUM(CASE WHEN ep.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS configured_count,
				COALESCE(SUM(CASE WHEN ep.id IS NULL THEN 1 ELSE 0 END), 0) AS unconfigured_count,
				COALESCE(SUM(CASE WHEN ep.id IS NULL OR ep.employment_status = 'active' THEN 1 ELSE 0 END), 0) AS active_count,
				COALESCE(SUM(CASE WHEN ep.employment_status = 'paused' THEN 1 ELSE 0 END), 0) AS paused_count,
				COALESCE(SUM(CASE WHEN ep.employment_status = 'ended' THEN 1 ELSE 0 END), 0) AS ended_count
			FROM {$contacts_table} c
			LEFT JOIN {$this->table()} ep ON ep.contact_id = c.id
			WHERE c.is_employee = 1",
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'total_count'        => isset( $row['total_count'] ) ? (int) $row['total_count'] : 0,
			'active_count'       => isset( $row['active_count'] ) ? (int) $row['active_count'] : 0,
			'paused_count'       => isset( $row['paused_count'] ) ? (int) $row['paused_count'] : 0,
			'ended_count'        => isset( $row['ended_count'] ) ? (int) $row['ended_count'] : 0,
			'configured_count'   => isset( $row['configured_count'] ) ? (int) $row['configured_count'] : 0,
			'unconfigured_count' => isset( $row['unconfigured_count'] ) ? (int) $row['unconfigured_count'] : 0,
		);
	}

	public function all_with_contacts( $limit = 200, $employment_status = '' ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$limit             = max( 1, min( 500, (int) $limit ) );
		$employment_status = sanitize_key( (string) $employment_status );
		$contacts_table    = Tables::name( 'contacts' );
		$where_sql         = 'WHERE c.is_employee = 1';
		$params            = array();

		if ( in_array( $employment_status, array( 'active', 'paused', 'ended' ), true ) ) {
			$where_sql .= ' AND COALESCE(ep.employment_status, \'active\') = %s';
			$params[]  = $employment_status;
		}

		$params[] = $limit;

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT
					ep.id,
					c.id AS contact_id,
					COALESCE(ep.wp_user_id, c.wp_user_id) AS wp_user_id,
					COALESCE(ep.employment_status, 'active') AS employment_status,
					COALESCE(ep.salary_amount, 0) AS salary_amount,
					COALESCE(NULLIF(ep.salary_currency, ''), 'USD') AS salary_currency,
					COALESCE(NULLIF(ep.pay_frequency, ''), 'monthly') AS pay_frequency,
					COALESCE(NULLIF(ep.payday_mode, ''), 'monthday') AS payday_mode,
					COALESCE(ep.payday_value, 1) AS payday_value,
					ep.cycle_anchor_date,
					ep.effective_from,
					ep.next_payment_date,
					ep.last_payment_date,
					ep.default_account_id,
					COALESCE(ep.notes, '') AS notes,
					COALESCE(ep.meta_json, '{}') AS meta_json,
					ep.created_at,
					ep.updated_at,
					c.display_name AS contact_display_name,
					c.email AS contact_email,
					c.profile_origin AS contact_profile_origin,
					c.status AS contact_status,
					c.is_employee AS contact_is_employee
				FROM {$contacts_table} c
				LEFT JOIN {$this->table()} ep ON ep.contact_id = c.id
				{$where_sql}
				ORDER BY
					CASE COALESCE(ep.employment_status, 'active')
						WHEN 'active' THEN 0
						WHEN 'paused' THEN 1
						ELSE 2
					END,
					CASE
						WHEN ep.next_payment_date IS NULL OR ep.next_payment_date = '' THEN 1
						ELSE 0
					END,
					ep.next_payment_date ASC,
					c.display_name ASC,
					ep.id ASC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map(
			function ( array $row ) {
				$row                         = $this->hydrate_profile( $row );
				$row['contact_display_name'] = sanitize_text_field( (string) ( $row['contact_display_name'] ?? '' ) );
				$row['contact_email']        = sanitize_email( (string) ( $row['contact_email'] ?? '' ) );
				$row['contact_profile_origin'] = sanitize_key( (string) ( $row['contact_profile_origin'] ?? '' ) );
				$row['contact_status']       = sanitize_key( (string) ( $row['contact_status'] ?? '' ) );
				$row['has_employee_profile'] = ! empty( $row['id'] );

				return $row;
			},
			$rows
		);
	}

	public function upcoming_payroll_queue( $from_date = '', $to_date = '', $limit = 100 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$from_date = $this->sanitize_date( $from_date ) ?: gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to_date   = $this->sanitize_date( $to_date ) ?: gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$limit     = max( 1, min( 300, (int) $limit ) );

		if ( $from_date > $to_date ) {
			$temp      = $from_date;
			$from_date = $to_date;
			$to_date   = $temp;
		}

		$contacts_table = Tables::name( 'contacts' );
		$rows           = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT ep.*, c.display_name AS contact_display_name, c.email AS contact_email, c.profile_origin AS contact_profile_origin, c.status AS contact_status
				FROM {$this->table()} ep
				INNER JOIN {$contacts_table} c ON c.id = ep.contact_id
				WHERE ep.employment_status = 'active'
				AND ep.next_payment_date IS NOT NULL
				AND ep.next_payment_date <> ''
				AND ep.next_payment_date >= %s
				AND ep.next_payment_date <= %s
				ORDER BY ep.next_payment_date ASC, ep.id ASC
				LIMIT %d",
				$from_date,
				$to_date,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$items = array();

		foreach ( $rows as $row ) {
			$row = $this->hydrate_profile( $row );

			if ( empty( $row['payroll_eligible'] ) ) {
				continue;
			}

			$row['contact_display_name']   = sanitize_text_field( (string) ( $row['contact_display_name'] ?? '' ) );
			$row['contact_email']          = sanitize_email( (string) ( $row['contact_email'] ?? '' ) );
			$row['contact_profile_origin'] = sanitize_key( (string) ( $row['contact_profile_origin'] ?? '' ) );
			$row['contact_status']         = sanitize_key( (string) ( $row['contact_status'] ?? '' ) );
			$items[]                       = $row;
		}

		return $items;
	}

	public function find_by_contact_id( $contact_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE contact_id = %d
				LIMIT 1",
				$contact_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_profile( $row );
	}

	public function save_for_contact( $contact_id, array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_employee_profiles_missing', 'La tabla laboral aun no esta disponible.' );
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_employee_profile_contact', 'Debes indicar el perfil del empleado.' );
		}

		$contact = ( new ContactsRepository() )->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_employee_profile_contact', 'No se encontro el perfil del empleado.' );
		}

		$payload = $this->sanitize_payload( $contact, $data, $this->find_by_contact_id( $contact_id ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( $payload['salary_amount'] <= 0 ) {
			return $this->error( 'asdl_fin_employee_salary_amount', 'Debes indicar un sueldo base valido.' );
		}

		$wpdb     = $this->db();
		$existing = $this->find_by_contact_id( $contact_id );
		$now      = $this->now();

		( new ContactsRepository() )->mark_as_employee( $contact_id );

		if ( ! empty( $existing['id'] ) ) {
			$result = $wpdb->update(
				$this->table(),
				array_merge(
					$payload,
					array(
						'updated_at' => $now,
					)
				),
				array( 'contact_id' => $contact_id ),
				array_merge( $this->formats(), array( '%s' ) ),
				array( '%d' )
			);

			if ( false === $result ) {
				return $this->error( 'asdl_fin_employee_profile_update', 'No se pudo actualizar la configuracion laboral.' );
			}

			return (int) $existing['id'];
		}

		$result = $wpdb->insert(
			$this->table(),
			array_merge(
				$payload,
				array(
					'created_at' => $now,
					'updated_at' => $now,
				)
			),
			array_merge( $this->formats(), array( '%s', '%s' ) )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_employee_profile_insert', 'No se pudo guardar la configuracion laboral.' );
		}

		return (int) $wpdb->insert_id;
	}

	public function register_payroll_payment( $contact_id, $paid_date ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$contact_id = absint( $contact_id );
		$paid_date  = $this->sanitize_date( $paid_date );

		if ( $contact_id <= 0 || ! $paid_date ) {
			return false;
		}

		$existing = $this->find_by_contact_id( $contact_id );
		if ( empty( $existing['id'] ) ) {
			return false;
		}

		$next_payment_date = $this->calculate_next_payment_date(
			$existing['pay_frequency'] ?? 'monthly',
			(int) ( $existing['payday_value'] ?? 1 ),
			$existing['effective_from'] ?? gmdate( 'Y-m-d' ),
			$existing['cycle_anchor_date'] ?? '',
			$paid_date
		);

		$result = $this->db()->update(
			$this->table(),
			array(
				'last_payment_date' => $paid_date,
				'next_payment_date' => $next_payment_date,
				'updated_at'        => $this->now(),
			),
			array( 'contact_id' => $contact_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	private function sanitize_payload( array $contact, array $data, $existing = null ) {
		$pay_frequency     = sanitize_key( $data['pay_frequency'] ?? ( $existing['pay_frequency'] ?? 'monthly' ) );
		$employment_status = sanitize_key( $data['employment_status'] ?? ( $existing['employment_status'] ?? 'active' ) );
		$payday_mode       = $this->resolve_payday_mode( $pay_frequency, sanitize_key( $data['payday_mode'] ?? ( $existing['payday_mode'] ?? '' ) ) );
		$raw_payday_value  = 'monthly' === $pay_frequency
			? (int) ( $data['payday_value_monthly'] ?? ( $existing['payday_value'] ?? 1 ) )
			: (int) ( $data['payday_value'] ?? ( $existing['payday_value'] ?? 1 ) );
		$payday_value      = $this->sanitize_payday_value( $pay_frequency, $raw_payday_value );
		$effective_from   = $this->sanitize_date( $data['effective_from'] ?? ( $existing['effective_from'] ?? '' ) ) ?: gmdate( 'Y-m-d' );
		$cycle_anchor_date = $this->sanitize_date( $data['cycle_anchor_date'] ?? ( $existing['cycle_anchor_date'] ?? '' ) );
		$last_payment_date = $this->sanitize_date( $data['last_payment_date'] ?? ( $existing['last_payment_date'] ?? '' ) );
		$next_payment_date = $this->sanitize_date( $data['next_payment_date'] ?? '' );
		$salary_currency   = $this->sanitize_currency_code(
			$data['salary_currency'] ?? '',
			$existing['salary_currency'] ?? 'USD',
			'asdl_fin_employee_currency',
			'Debes seleccionar una moneda valida para el sueldo del empleado.'
		);
		$salary_amount     = max( 0, (float) ( $data['salary_amount'] ?? ( $existing['salary_amount'] ?? 0 ) ) );
		$existing_meta     = is_array( $existing['meta'] ?? null ) ? $existing['meta'] : array();
		$hire_date         = $this->sanitize_date( $data['hire_date'] ?? ( $existing_meta['hire_date'] ?? '' ) ) ?: $effective_from;
		$contract_start    = $this->sanitize_date( $data['contract_start_date'] ?? ( $existing_meta['contract_start_date'] ?? '' ) ) ?: $hire_date;
		$contract_type     = $this->sanitize_contract_type( $data['contract_type'] ?? ( $existing_meta['contract_type'] ?? '' ) );
		$contract_end      = $this->sanitize_date( $data['contract_end_date'] ?? ( $existing_meta['contract_end_date'] ?? '' ) );
		$birth_date        = $this->sanitize_date( $data['birth_date'] ?? ( $existing_meta['birth_date'] ?? '' ) );
		$contract_file_id  = ! empty( $data['contract_attachment_id'] ) ? absint( $data['contract_attachment_id'] ) : ( ! empty( $existing_meta['contract_attachment_id'] ) ? absint( $existing_meta['contract_attachment_id'] ) : 0 );
		$termination_type  = 'ended' === $employment_status ? $this->sanitize_termination_type( $data['termination_type'] ?? ( $existing_meta['termination_type'] ?? '' ) ) : '';
		$termination_date  = 'ended' === $employment_status ? $this->sanitize_date( $data['termination_date'] ?? ( $existing_meta['termination_date'] ?? '' ) ) : null;
		$termination_reason= 'ended' === $employment_status ? sanitize_textarea_field( $data['termination_reason'] ?? ( $existing_meta['termination_reason'] ?? '' ) ) : '';

		if ( is_wp_error( $salary_currency ) ) {
			return $salary_currency;
		}

		$meta = array(
			'birth_date'              => $birth_date,
			'hire_date'               => $hire_date,
			'contract_type'           => $contract_type,
			'contract_start_date'     => $contract_start,
			'contract_end_date'       => $contract_end,
			'contract_attachment_id'  => $contract_file_id,
			'termination_type'        => $termination_type,
			'termination_date'        => $termination_date,
			'termination_reason'      => $termination_reason,
		);

		if ( 'biweekly' === $pay_frequency && ! $cycle_anchor_date ) {
			$cycle_anchor_date = $effective_from;
		}

		if ( ! $next_payment_date ) {
			$next_payment_date = $this->calculate_next_payment_date(
				$pay_frequency,
				$payday_value,
				$effective_from,
				$cycle_anchor_date,
				$last_payment_date
			);
		}

		return array(
			'contact_id'         => (int) $contact['id'],
			'wp_user_id'         => ! empty( $contact['wp_user_id'] ) ? absint( $contact['wp_user_id'] ) : null,
			'employment_status'  => $employment_status,
			'salary_amount'      => $salary_amount,
			'salary_currency'    => $salary_currency,
			'pay_frequency'      => $pay_frequency,
			'payday_mode'        => $payday_mode,
			'payday_value'       => $payday_value,
			'cycle_anchor_date'  => $cycle_anchor_date,
			'effective_from'     => $effective_from,
			'next_payment_date'  => $next_payment_date,
			'last_payment_date'  => $last_payment_date,
			'default_account_id' => ! empty( $data['default_account_id'] ) ? absint( $data['default_account_id'] ) : ( ! empty( $existing['default_account_id'] ) ? absint( $existing['default_account_id'] ) : null ),
			'notes'              => sanitize_textarea_field( $data['notes'] ?? ( $existing['notes'] ?? '' ) ),
			'meta_json'          => wp_json_encode( $meta ),
		);
	}

	private function hydrate_profile( array $row ) {
		$row['id']                 = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['contact_id']         = isset( $row['contact_id'] ) ? (int) $row['contact_id'] : 0;
		$row['wp_user_id']         = ! empty( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;
		$row['salary_amount']      = isset( $row['salary_amount'] ) ? (float) $row['salary_amount'] : 0;
		$row['salary_currency']    = $this->normalize_currency_code( $row['salary_currency'] ?? '', 'USD' );
		$row['payday_value']       = isset( $row['payday_value'] ) ? (int) $row['payday_value'] : 1;
		$row['default_account_id'] = ! empty( $row['default_account_id'] ) ? (int) $row['default_account_id'] : 0;
		$row['payday_summary']     = $this->payday_summary( $row );
		$row['meta']               = $this->decode_meta_json( $row['meta_json'] ?? '' );

		$row['birth_date']             = $this->sanitize_date( $row['meta']['birth_date'] ?? '' );
		$row['hire_date']              = $this->sanitize_date( $row['meta']['hire_date'] ?? '' );
		$row['contract_type']          = $this->sanitize_contract_type( $row['meta']['contract_type'] ?? '' );
		$row['contract_start_date']    = $this->sanitize_date( $row['meta']['contract_start_date'] ?? '' );
		$row['contract_end_date']      = $this->sanitize_date( $row['meta']['contract_end_date'] ?? '' );
		$row['contract_attachment_id'] = ! empty( $row['meta']['contract_attachment_id'] ) ? absint( $row['meta']['contract_attachment_id'] ) : 0;
		$row['termination_type']       = $this->sanitize_termination_type( $row['meta']['termination_type'] ?? '' );
		$row['termination_date']       = $this->sanitize_date( $row['meta']['termination_date'] ?? '' );
		$row['termination_reason']     = sanitize_textarea_field( $row['meta']['termination_reason'] ?? '' );
		$row['contract_attachment_url']= $row['contract_attachment_id'] ? wp_get_attachment_url( $row['contract_attachment_id'] ) : '';
		$row['contract_attachment_label'] = $row['contract_attachment_id'] ? sanitize_text_field( get_the_title( $row['contract_attachment_id'] ) ?: sprintf( 'Archivo #%d', (int) $row['contract_attachment_id'] ) ) : '';

		$row = array_merge( $row, $this->build_contract_snapshot( $row ) );

		return $row;
	}

	private function decode_meta_json( $meta_json ) {
		$decoded = json_decode( (string) $meta_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function sanitize_contract_type( $value ) {
		$value = sanitize_key( (string) $value );

		return in_array( $value, array( 'indefinite', 'fixed_term', 'temporary', 'service_contract' ), true ) ? $value : '';
	}

	private function sanitize_termination_type( $value ) {
		$value = sanitize_key( (string) $value );

		return in_array( $value, array( 'resignation', 'dismissal', 'contract_end', 'mutual_agreement', 'other' ), true ) ? $value : '';
	}

	private function build_contract_snapshot( array $profile ) {
		$today_ts            = current_time( 'timestamp' );
		$today               = gmdate( 'Y-m-d', $today_ts );
		$employment_status   = sanitize_key( (string) ( $profile['employment_status'] ?? '' ) );
		$contract_type       = sanitize_key( (string) ( $profile['contract_type'] ?? '' ) );
		$contract_start_date = $profile['contract_start_date'] ?: ( $profile['hire_date'] ?: ( $profile['effective_from'] ?? '' ) );
		$contract_end_date   = $profile['contract_end_date'] ?? '';
		$contract_status_key = 'not_configured';

		if ( 'ended' === $employment_status ) {
			$contract_status_key = 'ended';
		} elseif ( in_array( $contract_type, array( 'fixed_term', 'temporary' ), true ) && $contract_end_date ) {
			$days_remaining = $this->days_between( $today, $contract_end_date );

			if ( $days_remaining < 0 ) {
				$contract_status_key = 'expired';
			} elseif ( $days_remaining <= 15 ) {
				$contract_status_key = 'renewal_due';
			} else {
				$contract_status_key = 'active';
			}
		} elseif ( $contract_type || $contract_start_date ) {
			$contract_status_key = 'active';
		}

		$elapsed_days = $contract_start_date ? max( 0, $this->days_between( $contract_start_date, $today ) ) : null;
		$total_days   = ( $contract_start_date && $contract_end_date && $contract_end_date >= $contract_start_date ) ? max( 0, $this->days_between( $contract_start_date, $contract_end_date ) ) : null;
		$remaining_days = null;

		if ( in_array( $contract_type, array( 'fixed_term', 'temporary' ), true ) && $contract_end_date ) {
			$remaining_days = max( 0, $this->days_between( $today, $contract_end_date ) );
		}

		$renewal_required = in_array( $contract_status_key, array( 'renewal_due', 'expired' ), true );
		$payroll_eligible = 'active' === $employment_status && 'expired' !== $contract_status_key;

		return array(
			'contract_status_key'   => $contract_status_key,
			'contract_is_fixed_term'=> in_array( $contract_type, array( 'fixed_term', 'temporary' ), true ),
			'contract_total_days'   => $total_days,
			'contract_elapsed_days' => $elapsed_days,
			'contract_remaining_days' => $remaining_days,
			'contract_elapsed_label'  => null !== $elapsed_days ? $this->format_day_span( $elapsed_days ) : 'Sin fecha',
			'contract_remaining_label'=> 'expired' === $contract_status_key ? 'Vencido' : ( null !== $remaining_days ? $this->format_day_span( $remaining_days ) : 'No aplica' ),
			'renewal_required'        => $renewal_required,
			'renewal_message'         => $renewal_required ? ( 'expired' === $contract_status_key ? 'El contrato vencio y requiere renovacion para reactivar la operacion normal.' : 'El contrato esta por vencer y conviene renovarlo a tiempo.' ) : '',
			'payroll_eligible'        => $payroll_eligible,
		);
	}

	private function days_between( $from_date, $to_date ) {
		$from_date = $this->sanitize_date( $from_date );
		$to_date   = $this->sanitize_date( $to_date );

		if ( ! $from_date || ! $to_date ) {
			return 0;
		}

		$from = new DateTimeImmutable( $from_date );
		$to   = new DateTimeImmutable( $to_date );

		return (int) $from->diff( $to )->format( '%r%a' );
	}

	private function format_day_span( $days ) {
		$days = max( 0, (int) $days );

		if ( $days < 30 ) {
			return sprintf( '%d dia(s)', $days );
		}

		$months = (int) floor( $days / 30 );
		$rest   = $days % 30;

		if ( 0 === $rest ) {
			return sprintf( '%d mes(es)', $months );
		}

		return sprintf( '%d mes(es) y %d dia(s)', $months, $rest );
	}

	private function resolve_payday_mode( $pay_frequency, $payday_mode ) {
		if ( in_array( $pay_frequency, array( 'weekly', 'biweekly' ), true ) ) {
			return 'weekday';
		}

		return 'monthday' === $payday_mode ? 'monthday' : 'monthday';
	}

	private function sanitize_payday_value( $pay_frequency, $payday_value ) {
		if ( in_array( $pay_frequency, array( 'weekly', 'biweekly' ), true ) ) {
			return max( 0, min( 6, $payday_value ) );
		}

		return max( 1, min( 31, $payday_value ) );
	}

	private function calculate_next_payment_date( $frequency, $payday_value, $effective_from, $cycle_anchor_date = '', $last_payment_date = '' ) {
		$reference = new DateTimeImmutable( $last_payment_date ?: gmdate( 'Y-m-d' ) );
		$effective = new DateTimeImmutable( $effective_from ?: gmdate( 'Y-m-d' ) );

		if ( $effective > $reference ) {
			$reference = $effective;
		}

		switch ( $frequency ) {
			case 'weekly':
				return $this->next_weekday_date( $reference, $payday_value )->format( 'Y-m-d' );
			case 'biweekly':
				return $this->next_biweekly_date( $reference, $payday_value, $cycle_anchor_date ?: $effective->format( 'Y-m-d' ) )->format( 'Y-m-d' );
			case 'monthly':
			default:
				return $this->next_monthday_date( $reference, $payday_value )->format( 'Y-m-d' );
		}
	}

	private function next_weekday_date( DateTimeImmutable $reference, $weekday ) {
		$current_weekday = (int) $reference->format( 'w' );
		$delta           = $weekday - $current_weekday;

		if ( $delta < 0 ) {
			$delta += 7;
		}

		return $reference->add( new DateInterval( 'P' . $delta . 'D' ) );
	}

	private function next_biweekly_date( DateTimeImmutable $reference, $weekday, $anchor_date ) {
		$anchor = new DateTimeImmutable( $anchor_date );
		$anchor = $this->next_weekday_date( $anchor, $weekday );

		while ( $anchor < $reference ) {
			$anchor = $anchor->add( new DateInterval( 'P14D' ) );
		}

		return $anchor;
	}

	private function next_monthday_date( DateTimeImmutable $reference, $day_of_month ) {
		$year  = (int) $reference->format( 'Y' );
		$month = (int) $reference->format( 'm' );

		for ( $offset = 0; $offset < 24; $offset++ ) {
			$target_month = $month + $offset;
			$target_year  = $year + (int) floor( ( $target_month - 1 ) / 12 );
			$normalized_month = ( ( $target_month - 1 ) % 12 ) + 1;
			$last_day = cal_days_in_month( CAL_GREGORIAN, $normalized_month, $target_year );
			$target_day = min( $day_of_month, $last_day );
			$candidate = new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $target_year, $normalized_month, $target_day ) );

			if ( $candidate >= $reference ) {
				return $candidate;
			}
		}

		return $reference;
	}

	private function payday_summary( array $profile ) {
		$frequency = sanitize_key( $profile['pay_frequency'] ?? 'monthly' );
		$value     = isset( $profile['payday_value'] ) ? (int) $profile['payday_value'] : 1;

		if ( in_array( $frequency, array( 'weekly', 'biweekly' ), true ) ) {
			$days = array( 'Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado' );
			return $days[ $value ] ?? 'Sin definir';
		}

		return sprintf( 'Dia %d de cada mes', $value );
	}

	private function formats() {
		return array( '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
	}
}
