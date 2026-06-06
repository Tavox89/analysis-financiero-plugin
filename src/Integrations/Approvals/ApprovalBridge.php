<?php

namespace ASDLabs\Finance\Integrations\Approvals;

use ASDLabs\Finance\Core\CapabilityManager;
use WP_Error;

final class ApprovalBridge {
	const TARGET_PLUGIN = 'analysis-financiero-plugin';

	const ACTION_EXTRAORDINARY_ORDER_CLOSURE        = 'finance.extraordinary_order_closure.execute';
	const ACTION_HISTORICAL_PREVIOUS_YEAR_SPECIAL   = 'finance.historical_resolution.previous_year_special.execute';
	const ACTION_PAYROLL_COMMITMENT_OVERRIDE        = 'finance.payroll.commitment_override.execute';

	public function plugin_available() {
		return class_exists( '\ASDL_OA_Approvals' ) && class_exists( '\ASDL_OA_Approval_Policies' );
	}

	public function action_keys() {
		return array(
			'extraordinaryOrderClosure'      => self::ACTION_EXTRAORDINARY_ORDER_CLOSURE,
			'historicalPreviousYearSpecial'  => self::ACTION_HISTORICAL_PREVIOUS_YEAR_SPECIAL,
			'payrollCommitmentOverride'      => self::ACTION_PAYROLL_COMMITMENT_OVERRIDE,
		);
	}

	public function register_policies() {
		if ( ! $this->plugin_available() ) {
			return;
		}

		foreach ( $this->policies() as $action_key => $policy ) {
			\ASDL_OA_Approval_Policies::register_action( $action_key, $policy );
		}
	}

	public function can_prepare_extraordinary_order_closure() {
		return $this->current_user_can_visible_action( self::ACTION_EXTRAORDINARY_ORDER_CLOSURE );
	}

	public function can_prepare_historical_previous_year_special() {
		return $this->current_user_can_visible_action( self::ACTION_HISTORICAL_PREVIOUS_YEAR_SPECIAL );
	}

	public function can_prepare_payroll_commitment_override() {
		return $this->current_user_can_visible_action( self::ACTION_PAYROLL_COMMITMENT_OVERRIDE );
	}

	public function evaluate_gate( $action_key, array $args = array() ) {
		$policy = $this->policies()[ $action_key ] ?? array();
		$require_approval_for_bypass_users = ! empty( $policy['require_approval_for_bypass_users'] );
		$raw_can_bypass = $this->current_user_can_any( (array) ( $policy['bypass_capabilities'] ?? array( 'manage_options' ) ) );
		$gate   = array(
			'action_key'          => sanitize_key( (string) $action_key ),
			'plugin_available'    => $this->plugin_available(),
			'requires_approval'   => false,
			'can_bypass'          => $raw_can_bypass && ! $require_approval_for_bypass_users,
			'allow_self_approval' => ! empty( $policy['allow_self_approval'] ),
			'actor_user_id'       => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'eligible_approvers'  => array(),
			'token_ttl_seconds'   => (int) ( $policy['token_ttl_seconds'] ?? 300 ),
			'message'             => '',
			'single_actor_approver' => false,
		);

		if ( ! $this->plugin_available() ) {
			if ( $this->current_user_can_visible_action( $action_key ) ) {
				$gate['requires_approval'] = true;
				$gate['message']           = 'ASD Labs Operational Approvals no esta disponible. Esta accion sensible requiere validacion TOTP para ejecutarse.';
			}

			return $gate;
		}

		$evaluation = \ASDL_OA_Approvals::evaluate_action( $action_key, $args );
		if ( is_wp_error( $evaluation ) ) {
			$gate['message'] = $evaluation->get_error_message();
			return $gate;
		}

		$gate['requires_approval']   = ! empty( $evaluation['requires_approval'] );
		$gate['can_bypass']          = ! empty( $evaluation['can_bypass'] );
		$gate['allow_self_approval'] = ! empty( $evaluation['allow_self_approval'] );
		$gate['actor_user_id']       = (int) ( $evaluation['actor_user_id'] ?? $gate['actor_user_id'] );
		$gate['eligible_approvers']  = array_values( (array) ( $evaluation['eligible_approvers'] ?? array() ) );
		$gate['token_ttl_seconds']   = (int) ( $evaluation['token_ttl_seconds'] ?? $gate['token_ttl_seconds'] );
		$gate['single_actor_approver'] = 1 === count( $gate['eligible_approvers'] )
			&& (int) ( $gate['eligible_approvers'][0]['id'] ?? 0 ) === (int) $gate['actor_user_id'];

		if ( $require_approval_for_bypass_users && $this->current_user_can_visible_action( $action_key ) ) {
			$gate['requires_approval'] = true;
			$gate['can_bypass']        = false;
		}

		if ( $gate['requires_approval'] ) {
			$gate['message'] = ! empty( $gate['eligible_approvers'] )
				? 'Valida esta accion sensible con tu autenticador antes de ejecutarla.'
				: 'No hay aprobadores con TOTP enrolado que cumplan esta politica operativa.';
		}

		return $gate;
	}

