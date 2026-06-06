<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	$GLOBALS['ASDL_TEST_PAYROLL_ROW']                  = array();
	$GLOBALS['ASDL_TEST_CAPS']                         = array();
	$GLOBALS['ASDL_TEST_CURRENT_USER_ID']              = 17;
	$GLOBALS['ASDL_TEST_COMMITMENT_DEDUCTION_PREVIEW'] = array();
	$GLOBALS['ASDL_TEST_COMMITMENT_PAYOUT_PREVIEW']    = array();
	$GLOBALS['ASDL_TEST_COMMITMENT_APPLY_CALLS']       = array();
	$GLOBALS['ASDL_TEST_PAYMENT_CREATE_CALLS']         = array();
	$GLOBALS['ASDL_TEST_DOCUMENT_CREATE_CALLS']        = array();
	$GLOBALS['ASDL_TEST_ALLOCATIONS']                  = array();
	$GLOBALS['ASDL_TEST_DEFER_CALLS']                  = array();
	$GLOBALS['ASDL_TEST_EVENT_LOGS']                   = array();
	$GLOBALS['ASDL_TEST_REGISTERED_PAYROLLS']          = array();
	$GLOBALS['ASDL_TEST_NEXT_CYCLE_DATE']              = '2026-04-11';
	$GLOBALS['ASDL_TEST_QUEUE_BUMPS']                  = 0;

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! class_exists( 'WP_Error', false ) ) {
		class WP_Error {
			private $code;
			private $message;

			public function __construct( $code = '', $message = '' ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
			}

			public function get_error_code() {
				return $this->code;
			}

			public function get_error_message() {
				return $this->message;
			}
		}
	}

	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
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

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function current_time( $type = 'mysql' ) {
		return 'mysql' === $type ? '2026-04-22 11:00:00' : '2026-04-22';
	}

	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['ASDL_TEST_CAPS'][ (string) $capability ] );
	}

	function get_current_user_id() {
		return (int) $GLOBALS['ASDL_TEST_CURRENT_USER_ID'];
	}

	function wp_generate_uuid4() {
		static $counter = 100;
		$counter++;
		return sprintf( '00000000-0000-4000-8000-%012d', $counter );
	}

	function do_action( $hook, ...$args ) {
		unset( $hook, $args );
	}

	final class FakeDb {
		public function prepare( $query, ...$args ) {
			return array(
				'query' => $query,
				'args'  => $args,
			);
		}

		public function get_row( $prepared, $output = ARRAY_A ) {
			unset( $output );
			$args = is_array( $prepared ) ? (array) ( $prepared['args'] ?? array() ) : array();
			$id   = isset( $args[0] ) ? (int) $args[0] : 0;

			if ( $id <= 0 || (int) ( $GLOBALS['ASDL_TEST_PAYROLL_ROW']['id'] ?? 0 ) !== $id ) {
				return null;
			}

			return $GLOBALS['ASDL_TEST_PAYROLL_ROW'];
		}

		public function update( $table, $data, $where, $formats = array(), $where_formats = array() ) {
			unset( $table, $formats, $where_formats );
			if ( (int) ( $where['id'] ?? 0 ) !== (int) ( $GLOBALS['ASDL_TEST_PAYROLL_ROW']['id'] ?? 0 ) ) {
				return false;
			}

			foreach ( $data as $key => $value ) {
				$GLOBALS['ASDL_TEST_PAYROLL_ROW'][ $key ] = $value;
			}

			return 1;
		}

		public function query( $sql ) {
			unset( $sql );
			return true;
		}
	}
}

namespace ASDLabs\Finance\Core {
	final class Tables {
		public static function name( $key ) {
			return 'asdl_test_' . (string) $key;
		}
	}
}

namespace ASDLabs\Finance\Integrations\Approvals {
	class ApprovalBridge {
		const TARGET_PLUGIN = 'analysis-financiero-plugin';
		const ACTION_PAYROLL_COMMITMENT_OVERRIDE = 'finance.payroll.commitment_override.execute';

		public function authorize_execution( $action_key, array $args = array() ) {
			if ( 'tok-ok' !== (string) ( $args['approval_token'] ?? '' ) ) {
				return new \WP_Error( 'approval_token_required', 'Esta acción requiere un token de aprobación.' );
			}

			return array(
				'action_key'       => $action_key,
				'approval_id'      => 91,
				'approval_uuid'    => 'oa-payroll-91',
				'actor_user_id'    => \get_current_user_id(),
				'approver_user_id' => \get_current_user_id(),
				'mode'             => 'approval',
			);
		}

