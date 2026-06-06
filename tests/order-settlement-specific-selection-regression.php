<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	$GLOBALS['asdl_test_options'] = array(
		'csfx_discount_enabled'    => 1,
		'csfx_discount_percent'    => 31.03,
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
	require_once dirname( __DIR__ ) . '/src/Integrations/Approvals/ApprovalBridge.php';
	require_once dirname( __DIR__ ) . '/src/Finance/OrderSettlementPlannerService.php';

	use ASDLabs\Finance\Finance\OrderSettlementPlannerService;

	function reset_specific_selection_state() {
		$GLOBALS['asdl_test_options'] = array(
			'csfx_discount_enabled'    => 1,
			'csfx_discount_percent'    => 31.03,
			'asdl_fin_payment_methods' => array(),
		);
		$GLOBALS['ASDL_TEST_CONTACTS'] = array(
			61 => array(
				'id'         => 61,
				'wp_user_id' => 1051,
				'email'      => 'alirio@example.com',
			),
		);
		$GLOBALS['ASDL_TEST_ORDERS'] = array();
		$GLOBALS['ASDL_TEST_ORDER_DISCOUNT_SNAPSHOTS'] = array();
		$GLOBALS['ASDL_TEST_HISTORICAL_ROWS'] = array();
	}

	function base_order( array $overrides = array() ) {
		return array_replace(
			array(
				'order_id'            => 209527,
				'order_number'        => '880563541',
				'provider'            => 'openpos',
				'currency'            => 'USD',
				'effective_due_total' => 490.36,
				'is_effectively_open' => true,
				'document_id'         => 77,
				'date_created'        => '2026-04-07 12:56:51',
				'display_name'        => 'Alirio Torres V13863718',
				'billing_email'       => 'alirio@example.com',
				'status'              => 'pending',
				'status_label'        => 'Pendiente de pago',
				'edit_url'            => 'https://example.test/wp-admin/post.php?post=209527&action=edit',
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

	function run_preview_raw( array $args, array $orders ) {
		$GLOBALS['ASDL_TEST_ORDERS'] = $orders;
		$planner                     = new OrderSettlementPlannerService();

		return $planner->preview( $args, 'profile_settlement' );
	}

	function run_preview( array $args, array $orders ) {
		$result = run_preview_raw( $args, $orders );
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

	function assert_wp_error_code( $result, $code, $message ) {
		if ( ! is_wp_error( $result ) ) {
			throw new \RuntimeException( $message . ' | expected WP_Error, got success payload.' );
		}

		assert_same( $code, $result->get_error_code(), $message );
	}

	reset_specific_selection_state();

	$order_primary   = base_order();
	$order_secondary = base_order(
		array(
			'order_id'            => 209668,
			'order_number'        => '880563592',
			'effective_due_total' => 272.00,
			'document_id'         => 128,
			'date_created'        => '2026-04-08 12:56:51',
			'edit_url'            => 'https://example.test/wp-admin/post.php?post=209668&action=edit',
		)
	);
	$primary_key     = item_key_for_order( $order_primary );
	$secondary_key   = item_key_for_order( $order_secondary );

	$single_selected = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 490.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $primary_key ),
		),
		array( $order_primary, $order_secondary )
	);
	assert_same( 'specific', $single_selected['selection_mode'], 'Debe conservar el modo specific.' );
	assert_same( 1, count( $single_selected['items'] ), 'Con un solo pedido seleccionado no debe entrar otro pedido al plan.' );
	assert_same( $order_primary['order_id'], $single_selected['items'][0]['order_id'], 'Solo el pedido marcado debe participar.' );
	assert_float_equals( 490.00, (float) $single_selected['items'][0]['covered_total'], 'El abono parcial debe quedarse solo en el pedido seleccionado.' );
	assert_float_equals( 0.36, (float) $single_selected['items'][0]['remaining_document_balance'], 'El pendiente debe quedar en el mismo pedido seleccionado.' );
	assert_float_equals( 490.00, (float) $single_selected['summary']['covered_total'], 'El total cubierto debe corresponder solo al pedido seleccionado.' );
	assert_same( 2, count( $single_selected['eligible_items'] ), 'La vista puede listar ambos pedidos elegibles.' );

	reset_specific_selection_state();
	$selection_required = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 490.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array(),
		),
		array( $order_primary, $order_secondary )
	);
	assert_true( ! empty( $selection_required['selection_required'] ), 'Specific sin seleccion debe devolver estado bloqueado, no fallback a todos.' );
	assert_same( '', $selection_required['preview_signature'], 'Specific sin seleccion no debe generar firma aplicable.' );
	assert_same( 0, count( $selection_required['items'] ), 'Specific sin seleccion no debe construir items aplicables.' );
	assert_same( 2, count( $selection_required['eligible_items'] ), 'Specific sin seleccion debe seguir mostrando facturas elegibles para marcar.' );

	reset_specific_selection_state();
	$invalid_selection = run_preview_raw(
		array(
			'contact_id'         => 61,
			'total'              => 490.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( 'current_live:openpos:999999:999999' ),
		),
		array( $order_primary, $order_secondary )
	);
	assert_wp_error_code( $invalid_selection, 'asdl_fin_order_settlement_specific_invalid', 'Specific con item_key invalido debe fallar explicitamente.' );

	reset_specific_selection_state();
	$order_third = base_order(
		array(
			'order_id'            => 210862,
			'order_number'        => '880563674',
			'effective_due_total' => 59.14,
			'document_id'         => 129,
			'date_created'        => '2026-04-09 12:56:51',
			'edit_url'            => 'https://example.test/wp-admin/post.php?post=210862&action=edit',
		)
	);
	$third_key = item_key_for_order( $order_third );
	$multi_selected = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 549.14,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $secondary_key, $third_key ),
		),
		array( $order_primary, $order_secondary, $order_third )
	);
	assert_same( 2, count( $multi_selected['items'] ), 'Specific con dos pedidos marcados solo debe incluir esos dos.' );
	assert_same( array( $order_secondary['order_id'], $order_third['order_id'] ), array_column( $multi_selected['items'], 'order_id' ), 'No deben entrar pedidos no seleccionados.' );

	reset_specific_selection_state();
	$create_credit = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 520.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'remainder_policy'   => 'create_credit',
			'selected_item_keys' => array( $primary_key ),
		),
		array( $order_primary, $order_secondary )
	);
	assert_same( 1, count( $create_credit['items'] ), 'Specific con remanente y create_credit no debe tocar otros pedidos.' );
	assert_float_equals( 29.64, (float) $create_credit['summary']['remainder_total'], 'El remanente debe quedar explicito cuando sobra dinero.' );
	assert_float_equals( 520.00, (float) $create_credit['summary']['payment_recorded_total'], 'Con create_credit el pago registrado debe conservar el total recibido.' );
	assert_true( empty( $create_credit['summary']['remainder_consumed_oldest_first'] ), 'Specific no debe consumir remanente por antiguedad automaticamente.' );

	reset_specific_selection_state();
	$discard_remainder = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 520.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'remainder_policy'   => 'discard',
			'selected_item_keys' => array( $primary_key ),
		),
		array( $order_primary, $order_secondary )
	);
	assert_same( 1, count( $discard_remainder['items'] ), 'Specific con discard tampoco debe tocar otros pedidos.' );
	assert_same( 'adjust_payment_total', $discard_remainder['remainder_policy'], 'El alias discard debe normalizarse al ajuste exacto.' );
	assert_float_equals( 29.64, (float) $discard_remainder['summary']['remainder_total'], 'El remanente sigue siendo el mismo en discard.' );
	assert_float_equals( 29.64, (float) $discard_remainder['summary']['remainder_adjusted_total'], 'El ajuste exacto debe marcar el excedente como no registrado.' );
	assert_float_equals( 490.36, (float) $discard_remainder['summary']['payment_recorded_total'], 'Con discard solo debe registrarse lo realmente aplicado.' );

	reset_specific_selection_state();
	$apply_remainder_oldest = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 520.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'remainder_policy'   => 'apply_oldest_first',
			'selected_item_keys' => array( $primary_key ),
		),
		array( $order_primary, $order_secondary )
	);
	assert_same( 2, count( $apply_remainder_oldest['items'] ), 'Specific con apply_oldest_first debe agregar pedidos no seleccionados solo cuando el operador lo pidio.' );
	assert_same( 'selected', $apply_remainder_oldest['items'][0]['selection_origin'], 'El primer tramo debe seguir marcado como seleccion manual.' );
	assert_same( 'specific_remainder_oldest_first', $apply_remainder_oldest['items'][1]['selection_origin'], 'El segundo tramo debe quedar trazado como excedente por antiguedad.' );
	assert_same( $order_secondary['order_id'], $apply_remainder_oldest['items'][1]['order_id'], 'El excedente debe ir al siguiente pedido elegible por antiguedad.' );
	assert_float_equals( 29.64, (float) $apply_remainder_oldest['items'][1]['covered_total'], 'El excedente debe cubrir solo el saldo sobrante.' );
	assert_float_equals( 29.64, (float) $apply_remainder_oldest['summary']['remainder_applied_oldest_first_total'], 'El resumen debe separar el excedente aplicado a otras facturas.' );
	assert_same( 1, (int) $apply_remainder_oldest['summary']['remainder_additional_item_count'], 'El resumen debe contar los pedidos adicionales por excedente.' );
	assert_true( ! empty( $apply_remainder_oldest['summary']['remainder_consumed_oldest_first'] ), 'El resumen debe dejar trazado que el excedente fue consumido por antiguedad.' );
	assert_float_equals( 0.0, (float) $apply_remainder_oldest['summary']['remainder_total'], 'Si el excedente se consume completo no queda remanente.' );
	assert_float_equals( 520.00, (float) $apply_remainder_oldest['summary']['payment_recorded_total'], 'Si todo el monto se aplica, el pago registrado conserva el total recibido.' );

	reset_specific_selection_state();
	$apply_remainder_with_tail = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 900.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'remainder_policy'   => 'apply_oldest_first',
			'selected_item_keys' => array( $primary_key ),
		),
		array( $order_primary, $order_secondary )
	);
	assert_same( 2, count( $apply_remainder_with_tail['items'] ), 'Si no hay mas pedidos, apply_oldest_first no debe inventar items.' );
	assert_float_equals( 137.64, (float) $apply_remainder_with_tail['summary']['remainder_total'], 'Debe quedar visible el excedente que ni los pedidos adicionales consumieron.' );
	assert_float_equals( 137.64, (float) $apply_remainder_with_tail['summary']['remainder_adjusted_total'], 'El excedente final de apply_oldest_first no debe convertirse en saldo a favor implicito.' );
	assert_float_equals( 762.36, (float) $apply_remainder_with_tail['summary']['payment_recorded_total'], 'Cuando aun sobra tras aplicar a otras facturas, solo debe registrarse lo realmente aplicado.' );

	reset_specific_selection_state();
	$oldest_first = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 520.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'oldest_first',
		),
		array( $order_primary, $order_secondary )
	);
	assert_same( 2, count( $oldest_first['items'] ), 'oldest_first debe seguir repartiendo por antiguedad.' );
	assert_same( $order_primary['order_id'], $oldest_first['items'][0]['order_id'], 'oldest_first debe empezar por el pedido mas viejo.' );
	assert_same( $order_secondary['order_id'], $oldest_first['items'][1]['order_id'], 'oldest_first debe continuar con el siguiente pedido elegible.' );
	assert_float_equals( 29.64, (float) $oldest_first['items'][1]['covered_total'], 'oldest_first debe seguir usando el remanente en el siguiente pedido.' );

	reset_specific_selection_state();
	$signature_one = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 490.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $primary_key ),
		),
		array( $order_primary, $order_secondary )
	);
	$signature_two = run_preview(
		array(
			'contact_id'         => 61,
			'total'              => 490.00,
			'currency'           => 'USD',
			'method_key'         => 'mobile_payment',
			'dual_discount_mode' => 'off',
			'selection_mode'     => 'specific',
			'selected_item_keys' => array( $secondary_key ),
		),
		array( $order_primary, $order_secondary )
	);
	assert_true( $signature_one['preview_signature'] !== $signature_two['preview_signature'], 'La preview_signature debe cambiar cuando cambia la seleccion especifica.' );

	echo "Order settlement specific selection regression checks passed.\n";
}
