<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	$GLOBALS['asdl_test_options'] = array(
		'csfx_discount_enabled'    => 1,
		'csfx_discount_percent'    => 31.03,
		'asdl_fin_payment_methods' => array(),
	);
	$GLOBALS['ASDL_TEST_CONTACTS']                 = array();
	$GLOBALS['ASDL_TEST_ORDERS']                   = array();
	$GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'] = array();
	$GLOBALS['ASDL_TEST_HISTORICAL_ROWS']          = array();

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
	require_once dirname( __DIR__ ) . '/src/Finance/OrderSettlementBatchService.php';

	use ASDLabs\Finance\Finance\OrderSettlementBatchService;
	use ASDLabs\Finance\Finance\OrderSettlementPlannerService;

	function reset_dual_eligibility_state() {
		$GLOBALS['asdl_test_options'] = array(
			'csfx_discount_enabled'    => 1,
			'csfx_discount_percent'    => 31.03,
			'asdl_fin_payment_methods' => array(
				'binance'       => array(
					'label'         => 'Binance',
					'dual_eligible' => true,
				),
				'bank_transfer' => array(
					'label'         => 'Transferencia bancaria',
					'dual_eligible' => false,
				),
			),
		);
		$GLOBALS['ASDL_TEST_CONTACTS'] = array(
			465 => array(
				'id'         => 465,
				'wp_user_id' => 1192,
				'email'      => 'miguel.silva@example.com',
			),
		);
		$GLOBALS['ASDL_TEST_ORDERS']                    = array();
		$GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'] = array();
		$GLOBALS['ASDL_TEST_HISTORICAL_ROWS']           = array();
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
				'date_created'        => '2026-04-18 11:30:00',
				'display_name'        => 'Miguel Silva V20280609',
				'billing_email'       => 'miguel.silva@example.com',
				'status'              => 'pending',
				'status_label'        => 'Pendiente de pago',
				'edit_url'            => 'https://example.test/wp-admin/post.php?post=214097&action=edit',
			),
			$overrides
		);
	}

	function preview_args( $method_key, $total ) {
		return array(
			'contact_id'         => 465,
			'account_id'         => 17,
			'payment_date'       => '2026-04-18',
			'total'              => $total,
			'currency'           => 'USD',
			'method_key'         => $method_key,
			'reference'          => 'REG-220',
			'notes'              => 'Regression dual eligibility',
			'dual_discount_mode' => 'auto',
			'selection_mode'     => 'oldest_first',
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

	function build_batch_service() {
		$reflection = new \ReflectionClass( OrderSettlementBatchService::class );
		$service    = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'planner' );

		$property->setValue( $service, new OrderSettlementPlannerService() );

		return $service;
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

	function assert_contains( $needle, $haystack, $message ) {
		if ( false === strpos( (string) $haystack, (string) $needle ) ) {
			throw new \RuntimeException(
				$message . ' | needle=' . var_export( $needle, true ) . ' haystack=' . var_export( $haystack, true )
			);
		}
	}

	reset_dual_eligibility_state();

	$eligible_preview = run_preview(
		preview_args( 'binance', '18.63' ),
		array( base_order() )
	);

	assert_true( ! empty( $eligible_preview['uses_dual'] ), 'Eligible preview must use dual pricing.' );
	assert_same( 'active', (string) ( $eligible_preview['dual_status']['key'] ?? '' ), 'Eligible preview must report active dual status.' );
	assert_true( empty( $eligible_preview['execution_blocked'] ), 'Eligible preview must not be blocked.' );
	assert_true( (float) ( $eligible_preview['summary']['discount_applied_total'] ?? 0 ) > 0, 'Eligible preview must calculate a dual discount.' );

	$blocked_preview = run_preview(
		preview_args( 'bank_transfer', '18.63' ),
		array( base_order() )
	);

	assert_true( empty( $blocked_preview['uses_dual'] ), 'Non-eligible preview must not use dual pricing.' );
	assert_same( 'method', (string) ( $blocked_preview['dual_status']['key'] ?? '' ), 'Non-eligible preview must report method mismatch.' );
	assert_true( ! empty( $blocked_preview['execution_blocked'] ), 'Non-eligible preview must be marked as blocked.' );
	assert_contains(
		'Cambia el metodo o apaga el descuento',
		(string) ( $blocked_preview['execution_blocked_message'] ?? '' ),
		'Non-eligible preview must explain how to unblock execution.'
	);

	$GLOBALS['ASDL_TEST_ORDERS'] = array( base_order() );
	$batch_service               = build_batch_service();
	$blocked_start               = $batch_service->start( preview_args( 'bank_transfer', '18.63' ), 'profile_settlement' );

	assert_true( is_wp_error( $blocked_start ), 'Non-eligible start must return a WP_Error.' );
	assert_same( 'asdl_fin_order_settlement_dual_blocked', $blocked_start->get_error_code(), 'Non-eligible start must stop before batch creation.' );
	assert_contains(
		'Cambia el metodo o apaga el descuento',
		$blocked_start->get_error_message(),
		'Non-eligible start must reuse the blocking message shown in preview.'
	);

	echo "OK\n";
}
