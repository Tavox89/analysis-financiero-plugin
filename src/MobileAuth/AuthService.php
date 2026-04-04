<?php

namespace ASDLabs\Finance\MobileAuth;

use ASDLabs\Finance\Core\CapabilityManager;
use ASDLabs\Finance\Finance\EventsRepository;
use WP_Error;
use WP_User;

final class AuthService {
	private $sessions;
	private $tokens;
	private $events;
	private $rate_limiter;

	public function __construct() {
		$this->sessions     = new MobileSessionRepository();
		$this->tokens       = new TokenService();
		$this->events       = new EventsRepository();
		$this->rate_limiter = new RateLimiter();
	}

	public function login( array $payload, array $context = array() ) {
		$username = sanitize_user( (string) ( $payload['username'] ?? '' ), true );
		$password = (string) ( $payload['password'] ?? '' );

		if ( '' === $username || '' === $password ) {
			return new WP_Error( 'clubsams_control_validation_error', 'Debes indicar usuario y contrasena para iniciar sesion.', array( 'status' => 400 ) );
		}

		$rate_subject = strtolower( $username ) . '|' . sanitize_text_field( (string) ( $context['ip'] ?? '' ) );
		$rate_guard   = $this->rate_limiter->ensure_not_limited( 'login', $rate_subject, 10, 900 );
		if ( is_wp_error( $rate_guard ) ) {
			return $rate_guard;
		}

		$user = wp_authenticate( $username, $password );
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			$this->rate_limiter->register_failure( 'login', $rate_subject, 900 );
			$this->record_event( 'failed_login', 'Intento fallido de login movil.', array( 'username' => $username, 'origin' => 'clubsams-control/v1' ) );
			return new WP_Error( 'clubsams_control_unauthorized', 'Credenciales invalidas para el acceso movil.', array( 'status' => 401 ) );
		}

		if ( ! CapabilityManager::user_can_access_mobile( $user ) ) {
			$this->rate_limiter->register_failure( 'login', $rate_subject, 900 );
			$this->record_event(
				'failed_login',
				'Usuario sin acceso movil intento autenticarse.',
				array(
					'user_id'  => (int) $user->ID,
					'username' => $username,
					'origin'   => 'clubsams-control/v1',
				),
				(int) $user->ID
			);
			return new WP_Error( 'clubsams_control_forbidden', 'El usuario no tiene acceso al backend movil.', array( 'status' => 403 ) );
		}

		$token_pair = $this->tokens->issue_token_pair( $user );
		$device     = $this->build_device_context( $payload, $context );
		$session_id = $this->sessions->create_session( $user, $device, $token_pair );
		if ( is_wp_error( $session_id ) ) {
			return $session_id;
		}

		$session = $this->sessions->find( $session_id );
		$this->rate_limiter->clear( 'login', $rate_subject );
		$this->record_event(
			'login',
			'Sesion movil iniciada correctamente.',
			array(
				'session_id'  => (int) $session_id,
				'device_name' => $device['device_name'],
				'platform'    => $device['platform'],
				'app_version' => $device['app_version'],
			),
			(int) $user->ID,
			(int) $session_id
		);

