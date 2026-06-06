<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	$GLOBALS['ASDL_TEST_TRANSIENTS']                  = array();
	$GLOBALS['ASDL_TEST_PAYROLL_SUMMARY_COUNTS']      = array();
	$GLOBALS['ASDL_TEST_PAYROLL_QUEUE_PROFILES']      = array();
	$GLOBALS['ASDL_TEST_EMPLOYEE_DIRECTORY']          = array();
	$GLOBALS['ASDL_TEST_EMPLOYEE_PROFILE_MAP']        = array();
	$GLOBALS['ASDL_TEST_PAYROLL_COMMITMENT_PREVIEWS'] = array();
	$GLOBALS['ASDL_TEST_PAYROLL_PLAN_MAP']            = array();
	$GLOBALS['ASDL_TEST_INSTALLMENTS_BY_PLAN']        = array();

	function absint( $value ) {
		return abs( (int) $value );
	}

	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	function sanitize_email( $value ) {
		return strtolower( trim( (string) $value ) );
	}

	function remove_accents( $value ) {
		$value     = (string) $value;
		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );

		return false !== $converted ? $converted : $value;
	}

	function sanitize_key( $value ) {
		$value = strtolower( remove_accents( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_:-]/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function is_wp_error( $thing ) {
		return false;
	}

	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['ASDL_TEST_TRANSIENTS'] ) ? $GLOBALS['ASDL_TEST_TRANSIENTS'][ $key ] : false;
	}

	function set_transient( $key, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['ASDL_TEST_TRANSIENTS'][ $key ] = $value;

		return true;
	}
}

namespace ASDLabs\Finance\Finance {
	class EmployeeProfilesRepository {
		public function summary_counts() {
			return $GLOBALS['ASDL_TEST_PAYROLL_SUMMARY_COUNTS'];
		}

		public function upcoming_payroll_queue( $from_date = '', $to_date = '', $limit = 100 ) {
			unset( $from_date, $to_date, $limit );
			return $GLOBALS['ASDL_TEST_PAYROLL_QUEUE_PROFILES'];
		}

		public function all_with_contacts( $limit = 200, $employment_status = '' ) {
			unset( $limit, $employment_status );
			return $GLOBALS['ASDL_TEST_EMPLOYEE_DIRECTORY'];
		}

		public function find_by_contact_id( $contact_id ) {
			return $GLOBALS['ASDL_TEST_EMPLOYEE_PROFILE_MAP'][ (int) $contact_id ] ?? null;
		}
	}

	class PayrollPeriodsRepository {
		public function find_actionable_for_contact( $contact_id, $scheduled_payment_date = '', $grace_days = 2 ) {
			unset( $contact_id, $scheduled_payment_date, $grace_days );
			return null;
		}
	}

	class EmployeeAdvancesRepository {
		public function eligible_for_payroll( $contact_id, $scheduled_payment_date ) {
			unset( $contact_id, $scheduled_payment_date );
			return array();
		}
	}

	class CommitmentSettlementService {
		public function preview_payroll_deductions( $contact_id, $scheduled_payment_date, $available_amount ) {
			unset( $available_amount );
			$key = (int) $contact_id . ':' . (string) $scheduled_payment_date;

			return $GLOBALS['ASDL_TEST_PAYROLL_COMMITMENT_PREVIEWS'][ $key ] ?? array(
				'planned_total' => 0.0,
				'items'         => array(),
			);
		}

		public function preview_payroll_disbursements( $contact_id, $scheduled_payment_date, $available_amount = null ) {
			unset( $contact_id, $scheduled_payment_date, $available_amount );
			return array(
				'planned_total' => 0.0,
				'items'         => array(),
			);
		}
	}

	class PayrollManualSettlementService {
		public function get_open_debts_for_contact( $contact_id ) {
			unset( $contact_id );
			return array(
				'summary' => array(
					'has_open_debts' => false,
					'total_amount'   => 0.0,
					'target_count'   => 0,
				),
			);
		}
	}

	class InstallmentPlansRepository {
		public function for_contact( $contact_id, $limit = 50 ) {
			unset( $limit );
			return $GLOBALS['ASDL_TEST_PAYROLL_PLAN_MAP'][ (int) $contact_id ] ?? array();
		}
	}

