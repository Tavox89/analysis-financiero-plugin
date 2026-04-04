<?php

namespace ASDLabs\Finance\Finance;

use DateInterval;
use DateTimeImmutable;

final class PayrollPeriodsRepository extends BaseRepository {
	protected $table_key = 'payroll_periods';

	public function find( $payroll_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$payroll_id = absint( $payroll_id );
		if ( $payroll_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$payroll_id
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
				ORDER BY scheduled_payment_date DESC, id DESC
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

	public function latest_by_contacts( array $contact_ids, $limit_per_contact = 2 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );

		if ( empty( $contact_ids ) ) {
			return array();
		}

		$limit_per_contact = max( 1, min( 5, (int) $limit_per_contact ) );
		$placeholders      = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$rows              = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE contact_id IN ({$placeholders})
				ORDER BY contact_id ASC, scheduled_payment_date DESC, id DESC",
				...$contact_ids
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$items = array();

		foreach ( $rows as $row ) {
			$contact_id = isset( $row['contact_id'] ) ? (int) $row['contact_id'] : 0;
			if ( $contact_id <= 0 ) {
				continue;
			}

			if ( ! isset( $items[ $contact_id ] ) ) {
				$items[ $contact_id ] = array();
			}

			if ( count( $items[ $contact_id ] ) >= $limit_per_contact ) {
				continue;
			}

			$items[ $contact_id ][] = $this->hydrate_row( $row );
		}

		return $items;
	}

	public function find_planned_for_contact( $contact_id, $scheduled_payment_date = '' ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return null;
		}

		$where_sql = 'WHERE contact_id = %d AND status = %s';
		$params    = array( $contact_id, 'planned' );

		$scheduled_payment_date = $this->sanitize_date( $scheduled_payment_date );
		if ( $scheduled_payment_date ) {
			$where_sql .= ' AND scheduled_payment_date = %s';
			$params[] = $scheduled_payment_date;
		}

		$params[] = 1;

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				{$where_sql}
				ORDER BY scheduled_payment_date ASC, id ASC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_row( $row );
	}

	public function find_actionable_for_contact( $contact_id, $scheduled_payment_date = '', $grace_days = 2 ) {
		$exact_match = $this->find_planned_for_contact( $contact_id, $scheduled_payment_date );
		if ( ! empty( $exact_match['id'] ) ) {
			return $exact_match;
		}

		if ( ! $this->has_table() ) {
			return null;
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return null;
		}

		$scheduled_payment_date = $this->sanitize_date( $scheduled_payment_date );
		$grace_days             = max( 0, min( 7, (int) $grace_days ) );

		$where_sql = 'WHERE contact_id = %d AND status = %s';
		$params    = array( $contact_id, 'planned' );

		if ( $scheduled_payment_date ) {
			try {
				$scheduled  = new DateTimeImmutable( $scheduled_payment_date );
				$range_from = $scheduled->sub( new DateInterval( 'P' . $grace_days . 'D' ) )->format( 'Y-m-d' );
				$range_to   = $scheduled->add( new DateInterval( 'P' . $grace_days . 'D' ) )->format( 'Y-m-d' );

				$where_sql .= ' AND ((scheduled_payment_date >= %s AND scheduled_payment_date <= %s) OR (period_start <= %s AND period_end >= %s))';
				$params[]   = $range_from;
				$params[]   = $range_to;
				$params[]   = $scheduled_payment_date;
				$params[]   = $scheduled_payment_date;
			} catch ( \Exception $e ) {
				$scheduled_payment_date = null;
			}
		}

		$params[] = 1;

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				{$where_sql}
				ORDER BY scheduled_payment_date ASC, id ASC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_row( $row );
	}

