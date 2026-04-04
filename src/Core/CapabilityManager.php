<?php

namespace ASDLabs\Finance\Core;

use ASDLabs\Finance\Core\Contracts\Module;
use WP_User;

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
		return self::user_can_access_mobile( wp_get_current_user() );
	}

	public static function current_user_can_cap( $capability ) {
		return self::user_can_cap( wp_get_current_user(), $capability );
	}

	public static function current_user_permissions() {
		return self::user_permissions( wp_get_current_user() );
	}

	public static function user_can_access_mobile( $user ) {
		return self::user_can_cap( $user, self::ACCESS_MOBILE );
	}

	public static function user_can_cap( $user, $capability ) {
		$user = self::normalize_user( $user );

		if ( ! $user instanceof WP_User || empty( $user->ID ) ) {
			return false;
		}

		if (
			user_can( $user, 'manage_options' ) ||
			user_can( $user, 'manage_woocommerce' ) ||
			user_can( $user, self::MANAGE_FINANCE )
		) {
			return true;
		}

		if ( self::ACCESS_MOBILE === $capability ) {
			return user_can( $user, self::ACCESS_MOBILE );
		}

		if ( ! user_can( $user, self::ACCESS_MOBILE ) ) {
			return false;
		}

		return user_can( $user, $capability );
	}

	public static function user_permissions( $user ) {
		$permissions = array();

		foreach ( self::capability_map() as $capability => $label ) {
			unset( $label );
			$permissions[ $capability ] = self::user_can_cap( $user, $capability );
		}

		return $permissions;
	}

	public static function route_groups_for_permissions( array $permissions ) {
		return array(
			'dashboard'    => ! empty( $permissions[ self::VIEW_DASHBOARD ] ),
			'cash'         => ! empty( $permissions[ self::VIEW_PAYMENTS ] ) || ! empty( $permissions[ self::MANAGE_PAYMENTS ] ),
			'inventory'    => ! empty( $permissions[ self::VIEW_DOCUMENTS ] ),
			'finance'      => ! empty( $permissions[ self::VIEW_DOCUMENTS ] ) || ! empty( $permissions[ self::VIEW_PAYMENTS ] ),
			'profiles'     => ! empty( $permissions[ self::VIEW_PROFILES ] ),
			'audit'        => ! empty( $permissions[ self::VIEW_DASHBOARD ] ),
			'integrations' => ! empty( $permissions[ self::VIEW_INTEGRATIONS ] ),
			'settings'     => ! empty( $permissions[ self::ACCESS_MOBILE ] ),
		);
	}

	private static function normalize_user( $user ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}

		if ( is_numeric( $user ) ) {
			$user = get_user_by( 'id', absint( $user ) );
			return $user instanceof WP_User ? $user : null;
		}

		return null;
	}
}