	class InstallmentsRepository {
		public function open_for_plan( $plan_id, $limit = 200 ) {
			unset( $limit );
			return $GLOBALS['ASDL_TEST_INSTALLMENTS_BY_PLAN'][ (int) $plan_id ] ?? array();
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Finance/PayrollQueueService.php';

	use ASDLabs\Finance\Finance\PayrollQueueService;

	function assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
			);
		}
	}

	function assert_true( $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$GLOBALS['ASDL_TEST_PAYROLL_SUMMARY_COUNTS'] = array(
		'total_count'        => 3,
		'active_count'       => 3,
		'paused_count'       => 0,
		'ended_count'        => 0,
		'configured_count'   => 3,
		'unconfigured_count' => 0,
	);

	$GLOBALS['ASDL_TEST_PAYROLL_QUEUE_PROFILES'] = array(
		array(
			'id'                   => 1001,
			'contact_id'           => 10,
			'contact_display_name' => 'Empleado Visible',
			'contact_email'        => 'visible@example.com',
			'contact_profile_origin' => 'wp_user',
			'employment_status'    => 'active',
			'contract_status_key'  => 'active',
			'next_payment_date'    => '2026-04-25',
			'pay_frequency'        => 'weekly',
			'salary_amount'        => 150,
			'salary_currency'      => 'USD',
			'default_account_id'   => 0,
			'payroll_eligible'     => true,
		),
		array(
			'id'                   => 1003,
			'contact_id'           => 12,
			'contact_display_name' => 'Empleado Con Cuota Futura',
			'contact_email'        => 'future@example.com',
			'contact_profile_origin' => 'wp_user',
			'employment_status'    => 'active',
			'contract_status_key'  => 'active',
			'next_payment_date'    => '2026-04-22',
			'pay_frequency'        => 'weekly',
			'salary_amount'        => 90,
			'salary_currency'      => 'USD',
			'default_account_id'   => 0,
			'payroll_eligible'     => true,
		),
	);

	$GLOBALS['ASDL_TEST_EMPLOYEE_DIRECTORY'] = array(
		$GLOBALS['ASDL_TEST_PAYROLL_QUEUE_PROFILES'][0],
		array(
			'id'                   => 1002,
			'contact_id'           => 11,
			'contact_display_name' => 'Empleado Sin Proximo Pago',
			'contact_email'        => 'missing@example.com',
			'contact_profile_origin' => 'wp_user',
			'employment_status'    => 'active',
			'contract_status_key'  => 'active',
			'next_payment_date'    => '',
			'pay_frequency'        => 'weekly',
			'salary_amount'        => 100,
			'salary_currency'      => 'USD',
			'default_account_id'   => 0,
			'payroll_eligible'     => true,
		),
		$GLOBALS['ASDL_TEST_PAYROLL_QUEUE_PROFILES'][1],
	);

	$GLOBALS['ASDL_TEST_EMPLOYEE_PROFILE_MAP'] = array(
		10 => $GLOBALS['ASDL_TEST_PAYROLL_QUEUE_PROFILES'][0],
		11 => $GLOBALS['ASDL_TEST_EMPLOYEE_DIRECTORY'][1],
		12 => $GLOBALS['ASDL_TEST_PAYROLL_QUEUE_PROFILES'][1],
	);

	$GLOBALS['ASDL_TEST_PAYROLL_PLAN_MAP'] = array(
		11 => array(
			array(
				'id'              => 201,
				'status'          => 'active',
				'collection_mode' => 'payroll_deduction',
				'balance'         => 120.00,
			),
		),
		12 => array(
			array(
				'id'              => 202,
				'status'          => 'active',
				'collection_mode' => 'payroll_deduction',
				'balance'         => 75.00,
			),
		),
	);

	$GLOBALS['ASDL_TEST_INSTALLMENTS_BY_PLAN'] = array(
		201 => array(
			array(
				'id'       => 501,
				'due_date' => '2026-04-25',
			),
		),
		202 => array(
			array(
				'id'       => 502,
				'due_date' => '2026-04-29',
			),
		),
	);

	$GLOBALS['ASDL_TEST_PAYROLL_COMMITMENT_PREVIEWS'] = array(
		'10:2026-04-25' => array(
			'planned_total' => 15.0,
			'items'         => array(
				array( 'plan_id' => 301, 'planned_amount' => 15.0 ),
			),
		),
		'12:2026-04-22' => array(
			'planned_total' => 0.0,
			'items'         => array(),
		),
	);

	$service  = new PayrollQueueService();
	$snapshot = $service->get_snapshot(
		array(
			'from_date' => '2026-04-20',
			'to_date'   => '2026-04-30',
			'limit'     => 80,
		)
	);

	assert_same(
		3,
		(int) ( $snapshot['summary']['configured_count'] ?? 0 ),
		'El resumen debe exponer perfiles laborales configurados.'
	);

	assert_same(
		1,
		(int) ( $snapshot['summary']['commitment_excluded_count'] ?? 0 ),
		'Debe detectar empleados con compromisos por nomina fuera de cola.'
	);

	assert_same(
		11,
		(int) ( $snapshot['commitment_exclusions'][0]['contact_id'] ?? 0 ),
		'La exclusión visible debe corresponder al empleado que no tiene proximo pago.'
	);

	assert_same(
		'missing_next_payment_date',
		(string) ( $snapshot['commitment_exclusions'][0]['reason_key'] ?? '' ),
		'Debe explicar que el empleado queda fuera por no tener proximo pago.'
	);

	$missing_payment_diagnostic = $service->describe_contact_queue_status(
		11,
		array(
			'from_date' => '2026-04-20',
			'to_date'   => '2026-04-30',
		)
	);

	assert_true(
		! empty( $missing_payment_diagnostic['has_payroll_managed_commitments'] ),
		'El diagnostico debe reconocer compromisos por nomina activos.'
	);

	assert_same(
		'missing_next_payment_date',
		(string) ( $missing_payment_diagnostic['reason_key'] ?? '' ),
		'Debe explicar por que el empleado con compromiso no entra en cola.'
	);

	$future_due_diagnostic = $service->describe_contact_queue_status(
		12,
		array(
			'from_date' => '2026-04-20',
			'to_date'   => '2026-04-30',
		)
	);

	assert_true(
		! empty( $future_due_diagnostic['in_queue'] ),
		'El empleado con ficha lista y proximo pago dentro del rango debe aparecer como elegible en cola.'
	);

	assert_same(
		0.0,
		(float) ( $future_due_diagnostic['projected_commitment_total'] ?? -1 ),
		'Si la cuota vence despues del siguiente pago, el descuento previsto debe quedar en cero.'
	);

	assert_true(
		false !== strpos( (string) ( $future_due_diagnostic['projection_message'] ?? '' ), 'todavia no entra en la nomina' ),
		'Debe explicar cuando la cuota existe pero todavia no vence para el siguiente pago.'
	);

	echo "payroll-queue-diagnostics-regression: OK\n";
}
