<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	$GLOBALS['asdl_test_options'] = array(
		'csfx_discount_enabled'    => 1,
		'csfx_discount_percent'    => 31.03,
		'asdl_fin_payment_methods' => array(),
	);
	$GLOBALS['ASDL_TEST_CONTACTS'] = array();
	$GLOBALS['ASDL_TEST_ORDERS'] = array();
	$GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'] = array();
	$GLOBALS['ASDL_TEST_HISTORICAL_ROWS'] = array();
	$GLOBALS['ASDL_TEST_CURRENT_USER_ID'] = 22;
	$GLOBALS['ASDL_TEST_CAPABILITIES'] = array();

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

	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['asdl_test_options'] ) ? $GLOBALS['asdl_test_options'][ $key ] : $default;
	}

	function update_option( $key, $value, $autoload = false ) {
		$GLOBALS['asdl_test_options'][ $key ] = $value;
		return true;
	}

	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function wp_salt( $scheme = 'auth' ) {
		return 'asdl-finance-approval-salt';
	}

	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}

	function esc_url_raw( $value ) {
		return (string) $value;
	}

	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}

	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['ASDL_TEST_CAPABILITIES'][ (string) $capability ] );
	}

	function get_current_user_id() {
		return (int) $GLOBALS['ASDL_TEST_CURRENT_USER_ID'];
	}

	function wp_generate_uuid4() {
		static $counter = 1;
		$counter++;
		return sprintf( '00000000-0000-4000-8000-%012d', $counter );
	}

	function current_time( $type = 'mysql' ) {
		return 'mysql' === $type ? '2026-04-22 10:00:00' : '2026-04-22';
	}

	function do_action( $hook, ...$args ) {
		return null;
	}

	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
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
				'can_bypass'          => ! empty( $GLOBALS['ASDL_TEST_OA_CAN_BYPASS'] ),
				'requires_approval'   => array_key_exists( 'ASDL_TEST_OA_REQUIRES_APPROVAL', $GLOBALS ) ? ! empty( $GLOBALS['ASDL_TEST_OA_REQUIRES_APPROVAL'] ) : true,
				'allow_self_approval' => true,
				'token_ttl_seconds'   => 300,
				'eligible_approvers'  => array(
					array(
						'id'           => get_current_user_id(),
						'display_name' => 'Operador QA',
						'user_login'   => 'shop_manager',
						'user_email'   => 'shop@example.com',
					),
				),
			);
		}

		public static function authorize_execution( $action_key, $args = array() ) {
			if ( 'tok-ok' !== (string) ( $args['approval_token'] ?? '' ) ) {
				if ( ! empty( $GLOBALS['ASDL_TEST_OA_ALLOW_BYPASS_WITHOUT_TOKEN'] ) ) {
					return array(
						'action_key'    => $action_key,
						'actor_user_id' => get_current_user_id(),
						'mode'          => 'bypass',
						'plugin_active' => true,
					);
				}

				return new \WP_Error( 'approval_token_required', 'Esta acción requiere un token de aprobación.' );
			}

			return array(
				'action_key'       => $action_key,
				'approval_id'      => 88,
				'approval_uuid'    => 'oa-test-88',
				'actor_user_id'    => get_current_user_id(),
				'approver_user_id' => get_current_user_id(),
				'mode'             => 'approval',
				'payload_hash'     => hash( 'sha256', wp_json_encode( $args['payload'] ?? array() ) ),
				'expires_at'       => '2026-04-22 10:05:00',
			);
		}
	}
}

namespace ASDLabs\Finance\Finance {
	class BaseRepository {
		protected function error( $code, $message ) {
			return new \WP_Error( $code, $message );
		}

		protected function sanitize_date( $value ) {
			$value = \sanitize_text_field( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		}

		protected function normalize_balance_amount( $amount, $currency = '' ) {
			return round( (float) $amount, 6 );
		}

		protected function money_balance_is_zero( $amount, $currency = '' ) {
			return abs( round( (float) $amount, 6 ) ) <= 0.00001;
		}
	}

	class ContactsRepository {
		public function find( $contact_id ) {
			return $GLOBALS['ASDL_TEST_CONTACTS'][ (int) $contact_id ] ?? array();
		}
	}

	class FiscalYearService {
		public function get_context() {
			return array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-12-31',
			);
		}
	}