		return $this->build_authenticated_payload( $user, $session, $token_pair, 'auth_login' );
	}

	public function refresh( array $payload, array $context = array() ) {
		$refresh_token = sanitize_text_field( (string) ( $payload['refresh_token'] ?? '' ) );
		if ( '' === $refresh_token ) {
			return new WP_Error( 'clubsams_control_validation_error', 'Debes indicar el refresh token de la sesion.', array( 'status' => 400 ) );
		}

		$rate_subject = $refresh_token . '|' . sanitize_text_field( (string) ( $context['ip'] ?? '' ) );
		$rate_guard   = $this->rate_limiter->ensure_not_limited( 'refresh', $rate_subject, 20, 900 );
		if ( is_wp_error( $rate_guard ) ) {
			return $rate_guard;
		}

		$session = $this->sessions->find_active_by_refresh_token( $refresh_token );
		if ( empty( $session['id'] ) ) {
			$this->rate_limiter->register_failure( 'refresh', $rate_subject, 900 );
			return new WP_Error( 'clubsams_control_unauthorized', 'La sesion movil ya no es valida para refrescar tokens.', array( 'status' => 401 ) );
		}

		if ( $this->tokens->is_expired( $session['refresh_expires_at'] ?? '' ) ) {
			$this->sessions->revoke_session( $session['id'], 'refresh_expired' );
			$this->rate_limiter->register_failure( 'refresh', $rate_subject, 900 );
			return new WP_Error( 'clubsams_control_unauthorized', 'El refresh token expiro. Debes iniciar sesion de nuevo.', array( 'status' => 401 ) );
		}

		$user = get_user_by( 'id', (int) ( $session['user_id'] ?? 0 ) );
		$guard = $this->ensure_mobile_user( $user, $session );
		if ( is_wp_error( $guard ) ) {
			$this->sessions->revoke_session( $session['id'], 'user_invalidated' );
			return $guard;
		}

		$token_pair = $this->tokens->issue_token_pair( $user );
		$device     = $this->build_device_context( $payload, $context, $session );
		$this->sessions->rotate_session_tokens( $session['id'], $token_pair, $device );
		$session = $this->sessions->find( $session['id'] );
		$this->rate_limiter->clear( 'refresh', $rate_subject );
		$this->record_event(
			'refresh',
			'Tokens moviles renovados correctamente.',
			array(
				'session_id' => (int) $session['id'],
				'platform'   => $device['platform'],
			),
			(int) $user->ID,
			(int) $session['id']
		);

		return $this->build_authenticated_payload( $user, $session, $token_pair, 'auth_refresh' );
	}

	public function logout( array $session ) {
		$session_id = (int) ( $session['id'] ?? 0 );
		if ( $session_id <= 0 ) {
			return new WP_Error( 'clubsams_control_unauthorized', 'No existe una sesion movil activa para cerrar.', array( 'status' => 401 ) );
		}

		$user = get_user_by( 'id', (int) ( $session['user_id'] ?? 0 ) );
		$this->sessions->revoke_session( $session_id, 'logout' );
		$this->record_event(
			'logout',
			'Sesion movil cerrada correctamente.',
			array(
				'session_id' => $session_id,
			),
			$user instanceof WP_User ? (int) $user->ID : 0,
			$session_id
		);

		return array(
			'session_revoked' => true,
			'session'         => $this->session_payload( $session ),
			'operation'       => $this->operation_payload(
				'auth_logout',
				array(
					'session_id' => $session_id,
					'user_id'    => $user instanceof WP_User ? (int) $user->ID : 0,
				)
			),
		);
	}

	public function auth_me( WP_User $user, array $session ) {
		return array(
			'user'         => $this->user_payload( $user ),
			'permissions'  => CapabilityManager::user_permissions( $user ),
			'route_groups' => CapabilityManager::route_groups_for_permissions( CapabilityManager::user_permissions( $user ) ),
			'session'      => $this->session_payload( $session ),
		);
	}

	public function auth_permissions( WP_User $user ) {
		$permissions = CapabilityManager::user_permissions( $user );

		return array(
			'permissions'  => $permissions,
			'route_groups' => CapabilityManager::route_groups_for_permissions( $permissions ),
		);
	}

	public function ensure_mobile_user( $user, array $session = array() ) {
		if ( ! $user instanceof WP_User || empty( $user->ID ) ) {
			return new WP_Error( 'clubsams_control_unauthorized', 'La sesion movil ya no tiene un usuario valido.', array( 'status' => 401 ) );
		}

		if ( ! CapabilityManager::user_can_access_mobile( $user ) ) {
			return new WP_Error( 'clubsams_control_forbidden', 'El usuario ya no tiene acceso movil.', array( 'status' => 403 ) );
		}

		if ( ! empty( $session['auth_state_hash'] ) && $session['auth_state_hash'] !== $this->tokens->auth_state_hash( $user ) ) {
			return new WP_Error( 'clubsams_control_unauthorized', 'La sesion movil fue invalidada por un cambio de credenciales.', array( 'status' => 401 ) );
		}

		return true;
	}

	private function build_authenticated_payload( WP_User $user, array $session, array $token_pair, $operation_type ) {
		$permissions = CapabilityManager::user_permissions( $user );

		return array(
			'access_token'       => sanitize_text_field( (string) $token_pair['access_token'] ),
			'refresh_token'      => sanitize_text_field( (string) $token_pair['refresh_token'] ),
			'expires_in'         => (int) $token_pair['expires_in'],
			'refresh_expires_in' => (int) $token_pair['refresh_expires_in'],
			'user'               => $this->user_payload( $user ),
			'permissions'        => $permissions,
			'route_groups'       => CapabilityManager::route_groups_for_permissions( $permissions ),
			'session'            => $this->session_payload( $session ),
			'operation'          => $this->operation_payload(
				$operation_type,
				array(
					'session_id' => (int) ( $session['id'] ?? 0 ),
					'user_id'    => (int) $user->ID,
				)
			),
		);
	}

	private function user_payload( WP_User $user ) {
		return array(
			'id'           => (int) $user->ID,
			'username'     => sanitize_user( (string) $user->user_login, true ),
			'display_name' => sanitize_text_field( (string) $user->display_name ),
			'email'        => sanitize_email( (string) $user->user_email ),
			'roles'        => array_values( array_map( 'sanitize_key', (array) $user->roles ) ),
		);
	}

	private function session_payload( array $session ) {
		return array(
			'id'                 => (int) ( $session['id'] ?? 0 ),
			'device_name'        => sanitize_text_field( (string) ( $session['device_name'] ?? '' ) ),
			'platform'           => sanitize_key( (string) ( $session['platform'] ?? '' ) ),
			'app_version'        => sanitize_text_field( (string) ( $session['app_version'] ?? '' ) ),
			'access_expires_at'  => sanitize_text_field( (string) ( $session['access_expires_at'] ?? '' ) ),
			'refresh_expires_at' => sanitize_text_field( (string) ( $session['refresh_expires_at'] ?? '' ) ),
			'last_used_at'       => sanitize_text_field( (string) ( $session['last_used_at'] ?? '' ) ),
		);
	}

	private function operation_payload( $type, array $data = array() ) {
		$operation_id = sanitize_key( (string) $type ) . ':' . md5( wp_json_encode( $data ) );

		return array(
			'type'         => sanitize_key( (string) $type ),
			'status'       => 'completed',
			'operation_id' => $operation_id,
			'session_id'   => ! empty( $data['session_id'] ) ? (int) $data['session_id'] : 0,
			'user_id'      => ! empty( $data['user_id'] ) ? (int) $data['user_id'] : 0,
		);
	}

	private function record_event( $event_type, $message, array $payload = array(), $actor_user_id = 0, $entity_id = 0 ) {
		$this->events->log( 'mobile_session', $entity_id, $event_type, $message, $payload, $actor_user_id > 0 ? $actor_user_id : null );
	}

	private function build_device_context( array $payload, array $context = array(), array $fallback_session = array() ) {
		return array(
			'device_name' => sanitize_text_field( (string) ( $payload['device_name'] ?? ( $fallback_session['device_name'] ?? 'iPhone ClubSams Control' ) ) ),
			'platform'    => sanitize_key( (string) ( $payload['platform'] ?? ( $fallback_session['platform'] ?? 'ios' ) ) ),
			'app_version' => sanitize_text_field( (string) ( $payload['app_version'] ?? ( $fallback_session['app_version'] ?? '' ) ) ),
			'ip'          => sanitize_text_field( (string) ( $context['ip'] ?? '' ) ),
			'user_agent'  => sanitize_text_field( (string) ( $context['user_agent'] ?? '' ) ),
		);
	}
}
