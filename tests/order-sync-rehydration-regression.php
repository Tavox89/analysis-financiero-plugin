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

	function sanitize_email( $value ) {
		return strtolower( trim( (string) $value ) );
	}

	function sanitize_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_:-]/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	function current_time( $type = 'mysql' ) {
		return 'mysql' === $type ? '2026-04-10 15:00:00' : '2026-04-10';
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function do_action( ...$args ) {
		return null;
	}

	$GLOBALS['ASDL_TEST_OPTIONS']     = array();
	$GLOBALS['ASDL_TEST_ORDERS']      = array();
	$GLOBALS['ASDL_TEST_DOCS']        = array();
	$GLOBALS['ASDL_TEST_LINKS']       = array();
	$GLOBALS['ASDL_TEST_ALLOCATIONS'] = array();

	function get_option( $key, $default = false ) {
		return $GLOBALS['ASDL_TEST_OPTIONS'][ (string) $key ] ?? $default;
	}

	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['ASDL_TEST_OPTIONS'][ (string) $key ] = $value;

		return true;
	}

	final class ASDL_Test_Date {
		private $value;

		public function __construct( $value ) {
			$this->value = (string) $value;
		}

		public function date_i18n( $format ) {
			if ( 'Y-m-d' === $format ) {
				return substr( $this->value, 0, 10 );
			}

			return $this->value;
		}
	}

	final class ASDL_Test_Order {
		private $id;
		private $order_number;
		private $status;
		private $total;
		private $currency;
		private $customer_id;
		private $billing_email;
		private $billing_first_name;
		private $billing_last_name;
		private $billing_company;
		private $billing_phone;
		private $meta;
		private $date_created;

		public function __construct( array $data ) {
			$this->id            = (int) $data['id'];
			$this->order_number  = (string) $data['order_number'];
			$this->status        = sanitize_key( $data['status'] ?? 'pending' );
			$this->total         = (float) ( $data['total'] ?? 0 );
			$this->currency      = (string) ( $data['currency'] ?? 'USD' );
			$this->customer_id   = (int) ( $data['customer_id'] ?? 0 );
			$this->billing_email = (string) ( $data['billing_email'] ?? '' );
			$this->billing_first_name = (string) ( $data['billing_first_name'] ?? '' );
			$this->billing_last_name  = (string) ( $data['billing_last_name'] ?? '' );
			$this->billing_company    = (string) ( $data['billing_company'] ?? '' );
			$this->billing_phone      = (string) ( $data['billing_phone'] ?? '' );
			$this->meta          = (array) ( $data['meta'] ?? array() );
			$this->date_created  = new ASDL_Test_Date( (string) ( $data['created_at'] ?? '2026-04-10 15:00:00' ) );
		}

		public function get_id() {
			return $this->id;
		}

		public function get_order_number() {
			return $this->order_number;
		}

		public function get_status() {
			return $this->status;
		}

		public function get_total() {
			return $this->total;
		}

		public function get_currency() {
			return $this->currency;
		}

		public function get_customer_id() {
			return $this->customer_id;
		}

		public function get_billing_email() {
			return $this->billing_email;
		}

		public function get_billing_first_name() {
			return $this->billing_first_name;
		}

		public function get_billing_last_name() {
			return $this->billing_last_name;
		}

		public function get_billing_company() {
			return $this->billing_company;
		}

		public function get_billing_phone() {
			return $this->billing_phone;
		}

		public function get_formatted_billing_full_name() {
			return trim( $this->billing_first_name . ' ' . $this->billing_last_name );
		}

		public function get_date_created() {
			return $this->date_created;
		}

		public function get_meta( $key ) {
			return $this->meta[ (string) $key ] ?? null;
		}

		public function get_date_paid() {
			return null;
		}

		public function get_items( $type = '' ) {
			return array();
		}

		public function get_discount_total() {
			return 0.0;
		}
	}

	function wc_get_order( $order_id ) {
		return $GLOBALS['ASDL_TEST_ORDERS'][ (int) $order_id ] ?? null;
	}
}

namespace ASDLabs\Finance\Finance {
	final class MoneyStateService {
		public static function normalize_balance( $amount, $currency = '' ) {
			$rounded = round( max( 0, (float) $amount ), 2 );

			return $rounded > 0 ? $rounded : 0.0;
		}