	class CommerceOrderIndexRepository {
		public function list_collectible_orders( $args ) {
			return $GLOBALS['ASDL_TEST_HISTORICAL_ROWS'];
		}
	}

	class PaymentsRepository {
		public static $created = array();
		public static $next_id = 900;

		public function for_contact( $contact_id, $limit = 200 ) {
			return array();
		}

		public function create( array $data ) {
			$payment_id = self::$next_id++;
			self::$created[] = array_merge( array( 'id' => $payment_id ), $data );
			return $payment_id;
		}
	}

	class DocumentsRepository {
		public function for_contact( $contact_id, $limit = 200, $include_meta = true ) {
			return array();
		}
	}

	class PaymentAllocationService {
	}

	class EventsRepository {
	}

	class OrderSettlementBatchesRepository {
		public $batch = array();
		public $items = array();

		public function find_by_preview_signature( $contact_id, $origin, $signature ) {
			return array();
		}

		public function create_batch( array $data ) {
			$this->batch = array_merge( array( 'id' => 77 ), $data );
			return 77;
		}

		public function append_items( $batch_id, array $items ) {
			$this->items = $items;
			return true;
		}

		public function find( $batch_id ) {
			if ( empty( $this->batch ) ) {
				return array();
			}

			return array(
				'id'              => 77,
				'status'          => $this->batch['status'] ?? 'running',
				'processed_count' => 0,
				'item_count'      => count( $this->items ),
				'processed_total' => 0,
				'total_received'  => (float) ( $this->batch['total_received'] ?? 0 ),
				'total_covered'   => (float) ( $this->batch['total_covered'] ?? 0 ),
				'discount_total'  => (float) ( $this->batch['discount_total'] ?? 0 ),
				'contact_id'      => (int) ( $this->batch['contact_id'] ?? 0 ),
				'origin'          => (string) ( $this->batch['origin'] ?? 'profile_settlement' ),
				'updated_at'      => '2026-04-22 10:00:00',
				'meta'            => $this->batch['meta_json'] ?? array(),
			);
		}

		public function update_batch( $batch_id, array $data ) {
			$this->batch = array_merge( $this->batch, $data );
			if ( isset( $data['meta_json'] ) ) {
				$this->batch['meta_json'] = $data['meta_json'];
			}
			return true;
		}

		public function count_items_by_status( $batch_id, $status ) {
			return 0;
		}

		public function list_batch_errors( $batch_id, $limit = 20 ) {
			return array();
		}
	}
}

namespace ASDLabs\Finance\Integrations\Woo {
	class OrderSyncService {
		public function list_orders( $args ) {
			return $GLOBALS['ASDL_TEST_ORDERS'];
		}

		public function collectible_statuses() {
			return array( 'pending', 'processing', 'on-hold' );
		}

