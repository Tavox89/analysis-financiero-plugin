<?php

namespace ASDLabs\Finance\Api;

use ASDLabs\Finance\Core\CapabilityManager;
use ASDLabs\Finance\Core\Contracts\Module;
use ASDLabs\Finance\Core\Schema;
use ASDLabs\Finance\Mobile\MobileAuditService;
use ASDLabs\Finance\Mobile\MobileCashService;
use ASDLabs\Finance\Mobile\MobileFinanceService;
use ASDLabs\Finance\Mobile\MobileInventoryService;
use ASDLabs\Finance\MobileAuth\AuthService;
use ASDLabs\Finance\MobileAuth\Module as MobileAuthModule;
use WP_Error;
use WP_REST_Request;

final class ClubsamsControlRoutes implements Module {
	private $legacy_routes;
	private $auth_service;
	private $finance_service;
	private $cash_service;
	private $audit_service;
	private $inventory_service;

	public function __construct() {
		$this->legacy_routes    = new Routes();
		$this->auth_service     = new AuthService();
		$this->finance_service  = new MobileFinanceService();
		$this->cash_service     = new MobileCashService();
		$this->audit_service    = new MobileAuditService();
		$this->inventory_service = new MobileInventoryService();
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$this->register_get( '/public/app-config', 'public_app_config' );
		$this->register_get( '/health', 'health' );
		$this->register_post( '/auth/login', 'auth_login' );
		$this->register_post( '/auth/refresh', 'auth_refresh' );
		$this->register_post( '/auth/logout', 'auth_logout' );
		$this->register_get( '/auth/me', 'auth_me' );
		$this->register_get( '/auth/permissions', 'auth_permissions' );
		$this->register_get( '/dashboard', 'dashboard' );
		$this->register_get( '/profiles', 'profiles' );
		$this->register_get( '/profiles/(?P<id>\d+)', 'profile_detail' );
		$this->register_get( '/profiles/(?P<id>\d+)/pending-orders', 'profile_pending_orders' );
		$this->register_post( '/profiles/(?P<id>\d+)/collections/preview', 'profile_collections_preview' );
		$this->register_post( '/profiles/(?P<id>\d+)/collections', 'profile_collections' );
		$this->register_post( '/profiles/(?P<id>\d+)/apply-credit', 'profile_apply_credit' );
		$this->register_get( '/documents', 'documents' );
		$this->register_get( '/documents/(?P<id>\d+)/attachments', 'document_attachments' );
		$this->register_get( '/payments', 'payments' );
		$this->register_get( '/integrations/status', 'integrations_status' );
		$this->register_get( '/cash/summary', 'cash_summary' );
		$this->register_get( '/cash/movements', 'cash_movements' );
		$this->register_post( '/cash/collections', 'cash_collections' );
		$this->register_post( '/cash/manual-deliveries', 'cash_manual_deliveries' );
		$this->register_post( '/cash/voids', 'cash_voids' );
		$this->register_get( '/finance/overview', 'finance_overview' );
		$this->register_get( '/finance/receivables', 'finance_receivables' );
		$this->register_get( '/finance/payables', 'finance_payables' );
		$this->register_get( '/finance/comparison', 'finance_comparison' );
		$this->register_get( '/inventory/summary', 'inventory_summary' );
		$this->register_get( '/inventory/expirations', 'inventory_expirations' );
		$this->register_get( '/inventory/incoming', 'inventory_incoming' );
		$this->register_get( '/inventory/usd-report', 'inventory_usd_report' );
		$this->register_get( '/audit/events', 'audit_events' );
	}