		public static function balance_is_zero( $amount, $currency = '' ) {
			return 0.0 === self::normalize_balance( $amount, $currency );
		}

		public static function is_paid_like( $paid_total, $balance, $currency = '' ) {
			return (float) $paid_total > 0 && self::balance_is_zero( $balance, $currency );
		}
	}

	class ContactsRepository {
		public function find_by_wp_user_id( $wp_user_id ) {
			return array();
		}

		public function find_by_email( $email ) {
			return array();
		}

		public function create( array $data ) {
			return 0;
		}
	}

	class DocumentsRepository {
		public function find( $document_id ) {
			return $GLOBALS['ASDL_TEST_DOCS'][ (int) $document_id ] ?? null;
		}

		public function update_from_sync( $document_id, array $data, array $options = array() ) {
			if ( empty( $GLOBALS['ASDL_TEST_DOCS'][ (int) $document_id ] ) ) {
				return false;
			}

			$GLOBALS['ASDL_TEST_DOCS'][ (int) $document_id ] = array_merge(
				$GLOBALS['ASDL_TEST_DOCS'][ (int) $document_id ],
				array(
					'contact_id'       => $data['contact_id'] ?? null,
					'wp_user_id'       => $data['wp_user_id'] ?? null,
					'total'            => (float) ( $data['total'] ?? 0 ),
					'paid_total'       => (float) ( $data['paid_total'] ?? 0 ),
					'balance'          => (float) ( $data['balance'] ?? 0 ),
					'currency'         => (string) ( $data['currency'] ?? 'USD' ),
					'operational_status'=> (string) ( $data['operational_status'] ?? '' ),
					'financial_status' => (string) ( $data['financial_status'] ?? '' ),
					'payment_status'   => (string) ( $data['payment_status'] ?? '' ),
				)
			);

			return true;
		}

		public function create( array $data ) {
			$id = empty( $GLOBALS['ASDL_TEST_DOCS'] ) ? 1 : ( max( array_keys( $GLOBALS['ASDL_TEST_DOCS'] ) ) + 1 );
			$GLOBALS['ASDL_TEST_DOCS'][ $id ] = array_merge( array( 'id' => $id ), $data );

			return $id;
		}

		public function find_unlinked_order_candidate( array $args ) {
			foreach ( $GLOBALS['ASDL_TEST_DOCS'] as $document ) {
				if ( 'woo_sale' !== (string) ( $document['document_type'] ?? '' ) ) {
					continue;
				}

				if ( 'void' === (string) ( $document['financial_status'] ?? '' ) ) {
					continue;
				}

				foreach ( $GLOBALS['ASDL_TEST_LINKS'] as $link ) {
					if ( (int) ( $link['document_id'] ?? 0 ) === (int) ( $document['id'] ?? 0 ) ) {
						continue 2;
					}
				}

				if ( '' !== (string) ( $args['provider'] ?? '' ) && (string) ( $document['source_type'] ?? '' ) !== (string) $args['provider'] ) {
					continue;
				}

				if ( '' !== (string) ( $args['currency'] ?? '' ) && strtoupper( (string) ( $document['currency'] ?? '' ) ) !== strtoupper( (string) $args['currency'] ) ) {
					continue;
				}

				if ( isset( $args['total'] ) && abs( (float) ( $document['total'] ?? 0 ) - (float) $args['total'] ) > 0.0001 ) {
					continue;
				}

				$external_match = '' !== (string) ( $args['external_reference'] ?? '' )
					&& (string) ( $document['external_reference'] ?? '' ) === (string) $args['external_reference'];
				$number_match   = '' !== (string) ( $args['document_number'] ?? '' )
					&& (string) ( $document['document_number'] ?? '' ) === (string) $args['document_number']
					&& (
						( (int) ( $args['contact_id'] ?? 0 ) > 0 && (int) ( $document['contact_id'] ?? 0 ) === (int) $args['contact_id'] )
						|| ( (int) ( $args['wp_user_id'] ?? 0 ) > 0 && (int) ( $document['wp_user_id'] ?? 0 ) === (int) $args['wp_user_id'] )
					);

				if ( $external_match || $number_match ) {
					return $document;
				}
			}

			return null;
		}
	}

