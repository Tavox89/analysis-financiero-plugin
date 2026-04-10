<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	function absint( $value ) {
		return abs( (int) $value );
	}

	function sanitize_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_:-]/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	$GLOBALS['ASDL_TEST_WC_ORDERS']   = array();
	$GLOBALS['ASDL_TEST_SOURCE_LINKS'] = array();
	$GLOBALS['ASDL_TEST_SYNC_TARGETS'] = array();
	$GLOBALS['ASDL_TEST_DOCUMENTS']    = array();
	$GLOBALS['ASDL_TEST_SYNC_CALLS']   = array();

	final class ASDL_Test_Cancellation_Order {
		private $id;
		private $status;
		private $total;
		private $date_paid = '2026-04-09 21:46:18';
		private $meta = array();
		public $update_status_calls = array();
		public $save_count = 0;
		public $set_date_paid_calls = 0;

		public function __construct( $id, $status, $total, array $meta = array() ) {
			$this->id     = (int) $id;
			$this->status = sanitize_key( $status );
			$this->total  = (float) $total;
			$this->meta   = $meta;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_total() {
			return $this->total;
		}

		public function update_status( $status, $note = '', $manual = false ) {
			$this->status = sanitize_key( $status );
			$this->update_status_calls[] = array(
				'status' => $this->status,
				'note'   => (string) $note,
				'manual' => (bool) $manual,
			);
		}

		public function set_date_paid( $value ) {
			$this->date_paid = $value;
			$this->set_date_paid_calls++;
		}

		public function get_date_paid() {
			return $this->date_paid;
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ (string) $key ] = $value;
		}

		public function get_meta( $key ) {
			return $this->meta[ (string) $key ] ?? null;
		}

		public function save() {
			$this->save_count++;
		}
	}

	function wc_get_order( $order_id ) {
		return $GLOBALS['ASDL_TEST_WC_ORDERS'][ (int) $order_id ] ?? null;
	}
}

namespace ASDLabs\Finance\Finance {
	class BaseRepository {}

	class SourceLinksRepository {
		public function find_for_document( $document_id ) {
			return $GLOBALS['ASDL_TEST_SOURCE_LINKS'][ (int) $document_id ] ?? array();
		}
	}
}

namespace ASDLabs\Finance\Integrations\Woo {
	final class Module {
		public static function run_without_status_guard( callable $callback ) {
			return $callback();
		}
	}

