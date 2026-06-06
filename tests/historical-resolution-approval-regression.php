<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	$GLOBALS['asdl_test_options'] = array();
	$GLOBALS['ASDL_TEST_CURRENT_USER_ID'] = 33;
	$GLOBALS['ASDL_TEST_CAPABILITIES'] = array();
	$GLOBALS['ASDL_TEST_HISTORICAL_SUMMARY'] = array();
	$GLOBALS['ASDL_TEST_HISTORICAL_ITEMS'] = array();

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

	function sanitize_email( $value ) {
		return strtolower( trim( (string) $value ) );
	}

	function sanitize_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_:-]/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['asdl_test_options'] ) ? $GLOBALS['asdl_test_options'][ $key ] : $default;
	}

	function update_option( $key, $value, $autoload = false ) {
		$GLOBALS['asdl_test_options'][ $key ] = $value;
		return true;
	}

	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['ASDL_TEST_CAPABILITIES'][ (string) $capability ] );
	}

	function get_current_user_id() {
		return (int) $GLOBALS['ASDL_TEST_CURRENT_USER_ID'];
	}

	function current_time( $type = 'mysql', $gmt = false ) {
		return 'mysql' === $type ? '2026-04-22 11:00:00' : '2026-04-22';
	}

	function wp_generate_uuid4() {
		static $counter = 10;
		$counter++;
		return sprintf( '00000000-0000-4000-8000-%012d', $counter );
	}

	final class ASDL_OA_Approval_Policies {
		public static function register_action( $action_key, $policy ) {
			return array( $action_key, $policy );
		}
	}

	final class ASDL_OA_Approvals {
		public static function evaluate_action( $action_key, $args = array() ) {
			return array(
				'action_key'          => $action_key,
				'actor_user_id'       => get_current_user_id(),
				'can_bypass'          => false,
				'requires_approval'   => true,
				'allow_self_approval' => true,
				'token_ttl_seconds'   => 300,
				'eligible_approvers'  => array(
					array(
						'id'           => get_current_user_id(),
						'display_name' => 'Operador Historico',
						'user_login'   => 'shop_manager',
						'user_email'   => 'shop@example.com',
					),
				),
			);
		}

		public static function authorize_execution( $action_key, $args = array() ) {
			if ( 'tok-ok' !== (string) ( $args['approval_token'] ?? '' ) ) {
				return new \WP_Error( 'approval_token_required', 'Esta acción requiere un token de aprobación.' );
			}

			return array(
				'action_key'       => $action_key,
				'approval_id'      => 103,
				'approval_uuid'    => 'oa-historical-103',
				'actor_user_id'    => get_current_user_id(),
				'approver_user_id' => get_current_user_id(),
				'mode'             => 'approval',
				'payload_hash'     => hash( 'sha256', json_encode( $args['payload'] ?? array() ) ),
				'expires_at'       => '2026-04-22 11:05:00',
			);
		}
	}
}

namespace ASDLabs\Finance\Finance {
	class FiscalYearService {
		public function get_context() {
			return array(
				'start_year' => 2026,
			);
		}

		public function label_for_year( $year ) {
			return 'FY ' . (int) $year;
		}
	}

	class CommerceOrderIndexRepository {
		public function summarize_resolution_candidates( $filters ) {
			return $GLOBALS['ASDL_TEST_HISTORICAL_SUMMARY'];
		}

		public function list_resolution_candidates( $filters, $cursor = 0, $limit = 200 ) {
			return $GLOBALS['ASDL_TEST_HISTORICAL_ITEMS'];
		}

		public function apply_historical_resolution( $row_ids, $batch_id, $reason_key, $note ) {
			return true;
		}
	}

	class CommerceContactRollupsRepository {
		public function rebuild_for_year( $year ) {
			return true;
		}
	}

	class HistoricalResolutionBatchesRepository {
		public function list_batches( $limit = 12 ) {
			return array();
		}

		public function create_batch( array $data ) {
			return 501;
		}

		public function update_batch( $batch_id, array $data ) {
			return true;
		}

		public function append_items( $batch_id, array $items ) {
			return true;
		}
	}

	class HistoricalIndexRebuildService {
		public function is_year_indexed( $year ) {
			return true;
		}

		public function is_year_closable( $year ) {
			return $year <= 2024;
		}

		public function is_year_special_case_allowed( $year ) {
			return 2025 === (int) $year;
		}

		public function bump_data_version() {
			return true;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Integrations/Approvals/ApprovalBridge.php';
	require_once dirname( __DIR__ ) . '/src/Finance/HistoricalResolutionService.php';

	use ASDLabs\Finance\Finance\HistoricalResolutionService;

	function assert_true( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . ' | expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) );
		}
	}

