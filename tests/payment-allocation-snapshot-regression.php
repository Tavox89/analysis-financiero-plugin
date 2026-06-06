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

		public function set_available_amount( $payment_id, $available_amount ) {
			$payment_id = (int) $payment_id;
			if ( ! isset( self::$records[ $payment_id ] ) ) {
				return false;
			}

			self::$records[ $payment_id ]['available_amount'] = round( max( 0, (float) $available_amount ), 2 );

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

			self::$records[ $document_id ]['paid_total']     = round( (float) $paid_total, 6 );
			self::$records[ $document_id ]['balance']        = round( max( 0, (float) $balance ), 2 );
			self::$records[ $document_id ]['payment_status'] = sanitize_key( $payment_status );

			return true;
		}
	}

	class PaymentAllocationsRepository {
		public static $records = array();
		public static $next_id = 1;

		public function exists() {
			return true;
		}

		public function create_record( $payment_id, $document_id, $amount, $notes = '' ) {
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

		public function update_snapshot_fields( $allocation_id, array $snapshot ) {
			$allocation_id = (int) $allocation_id;
			if ( ! isset( self::$records[ $allocation_id ] ) ) {
				return false;
			}

			self::$records[ $allocation_id ] = array_merge( self::$records[ $allocation_id ], $snapshot );

			return true;
		}
	}
}

namespace ASDLabs\Finance\Integrations\Woo {
	class OrderSyncService {
		public static function invalidate_cached_views() {
			return null;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Finance/PaymentAllocationService.php';

	use ASDLabs\Finance\Finance\DocumentsRepository;
	use ASDLabs\Finance\Finance\PaymentAllocationService;
	use ASDLabs\Finance\Finance\PaymentAllocationsRepository;
	use ASDLabs\Finance\Finance\PaymentsRepository;

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

	function reset_payment_allocation_snapshot_state() {
		PaymentsRepository::$records           = array();
		DocumentsRepository::$records          = array();
		PaymentAllocationsRepository::$records = array();
		PaymentAllocationsRepository::$next_id = 1;
	}

	reset_payment_allocation_snapshot_state();

	PaymentsRepository::$records = array(
		10 => array(
			'id'               => 10,
			'status'           => 'posted',
			'available_amount' => 20.00,
			'currency'         => 'USD',
			'contact_id'       => 24,
			'payment_type'     => 'collection',
			'method_key'       => 'transfer',
		),
		11 => array(
			'id'               => 11,
			'status'           => 'posted',
			'available_amount' => 0.00,
			'currency'         => 'USD',
			'contact_id'       => 24,
			'payment_type'     => 'adjustment',
			'method_key'       => 'salary_advance',
		),
	);

	DocumentsRepository::$records = array(
		200 => array(
			'id'              => 200,
			'contact_id'      => 24,
			'currency'        => 'USD',
			'total'           => 27.00,
			'paid_total'      => 0.00,
			'balance'         => 27.00,
			'payment_status'  => 'pending',
			'balance_nature'  => 'receivable',
			'financial_status'=> 'posted',
			'due_date'        => '2026-04-30',
			'document_type'   => 'manual_invoice',
			'source_type'     => 'manual',
		),
		201 => array(
			'id'              => 201,
			'contact_id'      => 24,
			'currency'        => 'USD',
			'total'           => 10.00,
			'paid_total'      => 0.00,
			'balance'         => 10.00,
			'payment_status'  => 'pending',
			'balance_nature'  => 'receivable',
			'financial_status'=> 'posted',
			'due_date'        => '2026-04-30',
			'document_type'   => 'manual_invoice',
			'source_type'     => 'manual',
		),
	);

	$service = new PaymentAllocationService();

	$result = $service->allocate(
		array(
			'payment_id'  => 10,
			'document_id' => 200,
			'amount'      => 18.62,
			'notes'       => 'Abono manual de prueba.',
		)
	);

	if ( is_wp_error( $result ) ) {
		throw new \RuntimeException( 'La asignacion principal no debio fallar: ' . $result->get_error_message() );
	}

	assert_float_equals( 27.00, $result['document_balance_before'], 'Debe devolver el saldo previo del documento.' );
	assert_float_equals( 8.38, $result['document_balance_after'], 'Debe devolver el saldo posterior del documento.' );
	assert_float_equals( 20.00, $result['payment_available_before'], 'Debe devolver el disponible previo del pago.' );
	assert_float_equals( 1.38, $result['payment_available_after'], 'Debe devolver el disponible posterior del pago.' );
	assert_same( 'partial', $result['document_payment_status_after'], 'El estado posterior del documento debe quedar parcial.' );

	$allocation_row = PaymentAllocationsRepository::$records[ (int) $result['allocation_id'] ] ?? array();
	assert_float_equals( 27.00, $allocation_row['document_balance_before'] ?? null, 'La asignacion debe congelar el saldo antes.' );
	assert_float_equals( 8.38, $allocation_row['document_balance_after'] ?? null, 'La asignacion debe congelar el saldo despues.' );
	assert_float_equals( 20.00, $allocation_row['payment_available_before'] ?? null, 'La asignacion debe congelar el disponible previo.' );
	assert_float_equals( 1.38, $allocation_row['payment_available_after'] ?? null, 'La asignacion debe congelar el disponible posterior.' );

	$salary_result = $service->allocate(
		array(
			'payment_id'                 => 11,
			'document_id'                => 201,
			'amount'                     => 10.00,
			'notes'                      => 'Compensacion con saldo a favor.',
			'allow_non_available_payment'=> true,
		)
	);

	if ( is_wp_error( $salary_result ) ) {
		throw new \RuntimeException( 'La asignacion salary_advance no debio fallar: ' . $salary_result->get_error_message() );
	}

	assert_float_equals( 0.00, $salary_result['payment_available_before'], 'salary_advance debe conservar snapshot previo en cero.' );
	assert_float_equals( 0.00, $salary_result['payment_available_after'], 'salary_advance debe conservar snapshot posterior en cero.' );
	assert_float_equals( 10.00, $salary_result['document_balance_before'], 'Tambien debe congelar el saldo previo del documento con salary_advance.' );
	assert_float_equals( 0.00, $salary_result['document_balance_after'], 'Tambien debe congelar el saldo posterior del documento con salary_advance.' );

	echo "payment-allocation-snapshot-regression: OK\n";
}