		public function summarize_authorization( $authorization ) {
			return array(
				'action_key'       => (string) ( $authorization['action_key'] ?? '' ),
				'approval_mode'    => 'approval',
				'approval_id'      => (int) ( $authorization['approval_id'] ?? 0 ),
				'approval_uuid'    => (string) ( $authorization['approval_uuid'] ?? '' ),
				'actor_user_id'    => (int) ( $authorization['actor_user_id'] ?? 0 ),
				'approver_user_id' => (int) ( $authorization['approver_user_id'] ?? 0 ),
			);
		}

		public function build_payroll_commitment_override_payload( array $args ) {
			return $args;
		}
	}
}

namespace ASDLabs\Finance\Finance {
	class BaseRepository {
		protected function db() {
			global $wpdb;
			return $wpdb;
		}

		protected function table() {
			return 'asdl_test_' . $this->table_key;
		}

		protected function has_table() {
			return true;
		}

		protected function now() {
			return \current_time( 'mysql' );
		}

		protected function sanitize_date( $value ) {
			$value = \sanitize_text_field( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
		}

		protected function error( $code, $message ) {
			return new \WP_Error( $code, $message );
		}

		protected function begin_transaction() {
			return true;
		}

		protected function commit_transaction() {
			return true;
		}

		protected function rollback_transaction() {
			return true;
		}
	}

	class DocumentsRepository {
		public function create( array $data ) {
			$GLOBALS['ASDL_TEST_DOCUMENT_CREATE_CALLS'][] = $data;
			return 701;
		}
	}

	class PaymentsRepository {
		private static $next_id = 900;

		public function create( array $data ) {
			$id = self::$next_id++;
			$GLOBALS['ASDL_TEST_PAYMENT_CREATE_CALLS'][] = array_merge( array( 'id' => $id ), $data );
			return $id;
		}
	}

	class PaymentAllocationService {
		public function allocate( array $data ) {
			$GLOBALS['ASDL_TEST_ALLOCATIONS'][] = $data;
			return array( 'ok' => true );
		}
	}

	class EmployeeAdvancesRepository {
		public function eligible_for_payroll( $contact_id, $scheduled_payment_date ) {
			unset( $contact_id, $scheduled_payment_date );
			return array();
		}

		public function apply_recovery( $advance_id, $amount, array $context = array() ) {
			unset( $advance_id, $amount, $context );
			return array();
		}
	}

	class CommitmentSettlementService {
		public function preview_payroll_deductions( $contact_id, $scheduled_payment_date, $available_amount ) {
			unset( $contact_id, $scheduled_payment_date, $available_amount );
			return $GLOBALS['ASDL_TEST_COMMITMENT_DEDUCTION_PREVIEW'];
		}

		public function preview_payroll_disbursements( $contact_id, $scheduled_payment_date, $available_amount = null ) {
			unset( $contact_id, $scheduled_payment_date, $available_amount );
			return $GLOBALS['ASDL_TEST_COMMITMENT_PAYOUT_PREVIEW'];
		}

		public function apply( array $data ) {
			$GLOBALS['ASDL_TEST_COMMITMENT_APPLY_CALLS'][] = $data;
			return array(
				'plan_id'              => (int) ( $data['plan_id'] ?? 0 ),
				'payment_id'           => 980 + count( $GLOBALS['ASDL_TEST_COMMITMENT_APPLY_CALLS'] ),
				'applied_total'        => (float) ( $data['amount'] ?? 0 ),
				'plan_balance'         => 25.00,
				'settlement_direction' => 'adjustment' === (string) ( $data['payment_type'] ?? '' ) ? 'receivable' : 'payable',
			);
		}
	}

	class PayrollManualSettlementService {
		public function normalize_manual_settlement( $contact_id, array $data = array() ) {
			unset( $contact_id, $data );
			return array(
				'enabled' => false,
			);
		}
	}

	class EmployeeProfilesRepository {
		public function find_by_contact_id( $contact_id ) {
			unset( $contact_id );
			return array(
				'id'                => 41,
				'contact_id'        => 33,
				'pay_frequency'     => 'weekly',
				'payday_value'      => 5,
				'effective_from'    => '2026-01-01',
				'cycle_anchor_date' => '2026-01-02',
			);
		}

		public function project_following_payment_date( array $profile, $reference_date = '' ) {
			unset( $profile, $reference_date );
			return (string) $GLOBALS['ASDL_TEST_NEXT_CYCLE_DATE'];
		}

		public function register_payroll_payment( $contact_id, $paid_date ) {
			$GLOBALS['ASDL_TEST_REGISTERED_PAYROLLS'][] = array(
				'contact_id' => (int) $contact_id,
				'paid_date'  => (string) $paid_date,
			);
			return true;
		}
	}

	class PaymentMethodsService {
		public function is_valid_key( $key ) {
			return '' !== (string) $key;
		}
	}

