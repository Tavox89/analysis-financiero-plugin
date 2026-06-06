<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

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

	function sanitize_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_:-]/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}

	function current_time( $type = 'mysql' ) {
		return 'mysql' === $type ? '2026-04-09 00:00:00' : '2026-04-09';
	}

	function do_action( $hook, ...$args ) {
		return null;
	}
}

namespace ASDLabs\Finance\Finance {
	class BaseRepository {
		protected function error( $code, $message ) {
			return new \WP_Error( $code, $message );
		}

		protected function normalize_balance_amount( $amount, $currency = '' ) {
			$rounded = round( max( 0, (float) $amount ), 2 );

			return $rounded > 0 ? $rounded : 0.0;
		}

		protected function money_balance_is_zero( $amount, $currency = '' ) {
			return 0.0 === $this->normalize_balance_amount( $amount, $currency );
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

	class PaymentsRepository {
		public static $records = array();

		public function exists() {
			return true;
		}

		public function find( $payment_id ) {
			return self::$records[ (int) $payment_id ] ?? array();
		}

		public function set_available_amount( $payment_id, $amount ) {
			$payment_id = (int) $payment_id;
			if ( ! isset( self::$records[ $payment_id ] ) ) {
				return false;
			}

			self::$records[ $payment_id ]['available_amount'] = round( max( 0, (float) $amount ), 2 );

			return true;
		}
	}

	class DocumentsRepository {
		public static $records = array();

		public function exists() {
			return true;
		}

		public function find( $document_id ) {
			return self::$records[ (int) $document_id ] ?? array();
		}

		public function set_payment_progress( $document_id, $paid_total, $balance, $payment_status ) {
			$document_id = (int) $document_id;
			if ( ! isset( self::$records[ $document_id ] ) ) {
				return false;
			}

			self::$records[ $document_id ]['paid_total']      = round( (float) $paid_total, 6 );
			self::$records[ $document_id ]['balance']         = round( max( 0, (float) $balance ), 2 );
			self::$records[ $document_id ]['payment_status']  = sanitize_key( $payment_status );

			return true;
		}
	}

	class PaymentAllocationsRepository {
		public static $records = array();
		public static $next_id = 1;

		public function exists() {
			return true;
		}

		public function create_record( $payment_id, $document_id, $amount, $notes ) {
			$allocation_id = self::$next_id++;
			self::$records[ $allocation_id ] = array(
				'id'          => $allocation_id,
				'payment_id'  => (int) $payment_id,
				'document_id' => (int) $document_id,
				'amount'      => round( (float) $amount, 6 ),
				'notes'       => (string) $notes,
			);

			return $allocation_id;
		}
	}
}

namespace ASDLabs\Finance\Integrations\Woo {
	class OrderSyncService {
		public function sync_order( $order_id, array $args = array() ) {
			return array();
		}

		public function get_linked_document_context( $order_id ) {
			return array();
		}