	public function summary_for_contact( $contact_id ) {
		$empty = array(
			'period_count'              => 0,
			'planned_count'             => 0,
			'paid_count'                => 0,
			'gross_total'               => 0,
			'advance_deduction_total'   => 0,
			'commitment_deduction_total'=> 0,
			'commitment_payout_total'   => 0,
			'net_total'                 => 0,
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
					COUNT(*) AS period_count,
					COALESCE(SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END), 0) AS planned_count,
					COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
					COALESCE(SUM(gross_amount), 0) AS gross_total,
					COALESCE(SUM(advance_deduction_amount), 0) AS advance_deduction_total,
					COALESCE(SUM(net_amount), 0) AS net_total
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
			'period_count'            => isset( $row['period_count'] ) ? (int) $row['period_count'] : 0,
			'planned_count'           => isset( $row['planned_count'] ) ? (int) $row['planned_count'] : 0,
			'paid_count'              => isset( $row['paid_count'] ) ? (int) $row['paid_count'] : 0,
			'gross_total'             => isset( $row['gross_total'] ) ? (float) $row['gross_total'] : 0,
			'advance_deduction_total' => isset( $row['advance_deduction_total'] ) ? (float) $row['advance_deduction_total'] : 0,
			'commitment_deduction_total' => $this->sum_commitment_deductions( $contact_id ),
			'commitment_payout_total' => $this->sum_commitment_payouts( $contact_id ),
			'net_total'               => isset( $row['net_total'] ) ? (float) $row['net_total'] : 0,
		);
	}

	public function create_for_contact( $contact_id, array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_payroll_periods_missing', 'La tabla de nomina aun no esta disponible.' );
		}

		$contact_id       = absint( $contact_id );
		$contact          = ( new ContactsRepository() )->find( $contact_id );
		$employee_profile = ( new EmployeeProfilesRepository() )->find_by_contact_id( $contact_id );

		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_payroll_contact', 'No se encontro el perfil del empleado.' );
		}

		if ( empty( $contact['is_employee'] ) || empty( $employee_profile['id'] ) ) {
			return $this->error( 'asdl_fin_payroll_employee_profile', 'Primero debes definir la configuracion laboral del empleado.' );
		}

		$payload = $this->sanitize_create_payload( $contact, $employee_profile, $data );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( $payload['gross_amount'] <= 0 ) {
			return $this->error( 'asdl_fin_payroll_gross', 'Debes indicar un monto bruto valido para la nomina.' );
		}

		if ( ! ( new PaymentMethodsService() )->is_valid_key( $payload['payment_method_key'] ) ) {
			return $this->error( 'asdl_fin_payroll_payment_method', 'Debes seleccionar un metodo de pago valido para este periodo de nomina.' );
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
			return $this->error( 'asdl_fin_payroll_insert', 'No se pudo guardar el periodo de nomina.' );
		}

		PayrollQueueService::bump_cache_version();

		return (int) $this->db()->insert_id;
	}

	public function mark_paid( $payroll_id, array $data = array() ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_payroll_periods_missing', 'La tabla de nomina aun no esta disponible.' );
		}

		$payroll = $this->find( $payroll_id );
		if ( empty( $payroll['id'] ) ) {
			return $this->error( 'asdl_fin_payroll_missing', 'No se encontro el periodo de nomina solicitado.' );
		}

		if ( 'paid' === ( $payroll['status'] ?? '' ) ) {
			return $this->error( 'asdl_fin_payroll_paid', 'Este periodo de nomina ya fue procesado.' );
		}

		$documents         = new DocumentsRepository();
		$payments          = new PaymentsRepository();
		$allocations       = new PaymentAllocationService();
		$advances          = new EmployeeAdvancesRepository();
		$commitments       = new CommitmentSettlementService();
		$manual_settlement = new PayrollManualSettlementService();
		$employee_profiles = new EmployeeProfilesRepository();
		$payment_methods   = new PaymentMethodsService();
		$paid_at           = $this->sanitize_date( $data['paid_at'] ?? '' ) ?: ( $payroll['scheduled_payment_date'] ?? gmdate( 'Y-m-d' ) );
		$payment_account   = ! empty( $data['payment_account_id'] ) ? absint( $data['payment_account_id'] ) : ( ! empty( $payroll['payment_account_id'] ) ? absint( $payroll['payment_account_id'] ) : null );
		$payment_method    = sanitize_key( $data['payment_method_key'] ?? ( $payroll['payment_method_key'] ?? 'bank_transfer' ) );
		$payment_reference = sanitize_text_field( $data['reference'] ?? '' );
		$notes             = sanitize_textarea_field( $data['notes'] ?? ( $payroll['notes'] ?? '' ) );
		$gross_amount      = (float) ( $payroll['gross_amount'] ?? 0 );
		$other_deductions  = (float) ( $payroll['other_deduction_amount'] ?? 0 );
		$meta              = is_array( $payroll['meta'] ?? null ) ? $payroll['meta'] : array();
		$planned_commitments = is_array( $meta['commitment_breakdown'] ?? null ) ? $meta['commitment_breakdown'] : array();
		$planned_commitment_payouts = is_array( $meta['commitment_payout_breakdown'] ?? null ) ? $meta['commitment_payout_breakdown'] : array();
		$currency          = $payroll['currency'] ?? 'USD';
		$document_id       = ! empty( $payroll['document_id'] ) ? absint( $payroll['document_id'] ) : 0;
		$cash_payment_id   = 0;
		$advance_deduction = 0.0;
		$commitment_deduction = 0.0;
		$commitment_payout = 0.0;
		$manual_settlement_amount = 0.0;
		$applied_advances  = array();
		$applied_manual_settlement = array();

		if ( ! $payment_methods->is_valid_key( $payment_method ) ) {
			return $this->error( 'asdl_fin_payroll_payment_method', 'Debes seleccionar un metodo de pago valido para procesar la nomina.' );
		}

		$current_commitments = $commitments->preview_payroll_deductions(
			(int) $payroll['contact_id'],
			$paid_at,
			max( 0, $gross_amount - $other_deductions )
		);
		$current_commitment_payouts = $commitments->preview_payroll_disbursements(
			(int) $payroll['contact_id'],
			$paid_at
		);

		if ( ! empty( $current_commitments['items'] ) ) {
			$planned_commitments = $current_commitments['items'];
		}
		if ( ! empty( $current_commitment_payouts['items'] ) ) {
			$planned_commitment_payouts = $current_commitment_payouts['items'];
		}

		foreach ( $planned_commitments as $commitment_item ) {
			$planned_amount = max( 0, (float) ( $commitment_item['planned_amount'] ?? 0 ) );
			if ( $planned_amount <= 0 ) {
				continue;
			}

			$commitment_deduction += $planned_amount;
		}

		foreach ( $planned_commitment_payouts as $commitment_item ) {
			$planned_amount = max( 0, (float) ( $commitment_item['planned_amount'] ?? 0 ) );
			if ( $planned_amount <= 0 ) {
				continue;
			}

			$commitment_payout += $planned_amount;
		}

		$eligible_advances      = $advances->eligible_for_payroll( (int) $payroll['contact_id'], $paid_at );
		$available_for_advances = max( 0, $gross_amount - $other_deductions - $commitment_deduction );

		foreach ( $eligible_advances as $advance ) {
			if ( $advance_deduction >= $available_for_advances ) {
				break;
			}

			if ( empty( $advance['id'] ) || empty( $advance['payment_id'] ) || (float) ( $advance['balance'] ?? 0 ) <= 0 ) {
				continue;
			}

			$apply_amount = min( (float) ( $advance['balance'] ?? 0 ), $available_for_advances - $advance_deduction );
			if ( $apply_amount <= 0 ) {
				continue;
			}

			$advance_deduction += $apply_amount;
			$applied_advances[] = array(
				'advance_id' => (int) $advance['id'],
				'payment_id' => (int) $advance['payment_id'],
				'amount'     => $apply_amount,
			);
		}

		$document_total = max( 0, $gross_amount - $other_deductions - $commitment_deduction );
		$salary_cash_amount = max( 0, $gross_amount - $other_deductions - $advance_deduction - $commitment_deduction );
		$manual_selection = $manual_settlement->normalize_manual_settlement(
			(int) $payroll['contact_id'],
			$data
		);
		if ( is_wp_error( $manual_selection ) ) {
			return $manual_selection;
		}
		if ( ! empty( $manual_selection['enabled'] ) ) {
			$manual_settlement_amount = max( 0, (float) ( $manual_selection['amount'] ?? 0 ) );
			if ( $manual_settlement_amount > $salary_cash_amount + 0.00001 ) {
				return $this->error( 'asdl_fin_payroll_manual_amount', 'El abono manual supera el neto disponible para descontar en esta nomina.' );
			}

			$document_total    = max( 0, $document_total - $manual_settlement_amount );
			$salary_cash_amount = max( 0, $salary_cash_amount - $manual_settlement_amount );
		}

		$net_amount     = $salary_cash_amount + $commitment_payout;

		$this->begin_transaction();

		if ( $document_id <= 0 ) {
			$document_id = $documents->create(
				array(
					'document_type'    => 'salary_expense',
					'source_type'      => 'manual',
					'account_id'       => $payment_account,
					'contact_id'       => (int) $payroll['contact_id'],
					'wp_user_id'       => ! empty( $payroll['wp_user_id'] ) ? (int) $payroll['wp_user_id'] : null,
					'title'            => $payroll['title'],
					'external_reference' => sprintf( 'payroll-period-%d', (int) $payroll['id'] ),
					'issue_date'       => $paid_at,
					'due_date'         => $paid_at,
					'currency'         => $currency,
					'total'            => $document_total,
					'paid_total'       => 0,
					'payment_status'   => 'pending',
					'financial_status' => 'posted',
					'notes'            => $notes,
				)
			);

			if ( is_wp_error( $document_id ) ) {
				$this->rollback_transaction();
				return $document_id;
			}
		}

		if ( $salary_cash_amount > 0 ) {
			$cash_payment_id = $payments->create(
				array(
					'payment_type' => 'disbursement',
					'account_id'   => $payment_account,
					'contact_id'   => (int) $payroll['contact_id'],
					'status'       => 'posted',
					'payment_date' => $paid_at,
					'currency'     => $currency,
					'total'        => $salary_cash_amount,
					'method_key'   => $payment_method,
					'reference'    => '' !== $payment_reference ? $payment_reference : sprintf( 'Nomina %s', $payroll['title'] ),
					'notes'        => $notes,
				)
			);

			if ( is_wp_error( $cash_payment_id ) ) {
				$this->rollback_transaction();
				return $cash_payment_id;
			}

			$allocation_result = $allocations->allocate(
				array(
					'payment_id'          => (int) $cash_payment_id,
					'document_id'         => (int) $document_id,
					'amount'              => $salary_cash_amount,
					'notes'               => 'Pago neto de nomina',
					'manage_transaction'  => false,
				)
			);

			if ( is_wp_error( $allocation_result ) ) {
				$this->rollback_transaction();
				return $allocation_result;
			}
		}

		foreach ( $applied_advances as $advance_data ) {
			$allocation_result = $allocations->allocate(
				array(
					'payment_id'         => (int) $advance_data['payment_id'],
					'document_id'        => (int) $document_id,
					'amount'             => (float) $advance_data['amount'],
					'notes'              => 'Compensacion de adelanto de sueldo',
					'allow_non_available_payment' => true,
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $allocation_result ) ) {
				$this->rollback_transaction();
				return $allocation_result;
			}

			$updated_advance = $advances->apply_recovery(
				(int) $advance_data['advance_id'],
				(float) $advance_data['amount'],
				array(
					'payroll_id'    => (int) $payroll['id'],
					'document_id'   => (int) $document_id,
					'paid_at'       => $paid_at,
				)
			);

			if ( is_wp_error( $updated_advance ) ) {
				$this->rollback_transaction();
				return $updated_advance;
			}
		}

		$applied_commitments = array();
		$applied_commitment_payouts = array();
		foreach ( $planned_commitments as $commitment_item ) {
			$planned_amount = max( 0, (float) ( $commitment_item['planned_amount'] ?? 0 ) );
			if ( $planned_amount <= 0 ) {
				continue;
			}

			$commitment_result = $commitments->apply(
				array(
					'plan_id'             => (int) ( $commitment_item['plan_id'] ?? 0 ),
					'amount'              => $planned_amount,
					'create_payment'      => true,
					'payment_type'        => 'adjustment',
					'account_id'          => $payment_account,
					'payment_date'        => $paid_at,
					'currency'            => $currency,
					'method_key'          => 'payroll_deduction',
					'reference'           => sprintf( 'Descuento por sueldo %s', $payroll['title'] ),
					'notes'               => 'Descuento de compromiso aplicado desde nomina.',
					'origin'              => 'payroll_deduction',
					'manage_transaction'  => false,
				)
			);

			if ( is_wp_error( $commitment_result ) ) {
				$this->rollback_transaction();
				return $commitment_result;
			}

			$applied_commitments[] = array(
				'plan_id'          => (int) $commitment_result['plan_id'],
				'payment_id'       => (int) $commitment_result['payment_id'],
				'applied_total'    => (float) $commitment_result['applied_total'],
				'plan_balance'     => (float) $commitment_result['plan_balance'],
				'settlement_direction' => sanitize_key( (string) ( $commitment_result['settlement_direction'] ?? 'receivable' ) ),
				'collection_mode'  => sanitize_key( (string) ( $commitment_item['collection_mode'] ?? '' ) ),
				'commitment_origin'=> sanitize_key( (string) ( $commitment_item['commitment_origin'] ?? '' ) ),
				'title'            => sanitize_text_field( (string) ( $commitment_item['title'] ?? '' ) ),
			);
		}

		foreach ( $planned_commitment_payouts as $commitment_item ) {
			$planned_amount = max( 0, (float) ( $commitment_item['planned_amount'] ?? 0 ) );
			if ( $planned_amount <= 0 ) {
				continue;
			}

			$commitment_result = $commitments->apply(
				array(
					'plan_id'             => (int) ( $commitment_item['plan_id'] ?? 0 ),
					'amount'              => $planned_amount,
					'create_payment'      => true,
					'payment_type'        => 'disbursement',
					'account_id'          => $payment_account,
					'payment_date'        => $paid_at,
					'currency'            => $currency,
					'method_key'          => 'payroll',
					'reference'           => sprintf( 'Pago por nomina %s', $payroll['title'] ),
					'notes'               => 'Pago de compromiso a favor del empleado aplicado desde nomina.',
					'origin'              => 'payroll_disbursement',
					'manage_transaction'  => false,
				)
			);

			if ( is_wp_error( $commitment_result ) ) {
				$this->rollback_transaction();
				return $commitment_result;
			}

			$applied_commitment_payouts[] = array(
				'plan_id'          => (int) $commitment_result['plan_id'],
				'payment_id'       => (int) $commitment_result['payment_id'],
				'applied_total'    => (float) $commitment_result['applied_total'],
				'plan_balance'     => (float) $commitment_result['plan_balance'],
				'settlement_direction' => sanitize_key( (string) ( $commitment_result['settlement_direction'] ?? 'payable' ) ),
				'collection_mode'  => sanitize_key( (string) ( $commitment_item['collection_mode'] ?? '' ) ),
				'commitment_origin'=> sanitize_key( (string) ( $commitment_item['commitment_origin'] ?? '' ) ),
				'title'            => sanitize_text_field( (string) ( $commitment_item['title'] ?? '' ) ),
			);
		}

		if ( ! empty( $manual_selection['enabled'] ) ) {
			$manual_result = $manual_settlement->apply_manual_settlement(
				(int) $payroll['contact_id'],
				$manual_selection,
				array(
					'account_id'    => $payment_account,
					'currency'      => $currency,
					'payment_date'  => $paid_at,
					'payroll_id'    => (int) $payroll['id'],
					'payroll_title' => sanitize_text_field( (string) ( $payroll['title'] ?? '' ) ),
				)
			);

			if ( is_wp_error( $manual_result ) ) {
				$this->rollback_transaction();
				return $manual_result;
			}

			$applied_manual_settlement = array(
				'target_type'          => sanitize_key( (string) ( $manual_selection['target_type'] ?? '' ) ),
				'target_id'            => (int) ( $manual_selection['target_id'] ?? 0 ),
				'label'                => sanitize_text_field( (string) ( $manual_selection['label'] ?? '' ) ),
				'kind_label'           => sanitize_text_field( (string) ( $manual_selection['kind_label'] ?? '' ) ),
				'destination_kind'     => sanitize_key( (string) ( $manual_selection['destination_kind'] ?? '' ) ),
				'amount'               => $manual_settlement_amount,
				'force_dual'           => ! empty( $manual_selection['force_dual'] ),
				'preview_signature'    => sanitize_text_field( (string) ( $manual_selection['preview_signature'] ?? '' ) ),
				'payment_id'           => ! empty( $manual_result['payment_id'] ) ? (int) $manual_result['payment_id'] : 0,
				'child_payment_ids'    => array_values( array_map( 'intval', (array) ( $manual_result['dual_discount_payment_ids'] ?? array() ) ) ),
				'applied_total'        => round( (float) ( $manual_result['applied_total'] ?? $manual_settlement_amount ), 6 ),
				'unapplied_total'      => round( (float) ( $manual_result['unapplied_total'] ?? 0 ), 6 ),
				'dual_discount_applied'=> ! empty( $manual_result['dual_discount_applied'] ),
				'dual_discount_total'  => round( (float) ( $manual_result['dual_discount_total'] ?? 0 ), 6 ),
				'dual_discount_percent'=> round( (float) ( $manual_result['dual_discount_percent'] ?? 0 ), 6 ),
				'closed_order_ids'     => array_values( array_map( 'intval', (array) ( $manual_result['closed_order_ids'] ?? array() ) ) ),
				'partial_order_ids'    => array_values( array_map( 'intval', (array) ( $manual_result['partial_order_ids'] ?? array() ) ) ),
			);
		}

		$meta['applied_advance_breakdown'] = $applied_advances;
		$meta['commitment_breakdown']      = $planned_commitments;
		$meta['applied_commitment_breakdown'] = $applied_commitments;
		$meta['commitment_deduction_total']   = $commitment_deduction;
		$meta['commitment_payout_breakdown']  = $planned_commitment_payouts;
		$meta['applied_commitment_payout_breakdown'] = $applied_commitment_payouts;
		$meta['commitment_payout_total']      = $commitment_payout;
		$meta['manual_settlement']            = $manual_selection;
		$meta['manual_settlement_total']      = $manual_settlement_amount;
		$meta['applied_manual_settlement']    = $applied_manual_settlement;
		$update_result = $this->db()->update(
			$this->table(),
			array(
				'paid_at'                  => $paid_at,
				'payment_account_id'       => $payment_account,
				'payment_method_key'       => $payment_method,
				'advance_deduction_amount' => $advance_deduction,
				'net_amount'               => $net_amount,
				'status'                   => 'paid',
				'document_id'              => (int) $document_id,
				'payment_id'               => $cash_payment_id ? (int) $cash_payment_id : null,
				'notes'                    => $notes,
				'meta_json'                => wp_json_encode( $meta ),
				'updated_at'               => $this->now(),
			),
			array( 'id' => (int) $payroll['id'] ),
			array( '%s', '%d', '%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $update_result ) {
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_payroll_update', 'No se pudo cerrar el periodo de nomina.' );
		}

		$employee_profiles->register_payroll_payment( (int) $payroll['contact_id'], $paid_at );
		$this->commit_transaction();
		PayrollQueueService::bump_cache_version();

		return $this->find( $payroll['id'] );
	}

	private function sanitize_create_payload( array $contact, array $employee_profile, array $data ) {
		$frequency_key           = sanitize_key( $employee_profile['pay_frequency'] ?? 'monthly' );
		$scheduled_payment_date  = $this->sanitize_date( $data['scheduled_payment_date'] ?? ( $employee_profile['next_payment_date'] ?? '' ) ) ?: gmdate( 'Y-m-d' );
		$period_window           = $this->resolve_period_window( $frequency_key, $data['period_start'] ?? '', $data['period_end'] ?? '', $scheduled_payment_date );
		$gross_amount            = max( 0, (float) ( $data['gross_amount'] ?? ( $employee_profile['salary_amount'] ?? 0 ) ) );
		$other_deduction_amount  = max( 0, (float) ( $data['other_deduction_amount'] ?? 0 ) );
		$commitments = new CommitmentSettlementService();
		$commitment_preview = $commitments->preview_payroll_deductions(
			(int) $contact['id'],
			$scheduled_payment_date,
			max( 0, $gross_amount - $other_deduction_amount )
		);
		$commitment_payment_preview = $commitments->preview_payroll_disbursements(
			(int) $contact['id'],
			$scheduled_payment_date
		);
		$eligible_advances       = ( new EmployeeAdvancesRepository() )->eligible_for_payroll( (int) $contact['id'], $scheduled_payment_date );
		$available_for_advances  = max( 0, $gross_amount - $other_deduction_amount - (float) ( $commitment_preview['planned_total'] ?? 0 ) );
		$advance_deduction       = 0.0;
		$advance_breakdown       = array();

		foreach ( $eligible_advances as $advance ) {
			if ( $advance_deduction >= $available_for_advances ) {
				break;
			}

			$apply_amount = min( (float) $advance['balance'], $available_for_advances - $advance_deduction );

			if ( $apply_amount <= 0 ) {
				continue;
			}

			$advance_deduction += $apply_amount;
			$advance_breakdown[] = array(
				'advance_id'     => (int) $advance['id'],
				'payment_id'     => (int) ( $advance['payment_id'] ?? 0 ),
				'planned_amount' => $apply_amount,
				'balance'        => (float) $advance['balance'],
			);
		}

		$commitment_deduction = max( 0, (float) ( $commitment_preview['planned_total'] ?? 0 ) );
		$commitment_payout    = max( 0, (float) ( $commitment_payment_preview['planned_total'] ?? 0 ) );
		$net_amount           = max( 0, $gross_amount - $other_deduction_amount - $commitment_deduction - $advance_deduction ) + $commitment_payout;
		$title      = sanitize_text_field( $data['title'] ?? '' );

		if ( '' === $title ) {
			$title = $this->default_title( $frequency_key, $period_window['period_start'], $period_window['period_end'] );
		}

		$currency = $this->sanitize_currency_code(
			$data['currency'] ?? '',
			$employee_profile['salary_currency'] ?? 'USD',
			'asdl_fin_payroll_currency',
			'Debes seleccionar una moneda valida para el periodo de nomina.'
		);
		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		return array(
			'contact_id'                => (int) $contact['id'],
			'wp_user_id'                => ! empty( $contact['wp_user_id'] ) ? absint( $contact['wp_user_id'] ) : null,
			'employee_profile_id'       => (int) $employee_profile['id'],
			'title'                     => $title,
			'frequency_key'             => $frequency_key,
			'period_start'              => $period_window['period_start'],
			'period_end'                => $period_window['period_end'],
			'scheduled_payment_date'    => $scheduled_payment_date,
			'paid_at'                   => null,
			'payment_account_id'        => ! empty( $data['payment_account_id'] ) ? absint( $data['payment_account_id'] ) : ( ! empty( $employee_profile['default_account_id'] ) ? absint( $employee_profile['default_account_id'] ) : null ),
			'payment_method_key'        => sanitize_key( $data['payment_method_key'] ?? 'bank_transfer' ),
			'gross_amount'              => $gross_amount,
			'advance_deduction_amount'  => $advance_deduction,
			'other_deduction_amount'    => $other_deduction_amount,
			'net_amount'                => $net_amount,
			'currency'                  => $currency,
			'status'                    => 'planned',
			'document_id'               => null,
			'payment_id'                => null,
			'notes'                     => sanitize_textarea_field( $data['notes'] ?? '' ),
			'meta_json'                 => wp_json_encode(
				array(
					'advance_breakdown'       => $advance_breakdown,
					'commitment_breakdown'    => $commitment_preview['items'] ?? array(),
					'commitment_deduction_total' => $commitment_deduction,
					'commitment_payout_breakdown' => $commitment_payment_preview['items'] ?? array(),
					'commitment_payout_total' => $commitment_payout,
				)
			),
		);
	}

	private function resolve_period_window( $frequency_key, $period_start, $period_end, $scheduled_payment_date ) {
		$scheduled = new DateTimeImmutable( $scheduled_payment_date );
		$end       = $this->sanitize_date( $period_end ) ?: $scheduled->format( 'Y-m-d' );
		$start     = $this->sanitize_date( $period_start );

		if ( ! $start ) {
			switch ( $frequency_key ) {
				case 'weekly':
					$start = $scheduled->sub( new DateInterval( 'P6D' ) )->format( 'Y-m-d' );
					break;
				case 'biweekly':
					$start = $scheduled->sub( new DateInterval( 'P13D' ) )->format( 'Y-m-d' );
					break;
				case 'monthly':
				default:
					$start = $scheduled->modify( 'first day of this month' )->format( 'Y-m-d' );
					break;
			}
		}

		if ( $start > $end ) {
			$temp  = $start;
			$start = $end;
			$end   = $temp;
		}

		return array(
			'period_start' => $start,
			'period_end'   => $end,
		);
	}

	private function default_title( $frequency_key, $period_start, $period_end ) {
		return sprintf(
			'Nomina %s %s al %s',
			$this->frequency_label( $frequency_key ),
			$period_start,
			$period_end
		);
	}

	private function frequency_label( $frequency_key ) {
		switch ( sanitize_key( (string) $frequency_key ) ) {
			case 'weekly':
				return 'semanal';
			case 'biweekly':
				return 'quincenal';
			case 'monthly':
			default:
				return 'mensual';
		}
	}

	private function hydrate_row( array $row ) {
		$row['id']                        = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['contact_id']                = isset( $row['contact_id'] ) ? (int) $row['contact_id'] : 0;
		$row['wp_user_id']                = ! empty( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;
		$row['employee_profile_id']       = isset( $row['employee_profile_id'] ) ? (int) $row['employee_profile_id'] : 0;
		$row['payment_account_id']        = ! empty( $row['payment_account_id'] ) ? (int) $row['payment_account_id'] : 0;
		$row['document_id']               = ! empty( $row['document_id'] ) ? (int) $row['document_id'] : 0;
		$row['payment_id']                = ! empty( $row['payment_id'] ) ? (int) $row['payment_id'] : 0;
		$row['gross_amount']              = isset( $row['gross_amount'] ) ? (float) $row['gross_amount'] : 0;
		$row['advance_deduction_amount']  = isset( $row['advance_deduction_amount'] ) ? (float) $row['advance_deduction_amount'] : 0;
		$row['other_deduction_amount']    = isset( $row['other_deduction_amount'] ) ? (float) $row['other_deduction_amount'] : 0;
		$row['net_amount']                = isset( $row['net_amount'] ) ? (float) $row['net_amount'] : 0;
		$row['meta']                      = $this->decode_meta_json( $row['meta_json'] ?? '' );
		$row['commitment_deduction_amount'] = isset( $row['meta']['commitment_deduction_total'] ) ? (float) $row['meta']['commitment_deduction_total'] : 0;
		$row['commitment_payout_amount']  = isset( $row['meta']['commitment_payout_total'] ) ? (float) $row['meta']['commitment_payout_total'] : 0;

		return $row;
	}

	private function sum_commitment_deductions( $contact_id ) {
		if ( ! $this->has_table() ) {
			return 0;
		}

		$rows = $this->db()->get_col(
			$this->db()->prepare(
				"SELECT meta_json
				FROM {$this->table()}
				WHERE contact_id = %d",
				absint( $contact_id )
			)
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$total = 0.0;
		foreach ( $rows as $meta_json ) {
			$meta = $this->decode_meta_json( $meta_json );
			$total += max( 0, (float) ( $meta['commitment_deduction_total'] ?? 0 ) );
		}

		return round( $total, 6 );
	}

	private function sum_commitment_payouts( $contact_id ) {
		if ( ! $this->has_table() ) {
			return 0;
		}

		$rows = $this->db()->get_col(
			$this->db()->prepare(
				"SELECT meta_json
				FROM {$this->table()}
				WHERE contact_id = %d",
				absint( $contact_id )
			)
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$total = 0.0;
		foreach ( $rows as $meta_json ) {
			$meta = $this->decode_meta_json( $meta_json );
			$total += max( 0, (float) ( $meta['commitment_payout_total'] ?? 0 ) );
		}

		return round( $total, 6 );
	}

	private function decode_meta_json( $meta_json ) {
		$decoded = json_decode( (string) $meta_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function formats() {
		return array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%d', '%d', '%s', '%s' );
	}
}