	class InstallmentsRepository {
		public function defer_due_date( $installment_id, $new_due_date, array $context = array() ) {
			$GLOBALS['ASDL_TEST_DEFER_CALLS'][] = array(
				'installment_id' => (int) $installment_id,
				'new_due_date'   => (string) $new_due_date,
				'context'        => $context,
			);

			return array(
				'id'       => (int) $installment_id,
				'due_date' => (string) $new_due_date,
			);
		}
	}

	class EventsRepository {
		public function log( $entity_type, $entity_id, $event_type, $message, array $payload = array(), $actor_user_id = null ) {
			$GLOBALS['ASDL_TEST_EVENT_LOGS'][] = array(
				'entity_type'   => $entity_type,
				'entity_id'     => $entity_id,
				'event_type'    => $event_type,
				'message'       => $message,
				'payload'       => $payload,
				'actor_user_id' => $actor_user_id,
			);

			return count( $GLOBALS['ASDL_TEST_EVENT_LOGS'] );
		}
	}

	class PayrollQueueService {
		public static function bump_cache_version() {
			$GLOBALS['ASDL_TEST_QUEUE_BUMPS']++;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Finance/PayrollPeriodsRepository.php';

	use ASDLabs\Finance\Finance\PayrollPeriodsRepository;

	$GLOBALS['wpdb'] = new FakeDb();

	function assert_true( $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
			);
		}
	}

	function reset_payroll_test_state(): void {
		$GLOBALS['ASDL_TEST_COMMITMENT_APPLY_CALLS'] = array();
		$GLOBALS['ASDL_TEST_PAYMENT_CREATE_CALLS']   = array();
		$GLOBALS['ASDL_TEST_DOCUMENT_CREATE_CALLS']  = array();
		$GLOBALS['ASDL_TEST_ALLOCATIONS']            = array();
		$GLOBALS['ASDL_TEST_DEFER_CALLS']            = array();
		$GLOBALS['ASDL_TEST_EVENT_LOGS']             = array();
		$GLOBALS['ASDL_TEST_REGISTERED_PAYROLLS']    = array();
		$GLOBALS['ASDL_TEST_QUEUE_BUMPS']            = 0;
		$GLOBALS['ASDL_TEST_CAPS']                   = array( 'manage_woocommerce' => true );
		$GLOBALS['ASDL_TEST_NEXT_CYCLE_DATE']        = '2026-04-11';
		$GLOBALS['ASDL_TEST_PAYROLL_ROW']            = array(
			'id'                      => 77,
			'contact_id'              => 33,
			'wp_user_id'              => 0,
			'employee_profile_id'     => 41,
			'title'                   => 'Nomina semanal 2026-03-29 al 2026-04-04',
			'scheduled_payment_date'  => '2026-04-04',
			'currency'                => 'USD',
			'gross_amount'            => 150.00,
			'advance_deduction_amount'=> 0.00,
			'other_deduction_amount'  => 0.00,
			'net_amount'              => 100.00,
			'status'                  => 'planned',
			'payment_account_id'      => 0,
			'payment_method_key'      => 'bank_transfer',
			'notes'                   => '',
			'document_id'             => 0,
			'payment_id'              => 0,
			'meta_json'               => wp_json_encode(
				array(
					'commitment_breakdown' => array(),
				)
			),
		);
		$GLOBALS['ASDL_TEST_COMMITMENT_DEDUCTION_PREVIEW'] = array(
			'planned_total' => 50.00,
			'items'         => array(
				array(
					'plan_id'              => 901,
					'installment_id'       => 501,
					'document_id'          => 0,
					'title'                => 'Deuda tienda',
					'settlement_direction' => 'receivable',
					'collection_mode'      => 'payroll_deduction',
					'commitment_origin'    => 'store_debt',
					'installment_title'    => 'Cuota 1',
					'due_date'             => '2026-04-04',
					'planned_amount'       => 50.00,
					'installment_balance'  => 50.00,
					'sequence_no'          => 1,
				),
			),
		);
		$GLOBALS['ASDL_TEST_COMMITMENT_PAYOUT_PREVIEW'] = array(
			'planned_total' => 0.00,
			'items'         => array(),
		);
	}

	function override_payload( string $action ): string {
		return wp_json_encode(
			array(
				array(
					'item_key'              => 'receivable:901:501',
					'plan_id'               => 901,
					'installment_id'        => 501,
					'settlement_direction'  => 'receivable',
					'action'                => $action,
				),
			)
		);
	}

	reset_payroll_test_state();
	$repository = new PayrollPeriodsRepository();

	$missing_token = $repository->mark_paid(
		77,
		array(
			'paid_at'                            => '2026-04-04',
			'payment_method_key'                 => 'bank_transfer',
			'payroll_commitment_actions_json'    => override_payload( 'skip_once' ),
			'payroll_commitment_override_reason' => 'Prueba QA',
		)
	);
	assert_true( is_wp_error( $missing_token ), 'Un skip_once sin token debe quedar bloqueado.' );
	assert_same( 'approval_token_required', $missing_token->get_error_code(), 'El bloqueo debe venir de la capa TOTP.' );

