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

	function current_time( $type = 'mysql' ) {
		return 'mysql' === $type ? '2026-04-10 12:00:00' : '2026-04-10';
	}

	function get_current_user_id() {
		return 99;
	}

	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function wp_list_pluck( $list, $field ) {
		$values = array();

		foreach ( (array) $list as $item ) {
			if ( is_array( $item ) && array_key_exists( $field, $item ) ) {
				$values[] = $item[ $field ];
			} elseif ( is_object( $item ) && isset( $item->{$field} ) ) {
				$values[] = $item->{$field};
			} else {
				$values[] = null;
			}
		}

		return $values;
	}

	final class ASDL_Test_Order {
		private $status;
		private $currency;

		public function __construct( $status, $currency = 'USD' ) {
			$this->status   = sanitize_key( $status );
			$this->currency = strtoupper( sanitize_text_field( $currency ) );
		}

		public function get_status() {
			return $this->status;
		}

		public function get_currency() {
			return $this->currency;
		}
	}

	$GLOBALS['ASDL_TEST_WC_ORDERS'] = array();

	function wc_get_order( $order_id ) {
		$order_id = absint( $order_id );

		return $GLOBALS['ASDL_TEST_WC_ORDERS'][ $order_id ] ?? null;
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
	}

	class IntegrityCasesRepository {
		const STATUS_OPEN     = 'open';
		const STATUS_REVIEWED = 'reviewed';
		const STATUS_IGNORED  = 'ignored';
		const STATUS_RESOLVED = 'resolved';

		const SEVERITY_HIGH   = 'high';
		const SEVERITY_MEDIUM = 'medium';
		const SEVERITY_LOW    = 'low';

		public static function severity_options() {
			return array(
				self::SEVERITY_HIGH   => 'Alta',
				self::SEVERITY_MEDIUM => 'Media',
				self::SEVERITY_LOW    => 'Baja',
			);
		}

		public static function status_options() {
			return array(
				self::STATUS_OPEN     => 'Abierto',
				self::STATUS_REVIEWED => 'Revisado',
				self::STATUS_IGNORED  => 'Ignorado',
				self::STATUS_RESOLVED => 'Resuelto',
			);
		}
	}

	class ContactsRepository {
		public function find( $contact_id ) {
			return $GLOBALS['ASDL_TEST_CONTACTS'][ (int) $contact_id ] ?? array();
		}
	}

	class DocumentsRepository {
		public function find( $document_id ) {
			return $GLOBALS['ASDL_TEST_DOCUMENTS'][ (int) $document_id ] ?? array();
		}
	}

	class PaymentsRepository {
		public function find( $payment_id ) {
			return $GLOBALS['ASDL_TEST_PAYMENTS'][ (int) $payment_id ] ?? array();
		}
	}

	class OrderSettlementBatchesRepository {
		public function find( $batch_id ) {
			return $GLOBALS['ASDL_TEST_BATCHES'][ (int) $batch_id ] ?? array();
		}

		public function list_batch_items( $batch_id, $limit = 200 ) {
			return $GLOBALS['ASDL_TEST_BATCH_ITEMS'][ (int) $batch_id ] ?? array();
		}
	}

	class EventsRepository {
		public function log( $entity_type, $entity_id, $event_type, $message, array $payload = array(), $actor_user_id = null ) {
			return true;
		}
	}

	class SourceLinksRepository {
		public function find_for_document( $document_id ) {
			return $GLOBALS['ASDL_TEST_SOURCE_LINKS'][ (int) $document_id ] ?? array();
		}
	}

	class ContactOverviewService {
		public function get_contact_snapshot_cached( $contact_id, array $args = array() ) {
			return $GLOBALS['ASDL_TEST_CONTACT_SNAPSHOTS'][ (int) $contact_id ] ?? array();
		}
	}

	class CommerceOrderIndexRepository {
		public function list_collectible_orders( array $args = array() ) {
			$order_id = absint( $args['external_order_id'] ?? 0 );

			if ( $order_id > 0 ) {
				return array_values(
					array_filter(
						(array) $GLOBALS['ASDL_TEST_ORDER_INDEX_ROWS'],
						static function ( array $row ) use ( $order_id ) {
							return (int) ( $row['external_order_id'] ?? 0 ) === $order_id;
						}
					)
				);
			}

			return (array) $GLOBALS['ASDL_TEST_ORDER_INDEX_ROWS'];
		}
	}

	final class FakeIntegrityCasesRepository {
		private $records = array();
		private $next_id = 1;

		public function upsert_detected_case( array $case ) {
			$key = sanitize_text_field( (string) ( $case['case_key'] ?? '' ) );
			if ( '' === $key ) {
				return new \WP_Error( 'missing_key', 'missing case key' );
			}

			if ( isset( $this->records[ $key ] ) ) {
				$existing                    = $this->records[ $key ];
				$case['id']                  = $existing['id'];
				$case['status']              = IntegrityCasesRepository::STATUS_RESOLVED === (string) ( $existing['status'] ?? '' )
					? IntegrityCasesRepository::STATUS_OPEN
					: ( $existing['status'] ?? IntegrityCasesRepository::STATUS_OPEN );
				$case['persistence_action']  = IntegrityCasesRepository::STATUS_RESOLVED === (string) ( $existing['status'] ?? '' )
					? 'reopened'
					: 'updated';
			} else {
				$case['id']                 = $this->next_id++;
				$case['status']             = IntegrityCasesRepository::STATUS_OPEN;
				$case['persistence_action'] = 'created';
			}

			$this->records[ $key ] = $case;

			return $case;
		}

		public function find( $case_id ) {
			foreach ( $this->records as $record ) {
				if ( (int) ( $record['id'] ?? 0 ) === (int) $case_id ) {
					return $record;
				}
			}

			return null;
		}

		public function touch_last_scanned( $case_id ) {
			foreach ( $this->records as $key => $record ) {
				if ( (int) ( $record['id'] ?? 0 ) === (int) $case_id ) {
					$record['last_scanned_at'] = current_time( 'mysql' );
					$this->records[ $key ]     = $record;

					return true;
				}
			}

			return true;
		}

		public function update_status( $case_id, $status, $actor_user_id = null, $note = '' ) {
			foreach ( $this->records as $key => $record ) {
				if ( (int) ( $record['id'] ?? 0 ) === (int) $case_id ) {
					$record['status']            = sanitize_key( (string) $status );
					$record['status_changed_at'] = current_time( 'mysql' );
					$record['status_changed_by'] = null !== $actor_user_id ? (int) $actor_user_id : 0;
					$record['status_note']       = sanitize_textarea_field( (string) $note );
					$this->records[ $key ]       = $record;

					return true;
				}
			}

			return true;
		}
	}
}

