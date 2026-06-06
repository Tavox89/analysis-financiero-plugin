<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	$GLOBALS['ASDL_TEST_RECOVERY_PLANS'] = array();
	$GLOBALS['ASDL_TEST_RECOVERY_INSTALLMENTS'] = array();
	$GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'] = array();
	$GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'] = array();
	$GLOBALS['ASDL_TEST_RECOVERY_ALLOCATIONS'] = array();
	$GLOBALS['ASDL_TEST_RECOVERY_EVENTS'] = array();
	$GLOBALS['ASDL_TEST_RECOVERY_ORDER_PREVIEWS'] = array();
	$GLOBALS['ASDL_TEST_RECOVERY_ORDER_APPLIES'] = array();
	$GLOBALS['ASDL_TEST_RECOVERY_NEXT_PAYMENT_ID'] = 500;
	$GLOBALS['ASDL_TEST_RECOVERY_TX_STACK'] = array();

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	if ( ! class_exists( 'WP_Error', false ) ) {
		class WP_Error {
			private $code;
			private $message;

			public function __construct( $code = '', $message = '' ) {
				$this->code = (string) $code;
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

	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	function sanitize_textarea_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function current_time( $type = 'mysql' ) {
		return 'mysql' === $type ? '2026-04-22 12:00:00' : '2026-04-22';
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

		public function get_results( $prepared, $output = ARRAY_A ) {
			unset( $output );
			$query = is_array( $prepared ) ? (string) ( $prepared['query'] ?? '' ) : (string) $prepared;
			$args  = is_array( $prepared ) ? (array) ( $prepared['args'] ?? array() ) : array();

			if ( false !== strpos( $query, 'FROM asdl_test_documents' ) ) {
				$contact_id = isset( $args[0] ) ? (int) $args[0] : 0;
				$rows = array();
				foreach ( $GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'] as $document ) {
					if ( (int) ( $document['contact_id'] ?? 0 ) !== $contact_id ) {
						continue;
					}
					if ( 'receivable' !== (string) ( $document['balance_nature'] ?? '' ) ) {
						continue;
					}
					if ( (float) ( $document['balance'] ?? 0 ) <= 0 ) {
						continue;
					}
					if ( 'void' === (string) ( $document['financial_status'] ?? '' ) ) {
						continue;
					}
					$rows[] = array(
						'id'      => (int) $document['id'],
						'balance' => (float) $document['balance'],
					);
				}
				return $rows;
			}

			return array();
		}

		public function get_var( $prepared ) {
			$query = is_array( $prepared ) ? (string) ( $prepared['query'] ?? '' ) : (string) $prepared;
			$args  = is_array( $prepared ) ? (array) ( $prepared['args'] ?? array() ) : array();

			if ( false !== strpos( $query, 'SUM(balance)' ) && false !== strpos( $query, 'asdl_test_documents' ) ) {
				$contact_id = isset( $args[0] ) ? (int) $args[0] : 0;
				$total = 0.0;
				foreach ( $GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'] as $document ) {
					if ( (int) ( $document['contact_id'] ?? 0 ) !== $contact_id ) {
						continue;
					}
					if ( 'receivable' !== (string) ( $document['balance_nature'] ?? '' ) ) {
						continue;
					}
					if ( (float) ( $document['balance'] ?? 0 ) <= 0 ) {
						continue;
					}
					if ( 'void' === (string) ( $document['financial_status'] ?? '' ) ) {
						continue;
					}

					$is_order = 'woo_sale' === (string) ( $document['document_type'] ?? '' )
						|| in_array( (string) ( $document['source_type'] ?? '' ), array( 'woocommerce', 'openpos' ), true )
						|| 0 === strpos( (string) ( $document['external_reference'] ?? '' ), 'shop_order:' );

					if ( $is_order ) {
						$total += (float) $document['balance'];
					}
				}
				return $total;
			}

			return 0;
		}

		public function query( $sql ) {
			$sql = trim( strtoupper( (string) $sql ) );

			if ( 'START TRANSACTION' === $sql ) {
				$GLOBALS['ASDL_TEST_RECOVERY_TX_STACK'][] = array(
					'plans'        => $GLOBALS['ASDL_TEST_RECOVERY_PLANS'],
					'installments' => $GLOBALS['ASDL_TEST_RECOVERY_INSTALLMENTS'],
					'payments'     => $GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'],
					'documents'    => $GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'],
					'allocations'  => $GLOBALS['ASDL_TEST_RECOVERY_ALLOCATIONS'],
				);
				return true;
			}

			if ( 'COMMIT' === $sql ) {
				array_pop( $GLOBALS['ASDL_TEST_RECOVERY_TX_STACK'] );
				return true;
			}

			if ( 'ROLLBACK' === $sql ) {
				$snapshot = array_pop( $GLOBALS['ASDL_TEST_RECOVERY_TX_STACK'] );
				if ( is_array( $snapshot ) ) {
					$GLOBALS['ASDL_TEST_RECOVERY_PLANS']        = $snapshot['plans'];
					$GLOBALS['ASDL_TEST_RECOVERY_INSTALLMENTS'] = $snapshot['installments'];
					$GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS']     = $snapshot['payments'];
					$GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS']    = $snapshot['documents'];
					$GLOBALS['ASDL_TEST_RECOVERY_ALLOCATIONS']  = $snapshot['allocations'];
				}
				return true;
			}

			return true;
		}
	}

	$GLOBALS['wpdb'] = new FakeDb();

	function assert_true( $condition, string $message ) : void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function assert_same( $expected, $actual, string $message ) : void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
			);
		}
	}

	function assert_float_same( float $expected, $actual, string $message ) : void {
		$actual = (float) $actual;
		if ( abs( $expected - $actual ) > 0.00001 ) {
			throw new \RuntimeException(
				$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
			);
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

namespace ASDLabs\Finance\Finance {
	class BaseRepository {
		protected $table_key = '';

		protected function db() {
			global $wpdb;
			return $wpdb;
		}

		protected function table() {
			return \ASDLabs\Finance\Core\Tables::name( $this->table_key );
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

		protected function normalize_balance_amount( $amount, $currency = '' ) {
			unset( $currency );
			return round( (float) $amount, 6 );
		}

		protected function money_balance_is_zero( $amount, $currency = '' ) {
			unset( $currency );
			return abs( (float) $amount ) <= 0.00001;
		}

		protected function begin_transaction() {
			return $this->db()->query( 'START TRANSACTION' );
		}

		protected function commit_transaction() {
			return $this->db()->query( 'COMMIT' );
		}

		protected function rollback_transaction() {
			return $this->db()->query( 'ROLLBACK' );
		}
	}

	class InstallmentPlansRepository {
		public function exists() {
			return true;
		}

		public function find( $plan_id ) {
			return $GLOBALS['ASDL_TEST_RECOVERY_PLANS'][ (int) $plan_id ] ?? null;
		}

		public function for_contact( $contact_id, $limit = 50 ) {
			unset( $limit );
			return array_values(
				array_filter(
					$GLOBALS['ASDL_TEST_RECOVERY_PLANS'],
					static function ( array $plan ) use ( $contact_id ) {
						return (int) ( $plan['contact_id'] ?? 0 ) === (int) $contact_id;
					}
				)
			);
		}

		public function set_balance_status( $plan_id, $balance, $status, array $meta_updates = array() ) {
			$plan_id = (int) $plan_id;
			if ( empty( $GLOBALS['ASDL_TEST_RECOVERY_PLANS'][ $plan_id ] ) ) {
				return false;
			}
			$plan = $GLOBALS['ASDL_TEST_RECOVERY_PLANS'][ $plan_id ];
			$meta = is_array( $plan['meta'] ?? null ) ? $plan['meta'] : array();
			foreach ( $meta_updates as $key => $value ) {
				$meta[ $key ] = $value;
			}
			$plan['balance'] = (float) $balance;
			$plan['status']  = (string) $status;
			$plan['meta']    = $meta;
			$GLOBALS['ASDL_TEST_RECOVERY_PLANS'][ $plan_id ] = $plan;
			return true;
		}
	}

	class InstallmentsRepository {
		public function exists() {
			return true;
		}

		public function open_for_plan( $plan_id, $limit = 200 ) {
			unset( $limit );
			$rows = array_filter(
				$GLOBALS['ASDL_TEST_RECOVERY_INSTALLMENTS'],
				static function ( array $row ) use ( $plan_id ) {
					return (int) ( $row['plan_id'] ?? 0 ) === (int) $plan_id && (float) ( $row['balance'] ?? 0 ) > 0;
				}
			);
			usort(
				$rows,
				static function ( array $left, array $right ) {
					return (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 );
				}
			);
			return array_values( $rows );
		}

		public function apply_amount( $installment_id, $amount, array $context = array() ) {
			foreach ( $GLOBALS['ASDL_TEST_RECOVERY_INSTALLMENTS'] as $index => $installment ) {
				if ( (int) ( $installment['id'] ?? 0 ) !== (int) $installment_id ) {
					continue;
				}

				$applied = min( (float) $amount, (float) ( $installment['balance'] ?? 0 ) );
				if ( $applied <= 0 ) {
					return new \WP_Error( 'installment_empty', 'La cuota ya no tiene saldo.' );
				}

				$installment['paid_amount'] = round( (float) ( $installment['paid_amount'] ?? 0 ) + $applied, 6 );
				$installment['balance'] = round( max( 0, (float) ( $installment['amount'] ?? 0 ) - (float) $installment['paid_amount'] ), 6 );
				$installment['payment_status'] = $installment['balance'] <= 0 ? 'paid' : 'partial';
				$installment['last_context'] = $context;
				$installment['applied_amount'] = $applied;
				$GLOBALS['ASDL_TEST_RECOVERY_INSTALLMENTS'][ $index ] = $installment;
				return $installment;
			}

			return new \WP_Error( 'installment_missing', 'No se encontró la cuota.' );
		}

		public function summary_for_plan( $plan_id ) {
			$balance = 0.0;
			foreach ( $GLOBALS['ASDL_TEST_RECOVERY_INSTALLMENTS'] as $installment ) {
				if ( (int) ( $installment['plan_id'] ?? 0 ) !== (int) $plan_id ) {
					continue;
				}
				$balance += (float) ( $installment['balance'] ?? 0 );
			}
			return array( 'balance_total' => round( $balance, 6 ) );
		}
	}

	class PaymentsRepository {
		public function create( array $data ) {
			$id = (int) $GLOBALS['ASDL_TEST_RECOVERY_NEXT_PAYMENT_ID']++;
			$GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'][ $id ] = array_merge(
				array(
					'id'               => $id,
					'available_amount' => (float) ( $data['total'] ?? 0 ),
				),
				$data
			);
			return $id;
		}

		public function find( $payment_id ) {
			return $GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'][ (int) $payment_id ] ?? null;
		}

		public function set_available_amount( $payment_id, $available_amount ) {
			if ( empty( $GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'][ (int) $payment_id ] ) ) {
				return false;
			}
			$GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'][ (int) $payment_id ]['available_amount'] = round( (float) $available_amount, 6 );
			return true;
		}
	}

	class DocumentsRepository {
		public function find( $document_id ) {
			return $GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'][ (int) $document_id ] ?? null;
		}
	}

	class PaymentAllocationService {
		public function allocate( array $data ) {
			$payment_id = (int) ( $data['payment_id'] ?? 0 );
			$document_id = (int) ( $data['document_id'] ?? 0 );
			$amount = (float) ( $data['amount'] ?? 0 );

			if ( empty( $GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'][ $payment_id ] ) || empty( $GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'][ $document_id ] ) ) {
				return new \WP_Error( 'allocation_missing', 'No se encontró el respaldo.' );
			}

			$payment = $GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'][ $payment_id ];
			$document = $GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'][ $document_id ];
			if ( $amount <= 0 || $amount > (float) ( $payment['available_amount'] ?? 0 ) + 0.00001 || $amount > (float) ( $document['balance'] ?? 0 ) + 0.00001 ) {
				return new \WP_Error( 'allocation_amount', 'El monto no cabe en el respaldo.' );
			}

			$payment['available_amount'] = round( (float) $payment['available_amount'] - $amount, 6 );
			$document['paid_total'] = round( (float) ( $document['paid_total'] ?? 0 ) + $amount, 6 );
			$document['balance'] = round( max( 0, (float) ( $document['balance'] ?? 0 ) - $amount ), 6 );
			$document['payment_status'] = $document['balance'] <= 0 ? 'paid' : 'partial';
			$GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'][ $payment_id ] = $payment;
			$GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'][ $document_id ] = $document;
			$GLOBALS['ASDL_TEST_RECOVERY_ALLOCATIONS'][] = $data;

			return array(
				'payment_id' => $payment_id,
				'document_id' => $document_id,
				'amount' => $amount,
				'document_status' => $document['payment_status'],
			);
		}
	}

	class OrderSettlementBatchService {
		public function preview( array $args, $origin = 'profile_settlement' ) {
			$GLOBALS['ASDL_TEST_RECOVERY_ORDER_PREVIEWS'][] = array(
				'args' => $args,
				'origin' => $origin,
			);
			return $GLOBALS['ASDL_TEST_RECOVERY_ORDER_PREVIEW_RESPONSE'];
		}

		public function apply_fast_path_preview( array $preview, array $args = array() ) {
			$GLOBALS['ASDL_TEST_RECOVERY_ORDER_APPLIES'][] = array(
				'preview' => $preview,
				'args' => $args,
			);
			return $GLOBALS['ASDL_TEST_RECOVERY_ORDER_APPLY_RESPONSE'];
		}
	}

	class EventsRepository {
		public function log( $entity_type, $entity_id, $event_type, $message, array $payload = array(), $actor_user_id = null ) {
			unset( $actor_user_id );
			$GLOBALS['ASDL_TEST_RECOVERY_EVENTS'][] = array(
				'entity_type' => $entity_type,
				'entity_id'   => $entity_id,
				'event_type'  => $event_type,
				'message'     => $message,
				'payload'     => $payload,
			);
			return count( $GLOBALS['ASDL_TEST_RECOVERY_EVENTS'] );
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Finance/CommitmentExposureService.php';
	require_once dirname( __DIR__ ) . '/src/Finance/CommitmentSettlementService.php';

	use ASDLabs\Finance\Finance\CommitmentSettlementService;

	$service = new CommitmentSettlementService();

	$GLOBALS['ASDL_TEST_RECOVERY_PLANS'] = array(
		1 => array(
			'id' => 1,
			'contact_id' => 24,
			'status' => 'active',
			'balance' => 15.0,
			'currency' => 'USD',
			'title' => 'Recuperacion tienda',
			'commitment_origin' => 'store_debt',
			'settlement_direction' => 'receivable',
			'collection_mode' => 'payroll_deduction',
			'meta' => array(
				'exposure_kind' => 'recovery_existing_debt',
				'backing_source_type' => 'orders',
				'backing_document_id' => 0,
				'backing_debt_scope' => 'contact_open_orders',
			),
		),
		2 => array(
			'id' => 2,
			'contact_id' => 24,
			'status' => 'active',
			'balance' => 10.0,
			'currency' => 'USD',
			'title' => 'Recuperacion documento',
			'commitment_origin' => 'manual_charge',
			'document_id' => 901,
			'settlement_direction' => 'receivable',
			'collection_mode' => 'manual',
			'meta' => array(
				'exposure_kind' => 'recovery_existing_debt',
				'backing_source_type' => 'document',
				'backing_document_id' => 901,
				'backing_debt_scope' => 'single_document',
			),
		),
		3 => array(
			'id' => 3,
			'contact_id' => 24,
			'status' => 'active',
			'balance' => 12.0,
			'currency' => 'USD',
			'title' => 'Prestamo directo',
			'commitment_origin' => 'loan',
			'settlement_direction' => 'receivable',
			'collection_mode' => 'manual',
			'meta' => array(
				'exposure_kind' => 'standalone_obligation',
				'backing_source_type' => 'none',
				'backing_document_id' => 0,
				'backing_debt_scope' => 'standalone',
			),
		),
		4 => array(
			'id' => 4,
			'contact_id' => 24,
			'status' => 'active',
			'balance' => 9.0,
			'currency' => 'USD',
			'title' => 'Recovery huérfano',
			'commitment_origin' => 'manual_charge',
			'document_id' => 999,
			'settlement_direction' => 'receivable',
			'collection_mode' => 'payroll_deduction',
			'meta' => array(
				'exposure_kind' => 'recovery_existing_debt',
				'backing_source_type' => 'document',
				'backing_document_id' => 999,
				'backing_debt_scope' => 'single_document',
			),
		),
	);

	$GLOBALS['ASDL_TEST_RECOVERY_INSTALLMENTS'] = array(
		array( 'id' => 101, 'plan_id' => 1, 'amount' => 15.0, 'paid_amount' => 0.0, 'balance' => 15.0, 'payment_status' => 'pending', 'title' => 'Cuota tienda' ),
		array( 'id' => 201, 'plan_id' => 2, 'amount' => 10.0, 'paid_amount' => 0.0, 'balance' => 10.0, 'payment_status' => 'pending', 'title' => 'Cuota documento' ),
		array( 'id' => 301, 'plan_id' => 3, 'amount' => 12.0, 'paid_amount' => 0.0, 'balance' => 12.0, 'payment_status' => 'pending', 'title' => 'Cuota prestamo' ),
		array( 'id' => 401, 'plan_id' => 4, 'amount' => 9.0, 'paid_amount' => 0.0, 'balance' => 9.0, 'payment_status' => 'pending', 'title' => 'Cuota huérfana' ),
	);

	$GLOBALS['ASDL_TEST_RECOVERY_DOCUMENTS'] = array(
		901 => array(
			'id' => 901,
			'contact_id' => 24,
			'currency' => 'USD',
			'balance' => 8.0,
			'paid_total' => 0.0,
			'payment_status' => 'pending',
			'financial_status' => 'posted',
			'balance_nature' => 'receivable',
			'document_type' => 'manual_invoice',
			'source_type' => 'manual',
			'external_reference' => '',
		),
		902 => array(
			'id' => 902,
			'contact_id' => 24,
			'currency' => 'USD',
			'balance' => 15.0,
			'paid_total' => 0.0,
			'payment_status' => 'pending',
			'financial_status' => 'posted',
			'balance_nature' => 'receivable',
			'document_type' => 'woo_sale',
			'source_type' => 'woocommerce',
			'external_reference' => 'shop_order:77',
		),
	);

	$GLOBALS['ASDL_TEST_RECOVERY_ORDER_PREVIEW_RESPONSE'] = array(
		'execution_mode' => 'fast_path',
		'uses_dual' => false,
		'currency' => 'USD',
		'contact_id' => 24,
		'origin' => 'recovery_commitment_payment',
		'batch_payload' => array(
			'payment_date' => '2026-04-22',
			'currency' => 'USD',
			'method_key' => 'payroll_deduction',
			'payment_type' => 'adjustment',
		),
		'summary' => array(
			'payment_applied_total' => 15.0,
			'credit_applied_total' => 0.0,
			'discount_applied_total' => 0.0,
			'extraordinary_closure_total' => 0.0,
		),
		'items' => array(
			array(
				'external_order_id' => 77,
				'document_id' => 902,
				'order_number' => '77',
				'source_kind' => 'current_live',
				'balance_before' => 15.0,
				'customer_paid_amount' => 15.0,
				'expected_balance_after' => 0.0,
				'discount_detection' => array( 'status' => 'none', 'label' => 'Sin descuento' ),
			),
		),
	);

	$GLOBALS['ASDL_TEST_RECOVERY_ORDER_APPLY_RESPONSE'] = array(
		'payment_id' => 500,
		'applied_total' => 15.0,
		'covered_total' => 15.0,
		'document_ids' => array( 902 ),
		'order_ids' => array( 77 ),
	);

	$order_result = $service->apply(
		array(
			'plan_id' => 1,
			'amount' => 15.0,
			'create_payment' => true,
			'payment_type' => 'adjustment',
			'payment_date' => '2026-04-22',
			'currency' => 'USD',
			'method_key' => 'payroll_deduction',
			'reference' => 'PAY-ORD',
			'notes' => 'Cobro recovery orders',
			'origin' => 'payroll_deduction',
		)
	);

	assert_true( ! is_wp_error( $order_result ), 'El recovery-backed sobre pedidos debe completarse.' );
	assert_same( 'orders', $order_result['backing_source_type'], 'El cobro debe reconocer el respaldo por pedidos.' );
	assert_float_same( 15.0, $order_result['backing_applied_total'], 'Debe liquidar 15 USD reales sobre la deuda base de pedidos.' );
	assert_float_same( 15.0, $order_result['plan_reflected_total'], 'Debe reflejar exactamente el mismo monto sobre el plan.' );
	assert_same( 1, count( $GLOBALS['ASDL_TEST_RECOVERY_ORDER_PREVIEWS'] ), 'El recovery de pedidos debe construir preview del settlement real.' );
	assert_same( 'off', $GLOBALS['ASDL_TEST_RECOVERY_ORDER_PREVIEWS'][0]['args']['dual_discount_mode'], 'El recovery no debe usar precio dual.' );
	assert_same( false, $GLOBALS['ASDL_TEST_RECOVERY_ORDER_PREVIEWS'][0]['args']['force_dual_discount'], 'El recovery no debe forzar dual.' );

	$document_result = $service->apply(
		array(
			'plan_id' => 2,
			'amount' => 10.0,
			'create_payment' => true,
			'payment_type' => 'adjustment',
			'payment_date' => '2026-04-22',
			'currency' => 'USD',
			'method_key' => 'payroll_deduction',
			'reference' => 'PAY-DOC',
			'notes' => 'Cobro recovery doc',
			'origin' => 'payroll_deduction',
		)
	);

	assert_true( ! is_wp_error( $document_result ), 'El recovery-backed sobre documento debe completarse.' );
	assert_same( 'document', $document_result['backing_source_type'], 'Debe reconocer respaldo documental.' );
	assert_float_same( 8.0, $document_result['backing_applied_total'], 'Solo debe aplicar lo efectivamente abierto en el documento base.' );
	assert_float_same( 8.0, $document_result['plan_reflected_total'], 'La cuota debe bajar exactamente por el monto realmente aplicado al respaldo.' );
	assert_float_same( 2.0, $document_result['unapplied_total'], 'El remanente solicitado debe quedar sin aplicar si el respaldo no alcanza.' );

	$standalone_result = $service->apply(
		array(
			'plan_id' => 3,
			'amount' => 12.0,
			'create_payment' => true,
			'payment_type' => 'collection',
			'payment_date' => '2026-04-22',
			'currency' => 'USD',
			'method_key' => 'cash',
			'reference' => 'PAY-STD',
			'notes' => 'Cobro standalone',
			'origin' => 'manual_commitment',
		)
	);

	assert_true( ! is_wp_error( $standalone_result ), 'El compromiso standalone debe seguir cobrando cuota directa.' );
	assert_same( 'none', $standalone_result['backing_source_type'], 'El standalone no debe tocar pedidos ni documentos.' );
	assert_float_same( 0.0, $standalone_result['backing_applied_total'], 'No debe reportar respaldo aplicado.' );
	assert_float_same( 12.0, $standalone_result['plan_reflected_total'], 'El standalone debe reflejar su cuota completa en el plan.' );

	$orphan_preview = $service->preview_payroll_deductions( 24, '2026-04-22', 20.0 );
	$preview_items = (array) ( $orphan_preview['items'] ?? array() );
	$orphan_items  = array_values(
		array_filter(
			$preview_items,
			static function ( array $item ) : bool {
				return 4 === (int) ( $item['plan_id'] ?? 0 );
			}
		)
	);
	assert_true( ! empty( $orphan_preview['execution_blocked'] ), 'El preview debe marcar bloqueo cuando un recovery perdió respaldo.' );
	assert_true( ! empty( $orphan_items ) && ! empty( $orphan_items[0]['blocked'] ), 'La cuota huérfana debe quedar visible como bloqueada.' );
	assert_same( 'recovery_existing_debt', $orphan_items[0]['exposure_kind'], 'El preview debe exponer el tipo de exposición del compromiso.' );
	assert_same( 'document', $orphan_items[0]['backing_source_type'], 'El preview debe exponer el tipo de respaldo del compromiso.' );
	assert_true( ! empty( $orphan_items[0]['is_recovery_plan'] ), 'El preview debe marcar si el compromiso es un recovery plan.' );
	assert_true(
		false !== strpos( (string) ( $orphan_items[0]['recovery_helper'] ?? '' ), 'liquida primero la deuda base' ),
		'El preview debe explicar que primero se liquida la deuda base y luego se refleja la cuota.'
	);

	$before_orphan_payments = count( $GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'] );
	$orphan_result = $service->apply(
		array(
			'plan_id' => 4,
			'amount' => 9.0,
			'create_payment' => true,
			'payment_type' => 'adjustment',
			'payment_date' => '2026-04-22',
			'currency' => 'USD',
			'method_key' => 'payroll_deduction',
			'reference' => 'PAY-BLOCK',
			'notes' => 'Recovery bloqueado',
			'origin' => 'payroll_deduction',
		)
	);

	assert_true( is_wp_error( $orphan_result ), 'El recovery huérfano debe bloquearse.' );
	assert_same( 'asdl_fin_recovery_backing_missing', $orphan_result->get_error_code(), 'El bloqueo debe venir por falta de deuda base abierta.' );
	assert_same( $before_orphan_payments, count( $GLOBALS['ASDL_TEST_RECOVERY_PAYMENTS'] ), 'El bloqueo no debe dejar pagos nuevos persistidos.' );
	assert_true(
		in_array(
			'recovery_commitment_payment_blocked',
			array_map(
				static function ( array $event ) {
					return (string) ( $event['event_type'] ?? '' );
				},
				$GLOBALS['ASDL_TEST_RECOVERY_EVENTS']
			),
			true
		),
		'El bloqueo debe dejar evento operativo.'
	);

	echo "recovery-commitment-settlement-regression: OK\n";
}
