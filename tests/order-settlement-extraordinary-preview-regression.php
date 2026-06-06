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
	$GLOBALS['ASDL_TEST_CURRENT_USER_CAN'] = true;

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

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function wp_salt( $scheme = 'auth' ) {
		return 'asdl-finance-test-salt';
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
		return ! empty( $GLOBALS['ASDL_TEST_CURRENT_USER_CAN'] );
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
		public function for_contact( $contact_id, $limit = 200 ) {
			return array();
		}
	}

	class DocumentsRepository {
		public function for_contact( $contact_id, $limit = 200, $include_meta = true ) {
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
	require_once dirname( __DIR__ ) . '/src/Finance/PaymentMethodsService.php';
	require_once dirname( __DIR__ ) . '/src/Integrations/Woo/DualPricingService.php';
	require_once dirname( __DIR__ ) . '/src/Integrations/Approvals/ApprovalBridge.php';
	require_once dirname( __DIR__ ) . '/src/Finance/OrderSettlementPlannerService.php';

	use ASDLabs\Finance\Finance\OrderSettlementPlannerService;

	function reset_extraordinary_preview_state() {
		$GLOBALS['asdl_test_options'] = array(
			'csfx_discount_enabled'    => 1,
			'csfx_discount_percent'    => 31.03,
			'asdl_fin_payment_methods' => array(),
		);
		$GLOBALS['ASDL_TEST_CURRENT_USER_CAN'] = true;
		$GLOBALS['ASDL_TEST_CONTACTS'] = array(
			465 => array(
				'id'         => 465,
				'wp_user_id' => 1192,
				'email'      => 'miguel@example.com',
			),
		);
		$GLOBALS['ASDL_TEST_ORDERS'] = array();
		$GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'] = array();
		$GLOBALS['ASDL_TEST_HISTORICAL_ROWS'] = array();
	}

	function base_order( array $overrides = array() ) {
		return array_replace(
			array(
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
			),
			$overrides
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
				'extraordinary_closure_reason'         => 'cierre_administrativo',
				'extraordinary_closure_approval_reference' => 'APR-7781',
				'extraordinary_closure_note'           => 'Se cierra la diferencia aprobada para cuadrar el pedido especifico.',
				'extraordinary_closure_acknowledged'   => '1',
			),
			$overrides
		);
	}

	function run_preview( array $args, array $orders ) {
		$GLOBALS['ASDL_TEST_ORDERS'] = $orders;
		$planner                     = new OrderSettlementPlannerService();
		$result                      = $planner->preview( $args, 'profile_settlement' );

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() );
		}

		return $result;
	}

	function assert_true( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . ' | expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true )
			);
		}
	}

	function assert_float_equals( $expected, $actual, $message, $tolerance = 0.0001 ) {
		if ( abs( (float) $expected - (float) $actual ) > $tolerance ) {
			throw new \RuntimeException(
				$message . ' | expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true )
			);
		}
	}

	reset_extraordinary_preview_state();
	$order_primary = base_order();
	$order_secondary = base_order(
		array(
			'order_id'            => 214198,
			'order_number'        => '880564255',
			'effective_due_total' => 12.10,
			'document_id'         => 221,
			'date_created'        => '2026-04-22 12:56:51',
			'edit_url'            => 'https://example.test/wp-admin/post.php?post=214198&action=edit',
		)
	);
	$primary_key = item_key_for_order( $order_primary );
	$secondary_key = item_key_for_order( $order_secondary );

	$eligible_preview = run_preview(
		preview_args(
			array(
				'selected_item_keys' => array( $primary_key ),
			)
		),
		array( $order_primary, $order_secondary )
	);
	assert_true( empty( $eligible_preview['execution_blocked'] ), 'El cierre extraordinario valido no debe quedar bloqueado.' );
	assert_float_equals( 8.38, (float) $eligible_preview['summary']['extraordinary_closure_total'], 'La diferencia extraordinaria debe calcularse contra el pedido especifico.' );
	assert_same( 1, count( $eligible_preview['items'] ), 'El preview extraordinario solo debe afectar el pedido marcado.' );
	assert_float_equals( 18.62, (float) $eligible_preview['items'][0]['customer_paid_amount'], 'El pago real debe conservar su monto real.' );
	assert_float_equals( 8.38, (float) $eligible_preview['items'][0]['extraordinary_closure_amount'], 'El item debe exponer el tramo extraordinario.' );
	assert_float_equals( 27.00, (float) $eligible_preview['items'][0]['covered_total'], 'El pedido debe quedar totalmente cubierto en preview.' );
	assert_float_equals( 0.0, (float) $eligible_preview['items'][0]['expected_balance_after'], 'El saldo proyectado del pedido debe quedar en cero.' );
	assert_true( ! empty( $eligible_preview['extraordinary_closure']['enabled'] ), 'La bandera extraordinaria debe mantenerse activa en la respuesta.' );
	assert_float_equals( 8.38, (float) $eligible_preview['batch_payload']['extraordinary_closure_total'], 'El lote debe congelar el monto extraordinario exacto.' );

	reset_extraordinary_preview_state();
	$without_manual_reference_preview = run_preview(
		preview_args(
			array(
				'selected_item_keys' => array( $primary_key ),
				'extraordinary_closure_approval_reference' => '',
			)
		),
		array( $order_primary, $order_secondary )
	);
	assert_true( empty( $without_manual_reference_preview['execution_blocked'] ), 'El cierre extraordinario no debe exigir una referencia manual cuando el flujo ya pedira TOTP.' );
	assert_float_equals( 8.38, (float) $without_manual_reference_preview['summary']['extraordinary_closure_total'], 'Sin referencia manual el cierre extraordinario debe calcular la misma diferencia.' );

	reset_extraordinary_preview_state();
	$without_payment_preview = run_preview(
		preview_args(
			array(
				'total'              => 5.99,
				'method_key'         => '',
				'selected_item_keys' => array( $primary_key ),
			)
		),
		array( $order_primary )
	);
	assert_true( empty( $without_payment_preview['execution_blocked'] ), 'El cierre extraordinario debe poder ejecutarse sin metodo cuando no hay abono real.' );
	assert_float_equals( 0.0, (float) $without_payment_preview['summary']['payment_recorded_total'], 'Sin metodo no debe congelarse ningun pago real.' );
	assert_float_equals( 0.0, (float) $without_payment_preview['items'][0]['customer_paid_amount'], 'Sin metodo el pedido no debe registrar abono real nuevo.' );
	assert_float_equals( 27.00, (float) $without_payment_preview['summary']['extraordinary_closure_total'], 'Sin metodo el cierre extraordinario debe cubrir el saldo restante completo del pedido seleccionado.' );
	assert_float_equals( 27.00, (float) $without_payment_preview['items'][0]['covered_total'], 'Sin metodo el pedido seleccionado debe quedar totalmente cubierto por el ajuste extraordinario.' );
	assert_true(
		false !== strpos( (string) $without_payment_preview['extraordinary_closure']['message'], 'sin registrar un pago real' ),
		'El preview debe explicar claramente que el cierre correra sin pago real nuevo.'
	);

	reset_extraordinary_preview_state();
	$blocked_multi = run_preview(
		preview_args(
			array(
				'selected_item_keys' => array( $primary_key, $secondary_key ),
			)
		),
		array( $order_primary, $order_secondary )
	);
	assert_true( ! empty( $blocked_multi['execution_blocked'] ), 'El cierre extraordinario debe bloquearse cuando se marcan varios pedidos.' );
	assert_true(
		false !== strpos( (string) $blocked_multi['execution_blocked_message'], 'un solo pedido' ),
		'El bloqueo por multiples pedidos debe ser explicito.'
	);

	reset_extraordinary_preview_state();
	$GLOBALS['ASDL_TEST_CURRENT_USER_CAN'] = false;
	$blocked_non_admin = run_preview(
		preview_args(
			array(
				'selected_item_keys' => array( $primary_key ),
			)
		),
		array( $order_primary )
	);
	assert_true( ! empty( $blocked_non_admin['execution_blocked'] ), 'El cierre extraordinario debe bloquearse cuando la cuenta no tiene permisos operativos para prepararlo.' );
	assert_true(
		false !== strpos( (string) $blocked_non_admin['execution_blocked_message'], 'permisos operativos' ),
		'El mensaje debe indicar claramente que la cuenta no tiene permisos operativos para preparar el cierre.'
	);

	echo "Order settlement extraordinary preview regression checks passed.\n";
}
