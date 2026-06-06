<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	final class JsonSuccessResponse extends \RuntimeException {
		public $data;
		public $status;

		public function __construct( array $data, $status ) {
			parent::__construct( 'json_success' );
			$this->data   = $data;
			$this->status = $status;
		}
	}

	final class JsonErrorResponse extends \RuntimeException {
		public $data;
		public $status;

		public function __construct( array $data, $status ) {
			parent::__construct( 'json_error' );
			$this->data   = $data;
			$this->status = $status;
		}
	}

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

	function wp_unslash( $value ) {
		return $value;
	}

	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
		return true;
	}

	function current_user_can( $capability ) {
		return true;
	}

	function get_current_user_id() {
		return 77;
	}

	function esc_url_raw( $value ) {
		return (string) $value;
	}

	function wp_send_json_success( $data = null, $status_code = null ) {
		throw new JsonSuccessResponse( is_array( $data ) ? $data : array(), $status_code );
	}

	function wp_send_json_error( $data = null, $status_code = null ) {
		throw new JsonErrorResponse( is_array( $data ) ? $data : array(), $status_code );
	}

	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals, '.', ',' );
	}
}

namespace ASDLabs\Finance\Core\Contracts {
	interface Module {}
}

namespace ASDLabs\Finance\Finance {
	final class EventsRepository {
		public static $logs = array();
		public static $events = array();

		public function log( $entity_type, $entity_id, $event_type, $message, array $payload = array(), $actor_user_id = null ) {
			self::$logs[] = array(
				'entity_type'   => sanitize_key( (string) $entity_type ),
				'entity_id'     => $entity_id ? (int) $entity_id : 0,
				'event_type'    => sanitize_key( (string) $event_type ),
				'message'       => sanitize_text_field( (string) $message ),
				'payload'       => $payload,
				'actor_user_id' => null !== $actor_user_id ? (int) $actor_user_id : get_current_user_id(),
			);

			return count( self::$logs );
		}

		public function for_entity( $entity_type, $entity_id, $limit = 30 ) {
			return array_slice( self::$events, 0, $limit );
		}
	}

	final class OrderSettlementBatchService {
		public static $preview_result;
		public static $start_result;
		public static $status_result;
		public static $continue_result;
		public static $result_result;

		public function preview( array $args, $origin = 'profile_settlement' ) {
			return self::$preview_result;
		}

		public function start( array $args, $origin = 'profile_settlement' ) {
			return self::$start_result;
		}

		public function status( array $args ) {
			return self::$status_result;
		}

		public function continue_batch( $batch_id ) {
			return self::$continue_result;
		}

		public function result( array $args ) {
			return self::$result_result;
		}
	}

	final class OrderSettlementBatchesRepository {
		public function find( $batch_id ) {
			return array(
				'id'         => (int) $batch_id,
				'contact_id' => 465,
			);
		}
	}

	final class RuntimeRefreshService {
		public static function build_dashboard_refresh( array $targets, array $args = array() ) {
			return array(
				'targets' => $targets,
				'args'    => $args,
			);
		}

		public static function merge_runtime_refreshes( array $base, array $extra ) {
			return array_merge( $base, $extra );
		}