		public function get_order_discount_snapshot( $order_id ) {
			return $GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'][ (int) $order_id ] ?? array();
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Integrations/Approvals/ApprovalBridge.php';
	require_once dirname( __DIR__ ) . '/src/Finance/PaymentMethodsService.php';
	require_once dirname( __DIR__ ) . '/src/Integrations/Woo/DualPricingService.php';
	require_once dirname( __DIR__ ) . '/src/Finance/OrderSettlementPlannerService.php';
	require_once dirname( __DIR__ ) . '/src/Finance/OrderSettlementBatchService.php';

	use ASDLabs\Finance\Finance\OrderSettlementBatchService;
	use ASDLabs\Finance\Finance\OrderSettlementBatchesRepository;
	use ASDLabs\Finance\Finance\OrderSettlementPlannerService;
	use ASDLabs\Finance\Finance\PaymentsRepository;

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

	function base_order() {
		return array(
			'order_id'            => 214097,
			'order_number'        => '880564130',
			'provider'            => 'openpos',
			'currency'            => 'USD',
			'effective_due_total' => 27.00,
			'is_effectively_open' => true,
			'document_id'         => 220,
			'date_created'        => '2026-04-21 12:56:51',
			'display_name'        => 'Miguel Silva V20280609',
			'billing_email'       => 'miguel@example.com',
			'status'              => 'pending',
			'status_label'        => 'Pendiente de pago',
			'edit_url'            => 'https://example.test/wp-admin/post.php?post=214097&action=edit',
		);
	}

	function item_key_for_order( array $order ) {
		return implode(
			':',
			array(
				'current_live',
				sanitize_key( (string) ( $order['provider'] ?? '' ) ),
				(int) ( $order['order_id'] ?? 0 ),
				(int) ( $order['document_id'] ?? 0 ),
			)
		);
	}

	function preview_args( array $overrides = array() ) {
		return array_replace(
			array(
				'contact_id'                           => 465,
				'total'                                => 18.62,
				'currency'                             => 'USD',
				'method_key'                           => 'mobile_payment',
				'dual_discount_mode'                   => 'off',
				'selection_mode'                       => 'specific',
				'extraordinary_closure_enabled'        => '1',
				'extraordinary_closure_reason'         => 'ajuste_operativo',
				'extraordinary_closure_approval_reference' => '',
				'extraordinary_closure_note'           => 'Validacion TOTP requerida para cerrar la diferencia.',
				'extraordinary_closure_acknowledged'   => '1',
			),
			$overrides
		);
	}

	function build_service_with_preview( array $preview ) {
		$reflection = new \ReflectionClass( OrderSettlementBatchService::class );
		$service    = $reflection->newInstanceWithoutConstructor();
		$batches    = new OrderSettlementBatchesRepository();
		$planner    = new class( $preview ) {
			private $preview;
			public function __construct( array $preview ) { $this->preview = $preview; }
			public function preview( array $args, $origin = 'profile_settlement' ) { return $this->preview; }
		};

		$properties = array(
			'planner'       => $planner,
			'batches'       => $batches,
			'payments'      => new PaymentsRepository(),
			'allocations'   => new \ASDLabs\Finance\Finance\PaymentAllocationService(),
			'documents'     => new \ASDLabs\Finance\Finance\DocumentsRepository(),
			'events'        => new \ASDLabs\Finance\Finance\EventsRepository(),
			'order_service' => new \ASDLabs\Finance\Integrations\Woo\OrderSyncService(),
			'approvals'     => new \ASDLabs\Finance\Integrations\Approvals\ApprovalBridge(),
		);

		foreach ( $properties as $name => $value ) {
			$property = $reflection->getProperty( $name );
			$property->setValue( $service, $value );
		}

		return array( $service, $batches );
	}

	$GLOBALS['ASDL_TEST_CONTACTS'] = array(
		465 => array(
			'id'         => 465,
			'wp_user_id' => 1192,
			'email'      => 'miguel@example.com',
		),
	);

	$order = base_order();
	$key   = item_key_for_order( $order );
	$GLOBALS['ASDL_TEST_ORDERS'] = array( $order );

	$GLOBALS['ASDL_TEST_CAPABILITIES'] = array(
		'manage_woocommerce' => true,
	);

	$planner = new OrderSettlementPlannerService();
	$normal_preview = $planner->preview(
		preview_args(
			array(
				'selected_item_keys'                 => array( $key ),
				'extraordinary_closure_enabled'      => '0',
				'extraordinary_closure_reason'       => '',
				'extraordinary_closure_approval_reference' => '',
				'extraordinary_closure_note'         => '',
				'extraordinary_closure_acknowledged' => '0',
			)
		),
		'profile_settlement'
	);

	assert_true( ! is_wp_error( $normal_preview ), 'El preview normal debe seguir disponible para el usuario operativo.' );
	assert_true( empty( $normal_preview['approval_gate']['requires_approval'] ), 'Un abono normal sin cierre extraordinario no debe pedir validacion TOTP.' );

	list( $service, $batches ) = build_service_with_preview(
		array_merge(
			$normal_preview,
			array(
				'execution_mode' => 'runner',
				'batch_payload'  => array_merge(
					(array) ( $normal_preview['batch_payload'] ?? array() ),
					array( 'execution_mode' => 'runner' )
				),
			)
		)
	);
	$normal_start = $service->start(
		array(
			'preview_signature' => (string) ( $normal_preview['preview_signature'] ?? '' ),
		),
		'profile_settlement'
	);

	assert_true( ! is_wp_error( $normal_start ), 'El settlement normal no debe quedar bloqueado por TOTP cuando no hay cierre extraordinario.' );

	$preview = $planner->preview(
		preview_args(
			array(
				'selected_item_keys' => array( $key ),
			)
		),
		'profile_settlement'
	);

	assert_true( ! is_wp_error( $preview ), 'El preview del cierre extraordinario debe seguir disponible para un usuario operativo.' );
	assert_true( empty( $preview['execution_blocked'] ), 'El preview no debe bloquear el cierre extraordinario para un usuario operativo con acceso funcional.' );
	assert_true( ! empty( $preview['approval_gate']['requires_approval'] ), 'El preview debe marcar que el cierre extraordinario requiere validacion TOTP para el usuario operativo.' );
	assert_true( empty( $preview['approval_gate']['can_bypass'] ), 'El usuario operativo no debe tener bypass admin en este flujo.' );
	$preview['execution_mode'] = 'runner';
	$preview['batch_payload']['execution_mode'] = 'runner';

	list( $service, $batches ) = build_service_with_preview( $preview );
	$missing_token = $service->start(
		array(
			'preview_signature' => (string) ( $preview['preview_signature'] ?? '' ),
		),
		'profile_settlement'
	);

	assert_true( is_wp_error( $missing_token ), 'El start debe bloquearse si el cierre extraordinario no trae approval_token.' );
	assert_same( 'approval_token_required', $missing_token->get_error_code(), 'El start debe rechazar el cierre extraordinario sin token aprobado.' );

	list( $service, $batches ) = build_service_with_preview( $preview );
	$authorized_start = $service->start(
		array(
			'preview_signature' => (string) ( $preview['preview_signature'] ?? '' ),
			'approval_token'    => 'tok-ok',
		),
		'profile_settlement'
	);

	assert_true( ! is_wp_error( $authorized_start ), 'El start debe permitir el cierre extraordinario cuando llega un approval_token valido.' );
	assert_same( 'running', (string) ( $authorized_start['job']['status'] ?? '' ), 'El lote debe quedar corriendo tras iniciar el settlement aprobado.' );
	assert_same( 'self', (string) ( $authorized_start['job']['approval_mode'] ?? '' ), 'La aprobacion debe registrarse como self cuando el mismo operador se valida con TOTP.' );

	$GLOBALS['ASDL_TEST_CAPABILITIES'] = array(
		'manage_options' => true,
	);
	$GLOBALS['ASDL_TEST_OA_CAN_BYPASS'] = true;
	$GLOBALS['ASDL_TEST_OA_ALLOW_BYPASS_WITHOUT_TOKEN'] = true;

	$planner = new OrderSettlementPlannerService();
	$preview = $planner->preview(
		preview_args(
			array(
				'selected_item_keys' => array( $key ),
			)
		),
		'profile_settlement'
	);

	assert_true( ! empty( $preview['approval_gate']['requires_approval'] ), 'El administrador tambien debe validar TOTP para el cierre extraordinario.' );
	assert_true( empty( $preview['approval_gate']['can_bypass'] ), 'El bypass admin debe quedar desactivado aunque el plugin externo lo permita.' );
	$preview['execution_mode'] = 'runner';
	$preview['batch_payload']['execution_mode'] = 'runner';

	list( $service, $batches ) = build_service_with_preview( $preview );
	$admin_missing_token = $service->start(
		array(
			'preview_signature' => (string) ( $preview['preview_signature'] ?? '' ),
		),
		'profile_settlement'
	);

	assert_true( is_wp_error( $admin_missing_token ), 'El administrador ya no debe poder iniciar el cierre extraordinario sin token TOTP.' );
	assert_same( 'approval_token_required', $admin_missing_token->get_error_code(), 'El bloqueo admin debe exigir approval_token aunque el plugin externo responda bypass.' );

	list( $service, $batches ) = build_service_with_preview( $preview );
	$admin_start = $service->start(
		array(
			'preview_signature' => (string) ( $preview['preview_signature'] ?? '' ),
			'approval_token'    => 'tok-ok',
		),
		'profile_settlement'
	);

	assert_true( ! is_wp_error( $admin_start ), 'El administrador debe poder iniciar el cierre extraordinario con token TOTP valido.' );
	assert_same( 'self', (string) ( $admin_start['job']['approval_mode'] ?? '' ), 'El start admin debe quedar auditado como aprobacion self.' );

	unset( $GLOBALS['ASDL_TEST_OA_CAN_BYPASS'], $GLOBALS['ASDL_TEST_OA_ALLOW_BYPASS_WITHOUT_TOKEN'] );

	echo "Order settlement approval regression checks passed.\n";
}
