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
			$scheduled_date    = $profile['next_payment_date'] ?? gmdate( 'Y-m-d' );
			$planned_period    = $periods_repository->find_actionable_for_contact( (int) $profile['contact_id'], $scheduled_date, 2 );
			$eligible_advances = $advances_repository->eligible_for_payroll( (int) $profile['contact_id'], $scheduled_date );
			$gross_amount      = (float) ( $profile['salary_amount'] ?? 0 );
			$commitment_preview = $commitments->preview_payroll_deductions(
				(int) $profile['contact_id'],
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
				$manual_debt_snapshot = $manual_settlements->get_open_debts_for_contact( (int) $profile['contact_id'] );
				$manual_debt_summary  = is_wp_error( $manual_debt_snapshot )
					? $manual_debt_summary
					: (array) ( $manual_debt_snapshot['summary'] ?? array() );
			}

			$days_until_payment = $this->days_until_payment( $scheduled_date );
			$queue_action_mode  = ! empty( $planned_period['id'] )
				? 'process'
				: ( null !== $days_until_payment && $days_until_payment <= 2 ? 'prepare' : 'generate' );

			$items[] = array(
				'contact_id'              => (int) $profile['contact_id'],
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
				'commitment_items'        => $commitment_preview['items'] ?? array(),
				'commitment_payment_items'=> $commitment_payment_preview['items'] ?? array(),
				'manual_debt_summary'     => $manual_debt_summary,
			);
		}

		$summary = array(
			'total_employee_count'   => (int) ( $profile_counts['total_count'] ?? 0 ),
			'active_employee_count'  => (int) ( $profile_counts['active_count'] ?? 0 ),
			'paused_employee_count'  => (int) ( $profile_counts['paused_count'] ?? 0 ),
			'ended_employee_count'   => (int) ( $profile_counts['ended_count'] ?? 0 ),
			'employee_count'         => count( $items ),
			'planned_period_count'   => count( array_filter( $items, static function ( array $item ) {
				return ! empty( $item['planned_period']['id'] );
			} ) ),
			'pending_generation_count' => count( array_filter( $items, static function ( array $item ) { return empty( $item['planned_period']['id'] ); } ) ),
			'gross_total'            => array_sum( array_map( static function ( array $item ) { return (float) ( $item['salary_amount'] ?? 0 ); }, $items ) ),
			'advance_deduction_total'=> array_sum( array_map( static function ( array $item ) { return (float) ( $item['advance_deduction'] ?? 0 ); }, $items ) ),
			'commitment_deduction_total' => array_sum( array_map( static function ( array $item ) { return (float) ( $item['commitment_deduction'] ?? 0 ); }, $items ) ),
			'commitment_payment_total' => array_sum( array_map( static function ( array $item ) { return (float) ( $item['commitment_payment'] ?? 0 ); }, $items ) ),
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
			'summary' => $summary,
			'items'   => $items,
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
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
}