	public function public_app_config( WP_REST_Request $request ) {
		unset( $request );

		$environment_key = sanitize_key( (string) apply_filters( 'clubsams_control_environment_key', wp_get_environment_type() ) );
		if ( '' === $environment_key ) {
			$environment_key = 'production';
		}

		$default_labels = array(
			'development' => 'Desarrollo ClubSams Control',
			'staging'     => 'Staging financiero ClubSams',
			'production'  => 'Produccion ClubSams Control',
		);

		$config = array(
			'environmentKey'   => $environment_key,
			'environmentLabel' => sanitize_text_field(
				(string) apply_filters(
					'clubsams_control_environment_label',
					$default_labels[ $environment_key ] ?? ucfirst( $environment_key ) . ' ClubSams Control',
					$environment_key
				)
			),
			'apiBaseUrl'       => untrailingslashit( home_url() ),
			'apiNamespace'     => '/wp-json/clubsams-control/v1',
			'auth'             => array(
				'loginPath'             => '/auth/login',
				'refreshPath'           => '/auth/refresh',
				'logoutPath'            => '/auth/logout',
				'mePath'                => '/auth/me',
				'accessTokenHeaderName' => 'X-Clubsams-Access-Token',
			),
			'branding'         => array(
				'appName'          => sanitize_text_field( (string) apply_filters( 'clubsams_control_app_name', 'ClubSams Control' ) ),
				'organizationName' => sanitize_text_field( (string) apply_filters( 'clubsams_control_organization_name', 'ClubSams' ) ),
				'supportLabel'     => sanitize_text_field( (string) apply_filters( 'clubsams_control_support_label', 'Soporte ClubSams' ) ),
			),
			'featureFlags'     => array(
				'allowEnvironmentOverride' => (bool) apply_filters( 'clubsams_control_allow_environment_override', 'production' !== $environment_key, $environment_key ),
				'inventoryEnabled'         => (bool) apply_filters( 'clubsams_control_inventory_enabled', false, $environment_key ),
				'auditEnabled'             => (bool) apply_filters( 'clubsams_control_audit_enabled', true, $environment_key ),
			),
		);

		$config = apply_filters( 'clubsams_control_public_app_config', $config, $environment_key );

		return $this->success(
			$config,
			array(
				'public' => true,
			)
		);
	}