	public function authorize_execution( $action_key, array $args = array() ) {
		$policy = $this->policies()[ $action_key ] ?? array();
		$require_approval_for_bypass_users = ! empty( $policy['require_approval_for_bypass_users'] );
		if ( $this->plugin_available() ) {
			if ( $require_approval_for_bypass_users && '' === sanitize_text_field( (string) ( $args['approval_token'] ?? '' ) ) ) {
				return new WP_Error(
					'approval_token_required',
					'Esta accion requiere validacion TOTP incluso para administradores.'
				);
			}

			$authorization = \ASDL_OA_Approvals::authorize_execution( $action_key, $args );
			if (
				$require_approval_for_bypass_users
				&& is_array( $authorization )
				&& 'bypass' === sanitize_key( (string) ( $authorization['mode'] ?? '' ) )
			) {
				return new WP_Error(
					'approval_token_required',
					'Esta accion requiere una aprobacion TOTP valida; el bypass administrativo no aplica.'
				);
			}

			return $authorization;
		}

		if ( empty( $policy['require_approval_for_bypass_users'] ) && $this->current_user_can_any( (array) ( $policy['bypass_capabilities'] ?? array( 'manage_options' ) ) ) ) {
			return array(
				'action_key'    => sanitize_key( (string) $action_key ),
				'actor_user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'mode'          => 'bypass',
				'plugin_active' => false,
			);
		}

		return new WP_Error(
			'asdl_fin_operational_approval_unavailable',
			'ASD Labs Operational Approvals no esta disponible para validar esta accion sensible. Activa el plugin de aprobaciones para continuar.'
		);
	}

	public function summarize_authorization( $authorization ) {
		if ( is_wp_error( $authorization ) || ! is_array( $authorization ) ) {
			return array();
		}

		$approval_mode = sanitize_key( (string) ( $authorization['mode'] ?? '' ) );
		if ( 'approval' === $approval_mode ) {
			$approval_mode = (int) ( $authorization['approver_user_id'] ?? 0 ) === (int) ( $authorization['actor_user_id'] ?? 0 )
				? 'self'
				: 'delegated';
		}

		return array(
			'action_key'       => sanitize_key( (string) ( $authorization['action_key'] ?? '' ) ),
			'approval_mode'    => $approval_mode,
			'approval_uuid'    => sanitize_text_field( (string) ( $authorization['approval_uuid'] ?? '' ) ),
			'approval_id'      => isset( $authorization['approval_id'] ) ? (int) $authorization['approval_id'] : 0,
			'actor_user_id'    => isset( $authorization['actor_user_id'] ) ? (int) $authorization['actor_user_id'] : 0,
			'approver_user_id' => isset( $authorization['approver_user_id'] ) ? (int) $authorization['approver_user_id'] : 0,
			'payload_hash'     => sanitize_text_field( (string) ( $authorization['payload_hash'] ?? '' ) ),
			'expires_at'       => sanitize_text_field( (string) ( $authorization['expires_at'] ?? '' ) ),
			'plugin_active'    => array_key_exists( 'plugin_active', $authorization ) ? ! empty( $authorization['plugin_active'] ) : null,
		);
	}

