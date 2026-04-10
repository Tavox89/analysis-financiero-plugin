<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	$GLOBALS['asdl_test_options'] = array(
		'csfx_discount_enabled'  => 1,
		'csfx_discount_percent'  => 31.03,
		'asdl_fin_payment_methods' => array(),
	);
	$GLOBALS['ASDL_TEST_CONTACTS'] = array();
	$GLOBALS['ASDL_TEST_ORDERS'] = array();
	$GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'] = array();
	$GLOBALS['ASDL_TEST_HISTORICAL_ROWS'] = array();

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
		$value = (string) $value;
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

	function selected( $selected, $current, $display = true ) {
		return (string) $selected === (string) $current ? 'selected="selected"' : '';
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
	require_once dirname( __DIR__ ) . '/src/Finance/OrderSettlementPlannerService.php';

	use ASDLabs\Finance\Finance\OrderSettlementPlannerService;

	function reset_dual_regression_state() {
		$GLOBALS['asdl_test_options'] = array(
			'csfx_discount_enabled'  => 1,
			'csfx_discount_percent'  => 31.03,
			'asdl_fin_payment_methods' => array(),
		);
		$GLOBALS['ASDL_TEST_CONTACTS'] = array(
			1345 => array(
				'id'         => 1345,
				'wp_user_id' => 8806,
				'email'      => 'edgardo@example.com',
			),
		);
		$GLOBALS['ASDL_TEST_ORDERS'] = array();
		$GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'] = array();
		$GLOBALS['ASDL_TEST_HISTORICAL_ROWS'] = array();
	}

	function base_order( array $overrides = array() ) {
		return array_replace(
			array(
				'order_id'            => 197317,
				'order_number'        => '77259698',
				'provider'            => 'openpos',
				'currency'            => 'USD',
				'effective_due_total' => 100.0,
				'is_effectively_open' => true,
				'document_id'         => 81,
				'date_created'        => '2026-04-01 17:07:25',
				'display_name'        => 'Edgardo Piña V20282068',
				'billing_email'       => 'edgardo@example.com',
				'status'              => 'pending',
				'status_label'        => 'Pendiente de pago',
				'edit_url'            => 'https://example.test/wp-admin/post.php?post=197317&action=edit',
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

	function run_preview( array $args, array $orders, array $discount_snapshots = array() ) {
		$GLOBALS['ASDL_TEST_ORDERS'] = $orders;
		$GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'] = $discount_snapshots;

		$planner = new OrderSettlementPlannerService();
		$result  = $planner->preview( $args, 'profile_settlement' );

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

	reset_dual_regression_state();

	$cash_order = base_order();
	$cash_key   = item_key_for_order( $cash_order );

	$cash_specific = run_preview(
		array(
			'contact_id'         => 1345,
			'total'              => 68.97,
			'currency'           => 'USD',
			'method_key'         => 'cash',
			'dual_discount_mode' => 'auto',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $cash_key ),
		),
		array( $cash_order )
	);
	assert_true( ! empty( $cash_specific['uses_dual'] ), 'specific + USD + cash debe usar dual.' );
	assert_same( 'specific', $cash_specific['selection_mode'], 'El preview debe quedar en specific.' );
	assert_same( 'none', $cash_specific['items'][0]['discount_detection']['status'], 'No debe detectar descuento previo en el caso base.' );
	assert_true( (float) $cash_specific['items'][0]['discount_amount'] > 0, 'specific + USD + cash debe generar descuento del item.' );
	assert_float_equals( 31.03, (float) $cash_specific['summary']['discount_applied_total'], 'specific + USD + cash debe cubrir con el descuento esperado.' );

	reset_dual_regression_state();
	$zelle_order = base_order( array( 'order_id' => 197318, 'order_number' => '77259699' ) );
	$zelle_key   = item_key_for_order( $zelle_order );
	$zelle_specific = run_preview(
		array(
			'contact_id'         => 1345,
			'total'              => 68.97,
			'currency'           => 'USD',
			'method_key'         => 'zelle',
			'dual_discount_mode' => 'auto',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $zelle_key ),
		),
		array( $zelle_order )
	);
	assert_true( ! empty( $zelle_specific['uses_dual'] ), 'specific + USD + zelle debe usar dual.' );
	assert_true( (float) $zelle_specific['items'][0]['discount_amount'] > 0, 'specific + USD + zelle debe generar descuento.' );

	reset_dual_regression_state();
	$mobile_order = base_order( array( 'order_id' => 197319, 'order_number' => '77259700' ) );
	$mobile_key   = item_key_for_order( $mobile_order );
	$mobile_specific = run_preview(
		array(
			'contact_id'         => 1345,
			'total'              => 68.97,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'auto',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $mobile_key ),
		),
		array( $mobile_order )
	);
	assert_true( empty( $mobile_specific['uses_dual'] ), 'specific + USD + mobile_payment no debe usar dual.' );
	assert_float_equals( 0.0, (float) $mobile_specific['summary']['discount_applied_total'], 'specific + USD + mobile_payment no debe dar descuento.' );

	reset_dual_regression_state();
	$prior_order = base_order();
	$prior_key   = item_key_for_order( $prior_order );
	$prior_specific = run_preview(
		array(
			'contact_id'         => 1345,
			'total'              => 68.97,
			'currency'           => 'USD',
			'method_key'         => 'cash',
			'dual_discount_mode' => 'auto',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $prior_key ),
		),
		array( $prior_order ),
		array(
			197317 => array(
				'known'            => true,
				'discount_total'   => 2.974,
				'discount_percent' => 2.974,
				'source'           => 'manual',
			),
		)
	);
	assert_true( ! empty( $prior_specific['uses_dual'] ), 'El contexto dual puede seguir activo aunque el item tenga descuento previo distinto.' );
	assert_same( 'different', $prior_specific['items'][0]['discount_detection']['status'], 'Debe detectar descuento previo distinto.' );
	assert_float_equals( 0.0, (float) $prior_specific['items'][0]['discount_amount'], 'El item con descuento previo distinto no debe recibir descuento adicional.' );
	assert_float_equals( 0.0, (float) $prior_specific['summary']['discount_applied_total'], 'El lote no debe sumar descuento cuando el item ya tenia descuento distinto.' );

	reset_dual_regression_state();
	$oldest_order = base_order();
	$oldest_first = run_preview(
		array(
			'contact_id'         => 1345,
			'total'              => 68.97,
			'currency'           => 'USD',
			'method_key'         => 'cash',
			'dual_discount_mode' => 'auto',
			'selection_mode'     => 'oldest_first',
		),
		array( $oldest_order )
	);
	assert_true( ! empty( $oldest_first['uses_dual'] ), 'oldest_first + USD + cash no debe romper el dual.' );
	assert_true( (float) $oldest_first['items'][0]['discount_amount'] > 0, 'oldest_first + USD + cash debe seguir generando descuento.' );

	reset_dual_regression_state();
	$ves_order = base_order( array( 'currency' => 'VES' ) );
	$ves_key   = item_key_for_order( $ves_order );
	$non_usd = run_preview(
		array(
			'contact_id'         => 1345,
			'total'              => 68.97,
			'currency'           => 'VES',
			'method_key'         => 'cash',
			'dual_discount_mode' => 'auto',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $ves_key ),
		),
		array( $ves_order )
	);
	assert_true( empty( $non_usd['uses_dual'] ), 'non-USD no debe usar dual.' );
	assert_float_equals( 0.0, (float) $non_usd['summary']['discount_applied_total'], 'non-USD no debe aplicar descuento.' );

	echo "Order settlement dual regression checks passed.\n";
}