	public function health( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::ACCESS_MOBILE );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'health', $request );
	}

	public function auth_login( WP_REST_Request $request ) {
		$result = $this->auth_service->login( $this->request_payload( $request ), $this->request_context() );
		return $this->normalize_result( $result );
	}

	public function auth_refresh( WP_REST_Request $request ) {
		$result = $this->auth_service->refresh( $this->request_payload( $request ), $this->request_context() );
		return $this->normalize_result( $result );
	}

	public function auth_logout( WP_REST_Request $request ) {
		unset( $request );

		$guard = $this->ensure_capability( CapabilityManager::ACCESS_MOBILE );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->auth_service->logout( $this->current_mobile_session() );
		return $this->normalize_result( $result );
	}

	public function auth_me( WP_REST_Request $request ) {
		unset( $request );

		$guard = $this->ensure_capability( CapabilityManager::ACCESS_MOBILE );
		if ( $guard ) {
			return $guard;
		}

		$user = $this->current_mobile_user();
		if ( ! $user ) {
			return $this->error_response( 'unauthorized', 'No existe un usuario movil valido para esta sesion.', 401 );
		}

		$result = $this->auth_service->auth_me( $user, $this->current_mobile_session() );
		return $this->success( $result );
	}

	public function auth_permissions( WP_REST_Request $request ) {
		unset( $request );

		$guard = $this->ensure_capability( CapabilityManager::ACCESS_MOBILE );
		if ( $guard ) {
			return $guard;
		}

		$user = $this->current_mobile_user();
		if ( ! $user ) {
			return $this->error_response( 'unauthorized', 'No existe un usuario movil valido para esta sesion.', 401 );
		}

		$result = $this->auth_service->auth_permissions( $user );
		return $this->success( $result );
	}

	public function dashboard( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DASHBOARD );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'dashboard', $request );
	}

	public function profiles( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_PROFILES );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'contacts', $request );
	}

	public function profile_detail( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_PROFILES );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'contact_detail', $request );
	}

	public function profile_pending_orders( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_PROFILES );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'contact_pending_orders', $request );
	}

	public function profile_collections_preview( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::MANAGE_COLLECTIONS );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'preview_settle_contact_orders', $request );
	}

	public function profile_collections( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::MANAGE_COLLECTIONS );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'settle_contact_orders', $request );
	}

	public function profile_apply_credit( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::MANAGE_COLLECTIONS );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'apply_contact_credit', $request );
	}

	public function documents( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DOCUMENTS );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'documents', $request );
	}

	public function document_attachments( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DOCUMENTS );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'document_files', $request );
	}

	public function payments( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_PAYMENTS );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'payments', $request );
	}

	public function integrations_status( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_INTEGRATIONS );
		if ( $guard ) {
			return $guard;
		}

		return $this->proxy_legacy( 'integrations_status', $request );
	}

	public function cash_summary( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_PAYMENTS );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->cash_service->get_summary( $request->get_params() );
		return $this->success( $result );
	}

	public function cash_movements( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_PAYMENTS );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->cash_service->list_movements( $request->get_params() );
		return $this->success(
			array(
				'items' => $result['items'],
			),
			$result['meta'] ?? array()
		);
	}

	public function cash_collections( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::MANAGE_COLLECTIONS );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->cash_service->create_collection( $this->request_payload( $request ), $this->current_mobile_user_id() );
		return $this->normalize_result( $result );
	}

	public function cash_manual_deliveries( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::MANAGE_PAYMENTS );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->cash_service->create_manual_delivery( $this->request_payload( $request ), $this->current_mobile_user_id() );
		return $this->normalize_result( $result );
	}

	public function cash_voids( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::MANAGE_PAYMENTS );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->cash_service->void_movement( $this->request_payload( $request ), $this->current_mobile_user_id() );
		return $this->normalize_result( $result );
	}

	public function finance_overview( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DASHBOARD );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->finance_service->get_overview( $request->get_params() );
		return $this->success( $result );
	}

	public function finance_receivables( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DOCUMENTS );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->finance_service->list_receivables( $request->get_params() );
		return $this->success(
			array(
				'items'    => $result['items'],
				'summary'  => $result['summary'] ?? array(),
				'currency' => $result['currency'] ?? 'USD',
				'range'    => $result['range'] ?? array(),
			)
		);
	}

	public function finance_payables( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DOCUMENTS );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->finance_service->list_payables( $request->get_params() );
		return $this->success(
			array(
				'items'    => $result['items'],
				'summary'  => $result['summary'] ?? array(),
				'currency' => $result['currency'] ?? 'USD',
				'range'    => $result['range'] ?? array(),
			)
		);
	}

	public function finance_comparison( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DASHBOARD );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->finance_service->get_comparison( $request->get_params() );
		return $this->success( $result );
	}

	public function inventory_summary( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DOCUMENTS );
		if ( $guard ) {
			return $guard;
		}

		return $this->success( $this->inventory_service->get_summary( $request->get_params() ), array( 'available' => false, 'note' => 'inventory_contract_pending' ) );
	}

	public function inventory_expirations( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DOCUMENTS );
		if ( $guard ) {
			return $guard;
		}

		return $this->success(
			array(
				'items' => $this->inventory_service->list_expirations( $request->get_params() ),
			),
			array( 'available' => false, 'count' => 0, 'note' => 'inventory_contract_pending' )
		);
	}

	public function inventory_incoming( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DOCUMENTS );
		if ( $guard ) {
			return $guard;
		}

		return $this->success(
			array(
				'items' => $this->inventory_service->list_incoming( $request->get_params() ),
			),
			array( 'available' => false, 'count' => 0, 'note' => 'inventory_contract_pending' )
		);
	}

	public function inventory_usd_report( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DOCUMENTS );
		if ( $guard ) {
			return $guard;
		}

		return $this->success( $this->inventory_service->get_usd_report( $request->get_params() ), array( 'available' => false, 'note' => 'inventory_contract_pending' ) );
	}

	public function audit_events( WP_REST_Request $request ) {
		$guard = $this->ensure_capability( CapabilityManager::VIEW_DASHBOARD );
		if ( $guard ) {
			return $guard;
		}

		$result = $this->audit_service->list_events( $request->get_params() );
		return $this->success(
			array(
				'items' => $result['items'],
			),
			$result['meta'] ?? array()
		);
	}

	private function proxy_legacy( $legacy_method, WP_REST_Request $request ) {
		$user = $this->current_mobile_user();
		if ( $user ) {
			wp_set_current_user( (int) $user->ID );
		}

		$result = $this->legacy_routes->{$legacy_method}( $request );
		return $this->normalize_result(
			$result,
			array(
				'compatibility_namespace' => 'asdl-fin/v1',
				'namespace'               => 'clubsams-control/v1',
			)
		);
	}

	private function ensure_capability( $capability ) {
		$user = $this->current_mobile_user();
		if ( ! $user ) {
			$error = MobileAuthModule::auth_error();
			if ( $error instanceof WP_Error ) {
				return $this->normalize_error( $error );
			}

			return $this->error_response( 'unauthorized', 'Debes iniciar sesion para usar este endpoint.', 401 );
		}

		if ( $capability && ! CapabilityManager::user_can_cap( $user, $capability ) ) {
			return $this->error_response( 'forbidden', 'No tienes permisos para acceder a este endpoint.', 403 );
		}

		return null;
	}

	private function current_mobile_session() {
		$session = MobileAuthModule::current_session();
		return is_array( $session ) ? $session : array();
	}

	private function current_mobile_user() {
		$session = $this->current_mobile_session();
		if ( empty( $session['user_id'] ) ) {
			return null;
		}

		$user = get_user_by( 'id', (int) $session['user_id'] );
		return $user instanceof \WP_User ? $user : null;
	}

	private function current_mobile_user_id() {
		$user = $this->current_mobile_user();
		return $user ? (int) $user->ID : 0;
	}

	private function register_get( $route, $callback ) {
		register_rest_route(
			'clubsams-control/v1',
			$route,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, $callback ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private function register_post( $route, $callback ) {
		register_rest_route(
			'clubsams-control/v1',
			$route,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, $callback ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private function request_payload( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( is_array( $data ) && ! empty( $data ) ) {
			return $data;
		}

		return $request->get_params();
	}

	private function request_context() {
		return array(
			'ip'         => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
		);
	}

	private function normalize_result( $result, array $meta = array() ) {
		if ( is_wp_error( $result ) ) {
			return $this->normalize_error( $result, $meta );
		}

		$status  = 200;
		$payload = $result;

		if ( $result instanceof \WP_REST_Response ) {
			$status  = $result->get_status();
			$payload = $result->get_data();
		}

		$data      = is_array( $payload ) && array_key_exists( 'data', $payload ) ? $payload['data'] : $payload;
		$payload_meta = is_array( $payload ) && is_array( $payload['meta'] ?? null ) ? $payload['meta'] : array();

		return $this->success( $data, array_merge( $payload_meta, $meta ), $status );
	}

	private function normalize_error( WP_Error $error, array $meta = array() ) {
		$error_data  = $error->get_error_data();
		$status      = is_array( $error_data ) && ! empty( $error_data['status'] ) ? (int) $error_data['status'] : 400;
		$code        = $this->standard_error_code( (string) $error->get_error_code(), $status );
		$details     = is_array( $error_data ) ? $error_data : array();
		$details['original_code'] = (string) $error->get_error_code();

		return $this->error_response( $code, $error->get_error_message(), $status, $details, $meta );
	}

	private function standard_error_code( $original_code, $status ) {
		$original_code = sanitize_key( (string) $original_code );

		if ( 429 === (int) $status ) {
			return 'rate_limited';
		}

		if ( 401 === (int) $status ) {
			return 'unauthorized';
		}

		if ( 403 === (int) $status ) {
			return 'forbidden';
		}

		if ( 404 === (int) $status || false !== strpos( $original_code, 'not_found' ) ) {
			return 'not_found';
		}

		if ( 409 === (int) $status ) {
			return 'conflict';
		}

		if ( (int) $status >= 500 ) {
			return 'backend_unavailable';
		}

		return 'validation_error';
	}

	private function error_response( $code, $message, $status = 400, array $details = array(), array $meta = array() ) {
		$response = rest_ensure_response(
			array(
				'data'  => null,
				'meta'  => $this->merge_meta( $meta ),
				'error' => array(
					'code'    => sanitize_key( (string) $code ),
					'message' => sanitize_text_field( (string) $message ),
					'details' => $details,
				),
			)
		);
		$response->set_status( (int) $status );

		return $response;
	}

	private function success( $data, array $meta = array(), $status = 200 ) {
		$response = rest_ensure_response(
			array(
				'data'  => $data,
				'meta'  => $this->merge_meta( $meta ),
				'error' => null,
			)
		);
		$response->set_status( (int) $status );

		return $response;
	}

	private function merge_meta( array $meta = array() ) {
		return array_merge(
			array(
				'namespace'      => 'clubsams-control/v1',
				'plugin_version' => defined( 'ASDL_FINANCE_VERSION' ) ? ASDL_FINANCE_VERSION : 'dev',
				'schema_version' => Schema::VERSION,
			),
			$meta
		);
	}
}