	public function build_extraordinary_order_closure_payload( array $args, array $preview = array() ) {
		$batch_payload        = isset( $preview['batch_payload'] ) && is_array( $preview['batch_payload'] ) ? $preview['batch_payload'] : array();
		$summary              = isset( $preview['summary'] ) && is_array( $preview['summary'] ) ? $preview['summary'] : array();
		$extraordinary        = isset( $preview['extraordinary_closure'] ) && is_array( $preview['extraordinary_closure'] ) ? $preview['extraordinary_closure'] : array();
		$selected_item_keys   = $batch_payload['selected_item_keys'] ?? ( $preview['selected_item_keys'] ?? array() );

		if ( ! is_array( $selected_item_keys ) ) {
			$selected_item_keys = preg_split( '/\s*,\s*/', (string) $selected_item_keys );
		}

		$selected_item_keys = array_values(
			array_filter(
				array_map(
					static function ( $item_key ) {
						return sanitize_text_field( (string) $item_key );
					},
					(array) $selected_item_keys
				)
			)
		);

		return array(
			'contact_id'                   => (int) ( $preview['contact_id'] ?? $args['contact_id'] ?? 0 ),
			'origin'                       => sanitize_key( (string) ( $batch_payload['origin'] ?? $args['origin'] ?? 'profile_settlement' ) ),
			'preview_signature'            => sanitize_text_field( (string) ( $preview['preview_signature'] ?? $args['preview_signature'] ?? '' ) ),
			'selection_mode'               => sanitize_key( (string) ( $batch_payload['selection_mode'] ?? $args['selection_mode'] ?? '' ) ),
			'selected_item_keys'           => $selected_item_keys,
			'selected_order_id'            => (int) ( $extraordinary['selected_order_id'] ?? 0 ),
			'selected_order_label'         => sanitize_text_field( (string) ( $extraordinary['selected_order_label'] ?? '' ) ),
			'currency'                     => sanitize_text_field( (string) ( $preview['currency'] ?? $batch_payload['currency'] ?? $args['currency'] ?? 'USD' ) ),
			'method_key'                   => sanitize_key( (string) ( $batch_payload['method_key'] ?? $args['method_key'] ?? '' ) ),
			'payment_recorded_total'       => round( (float) ( $summary['payment_recorded_total'] ?? 0 ), 6 ),
			'extraordinary_closure_total'  => round( (float) ( $summary['extraordinary_closure_total'] ?? 0 ), 6 ),
			'extraordinary_reason'         => sanitize_key( (string) ( $batch_payload['extraordinary_closure_reason'] ?? $args['extraordinary_closure_reason'] ?? '' ) ),
			'extraordinary_reason_label'   => sanitize_text_field( (string) ( $batch_payload['extraordinary_closure_reason_label'] ?? $extraordinary['reason_label'] ?? '' ) ),
			'approval_reference'           => sanitize_text_field( (string) ( $batch_payload['extraordinary_closure_approval_reference'] ?? $args['extraordinary_closure_approval_reference'] ?? '' ) ),
			'note'                         => sanitize_textarea_field( (string) ( $batch_payload['extraordinary_closure_note'] ?? $args['extraordinary_closure_note'] ?? '' ) ),
		);
	}