	class EventsRepository {
		public function log( $entity_type, $entity_id, $event_type, $message, array $payload = array() ) {
			return true;
		}
	}

	class PaymentAllocationsRepository {
		public function count_for_document( $document_id ) {
			return (int) ( $GLOBALS['ASDL_TEST_ALLOCATIONS'][ (int) $document_id ] ?? 0 );
		}
	}

	class RuntimeRefreshService {
		const SCOPE_DASHBOARD_SUMMARY     = 'dashboard_summary';
		const SCOPE_DASHBOARD_RECEIVABLES = 'dashboard_receivables';
		const SCOPE_CONTACT               = 'contact';

		public static function invalidate( array $scopes, array $args = array() ) {
			return true;
		}
	}

	class SourceLinksRepository {
		public function find_by_provider_object_external( $provider, $object_type, $external_id ) {
			foreach ( (array) $GLOBALS['ASDL_TEST_LINKS'] as $link ) {
				if ( (string) $provider === (string) ( $link['provider'] ?? '' ) && (string) $object_type === (string) ( $link['object_type'] ?? '' ) && (string) $external_id === (string) ( $link['external_id'] ?? '' ) ) {
					return $link;
				}
			}

			return null;
		}

		public function upsert( array $data ) {
			$matched = false;
			foreach ( (array) $GLOBALS['ASDL_TEST_LINKS'] as $index => $link ) {
				if ( (string) ( $link['provider'] ?? '' ) === (string) ( $data['provider'] ?? '' ) && (string) ( $link['object_type'] ?? '' ) === (string) ( $data['object_type'] ?? '' ) && (string) ( $link['external_id'] ?? '' ) === (string) ( $data['external_id'] ?? '' ) ) {
					$GLOBALS['ASDL_TEST_LINKS'][ $index ] = array_merge( $link, $data );
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				$GLOBALS['ASDL_TEST_LINKS'][] = $data;
			}

			return true;
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Integrations/Woo/OrderSyncService.php';

	use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

	function assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . ' | expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true )
			);
		}
	}

