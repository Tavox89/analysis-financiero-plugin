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

	function current_time( $type = 'mysql' ) {
		return 'mysql' === $type ? '2026-04-21 12:00:00' : '2026-04-21';
	}

	function do_action( $hook, ...$args ) {
		return null;
	}

	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
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

		private function format_money( $amount, $currency = '' ) {
			return sprintf( '%s %.2f', $currency ?: 'USD', (float) $amount );
		}
	}

	class PaymentsRepository {
		public static $records = array();
		public static $created = array();
		public static $next_id = 1000;

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

		public function create( array $data ) {
			$payment_id = self::$next_id++;
			self::$records[ $payment_id ] = array(
				'id'               => $payment_id,
				'status'           => sanitize_key( (string) ( $data['status'] ?? 'posted' ) ),
				'available_amount' => round( max( 0, (float) ( $data['total'] ?? 0 ) ), 2 ),
				'currency'         => sanitize_text_field( (string) ( $data['currency'] ?? 'USD' ) ),
				'contact_id'       => (int) ( $data['contact_id'] ?? 0 ),
				'payment_type'     => sanitize_key( (string) ( $data['payment_type'] ?? 'collection' ) ),
				'method_key'       => sanitize_key( (string) ( $data['method_key'] ?? '' ) ),
				'reference'        => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
				'notes'            => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			);
			self::$created[] = self::$records[ $payment_id ];

			return $payment_id;
		}
	}

	class DocumentsRepository {
		public static $records = array();
		public static $next_id = 1000;

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

			self::$records[ $document_id ]['paid_total']     = round( (float) $paid_total, 6 );
			self::$records[ $document_id ]['balance']        = round( max( 0, (float) $balance ), 2 );
			self::$records[ $document_id ]['payment_status'] = sanitize_key( $payment_status );

			return true;
		}

		public function create( array $data ) {
			$document_id = self::$next_id++;
			self::$records[ $document_id ] = array(
				'id'                 => $document_id,
				'contact_id'         => (int) ( $data['contact_id'] ?? 0 ),
				'parent_document_id' => (int) ( $data['parent_document_id'] ?? 0 ),
				'document_type'      => sanitize_key( (string) ( $data['document_type'] ?? 'adjustment' ) ),
				'source_type'        => sanitize_key( (string) ( $data['source_type'] ?? 'manual' ) ),
				'title'              => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'external_reference' => sanitize_text_field( (string) ( $data['external_reference'] ?? '' ) ),
				'issue_date'         => sanitize_text_field( (string) ( $data['issue_date'] ?? '' ) ),
				'currency'           => sanitize_text_field( (string) ( $data['currency'] ?? 'USD' ) ),
				'total'              => round( (float) ( $data['total'] ?? 0 ), 2 ),
				'paid_total'         => round( (float) ( $data['paid_total'] ?? 0 ), 2 ),
				'balance'            => round( max( 0, (float) ( $data['total'] ?? 0 ) - (float) ( $data['paid_total'] ?? 0 ) ), 2 ),
				'financial_status'   => sanitize_key( (string) ( $data['financial_status'] ?? 'posted' ) ),
				'payment_status'     => sanitize_key( (string) ( $data['payment_status'] ?? 'paid' ) ),
				'balance_nature'     => 'neutral',
				'notes'              => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			);

			return $document_id;
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

	function reset_extraordinary_batch_state() {
		PaymentsRepository::$records           = array();
		PaymentsRepository::$created           = array();
		PaymentsRepository::$next_id           = 1000;
		DocumentsRepository::$records          = array();
		DocumentsRepository::$next_id          = 1000;
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

	function invoke_create_batch_payments( OrderSettlementBatchService $service, $batch_id, array $preview ) {
		$method = new \ReflectionMethod( OrderSettlementBatchService::class, 'create_batch_payments' );

		return $method->invoke( $service, $batch_id, $preview );
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

	reset_extraordinary_batch_state();
	PaymentsRepository::$records = array(
		901 => array(
			'id'               => 901,
			'status'           => 'posted',
			'available_amount' => 18.62,
			'currency'         => 'USD',
			'contact_id'       => 465,
			'payment_type'     => 'collection',
			'method_key'       => 'mobile_payment',
		),
		902 => array(
			'id'               => 902,
			'status'           => 'posted',
			'available_amount' => 8.38,
			'currency'         => 'USD',
			'contact_id'       => 465,
			'payment_type'     => 'adjustment',
			'method_key'       => 'extraordinary_profile_closure',
		),
	);
	DocumentsRepository::$records = array(
		220 => array(
			'id'                 => 220,
			'currency'           => 'USD',
			'contact_id'         => 465,
			'total'              => 27.00,
			'paid_total'         => 0.0,
			'balance'            => 27.00,
			'financial_status'   => 'posted',
			'payment_status'     => 'pending',
			'balance_nature'     => 'receivable',
			'document_type'      => 'woo_sale',
			'source_type'        => 'openpos',
			'external_reference' => 'shop_order:214097',
			'due_date'           => '2026-04-21',
		),
	);

	$service = build_batch_service();
	$batch   = array(
		'id'         => 77,
		'contact_id' => 465,
		'currency'   => 'USD',
		'meta'       => array(
			'main_payment_id'          => 901,
			'extraordinary_payment_id' => 902,
			'context'                  => array(
				'currency'                             => 'USD',
				'payment_date'                         => '2026-04-21',
				'method_key'                           => 'mobile_payment',
				'extraordinary_closure_reason_label'   => 'Cierre administrativo',
				'extraordinary_closure_approval_reference' => 'APR-7781',
				'extraordinary_closure_note'           => 'Se cierra la diferencia aprobada del pedido especifico.',
			),
		),
	);
	$item    = array(
		'external_order_id'    => 214097,
		'source_kind'          => 'historical_index',
		'document_id'          => 220,
		'order_number'         => '880564130',
		'balance_before'       => 27.00,
		'customer_paid_amount' => 18.62,
		'discount_amount'      => 0.0,
		'meta'                 => array(
			'discount_detection' => array(
				'status' => 'none',
			),
			'extraordinary_closure_amount' => 8.38,
		),
	);

	$result = invoke_process_item( $service, $batch, $item );
	assert_true( 'applied' === $result['status'], 'El item con cierre extraordinario debe aplicarse correctamente.' );
	assert_true( 'paid' === $result['final_status'], 'El pedido debe terminar pagado tras el cierre extraordinario.' );
	assert_float_equals( 0.0, (float) DocumentsRepository::$records[220]['balance'], 'El documento original debe quedar en cero.' );
	assert_float_equals( 0.0, (float) PaymentsRepository::$records[901]['available_amount'], 'El pago real debe agotarse por completo.' );
	assert_float_equals( 0.0, (float) PaymentsRepository::$records[902]['available_amount'], 'El ajuste extraordinario debe agotarse por completo.' );
	assert_float_equals( 8.38, (float) $result['extraordinary_closure_amount'], 'El resultado debe exponer el monto extraordinario aplicado.' );
	assert_true( ! empty( $result['extraordinary_document_id'] ), 'La ejecucion debe generar un movimiento manual visible para la diferencia.' );
	assert_true( isset( DocumentsRepository::$records[ (int) $result['extraordinary_document_id'] ] ), 'El movimiento manual debe existir en el repositorio stub.' );
	assert_float_equals( 8.38, (float) DocumentsRepository::$records[ (int) $result['extraordinary_document_id'] ]['total'], 'El movimiento manual debe guardar exactamente la diferencia cerrada.' );
	assert_true(
		false !== strpos( (string) DocumentsRepository::$records[ (int) $result['extraordinary_document_id'] ]['title'], 'Cierre extraordinario' ),
		'El movimiento manual debe quedar claramente identificado.'
	);

	reset_extraordinary_batch_state();
	$service = build_batch_service();
	$payment_ids = invoke_create_batch_payments(
		$service,
		88,
		array(
			'contact_id' => 465,
			'currency'   => 'USD',
			'summary'    => array(
				'payment_recorded_total'      => 0.0,
				'requested_total'             => 0.0,
				'extraordinary_closure_total' => 27.00,
			),
			'batch_payload' => array(
				'payment_type'                         => 'collection',
				'account_id'                           => 0,
				'payment_date'                         => '2026-04-21',
				'method_key'                           => '',
				'extraordinary_closure_enabled'        => 1,
				'extraordinary_closure_reason_label'   => 'Cierre administrativo',
				'extraordinary_closure_approval_reference' => 'APR-9901',
				'extraordinary_closure_note'           => 'Cierre total del pedido sin registrar un pago real nuevo.',
			),
		)
	);
	assert_true( ! is_wp_error( $payment_ids ), 'La creacion de pagos del lote debe aceptar cierres extraordinarios sin pago principal.' );
	assert_same( 0, (int) $payment_ids['main_payment_id'], 'Sin abono real el lote no debe crear pago principal.' );
	assert_true( (int) $payment_ids['extraordinary_payment_id'] > 0, 'Sin abono real el lote debe crear el ajuste extraordinario.' );
	assert_same( 1, count( PaymentsRepository::$created ), 'Sin abono real solo debe generarse un payment tecnico: el cierre extraordinario.' );
	assert_same( 'extraordinary_profile_closure', PaymentsRepository::$created[0]['method_key'], 'El payment tecnico debe usar el metodo de cierre extraordinario.' );

	reset_extraordinary_batch_state();
	PaymentsRepository::$records = array(
		902 => array(
			'id'               => 902,
			'status'           => 'posted',
			'available_amount' => 27.00,
			'currency'         => 'USD',
			'contact_id'       => 465,
			'payment_type'     => 'adjustment',
			'method_key'       => 'extraordinary_profile_closure',
		),
	);
	DocumentsRepository::$records = array(
		220 => array(
			'id'                 => 220,
			'currency'           => 'USD',
			'contact_id'         => 465,
			'total'              => 27.00,
			'paid_total'         => 0.0,
			'balance'            => 27.00,
			'financial_status'   => 'posted',
			'payment_status'     => 'pending',
			'balance_nature'     => 'receivable',
			'document_type'      => 'woo_sale',
			'source_type'        => 'openpos',
			'external_reference' => 'shop_order:214097',
			'due_date'           => '2026-04-21',
		),
	);
	$service = build_batch_service();
	$result  = invoke_process_item(
		$service,
		array(
			'id'         => 78,
			'contact_id' => 465,
			'currency'   => 'USD',
			'meta'       => array(
				'main_payment_id'          => 0,
				'extraordinary_payment_id' => 902,
				'context'                  => array(
					'currency'                           => 'USD',
					'payment_date'                       => '2026-04-21',
					'method_key'                         => '',
					'extraordinary_closure_reason_label' => 'Cierre administrativo',
				),
			),
		),
		array(
			'external_order_id'    => 214097,
			'source_kind'          => 'historical_index',
			'document_id'          => 220,
			'order_number'         => '880564130',
			'balance_before'       => 27.00,
			'customer_paid_amount' => 0.0,
			'discount_amount'      => 0.0,
			'meta'                 => array(
				'discount_detection' => array(
					'status' => 'none',
				),
				'extraordinary_closure_amount' => 27.00,
			),
		)
	);
	assert_true( 'applied' === $result['status'], 'El proceso del lote debe cerrar el pedido aunque no exista pago principal.' );
	assert_float_equals( 0.0, (float) DocumentsRepository::$records[220]['balance'], 'Sin pago principal el documento igual debe quedar en cero.' );
	assert_float_equals( 0.0, (float) PaymentsRepository::$records[902]['available_amount'], 'Sin pago principal el ajuste extraordinario debe agotarse por completo.' );

	echo "Order settlement extraordinary batch regression checks passed.\n";
}
