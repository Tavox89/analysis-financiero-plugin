<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use DateInterval;
use DateTime;

final class InstallmentPlansRepository extends BaseRepository {
	protected $table_key = 'installment_plans';

	public function find( $plan_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$plan_id = absint( $plan_id );
		if ( $plan_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$plan_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_plan( $row );
	}

	public function all( $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb  = $this->db();
		$limit = max( 1, (int) $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_plan' ), $rows );
	}

	public function for_contact( $contact_id, $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$contact_id = absint( $contact_id );
		$limit      = max( 1, (int) $limit );
		$wpdb       = $this->db();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE contact_id = %d
				ORDER BY id DESC
				LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_plan' ), $rows );
	}

	public function for_document( $document_id, $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$document_id = absint( $document_id );
		$limit       = max( 1, (int) $limit );

		if ( $document_id <= 0 ) {
			return array();
		}

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE document_id = %d
				ORDER BY id DESC
				LIMIT %d",
				$document_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_plan' ), $rows );
	}

	public function has_active_for_document( $document_id ) {
		foreach ( $this->for_document( $document_id, 100 ) as $plan ) {
			if ( in_array( sanitize_key( (string) ( $plan['status'] ?? '' ) ), array( 'active', 'paused' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	public function create( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_plans_missing', 'La tabla de compromisos aun no esta disponible.' );
		}

		$title = sanitize_text_field( $data['title'] ?? '' );
		if ( '' === $title ) {
			return $this->error( 'asdl_fin_plan_title', 'Debes indicar el nombre del compromiso.' );
		}

		$contact_id             = ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : 0;
		$employee_profile       = $contact_id > 0 ? ( new EmployeeProfilesRepository() )->find_by_contact_id( $contact_id ) : null;
		$principal_amount       = max( 0, (float) ( $data['principal_amount'] ?? 0 ) );
		$raw_total_amount       = max( 0, (float) ( $data['total_amount'] ?? 0 ) );
		$total_amount           = max( $principal_amount, $raw_total_amount > 0 ? $raw_total_amount : $principal_amount );
		$planning_mode          = sanitize_key( (string) ( $data['planning_mode'] ?? '' ) );
		$planning_value         = max( 0, (float) ( $data['planning_value'] ?? 0 ) );
		$target_installment     = max( 0, (float) ( $data['target_installment_amount'] ?? 0 ) );
		$installment_count      = max( 0, (int) ( $data['installment_count'] ?? 0 ) );
		$commitment_origin      = sanitize_key( $data['commitment_origin'] ?? 'loan' );
		$settlement_direction   = $this->normalize_settlement_direction( $data['settlement_direction'] ?? '', $commitment_origin );
		$collection_mode        = $this->normalize_collection_mode(
			$data['collection_mode'] ?? 'manual',
			$settlement_direction,
			$contact_id
		);
		$plan_type             = sanitize_key( $data['plan_type'] ?? ( 'loan' === $commitment_origin ? 'loan' : 'installment' ) );
		$start_date            = $this->resolve_effective_start_date(
			$this->sanitize_date( $data['start_date'] ?? '' ),
			$collection_mode,
			$employee_profile
		);
		$frequency_key         = $this->resolve_effective_frequency(
			sanitize_key( $data['frequency_key'] ?? 'monthly' ),
			$collection_mode,
			$employee_profile
		);
		$wpdb             = $this->db();
		$now              = $this->now();

		if ( $total_amount <= 0 ) {
			return $this->error( 'asdl_fin_plan_amount', 'Debes indicar un monto valido para el compromiso.' );
		}

		$currency = $this->sanitize_currency_code(
			$data['currency'] ?? '',
			'USD',
			'asdl_fin_plan_currency',
			'Debes seleccionar una moneda valida para el compromiso.'
		);
		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		$schedule = $this->derive_schedule(
			$total_amount,
			array(
				'planning_mode'              => $planning_mode,
				'planning_value'             => $planning_value,
				'target_installment_amount'  => $target_installment,
				'installment_count'          => $installment_count,
			)
		);

		if ( is_wp_error( $schedule ) ) {
			return $schedule;
		}

		$planning_mode      = $schedule['planning_mode'];
		$target_installment = $schedule['target_installment_amount'];
		$installment_count  = $schedule['installment_count'];
		$installment_amounts = (array) ( $schedule['installment_amounts'] ?? array() );
		$regular_period_amount = isset( $schedule['regular_period_amount'] ) ? (float) $schedule['regular_period_amount'] : $target_installment;
		$first_period_amount   = isset( $schedule['first_period_amount'] ) ? (float) $schedule['first_period_amount'] : ( $installment_amounts[0] ?? $target_installment );
		$last_period_amount    = isset( $schedule['last_period_amount'] ) ? (float) $schedule['last_period_amount'] : ( ! empty( $installment_amounts ) ? end( $installment_amounts ) : $target_installment );
		$capacity_available_first_period = 0.0;
		$capacity_shortfall_first_period = 0.0;
		$capacity_warning                = '';

		if (
			$contact_id > 0
			&& 'receivable' === $settlement_direction
			&& in_array( $collection_mode, array( 'payroll_deduction', 'mixed' ), true )
			&& ! empty( $employee_profile['id'] )
		) {
			$salary_amount = max( 0, (float) ( $employee_profile['salary_amount'] ?? 0 ) );
			$preview       = ( new CommitmentSettlementService() )->preview_payroll_deductions(
				$contact_id,
				$start_date,
				$salary_amount
			);

			$capacity_available_first_period = max( 0, $salary_amount - (float) ( $preview['planned_total'] ?? 0 ) );
			$capacity_shortfall_first_period = max( 0, $first_period_amount - $capacity_available_first_period );

			if ( $capacity_shortfall_first_period > 0.00001 ) {
				$capacity_warning = sprintf(
					'La primera cuota pide %1$s, pero en el primer pago solo caben %2$s. El faltante %3$s se movera al siguiente periodo si confirmas este compromiso.',
					number_format_i18n( $first_period_amount, 2 ),
					number_format_i18n( $capacity_available_first_period, 2 ),
					number_format_i18n( $capacity_shortfall_first_period, 2 )
				);

				if ( empty( $data['confirm_capacity_override'] ) ) {
					return $this->error( 'asdl_fin_plan_capacity', $capacity_warning );
				}
			}
		}

		$result = $wpdb->insert(
			$this->table(),
			array(
				'document_id'       => ! empty( $data['document_id'] ) ? absint( $data['document_id'] ) : null,
				'contact_id'        => $contact_id ?: null,
				'plan_type'         => $plan_type,
				'title'             => $title,
				'currency'          => $currency,
				'principal_amount'  => $principal_amount,
				'total_amount'      => $total_amount,
				'balance'           => $total_amount,
				'installment_count' => $installment_count,
				'frequency_key'     => $frequency_key,
				'status'            => sanitize_key( $data['status'] ?? 'active' ),
				'start_date'        => $start_date,
				'end_date'          => $this->sanitize_date( $data['end_date'] ?? '' ),
				'notes'             => sanitize_textarea_field( $data['notes'] ?? '' ),
				'meta_json'         => wp_json_encode(
					array_merge(
						array(
							'commitment_origin'      => $commitment_origin,
							'settlement_direction'   => $settlement_direction,
							'collection_mode'        => $collection_mode,
							'planning_mode'          => $planning_mode,
							'planning_value'         => $planning_value,
							'target_installment_amount' => $target_installment,
							'regular_period_amount'  => $regular_period_amount,
							'first_period_amount'    => $first_period_amount,
							'last_period_amount'     => $last_period_amount,
							'period_count'           => $installment_count,
							'capacity_available_first_period' => round( $capacity_available_first_period, 6 ),
							'capacity_shortfall_first_period' => round( $capacity_shortfall_first_period, 6 ),
							'capacity_warning'       => $capacity_warning,
							'confirmed_capacity_override' => ! empty( $data['confirm_capacity_override'] ) ? 1 : 0,
						),
						CommitmentExposureService::creation_metadata(
							array(
								'contact_id'           => $contact_id,
								'document_id'          => ! empty( $data['document_id'] ) ? absint( $data['document_id'] ) : 0,
								'commitment_origin'    => $commitment_origin,
								'settlement_direction' => $settlement_direction,
							)
						)
					)
				),
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_plan_insert', 'No se pudo guardar el plan.' );
		}

		$plan_id = (int) $wpdb->insert_id;

		if ( $installment_count > 0 && $start_date && $total_amount > 0 ) {
			$this->create_installments( $plan_id, $title, $start_date, $installment_amounts, $frequency_key );
		}

		return $plan_id;
	}

	public function set_balance_status( $plan_id, $balance, $status, array $meta_updates = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$plan = $this->find( $plan_id );
		if ( empty( $plan['id'] ) ) {
			return false;
		}

		$meta = is_array( $plan['meta'] ?? null ) ? $plan['meta'] : array();
		foreach ( $meta_updates as $meta_key => $meta_value ) {
			$meta[ sanitize_key( (string) $meta_key ) ] = $meta_value;
		}

		$balance = max( 0, (float) $balance );
		$status  = sanitize_key( (string) $status );
		$payload = array(
			'balance'    => $balance,
			'status'     => $status,
			'meta_json'  => wp_json_encode( $meta ),
			'updated_at' => $this->now(),
		);
		$formats = array( '%f', '%s', '%s', '%s' );

		if ( $balance <= 0 && empty( $plan['end_date'] ) ) {
			$payload['end_date'] = gmdate( 'Y-m-d' );
			$formats[] = '%s';
		}

		$result = $this->db()->update(
			$this->table(),
			$payload,
			array( 'id' => (int) $plan['id'] ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	public function set_status( $plan_id, $status, array $meta_updates = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$plan = $this->find( $plan_id );
		if ( empty( $plan['id'] ) ) {
			return false;
		}

		return $this->set_balance_status(
			(int) $plan['id'],
			(float) ( $plan['balance'] ?? 0 ),
			sanitize_key( (string) $status ),
			$meta_updates
		);
	}

	private function create_installments( $plan_id, $title, $start_date, array $installment_amounts, $frequency_key ) {
		$wpdb             = $this->db();
		$installments_tbl = Tables::name( 'installments' );
		$now              = $this->now();
		$interval         = $this->resolve_interval( $frequency_key );
		$start            = new DateTime( $start_date );
		$installment_count = count( $installment_amounts );

		for ( $index = 1; $index <= $installment_count; $index++ ) {
			$amount = round( (float) ( $installment_amounts[ $index - 1 ] ?? 0 ), 6 );
			if ( $amount <= 0 ) {
				continue;
			}

			$wpdb->insert(
				$installments_tbl,
				array(
					'plan_id'        => $plan_id,
					'sequence_no'    => $index,
					'title'          => sprintf( '%s - Cuota %d', $title, $index ),
					'due_date'       => $start->format( 'Y-m-d' ),
					'amount'         => $amount,
					'paid_amount'    => 0,
					'balance'        => $amount,
					'payment_status' => 'pending',
					'paid_at'        => null,
					'meta_json'      => null,
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' )
			);

			$start->add( $interval );
		}
	}

	private function resolve_interval( $frequency_key ) {
		switch ( $frequency_key ) {
			case 'weekly':
				return new DateInterval( 'P1W' );
			case 'biweekly':
				return new DateInterval( 'P2W' );
			case 'quarterly':
				return new DateInterval( 'P3M' );
			case 'monthly':
			default:
				return new DateInterval( 'P1M' );
		}
	}

	private function hydrate_plan( array $row ) {
		$meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();

		$row['meta']                      = $meta;
		$row['commitment_origin']         = sanitize_key( $meta['commitment_origin'] ?? ( 'loan' === ( $row['plan_type'] ?? '' ) ? 'loan' : 'manual_charge' ) );
		$row['settlement_direction']      = $this->normalize_settlement_direction( $meta['settlement_direction'] ?? '', $row['commitment_origin'] );
		$row['collection_mode']           = sanitize_key( $meta['collection_mode'] ?? 'manual' );
		$row['planning_mode']             = sanitize_key( $meta['planning_mode'] ?? 'period_amount' );
		$row['planning_value']            = isset( $meta['planning_value'] ) ? (float) $meta['planning_value'] : 0.0;
		$row['target_installment_amount'] = isset( $meta['target_installment_amount'] ) ? (float) $meta['target_installment_amount'] : 0.0;
		$row['regular_period_amount']     = isset( $meta['regular_period_amount'] ) ? (float) $meta['regular_period_amount'] : $row['target_installment_amount'];
		$row['first_period_amount']       = isset( $meta['first_period_amount'] ) ? (float) $meta['first_period_amount'] : $row['target_installment_amount'];
		$row['last_period_amount']        = isset( $meta['last_period_amount'] ) ? (float) $meta['last_period_amount'] : $row['target_installment_amount'];
		$row['period_count']              = isset( $meta['period_count'] ) ? (int) $meta['period_count'] : (int) ( $row['installment_count'] ?? 0 );
		$row['exposure_kind']             = sanitize_key( (string) ( $meta['exposure_kind'] ?? '' ) );
		$row['backing_source_type']       = sanitize_key( (string) ( $meta['backing_source_type'] ?? '' ) );
		$row['backing_document_id']       = absint( $meta['backing_document_id'] ?? 0 );
		$row['backing_debt_scope']        = sanitize_key( (string) ( $meta['backing_debt_scope'] ?? '' ) );

		return $row;
	}

	private function derive_schedule( $total_amount, array $data ) {
		$planning_mode      = sanitize_key( (string) ( $data['planning_mode'] ?? '' ) );
		$planning_value     = max( 0, (float) ( $data['planning_value'] ?? 0 ) );
		$target_installment = max( 0, (float) ( $data['target_installment_amount'] ?? 0 ) );
		$installment_count  = max( 0, (int) ( $data['installment_count'] ?? 0 ) );

		if ( '' === $planning_mode ) {
			$planning_mode = $installment_count > 0 ? 'period_count' : ( $target_installment > 0 ? 'period_amount' : 'single_period' );
		}

		switch ( $planning_mode ) {
			case 'period_amount':
				$target_installment = $planning_value > 0 ? $planning_value : $target_installment;
				if ( $target_installment <= 0 ) {
					return $this->error( 'asdl_fin_plan_period_amount', 'Debes indicar cuanto quieres descontar o pagar por periodo.' );
				}
				$installment_count = max( 1, (int) ceil( $total_amount / $target_installment ) );
				break;

			case 'period_count':
				$installment_count = $planning_value > 0 ? max( 1, (int) ceil( $planning_value ) ) : $installment_count;
				if ( $installment_count <= 0 ) {
					return $this->error( 'asdl_fin_plan_period_count', 'Debes indicar en cuantos periodos quieres resolver el compromiso.' );
				}
				$target_installment = round( $total_amount / $installment_count, 6 );
				break;

			case 'single_period':
				$installment_count  = 1;
				$target_installment = $total_amount;
				$planning_value     = $total_amount;
				break;

			default:
				if ( $installment_count <= 0 && $target_installment > 0 ) {
					$installment_count = max( 1, (int) ceil( $total_amount / $target_installment ) );
					$planning_mode     = 'period_amount';
					$planning_value    = $target_installment;
				} elseif ( $installment_count > 0 && $target_installment <= 0 ) {
					$target_installment = round( $total_amount / $installment_count, 6 );
					$planning_mode      = 'period_count';
					$planning_value     = $installment_count;
				} else {
					$installment_count  = 1;
					$target_installment = $total_amount;
					$planning_mode      = 'single_period';
					$planning_value     = $total_amount;
				}
				break;
		}

		$installment_amounts = $this->build_installment_amounts( $total_amount, $installment_count, $target_installment, $planning_mode );

		return array(
			'planning_mode'             => $planning_mode,
			'planning_value'            => $planning_value,
			'target_installment_amount' => $target_installment,
			'installment_count'         => $installment_count,
			'installment_amounts'       => $installment_amounts,
			'regular_period_amount'     => ! empty( $installment_amounts ) ? (float) $installment_amounts[0] : 0.0,
			'first_period_amount'       => ! empty( $installment_amounts ) ? (float) $installment_amounts[0] : 0.0,
			'last_period_amount'        => ! empty( $installment_amounts ) ? (float) end( $installment_amounts ) : 0.0,
		);
	}

	private function build_installment_amounts( $total_amount, $installment_count, $target_installment, $planning_mode ) {
		$total_amount      = max( 0, (float) $total_amount );
		$installment_count = max( 1, (int) $installment_count );
		$target_installment = max( 0, (float) $target_installment );

		if ( 'period_amount' === $planning_mode && $target_installment > 0 ) {
			$remaining = $total_amount;
			$amounts   = array();

			while ( $remaining > 0.000001 ) {
				$amounts[] = round( min( $target_installment, $remaining ), 6 );
				$remaining = round( $remaining - end( $amounts ), 6 );
			}

			return $amounts;
		}

		$base_amount = round( $total_amount / $installment_count, 6 );
		$amounts     = array();
		$accumulated = 0.0;

		for ( $index = 1; $index <= $installment_count; $index++ ) {
			$amounts[]  = $index === $installment_count ? round( $total_amount - $accumulated, 6 ) : $base_amount;
			$accumulated += (float) end( $amounts );
		}

		return $amounts;
	}

	private function normalize_settlement_direction( $direction, $origin = '' ) {
		$direction = sanitize_key( (string) $direction );
		if ( in_array( $direction, array( 'receivable', 'payable' ), true ) ) {
			return $direction;
		}

		$origin = sanitize_key( (string) $origin );

		return 'company_debt' === $origin ? 'payable' : 'receivable';
	}

	private function normalize_collection_mode( $mode, $direction, $contact_id = 0 ) {
		$mode       = sanitize_key( (string) $mode );
		$direction  = $this->normalize_settlement_direction( $direction );
		$is_employee = false;

		if ( $contact_id > 0 ) {
			$contact = ( new ContactsRepository() )->find( $contact_id );
			$is_employee = ! empty( $contact['is_employee'] );
		}

		if ( ! in_array( $mode, array( 'manual', 'payroll_deduction', 'payroll_disbursement', 'mixed' ), true ) ) {
			$mode = 'manual';
		}

		if ( ! $is_employee && in_array( $mode, array( 'payroll_deduction', 'payroll_disbursement', 'mixed' ), true ) ) {
			return 'manual';
		}

		if ( 'payable' === $direction && 'payroll_deduction' === $mode ) {
			return $is_employee ? 'payroll_disbursement' : 'manual';
		}

		if ( 'receivable' === $direction && 'payroll_disbursement' === $mode ) {
			return $is_employee ? 'payroll_deduction' : 'manual';
		}

		return $mode;
	}

	private function resolve_effective_frequency( $frequency_key, $collection_mode, $employee_profile ) {
		$frequency_key = sanitize_key( (string) $frequency_key );

		if (
			is_array( $employee_profile ) &&
			! empty( $employee_profile['pay_frequency'] ) &&
			in_array( $collection_mode, array( 'payroll_deduction', 'payroll_disbursement', 'mixed' ), true )
		) {
			return sanitize_key( (string) $employee_profile['pay_frequency'] );
		}

		return in_array( $frequency_key, array( 'weekly', 'biweekly', 'monthly', 'quarterly' ), true ) ? $frequency_key : 'monthly';
	}

	private function resolve_effective_start_date( $start_date, $collection_mode, $employee_profile ) {
		$start_date = $this->sanitize_date( $start_date );

		if ( $start_date ) {
			return $start_date;
		}

		if (
			is_array( $employee_profile ) &&
			! empty( $employee_profile['next_payment_date'] ) &&
			in_array( $collection_mode, array( 'payroll_deduction', 'payroll_disbursement', 'mixed' ), true )
		) {
			return $this->sanitize_date( $employee_profile['next_payment_date'] );
		}

		return gmdate( 'Y-m-d' );
	}
}