	function assert_true( $condition, $message ) {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function build_link_sync_hash( OrderSyncService $service, $order, array $document, $contact_id, $preserve_payment = false, $preserve_class = false ) {
		$payload_method = new \ReflectionMethod( $service, 'build_document_payload' );
		$hash_method    = new \ReflectionMethod( $service, 'build_sync_hash' );
		$provider_method = new \ReflectionMethod( $service, 'detect_provider' );
		$provider = $provider_method->invoke( $service, $order );
		$payload  = $payload_method->invoke( $service, $order, $provider, $document, $contact_id, $preserve_payment );

		return $hash_method->invoke( $service, $payload, $provider, $order, $preserve_payment, $preserve_class );
	}

	function seed_case( array $order_data, array $document_data, $sync_hash = null ) {
		$GLOBALS['ASDL_TEST_ORDERS'] = array(
			(int) $order_data['id'] => new ASDL_Test_Order( $order_data ),
		);
		$GLOBALS['ASDL_TEST_DOCS'] = array(
			(int) $document_data['id'] => $document_data,
		);
		$GLOBALS['ASDL_TEST_LINKS'] = array(
			array(
				'id'           => 1,
				'document_id'  => (int) $document_data['id'],
				'provider'     => 'openpos',
				'object_type'  => 'shop_order',
				'external_id'  => (string) $order_data['id'],
				'external_ref' => (string) $order_data['order_number'],
				'sync_hash'    => (string) $sync_hash,
			),
		);
		$GLOBALS['ASDL_TEST_ALLOCATIONS'] = array(
			(int) $document_data['id'] => 0,
		);
		$GLOBALS['ASDL_TEST_OPTIONS'] = array();
	}

	$service = new OrderSyncService();

	// 1. Caso tipo Hevert: pending + documento void + hash igual => no debe quedar unchanged.
	seed_case(
		array(
			'id'            => 205310,
			'order_number'  => '708762182',
			'status'        => 'pending',
			'total'         => 64.40,
			'currency'      => 'USD',
			'customer_id'   => 1063,
			'billing_email' => 'hevert@example.com',
			'meta'          => array(
				'_op_order_source'     => 'openpos',
				'_op_source'           => 'openpos',
				'_op_order_total_paid' => 0,
				'_op_remain_paid'      => 64.40,
			),
		),
		array(
			'id'               => 151,
			'contact_id'       => 98,
			'wp_user_id'       => 1063,
			'currency'         => 'USD',
			'total'            => 64.40,
			'paid_total'       => 0.0,
			'balance'          => 0.0,
			'financial_status' => 'void',
			'payment_status'   => 'pending',
			'balance_nature'   => 'receivable',
		)
	);
	$hash = build_link_sync_hash( $service, $GLOBALS['ASDL_TEST_ORDERS'][205310], $GLOBALS['ASDL_TEST_DOCS'][151], 98, false, false );
	$GLOBALS['ASDL_TEST_LINKS'][0]['sync_hash'] = $hash;
	$result = $service->sync_order( 205310, array( 'trigger' => 'financial_cancellation' ) );
	assert_same( 'updated', $result['status'], 'El sync financiero debe romper el unchanged si el documento esta stale.' );
	assert_same( 'posted', $GLOBALS['ASDL_TEST_DOCS'][151]['financial_status'], 'El documento stale debe rehidratarse a posted.' );
	assert_same( 'pending', $GLOBALS['ASDL_TEST_DOCS'][151]['payment_status'], 'El documento rehidratado debe quedar pending.' );
	assert_same( 64.40, $GLOBALS['ASDL_TEST_DOCS'][151]['balance'], 'El documento rehidratado debe recuperar el balance real.' );

	// 2. Caso sano: hash igual y documento coherente => unchanged.
	seed_case(
		array(
			'id'            => 205311,
			'order_number'  => '708762183',
			'status'        => 'pending',
			'total'         => 64.40,
			'currency'      => 'USD',
			'customer_id'   => 1063,
			'billing_email' => 'hevert@example.com',
			'meta'          => array(
				'_op_order_source'     => 'openpos',
				'_op_source'           => 'openpos',
				'_op_order_total_paid' => 0,
				'_op_remain_paid'      => 64.40,
			),
		),
		array(
			'id'               => 152,
			'contact_id'       => 98,
			'wp_user_id'       => 1063,
			'currency'         => 'USD',
			'total'            => 64.40,
			'paid_total'       => 0.0,
			'balance'          => 64.40,
			'financial_status' => 'posted',
			'payment_status'   => 'pending',
			'balance_nature'   => 'receivable',
		)
	);
	$hash = build_link_sync_hash( $service, $GLOBALS['ASDL_TEST_ORDERS'][205311], $GLOBALS['ASDL_TEST_DOCS'][152], 98, false, false );
	$GLOBALS['ASDL_TEST_LINKS'][0]['sync_hash'] = $hash;
	$result = $service->sync_order( 205311, array( 'trigger' => 'financial_cancellation' ) );
	assert_same( 'unchanged', $result['status'], 'El pedido sano debe seguir saliendo unchanged.' );

	// 3. Pedido cobrable + documento con balance 0 incoherente => fuerza update.
	seed_case(
		array(
			'id'            => 205312,
			'order_number'  => '708762184',
			'status'        => 'pending',
			'total'         => 80.00,
			'currency'      => 'USD',
			'customer_id'   => 1063,
			'billing_email' => 'hevert@example.com',
			'meta'          => array(
				'_op_order_source'     => 'openpos',
				'_op_source'           => 'openpos',
				'_op_order_total_paid' => 0,
				'_op_remain_paid'      => 80.00,
			),
		),
		array(
			'id'               => 153,
			'contact_id'       => 98,
			'wp_user_id'       => 1063,
			'currency'         => 'USD',
			'total'            => 80.00,
			'paid_total'       => 0.0,
			'balance'          => 0.0,
			'financial_status' => 'posted',
			'payment_status'   => 'pending',
			'balance_nature'   => 'receivable',
		)
	);
	$hash = build_link_sync_hash( $service, $GLOBALS['ASDL_TEST_ORDERS'][205312], $GLOBALS['ASDL_TEST_DOCS'][153], 98, false, false );
	$GLOBALS['ASDL_TEST_LINKS'][0]['sync_hash'] = $hash;
	$result = $service->sync_order( 205312, array( 'trigger' => 'financial_cancellation' ) );
	assert_same( 'updated', $result['status'], 'Balance 0 incoherente tambien debe forzar update.' );
	assert_same( 80.00, $GLOBALS['ASDL_TEST_DOCS'][153]['balance'], 'El balance debe volver al total real del pedido cobrable.' );

	// 4. Caso no cobrable real y alineado => no debe forzar algo incorrecto.
	seed_case(
		array(
			'id'            => 205313,
			'order_number'  => '708762185',
			'status'        => 'cancelled',
			'total'         => 91.25,
			'currency'      => 'USD',
			'customer_id'   => 1063,
			'billing_email' => 'hevert@example.com',
			'meta'          => array(
				'_op_order_source'     => 'openpos',
				'_op_source'           => 'openpos',
				'_op_order_total_paid' => 0,
				'_op_remain_paid'      => 91.25,
			),
		),
		array(
			'id'               => 154,
			'contact_id'       => 98,
			'wp_user_id'       => 1063,
			'currency'         => 'USD',
			'total'            => 91.25,
			'paid_total'       => 0.0,
			'balance'          => 91.25,
			'financial_status' => 'void',
			'payment_status'   => 'pending',
			'balance_nature'   => 'receivable',
		)
	);
	$hash = build_link_sync_hash( $service, $GLOBALS['ASDL_TEST_ORDERS'][205313], $GLOBALS['ASDL_TEST_DOCS'][154], 98, false, false );
	$GLOBALS['ASDL_TEST_LINKS'][0]['sync_hash'] = $hash;
	$result = $service->sync_order( 205313, array( 'trigger' => 'financial_cancellation' ) );
	assert_same( 'unchanged', $result['status'], 'Un pedido no cobrable ya alineado debe seguir unchanged.' );

	// 5. Documento huerfano compatible: debe adoptarlo y crear el source_link sin duplicar.
	$GLOBALS['ASDL_TEST_ORDERS'] = array(
		205314 => new ASDL_Test_Order(
			array(
				'id'                 => 205314,
				'order_number'       => '708762186',
				'status'             => 'pending',
				'total'              => 11.89,
				'currency'           => 'USD',
				'customer_id'        => 1063,
				'billing_email'      => 'pierina@example.com',
				'billing_first_name' => 'Pierina',
				'billing_last_name'  => 'Fuenmayor',
				'meta'               => array(
					'_op_order_source'     => 'openpos',
					'_op_source'           => 'openpos',
					'_op_order_total_paid' => 0,
					'_op_remain_paid'      => 11.89,
				),
			)
		),
	);
	$GLOBALS['ASDL_TEST_DOCS'] = array(
		160 => array(
			'id'                 => 160,
			'document_type'      => 'woo_sale',
			'source_type'        => 'openpos',
			'external_reference' => 'shop_order:205314',
			'document_number'    => '708762186',
			'contact_id'         => 47,
			'wp_user_id'         => 1063,
			'currency'           => 'USD',
			'total'              => 11.89,
			'paid_total'         => 0.0,
			'balance'            => 11.89,
			'financial_status'   => 'posted',
			'payment_status'     => 'pending',
			'balance_nature'     => 'receivable',
		),
	);
	$GLOBALS['ASDL_TEST_LINKS']       = array();
	$GLOBALS['ASDL_TEST_ALLOCATIONS'] = array( 160 => 0 );
	$result = $service->sync_order( 205314, array( 'trigger' => 'manual_single' ) );
	assert_same( 'updated', $result['status'], 'El sync debe actualizar el documento huerfano, no crear otro.' );
	assert_same( 160, $result['document_id'], 'El documento adoptado debe conservar su ID original.' );
	assert_same( 1, count( $GLOBALS['ASDL_TEST_DOCS'] ), 'No debe crear un documento duplicado para el mismo pedido.' );
	assert_same( 160, (int) ( $GLOBALS['ASDL_TEST_LINKS'][0]['document_id'] ?? 0 ), 'El source_link nuevo debe apuntar al documento adoptado.' );

	echo "Order sync rehydration regression passed.\n";
}
