<?php

namespace ASDLabs\Finance\Api;

use ASDLabs\Finance\Core\CapabilityManager;
use ASDLabs\Finance\Core\Contracts\Module;
use ASDLabs\Finance\Core\Schema;
use ASDLabs\Finance\Core\SchemaInstaller;
use ASDLabs\Finance\Core\Tables;
use ASDLabs\Finance\Finance\AccountsRepository;
use ASDLabs\Finance\Finance\CancellationService;
use ASDLabs\Finance\Finance\CommitmentSettlementService;
use ASDLabs\Finance\Finance\ContactOverviewService;
use ASDLabs\Finance\Finance\ContactsRepository;
use ASDLabs\Finance\Finance\CurrenciesService;
use ASDLabs\Finance\Finance\DocumentsRepository;
use ASDLabs\Finance\Finance\DocumentFilesRepository;
use ASDLabs\Finance\Finance\EmployeeAdvancesRepository;
use ASDLabs\Finance\Finance\EmployeeProfilesRepository;
use ASDLabs\Finance\Finance\EventsRepository;
use ASDLabs\Finance\Finance\FiscalYearService;
use ASDLabs\Finance\Finance\InstallmentsRepository;
use ASDLabs\Finance\Finance\InstallmentPlansRepository;
use ASDLabs\Finance\Finance\IntegrationStatusService;
use ASDLabs\Finance\Finance\OverviewService;
use ASDLabs\Finance\Finance\OrderSettlementBatchService;
use ASDLabs\Finance\Finance\PayrollManualSettlementService;
use ASDLabs\Finance\Finance\PayrollQueueService;
use ASDLabs\Finance\Finance\PayrollPeriodsRepository;
use ASDLabs\Finance\Finance\PaymentAllocationsRepository;
use ASDLabs\Finance\Finance\PaymentAllocationService;
use ASDLabs\Finance\Finance\PaymentMethodsService;
use ASDLabs\Finance\Finance\PaymentsRepository;
use ASDLabs\Finance\Finance\ReceiptBrandingService;
use ASDLabs\Finance\Finance\ReceiptService;
use ASDLabs\Finance\Finance\RulesRepository;
use ASDLabs\Finance\Finance\SourceLinksRepository;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;
use ASDLabs\Finance\Integrations\Woo\ProfileOrderSettlementService;
use WP_REST_Request;