namespace ASDLabs\Finance\Integrations\Woo {
	class OrderSyncService {
		public function describe_order( $order ) {
			return array();
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Finance/IntegrityMonitorService.php';

	use ASDLabs\Finance\Finance\ContactOverviewService;
	use ASDLabs\Finance\Finance\ContactsRepository;
	use ASDLabs\Finance\Finance\DocumentsRepository;
	use ASDLabs\Finance\Finance\EventsRepository;
	use ASDLabs\Finance\Finance\FakeIntegrityCasesRepository;
	use ASDLabs\Finance\Finance\IntegrityCasesRepository;
	use ASDLabs\Finance\Finance\IntegrityMonitorService;
	use ASDLabs\Finance\Finance\OrderSettlementBatchesRepository;
	use ASDLabs\Finance\Finance\PaymentsRepository;
	use ASDLabs\Finance\Finance\SourceLinksRepository;
	use ASDLabs\Finance\Finance\CommerceOrderIndexRepository;
	use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

	function reset_integrity_monitor_state() {
		$GLOBALS['ASDL_TEST_CONTACTS'] = array(
			61 => array(
				'id'           => 61,
				'display_name' => 'Alirio Torres V13863718',
			),
			4656 => array(
				'id'           => 4656,
				'display_name' => 'la americana C.A #4656',
			),
		);
		$GLOBALS['ASDL_TEST_CONTACT_SNAPSHOTS'] = array(
			61 => array(
				'contact' => array(
					'id'           => 61,
					'display_name' => 'Alirio Torres V13863718',
				),
				'summary' => array(
					'unapplied_payment_total' => 75.58,
					'pending_order_total'     => 75.58,
				),
				'payments' => array(
					array(
						'id'               => 82,
						'payment_number'   => 'PAY-82',
						'available_amount' => 52.13,
						'currency'         => 'USD',
						'method_key'       => 'binance',
					),
					array(
						'id'               => 83,
						'payment_number'   => 'PAY-83',
						'available_amount' => 23.45,
						'currency'         => 'USD',
						'method_key'       => 'dual_price_discount',
					),
				),
				'pending_orders' => array(
					array(
						'order_id'            => 209668,
						'order_number'        => '880563592',
						'document_id'         => 128,
						'effective_due_total' => 16.44,
						'currency'            => 'USD',
					),
					array(
						'order_id'            => 210862,
						'order_number'        => '880563674',
						'document_id'         => 129,
						'effective_due_total' => 59.14,
						'currency'            => 'USD',
					),
				),
			),
		);
		$GLOBALS['ASDL_TEST_PAYMENTS'] = array(
			81 => array(
				'id'               => 81,
				'payment_number'   => 'PAY-81',
				'status'           => 'posted',
				'available_amount' => 0.00,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'collection',
				'method_key'       => 'binance',
			),
			82 => array(
				'id'               => 82,
				'payment_number'   => 'PAY-82',
				'status'           => 'posted',
				'available_amount' => 52.13,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'collection',
				'method_key'       => 'binance',
			),
			83 => array(
				'id'               => 83,
				'payment_number'   => 'PAY-83',
				'status'           => 'posted',
				'available_amount' => 23.45,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'adjustment',
				'method_key'       => 'dual_price_discount',
				'meta_json'        => wp_json_encode(
					array(
						'order_settlement_batch_id' => 49,
						'dual_discount_parent_payment_id' => 82,
					)
				),
			),
			85 => array(
				'id'               => 85,
				'payment_number'   => 'PAY-85',
				'status'           => 'posted',
				'available_amount' => 0.00,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'adjustment',
				'method_key'       => 'dual_price_discount',
				'meta_json'        => wp_json_encode(
					array(
						'order_settlement_batch_id' => 48,
						'dual_discount_parent_payment_id' => 81,
					)
				),
			),
			90 => array(
				'id'               => 90,
				'payment_number'   => 'PAY-90',
				'status'           => 'posted',
				'available_amount' => 0.00,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'collection',
				'method_key'       => 'binance',
			),
			91 => array(
				'id'               => 91,
				'payment_number'   => 'PAY-91',
				'status'           => 'posted',
				'available_amount' => 0.00,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'adjustment',
				'method_key'       => 'dual_price_discount',
				'meta_json'        => wp_json_encode(
					array(
						'order_settlement_batch_id' => 40,
						'dual_discount_parent_payment_id' => 90,
					)
				),
			),
			180 => array(
				'id'               => 180,
				'payment_number'   => 'PAY-180',
				'status'           => 'posted',
				'available_amount' => 0.00,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'collection',
				'method_key'       => 'binance',
			),
			181 => array(
				'id'               => 181,
				'payment_number'   => 'PAY-181',
				'status'           => 'posted',
				'available_amount' => 0.01,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'adjustment',
				'method_key'       => 'dual_price_discount',
				'meta_json'        => wp_json_encode(
					array(
						'order_settlement_batch_id' => 80,
						'dual_discount_parent_payment_id' => 180,
					)
				),
			),
			84 => array(
				'id'               => 84,
				'payment_number'   => 'PAY-84',
				'status'           => 'posted',
				'available_amount' => 0.01,
				'currency'         => 'USD',
				'contact_id'       => 61,
				'payment_type'     => 'adjustment',
				'method_key'       => 'dual_price_discount',
				'meta_json'        => wp_json_encode(
					array(
						'order_settlement_batch_id' => 48,
						'dual_discount_parent_payment_id' => 81,
					)
				),
			),
		);
		$GLOBALS['ASDL_TEST_DOCUMENTS'] = array(
			128 => array(
				'id'               => 128,
				'contact_id'       => 61,
				'balance'          => 16.44,
				'currency'         => 'USD',
				'financial_status' => 'posted',
				'payment_status'   => 'pending',
			),
			129 => array(
				'id'               => 129,
				'contact_id'       => 61,
				'balance'          => 59.14,
				'currency'         => 'USD',
				'financial_status' => 'posted',
				'payment_status'   => 'pending',
			),
			201 => array(
				'id'                 => 201,
				'contact_id'         => 61,
				'balance'            => 0.01,
				'currency'           => 'USD',
				'financial_status'   => 'posted',
				'payment_status'     => 'pending',
				'document_type'      => 'woo_sale',
				'external_reference' => 'shop_order:187373',
			),
			127 => array(
				'id'               => 127,
				'contact_id'       => 61,
				'balance'          => 0.00,
				'currency'         => 'USD',
				'financial_status' => 'posted',
				'payment_status'   => 'paid',
			),
			140 => array(
				'id'               => 140,
				'contact_id'       => 61,
				'balance'          => 12.75,
				'currency'         => 'USD',
				'financial_status' => 'posted',
				'payment_status'   => 'pending',
			),
			180 => array(
				'id'               => 180,
				'contact_id'       => 61,
				'balance'          => 0.00,
				'currency'         => 'USD',
				'financial_status' => 'posted',
				'payment_status'   => 'paid',
			),
		);
		$GLOBALS['ASDL_TEST_BATCHES'] = array(
			40 => array(
				'id'         => 40,
				'contact_id' => 61,
				'status'     => 'completed_with_errors',
				'mode'       => 'dual',
				'currency'   => 'USD',
				'method_key' => 'binance',
				'meta'       => array(
					'main_payment_id'     => 90,
					'discount_payment_id' => 91,
				),
			),
			48 => array(
				'id'         => 48,
				'contact_id' => 61,
				'status'     => 'completed_with_errors',
				'mode'       => 'dual',
				'currency'   => 'USD',
				'method_key' => 'binance',
				'meta'       => array(
					'main_payment_id'     => 81,
					'discount_payment_id' => 85,
				),
			),
			49 => array(
				'id'         => 49,
				'contact_id' => 61,
				'status'     => 'completed_with_errors',
				'mode'       => 'dual',
				'currency'   => 'USD',
				'method_key' => 'binance',
				'meta'       => array(
					'main_payment_id'     => 82,
					'discount_payment_id' => 83,
				),
			),
			80 => array(
				'id'         => 80,
				'contact_id' => 61,
				'status'     => 'completed_with_errors',
				'mode'       => 'dual',
				'currency'   => 'USD',
				'method_key' => 'binance',
				'meta'       => array(
					'main_payment_id'     => 180,
					'discount_payment_id' => 181,
				),
			),
		);
		$GLOBALS['ASDL_TEST_BATCH_ITEMS'] = array(
			40 => array(
				array(
					'id'                => 4001,
					'status'            => 'error',
					'external_order_id' => 200140,
					'order_number'      => '880564040',
					'document_id'       => 140,
					'error_message'     => 'Documento aun abierto.',
					'cover_amount'      => 8.80,
					'discount_amount'   => 3.95,
				),
			),
			48 => array(
				array(
					'id'                => 4801,
					'status'            => 'error',
					'external_order_id' => 200127,
					'order_number'      => '880564048',
					'document_id'       => 127,
					'error_message'     => 'Error historico saneado.',
					'cover_amount'      => 21.11,
					'discount_amount'   => 9.49,
				),
			),
			49 => array(
				array(
					'id'                => 4901,
					'status'            => 'error',
					'external_order_id' => 209668,
					'order_number'      => '880563592',
					'document_id'       => 128,
					'error_message'     => 'El monto supera el saldo pendiente del documento.',
					'cover_amount'      => 11.338668,
					'discount_amount'   => 5.101332,
				),
				array(
					'id'                => 4902,
					'status'            => 'error',
					'external_order_id' => 210862,
					'order_number'      => '880563674',
					'document_id'       => 129,
					'error_message'     => 'El monto supera el saldo pendiente del documento.',
					'cover_amount'      => 40.788858,
					'discount_amount'   => 18.351142,
				),
			),
			80 => array(
				array(
					'id'                => 8001,
					'status'            => 'error',
					'external_order_id' => 200180,
					'order_number'      => '880564080',
					'document_id'       => 180,
					'error_message'     => 'Residual tecnico pendiente.',
					'cover_amount'      => 0.02,
					'discount_amount'   => 0.01,
				),
			),
		);
		$GLOBALS['ASDL_TEST_SOURCE_LINKS'] = array(
			201 => array(
				array(
					'provider'     => 'woocommerce',
					'object_type'  => 'shop_order',
					'external_id'  => '187373',
					'external_ref' => '456516',
				),
			),
		);
		$GLOBALS['ASDL_TEST_ORDER_INDEX_ROWS'] = array(
			array(
				'external_order_id'         => 187373,
				'order_number'              => '456516',
				'contact_id'                => 4656,
				'display_name'              => 'la americana C.A #4656',
				'document_id'               => 0,
				'source_link_id'            => 0,
				'status'                    => 'pending',
				'balance'                   => 3532.14,
				'currency'                  => 'USD',
				'is_open'                   => 1,
				'operationally_collectible' => 1,
				'fiscal_year'               => 2025,
			),
		);
		$GLOBALS['ASDL_TEST_WC_ORDERS'] = array(
			187373 => new ASDL_Test_Order( 'cancelled', 'USD' ),
		);
	}

	function build_monitor_service() {
		return new IntegrityMonitorService(
			array(
				'cases'            => new FakeIntegrityCasesRepository(),
				'contacts'         => new ContactsRepository(),
				'documents'        => new DocumentsRepository(),
				'payments'         => new PaymentsRepository(),
				'batches'          => new OrderSettlementBatchesRepository(),
				'events'           => new EventsRepository(),
				'order_sync'       => new OrderSyncService(),
				'source_links'     => new SourceLinksRepository(),
				'contact_overview' => new ContactOverviewService(),
				'order_index'      => new CommerceOrderIndexRepository(),
			)
		);
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

	reset_integrity_monitor_state();

	$service = build_monitor_service();
	$result  = $service->scan(
		array(
			'case_type'  => IntegrityMonitorService::CASE_CREDIT_AND_DEBT_OVERLAP,
			'contact_id' => 61,
		)
	);
	assert_same( 1, $result['detected_count'], 'Debe detectar un caso de credito + deuda superpuestos.' );
	assert_same( IntegrityCasesRepository::SEVERITY_HIGH, $result['cases'][0]['severity'], 'El choque casi exacto debe salir en severidad alta.' );

	$service = build_monitor_service();
	$result  = $service->scan(
		array(
			'case_type' => IntegrityMonitorService::CASE_DUAL_BATCH_INCOMPLETE,
			'batch_id'  => 48,
		)
	);
	assert_same( 0, $result['detected_count'], 'Un batch con solo error historico pero ya limpio no debe seguir activo.' );

	$service = build_monitor_service();
	$result  = $service->scan(
		array(
			'case_type' => IntegrityMonitorService::CASE_DUAL_BATCH_INCOMPLETE,
			'batch_id'  => 40,
		)
	);
	assert_same( 1, $result['detected_count'], 'Un batch dual con deuda abierta real debe seguir detectandose.' );
	assert_same( IntegrityCasesRepository::SEVERITY_MEDIUM, $result['cases'][0]['severity'], 'Con solo deuda abierta, la severidad debe seguir media.' );

	$service = build_monitor_service();
	$result  = $service->scan(
		array(
			'case_type' => IntegrityMonitorService::CASE_DUAL_BATCH_INCOMPLETE,
			'batch_id'  => 80,
		)
	);
	assert_same( 1, $result['detected_count'], 'Un batch dual con remanente disponible real debe seguir detectandose.' );
	assert_same( IntegrityCasesRepository::SEVERITY_MEDIUM, $result['cases'][0]['severity'], 'Con solo remanente disponible, la severidad debe seguir media.' );

	$service = build_monitor_service();
	$result  = $service->scan(
		array(
			'case_type' => IntegrityMonitorService::CASE_DUAL_BATCH_INCOMPLETE,
			'batch_id'  => 49,
		)
	);
	assert_same( 1, $result['detected_count'], 'Debe detectar un batch dual incompleto.' );
	assert_same( IntegrityCasesRepository::SEVERITY_HIGH, $result['cases'][0]['severity'], 'El batch dual con disponible y deuda equivalente debe salir alta.' );
	assert_same( 2, (int) ( $result['cases'][0]['payload']['error_count'] ?? 0 ), 'El detector debe reflejar los items fallidos del batch.' );

	$service  = build_monitor_service();
	$initial  = $service->scan(
		array(
			'case_type' => IntegrityMonitorService::CASE_DUAL_BATCH_INCOMPLETE,
			'batch_id'  => 49,
		)
	);
	$case_id = (int) $initial['cases'][0]['id'];
	$GLOBALS['ASDL_TEST_DOCUMENTS'][128]['balance']        = 0.00;
	$GLOBALS['ASDL_TEST_DOCUMENTS'][128]['payment_status'] = 'paid';
	$GLOBALS['ASDL_TEST_DOCUMENTS'][129]['balance']        = 0.00;
	$GLOBALS['ASDL_TEST_DOCUMENTS'][129]['payment_status'] = 'paid';
	$GLOBALS['ASDL_TEST_PAYMENTS'][82]['available_amount'] = 0.00;
	$GLOBALS['ASDL_TEST_PAYMENTS'][83]['available_amount'] = 0.00;
	$rescan = $service->rescan_case( $case_id );
	assert_same( false, $rescan['active'], 'Un reescaneo debe resolver el caso cuando el batch ya quedo limpio.' );
	assert_same( IntegrityCasesRepository::STATUS_RESOLVED, $rescan['case']['status'], 'El caso limpio debe terminar como resuelto tras reescaneo.' );

	$service = build_monitor_service();
	$result  = $service->scan(
		array(
			'case_type'  => IntegrityMonitorService::CASE_SMALL_RESIDUAL_OPEN_BALANCE,
			'payment_id' => 84,
		)
	);
	assert_same( 1, $result['detected_count'], 'Debe detectar un residual pequeño disponible.' );
	assert_same( IntegrityCasesRepository::SEVERITY_LOW, $result['cases'][0]['severity'], 'El residual pequeno debe salir baja.' );

	$service = build_monitor_service();
	$result  = $service->scan(
		array(
			'case_type' => IntegrityMonitorService::CASE_CANCELLED_ORDER_COLLECTIBLE,
			'order_id'  => 187373,
		)
	);
	assert_same( 1, $result['detected_count'], 'Debe detectar un pedido cancelado que sigue cobrable.' );
	assert_same( IntegrityCasesRepository::SEVERITY_HIGH, $result['cases'][0]['severity'], 'El pedido cancelado cobrable debe salir alta.' );

	$service = build_monitor_service();
	$result  = $service->scan(
		array(
			'case_type'   => IntegrityMonitorService::CASE_NEAR_ZERO_OPEN_DOCUMENT,
			'document_id' => 201,
		)
	);
	assert_same( 1, $result['detected_count'], 'Debe detectar un documento casi pagado pero abierto.' );
	assert_true( 0.01 === (float) ( $result['cases'][0]['amount'] ?? 0 ), 'El monto del caso near-zero debe conservar el residual real.' );

	echo "Integrity monitor regression passed.\n";
}