		public static function invalidate_cached_views() {
			return null;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Finance/PaymentAllocationService.php';
	require_once dirname( __DIR__ ) . '/src/Integrations/Approvals/ApprovalBridge.php';
	require_once dirname( __DIR__ ) . '/src/Finance/OrderSettlementBatchService.php';

	use ASDLabs\Finance\Finance\DocumentsRepository;
	use ASDLabs\Finance\Finance\OrderSettlementBatchService;
	use ASDLabs\Finance\Finance\PaymentAllocationService;
	use ASDLabs\Finance\Finance\PaymentAllocationsRepository;
	use ASDLabs\Finance\Finance\PaymentsRepository;
	use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

	function reset_batch_dual_regression_state() {
		PaymentsRepository::$records           = array();
		DocumentsRepository::$records          = array();
		PaymentAllocationsRepository::$records = array();
		PaymentAllocationsRepository::$next_id = 1;
	}

	function build_batch_service() {
		$reflection = new \ReflectionClass( OrderSettlementBatchService::class );
		$service    = $reflection->newInstanceWithoutConstructor();

		$properties = array(
			'payments'      => new PaymentsRepository(),
			'documents'     => new DocumentsRepository(),
			'allocations'   => new PaymentAllocationService(),
			'order_service' => new OrderSyncService(),
		);

		foreach ( $properties as $name => $value ) {
			$property = $reflection->getProperty( $name );
			$property->setValue( $service, $value );
		}

		return $service;
	}

	function invoke_process_item( OrderSettlementBatchService $service, array $batch, array $item ) {
		$method = new \ReflectionMethod( OrderSettlementBatchService::class, 'process_item' );

		return $method->invoke( $service, $batch, $item );
	}

	function assert_true( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function assert_float_equals( $expected, $actual, $message, $tolerance = 0.0001 ) {
		if ( abs( (float) $expected - (float) $actual ) > $tolerance ) {
			throw new \RuntimeException(
				$message . ' | expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true )
			);
		}
	}

	function base_batch() {
		return array(
			'id'       => 49,
			'currency' => 'USD',
			'meta'     => array(
				'main_payment_id'     => 82,
				'discount_payment_id' => 83,
				'context'             => array(
					'currency'         => 'USD',
					'method_key'       => 'binance',
					'discount_percent' => 31.03,
				),
			),
		);
	}

	function base_item( array $overrides = array() ) {
		return array_replace_recursive(
			array(
				'external_order_id'    => 209668,
				'source_kind'          => 'historical_index',
				'document_id'          => 128,
				'order_number'         => '880563592',
				'balance_before'       => 16.44,
				'customer_paid_amount' => 11.338668,
				'discount_amount'      => 5.101332,
				'meta'                 => array(
					'discount_detection' => array(
						'status' => 'none',
					),
				),
			),
			$overrides
		);
	}

	reset_batch_dual_regression_state();
	PaymentsRepository::$records = array(
		82 => array(
			'id'               => 82,
			'status'           => 'posted',
			'available_amount' => 52.13,
			'currency'         => 'USD',
			'contact_id'       => 61,
			'payment_type'     => 'collection',
			'method_key'       => 'binance',
		),
		83 => array(
			'id'               => 83,
			'status'           => 'posted',
			'available_amount' => 23.45,
			'currency'         => 'USD',
			'contact_id'       => 61,
			'payment_type'     => 'adjustment',
			'method_key'       => 'dual_price_discount',
		),
	);
	DocumentsRepository::$records = array(
		128 => array(
			'id'                 => 128,
			'currency'           => 'USD',
			'contact_id'         => 61,
			'total'              => 16.44,
			'paid_total'         => 0.0,
			'balance'            => 16.44,
			'financial_status'   => 'posted',
			'payment_status'     => 'pending',
			'balance_nature'     => 'receivable',
			'document_type'      => 'woo_sale',
			'source_type'        => 'openpos',
			'external_reference' => 'shop_order:209668',
			'due_date'           => '2026-04-07',
		),
		129 => array(
			'id'                 => 129,
			'currency'           => 'USD',
			'contact_id'         => 61,
			'total'              => 59.14,
			'paid_total'         => 0.0,
			'balance'            => 59.14,
			'financial_status'   => 'posted',
			'payment_status'     => 'pending',
			'balance_nature'     => 'receivable',
			'document_type'      => 'woo_sale',
			'source_type'        => 'openpos',
			'external_reference' => 'shop_order:210862',
			'due_date'           => '2026-04-07',
		),
	);

	$service = build_batch_service();
	$batch   = base_batch();

	$first_result = invoke_process_item( $service, $batch, base_item() );
	assert_true( 'applied' === $first_result['status'], 'El primer item dual del caso batch 49 debe aplicarse.' );
	assert_true( 'paid' === $first_result['final_status'], 'El primer documento debe terminar pagado.' );
	assert_float_equals( 0.0, (float) DocumentsRepository::$records[128]['balance'], 'El primer documento debe quedar sin saldo.' );
	assert_float_equals( 40.79, (float) PaymentsRepository::$records[82]['available_amount'], 'El pago principal debe conservar el resto correcto tras el primer item.' );
	assert_float_equals( 18.35, (float) PaymentsRepository::$records[83]['available_amount'], 'El ajuste dual debe conservar el resto correcto tras el primer item.' );

	$second_result = invoke_process_item(
		$service,
		$batch,
		base_item(
			array(
				'external_order_id'    => 210862,
				'document_id'          => 129,
				'order_number'         => '880563674',
				'balance_before'       => 59.14,
				'customer_paid_amount' => 40.788858,
				'discount_amount'      => 18.351142,
			)
		)
	);
	assert_true( 'applied' === $second_result['status'], 'El segundo item dual debe aplicarse sin dejar saldo falso a favor.' );
	assert_true( 'paid' === $second_result['final_status'], 'El segundo documento debe terminar pagado.' );
	assert_float_equals( 0.0, (float) DocumentsRepository::$records[129]['balance'], 'El segundo documento debe quedar sin saldo.' );
	assert_float_equals( 0.0, (float) PaymentsRepository::$records[82]['available_amount'], 'El pago principal no debe quedar disponible tras cubrir ambos documentos.' );
	assert_float_equals( 0.0, (float) PaymentsRepository::$records[83]['available_amount'], 'El ajuste dual no debe quedar disponible tras cubrir ambos documentos.' );

	reset_batch_dual_regression_state();
	PaymentsRepository::$records = array(
		82 => array(
			'id'               => 82,
			'status'           => 'posted',
			'available_amount' => 11.33,
			'currency'         => 'USD',
			'contact_id'       => 61,
			'payment_type'     => 'collection',
			'method_key'       => 'binance',
		),
		83 => array(
			'id'               => 83,
			'status'           => 'posted',
			'available_amount' => 5.20,
			'currency'         => 'USD',
			'contact_id'       => 61,
			'payment_type'     => 'adjustment',
			'method_key'       => 'dual_price_discount',
		),
	);
	DocumentsRepository::$records = array(
		128 => array(
			'id'                 => 128,
			'currency'           => 'USD',
			'contact_id'         => 61,
			'total'              => 16.40,
			'paid_total'         => 0.0,
			'balance'            => 16.40,
			'financial_status'   => 'posted',
			'payment_status'     => 'pending',
			'balance_nature'     => 'receivable',
			'document_type'      => 'woo_sale',
			'source_type'        => 'openpos',
			'external_reference' => 'shop_order:209668',
			'due_date'           => '2026-04-07',
		),
	);

	$error_service = build_batch_service();
	$error_result  = invoke_process_item(
		$error_service,
		base_batch(),
		base_item(
			array(
				'balance_before'       => 16.40,
				'customer_paid_amount' => 11.33,
				'discount_amount'      => 5.10,
			)
		)
	);
	assert_true( 'error' === $error_result['status'], 'Una deriva material en el tramo dual debe seguir fallando.' );
	assert_true(
		false !== strpos( (string) $error_result['error_message'], 'descuento dual' ),
		'El error dual material debe informar que el saldo cambio durante el segundo tramo.'
	);
	assert_true( 0 === count( PaymentAllocationsRepository::$records ), 'La prevalidacion dual no debe crear allocations parciales cuando el tramo no cuadra.' );
	assert_float_equals( 16.40, (float) DocumentsRepository::$records[128]['balance'], 'La prevalidacion dual debe dejar intacto el documento fallido.' );
	assert_float_equals( 11.33, (float) PaymentsRepository::$records[82]['available_amount'], 'La prevalidacion dual debe dejar intacto el pago principal fallido.' );
	assert_float_equals( 5.20, (float) PaymentsRepository::$records[83]['available_amount'], 'La prevalidacion dual debe dejar intacto el ajuste tecnico fallido.' );

	echo "Order settlement batch dual regression checks passed.\n";
}