	$GLOBALS['ASDL_TEST_HISTORICAL_SUMMARY'] = array(
		'item_count'     => 1,
		'balance_total'  => 42.50,
	);
	$GLOBALS['ASDL_TEST_HISTORICAL_ITEMS'] = array(
		array(
			'id'               => 9001,
			'external_order_id'=> 214417,
			'order_number'     => '#770001',
			'fiscal_year'      => 2024,
			'provider'         => 'woocommerce',
			'balance'          => 42.50,
			'display_name'     => 'Jose Daniel Romero',
			'customer_email'   => 'jose@example.com',
		),
	);

	$args = array(
		'fiscal_year_from'      => 2025,
		'fiscal_year_to'        => 2025,
		'contact_id'            => 465,
		'provider'              => 'all',
		'search'                => 'jose romero',
		'reason_key'            => 'historical_cleanup',
		'note'                  => 'Validacion TOTP requerida para este caso especial.',
		'batch_size'            => 200,
		'special_previous_year' => '1',
		'selected_row_ids'      => '9001',
	);

	$GLOBALS['ASDL_TEST_CAPABILITIES'] = array(
		'manage_woocommerce' => true,
	);

	$service = new HistoricalResolutionService();
	$old_year_args = array_merge(
		$args,
		array(
			'fiscal_year_from' => 2024,
			'fiscal_year_to'   => 2024,
		)
	);
	$old_year_preview = $service->preview( $old_year_args );
	assert_true( ! is_wp_error( $old_year_preview ), 'El preview historico debe seguir funcionando en rangos viejos aunque Caso especial este marcado.' );
	assert_true( empty( $old_year_preview['approval_gate']['requires_approval'] ), 'No debe pedirse TOTP si el rango no toca el ejercicio inmediatamente anterior.' );

	$old_year_start = $service->start_batch( $old_year_args );
	assert_true( ! is_wp_error( $old_year_start ), 'El batch historico no debe exigir TOTP si Caso especial no aplica realmente al rango seleccionado.' );

	$GLOBALS['ASDL_TEST_HISTORICAL_ITEMS'] = array(
		array(
			'id'               => 9001,
			'external_order_id'=> 214417,
			'order_number'     => '#880564212',
			'fiscal_year'      => 2025,
			'provider'         => 'woocommerce',
			'balance'          => 42.50,
			'display_name'     => 'Jose Daniel Romero',
			'customer_email'   => 'jose@example.com',
		),
	);

	$preview = $service->preview( $args );
	assert_true( ! is_wp_error( $preview ), 'El preview historico debe permitir preparar el caso especial a un usuario operativo.' );
	assert_true( ! empty( $preview['approval_gate']['requires_approval'] ), 'El preview historico debe exigir aprobacion TOTP para el caso especial del ejercicio inmediatamente anterior.' );
	assert_true( empty( $preview['approval_gate']['can_bypass'] ), 'El usuario operativo no debe recibir bypass admin en el caso especial.' );

	$blocked = $service->start_batch( $args );
	assert_true( is_wp_error( $blocked ), 'El start historico debe bloquearse sin approval_token cuando el caso especial requiere TOTP.' );
	assert_same( 'approval_token_required', $blocked->get_error_code(), 'El caso especial debe rechazar start sin token aprobado.' );

	$authorized = $service->start_batch(
		array_merge(
			$args,
			array(
				'approval_token' => 'tok-ok',
			)
		)
	);
	assert_true( ! is_wp_error( $authorized ), 'El start historico debe permitir el caso especial cuando llega un approval_token valido.' );
	assert_same( 'running', (string) ( $authorized['status'] ?? '' ), 'El job historico debe quedar corriendo tras iniciar el batch aprobado.' );
	assert_same( 501, (int) ( $authorized['batch_id'] ?? 0 ), 'El batch historico debe crear el lote esperado.' );

	$GLOBALS['ASDL_TEST_CAPABILITIES'] = array(
		'manage_options' => true,
	);

	$service = new HistoricalResolutionService();
	$preview = $service->preview( $args );
	assert_true( ! empty( $preview['approval_gate']['requires_approval'] ), 'El administrador tambien debe validar TOTP en el caso especial historico.' );
	assert_true( empty( $preview['approval_gate']['can_bypass'] ), 'El bypass admin debe quedar desactivado en el caso especial historico.' );

	$admin_missing_token = $service->start_batch( $args );
	assert_true( is_wp_error( $admin_missing_token ), 'El administrador ya no debe iniciar el caso especial historico sin token adicional.' );
	assert_same( 'approval_token_required', $admin_missing_token->get_error_code(), 'El caso especial historico debe exigir token tambien al administrador.' );

	$admin = $service->start_batch(
		array_merge(
			$args,
			array(
				'approval_token' => 'tok-ok',
			)
		)
	);
	assert_true( ! is_wp_error( $admin ), 'El administrador debe poder iniciar el caso especial historico con token valido.' );

	echo "Historical resolution approval regression checks passed.\n";
}