final class Routes implements Module {
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'asdl-fin/v1',
			'/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'me' ),
				'permission_callback' => array( $this, 'can_access_mobile' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/me/permissions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'me_permissions' ),
				'permission_callback' => array( $this, 'can_access_mobile' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'health' ),
				'permission_callback' => array( $this, 'can_access_mobile' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'dashboard' ),
				'permission_callback' => array( $this, 'can_view_dashboard' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/payment-methods',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'payment_methods' ),
				'permission_callback' => array( $this, 'can_access_mobile' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/currencies',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'currencies' ),
				'permission_callback' => array( $this, 'can_access_mobile' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/fiscal-years',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'fiscal_years' ),
				'permission_callback' => array( $this, 'can_access_mobile' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/accounts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'accounts' ),
					'permission_callback' => array( $this, 'can_view_accounts' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_account' ),
					'permission_callback' => array( $this, 'can_manage_accounts' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'contacts' ),
					'permission_callback' => array( $this, 'can_view_profiles' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_contact' ),
					'permission_callback' => array( $this, 'can_manage_profiles' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'contact_detail' ),
				'permission_callback' => array( $this, 'can_view_profiles' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/pending-orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'contact_pending_orders' ),
				'permission_callback' => array( $this, 'can_view_profiles' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/commitments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'contact_commitments' ),
					'permission_callback' => array( $this, 'can_view_commitments' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_contact_commitment' ),
					'permission_callback' => array( $this, 'can_manage_commitments' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/settle-orders',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'settle_contact_orders' ),
				'permission_callback' => array( $this, 'can_manage_collections' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/settle-orders/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview_settle_contact_orders' ),
				'permission_callback' => array( $this, 'can_manage_collections' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/payroll-open-debts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'contact_payroll_open_debts' ),
				'permission_callback' => array( $this, 'can_manage_payroll' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/apply-credit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_contact_credit' ),
				'permission_callback' => array( $this, 'can_manage_collections' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/employee-profile',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'employee_profile_detail' ),
					'permission_callback' => array( $this, 'can_view_payroll' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_employee_profile' ),
					'permission_callback' => array( $this, 'can_manage_payroll' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/salary-advances',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'salary_advances' ),
					'permission_callback' => array( $this, 'can_view_payroll' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_salary_advance' ),
					'permission_callback' => array( $this, 'can_manage_payroll' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/contacts/(?P<id>\d+)/payroll-periods',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'payroll_periods' ),
					'permission_callback' => array( $this, 'can_view_payroll' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_payroll_period' ),
					'permission_callback' => array( $this, 'can_manage_payroll' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/payroll-periods/(?P<id>\d+)/mark-paid',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'mark_payroll_period_paid' ),
				'permission_callback' => array( $this, 'can_manage_payroll' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/payroll-periods/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'payroll_period_detail' ),
				'permission_callback' => array( $this, 'can_view_payroll' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/payroll/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'payroll_queue' ),
				'permission_callback' => array( $this, 'can_view_payroll' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/documents',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'documents' ),
					'permission_callback' => array( $this, 'can_view_documents' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_document' ),
					'permission_callback' => array( $this, 'can_manage_documents' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/documents/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'document_detail' ),
					'permission_callback' => array( $this, 'can_view_documents' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_document' ),
					'permission_callback' => array( $this, 'can_manage_documents' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/documents/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_document' ),
				'permission_callback' => array( $this, 'can_manage_documents' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/documents/(?P<id>\d+)/files',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'document_files' ),
					'permission_callback' => array( $this, 'can_view_documents' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'link_document_file' ),
					'permission_callback' => array( $this, 'can_manage_documents' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/payments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'payments' ),
					'permission_callback' => array( $this, 'can_view_payments' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_payment' ),
					'permission_callback' => array( $this, 'can_manage_payments' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/payments/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'payment_detail' ),
				'permission_callback' => array( $this, 'can_view_payments' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/payments/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_payment' ),
				'permission_callback' => array( $this, 'can_manage_payments' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/salary-advances/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'salary_advance_detail' ),
				'permission_callback' => array( $this, 'can_view_payroll' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/salary-advances/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_salary_advance' ),
				'permission_callback' => array( $this, 'can_manage_payroll' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/receipts/(?P<type>[a-z_]+)/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'receipt_detail' ),
				'permission_callback' => array( $this, 'can_view_receipt' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/rules',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'rules' ),
					'permission_callback' => array( $this, 'can_manage_automations' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_rule' ),
					'permission_callback' => array( $this, 'can_manage_automations' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/payment-allocations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'payment_allocations' ),
					'permission_callback' => array( $this, 'can_view_payments' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_payment_allocation' ),
					'permission_callback' => array( $this, 'can_manage_payments' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/installment-plans',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'installment_plans' ),
					'permission_callback' => array( $this, 'can_view_commitments' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_installment_plan' ),
					'permission_callback' => array( $this, 'can_manage_commitments' ),
				),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/installment-plans/(?P<id>\d+)/apply-payment',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_installment_plan_payment' ),
				'permission_callback' => array( $this, 'can_manage_commitments' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/installment-plans/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'installment_plan_detail' ),
				'permission_callback' => array( $this, 'can_view_commitments' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/installment-plans/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_installment_plan' ),
				'permission_callback' => array( $this, 'can_manage_commitments' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/integrations/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'integrations_status' ),
				'permission_callback' => array( $this, 'can_view_integrations' ),
			)
		);

		register_rest_route(
			'asdl-fin/v1',
			'/sync/orders',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'sync_orders' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function health() {
		return rest_ensure_response(
			array(
				'plugin'               => 'ASDLabs Finance Core',
				'brand'                => 'Finanzas ASD',
				'plugin_version'       => ASDL_FINANCE_VERSION,
				'schema_version'       => get_option( SchemaInstaller::OPTION_SCHEMA_VERSION, '' ),
				'target_schema'        => Schema::VERSION,
				'capabilities_version' => get_option( CapabilityManager::OPTION_VERSION, '' ),
				'tables'               => Tables::all(),
				'available_routes'     => array(
					'me',
					'me/permissions',
					'health',
					'dashboard',
					'payment-methods',
					'currencies',
					'fiscal-years',
					'accounts',
					'contacts',
					'contacts/<id>',
					'contacts/<id>/pending-orders',
					'contacts/<id>/commitments',
					'contacts/<id>/employee-profile',
					'contacts/<id>/salary-advances',
					'contacts/<id>/payroll-periods',
					'contacts/<id>/settle-orders',
					'contacts/<id>/settle-orders/preview',
					'contacts/<id>/payroll-open-debts',
					'contacts/<id>/apply-credit',
					'payroll/queue',
					'payroll-periods/<id>',
					'payroll-periods/<id>/mark-paid',
					'documents',
					'documents/<id>',
					'documents/<id>/cancel',
					'documents/<id>/files',
					'payments',
					'payments/<id>',
					'payments/<id>/cancel',
					'salary-advances/<id>',
					'salary-advances/<id>/cancel',
					'receipts/<type>/<id>',
					'rules',
					'payment-allocations',
					'installment-plans',
					'installment-plans/<id>/apply-payment',
					'installment-plans/<id>',
					'installment-plans/<id>/cancel',
					'integrations/status',
					'sync/orders',
				),
			)
		);
	}

	public function me() {
		$user = wp_get_current_user();

		return $this->success_response(
			array(
				'user' => array(
					'id'           => (int) $user->ID,
					'username'     => (string) $user->user_login,
					'display_name' => (string) $user->display_name,
					'email'        => (string) $user->user_email,
					'roles'        => array_values( (array) $user->roles ),
				),
				'permissions' => CapabilityManager::current_user_permissions(),
				'auth'        => array(
					'provider'                         => 'wordpress',
					'application_passwords_available' => function_exists( 'wp_is_application_passwords_available' ) ? wp_is_application_passwords_available() : false,
					'application_passwords_for_user'  => function_exists( 'wp_is_application_passwords_available_for_user' ) ? wp_is_application_passwords_available_for_user( $user ) : false,
				),
			),
			array(
				'access_mobile' => CapabilityManager::current_user_can_access_mobile(),
			)
		);
	}

	public function me_permissions() {
		$permissions = CapabilityManager::current_user_permissions();

		return $this->success_response(
			array(
				'capabilities' => CapabilityManager::capability_map(),
				'permissions'  => $permissions,
				'route_groups' => array(
					'dashboard'    => ! empty( $permissions[ CapabilityManager::VIEW_DASHBOARD ] ),
					'accounts'     => ! empty( $permissions[ CapabilityManager::VIEW_ACCOUNTS ] ),
					'profiles'     => ! empty( $permissions[ CapabilityManager::VIEW_PROFILES ] ),
					'collections'  => ! empty( $permissions[ CapabilityManager::MANAGE_COLLECTIONS ] ),
					'documents'    => ! empty( $permissions[ CapabilityManager::VIEW_DOCUMENTS ] ),
					'payments'     => ! empty( $permissions[ CapabilityManager::VIEW_PAYMENTS ] ),
					'commitments'  => ! empty( $permissions[ CapabilityManager::VIEW_COMMITMENTS ] ),
					'payroll'      => ! empty( $permissions[ CapabilityManager::VIEW_PAYROLL ] ),
					'integrations' => ! empty( $permissions[ CapabilityManager::VIEW_INTEGRATIONS ] ),
					'automations'  => ! empty( $permissions[ CapabilityManager::MANAGE_AUTOMATIONS ] ),
				),
			),
			array(
				'count' => count( $permissions ),
			)
		);
	}

	public function dashboard( WP_REST_Request $request ) {
		$service = new OverviewService();
		$range   = $this->resolve_fiscal_range( $request );
		$data    = $service->get_dashboard_snapshot(
			array(
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
			)
		);

		return $this->success_response(
			$data,
			array(
				'filters'     => array(
					'range_from'  => $range['range_from'],
					'range_to'    => $range['range_to'],
					'fiscal_year' => (int) $range['fiscal_context']['start_year'],
				),
				'fiscal_year' => $range['fiscal_context'],
				'read_only'   => ! empty( $range['read_only'] ),
			)
		);
	}

	public function payment_methods() {
		$service = new PaymentMethodsService();
		$items   = $service->catalog();

		return $this->success_response(
			array(
				'items' => $items,
			),
			array(
				'count'      => count( $items ),
				'selectable' => count(
					array_filter(
						$items,
						static function ( $item ) {
							return ! empty( $item['selectable'] );
						}
					)
				),
			)
		);
	}

	public function currencies() {
		$service = new CurrenciesService();
		$items   = $service->catalog();

		return $this->success_response(
			array(
				'items' => $items,
			),
			array(
				'count' => count( $items ),
			)
		);
	}

	public function fiscal_years( WP_REST_Request $request ) {
		$service       = new FiscalYearService();
		$selected_year = absint( $request->get_param( 'fiscal_year' ) );
		$lookback      = max( 1, absint( $request->get_param( 'lookback' ) ?: 6 ) );
		$lookahead     = max( 0, absint( $request->get_param( 'lookahead' ) ?: 1 ) );
		$selected      = $service->get_context( $selected_year );
		$active        = $service->get_context( (int) ( $selected['settings']['active_start_year'] ?? 0 ) );
		$years         = array();

		foreach ( $service->available_years( $lookback, $lookahead ) as $start_year => $label ) {
			$context   = $service->get_context( (int) $start_year );
			$context['label'] = $label;
			$years[]   = $context;
		}

		return $this->success_response(
			array(
				'selected' => $selected,
				'active'   => $active,
				'years'    => $years,
				'settings' => $service->get_settings(),
			),
			array(
				'count'         => count( $years ),
				'selected_year' => (int) $selected['start_year'],
				'read_only'     => ! empty( $selected['is_active'] ) ? false : true,
			)
		);
	}

	public function accounts( WP_REST_Request $request ) {
		return $this->list_response( new AccountsRepository(), $request );
	}

	public function contacts( WP_REST_Request $request ) {
		$result = ( new ContactsRepository() )->list_for_api(
			array(
				'page'           => $request->get_param( 'page' ),
				'limit'          => $request->get_param( 'limit' ),
				'search'         => $request->get_param( 'search' ),
				'status'         => $request->get_param( 'status' ),
				'profile_origin' => $request->get_param( 'profile_origin' ),
				'origin'         => $request->get_param( 'origin' ),
				'role'           => $request->get_param( 'role' ),
				'is_customer'    => $request->get_param( 'is_customer' ),
				'is_employee'    => $request->get_param( 'is_employee' ),
				'is_supplier'    => $request->get_param( 'is_supplier' ),
			)
		);

		return $this->success_response(
			array(
				'items' => $result['items'],
			),
			$result['meta']
		);
	}

	public function contact_detail( WP_REST_Request $request ) {
		$service  = new ContactOverviewService();
		$range    = $this->resolve_fiscal_range( $request );
		$contact  = $service->get_contact_snapshot(
			(int) $request['id'],
			array(
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
				'order_limit'=> absint( $request->get_param( 'order_limit' ) ),
			)
		);

		if ( empty( $contact ) ) {
			return new \WP_Error( 'asdl_fin_contact_missing', 'No se encontro el perfil solicitado.', array( 'status' => 404 ) );
		}

		return $this->success_response(
			$contact,
			array(
				'filters'     => array(
					'range_from'  => $range['range_from'],
					'range_to'    => $range['range_to'],
					'fiscal_year' => (int) $range['fiscal_context']['start_year'],
					'order_limit' => absint( $request->get_param( 'order_limit' ) ),
				),
				'fiscal_year' => $range['fiscal_context'],
				'read_only'   => ! empty( $range['read_only'] ),
			)
		);
	}

	public function contact_pending_orders( WP_REST_Request $request ) {
		$service  = new ContactOverviewService();
		$limit    = absint( $request->get_param( 'limit' ) ?: 50 );
		$snapshot = $service->get_pending_orders_snapshot(
			(int) $request['id'],
			array(
				'limit' => $limit,
			)
		);

		if ( empty( $snapshot ) ) {
			return new \WP_Error( 'asdl_fin_contact_missing', 'No se encontro el perfil solicitado.', array( 'status' => 404 ) );
		}

		return $this->success_response(
			array(
				'contact' => $this->contact_summary( $snapshot['contact'] ?? null ),
				'items'   => array_values( (array) ( $snapshot['orders'] ?? array() ) ),
				'summary' => (array) ( $snapshot['summary'] ?? array() ),
			),
			array(
				'count' => count( (array) ( $snapshot['orders'] ?? array() ) ),
				'total' => (int) ( $snapshot['total'] ?? 0 ),
				'limit' => (int) ( $snapshot['limit'] ?? $limit ),
			)
		);
	}

	public function contact_commitments( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$limit      = max( 1, min( 200, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$status     = sanitize_key( (string) $request->get_param( 'status' ) );
		$direction  = sanitize_key( (string) $request->get_param( 'settlement_direction' ) );
		$mode       = sanitize_key( (string) $request->get_param( 'collection_mode' ) );
		$contacts   = new ContactsRepository();
		$contact    = $contacts->find( $contact_id );

		if ( empty( $contact['id'] ) ) {
			return new \WP_Error( 'asdl_fin_contact_missing', 'No se encontro el perfil solicitado.', array( 'status' => 404 ) );
		}

		$items = ( new InstallmentPlansRepository() )->for_contact( $contact_id, 200 );
		$items = array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $status, $direction, $mode ) {
					if ( '' !== $status && sanitize_key( (string) ( $item['status'] ?? '' ) ) !== $status ) {
						return false;
					}

					if ( '' !== $direction && sanitize_key( (string) ( $item['settlement_direction'] ?? '' ) ) !== $direction ) {
						return false;
					}

					if ( '' !== $mode && sanitize_key( (string) ( $item['collection_mode'] ?? '' ) ) !== $mode ) {
						return false;
					}

					return true;
				}
			)
		);

		$summary = array(
			'total_count'      => count( $items ),
			'open_count'       => count(
				array_filter(
					$items,
					static function ( array $item ) {
						return (float) ( $item['balance'] ?? 0 ) > 0 && 'closed' !== sanitize_key( (string) ( $item['status'] ?? '' ) );
					}
				)
			),
			'balance_total'    => array_sum( array_map( static function ( array $item ) { return (float) ( $item['balance'] ?? 0 ); }, $items ) ),
			'receivable_total' => array_sum( array_map( static function ( array $item ) {
				return 'receivable' === sanitize_key( (string) ( $item['settlement_direction'] ?? 'receivable' ) ) ? (float) ( $item['balance'] ?? 0 ) : 0;
			}, $items ) ),
			'payable_total'    => array_sum( array_map( static function ( array $item ) {
				return 'payable' === sanitize_key( (string) ( $item['settlement_direction'] ?? 'receivable' ) ) ? (float) ( $item['balance'] ?? 0 ) : 0;
			}, $items ) ),
		);

		return $this->success_response(
			array(
				'contact' => $this->contact_summary( $contact ),
				'items'   => array_slice( $items, 0, $limit ),
				'summary' => $summary,
			),
			array(
				'count'      => min( count( $items ), $limit ),
				'total'      => count( $items ),
				'limit'      => $limit,
				'contact_id' => $contact_id,
				'filters'    => array(
					'status'               => $status,
					'settlement_direction' => $direction,
					'collection_mode'      => $mode,
				),
			)
		);
	}

	public function create_contact_commitment( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$contacts   = new ContactsRepository();
		$contact    = $contacts->find( $contact_id );

		if ( empty( $contact['id'] ) ) {
			return new \WP_Error( 'asdl_fin_contact_missing', 'No se encontro el perfil solicitado.', array( 'status' => 404 ) );
		}

		$data               = $this->request_payload( $request );
		$data['contact_id'] = $contact_id;
		$repository         = new InstallmentPlansRepository();
		$result             = $repository->create( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new EventsRepository() )->log(
			'installment_plan',
			(int) $result,
			'created',
			'Compromiso registrado correctamente.',
			array(
				'entity_type' => 'installment_plan',
				'entity_id'   => (int) $result,
				'origin'      => 'api',
				'contact_id'  => $contact_id,
			)
		);

		do_action( 'asdl_fin_installment_plan_created', (int) $result );

		return $this->success_response(
			array(
				'id'         => (int) $result,
				'message'    => 'Compromiso registrado correctamente.',
				'operation'  => $this->build_operation_payload(
					'create_commitment',
					array(
						'contact_id' => $contact_id,
						'plan_id'    => (int) $result,
						'entity_id'  => (int) $result,
					)
				),
				'commitment' => $repository->find( $result ),
			),
			array(
				'contact_id' => $contact_id,
			)
		);
	}

	public function documents( WP_REST_Request $request ) {
		$result = ( new DocumentsRepository() )->list_for_api(
			array(
				'page'             => $request->get_param( 'page' ),
				'limit'            => $request->get_param( 'limit' ),
				'search'           => $request->get_param( 'search' ),
				'contact_id'       => $request->get_param( 'contact_id' ),
				'wp_user_id'       => $request->get_param( 'wp_user_id' ),
				'document_type'    => $request->get_param( 'document_type' ),
				'source_type'      => $request->get_param( 'source_type' ),
				'payment_status'   => $request->get_param( 'payment_status' ),
				'balance_nature'   => $request->get_param( 'balance_nature' ),
				'financial_status' => $request->get_param( 'financial_status' ),
				'open_only'        => $request->get_param( 'open_only' ),
				'range_from'       => $request->get_param( 'range_from' ),
				'range_to'         => $request->get_param( 'range_to' ),
			)
		);

		return $this->success_response(
			array(
				'items' => $result['items'],
			),
			$result['meta']
		);
	}

	public function document_detail( WP_REST_Request $request ) {
		$document_id = (int) $request['id'];
		$document    = ( new DocumentsRepository() )->find( $document_id );

		if ( empty( $document ) ) {
			return new \WP_Error( 'asdl_fin_document_missing', 'No se encontro el movimiento solicitado.', array( 'status' => 404 ) );
		}

		$contact = ! empty( $document['contact_id'] ) ? ( new ContactsRepository() )->find( (int) $document['contact_id'] ) : null;

		return $this->success_response(
			array(
				'document'     => $document,
				'contact'      => $this->contact_summary( $contact ),
				'source_links' => ( new SourceLinksRepository() )->find_for_document( $document_id ),
			),
			array(
				'document_id' => $document_id,
			)
		);
	}

	public function document_files( WP_REST_Request $request ) {
		$document_id = (int) $request['id'];
		$document    = ( new DocumentsRepository() )->find( $document_id );

		if ( empty( $document ) ) {
			return new \WP_Error( 'asdl_fin_document_missing', 'No se encontro el movimiento solicitado.', array( 'status' => 404 ) );
		}

		$items = ( new DocumentFilesRepository() )->for_document_api( $document_id );

		return $this->success_response(
			array(
				'document' => array(
					'id'       => $document_id,
					'title'    => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
					'number'   => sanitize_text_field( (string) ( $document['document_number'] ?? '' ) ),
					'currency' => sanitize_text_field( (string) ( $document['currency'] ?? 'USD' ) ),
				),
				'items'    => $items,
			),
			array(
				'count'       => count( $items ),
				'document_id' => $document_id,
			)
		);
	}

	public function link_document_file( WP_REST_Request $request ) {
		$document_id = (int) $request['id'];
		$document    = ( new DocumentsRepository() )->find( $document_id );
		$payload     = $this->request_payload( $request );

		if ( empty( $document ) ) {
			return new \WP_Error( 'asdl_fin_document_missing', 'No se encontro el movimiento solicitado.', array( 'status' => 404 ) );
		}

		$attachment_id = absint( $payload['attachment_id'] ?? 0 );
		$attachment    = $attachment_id > 0 ? get_post( $attachment_id ) : null;

		if ( $attachment_id <= 0 || ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error( 'asdl_fin_attachment_missing', 'Debes indicar un archivo de WordPress valido para vincularlo al movimiento.', array( 'status' => 400 ) );
		}

		$repository = new DocumentFilesRepository();
		$result     = $repository->create_record(
			$document_id,
			$attachment_id,
			sanitize_key( (string) ( $payload['file_kind'] ?? 'supporting_document' ) ),
			sanitize_text_field( (string) ( $payload['title'] ?? get_the_title( $attachment_id ) ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$file_id = (int) $result;

		( new EventsRepository() )->log(
			'document_file',
			$file_id,
			'linked',
			'Archivo vinculado correctamente al movimiento.',
			array(
				'entity_type'   => 'document_file',
				'entity_id'     => $file_id,
				'document_id'   => $document_id,
				'attachment_id' => $attachment_id,
				'origin'        => 'api',
			)
		);

		return $this->success_response(
			array(
				'id'      => $file_id,
				'message' => 'Archivo vinculado correctamente al movimiento.',
				'file'    => $repository->find_for_api( $file_id ),
			),
			array(
				'document_id'   => $document_id,
				'attachment_id' => $attachment_id,
			)
		);
	}

	public function payments( WP_REST_Request $request ) {
		$result = ( new PaymentsRepository() )->list_for_api(
			array(
				'page'           => $request->get_param( 'page' ),
				'limit'          => $request->get_param( 'limit' ),
				'search'         => $request->get_param( 'search' ),
				'contact_id'     => $request->get_param( 'contact_id' ),
				'payment_type'   => $request->get_param( 'payment_type' ),
				'status'         => $request->get_param( 'status' ),
				'method_key'     => $request->get_param( 'method_key' ),
				'available_only' => $request->get_param( 'available_only' ),
				'range_from'     => $request->get_param( 'range_from' ),
				'range_to'       => $request->get_param( 'range_to' ),
			)
		);

		return $this->success_response(
			array(
				'items' => $result['items'],
			),
			$result['meta']
		);
	}

	public function payment_detail( WP_REST_Request $request ) {
		$payment_id    = (int) $request['id'];
		$payments      = new PaymentsRepository();
		$payment       = $payments->find( $payment_id );

		if ( empty( $payment['id'] ) ) {
			return new \WP_Error( 'asdl_fin_payment_missing', 'No se encontro el pago solicitado.', array( 'status' => 404 ) );
		}

		$contact       = ! empty( $payment['contact_id'] ) ? ( new ContactsRepository() )->find( (int) $payment['contact_id'] ) : null;
		$allocations   = ( new PaymentAllocationsRepository() )->for_payment( $payment_id, 100 );
		$applications  = ( new InstallmentsRepository() )->applications_for_payment( $payment_id, 100 );

		return $this->success_response(
			array(
				'payment'                 => $payment,
				'contact'                 => $this->contact_summary( $contact ),
				'allocations'             => $allocations,
				'commitment_applications' => $applications,
				'receipt'                 => array(
					'type' => 'payment',
					'id'   => $payment_id,
				),
			),
			array(
				'payment_id'    => $payment_id,
				'traceable_by'  => array( 'payment_id', 'receipt_type' ),
			)
		);
	}

	public function receipt_detail( WP_REST_Request $request ) {
		$receipt_type = sanitize_key( (string) $request['type'] );
		$entity_id    = (int) $request['id'];
		$receipt      = ( new ReceiptService() )->build( $receipt_type, $entity_id );

		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}

		return $this->success_response(
			array(
				'receipt'  => $receipt,
				'branding' => ( new ReceiptBrandingService() )->get_snapshot(),
			),
			array(
				'receipt_type' => $receipt_type,
				'entity_id'    => $entity_id,
				'printable'    => true,
			)
		);
	}

	public function rules( WP_REST_Request $request ) {
		return $this->list_response( new RulesRepository(), $request );
	}

	public function payment_allocations( WP_REST_Request $request ) {
		return $this->list_response( new PaymentAllocationsRepository(), $request );
	}

	public function installment_plans( WP_REST_Request $request ) {
		return $this->list_response( new InstallmentPlansRepository(), $request );
	}

	public function installment_plan_detail( WP_REST_Request $request ) {
		$plan_id       = (int) $request['id'];
		$plans         = new InstallmentPlansRepository();
		$installments  = new InstallmentsRepository();
		$plan          = $plans->find( $plan_id );

		if ( empty( $plan['id'] ) ) {
			return new \WP_Error( 'asdl_fin_commitment_missing', 'No se encontro el compromiso solicitado.', array( 'status' => 404 ) );
		}

		$contact  = ! empty( $plan['contact_id'] ) ? ( new ContactsRepository() )->find( (int) $plan['contact_id'] ) : null;
		$document = ! empty( $plan['document_id'] ) ? ( new DocumentsRepository() )->find( (int) $plan['document_id'] ) : null;

		return $this->success_response(
			array(
				'commitment'   => $plan,
				'contact'      => $this->contact_summary( $contact ),
				'document'     => $this->document_summary( $document ),
				'installments' => $installments->for_plan( $plan_id, false, 200 ),
				'summary'      => $installments->summary_for_plan( $plan_id ),
			),
			array(
				'plan_id'      => $plan_id,
				'traceable_by' => array( 'plan_id', 'document_id' ),
			)
		);
	}

	public function integrations_status() {
		$service = new IntegrationStatusService();

		return $this->success_response(
			$service->get_snapshot(),
			array(
				'source' => 'integration_status_service',
			)
		);
	}

	public function sync_orders( WP_REST_Request $request ) {
		$service  = new OrderSyncService();
		$payload  = $this->request_payload( $request );
		$order_id = absint( $payload['order_id'] ?? 0 );

		if ( $order_id > 0 ) {
			$result = $service->sync_order( $order_id, array( 'trigger' => 'api_single' ) );
		} else {
			$result = $service->sync_recent_orders(
				array(
					'limit'  => isset( $payload['limit'] ) ? (int) $payload['limit'] : 25,
					'days'   => isset( $payload['days'] ) ? (int) $payload['days'] : 30,
					'source' => isset( $payload['source'] ) ? sanitize_key( $payload['source'] ) : 'all',
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array(
				'result' => $result,
			),
			array(
				'operation' => 'sync_orders',
			)
		);
	}

	public function create_account( WP_REST_Request $request ) {
		return $this->create_resource(
			new AccountsRepository(),
			$request,
			'account',
			'Cuenta registrada correctamente.',
			'asdl_fin_account_created'
		);
	}

	public function create_contact( WP_REST_Request $request ) {
		return $this->create_resource(
			new ContactsRepository(),
			$request,
			'contact',
			'Perfil registrado correctamente.',
			'asdl_fin_contact_created'
		);
	}

	public function employee_profile_detail( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$profile    = ( new EmployeeProfilesRepository() )->find_by_contact_id( $contact_id );

		if ( empty( $profile ) ) {
			return new \WP_Error( 'asdl_fin_employee_profile_missing', 'Este perfil aun no tiene configuracion laboral.', array( 'status' => 404 ) );
		}

		return $this->success_response(
			array(
				'employee_profile' => $profile,
			),
			array(
				'contact_id' => $contact_id,
			)
		);
	}

	public function save_employee_profile( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$result     = ( new EmployeeProfilesRepository() )->save_for_contact( $contact_id, $this->request_payload( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		do_action(
			'asdl_fin_employee_profile_saved',
			array(
				'id'         => (int) $result,
				'contact_id' => $contact_id,
			)
		);

		return $this->success_response(
			array(
				'id'        => (int) $result,
				'message'   => 'Configuracion laboral guardada correctamente.',
				'operation' => $this->build_operation_payload(
					'save_employee_profile',
					array(
						'contact_id' => $contact_id,
						'entity_id'  => (int) $result,
					)
				),
				'employee_profile' => ( new EmployeeProfilesRepository() )->find_by_contact_id( $contact_id ),
			),
			array(
				'contact_id' => $contact_id,
			)
		);
	}

	public function salary_advances( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$limit      = (int) $request->get_param( 'limit' );
		$limit      = $limit > 0 ? $limit : 20;
		$repository = new EmployeeAdvancesRepository();
		$items      = $repository->for_contact( $contact_id, $limit );

		return $this->success_response(
			array(
				'items'   => $items,
				'summary' => $repository->summary_for_contact( $contact_id ),
			),
			array(
				'count'      => count( $items ),
				'limit'      => $limit,
				'contact_id' => $contact_id,
			)
		);
	}

	public function salary_advance_detail( WP_REST_Request $request ) {
		$advance_id  = (int) $request['id'];
		$repository  = new EmployeeAdvancesRepository();
		$advance     = $repository->find( $advance_id );

		if ( empty( $advance['id'] ) ) {
			return new \WP_Error( 'asdl_fin_salary_advance_missing', 'No se encontro el adelanto solicitado.', array( 'status' => 404 ) );
		}

		$contact = ! empty( $advance['contact_id'] ) ? ( new ContactsRepository() )->find( (int) $advance['contact_id'] ) : null;
		$payment = ! empty( $advance['payment_id'] ) ? ( new PaymentsRepository() )->find( (int) $advance['payment_id'] ) : null;

		return $this->success_response(
			array(
				'salary_advance' => $advance,
				'contact'        => $this->contact_summary( $contact ),
				'payment'        => $payment,
				'receipt'        => array(
					'type' => 'salary_advance',
					'id'   => $advance_id,
				),
			),
			array(
				'salary_advance_id' => $advance_id,
				'traceable_by'      => array( 'payment_id', 'receipt_type' ),
			)
		);
	}

	public function create_salary_advance( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$repository = new EmployeeAdvancesRepository();
		$result     = $repository->create_for_contact( $contact_id, $this->request_payload( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new EventsRepository() )->log(
			'salary_advance',
			(int) $result,
			'created',
			'Adelanto de sueldo registrado correctamente.',
			array(
				'entity_type' => 'salary_advance',
				'entity_id'   => (int) $result,
				'origin'      => 'api',
				'contact_id'  => $contact_id,
			)
		);

		do_action(
			'asdl_fin_salary_advance_saved',
			array(
				'id'         => (int) $result,
				'contact_id' => $contact_id,
			)
		);

		return $this->success_response(
			array(
				'id'             => (int) $result,
				'message'        => 'Adelanto de sueldo registrado correctamente.',
				'operation'      => $this->build_operation_payload(
					'create_salary_advance',
					array(
						'contact_id' => $contact_id,
						'entity_id'  => (int) $result,
					)
				),
				'salary_advance' => $repository->find( $result ),
			),
			array(
				'contact_id' => $contact_id,
			)
		);
	}

	public function cancel_salary_advance( WP_REST_Request $request ) {
		$advance_id = (int) $request['id'];
		$payload    = $this->request_payload( $request );
		$reason     = sanitize_textarea_field( (string) ( $payload['cancel_reason'] ?? $payload['reason'] ?? '' ) );
		$result     = ( new CancellationService() )->cancel_salary_advance(
			$advance_id,
			$reason,
			array(
				'origin' => 'api',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array(
				'message'   => 'Adelanto anulado correctamente.',
				'operation' => $this->build_operation_payload(
					'cancel_salary_advance',
					array(
						'payment_id' => (int) ( $result['payment_id'] ?? 0 ),
						'entity_id'  => $advance_id,
						'contact_id' => (int) ( $result['contact_id'] ?? 0 ),
					)
				),
				'result'    => $result,
			),
			array(
				'traceable_by' => array( 'payment_id', 'operation_id' ),
			)
		);
	}

	public function payroll_periods( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$limit      = (int) $request->get_param( 'limit' );
		$limit      = $limit > 0 ? $limit : 20;
		$repository = new PayrollPeriodsRepository();
		$items      = $repository->for_contact( $contact_id, $limit );

		return $this->success_response(
			array(
				'items'   => $items,
				'summary' => $repository->summary_for_contact( $contact_id ),
			),
			array(
				'count'      => count( $items ),
				'limit'      => $limit,
				'contact_id' => $contact_id,
			)
		);
	}

	public function payroll_period_detail( WP_REST_Request $request ) {
		$payroll_id  = (int) $request['id'];
		$repository  = new PayrollPeriodsRepository();
		$period      = $repository->find( $payroll_id );

		if ( empty( $period['id'] ) ) {
			return new \WP_Error( 'asdl_fin_payroll_missing', 'No se encontro el periodo de nomina solicitado.', array( 'status' => 404 ) );
		}

		$contact          = ! empty( $period['contact_id'] ) ? ( new ContactsRepository() )->find( (int) $period['contact_id'] ) : null;
		$employee_profile = ! empty( $period['contact_id'] ) ? ( new EmployeeProfilesRepository() )->find_by_contact_id( (int) $period['contact_id'] ) : null;
		$document         = ! empty( $period['document_id'] ) ? ( new DocumentsRepository() )->find( (int) $period['document_id'] ) : null;

		return $this->success_response(
			array(
				'payroll_period'    => $period,
				'contact'           => $this->contact_summary( $contact ),
				'employee_profile'  => $employee_profile,
				'document'          => $this->document_summary( $document ),
				'receipt'           => array(
					'type' => 'payroll_period',
					'id'   => $payroll_id,
				),
			),
			array(
				'payroll_period_id' => $payroll_id,
				'traceable_by'      => array( 'payment_id', 'document_id', 'receipt_type' ),
			)
		);
	}

	public function payroll_queue( WP_REST_Request $request ) {
		$limit     = max( 1, min( 200, (int) ( $request->get_param( 'limit' ) ?: 80 ) ) );
		$from_date = sanitize_text_field( (string) $request->get_param( 'from_date' ) );
		$to_date   = sanitize_text_field( (string) $request->get_param( 'to_date' ) );
		$search    = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$raw_search = $search;
		$queue     = ( new PayrollQueueService() )->get_snapshot(
			array(
				'from_date' => $from_date,
				'to_date'   => $to_date,
				'limit'     => $limit,
			)
		);
		$items     = array_values( (array) ( $queue['items'] ?? array() ) );

		if ( '' !== $search ) {
			$search = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
			$items  = array_values(
				array_filter(
					$items,
					static function ( array $item ) use ( $search ) {
						$haystack = implode(
							' ',
							array(
								(string) ( $item['display_name'] ?? '' ),
								(string) ( $item['email'] ?? '' ),
								(string) ( $item['pay_frequency'] ?? '' ),
							)
						);
						$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

						return false !== strpos( $haystack, $search );
					}
				)
			);
		}

		return $this->success_response(
			array(
				'items'   => $items,
				'summary' => $queue['summary'] ?? array(),
			),
			array(
				'count'   => count( $items ),
				'limit'   => $limit,
				'filters' => array(
					'from_date' => $queue['filters']['from_date'] ?? $from_date,
					'to_date'   => $queue['filters']['to_date'] ?? $to_date,
					'search'    => $raw_search,
				),
			)
		);
	}

	public function create_payroll_period( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$repository = new PayrollPeriodsRepository();
		$result     = $repository->create_for_contact( $contact_id, $this->request_payload( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new EventsRepository() )->log(
			'payroll_period',
			(int) $result,
			'created',
			'Periodo de nomina generado correctamente.',
			array(
				'entity_type' => 'payroll_period',
				'entity_id'   => (int) $result,
				'origin'      => 'api',
				'contact_id'  => $contact_id,
			)
		);

		do_action(
			'asdl_fin_payroll_period_created',
			array(
				'id'         => (int) $result,
				'contact_id' => $contact_id,
			)
		);

		return $this->success_response(
			array(
				'id'             => (int) $result,
				'message'        => 'Periodo de nomina generado correctamente.',
				'operation'      => $this->build_operation_payload(
					'create_payroll_period',
					array(
						'contact_id' => $contact_id,
						'entity_id'  => (int) $result,
					)
				),
				'payroll_period' => $repository->find( $result ),
			),
			array(
				'contact_id' => $contact_id,
			)
		);
	}

	public function mark_payroll_period_paid( WP_REST_Request $request ) {
		$payroll_id  = (int) $request['id'];
		$repository  = new PayrollPeriodsRepository();
		$result      = $repository->mark_paid( $payroll_id, $this->request_payload( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new EventsRepository() )->log(
			'payroll_period',
			(int) $payroll_id,
			'paid',
			'Periodo de nomina procesado correctamente.',
			array(
				'entity_type' => 'payroll_period',
				'entity_id'   => (int) $payroll_id,
				'origin'      => 'api',
			)
		);

		do_action( 'asdl_fin_payroll_period_paid', $result );

		return $this->success_response(
			array(
				'id'             => (int) $payroll_id,
				'message'        => 'Periodo de nomina procesado correctamente.',
				'operation'      => $this->build_operation_payload(
					'mark_payroll_period_paid',
					array(
						'contact_id' => (int) ( $result['contact_id'] ?? 0 ),
						'payment_id' => (int) ( $result['payment_id'] ?? 0 ),
						'entity_id'  => (int) $payroll_id,
					)
				),
				'payroll_period' => $result,
			),
			array(
				'traceable_by' => array( 'payment_id', 'operation_id' ),
			)
		);
	}

	public function settle_contact_orders( WP_REST_Request $request ) {
		$payload               = $this->request_payload( $request );
		$payload['contact_id'] = (int) $request['id'];
		$result                = ( new ProfileOrderSettlementService() )->settle_oldest_first( $payload );
		$client_operation_id   = sanitize_text_field( (string) ( $payload['client_operation_id'] ?? '' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		do_action( 'asdl_fin_profile_payment_applied', $result );

		return $this->success_response(
			array(
				'message'   => 'Abono aplicado correctamente a los pedidos pendientes del perfil.',
				'operation' => $this->build_operation_payload(
					'settle_orders',
					array(
						'contact_id'          => (int) ( $result['contact_id'] ?? 0 ),
						'payment_id'          => (int) ( $result['payment_id'] ?? 0 ),
						'client_operation_id' => $client_operation_id,
					)
				),
				'result'    => array_merge(
					array(
						'requested_total' => max( 0, (float) ( $payload['total'] ?? 0 ) ),
					),
					$result
				),
			),
			array(
				'idempotency_enforced' => false,
				'traceable_by'         => array( 'payment_id', 'client_operation_id', 'operation_id' ),
			)
		);
	}

	public function preview_settle_contact_orders( WP_REST_Request $request ) {
		$payload               = $this->request_payload( $request );
		$payload['contact_id'] = (int) $request['id'];
		$result                = ( new OrderSettlementBatchService() )->preview( $payload, 'profile_settlement' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			$result,
			array(
				'preview'      => true,
				'contact_id'   => (int) $request['id'],
				'traceable_by' => array( 'contact_id' ),
			)
		);
	}

	public function contact_payroll_open_debts( WP_REST_Request $request ) {
		$contact_id = (int) $request['id'];
		$result     = ( new PayrollManualSettlementService() )->get_open_debts_for_contact( $contact_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			$result,
			array(
				'contact_id'   => $contact_id,
				'traceable_by' => array( 'contact_id' ),
			)
		);
	}

	public function apply_contact_credit( WP_REST_Request $request ) {
		$payload               = $this->request_payload( $request );
		$payload['contact_id'] = (int) $request['id'];
		$result                = ( new ProfileOrderSettlementService() )->apply_credit_balance( $payload );
		$client_operation_id   = sanitize_text_field( (string) ( $payload['client_operation_id'] ?? '' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		do_action( 'asdl_fin_profile_credit_applied', $result );

		return $this->success_response(
			array(
				'message'   => 'Saldo a favor aplicado correctamente sobre pedidos pendientes del perfil.',
				'operation' => $this->build_operation_payload(
					'apply_credit',
					array(
						'contact_id'          => (int) ( $result['contact_id'] ?? 0 ),
						'payment_ids'         => array_values(
							array_unique(
								array_merge(
									array_map( 'intval', (array) ( $result['compensation_payment_ids'] ?? array() ) ),
									array_map(
										static function ( array $payment ) {
											return (int) ( $payment['payment_id'] ?? 0 );
										},
										(array) ( $result['source_payments'] ?? array() )
									)
								)
							)
						),
						'client_operation_id' => $client_operation_id,
					)
				),
				'result'    => $result,
			),
			array(
				'idempotency_enforced' => false,
				'traceable_by'         => array( 'compensation_payment_ids', 'client_operation_id', 'operation_id' ),
			)
		);
	}

	public function create_document( WP_REST_Request $request ) {
		return $this->create_resource(
			new DocumentsRepository(),
			$request,
			'document',
			'Movimiento registrado correctamente.',
			'asdl_fin_document_created'
		);
	}

	public function update_document( WP_REST_Request $request ) {
		$document_id = (int) $request['id'];
		$payload     = $this->request_payload( $request );
		$result      = ( new DocumentsRepository() )->update_manual( $document_id, $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( array_key_exists( 'manual_override', $payload ) ) {
			( new SourceLinksRepository() )->set_override_lock_for_document( $document_id, ! empty( $payload['manual_override'] ) );
		}

		$events = new EventsRepository();
		$events->log(
			'document',
			$document_id,
			'updated',
			'Movimiento actualizado correctamente.',
			array(
				'entity_type'     => 'document',
				'entity_id'       => $document_id,
				'manual_override' => ! empty( $payload['manual_override'] ),
				'origin'          => 'api',
			)
		);

		do_action( 'asdl_fin_document_updated', $document_id );

		return rest_ensure_response(
			array(
				'data' => array(
					'id'         => $document_id,
					'message'    => 'Movimiento actualizado correctamente.',
					'operation'  => $this->build_operation_payload(
						'update_document',
						array(
							'document_id' => $document_id,
						)
					),
				),
				'meta' => array(
					'updated' => true,
				),
			)
		);
	}

	public function cancel_document( WP_REST_Request $request ) {
		$document_id = (int) $request['id'];
		$payload     = $this->request_payload( $request );
		$reason      = sanitize_textarea_field( (string) ( $payload['cancel_reason'] ?? $payload['reason'] ?? '' ) );
		$result      = ( new CancellationService() )->cancel_document(
			$document_id,
			$reason,
			array(
				'origin' => 'api',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array(
				'message'   => 'Movimiento anulado correctamente.',
				'operation' => $this->build_operation_payload(
					'cancel_document',
					array(
						'document_id' => $document_id,
						'entity_id'   => $document_id,
						'contact_id'  => (int) ( $result['contact_id'] ?? 0 ),
					)
				),
				'result'    => $result,
			),
			array(
				'traceable_by' => array( 'document_id', 'operation_id' ),
			)
		);
	}

	public function create_payment( WP_REST_Request $request ) {
		return $this->create_resource(
			new PaymentsRepository(),
			$request,
			'payment',
			'Pago o abono registrado correctamente.',
			'asdl_fin_payment_recorded'
		);
	}

	public function cancel_payment( WP_REST_Request $request ) {
		$payment_id = (int) $request['id'];
		$payload    = $this->request_payload( $request );
		$reason     = sanitize_textarea_field( (string) ( $payload['cancel_reason'] ?? $payload['reason'] ?? '' ) );
		$result     = ( new CancellationService() )->cancel_payment(
			$payment_id,
			$reason,
			array(
				'origin' => 'api',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array(
				'message'   => 'Pago anulado correctamente.',
				'operation' => $this->build_operation_payload(
					'cancel_payment',
					array(
						'payment_id' => $payment_id,
						'entity_id'  => $payment_id,
						'contact_id' => (int) ( $result['contact_id'] ?? 0 ),
					)
				),
				'result'    => $result,
			),
			array(
				'traceable_by' => array( 'payment_id', 'operation_id' ),
			)
		);
	}

	public function create_rule( WP_REST_Request $request ) {
		return $this->create_resource(
			new RulesRepository(),
			$request,
			'rule',
			'Regla registrada correctamente.',
			'asdl_fin_rule_created'
		);
	}

	public function create_payment_allocation( WP_REST_Request $request ) {
		$service = new PaymentAllocationService();
		$result  = $service->allocate( $this->request_payload( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$events = new EventsRepository();
		$events->log(
			'payment_allocation',
			(int) $result['allocation_id'],
			'created',
			'Asignacion registrada correctamente.',
			array_merge(
				$result,
				array(
					'origin' => 'api',
				)
			)
		);

		do_action( 'asdl_fin_payment_allocated', $result );

		return $this->success_response(
			array(
				'id'         => (int) $result['allocation_id'],
				'message'    => 'Asignacion registrada correctamente.',
				'operation'  => $this->build_operation_payload(
					'create_payment_allocation',
					array(
						'payment_id'  => (int) ( $result['payment_id'] ?? 0 ),
						'document_id' => (int) ( $result['document_id'] ?? 0 ),
						'entity_id'   => (int) ( $result['allocation_id'] ?? 0 ),
					)
				),
				'allocation' => $result,
			),
			array(
				'traceable_by' => array( 'payment_id', 'operation_id' ),
			)
		);
	}

	public function create_installment_plan( WP_REST_Request $request ) {
		return $this->create_resource(
			new InstallmentPlansRepository(),
			$request,
			'installment_plan',
			'Compromiso registrado correctamente.',
			'asdl_fin_installment_plan_created'
		);
	}

	public function apply_installment_plan_payment( WP_REST_Request $request ) {
		$payload            = $this->request_payload( $request );
		$payload['plan_id'] = (int) $request['id'];
		$result             = ( new CommitmentSettlementService() )->apply( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new EventsRepository() )->log(
			'installment_plan',
			(int) $result['plan_id'],
			'payment_applied',
			'Movimiento aplicado correctamente sobre el compromiso.',
			array(
				'entity_type' => 'installment_plan',
				'entity_id'   => (int) $result['plan_id'],
				'origin'      => 'api',
				'payment_id'  => (int) ( $result['payment_id'] ?? 0 ),
				'applied_total' => (float) ( $result['applied_total'] ?? 0 ),
			)
		);

		do_action( 'asdl_fin_commitment_payment_applied', $result );

		return $this->success_response(
			array(
				'id'         => (int) $result['plan_id'],
				'message'    => 'Movimiento aplicado correctamente sobre el compromiso.',
				'operation'  => $this->build_operation_payload(
					'apply_installment_plan_payment',
					array(
						'payment_id' => (int) ( $result['payment_id'] ?? 0 ),
						'plan_id'    => (int) ( $result['plan_id'] ?? 0 ),
					)
				),
				'result'     => $result,
			),
			array(
				'traceable_by' => array( 'payment_id', 'operation_id' ),
			)
		);
	}

	public function cancel_installment_plan( WP_REST_Request $request ) {
		$plan_id = (int) $request['id'];
		$payload = $this->request_payload( $request );
		$reason  = sanitize_textarea_field( (string) ( $payload['cancel_reason'] ?? $payload['reason'] ?? '' ) );
		$result  = ( new CancellationService() )->cancel_installment_plan(
			$plan_id,
			$reason,
			array(
				'origin' => 'api',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array(
				'message'   => 'Compromiso anulado correctamente.',
				'operation' => $this->build_operation_payload(
					'cancel_installment_plan',
					array(
						'plan_id'     => $plan_id,
						'entity_id'   => $plan_id,
						'document_id' => (int) ( $result['document_id'] ?? 0 ),
						'contact_id'  => (int) ( $result['contact_id'] ?? 0 ),
					)
				),
				'result'    => $result,
			),
			array(
				'traceable_by' => array( 'plan_id', 'document_id', 'operation_id' ),
			)
		);
	}

	public function can_manage() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_FINANCE );
	}

	public function can_access_mobile() {
		return CapabilityManager::current_user_can_access_mobile();
	}

	public function can_view_dashboard() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::VIEW_DASHBOARD );
	}

	public function can_view_accounts() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::VIEW_ACCOUNTS );
	}

	public function can_manage_accounts() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_ACCOUNTS );
	}

	public function can_view_profiles() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::VIEW_PROFILES );
	}

	public function can_manage_profiles() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_PROFILES );
	}

	public function can_view_documents() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::VIEW_DOCUMENTS );
	}

	public function can_manage_documents() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_DOCUMENTS );
	}

	public function can_view_payments() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::VIEW_PAYMENTS );
	}

	public function can_manage_payments() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_PAYMENTS );
	}

	public function can_manage_collections() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_COLLECTIONS );
	}

	public function can_view_commitments() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::VIEW_COMMITMENTS );
	}

	public function can_manage_commitments() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_COMMITMENTS );
	}

	public function can_view_payroll() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::VIEW_PAYROLL );
	}

	public function can_view_receipt( WP_REST_Request $request ) {
		$receipt_type = sanitize_key( (string) $request['type'] );

		if ( 'payment' === $receipt_type ) {
			return $this->can_view_payments();
		}

		if ( in_array( $receipt_type, array( 'salary_advance', 'payroll_period' ), true ) ) {
			return $this->can_view_payroll();
		}

		return $this->can_access_mobile();
	}

	public function can_manage_payroll() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_PAYROLL );
	}

	public function can_view_integrations() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::VIEW_INTEGRATIONS );
	}

	public function can_manage_automations() {
		return CapabilityManager::current_user_can_cap( CapabilityManager::MANAGE_AUTOMATIONS );
	}

	private function success_response( array $data, array $meta = array() ) {
		return rest_ensure_response(
			array(
				'data' => $data,
				'meta' => $meta,
			)
		);
	}

	private function list_response( $repository, WP_REST_Request $request ) {
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? $limit : 20;

		$items = $repository->all( $limit );

		return $this->success_response(
			array(
				'items' => $items,
			),
			array(
				'count' => count( $items ),
				'limit' => $limit,
			)
		);
	}

	private function contact_summary( $contact ) {
		if ( empty( $contact['id'] ) ) {
			return null;
		}

		return array(
			'id'                  => (int) $contact['id'],
			'display_name'        => sanitize_text_field( (string) ( $contact['display_name'] ?? '' ) ),
			'email'               => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
			'profile_origin'      => sanitize_key( (string) ( $contact['profile_origin'] ?? '' ) ),
			'wp_user_id'          => ! empty( $contact['wp_user_id'] ) ? (int) $contact['wp_user_id'] : 0,
			'profile_roles'       => array_values( (array) ( $contact['profile_roles'] ?? array() ) ),
			'profile_roles_label' => sanitize_text_field( (string) ( $contact['profile_roles_label'] ?? '' ) ),
			'status'              => sanitize_key( (string) ( $contact['status'] ?? '' ) ),
		);
	}

	private function document_summary( $document ) {
		if ( empty( $document['id'] ) ) {
			return null;
		}

		return array(
			'id'             => (int) $document['id'],
			'document_number'=> sanitize_text_field( (string) ( $document['document_number'] ?? '' ) ),
			'title'          => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
			'document_type'  => sanitize_key( (string) ( $document['document_type'] ?? '' ) ),
			'payment_status' => sanitize_key( (string) ( $document['payment_status'] ?? '' ) ),
			'balance'        => (float) ( $document['balance'] ?? 0 ),
			'currency'       => sanitize_text_field( (string) ( $document['currency'] ?? 'USD' ) ),
		);
	}

	private function build_operation_payload( $type, array $data = array() ) {
		$base = array(
			'type'   => sanitize_key( (string) $type ),
			'status' => 'completed',
		);
		$seed = array( $base['type'] );

		foreach ( array( 'contact_id', 'payment_id', 'entity_id', 'plan_id', 'document_id' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$seed[] = (int) $data[ $key ];
			}
		}

		if ( ! empty( $data['payment_ids'] ) && is_array( $data['payment_ids'] ) ) {
			$seed = array_merge( $seed, array_map( 'intval', $data['payment_ids'] ) );
		}

		$base['operation_id'] = sanitize_key( $base['type'] ) . ':' . md5( wp_json_encode( $seed ) );

		if ( ! empty( $data['client_operation_id'] ) ) {
			$base['client_operation_id'] = sanitize_text_field( (string) $data['client_operation_id'] );
		}

		foreach ( array( 'contact_id', 'payment_id', 'entity_id', 'plan_id', 'document_id' ) as $key ) {
			if ( ! empty( $data[ $key ] ) ) {
				$base[ $key ] = (int) $data[ $key ];
			}
		}

		if ( ! empty( $data['payment_ids'] ) && is_array( $data['payment_ids'] ) ) {
			$base['payment_ids'] = array_values( array_map( 'intval', $data['payment_ids'] ) );
		}

		return $base;
	}

	private function create_resource( $repository, WP_REST_Request $request, $entity_type, $message, $hook_name ) {
		$data   = $this->request_payload( $request );
		$result = $repository->create( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$events = new EventsRepository();
		$events->log(
			$entity_type,
			(int) $result,
			'created',
			$message,
			array(
				'entity_type' => $entity_type,
				'entity_id'   => (int) $result,
				'origin'      => 'api',
			)
		);

		do_action( $hook_name, (int) $result );

		return $this->success_response(
			array(
				'id'          => (int) $result,
				'message'     => $message,
				'entity_type' => sanitize_key( (string) $entity_type ),
				'operation'   => $this->build_operation_payload(
					'create_' . sanitize_key( (string) $entity_type ),
					array(
						'entity_id'  => (int) $result,
						'contact_id' => ! empty( $data['contact_id'] ) ? (int) $data['contact_id'] : 0,
						'document_id'=> 'document' === sanitize_key( (string) $entity_type ) ? (int) $result : 0,
						'payment_id' => 'payment' === sanitize_key( (string) $entity_type ) ? (int) $result : 0,
						'plan_id'    => 'installment_plan' === sanitize_key( (string) $entity_type ) ? (int) $result : 0,
					)
				),
			),
			array(
				'created' => true,
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

	private function resolve_fiscal_range( WP_REST_Request $request ) {
		$fiscal_service = new FiscalYearService();
		$selected_year  = absint( $request->get_param( 'fiscal_year' ) );
		$context        = $fiscal_service->get_context( $selected_year );
		$range_from     = $this->sanitize_date( (string) $request->get_param( 'range_from' ) );
		$range_to       = $this->sanitize_date( (string) $request->get_param( 'range_to' ) );

		if ( ! $range_from || $range_from < $context['start_date'] ) {
			$range_from = $context['start_date'];
		}

		if ( ! $range_to || $range_to > $context['end_date'] ) {
			$range_to = $context['end_date'];
		}

		if ( $range_from > $range_to ) {
			$range_from = $context['start_date'];
			$range_to   = $context['end_date'];
		}

		return array(
			'range_from'    => $range_from,
			'range_to'      => $range_to,
			'fiscal_context'=> $context,
			'read_only'     => empty( $context['is_active'] ),
		);
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}
}
