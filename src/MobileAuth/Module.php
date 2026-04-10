<?php

namespace ASDLabs\Finance\MobileAuth;

use ASDLabs\Finance\Core\CapabilityManager;
use ASDLabs\Finance\Core\Contracts\Module as ModuleContract;
use WP_Error;

final class Module implements ModuleContract {
	private static $current_session = null;
	private static $auth_error = null;
	private static $resolving_user = false;

	public function register() {
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 5 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ), 5 );
	}

	public function determine_current_user( $user_id ) {
		if ( self::$resolving_user ) {
			return $user_id;
		}

		self::$resolving_user = true;

		try {
			self::$current_session = null;
			self::$auth_error      = null;

			if ( ! $this->is_canonical_request() ) {
				return $user_id;
			}

			if ( ! empty( $user_id ) ) {
				return $user_id;
			}

			$access_token = $this->bearer_token();
			if ( '' === $access_token ) {
				return $user_id;
			}

			$repository = new MobileSessionRepository();
			$session    = $repository->find_active_by_access_token( $access_token );
			if ( empty( $session['id'] ) ) {
				self::$auth_error = new WP_Error( 'clubsams_control_unauthorized', 'No existe una sesion movil valida para este dispositivo.', array( 'status' => 401 ) );
				return $user_id;
			}

			$token_service = new TokenService();
			if ( $token_service->is_expired( $session['access_expires_at'] ?? '' ) ) {
				$repository->revoke_session( $session['id'], 'access_expired' );
				self::$auth_error = new WP_Error( 'clubsams_control_unauthorized', 'El access token expiro. Debes refrescar o iniciar sesion otra vez.', array( 'status' => 401 ) );
				return $user_id;
			}

			$user = get_user_by( 'id', (int) ( $session['user_id'] ?? 0 ) );
			if ( ! $user || ! CapabilityManager::user_can_access_mobile( $user ) ) {
				$repository->revoke_session( $session['id'], 'user_without_access' );
				self::$auth_error = new WP_Error( 'clubsams_control_forbidden', 'El usuario ya no tiene acceso movil.', array( 'status' => 403 ) );
				return $user_id;
			}

			if ( ! empty( $session['auth_state_hash'] ) && $session['auth_state_hash'] !== $token_service->auth_state_hash( $user ) ) {
				$repository->revoke_session( $session['id'], 'credentials_changed' );
				self::$auth_error = new WP_Error( 'clubsams_control_unauthorized', 'La sesion fue invalidada por un cambio de credenciales.', array( 'status' => 401 ) );
				return $user_id;
			}

			$repository->touch_session( $session['id'], $this->request_context() );
			self::$current_session = $repository->find( $session['id'] );
			$this->clear_authorization_headers();

			// Evita reentrada del auth movil cuando terceros llaman
			// wp_get_current_user() dentro de determine_current_user().
			wp_set_current_user( (int) $user->ID );

			return (int) $user->ID;
		} finally {
			self::$resolving_user = false;
		}
	}

	public function rest_authentication_errors( $result ) {
		if ( ! $this->is_canonical_request() ) {
			return $result;
		}

		if ( self::$auth_error instanceof WP_Error ) {
			return self::$auth_error;
		}

		if ( ! empty( self::$current_session['id'] ) ) {
			return null;
		}

		// Evita que otros plugins de auth interfieran con clubsams-control/v1.
		return null;
	}

	public static function current_session() {
		return is_array( self::$current_session ) ? self::$current_session : null;
	}

	public static function auth_error() {
		return self::$auth_error;
	}

	private function is_canonical_request() {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$needle      = '/' . rest_get_url_prefix() . '/clubsams-control/v1/';

		return false !== strpos( $request_uri, $needle );
	}

	private function bearer_token() {
		$custom_header = '';

		foreach ( array( 'HTTP_X_CLUBSAMS_ACCESS_TOKEN', 'REDIRECT_HTTP_X_CLUBSAMS_ACCESS_TOKEN' ) as $server_key ) {
			if ( ! empty( $_SERVER[ $server_key ] ) ) {
				$custom_header = sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
				break;
			}
		}

		if ( '' === $custom_header && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) && ! empty( $headers['X-Clubsams-Access-Token'] ) ) {
				$custom_header = sanitize_text_field( (string) $headers['X-Clubsams-Access-Token'] );
			}
		}

		if ( '' !== $custom_header ) {
			return trim( $custom_header );
		}

		$header = '';

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $server_key ) {
			if ( ! empty( $_SERVER[ $server_key ] ) ) {
				$header = sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
				break;
			}
		}

		if ( '' === $header && function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) && ! empty( $headers['Authorization'] ) ) {
				$header = sanitize_text_field( (string) $headers['Authorization'] );
			}
		}

		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}

	private function request_context() {
		return array(
			'ip'         => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
		);
	}

	private function clear_authorization_headers() {
		foreach ( array( 'HTTP_X_CLUBSAMS_ACCESS_TOKEN', 'REDIRECT_HTTP_X_CLUBSAMS_ACCESS_TOKEN', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW' ) as $server_key ) {
			if ( isset( $_SERVER[ $server_key ] ) ) {
				unset( $_SERVER[ $server_key ] );
			}
		}
	}
}