		public static function build_profile_refresh( $contact_id, array $targets ) {
			return array(
				'contact_id' => (int) $contact_id,
				'targets'    => $targets,
			);
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Admin/Menu.php';

	use ASDLabs\Finance\Admin\Menu;
	use ASDLabs\Finance\Finance\EventsRepository;
	use ASDLabs\Finance\Finance\OrderSettlementBatchService;

	function reset_audit_regression_state() {
		EventsRepository::$logs = array();
		EventsRepository::$events = array();
		OrderSettlementBatchService::$preview_result  = null;
		OrderSettlementBatchService::$start_result    = null;
		OrderSettlementBatchService::$status_result   = array();
		OrderSettlementBatchService::$continue_result = array();
		OrderSettlementBatchService::$result_result   = array();
		$_POST = array();
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
		$_SERVER['HTTP_REFERER'] = '/wp-admin/admin.php?page=asdl-fin-contacts&contact_id=465';
		$_SERVER['HTTP_USER_AGENT'] = 'Audit Regression Test';
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

	function find_logged_event( $event_type ) {
		foreach ( EventsRepository::$logs as $entry ) {
			if ( sanitize_key( (string) $event_type ) === (string) ( $entry['event_type'] ?? '' ) ) {
				return $entry;
			}
		}

		return null;
	}

	function base_request( array $overrides = array() ) {
		return array_replace(
			array(
				'contact_id'          => 465,
				'origin'              => 'profile_settlement',
				'total'               => '18.62',
				'currency'            => 'USD',
				'method_key'          => 'pago_movil',
				'selection_mode'      => 'oldest_first',
				'dual_discount_mode'  => 'off',
				'force_dual_discount' => '0',
				'preview_signature'   => 'preview_sig_123',
				'client_operation_id' => 'settlement_test_001',
			),
			$overrides
		);
	}

	function expect_ajax_success( callable $callback ) {
		try {
			$callback();
		} catch ( JsonSuccessResponse $response ) {
			return $response;
		}

		throw new \RuntimeException( 'Se esperaba una respuesta JSON success.' );
	}

	function expect_ajax_error( callable $callback ) {
		try {
			$callback();
		} catch ( JsonErrorResponse $response ) {
			return $response;
		}

		throw new \RuntimeException( 'Se esperaba una respuesta JSON error.' );
	}

	reset_audit_regression_state();
	$menu = new Menu();

	OrderSettlementBatchService::$preview_result = array(
		'contact_id'          => 465,
		'preview_signature'   => 'preview_sig_123',
		'execution_mode'      => 'fast_path',
		'execution_blocked'   => false,
		'summary'             => array(
			'covered_total' => 27.00,
		),
	);

	$_POST = base_request();
	expect_ajax_success( static function () use ( $menu ) {
		$menu->ajax_order_settlement_preview();
	} );

	$preview_success = find_logged_event( 'order_settlement_preview_succeeded' );
	assert_true( is_array( $preview_success ), 'El preview exitoso debe registrar evento.' );
	assert_same( 465, $preview_success['entity_id'], 'El preview exitoso debe quedar asociado al contacto.' );
	assert_same( 'settlement_test_001', $preview_success['payload']['client_operation_id'] ?? '', 'El preview debe conservar el client_operation_id.' );

	reset_audit_regression_state();
	$menu = new Menu();
	OrderSettlementBatchService::$preview_result = array(
		'contact_id'          => 465,
		'preview_signature'   => 'preview_sig_blocked',
		'execution_mode'      => 'fast_path',
		'execution_blocked'   => true,
		'execution_blocked_message' => 'El metodo no califica para descuento automatico.',
		'summary'             => array(
			'covered_total' => 0,
		),
	);

	$_POST = base_request(
		array(
			'client_operation_id' => 'settlement_test_blocked',
			'dual_discount_mode'  => 'force',
			'force_dual_discount' => '1',
		)
	);
	expect_ajax_success( static function () use ( $menu ) {
		$menu->ajax_order_settlement_preview();
	} );

	$preview_blocked = find_logged_event( 'order_settlement_preview_succeeded' );
	assert_true( ! empty( $preview_blocked['payload']['execution_blocked'] ), 'El preview bloqueado por configuracion debe quedar marcado.' );
	assert_same( 'El metodo no califica para descuento automatico.', $preview_blocked['payload']['execution_blocked_message'] ?? '', 'El preview bloqueado debe guardar el mensaje exacto.' );

	reset_audit_regression_state();
	$menu = new Menu();
	OrderSettlementBatchService::$start_result = array(
		'contact_id' => 465,
		'job'        => array(
			'batch_id'    => 901,
			'contact_id'  => 465,
			'status'      => 'pending',
		),
	);

	$_POST = base_request(
		array(
			'client_operation_id' => 'settlement_test_start_ok',
		)
	);
	expect_ajax_success( static function () use ( $menu ) {
		$menu->ajax_order_settlement_start();
	} );

	$start_success = find_logged_event( 'order_settlement_start_succeeded' );
	assert_true( is_array( $start_success ), 'El start exitoso debe registrar evento.' );
	assert_same( 465, $start_success['entity_id'], 'El start exitoso debe quedar asociado al contacto.' );
	assert_same( 901, (int) ( $start_success['payload']['batch_id'] ?? 0 ), 'El start exitoso debe conservar el batch_id.' );

	reset_audit_regression_state();
	$menu = new Menu();
	OrderSettlementBatchService::$start_result = new \WP_Error(
		'asdl_fin_order_settlement_preview_signature',
		'La vista previa del abono cambio o ya no es valida. Calculala otra vez antes de confirmar.'
	);

	$_POST = base_request(
		array(
			'client_operation_id' => 'settlement_test_start_fail',
			'preview_signature'   => 'firma_invalida',
		)
	);
	$error_response = expect_ajax_error( static function () use ( $menu ) {
		$menu->ajax_order_settlement_start();
	} );
	assert_same( 400, $error_response->status, 'El start fallido debe responder 400.' );

	$start_failed = find_logged_event( 'order_settlement_start_failed' );
	assert_true( is_array( $start_failed ), 'El start fallido debe registrar evento.' );
	assert_same( 465, $start_failed['entity_id'], 'El start fallido debe quedar asociado al contacto.' );
	assert_same(
		'La vista previa del abono cambio o ya no es valida. Calculala otra vez antes de confirmar.',
		$start_failed['payload']['error_message'] ?? '',
		'El start fallido debe guardar el error exacto.'
	);

	reset_audit_regression_state();
	$menu = new Menu();

	$_POST = base_request(
		array(
			'event_type'          => 'order_settlement_ui_blocked',
			'message'             => 'Los datos del formulario cambiaron despues de la simulacion.',
			'reason'              => 'form_signature_changed',
			'client_operation_id' => 'settlement_test_ui_blocked',
		)
	);
	expect_ajax_success( static function () use ( $menu ) {
		$menu->ajax_order_settlement_trace();
	} );

	$ui_blocked = find_logged_event( 'order_settlement_ui_blocked' );
	assert_true( is_array( $ui_blocked ), 'El bloqueo de UI debe registrar evento.' );
	assert_same( 465, $ui_blocked['entity_id'], 'El bloqueo de UI debe quedar asociado al contacto.' );
	assert_same( 'form_signature_changed', $ui_blocked['payload']['block_reason'] ?? '', 'El bloqueo de UI debe conservar el motivo.' );

	echo "OK\n";
}
