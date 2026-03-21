<?php

namespace ASDLabs\Finance\Core;

use ASDLabs\Finance\Core\Contracts\Module;

final class CapabilityManager implements Module {
	const OPTION_VERSION          = 'asdl_fin_capabilities_version';
	const VERSION                 = '2026.03.14-mobile-phase1';
	const ACCESS_MOBILE           = 'asdl_fin_access_mobile';
	const MANAGE_FINANCE          = 'asdl_fin_manage_finance';
	const VIEW_DASHBOARD          = 'asdl_fin_view_dashboard';
	const VIEW_ACCOUNTS           = 'asdl_fin_view_accounts';
	const MANAGE_ACCOUNTS         = 'asdl_fin_manage_accounts';
	const VIEW_PROFILES           = 'asdl_fin_view_profiles';
	const MANAGE_PROFILES         = 'asdl_fin_manage_profiles';
	const VIEW_DOCUMENTS          = 'asdl_fin_view_documents';
	const MANAGE_DOCUMENTS        = 'asdl_fin_manage_documents';
	const VIEW_PAYMENTS           = 'asdl_fin_view_payments';
	const MANAGE_PAYMENTS         = 'asdl_fin_manage_payments';
	const MANAGE_COLLECTIONS      = 'asdl_fin_manage_collections';
	const VIEW_COMMITMENTS        = 'asdl_fin_view_commitments';
	const MANAGE_COMMITMENTS      = 'asdl_fin_manage_commitments';
	const VIEW_PAYROLL            = 'asdl_fin_view_payroll';
	const MANAGE_PAYROLL          = 'asdl_fin_manage_payroll';
	const VIEW_INTEGRATIONS       = 'asdl_fin_view_integrations';
	const MANAGE_AUTOMATIONS      = 'asdl_fin_manage_automations';

	public function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function activate() {
		self::install();
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_VERSION, '' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install() {
		$roles = array( 'administrator', 'shop_manager' );

		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( array_keys( self::capability_map() ) as $capability ) {
				$role->add_cap( $capability );
			}
		}

		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	public static function capability_map() {
		return array(
			self::ACCESS_MOBILE      => 'Acceso base a la API movil interna.',
			self::MANAGE_FINANCE     => 'Control total del backend financiero.',
			self::VIEW_DASHBOARD     => 'Consulta dashboard, resumenes y estado general.',
			self::VIEW_ACCOUNTS      => 'Consulta cuentas financieras.',
			self::MANAGE_ACCOUNTS    => 'Crea y edita cuentas financieras.',
			self::VIEW_PROFILES      => 'Consulta perfiles y detalle financiero.',
			self::MANAGE_PROFILES    => 'Crea y edita perfiles financieros.',
			self::VIEW_DOCUMENTS     => 'Consulta movimientos financieros.',
			self::MANAGE_DOCUMENTS   => 'Crea y actualiza movimientos financieros.',
			self::VIEW_PAYMENTS      => 'Consulta cobros, pagos y asignaciones.',
			self::MANAGE_PAYMENTS    => 'Registra cobros, pagos y asignaciones.',
			self::MANAGE_COLLECTIONS => 'Opera cobranza por perfil y cruces de saldo.',
			self::VIEW_COMMITMENTS   => 'Consulta compromisos y acuerdos.',
			self::MANAGE_COMMITMENTS => 'Crea y opera compromisos y acuerdos.',
			self::VIEW_PAYROLL       => 'Consulta nomina, adelantos y fichas laborales.',
			self::MANAGE_PAYROLL     => 'Gestiona nomina, adelantos y fichas laborales.',
			self::VIEW_INTEGRATIONS  => 'Consulta integraciones y estado de sincronizacion.',
			self::MANAGE_AUTOMATIONS => 'Gestiona automatizaciones y reglas.',
		);
	}

	public static function current_user_can_access_mobile() {
		return self::current_user_can_cap( self::ACCESS_MOBILE );
	}

	public static function current_user_can_cap( $capability ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if (
			current_user_can( 'manage_options' ) ||
			current_user_can( 'manage_woocommerce' ) ||
			current_user_can( self::MANAGE_FINANCE )
		) {
			return true;
		}

		if ( self::ACCESS_MOBILE === $capability ) {
			return current_user_can( self::ACCESS_MOBILE );
		}

		if ( ! current_user_can( self::ACCESS_MOBILE ) ) {
			return false;
		}

		return current_user_can( $capability );
	}

	public static function current_user_permissions() {
		$permissions = array();

		foreach ( self::capability_map() as $capability => $label ) {
			unset( $label );
			$permissions[ $capability ] = self::current_user_can_cap( $capability );
		}

		return $permissions;
	}
}