	public function build_historical_previous_year_payload( array $args ) {
		return array(
			'fiscal_year_from'     => absint( $args['fiscal_year_from'] ?? 0 ),
			'fiscal_year_to'       => absint( $args['fiscal_year_to'] ?? 0 ),
			'contact_id'           => absint( $args['contact_id'] ?? 0 ),
			'provider'             => sanitize_key( (string) ( $args['provider'] ?? 'all' ) ),
			'search'               => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'reason_key'           => sanitize_key( (string) ( $args['reason_key'] ?? '' ) ),
			'note'                 => sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ),
			'special_previous_year'=> ! empty( $args['special_previous_year'] ),
			'only_without_paid'    => ! empty( $args['only_without_paid'] ),
			'min_balance'          => isset( $args['min_balance'] ) && '' !== (string) $args['min_balance'] ? round( (float) $args['min_balance'], 6 ) : null,
			'max_balance'          => isset( $args['max_balance'] ) && '' !== (string) $args['max_balance'] ? round( (float) $args['max_balance'], 6 ) : null,
			'selected_row_ids'     => $this->sanitize_id_list( $args['selected_row_ids'] ?? array() ),
		);
	}

	public function build_payroll_commitment_override_payload( array $args ) {
		$actions = $this->sanitize_payroll_override_actions( $args['actions'] ?? array() );

		return array(
			'contact_id'              => absint( $args['contact_id'] ?? 0 ),
			'payroll_id'              => absint( $args['payroll_id'] ?? 0 ),
			'scheduled_payment_date'  => sanitize_text_field( (string) ( $args['scheduled_payment_date'] ?? '' ) ),
			'currency'                => sanitize_text_field( (string) ( $args['currency'] ?? 'USD' ) ),
			'override_reason'         => sanitize_textarea_field( (string) ( $args['override_reason'] ?? '' ) ),
			'actions'                 => $actions,
		);
	}

	private function policies() {
		$collections_caps = $this->collections_caps();
		$payroll_caps     = $this->payroll_caps();

		return array(
			self::ACTION_EXTRAORDINARY_ORDER_CLOSURE => array(
				'label'                         => 'Cierre extraordinario de pedido',
				'description'                   => 'Extingue administrativamente la diferencia restante de un pedido especifico desde el perfil.',
				'plugin_source'                 => self::TARGET_PLUGIN,
				'bypass_capabilities'           => array( 'manage_options' ),
				'require_approval_for_bypass_users' => true,
				'visible_to_caps'               => $collections_caps,
				'required_approver_capabilities'=> $collections_caps,
				'token_ttl_seconds'             => 300,
				'requires_reason'               => true,
				'audit_enabled'                 => true,
				'allow_self_approval'           => true,
				'execution_mode'                => 'interactive_token',
			),
			self::ACTION_HISTORICAL_PREVIOUS_YEAR_SPECIAL => array(
				'label'                         => 'Caso especial de cierre historico del ejercicio inmediatamente anterior',
				'description'                   => 'Autoriza un cierre administrativo historico que incluye el ejercicio inmediatamente anterior.',
				'plugin_source'                 => self::TARGET_PLUGIN,
				'bypass_capabilities'           => array( 'manage_options' ),
				'require_approval_for_bypass_users' => true,
				'visible_to_caps'               => $collections_caps,
				'required_approver_capabilities'=> $collections_caps,
				'token_ttl_seconds'             => 300,
				'requires_reason'               => true,
				'audit_enabled'                 => true,
				'allow_self_approval'           => true,
				'execution_mode'                => 'interactive_token',
			),
			self::ACTION_PAYROLL_COMMITMENT_OVERRIDE => array(
				'label'                         => 'Ajuste operativo de compromiso en nomina',
				'description'                   => 'Omite o rueda individualmente una cuota prevista dentro de la nomina antes de procesar el pago.',
				'plugin_source'                 => self::TARGET_PLUGIN,
				'bypass_capabilities'           => array( 'manage_options' ),
				'require_approval_for_bypass_users' => true,
				'visible_to_caps'               => $payroll_caps,
				'required_approver_capabilities'=> $payroll_caps,
				'token_ttl_seconds'             => 300,
				'requires_reason'               => true,
				'audit_enabled'                 => true,
				'allow_self_approval'           => true,
				'execution_mode'                => 'interactive_token',
			),
		);
	}

	private function collections_caps() {
		$caps = array( 'manage_options', 'manage_woocommerce' );

		if ( class_exists( '\ASDLabs\Finance\Core\CapabilityManager' ) ) {
			$caps[] = CapabilityManager::MANAGE_COLLECTIONS;
		}

		return array_values( array_unique( array_filter( $caps ) ) );
	}

	private function payroll_caps() {
		$caps = array( 'manage_options', 'manage_woocommerce' );

		if ( class_exists( '\ASDLabs\Finance\Core\CapabilityManager' ) ) {
			$caps[] = CapabilityManager::MANAGE_PAYROLL;
		}

		return array_values( array_unique( array_filter( $caps ) ) );
	}

	private function current_user_can_visible_action( $action_key ) {
		$policy = $this->policies()[ $action_key ] ?? array();
		$caps   = (array) ( $policy['visible_to_caps'] ?? array() );

		if ( empty( $caps ) ) {
			return true;
		}

		return $this->current_user_can_any( $caps );
	}

	private function current_user_can_any( array $caps ) {
		if ( ! function_exists( 'current_user_can' ) ) {
			return true;
		}

		foreach ( $caps as $cap ) {
			if ( current_user_can( sanitize_key( (string) $cap ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function sanitize_id_list( $raw ) {
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'absint', $raw ) ) );
		}

		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					'absint',
					preg_split( '/\s*,\s*/', $raw )
				)
			)
		);
	}

	private function sanitize_payroll_override_actions( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$actions = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$action = sanitize_key( (string) ( $entry['action'] ?? '' ) );
			if ( ! in_array( $action, array( 'skip_once', 'defer_next_cycle' ), true ) ) {
				continue;
			}

			$direction = sanitize_key( (string) ( $entry['settlement_direction'] ?? 'receivable' ) );
			if ( ! in_array( $direction, array( 'receivable', 'payable' ), true ) ) {
				$direction = 'receivable';
			}

			$actions[] = array(
				'plan_id'              => absint( $entry['plan_id'] ?? 0 ),
				'installment_id'       => absint( $entry['installment_id'] ?? 0 ),
				'settlement_direction' => $direction,
				'action'               => $action,
			);
		}

		usort(
			$actions,
			static function ( array $left, array $right ) {
				$left_installment  = (int) ( $left['installment_id'] ?? 0 );
				$right_installment = (int) ( $right['installment_id'] ?? 0 );
				if ( $left_installment !== $right_installment ) {
					return $left_installment <=> $right_installment;
				}

				$left_plan  = (int) ( $left['plan_id'] ?? 0 );
				$right_plan = (int) ( $right['plan_id'] ?? 0 );
				if ( $left_plan !== $right_plan ) {
					return $left_plan <=> $right_plan;
				}

				$left_direction  = (string) ( $left['settlement_direction'] ?? '' );
				$right_direction = (string) ( $right['settlement_direction'] ?? '' );

				return strcmp( $left_direction, $right_direction );
			}
		);

		return $actions;
	}
}
