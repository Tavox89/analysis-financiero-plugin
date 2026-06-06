<?php

namespace ASDLabs\Finance\Finance;

final class PayrollQueueService {
	const CACHE_TTL = 60;

	private static function cache_bust_key() {
		return 'asdl_fin_payroll_queue_version';
	}

	public static function bump_cache_version() {
		set_transient(
			self::cache_bust_key(),
			(string) microtime( true ),
			30 * DAY_IN_SECONDS
		);
	}

	private function cache_version() {
		$version = get_transient( self::cache_bust_key() );

		return is_scalar( $version ) && '' !== (string) $version ? (string) $version : '0';
	}

	public function get_snapshot( array $args = array() ) {
		$from_date          = $this->sanitize_date( $args['from_date'] ?? '' ) ?: gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to_date            = $this->sanitize_date( $args['to_date'] ?? '' ) ?: gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$limit              = max( 1, min( 200, (int) ( $args['limit'] ?? 80 ) ) );
		$include_manual_debt_summary = ! isset( $args['include_manual_debt_summary'] ) || (bool) $args['include_manual_debt_summary'];
		$cache_key          = 'asdl_fin_payroll_queue_' . md5(
			wp_json_encode(
				array(
					'from'                     => $from_date,
					'to'                       => $to_date,
					'limit'                    => $limit,
					'version'                  => $this->cache_version(),
					'include_manual_debt_summary' => $include_manual_debt_summary ? 1 : 0,
				)
			)
		);
		$cached             = get_transient( $cache_key );
		$profiles_repository = new EmployeeProfilesRepository();
		$periods_repository  = new PayrollPeriodsRepository();
		$advances_repository = new EmployeeAdvancesRepository();
		$commitments         = new CommitmentSettlementService();
		$manual_settlements  = $include_manual_debt_summary ? new PayrollManualSettlementService() : null;
		$profile_counts      = $profiles_repository->summary_counts();
		$items               = array();
		$queued_contact_ids  = array();

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( $from_date > $to_date ) {
			$temp      = $from_date;
			$from_date = $to_date;
			$to_date   = $temp;
		}

		$profiles = $profiles_repository->upcoming_payroll_queue( $from_date, $to_date, $limit );
		foreach ( $profiles as $profile ) {
			$contact_id        = (int) ( $profile['contact_id'] ?? 0 );
			$scheduled_date    = $profile['next_payment_date'] ?? gmdate( 'Y-m-d' );
			$commitment_context = $this->build_payroll_commitment_context( $contact_id );
			$planned_period    = $periods_repository->find_actionable_for_contact( $contact_id, $scheduled_date, 2 );
			$eligible_advances = $advances_repository->eligible_for_payroll( $contact_id, $scheduled_date );
			$gross_amount      = (float) ( $profile['salary_amount'] ?? 0 );
			$commitment_preview = $commitments->preview_payroll_deductions(
				$contact_id,
				$scheduled_date,
				$gross_amount
			);
			$advance_preview   = $this->sum_eligible_advances(
				$eligible_advances,
				max( 0, $gross_amount - (float) ( $commitment_preview['planned_total'] ?? 0 ) )
			);
			$commitment_payment_preview = $commitments->preview_payroll_disbursements(
				(int) $profile['contact_id'],
				$scheduled_date
			);

			$salary_cash_preview = max( 0, $gross_amount - (float) ( $commitment_preview['planned_total'] ?? 0 ) - $advance_preview );
			$net_preview         = $salary_cash_preview
				+ (float) ( $commitment_payment_preview['planned_total'] ?? 0 );
			$manual_debt_summary = array(
				'has_open_debts' => false,
				'total_amount'   => 0,
				'target_count'   => 0,
			);
			if ( $manual_settlements ) {
				$manual_debt_snapshot = $manual_settlements->get_open_debts_for_contact( $contact_id );
				$manual_debt_summary  = is_wp_error( $manual_debt_snapshot )
					? $manual_debt_summary
					: (array) ( $manual_debt_snapshot['summary'] ?? array() );
			}

			$days_until_payment = $this->days_until_payment( $scheduled_date );
			$queue_action_mode  = ! empty( $planned_period['id'] )
				? 'process'
				: ( null !== $days_until_payment && $days_until_payment <= 2 ? 'prepare' : 'generate' );
			$queued_contact_ids[] = $contact_id;

			$items[] = array(
				'contact_id'              => $contact_id,
				'display_name'            => sanitize_text_field( (string) ( $profile['contact_display_name'] ?? '' ) ),
				'email'                   => sanitize_email( (string) ( $profile['contact_email'] ?? '' ) ),
				'profile_origin'          => sanitize_key( (string) ( $profile['contact_profile_origin'] ?? '' ) ),
				'employment_status'       => sanitize_key( (string) ( $profile['employment_status'] ?? '' ) ),
				'contract_status_key'     => sanitize_key( (string) ( $profile['contract_status_key'] ?? '' ) ),
				'next_payment_date'       => sanitize_text_field( (string) $scheduled_date ),
				'days_until_payment'      => $days_until_payment,
				'queue_action_mode'       => $queue_action_mode,
				'pay_frequency'           => sanitize_key( (string) ( $profile['pay_frequency'] ?? 'monthly' ) ),
				'salary_amount'           => $gross_amount,
				'salary_currency'         => sanitize_text_field( (string) ( $profile['salary_currency'] ?? 'USD' ) ),
				'default_account_id'      => ! empty( $profile['default_account_id'] ) ? (int) $profile['default_account_id'] : 0,
				'planned_period'          => $planned_period,
				'advance_deduction'       => $advance_preview,
				'commitment_deduction'    => (float) ( $commitment_preview['planned_total'] ?? 0 ),
				'commitment_payment'      => (float) ( $commitment_payment_preview['planned_total'] ?? 0 ),
				'salary_cash_preview'     => $salary_cash_preview,
				'net_preview'             => $net_preview,
				'advance_count'           => count( $eligible_advances ),
				'commitment_count'        => count( $commitment_preview['items'] ?? array() ),
				'commitment_payment_count'=> count( $commitment_payment_preview['items'] ?? array() ),
				'has_payroll_managed_commitments' => ! empty( $commitment_context['has_payroll_managed_commitments'] ),
				'next_commitment_due_date' => sanitize_text_field( (string) ( $commitment_context['next_due_date'] ?? '' ) ),
				'commitment_projection_message' => $this->build_projection_message(
					$scheduled_date,
					$commitment_context,
					(float) ( $commitment_preview['planned_total'] ?? 0 ),
					count( (array) ( $commitment_preview['items'] ?? array() ) )
				),
				'commitment_items'        => $commitment_preview['items'] ?? array(),
				'commitment_payment_items'=> $commitment_payment_preview['items'] ?? array(),
				'manual_debt_summary'     => $manual_debt_summary,
			);
		}

		$commitment_exclusions = $this->build_commitment_exclusions( $from_date, $to_date, $limit, $queued_contact_ids );

		$summary = array(
			'total_employee_count'   => (int) ( $profile_counts['total_count'] ?? 0 ),
			'active_employee_count'  => (int) ( $profile_counts['active_count'] ?? 0 ),
			'paused_employee_count'  => (int) ( $profile_counts['paused_count'] ?? 0 ),
			'ended_employee_count'   => (int) ( $profile_counts['ended_count'] ?? 0 ),
			'configured_count'       => (int) ( $profile_counts['configured_count'] ?? 0 ),
			'unconfigured_count'     => (int) ( $profile_counts['unconfigured_count'] ?? 0 ),
			'employee_count'         => count( $items ),
			'planned_period_count'   => count( array_filter( $items, static function ( array $item ) {
				return ! empty( $item['planned_period']['id'] );
			} ) ),
			'pending_generation_count' => count( array_filter( $items, static function ( array $item ) { return empty( $item['planned_period']['id'] ); } ) ),
			'gross_total'            => array_sum( array_map( static function ( array $item ) { return (float) ( $item['salary_amount'] ?? 0 ); }, $items ) ),
			'advance_deduction_total'=> array_sum( array_map( static function ( array $item ) { return (float) ( $item['advance_deduction'] ?? 0 ); }, $items ) ),
			'commitment_deduction_total' => array_sum( array_map( static function ( array $item ) { return (float) ( $item['commitment_deduction'] ?? 0 ); }, $items ) ),
			'commitment_payment_total' => array_sum( array_map( static function ( array $item ) { return (float) ( $item['commitment_payment'] ?? 0 ); }, $items ) ),
			'commitment_excluded_count' => count( $commitment_exclusions ),
			'commitment_excluded_balance_total' => array_sum( array_map( static function ( array $item ) { return (float) ( $item['commitment_balance_total'] ?? 0 ); }, $commitment_exclusions ) ),
			'net_total'              => array_sum( array_map( static function ( array $item ) { return (float) ( $item['net_preview'] ?? 0 ); }, $items ) ),
			'from_date'              => $from_date,
			'to_date'                => $to_date,
		);

		$result = array(
			'filters' => array(
				'from_date' => $from_date,
				'to_date'   => $to_date,
				'limit'     => $limit,
			),
			'summary'               => $summary,
			'items'                 => $items,
			'commitment_exclusions' => $commitment_exclusions,
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	public function describe_contact_queue_status( $contact_id, array $args = array() ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return array();
		}

		$from_date            = $this->sanitize_date( $args['from_date'] ?? '' ) ?: gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to_date              = $this->sanitize_date( $args['to_date'] ?? '' ) ?: gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		$profiles_repository  = new EmployeeProfilesRepository();
		$commitments          = new CommitmentSettlementService();
		$profile              = $profiles_repository->find_by_contact_id( $contact_id );
		$commitment_context   = $this->build_payroll_commitment_context( $contact_id );
		$next_payment_date    = sanitize_text_field( (string) ( $profile['next_payment_date'] ?? '' ) );
		$projected_commitment = array(
			'planned_total' => 0.0,
			'items'         => array(),
		);

		if (
			! empty( $commitment_context['has_payroll_managed_commitments'] )
			&& '' !== $next_payment_date
			&& ! empty( $profile['payroll_eligible'] )
		) {
			$projected_commitment = $commitments->preview_payroll_deductions(
				$contact_id,
				$next_payment_date,
				max( 0, (float) ( $profile['salary_amount'] ?? 0 ) )
			);
		}

		return $this->build_contact_queue_state(
			is_array( $profile ) ? $profile : array( 'contact_id' => $contact_id ),
			$from_date,
			$to_date,
			$commitment_context,
			$projected_commitment
		);
	}

	private function sum_eligible_advances( array $eligible_advances, $available_amount ) {
		$available_amount = max( 0, (float) $available_amount );
		$total            = 0.0;

		foreach ( $eligible_advances as $advance ) {
			if ( $total >= $available_amount ) {
				break;
			}

			$apply_amount = min( (float) ( $advance['balance'] ?? 0 ), $available_amount - $total );
			if ( $apply_amount <= 0 ) {
				continue;
			}

			$total += $apply_amount;
		}

		return round( $total, 6 );
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	private function days_until_payment( $date ) {
		$date = $this->sanitize_date( $date );
		if ( ! $date ) {
			return null;
		}

		try {
			$today   = new \DateTimeImmutable( gmdate( 'Y-m-d' ) );
			$target  = new \DateTimeImmutable( $date );
			$diff    = $today->diff( $target );
			$days    = (int) $diff->days;

			return $diff->invert ? -1 * $days : $days;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private function build_commitment_exclusions( $from_date, $to_date, $limit, array $queued_contact_ids = array() ) {
		$profiles_repository = new EmployeeProfilesRepository();
		$profiles            = $profiles_repository->all_with_contacts( max( 250, min( 500, $limit * 4 ) ) );
		$commitments         = new CommitmentSettlementService();
		$items               = array();
		$queued_contact_ids  = array_values( array_unique( array_filter( array_map( 'absint', $queued_contact_ids ) ) ) );

		foreach ( $profiles as $profile ) {
			$contact_id = (int) ( $profile['contact_id'] ?? 0 );
			if ( $contact_id <= 0 || in_array( $contact_id, $queued_contact_ids, true ) ) {
				continue;
			}

			$commitment_context = $this->build_payroll_commitment_context( $contact_id );
			if ( empty( $commitment_context['has_payroll_managed_commitments'] ) ) {
				continue;
			}

			$next_payment_date    = sanitize_text_field( (string) ( $profile['next_payment_date'] ?? '' ) );
			$projected_commitment = array(
				'planned_total' => 0.0,
				'items'         => array(),
			);

			if ( '' !== $next_payment_date && ! empty( $profile['payroll_eligible'] ) ) {
				$projected_commitment = $commitments->preview_payroll_deductions(
					$contact_id,
					$next_payment_date,
					max( 0, (float) ( $profile['salary_amount'] ?? 0 ) )
				);
			}

			$diagnostic = $this->build_contact_queue_state(
				$profile,
				$from_date,
				$to_date,
				$commitment_context,
				$projected_commitment
			);

			if ( ! empty( $diagnostic['in_queue'] ) ) {
				$diagnostic['in_queue']        = false;
				$diagnostic['reason_key']      = 'queue_limit';
				$diagnostic['reason_label']    = 'Fuera del corte visible';
				$diagnostic['reason_message']  = 'El empleado ya califica para nomina en este rango, pero quedo fuera del corte visible por el limite actual de la cola.';
			}

			$items[] = array_merge(
				$diagnostic,
				array(
					'display_name'   => sanitize_text_field( (string) ( $profile['contact_display_name'] ?? '' ) ),
					'email'          => sanitize_email( (string) ( $profile['contact_email'] ?? '' ) ),
					'profile_origin' => sanitize_key( (string) ( $profile['contact_profile_origin'] ?? '' ) ),
				)
			);
		}

		usort(
			$items,
			static function ( array $left, array $right ) {
				$left_date  = (string) ( $left['next_payment_date'] ?? $left['next_commitment_due_date'] ?? '' );
				$right_date = (string) ( $right['next_payment_date'] ?? $right['next_commitment_due_date'] ?? '' );

				if ( $left_date === $right_date ) {
					return strcmp(
						mb_strtolower( (string) ( $left['display_name'] ?? '' ) ),
						mb_strtolower( (string) ( $right['display_name'] ?? '' ) )
					);
				}

				if ( '' === $left_date ) {
					return 1;
				}

				if ( '' === $right_date ) {
					return -1;
				}

				return strcmp( $left_date, $right_date );
			}
		);

		return array_slice( $items, 0, 50 );
	}

	private function build_payroll_commitment_context( $contact_id ) {
		$plans_repository        = new InstallmentPlansRepository();
		$installments_repository = new InstallmentsRepository();
		$plans                   = $plans_repository->for_contact( $contact_id, 100 );
		$context                 = array(
			'has_payroll_managed_commitments' => false,
			'plan_count'                      => 0,
			'balance_total'                   => 0.0,
			'next_due_date'                   => '',
			'open_installment_count'          => 0,
		);

		foreach ( $plans as $plan ) {
			$status          = sanitize_key( (string) ( $plan['status'] ?? '' ) );
			$collection_mode = sanitize_key( (string) ( $plan['collection_mode'] ?? '' ) );
			$balance         = max( 0, (float) ( $plan['balance'] ?? 0 ) );

			if ( $balance <= 0 ) {
				continue;
			}

			if ( ! in_array( $status, array( 'active', 'partial', 'paused' ), true ) ) {
				continue;
			}

			if ( ! in_array( $collection_mode, array( 'payroll_deduction', 'payroll_disbursement', 'mixed' ), true ) ) {
				continue;
			}

			$context['has_payroll_managed_commitments'] = true;
			$context['plan_count']++;
			$context['balance_total'] = round( (float) $context['balance_total'] + $balance, 6 );

			foreach ( $installments_repository->open_for_plan( (int) ( $plan['id'] ?? 0 ), 50 ) as $installment ) {
				$due_date = $this->sanitize_date( $installment['due_date'] ?? '' );
				$context['open_installment_count']++;

				if ( ! $due_date ) {
					continue;
				}

				if ( '' === $context['next_due_date'] || $due_date < $context['next_due_date'] ) {
					$context['next_due_date'] = $due_date;
				}
			}
		}

		return $context;
	}

	private function build_contact_queue_state( array $profile, $from_date, $to_date, array $commitment_context, array $projected_commitment ) {
		$contact_id          = (int) ( $profile['contact_id'] ?? 0 );
		$has_profile         = ! empty( $profile['id'] );
		$employment_status   = sanitize_key( (string) ( $profile['employment_status'] ?? '' ) );
		$contract_status_key = sanitize_key( (string) ( $profile['contract_status_key'] ?? '' ) );
		$next_payment_date   = sanitize_text_field( (string) ( $profile['next_payment_date'] ?? '' ) );
		$payroll_eligible    = ! empty( $profile['payroll_eligible'] );
		$in_queue            = false;
		$reason_key          = '';
		$reason_label        = '';
		$reason_message      = '';

		if ( empty( $commitment_context['has_payroll_managed_commitments'] ) ) {
			$reason_key     = 'no_payroll_commitments';
			$reason_label   = 'Sin compromisos por nomina';
			$reason_message = 'Este empleado no tiene compromisos activos configurados para cobrarse o pagarse por nomina.';
		} elseif ( ! $has_profile ) {
			$reason_key     = 'missing_employee_profile';
			$reason_label   = 'Falta ficha laboral';
			$reason_message = 'Tiene compromisos por nomina, pero todavia no existe una ficha laboral guardada para meterlo en cola.';
		} elseif ( 'active' !== $employment_status ) {
			$reason_key     = 'employment_not_active';
			$reason_label   = 'Estado laboral no activo';
			$reason_message = 'Tiene compromisos por nomina, pero su estado laboral no esta en activo y por eso no entra al corte.';
		} elseif ( ! $payroll_eligible ) {
			if ( 'expired' === $contract_status_key ) {
				$reason_key     = 'contract_expired';
				$reason_label   = 'Contrato vencido';
				$reason_message = 'Tiene compromisos por nomina, pero el contrato esta vencido y la ficha deja de ser elegible para cola.';
			} else {
				$reason_key     = 'payroll_ineligible';
				$reason_label   = 'Nomina no elegible';
				$reason_message = 'Tiene compromisos por nomina, pero la ficha laboral todavia no cumple las condiciones para entrar a cola.';
			}
		} elseif ( '' === $next_payment_date ) {
			$reason_key     = 'missing_next_payment_date';
			$reason_label   = 'Falta proximo pago';
			$reason_message = 'Tiene compromisos por nomina, pero no tiene proximo pago definido y por eso no entra al rango operativo.';
		} elseif ( $next_payment_date < $from_date ) {
			$reason_key     = 'payment_before_range';
			$reason_label   = 'Pago fuera del rango';
			$reason_message = sprintf(
				'Su proximo pago (%s) quedo antes del rango visible de la cola (%s a %s).',
				$next_payment_date,
				$from_date,
				$to_date
			);
		} elseif ( $next_payment_date > $to_date ) {
			$reason_key     = 'payment_after_range';
			$reason_label   = 'Pago fuera del rango';
			$reason_message = sprintf(
				'Su proximo pago (%s) queda despues del rango visible de la cola (%s a %s).',
				$next_payment_date,
				$from_date,
				$to_date
			);
		} else {
			$in_queue        = true;
			$reason_key      = 'eligible_in_range';
			$reason_label    = 'Visible en cola';
			$reason_message  = 'La ficha laboral ya califica y su proximo pago cae dentro del rango operativo de la cola.';
		}

		$projected_total    = round( max( 0, (float) ( $projected_commitment['planned_total'] ?? 0 ) ), 6 );
		$projection_message = $this->build_projection_message(
			$next_payment_date,
			$commitment_context,
			$projected_total,
			(int) count( (array) ( $projected_commitment['items'] ?? array() ) )
		);

		return array(
			'contact_id'                      => $contact_id,
			'has_employee_profile'            => $has_profile,
			'employment_status'               => $employment_status,
			'contract_status_key'             => $contract_status_key,
			'payroll_eligible'                => $payroll_eligible,
			'next_payment_date'               => $next_payment_date,
			'in_queue'                        => $in_queue,
			'reason_key'                      => $reason_key,
			'reason_label'                    => $reason_label,
			'reason_message'                  => $reason_message,
			'has_payroll_managed_commitments' => ! empty( $commitment_context['has_payroll_managed_commitments'] ),
			'commitment_plan_count'           => (int) ( $commitment_context['plan_count'] ?? 0 ),
			'commitment_balance_total'        => round( max( 0, (float) ( $commitment_context['balance_total'] ?? 0 ) ), 6 ),
			'next_commitment_due_date'        => sanitize_text_field( (string) ( $commitment_context['next_due_date'] ?? '' ) ),
			'open_installment_count'          => (int) ( $commitment_context['open_installment_count'] ?? 0 ),
			'projected_commitment_total'      => $projected_total,
			'projected_commitment_count'      => count( (array) ( $projected_commitment['items'] ?? array() ) ),
			'projection_message'              => $projection_message,
		);
	}

	private function build_projection_message( $next_payment_date, array $commitment_context, $projected_total, $projected_count ) {
		$next_due_date   = sanitize_text_field( (string) ( $commitment_context['next_due_date'] ?? '' ) );
		$projected_total = round( max( 0, (float) $projected_total ), 6 );
		$projected_count = max( 0, (int) $projected_count );

		if ( $projected_total > 0 ) {
			return sprintf(
				'En la proxima nomina se proyecta descontar o pagar %1$s en %2$d cuota(s) abierta(s).',
				number_format_i18n( $projected_total, 2 ),
				max( 1, $projected_count )
			);
		}

		if ( '' !== $next_payment_date && '' !== $next_due_date && $next_due_date > $next_payment_date ) {
			return sprintf(
				'El compromiso sigue activo, pero la proxima cuota vence el %1$s y todavia no entra en la nomina del %2$s.',
				$next_due_date,
				$next_payment_date
			);
		}

		if ( '' !== $next_due_date ) {
			return sprintf(
				'Hay compromisos activos, pero no hay cuotas abiertas que apliquen antes del siguiente pago visible. Proxima cuota abierta: %s.',
				$next_due_date
			);
		}

		if ( ! empty( $commitment_context['has_payroll_managed_commitments'] ) ) {
			return 'Hay compromisos por nomina activos, pero aun no encontramos cuotas abiertas listas para entrar en el siguiente corte.';
		}

		return 'Sin impacto por compromisos en la proxima nomina visible.';
	}
}