	reset_payroll_test_state();
	$normal_result = $repository->mark_paid(
		77,
		array(
			'paid_at'            => '2026-04-04',
			'payment_method_key' => 'bank_transfer',
		)
	);
	assert_true( ! is_wp_error( $normal_result ), 'El pago normal de nomina debe seguir funcionando.' );
	assert_same( 1, count( $GLOBALS['ASDL_TEST_COMMITMENT_APPLY_CALLS'] ), 'El cobro normal debe aplicar el compromiso previsto.' );
	assert_same( 501, (int) ( $GLOBALS['ASDL_TEST_COMMITMENT_APPLY_CALLS'][0]['target_installment_id'] ?? 0 ), 'La aplicacion normal debe respetar la cuota exacta prevista.' );

	reset_payroll_test_state();
	$skip_result = $repository->mark_paid(
		77,
		array(
			'paid_at'                            => '2026-04-04',
			'payment_method_key'                 => 'bank_transfer',
			'payroll_commitment_approval_token'  => 'tok-ok',
			'payroll_commitment_actions_json'    => override_payload( 'skip_once' ),
			'payroll_commitment_override_reason' => 'No cobrar esta semana',
		)
	);
	assert_true(
		! is_wp_error( $skip_result ),
		'Con token valido, skip_once debe procesarse correctamente.'
		. ( is_wp_error( $skip_result ) ? ' [' . $skip_result->get_error_code() . '] ' . $skip_result->get_error_message() : '' )
	);
	assert_same( 0, count( $GLOBALS['ASDL_TEST_COMMITMENT_APPLY_CALLS'] ), 'La cuota omitida no debe cobrarse en esta nomina.' );
	assert_same( 150.0, round( (float) $skip_result['net_amount'], 6 ), 'Al omitir la cuota, el neto debe subir al bruto completo.' );
	assert_same( 'skip_once', (string) ( $skip_result['meta']['applied_commitment_overrides'][0]['action'] ?? '' ), 'La nomina debe guardar el override aplicado.' );
	assert_same( 1, count( $GLOBALS['ASDL_TEST_EVENT_LOGS'] ), 'El override aplicado debe dejar un evento de auditoria.' );

	reset_payroll_test_state();
	$defer_result = $repository->mark_paid(
		77,
		array(
			'paid_at'                            => '2026-04-04',
			'payment_method_key'                 => 'bank_transfer',
			'payroll_commitment_approval_token'  => 'tok-ok',
			'payroll_commitment_actions_json'    => override_payload( 'defer_next_cycle' ),
			'payroll_commitment_override_reason' => 'Rodar por esta corrida',
		)
	);
	assert_true(
		! is_wp_error( $defer_result ),
		'Con token valido, defer_next_cycle debe procesarse correctamente.'
		. ( is_wp_error( $defer_result ) ? ' [' . $defer_result->get_error_code() . '] ' . $defer_result->get_error_message() : '' )
	);
	assert_same( 1, count( $GLOBALS['ASDL_TEST_DEFER_CALLS'] ), 'Rodar una cuota debe mover su vencimiento.' );
	assert_same( '2026-04-11', (string) ( $GLOBALS['ASDL_TEST_DEFER_CALLS'][0]['new_due_date'] ?? '' ), 'La cuota debe rodarse a la proxima nomina calculada.' );
	assert_same( 'defer_next_cycle', (string) ( $defer_result['meta']['applied_commitment_overrides'][0]['action'] ?? '' ), 'La meta debe reflejar la cuota rodada.' );
	assert_same( '2026-04-11', (string) ( $defer_result['meta']['applied_commitment_overrides'][0]['new_due_date'] ?? '' ), 'La meta debe guardar la nueva fecha proyectada.' );

	reset_payroll_test_state();
	$GLOBALS['ASDL_TEST_CAPS'] = array(
		'manage_options' => true,
	);
	$admin_missing_token = $repository->mark_paid(
		77,
		array(
			'paid_at'                            => '2026-04-04',
			'payment_method_key'                 => 'bank_transfer',
			'payroll_commitment_actions_json'    => override_payload( 'defer_next_cycle' ),
			'payroll_commitment_override_reason' => 'Rodar por validacion admin',
		)
	);
	assert_true( is_wp_error( $admin_missing_token ), 'El administrador tambien debe quedar bloqueado sin token en overrides de nomina.' );
	assert_same( 'approval_token_required', $admin_missing_token->get_error_code(), 'La capa TOTP debe exigir token al administrador para rodar una cuota.' );

	echo "OK\n";
}