	final class OrderSyncService {
		public function sync_order( $order_id, array $args = array() ) {
			$order_id = (int) $order_id;
			$GLOBALS['ASDL_TEST_SYNC_CALLS'][] = array(
				'order_id' => $order_id,
				'trigger'  => (string) ( $args['trigger'] ?? '' ),
			);

			$document_id = (int) ( $GLOBALS['ASDL_TEST_SYNC_TARGETS'][ $order_id ] ?? 0 );
			if ( $document_id > 0 && ! empty( $GLOBALS['ASDL_TEST_DOCUMENTS'][ $document_id ] ) ) {
				$order = \wc_get_order( $order_id );
				$total = $order ? (float) $order->get_total() : 0.0;
				$GLOBALS['ASDL_TEST_DOCUMENTS'][ $document_id ]['financial_status'] = 'posted';
				$GLOBALS['ASDL_TEST_DOCUMENTS'][ $document_id ]['payment_status']   = 'pending';
				$GLOBALS['ASDL_TEST_DOCUMENTS'][ $document_id ]['paid_total']       = 0.0;
				$GLOBALS['ASDL_TEST_DOCUMENTS'][ $document_id ]['balance']          = $total;
			}

			return array(
				'status'   => 'updated',
				'order_id' => $order_id,
			);
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Finance/CancellationService.php';

	use ASDLabs\Finance\Finance\CancellationService;

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

	function invoke_reopen_linked_orders( $document_id, $note ) {
		$service  = new CancellationService();
		$method   = new \ReflectionMethod( $service, 'reopen_linked_orders' );

		return $method->invoke( $service, $document_id, $note );
	}

	$GLOBALS['ASDL_TEST_DOCUMENTS'][151] = array(
		'id'               => 151,
		'financial_status' => 'void',
		'payment_status'   => 'pending',
		'paid_total'       => 0.0,
		'balance'          => 0.0,
	);
	$GLOBALS['ASDL_TEST_SOURCE_LINKS'][151] = array(
		array(
			'provider'    => 'openpos',
			'external_id' => 205310,
		),
	);
	$GLOBALS['ASDL_TEST_SYNC_TARGETS'][205310] = 151;
	$GLOBALS['ASDL_TEST_WC_ORDERS'][205310] = new ASDL_Test_Cancellation_Order(
		205310,
		'pending',
		64.40,
		array(
			'_op_order_total_paid' => 56.60,
			'_op_remain_paid'      => 7.80,
		)
	);

	$reopened_ids = invoke_reopen_linked_orders( 151, 'Reapertura por anulacion financiera.' );
	$order        = $GLOBALS['ASDL_TEST_WC_ORDERS'][205310];

	assert_same( array( 205310 ), $reopened_ids, 'El pedido pending tambien debe entrar en la resincronizacion.' );
	assert_same( 0, count( $order->update_status_calls ), 'Si ya estaba pending, no hace falta volver a cambiar el status.' );
	assert_same( 1, $order->set_date_paid_calls, 'La reapertura debe limpiar date_paid aunque el pedido ya este pending.' );
	assert_same( null, $order->get_date_paid(), 'date_paid debe quedar limpio.' );
	assert_same( 0, $order->get_meta( '_op_order_total_paid' ), 'OpenPOS debe limpiar el total pagado cacheado.' );
	assert_same( 64.40, $order->get_meta( '_op_remain_paid' ), 'OpenPOS debe restaurar el remain_paid al total del pedido.' );
	assert_same( 1, $order->save_count, 'El pedido debe guardarse antes de resincronizar.' );
	assert_same( 1, count( $GLOBALS['ASDL_TEST_SYNC_CALLS'] ), 'Debe ejecutar sync_order aun si el pedido ya estaba pending.' );
	assert_same( 'financial_cancellation', $GLOBALS['ASDL_TEST_SYNC_CALLS'][0]['trigger'], 'La resincronizacion debe usar el trigger financiero correcto.' );
	assert_same( 'posted', $GLOBALS['ASDL_TEST_DOCUMENTS'][151]['financial_status'], 'El documento void debe rehidratarse por la resincronizacion.' );
	assert_same( 64.40, $GLOBALS['ASDL_TEST_DOCUMENTS'][151]['balance'], 'El documento rehidratado debe volver a reflejar la deuda real.' );

	$GLOBALS['ASDL_TEST_SYNC_CALLS'] = array();
	$GLOBALS['ASDL_TEST_DOCUMENTS'][152] = array(
		'id'               => 152,
		'financial_status' => 'void',
		'payment_status'   => 'pending',
		'paid_total'       => 0.0,
		'balance'          => 0.0,
	);
	$GLOBALS['ASDL_TEST_SOURCE_LINKS'][152] = array(
		array(
			'provider'    => 'openpos',
			'external_id' => 205311,
		),
	);
	$GLOBALS['ASDL_TEST_SYNC_TARGETS'][205311] = 152;
	$GLOBALS['ASDL_TEST_WC_ORDERS'][205311] = new ASDL_Test_Cancellation_Order(
		205311,
		'processing',
		80.00,
		array(
			'_op_order_total_paid' => 80.00,
			'_op_remain_paid'      => 0.00,
		)
	);

	$reopened_ids = invoke_reopen_linked_orders( 152, 'Reapertura por anulacion financiera.' );
	$order        = $GLOBALS['ASDL_TEST_WC_ORDERS'][205311];

	assert_same( array( 205311 ), $reopened_ids, 'El pedido no-pending tambien debe volver como resincronizado.' );
	assert_same( 1, count( $order->update_status_calls ), 'Si no estaba pending, debe reabrirse normalmente.' );
	assert_same( 'pending', $order->update_status_calls[0]['status'], 'El pedido debe pasar a pending antes de resincronizar.' );
	assert_same( 1, count( $GLOBALS['ASDL_TEST_SYNC_CALLS'] ), 'El flujo normal tambien debe resincronizar una vez.' );
	assert_same( 'posted', $GLOBALS['ASDL_TEST_DOCUMENTS'][152]['financial_status'], 'La reapertura normal tambien debe rehidratar el documento.' );
	assert_same( 80.00, $GLOBALS['ASDL_TEST_DOCUMENTS'][152]['balance'], 'La reapertura normal debe restaurar el balance del documento.' );

	echo "Cancellation reopen order regression passed.\n";
}
