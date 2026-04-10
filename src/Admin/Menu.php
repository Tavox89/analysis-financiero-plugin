<?php

namespace ASDLabs\Finance\Admin;

use ASDLabs\Finance\Core\Contracts\Module;
use ASDLabs\Finance\Core\Schema;
use ASDLabs\Finance\Core\SchemaInstaller;
use ASDLabs\Finance\Finance\AccountsRepository;
use ASDLabs\Finance\Finance\BalanceAuditService;
use ASDLabs\Finance\Finance\CompanyExpensesService;
use ASDLabs\Finance\Finance\CommitmentSettlementService;
use ASDLabs\Finance\Finance\ContactOverviewService;
use ASDLabs\Finance\Finance\ContactsRepository;
use ASDLabs\Finance\Finance\CurrenciesService;
use ASDLabs\Finance\Finance\DocumentFilesRepository;
use ASDLabs\Finance\Finance\DocumentsRepository;
use ASDLabs\Finance\Finance\EmployeeAdvancesRepository;
use ASDLabs\Finance\Finance\EventsRepository;
use ASDLabs\Finance\Finance\FinancialMasterReportService;
use ASDLabs\Finance\Finance\FiscalYearService;
use ASDLabs\Finance\Finance\IntegrationStatusService;
use ASDLabs\Finance\Finance\InstallmentPlansRepository;
use ASDLabs\Finance\Finance\MasterReportBatchService;
use ASDLabs\Finance\Finance\MoneyStateService;
use ASDLabs\Finance\Finance\MonthlyCloseSnapshotService;
use ASDLabs\Finance\Finance\PendingCollectionsService;
use ASDLabs\Finance\Finance\PaymentAllocationsRepository;
use ASDLabs\Finance\Finance\PaymentsRepository;
use ASDLabs\Finance\Finance\PaymentMethodsService;
use ASDLabs\Finance\Finance\EmployeeProfilesRepository;
use ASDLabs\Finance\Finance\HistoricalIndexRebuildService;
use ASDLabs\Finance\Finance\HistoricalResolutionBatchesRepository;
use ASDLabs\Finance\Finance\HistoricalResolutionService;
use ASDLabs\Finance\Finance\IntegrityCasesRepository;
use ASDLabs\Finance\Finance\IntegrityMonitorModule;
use ASDLabs\Finance\Finance\IntegrityMonitorService;
use ASDLabs\Finance\Finance\OrderAssumptionBatchService;
use ASDLabs\Finance\Finance\OrderSettlementBatchService;
use ASDLabs\Finance\Finance\PayrollPeriodsRepository;
use ASDLabs\Finance\Finance\PayrollQueueService;
use ASDLabs\Finance\Finance\PendingPayablesService;
use ASDLabs\Finance\Finance\ProductMarginCheckService;
use ASDLabs\Finance\Finance\ReceiptBrandingService;
use ASDLabs\Finance\Finance\ReceiptService;
use ASDLabs\Finance\Finance\RulesRepository;
use ASDLabs\Finance\Finance\RuntimeRefreshService;
use ASDLabs\Finance\Finance\OverviewService;
use ASDLabs\Finance\Finance\ServiceProfilesRepository;
use ASDLabs\Finance\Integrations\Woo\DualPricingService;
use ASDLabs\Finance\Finance\SourceLinksRepository;

final class Menu implements Module {
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_menu', array( $this, 'remove_legacy_submenus' ), 999 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'wp_ajax_asdl_fin_admin_runtime', array( $this, 'ajax_admin_runtime' ) );
		add_action( 'wp_ajax_asdl_fin_dashboard_runtime', array( $this, 'ajax_dashboard_runtime' ) );
		add_action( 'wp_ajax_asdl_fin_contact_detail_runtime', array( $this, 'ajax_contact_detail_runtime' ) );
		add_action( 'wp_ajax_asdl_fin_receivable_group_detail', array( $this, 'ajax_receivable_group_detail' ) );
		add_action( 'wp_ajax_asdl_fin_historical_index_status', array( $this, 'ajax_historical_index_status' ) );
		add_action( 'wp_ajax_asdl_fin_historical_index_start', array( $this, 'ajax_historical_index_start' ) );
		add_action( 'wp_ajax_asdl_fin_historical_index_continue', array( $this, 'ajax_historical_index_continue' ) );
		add_action( 'wp_ajax_asdl_fin_historical_index_diagnostics', array( $this, 'ajax_historical_index_diagnostics' ) );
		add_action( 'wp_ajax_asdl_fin_historical_index_rollups', array( $this, 'ajax_historical_index_rollups' ) );
		add_action( 'wp_ajax_asdl_fin_historical_index_compact', array( $this, 'ajax_historical_index_compact' ) );
		add_action( 'wp_ajax_asdl_fin_balance_audit', array( $this, 'ajax_balance_audit' ) );
		add_action( 'wp_ajax_asdl_fin_master_report_start', array( $this, 'ajax_master_report_start' ) );
		add_action( 'wp_ajax_asdl_fin_master_report_continue', array( $this, 'ajax_master_report_continue' ) );
		add_action( 'wp_ajax_asdl_fin_master_report_status', array( $this, 'ajax_master_report_status' ) );
		add_action( 'wp_ajax_asdl_fin_master_report_result', array( $this, 'ajax_master_report_result' ) );
		add_action( 'wp_ajax_asdl_fin_product_margin_check_start', array( $this, 'ajax_product_margin_check_start' ) );
		add_action( 'wp_ajax_asdl_fin_product_margin_check_continue', array( $this, 'ajax_product_margin_check_continue' ) );
		add_action( 'wp_ajax_asdl_fin_product_margin_check_status', array( $this, 'ajax_product_margin_check_status' ) );
		add_action( 'wp_ajax_asdl_fin_product_margin_check_result', array( $this, 'ajax_product_margin_check_result' ) );
		add_action( 'wp_ajax_asdl_fin_product_margin_update_cost', array( $this, 'ajax_product_margin_update_cost' ) );
		add_action( 'wp_ajax_asdl_fin_product_margin_discard_row', array( $this, 'ajax_product_margin_discard_row' ) );
		add_action( 'wp_ajax_asdl_fin_product_margin_reinstate_row', array( $this, 'ajax_product_margin_reinstate_row' ) );
		add_action( 'wp_ajax_asdl_fin_product_margin_discard_no_stock_visible', array( $this, 'ajax_product_margin_discard_no_stock_visible' ) );
		add_action( 'wp_ajax_asdl_fin_historical_resolution_status', array( $this, 'ajax_historical_resolution_status' ) );
		add_action( 'wp_ajax_asdl_fin_historical_resolution_preview', array( $this, 'ajax_historical_resolution_preview' ) );
		add_action( 'wp_ajax_asdl_fin_historical_resolution_start', array( $this, 'ajax_historical_resolution_start' ) );
		add_action( 'wp_ajax_asdl_fin_historical_resolution_continue', array( $this, 'ajax_historical_resolution_continue' ) );
		add_action( 'wp_ajax_asdl_fin_historical_resolution_batch_detail', array( $this, 'ajax_historical_resolution_batch_detail' ) );
		add_action( 'wp_ajax_asdl_fin_order_settlement_preview', array( $this, 'ajax_order_settlement_preview' ) );
		add_action( 'wp_ajax_asdl_fin_order_settlement_start', array( $this, 'ajax_order_settlement_start' ) );
		add_action( 'wp_ajax_asdl_fin_order_settlement_continue', array( $this, 'ajax_order_settlement_continue' ) );
		add_action( 'wp_ajax_asdl_fin_order_settlement_status', array( $this, 'ajax_order_settlement_status' ) );
		add_action( 'wp_ajax_asdl_fin_order_settlement_result', array( $this, 'ajax_order_settlement_result' ) );
		add_action( 'wp_ajax_asdl_fin_order_assumption_preview', array( $this, 'ajax_order_assumption_preview' ) );
		add_action( 'wp_ajax_asdl_fin_order_assumption_start', array( $this, 'ajax_order_assumption_start' ) );
		add_action( 'wp_ajax_asdl_fin_order_assumption_continue', array( $this, 'ajax_order_assumption_continue' ) );
		add_action( 'wp_ajax_asdl_fin_order_assumption_status', array( $this, 'ajax_order_assumption_status' ) );
		add_action( 'wp_ajax_asdl_fin_order_assumption_result', array( $this, 'ajax_order_assumption_result' ) );
		add_action( 'wp_ajax_asdl_fin_order_assumption_reverse_item', array( $this, 'ajax_order_assumption_reverse_item' ) );
		add_action( 'wp_ajax_asdl_fin_order_assumption_reverse_batch', array( $this, 'ajax_order_assumption_reverse_batch' ) );
		add_action( 'wp_ajax_asdl_fin_search_wp_users', array( $this, 'ajax_search_wp_users' ) );
	}

	public function register_menu() {
		$capability = $this->get_capability();

		add_menu_page(
			'Finanzas ASD',
			'Finanzas ASD',
			$capability,
			'asdl-finanzas',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-pie',
			56
		);

		add_submenu_page(
			'asdl-finanzas',
			'Dashboard',
			'Dashboard',
			$capability,
			'asdl-finanzas',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'asdl-finanzas',
			'Movimientos',
			'Movimientos',
			$capability,
			'asdl-fin-documents',
			array( $this, 'render_documents_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Gastos',
			'Gastos',
			$capability,
			'asdl-fin-expenses',
			array( $this, 'render_expenses_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Perfiles y terceros',
			'Perfiles y terceros',
			$capability,
			'asdl-fin-contacts',
			array( $this, 'render_contacts_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Servicios',
			'Servicios',
			$capability,
			'asdl-fin-services',
			array( $this, 'render_services_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Cuentas internas',
			'Cuentas internas',
			$capability,
			'asdl-fin-accounts',
			array( $this, 'render_accounts_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Compras',
			'Compras',
			$capability,
			'asdl-fin-purchases',
			array( $this, 'render_purchases_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Mercancia por recibir',
			'Mercancia por recibir',
			$capability,
			'asdl-fin-incoming',
			array( $this, 'render_incoming_goods_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Cobros y pagos',
			'Cobros y pagos',
			$capability,
			'asdl-fin-payments',
			array( $this, 'render_payments_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Compromisos',
			'Compromisos',
			$capability,
			'asdl-fin-installments',
			array( $this, 'render_installments_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Nomina',
			'Nomina',
			$capability,
			'asdl-fin-payroll',
			array( $this, 'render_payroll_page' )
		);

		add_submenu_page(
			'asdl-finanzas',
			'Reportes',
			'Reportes',
			$capability,
			'asdl-fin-reports',
			array( $this, 'render_reports' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Productos y margenes',
			'Productos y margenes',
			$capability,
			'asdl-fin-product-margins',
			array( $this, 'render_product_margins_page' )
		);

		add_submenu_page(
			'asdl-finanzas',
			'Integraciones',
			'Integraciones',
			$capability,
			'asdl-fin-integrations',
			array( $this, 'render_integrations_page' )
		);
		add_submenu_page(
			'asdl-finanzas',
			'Integridad financiera',
			'Integridad financiera',
			$capability,
			'asdl-fin-integrity',
			array( $this, 'render_integrity_page' )
		);

		add_submenu_page(
			'asdl-finanzas',
			'Automatizaciones',
			'Automatizaciones',
			$capability,
			'asdl-fin-rules',
			array( $this, 'render_rules_page' )
		);
		
		add_submenu_page(
			'asdl-finanzas',
			'API y documentacion',
			'API y documentacion',
			$capability,
			'asdl-fin-docs',
			array( $this, 'render_docs' )
		);

		add_submenu_page(
			'asdl-finanzas',
			'Configuracion',
			'Configuracion',
			$capability,
			'asdl-fin-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			null,
			'Comprobante',
			'Comprobante',
			$capability,
			'asdl-fin-receipt',
			array( $this, 'render_receipt_page' )
		);
		add_submenu_page(
			null,
			'Reporte Maestro',
			'Reporte Maestro',
			$capability,
			'asdl-fin-report-print',
			array( $this, 'render_report_print_page' )
		);
	}

	public function remove_legacy_submenus() {
		remove_submenu_page( 'woocommerce', 'analysis-financiero' );
		remove_submenu_page( 'woocommerce', 'analysis-financiero-settings' );
	}

	public function render_notice() {
		if ( ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'asdl-fin' ) ) {
			return;
		}

		$type = isset( $_GET['asdl_fin_notice'] ) ? sanitize_key( wp_unslash( $_GET['asdl_fin_notice'] ) ) : '';
		$text = isset( $_GET['asdl_fin_notice_text'] ) ? sanitize_text_field( wp_unslash( $_GET['asdl_fin_notice_text'] ) ) : '';

		if ( '' === $type || '' === $text ) {
			return;
		}

		$class = 'error' === $type ? 'notice notice-error' : 'notice notice-success is-dismissible';

		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}

	public function ajax_dashboard_runtime() {
		check_ajax_referer( 'asdl_fin_dashboard_runtime' );

		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => 'No tienes permisos para cargar este bloque.',
				),
				403
			);
		}

		$section      = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		$fiscal_range = $this->normalize_runtime_fiscal_range( $_POST );

		if ( '' === $section ) {
			wp_send_json_error(
				array(
					'message' => 'Bloque no valido.',
				),
				400
			);
		}

		try {
			ob_start();
			$this->render_dashboard_runtime_section( $section, $fiscal_range );
			$html = (string) ob_get_clean();
			wp_send_json_success(
				array(
					'html' => $html,
				)
			);
		} catch ( \Throwable $exception ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			wp_send_json_error(
				array(
					'message' => $this->dashboard_runtime_error_message( $section ),
				),
				500
			);
		}
	}

	public function ajax_admin_runtime() {
		check_ajax_referer( 'asdl_fin_admin_runtime' );

		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => 'No tienes permisos para cargar este bloque.',
				),
				403
			);
		}

		$page_key    = isset( $_POST['page_key'] ) ? sanitize_key( wp_unslash( $_POST['page_key'] ) ) : '';
		$section_key = isset( $_POST['section_key'] ) ? sanitize_key( wp_unslash( $_POST['section_key'] ) ) : '';

		if ( '' === $page_key || '' === $section_key ) {
			wp_send_json_error(
				array(
					'message' => 'Bloque runtime no valido.',
				),
				400
			);
		}

		try {
			ob_start();
			$this->render_admin_runtime_section( $page_key, $section_key, $_POST );
			$html = (string) ob_get_clean();

			wp_send_json_success(
				array(
					'html' => $html,
				)
			);
		} catch ( \Throwable $exception ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			wp_send_json_error(
				array(
					'message' => $this->admin_runtime_error_message( $page_key, $section_key ),
				),
				500
			);
		}
	}

	public function ajax_contact_detail_runtime() {
		check_ajax_referer( 'asdl_fin_contact_detail_runtime' );

		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => 'No tienes permisos para cargar este perfil.',
				),
				403
			);
		}

		$contact_id = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
		if ( $contact_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => 'Perfil no valido.',
				),
				400
			);
		}

		$fiscal_range = $this->normalize_runtime_fiscal_range( $_POST );
		$order_limit  = isset( $_POST['order_limit'] ) ? absint( wp_unslash( $_POST['order_limit'] ) ) : 25;

		try {
			$snapshot = ( new ContactOverviewService() )->get_contact_snapshot_cached(
				$contact_id,
				array(
					'range_from' => $fiscal_range['range_from'],
					'range_to'   => $fiscal_range['range_to'],
					'order_limit'=> $order_limit > 0 ? $order_limit : 25,
				)
			);

			if ( empty( $snapshot ) ) {
				wp_send_json_error(
					array(
						'message' => 'No encontramos el perfil solicitado.',
					),
					404
				);
			}

			ob_start();
			$this->render_contact_detail( $snapshot );
			$html = (string) ob_get_clean();
			wp_send_json_success(
				array(
					'html' => $html,
				)
			);
		} catch ( \Throwable $exception ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			wp_send_json_error(
				array(
					'message' => 'No se pudo cargar el perfil completo. Intenta de nuevo o reduce el rango consultado.',
				),
				500
			);
		}
	}

	private function admin_runtime_error_message( $page_key, $section_key ) {
		switch ( $page_key . ':' . $section_key ) {
			case 'dashboard:summary-core':
				return 'No se pudo cargar el resumen operativo.';
			case 'dashboard:summary-finance':
				return 'No se pudo cargar el resumen financiero.';
			case 'dashboard:summary-payroll':
				return 'No se pudo cargar el resumen corto de nomina.';
			case 'dashboard:core':
				return 'No se pudo cargar el estado del core.';
			case 'dashboard:pending-receivables-summary':
			case 'dashboard:pending-receivables-table':
				return 'No se pudo cargar pendientes por cobrar.';
			case 'dashboard:pending-payables-summary':
			case 'dashboard:pending-payables-table':
				return 'No se pudo cargar pendientes por pagar.';
			case 'dashboard:third-parties':
				return 'No se pudo cargar el directorio corto de terceros.';
			case 'dashboard:recent-activity':
				return 'No se pudo cargar la actividad reciente.';
			case 'dashboard:payroll-queue':
				return 'No se pudo cargar la cola rapida de nomina.';
			case 'accounts:accounts-table':
				return 'No se pudo cargar la tabla de cuentas.';
			case 'documents:documents-table':
				return 'No se pudo cargar la tabla de movimientos.';
			case 'expenses:expenses-summary':
			case 'expenses:expenses-table':
				return 'No se pudo cargar el modulo de gastos.';
			case 'payments:payments-table':
				return 'No se pudo cargar la tabla de cobros y pagos.';
			case 'payments:allocations-table':
				return 'No se pudo cargar las asignaciones recientes.';
			case 'payroll:payroll-summary':
			case 'payroll:payroll-queue':
			case 'payroll:payroll-directory':
				return 'No se pudo cargar la operativa de nomina.';
			case 'installments:installments-table':
				return 'No se pudo cargar la tabla de compromisos.';
			case 'contacts:contacts-table':
				return 'No se pudo cargar la tabla de perfiles y terceros.';
			case 'contacts:profile-header-summary':
				return 'No se pudo cargar el encabezado del perfil.';
			case 'contacts:profile-financial-cards':
				return 'No se pudieron cargar las tarjetas financieras del perfil.';
			case 'contacts:profile-orders-summary':
				return 'No se pudo cargar el resumen de pedidos del perfil.';
			case 'contacts:profile-orders-table':
				return 'No se pudo cargar la operativa de pedidos del perfil.';
			case 'contacts:profile-account-state':
				return 'No se pudo cargar el estado de cuenta del perfil.';
			case 'contacts:profile-payments':
				return 'No se pudo cargar los pagos del perfil.';
			case 'contacts:profile-payroll':
				return 'No se pudo cargar la seccion laboral del perfil.';
			case 'contacts:profile-history':
				return 'No se pudo cargar el historico del perfil.';
			case 'contacts:contact-full':
				return 'No se pudo cargar el perfil completo.';
			case 'services:services-summary':
			case 'services:services-queue':
			case 'services:services-profiles':
			case 'services:services-directory':
			case 'services:services-open-documents':
			case 'services:services-documents':
				return 'No se pudo cargar el modulo de servicios.';
			case 'integrations:integrations-summary':
			case 'integrations:integrations-pending-orders':
			case 'integrations:integrations-status':
			case 'integrations:integrations-links':
				return 'No se pudo cargar la informacion de integraciones.';
			case 'rules:rules-table':
				return 'No se pudo cargar la tabla de automatizaciones.';
			default:
				return 'No se pudo cargar este bloque.';
		}
	}

	public function ajax_receivable_group_detail() {
		check_ajax_referer( 'asdl_fin_receivable_group_detail' );

		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => 'No tienes permisos para cargar este detalle.',
				),
				403
			);
		}

		$fiscal_range = $this->normalize_runtime_fiscal_range( $_POST );
		$contact_id   = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
		$wp_user_id   = isset( $_POST['wp_user_id'] ) ? absint( wp_unslash( $_POST['wp_user_id'] ) ) : 0;
		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$label        = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';

		if ( $contact_id <= 0 && $wp_user_id <= 0 && '' === $email ) {
			wp_send_json_error(
				array(
					'message' => 'No encontramos el grupo solicitado.',
				),
				400
			);
		}

		try {
			$items = ( new PendingCollectionsService() )->get_group_items(
				array(
					'contact_id'   => $contact_id,
					'wp_user_id'   => $wp_user_id,
					'email'        => $email,
					'display_name' => $label,
					'range_from'   => $fiscal_range['range_from'],
					'range_to'     => $fiscal_range['range_to'],
					'order_limit'  => 0,
				)
			);

			wp_send_json_success(
				array(
					'items' => $this->build_pending_collection_modal_items(
						array(
							'contact_id' => $contact_id,
							'items'      => $items,
						)
					),
				)
			);
		} catch ( \Throwable $exception ) {
			wp_send_json_error(
				array(
					'message' => 'No se pudo cargar el detalle de este grupo por cobrar.',
				),
				500
			);
		}
	}

	public function ajax_historical_index_status() {
		check_ajax_referer( 'asdl_fin_historical_index_status' );
		$this->guard_finance_admin_runtime();
		wp_send_json_success(
			array(
				'status' => ( new HistoricalIndexRebuildService() )->get_status_snapshot(),
			)
		);
	}

	public function ajax_historical_index_start() {
		check_ajax_referer( 'asdl_fin_historical_index_start' );
		$this->guard_finance_admin_runtime();

		$service = new HistoricalIndexRebuildService();
		$result  = $service->start_rebuild(
			isset( $_POST['fiscal_year'] ) ? absint( wp_unslash( $_POST['fiscal_year'] ) ) : 0,
			isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : 250,
			! empty( $_POST['force'] )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'job'    => $result,
				'status' => $service->get_status_snapshot(),
			)
		);
	}

	public function ajax_historical_index_continue() {
		check_ajax_referer( 'asdl_fin_historical_index_continue' );
		$this->guard_finance_admin_runtime();

		$service = new HistoricalIndexRebuildService();
		$result  = $service->continue_rebuild();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'job'    => $result,
				'status' => $service->get_status_snapshot(),
			)
		);
	}

	public function ajax_historical_index_diagnostics() {
		check_ajax_referer( 'asdl_fin_historical_index_status' );
		$this->guard_finance_admin_runtime();

		$service = new HistoricalIndexRebuildService();
		$result  = $service->diagnostics(
			isset( $_POST['fiscal_year'] ) ? absint( wp_unslash( $_POST['fiscal_year'] ) ) : 0
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'diagnostics' => $result,
				'status'      => $service->get_status_snapshot(),
			)
		);
	}

	public function ajax_historical_index_rollups() {
		check_ajax_referer( 'asdl_fin_historical_index_rollups' );
		$this->guard_finance_admin_runtime();

		$service = new HistoricalIndexRebuildService();
		$result  = $service->rebuild_rollups(
			isset( $_POST['fiscal_year'] ) ? absint( wp_unslash( $_POST['fiscal_year'] ) ) : 0
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'result'  => $result,
				'status'  => $service->get_status_snapshot(),
				'message' => 'Rollups historicos recalculados correctamente.',
			)
		);
	}

	public function ajax_historical_index_compact() {
		check_ajax_referer( 'asdl_fin_historical_index_compact' );
		$this->guard_finance_admin_runtime();

		$service = new HistoricalIndexRebuildService();
		$result  = $service->compact_year(
			isset( $_POST['fiscal_year'] ) ? absint( wp_unslash( $_POST['fiscal_year'] ) ) : 0
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'result'  => $result,
				'status'  => $service->get_status_snapshot(),
				'message' => 'Ano historico compactado y marcado como carryforward.',
			)
		);
	}

	public function ajax_balance_audit() {
		check_ajax_referer( 'asdl_fin_balance_audit' );
		$this->guard_finance_admin_runtime();

		$kind         = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$fiscal_range = $this->normalize_runtime_fiscal_range( $_POST );
		$service      = new BalanceAuditService();

		switch ( $kind ) {
			case 'dashboard':
				$audit = $service->audit_dashboard( $fiscal_range );
				break;

			case 'mobile':
				$audit = $service->audit_mobile( $fiscal_range );
				break;

			case 'contact':
				$contact_id = isset( $_POST['contact_id'] ) ? absint( wp_unslash( $_POST['contact_id'] ) ) : 0;
				$audit      = $service->audit_contact( $contact_id, $fiscal_range );
				break;

			default:
				wp_send_json_error(
					array(
						'message' => 'Tipo de auditoria no valido.',
					),
					400
				);
		}

		if ( is_wp_error( $audit ) ) {
			wp_send_json_error(
				array(
					'message' => $audit->get_error_message(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'audit' => $audit,
			)
		);
	}

	public function ajax_master_report_start() {
		check_ajax_referer( 'asdl_fin_master_report_start' );
		$this->guard_finance_admin_runtime();

		$service = new MasterReportBatchService();
		$job     = $service->start( $this->report_request_args_from_runtime_post() );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'job' => $job,
			)
		);
	}

	public function ajax_master_report_continue() {
		check_ajax_referer( 'asdl_fin_master_report_continue' );
		$this->guard_finance_admin_runtime();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = ( new MasterReportBatchService() )->continue_job( $job_id );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'job' => $job,
			)
		);
	}

	public function ajax_master_report_status() {
		check_ajax_referer( 'asdl_fin_master_report_status' );
		$this->guard_finance_admin_runtime();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = ( new MasterReportBatchService() )->status( $job_id );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'job' => $job,
			)
		);
	}

	public function ajax_master_report_result() {
		check_ajax_referer( 'asdl_fin_master_report_result' );
		$this->guard_finance_admin_runtime();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$result = ( new MasterReportBatchService() )->result( $job_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$payload = (array) ( $result['payload'] ?? array() );
		$versions = array_values( (array) ( $result['versions'] ?? array() ) );
		$request_args = $this->report_args_from_payload( $this->current_report_request_args(), $payload );

		wp_send_json_success(
			array(
				'job'          => $result['job'],
				'payload'      => $payload,
				'actions_html' => $this->capture_master_report_actions_html( $request_args, $payload ),
				'versions_html'=> ! empty( $versions ) ? $this->capture_master_report_versions_html( $versions, $request_args, $payload ) : '',
				'sections_html'=> $this->capture_master_report_sections_html( $payload, false ),
				'range_label'  => $this->master_report_range_label( $payload ),
			)
		);
	}

	public function ajax_product_margin_check_start() {
		check_ajax_referer( 'asdl_fin_product_margin_check_start' );
		$this->guard_finance_admin_runtime();

		$scope_kind = isset( $_POST['scope_kind'] ) ? sanitize_key( wp_unslash( $_POST['scope_kind'] ) ) : 'catalog';
		$exclude = isset( $_POST['exclude_categories_raw'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude_categories_raw'] ) ) : '';
		$job = ( new ProductMarginCheckService() )->start_job(
			array(
				'scope_kind'             => $scope_kind,
				'exclude_categories_raw' => $exclude,
			)
		);

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'job' => $job ) );
	}

	public function ajax_product_margin_check_continue() {
		check_ajax_referer( 'asdl_fin_product_margin_check_continue' );
		$this->guard_finance_admin_runtime();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = ( new ProductMarginCheckService() )->continue_job( $job_id );

		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'job' => $job ) );
	}

	public function ajax_product_margin_check_status() {
		check_ajax_referer( 'asdl_fin_product_margin_check_status' );
		$this->guard_finance_admin_runtime();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = ( new ProductMarginCheckService() )->get_job( $job_id );

		if ( empty( $job['job_id'] ) ) {
			wp_send_json_error( array( 'message' => 'No se encontro la verificacion solicitada.' ), 404 );
		}

		wp_send_json_success( array( 'job' => $job ) );
	}

	public function ajax_product_margin_check_result() {
		check_ajax_referer( 'asdl_fin_product_margin_check_result' );
		$this->guard_finance_admin_runtime();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$service = new ProductMarginCheckService();
		$result  = $service->get_result( $job_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$result['daily_certification'] = $service->get_daily_result_for_scope( (array) ( $result['scope'] ?? array() ) );

		wp_send_json_success(
			array(
				'result' => $result,
				'html'   => $this->capture_product_margin_result_html( $result ),
			)
		);
	}

	public function ajax_product_margin_update_cost() {
		check_ajax_referer( 'asdl_fin_product_margin_update_cost' );
		$this->guard_finance_admin_runtime();

		$args = array(
			'product_id'             => isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0,
			'row_signature'          => isset( $_POST['row_signature'] ) ? sanitize_text_field( wp_unslash( $_POST['row_signature'] ) ) : '',
			'cost_target_product_id' => isset( $_POST['cost_target_product_id'] ) ? absint( wp_unslash( $_POST['cost_target_product_id'] ) ) : 0,
			'category_target_product_id' => isset( $_POST['category_target_product_id'] ) ? absint( wp_unslash( $_POST['category_target_product_id'] ) ) : 0,
			'cost_meta_key'          => isset( $_POST['cost_meta_key'] ) ? sanitize_key( wp_unslash( $_POST['cost_meta_key'] ) ) : '',
			'cost'                   => isset( $_POST['cost'] ) ? (float) wp_unslash( $_POST['cost'] ) : 0,
			'regular_price'          => isset( $_POST['regular_price'] ) ? (float) wp_unslash( $_POST['regular_price'] ) : 0,
			'target_percent'         => isset( $_POST['target_percent'] ) ? (float) wp_unslash( $_POST['target_percent'] ) : 0,
			'inherit_target'         => ! empty( $_POST['inherit_target'] ),
			'strategy_mode'          => isset( $_POST['strategy_mode'] ) ? sanitize_key( wp_unslash( $_POST['strategy_mode'] ) ) : 'formula',
			'category_ids_csv'       => isset( $_POST['category_ids_csv'] ) ? sanitize_text_field( wp_unslash( $_POST['category_ids_csv'] ) ) : '',
			'scope_kind'             => isset( $_POST['scope_kind'] ) ? sanitize_key( wp_unslash( $_POST['scope_kind'] ) ) : 'catalog',
			'exclude_categories_raw' => isset( $_POST['exclude_categories_raw'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude_categories_raw'] ) ) : '',
		);

		$result = ( new ProductMarginCheckService() )->update_pricing( $args );

		if ( is_wp_error( $result ) ) {
			$data        = (array) $result->get_error_data();
			$current_row = (array) ( $data['current_row'] ?? array() );
			$http_status = 'asdl_fin_product_margin_conflict' === $result->get_error_code() ? 409 : 400;
			$response    = array(
				'message' => $result->get_error_message(),
			);

			if ( ! empty( $current_row ) ) {
				$response['current_row'] = $current_row;
				$response['row_html']    = $this->capture_product_margin_row_html( $current_row );
				$response['tab_key']     = $this->product_margin_tab_key( $current_row );
			}

			wp_send_json_error( $response, $http_status );
		}

		$row = (array) ( $result['row'] ?? array() );

		wp_send_json_success(
			array(
				'result'          => $result,
				'row'             => $row,
				'row_html'        => $this->capture_product_margin_row_html( $row ),
				'tab_key'         => $this->product_margin_tab_key( $row ),
				'message'         => sanitize_text_field( (string) ( $result['message'] ?? 'Producto actualizado correctamente.' ) ),
				'workspace_stale' => ! empty( $result['stale'] ),
			)
		);
	}

	public function ajax_product_margin_discard_row() {
		check_ajax_referer( 'asdl_fin_product_margin_discard_row' );
		$this->guard_finance_admin_runtime();

		$scope = array(
			'scope_kind'             => isset( $_POST['scope_kind'] ) ? sanitize_key( wp_unslash( $_POST['scope_kind'] ) ) : 'catalog',
			'exclude_categories_raw' => isset( $_POST['exclude_categories_raw'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude_categories_raw'] ) ) : '',
		);
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$service    = new ProductMarginCheckService();
		$result     = $service->discard_from_active_snapshot( $scope, $product_id, 'Descartado en esta vista' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$result['daily_certification'] = $service->get_daily_result_for_scope( (array) ( $result['scope'] ?? $scope ) );

		wp_send_json_success(
			array(
				'result'  => $result,
				'html'    => $this->capture_product_margin_result_html( $result ),
				'message' => 'El hallazgo se descarto solo en esta vista.',
			)
		);
	}

	public function ajax_product_margin_reinstate_row() {
		check_ajax_referer( 'asdl_fin_product_margin_reinstate_row' );
		$this->guard_finance_admin_runtime();

		$scope = array(
			'scope_kind'             => isset( $_POST['scope_kind'] ) ? sanitize_key( wp_unslash( $_POST['scope_kind'] ) ) : 'catalog',
			'exclude_categories_raw' => isset( $_POST['exclude_categories_raw'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude_categories_raw'] ) ) : '',
		);
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$service    = new ProductMarginCheckService();
		$result     = $service->reinstate_from_active_snapshot( $scope, $product_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$result['daily_certification'] = $service->get_daily_result_for_scope( (array) ( $result['scope'] ?? $scope ) );

		wp_send_json_success(
			array(
				'result'  => $result,
				'html'    => $this->capture_product_margin_result_html( $result ),
				'message' => 'El producto volvio a contarse en esta vista.',
			)
		);
	}

	public function ajax_product_margin_discard_no_stock_visible() {
		check_ajax_referer( 'asdl_fin_product_margin_discard_no_stock_visible' );
		$this->guard_finance_admin_runtime();

		$scope = array(
			'scope_kind'             => isset( $_POST['scope_kind'] ) ? sanitize_key( wp_unslash( $_POST['scope_kind'] ) ) : 'catalog',
			'exclude_categories_raw' => isset( $_POST['exclude_categories_raw'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude_categories_raw'] ) ) : '',
		);
		$visible_ids = isset( $_POST['visible_ids_csv'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['visible_ids_csv'] ) ) ) ) ) : array();
		$service     = new ProductMarginCheckService();
		$result      = $service->discard_visible_no_stock_from_active_snapshot( $scope, $visible_ids );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$result['daily_certification'] = $service->get_daily_result_for_scope( (array) ( $result['scope'] ?? $scope ) );

		wp_send_json_success(
			array(
				'result'  => $result,
				'html'    => $this->capture_product_margin_result_html( $result ),
				'message' => 'Se descartaron los hallazgos visibles sin inventario.',
			)
		);
	}

	public function ajax_historical_resolution_status() {
		check_ajax_referer( 'asdl_fin_historical_resolution_status' );
		$this->guard_finance_admin_runtime();
		wp_send_json_success(
			array(
				'status' => ( new HistoricalResolutionService() )->get_status_snapshot(),
			)
		);
	}

	public function ajax_historical_resolution_preview() {
		check_ajax_referer( 'asdl_fin_historical_resolution_preview' );
		$this->guard_finance_admin_runtime();

		$service = new HistoricalResolutionService();
		$result  = $service->preview( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'preview' => $result ) );
	}

	public function ajax_historical_resolution_start() {
		check_ajax_referer( 'asdl_fin_historical_resolution_start' );
		$this->guard_finance_admin_runtime();

		$service = new HistoricalResolutionService();
		$result  = $service->start_batch( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'job'    => $result,
				'status' => $service->get_status_snapshot(),
			)
		);
	}

	public function ajax_historical_resolution_continue() {
		check_ajax_referer( 'asdl_fin_historical_resolution_continue' );
		$this->guard_finance_admin_runtime();

		$service = new HistoricalResolutionService();
		$result  = $service->continue_batch();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'job'    => $result,
				'status' => $service->get_status_snapshot(),
			)
		);
	}

	public function ajax_historical_resolution_batch_detail() {
		check_ajax_referer( 'asdl_fin_historical_resolution_batch_detail' );
		$this->guard_finance_admin_runtime();

		$repository = new HistoricalResolutionBatchesRepository();
		$batch_id    = isset( $_POST['batch_id'] ) ? absint( wp_unslash( $_POST['batch_id'] ) ) : 0;
		$batch       = $repository->find( $batch_id );

		if ( empty( $batch['id'] ) ) {
			wp_send_json_error( array( 'message' => 'No encontramos el lote historico solicitado.' ), 404 );
		}

		wp_send_json_success(
			array(
				'batch' => $batch,
				'items' => $repository->list_batch_items( $batch_id, 100 ),
			)
		);
	}

	public function ajax_order_settlement_preview() {
		check_ajax_referer( 'asdl_fin_order_settlement_preview' );
		$this->guard_finance_admin_runtime();

		$service = new OrderSettlementBatchService();
		$origin  = isset( $_POST['origin'] ) ? sanitize_key( wp_unslash( $_POST['origin'] ) ) : 'profile_settlement';
		$result  = $service->preview( wp_unslash( $_POST ), $origin );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'preview' => $result,
			)
		);
	}

	public function ajax_order_settlement_start() {
		check_ajax_referer( 'asdl_fin_order_settlement_start' );
		$this->guard_finance_admin_runtime();

		$service = new OrderSettlementBatchService();
		$origin  = isset( $_POST['origin'] ) ? sanitize_key( wp_unslash( $_POST['origin'] ) ) : 'profile_settlement';
		$result  = $service->start( wp_unslash( $_POST ), $origin );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_settlement_continue() {
		check_ajax_referer( 'asdl_fin_order_settlement_continue' );
		$this->guard_finance_admin_runtime();

		$batch_id = isset( $_POST['batch_id'] ) ? absint( wp_unslash( $_POST['batch_id'] ) ) : 0;
		if ( $batch_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'No encontramos el lote del abono que se debe continuar.' ), 400 );
		}

		$service = new OrderSettlementBatchService();
		$result  = $service->continue_batch( $batch_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_settlement_status() {
		check_ajax_referer( 'asdl_fin_order_settlement_status' );
		$this->guard_finance_admin_runtime();

		$service = new OrderSettlementBatchService();
		$result  = $service->status( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_settlement_result() {
		check_ajax_referer( 'asdl_fin_order_settlement_result' );
		$this->guard_finance_admin_runtime();

		$service = new OrderSettlementBatchService();
		$result  = $service->result( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_assumption_preview() {
		check_ajax_referer( 'asdl_fin_order_assumption_preview' );
		$this->guard_finance_admin_runtime();

		$service = new OrderAssumptionBatchService();
		$origin  = isset( $_POST['origin'] ) ? sanitize_key( wp_unslash( $_POST['origin'] ) ) : 'profile_order_assumption';
		$result  = $service->preview( wp_unslash( $_POST ), $origin );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'preview' => $result,
			)
		);
	}

	public function ajax_order_assumption_start() {
		check_ajax_referer( 'asdl_fin_order_assumption_start' );
		$this->guard_finance_admin_runtime();

		$service = new OrderAssumptionBatchService();
		$origin  = isset( $_POST['origin'] ) ? sanitize_key( wp_unslash( $_POST['origin'] ) ) : 'profile_order_assumption';
		$result  = $service->start( wp_unslash( $_POST ), $origin );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_assumption_continue() {
		check_ajax_referer( 'asdl_fin_order_assumption_continue' );
		$this->guard_finance_admin_runtime();

		$batch_id = isset( $_POST['batch_id'] ) ? absint( wp_unslash( $_POST['batch_id'] ) ) : 0;
		if ( $batch_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'No encontramos el lote de asuncion que se debe continuar.' ), 400 );
		}

		$service = new OrderAssumptionBatchService();
		$result  = $service->continue_batch( $batch_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_assumption_status() {
		check_ajax_referer( 'asdl_fin_order_assumption_status' );
		$this->guard_finance_admin_runtime();

		$service = new OrderAssumptionBatchService();
		$result  = $service->status( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_assumption_result() {
		check_ajax_referer( 'asdl_fin_order_assumption_result' );
		$this->guard_finance_admin_runtime();

		$service = new OrderAssumptionBatchService();
		$result  = $service->result( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_assumption_reverse_item() {
		check_ajax_referer( 'asdl_fin_order_assumption_reverse_item' );
		$this->guard_finance_admin_runtime();

		$service = new OrderAssumptionBatchService();
		$result  = $service->reverse_item( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_order_assumption_reverse_batch() {
		check_ajax_referer( 'asdl_fin_order_assumption_reverse_batch' );
		$this->guard_finance_admin_runtime();

		$service = new OrderAssumptionBatchService();
		$result  = $service->reverse_batch( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'snapshot'        => $result,
				'runtime_refresh' => $this->build_receivables_runtime_refresh(
					$this->extract_runtime_contact_id( $result )
				),
			)
		);
	}

	public function ajax_search_wp_users() {
		check_ajax_referer( 'asdl_fin_search_wp_users' );
		$this->guard_finance_admin_runtime();

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$limit  = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 10;
		$limit  = min( 20, max( 5, $limit ) );

		if ( strlen( $search ) < 2 ) {
			wp_send_json_success(
				array(
					'items' => array(),
				)
			);
		}

		$items      = array();
		$seen_ids   = array();
		$role_names = wp_roles()->roles;

		if ( is_numeric( $search ) ) {
			$exact_user = get_user_by( 'id', absint( $search ) );
			if ( $exact_user instanceof \WP_User ) {
				$exact_user_id            = (int) $exact_user->ID;
				$seen_ids[ $exact_user_id ] = true;
				$items[]                  = $this->format_wp_user_picker_item( $exact_user, $role_names );
			}
		}

		$query = new \WP_User_Query(
			array(
				'number'         => $limit,
				'orderby'        => 'display_name',
				'order'          => 'ASC',
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'display_name', 'user_email', 'user_login' ),
				'fields'         => 'all',
			)
		);

		foreach ( (array) $query->get_results() as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			$user_id = (int) $user->ID;
			if ( isset( $seen_ids[ $user_id ] ) ) {
				continue;
			}

			$seen_ids[ $user_id ] = true;
			$items[]              = $this->format_wp_user_picker_item( $user, $role_names );

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		wp_send_json_success(
			array(
				'items' => $items,
			)
		);
	}

	private function extract_runtime_contact_id( array $payload ) {
		$candidates = array(
			$payload['contact_id'] ?? 0,
			$payload['job']['contact_id'] ?? 0,
			$payload['summary']['contact_id'] ?? 0,
			$payload['result']['contact_id'] ?? 0,
		);

		foreach ( $candidates as $candidate ) {
			$contact_id = absint( $candidate );
			if ( $contact_id > 0 ) {
				return $contact_id;
			}
		}

		return 0;
	}

	private function build_receivables_runtime_refresh( $contact_id = 0 ) {
		$refresh = RuntimeRefreshService::build_dashboard_refresh(
			array( 'dashboard-summary', 'dashboard-receivables' ),
			array( 'fallback_reload' => true )
		);

		if ( $contact_id > 0 ) {
			$refresh = RuntimeRefreshService::merge_runtime_refreshes(
				$refresh,
				RuntimeRefreshService::build_profile_refresh(
					$contact_id,
					array(
						'profile-financial-cards',
						'profile-orders-summary',
						'profile-orders-table',
						'profile-account-state',
						'profile-payments',
						'profile-history',
					)
				)
			);
		}

		return $refresh;
	}

	private function build_payroll_runtime_refresh_plan( $contact_id = 0 ) {
		return RuntimeRefreshService::build_payroll_refresh(
			$contact_id,
			array( 'fallback_reload' => true )
		);
	}

	private function normalize_runtime_fiscal_range( array $input ) {
		$fiscal_context = $this->current_fiscal_context();
		$range_from     = isset( $input['range_from'] ) ? sanitize_text_field( wp_unslash( $input['range_from'] ) ) : ( $fiscal_context['start_date'] ?? '' );
		$range_to       = isset( $input['range_to'] ) ? sanitize_text_field( wp_unslash( $input['range_to'] ) ) : ( $fiscal_context['end_date'] ?? '' );

		if ( '' !== $range_from && '' !== $range_to && $range_from > $range_to ) {
			$temp       = $range_from;
			$range_from = $range_to;
			$range_to   = $temp;
		}

		return array(
			'range_from' => $range_from,
			'range_to'   => $range_to,
		);
	}

	private function guard_finance_admin_runtime() {
		if ( ! current_user_can( $this->get_capability() ) ) {
			wp_send_json_error(
				array(
					'message' => 'No tienes permisos para ejecutar esta herramienta.',
				),
				403
			);
		}
	}

	private function dashboard_runtime_error_message( $section ) {
		switch ( $section ) {
			case 'summary-core':
				return 'No se pudo cargar el resumen operativo principal.';
			case 'summary-finance':
				return 'No se pudo cargar el resumen financiero principal.';
			case 'summary-payroll':
				return 'No se pudo cargar el resumen corto de nomina.';
			case 'core':
				return 'No se pudo cargar el estado del core.';
			case 'pending-table':
				return 'No se pudo cargar la tabla inicial de pendientes por cobrar.';
			case 'pending':
				return 'No se pudo cargar la cola de pendientes por cobrar.';
			case 'payables':
				return 'No se pudo cargar la cola de pendientes por pagar.';
			case 'third-parties':
				return 'No se pudo cargar el directorio corto de terceros.';
			case 'recent':
				return 'No se pudo cargar la actividad reciente.';
			case 'payroll':
				return 'No se pudo cargar la cola rapida de nomina.';
			default:
				return 'No se pudo cargar el bloque solicitado.';
		}
	}

	private function render_dashboard_runtime_section( $section, array $fiscal_range ) {
		switch ( $section ) {
			case 'summary-core':
				$overview                 = ( new OverviewService() )->get_dashboard_snapshot(
					array(
						'range_from'     => $fiscal_range['range_from'],
						'range_to'       => $fiscal_range['range_to'],
						'include_recent' => false,
					)
				);
				$dashboard_cards          = $this->build_dashboard_core_cards( $overview );
				?>
				<div class="asdl-fin-card-grid">
					<?php foreach ( $dashboard_cards as $card ) : ?>
						<?php echo wp_kses_post( $this->render_dashboard_stat_card( $card['label'], $card['value'], $card['description'], $card['url'] ) ); ?>
					<?php endforeach; ?>
				</div>
				<?php
				return;

			case 'summary-finance':
				$dashboard_cards          = $this->load_dashboard_finance_cards( $fiscal_range );
				?>
				<div class="asdl-fin-card-grid">
					<?php foreach ( $dashboard_cards as $card ) : ?>
						<?php echo wp_kses_post( $this->render_dashboard_stat_card( $card['label'], $card['value'], $card['description'], $card['url'] ) ); ?>
					<?php endforeach; ?>
				</div>
				<?php
				return;

			case 'summary-payroll':
				$profiles_repository = new EmployeeProfilesRepository();
				$profile_counts      = $profiles_repository->summary_counts();
				$upcoming_profiles   = $profiles_repository->upcoming_payroll_queue(
					$fiscal_range['range_from'],
					$fiscal_range['range_to'],
					120
				);
				$dashboard_cards     = $this->build_dashboard_payroll_cards(
					$profile_counts,
					array(
						'employee_count' => count( $upcoming_profiles ),
					)
				);
				?>
				<div class="asdl-fin-card-grid">
					<?php foreach ( $dashboard_cards as $card ) : ?>
						<?php echo wp_kses_post( $this->render_dashboard_stat_card( $card['label'], $card['value'], $card['description'], $card['url'] ) ); ?>
					<?php endforeach; ?>
				</div>
				<?php
				return;

			case 'core':
				$integrations = ( new IntegrationStatusService() )->get_snapshot();
				$docs_url     = rest_url( 'asdl-fin/v1/health' );
				?>
				<details class="asdl-fin-panel asdl-fin-dashboard-details" open>
					<summary>
						<div>
							<strong>Estado del core</strong>
							<small>Base tecnica, API y sincronizacion actual.</small>
						</div>
						<div class="asdl-fin-dashboard-details-summary">
							<span><?php echo esc_html( get_option( SchemaInstaller::OPTION_SCHEMA_VERSION, 'pendiente' ) ); ?></span>
							<span class="asdl-fin-dashboard-chevron" aria-hidden="true"></span>
						</div>
					</summary>
					<div class="asdl-fin-dashboard-details-body">
						<ul class="asdl-fin-checklist">
							<li>Branding nuevo: Finanzas ASD</li>
							<li>Esquema propio: <?php echo esc_html( get_option( SchemaInstaller::OPTION_SCHEMA_VERSION, 'pendiente' ) ); ?></li>
							<li>Objetivo de esquema: <?php echo esc_html( Schema::VERSION ); ?></li>
							<li>API base: <code><?php echo esc_html( $docs_url ); ?></code></li>
							<li>Integraciones detectadas: <?php echo esc_html( number_format_i18n( $integrations['detected_count'] ?? 0 ) ); ?></li>
							<li>Overrides bloqueados: <?php echo esc_html( number_format_i18n( $integrations['locked_links'] ?? 0 ) ); ?></li>
						</ul>
					</div>
				</details>
				<?php
				return;

			case 'pending':
			case 'pending-receivables-summary':
				$pending_queue = ( new PendingCollectionsService() )->get_snapshot(
					array(
						'limit'        => 1,
						'range_from'   => $fiscal_range['range_from'],
						'range_to'     => $fiscal_range['range_to'],
						'summary_only' => true,
					)
				);
				?>
				<section id="asdl-fin-dashboard-pending" class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Pendientes por cobrar</h2>
						<p>Cola global de deuda a favor de la empresa: pedidos, documentos, prestamos, compromisos y adelantos por recuperar agrupados por perfil o persona. El historico operativo se limita al ejercicio actual y el fiscal anterior.</p>
					</div>
					<?php $this->render_pending_collection_summary_badges( $pending_queue['summary'] ?? array() ); ?>
				</section>
				<?php
				return;

			case 'pending-table':
			case 'pending-receivables-table':
				$pending_queue = ( new PendingCollectionsService() )->get_snapshot(
					array(
						'limit'          => 0,
						'order_limit'    => 0,
						'aux_limit'      => 0,
						'range_from'     => $fiscal_range['range_from'],
						'range_to'       => $fiscal_range['range_to'],
						'include_detail' => false,
					)
				);
				?>
				<div class="asdl-fin-stack">
					<p class="asdl-fin-runtime-note">Cola completa de grupos por cobrar del ejercicio actual y el fiscal anterior. La busqueda y los filtros trabajan sobre toda la lista cargada en esta vista.</p>
					<?php $this->render_pending_collection_groups_table( $pending_queue['items'] ?? array(), array( 'per_page' => 10 ) ); ?>
				</div>
				<?php
				return;

			case 'payables':
			case 'pending-payables-summary':
				$pending_payables = ( new PendingPayablesService() )->get_snapshot(
					array(
						'limit'      => 1,
						'range_from' => $fiscal_range['range_from'],
						'range_to'   => $fiscal_range['range_to'],
						'summary_only' => true,
					)
				);
				?>
				<section id="asdl-fin-dashboard-payables" class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Pendientes por pagar</h2>
						<p>Cola global de obligaciones de la empresa: documentos por pagar, deudas con perfiles, prestamos, compromisos y futuras compras agrupadas por contraparte.</p>
					</div>
					<?php $this->render_pending_payable_summary_badges( $pending_payables['summary'] ?? array() ); ?>
				</section>
				<?php
				return;

			case 'pending-payables-table':
				$pending_payables = ( new PendingPayablesService() )->get_snapshot(
					array(
						'limit'      => 120,
						'range_from' => $fiscal_range['range_from'],
						'range_to'   => $fiscal_range['range_to'],
					)
				);
				?>
				<div class="asdl-fin-stack">
					<p class="asdl-fin-runtime-note">Cola agrupada del ejercicio actual y el fiscal anterior para revisar proveedores, perfiles y otros pendientes por pagar.</p>
					<?php $this->render_pending_payable_groups_table( array_slice( $pending_payables['items'] ?? array(), 0, 120 ), array( 'per_page' => 8 ) ); ?>
				</div>
				<?php
				return;

			case 'third-parties':
				$directory = $this->get_dashboard_third_party_directory();
				?>
				<section id="asdl-fin-dashboard-third-parties" class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Proveedores y terceros base</h2>
						<p>Directorio corto para operar servicios, cuentas por pagar y la futura base de compras sobre perfiles ya preparados.</p>
					</div>
					<?php $this->render_third_party_directory_table( $directory, array( 'per_page' => 5 ) ); ?>
				</section>
				<?php
				return;

			case 'recent':
			case 'recent-activity':
				$overview = ( new OverviewService() )->get_dashboard_snapshot( $fiscal_range );
				?>
				<section id="asdl-fin-dashboard-documents" class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Movimientos recientes</h2>
						<p>Ventas, gastos y otros movimientos creados dentro del nuevo esquema financiero.</p>
					</div>
					<?php $this->render_documents_table( $overview['recent_documents'] ?? array(), array( 'per_page' => 6, 'show_number' => false ) ); ?>
				</section>

				<section id="asdl-fin-dashboard-payments" class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Pagos recientes</h2>
						<p>Resumen corto de cobros y pagos ya registrados.</p>
					</div>
					<?php $this->render_payments_table( $overview['recent_payments'] ?? array(), array( 'per_page' => 6 ) ); ?>
				</section>

				<section id="asdl-fin-dashboard-activity" class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Actividad reciente</h2>
						<p>Bitacora del nuevo core financiero. En esta etapa aparecera a medida que entren sincronizaciones y movimientos reales.</p>
					</div>
					<?php if ( ! empty( $overview['recent_activity'] ) ) : ?>
						<div class="asdl-fin-table-wrap">
						<table class="widefat striped asdl-fin-table" data-dashboard-per-page="6">
							<thead>
								<tr>
									<th>Evento</th>
									<th>Entidad</th>
									<th>Usuario</th>
									<th>Detalle</th>
									<th>Fecha</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $overview['recent_activity'] as $event ) : ?>
									<tr>
										<td><?php echo esc_html( $event['event_type'] ); ?></td>
										<td><?php echo esc_html( $event['entity_type'] . ' #' . $event['entity_id'] ); ?></td>
										<td><?php echo esc_html( $event['actor_display_name'] ?: ( ! empty( $event['actor_user_id'] ) ? 'Usuario #' . (int) $event['actor_user_id'] : 'Sistema' ) ); ?></td>
										<td><?php echo esc_html( $event['message'] ); ?></td>
										<td><?php echo esc_html( $event['created_at'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php else : ?>
						<div class="asdl-fin-empty">
							<strong>Sin actividad registrada todavia.</strong>
							<p>Esto es normal mientras terminamos la base del core y las primeras sincronizaciones.</p>
						</div>
					<?php endif; ?>
				</section>
				<?php
				return;

			case 'payroll':
			case 'payroll-queue':
				$queue = ( new PayrollQueueService() )->get_snapshot(
					array(
						'limit'                       => 12,
						'from_date'                   => $fiscal_range['range_from'],
						'to_date'                     => $fiscal_range['range_to'],
						'include_manual_debt_summary' => false,
					)
				);
				?>
				<section id="asdl-fin-dashboard-payroll" class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Nomina proxima</h2>
						<p>Empleados con pago previsto dentro del rango corto del dashboard, con acceso rapido a generar o procesar.</p>
					</div>
					<?php $this->render_payroll_queue_table( array_slice( $queue['items'] ?? array(), 0, 12 ), ( new AccountsRepository() )->options(), true, array( 'per_page' => 5 ) ); ?>
				</section>
				<?php
				return;
		}
	}

	private function render_admin_runtime_section( $page_key, $section_key, array $input ) {
		$fiscal_range = $this->normalize_runtime_fiscal_range( $input );

		switch ( $page_key ) {
			case 'dashboard':
				$this->render_dashboard_runtime_section( $section_key, $fiscal_range );
				return;

			case 'accounts':
				$this->render_accounts_runtime_section( $section_key );
				return;

			case 'documents':
				$this->render_documents_runtime_section( $section_key );
				return;

			case 'expenses':
				$this->render_expenses_runtime_section( $section_key, $input );
				return;

			case 'payments':
				$this->render_payments_runtime_section( $section_key );
				return;

			case 'payroll':
				$this->render_payroll_runtime_section( $section_key, $input );
				return;

			case 'installments':
				$this->render_installments_runtime_section( $section_key );
				return;

			case 'contacts':
				$this->render_contacts_runtime_section( $section_key, $input, $fiscal_range );
				return;

			case 'services':
				$this->render_services_runtime_section( $section_key );
				return;

			case 'integrations':
				$this->render_integrations_runtime_section( $section_key );
				return;

			case 'rules':
				$this->render_rules_runtime_section( $section_key );
				return;
		}

		throw new \RuntimeException( 'Unknown runtime section.' );
	}

	private function render_accounts_runtime_section( $section_key ) {
		if ( 'accounts-table' !== $section_key ) {
			throw new \RuntimeException( 'Unknown accounts runtime section.' );
		}

		$accounts = ( new AccountsRepository() )->all( 100 );
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Cuentas registradas</h2>
				<p>Listado base del nuevo core financiero.</p>
			</div>
			<?php $this->render_accounts_table( $accounts ); ?>
		</section>
		<?php
	}

	private function render_documents_runtime_section( $section_key ) {
		if ( 'documents-table' !== $section_key ) {
			throw new \RuntimeException( 'Unknown documents runtime section.' );
		}

		$documents = ( new DocumentsRepository() )->list_admin(
			array(
				'limit'                  => 100,
				'exclude_document_types' => array( 'service_expense', 'external_expense' ),
			)
		);
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Movimientos registrados</h2>
				<p>Listado tecnico del core financiero para ajustes, prestamos y documentos manuales. Los gastos y servicios se operan desde sus modulos propios.</p>
			</div>
			<?php
			$this->render_documents_table(
				$documents,
				array(
					'per_page'     => 12,
					'allow_cancel' => true,
				)
			);
			?>
		</section>
		<?php
	}

	private function render_expenses_runtime_section( $section_key, array $input ) {
		$service  = new CompanyExpensesService();
		$filters  = $this->expense_filters_from_input( $input );
		$snapshot = $service->get_snapshot( $filters );
		$summary  = is_array( $snapshot['summary'] ?? null ) ? $snapshot['summary'] : array();

		switch ( $section_key ) {
			case 'expenses-summary':
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Resumen de gastos</h2>
						<p>Vista operativa para gastos de empresa y consumos internos del período, con o sin contraparte.</p>
					</div>
					<div class="asdl-fin-card-grid">
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Total emitido</span>
							<strong><?php echo wp_kses_post( $this->format_money( $summary['total_issued'] ?? 0 ) ); ?></strong>
							<p>Gastos externos y consumos internos emitidos dentro del filtro actual.</p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Saldo pendiente</span>
							<strong><?php echo wp_kses_post( $this->format_money( $summary['pending_total'] ?? 0 ) ); ?></strong>
							<p>Parte aún abierta de esos movimientos del período.</p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Gastos abiertos</span>
							<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['open_count'] ?? 0 ) ) ); ?></strong>
							<p>Documentos que siguen con saldo por pagar.</p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Pagados / parciales</span>
							<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['paid_or_partial_count'] ?? 0 ) ) ); ?></strong>
							<p>Gastos con pago total o parcial ya registrado.</p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Sin contraparte</span>
							<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['without_contact_count'] ?? 0 ) ) ); ?></strong>
							<p>Gastos cargados directamente a la empresa sin perfil asociado.</p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Consumo interno</span>
							<strong><?php echo wp_kses_post( $this->format_money( $summary['internal_issued_total'] ?? 0 ) ); ?></strong>
							<p><?php echo esc_html( number_format_i18n( (int) ( $summary['internal_count'] ?? 0 ) ) ); ?> pedido(s) asumidos como gasto o regalo dentro del período.</p>
						</div>
					</div>
				</section>
				<?php
				return;

			case 'expenses-table':
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Gastos registrados</h2>
						<p>Lista plana de gastos de empresa, consumos internos y regalos internos con filtros por contraparte, pago y fechas.</p>
					</div>
					<?php
					$this->render_expenses_table(
						(array) ( $snapshot['items'] ?? array() ),
						array(
							'allow_cancel' => true,
							'per_page'     => 12,
						)
					);
					?>
				</section>
				<?php
				return;
		}

		throw new \RuntimeException( 'Unknown expenses runtime section.' );
	}

	private function expense_filters_from_input( array $input ) {
		$context = $this->current_fiscal_context();

		return ( new CompanyExpensesService() )->normalize_filters(
			array(
				'search'           => $input['expense_search'] ?? '',
				'financial_status' => $input['expense_financial_status'] ?? '',
				'payment_status'   => $input['expense_payment_status'] ?? '',
				'range_from'       => $input['expense_range_from'] ?? ( $context['start_date'] ?? '' ),
				'range_to'         => $input['expense_range_to'] ?? ( $context['end_date'] ?? '' ),
				'open_only'        => ! empty( $input['expense_open_only'] ),
				'has_contact'      => $input['expense_has_contact'] ?? 'all',
				'contact_id'       => absint( $input['expense_contact_id'] ?? 0 ),
				'limit'            => $input['expense_limit'] ?? 100,
			)
		);
	}

	private function expense_financial_status_options() {
		return array(
			'draft'  => 'Borrador',
			'posted' => 'Emitido',
			'void'   => 'Anulado',
		);
	}

	private function expense_payment_status_options() {
		return array(
			'pending' => 'Pendiente',
			'partial' => 'Abonado',
			'paid'    => 'Pagado',
			'overdue' => 'Vencido',
		);
	}

	private function render_payments_runtime_section( $section_key ) {
		$payments_repository    = new PaymentsRepository();
		$allocations_repository = new PaymentAllocationsRepository();

		switch ( $section_key ) {
			case 'payments-table':
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Cobros y pagos registrados</h2>
						<p>Lista base de cobros, pagos y abonos registrados, con perfil relacionado y monto disponible para futuras asignaciones.</p>
					</div>
					<?php $this->render_payments_table( $payments_repository->all( 100 ), array( 'per_page' => 12, 'allow_cancel' => true ) ); ?>
				</section>
				<?php
				return;

			case 'allocations-table':
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Asignaciones recientes</h2>
						<p>Historico basico de abonos aplicados a movimientos financieros.</p>
					</div>
					<?php $this->render_allocations_table( $allocations_repository->all( 100 ) ); ?>
				</section>
				<?php
				return;
		}

		throw new \RuntimeException( 'Unknown payments runtime section.' );
	}

	private function render_payroll_runtime_section( $section_key, array $input ) {
		$fiscal_context = $this->current_fiscal_context();
		$filters        = array(
			'from_date' => isset( $input['from_date'] ) ? sanitize_text_field( wp_unslash( $input['from_date'] ) ) : ( $fiscal_context['start_date'] ?? '' ),
			'to_date'   => isset( $input['to_date'] ) ? sanitize_text_field( wp_unslash( $input['to_date'] ) ) : ( $fiscal_context['end_date'] ?? '' ),
			'limit'     => isset( $input['limit'] ) ? absint( wp_unslash( $input['limit'] ) ) : 80,
		);
		$queue                 = ( new PayrollQueueService() )->get_snapshot( $filters );
		$summary               = $queue['summary'] ?? array();
		$account_options       = ( new AccountsRepository() )->options();
		$employee_profiles     = new EmployeeProfilesRepository();
		$payroll_periods       = new PayrollPeriodsRepository();

		switch ( $section_key ) {
			case 'payroll-summary':
				?>
				<div class="asdl-fin-card-grid">
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Empleados totales</span>
						<strong><?php echo esc_html( number_format_i18n( $summary['total_employee_count'] ?? 0 ) ); ?></strong>
						<p>Perfiles marcados como empleados. Configurados: <?php echo esc_html( number_format_i18n( $summary['configured_count'] ?? 0 ) ); ?> | Sin ficha laboral: <?php echo esc_html( number_format_i18n( $summary['unconfigured_count'] ?? 0 ) ); ?>.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Empleados en cola</span>
						<strong><?php echo esc_html( number_format_i18n( $summary['employee_count'] ?? 0 ) ); ?></strong>
						<p>Empleados activos con pago previsto dentro del rango consultado.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Periodos ya generados</span>
						<strong><?php echo esc_html( number_format_i18n( $summary['planned_period_count'] ?? 0 ) ); ?></strong>
						<p>Empleados que ya tienen un periodo pendiente listo para procesarse.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Por generar</span>
						<strong><?php echo esc_html( number_format_i18n( $summary['pending_generation_count'] ?? 0 ) ); ?></strong>
						<p>Empleados que aun no tienen periodo generado para su proximo pago.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Bruto previsto</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['gross_total'] ?? 0 ) ); ?></strong>
						<p>Total bruto estimado dentro del rango.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Adelantos previstos</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['advance_deduction_total'] ?? 0 ) ); ?></strong>
						<p>Descuento automatico esperado por adelantos activos.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Compromisos a descontar</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['commitment_deduction_total'] ?? 0 ) ); ?></strong>
						<p>Descuentos esperados por prestamos, deudas o cargos con cobro por sueldo.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Compromisos a pagar</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['commitment_payment_total'] ?? 0 ) ); ?></strong>
						<p>Pagos previstos por deudas de la empresa o acuerdos a favor del empleado.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Neto previsto</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['net_total'] ?? 0 ) ); ?></strong>
						<p>Monto neto estimado a pagar en la cola consultada.</p>
					</div>
				</div>
				<?php
				return;

			case 'payroll-queue':
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Cola general de nomina</h2>
						<p>Genera periodos o procesa pagos con metodo, referencia y nota sin salir de la vista general.</p>
					</div>
					<?php $this->render_payroll_queue_table( $queue['items'] ?? array(), $account_options, false ); ?>
				</section>
				<?php
				return;

			case 'payroll-directory':
				$employee_directory    = $employee_profiles->all_with_contacts( 250 );
				$directory_periods_map = $payroll_periods->latest_by_contacts( wp_list_pluck( $employee_directory, 'contact_id' ), 2 );
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Empleados registrados</h2>
						<p>CRUD general de empleados para revisar ficha laboral, contrato, sueldo base y los dos periodos mas recientes sin salir de nomina.</p>
					</div>
					<?php $this->render_employee_directory_table( $employee_directory, $directory_periods_map, array( 'per_page' => 10 ) ); ?>
				</section>
				<?php
				return;
		}

		throw new \RuntimeException( 'Unknown payroll runtime section.' );
	}

	private function render_installments_runtime_section( $section_key ) {
		if ( 'installments-table' !== $section_key ) {
			throw new \RuntimeException( 'Unknown installments runtime section.' );
		}

		$plans = ( new InstallmentPlansRepository() )->all( 100 );
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Compromisos recientes</h2>
				<p>Resumen global de prestamos y acuerdos activos del sistema.</p>
			</div>
			<?php $this->render_installment_plans_table( $plans, array( 'allow_cancel' => true ) ); ?>
		</section>
		<?php
	}

	private function render_contacts_runtime_section( $section_key, array $input, array $fiscal_range ) {
		switch ( $section_key ) {
			case 'contacts-table':
				$search   = isset( $input['profile_search'] ) ? sanitize_text_field( wp_unslash( $input['profile_search'] ) ) : '';
				$contacts = ( new ContactsRepository() )->list_for_admin( $search, 100 );
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Perfiles y terceros registrados</h2>
						<p>Vista unificada de usuarios enlazados, proveedores y terceros externos convertibles cuando haga falta.</p>
					</div>
					<?php
					$this->render_contacts_table(
						$contacts,
						array(
							'per_page'           => 15,
							'expanded_per_page'  => 30,
							'show_expand_toggle' => true,
						)
					);
					?>
				</section>
				<?php
				return;

			case 'profile-header-summary':
			case 'profile-financial-cards':
			case 'profile-orders-summary':
			case 'profile-orders-table':
			case 'profile-account-state':
			case 'profile-payments':
			case 'profile-payroll':
			case 'profile-history':
				$snapshot = $this->load_contact_runtime_snapshot( $input, $fiscal_range );
				if ( empty( $snapshot ) ) {
					throw new \RuntimeException( 'Missing contact snapshot.' );
				}

				$this->render_contact_profile_runtime_block(
					$section_key,
					$this->build_contact_detail_context( $snapshot )
				);
				return;

			case 'contact-full':
				$contact_id = isset( $input['contact_id'] ) ? absint( wp_unslash( $input['contact_id'] ) ) : 0;
				$order_limit = isset( $input['order_limit'] ) ? absint( wp_unslash( $input['order_limit'] ) ) : 25;
				$snapshot = ( new ContactOverviewService() )->get_contact_snapshot_cached(
					$contact_id,
					array(
						'range_from' => $fiscal_range['range_from'],
						'range_to'   => $fiscal_range['range_to'],
						'order_limit'=> $order_limit > 0 ? $order_limit : 25,
					)
				);
				if ( empty( $snapshot ) ) {
					throw new \RuntimeException( 'Missing contact snapshot.' );
				}
				$this->render_contact_detail( $snapshot );
				return;
		}

		throw new \RuntimeException( 'Unknown contacts runtime section.' );
	}

	private function load_contact_runtime_snapshot( array $input, array $fiscal_range ) {
		$contact_id  = isset( $input['contact_id'] ) ? absint( wp_unslash( $input['contact_id'] ) ) : 0;
		$order_limit = isset( $input['order_limit'] ) ? absint( wp_unslash( $input['order_limit'] ) ) : 25;

		if ( $contact_id <= 0 ) {
			return null;
		}

		return ( new ContactOverviewService() )->get_contact_snapshot_cached(
			$contact_id,
			array(
				'range_from' => $fiscal_range['range_from'],
				'range_to'   => $fiscal_range['range_to'],
				'order_limit'=> $order_limit > 0 ? $order_limit : 25,
			)
		);
	}

	private function build_contact_detail_context( array $snapshot ) {
		$contact                  = $snapshot['contact'];
		$summary                  = $snapshot['summary'];
		$filters                  = $snapshot['filters'] ?? array();
		$order_summary            = $snapshot['order_summary'] ?? array();
		$pending_summary          = $snapshot['pending_order_summary'] ?? array();
		$employee_profile         = is_array( $snapshot['employee_profile'] ?? null ) ? $snapshot['employee_profile'] : array();
		$salary_advance_summary   = $snapshot['salary_advance_summary'] ?? array();
		$payroll_summary          = $snapshot['payroll_summary'] ?? array();
		$payroll_periods          = $snapshot['payroll_periods'] ?? array();
		$payroll_defaults         = $this->payroll_defaults_for_employee( $employee_profile );
		$user                     = ! empty( $contact['wp_user_id'] ) ? get_userdata( (int) $contact['wp_user_id'] ) : null;
		$is_employee              = ! empty( $contact['is_employee'] );
		$is_supplier              = ! empty( $contact['is_supplier'] );
		$is_customer              = ! empty( $contact['is_customer'] );
		$supplier_kind            = $is_supplier ? sanitize_key( (string) ( $contact['supplier_kind'] ?? 'general' ) ) : '';
		$account_options          = ( new AccountsRepository() )->options();
		$active_commitment_options = array();

		foreach ( (array) ( $snapshot['plans'] ?? array() ) as $plan_item ) {
			if ( empty( $plan_item['id'] ) || (float) ( $plan_item['balance'] ?? 0 ) <= 0 || 'closed' === ( $plan_item['status'] ?? '' ) ) {
				continue;
			}

			$active_commitment_options[ (int) $plan_item['id'] ] = sprintf(
				'%s - %s (%s)',
				sanitize_text_field( $plan_item['title'] ?? 'Compromiso' ),
				$this->label_for( 'settlement_direction', $plan_item['settlement_direction'] ?? 'receivable' ),
				number_format_i18n( (float) ( $plan_item['balance'] ?? 0 ), 2 )
			);
		}

		$consolidated_receivable  = (float) ( $summary['consolidated_receivable_total'] ?? 0 );
		$credit_total             = (float) ( $summary['credit_total'] ?? 0 );
		$available_credit_total   = (float) ( $summary['usable_credit_total'] ?? 0 );
		$usable_credit_total      = min( $available_credit_total, (float) ( $pending_summary['pending_order_total'] ?? 0 ) );
		$order_debt_gross_total   = (float) ( $summary['pending_order_gross_total'] ?? 0 );
		$order_debt_paid_total    = (float) ( $summary['pending_order_paid_total'] ?? 0 );
		$net_position_total       = (float) ( $summary['net_position_total'] ?? 0 );
		$period_open_total        = (float) ( $summary['open_order_total'] ?? 0 );
		$period_open_count        = (int) ( $summary['open_order_count'] ?? 0 );
		$total_pending_count      = (int) ( $summary['pending_order_count_total'] ?? 0 );
		$historical_pending_total = max( 0, round( (float) ( $summary['pending_order_total'] ?? 0 ) - $period_open_total, 6 ) );
		$historical_pending_count = max( 0, $total_pending_count - $period_open_count );
		$store_debt_commitment_planned_total = (float) ( $summary['receivable_store_debt_commitment_applied_to_orders_total'] ?? 0 );
		$additional_receivable_commitment_total = (float) ( $summary['receivable_commitment_total'] ?? 0 );
		$dual_pricing_service     = new DualPricingService();
		$dual_discount_config     = $dual_pricing_service->get_discount_config();
		$dual_discount_fraction   = (float) ( $dual_discount_config['fraction'] ?? 0 );
		$dual_discount_percent    = (float) ( $dual_discount_config['percent'] ?? 0 );
		$dual_discount_active     = ! empty( $dual_discount_config['active'] ) && $dual_discount_fraction > 0;
		$dual_pending_total       = $dual_discount_active
			? (float) ( $dual_pricing_service->compute_dual( (float) ( $pending_summary['pending_order_total'] ?? 0 ), PHP_FLOAT_MAX, $dual_discount_fraction )['net_effective'] ?? 0 )
			: 0.0;
		$dual_period_open_total   = $dual_discount_active
			? (float) ( $dual_pricing_service->compute_dual( $period_open_total, PHP_FLOAT_MAX, $dual_discount_fraction )['net_effective'] ?? 0 )
			: 0.0;
		$dual_historical_total    = $dual_discount_active
			? (float) ( $dual_pricing_service->compute_dual( $historical_pending_total, PHP_FLOAT_MAX, $dual_discount_fraction )['net_effective'] ?? 0 )
			: 0.0;
		$dual_discount_label      = $dual_discount_active
			? sprintf( 'Si paga en USD/divisa con %s%%: ', number_format_i18n( $dual_discount_percent, 2 ) )
			: '';
		$net_position_label       = abs( $net_position_total ) < 0.00001
			? 'En equilibrio'
			: ( $net_position_total > 0 ? 'A favor de la empresa' : 'A favor del perfil' );
		$filters_open             = isset( $_GET['range_from'] ) || isset( $_GET['range_to'] ) || isset( $_GET['order_limit'] );
		$readonly_context         = $this->is_fiscal_readonly_context();
		$service_summary          = is_array( $snapshot['service_summary'] ?? null ) ? $snapshot['service_summary'] : array();
		$service_profiles         = $snapshot['service_profiles'] ?? array();
		$due_service_profiles     = $snapshot['due_service_profiles'] ?? array();
		$upcoming_service_profiles = $snapshot['upcoming_service_profiles'] ?? array();
		$service_documents        = $snapshot['service_documents'] ?? array();
		$open_service_documents   = $snapshot['open_service_documents'] ?? array();
		$open_payable_documents   = $snapshot['open_payable_documents'] ?? array();
		$non_service_open_payable_documents = array_values(
			array_filter(
				$open_payable_documents,
				static function ( array $document ) {
					return 'service_expense' !== sanitize_key( (string) ( $document['document_type'] ?? '' ) );
				}
			)
		);
		$profile_documents = array_values(
			array_filter(
				(array) ( $snapshot['documents'] ?? array() ),
				static function ( array $document ) {
					return 'service_expense' !== sanitize_key( (string) ( $document['document_type'] ?? '' ) );
				}
			)
		);
		$supplier_supports_services = in_array( $supplier_kind, array( 'services', 'mixed' ), true );
		$has_service_history        = ! empty( $service_profiles ) || ! empty( $service_documents );
		$has_service_section        = ( $is_supplier && $supplier_supports_services ) || $has_service_history;
		$is_supplier_only           = $is_supplier && ! $is_customer && ! $is_employee;
		$show_customer_sections     = ! $is_supplier_only;
		$service_open_balance       = (float) ( $service_summary['open_balance_total'] ?? 0 );
		$next_service_profile       = $this->next_service_profile( $service_profiles );
		$next_service_issue_date    = ! empty( $next_service_profile['next_issue_date'] ) ? sanitize_text_field( (string) $next_service_profile['next_issue_date'] ) : '';
		$next_service_title         = ! empty( $next_service_profile['title'] ) ? sanitize_text_field( (string) $next_service_profile['title'] ) : '';
		$next_service_amount        = ! empty( $next_service_profile['amount'] ) ? (float) $next_service_profile['amount'] : 0.0;
		$next_service_currency      = ! empty( $next_service_profile['currency'] ) ? sanitize_text_field( (string) $next_service_profile['currency'] ) : 'USD';
		$next_service_due_label     = '' !== $next_service_issue_date ? $this->format_date_with_weekday( $next_service_issue_date ) : 'Sin programar';
		$payable_total              = (float) ( $summary['payable_total'] ?? 0 );
		$non_service_payable_total  = max( 0, round( $payable_total - $service_open_balance, 6 ) );
		$registered_credit_total    = (float) ( $summary['unapplied_payment_total'] ?? 0 );
		$display_open_payable_documents = $has_service_section ? $non_service_open_payable_documents : $open_payable_documents;
		$provider_kind_label        = $is_supplier ? $this->label_for( 'supplier_kind', $supplier_kind ?: 'general' ) : '';
		$header_role_label          = $contact['profile_roles_label'] ?? $this->label_for( 'contact_type', $contact['contact_type'] );
		if ( $is_supplier_only && '' !== $provider_kind_label ) {
			$header_role_label = 'Proveedor | ' . $provider_kind_label;
		}

		$provider_note = '';
		if ( $is_supplier ) {
			if ( 'services' === $supplier_kind ) {
				$provider_note = 'Proveedor orientado a servicios. Aqui se gestionan servicios recurrentes, cargos puntuales y cuentas por pagar ligadas a esos servicios.';
			} elseif ( 'products' === $supplier_kind ) {
				$provider_note = 'Proveedor orientado a productos. Aqui se concentran cuentas por pagar y queda preparada la futura base del modulo de compras.';
			} elseif ( 'mixed' === $supplier_kind ) {
				$provider_note = 'Proveedor mixto. Puede operar con servicios y luego tambien con compras o abastecimiento de productos.';
			} else {
				$provider_note = 'Proveedor sin clasificar. Define si opera por servicios, productos o mixto para que el perfil muestre solo las secciones correctas.';
			}
		}

		return compact(
			'snapshot',
			'contact',
			'summary',
			'filters',
			'order_summary',
			'pending_summary',
			'employee_profile',
			'salary_advance_summary',
			'payroll_summary',
			'payroll_periods',
			'payroll_defaults',
			'user',
			'is_employee',
			'is_supplier',
			'is_customer',
			'supplier_kind',
			'account_options',
			'active_commitment_options',
			'consolidated_receivable',
			'credit_total',
			'available_credit_total',
			'usable_credit_total',
			'order_debt_gross_total',
			'order_debt_paid_total',
			'net_position_total',
			'period_open_total',
			'period_open_count',
			'total_pending_count',
			'historical_pending_total',
			'historical_pending_count',
			'dual_discount_percent',
			'dual_discount_active',
			'dual_pending_total',
			'dual_period_open_total',
			'dual_historical_total',
			'dual_discount_label',
			'net_position_label',
			'filters_open',
			'readonly_context',
			'service_summary',
			'service_profiles',
			'due_service_profiles',
			'upcoming_service_profiles',
			'service_documents',
			'open_service_documents',
			'open_payable_documents',
			'non_service_open_payable_documents',
			'profile_documents',
			'has_service_section',
			'is_supplier_only',
			'show_customer_sections',
			'service_open_balance',
			'next_service_profile',
			'next_service_issue_date',
			'next_service_title',
			'next_service_amount',
			'next_service_currency',
			'next_service_due_label',
			'payable_total',
			'non_service_payable_total',
			'registered_credit_total',
			'display_open_payable_documents',
			'provider_kind_label',
			'header_role_label',
			'provider_note'
		);
	}

	private function render_contact_profile_runtime_block( $section_key, array $context ) {
		switch ( $section_key ) {
			case 'profile-header-summary':
				$this->render_contact_profile_header_summary( $context );
				return;
			case 'profile-financial-cards':
				$this->render_contact_profile_financial_cards( $context );
				return;
			case 'profile-orders-summary':
				$this->render_contact_profile_orders_summary( $context );
				return;
			case 'profile-orders-table':
				$this->render_contact_profile_orders_table( $context );
				return;
			case 'profile-account-state':
				$this->render_contact_profile_account_state( $context );
				return;
			case 'profile-payments':
				$this->render_contact_profile_payments( $context );
				return;
			case 'profile-payroll':
				$this->render_contact_profile_payroll( $context );
				return;
			case 'profile-history':
				$this->render_contact_profile_history( $context );
				return;
		}

		throw new \RuntimeException( 'Unknown contact profile runtime block.' );
	}

	private function render_contact_profile_header_summary( array $context ) {
		extract( $context, EXTR_SKIP );
		$role_labels = array();
		if ( $is_customer ) {
			$role_labels[] = array(
				'label' => 'Cliente',
				'tone'  => 'success',
			);
		}
		if ( $is_employee ) {
			$role_labels[] = array(
				'label' => 'Empleado',
				'tone'  => 'warning',
			);
		}
		if ( $is_supplier ) {
			$role_labels[] = array(
				'label' => 'Proveedor',
				'tone'  => 'warning',
			);
		}
		if ( ! empty( $contact['internal_use_profile'] ) ) {
			$role_labels[] = array(
				'label' => 'Consumo / regalos',
				'tone'  => 'neutral',
			);
		}
		if ( empty( $role_labels ) ) {
			$role_labels[] = array(
				'label' => 'Sin rol definido',
				'tone'  => 'neutral',
			);
		}
		$wp_user_label          = $user ? $user->user_login . ' (#' . (int) $user->ID . ')' : 'No vinculado';
		$profile_modal_id       = 'contact-profile-config-' . (int) $contact['id'];
		$profile_summary_items  = array(
			array(
				'label' => 'Roles',
				'value' => implode( ' / ', wp_list_pluck( $role_labels, 'label' ) ),
			),
			array(
				'label' => 'Usuario WP',
				'value' => $wp_user_label,
			),
			array(
				'label' => 'Correo',
				'value' => $contact['email'] ?: 'Sin correo',
			),
			array(
				'label' => 'Telefono',
				'value' => $contact['phone'] ?: 'Sin telefono',
			),
		);
		?>
		<section class="asdl-fin-panel asdl-fin-contact-detail">
			<div class="asdl-fin-contact-header">
				<div>
					<h2><?php echo esc_html( $contact['display_name'] ); ?></h2>
					<p><?php echo esc_html( $header_role_label ); ?><?php echo ! empty( $contact['legal_name'] ) ? esc_html( ' | ' . $contact['legal_name'] ) : ''; ?></p>
				</div>
				<div class="asdl-fin-badge-group">
					<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'profile_origin', $contact['profile_origin'] ?? '' ), 'wp_user' === ( $contact['profile_origin'] ?? '' ) ? 'success' : 'neutral' ) ); ?>
					<?php if ( $is_supplier ) : ?>
						<?php echo wp_kses_post( $this->render_pill( $provider_kind_label, 'warning' ) ); ?>
					<?php endif; ?>
					<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'status', $contact['status'] ), $this->tone_for_status( $contact['status'] ) ) ); ?>
					<?php if ( empty( $contact['wp_user_id'] ) && ! empty( $contact['email'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="asdl_fin_promote_contact_to_user" />
							<input type="hidden" name="return_page" value="asdl-fin-contacts" />
							<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
							<?php $this->render_current_fiscal_hidden_input(); ?>
							<?php wp_nonce_field( 'asdl_fin_promote_contact_to_user' ); ?>
							<?php submit_button( 'Vincular o crear usuario interno', 'secondary small', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<div class="asdl-fin-anchor-links">
				<a href="#asdl-fin-contact-profile-overview">Perfil</a>
				<?php if ( $is_supplier_only ) : ?>
					<a href="#asdl-fin-contact-credit">Saldo a favor</a>
					<?php if ( $has_service_section ) : ?>
						<a href="#asdl-fin-contact-services">Servicios</a>
					<?php endif; ?>
					<a href="#asdl-fin-contact-documents">Movimientos</a>
				<?php else : ?>
					<a href="#asdl-fin-contact-collections">Cobranza</a>
					<a href="#asdl-fin-contact-orders">Pedidos</a>
					<a href="#asdl-fin-contact-consumption">Consumo</a>
					<a href="#asdl-fin-contact-documents">Movimientos</a>
					<a href="#asdl-fin-contact-credit">Saldo a favor</a>
					<?php if ( $has_service_section ) : ?>
						<a href="#asdl-fin-contact-services">Servicios</a>
					<?php endif; ?>
				<?php endif; ?>
				<a href="#asdl-fin-contact-commitments">Compromisos</a>
				<a href="#asdl-fin-contact-payments">Abonos y pagos</a>
				<a href="#asdl-fin-contact-history">Historico</a>
				<?php if ( $is_employee ) : ?>
					<a href="#asdl-fin-contact-employee">Empleado</a>
				<?php endif; ?>
			</div>

			<?php $this->render_profile_context_disclosure_start( 'asdl-fin-contact-profile-overview', 'Ficha general del perfil', 'Roles, usuario WordPress, contacto y estado operativo en un solo bloque.', $profile_summary_items, false ); ?>
				<div class="asdl-fin-data-grid">
					<div>
						<strong>Roles activos</strong>
						<span class="asdl-fin-badge-group">
							<?php foreach ( $role_labels as $role_label ) : ?>
								<?php echo wp_kses_post( $this->render_pill( $role_label['label'], $role_label['tone'] ) ); ?>
							<?php endforeach; ?>
						</span>
					</div>
					<div><strong>Usuario WP</strong><span><?php echo esc_html( $wp_user_label ); ?></span></div>
					<div><strong>Correo</strong><span><?php echo esc_html( $contact['email'] ?: '—' ); ?></span></div>
					<div><strong>Telefono</strong><span><?php echo esc_html( $contact['phone'] ?: '—' ); ?></span></div>
					<div><strong>Estado</strong><span><?php echo esc_html( $this->label_for( 'status', $contact['status'] ) ); ?></span></div>
					<div><strong>Documento</strong><span><?php echo esc_html( $contact['document_id'] ?: '—' ); ?></span></div>
				</div>
				<div class="asdl-fin-inline-actions">
					<button type="button" class="button button-primary asdl-fin-open-modal" data-modal-target="<?php echo esc_attr( $profile_modal_id ); ?>">Gestionar roles y estado</button>
					<?php if ( $user && get_edit_user_link( $user->ID ) ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">Abrir usuario en WordPress</a>
					<?php endif; ?>
				</div>
				<details class="asdl-fin-disclosure asdl-fin-profile-mini-disclosure">
					<summary>Ver mas del perfil</summary>
					<div class="asdl-fin-disclosure-body">
						<div class="asdl-fin-data-grid">
							<div><strong>Origen</strong><span><?php echo esc_html( $this->label_for( 'profile_origin', $contact['profile_origin'] ?? '' ) ); ?></span></div>
							<div><strong>Nombre legal</strong><span><?php echo esc_html( $contact['legal_name'] ?: '—' ); ?></span></div>
							<?php if ( $is_supplier ) : ?>
								<div><strong>Tipo proveedor</strong><span><?php echo esc_html( $provider_kind_label ); ?></span></div>
							<?php endif; ?>
							<?php if ( $has_service_section ) : ?>
								<div><strong>Servicios activos</strong><span><?php echo esc_html( number_format_i18n( $service_summary['active_profile_count'] ?? 0 ) ); ?></span></div>
								<div><strong>Servicios por pagar</strong><span><?php echo wp_kses_post( $this->format_money( $service_summary['open_balance_total'] ?? 0 ) ); ?></span></div>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $provider_note ) ) : ?>
							<div class="asdl-fin-note-box"><?php echo esc_html( $provider_note ); ?></div>
						<?php endif; ?>
						<?php if ( ! empty( $contact['notes'] ) ) : ?>
							<div class="asdl-fin-note-box"><?php echo esc_html( $contact['notes'] ); ?></div>
						<?php endif; ?>
					</div>
				</details>
			<?php $this->render_profile_context_disclosure_end(); ?>

			<?php if ( $show_customer_sections ) : ?>
				<details class="asdl-fin-disclosure" <?php echo $filters_open ? 'open' : ''; ?>>
					<summary>Filtrar pedidos y consumo</summary>
					<div class="asdl-fin-disclosure-body">
						<?php $this->render_profile_order_filters( (int) $contact['id'], $filters ); ?>
					</div>
				</details>
			<?php endif; ?>

			<div class="asdl-fin-modal asdl-fin-order-modal" data-modal="<?php echo esc_attr( $profile_modal_id ); ?>" hidden>
				<div class="asdl-fin-modal-overlay" data-modal-close></div>
				<div class="asdl-fin-modal-dialog">
					<div class="asdl-fin-modal-header">
						<div>
							<h2>Gestionar roles y estado</h2>
							<p>Actualiza como opera este perfil dentro del core sin tocar la informacion comercial del usuario.</p>
						</div>
						<button type="button" class="button button-secondary asdl-fin-modal-close-button" data-modal-close>Cerrar</button>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
						<input type="hidden" name="action" value="asdl_fin_update_contact_profile" />
						<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
						<input type="hidden" name="return_page" value="asdl-fin-contacts" />
						<?php $this->render_current_fiscal_hidden_input(); ?>
						<?php wp_nonce_field( 'asdl_fin_update_contact_profile' ); ?>
						<label class="asdl-fin-field asdl-fin-field-wide">
							<span>Roles operativos</span>
							<div class="asdl-fin-badge-group">
								<input type="hidden" name="is_customer" value="0" />
								<label class="asdl-fin-inline-checkbox">
									<input type="checkbox" name="is_customer" value="1" <?php checked( $is_customer ); ?> />
									<span>Cliente</span>
								</label>
								<input type="hidden" name="is_employee" value="0" />
								<label class="asdl-fin-inline-checkbox">
									<input type="checkbox" name="is_employee" value="1" <?php checked( $is_employee ); ?> />
									<span>Empleado</span>
								</label>
								<input type="hidden" name="is_supplier" value="0" />
								<label class="asdl-fin-inline-checkbox">
									<input type="checkbox" name="is_supplier" value="1" <?php checked( $is_supplier ); ?> />
									<span>Proveedor</span>
								</label>
								<input type="hidden" name="internal_use_profile" value="0" />
								<label class="asdl-fin-inline-checkbox">
									<input type="checkbox" name="internal_use_profile" value="1" <?php checked( ! empty( $contact['internal_use_profile'] ) ); ?> />
									<span>Consumo / regalos</span>
								</label>
							</div>
							<small>Activa aqui los roles reales del perfil. El rol interno sirve para consumibles, compras asumidas por la tienda y regalos facturados operativamente.</small>
						</label>
						<?php $this->render_select( 'status', 'Estado', array( 'active' => 'Activo', 'inactive' => 'Inactivo' ), true, '', $contact['status'] ?? 'active' ); ?>
						<?php $this->render_select( 'supplier_kind', 'Tipo de proveedor', $this->supplier_kind_options(), true, 'Solo aplica si este perfil tambien opera como proveedor.', $supplier_kind ?: 'general' ); ?>
						<label class="asdl-fin-field asdl-fin-field-wide">
							<span>Notas operativas</span>
							<textarea name="notes" rows="4"><?php echo esc_textarea( $contact['notes'] ?? '' ); ?></textarea>
							<small>Usa esta nota para aclarar contexto interno, operacion mixta o cualquier detalle que ayude a gestionar el perfil.</small>
						</label>
						<div class="asdl-fin-inline-actions">
							<button type="button" class="button button-secondary" data-modal-close>Cancelar</button>
							<?php submit_button( 'Guardar roles y estado', 'primary', 'submit', false ); ?>
						</div>
					</form>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_contact_profile_financial_cards( array $context ) {
		extract( $context, EXTR_SKIP );
		$all_time_consumption_summary = (array) ( $snapshot['consumption_all_time_summary'] ?? array() );
		$all_time_consumption_total   = (float) ( $all_time_consumption_summary['total'] ?? 0 );
		$all_time_consumption_orders  = (int) ( $all_time_consumption_summary['order_count'] ?? 0 );
		$all_time_pending_total       = max( 0, (float) ( $summary['pending_order_total'] ?? 0 ) + (float) $historical_pending_total );
		?>
		<div class="asdl-fin-card-grid">
			<?php if ( $is_supplier_only ) : ?>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Por pagar total</span>
					<strong><?php echo wp_kses_post( $this->format_money( $credit_total ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Documentado por pagar: <?php echo wp_kses_post( $this->format_money( $payable_total ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Saldo ya registrado a favor: <?php echo wp_kses_post( $this->format_money( $registered_credit_total ) ); ?></span>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Compromisos por pagar</span>
					<strong><?php echo wp_kses_post( $this->format_money( $summary['payable_commitment_total'] ?? 0 ) ); ?></strong>
					<p><span class="asdl-fin-card-breakdown">Acuerdos activos a favor del proveedor o tercero.</span></p>
				</div>
				<?php if ( $has_service_section ) : ?>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Servicios por pagar</span>
						<strong><?php echo wp_kses_post( $this->format_money( $service_open_balance ) ); ?></strong>
						<p><span class="asdl-fin-card-breakdown">Servicios emitidos pendientes con este proveedor o tercero.</span></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Proximo cobro</span>
						<strong><?php echo esc_html( $next_service_due_label ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown"><?php echo esc_html( '' !== $next_service_title ? $next_service_title : 'Sin servicio recurrente programado' ); ?></span>
							<?php if ( '' !== $next_service_issue_date ) : ?>
								<span class="asdl-fin-card-breakdown">Monto previsto: <?php echo wp_kses_post( $this->format_money( $next_service_amount, $next_service_currency ) ); ?></span>
							<?php endif; ?>
						</p>
					</div>
				<?php endif; ?>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pagos registrados</span>
					<strong><?php echo esc_html( number_format_i18n( $summary['payment_count'] ) ); ?></strong>
					<p><span class="asdl-fin-card-breakdown">Pagos o abonos registrados para este proveedor o tercero.</span></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Total pagado</span>
					<strong><?php echo wp_kses_post( $this->format_money( $summary['payments_total'] ) ); ?></strong>
					<p><span class="asdl-fin-card-breakdown">Total historico pagado o abonado a este proveedor o tercero.</span></p>
				</div>
			<?php else : ?>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Por cobrar</span>
					<strong><?php echo wp_kses_post( $this->format_money( $consolidated_receivable ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Total pendiente en pedidos: <?php echo wp_kses_post( $this->format_money( $summary['pending_order_total'] ?? 0 ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Base abierta en pedidos: <?php echo wp_kses_post( $this->format_money( $order_debt_gross_total ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Abonado a pedidos abiertos: <?php echo wp_kses_post( $this->format_money( $order_debt_paid_total ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Adelantos por recuperar: <?php echo wp_kses_post( $this->format_money( $summary['salary_advance_balance'] ?? 0 ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Historico por cerrar: <?php echo wp_kses_post( $this->format_money( $historical_pending_total ) ); ?></span>
						<?php if ( $store_debt_commitment_planned_total > 0.00001 ) : ?>
							<span class="asdl-fin-card-breakdown">Deuda de tienda ya planificada en compromisos: <?php echo wp_kses_post( $this->format_money( $store_debt_commitment_planned_total ) ); ?></span>
						<?php endif; ?>
						<span class="asdl-fin-card-breakdown">Compromisos adicionales por cobrar: <?php echo wp_kses_post( $this->format_money( $additional_receivable_commitment_total ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Balance neto: <?php echo esc_html( $net_position_label ); ?><?php echo abs( $net_position_total ) > 0.00001 ? wp_kses_post( ' | ' . $this->format_money( abs( $net_position_total ) ) ) : ''; ?></span>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Saldo a favor</span>
					<strong><?php echo wp_kses_post( $this->format_money( $credit_total ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Disponible hoy: <?php echo wp_kses_post( $this->format_money( $available_credit_total ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Deuda documentada de la empresa: <?php echo wp_kses_post( $this->format_money( $summary['payable_total'] ?? 0 ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Saldo ya registrado a favor: <?php echo wp_kses_post( $this->format_money( $summary['unapplied_payment_total'] ?? 0 ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Compromisos por pagar: <?php echo wp_kses_post( $this->format_money( $summary['payable_commitment_total'] ?? 0 ) ); ?></span>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pedidos pendientes</span>
					<strong><?php echo esc_html( number_format_i18n( $total_pending_count ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">En periodo consultado: <?php echo esc_html( number_format_i18n( $period_open_count ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Historico por cerrar: <?php echo esc_html( number_format_i18n( $historical_pending_count ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Compromisos activos: <?php echo esc_html( number_format_i18n( $summary['installment_plan_count'] ?? 0 ) ); ?></span>
						<span class="asdl-fin-card-breakdown">Pendiente en compromisos: <?php echo wp_kses_post( $this->format_money( $summary['installment_balance'] ?? 0 ) ); ?></span>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pagos registrados</span>
					<strong><?php echo esc_html( number_format_i18n( $summary['payment_count'] ) ); ?></strong>
					<p>Cobros, pagos o abonos cargados a este perfil dentro del core.</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Total abonado</span>
					<strong><?php echo wp_kses_post( $this->format_money( $summary['payments_total'] ) ); ?></strong>
					<p>Total historico registrado en cobros, pagos o abonos aplicados a este perfil.</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pendiente periodo actual</span>
					<strong><?php echo wp_kses_post( $this->format_money( $summary['open_order_total'] ?? 0 ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Abierto dentro del periodo actual filtrado.</span>
						<span class="asdl-fin-card-breakdown">Pendiente total abierto: <?php echo wp_kses_post( $this->format_money( $all_time_pending_total ) ); ?></span>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Consumo periodo actual</span>
					<strong><?php echo wp_kses_post( $this->format_money( $summary['orders_total'] ?? 0 ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Total comprado o consumido dentro del periodo actual.</span>
						<span class="asdl-fin-card-breakdown">Consumo total acumulado: <?php echo wp_kses_post( $this->format_money( $all_time_consumption_total ) ); ?></span>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pedidos periodo actual</span>
					<strong><?php echo esc_html( number_format_i18n( $summary['order_count'] ?? 0 ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Pedidos Woo/OpenPOS cargados segun el periodo actual y el limite visible.</span>
						<span class="asdl-fin-card-breakdown">Pedidos acumulados: <?php echo esc_html( number_format_i18n( $all_time_consumption_orders ) ); ?></span>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Promedio por pedido</span>
					<strong><?php echo wp_kses_post( $this->format_money( $summary['average_ticket'] ?? 0 ) ); ?></strong>
					<p>Consumo promedio por pedido dentro del periodo consultado.</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_contact_profile_orders_summary( array $context ) {
		extract( $context, EXTR_SKIP );

		if ( ! $show_customer_sections ) {
			?>
			<section class="asdl-fin-panel">
				<div class="asdl-fin-empty">
					<strong>Este perfil no opera por pedidos de tienda.</strong>
					<p>La cobranza comercial solo aparece para perfiles con cartera Woo/OpenPOS.</p>
				</div>
			</section>
			<?php
			return;
		}

		$pending_summary_open  = ( (int) ( $pending_summary['pending_count'] ?? 0 ) > 0 ) || ( (float) ( $pending_summary['pending_order_total'] ?? 0 ) > 0 );
		$pending_summary_copy  = $pending_summary_open
			? sprintf(
				'%1$s pedido(s) abiertos y %2$s pendientes en la cola operativa.',
				number_format_i18n( (int) ( $pending_summary['pending_count'] ?? 0 ) ),
				wp_strip_all_tags( $this->format_money( $pending_summary['pending_order_total'] ?? 0 ) )
			)
			: 'Sin pedidos pendientes en la cola operativa actual.';
		$pending_summary_items = array(
			array(
				'label' => 'Abiertos',
				'value' => number_format_i18n( (int) ( $pending_summary['pending_count'] ?? 0 ) ),
			),
			array(
				'label' => 'Pendiente',
				'value' => wp_strip_all_tags( $this->format_money( $pending_summary['pending_order_total'] ?? 0 ) ),
			),
		);
		?>
		<section class="asdl-fin-panel asdl-fin-profile-context-panel" id="asdl-fin-contact-orders-summary">
			<?php $this->render_profile_context_disclosure_start( 'asdl-fin-contact-orders-summary', 'Resumen de pedidos pendientes', $pending_summary_copy, $pending_summary_items, $pending_summary_open ); ?>
			<div class="asdl-fin-panel-header asdl-fin-profile-context-header">
				<h2>Resumen de pedidos pendientes</h2>
				<p>Lectura operativa del ejercicio actual y el fiscal anterior antes de abrir la tabla completa.</p>
			</div>
			<div class="asdl-fin-card-grid asdl-fin-collections-summary-grid">
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pedidos abiertos</span>
					<strong><?php echo esc_html( number_format_i18n( $pending_summary['pending_count'] ?? 0 ) ); ?></strong>
					<p><span class="asdl-fin-card-breakdown">Total operativo pendiente por cobrar.</span></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pendiente total</span>
					<strong><?php echo wp_kses_post( $this->format_money( $pending_summary['pending_order_total'] ?? 0 ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Incluye solo el ejercicio actual y el fiscal anterior que sigan abiertos.</span>
						<?php if ( $dual_discount_active ) : ?>
							<span class="asdl-fin-card-breakdown"><?php echo esc_html( $dual_discount_label ); ?><?php echo wp_kses_post( $this->format_money( $dual_pending_total, 'USD' ) ); ?></span>
						<?php endif; ?>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">En periodo consultado</span>
					<strong><?php echo esc_html( number_format_i18n( $period_open_count ) ); ?></strong>
					<p><span class="asdl-fin-card-breakdown">Pedidos abiertos dentro del filtro actual.</span></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pendiente en periodo</span>
					<strong><?php echo wp_kses_post( $this->format_money( $period_open_total ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Saldo abierto del rango consultado.</span>
						<?php if ( $dual_discount_active ) : ?>
							<span class="asdl-fin-card-breakdown"><?php echo esc_html( $dual_discount_label ); ?><?php echo wp_kses_post( $this->format_money( $dual_period_open_total, 'USD' ) ); ?></span>
						<?php endif; ?>
					</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Historico reciente</span>
					<strong><?php echo esc_html( number_format_i18n( $historical_pending_count ) ); ?></strong>
					<p><span class="asdl-fin-card-breakdown">Pedidos pendientes del ejercicio fiscal anterior o fuera del filtro actual.</span></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pendiente historico</span>
					<strong><?php echo wp_kses_post( $this->format_money( $historical_pending_total ) ); ?></strong>
					<p>
						<span class="asdl-fin-card-breakdown">Saldo abierto acumulado del ejercicio fiscal anterior.</span>
						<?php if ( $dual_discount_active ) : ?>
							<span class="asdl-fin-card-breakdown"><?php echo esc_html( $dual_discount_label ); ?><?php echo wp_kses_post( $this->format_money( $dual_historical_total, 'USD' ) ); ?></span>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<?php $this->render_profile_context_disclosure_end(); ?>
		</section>
		<?php
	}

	private function render_contact_profile_orders_table( array $context ) {
		extract( $context, EXTR_SKIP );

		if ( ! $show_customer_sections ) {
			return;
		}

		$pending_operational_count = count( $snapshot['pending_orders'] ?? array() );
		$register_abono_open       = ! $readonly_context && $pending_operational_count > 0;
		$register_abono_copy       = $register_abono_open
			? sprintf(
				'%1$s pedido(s) abierto(s) listos para abono por antiguedad.',
				number_format_i18n( $pending_operational_count )
			)
			: 'Sin deuda operativa lista para abonos en este momento.';
		$register_abono_items      = array(
			array(
				'label' => 'Abiertos',
				'value' => number_format_i18n( $pending_operational_count ),
			),
			array(
				'label' => 'Pendiente',
				'value' => wp_strip_all_tags( $this->format_money( $pending_summary['pending_order_total'] ?? 0 ) ),
			),
			array(
				'label_html' => '<span class="asdl-fin-profile-context-chip-dual-percent" data-settlement-summary-percent>Precio dual 0,00%</span>',
				'value_html' => '<span class="asdl-fin-profile-context-chip-dual-total" data-settlement-summary-total>USD 0,00</span>',
				'extra_html' => '<button type="button" class="button-link asdl-fin-profile-context-chip-action" data-settlement-dual-apply>Usar</button>',
				'class'      => 'asdl-fin-profile-context-chip-dual',
				'attrs'      => array(
					'data-settlement-summary-chip' => '1',
					'hidden'                       => 'hidden',
				),
			),
		);
		?>
		<section class="asdl-fin-panel" id="asdl-fin-contact-collections">
			<div class="asdl-fin-panel-header">
				<h2>Pedidos pendientes por cobrar</h2>
				<p>Gestion operativa de abonos, asunciones y cierre por antiguedad sobre pedidos Woo/OpenPOS.</p>
			</div>
			<div class="asdl-fin-collections-stack">
				<section class="asdl-fin-panel asdl-fin-contact-settlement-panel asdl-fin-profile-context-panel" id="asdl-fin-contact-register-abono">
					<?php $this->render_profile_context_disclosure_start( 'asdl-fin-contact-register-abono', 'Registrar abono', $register_abono_copy, $register_abono_items, $register_abono_open ); ?>
					<div class="asdl-fin-panel-header asdl-fin-profile-context-header">
						<h2>Registrar abono</h2>
						<p>Aplica el monto por antiguedad: cierra primero los pedidos mas viejos y deja parcial el siguiente si el abono no alcanza.</p>
					</div>
					<?php if ( $readonly_context ) : ?>
						<?php $this->render_fiscal_readonly_action_state( 'El registro de abonos queda bloqueado en modo consulta.' ); ?>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-contact-settlement-form" data-order-settlement-preview-form="1" data-order-settlement-origin="profile_settlement" data-settlement-open-total="<?php echo esc_attr( number_format( (float) ( $pending_summary['pending_order_total'] ?? 0 ), 2, '.', '' ) ); ?>" data-settlement-dual-total="<?php echo esc_attr( number_format( (float) $dual_pending_total, 2, '.', '' ) ); ?>" data-settlement-dual-percent="<?php echo esc_attr( number_format( (float) $dual_discount_percent, 2, '.', '' ) ); ?>" data-settlement-dual-reference-active="<?php echo esc_attr( $dual_discount_active ? '1' : '0' ); ?>" data-settlement-dual-reference-total="<?php echo esc_attr( number_format( (float) $dual_pending_total, 2, '.', '' ) ); ?>" data-settlement-dual-reference-percent="<?php echo esc_attr( number_format( (float) $dual_discount_percent, 2, '.', '' ) ); ?>">
							<input type="hidden" name="action" value="asdl_fin_settle_profile_orders" />
							<input type="hidden" name="return_page" value="asdl-fin-contacts" />
							<input type="hidden" name="return_section" value="asdl-fin-contact-register-abono" />
							<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
							<input type="hidden" name="range_from" value="<?php echo esc_attr( $filters['range_from'] ?? '' ); ?>" />
							<input type="hidden" name="range_to" value="<?php echo esc_attr( $filters['range_to'] ?? '' ); ?>" />
							<input type="hidden" name="order_limit" value="<?php echo esc_attr( $filters['order_limit'] ?? 25 ); ?>" />
							<input type="hidden" name="dual_discount_preview_confirmed" value="0" data-settlement-preview-confirmed />
							<input type="hidden" name="dual_discount_preview_signature" value="" data-settlement-preview-signature />
							<input type="hidden" name="dual_discount_mode" value="" data-settlement-dual-mode />
							<input type="hidden" name="selection_mode" value="oldest_first" data-settlement-selection-mode />
							<input type="hidden" name="include_credit_balance" value="0" data-settlement-include-credit />
							<input type="hidden" name="remainder_policy" value="create_credit" data-settlement-remainder-policy />
							<?php $this->render_current_fiscal_hidden_input(); ?>
							<?php wp_nonce_field( 'asdl_fin_settle_profile_orders' ); ?>
							<div class="asdl-fin-field asdl-fin-field-wide">
								<div class="asdl-fin-settlement-controls">
									<label class="asdl-fin-inline-checkbox">
										<input type="checkbox" name="force_dual_discount" value="1" data-settlement-force-dual />
										<span>Descuento automatico</span>
									</label>
									<small class="asdl-fin-settlement-force-dual-help" data-settlement-force-dual-help>Cuando esta activo, el abono solo evaluara precio dual si la moneda registrada es USD y el metodo califica.</small>
									<?php if ( (float) $usable_credit_total > 0 ) : ?>
										<label class="asdl-fin-inline-checkbox">
											<input type="checkbox" value="1" data-settlement-include-credit-toggle />
											<span>Incluir saldo a favor disponible</span>
										</label>
										<small class="asdl-fin-settlement-credit-help">Disponible hoy: <?php echo wp_kses_post( $this->format_money( $usable_credit_total ) ); ?>. Si lo activas, el preview suma ese credito al efectivo del abono.</small>
									<?php endif; ?>
								</div>
							</div>
							<?php $this->render_select( 'account_id', 'Cuenta de entrada', $account_options, false, 'Opcional' ); ?>
							<?php $this->render_input( 'payment_date', 'Fecha del abono', 'date', gmdate( 'Y-m-d' ), true, array( 'data-settlement-payment-date' => '1' ) ); ?>
							<?php $this->render_input( 'total', 'Monto del abono', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01', 'data-settlement-total' => '1' ) ); ?>
							<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true, 'Selecciona una moneda', array( 'data-settlement-currency' => '1' ) ); ?>
							<?php $this->render_payment_method_select( 'method_key', 'Metodo', '', true ); ?>
							<div class="asdl-fin-note-box asdl-fin-field-wide">
								<strong>Como se usa la moneda en este abono</strong>
								<p>La moneda indica en que divisa se registrara este pago. Solo si queda en <code>USD</code> y el descuento automatico esta activo se evaluara precio dual.</p>
							</div>
							<?php $this->render_input( 'reference', 'Referencia', 'text', '' ); ?>
							<?php $this->render_textarea( 'notes', 'Notas', 'Si el monto supera varios pedidos, el sistema los ira cerrando por fecha y dejara parcial el siguiente si aplica.' ); ?>
							<div class="asdl-fin-inline-actions">
								<?php submit_button( 'Aplicar abono a pedidos', 'primary', 'submit', false ); ?>
								<button type="button" class="button button-secondary" data-order-settlement-specific-open="1">Pedidos especificos</button>
							</div>
						</form>
						<?php if ( $pending_operational_count > 0 ) : ?>
							<div class="asdl-fin-order-assumption-callout asdl-fin-note-box">
								<div class="asdl-fin-order-assumption-callout-copy">
									<strong>Gestionar pedidos como gasto o regalo</strong>
									<p>Usa esta accion cuando la tienda asumira consumos internos o regalos facturados operativamente. Es independiente del monto y del metodo del abono.</p>
								</div>
								<div class="asdl-fin-inline-actions">
									<button
										type="button"
										class="button button-secondary"
										data-order-assumption-open="1"
										data-contact-id="<?php echo esc_attr( (int) $contact['id'] ); ?>"
										data-contact-label="<?php echo esc_attr( sanitize_text_field( (string) ( $contact['display_name'] ?? 'Perfil' ) ) ); ?>"
										data-assumption-origin="profile_order_assumption"
									>Gestionar gasto / regalo</button>
								</div>
							</div>
						<?php endif; ?>
					<?php endif; ?>
					<?php $this->render_profile_context_disclosure_end(); ?>
				</section>
			</div>
			<?php $this->render_orders_table(
				$snapshot['pending_orders'] ?? array(),
				'asdl-fin-contacts',
				array(
					'contact_id' => (int) $contact['id'],
					'range_from' => $filters['range_from'] ?? '',
					'range_to'   => $filters['range_to'] ?? '',
					'order_limit'=> $filters['order_limit'] ?? 25,
				),
				array(
					'mode'               => 'operational',
					'action_label'       => 'Gestion',
					'table_title'        => 'Pedidos abiertos para cruce / gestion',
					'table_description'  => 'Cola operativa de pedidos Woo/OpenPOS que siguen abiertos y aun puedes abonar, compensar o regularizar desde este perfil. Si un pedido ya quedo pagado, sale de esta lista por diseno.',
					'per_page'           => 15,
					'expanded_per_page'  => 30,
					'show_expand_toggle' => true,
				)
			); ?>
		</section>

		<section class="asdl-fin-panel" id="asdl-fin-contact-orders">
			<div class="asdl-fin-panel-header">
				<h2>Pedidos</h2>
				<p>Lectura historica del periodo filtrado para revisar consumo, frecuencia y deuda pendiente del perfil.</p>
			</div>
			<div class="asdl-fin-data-grid">
				<div><strong>Pedidos en rango</strong><span><?php echo esc_html( number_format_i18n( $order_summary['order_count'] ?? 0 ) ); ?></span></div>
				<div><strong>Pendientes en rango</strong><span><?php echo esc_html( number_format_i18n( $order_summary['open_order_count'] ?? 0 ) ); ?></span></div>
				<div><strong>Total consumido</strong><span><?php echo wp_kses_post( $this->format_money( $order_summary['orders_total'] ?? 0 ) ); ?></span></div>
				<div><strong>Total pendiente en rango</strong><span><?php echo wp_kses_post( $this->format_money( $order_summary['open_order_total'] ?? 0 ) ); ?></span></div>
			</div>
			<?php $this->render_orders_table(
				$snapshot['orders'] ?? array(),
				'asdl-fin-contacts',
				array(
					'contact_id' => (int) $contact['id'],
					'range_from' => $filters['range_from'] ?? '',
					'range_to'   => $filters['range_to'] ?? '',
					'order_limit'=> $filters['order_limit'] ?? 25,
				),
				array(
					'mode'               => 'history',
					'action_label'       => 'Gestion',
					'table_title'        => 'Vista amplia de pedidos del rango',
					'table_description'  => 'Esta tabla si mezcla lectura actual e historica del rango filtrado para revisar consumo, frecuencia, pedidos pagados y deuda pendiente del perfil.',
					'per_page'           => 15,
					'expanded_per_page'  => 30,
					'show_expand_toggle' => true,
				)
			); ?>
		</section>

		<section class="asdl-fin-panel" id="asdl-fin-contact-consumption">
			<div class="asdl-fin-panel-header">
				<h2>Consumo e interacciones</h2>
				<p>Resumen del mismo periodo filtrado para leer habito de compra, frecuencia e intensidad del perfil.</p>
			</div>
			<?php $this->render_consumption_interactions_panel( $snapshot ); ?>
		</section>
		<?php
	}

	private function render_contact_profile_credit_section( array $context ) {
		extract( $context, EXTR_SKIP );

		$pending_operational_count = count( $snapshot['pending_orders'] ?? array() );
		$pay_profile_open          = ! $readonly_context && $payable_total > 0;
		$pay_profile_copy          = $pay_profile_open
			? sprintf(
				'Deuda documentada abierta lista para pago manual: %s.',
				wp_strip_all_tags( $this->format_money( $payable_total ) )
			)
			: 'Sin deuda documentada abierta lista para pago manual en este momento.';
		$pay_profile_items         = array(
			array(
				'label' => 'Documentado',
				'value' => wp_strip_all_tags( $this->format_money( $payable_total ) ),
			),
			array(
				'label' => 'Disponible',
				'value' => wp_strip_all_tags( $this->format_money( $available_credit_total ) ),
			),
		);
		$register_credit_copy      = $credit_total > 0
			? sprintf( 'Saldo a favor actual: %s.', wp_strip_all_tags( $this->format_money( $credit_total ) ) )
			: 'Registra deuda de la empresa con este perfil para gestionarla luego como pago o compensacion.';
		$register_credit_items     = array(
			array(
				'label' => 'Saldo actual',
				'value' => wp_strip_all_tags( $this->format_money( $credit_total ) ),
			),
			array(
				'label' => 'Disponible hoy',
				'value' => wp_strip_all_tags( $this->format_money( $available_credit_total ) ),
			),
		);
		$apply_credit_open         = ! $readonly_context && $usable_credit_total > 0 && $pending_operational_count > 0;
		$apply_credit_copy         = $apply_credit_open
			? sprintf(
				'Disponible hoy: %1$s para compensar contra %2$s pedido(s) abierto(s).',
				wp_strip_all_tags( $this->format_money( $usable_credit_total ) ),
				number_format_i18n( $pending_operational_count )
			)
			: 'Sin saldo utilizable o sin pedidos abiertos para aplicar contra pedidos.';
		$apply_credit_items        = array(
			array(
				'label' => 'Disponible',
				'value' => wp_strip_all_tags( $this->format_money( $available_credit_total ) ),
			),
			array(
				'label' => 'Aplicable hoy',
				'value' => wp_strip_all_tags( $this->format_money( $usable_credit_total ) ),
			),
		);
		$credit_breakdown_rows     = array(
			array(
				'label'       => 'Deuda documentada de la empresa',
				'description' => 'Documentos abiertos donde la empresa le debe dinero a este perfil.',
				'value'       => $this->format_money( $summary['payable_total'] ?? 0 ),
			),
			array(
				'label'       => 'Pagos o abonos disponibles sin aplicar',
				'description' => 'Monto ya registrado a favor del perfil y todavia no usado en cruces o pagos.',
				'value'       => $this->format_money( $summary['unapplied_payment_total'] ?? 0 ),
			),
			array(
				'label'       => 'Compromisos por pagar',
				'description' => 'Acuerdos programados a favor del perfil que aun no pasan por pago manual.',
				'value'       => $this->format_money( $summary['payable_commitment_total'] ?? 0 ),
			),
		);

		if ( (float) ( $summary['salary_advance_balance'] ?? 0 ) > 0 ) {
			$credit_breakdown_rows[] = array(
				'label'       => 'Adelantos por recuperar',
				'description' => 'No forman parte del saldo a favor. Se rebajan por nomina o recuperacion manual.',
				'value'       => $this->format_money( $summary['salary_advance_balance'] ?? 0 ),
				'tone'        => 'warning',
			);
		}
		?>
		<section class="asdl-fin-panel" id="asdl-fin-contact-credit">
			<div class="asdl-fin-panel-header">
				<h2>Saldo a favor y pagos al perfil</h2>
				<p>Aqui registras deuda de la empresa con el perfil, pagas esa deuda manualmente o usas el saldo disponible para compensar pedidos abiertos.</p>
			</div>
			<?php $this->render_summary_rows(
				array(
					array(
						'label'       => 'Saldo actual',
						'description' => 'Total a favor del perfil entre deuda documentada, pagos registrados y compromisos por pagar.',
						'value'       => $this->format_money( $credit_total ),
					),
					array(
						'label'       => 'Disponible hoy',
						'description' => 'Parte del saldo que ya puede pagarse o aplicarse de inmediato.',
						'value'       => $this->format_money( $available_credit_total ),
					),
					array(
						'label'       => 'Documentado por pagar',
						'description' => 'Deuda abierta de la empresa que ya existe como documento emitido.',
						'value'       => $this->format_money( $summary['payable_total'] ?? 0 ),
					),
					array(
						'label'       => 'Compromisos por pagar',
						'description' => 'Acuerdos futuros a favor del perfil que aun no se pagan manualmente.',
						'value'       => $this->format_money( $summary['payable_commitment_total'] ?? 0 ),
					),
					array(
						'label'       => 'Aplicable a pedidos hoy',
						'description' => 'Tope operativo entre el saldo utilizable y el pendiente real de pedidos abiertos.',
						'value'       => $this->format_money( $usable_credit_total ),
					),
				)
			); ?>
			<details class="asdl-fin-disclosure asdl-fin-financial-detail-disclosure">
				<summary>Ver desglose del saldo a favor</summary>
				<div class="asdl-fin-disclosure-body">
					<?php $this->render_summary_rows( $credit_breakdown_rows ); ?>
				</div>
			</details>
			<div class="asdl-fin-collections-stack">
				<section class="asdl-fin-panel asdl-fin-profile-context-panel" id="asdl-fin-contact-credit-register">
					<?php $this->render_profile_context_disclosure_start( 'asdl-fin-contact-credit-register', 'Registrar saldo a favor', $register_credit_copy, $register_credit_items, false ); ?>
					<div class="asdl-fin-panel-header asdl-fin-profile-context-header">
						<h2>Registrar saldo a favor</h2>
						<p>Usa esta accion cuando la empresa le deba dinero a este perfil y quieras documentarlo para pagarlo despues o usarlo en compensaciones.</p>
					</div>
					<?php if ( $readonly_context ) : ?>
						<?php $this->render_fiscal_readonly_action_state( 'El alta de saldo a favor queda bloqueada en modo consulta.' ); ?>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid" enctype="multipart/form-data">
							<input type="hidden" name="action" value="asdl_fin_save_document" />
							<input type="hidden" name="return_page" value="asdl-fin-contacts" />
							<input type="hidden" name="return_section" value="asdl-fin-contact-credit-register" />
							<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
							<input type="hidden" name="range_from" value="<?php echo esc_attr( $filters['range_from'] ?? '' ); ?>" />
							<input type="hidden" name="range_to" value="<?php echo esc_attr( $filters['range_to'] ?? '' ); ?>" />
							<input type="hidden" name="order_limit" value="<?php echo esc_attr( $filters['order_limit'] ?? 25 ); ?>" />
							<?php $this->render_current_fiscal_hidden_input(); ?>
							<?php wp_nonce_field( 'asdl_fin_save_document' ); ?>
							<?php $this->render_hidden_inputs(
								array(
									'document_type'    => 'manual_document',
									'source_type'      => 'manual',
									'financial_status' => 'posted',
									'payment_status'   => 'pending',
									'manual_override'  => '1',
									'financial_intent' => 'neutral',
									'balance_nature'   => 'payable',
								)
							); ?>
							<?php $this->render_input( 'title', 'Concepto', 'text', '', true ); ?>
							<?php $this->render_select( 'account_id', 'Cuenta', $account_options, false, 'Opcional' ); ?>
							<?php $this->render_input( 'issue_date', 'Fecha', 'date', gmdate( 'Y-m-d' ), true ); ?>
							<?php $this->render_input( 'total', 'Monto a favor', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01' ) ); ?>
							<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
							<?php $this->render_input( 'external_reference', 'Referencia', 'text', '' ); ?>
							<?php $this->render_textarea( 'notes', 'Notas', 'Documenta aqui la deuda de la empresa con el perfil para luego pagarla manualmente o usarla como compensacion.' ); ?>
							<?php submit_button( 'Registrar saldo a favor', 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<?php $this->render_profile_context_disclosure_end(); ?>
				</section>
				<section class="asdl-fin-panel asdl-fin-profile-context-panel" id="asdl-fin-contact-credit-payout">
					<?php $this->render_profile_context_disclosure_start( 'asdl-fin-contact-credit-payout', 'Pagar al perfil', $pay_profile_copy, $pay_profile_items, $pay_profile_open ); ?>
					<div class="asdl-fin-panel-header asdl-fin-profile-context-header">
						<h2>Pagar al perfil</h2>
						<p>Registra el pago real que la empresa le entrega al perfil y aplicalo oldest-first contra la deuda documentada abierta.</p>
					</div>
					<?php if ( $readonly_context ) : ?>
						<?php $this->render_fiscal_readonly_action_state( 'El pago manual al perfil queda bloqueado en modo consulta.' ); ?>
					<?php elseif ( $pay_profile_open ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
							<input type="hidden" name="action" value="asdl_fin_pay_profile_credit" />
							<input type="hidden" name="return_page" value="asdl-fin-contacts" />
							<input type="hidden" name="return_section" value="asdl-fin-contact-credit-payout" />
							<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
							<input type="hidden" name="range_from" value="<?php echo esc_attr( $filters['range_from'] ?? '' ); ?>" />
							<input type="hidden" name="range_to" value="<?php echo esc_attr( $filters['range_to'] ?? '' ); ?>" />
							<input type="hidden" name="order_limit" value="<?php echo esc_attr( $filters['order_limit'] ?? 25 ); ?>" />
							<?php $this->render_current_fiscal_hidden_input(); ?>
							<?php wp_nonce_field( 'asdl_fin_pay_profile_credit' ); ?>
							<?php $this->render_select( 'account_id', 'Cuenta de salida', $account_options, false, 'Opcional' ); ?>
							<?php $this->render_input( 'payment_date', 'Fecha del pago', 'date', gmdate( 'Y-m-d' ), true ); ?>
							<?php $this->render_input( 'total', 'Monto a pagar', 'number', (string) number_format( $payable_total, 2, '.', '' ), true, array( 'step' => '0.01', 'min' => '0.01', 'max' => number_format( $payable_total, 2, '.', '' ) ) ); ?>
							<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
							<?php $this->render_payment_method_select( 'method_key', 'Metodo', '', true ); ?>
							<?php $this->render_input( 'reference', 'Referencia', 'text', '' ); ?>
							<?php $this->render_textarea( 'notes', 'Notas', 'Este pago manual se asignara por antiguedad contra la deuda documentada abierta del perfil.' ); ?>
							<?php submit_button( 'Pagar al perfil', 'secondary', 'submit', false ); ?>
						</form>
					<?php else : ?>
						<div class="asdl-fin-empty">
							<strong>Sin deuda documentada abierta lista para pago manual.</strong>
							<p>Los compromisos por pagar no cuentan aqui hasta que exista un documento abierto por pagar asociado a este perfil.</p>
						</div>
					<?php endif; ?>
					<?php $this->render_profile_context_disclosure_end(); ?>
				</section>
				<?php if ( $show_customer_sections ) : ?>
					<section class="asdl-fin-panel asdl-fin-profile-context-panel" id="asdl-fin-contact-credit-apply">
						<?php $this->render_profile_context_disclosure_start( 'asdl-fin-contact-credit-apply', 'Aplicar a pedidos', $apply_credit_copy, $apply_credit_items, $apply_credit_open ); ?>
						<div class="asdl-fin-panel-header asdl-fin-profile-context-header">
							<h2>Aplicar a pedidos</h2>
							<p>Usa el saldo disponible del perfil para compensar internamente pedidos abiertos sin registrar una nueva entrada de caja.</p>
						</div>
						<?php if ( $readonly_context ) : ?>
							<?php $this->render_fiscal_readonly_action_state( 'La compensacion interna queda bloqueada en modo consulta.' ); ?>
						<?php elseif ( $usable_credit_total > 0 && $pending_operational_count > 0 ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
								<input type="hidden" name="action" value="asdl_fin_apply_profile_credit" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="return_section" value="asdl-fin-contact-credit-apply" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<input type="hidden" name="range_from" value="<?php echo esc_attr( $filters['range_from'] ?? '' ); ?>" />
								<input type="hidden" name="range_to" value="<?php echo esc_attr( $filters['range_to'] ?? '' ); ?>" />
								<input type="hidden" name="order_limit" value="<?php echo esc_attr( $filters['order_limit'] ?? 25 ); ?>" />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_apply_profile_credit' ); ?>
								<?php $this->render_select( 'account_id', 'Cuenta interna', $account_options, false, 'Opcional' ); ?>
								<?php $this->render_input( 'payment_date', 'Fecha de compensacion', 'date', gmdate( 'Y-m-d' ), true ); ?>
								<?php $this->render_input( 'total', 'Monto a cruzar', 'number', (string) number_format( $usable_credit_total, 2, '.', '' ), true, array( 'step' => '0.01', 'min' => '0.01', 'max' => number_format( $usable_credit_total, 2, '.', '' ) ) ); ?>
								<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
								<?php $this->render_input( 'reference', 'Referencia interna', 'text', '' ); ?>
								<?php $this->render_textarea( 'notes', 'Notas', 'Cruce interno entre saldo a favor del perfil y pedidos pendientes. Si alcanza, el pedido se completara en Woo.' ); ?>
								<?php submit_button( 'Aplicar a pedidos', 'secondary', 'submit', false ); ?>
							</form>
						<?php elseif ( $pending_operational_count <= 0 ) : ?>
							<div class="asdl-fin-empty">
								<strong>Sin pedidos abiertos para compensar.</strong>
								<p>El saldo a favor sigue disponible, pero hoy no hay pedidos abiertos sobre los que aplicar esa compensacion interna.</p>
							</div>
						<?php else : ?>
							<div class="asdl-fin-empty">
								<strong>Sin saldo utilizable en este momento.</strong>
								<p>Para aplicar a pedidos necesitas credito disponible hoy o documentos abiertos donde la empresa le deba dinero a este perfil.</p>
							</div>
						<?php endif; ?>
						<?php $this->render_profile_context_disclosure_end(); ?>
					</section>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	private function render_contact_profile_account_state( array $context ) {
		$this->render_contact_profile_credit_section( $context );

		$this->render_contact_detail_section_slice(
			$context['snapshot'],
			array(
				'asdl-fin-contact-documents',
				'asdl-fin-contact-open',
			),
			'Sin movimientos o cuentas auxiliares disponibles.',
			'No encontramos movimientos ni cuentas por pagar adicionales para este perfil.'
		);
	}

	private function render_contact_profile_payments( array $context ) {
		$snapshot = $context['snapshot'];
		?>
		<section class="asdl-fin-panel" id="asdl-fin-contact-payments">
			<div class="asdl-fin-panel-header">
				<h2>Abonos y pagos</h2>
				<p>Movimientos financieros registrados sobre este perfil.</p>
			</div>
			<?php $this->render_payments_table( $snapshot['payments'] ?? array(), array( 'allow_cancel' => true ) ); ?>
		</section>
		<?php
	}

	private function render_contact_profile_payroll( array $context ) {
		if ( empty( $context['is_employee'] ) ) {
			return;
		}

		$this->render_contact_detail_section_slice(
			$context['snapshot'],
			array( 'asdl-fin-contact-employee' ),
			'Sin datos laborales disponibles.',
			'Este perfil todavia no tiene seccion laboral renderizable.'
		);
	}

	private function render_contact_profile_history( array $context ) {
		$this->render_contact_detail_section_slice(
			$context['snapshot'],
			array(
				'asdl-fin-contact-services',
				'asdl-fin-contact-commitments',
				'asdl-fin-contact-history',
			),
			'Sin historico adicional.',
			'No hay secciones auxiliares o historicas para mostrar en este perfil.'
		);
	}

	private function render_contact_detail_section_slice( array $snapshot, array $section_ids, $empty_title, $empty_description ) {
		ob_start();
		$this->render_contact_detail( $snapshot );
		$html = trim( (string) ob_get_clean() );

		if ( '' === $html ) {
			$this->render_runtime_panel_skeleton( $empty_title, $empty_description );
			return;
		}

		$internal_errors = libxml_use_internal_errors( true );
		$dom             = new \DOMDocument( '1.0', 'UTF-8' );
		$loaded          = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><div id="asdl-fin-contact-slice-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );

		if ( ! $loaded ) {
			$this->render_runtime_panel_skeleton( $empty_title, $empty_description );
			return;
		}

		$xpath    = new \DOMXPath( $dom );
		$rendered = false;

		foreach ( $section_ids as $section_id ) {
			$section_id = sanitize_text_field( (string) $section_id );
			$nodes      = $xpath->query( sprintf( '//*[@id="%s"]', $section_id ) );
			if ( ! $nodes || 0 === $nodes->length ) {
				continue;
			}

			$rendered = true;
			echo $dom->saveHTML( $nodes->item( 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! $rendered ) {
			?>
			<section class="asdl-fin-panel">
				<div class="asdl-fin-empty">
					<strong><?php echo esc_html( $empty_title ); ?></strong>
					<p><?php echo esc_html( $empty_description ); ?></p>
				</div>
			</section>
			<?php
		}
	}

	private function render_services_runtime_section( $section_key ) {
		$documents_repository   = new DocumentsRepository();
		$contacts_repository    = new ContactsRepository();
		$service_profiles_repo  = new ServiceProfilesRepository();
		$today                  = gmdate( 'Y-m-d' );
		$upcoming_from          = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$upcoming_to            = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

		switch ( $section_key ) {
			case 'services-summary':
				$service_documents  = $documents_repository->list_admin(
					array(
						'document_type' => 'service_expense',
						'limit'         => 120,
					)
				);
				$service_summary    = $this->summarize_service_documents( $service_documents );
				$recurring_summary  = $service_profiles_repo->summary_counts( $today, $upcoming_to );
				?>
				<div class="asdl-fin-card-grid">
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Servicios recurrentes</span>
						<strong><?php echo esc_html( number_format_i18n( $recurring_summary['active_count'] ?? 0 ) ); ?></strong>
						<p><?php echo esc_html( sprintf( 'Activos: %1$s | Pausados: %2$s.', number_format_i18n( $recurring_summary['active_count'] ?? 0 ), number_format_i18n( $recurring_summary['paused_count'] ?? 0 ) ) ); ?></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Por emitir hoy</span>
						<strong><?php echo esc_html( number_format_i18n( $recurring_summary['due_count'] ?? 0 ) ); ?></strong>
						<p><?php echo esc_html( sprintf( 'Monto en cola hoy: %s.', wp_strip_all_tags( $this->format_money( $recurring_summary['due_amount_total'] ?? 0 ) ) ) ); ?></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Proximos 30 dias</span>
						<strong><?php echo esc_html( number_format_i18n( $recurring_summary['upcoming_count'] ?? 0 ) ); ?></strong>
						<p><?php echo esc_html( sprintf( 'Monto programado: %s.', wp_strip_all_tags( $this->format_money( $recurring_summary['upcoming_amount_total'] ?? 0 ) ) ) ); ?></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Servicios por pagar</span>
						<strong><?php echo wp_kses_post( $this->format_money( $service_summary['open_balance_total'] ?? 0 ) ); ?></strong>
						<p><?php echo esc_html( sprintf( '%s servicios siguen abiertos o parciales.', number_format_i18n( $service_summary['open_count'] ?? 0 ) ) ); ?></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Servicios emitidos</span>
						<strong><?php echo esc_html( number_format_i18n( $service_summary['service_count'] ?? 0 ) ); ?></strong>
						<p><?php echo esc_html( sprintf( 'Pagado o abonado: %s.', wp_strip_all_tags( $this->format_money( $service_summary['paid_total'] ?? 0 ) ) ) ); ?></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Proveedores base</span>
						<strong><?php echo esc_html( number_format_i18n( $service_summary['linked_contact_count'] ?? 0 ) ); ?></strong>
						<p>Perfiles o terceros ya usados como base para servicios y cuentas por pagar.</p>
					</div>
				</div>
				<?php
				return;

			case 'services-queue':
				$due_service_profiles      = $service_profiles_repo->due_queue( $today, 60 );
				$upcoming_service_profiles = $service_profiles_repo->upcoming_queue( $upcoming_from, $upcoming_to, 60 );
				$readonly_context          = $this->is_fiscal_readonly_context();
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Cola operativa de servicios</h2>
						<p>Trabaja aqui los servicios recurrentes que ya deben emitirse y los proximos cargos programados del modulo.</p>
					</div>
					<div class="asdl-fin-card-grid asdl-fin-card-grid-compact">
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Por emitir hoy</span>
							<strong><?php echo esc_html( number_format_i18n( count( $due_service_profiles ) ) ); ?></strong>
							<p><?php echo esc_html( sprintf( 'Servicios vencidos o listos al %s.', gmdate( 'd/m/Y' ) ) ); ?></p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Proximos 30 dias</span>
							<strong><?php echo esc_html( number_format_i18n( count( $upcoming_service_profiles ) ) ); ?></strong>
							<p>Servicios programados para la siguiente ventana operativa.</p>
						</div>
					</div>
					<section class="asdl-fin-panel asdl-fin-panel-subtle">
						<div class="asdl-fin-panel-header">
							<h3>Proximos cargos</h3>
							<p>Vista previa de los siguientes servicios que entraran a la cola operativa despues del corte actual.</p>
						</div>
						<?php $this->render_service_profiles_table( $upcoming_service_profiles, array( 'mode' => 'upcoming', 'allow_generate' => false, 'empty_title' => 'Sin servicios proximos en la ventana.', 'empty_description' => 'Cuando existan proximas emisiones dentro de 30 dias, se listaran aqui.' ) ); ?>
					</section>
					<section class="asdl-fin-panel asdl-fin-panel-subtle">
						<div class="asdl-fin-panel-header">
							<h3>Listos para emitir</h3>
							<p>Cuando generas un servicio desde aqui, nace el documento `service_expense` y pasa a cuentas por pagar.</p>
						</div>
						<?php $this->render_service_profiles_table( $due_service_profiles, array( 'mode' => 'queue', 'allow_generate' => ! $readonly_context, 'empty_title' => 'Sin servicios por emitir hoy.', 'empty_description' => 'Los servicios recurrentes activos apareceran aqui cuando su proxima fecha llegue o quede vencida.' ) ); ?>
					</section>
				</section>
				<?php
				return;

			case 'services-profiles':
				$readonly_context = $this->is_fiscal_readonly_context();
				$service_profiles  = $service_profiles_repo->all_with_contacts( 120 );
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Servicios recurrentes configurados</h2>
						<p>Base de contratos o cargos repetitivos que luego alimentan la cola operativa del modulo.</p>
					</div>
					<?php $this->render_service_profiles_table( $service_profiles, array( 'mode' => 'profiles', 'allow_generate' => ! $readonly_context, 'allow_toggle' => ! $readonly_context, 'empty_title' => 'Aun no hay servicios recurrentes configurados.', 'empty_description' => 'Usa la forma de arriba para sembrar contratos, suscripciones o servicios que deban emitirse por frecuencia.' ) ); ?>
				</section>
				<?php
				return;

			case 'services-directory':
				$service_contacts = $this->service_directory_contacts( $contacts_repository );
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Base de proveedores y terceros</h2>
						<p>Directorio operativo para partir servicios, cuentas por pagar y luego compras desde la misma entidad base.</p>
					</div>
					<?php $this->render_third_party_directory_table( $service_contacts, array( 'per_page' => 6 ) ); ?>
				</section>
				<?php
				return;

			case 'services-open-documents':
				$open_service_documents = $documents_repository->list_admin(
					array(
						'document_type' => 'service_expense',
						'open_only'     => true,
						'limit'         => 120,
					)
				);
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Servicios por pagar</h2>
						<p>Obligaciones de servicio que siguen abiertas, parciales o pendientes dentro del core.</p>
					</div>
					<?php
					$this->render_documents_table(
						$open_service_documents,
						array(
							'per_page'          => 8,
							'allow_cancel'      => true,
							'detail_page'       => 'asdl-fin-services',
							'empty_title'       => 'Sin servicios por pagar.',
							'empty_description' => 'Cuando existan servicios abiertos o parcialmente pagados, apareceran aqui para gestion operativa.',
						)
					);
					?>
				</section>
				<?php
				return;

			case 'services-documents':
				$service_documents = $documents_repository->list_admin(
					array(
						'document_type' => 'service_expense',
						'limit'         => 120,
					)
				);
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Servicios registrados</h2>
						<p>Historial operativo de servicios emitidos fuera del modulo general de movimientos.</p>
					</div>
					<?php
					$this->render_documents_table(
						$service_documents,
						array(
							'per_page'          => 12,
							'allow_cancel'      => true,
							'detail_page'       => 'asdl-fin-services',
							'empty_title'       => 'Aun no hay servicios registrados.',
							'empty_description' => 'Los servicios puntuales que guardes en este modulo apareceran aqui con su saldo y gestion.',
						)
					);
					?>
				</section>
				<?php
				return;
		}

		throw new \RuntimeException( 'Unknown services runtime section.' );
	}

	private function render_integrations_runtime_section( $section_key ) {
		$snapshot     = ( new IntegrationStatusService() )->get_snapshot();
		$integrations = $snapshot['items'];

		switch ( $section_key ) {
			case 'integrations-summary':
				$pending_snapshot = ( new \ASDLabs\Finance\Integrations\Woo\OrderSyncService() )->pending_orders_snapshot(
					array(
						'limit' => 20,
					)
				);
				?>
				<div class="asdl-fin-card-grid">
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Integraciones detectadas</span>
						<strong><?php echo esc_html( number_format_i18n( $snapshot['detected_count'] ) ); ?></strong>
						<p>Conectores encontrados en el entorno actual.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Vinculos registrados</span>
						<strong><?php echo esc_html( number_format_i18n( $snapshot['total_links'] ) ); ?></strong>
						<p>Relaciones externas guardadas en el core financiero.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Overrides bloqueados</span>
						<strong><?php echo esc_html( number_format_i18n( $snapshot['locked_links'] ) ); ?></strong>
						<p>Casos donde la gestion manual debe prevalecer sobre la sincronizacion.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pedidos pendientes</span>
						<strong><?php echo esc_html( number_format_i18n( $pending_snapshot['summary']['pending_count'] ?? 0 ) ); ?></strong>
						<p>Pedidos Woo/OpenPOS abiertos para gestionar bajo demanda desde Finanzas ASD.</p>
					</div>
				</div>
				<?php
				return;

			case 'integrations-pending-orders':
				$pending_snapshot = ( new \ASDLabs\Finance\Integrations\Woo\OrderSyncService() )->pending_orders_snapshot(
					array(
						'limit' => 20,
					)
				);
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Gestion bajo demanda</h2>
						<p>Usa esta accion cuando un pedido Woo/OpenPOS deba entrar en pagos parciales, estado de cuenta o clasificacion financiera.</p>
					</div>
					<div class="asdl-fin-data-grid">
						<div><strong>Pedidos abiertos</strong><span><?php echo esc_html( number_format_i18n( $pending_snapshot['summary']['pending_count'] ?? 0 ) ); ?></span></div>
						<div><strong>Ya gestionados</strong><span><?php echo esc_html( number_format_i18n( $pending_snapshot['summary']['managed_count'] ?? 0 ) ); ?></span></div>
						<div><strong>Por enlazar</strong><span><?php echo esc_html( number_format_i18n( $pending_snapshot['summary']['unmanaged_count'] ?? 0 ) ); ?></span></div>
					</div>
					<?php $this->render_orders_table( $pending_snapshot['orders'] ?? array(), 'asdl-fin-integrations' ); ?>
				</section>
				<?php
				return;

			case 'integrations-status':
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Estado actual</h2>
						<p>Resumen rapido del criterio de integracion en esta fase del proyecto.</p>
					</div>
					<table class="widefat striped asdl-fin-table">
						<thead>
							<tr>
								<th>Integracion</th>
								<th>Estado</th>
								<th>Descripcion</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $integrations as $integration ) : ?>
								<tr>
									<td><?php echo esc_html( $integration['name'] ); ?></td>
									<td><?php echo wp_kses_post( $this->render_pill( $integration['status'], $this->tone_for_status( $integration['status'] ) ) ); ?></td>
									<td>
										<div class="asdl-fin-stack">
											<span><?php echo esc_html( $integration['description'] ); ?></span>
											<small>Vinculos: <?php echo esc_html( number_format_i18n( $integration['total_links'] ) ); ?> | Ultima sincronizacion: <?php echo esc_html( $integration['last_synced_at'] ?: 'Sin actividad' ); ?></small>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</section>
				<?php
				return;

			case 'integrations-links':
				$recent_links = ( new SourceLinksRepository() )->all( 30 );
				?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Vinculos sincronizados</h2>
						<p>Relacion entre pedidos externos y movimientos del core financiero.</p>
					</div>
					<?php $this->render_source_links_table( $recent_links ); ?>
				</section>
				<?php
				return;
		}

		throw new \RuntimeException( 'Unknown integrations runtime section.' );
	}

	private function render_rules_runtime_section( $section_key ) {
		if ( 'rules-table' !== $section_key ) {
			throw new \RuntimeException( 'Unknown rules runtime section.' );
		}

		$rules = ( new RulesRepository() )->all( 150 );
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Automatizaciones registradas</h2>
				<p>Reglas manuales que actuan solo cuando necesitas refinar el comportamiento del motor automatico.</p>
			</div>
			<?php $this->render_rules_table( $rules ); ?>
		</section>
		<?php
	}

	private function get_dashboard_third_party_directory() {
		$contacts_repository = new ContactsRepository();
		$directory           = array_values(
			array_filter(
				$contacts_repository->list_for_admin( '', 120 ),
				static function ( array $contact ) {
					if ( ! empty( $contact['is_supplier'] ) ) {
						return true;
					}

					if ( 'external' === ( $contact['profile_origin'] ?? '' ) && empty( $contact['is_employee'] ) ) {
						return true;
					}

					return (float) ( $contact['payable_total'] ?? 0 ) > 0;
				}
			)
		);

		return array_slice( $directory, 0, 8 );
	}

	private function build_dashboard_core_cards( array $overview ) {
		return array(
			array(
				'label'       => 'Movimientos',
				'value'       => number_format_i18n( $overview['document_count'] ?? 0 ),
				'description' => sprintf(
					'<span class="asdl-fin-card-breakdown">Con saldo abierto: %1$s</span><span class="asdl-fin-card-breakdown">Anulados: %2$s</span>',
					number_format_i18n( $overview['open_document_count'] ?? 0 ),
					number_format_i18n( $overview['void_document_count'] ?? 0 )
				),
				'url'         => admin_url( 'admin.php?page=asdl-fin-documents' ),
			),
			array(
				'label'       => 'Abiertos',
				'value'       => number_format_i18n( $overview['open_document_count'] ?? 0 ),
				'description' => 'Documentos con saldo pendiente, parcial o vencido.',
				'url'         => admin_url( 'admin.php?page=asdl-fin-documents#asdl-fin-open-balances' ),
			),
			array(
				'label'       => 'Perfiles y terceros',
				'value'       => number_format_i18n( $overview['contact_count'] ?? 0 ),
				'description' => 'Usuarios enlazados, proveedores y terceros externos disponibles para operar.',
				'url'         => admin_url( 'admin.php?page=asdl-fin-contacts' ),
			),
			array(
				'label'       => 'Pagos registrados',
				'value'       => number_format_i18n( $overview['payment_count'] ?? 0 ),
				'description' => sprintf(
					'<span class="asdl-fin-card-breakdown">Activos: %1$s</span><span class="asdl-fin-card-breakdown">Anulados: %2$s</span>',
					number_format_i18n( max( 0, (int) ( $overview['payment_count'] ?? 0 ) - (int) ( $overview['void_payment_count'] ?? 0 ) ) ),
					number_format_i18n( $overview['void_payment_count'] ?? 0 )
				),
				'url'         => admin_url( 'admin.php?page=asdl-fin-payments' ),
			),
			array(
				'label'       => 'Compromisos',
				'value'       => number_format_i18n( $overview['installment_plan_count'] ?? 0 ),
				'description' => sprintf(
					'<span class="asdl-fin-card-breakdown">Activos o en curso: %1$s</span><span class="asdl-fin-card-breakdown">Anulados: %2$s</span>',
					number_format_i18n( max( 0, (int) ( $overview['installment_plan_count'] ?? 0 ) - (int) ( $overview['cancelled_installment_count'] ?? 0 ) ) ),
					number_format_i18n( $overview['cancelled_installment_count'] ?? 0 )
				),
				'url'         => admin_url( 'admin.php?page=asdl-fin-installments' ),
			),
		);
	}

	private function build_dashboard_finance_cards( array $pending_summary, array $pending_payables_summary ) {
		$pending_other_core_total = round(
			(float) ( $pending_summary['invoice_pending_total'] ?? 0 )
			+ (float) ( $pending_summary['loan_pending_total'] ?? 0 )
			+ (float) ( $pending_summary['commitment_pending_total'] ?? 0 ),
			6
		);
		$pending_payables_other_total = round(
			(float) ( $pending_payables_summary['profile_credit_pending_total'] ?? 0 )
			+ (float) ( $pending_payables_summary['loan_pending_total'] ?? 0 )
			+ (float) ( $pending_payables_summary['commitment_pending_total'] ?? 0 ),
			6
		);

		return array(
			array(
				'label'       => 'Por cobrar',
				'value'       => $this->format_money( $pending_summary['pending_total'] ?? 0 ),
				'description' => sprintf(
					'<span class="asdl-fin-card-breakdown">Pedidos: %1$s</span><span class="asdl-fin-card-breakdown">Facturas, prestamos y compromisos: %2$s</span><span class="asdl-fin-card-breakdown">Adelantos por recuperar: %3$s</span>',
					$this->format_money( $pending_summary['order_pending_total'] ?? 0 ),
					$this->format_money( $pending_other_core_total ),
					$this->format_money( $pending_summary['advance_pending_total'] ?? 0 )
				),
				'url'         => admin_url( 'admin.php?page=asdl-finanzas#asdl-fin-dashboard-pending' ),
			),
			array(
				'label'       => 'Por pagar',
				'value'       => $this->format_money( $pending_payables_summary['pending_total'] ?? 0 ),
				'description' => sprintf(
					'<span class="asdl-fin-card-breakdown">Docs / proveedor: %1$s</span><span class="asdl-fin-card-breakdown">Perfiles, prestamos y compromisos: %2$s</span><span class="asdl-fin-card-breakdown">Compras pendientes: %3$s</span>',
					$this->format_money( $pending_payables_summary['invoice_pending_total'] ?? 0 ),
					$this->format_money( $pending_payables_other_total ),
					$this->format_money( $pending_payables_summary['purchase_pending_total'] ?? 0 )
				),
				'url'         => admin_url( 'admin.php?page=asdl-finanzas#asdl-fin-dashboard-payables' ),
			),
			array(
				'label'       => 'Cobranza agrupada',
				'value'       => number_format_i18n( $pending_summary['group_count'] ?? 0 ),
				'description' => sprintf(
					'<span class="asdl-fin-card-breakdown">Pedidos: %1$s</span><span class="asdl-fin-card-breakdown">Otros cobros: %2$s</span>',
					$this->format_money( $pending_summary['order_pending_total'] ?? 0 ),
					$this->format_money( $pending_summary['other_pending_total'] ?? 0 )
				),
				'url'         => admin_url( 'admin.php?page=asdl-finanzas#asdl-fin-dashboard-pending' ),
			),
		);
	}

	private function load_dashboard_finance_cards( array $fiscal_range ) {
		$cache_key = 'asdl_fin_dashboard_finance_cards_v2_' . md5(
			wp_json_encode(
				array(
					'range_from'   => $fiscal_range['range_from'] ?? '',
					'range_to'     => $fiscal_range['range_to'] ?? '',
					'data_version' => ( new HistoricalIndexRebuildService() )->get_data_version(),
					'plugin_ver'   => defined( 'ASDL_FINANCE_VERSION' ) ? ASDL_FINANCE_VERSION : 'dev',
				)
			)
		);
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$pending_queue    = ( new PendingCollectionsService() )->get_snapshot(
			array(
				'limit'        => 1,
				'range_from'   => $fiscal_range['range_from'],
				'range_to'     => $fiscal_range['range_to'],
				'summary_only' => true,
			)
		);
		$pending_payables = ( new PendingPayablesService() )->get_snapshot(
			array(
				'limit'        => 1,
				'range_from'   => $fiscal_range['range_from'],
				'range_to'     => $fiscal_range['range_to'],
				'summary_only' => true,
			)
		);
		$cards            = $this->build_dashboard_finance_cards( $pending_queue['summary'] ?? array(), $pending_payables['summary'] ?? array() );

		set_transient( $cache_key, $cards, 300 );

		return $cards;
	}

	private function build_dashboard_payroll_cards( array $profile_counts, array $payroll_summary ) {
		return array(
			array(
				'label'       => 'Empleados',
				'value'       => number_format_i18n( $profile_counts['total_count'] ?? 0 ),
				'description' => sprintf(
					'Resumen laboral general. Activos: %1$s | Finalizados: %2$s.',
					number_format_i18n( $profile_counts['active_count'] ?? 0 ),
					number_format_i18n( $profile_counts['ended_count'] ?? 0 )
				),
				'url'         => admin_url( 'admin.php?page=asdl-fin-payroll' ),
			),
			array(
				'label'       => 'Nomina por atender',
				'value'       => number_format_i18n( $payroll_summary['employee_count'] ?? 0 ),
				'description' => 'Empleados con pago previsto dentro del rango rapido del dashboard.',
				'url'         => admin_url( 'admin.php?page=asdl-fin-payroll' ),
			),
		);
	}

	public function render_dashboard() {
		$fiscal_context = $this->current_fiscal_context();
		$fiscal_range   = array(
			'range_from' => $fiscal_context['start_date'] ?? '',
			'range_to'   => $fiscal_context['end_date'] ?? '',
		);
		?>
		<div class="wrap asdl-fin-wrap">
			<div class="asdl-fin-hero">
				<div>
					<h1>Finanzas ASD</h1>
					<p>Centro financiero modular de ASD Labs para operar movimientos, saldos, cobros, pagos y sincronizaciones desde una sola base.</p>
				</div>
				<div class="asdl-fin-badge-group">
					<span class="asdl-fin-badge">MVP operativo</span>
					<span class="asdl-fin-badge asdl-fin-badge-soft">Perfiles y terceros, pagos, adelantos y nomina</span>
				</div>
			</div>
			<?php $this->render_fiscal_context_toolbar(); ?>
			<?php $this->render_fiscal_readonly_notice(); ?>

			<div class="asdl-fin-stack asdl-fin-dashboard-summary-stack">
				<div
					class="asdl-fin-runtime-container"
					data-runtime-action="asdl_fin_admin_runtime"
					data-runtime-nonce="adminRuntime"
					data-runtime-priority="high"
					data-runtime-group="dashboard-summary"
					data-runtime-title="No se pudo cargar el resumen operativo."
					data-runtime-param-page-key="dashboard"
					data-runtime-param-section-key="summary-core"
					data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
					data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
				>
					<?php $this->render_runtime_card_skeleton( 5 ); ?>
				</div>
				<div
					class="asdl-fin-runtime-container"
					data-runtime-action="asdl_fin_admin_runtime"
					data-runtime-nonce="adminRuntime"
					data-runtime-priority="high"
					data-runtime-group="dashboard-summary"
					data-runtime-title="No se pudo cargar el resumen financiero."
					data-runtime-param-page-key="dashboard"
					data-runtime-param-section-key="summary-finance"
					data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
					data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
				>
					<?php $this->render_runtime_card_skeleton( 3 ); ?>
				</div>
				<div
					class="asdl-fin-runtime-container"
					data-runtime-action="asdl_fin_admin_runtime"
					data-runtime-nonce="adminRuntime"
					data-runtime-priority="high"
					data-runtime-group="dashboard-summary"
					data-runtime-title="No se pudo cargar el resumen de nomina."
					data-runtime-param-page-key="dashboard"
					data-runtime-param-section-key="summary-payroll"
					data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
					data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
				>
					<?php $this->render_runtime_card_skeleton( 2 ); ?>
				</div>
			</div>

			<div class="asdl-fin-dashboard-utility-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Accesos rapidos</h2>
						<p>Entradas principales del MVP que vamos a desarrollar por fases.</p>
					</div>
					<div class="asdl-fin-action-grid">
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-documents' ), 'Movimientos', 'Registrar y consultar ventas, gastos y ajustes.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-contacts' ), 'Perfiles y terceros', 'Usuarios enlazados, proveedores y terceros externos en una sola vista.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-services' ), 'Servicios', 'Base para gestionar servicios puntuales o recurrentes sobre terceros y proveedores.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-purchases' ), 'Compras', 'Base del futuro gestor propio de ordenes de compra.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-incoming' ), 'Mercancia por recibir', 'Panel previsto para productos y recepciones pendientes.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-payments' ), 'Cobros y pagos', 'Abonos, pagos completos y asignaciones.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-installments' ), 'Compromisos', 'Prestamos, acuerdos y descuentos programados por perfil.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-payroll' ), 'Nomina', 'Vista general para generar y procesar pagos de empleados.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-integrations' ), 'Integraciones', 'Pedidos enlazados, vinculos externos y gestion bajo demanda.' ) ); ?>
						<?php echo wp_kses_post( $this->render_action_link( admin_url( 'admin.php?page=asdl-fin-reports' ), 'Reportes', 'Reporte maestro, exportes consolidados y cierres mensuales versionados.' ) ); ?>
					</div>
				</section>

				<div
					class="asdl-fin-runtime-container"
					data-runtime-action="asdl_fin_admin_runtime"
					data-runtime-nonce="adminRuntime"
					data-runtime-priority="normal"
					data-runtime-group="dashboard-utility"
					data-runtime-title="No se pudo cargar el estado del core."
					data-runtime-param-page-key="dashboard"
					data-runtime-param-section-key="core"
					data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
					data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
				>
					<?php $this->render_runtime_panel_skeleton( 'Estado del core', 'Cargando estado tecnico e integraciones...' ); ?>
				</div>
			</div>

			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="high"
				data-runtime-group="dashboard-receivables"
				data-runtime-title="No se pudo cargar pendientes por cobrar."
				data-runtime-param-page-key="dashboard"
				data-runtime-param-section-key="pending-receivables-summary"
				data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
				data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
			>
				<?php $this->render_runtime_panel_skeleton( 'Pendientes por cobrar', 'Cargando la cola global de cobranza...' ); ?>
			</div>

			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="high"
				data-runtime-group="dashboard-receivables"
				data-runtime-title="No se pudo cargar la tabla de pendientes por cobrar."
				data-runtime-param-page-key="dashboard"
				data-runtime-param-section-key="pending-receivables-table"
				data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
				data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
			>
				<?php $this->render_runtime_table_skeleton( 'Tabla de cobranza agrupada', 'Cargando grupos por cobrar del periodo operativo...', 6, 6 ); ?>
			</div>

			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="high"
				data-runtime-group="dashboard-payables"
				data-runtime-title="No se pudo cargar pendientes por pagar."
				data-runtime-param-page-key="dashboard"
				data-runtime-param-section-key="pending-payables-summary"
				data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
				data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
			>
				<?php $this->render_runtime_panel_skeleton( 'Pendientes por pagar', 'Cargando la cola global de obligaciones por pagar...' ); ?>
			</div>

			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="high"
				data-runtime-group="dashboard-payables"
				data-runtime-title="No se pudo cargar la tabla de pendientes por pagar."
				data-runtime-param-page-key="dashboard"
				data-runtime-param-section-key="pending-payables-table"
				data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
				data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
			>
				<?php $this->render_runtime_table_skeleton( 'Tabla de obligaciones agrupadas', 'Cargando grupos por pagar del periodo operativo...', 6, 6 ); ?>
			</div>

			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="normal"
				data-runtime-group="dashboard-secondary"
				data-runtime-title="No se pudo cargar el directorio corto de terceros."
				data-runtime-param-page-key="dashboard"
				data-runtime-param-section-key="third-parties"
				data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
				data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
			>
				<?php $this->render_runtime_panel_skeleton( 'Proveedores y terceros base', 'Cargando el directorio operativo corto...' ); ?>
			</div>

			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="normal"
				data-runtime-group="dashboard-secondary"
				data-runtime-title="No se pudo cargar la actividad reciente."
				data-runtime-param-page-key="dashboard"
				data-runtime-param-section-key="recent-activity"
				data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
				data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
			>
				<?php $this->render_runtime_panel_skeleton( 'Actividad reciente', 'Cargando movimientos, pagos y bitacora reciente...' ); ?>
			</div>

			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="low"
				data-runtime-group="dashboard-secondary"
				data-runtime-title="No se pudo cargar la cola rapida de nomina."
				data-runtime-param-page-key="dashboard"
				data-runtime-param-section-key="payroll-queue"
				data-runtime-param-range-from="<?php echo esc_attr( $fiscal_range['range_from'] ); ?>"
				data-runtime-param-range-to="<?php echo esc_attr( $fiscal_range['range_to'] ); ?>"
			>
				<?php $this->render_runtime_panel_skeleton( 'Nomina proxima', 'Cargando la cola rapida de nomina...' ); ?>
			</div>
			<?php $this->render_order_selection_modal(); ?>
		</div>
		<?php
	}

	public function render_reports() {
		$request_args = $this->current_report_request_args();
		$payload      = array();
		$versions     = array();
		$report_error = null;
		$server_snapshot = ! empty( $request_args['snapshot_id'] );

		if ( $server_snapshot ) {
			try {
				$service   = new FinancialMasterReportService();
				$payload   = $service->get_payload( $request_args );
				$month_key = sanitize_text_field( (string) ( $payload['meta']['month_key'] ?? $request_args['month_key'] ) );

				if ( 'monthly_close' === sanitize_key( (string) ( $payload['meta']['mode'] ?? $request_args['mode'] ) ) && '' !== $month_key ) {
					$versions = ( new MonthlyCloseSnapshotService() )->list_versions( $month_key );
				}
			} catch ( \Throwable $throwable ) {
				$report_error = $throwable->getMessage();
			}
		}

		$this->render_page_open(
			'Reporte Maestro Financiero',
			'Consulta consolidada para rango libre, total del ejercicio y cierre mensual versionado.'
		);
		if ( $report_error ) {
			$this->render_empty_state( 'No se pudo calcular el reporte.', $report_error );
			$this->render_page_close();
			return;
		}
		$this->render_master_report_page( $request_args, $payload, $versions, false );
		$this->render_page_close();
	}

	public function render_report_print_page() {
		$request_args = $this->current_report_request_args();
		$this->render_page_open(
			'Reporte Maestro Imprimible',
			'Vista lista para imprimir o guardar como PDF desde el navegador.'
		);

		if ( empty( $request_args['run'] ) && empty( $request_args['snapshot_id'] ) ) {
			$this->render_empty_state( 'Todavia no hay un reporte cargado para imprimir.', 'Primero carga el reporte maestro y luego abre la vista imprimible desde esa misma pantalla.' );
			$this->render_page_close();
			return;
		}

		try {
			$service = new FinancialMasterReportService();
			$payload = $service->get_payload( $request_args );
		} catch ( \Throwable $throwable ) {
			$this->render_empty_state( 'No se pudo preparar la vista imprimible.', $throwable->getMessage() );
			$this->render_page_close();
			return;
		}
		?>
		<section class="asdl-fin-panel asdl-fin-report-print-page">
			<div class="asdl-fin-panel-header asdl-fin-report-print-toolbar">
				<div>
					<h2>Reporte Maestro Financiero</h2>
					<p><?php echo esc_html( $this->master_report_range_label( $payload ) ); ?></p>
				</div>
				<div class="asdl-fin-inline-actions">
					<a class="button button-secondary" href="<?php echo esc_url( $this->report_page_url( $this->report_args_from_payload( $request_args, $payload ) ) ); ?>">Volver al reporte</a>
					<button type="button" class="button button-primary asdl-fin-print-trigger">Imprimir / Guardar PDF</button>
				</div>
			</div>
			<?php $this->render_master_report_sections( $payload, true ); ?>
		</section>
		<?php
		$this->render_page_close();
	}

	public function render_product_margins_page() {
		$service                  = new ProductMarginCheckService();
		$scope                    = $service->catalog_scope();
		$result                   = $service->get_workspace_result_for_scope( $scope );
		$product_category_options = $this->product_margin_category_options();
		$result['daily_certification'] = $service->get_daily_result_for_scope( $scope );

		$this->render_page_open(
			'Productos y precios',
			'Panel rapido para revisar costo, precio, inventario y margen del catalogo antes del reporte o del cierre mensual.'
		);
		?>
		<section class="asdl-fin-panel asdl-fin-product-margin-page" data-product-margin-root="1">
			<div class="asdl-fin-panel-header">
				<h2>Productos y precios</h2>
				<p>Usa este panel para revisar productos, detectar margenes criticos y ajustar precios o costos con una referencia rapida del precio real.</p>
			</div>
			<div class="asdl-fin-form-grid asdl-fin-product-margin-filter-grid">
				<?php $this->render_input( 'product_margin_exclude_categories', 'Excluir categorias', 'text', '', false, array( 'placeholder' => 'slug-o-id, slug-2' ) ); ?>
				<?php $this->render_input( 'product_margin_search', 'Buscar producto o SKU', 'search', '', false, array( 'placeholder' => 'Nombre o SKU' ) ); ?>
				<?php $this->render_input( 'product_margin_category', 'Filtrar categoria', 'search', '', false, array( 'placeholder' => 'Categoria' ) ); ?>
				<div class="asdl-fin-field">
					<span>Ver estado</span>
					<select name="product_margin_status_filter">
						<option value="all">Todos</option>
						<option value="critical">Criticos</option>
						<option value="review">Revisar</option>
						<option value="manual">Manual</option>
						<option value="discarded">Descartados en esta vista</option>
						<option value="ok">OK</option>
					</select>
				</div>
				<div class="asdl-fin-field">
					<span>Estrategia</span>
					<select name="product_margin_mode_filter">
						<option value="all">Todas</option>
						<option value="formula">Segun regla</option>
						<option value="manual">Manual</option>
					</select>
				</div>
				<div class="asdl-fin-field asdl-fin-field-wide">
					<span>Accion</span>
					<div class="asdl-fin-inline-actions">
						<button type="button" class="button button-primary" data-product-margin-start="1">Actualizar vista</button>
						<button type="button" class="button button-secondary" data-product-margin-discard-no-stock="1">Descartar visibles sin inventario</button>
					</div>
					<small>La vista rapida dura 2 horas. La revision del dia es la que usa Reportes y el cierre mensual.</small>
				</div>
			</div>
			<div class="asdl-fin-tool-progress asdl-fin-product-margin-progress" data-product-margin-progress>
				<?php
				$daily = (array) ( $result['daily_certification'] ?? array() );
				if ( ! empty( $result['cache_valid'] ) ) {
					echo wp_kses_post( $this->render_notice_box( 'success', 'La vista rapida sigue vigente.', 'Puedes seguir corrigiendo filas sin recalcular todo. La revision del dia ' . ( ! empty( $daily['cache_valid'] ) ? 'ya esta lista para hoy.' : 'todavia no se ha certificado hoy.' ) ) );
				} elseif ( ! empty( $daily['cache_valid'] ) ) {
					echo wp_kses_post( $this->render_notice_box( 'warning', 'La revision del dia sigue valida, pero la vista rapida ya vencio.', 'Puedes recalcular la vista para trabajar con una lista fresca sin perder la revision que ya usa el reporte.' ) );
				} else {
					echo wp_kses_post( $this->render_notice_box( 'neutral', 'Todavia no hay una vista rapida ni una revision del dia vigentes.', 'Pulsa Actualizar vista para recorrer el catalogo por lotes y dejar lista la revision del dia.' ) );
				}
				?>
			</div>
			<div data-product-margin-results>
				<?php echo $this->capture_product_margin_result_html( $result ); ?>
			</div>
			<div class="asdl-fin-modal asdl-fin-product-margin-modal" data-modal="product-margin-editor" hidden>
				<div class="asdl-fin-modal-overlay" data-modal-close></div>
				<div class="asdl-fin-modal-dialog">
					<div class="asdl-fin-modal-header">
						<div>
							<h2>Gestionar precio del producto</h2>
							<p data-product-margin-modal-subtitle>Abre una fila para revisar costo, precio publicado, inventario y referencia de precio real.</p>
						</div>
						<button type="button" class="button button-secondary asdl-fin-modal-close-button" data-modal-close>Cerrar</button>
					</div>
					<div class="asdl-fin-product-margin-modal-body">
						<div data-product-margin-modal-notice></div>
						<form class="asdl-fin-form-grid asdl-fin-product-margin-editor-form" data-product-margin-editor-form="1">
							<input type="hidden" name="product_id" value="" />
							<input type="hidden" name="row_signature" value="" />
							<input type="hidden" name="cost_target_product_id" value="" />
							<input type="hidden" name="category_target_product_id" value="" />
							<input type="hidden" name="cost_meta_key" value="" />
							<input type="hidden" name="scope_kind" value="catalog" />
							<input type="hidden" name="exclude_categories_raw" value="" />
							<div class="asdl-fin-field asdl-fin-field-wide">
								<span>Producto</span>
								<div class="asdl-fin-product-margin-modal-product" data-product-margin-modal-product>Selecciona una fila del catalogo.</div>
							</div>
							<div class="asdl-fin-field asdl-fin-field-wide">
								<span>Inventario</span>
								<div class="asdl-fin-product-margin-modal-product asdl-fin-product-margin-modal-stock" data-product-margin-modal-stock>La vista mostrara aqui el inventario actual y la ultima fecha con existencia.</div>
								<small>Solo lectura. El inventario se visualiza aqui, pero no se modifica desde este panel.</small>
							</div>
							<div class="asdl-fin-field">
								<span>Costo actual</span>
								<input type="number" name="cost" min="0" step="0.01" value="0" />
							</div>
							<div class="asdl-fin-field">
								<span>Precio publicado</span>
								<input type="number" name="regular_price" min="0" step="0.01" value="0" />
							</div>
							<div class="asdl-fin-field">
								<span>% objetivo</span>
								<input type="number" name="target_percent" min="0" max="95" step="0.01" value="0" />
							</div>
							<div class="asdl-fin-field">
								<span>Modo de estrategia</span>
								<select name="strategy_mode">
									<option value="formula">Segun regla</option>
									<option value="manual">Manual</option>
								</select>
							</div>
							<label class="asdl-fin-field asdl-fin-field-wide">
								<span>Categorias del producto</span>
								<div class="asdl-fin-product-margin-modal-tags asdl-fin-product-margin-category-badges" data-product-margin-category-badges></div>
								<select name="category_ids" class="asdl-fin-product-margin-category-select" multiple size="8">
									<?php foreach ( $product_category_options as $category_id => $category_name ) : ?>
										<option value="<?php echo esc_attr( (string) $category_id ); ?>"><?php echo esc_html( $category_name ); ?></option>
									<?php endforeach; ?>
								</select>
								<small>Puedes seleccionar varias categorias. Se guardan en el producto real o en el padre si es una variacion.</small>
							</label>
							<div class="asdl-fin-field asdl-fin-field-wide">
								<label class="asdl-fin-inline-checkbox">
									<input type="checkbox" name="inherit_target" value="1" />
									<span>Usar la referencia global vigente para este producto</span>
								</label>
								<small data-product-margin-modal-global-help>Cuando esta activo, el producto hereda el porcentaje global actual.</small>
							</div>
							<div class="asdl-fin-field asdl-fin-field-wide">
								<span>Deslizador rapido del % objetivo</span>
								<input type="range" name="target_percent_slider" min="0" max="95" step="0.01" value="0" />
							</div>
							<div class="asdl-fin-card-grid asdl-fin-card-grid-compact asdl-fin-product-margin-preview-grid">
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Precio real estimado</span>
									<strong data-product-margin-preview-real>—</strong>
									<p>Referencia util para revisar el pricing.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Diferencia entre precios</span>
									<strong data-product-margin-preview-gap>—</strong>
									<p>Cuanto se separa el publicado del real estimado.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Margen sobre precio real</span>
									<strong data-product-margin-preview-margin>—</strong>
									<p>Referencia operativa del margen real.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Estado resultante</span>
									<strong data-product-margin-preview-status><?php echo wp_kses_post( $this->render_pill( 'Pendiente', 'neutral' ) ); ?></strong>
									<p data-product-margin-preview-help>La clasificacion se actualiza mientras editas.</p>
								</div>
							</div>
							<div class="asdl-fin-inline-actions asdl-fin-field-wide">
								<button type="submit" class="button button-primary" data-product-margin-save="1">Guardar cambios</button>
								<button type="button" class="button button-secondary" data-modal-close>Cancelar</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		</section>
		<?php
		$this->render_page_close();
	}

	private function capture_master_report_actions_html( array $request_args, array $payload ) {
		ob_start();
		$this->render_master_report_actions( $request_args, $payload );
		return (string) ob_get_clean();
	}

	private function capture_master_report_versions_html( array $versions, array $request_args, array $payload ) {
		ob_start();
		$this->render_master_report_versions( $versions, $request_args, $payload );
		return (string) ob_get_clean();
	}

	private function capture_master_report_sections_html( array $payload, $print = false ) {
		ob_start();
		$this->render_master_report_sections( $payload, $print );
		return (string) ob_get_clean();
	}

	private function capture_product_margin_result_html( array $result ) {
		ob_start();
		$this->render_product_margin_result( $result );
		return (string) ob_get_clean();
	}

	private function capture_product_margin_row_html( array $row ) {
		ob_start();
		$this->render_product_margin_row( $row );
		return (string) ob_get_clean();
	}

	private function render_notice_box( $tone, $title, $description = '' ) {
		$tone = sanitize_key( (string) $tone );
		$title = sanitize_text_field( (string) $title );
		$description = sanitize_text_field( (string) $description );

		return sprintf(
			'<div class="asdl-fin-note-box asdl-fin-note-box-%1$s"><strong>%2$s</strong>%3$s</div>',
			esc_attr( $tone ?: 'neutral' ),
			esc_html( $title ),
			'' !== $description ? '<p>' . esc_html( $description ) . '</p>' : ''
		);
	}

	private function render_product_margin_result( array $result ) {
		$status         = sanitize_key( (string) ( $result['status'] ?? 'unknown' ) );
		$cache_valid    = ! empty( $result['cache_valid'] );
		$rows           = array_values( (array) ( $result['rows'] ?? array() ) );
		$scope_label    = sanitize_text_field( (string) ( $result['scope_label'] ?? 'Catalogo de productos' ) );
		$checked_at     = sanitize_text_field( (string) ( $result['checked_at'] ?? '' ) );
		$issue_count    = (int) ( $result['issue_count'] ?? 0 );
		$critical_count = (int) ( $result['critical_count'] ?? 0 );
		$review_count   = (int) ( $result['review_count'] ?? 0 );
		$manual_count   = (int) ( $result['manual_count'] ?? 0 );
		$ok_count       = (int) ( $result['ok_count'] ?? 0 );
		$discarded_count = (int) ( $result['discarded_count'] ?? 0 );
		$visible_critical_count = (int) ( $result['visible_critical_count'] ?? $critical_count );
		$visible_review_count   = (int) ( $result['visible_review_count'] ?? $review_count );
		$visible_manual_count   = (int) ( $result['visible_manual_count'] ?? $manual_count );
		$visible_ok_count       = (int) ( $result['visible_ok_count'] ?? $ok_count );
		$daily          = (array) ( $result['daily_certification'] ?? array() );
		$issue_rows     = array();
		$verified_rows  = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_issue'] ) ) {
				$issue_rows[] = $row;
				continue;
			}
			$verified_rows[] = $row;
		}

		$tone = 'issues' === $status
			? 'danger'
			: ( 'review' === $status ? 'warning' : ( $cache_valid ? 'success' : 'neutral' ) );
		$title = $cache_valid
			? ( $critical_count > 0
				? sprintf( 'Se detectaron %d producto(s) criticos.', $critical_count )
				: ( $review_count > 0
					? sprintf( 'La vista quedo con %d producto(s) para revisar.', $review_count )
					: 'La vista rapida esta lista.' ) )
			: 'Todavia no hay una vista rapida para mostrar.';
		$description = $cache_valid
			? sprintf( 'Ultima revision: %s · Alcance: %s.', $checked_at ?: 'sin fecha', $scope_label )
			: 'Lanza la revision para construir una vista rapida del catalogo y dejar lista la revision del dia.';
		?>
		<div class="asdl-fin-product-margin-results-shell" data-product-margin-workspace data-product-margin-snapshot-valid="<?php echo $cache_valid ? '1' : '0'; ?>">
			<?php echo wp_kses_post( $this->render_notice_box( $tone, $title, $description ) ); ?>
			<div class="asdl-fin-card-grid asdl-fin-product-margin-card-grid">
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Vista rapida</span>
					<strong data-product-margin-summary="snapshot"><?php echo wp_kses_post( $this->render_pill( $cache_valid ? 'Activo' : 'Pendiente', $cache_valid ? 'success' : 'neutral' ) ); ?></strong>
					<p><?php echo esc_html( $cache_valid ? 'Se reutiliza durante 2 horas para trabajar rapido.' : 'Todavia no hay una vista rapida del catalogo.' ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Revision del dia</span>
					<strong data-product-margin-summary="daily"><?php echo wp_kses_post( $this->render_pill( ! empty( $daily['cache_valid'] ) ? ( ! empty( $daily['blocking_for_monthly_close'] ) ? 'Con bloqueo' : 'OK hoy' ) : 'Pendiente', ! empty( $daily['cache_valid'] ) ? ( ! empty( $daily['blocking_for_monthly_close'] ) ? 'danger' : 'success' ) : 'neutral' ) ); ?></strong>
					<p><?php echo esc_html( ! empty( $daily['cache_valid'] ) ? 'El reporte puede reutilizar esta revision hoy.' : 'Todavia no hay una revision valida para el reporte de hoy.' ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Criticos / revisar</span>
					<strong data-product-margin-summary="issues"><?php echo esc_html( number_format_i18n( $visible_critical_count ) . ' / ' . number_format_i18n( $visible_review_count ) ); ?></strong>
					<p><?php echo esc_html( 'Los criticos visibles bloquean el cierre oficial. Revisar solo avisa que conviene mirarlo.' ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Productos en orden</span>
					<strong data-product-margin-summary="verified"><?php echo esc_html( number_format_i18n( $visible_ok_count + $visible_manual_count ) ); ?></strong>
					<p><?php echo esc_html( 'Productos sanos, manuales o descartados solo en esta vista.' ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Ultima revision</span>
					<strong><?php echo esc_html( '' !== $checked_at ? $checked_at : 'Pendiente' ); ?></strong>
					<p><?php echo esc_html( 'Si esta revision ya salio hoy, el reporte puede saltarse esta etapa.' ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Alcance</span>
					<strong><?php echo esc_html( $scope_label ); ?></strong>
					<p><?php echo esc_html( 'Las mismas reglas se usan desde Reportes y desde este panel.' ); ?></p>
				</div>
			</div>
			<?php if ( ! empty( $result['truncated'] ) ) : ?>
				<?php echo wp_kses_post( $this->render_notice_box( 'warning', 'La lista se redujo para que la vista siga rapida.', 'Corrige primero estos productos y luego vuelve a actualizar la vista si necesitas revisar el resto del catalogo.' ) ); ?>
			<?php endif; ?>
			<?php if ( $discarded_count > 0 ) : ?>
				<?php echo wp_kses_post( $this->render_notice_box( 'neutral', sprintf( 'Hay %d producto(s) descartados solo en esta vista.', $discarded_count ), 'Esos productos salen de Hallazgos hasta que vuelvas a actualizar la vista.' ) ); ?>
			<?php endif; ?>
			<div class="asdl-fin-inline-tabs asdl-fin-product-margin-tabs" data-product-margin-tabs="1">
				<div class="asdl-fin-inline-actions asdl-fin-product-margin-bulk-actions">
					<button type="button" class="button button-secondary" data-product-margin-discard-no-stock="1" <?php echo empty( $issue_rows ) ? 'disabled' : ''; ?>>Descartar visibles sin inventario</button>
				</div>
				<div class="asdl-fin-inline-tab-list">
					<button type="button" class="button is-active" data-product-margin-tab="issues">Hallazgos (<?php echo esc_html( number_format_i18n( count( $issue_rows ) ) ); ?>)</button>
					<button type="button" class="button" data-product-margin-tab="verified">Productos en orden (<?php echo esc_html( number_format_i18n( count( $verified_rows ) ) ); ?>)</button>
				</div>
				<?php $this->render_product_margin_table_panel( 'issues', 'Hallazgos', 'Criticos y filas que todavia merecen revision operativa.', $issue_rows, true ); ?>
				<?php $this->render_product_margin_table_panel( 'verified', 'Productos en orden', 'Productos sanos, manuales o descartados solo en esta vista.', $verified_rows, false ); ?>
			</div>
		</div>
		<?php
	}

	private function render_product_margin_table_panel( $tab_key, $title, $description, array $rows, $active = false ) {
		$tab_key = sanitize_key( (string) $tab_key );
		?>
		<div class="asdl-fin-inline-tab-panel asdl-fin-product-margin-panel" data-product-margin-panel="<?php echo esc_attr( $tab_key ); ?>" <?php echo $active ? '' : 'hidden'; ?>>
			<div class="asdl-fin-panel-header">
				<h3><?php echo esc_html( $title ); ?></h3>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="asdl-fin-product-margin-empty" data-product-margin-tab-empty="<?php echo esc_attr( $tab_key ); ?>" <?php echo empty( $rows ) ? '' : 'hidden'; ?>>
				<strong><?php echo esc_html( 'issues' === $tab_key ? 'No hay hallazgos visibles con estos filtros.' : 'No hay productos visibles en esta pestaña con los filtros actuales.' ); ?></strong>
				<p><?php echo esc_html( 'Ajusta la busqueda o actualiza la vista para traer una lista nueva del catalogo.' ); ?></p>
			</div>
			<div class="asdl-fin-table-wrap" data-product-margin-table-wrap="<?php echo esc_attr( $tab_key ); ?>" <?php echo empty( $rows ) ? 'hidden' : ''; ?>>
				<table class="widefat striped asdl-fin-table asdl-fin-table-compact asdl-fin-product-margin-table">
					<thead>
						<tr>
							<th>Producto</th>
							<th>Categorias</th>
							<th>Costo</th>
							<th>% objetivo</th>
							<th>Estado de precios</th>
							<th>Modo</th>
							<th>Estado</th>
							<th>Acciones</th>
						</tr>
					</thead>
					<tbody data-product-margin-tbody="<?php echo esc_attr( $tab_key ); ?>">
						<?php foreach ( $rows as $row ) : ?>
							<?php $this->render_product_margin_row( $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private function render_product_margin_row( array $row ) {
		$product_name = sanitize_text_field( (string) ( $row['product_name'] ?? 'Producto' ) );
		$sku          = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
		$category     = sanitize_text_field( (string) ( $row['category_label'] ?? '' ) );
		$product_id   = (int) ( $row['product_id'] ?? 0 );
		$internal_id  = (int) ( $row['product_internal_id'] ?? $product_id );
		$search_blob  = strtolower( trim( $product_name . ' ' . $sku . ' ' . $category . ' ' . (string) $internal_id ) );
		$status       = sanitize_key( (string) ( $row['status'] ?? 'ok' ) );
		$mode         = sanitize_key( (string) ( $row['strategy_mode'] ?? 'formula' ) );
		$row_json     = rawurlencode( wp_json_encode( $row ) );
		$inventory_tone = sanitize_key( (string) ( $row['inventory_tone'] ?? 'neutral' ) );
		$inventory_label = sanitize_text_field( (string) ( $row['inventory_label'] ?? 'Sin control' ) );
		$last_inventory_label = sanitize_text_field( (string) ( $row['last_inventory_label'] ?? 'Sin historial' ) );
		$is_discarded = ! empty( $row['snapshot_discarded'] );
		?>
		<tr
			data-product-margin-row="1"
			data-product-margin-id="<?php echo esc_attr( $product_id ); ?>"
			data-margin-status="<?php echo esc_attr( $status ); ?>"
			data-margin-mode="<?php echo esc_attr( $mode ); ?>"
			data-margin-category="<?php echo esc_attr( strtolower( $category ) ); ?>"
			data-margin-search="<?php echo esc_attr( $search_blob ); ?>"
			data-row-json="<?php echo esc_attr( $row_json ); ?>"
		>
			<td>
				<div class="asdl-fin-stack">
					<strong><?php echo esc_html( $product_name ); ?></strong>
					<small><?php echo esc_html( 'ID interno: ' . number_format_i18n( $internal_id ) ); ?></small>
					<small><?php echo esc_html( 'SKU: ' . ( '' !== $sku ? $sku : 'Sin SKU' ) ); ?></small>
					<small><span class="asdl-fin-product-margin-stock-pill asdl-fin-product-margin-stock-pill-<?php echo esc_attr( $inventory_tone ); ?>"><?php echo esc_html( $inventory_label ); ?></span></small>
					<small><?php echo esc_html( $last_inventory_label ); ?></small>
				</div>
			</td>
			<td>
				<button type="button" class="button-link asdl-fin-product-margin-cell-button" data-product-margin-open-editor="1" data-product-margin-field="categories"><?php echo '' !== $category ? esc_html( $category ) : 'Sin categoria'; ?></button>
			</td>
			<td class="asdl-fin-product-margin-money">
				<button type="button" class="button-link asdl-fin-product-margin-cell-button" data-product-margin-open-editor="1" data-product-margin-field="cost"><?php echo wp_kses_post( $this->format_money( (float) ( $row['cost'] ?? 0 ) ) ); ?></button>
				<small><?php echo esc_html( sanitize_text_field( (string) ( $row['cost_source_label'] ?? 'Costo actual' ) ) ); ?></small>
			</td>
			<td>
				<button type="button" class="button-link asdl-fin-product-margin-cell-button" data-product-margin-open-editor="1" data-product-margin-field="target_percent"><?php echo esc_html( number_format_i18n( (float) ( $row['target_percent'] ?? 0 ), 2 ) . '%' ); ?></button>
				<small><?php echo esc_html( ! empty( $row['target_inherited'] ) ? 'Usa la regla general' : sanitize_text_field( (string) ( $row['target_source_label'] ?? 'Ajuste propio' ) ) ); ?></small>
			</td>
			<td class="asdl-fin-product-margin-status-prices">
				<div class="asdl-fin-product-margin-status-lines">
					<div class="asdl-fin-product-margin-status-line">
						<span>Precio publicado</span>
						<button type="button" class="button-link asdl-fin-product-margin-cell-button" data-product-margin-open-editor="1" data-product-margin-field="regular_price"><?php echo wp_kses_post( $this->format_money( (float) ( $row['regular_price'] ?? 0 ) ) ); ?></button>
					</div>
					<div class="asdl-fin-product-margin-status-line">
						<span>Precio estimado real</span>
						<button type="button" class="button-link asdl-fin-product-margin-cell-button" data-product-margin-open-editor="1" data-product-margin-field="estimated_real_price"><?php echo wp_kses_post( $this->format_money( (float) ( $row['estimated_real_price'] ?? 0 ) ) ); ?></button>
					</div>
					<div class="asdl-fin-product-margin-status-line asdl-fin-product-margin-status-line-metric">
						<span>Diferencia</span>
						<strong class="asdl-fin-product-margin-status-value asdl-fin-product-margin-status-value-gap"><?php echo esc_html( number_format_i18n( (float) ( $row['price_gap_percent'] ?? 0 ), 2 ) . '%' ); ?></strong>
					</div>
					<div class="asdl-fin-product-margin-status-line asdl-fin-product-margin-status-line-metric">
						<span>Margen real</span>
						<strong class="asdl-fin-product-margin-status-value asdl-fin-product-margin-status-value-margin"><?php echo esc_html( number_format_i18n( (float) ( $row['real_margin_pct'] ?? 0 ), 2 ) . '%' ); ?></strong>
					</div>
				</div>
			</td>
			<td><?php echo wp_kses_post( $this->render_pill( sanitize_text_field( 'manual' === $mode ? 'Manual' : 'Segun regla' ), 'manual' === $mode ? 'info' : 'neutral' ) ); ?></td>
			<td data-product-margin-status><?php echo wp_kses_post( $this->render_pill( sanitize_text_field( (string) ( $row['status_label'] ?? 'OK' ) ), $this->product_margin_tone( $status ) ) ); ?><?php if ( ! empty( $row['status_help'] ) ) : ?><small><?php echo esc_html( sanitize_text_field( (string) $row['status_help'] ) ); ?></small><?php endif; ?></td>
			<td>
				<div class="asdl-fin-inline-actions asdl-fin-product-margin-actions">
					<button type="button" class="button button-secondary button-small" data-product-margin-open-editor="1">Editar</button>
					<?php if ( ! empty( $row['edit_url'] ) ) : ?>
						<a class="button button-secondary button-small" href="<?php echo esc_url( (string) $row['edit_url'] ); ?>" target="_blank" rel="noopener noreferrer">Abrir producto</a>
					<?php endif; ?>
					<button type="button" class="button button-secondary button-small" data-product-margin-discard-row="1"><?php echo esc_html( $is_discarded ? 'Reincluir' : 'Descartar en esta vista' ); ?></button>
				</div>
			</td>
		</tr>
		<?php
	}

	private function product_margin_tab_key( array $row ) {
		return ! empty( $row['is_issue'] ) ? 'issues' : 'verified';
	}

	private function product_margin_tone( $status ) {
		$status = sanitize_key( (string) $status );

		if ( 'critical' === $status ) {
			return 'danger';
		}

		if ( 'review' === $status ) {
			return 'warning';
		}

		if ( 'manual' === $status ) {
			return 'info';
		}

		if ( 'discarded' === $status ) {
			return 'neutral';
		}

		return 'success';
	}

	private function product_margin_category_options() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$options = array();
		foreach ( $terms as $term ) {
			$term_id = (int) ( $term->term_id ?? 0 );
			if ( $term_id <= 0 ) {
				continue;
			}

			$options[ $term_id ] = sanitize_text_field( (string) ( $term->name ?? '' ) );
		}

		return $options;
	}

	private function render_master_report_page( array $request_args, array $payload, array $versions, $print = false ) {
		$autoload_live = ! $print && empty( $payload ) && ! empty( $request_args['run'] ) && empty( $request_args['snapshot_id'] );
		?>
		<section class="asdl-fin-panel asdl-fin-report-toolbar" data-master-report-root="<?php echo $print ? '0' : '1'; ?>" data-master-report-autoload="<?php echo $autoload_live ? '1' : '0'; ?>" data-master-report-snapshot="<?php echo ! empty( $payload['meta']['is_snapshot'] ) ? '1' : '0'; ?>">
			<div class="asdl-fin-panel-header">
				<h2>Filtros del reporte</h2>
				<p>El reporte maestro separa desempeño del período y posición abierta al cierre con las mismas fuentes del core financiero.</p>
			</div>
			<?php if ( ! $print ) : ?>
				<?php $this->render_master_report_filters( $request_args ); ?>
				<div class="asdl-fin-tool-progress asdl-fin-master-report-progress" data-master-report-progress>
					<?php
					if ( $autoload_live ) {
						echo wp_kses_post( $this->render_notice_box( 'warning', 'Preparando el runner del reporte maestro...', 'El reporte se calculara por etapas para que puedas ver el avance real y evitar pantallas en blanco.' ) );
					}
					?>
				</div>
				<div data-master-report-actions>
					<?php if ( ! empty( $payload ) ) : ?>
						<?php $this->render_master_report_actions( $request_args, $payload ); ?>
					<?php endif; ?>
				</div>
				<div data-master-report-versions>
					<?php if ( ! empty( $versions ) ) : ?>
						<?php $this->render_master_report_versions( $versions, $request_args, $payload ); ?>
					<?php endif; ?>
				</div>
				<div data-master-report-sections>
					<?php if ( ! empty( $payload ) ) : ?>
						<?php $this->render_master_report_sections( $payload, $print ); ?>
					<?php else : ?>
						<?php $this->render_empty_state( 'Selecciona el rango o el mes y pulsa Cargar reporte.', 'El reporte vivo ahora corre por etapas con barra de progreso. Si la verificacion de productos ya se hizo hoy, el runner la reutiliza.' ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		if ( empty( $payload ) || ! $print ) {
			return;
		}

		$this->render_master_report_sections( $payload, $print );
	}

	private function render_master_report_filters( array $request_args ) {
		$current_mode   = sanitize_key( (string) ( $request_args['mode'] ?? 'total' ) );
		$statuses       = function_exists( 'wc_get_order_statuses' ) ? (array) wc_get_order_statuses() : array();
		$sales_filters  = (array) ( $request_args['sales_statuses'] ?? array() );
		$pending_filters = (array) ( $request_args['pending_statuses'] ?? array() );
		$advanced_open  = '' !== (string) ( $request_args['sales_exclude_categories'] ?? '' ) || ! empty( $sales_filters ) || ! empty( $pending_filters );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-report-filter-grid" data-master-report-form="1">
			<input type="hidden" name="page" value="asdl-fin-reports" />
			<input type="hidden" name="report_run" value="1" />
			<?php $this->render_current_fiscal_hidden_input(); ?>
			<?php
			$this->render_select(
				'report_mode',
				'Modo',
				array(
					'range'         => 'Rango libre',
					'total'         => 'Total del ejercicio',
					'monthly_close' => 'Cierre mensual',
				),
				true,
				'Selecciona un modo',
				$current_mode,
				array( 'data-report-mode-select' => '1' )
			);
			$this->render_input( 'report_range_from', 'Desde', 'date', $request_args['range_from'] ?? '', false, array( 'data-report-mode-target' => 'range' ) );
			$this->render_input( 'report_range_to', 'Hasta', 'date', $request_args['range_to'] ?? '', false, array( 'data-report-mode-target' => 'range' ) );
			$this->render_input( 'report_month', 'Mes calendario', 'month', $request_args['month_key'] ?? '', false, array( 'data-report-mode-target' => 'monthly_close' ) );
			?>
			<div class="asdl-fin-field asdl-fin-field-wide">
				<span>Acción</span>
				<div class="asdl-fin-inline-actions">
					<?php submit_button( 'Cargar reporte', 'primary', 'submit', false, array( 'data-master-report-submit' => '1' ) ); ?>
					<a class="button button-secondary" href="<?php echo esc_url( $this->report_page_url( array( 'mode' => 'total', 'run' => 1 ) ) ); ?>" data-master-report-total-shortcut="1">Total del ejercicio</a>
				</div>
			</div>
			<details class="asdl-fin-report-advanced" <?php echo $advanced_open ? 'open' : ''; ?>>
				<summary>Ventas y margen comercial · filtros avanzados</summary>
				<div class="asdl-fin-form-grid asdl-fin-report-advanced-grid">
					<?php $this->render_input( 'sales_exclude_categories', 'Excluir categorías', 'text', $request_args['sales_exclude_categories'] ?? '', false, array( 'placeholder' => 'slug-o-id, slug-2' ) ); ?>
					<?php if ( ! empty( $statuses ) ) : ?>
						<label class="asdl-fin-field asdl-fin-field-wide">
							<span>Estados que cuentan como ventas</span>
							<div class="asdl-fin-inline-checkbox-grid">
								<?php foreach ( $statuses as $status_key => $status_label ) : ?>
									<label class="asdl-fin-inline-checkbox">
										<input type="checkbox" name="sales_statuses[]" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $sales_filters, true ) ); ?> />
										<span><?php echo esc_html( $status_label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</label>
						<label class="asdl-fin-field asdl-fin-field-wide">
							<span>Estados comerciales de referencia</span>
							<div class="asdl-fin-inline-checkbox-grid">
								<?php foreach ( $statuses as $status_key => $status_label ) : ?>
									<label class="asdl-fin-inline-checkbox">
										<input type="checkbox" name="pending_statuses[]" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $pending_filters, true ) ); ?> />
										<span><?php echo esc_html( $status_label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</label>
					<?php endif; ?>
				</div>
			</details>
		</form>
		<?php
	}

	private function render_master_report_actions( array $request_args, array $payload ) {
		$effective_args = $this->report_args_from_payload( $request_args, $payload );
		$meta           = (array) ( $payload['meta'] ?? array() );
		$preflight      = (array) ( $payload['preflight'] ?? array() );
		$gate           = (array) ( $meta['monthly_close_gate'] ?? array() );
		?>
		<div class="asdl-fin-report-actions">
			<div class="asdl-fin-note-box">
				<strong><?php echo esc_html( $this->master_report_range_label( $payload ) ); ?></strong>
				<p>
					<?php if ( ! empty( $meta['is_snapshot'] ) ) : ?>
						<?php
						printf(
							'Estas viendo una version guardada del cierre mensual. Version: v%s%s.',
							esc_html( number_format_i18n( (int) ( $meta['snapshot_version'] ?? 0 ) ) ),
							! empty( $meta['is_official'] ) ? ' · oficial' : ''
						);
						?>
					<?php else : ?>
						Reporte vivo calculado con las mismas fuentes de saldos que perfil, dashboard y móvil.
					<?php endif; ?>
				</p>
				<p>
					<?php
					if ( ! empty( $preflight['cache_valid'] ) ) {
						printf(
							'Verificacion de productos: %1$s. Revisada el %2$s sobre %3$s.',
							(int) ( $preflight['critical_count'] ?? 0 ) > 0
								? sprintf( '%d critico(s)', (int) ( $preflight['critical_count'] ?? 0 ) )
								: ( (int) ( $preflight['review_count'] ?? 0 ) > 0
									? sprintf( '%d por revisar', (int) ( $preflight['review_count'] ?? 0 ) )
									: ( ! empty( $preflight['certified_today'] ) ? 'OK hoy' : 'Vista activa vigente' ) ),
							esc_html( sanitize_text_field( (string) ( $preflight['checked_at'] ?? '' ) ) ),
							esc_html( sanitize_text_field( (string) ( $preflight['scope_label'] ?? 'Catalogo de productos' ) ) )
						);
					} else {
						echo 'La revision de productos sigue pendiente o vencida para este alcance.';
					}
					?>
				</p>
				<?php if ( ! empty( $gate['reason'] ) && 'monthly_close' === sanitize_key( (string) ( $effective_args['mode'] ?? '' ) ) ) : ?>
					<p><strong><?php echo esc_html( $gate['reason'] ); ?></strong></p>
				<?php endif; ?>
			</div>
			<div class="asdl-fin-inline-actions">
				<a class="button button-secondary" href="<?php echo esc_url( $this->with_current_context_url( admin_url( 'admin.php?page=asdl-fin-product-margins' ) ) ); ?>">Productos y margenes</a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="asdl_fin_export_master_report" />
					<?php wp_nonce_field( 'asdl_fin_export_master_report' ); ?>
					<?php $this->render_master_report_hidden_fields( $effective_args, true ); ?>
					<?php submit_button( 'Exportar CSV', 'secondary', 'submit', false ); ?>
				</form>
				<a class="button button-secondary" href="<?php echo esc_url( $this->report_print_url( $effective_args ) ); ?>">Vista imprimible</a>
				<?php if ( 'monthly_close' === sanitize_key( (string) ( $effective_args['mode'] ?? '' ) ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="asdl_fin_generate_monthly_close" />
						<?php wp_nonce_field( 'asdl_fin_generate_monthly_close' ); ?>
						<?php $this->render_master_report_hidden_fields( array_merge( $effective_args, array( 'snapshot_id' => 0 ) ), false ); ?>
						<button type="submit" class="button button-primary" <?php disabled( empty( $gate['can_generate_official'] ) ); ?>>Generar nueva version del mes</button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_master_report_versions( array $versions, array $request_args, array $payload ) {
		$current_snapshot_id = (int) ( $payload['meta']['snapshot_id'] ?? 0 );
		$effective_args      = $this->report_args_from_payload( $request_args, $payload );
		?>
		<section class="asdl-fin-panel asdl-fin-report-version-panel">
			<div class="asdl-fin-panel-header">
				<h2>Versiones del cierre mensual</h2>
				<p>Un mismo mes puede tener varias versiones. Solo una queda marcada como oficial.</p>
			</div>
			<div class="asdl-fin-table-wrap">
				<table class="widefat striped asdl-fin-table asdl-fin-table-compact">
					<thead>
						<tr>
							<th>Versión</th>
							<th>Estado</th>
							<th>Rango</th>
							<th>Creada</th>
							<th>Gestión</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $versions as $version ) : ?>
							<tr>
								<td>#<?php echo esc_html( number_format_i18n( (int) ( $version['version_no'] ?? 0 ) ) ); ?></td>
								<td><?php echo wp_kses_post( $this->render_pill( ! empty( $version['is_official'] ) ? 'Oficial' : 'Guardada', ! empty( $version['is_official'] ) ? 'success' : 'neutral' ) ); ?></td>
								<td><?php echo esc_html( sanitize_text_field( (string) ( $version['range_from'] ?? '' ) ) . ' al ' . sanitize_text_field( (string) ( $version['range_to'] ?? '' ) ) ); ?></td>
								<td><?php echo esc_html( sanitize_text_field( (string) ( $version['created_at'] ?? '' ) ) ); ?></td>
								<td>
									<div class="asdl-fin-inline-actions">
										<a class="button button-secondary button-small" href="<?php echo esc_url( $this->report_page_url( array_merge( $effective_args, array( 'snapshot_id' => (int) ( $version['id'] ?? 0 ) ) ) ) ); ?>">
											<?php echo (int) ( $version['id'] ?? 0 ) === $current_snapshot_id ? 'Viendo' : 'Abrir'; ?>
										</a>
										<?php if ( empty( $version['is_official'] ) ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="asdl_fin_mark_monthly_close_official" />
												<input type="hidden" name="snapshot_id" value="<?php echo esc_attr( (int) ( $version['id'] ?? 0 ) ); ?>" />
												<?php wp_nonce_field( 'asdl_fin_mark_monthly_close_official' ); ?>
												<?php $this->render_master_report_hidden_fields( array_merge( $effective_args, array( 'snapshot_id' => (int) ( $version['id'] ?? 0 ) ) ), true ); ?>
												<?php submit_button( 'Marcar oficial', 'secondary', 'submit', false, array( 'class' => 'button button-secondary button-small' ) ); ?>
											</form>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	private function render_master_report_sections( array $payload, $print = false ) {
		$executive   = (array) ( $payload['executive'] ?? array() );
		$sales       = (array) ( $payload['sales'] ?? array() );
		$receivables = (array) ( $payload['receivables'] ?? array() );
		$payables    = (array) ( $payload['payables'] ?? array() );
		$expenses    = (array) ( $payload['expenses'] ?? array() );
		$comparison  = (array) ( $payload['comparison'] ?? array() );
		$overview    = (array) ( $payload['overview'] ?? array() );
		$preflight   = (array) ( $payload['preflight'] ?? array() );
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Prechequeo comercial</h2>
				<p>Antes de confiar el cierre oficial, el reporte revisa el estado actual del catalogo, sus costos y la referencia de precio real.</p>
			</div>
			<div class="asdl-fin-card-grid asdl-fin-report-card-grid">
				<?php $this->render_report_metric_card( 'Criticos', (int) ( $preflight['critical_count'] ?? 0 ), ! empty( $preflight['cache_valid'] ) ? 'Bloquean el cierre oficial' : 'Pendiente', false ); ?>
				<?php $this->render_report_metric_card( 'Por revisar', (int) ( $preflight['review_count'] ?? 0 ), ! empty( $preflight['cache_valid'] ) ? 'Observaciones operativas' : 'Pendiente', false ); ?>
				<?php if ( ! empty( $preflight['discarded_count'] ) ) : ?>
					<?php $this->render_report_metric_card( 'Descartados en vista', (int) ( $preflight['discarded_count'] ?? 0 ), 'Solo afectan la vista activa del reporte', false ); ?>
				<?php endif; ?>
				<?php $this->render_report_metric_card( 'Ultima revision', sanitize_text_field( (string) ( $preflight['checked_at'] ?? '' ) ), sanitize_text_field( (string) ( $preflight['scope_label'] ?? 'Catalogo de productos' ) ), false ); ?>
			</div>
			<div class="asdl-fin-note-box">
				<strong><?php echo (int) ( $preflight['certification_critical_count'] ?? 0 ) > 0 ? esc_html( 'La revision del dia tiene productos criticos que bloquean el cierre oficial.' ) : ( (int) ( $preflight['review_count'] ?? 0 ) > 0 ? esc_html( 'No hay bloqueos criticos visibles, pero si productos que conviene revisar.' ) : ( ! empty( $preflight['certified_today'] ) ? esc_html( 'No hay hallazgos bloqueantes en la revision valida de hoy.' ) : esc_html( 'No hay hallazgos bloqueantes en la vista activa del catalogo.' ) ) ); ?></strong>
				<p><?php echo (int) ( $preflight['certification_critical_count'] ?? 0 ) > 0 ? esc_html( 'Puedes seguir viendo el reporte vivo, pero el cierre mensual oficial se bloquea hasta corregir esos productos criticos.' ) : ( (int) ( $preflight['review_count'] ?? 0 ) > 0 ? esc_html( 'La vista mensual puede continuar, pero te conviene revisar esos productos antes de formalizar decisiones comerciales.' ) : ( ! empty( $preflight['certified_today'] ) ? esc_html( 'Si la revision ya se hizo hoy para este mismo alcance, el runner del reporte la reutiliza.' ) : esc_html( 'Si la vista activa de Productos y precios sigue vigente para este mismo alcance, el reporte la reutiliza sin volver a correr toda la revision.' ) ) ); ?></p>
			</div>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Resumen ejecutivo</h2>
				<p>Desempeño del período y posición abierta al cierre del rango consultado.</p>
			</div>
			<div class="asdl-fin-card-grid asdl-fin-report-card-grid">
				<?php $this->render_report_metric_card( 'Ventas netas', $executive['sales_net'] ?? 0, 'Periodo' ); ?>
				<?php $this->render_report_metric_card( 'Costo vendido', $executive['cogs'] ?? 0, 'Periodo' ); ?>
				<?php $this->render_report_metric_card( 'Ganancia bruta', $executive['gross_profit'] ?? 0, 'Periodo' ); ?>
				<?php $this->render_report_metric_card( 'Gastos del período', $executive['period_expense_total'] ?? 0, 'Consolidados' ); ?>
				<?php $this->render_report_metric_card( 'Resultado operativo', $executive['operating_result'] ?? 0, 'Antes de caja' ); ?>
				<?php $this->render_report_metric_card( 'Por cobrar abierto', $executive['receivable_open_total'] ?? 0, 'Al cierre' ); ?>
				<?php $this->render_report_metric_card( 'Por pagar abierto', $executive['payable_open_total'] ?? 0, 'Al cierre' ); ?>
				<?php $this->render_report_metric_card( 'Posición neta abierta', $executive['net_open_position'] ?? 0, 'Cobrar - pagar' ); ?>
			</div>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Ventas y margen comercial</h2>
				<p>Bloque comercial encapsulado desde Woo para conservar ventas, reembolsos, costo y margen del período.</p>
			</div>
			<?php if ( ! empty( $sales['error_message'] ) ) : ?>
				<div class="asdl-fin-note-box">
					<strong>El bloque comercial se cargo con fallback.</strong>
					<p><?php echo esc_html( (string) $sales['error_message'] ); ?></p>
				</div>
			<?php endif; ?>
			<div class="asdl-fin-card-grid asdl-fin-report-card-grid">
				<?php $this->render_report_metric_card( 'Ventas brutas', $sales['totals']['gross_sales'] ?? 0, 'Woo / OpenPOS' ); ?>
				<?php $this->render_report_metric_card( 'Reembolsos', $sales['totals']['refunds'] ?? 0, 'Dentro del rango' ); ?>
				<?php $this->render_report_metric_card( 'Ventas netas', $sales['totals']['net_sales'] ?? 0, 'Comercial' ); ?>
				<?php $this->render_report_metric_card( 'Costo vendido', $sales['totals']['cogs'] ?? 0, 'COGS' ); ?>
				<?php $this->render_report_metric_card( 'Ganancia bruta', $sales['totals']['gross_profit'] ?? 0, 'Ventas - costo' ); ?>
				<?php $this->render_report_metric_card( 'Pedidos', $sales['totals']['orders'] ?? 0, 'Contados como venta', false ); ?>
				<?php $this->render_report_metric_card( 'Unidades', $sales['totals']['units'] ?? 0, 'Vendidas', false ); ?>
				<?php $this->render_report_metric_card( 'Margen bruto %', $sales['totals']['gross_margin_percent'] ?? 0, 'Sobre ventas netas', false, '%' ); ?>
			</div>
			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Top productos</h2>
						<p>Mayor aporte comercial dentro del rango consultado.</p>
					</div>
					<?php $this->render_master_report_sales_items_table( (array) ( $sales['products'] ?? array() ), 'Producto' ); ?>
				</section>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Categorías</h2>
						<p>Desglose comercial por categoría para leer mezcla de ventas.</p>
					</div>
					<?php $this->render_master_report_sales_items_table( (array) ( $sales['categories'] ?? array() ), 'Categoría' ); ?>
				</section>
			</div>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Cuentas por cobrar abiertas</h2>
				<p>Fuente oficial: cartera agrupada del core financiero, no estados manuales de Woo.</p>
			</div>
			<div class="asdl-fin-card-grid asdl-fin-report-card-grid">
				<?php $this->render_report_metric_card( 'Total pendiente', $receivables['summary']['pending_total'] ?? 0, 'Abierto al cierre' ); ?>
				<?php $this->render_report_metric_card( 'Pedidos', $receivables['summary']['order_pending_total'] ?? 0, 'Subtotal pedidos' ); ?>
				<?php $this->render_report_metric_card( 'Otros por cobrar', $receivables['summary']['other_pending_total'] ?? 0, 'Docs, préstamos, compromisos y adelantos' ); ?>
				<?php $this->render_report_metric_card( 'En rango', $receivables['summary']['in_range_pending_total'] ?? 0, 'Nacido en el período' ); ?>
				<?php $this->render_report_metric_card( 'Histórico abierto', $receivables['summary']['historical_pending_total'] ?? 0, 'Fuera del rango actual' ); ?>
				<?php $this->render_report_metric_card( 'Grupos', $receivables['summary']['group_count'] ?? 0, 'Perfiles o personas', false ); ?>
			</div>
			<?php $this->render_master_report_groups_table( (array) ( $receivables['items'] ?? array() ), 'receivable' ); ?>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Cuentas por pagar abiertas</h2>
				<p>Fuente oficial: obligaciones abiertas del core financiero, incluyendo gastos, compras, créditos a perfiles y compromisos.</p>
			</div>
			<div class="asdl-fin-card-grid asdl-fin-report-card-grid">
				<?php $this->render_report_metric_card( 'Total pendiente', $payables['summary']['pending_total'] ?? 0, 'Abierto al cierre' ); ?>
				<?php $this->render_report_metric_card( 'Docs / proveedor', $payables['summary']['invoice_pending_total'] ?? 0, 'Incluye gastos y compras' ); ?>
				<?php $this->render_report_metric_card( 'Perfiles / terceros', $payables['summary']['profile_credit_pending_total'] ?? 0, 'Saldos a favor y deudas documentadas' ); ?>
				<?php $this->render_report_metric_card( 'Compras pendientes', $payables['summary']['purchase_pending_total'] ?? 0, 'Compra e incoming' ); ?>
				<?php $this->render_report_metric_card( 'Compromisos', $payables['summary']['commitment_pending_total'] ?? 0, 'Pendiente al cierre' ); ?>
				<?php $this->render_report_metric_card( 'Grupos', $payables['summary']['group_count'] ?? 0, 'Contrapartes', false ); ?>
			</div>
			<?php $this->render_master_report_groups_table( (array) ( $payables['items'] ?? array() ), 'payable' ); ?>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Gastos del período</h2>
				<p>Consolidado del período para gastos de empresa, servicios y nómina, con desglose por origen.</p>
			</div>
			<div class="asdl-fin-card-grid asdl-fin-report-card-grid">
				<?php $this->render_report_metric_card( 'Gastos externos', $expenses['external']['issued_total'] ?? 0, sprintf( '%s registros', number_format_i18n( (int) ( $expenses['external']['count'] ?? 0 ) ) ) ); ?>
				<?php $this->render_report_metric_card( 'Consumo interno', $expenses['internal']['issued_total'] ?? 0, sprintf( '%s registros', number_format_i18n( (int) ( $expenses['internal']['count'] ?? 0 ) ) ) ); ?>
				<?php $this->render_report_metric_card( 'Servicios', $expenses['services']['issued_total'] ?? 0, sprintf( '%s registros', number_format_i18n( (int) ( $expenses['services']['count'] ?? 0 ) ) ) ); ?>
				<?php $this->render_report_metric_card( 'Nómina', $expenses['payroll']['issued_total'] ?? 0, sprintf( '%s registros', number_format_i18n( (int) ( $expenses['payroll']['count'] ?? 0 ) ) ) ); ?>
				<?php $this->render_report_metric_card( 'Total emitido', $expenses['total']['issued_total'] ?? 0, 'Periodo consolidado' ); ?>
				<?php $this->render_report_metric_card( 'Saldo pendiente', $expenses['total']['pending_total'] ?? 0, 'Parte abierta al cierre' ); ?>
			</div>
			<?php $this->render_master_report_expense_items_table( (array) ( $expenses['items'] ?? array() ) ); ?>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Comparativo contra período anterior</h2>
				<p><?php echo esc_html( (string) ( $comparison['current_label'] ?? '' ) ); ?> vs <?php echo esc_html( (string) ( $comparison['previous_label'] ?? '' ) ); ?>.</p>
			</div>
			<?php $this->render_master_report_comparison_table( (array) ( $comparison['rows'] ?? array() ) ); ?>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Anexos</h2>
				<p>Lecturas auxiliares del reporte para exportación, revisión operativa y cierre mensual.</p>
			</div>
			<div class="asdl-fin-card-grid asdl-fin-report-card-grid">
				<?php $this->render_report_metric_card( 'Documentos abiertos', $overview['open_documents'] ?? 0, 'Conteo auxiliar', false ); ?>
				<?php $this->render_report_metric_card( 'Pagos registrados', $overview['payment_count'] ?? 0, 'Conteo auxiliar', false ); ?>
				<?php $this->render_report_metric_card( 'Documentos en rango', $overview['document_count'] ?? 0, 'Conteo auxiliar', false ); ?>
			</div>
			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Días destacados</h2>
						<p>Mejor y peor día comercial según el bloque de ventas.</p>
					</div>
					<?php $this->render_master_report_top_days_table( (array) ( $sales['top_days'] ?? array() ) ); ?>
				</section>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Estado del reporte</h2>
						<p>Resumen rápido del contexto y la versión cargada.</p>
					</div>
					<div class="asdl-fin-data-grid">
						<div><strong>Modo</strong><span><?php echo esc_html( $this->master_report_mode_label( $payload['meta']['mode'] ?? '' ) ); ?></span></div>
						<div><strong>Rango</strong><span><?php echo esc_html( $this->master_report_range_label( $payload ) ); ?></span></div>
						<div><strong>Moneda base</strong><span><?php echo esc_html( sanitize_text_field( (string) ( $payload['meta']['currency'] ?? 'USD' ) ) ); ?></span></div>
						<div><strong>Version guardada</strong><span><?php echo wp_kses_post( $this->render_pill( ! empty( $payload['meta']['is_snapshot'] ) ? 'Si' : 'No', ! empty( $payload['meta']['is_snapshot'] ) ? 'warning' : 'neutral' ) ); ?></span></div>
					</div>
				</section>
			</div>
		</section>
		<?php
	}

	private function render_master_report_hidden_fields( array $request_args, $include_snapshot = true ) {
		$mode       = sanitize_key( (string) ( $request_args['mode'] ?? 'total' ) );
		$run        = ! empty( $request_args['run'] ) || ! empty( $request_args['snapshot_id'] );
		$range_from = sanitize_text_field( (string) ( $request_args['range_from'] ?? '' ) );
		$range_to   = sanitize_text_field( (string) ( $request_args['range_to'] ?? '' ) );
		$month_key  = sanitize_text_field( (string) ( $request_args['month_key'] ?? '' ) );
		$snapshot_id = $include_snapshot ? absint( $request_args['snapshot_id'] ?? 0 ) : 0;
		?>
		<?php if ( $run ) : ?>
			<input type="hidden" name="report_run" value="1" />
		<?php endif; ?>
		<input type="hidden" name="report_mode" value="<?php echo esc_attr( $mode ); ?>" />
		<input type="hidden" name="report_range_from" value="<?php echo esc_attr( $range_from ); ?>" />
		<input type="hidden" name="report_range_to" value="<?php echo esc_attr( $range_to ); ?>" />
		<input type="hidden" name="report_month" value="<?php echo esc_attr( $month_key ); ?>" />
		<?php if ( $include_snapshot && $snapshot_id > 0 ) : ?>
			<input type="hidden" name="report_snapshot_id" value="<?php echo esc_attr( $snapshot_id ); ?>" />
		<?php endif; ?>
		<input type="hidden" name="sales_exclude_categories" value="<?php echo esc_attr( sanitize_text_field( (string) ( $request_args['sales_exclude_categories'] ?? '' ) ) ); ?>" />
		<?php foreach ( (array) ( $request_args['sales_statuses'] ?? array() ) as $status_key ) : ?>
			<input type="hidden" name="sales_statuses[]" value="<?php echo esc_attr( sanitize_text_field( (string) $status_key ) ); ?>" />
		<?php endforeach; ?>
		<?php foreach ( (array) ( $request_args['pending_statuses'] ?? array() ) as $status_key ) : ?>
			<input type="hidden" name="pending_statuses[]" value="<?php echo esc_attr( sanitize_text_field( (string) $status_key ) ); ?>" />
		<?php endforeach; ?>
		<?php $this->render_current_fiscal_hidden_input(); ?>
		<?php
	}

	private function current_report_request_args() {
		$context = $this->current_fiscal_context();

		return array(
			'run'                     => ! empty( $_GET['report_run'] ) ? 1 : 0,
			'mode'                    => sanitize_key( (string) ( wp_unslash( $_GET['report_mode'] ?? 'total' ) ) ),
			'range_from'              => sanitize_text_field( (string) ( wp_unslash( $_GET['report_range_from'] ?? ( $context['start_date'] ?? '' ) ) ) ),
			'range_to'                => sanitize_text_field( (string) ( wp_unslash( $_GET['report_range_to'] ?? ( $context['end_date'] ?? '' ) ) ) ),
			'month_key'               => sanitize_text_field( (string) ( wp_unslash( $_GET['report_month'] ?? gmdate( 'Y-m' ) ) ) ),
			'snapshot_id'             => absint( wp_unslash( $_GET['report_snapshot_id'] ?? 0 ) ),
			'sales_exclude_categories'=> sanitize_text_field( (string) ( wp_unslash( $_GET['sales_exclude_categories'] ?? '' ) ) ),
			'sales_statuses'          => array_values( array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['sales_statuses'] ?? array() ) ) ) ),
			'pending_statuses'        => array_values( array_filter( array_map( 'sanitize_text_field', (array) wp_unslash( $_GET['pending_statuses'] ?? array() ) ) ) ),
			'fiscal_year'             => absint( wp_unslash( $_GET['fiscal_year'] ?? 0 ) ),
		);
	}

	private function report_request_args_from_runtime_post() {
		$defaults = $this->current_report_request_args();
		$sales_statuses_present   = ! empty( $_POST['sales_statuses_present'] );
		$pending_statuses_present = ! empty( $_POST['pending_statuses_present'] );

		$args = array(
			'run'                     => 1,
			'mode'                    => sanitize_key( (string) ( wp_unslash( $_POST['report_mode'] ?? $defaults['mode'] ?? 'total' ) ) ),
			'range_from'              => sanitize_text_field( (string) ( wp_unslash( $_POST['report_range_from'] ?? $defaults['range_from'] ?? '' ) ) ),
			'range_to'                => sanitize_text_field( (string) ( wp_unslash( $_POST['report_range_to'] ?? $defaults['range_to'] ?? '' ) ) ),
			'month_key'               => sanitize_text_field( (string) ( wp_unslash( $_POST['report_month'] ?? $defaults['month_key'] ?? '' ) ) ),
			'snapshot_id'             => absint( wp_unslash( $_POST['report_snapshot_id'] ?? 0 ) ),
			'sales_exclude_categories'=> sanitize_text_field( (string) ( wp_unslash( $_POST['sales_exclude_categories'] ?? $defaults['sales_exclude_categories'] ?? '' ) ) ),
			'fiscal_year'             => absint( wp_unslash( $_POST['fiscal_year'] ?? $defaults['fiscal_year'] ?? 0 ) ),
		);

		if ( isset( $_POST['sales_statuses'] ) ) {
			$args['sales_statuses'] = array_values(
				array_filter(
					array_map(
						static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
						(array) wp_unslash( $_POST['sales_statuses'] )
					)
				)
			);
		} elseif ( $sales_statuses_present ) {
			$args['sales_statuses'] = array();
		} else {
			$args['sales_statuses'] = (array) ( $defaults['sales_statuses'] ?? array() );
		}

		if ( isset( $_POST['pending_statuses'] ) ) {
			$args['pending_statuses'] = array_values(
				array_filter(
					array_map(
						static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
						(array) wp_unslash( $_POST['pending_statuses'] )
					)
				)
			);
		} elseif ( $pending_statuses_present ) {
			$args['pending_statuses'] = array();
		} else {
			$args['pending_statuses'] = (array) ( $defaults['pending_statuses'] ?? array() );
		}

		return $args;
	}

	private function report_args_from_payload( array $request_args, array $payload ) {
		$meta         = (array) ( $payload['meta'] ?? array() );
		$sales_filters = (array) ( $payload['sales']['filters'] ?? array() );

		return array(
			'run'                     => ! empty( $meta ) ? 1 : ( ! empty( $request_args['run'] ) ? 1 : 0 ),
			'mode'                    => sanitize_key( (string) ( $meta['mode'] ?? $request_args['mode'] ?? 'total' ) ),
			'range_from'              => sanitize_text_field( (string) ( $meta['range_from'] ?? $request_args['range_from'] ?? '' ) ),
			'range_to'                => sanitize_text_field( (string) ( $meta['range_to'] ?? $request_args['range_to'] ?? '' ) ),
			'month_key'               => sanitize_text_field( (string) ( $meta['month_key'] ?? $request_args['month_key'] ?? '' ) ),
			'snapshot_id'             => absint( $meta['snapshot_id'] ?? $request_args['snapshot_id'] ?? 0 ),
			'sales_exclude_categories'=> sanitize_text_field( (string) ( $sales_filters['exclude_categories_raw'] ?? $request_args['sales_exclude_categories'] ?? '' ) ),
			'sales_statuses'          => array_values( array_map( 'sanitize_text_field', (array) ( $sales_filters['sales_statuses'] ?? $request_args['sales_statuses'] ?? array() ) ) ),
			'pending_statuses'        => array_values( array_map( 'sanitize_text_field', (array) ( $sales_filters['pending_statuses'] ?? $request_args['pending_statuses'] ?? array() ) ) ),
		);
	}

	private function report_page_url( array $request_args = array(), $page = 'asdl-fin-reports' ) {
		$args = array(
			'page'              => sanitize_key( (string) $page ),
			'report_run'        => ! empty( $request_args['run'] ) || ! empty( $request_args['snapshot_id'] ) ? 1 : 0,
			'report_mode'       => sanitize_key( (string) ( $request_args['mode'] ?? 'total' ) ),
			'report_range_from' => sanitize_text_field( (string) ( $request_args['range_from'] ?? '' ) ),
			'report_range_to'   => sanitize_text_field( (string) ( $request_args['range_to'] ?? '' ) ),
			'report_month'      => sanitize_text_field( (string) ( $request_args['month_key'] ?? '' ) ),
		);

		if ( ! empty( $request_args['snapshot_id'] ) ) {
			$args['report_snapshot_id'] = absint( $request_args['snapshot_id'] );
		}

		if ( ! empty( $request_args['sales_exclude_categories'] ) ) {
			$args['sales_exclude_categories'] = sanitize_text_field( (string) $request_args['sales_exclude_categories'] );
		}

		if ( ! empty( $request_args['sales_statuses'] ) ) {
			$args['sales_statuses'] = array_values( array_map( 'sanitize_text_field', (array) $request_args['sales_statuses'] ) );
		}

		if ( ! empty( $request_args['pending_statuses'] ) ) {
			$args['pending_statuses'] = array_values( array_map( 'sanitize_text_field', (array) $request_args['pending_statuses'] ) );
		}

		return $this->with_current_context_url( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	}

	private function report_print_url( array $request_args = array() ) {
		return $this->report_page_url( $request_args, 'asdl-fin-report-print' );
	}

	private function render_report_metric_card( $label, $value, $description = '', $money = true, $suffix = '' ) {
		?>
		<div class="asdl-fin-card">
			<span class="asdl-fin-label"><?php echo esc_html( $label ); ?></span>
			<strong>
				<?php
				if ( $money ) {
					echo wp_kses_post( $this->format_money( (float) $value ) );
				} else {
					echo esc_html( number_format_i18n( (float) $value, '%' === $suffix ? 2 : 0 ) . $suffix );
				}
				?>
			</strong>
			<p><?php echo esc_html( $description ); ?></p>
		</div>
		<?php
	}

	private function render_master_report_sales_items_table( array $items, $label = 'Item' ) {
		if ( empty( $items ) ) {
			$this->render_empty_state( 'Sin movimientos comerciales para este rango.', 'Cuando haya ventas dentro del rango, el bloque comercial mostrará productos y categorías aquí.' );
			return;
		}
		?>
		<div class="asdl-fin-table-wrap">
			<table class="widefat striped asdl-fin-table asdl-fin-table-compact">
				<thead>
					<tr>
						<th><?php echo esc_html( $label ); ?></th>
						<th>Cantidad</th>
						<th>Total</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $items, 0, 10 ) as $item ) : ?>
						<tr>
							<td><?php echo esc_html( sanitize_text_field( (string) ( $item['nombre'] ?? 'Item' ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) ( $item['cantidad'] ?? 0 ), 2 ) ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( (float) ( $item['total'] ?? 0 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_master_report_groups_table( array $groups, $kind = 'receivable' ) {
		if ( empty( $groups ) ) {
			$this->render_empty_state(
				'receivable' === $kind ? 'Sin cuentas por cobrar abiertas en este rango.' : 'Sin cuentas por pagar abiertas en este rango.',
				'Cuando existan grupos abiertos al cierre, aparecerán aquí con su saldo y acceso al detalle operativo.'
			);
			return;
		}
		?>
		<div class="asdl-fin-table-wrap">
			<table class="widefat striped asdl-fin-table asdl-fin-table-compact">
				<thead>
					<tr>
						<th>Grupo</th>
						<th>Resumen</th>
						<th>Pendiente</th>
						<th>Fecha más antigua</th>
						<th>Gestión</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $groups, 0, 25 ) as $group ) : ?>
						<?php
						$contact_id = (int) ( $group['contact_id'] ?? 0 );
						$manage_url = '';
						if ( $contact_id > 0 ) {
							$manage_url = 'receivable' === $kind
								? $this->contact_section_url( $contact_id, 'asdl-fin-contact-open' )
								: $this->contact_section_url( $contact_id, $this->pending_payable_manage_section( $group ) );
						}
						?>
						<tr>
							<td><?php echo wp_kses_post( $this->render_profile_reference( $contact_id, $group['display_name'] ?? '', $group['email'] ?? '', 'Sin perfil enlazado' ) ); ?></td>
							<td>
								<div class="asdl-fin-stack">
									<?php if ( 'receivable' === $kind ) : ?>
										<strong>Pedidos: <?php echo esc_html( number_format_i18n( (int) ( $group['order_count'] ?? 0 ) ) ); ?> | Otros: <?php echo esc_html( number_format_i18n( (int) ( $group['other_count'] ?? 0 ) ) ); ?></strong>
										<small>En período: <?php echo esc_html( number_format_i18n( (int) ( $group['in_range_count'] ?? 0 ) ) ); ?> | Histórico: <?php echo esc_html( number_format_i18n( (int) ( $group['historical_count'] ?? 0 ) ) ); ?></small>
									<?php else : ?>
										<strong>Docs/proveedor: <?php echo esc_html( number_format_i18n( (int) ( $group['invoice_count'] ?? 0 ) ) ); ?> | Perfiles: <?php echo esc_html( number_format_i18n( (int) ( $group['profile_credit_count'] ?? 0 ) ) ); ?></strong>
										<small>En período: <?php echo esc_html( number_format_i18n( (int) ( $group['in_range_count'] ?? 0 ) ) ); ?> | Histórico: <?php echo esc_html( number_format_i18n( (int) ( $group['historical_count'] ?? 0 ) ) ); ?></small>
									<?php endif; ?>
								</div>
							</td>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo wp_kses_post( $this->format_money( (float) ( $group['pending_total'] ?? 0 ) ) ); ?></strong>
									<small><?php echo wp_kses_post( $this->format_money( (float) ( $group['in_range_pending_total'] ?? 0 ) ) ); ?> en período</small>
								</div>
							</td>
							<td><?php echo esc_html( sanitize_text_field( (string) ( $group['oldest_date'] ?? 'Sin fecha' ) ) ); ?></td>
							<td>
								<?php if ( '' !== $manage_url ) : ?>
									<a class="button button-secondary button-small" href="<?php echo esc_url( $manage_url ); ?>">Abrir perfil</a>
								<?php else : ?>
									<span class="asdl-fin-table-meta">Sin perfil directo</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_master_report_expense_items_table( array $items ) {
		if ( empty( $items ) ) {
			$this->render_empty_state( 'Sin gastos del período.', 'Cuando existan gastos externos, consumo interno, servicios o nómina dentro del rango, aparecerán aquí.' );
			return;
		}
		?>
		<div class="asdl-fin-table-wrap">
			<table class="widefat striped asdl-fin-table asdl-fin-table-compact">
				<thead>
					<tr>
						<th>Número</th>
						<th>Concepto</th>
						<th>Tipo</th>
						<th>Fecha</th>
						<th>Total</th>
						<th>Saldo</th>
						<th>Gestión</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $items, 0, 25 ) as $item ) : ?>
						<tr>
							<td><?php echo esc_html( sanitize_text_field( (string) ( $item['document_number'] ?? '' ) ) ?: 'Pendiente' ); ?></td>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( sanitize_text_field( (string) ( $item['title'] ?? 'Movimiento' ) ) ); ?></strong>
									<small><?php echo esc_html( sanitize_text_field( (string) ( $item['contact_display_name'] ?? 'Empresa / sin contraparte' ) ) ); ?></small>
								</div>
							</td>
							<td><?php echo esc_html( sanitize_text_field( (string) ( $item['expense_origin_label'] ?? $this->label_for( 'document_type', $item['document_type'] ?? '' ) ) ) ); ?></td>
							<td><?php echo esc_html( sanitize_text_field( (string) ( $item['issue_date'] ?? '' ) ) ?: 'Sin fecha' ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( (float) ( $item['total'] ?? 0 ), $item['currency'] ?? '' ) ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( (float) ( $item['balance'] ?? 0 ), $item['currency'] ?? '' ) ); ?></td>
							<td><a class="button button-secondary button-small" href="<?php echo esc_url( $this->document_detail_url( (int) ( $item['id'] ?? 0 ), $this->document_page_slug_for_document( $item ) ) ); ?>">Ver detalle</a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_master_report_comparison_table( array $rows ) {
		if ( empty( $rows ) ) {
			$this->render_empty_state( 'Sin comparativo disponible.', 'Cuando el reporte pueda resolver el período anterior equivalente, aparecerá aquí.' );
			return;
		}
		?>
		<div class="asdl-fin-table-wrap">
			<table class="widefat striped asdl-fin-table asdl-fin-table-compact">
				<thead>
					<tr>
						<th>Métrica</th>
						<th>Actual</th>
						<th>Anterior</th>
						<th>Diferencia</th>
						<th>%</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( sanitize_text_field( (string) ( $row['label'] ?? 'Métrica' ) ) ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( (float) ( $row['current'] ?? 0 ) ) ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( (float) ( $row['previous'] ?? 0 ) ) ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( (float) ( $row['delta'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) ( $row['delta_percent'] ?? 0 ), 2 ) . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_master_report_top_days_table( array $top_days ) {
		$rows = array();
		foreach ( array( 'best' => 'Mejor', 'worst' => 'Peor' ) as $bucket => $label ) {
			foreach ( (array) ( $top_days[ $bucket ] ?? array() ) as $item ) {
				$rows[] = array(
					'label' => $label,
					'date'  => sanitize_text_field( (string) ( $item['fecha'] ?? '' ) ),
					'total' => (float) ( $item['total'] ?? 0 ),
				);
			}
		}

		if ( empty( $rows ) ) {
			$this->render_empty_state( 'Sin días destacados.', 'Cuando el bloque comercial tenga datos suficientes, mostrará aquí el mejor y peor día del período.' );
			return;
		}
		?>
		<div class="asdl-fin-table-wrap">
			<table class="widefat striped asdl-fin-table asdl-fin-table-compact">
				<thead>
					<tr>
						<th>Tipo</th>
						<th>Fecha</th>
						<th>Total</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td><?php echo esc_html( $row['date'] ?: 'Sin fecha' ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( $row['total'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function master_report_mode_label( $mode ) {
		switch ( sanitize_key( (string) $mode ) ) {
			case 'range':
				return 'Rango libre';
			case 'monthly_close':
				return 'Cierre mensual';
			default:
				return 'Total del ejercicio';
		}
	}

	private function master_report_range_label( array $payload ) {
		$meta = (array) ( $payload['meta'] ?? array() );

		return sanitize_text_field( (string) ( $meta['range_from'] ?? '' ) ) . ' al ' . sanitize_text_field( (string) ( $meta['range_to'] ?? '' ) );
	}

	public function render_receipt_page() {
		$receipt_type = isset( $_GET['receipt_type'] ) ? sanitize_key( wp_unslash( $_GET['receipt_type'] ) ) : '';
		$entity_id    = absint( wp_unslash( $_GET['entity_id'] ?? 0 ) );
		$service      = new ReceiptService();
		$branding     = ( new ReceiptBrandingService() )->get_snapshot();
		$receipt      = $service->build( $receipt_type, $entity_id );

		$this->render_page_open(
			'Comprobante',
			'Vista lista para imprimir o guardar como PDF desde el navegador.'
		);

		if ( is_wp_error( $receipt ) ) {
			$this->render_empty_state( 'No se pudo cargar el comprobante solicitado.', $receipt->get_error_message() );
			$this->render_page_close();
			return;
		}

		?>
		<section class="asdl-fin-panel asdl-fin-receipt-page">
			<div class="asdl-fin-panel-header asdl-fin-receipt-header">
				<div class="asdl-fin-receipt-brand">
					<?php if ( ! empty( $branding['show_logo'] ) ) : ?>
						<div class="asdl-fin-receipt-logo-wrap">
							<img class="asdl-fin-receipt-logo" src="<?php echo esc_url( $branding['resolved_logo'] ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
						</div>
					<?php endif; ?>
					<div>
					<h2><?php echo esc_html( $receipt['title'] ?? 'Comprobante' ); ?></h2>
					<p><?php echo esc_html( $receipt['subtitle'] ?? '' ); ?></p>
						<p class="asdl-fin-receipt-company"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
					</div>
				</div>
				<div class="asdl-fin-inline-actions asdl-fin-receipt-toolbar">
					<a class="button button-secondary" href="<?php echo esc_url( wp_get_referer() ?: admin_url( 'admin.php?page=asdl-finanzas' ) ); ?>">Volver</a>
					<button type="button" class="button button-primary asdl-fin-print-trigger">Imprimir / Guardar PDF</button>
				</div>
			</div>

			<div class="asdl-fin-receipt-meta">
				<div class="asdl-fin-receipt-card">
					<span class="asdl-fin-label">Numero</span>
					<strong><?php echo esc_html( $receipt['number'] ?? 'Sin numero' ); ?></strong>
					<p><?php echo wp_kses_post( $this->render_pill( $receipt['status_label'] ?? 'Registrado', $receipt['status_tone'] ?? 'neutral' ) ); ?></p>
				</div>
				<div class="asdl-fin-receipt-card">
					<span class="asdl-fin-label">Persona</span>
					<strong><?php echo esc_html( $receipt['party_name'] ?? 'Sin perfil asociado' ); ?></strong>
					<p><?php echo esc_html( $receipt['party_meta'] ?? 'Sin dato adicional' ); ?></p>
				</div>
				<div class="asdl-fin-receipt-card">
					<span class="asdl-fin-label">Fecha</span>
					<strong><?php echo esc_html( $receipt['date'] ?? 'Sin fecha' ); ?></strong>
					<p><?php echo esc_html( $receipt['method_label'] ?? 'Sin metodo' ); ?></p>
				</div>
				<div class="asdl-fin-receipt-card">
					<span class="asdl-fin-label">Monto principal</span>
					<strong><?php echo wp_kses_post( $this->format_money( $receipt['total_amount'] ?? 0, $receipt['currency'] ?? '' ) ); ?></strong>
					<p><?php echo esc_html( $receipt['secondary_label'] ?? '' ); ?>: <?php echo wp_kses_post( $this->format_money( $receipt['secondary_total'] ?? 0, $receipt['currency'] ?? '' ) ); ?></p>
				</div>
			</div>

			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Resumen</h2>
						<p>Datos base del comprobante y referencia de operacion.</p>
					</div>
					<div class="asdl-fin-data-grid">
						<div><strong>Cuenta</strong><span><?php echo esc_html( $receipt['account_name'] ?? 'Sin cuenta definida' ); ?></span></div>
						<div><strong>Metodo</strong><span><?php echo esc_html( $receipt['method_label'] ?? 'Sin metodo' ); ?></span></div>
						<div><strong>Referencia</strong><span><?php echo esc_html( ! empty( $receipt['reference'] ) ? $receipt['reference'] : 'Sin referencia' ); ?></span></div>
						<div><strong>Moneda</strong><span><?php echo esc_html( $receipt['currency'] ?? 'USD' ); ?></span></div>
						<?php foreach ( $receipt['meta_rows'] ?? array() as $meta_row ) : ?>
							<div><strong><?php echo esc_html( $meta_row['label'] ?? '' ); ?></strong><span><?php echo esc_html( $meta_row['value'] ?? '' ); ?></span></div>
						<?php endforeach; ?>
					</div>
				</section>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Notas</h2>
						<p>Observaciones registradas junto al movimiento.</p>
					</div>
					<div class="asdl-fin-empty">
						<strong><?php echo esc_html( ! empty( $receipt['notes'] ) ? $receipt['notes'] : 'Sin observaciones adicionales.' ); ?></strong>
						<p>Generado desde Finanzas ASD para soporte operativo e impresion.</p>
					</div>
				</section>
			</div>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Detalle aplicado</h2>
					<p>Relacion de conceptos, cuotas o movimientos cubiertos por este comprobante.</p>
				</div>
				<?php if ( ! empty( $receipt['lines'] ) ) : ?>
					<table class="widefat striped asdl-fin-table">
						<thead>
							<tr>
								<th>Concepto</th>
								<th>Detalle</th>
								<th>Contexto</th>
								<th>Monto</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $receipt['lines'] as $line ) : ?>
								<tr>
									<td><?php echo esc_html( $line['label'] ?? 'Linea' ); ?></td>
									<td><?php echo esc_html( $line['detail'] ?? '—' ); ?></td>
									<td><?php echo esc_html( $line['context'] ?? '—' ); ?></td>
									<td><?php echo wp_kses_post( $this->format_money( $line['amount'] ?? 0, $receipt['currency'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<?php $this->render_empty_state( 'Sin detalle adicional para este comprobante.', 'Esto es normal cuando el movimiento no se aplico contra documentos o cuotas especificas.' ); ?>
				<?php endif; ?>
			</section>
		</section>
		<?php
		$this->render_page_close();
	}

	public function render_accounts_page() {
		$this->render_page_open(
			'Cuentas',
			'Organiza fondos, centros operativos, cajas o cuentas de control para que el resto del sistema tenga una base clara.'
		);
		?>
		<div class="asdl-fin-panel-grid">
			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Nueva cuenta</h2>
					<p>Usa nombres simples y claros. Ejemplo: Caja principal, Operaciones, Club de clientes o Servicios.</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
					<input type="hidden" name="action" value="asdl_fin_save_account" />
					<input type="hidden" name="return_page" value="asdl-fin-accounts" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php wp_nonce_field( 'asdl_fin_save_account' ); ?>
					<?php $this->render_input( 'name', 'Nombre de la cuenta', 'text', '', true ); ?>
					<?php $this->render_input( 'code', 'Codigo interno', 'text', '' ); ?>
					<?php $this->render_select( 'account_type', 'Tipo', array( 'operating' => 'Operativa', 'cost_center' => 'Centro operativo', 'cash' => 'Caja', 'loan' => 'Prestamo', 'wallet' => 'Fondo' ) ); ?>
					<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
					<?php $this->render_select( 'status', 'Estado', array( 'active' => 'Activa', 'inactive' => 'Inactiva' ) ); ?>
					<?php $this->render_textarea( 'notes', 'Notas', 'Uso interno o descripcion operativa de la cuenta.' ); ?>
					<?php submit_button( 'Guardar cuenta', 'primary', 'submit', false ); ?>
				</form>
			</section>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Cuentas registradas</h2>
					<p>Listado base del nuevo core financiero.</p>
				</div>
				<div
					class="asdl-fin-runtime-container"
					data-runtime-action="asdl_fin_admin_runtime"
					data-runtime-nonce="adminRuntime"
					data-runtime-priority="high"
					data-runtime-group="accounts"
					data-runtime-title="No se pudo cargar la tabla de cuentas."
					data-runtime-param-page-key="accounts"
					data-runtime-param-section-key="accounts-table"
				>
					<?php $this->render_runtime_table_skeleton( 'Cuentas registradas', 'Cargando la tabla base de cuentas internas...', 5, 6 ); ?>
				</div>
			</section>
		</div>
		<?php
		$this->render_page_close();
	}

	public function render_purchases_page() {
		$this->render_construction_page(
			'Compras',
			'Base sembrada para el futuro gestor propio de ordenes de compra de ASD Labs.',
			array(
				'registro de ordenes de compra propias sin depender de ATUM ni plugins externos',
				'proveedores, condiciones, totales estimados y control de saldos por pagar',
				'recepcion parcial o completa vinculada con mercancia por recibir',
				'impacto financiero posterior sobre gastos, cuentas por pagar y stock',
			)
		);
	}

	public function render_services_page() {
		$documents_repository      = new DocumentsRepository();
		$contacts_repository       = new ContactsRepository();
		$accounts_repository       = new AccountsRepository();
		$service_profiles_repo     = new ServiceProfilesRepository();
		$readonly_context          = $this->is_fiscal_readonly_context();
		$selected_document_id      = isset( $_GET['document_id'] ) ? absint( wp_unslash( $_GET['document_id'] ) ) : 0;
		$selected_document         = $selected_document_id > 0 ? $documents_repository->find( $selected_document_id ) : null;
		$selected_links            = ! empty( $selected_document ) ? ( new SourceLinksRepository() )->find_for_document( $selected_document_id ) : array();
		$selected_files            = ! empty( $selected_document ) ? ( new DocumentFilesRepository() )->for_document( $selected_document_id ) : array();
		$service_contacts         = $this->service_directory_contacts( $contacts_repository );
		$service_contact_options  = $this->service_contact_options( $service_contacts );
		$account_options          = $accounts_repository->options();

		$this->render_page_open(
			'Servicios',
			'Gestiona servicios puntuales y recurrentes sobre proveedores y terceros, con cola operativa propia separada de Movimientos.'
		);
		?>
		<?php if ( ! empty( $selected_document ) && 'service_expense' === sanitize_key( (string) ( $selected_document['document_type'] ?? '' ) ) ) : ?>
			<?php $this->render_document_detail( $selected_document, $selected_links, $selected_files, $accounts_repository->options(), $contacts_repository->options() ); ?>
		<?php endif; ?>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="high"
			data-runtime-group="services"
			data-runtime-title="No se pudo cargar el resumen de servicios."
			data-runtime-param-page-key="services"
			data-runtime-param-section-key="services-summary"
		>
			<?php $this->render_runtime_card_skeleton( 6 ); ?>
		</div>

		<?php if ( $readonly_context ) : ?>
			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Operativa de servicios bloqueada</h2>
					<p>La emision y configuracion de servicios queda disponible solo en el ejercicio activo. El historico y las colas siguen visibles para revision.</p>
				</div>
				<?php $this->render_fiscal_readonly_action_state(); ?>
			</section>
		<?php endif; ?>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="high"
			data-runtime-group="services"
			data-runtime-title="No se pudo cargar la cola operativa de servicios."
			data-runtime-param-page-key="services"
			data-runtime-param-section-key="services-queue"
		>
			<?php $this->render_runtime_table_skeleton( 'Cola operativa de servicios', 'Cargando vencidos y proximos cargos de servicios...', 6, 8 ); ?>
		</div>

		<?php if ( ! $readonly_context ) : ?>
			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Nuevo servicio recurrente</h2>
						<p>Define aqui contratos, suscripciones o servicios que deban emitirse automaticamente a lo largo del tiempo.</p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
						<input type="hidden" name="action" value="asdl_fin_save_service_profile" />
						<input type="hidden" name="return_page" value="asdl-fin-services" />
						<?php $this->render_current_fiscal_hidden_input(); ?>
						<?php wp_nonce_field( 'asdl_fin_save_service_profile' ); ?>
						<?php $this->render_input( 'title', 'Servicio recurrente', 'text', '', true ); ?>
						<?php $this->render_contact_picker( 'contact_id', 'Proveedor o tercero', 0, true, array( 'placeholder' => 'Busca proveedor o tercero', 'help' => 'Escribe al menos 2 caracteres para buscar rapido por nombre, correo o ID.', 'fallback_options' => $service_contact_options, 'fallback_placeholder' => 'Selecciona un proveedor o tercero' ) ); ?>
						<?php $this->render_select( 'account_id', 'Cuenta sugerida', $account_options, false, 'Selecciona una cuenta' ); ?>
						<?php $this->render_select( 'frequency_key', 'Frecuencia', $service_profiles_repo->frequency_options(), true ); ?>
						<?php $this->render_input( 'start_date', 'Primera emision', 'date', gmdate( 'Y-m-d' ), true ); ?>
						<?php $this->render_input( 'next_issue_date', 'Proxima emision (opcional)', 'date', '' ); ?>
						<?php $this->render_input( 'default_due_days', 'Dias para vencer', 'number', '0', false, array( 'min' => '0', 'step' => '1' ) ); ?>
						<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
						<?php $this->render_input( 'amount', 'Monto base', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01' ) ); ?>
						<?php $this->render_input( 'payment_terms', 'Condiciones de pago', 'text', '' ); ?>
						<?php $this->render_input( 'external_reference', 'Contrato o referencia', 'text', '' ); ?>
						<?php $this->render_select( 'status', 'Estado', $service_profiles_repo->status_options(), true, '', 'active' ); ?>
						<?php $this->render_textarea( 'notes', 'Notas', 'Describe alcance, ciclo acordado, comentario operativo o detalles del proveedor.' ); ?>
						<?php submit_button( 'Guardar servicio recurrente', 'primary', 'submit', false ); ?>
					</form>
				</section>

				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Nuevo servicio puntual</h2>
						<p>Registra aqui obligaciones puntuales de servicio que deban salir a cuentas por pagar sin recurrencia.</p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid" enctype="multipart/form-data">
						<input type="hidden" name="action" value="asdl_fin_save_document" />
						<input type="hidden" name="return_page" value="asdl-fin-services" />
						<?php $this->render_current_fiscal_hidden_input(); ?>
						<?php wp_nonce_field( 'asdl_fin_save_document' ); ?>
						<?php $this->render_hidden_inputs( array( 'document_type' => 'service_expense', 'source_type' => 'manual', 'financial_status' => 'posted' ) ); ?>
						<?php $this->render_input( 'title', 'Servicio o concepto', 'text', '', true ); ?>
						<?php $this->render_contact_picker( 'contact_id', 'Proveedor o tercero', 0, false, array( 'placeholder' => 'Busca proveedor o tercero', 'help' => 'Opcional. Puedes vincular el servicio puntual a un proveedor o tercero existente.', 'fallback_options' => $service_contact_options, 'fallback_placeholder' => 'Selecciona un proveedor o tercero' ) ); ?>
						<?php $this->render_select( 'account_id', 'Cuenta', $account_options, false, 'Selecciona una cuenta' ); ?>
						<?php $this->render_input( 'issue_date', 'Fecha de emision', 'date', gmdate( 'Y-m-d' ), true ); ?>
						<?php $this->render_input( 'due_date', 'Vencimiento', 'date', '' ); ?>
						<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
						<?php $this->render_input( 'total', 'Monto total', 'number', '0', true, array( 'step' => '0.01', 'min' => '0' ) ); ?>
						<?php $this->render_input( 'paid_total', 'Monto abonado', 'number', '0', false, array( 'step' => '0.01', 'min' => '0' ) ); ?>
						<?php $this->render_select( 'payment_status', 'Estado de pago', array( 'pending' => 'Pendiente', 'partial' => 'Abonado', 'paid' => 'Pagado' ) ); ?>
						<?php $this->render_input( 'external_reference', 'Contrato o referencia', 'text', '' ); ?>
						<?php $this->render_file_input( 'document_file', 'Comprobante o soporte', 'Adjunta factura, contrato o soporte simple del servicio.' ); ?>
						<?php $this->render_textarea( 'notes', 'Notas', 'Incluye alcance, periodicidad pactada o comentario operativo del servicio puntual.' ); ?>
						<?php submit_button( 'Guardar servicio puntual', 'primary', 'submit', false ); ?>
					</form>
				</section>
			</div>
		<?php endif; ?>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="normal"
			data-runtime-group="services"
			data-runtime-title="No se pudo cargar los servicios recurrentes."
			data-runtime-param-page-key="services"
			data-runtime-param-section-key="services-profiles"
		>
			<?php $this->render_runtime_table_skeleton( 'Servicios recurrentes configurados', 'Cargando la base de contratos y recurrencias...', 6, 8 ); ?>
		</div>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="low"
			data-runtime-group="services"
			data-runtime-title="No se pudo cargar la base de proveedores y terceros."
			data-runtime-param-page-key="services"
			data-runtime-param-section-key="services-directory"
		>
			<?php $this->render_runtime_table_skeleton( 'Base de proveedores y terceros', 'Cargando el directorio operativo base...', 5, 6 ); ?>
		</div>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="normal"
			data-runtime-group="services"
			data-runtime-title="No se pudo cargar los servicios por pagar."
			data-runtime-param-page-key="services"
			data-runtime-param-section-key="services-open-documents"
		>
			<?php $this->render_runtime_table_skeleton( 'Servicios por pagar', 'Cargando obligaciones de servicio pendientes...', 6, 8 ); ?>
		</div>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="low"
			data-runtime-group="services"
			data-runtime-title="No se pudo cargar los servicios registrados."
			data-runtime-param-page-key="services"
			data-runtime-param-section-key="services-documents"
		>
			<?php $this->render_runtime_table_skeleton( 'Servicios registrados', 'Cargando el historico operativo de servicios...', 6, 8 ); ?>
		</div>
		<?php
		$this->render_page_close();
	}

	public function render_expenses_page() {
		$documents_repository = new DocumentsRepository();
		$contacts_repository  = new ContactsRepository();
		$accounts_repository  = new AccountsRepository();
		$files_repository     = new DocumentFilesRepository();
		$source_links         = new SourceLinksRepository();
		$readonly_context     = $this->is_fiscal_readonly_context();
		$selected_document_id = isset( $_GET['document_id'] ) ? absint( wp_unslash( $_GET['document_id'] ) ) : 0;
		$selected_document    = $selected_document_id > 0 ? $documents_repository->find( $selected_document_id ) : null;

		if ( ! empty( $selected_document ) && ! $this->is_expense_document( $selected_document ) ) {
			$selected_document_id = 0;
			$selected_document    = null;
		}

		$selected_links      = ! empty( $selected_document ) ? $source_links->find_for_document( $selected_document_id ) : array();
		$selected_files      = ! empty( $selected_document ) ? $files_repository->for_document( $selected_document_id ) : array();
		$filters             = $this->expense_filters_from_input( $_GET );
		$selected_contact_id = (int) ( $filters['contact_id'] ?? 0 );

		$this->render_page_open(
			'Gastos',
			'Gestion operativa para gastos de empresa y pedidos asumidos como consumo interno o regalo.'
		);
		?>
		<section class="asdl-fin-panel asdl-fin-expenses-guide-panel">
			<details class="asdl-fin-expenses-guide-details">
				<summary class="asdl-fin-expenses-guide-summary">
					<span class="asdl-fin-expenses-guide-icon" aria-hidden="true">?</span>
					<div class="asdl-fin-expenses-guide-copy">
						<strong>Ayuda rapida: cuando usar cada modulo</strong>
						<small>Abre esta guia solo si quieres confirmar donde cargar cada caso.</small>
					</div>
					<span class="asdl-fin-expenses-guide-action">Ver guia</span>
				</summary>
				<div class="asdl-fin-expenses-guide-body">
					<div class="asdl-fin-table-wrap">
						<table class="widefat striped asdl-fin-table asdl-fin-expenses-guide-table">
							<thead>
								<tr>
									<th>Caso</th>
									<th>Modulo correcto</th>
									<th>Cuando usarlo</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>Gasto normal de la empresa</td>
									<td><strong>Gastos</strong></td>
									<td>Con o sin contraparte. Aqui se registra, busca, revisa y anula.</td>
								</tr>
								<tr>
									<td>Pedido asumido por la tienda como gasto o regalo</td>
									<td><strong>Gastos</strong></td>
									<td>Se muestra como consumo interno o regalo interno para leerlo junto al resto del gasto operativo.</td>
								</tr>
								<tr>
									<td>Gasto nacido del flujo de servicios</td>
									<td><strong>Servicios</strong></td>
									<td>Usalo cuando el gasto forma parte de la operativa del servicio.</td>
								</tr>
								<tr>
									<td>Sueldo, liquidacion o gasto laboral</td>
									<td><strong>Nomina</strong></td>
									<td>Se mantiene en el flujo laboral y no en gastos generales.</td>
								</tr>
								<tr>
									<td>Ajuste, prestamo o documento tecnico</td>
									<td><strong>Movimientos</strong></td>
									<td>Reservado para casos contables o tecnicos que no son gasto normal de empresa.</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</details>
		</section>

		<?php if ( ! empty( $selected_document ) ) : ?>
			<?php $this->render_document_detail( $selected_document, $selected_links, $selected_files, $accounts_repository->options(), $contacts_repository->options() ); ?>
		<?php endif; ?>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="high"
			data-runtime-group="expenses"
			data-runtime-title="No se pudo cargar el resumen de gastos."
			data-runtime-param-page-key="expenses"
			data-runtime-param-section-key="expenses-summary"
			data-runtime-param-expense-search="<?php echo esc_attr( (string) ( $filters['search'] ?? '' ) ); ?>"
			data-runtime-param-expense-financial-status="<?php echo esc_attr( (string) ( $filters['financial_status'] ?? '' ) ); ?>"
			data-runtime-param-expense-payment-status="<?php echo esc_attr( (string) ( $filters['payment_status'] ?? '' ) ); ?>"
			data-runtime-param-expense-range-from="<?php echo esc_attr( (string) ( $filters['range_from'] ?? '' ) ); ?>"
			data-runtime-param-expense-range-to="<?php echo esc_attr( (string) ( $filters['range_to'] ?? '' ) ); ?>"
			data-runtime-param-expense-open-only="<?php echo ! empty( $filters['open_only'] ) ? '1' : ''; ?>"
			data-runtime-param-expense-has-contact="<?php echo esc_attr( (string) ( $filters['has_contact'] ?? 'all' ) ); ?>"
			data-runtime-param-expense-contact-id="<?php echo esc_attr( (string) $selected_contact_id ); ?>"
		>
			<?php $this->render_runtime_card_skeleton( 5 ); ?>
		</div>

		<?php if ( $readonly_context ) : ?>
			<section class="asdl-fin-panel asdl-fin-expenses-entry-panel">
				<div class="asdl-fin-panel-header">
					<h2>Nuevo gasto</h2>
					<p>El alta y la anulación de gastos quedan bloqueadas mientras consultas un ejercicio histórico. El listado sigue disponible para revisión.</p>
				</div>
				<?php $this->render_fiscal_readonly_action_state(); ?>
			</section>
		<?php else : ?>
			<section class="asdl-fin-panel asdl-fin-expenses-entry-panel">
				<div class="asdl-fin-panel-header">
					<h2>Nuevo gasto</h2>
					<p>Registra aquí gastos normales de empresa, con o sin perfil o proveedor vinculado.</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-expenses-entry-form" enctype="multipart/form-data" data-expense-payment-form="1">
					<input type="hidden" name="action" value="asdl_fin_save_document" />
					<input type="hidden" name="return_page" value="asdl-fin-expenses" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php wp_nonce_field( 'asdl_fin_save_document' ); ?>
					<?php $this->render_hidden_inputs( array( 'document_type' => 'external_expense', 'source_type' => 'manual', 'financial_status' => 'posted' ) ); ?>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-title">
						<?php $this->render_input( 'title', 'Concepto o titulo', 'text', '', true ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-issue-date">
						<?php $this->render_input( 'issue_date', 'Fecha', 'date', gmdate( 'Y-m-d' ), true ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-due-date">
						<?php $this->render_input( 'due_date', 'Vencimiento (opcional)', 'date', '' ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-total">
						<?php $this->render_input( 'total', 'Monto total', 'number', '0', true, array( 'step' => '0.01', 'min' => '0' ) ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-paid-total">
						<?php $this->render_input( 'paid_total', 'Monto abonado', 'number', '0', false, array( 'step' => '0.01', 'min' => '0', 'data-expense-paid-total' => '1' ) ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-currency">
						<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-payment-status">
						<?php $this->render_select( 'payment_status', 'Estado de pago', $this->expense_payment_status_options(), true, '', 'pending', array( 'data-expense-payment-status' => '1' ) ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-payment-method" data-expense-payment-method-field hidden>
						<?php $this->render_payment_method_select( 'payment_method_key', 'Metodo de pago', '', false, 'Selecciona un metodo', array( 'data-expense-payment-method-select' => '1' ) ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-account">
						<?php $this->render_select( 'account_id', 'Cuenta', $accounts_repository->options(), false, 'Selecciona una cuenta' ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-contact">
						<?php $this->render_contact_picker( 'contact_id', 'Perfil o proveedor', 0, false, array( 'placeholder' => 'Busca un perfil o proveedor', 'help' => 'Opcional. Si no seleccionas contraparte, el gasto quedará como Empresa / sin contraparte.', 'fallback_options' => $contacts_repository->options(), 'fallback_placeholder' => 'Empresa / sin contraparte' ) ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-payment-note asdl-fin-field-wide" data-expense-payment-method-helper hidden>
						<div class="asdl-fin-note-box">
							<strong>Metodo de pago del gasto</strong>
							<p>En gastos externos este dato solo deja trazabilidad del abono ya registrado. No usa precio dual ni descuento por USD.</p>
						</div>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-file asdl-fin-field-wide">
						<?php $this->render_file_input( 'document_file', 'Comprobante o soporte', 'Adjunta factura, foto, PDF o cualquier soporte de este gasto.' ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-notes asdl-fin-field-wide">
						<?php $this->render_textarea( 'notes', 'Notas', 'Incluye contexto operativo, comprobante, proveedor o cualquier aclaratoria relevante.' ); ?>
					</div>
					<div class="asdl-fin-expenses-cell asdl-fin-expenses-cell-submit asdl-fin-field-wide">
						<div class="asdl-fin-inline-actions">
							<?php submit_button( 'Guardar gasto', 'primary', 'submit', false ); ?>
						</div>
					</div>
				</form>
			</section>
		<?php endif; ?>

		<section class="asdl-fin-panel asdl-fin-expenses-table-panel">
				<div class="asdl-fin-panel-header">
					<h2>Gastos registrados</h2>
					<p>Busca gastos de empresa y consumos internos por estado, pago, fechas y contraparte sin salir de esta vista.</p>
				</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-expenses-filters">
				<input type="hidden" name="page" value="asdl-fin-expenses" />
				<?php $this->render_current_fiscal_hidden_input(); ?>
				<div class="asdl-fin-expenses-filter-cell asdl-fin-expenses-filter-cell-search">
					<?php $this->render_input( 'expense_search', 'Buscar gasto', 'search', (string) ( $filters['search'] ?? '' ), false, array( 'placeholder' => 'Numero o concepto' ) ); ?>
				</div>
				<div class="asdl-fin-expenses-filter-cell asdl-fin-expenses-filter-cell-financial-status">
					<?php $this->render_select( 'expense_financial_status', 'Estado del gasto', $this->expense_financial_status_options(), false, 'Todos', (string) ( $filters['financial_status'] ?? '' ) ); ?>
				</div>
				<div class="asdl-fin-expenses-filter-cell asdl-fin-expenses-filter-cell-payment-status">
					<?php $this->render_select( 'expense_payment_status', 'Estado de pago', $this->expense_payment_status_options(), false, 'Todos', (string) ( $filters['payment_status'] ?? '' ) ); ?>
				</div>
				<div class="asdl-fin-expenses-filter-cell asdl-fin-expenses-filter-cell-range-from">
					<?php $this->render_input( 'expense_range_from', 'Desde', 'date', (string) ( $filters['range_from'] ?? '' ) ); ?>
				</div>
				<div class="asdl-fin-expenses-filter-cell asdl-fin-expenses-filter-cell-range-to">
					<?php $this->render_input( 'expense_range_to', 'Hasta', 'date', (string) ( $filters['range_to'] ?? '' ) ); ?>
				</div>
				<div class="asdl-fin-expenses-filter-cell asdl-fin-expenses-filter-cell-has-contact">
					<?php $this->render_select( 'expense_has_contact', 'Contraparte', array( 'all' => 'Todos', 'yes' => 'Con perfil', 'no' => 'Sin contraparte' ), false, '', (string) ( $filters['has_contact'] ?? 'all' ) ); ?>
				</div>
				<div class="asdl-fin-expenses-filter-cell asdl-fin-expenses-filter-cell-contact">
					<?php $this->render_contact_picker( 'expense_contact_id', 'Contraparte puntual', $selected_contact_id, false, array( 'placeholder' => 'Busca una contraparte puntual', 'help' => 'Opcional. Si eliges una contraparte concreta, el filtro anterior pasa a segundo plano.', 'fallback_options' => $contacts_repository->options(), 'fallback_placeholder' => 'Cualquier contraparte' ) ); ?>
				</div>
				<div class="asdl-fin-expenses-filter-cell asdl-fin-expenses-filter-cell-open">
					<label class="asdl-fin-inline-checkbox asdl-fin-expenses-open-toggle">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="expense_open_only" value="1" <?php checked( ! empty( $filters['open_only'] ) ); ?> />
							<strong>Solo abiertos</strong>
						</span>
					</label>
					<small>Muestra solo gastos con saldo pendiente.</small>
				</div>
				<div class="asdl-fin-expenses-filter-actions">
					<div class="asdl-fin-inline-actions">
						<?php submit_button( 'Aplicar filtros', 'secondary', '', false ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( $this->with_current_context_url( admin_url( 'admin.php?page=asdl-fin-expenses' ) ) ); ?>">Limpiar</a>
					</div>
				</div>
			</form>

			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="high"
				data-runtime-group="expenses"
				data-runtime-title="No se pudo cargar la tabla de gastos."
				data-runtime-param-page-key="expenses"
				data-runtime-param-section-key="expenses-table"
				data-runtime-param-expense-search="<?php echo esc_attr( (string) ( $filters['search'] ?? '' ) ); ?>"
				data-runtime-param-expense-financial-status="<?php echo esc_attr( (string) ( $filters['financial_status'] ?? '' ) ); ?>"
				data-runtime-param-expense-payment-status="<?php echo esc_attr( (string) ( $filters['payment_status'] ?? '' ) ); ?>"
				data-runtime-param-expense-range-from="<?php echo esc_attr( (string) ( $filters['range_from'] ?? '' ) ); ?>"
				data-runtime-param-expense-range-to="<?php echo esc_attr( (string) ( $filters['range_to'] ?? '' ) ); ?>"
				data-runtime-param-expense-open-only="<?php echo ! empty( $filters['open_only'] ) ? '1' : ''; ?>"
				data-runtime-param-expense-has-contact="<?php echo esc_attr( (string) ( $filters['has_contact'] ?? 'all' ) ); ?>"
				data-runtime-param-expense-contact-id="<?php echo esc_attr( (string) $selected_contact_id ); ?>"
			>
				<?php $this->render_runtime_table_skeleton( 'Gastos registrados', 'Cargando la base operativa de gastos externos...', 9, 8 ); ?>
			</div>
		</section>
		<?php
		$this->render_page_close();
	}

	public function render_incoming_goods_page() {
		$this->render_construction_page(
			'Mercancia por recibir',
			'Panel previsto para ver productos en camino, pendientes de recepcion y control de llegada.',
			array(
				'lista de productos esperados por orden de compra',
				'cantidades pendientes, fecha estimada y proveedor asociado',
				'recepciones parciales y cierre de llegada',
				'base para conectar luego con compras, costos y actualizacion operativa',
			)
		);
	}

	private function render_contact_runtime_container( $section_key, $contact_id, $range_from, $range_to, $order_limit, $priority, $title, $refreshable = false ) {
		?>
		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="<?php echo esc_attr( $priority ); ?>"
			data-runtime-group="contacts-detail"
			data-runtime-title="<?php echo esc_attr( $title ); ?>"
			data-runtime-param-page-key="contacts"
			data-runtime-param-section-key="<?php echo esc_attr( $section_key ); ?>"
			data-runtime-param-contact-id="<?php echo esc_attr( (int) $contact_id ); ?>"
			data-runtime-param-range-from="<?php echo esc_attr( $range_from ); ?>"
			data-runtime-param-range-to="<?php echo esc_attr( $range_to ); ?>"
			data-runtime-param-order-limit="<?php echo esc_attr( $order_limit > 0 ? $order_limit : 25 ); ?>"
			data-runtime-retryable="1"
			<?php if ( $refreshable ) : ?>
				data-contact-runtime-refreshable="1"
			<?php endif; ?>
		>
			<?php
			switch ( $section_key ) {
				case 'profile-header-summary':
					$this->render_runtime_panel_skeleton( 'Perfil', 'Cargando encabezado y navegacion del perfil.' );
					break;
				case 'profile-financial-cards':
					$this->render_runtime_card_skeleton( 8 );
					break;
				case 'profile-orders-summary':
					$this->render_runtime_card_skeleton( 6 );
					break;
				case 'profile-orders-table':
					$this->render_runtime_table_skeleton( 'Pedidos y abonos', 'Cargando operativa comercial del perfil.', 6, 5 );
					break;
				case 'profile-payments':
					$this->render_runtime_table_skeleton( 'Abonos y pagos', 'Cargando movimientos financieros del perfil.', 6, 6 );
					break;
				case 'profile-payroll':
					$this->render_runtime_panel_skeleton( 'Empleado', 'Cargando ficha laboral y nomina del perfil.' );
					break;
				case 'profile-history':
					$this->render_runtime_panel_skeleton( 'Historico', 'Cargando historico y trazabilidad del perfil.' );
					break;
				case 'profile-account-state':
				default:
					$this->render_runtime_panel_skeleton( 'Saldo a favor y pagos', 'Cargando saldo a favor, pagos al perfil y movimientos auxiliares.' );
					break;
			}
			?>
		</div>
		<?php
	}

	public function render_contacts_page() {
		$repository          = new ContactsRepository();
		$fiscal_context      = $this->current_fiscal_context();
		$profile_search      = isset( $_GET['profile_search'] ) ? sanitize_text_field( wp_unslash( $_GET['profile_search'] ) ) : '';
		$selected_contact_id = isset( $_GET['contact_id'] ) ? absint( wp_unslash( $_GET['contact_id'] ) ) : 0;
		$selected_contact    = $selected_contact_id > 0 ? $repository->find( $selected_contact_id ) : null;
		$range_from          = isset( $_GET['range_from'] ) ? sanitize_text_field( wp_unslash( $_GET['range_from'] ) ) : ( $fiscal_context['start_date'] ?? '' );
		$range_to            = isset( $_GET['range_to'] ) ? sanitize_text_field( wp_unslash( $_GET['range_to'] ) ) : ( $fiscal_context['end_date'] ?? '' );
		$order_limit         = isset( $_GET['order_limit'] ) ? absint( wp_unslash( $_GET['order_limit'] ) ) : 25;

		$this->render_page_open(
			'Perfiles y terceros',
			'Usa usuarios de WordPress como base para clientes, empleados o proveedores, y deja terceros externos solo cuando realmente no exista un usuario.'
		);
		?>
		<?php if ( ! empty( $selected_contact ) ) : ?>
			<div class="asdl-fin-contact-runtime-layout">
				<?php
				$this->render_contact_runtime_container( 'profile-header-summary', $selected_contact_id, $range_from, $range_to, $order_limit, 'high', 'No se pudo cargar el encabezado del perfil.' );
				$this->render_contact_runtime_container( 'profile-financial-cards', $selected_contact_id, $range_from, $range_to, $order_limit, 'high', 'No se pudieron cargar las tarjetas financieras del perfil.', true );
				if ( empty( $selected_contact['is_supplier'] ) || ! empty( $selected_contact['is_customer'] ) || ! empty( $selected_contact['is_employee'] ) ) {
					$this->render_contact_runtime_container( 'profile-orders-summary', $selected_contact_id, $range_from, $range_to, $order_limit, 'high', 'No se pudo cargar el resumen de pedidos del perfil.', true );
					$this->render_contact_runtime_container( 'profile-orders-table', $selected_contact_id, $range_from, $range_to, $order_limit, 'normal', 'No se pudo cargar la operativa de pedidos del perfil.', true );
				}
				$this->render_contact_runtime_container( 'profile-account-state', $selected_contact_id, $range_from, $range_to, $order_limit, 'normal', 'No se pudo cargar el saldo a favor, pagos al perfil y movimientos auxiliares.', true );
				$this->render_contact_runtime_container( 'profile-payments', $selected_contact_id, $range_from, $range_to, $order_limit, 'low', 'No se pudo cargar los pagos del perfil.', true );
				$this->render_contact_runtime_container( 'profile-history', $selected_contact_id, $range_from, $range_to, $order_limit, 'low', 'No se pudo cargar el historico del perfil.', true );
				if ( ! empty( $selected_contact['is_employee'] ) ) {
					$this->render_contact_runtime_container( 'profile-payroll', $selected_contact_id, $range_from, $range_to, $order_limit, 'low', 'No se pudo cargar la seccion laboral del perfil.' );
				}
				?>
			</div>
		<?php endif; ?>

		<div class="asdl-fin-panel-grid">
			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Vincular usuario existente</h2>
					<p>Usa esta opcion para crear o actualizar el perfil financiero de un usuario de WordPress ya registrado como cliente, empleado o proveedor.</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
					<input type="hidden" name="action" value="asdl_fin_link_wp_profile" />
					<input type="hidden" name="return_page" value="asdl-fin-contacts" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php wp_nonce_field( 'asdl_fin_link_wp_profile' ); ?>
					<?php $this->render_wp_user_picker( 'wp_user_id', 'Usuario WordPress', 0, true, array( 'placeholder' => 'Busca por nombre, correo, login o ID', 'help' => 'Escribe al menos 2 caracteres para buscar rapido el usuario que quieres vincular.' ) ); ?>
					<label class="asdl-fin-field asdl-fin-checkbox-field">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="is_customer" value="1" checked="checked" />
							<strong>Cliente</strong>
						</span>
						<small>Activalo para clientes de tienda o usuarios que deban aparecer como perfil comercial.</small>
					</label>
					<label class="asdl-fin-field asdl-fin-checkbox-field">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="is_employee" value="1" />
							<strong>Empleado</strong>
						</span>
						<small>Activalo si este usuario tambien debe entrar luego en sueldo, nomina o adelantos.</small>
					</label>
					<label class="asdl-fin-field asdl-fin-checkbox-field">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="is_supplier" value="1" data-supplier-kind-toggle="1" />
							<strong>Proveedor / tercero</strong>
						</span>
						<small>Activalo si este usuario tambien debe operar como proveedor o contraparte por pagar.</small>
					</label>
					<label class="asdl-fin-field asdl-fin-checkbox-field">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="internal_use_profile" value="1" />
							<strong>Perfil interno de consumo / regalos</strong>
						</span>
						<small>Usalo para perfiles internos que la tienda ocupa para gastos propios, consumibles o regalos facturados operativamente.</small>
					</label>
					<?php $this->render_select( 'supplier_kind', 'Tipo de proveedor', $this->supplier_kind_options(), false, '', 'general', array( 'data-supplier-kind-select' => '1', 'disabled' => 'disabled' ) ); ?>
					<label class="asdl-fin-field asdl-fin-field-wide" data-supplier-kind-note hidden>
						<span>Criterio</span>
						<div class="asdl-fin-note-box">Si marcas proveedor, clasificalo como servicios, productos o mixto. Si no opera como proveedor, este dato se ignora.</div>
					</label>
					<?php submit_button( 'Vincular perfil', 'primary', 'submit', false ); ?>
				</form>
			</section>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Nuevo contacto externo</h2>
					<p>Reservado para proveedores, servicios externos y terceros que todavia no necesitan un usuario en WordPress.</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
					<input type="hidden" name="action" value="asdl_fin_save_contact" />
					<input type="hidden" name="return_page" value="asdl-fin-contacts" />
					<input type="hidden" name="profile_origin" value="external" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php wp_nonce_field( 'asdl_fin_save_contact' ); ?>
					<?php $this->render_input( 'display_name', 'Nombre principal', 'text', '', true ); ?>
					<?php $this->render_input( 'legal_name', 'Nombre legal u observacion', 'text', '' ); ?>
					<?php $this->render_input( 'email', 'Correo', 'email', '' ); ?>
					<?php $this->render_input( 'phone', 'Telefono', 'text', '' ); ?>
					<?php $this->render_input( 'document_id', 'Documento o referencia', 'text', '' ); ?>
					<?php $this->render_input( 'payment_terms', 'Condiciones de pago', 'text', '' ); ?>
					<label class="asdl-fin-field asdl-fin-checkbox-field">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="is_supplier" value="1" checked="checked" data-supplier-kind-toggle="1" />
							<strong>Proveedor / tercero</strong>
						</span>
						<small>Activalo si este tercero debe operar por pagar o como base de servicios/compras.</small>
					</label>
					<label class="asdl-fin-field asdl-fin-checkbox-field">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="internal_use_profile" value="1" />
							<strong>Perfil interno de consumo / regalos</strong>
						</span>
						<small>Marcado para perfiles internos usados por caja cuando la tienda asume pedidos como gasto o regalo.</small>
					</label>
					<?php $this->render_select( 'supplier_kind', 'Tipo de proveedor', $this->supplier_kind_options(), false, '', 'general', array( 'data-supplier-kind-select' => '1' ) ); ?>
					<?php $this->render_select( 'status', 'Estado', array( 'active' => 'Activo', 'inactive' => 'Inactivo' ) ); ?>
					<?php $this->render_textarea( 'notes', 'Notas', 'Ejemplo: proveedor principal, servicio recurrente o tercero ocasional.' ); ?>
					<?php submit_button( 'Guardar tercero externo', 'primary', 'submit', false ); ?>
				</form>
			</section>
		</div>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Perfiles y terceros registrados</h2>
				<p>Vista unificada de usuarios enlazados, proveedores y terceros externos convertibles cuando haga falta.</p>
			</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="asdl-fin-toolbar" data-contact-search-form data-contact-search-limit="100">
				<input type="hidden" name="page" value="asdl-fin-contacts" />
				<?php $this->render_current_fiscal_hidden_input(); ?>
				<label class="asdl-fin-toolbar-search">
					<span>Buscar perfil o tercero</span>
					<input
						type="search"
						name="profile_search"
						value="<?php echo esc_attr( $profile_search ); ?>"
						placeholder="Nombre, correo, telefono, documento o ID"
						autocomplete="off"
						data-contact-search-input
					/>
				</label>
				<div class="asdl-fin-inline-actions">
					<?php submit_button( 'Buscar', 'secondary', '', false ); ?>
					<?php if ( '' !== $profile_search ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( $this->with_current_context_url( admin_url( 'admin.php?page=asdl-fin-contacts' ) ) ); ?>">Limpiar</a>
					<?php endif; ?>
				</div>
			</form>
			<div class="asdl-fin-toolbar-meta" data-contact-search-meta <?php echo '' !== $profile_search ? '' : 'hidden'; ?>>
				<?php if ( '' !== $profile_search ) : ?>
					<span>Cargando resultados para "<?php echo esc_html( $profile_search ); ?>"...</span>
				<?php endif; ?>
			</div>
			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="<?php echo ! empty( $selected_contact ) ? 'normal' : 'high'; ?>"
				data-runtime-group="contacts-list"
				data-runtime-title="No se pudo cargar la tabla de perfiles y terceros."
				data-runtime-param-page-key="contacts"
				data-runtime-param-section-key="contacts-table"
				data-runtime-param-profile-search="<?php echo esc_attr( $profile_search ); ?>"
			>
				<?php $this->render_runtime_table_skeleton( 'Perfiles y terceros registrados', 'Cargando la tabla de perfiles y terceros...', 6, 8 ); ?>
			</div>
		</section>
		<?php
		$this->render_page_close();
	}

	public function render_documents_page() {
		$accounts_repository  = new AccountsRepository();
		$contacts_repository  = new ContactsRepository();
		$files_repository     = new DocumentFilesRepository();
		$documents_repository = new DocumentsRepository();
		$source_links         = new SourceLinksRepository();
		$readonly_context     = $this->is_fiscal_readonly_context();
		$selected_document_id = isset( $_GET['document_id'] ) ? absint( wp_unslash( $_GET['document_id'] ) ) : 0;
		$selected_document    = $selected_document_id > 0 ? $documents_repository->find( $selected_document_id ) : null;
		$selected_links       = ! empty( $selected_document ) ? $source_links->find_for_document( $selected_document_id ) : array();
		$selected_files       = ! empty( $selected_document ) ? $files_repository->for_document( $selected_document_id ) : array();

		$this->render_page_open(
			'Movimientos',
			'El movimiento financiero es la entidad central del sistema. Desde aqui nace la clasificacion, el saldo y la lectura de reportes.'
		);
		?>
		<?php if ( ! empty( $selected_document ) ) : ?>
			<?php $this->render_document_detail( $selected_document, $selected_links, $selected_files, $accounts_repository->options(), $contacts_repository->options() ); ?>
		<?php endif; ?>

		<?php if ( $readonly_context ) : ?>
			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Registro de movimientos</h2>
					<p>La alta de gastos y movimientos manuales queda disponible solo en el ejercicio activo. Los gastos y servicios ahora se gestionan desde sus módulos propios.</p>
				</div>
				<?php $this->render_fiscal_readonly_action_state(); ?>
			</section>
		<?php else : ?>
			<div class="asdl-fin-documents-primary-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Gastos de empresa</h2>
						<p>Los gastos externos ya se operan desde su propio módulo para no mezclarlos con ajustes, préstamos ni documentos manuales.</p>
					</div>
					<div class="asdl-fin-empty">
						<strong>Usa el módulo Gastos para registrar, buscar, revisar y anular gastos normales de empresa.</strong>
						<p>Ahí tendrás resumen propio, filtros planos, contraparte opcional, pagos aplicados, historial del documento y anulación operativa.</p>
						<a class="button button-primary" href="<?php echo esc_url( $this->with_current_context_url( admin_url( 'admin.php?page=asdl-fin-expenses' ) ) ); ?>">Abrir módulo de gastos</a>
					</div>
				</section>

				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Movimiento avanzado</h2>
						<p>Usa este formulario cuando necesites un control más detallado sobre ajustes, préstamos o documentos manuales técnicos.</p>
					</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-documents-advanced-form" enctype="multipart/form-data">
					<input type="hidden" name="action" value="asdl_fin_save_document" />
					<input type="hidden" name="return_page" value="asdl-fin-documents" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php wp_nonce_field( 'asdl_fin_save_document' ); ?>
					<?php $this->render_input( 'title', 'Titulo o concepto', 'text', '', true ); ?>
					<?php $this->render_select( 'document_type', 'Tipo de movimiento', array( 'adjustment' => 'Ajuste', 'loan_receivable' => 'Prestamo por cobrar', 'loan_payable' => 'Prestamo por pagar', 'manual_document' => 'Movimiento manual' ) ); ?>
					<?php $this->render_select( 'source_type', 'Origen', array( 'manual' => 'Manual', 'external' => 'Externo' ) ); ?>
					<?php $this->render_select( 'account_id', 'Cuenta', $accounts_repository->options(), false, 'Selecciona una cuenta' ); ?>
					<?php $this->render_contact_picker( 'contact_id', 'Perfil', 0, false, array( 'placeholder' => 'Busca un perfil', 'help' => 'Opcional. Selecciona un perfil para vincular este movimiento avanzado.', 'fallback_options' => $contacts_repository->options(), 'fallback_placeholder' => 'Selecciona un perfil' ) ); ?>
					<?php $this->render_input( 'issue_date', 'Fecha', 'date', gmdate( 'Y-m-d' ) ); ?>
					<?php $this->render_input( 'due_date', 'Vencimiento', 'date', '' ); ?>
					<?php $this->render_input( 'total', 'Monto total', 'number', '0', true, array( 'step' => '0.01', 'min' => '0' ) ); ?>
					<?php $this->render_input( 'paid_total', 'Monto abonado', 'number', '0', false, array( 'step' => '0.01', 'min' => '0' ) ); ?>
					<?php $this->render_select( 'financial_status', 'Estado del movimiento', array( 'draft' => 'Borrador', 'posted' => 'Emitido', 'void' => 'Anulado' ) ); ?>
					<?php $this->render_select( 'payment_status', 'Estado de pago', array( 'pending' => 'Pendiente', 'partial' => 'Abonado', 'paid' => 'Pagado', 'overdue' => 'Vencido' ) ); ?>
					<label class="asdl-fin-field asdl-fin-field-wide asdl-fin-checkbox-field">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="manual_override" value="1" />
							<strong>Usar clasificacion manual</strong>
						</span>
						<small>Si no activas esta opcion, el motor de reglas y el fallback del sistema decidiran la clasificacion.</small>
					</label>
					<?php $this->render_select( 'financial_intent', 'Intencion financiera', array( 'income' => 'Ingreso', 'expense' => 'Egreso', 'adjustment' => 'Ajuste', 'internal_consumption' => 'Consumo interno', 'loan' => 'Prestamo', 'neutral' => 'Neutral' ) ); ?>
					<?php $this->render_select( 'balance_nature', 'Naturaleza del saldo', array( 'receivable' => 'Por cobrar', 'payable' => 'Por pagar', 'neutral' => 'Neutro' ) ); ?>
					<?php $this->render_input( 'external_reference', 'Referencia externa', 'text', '' ); ?>
					<?php $this->render_file_input( 'document_file', 'Archivo o comprobante', 'Adjunta un archivo si este movimiento manual tiene soporte documental.' ); ?>
					<?php $this->render_textarea( 'notes', 'Notas', 'Comprobante, observaciones o comentario operativo.' ); ?>
					<div class="asdl-fin-field asdl-fin-field-wide">
						<span>Accion</span>
						<div class="asdl-fin-inline-actions">
							<?php submit_button( 'Guardar movimiento', 'primary', 'submit', false ); ?>
						</div>
					</div>
				</form>
				</section>
			</div>
		<?php endif; ?>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Movimientos registrados</h2>
				<p>Listado técnico del core financiero con ajustes, préstamos y documentos manuales. Los gastos y servicios se leen desde sus módulos propios.</p>
			</div>
			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="high"
				data-runtime-group="documents"
				data-runtime-title="No se pudo cargar la tabla de movimientos."
				data-runtime-param-page-key="documents"
				data-runtime-param-section-key="documents-table"
			>
				<?php $this->render_runtime_table_skeleton( 'Movimientos registrados', 'Cargando la tabla operativa de movimientos...', 6, 8 ); ?>
			</div>
		</section>
		<?php
		$this->render_page_close();
	}

	public function render_payments_page() {
		$accounts_repository   = new AccountsRepository();
		$contacts_repository   = new ContactsRepository();
		$documents_repository  = new DocumentsRepository();
		$payments_repository   = new PaymentsRepository();
		$allocations_repository = new PaymentAllocationsRepository();
		$readonly_context      = $this->is_fiscal_readonly_context();

		$this->render_page_open(
			'Cobros y pagos',
			'Registra cobros, pagos y abonos desde una base comun que luego servira para asignaciones y conciliacion.'
		);
		?>
		<?php if ( $readonly_context ) : ?>
			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Registro operativo bloqueado</h2>
					<p>Los cobros, pagos y asignaciones quedan solo en lectura mientras consultas un ejercicio historico.</p>
				</div>
				<?php $this->render_fiscal_readonly_action_state(); ?>
			</section>
		<?php else : ?>
			<div class="asdl-fin-payments-layout">
				<section class="asdl-fin-panel asdl-fin-payments-entry-panel">
					<div class="asdl-fin-panel-header">
						<h2>Nuevo cobro o pago</h2>
						<p>Registra cobros, pagos y abonos que luego puedes asignar a movimientos o usar en flujos como pedidos pendientes y nomina.</p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-payments-entry-form">
						<input type="hidden" name="action" value="asdl_fin_save_payment" />
						<input type="hidden" name="return_page" value="asdl-fin-payments" />
						<?php $this->render_current_fiscal_hidden_input(); ?>
						<?php wp_nonce_field( 'asdl_fin_save_payment' ); ?>
						<?php $this->render_select( 'payment_type', 'Tipo de movimiento', array( 'collection' => 'Cobro', 'disbursement' => 'Pago', 'adjustment' => 'Ajuste' ) ); ?>
						<div class="asdl-fin-field asdl-fin-field-wide">
							<small><strong>Ajuste</strong> se usa solo para regularizaciones internas o aplicaciones sin entrada/salida real de caja o banco, por ejemplo descuentos desde nomina o correcciones operativas.</small>
						</div>
						<?php $this->render_contact_picker( 'contact_id', 'Perfil', 0, false, array( 'placeholder' => 'Busca un perfil', 'help' => 'Opcional. Vincula este cobro o pago a un perfil existente.', 'fallback_options' => $contacts_repository->options(), 'fallback_placeholder' => 'Selecciona un perfil' ) ); ?>
						<?php $this->render_select( 'account_id', 'Cuenta', $accounts_repository->options(), false, 'Selecciona una cuenta' ); ?>
						<?php $this->render_input( 'payment_date', 'Fecha', 'date', gmdate( 'Y-m-d' ), true ); ?>
						<?php $this->render_input( 'total', 'Monto', 'number', '0', true, array( 'step' => '0.01', 'min' => '0' ) ); ?>
						<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
						<?php $this->render_select( 'status', 'Estado', array( 'posted' => 'Registrado', 'draft' => 'Borrador', 'void' => 'Anulado' ) ); ?>
						<?php $this->render_payment_method_select( 'method_key', 'Metodo', '', true ); ?>
						<?php $this->render_input( 'reference', 'Referencia', 'text', '' ); ?>
						<?php $this->render_textarea( 'notes', 'Notas', 'Ejemplo: transferencia, efectivo, zelle, ajuste interno.' ); ?>
						<div class="asdl-fin-field asdl-fin-field-wide">
							<span>Accion</span>
							<div class="asdl-fin-inline-actions">
								<?php submit_button( 'Guardar movimiento', 'primary', 'submit', false ); ?>
							</div>
						</div>
					</form>
				</section>

				<section class="asdl-fin-panel asdl-fin-payments-allocation-panel">
					<div class="asdl-fin-panel-header">
						<h2>Asignar abono a movimiento</h2>
						<p>Esta operacion descuenta el monto disponible del pago y actualiza el saldo pendiente del movimiento en una sola accion.</p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-payments-allocation-form">
						<input type="hidden" name="action" value="asdl_fin_allocate_payment" />
						<input type="hidden" name="return_page" value="asdl-fin-payments" />
						<?php $this->render_current_fiscal_hidden_input(); ?>
						<?php wp_nonce_field( 'asdl_fin_allocate_payment' ); ?>
						<?php $this->render_select( 'payment_id', 'Pago o abono', $payments_repository->available_options(), true, 'Selecciona un pago con saldo disponible' ); ?>
						<?php $this->render_select( 'document_id', 'Movimiento', $documents_repository->open_options(), true, 'Selecciona un movimiento con saldo pendiente' ); ?>
						<?php $this->render_input( 'amount', 'Monto a aplicar', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01' ) ); ?>
						<?php $this->render_textarea( 'notes', 'Notas', 'Opcional: observacion interna sobre esta asignacion.' ); ?>
						<div class="asdl-fin-field asdl-fin-field-wide">
							<span>Accion</span>
							<div class="asdl-fin-inline-actions">
								<?php submit_button( 'Aplicar asignacion', 'secondary', 'submit', false ); ?>
							</div>
						</div>
					</form>
				</section>
			</div>
		<?php endif; ?>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Cobros y pagos registrados</h2>
				<p>Lista base de cobros, pagos y abonos registrados, con perfil relacionado y monto disponible para futuras asignaciones.</p>
			</div>
			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="high"
				data-runtime-group="payments"
				data-runtime-title="No se pudo cargar la tabla de cobros y pagos."
				data-runtime-param-page-key="payments"
				data-runtime-param-section-key="payments-table"
			>
				<?php $this->render_runtime_table_skeleton( 'Cobros y pagos registrados', 'Cargando pagos, cobros y abonos...', 7, 8 ); ?>
			</div>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Asignaciones recientes</h2>
				<p>Historico basico de abonos aplicados a movimientos financieros.</p>
			</div>
			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="normal"
				data-runtime-group="payments"
				data-runtime-title="No se pudo cargar las asignaciones recientes."
				data-runtime-param-page-key="payments"
				data-runtime-param-section-key="allocations-table"
			>
				<?php $this->render_runtime_table_skeleton( 'Asignaciones recientes', 'Cargando el historico basico de asignaciones...', 5, 6 ); ?>
			</div>
		</section>
		<?php
		$this->render_page_close();
	}

	public function render_payroll_page() {
		$fiscal_context    = $this->current_fiscal_context();
		$filters          = array(
			'from_date' => isset( $_GET['from_date'] ) ? sanitize_text_field( wp_unslash( $_GET['from_date'] ) ) : ( $fiscal_context['start_date'] ?? '' ),
			'to_date'   => isset( $_GET['to_date'] ) ? sanitize_text_field( wp_unslash( $_GET['to_date'] ) ) : ( $fiscal_context['end_date'] ?? '' ),
			'limit'     => isset( $_GET['limit'] ) ? absint( wp_unslash( $_GET['limit'] ) ) : 80,
		);

		$this->render_page_open(
			'Nomina',
			'Vista general para detectar empleados por pagar, generar periodos y procesar nomina sin entrar perfil por perfil.'
		);
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Filtro de nomina</h2>
				<p>Controla el rango operativo que quieres revisar para pagos semanales, quincenales o mensuales.</p>
			</div>
			<form method="get" class="asdl-fin-form-grid">
				<input type="hidden" name="page" value="asdl-fin-payroll" />
				<?php $this->render_current_fiscal_hidden_input(); ?>
				<?php $this->render_input( 'from_date', 'Desde', 'date', $filters['from_date'] ?: gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ); ?>
				<?php $this->render_input( 'to_date', 'Hasta', 'date', $filters['to_date'] ?: gmdate( 'Y-m-d', strtotime( '+14 days' ) ) ); ?>
				<?php $this->render_input( 'limit', 'Limite', 'number', (string) ( $filters['limit'] ?: 80 ), false, array( 'min' => '10', 'max' => '200', 'step' => '1' ) ); ?>
				<div class="asdl-fin-field">
					<span>Accion</span>
					<div class="asdl-fin-inline-actions">
						<?php submit_button( 'Aplicar filtro', 'secondary', '', false ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( $this->with_current_context_url( admin_url( 'admin.php?page=asdl-fin-payroll' ) ) ); ?>">Limpiar</a>
					</div>
				</div>
			</form>
		</section>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="high"
			data-runtime-group="payroll"
			data-runtime-title="No se pudo cargar el resumen de nomina."
			data-runtime-param-page-key="payroll"
			data-runtime-param-section-key="payroll-summary"
			data-runtime-param-from-date="<?php echo esc_attr( $filters['from_date'] ); ?>"
			data-runtime-param-to-date="<?php echo esc_attr( $filters['to_date'] ); ?>"
			data-runtime-param-limit="<?php echo esc_attr( (string) $filters['limit'] ); ?>"
		>
			<?php $this->render_runtime_card_skeleton( 9 ); ?>
		</div>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="high"
			data-runtime-group="payroll"
			data-runtime-title="No se pudo cargar la cola de nomina."
			data-runtime-param-page-key="payroll"
			data-runtime-param-section-key="payroll-queue"
			data-runtime-param-from-date="<?php echo esc_attr( $filters['from_date'] ); ?>"
			data-runtime-param-to-date="<?php echo esc_attr( $filters['to_date'] ); ?>"
			data-runtime-param-limit="<?php echo esc_attr( (string) $filters['limit'] ); ?>"
		>
			<?php $this->render_runtime_table_skeleton( 'Cola general de nomina', 'Cargando periodos y pagos pendientes...', 8, 7 ); ?>
		</div>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="normal"
			data-runtime-group="payroll"
			data-runtime-title="No se pudo cargar el directorio de empleados."
			data-runtime-param-page-key="payroll"
			data-runtime-param-section-key="payroll-directory"
			data-runtime-param-from-date="<?php echo esc_attr( $filters['from_date'] ); ?>"
			data-runtime-param-to-date="<?php echo esc_attr( $filters['to_date'] ); ?>"
			data-runtime-param-limit="<?php echo esc_attr( (string) $filters['limit'] ); ?>"
		>
			<?php $this->render_runtime_table_skeleton( 'Empleados registrados', 'Cargando el directorio corto de empleados...', 7, 7 ); ?>
		</div>
		<?php
		$this->render_page_close();
	}

	public function render_installments_page() {
		$contacts_repository    = new ContactsRepository();
		$documents_repository   = new DocumentsRepository();
		$plans_repository       = new InstallmentPlansRepository();
		$readonly_context       = $this->is_fiscal_readonly_context();

		$this->render_page_open(
			'Compromisos',
			'Gestion general de prestamos, deudas acordadas y compromisos de pago. La ruta preferida sigue siendo operar cada caso desde su perfil.'
		);
		?>
		<div class="asdl-fin-panel-grid">
			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Nuevo compromiso</h2>
					<p>Registra compromisos por cobrar o por pagar y define como se iran gestionando en cuotas o pagos directos.</p>
				</div>
				<?php if ( $readonly_context ) : ?>
					<?php $this->render_fiscal_readonly_action_state(); ?>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-commitment-form" data-commitment-form data-is-employee="0" data-employee-frequency="" data-employee-next-payment="" data-employee-currency="USD" data-allow-unknown-payroll="0" data-employee-payroll-ready="0" data-has-profile-context="0" data-store-debt-total="0" data-store-debt-count="0" data-company-debt-total="0">
						<input type="hidden" name="action" value="asdl_fin_save_installment_plan" />
						<input type="hidden" name="return_page" value="asdl-fin-installments" />
						<input type="hidden" name="target_installment_amount" value="" data-commitment-target-installment />
						<input type="hidden" name="installment_count" value="" data-commitment-installment-count />
						<input type="hidden" name="status" value="active" />
						<?php $this->render_current_fiscal_hidden_input(); ?>
						<?php wp_nonce_field( 'asdl_fin_save_installment_plan' ); ?>
						<?php $this->render_input( 'title', 'Nombre del compromiso', 'text', '', true ); ?>
						<?php $this->render_select( 'settlement_direction', 'Sentido', array( 'receivable' => 'El perfil paga a la empresa', 'payable' => 'La empresa paga al perfil' ), false, '', '', array( 'data-commitment-direction' => '1' ) ); ?>
						<?php $this->render_select( 'commitment_origin', 'Origen', array( 'loan' => 'Prestamo', 'store_debt' => 'Deuda de tienda', 'manual_charge' => 'Cargo manual', 'company_debt' => 'Deuda de la empresa' ), false, '', '', array( 'data-commitment-origin' => '1' ) ); ?>
						<label class="asdl-fin-field">
							<span>Forma de gestion</span>
							<select name="collection_mode" data-commitment-collection-mode>
								<?php echo $this->render_select_options( $this->commitment_collection_mode_options( false, false ), 'manual' ); ?>
							</select>
							<small data-commitment-mode-help>Para compromisos por nomina, abre el perfil del empleado y configuralo desde ahi con su ficha laboral completa.</small>
						</label>
						<?php $this->render_contact_picker( 'contact_id', 'Perfil', 0, false, array( 'placeholder' => 'Busca un perfil', 'help' => 'Opcional. Si eliges un perfil, el compromiso quedara ligado a ese contexto.', 'fallback_options' => $contacts_repository->options(), 'fallback_placeholder' => 'Selecciona un perfil' ) ); ?>
						<?php $this->render_select( 'document_id', 'Documento relacionado', $this->build_document_options( $documents_repository->all( 200 ) ), false, 'Opcional' ); ?>
						<?php $this->render_input( 'principal_amount', 'Monto comprometido', 'number', '', true, array( 'step' => '0.01', 'min' => '0.01', 'data-commitment-principal' => '1' ) ); ?>
						<div class="asdl-fin-field asdl-fin-field-wide asdl-fin-context-hint" data-commitment-origin-summary hidden>
							<span>Base detectada</span>
							<small data-commitment-origin-summary-text>Selecciona un origen para que el formulario te indique si puede sugerir un monto base.</small>
						</div>
						<label class="asdl-fin-field asdl-fin-field-wide asdl-fin-checkbox-field">
							<span class="asdl-fin-checkbox-row">
								<input type="checkbox" data-commitment-total-toggle />
								<strong>Usar un monto final distinto</strong>
							</span>
							<small>Activalo solo si el total que quieres recuperar o pagar no coincide con el monto comprometido base.</small>
						</label>
						<label class="asdl-fin-field" data-commitment-total-field hidden>
							<span>Monto total final</span>
							<input type="number" name="total_amount" value="" min="0" step="0.01" placeholder="Opcional si el total final es distinto" data-commitment-total />
							<small>Si lo dejas vacio, el sistema usara el mismo monto comprometido como total final.</small>
						</label>
						<?php $this->render_select( 'planning_mode', 'Como quieres planificarlo', $this->commitment_planning_mode_options(), true, '', 'period_amount', array( 'data-commitment-planning-mode' => '1' ) ); ?>
						<label class="asdl-fin-field" data-commitment-planning-field>
							<span data-commitment-planning-label>Monto por periodo *</span>
							<input type="number" name="planning_value" value="" min="0" step="0.01" required data-commitment-planning-value />
							<small data-commitment-planning-help>Indica cuanto quieres cobrar o pagar por cada periodo para que el sistema calcule automaticamente el calendario.</small>
						</label>
						<label class="asdl-fin-field" data-commitment-frequency-field>
							<span>Frecuencia</span>
							<select name="frequency_key" data-commitment-frequency>
								<?php echo $this->render_select_options( $this->commitment_frequency_options(), 'monthly' ); ?>
							</select>
							<small data-commitment-frequency-help>Para compromisos por nomina de empleados, esta frecuencia se ajustara automaticamente a la ficha laboral.</small>
						</label>
						<label class="asdl-fin-field" data-commitment-start-field>
							<span>Fecha inicial</span>
							<input type="date" name="start_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required data-commitment-start-date />
							<small>Se usa como primera aplicacion del compromiso. En perfiles empleados puede alinearse automaticamente con la proxima nomina.</small>
						</label>
						<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true, 'Selecciona una moneda', array( 'data-commitment-currency' => '1' ) ); ?>
						<div class="asdl-fin-field asdl-fin-field-wide">
							<span>Proyeccion automatica</span>
							<div class="asdl-fin-automation-projection asdl-fin-commitment-projection" data-commitment-projection>
								<div><strong>Frecuencia aplicada</strong><span data-projection-frequency>Mensual</span></div>
								<div><strong>Monto por periodo</strong><span data-projection-amount>—</span></div>
								<div><strong>Periodos estimados</strong><span data-projection-count>—</span></div>
								<div><strong>Proxima aplicacion</strong><span data-projection-start>—</span></div>
								<div><strong>Cierre estimado</strong><span data-projection-end>—</span></div>
								<div><strong>Tratamiento</strong><span data-projection-mode>Gestion manual</span></div>
							</div>
							<small data-commitment-projection-summary>Define el monto del acuerdo y deja que el sistema estime periodos, calendario e impacto operativo.</small>
						</div>
						<?php $this->render_textarea( 'notes', 'Notas', 'Puedes usar este campo para observaciones del acuerdo, del descuento por sueldo o del compromiso asumido.' ); ?>
						<?php submit_button( 'Guardar compromiso', 'primary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</section>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Compromisos recientes</h2>
					<p>Resumen global de prestamos y acuerdos activos del sistema.</p>
				</div>
				<div
					class="asdl-fin-runtime-container"
					data-runtime-action="asdl_fin_admin_runtime"
					data-runtime-nonce="adminRuntime"
					data-runtime-priority="high"
					data-runtime-group="installments"
					data-runtime-title="No se pudo cargar la tabla de compromisos."
					data-runtime-param-page-key="installments"
					data-runtime-param-section-key="installments-table"
				>
					<?php $this->render_runtime_table_skeleton( 'Compromisos recientes', 'Cargando la tabla global de compromisos...', 7, 6 ); ?>
				</div>
			</section>
		</div>
		<?php
		$this->render_page_close();
	}

	public function render_integrity_page() {
		$cases_repository = new IntegrityCasesRepository();
		$events_repository = new EventsRepository();
		$filters          = $this->integrity_page_filters();
		$listing          = $cases_repository->list_for_admin(
			array(
				'page'             => $filters['page_num'],
				'limit'            => 25,
				'search'           => $filters['search'],
				'contact_search'   => $filters['contact_search'],
				'case_type'        => $filters['case_type'],
				'severity'         => $filters['severity'],
				'status'           => $filters['status'],
				'contact_id'       => $filters['contact_id'],
				'external_order_id'=> $filters['order_id'],
				'batch_id'         => $filters['batch_id'],
				'range_from'       => $filters['range_from'],
				'range_to'         => $filters['range_to'],
			)
		);
		$summary          = $cases_repository->summarize(
			array(
				'search'           => $filters['search'],
				'contact_search'   => $filters['contact_search'],
				'case_type'        => $filters['case_type'],
				'severity'         => $filters['severity'],
				'status'           => $filters['status'],
				'contact_id'       => $filters['contact_id'],
				'external_order_id'=> $filters['order_id'],
				'batch_id'         => $filters['batch_id'],
				'range_from'       => $filters['range_from'],
				'range_to'         => $filters['range_to'],
			)
		);
		$selected_case    = $filters['case_id'] > 0 ? $cases_repository->find( $filters['case_id'] ) : null;
		$selected_events  = ! empty( $selected_case['id'] ) ? $events_repository->for_entity( 'integrity_case', (int) $selected_case['id'], 20 ) : array();
		$last_scan        = get_option( IntegrityMonitorModule::LAST_SCAN_OPTION, array() );

		$this->render_page_open(
			'Integridad financiera',
			'Monitor operativo para detectar inconsistencias financieras antes de que escalen. Esta primera fase solo detecta, registra, lista y permite gestion operativa del caso.'
		);
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Escaneo manual</h2>
				<p>Lanza un escaneo total o acotado por contacto, pedido o batch. No repara nada automaticamente; solo detecta y registra casos con evidencia.</p>
			</div>
			<div class="asdl-fin-card-grid">
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Ultimo escaneo</span>
					<strong><?php echo esc_html( ! empty( $last_scan['ran_at'] ) ? sanitize_text_field( (string) $last_scan['ran_at'] ) : 'Sin ejecutar todavia' ); ?></strong>
					<p>Detectados: <?php echo esc_html( number_format_i18n( (int) ( $last_scan['detected_count'] ?? 0 ) ) ); ?> · Nuevos: <?php echo esc_html( number_format_i18n( (int) ( $last_scan['created_count'] ?? 0 ) ) ); ?> · Reabiertos: <?php echo esc_html( number_format_i18n( (int) ( $last_scan['reopened_count'] ?? 0 ) ) ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Cobertura</span>
					<strong><?php echo esc_html( ! empty( $last_scan['scanned_types'] ) ? implode( ', ', array_map( array( 'ASDLabs\Finance\Finance\IntegrityMonitorService', 'case_type_label' ), (array) $last_scan['scanned_types'] ) ) : 'Todos los detectores' ); ?></strong>
					<p>Si acotas el escaneo, solo se revisan los tipos y entidades indicados en el formulario.</p>
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
				<input type="hidden" name="action" value="asdl_fin_integrity_scan" />
				<?php wp_nonce_field( 'asdl_fin_integrity_scan' ); ?>
				<?php $this->render_integrity_hidden_filter_inputs( $filters, $filters['case_id'] ); ?>
				<label class="asdl-fin-field">
					<span>Tipo de caso</span>
					<select name="case_type">
						<option value="">Todos</option>
						<?php echo $this->render_select_options( IntegrityMonitorService::case_type_options(), $filters['case_type'] ); ?>
					</select>
					<small>Dejalo en todos para barrer la primera ola completa de inconsistencias.</small>
				</label>
				<label class="asdl-fin-field">
					<span>Contacto</span>
					<input type="number" name="contact_id" min="0" step="1" value="<?php echo esc_attr( $filters['contact_id'] > 0 ? (string) $filters['contact_id'] : '' ); ?>" />
					<small>Opcional. Si lo indicas, el monitor concentra la busqueda en ese perfil.</small>
				</label>
				<label class="asdl-fin-field">
					<span>Pedido Woo/OpenPOS</span>
					<input type="number" name="order_id" min="0" step="1" value="<?php echo esc_attr( $filters['order_id'] > 0 ? (string) $filters['order_id'] : '' ); ?>" />
					<small>Usa el ID real del pedido, no solo el order number visible.</small>
				</label>
				<label class="asdl-fin-field">
					<span>Batch</span>
					<input type="number" name="batch_id" min="0" step="1" value="<?php echo esc_attr( $filters['batch_id'] > 0 ? (string) $filters['batch_id'] : '' ); ?>" />
					<small>Util para revisar un batch dual o de compensacion puntual.</small>
				</label>
				<?php $this->render_input( 'range_from', 'Desde', 'date', $filters['range_from'] ); ?>
				<?php $this->render_input( 'range_to', 'Hasta', 'date', $filters['range_to'] ); ?>
				<div class="asdl-fin-field asdl-fin-field-wide">
					<span>Accion</span>
					<div class="asdl-fin-inline-actions">
						<?php submit_button( 'Escanear ahora', 'primary', 'submit', false ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( $this->integrity_page_url() ); ?>">Limpiar contexto</a>
					</div>
					<small>Esta fase no corrige finanzas. Solo abre, actualiza o reabre casos de integridad.</small>
				</div>
			</form>
		</section>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Resumen operativo</h2>
				<p>Lectura actual del backlog de integridad con los filtros activos.</p>
			</div>
			<div class="asdl-fin-card-grid">
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Casos activos</span>
					<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['open_cases'] ?? 0 ) ) ); ?></strong>
					<p>Revisados: <?php echo esc_html( number_format_i18n( (int) ( $summary['reviewed_cases'] ?? 0 ) ) ); ?> · Ignorados: <?php echo esc_html( number_format_i18n( (int) ( $summary['ignored_cases'] ?? 0 ) ) ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Criticos activos</span>
					<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['active_high_cases'] ?? 0 ) ) ); ?></strong>
					<p>Media: <?php echo esc_html( number_format_i18n( (int) ( $summary['active_medium_cases'] ?? 0 ) ) ); ?> · Baja: <?php echo esc_html( number_format_i18n( (int) ( $summary['active_low_cases'] ?? 0 ) ) ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Monto comprometido</span>
					<strong><?php echo wp_kses_post( $this->format_money( (float) ( $summary['active_amount_total'] ?? 0 ) ) ); ?></strong>
					<p>Suma referencial de casos no resueltos dentro del filtro actual.</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Total historico</span>
					<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['total_cases'] ?? 0 ) ) ); ?></strong>
					<p>Resueltos: <?php echo esc_html( number_format_i18n( (int) ( $summary['resolved_cases'] ?? 0 ) ) ); ?> · Ultima deteccion: <?php echo esc_html( ! empty( $summary['last_detected_at'] ) ? sanitize_text_field( (string) $summary['last_detected_at'] ) : 'Sin fecha' ); ?></p>
				</div>
			</div>
		</section>

		<?php if ( ! empty( $selected_case['id'] ) ) : ?>
			<?php $this->render_integrity_case_detail( $selected_case, $selected_events, $filters ); ?>
		<?php endif; ?>

		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Casos detectados</h2>
				<p>Filtra por tipo, severidad, estado o entidad relacionada para concentrarte en el backlog que realmente importa.</p>
			</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="asdl-fin-form-grid">
				<input type="hidden" name="page" value="asdl-fin-integrity" />
				<?php $this->render_current_fiscal_hidden_input(); ?>
				<label class="asdl-fin-field asdl-fin-field-wide">
					<span>Buscar</span>
					<input type="search" name="integrity_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Tipo, resumen, perfil, order number o lote" />
					<small>Busca por resumen del caso, grupo operativo o identificadores visibles.</small>
				</label>
				<label class="asdl-fin-field">
					<span>Perfil</span>
					<input type="search" name="integrity_contact_search" value="<?php echo esc_attr( $filters['contact_search'] ); ?>" placeholder="Nombre o referencia del perfil" />
					<small>Filtro textual sobre el nombre visible del perfil.</small>
				</label>
				<label class="asdl-fin-field">
					<span>Tipo</span>
					<select name="integrity_case_type">
						<option value="">Todos</option>
						<?php echo $this->render_select_options( IntegrityMonitorService::case_type_options(), $filters['case_type'] ); ?>
					</select>
					<small>Acota la tabla al patrón operativo que quieres revisar.</small>
				</label>
				<label class="asdl-fin-field">
					<span>Severidad</span>
					<select name="integrity_severity">
						<option value="">Todas</option>
						<?php echo $this->render_select_options( IntegrityCasesRepository::severity_options(), $filters['severity'] ); ?>
					</select>
					<small>Alta, media o baja segun el choque detectado.</small>
				</label>
				<label class="asdl-fin-field">
					<span>Estado operativo</span>
					<select name="integrity_status">
						<option value="">Todos</option>
						<?php echo $this->render_select_options( IntegrityCasesRepository::status_options(), $filters['status'] ); ?>
					</select>
					<small>Abierto, revisado, ignorado o resuelto.</small>
				</label>
				<label class="asdl-fin-field">
					<span>Contacto ID</span>
					<input type="number" name="integrity_contact_id" min="0" step="1" value="<?php echo esc_attr( $filters['contact_id'] > 0 ? (string) $filters['contact_id'] : '' ); ?>" />
				</label>
				<label class="asdl-fin-field">
					<span>Pedido ID</span>
					<input type="number" name="integrity_order_id" min="0" step="1" value="<?php echo esc_attr( $filters['order_id'] > 0 ? (string) $filters['order_id'] : '' ); ?>" />
				</label>
				<label class="asdl-fin-field">
					<span>Batch ID</span>
					<input type="number" name="integrity_batch_id" min="0" step="1" value="<?php echo esc_attr( $filters['batch_id'] > 0 ? (string) $filters['batch_id'] : '' ); ?>" />
				</label>
				<?php $this->render_input( 'integrity_range_from', 'Desde deteccion', 'date', $filters['range_from'] ); ?>
				<?php $this->render_input( 'integrity_range_to', 'Hasta deteccion', 'date', $filters['range_to'] ); ?>
				<div class="asdl-fin-field asdl-fin-field-wide">
					<span>Accion</span>
					<div class="asdl-fin-inline-actions">
						<?php submit_button( 'Aplicar filtros', 'secondary', '', false ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( $this->integrity_page_url() ); ?>">Limpiar</a>
					</div>
				</div>
			</form>

			<div class="asdl-fin-table-wrap">
				<table class="widefat striped asdl-fin-table">
					<thead>
						<tr>
							<th>Tipo</th>
							<th>Severidad</th>
							<th>Perfil</th>
							<th>Pedido / documento / batch</th>
							<th>Monto</th>
							<th>Resumen</th>
							<th>Detectado</th>
							<th>Estado</th>
							<th>Gestion</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $listing['items'] ) ) : ?>
							<tr>
								<td colspan="9">
									<?php $this->render_empty_state( 'No se encontraron casos de integridad con esos filtros.', 'Ajusta la busqueda o lanza un escaneo manual si esperabas ver casos nuevos.' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( (array) $listing['items'] as $case ) : ?>
								<tr>
									<td>
										<div class="asdl-fin-stack">
											<strong><?php echo esc_html( IntegrityMonitorService::case_type_label( $case['case_type'] ?? '' ) ); ?></strong>
											<small><?php echo esc_html( sanitize_text_field( (string) ( $case['case_key'] ?? '' ) ) ); ?></small>
										</div>
									</td>
									<td><?php echo wp_kses_post( $this->render_pill( IntegrityMonitorService::severity_label( $case['severity'] ?? '' ), IntegrityMonitorService::severity_tone( $case['severity'] ?? '' ) ) ); ?></td>
									<td>
										<div class="asdl-fin-stack">
											<strong><?php echo esc_html( sanitize_text_field( (string) ( $case['contact_label'] ?? 'Sin perfil' ) ) ); ?></strong>
											<?php if ( ! empty( $case['contact_id'] ) ) : ?>
												<small>ID <?php echo esc_html( (string) (int) $case['contact_id'] ); ?></small>
											<?php else : ?>
												<small>Sin contacto vinculado</small>
											<?php endif; ?>
										</div>
									</td>
									<td>
										<div class="asdl-fin-stack">
											<?php if ( ! empty( $case['order_number'] ) || ! empty( $case['external_order_id'] ) ) : ?>
												<strong>Pedido <?php echo esc_html( ! empty( $case['order_number'] ) ? '#' . sanitize_text_field( (string) $case['order_number'] ) : '#' . (int) $case['external_order_id'] ); ?></strong>
											<?php endif; ?>
											<?php if ( ! empty( $case['document_id'] ) ) : ?>
												<small>Documento #<?php echo esc_html( (string) (int) $case['document_id'] ); ?></small>
											<?php endif; ?>
											<?php if ( ! empty( $case['batch_id'] ) ) : ?>
												<small>Batch #<?php echo esc_html( (string) (int) $case['batch_id'] ); ?></small>
											<?php endif; ?>
										</div>
									</td>
									<td><?php echo wp_kses_post( $this->format_money( (float) ( $case['amount'] ?? 0 ), $case['currency'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( sanitize_text_field( (string) ( $case['summary'] ?? '' ) ) ); ?></td>
									<td>
										<div class="asdl-fin-stack">
											<strong><?php echo esc_html( sanitize_text_field( (string) ( $case['last_detected_at'] ?? '' ) ) ); ?></strong>
											<small>Primera vez: <?php echo esc_html( sanitize_text_field( (string) ( $case['first_detected_at'] ?? '' ) ) ); ?></small>
										</div>
									</td>
									<td><?php echo wp_kses_post( $this->render_pill( IntegrityMonitorService::status_label( $case['status'] ?? '' ), $this->tone_for_status( $case['status'] ?? '' ) ) ); ?></td>
									<td><?php $this->render_integrity_case_row_actions( $case, $filters ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( ! empty( $listing['meta']['total_pages'] ) && (int) $listing['meta']['total_pages'] > 1 ) : ?>
				<div class="asdl-fin-inline-actions">
					<?php if ( (int) $listing['meta']['page'] > 1 ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( $this->integrity_page_url( array( 'integrity_page_num' => max( 1, (int) $listing['meta']['page'] - 1 ) ), $filters ) ); ?>">Anterior</a>
					<?php endif; ?>
					<span>Pagina <?php echo esc_html( number_format_i18n( (int) $listing['meta']['page'] ) ); ?> de <?php echo esc_html( number_format_i18n( (int) $listing['meta']['total_pages'] ) ); ?></span>
					<?php if ( (int) $listing['meta']['page'] < (int) $listing['meta']['total_pages'] ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( $this->integrity_page_url( array( 'integrity_page_num' => (int) $listing['meta']['page'] + 1 ), $filters ) ); ?>">Siguiente</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		$this->render_page_close();
	}

	private function render_integrity_case_detail( array $case, array $events, array $filters ) {
		$payload    = is_array( $case['payload'] ?? null ) ? $case['payload'] : array();
		$close_url  = remove_query_arg( 'case_id', $this->integrity_page_url( array(), $filters ) );
		$order_url  = $this->integrity_order_edit_url( (int) ( $case['external_order_id'] ?? 0 ) );
		$batch_url  = ! empty( $case['batch_id'] ) ? $this->integrity_page_url( array( 'integrity_batch_id' => (int) $case['batch_id'], 'case_id' => (int) $case['id'] ), $filters ) : '';
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Detalle del caso #<?php echo esc_html( (string) (int) $case['id'] ); ?></h2>
				<p><?php echo esc_html( sanitize_text_field( (string) ( $case['summary'] ?? '' ) ) ); ?></p>
			</div>
			<div class="asdl-fin-inline-actions">
				<a class="button button-secondary" href="<?php echo esc_url( $close_url ); ?>">Cerrar detalle</a>
				<?php $this->render_integrity_case_row_actions( $case, $filters, true ); ?>
			</div>
			<div class="asdl-fin-card-grid">
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Tipo</span>
					<strong><?php echo esc_html( IntegrityMonitorService::case_type_label( $case['case_type'] ?? '' ) ); ?></strong>
					<p>Clave: <?php echo esc_html( sanitize_text_field( (string) ( $case['case_key'] ?? '' ) ) ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Severidad</span>
					<strong><?php echo wp_kses_post( $this->render_pill( IntegrityMonitorService::severity_label( $case['severity'] ?? '' ), IntegrityMonitorService::severity_tone( $case['severity'] ?? '' ) ) ); ?></strong>
					<p>Estado actual: <?php echo wp_kses_post( $this->render_pill( IntegrityMonitorService::status_label( $case['status'] ?? '' ), $this->tone_for_status( $case['status'] ?? '' ) ) ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Monto comprometido</span>
					<strong><?php echo wp_kses_post( $this->format_money( (float) ( $case['amount'] ?? 0 ), $case['currency'] ?? '' ) ); ?></strong>
					<p>Primera deteccion: <?php echo esc_html( sanitize_text_field( (string) ( $case['first_detected_at'] ?? '' ) ) ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Ultimo escaneo</span>
					<strong><?php echo esc_html( sanitize_text_field( (string) ( $case['last_scanned_at'] ?? '' ) ) ); ?></strong>
					<p>Ultima deteccion: <?php echo esc_html( sanitize_text_field( (string) ( $case['last_detected_at'] ?? '' ) ) ); ?></p>
				</div>
			</div>
			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Entidades relacionadas</h2>
						<p>Enlaces directos para bajar al perfil, pedido, documento, comprobante o lote relacionado.</p>
					</div>
					<div class="asdl-fin-inline-actions">
						<?php if ( ! empty( $case['contact_id'] ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $this->contact_detail_url( (int) $case['contact_id'] ) ); ?>">Abrir perfil</a>
						<?php endif; ?>
						<?php if ( '' !== $order_url ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $order_url ); ?>">Abrir pedido</a>
						<?php endif; ?>
						<?php if ( ! empty( $case['document_id'] ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $this->document_detail_url( (int) $case['document_id'] ) ); ?>">Abrir documento</a>
						<?php endif; ?>
						<?php if ( ! empty( $case['payment_id'] ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $this->receipt_url( 'payment', (int) $case['payment_id'] ) ); ?>">Abrir pago</a>
						<?php endif; ?>
						<?php if ( '' !== $batch_url ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( $batch_url ); ?>">Filtrar por batch</a>
						<?php endif; ?>
					</div>
					<div class="asdl-fin-table-wrap">
						<table class="widefat striped asdl-fin-table">
							<tbody>
								<tr>
									<th>Perfil</th>
									<td><?php echo esc_html( sanitize_text_field( (string) ( $case['contact_label'] ?? 'Sin perfil' ) ) ); ?><?php if ( ! empty( $case['contact_id'] ) ) : ?> · ID <?php echo esc_html( (string) (int) $case['contact_id'] ); ?><?php endif; ?></td>
								</tr>
								<tr>
									<th>Pedido</th>
									<td><?php echo esc_html( ! empty( $case['order_number'] ) ? '#' . sanitize_text_field( (string) $case['order_number'] ) : ( ! empty( $case['external_order_id'] ) ? '#' . (int) $case['external_order_id'] : 'Sin pedido' ) ); ?></td>
								</tr>
								<tr>
									<th>Documento</th>
									<td><?php echo esc_html( ! empty( $case['document_id'] ) ? '#' . (string) (int) $case['document_id'] : 'Sin documento' ); ?></td>
								</tr>
								<tr>
									<th>Pago</th>
									<td><?php echo esc_html( ! empty( $case['payment_id'] ) ? '#' . (string) (int) $case['payment_id'] : 'Sin pago directo' ); ?></td>
								</tr>
								<tr>
									<th>Batch</th>
									<td><?php echo esc_html( ! empty( $case['batch_id'] ) ? '#' . (string) (int) $case['batch_id'] : 'Sin batch directo' ); ?></td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Contexto operativo</h2>
						<p>Motivo del caso y observaciones del operador.</p>
					</div>
					<div class="asdl-fin-empty">
						<strong><?php echo esc_html( sanitize_text_field( (string) ( $case['summary'] ?? '' ) ) ); ?></strong>
						<p><?php echo esc_html( '' !== (string) ( $case['status_note'] ?? '' ) ? sanitize_text_field( (string) $case['status_note'] ) : 'Todavia no hay nota operativa guardada para este caso.' ); ?></p>
					</div>
				</section>
			</div>

			<?php $this->render_integrity_evidence_table( 'Pagos disponibles detectados', (array) ( $payload['available_payments'] ?? array() ) ); ?>
			<?php $this->render_integrity_evidence_table( 'Pedidos abiertos detectados', (array) ( $payload['pending_orders'] ?? array() ) ); ?>
			<?php $this->render_integrity_evidence_table( 'Documentos abiertos detectados', (array) ( $payload['open_documents'] ?? array() ) ); ?>
			<?php $this->render_integrity_evidence_table( 'Items fallidos del batch', (array) ( $payload['errored_items'] ?? array() ) ); ?>

			<section class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Snapshot del criterio</h2>
						<p>Payload guardado al detectar el caso. Sirve para comparar el estado observado con el runtime actual.</p>
					</div>
					<pre><?php echo esc_html( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></pre>
				</section>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Bitacora del caso</h2>
						<p>Eventos auditables del escaneo, reescaneo y cambios de estado.</p>
					</div>
					<?php if ( empty( $events ) ) : ?>
						<?php $this->render_empty_state( 'Sin eventos registrados todavia.', 'El caso ya queda listo para auditarse en esta misma vista a medida que el equipo lo vaya gestionando.' ); ?>
					<?php else : ?>
						<div class="asdl-fin-table-wrap">
							<table class="widefat striped asdl-fin-table">
								<thead>
									<tr>
										<th>Fecha</th>
										<th>Evento</th>
										<th>Actor</th>
										<th>Detalle</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $events as $event ) : ?>
										<tr>
											<td><?php echo esc_html( sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ) ); ?></td>
											<td><?php echo esc_html( sanitize_text_field( (string) ( $event['event_type'] ?? '' ) ) ); ?></td>
											<td><?php echo esc_html( sanitize_text_field( (string) ( $event['actor_label'] ?? 'Sistema' ) ) ); ?></td>
											<td><?php echo esc_html( sanitize_text_field( (string) ( $event['message'] ?? '' ) ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>
			</section>
		</section>
		<?php
	}

	private function render_integrity_case_row_actions( array $case, array $filters, $detail = false ) {
		$case_id = (int) ( $case['id'] ?? 0 );
		if ( $case_id <= 0 ) {
			return;
		}

		$actions = array(
			IntegrityCasesRepository::STATUS_REVIEWED => 'Marcar revisado',
			IntegrityCasesRepository::STATUS_IGNORED  => 'Ignorar',
			IntegrityCasesRepository::STATUS_RESOLVED => 'Marcar resuelto',
		);
		?>
		<div class="asdl-fin-inline-actions">
			<?php if ( ! $detail ) : ?>
				<a class="button button-secondary small" href="<?php echo esc_url( $this->integrity_page_url( array( 'case_id' => $case_id ), $filters ) ); ?>">Ver detalle</a>
			<?php endif; ?>
			<?php foreach ( $actions as $status => $label ) : ?>
				<?php if ( sanitize_key( (string) ( $case['status'] ?? '' ) ) === $status ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="asdl_fin_integrity_case_state" />
					<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case_id ); ?>" />
					<input type="hidden" name="target_status" value="<?php echo esc_attr( $status ); ?>" />
					<?php wp_nonce_field( 'asdl_fin_integrity_case_state_' . $case_id ); ?>
					<?php $this->render_integrity_hidden_filter_inputs( $filters, $detail ? $case_id : 0 ); ?>
					<?php submit_button( $label, 'secondary small', 'submit', false ); ?>
				</form>
			<?php endforeach; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="asdl_fin_integrity_case_rescan" />
				<input type="hidden" name="case_id" value="<?php echo esc_attr( (string) $case_id ); ?>" />
				<?php wp_nonce_field( 'asdl_fin_integrity_case_rescan_' . $case_id ); ?>
				<?php $this->render_integrity_hidden_filter_inputs( $filters, $detail ? $case_id : 0 ); ?>
				<?php submit_button( 'Reescanear', 'secondary small', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_integrity_evidence_table( $title, array $rows ) {
		if ( empty( $rows ) || ! is_array( $rows[0] ?? null ) ) {
			return;
		}

		$columns = array_keys( (array) $rows[0] );
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2><?php echo esc_html( $title ); ?></h2>
				<p>Evidencia puntual capturada para este caso.</p>
			</div>
			<div class="asdl-fin-table-wrap">
				<table class="widefat striped asdl-fin-table">
					<thead>
						<tr>
							<?php foreach ( $columns as $column ) : ?>
								<th><?php echo esc_html( $this->integrity_case_field_label( $column ) ); ?></th>
							<?php endforeach; ?>
							<th>Gestion</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<?php foreach ( $columns as $column ) : ?>
									<td><?php echo wp_kses_post( $this->render_integrity_evidence_value( $column, $row[ $column ] ?? '', $row ) ); ?></td>
								<?php endforeach; ?>
								<td><?php echo wp_kses_post( $this->render_integrity_evidence_links( (array) $row ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	private function render_integrity_evidence_links( array $row ) {
		$links    = array();
		$order_id = ! empty( $row['external_order_id'] ) ? (int) $row['external_order_id'] : ( ! empty( $row['order_id'] ) ? (int) $row['order_id'] : 0 );

		if ( ! empty( $row['contact_id'] ) ) {
			$links[] = sprintf(
				'<a class="button button-secondary small" href="%s">Perfil</a>',
				esc_url( $this->contact_detail_url( (int) $row['contact_id'] ) )
			);
		}

		if ( $order_id > 0 ) {
			$order_url = $this->integrity_order_edit_url( $order_id );
			if ( '' !== $order_url ) {
				$links[] = sprintf(
					'<a class="button button-secondary small" href="%s">Pedido</a>',
					esc_url( $order_url )
				);
			}
		}

		if ( ! empty( $row['document_id'] ) ) {
			$links[] = sprintf(
				'<a class="button button-secondary small" href="%s">Documento</a>',
				esc_url( $this->document_detail_url( (int) $row['document_id'] ) )
			);
		}

		if ( ! empty( $row['payment_id'] ) ) {
			$links[] = sprintf(
				'<a class="button button-secondary small" href="%s">Pago</a>',
				esc_url( $this->receipt_url( 'payment', (int) $row['payment_id'] ) )
			);
		}

		if ( ! empty( $row['batch_id'] ) ) {
			$links[] = sprintf(
				'<a class="button button-secondary small" href="%s">Batch</a>',
				esc_url( $this->integrity_page_url( array( 'integrity_batch_id' => (int) $row['batch_id'] ) ) )
			);
		}

		return empty( $links ) ? '—' : implode( ' ', $links );
	}

	private function render_integrity_evidence_value( $key, $value, array $row = array() ) {
		if ( is_bool( $value ) ) {
			return $value ? 'Si' : 'No';
		}

		if ( is_array( $value ) ) {
			return '<code>' . esc_html( wp_json_encode( $value ) ?: '[]' ) . '</code>';
		}

		$key = sanitize_key( (string) $key );
		if ( is_numeric( $value ) && preg_match( '/(amount|balance|total|difference)$/', $key ) ) {
			return $this->format_money( (float) $value, $row['currency'] ?? '' );
		}

		if ( is_numeric( $value ) && preg_match( '/_id$/', $key ) ) {
			return '#' . esc_html( (string) absint( $value ) );
		}

		if ( '' === (string) $value ) {
			return '—';
		}

		return esc_html( sanitize_text_field( (string) $value ) );
	}

	private function integrity_case_field_label( $key ) {
		$labels = array(
			'payment_id'                      => 'Pago',
			'payment_number'                  => 'Numero',
			'available_amount'                => 'Disponible',
			'payment_type'                    => 'Tipo',
			'method_key'                      => 'Metodo',
			'order_id'                        => 'Pedido',
			'order_number'                    => 'Pedido #',
			'document_id'                     => 'Documento',
			'balance'                         => 'Saldo',
			'payment_status'                  => 'Pago',
			'error_message'                   => 'Error',
			'cover_amount'                    => 'Cliente cubre',
			'discount_amount'                 => 'Descuento',
			'currency'                        => 'Moneda',
			'linked_order_id'                 => 'Pedido vinculado',
			'linked_order_ref'                => 'Referencia',
			'actual_order_status'             => 'Estado Woo',
			'indexed_status'                  => 'Estado indexado',
			'indexed_balance'                 => 'Saldo indexado',
			'indexed_is_open'                 => 'Indexado abierto',
			'indexed_operationally_collectible' => 'Indexado cobrable',
		);

		$key = sanitize_key( (string) $key );

		return $labels[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
	}

	private function integrity_page_filters() {
		return array(
			'search'         => sanitize_text_field( (string) wp_unslash( $_GET['integrity_search'] ?? '' ) ),
			'contact_search' => sanitize_text_field( (string) wp_unslash( $_GET['integrity_contact_search'] ?? '' ) ),
			'case_type'      => sanitize_key( (string) wp_unslash( $_GET['integrity_case_type'] ?? '' ) ),
			'severity'       => sanitize_key( (string) wp_unslash( $_GET['integrity_severity'] ?? '' ) ),
			'status'         => sanitize_key( (string) wp_unslash( $_GET['integrity_status'] ?? '' ) ),
			'contact_id'     => absint( wp_unslash( $_GET['integrity_contact_id'] ?? 0 ) ),
			'order_id'       => absint( wp_unslash( $_GET['integrity_order_id'] ?? 0 ) ),
			'batch_id'       => absint( wp_unslash( $_GET['integrity_batch_id'] ?? 0 ) ),
			'range_from'     => sanitize_text_field( (string) wp_unslash( $_GET['integrity_range_from'] ?? '' ) ),
			'range_to'       => sanitize_text_field( (string) wp_unslash( $_GET['integrity_range_to'] ?? '' ) ),
			'page_num'       => max( 1, absint( wp_unslash( $_GET['integrity_page_num'] ?? 1 ) ) ),
			'case_id'        => absint( wp_unslash( $_GET['case_id'] ?? 0 ) ),
		);
	}

	private function integrity_page_url( array $overrides = array(), array $filters = array() ) {
		$args = array_merge(
			array(
				'page' => 'asdl-fin-integrity',
			),
			$this->integrity_filter_query_args( $filters ),
			$overrides
		);

		foreach ( $args as $key => $value ) {
			if ( null === $value || false === $value || '' === $value || ( is_int( $value ) && $value <= 0 && in_array( $key, array( 'case_id', 'integrity_contact_id', 'integrity_order_id', 'integrity_batch_id' ), true ) ) ) {
				unset( $args[ $key ] );
			}
		}

		return $this->with_current_context_url( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	}

	private function integrity_filter_query_args( array $filters ) {
		return array(
			'integrity_search'         => $filters['search'] ?? '',
			'integrity_contact_search' => $filters['contact_search'] ?? '',
			'integrity_case_type'      => $filters['case_type'] ?? '',
			'integrity_severity'       => $filters['severity'] ?? '',
			'integrity_status'         => $filters['status'] ?? '',
			'integrity_contact_id'     => ! empty( $filters['contact_id'] ) ? (int) $filters['contact_id'] : '',
			'integrity_order_id'       => ! empty( $filters['order_id'] ) ? (int) $filters['order_id'] : '',
			'integrity_batch_id'       => ! empty( $filters['batch_id'] ) ? (int) $filters['batch_id'] : '',
			'integrity_range_from'     => $filters['range_from'] ?? '',
			'integrity_range_to'       => $filters['range_to'] ?? '',
			'integrity_page_num'       => ! empty( $filters['page_num'] ) ? (int) $filters['page_num'] : 1,
		);
	}

	private function render_integrity_hidden_filter_inputs( array $filters, $case_id = 0 ) {
		foreach ( $this->integrity_filter_query_args( $filters ) as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}

			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( $name ),
				esc_attr( (string) $value )
			);
		}

		if ( $case_id > 0 ) {
			printf(
				'<input type="hidden" name="case_id" value="%d" />',
				absint( $case_id )
			);
		}

		$this->render_current_fiscal_hidden_input();
	}

	private function integrity_order_edit_url( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && is_callable( array( $order, 'get_edit_order_url' ) ) ) {
				return (string) $order->get_edit_order_url();
			}
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	public function render_integrations_page() {
		$this->render_page_open(
			'Integraciones',
			'Panel operativo para enlazar pedidos cuando haga falta, mantener la coherencia con Woo y usar el core financiero sin duplicar el origen.'
		);
		?>
		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="high"
			data-runtime-group="integrations"
			data-runtime-title="No se pudo cargar el resumen de integraciones."
			data-runtime-param-page-key="integrations"
			data-runtime-param-section-key="integrations-summary"
		>
			<?php $this->render_runtime_card_skeleton( 4 ); ?>
		</div>

		<div class="asdl-fin-panel-grid">
			<div
				class="asdl-fin-runtime-container"
				data-runtime-action="asdl_fin_admin_runtime"
				data-runtime-nonce="adminRuntime"
				data-runtime-priority="high"
				data-runtime-group="integrations"
				data-runtime-title="No se pudo cargar la gestion bajo demanda."
				data-runtime-param-page-key="integrations"
				data-runtime-param-section-key="integrations-pending-orders"
			>
				<?php $this->render_runtime_table_skeleton( 'Gestion bajo demanda', 'Cargando pedidos Woo/OpenPOS abiertos para operar...', 6, 7 ); ?>
			</div>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Sincronizacion por lote</h2>
					<p>Herramienta de backfill para poblar o revisar el core por lotes. No es el flujo principal del dia a dia.</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
					<input type="hidden" name="action" value="asdl_fin_sync_orders" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php wp_nonce_field( 'asdl_fin_sync_orders' ); ?>
					<?php $this->render_select( 'source', 'Origen', array( 'all' => 'Todos', 'woocommerce' => 'WooCommerce', 'openpos' => 'OpenPOS' ), true ); ?>
					<?php $this->render_input( 'days', 'Ultimos dias', 'number', '30', true, array( 'min' => '1', 'step' => '1' ) ); ?>
					<?php $this->render_input( 'limit', 'Maximo de pedidos', 'number', '25', true, array( 'min' => '1', 'step' => '1' ) ); ?>
					<div class="asdl-fin-field asdl-fin-field-wide">
						<span>Backfill controlado</span>
						<div class="asdl-fin-inline-actions">
							<?php submit_button( 'Sincronizar pedidos recientes', 'primary', 'submit', false ); ?>
						</div>
						<small>Util para traer pedidos historicos o revisar varios pedidos de una vez dentro del core.</small>
					</div>
				</form>
			</section>
		</div>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="normal"
			data-runtime-group="integrations"
			data-runtime-title="No se pudo cargar el estado actual de integraciones."
			data-runtime-param-page-key="integrations"
			data-runtime-param-section-key="integrations-status"
		>
			<?php $this->render_runtime_table_skeleton( 'Estado actual', 'Cargando el resumen operativo de integraciones...', 3, 5 ); ?>
		</div>

		<div
			class="asdl-fin-runtime-container"
			data-runtime-action="asdl_fin_admin_runtime"
			data-runtime-nonce="adminRuntime"
			data-runtime-priority="low"
			data-runtime-group="integrations"
			data-runtime-title="No se pudo cargar los vinculos sincronizados."
			data-runtime-param-page-key="integrations"
			data-runtime-param-section-key="integrations-links"
		>
			<?php $this->render_runtime_table_skeleton( 'Vinculos sincronizados', 'Cargando la relacion entre pedidos externos y el core...', 5, 6 ); ?>
		</div>
		<?php
		$this->render_page_close();
	}

	public function render_rules_page() {
		$accounts_repository = new AccountsRepository();
		$contacts_repository = new ContactsRepository();
		$rules_repository    = new RulesRepository();

		$this->render_page_open(
			'Automatizaciones',
			'Modulo avanzado y opcional para casos repetitivos. El sistema puede operar sin reglas manuales usando fallback y gestion manual.'
		);
		?>
		<div class="asdl-fin-panel-grid">
			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Nueva automatizacion</h2>
					<p>Usala cuando un criterio se repita muchas veces. La prioridad se evalua primero por alcance y luego por numero.</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
					<input type="hidden" name="action" value="asdl_fin_save_rule" />
					<input type="hidden" name="return_page" value="asdl-fin-rules" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php wp_nonce_field( 'asdl_fin_save_rule' ); ?>
					<?php $this->render_input( 'rule_name', 'Nombre de la automatizacion', 'text', '', true ); ?>
					<?php $this->render_input( 'priority', 'Prioridad', 'number', '100', true, array( 'min' => '1', 'step' => '1' ) ); ?>
					<?php $this->render_select( 'scope_type', 'Alcance', array( 'source' => 'Origen / documento', 'contact' => 'Contacto', 'account' => 'Cuenta', 'category' => 'Categoria' ) ); ?>
					<?php $this->render_select( 'source_type', 'Origen base', array( '' => 'Cualquiera', 'manual' => 'Manual', 'woocommerce' => 'WooCommerce', 'openpos' => 'OpenPOS', 'external' => 'Externo', 'api' => 'API propia' ) ); ?>
					<label class="asdl-fin-field asdl-fin-field-wide asdl-fin-checkbox-field">
						<span class="asdl-fin-checkbox-row">
							<input type="checkbox" name="is_active" value="1" checked="checked" />
							<strong>Automatizacion activa</strong>
						</span>
						<small>Si la desactivas, se conserva en el historial pero no participa en la clasificacion.</small>
					</label>

					<div class="asdl-fin-field asdl-fin-field-wide">
						<span>Condiciones</span>
						<small>Deja vacio lo que no quieras evaluar. Todas las condiciones llenas deben cumplirse.</small>
					</div>
					<?php $this->render_select( 'condition_document_type', 'Tipo de movimiento', array( '' => 'Cualquiera', 'woo_sale' => 'Venta de tienda', 'external_expense' => 'Gasto externo', 'service_expense' => 'Servicio', 'salary_expense' => 'Sueldo', 'adjustment' => 'Ajuste', 'loan_receivable' => 'Prestamo por cobrar', 'loan_payable' => 'Prestamo por pagar', 'manual_document' => 'Movimiento manual' ) ); ?>
					<?php $this->render_select( 'condition_source_type', 'Origen especifico', array( '' => 'Cualquiera', 'manual' => 'Manual', 'woocommerce' => 'WooCommerce', 'openpos' => 'OpenPOS', 'external' => 'Externo', 'api' => 'API propia' ) ); ?>
					<?php $this->render_select( 'condition_contact_type', 'Rol base del perfil', array( '' => 'Cualquiera', 'client' => 'Cliente', 'supplier' => 'Proveedor', 'employee' => 'Empleado', 'mixed' => 'Mixto' ) ); ?>
					<?php $this->render_contact_picker( 'condition_contact_id', 'Perfil puntual', 0, false, array( 'placeholder' => 'Busca un perfil puntual', 'help' => 'Opcional. Restringe la automatizacion a un perfil especifico.', 'fallback_options' => array( '' => 'Cualquiera' ) + $contacts_repository->options(), 'fallback_placeholder' => 'Cualquiera' ) ); ?>
					<?php $this->render_select( 'condition_account_type', 'Tipo de cuenta', array( '' => 'Cualquiera', 'operating' => 'Operativa', 'cost_center' => 'Centro operativo', 'cash' => 'Caja', 'loan' => 'Prestamo', 'wallet' => 'Fondo' ) ); ?>
					<?php $this->render_select( 'condition_account_id', 'Cuenta puntual', array( '' => 'Cualquiera' ) + $accounts_repository->options() ); ?>
					<?php $this->render_input( 'condition_category_key', 'Categoria actual', 'text', '' ); ?>
					<?php $this->render_select( 'condition_operational_status', 'Estado operativo', array( '' => 'Cualquiera', 'pending' => 'Pending', 'on-hold' => 'On hold', 'processing' => 'Processing', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'failed' => 'Failed', 'refunded' => 'Refunded' ) ); ?>
					<?php $this->render_select( 'condition_financial_intent', 'Intencion actual', array( '' => 'Cualquiera', 'income' => 'Ingreso', 'expense' => 'Egreso', 'salary' => 'Sueldo', 'service' => 'Servicio', 'adjustment' => 'Ajuste', 'internal_consumption' => 'Consumo interno', 'loan' => 'Prestamo', 'neutral' => 'Neutral' ) ); ?>
					<?php $this->render_input( 'condition_external_reference_contains', 'Referencia contiene', 'text', '' ); ?>
					<?php $this->render_input( 'condition_title_contains', 'Titulo contiene', 'text', '' ); ?>

					<div class="asdl-fin-field asdl-fin-field-wide">
						<span>Acciones</span>
						<small>Estas son las decisiones que la automatizacion aplicara cuando haga match.</small>
					</div>
					<?php $this->render_select( 'action_financial_intent', 'Nueva intencion', array( '' => 'Sin cambio', 'income' => 'Ingreso', 'expense' => 'Egreso', 'salary' => 'Sueldo', 'service' => 'Servicio', 'adjustment' => 'Ajuste', 'internal_consumption' => 'Consumo interno', 'loan' => 'Prestamo', 'neutral' => 'Neutral' ) ); ?>
					<?php $this->render_select( 'action_balance_nature', 'Nueva naturaleza', array( '' => 'Sin cambio', 'receivable' => 'Por cobrar', 'payable' => 'Por pagar', 'neutral' => 'Neutro' ) ); ?>
					<?php $this->render_input( 'action_category_key', 'Nueva categoria', 'text', '' ); ?>
					<?php $this->render_input( 'action_subcategory_key', 'Nueva subcategoria', 'text', '' ); ?>
					<?php $this->render_textarea( 'notes', 'Notas', 'Explica brevemente el criterio de negocio de esta automatizacion.' ); ?>
					<?php submit_button( 'Guardar automatizacion', 'primary', 'submit', false ); ?>
				</form>
			</section>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Automatizaciones registradas</h2>
					<p>El motor evalua override manual, luego automatizaciones activas por alcance y prioridad, y por ultimo fallback.</p>
				</div>
				<div
					class="asdl-fin-runtime-container"
					data-runtime-action="asdl_fin_admin_runtime"
					data-runtime-nonce="adminRuntime"
					data-runtime-priority="high"
					data-runtime-group="rules-table"
					data-runtime-title="No se pudo cargar la tabla de automatizaciones."
					data-runtime-param-page-key="rules"
					data-runtime-param-section-key="rules-table"
				>
					<?php $this->render_runtime_table_skeleton( 'Automatizaciones registradas', 'Cargando la tabla base de reglas y automatizaciones...', 6, 8 ); ?>
				</div>
			</section>
		</div>
		<?php
		$this->render_page_close();
	}

	public function render_docs() {
		$endpoints = array(
			'GET /wp-json/asdl-fin/v1/me'                  => 'Identidad autenticada via WordPress REST para la app interna y resumen basico del usuario actual.',
			'GET /wp-json/asdl-fin/v1/me/permissions'      => 'Capacidades y permisos efectivos del usuario autenticado dentro del plugin.',
			'GET /wp-json/asdl-fin/v1/health'              => 'Estado del plugin, version de esquema y tablas detectadas.',
			'GET /wp-json/asdl-fin/v1/dashboard'           => 'Resumen ejecutivo del dashboard.',
			'GET /wp-json/asdl-fin/v1/payment-methods'     => 'Catalogo de metodos de pago con separacion entre opciones seleccionables y metodos internos del sistema.',
			'GET /wp-json/asdl-fin/v1/currencies'          => 'Catalogo central de monedas validas para formularios, API y app movil.',
			'GET /wp-json/asdl-fin/v1/fiscal-years'        => 'Contexto fiscal disponible para la app, incluyendo ejercicio activo, seleccionado y modo consulta.',
			'GET, POST /wp-json/asdl-fin/v1/accounts'      => 'Consulta y registro de cuentas financieras.',
			'GET, POST /wp-json/asdl-fin/v1/contacts'      => 'Consulta y registro de perfiles financieros y contactos externos.',
			'GET /wp-json/asdl-fin/v1/contacts/{id}'       => 'Resumen financiero completo de un perfil.',
			'GET /wp-json/asdl-fin/v1/contacts/{id}/pending-orders' => 'Lista operativa simplificada de pedidos pendientes del perfil, pensada para cobranza movil.',
			'GET, POST /wp-json/asdl-fin/v1/contacts/{id}/commitments' => 'Lista y crea compromisos directamente desde el contexto del perfil para app staff.',
			'POST /wp-json/asdl-fin/v1/contacts/{id}/settle-orders/preview' => 'Simula como quedaria un abono a pedidos antes de ejecutarlo, incluyendo precio dual cuando aplique.',
			'POST /wp-json/asdl-fin/v1/contacts/{id}/settle-orders' => 'Aplica un abono por antiguedad sobre pedidos Woo/OpenPOS pendientes del perfil.',
			'POST /wp-json/asdl-fin/v1/contacts/{id}/apply-credit' => 'Cruza saldo a favor del perfil contra pedidos pendientes sin registrar entrada de caja.',
			'GET, POST /wp-json/asdl-fin/v1/contacts/{id}/employee-profile' => 'Consulta y guarda la configuracion laboral base del empleado.',
			'GET, POST /wp-json/asdl-fin/v1/contacts/{id}/salary-advances' => 'Consulta y registra adelantos de sueldo descontables para el empleado.',
			'GET /wp-json/asdl-fin/v1/salary-advances/{id}' => 'Detalle de un adelanto puntual con su pago vinculado y acceso al comprobante.',
			'GET, POST /wp-json/asdl-fin/v1/contacts/{id}/payroll-periods' => 'Consulta y genera periodos de nomina desde el perfil del empleado.',
			'GET /wp-json/asdl-fin/v1/payroll-periods/{id}' => 'Detalle de un periodo de nomina con documento, perfil laboral y recibo asociado.',
			'POST /wp-json/asdl-fin/v1/payroll-periods/{id}/mark-paid' => 'Procesa el pago de un periodo de nomina y descuenta adelantos y compromisos configurados.',
			'GET /wp-json/asdl-fin/v1/payroll/queue'       => 'Cola general de nomina para lectura movil de empleados por atender.',
			'GET, POST /wp-json/asdl-fin/v1/documents'     => 'Consulta y registro de movimientos financieros.',
			'GET, POST /wp-json/asdl-fin/v1/documents/{id}' => 'Consulta y actualizacion puntual de un movimiento.',
			'GET, POST /wp-json/asdl-fin/v1/documents/{id}/files' => 'Consulta y vincula soportes del movimiento usando adjuntos ya existentes en WordPress.',
			'GET, POST /wp-json/asdl-fin/v1/payments'      => 'Consulta y registro de cobros, pagos y abonos.',
			'GET /wp-json/asdl-fin/v1/payments/{id}'       => 'Detalle de un pago con asignaciones, aplicaciones a compromisos y acceso al comprobante.',
			'GET /wp-json/asdl-fin/v1/receipts/{type}/{id}' => 'Comprobante estructurado y listo para imprimir/mostrar desde app o integracion interna.',
			'GET, POST /wp-json/asdl-fin/v1/rules'         => 'Consulta y registro de reglas de clasificacion.',
			'GET, POST /wp-json/asdl-fin/v1/payment-allocations' => 'Consulta y registro de asignaciones de pago.',
			'GET, POST /wp-json/asdl-fin/v1/installment-plans' => 'Consulta y registro de compromisos, prestamos y acuerdos de pago.',
			'GET /wp-json/asdl-fin/v1/installment-plans/{id}' => 'Detalle de un compromiso con cuotas, saldo, documento enlazado y perfil relacionado.',
			'POST /wp-json/asdl-fin/v1/installment-plans/{id}/apply-payment' => 'Aplica un abono directo sobre un compromiso y ajusta el saldo vinculado.',
			'GET /wp-json/asdl-fin/v1/integrations/status' => 'Estado actual de integraciones y vinculos externos.',
			'POST /wp-json/asdl-fin/v1/sync/orders'        => 'Sincronizacion manual de pedidos Woo/OpenPOS al core.',
		);

		$files = array(
			'docs/vision.md'            => 'Vision general del producto y lenguaje del sistema.',
			'docs/domain-model.md'      => 'Entidades principales y criterio funcional del MVP.',
			'docs/menu-and-ux.md'       => 'Estructura del menu, nombres y regla de usabilidad.',
			'docs/api-overview.md'      => 'Base de la API propia del plugin.',
			'docs/sync-architecture.md' => 'Criterios de sincronizacion e integraciones.',
			'docs/first-install-checklist.md' => 'Pruebas funcionales recomendadas para la primera instalacion.',
			'docs/mobile-context/backend-api-phases.md' => 'Secuencia oficial para dejar el backend listo para la app movil.',
			'docs/mobile-context/api-contract.md' => 'Auditoria y contrato actual de la API para consumo movil interno.',
			'docs/mobile-context/handoff-brief.md' => 'Resumen consolidado para retomar la fase movil o entregarla a otro agente.',
			'docs/mobile-context/staff-auth-provisioning.md' => 'Flujo operativo para emitir, rotar y revocar credenciales de app usando Application Passwords.',
		);
		?>
		<div class="wrap asdl-fin-wrap">
			<div class="asdl-fin-page-heading">
				<h1>API y documentacion</h1>
				<p>Base tecnica para integraciones futuras, automatizaciones y consumo controlado desde otros plugins o herramientas.</p>
			</div>

			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Endpoints base</h2>
						<p>Primer contrato REST del plugin.</p>
					</div>
					<ul class="asdl-fin-endpoint-list">
						<?php foreach ( $endpoints as $route => $description ) : ?>
							<li>
								<button type="button" class="button-link asdl-fin-copy-route" data-copy="<?php echo esc_attr( $this->route_url_from_signature( $route ) ); ?>">
									<code><?php echo esc_html( $route ); ?></code>
								</button>
								<span><?php echo esc_html( $description ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>

				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Hooks previstos</h2>
						<p>Eventos internos pensados para extensibilidad.</p>
					</div>
					<ul class="asdl-fin-checklist">
						<li><code>asdl_fin_account_created</code></li>
						<li><code>asdl_fin_contact_created</code></li>
						<li><code>asdl_fin_profile_linked</code></li>
						<li><code>asdl_fin_profile_promoted</code></li>
						<li><code>asdl_fin_profile_payment_applied</code></li>
						<li><code>asdl_fin_employee_profile_saved</code></li>
						<li><code>asdl_fin_salary_advance_saved</code></li>
						<li><code>asdl_fin_payroll_period_created</code></li>
						<li><code>asdl_fin_payroll_period_paid</code></li>
						<li><code>asdl_fin_document_created</code></li>
						<li><code>asdl_fin_document_updated</code></li>
						<li><code>asdl_fin_payment_recorded</code></li>
						<li><code>asdl_fin_payment_allocated</code></li>
						<li><code>asdl_fin_installment_plan_created</code></li>
						<li><code>asdl_fin_rule_created</code></li>
						<li><code>asdl_fin_sync_completed</code></li>
					</ul>
				</section>
			</div>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Archivos de referencia</h2>
					<p>Documentacion viva incluida con el plugin para que la base tecnica no dependa de memoria informal.</p>
				</div>
				<div class="asdl-fin-action-grid">
					<?php foreach ( $files as $path => $description ) : ?>
						<div class="asdl-fin-action-card">
							<strong><?php echo esc_html( $path ); ?></strong>
							<p><?php echo esc_html( $description ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		</div>
		<?php
	}

	public function render_settings() {
		$payment_methods            = ( new PaymentMethodsService() )->catalog();
		$currencies                 = ( new CurrenciesService() )->catalog();
		$receipt_brand              = ( new ReceiptBrandingService() )->get_snapshot();
		$fiscal_year                = $this->current_fiscal_context();
		$contacts_repository        = new ContactsRepository();
		$historical_index_status    = ( new HistoricalIndexRebuildService() )->get_status_snapshot();
		$historical_resolution_status = ( new HistoricalResolutionService() )->get_status_snapshot();
		?>
		<div class="wrap asdl-fin-wrap">
			<div class="asdl-fin-page-heading">
				<h1>Configuracion</h1>
				<p>Panel transitorio de configuracion mientras el nuevo core absorbe por fases la logica del analisis financiero actual.</p>
			</div>
			<?php $this->render_fiscal_context_toolbar(); ?>
			<?php $this->render_payment_method_modal(); ?>
			<?php $this->render_currency_modal(); ?>

			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Core financiero</h2>
						<p>Estado base del esquema y preparacion del sistema.</p>
					</div>
					<ul class="asdl-fin-checklist">
						<li>Marca visible: Finanzas ASD</li>
						<li>Nombre tecnico: ASDLabs Finance Core</li>
						<li>Version del plugin: <?php echo esc_html( ASDL_FINANCE_VERSION ); ?></li>
						<li>Version de esquema: <?php echo esc_html( get_option( SchemaInstaller::OPTION_SCHEMA_VERSION, 'pendiente' ) ); ?></li>
					</ul>
				</section>

				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Compatibilidad temporal</h2>
						<p>Parametros heredados del analisis actual para no perder referencia funcional en la migracion.</p>
					</div>
					<form method="post" action="options.php" class="asdl-fin-settings-form">
						<?php
						settings_fields( 'afp_settings_group' );
						do_settings_sections( 'analysis-financiero-settings' );
						submit_button( 'Guardar configuracion heredada' );
						?>
					</form>
				</section>
			</div>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Ejercicio fiscal</h2>
					<p>Define el inicio del ejercicio y cual es el ejercicio activo. Cuando consultes otro ejercicio, el sistema entra en modo historico sin permitir cambios operativos.</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
					<input type="hidden" name="action" value="asdl_fin_save_fiscal_year_settings" />
					<?php wp_nonce_field( 'asdl_fin_save_fiscal_year_settings' ); ?>
					<?php $this->render_select( 'start_month', 'Mes de inicio', array(
						'1'  => 'Enero',
						'2'  => 'Febrero',
						'3'  => 'Marzo',
						'4'  => 'Abril',
						'5'  => 'Mayo',
						'6'  => 'Junio',
						'7'  => 'Julio',
						'8'  => 'Agosto',
						'9'  => 'Septiembre',
						'10' => 'Octubre',
						'11' => 'Noviembre',
						'12' => 'Diciembre',
					), true, '', (string) ( $fiscal_year['settings']['start_month'] ?? 1 ) ); ?>
					<?php $this->render_input( 'start_day', 'Dia de inicio', 'number', (string) ( $fiscal_year['settings']['start_day'] ?? 1 ), true, array( 'min' => '1', 'max' => '31', 'step' => '1' ) ); ?>
					<?php $this->render_select( 'active_start_year', 'Ejercicio activo', ( new FiscalYearService() )->available_years( 6, 0 ), true, '', (string) ( $fiscal_year['settings']['active_start_year'] ?? gmdate( 'Y' ) ) ); ?>
					<div class="asdl-fin-field asdl-fin-field-wide">
						<span>Resumen actual</span>
						<div class="asdl-fin-data-grid">
							<div><strong>Ejercicio activo</strong><span><?php echo esc_html( $fiscal_year['active_label'] ?? '' ); ?></span></div>
							<div><strong>Periodo actual</strong><span><?php echo esc_html( sprintf( '%1$s al %2$s', $fiscal_year['start_date'] ?? '', $fiscal_year['end_date'] ?? '' ) ); ?></span></div>
						</div>
						<small>El ejercicio activo habilita movimientos, pagos, compromisos y nomina. Los ejercicios anteriores quedan para consulta y revision historica.</small>
					</div>
					<?php submit_button( 'Guardar ejercicio fiscal', 'primary', 'submit', false ); ?>
				</form>
			</section>

			<div class="asdl-fin-panel-grid asdl-fin-panel-grid-wide">
				<section class="asdl-fin-panel asdl-fin-historical-tool" data-historical-index-root>
					<div class="asdl-fin-panel-header">
						<h2>Indice historico comercial</h2>
						<p>Reconstruye pedidos Woo/OpenPOS de ejercicios cerrados en lotes, recalcula rollups y deja un historico consultable sin volver a barrer toda la tienda en vivo.</p>
					</div>
					<div class="asdl-fin-tool-grid">
						<div class="asdl-fin-tool-card">
							<h3>Resumen</h3>
							<div class="asdl-fin-data-grid asdl-fin-data-grid-tight" data-historical-index-summary>
								<div><strong>Pedidos indexados</strong><span><?php echo esc_html( number_format_i18n( (int) ( $historical_index_status['global']['total_orders'] ?? 0 ) ) ); ?></span></div>
								<div><strong>Anios cubiertos</strong><span><?php echo esc_html( number_format_i18n( (int) ( $historical_index_status['global']['indexed_years'] ?? 0 ) ) ); ?></span></div>
								<div><strong>Historico cobrable</strong><span><?php echo wp_kses_post( $this->format_money( (float) ( $historical_index_status['global']['collectible_balance_total'] ?? 0 ) ) ); ?></span></div>
								<div><strong>Ultimo rebuild</strong><span><?php echo esc_html( sanitize_text_field( (string) ( $historical_index_status['job']['updated_at'] ?? 'Sin actividad' ) ) ); ?></span></div>
							</div>
						</div>
						<div class="asdl-fin-tool-card">
							<h3>Reconstruccion</h3>
							<div class="asdl-fin-form-grid">
								<?php $this->render_select( 'historical_index_year', 'Ejercicio a reconstruir', $this->build_historical_year_options( $historical_index_status['years'] ?? array() ), false, '', '', array( 'id' => 'historical_index_year' ) ); ?>
								<?php $this->render_input( 'historical_index_batch_size', 'Lote por request', 'number', '250', true, array( 'id' => 'historical_index_batch_size', 'min' => '50', 'max' => '500', 'step' => '10' ) ); ?>
								<label class="asdl-fin-checkbox-field">
									<input type="checkbox" name="historical_index_force" value="1" data-historical-index-force />
									<span>Rehacer el ejercicio desde cero</span>
								</label>
							</div>
							<div class="asdl-fin-inline-actions">
								<button type="button" class="button button-primary" data-historical-index-start>Reconstruir ejercicio</button>
								<button type="button" class="button button-secondary" data-historical-index-refresh>Actualizar estado</button>
							</div>
						</div>
						<div class="asdl-fin-tool-card">
							<h3>Compactacion y rollups</h3>
							<div class="asdl-fin-form-grid">
								<?php $this->render_select( 'historical_rollup_year', 'Ejercicio indexado', $this->build_historical_year_options( $historical_index_status['years'] ?? array(), true ), false, '', '', array( 'id' => 'historical_rollup_year' ) ); ?>
							</div>
							<div class="asdl-fin-inline-actions">
								<button type="button" class="button button-secondary" data-historical-rollups>Recalcular rollups</button>
								<button type="button" class="button button-secondary" data-historical-compact>Compactar carryforward</button>
								<button type="button" class="button button-secondary" data-historical-diagnostics>Diagnostico</button>
							</div>
							<small>La compactacion no toca el ejercicio actual. Solo marca el historico indexado como arrastre consolidado para lecturas mas rapidas.</small>
						</div>
					</div>
					<div class="asdl-fin-tool-progress" data-historical-index-progress></div>
					<div class="asdl-fin-tool-detail" data-historical-index-diagnostics>
						<div class="asdl-fin-empty">
							<strong>Sin diagnostico cargado.</strong>
							<p>Selecciona un ejercicio indexado y pulsa <em>Diagnostico</em> para revisar pedidos sin contacto, documento o source link.</p>
						</div>
					</div>
					<div class="asdl-fin-tool-status-table" data-historical-index-years></div>
				</section>

				<section class="asdl-fin-panel asdl-fin-historical-tool" data-historical-resolution-root>
					<div class="asdl-fin-panel-header">
						<h2>Cierre administrativo historico</h2>
						<p>Cierra en lote pedidos historicos indexados para sacarlos de la cobranza activa sin tocar el ejercicio actual ni registrar ingresos del periodo en curso.</p>
					</div>
					<div class="asdl-fin-tool-grid">
						<div class="asdl-fin-tool-card">
							<h3>Vista previa</h3>
							<div class="asdl-fin-form-grid">
								<?php $this->render_select( 'historical_resolution_year_from', 'Desde ejercicio', $this->build_historical_year_options( $historical_index_status['years'] ?? array(), true ), false, '', '', array( 'id' => 'historical_resolution_year_from' ) ); ?>
								<?php $this->render_select( 'historical_resolution_year_to', 'Hasta ejercicio', $this->build_historical_year_options( $historical_index_status['years'] ?? array(), true ), false, '', '', array( 'id' => 'historical_resolution_year_to' ) ); ?>
								<?php
								$this->render_contact_picker(
									'historical_resolution_contact_id',
									'Buscar perfil o cliente',
									0,
									false,
									array(
										'placeholder'          => 'Busca por nombre, correo o ID',
										'help'                 => 'Selecciona primero el perfil o cliente para filtrar rapido el historico antes de aplicar la vista previa.',
										'field_class'          => 'asdl-fin-field-wide',
										'fallback_options'     => $contacts_repository->options(),
										'fallback_placeholder' => 'Selecciona un perfil o cliente',
									)
								);
								?>
								<?php $this->render_input( 'historical_resolution_search', 'Pedido o correo exacto', 'text', '', false, array( 'id' => 'historical_resolution_search', 'placeholder' => '#77254533 o correo exacto' ) ); ?>
								<?php $this->render_select( 'historical_resolution_provider', 'Proveedor', array( 'all' => 'Todos', 'woocommerce' => 'WooCommerce', 'openpos' => 'OpenPOS' ), false, '', 'all', array( 'id' => 'historical_resolution_provider' ) ); ?>
								<?php $this->render_input( 'historical_resolution_min_balance', 'Monto minimo', 'number', '', false, array( 'id' => 'historical_resolution_min_balance', 'step' => '0.01' ) ); ?>
								<?php $this->render_input( 'historical_resolution_max_balance', 'Monto maximo', 'number', '', false, array( 'id' => 'historical_resolution_max_balance', 'step' => '0.01' ) ); ?>
								<?php $this->render_select( 'historical_resolution_reason', 'Motivo', array( 'historical_cleanup' => 'Cierre por limpieza historica', 'legacy_test' => 'Pruebas o datos legacy', 'administrative_writeoff' => 'Cierre administrativo' ), false, '', 'historical_cleanup', array( 'id' => 'historical_resolution_reason' ) ); ?>
								<?php $this->render_input( 'historical_resolution_batch_size', 'Lote por request', 'number', '200', true, array( 'id' => 'historical_resolution_batch_size', 'min' => '25', 'max' => '500', 'step' => '25' ) ); ?>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<label for="historical_resolution_note">Nota del lote</label>
									<textarea id="historical_resolution_note" name="historical_resolution_note" rows="3"></textarea>
									<small>La nota se guarda en el lote y tambien se deja como nota operativa en Woo/OpenPOS.</small>
								</div>
								<?php if ( current_user_can( 'manage_options' ) ) : ?>
									<label class="asdl-fin-checkbox-field">
										<input type="checkbox" id="historical_resolution_special_previous_year" name="historical_resolution_special_previous_year" value="1" data-historical-resolution-special-previous-year />
										<span>Caso especial: permitir tambien el ejercicio inmediatamente anterior</span>
									</label>
									<div class="asdl-fin-field asdl-fin-field-wide">
										<small>Solo administradores. Requiere nota obligatoria y nunca toca el ejercicio fiscal actual.</small>
									</div>
								<?php endif; ?>
								<label class="asdl-fin-checkbox-field">
									<input type="checkbox" name="historical_resolution_only_without_paid" value="1" data-historical-resolution-only-without-paid />
									<span>Solo pedidos sin pagos aplicados</span>
								</label>
							</div>
							<div class="asdl-fin-inline-actions">
								<button type="button" class="button button-secondary" data-historical-resolution-preview>Vista previa</button>
								<button type="button" class="button button-primary" data-historical-resolution-start>Aplicar cierre historico</button>
							</div>
						</div>
						<div class="asdl-fin-tool-card">
							<h3>Resultado esperado</h3>
							<div class="asdl-fin-data-grid asdl-fin-data-grid-tight" data-historical-resolution-preview-summary>
								<div><strong>Pedidos elegibles</strong><span>0</span></div>
								<div><strong>Total a excluir</strong><span>0,00$</span></div>
								<div><strong>Ejercicios</strong><span>Sin seleccion</span></div>
								<div><strong>Estado</strong><span>Esperando vista previa</span></div>
							</div>
							<div class="asdl-fin-runtime-note" data-historical-resolution-preview-items>
								Usa la vista previa para validar el lote historico antes de cerrarlo.
							</div>
						</div>
					</div>
					<div class="asdl-fin-tool-progress" data-historical-resolution-progress></div>
					<div class="asdl-fin-tool-status-table" data-historical-resolution-batches>
						<?php $this->render_historical_batches_table( $historical_resolution_status['batches'] ?? array() ); ?>
					</div>
					<div class="asdl-fin-tool-detail" data-historical-resolution-batch-detail>
						<div class="asdl-fin-empty">
							<strong>Sin lote seleccionado.</strong>
							<p>Cuando ejecutes un cierre historico, podras abrir aqui el detalle del lote para ver pedidos afectados, motivo y trazabilidad.</p>
						</div>
					</div>
				</section>
			</div>

			<section class="asdl-fin-panel asdl-fin-historical-tool" data-balance-audit-root>
				<div class="asdl-fin-panel-header">
					<h2>Auditoria de saldos y paridad</h2>
					<p>Compara perfil, dashboard y API movil contra las mismas fuentes de verdad para detectar diferencias de saldo, deudas y cache pegada antes de tocar formulas.</p>
				</div>
				<div class="asdl-fin-tool-grid">
					<div class="asdl-fin-tool-card">
						<h3>Perfil puntual</h3>
						<div class="asdl-fin-form-grid">
							<?php
							$this->render_contact_picker(
								'balance_audit_contact_id',
								'Perfil a auditar',
								0,
								false,
								array(
									'placeholder'          => 'Busca por nombre, correo o ID',
									'help'                 => 'Selecciona un perfil para comparar su snapshot, cola por cobrar y cola por pagar con las mismas reglas del dashboard.',
									'field_class'          => 'asdl-fin-field-wide',
									'fallback_options'     => $contacts_repository->options(),
									'fallback_placeholder' => 'Selecciona un perfil',
								)
							);
							?>
						</div>
						<div class="asdl-fin-inline-actions">
							<button type="button" class="button button-primary" data-balance-audit-contact>Auditar perfil</button>
							<button type="button" class="button button-secondary" data-balance-audit-dashboard>Auditar dashboard</button>
							<button type="button" class="button button-secondary" data-balance-audit-mobile>Auditar API movil</button>
						</div>
					</div>
					<div class="asdl-fin-tool-card">
						<h3>Fuentes auditadas</h3>
						<div class="asdl-fin-data-grid asdl-fin-data-grid-tight">
							<div><strong>Perfil por cobrar</strong><span>Snapshot financiero del perfil</span></div>
							<div><strong>Perfil saldo a favor</strong><span>Credito total del perfil</span></div>
							<div><strong>Perfil disponible hoy</strong><span>Credito utilizable hoy</span></div>
							<div><strong>Dashboard por cobrar</strong><span>Cola agrupada de pendientes por cobrar</span></div>
							<div><strong>Dashboard por pagar</strong><span>Cola agrupada de pendientes por pagar</span></div>
							<div><strong>Movil</strong><span>Debe consumir las mismas fuentes que la web.</span></div>
						</div>
						<small>La tolerancia aceptable es de 0.01 USD. Si algo falla, se corrige en el servicio base y no en la UI.</small>
					</div>
				</div>
				<div class="asdl-fin-tool-detail" data-balance-audit-results>
					<div class="asdl-fin-empty">
						<strong>Sin auditoria ejecutada.</strong>
						<p>Usa los botones de arriba para revisar perfil, dashboard o la paridad de la API movil con el mismo rango fiscal activo.</p>
					</div>
				</div>
			</section>

			<div class="asdl-fin-panel-grid asdl-fin-settings-catalog-grid">
				<section class="asdl-fin-panel asdl-fin-settings-payment-panel">
					<div class="asdl-fin-panel-header">
						<h2>Metodos de pago</h2>
						<p>Catalogo central para cobros, pagos, abonos y nomina. Aqui tambien decides cuales metodos califican para precio dual cuando la moneda es USD.</p>
					</div>
					<div class="asdl-fin-inline-actions">
						<button type="button" class="button button-secondary asdl-fin-open-modal" data-modal-target="payment-method" data-payment-method-open="1">
							Agregar metodo
						</button>
					</div>
					<div class="asdl-fin-note-box asdl-fin-payment-method-catalog-feedback is-hidden" data-payment-method-catalog-feedback role="status" aria-live="polite"></div>
					<p class="asdl-fin-dashboard-filter-help">El precio dual solo aplica si el descuento global esta activo, la moneda del abono/cobro es <code>USD</code> y el metodo aparece como elegible en esta tabla o por integracion externa.</p>
					<div class="asdl-fin-note-box asdl-fin-settings-dual-guide">
						<strong>Como se activa el precio dual</strong>
						<p>1. El descuento global debe estar activo.</p>
						<p>2. La moneda del cobro o abono debe ser <code>USD</code>.</p>
						<p>3. El metodo debe quedar <strong>Elegible</strong> por <strong>Catalogo ASD</strong> o por <strong>Integracion externa</strong>.</p>
						<p>Usa <strong>Configurar</strong> para cambiar la elegibilidad de cualquier metodo del catalogo ASD.</p>
					</div>
					<?php $this->render_payment_methods_table( $payment_methods ); ?>
				</section>

				<section class="asdl-fin-panel asdl-fin-settings-currency-panel">
					<div class="asdl-fin-panel-header">
						<h2>Monedas</h2>
						<p>Catalogo central para sueldos, movimientos, cobros, pagos, compromisos, adelantos y nomina.</p>
					</div>
					<div class="asdl-fin-inline-actions">
						<button type="button" class="button button-secondary asdl-fin-open-modal" data-modal-target="currency" data-currency-open="1">
							Agregar moneda
						</button>
					</div>
					<div class="asdl-fin-note-box asdl-fin-payment-method-catalog-feedback is-hidden" data-currency-catalog-feedback role="status" aria-live="polite"></div>
					<?php $this->render_currencies_table( $currencies ); ?>
				</section>

				<section class="asdl-fin-panel asdl-fin-settings-receipt-panel">
					<div class="asdl-fin-panel-header">
						<h2>Comprobantes</h2>
						<p>Controla si los comprobantes imprimibles usan el logo del sitio, uno propio o si deben salir sin logo.</p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
						<input type="hidden" name="action" value="asdl_fin_save_receipt_branding" />
						<?php wp_nonce_field( 'asdl_fin_save_receipt_branding' ); ?>
						<?php $this->render_select( 'logo_mode', 'Logo en comprobantes', array( 'site_logo' => 'Usar logo del sitio', 'custom_logo' => 'Usar logo personalizado', 'none' => 'Sin logo' ), true, '', $receipt_brand['logo_mode'] ?? 'site_logo' ); ?>
						<div class="asdl-fin-field asdl-fin-field-wide" data-receipt-logo-custom-field <?php echo 'custom_logo' === ( $receipt_brand['logo_mode'] ?? 'site_logo' ) ? '' : 'style="display:none"'; ?>>
							<span>Logo personalizado</span>
							<input type="hidden" name="custom_logo_id" id="asdl-fin-receipt-logo-id" value="<?php echo esc_attr( (int) ( $receipt_brand['custom_logo_id'] ?? 0 ) ); ?>" />
							<div class="asdl-fin-inline-actions">
								<button type="button" class="button button-secondary asdl-fin-select-media" data-target-input="asdl-fin-receipt-logo-id" data-target-preview="asdl-fin-receipt-logo-preview" data-frame-title="Seleccionar logo para comprobantes" data-button-text="Usar este logo">Seleccionar logo</button>
								<button type="button" class="button button-secondary asdl-fin-clear-media" data-target-input="asdl-fin-receipt-logo-id" data-target-preview="asdl-fin-receipt-logo-preview">Quitar</button>
							</div>
							<div class="asdl-fin-logo-preview-wrap">
								<?php $custom_preview = ! empty( $receipt_brand['custom_logo_id'] ) ? wp_get_attachment_image_url( (int) $receipt_brand['custom_logo_id'], 'medium' ) : ''; ?>
								<img id="asdl-fin-receipt-logo-preview" class="asdl-fin-logo-preview" src="<?php echo esc_url( $custom_preview ?: '' ); ?>" alt="" <?php echo $custom_preview ? '' : 'hidden'; ?> />
							</div>
							<small>Por defecto el sistema usa el logo del sitio. Si no existe, intenta usar el icono del sitio.</small>
						</div>
						<div class="asdl-fin-field asdl-fin-field-wide">
							<span>Resolucion actual</span>
							<div class="asdl-fin-data-grid">
								<div><strong>Modo</strong><span><?php echo esc_html( 'site_logo' === ( $receipt_brand['logo_mode'] ?? '' ) ? 'Logo del sitio' : ( 'custom_logo' === ( $receipt_brand['logo_mode'] ?? '' ) ? 'Logo personalizado' : 'Sin logo' ) ); ?></span></div>
								<div><strong>Salida actual</strong><span><?php echo esc_html( $receipt_brand['resolved_source'] ?? 'Sin logo' ); ?></span></div>
							</div>
							<?php if ( ! empty( $receipt_brand['resolved_logo'] ) ) : ?>
								<div class="asdl-fin-logo-preview-wrap">
									<img class="asdl-fin-logo-preview" src="<?php echo esc_url( $receipt_brand['resolved_logo'] ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
								</div>
							<?php endif; ?>
							<small>Predeterminado: activo con el logo del sitio o su icono.</small>
						</div>
						<?php submit_button( 'Guardar configuracion de comprobantes', 'primary', 'submit', false ); ?>
					</form>
				</section>
			</div>
		</div>
		<?php
	}

	private function render_action_link( $url, $title, $description ) {
		return sprintf(
			'<a class="asdl-fin-action-card" href="%1$s"><strong>%2$s</strong><p>%3$s</p></a>',
			esc_url( $this->with_current_context_url( $url ) ),
			esc_html( $title ),
			esc_html( $description )
		);
	}

	private function render_dashboard_stat_card( $label, $value, $description, $url ) {
		return sprintf(
			'<a class="asdl-fin-card asdl-fin-card-link" href="%1$s"><span class="asdl-fin-label">%2$s</span><strong>%3$s</strong><p>%4$s</p><span class="asdl-fin-card-action">Abrir</span></a>',
			esc_url( $this->with_current_context_url( $url ) ),
			esc_html( $label ),
			wp_kses_post( $value ),
			wp_kses_post( $description )
		);
	}

	private function render_profile_reference( $contact_id, $display_name, $email = '', $fallback = 'Sin perfil enlazado' ) {
		$display_name = sanitize_text_field( (string) $display_name );
		$email        = sanitize_email( (string) $email );
		$fallback     = sanitize_text_field( (string) $fallback );

		if ( $contact_id > 0 && '' !== $display_name ) {
			return sprintf(
				'Perfil: <a href="%1$s">%2$s</a>',
				esc_url( $this->contact_detail_url( $contact_id ) ),
				esc_html( $display_name )
			);
		}

		if ( '' !== $display_name ) {
			return 'Perfil: ' . esc_html( $display_name );
		}

		if ( '' !== $email ) {
			return 'Contacto: ' . esc_html( $email );
		}

		return esc_html( $fallback );
	}

	private function source_order_edit_url( $object_type, $external_id ) {
		$object_type = sanitize_key( (string) $object_type );
		$order_id    = absint( $external_id );

		if ( 'shop_order' !== $object_type || $order_id <= 0 ) {
			return '';
		}

		if ( function_exists( 'get_edit_post_link' ) ) {
			$edit_link = get_edit_post_link( $order_id, '' );
			if ( $edit_link ) {
				return $edit_link;
			}
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	private function render_order_selection_modal() {
		?>
		<div class="asdl-fin-modal asdl-fin-order-modal" data-modal="dashboard-order-list" hidden>
			<div class="asdl-fin-modal-overlay" data-modal-close></div>
			<div class="asdl-fin-modal-dialog">
				<div class="asdl-fin-modal-header">
					<div>
						<h2 data-order-list-title>Pendientes agrupados</h2>
						<p>Revisa pedidos y otros cobros abiertos del grupo antes de abrirlos o gestionarlos.</p>
					</div>
					<button type="button" class="button button-secondary asdl-fin-modal-close-button" data-modal-close>Cerrar</button>
				</div>
				<div class="asdl-fin-order-list" data-order-list-body>
					<div class="asdl-fin-empty">
						<strong>Sin pendientes disponibles.</strong>
						<p>Cuando haya pedidos, documentos, prestamos o adelantos asociados al grupo, apareceran aqui.</p>
					</div>
				</div>
				<div class="asdl-fin-inline-actions">
					<button type="button" class="button button-secondary" data-modal-close>Cerrar</button>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_order_settlement_preview_modal() {
		?>
		<div class="asdl-fin-modal asdl-fin-settlement-preview-modal" data-modal="order-settlement-preview" hidden>
			<div class="asdl-fin-modal-overlay" data-modal-close></div>
			<div class="asdl-fin-modal-dialog">
				<div class="asdl-fin-modal-header">
					<div>
						<h2 data-settlement-preview-title>Vista previa del abono</h2>
						<p data-settlement-preview-description>Revisa como quedaran los pedidos antes de aplicar el abono. Si el metodo usa precio dual, el descuento se mostrara aqui.</p>
					</div>
					<button type="button" class="button button-secondary asdl-fin-modal-close-button" data-modal-close>Cerrar</button>
				</div>
				<div class="asdl-fin-settlement-preview-body" data-settlement-preview-body>
					<div class="asdl-fin-empty">
						<strong>Sin simulacion cargada.</strong>
						<p>Selecciona el metodo y el monto del abono para calcular la vista previa.</p>
					</div>
				</div>
				<div class="asdl-fin-inline-actions asdl-fin-settlement-preview-actions">
					<button type="button" class="button button-secondary" data-modal-close data-settlement-preview-secondary>Cancelar</button>
					<button type="button" class="button button-primary" data-settlement-preview-confirm disabled>Confirmar y aplicar</button>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_order_assumption_modal() {
		?>
		<div class="asdl-fin-modal asdl-fin-assumption-preview-modal" data-modal="order-assumption-preview" hidden>
			<div class="asdl-fin-modal-overlay" data-modal-close></div>
			<div class="asdl-fin-modal-dialog">
				<div class="asdl-fin-modal-header">
					<div>
						<h2 data-order-assumption-title>Gestionar pedidos como gasto o regalo</h2>
						<p data-order-assumption-description>Revisa el impacto, marca los pedidos que quieras incluir y aplicalos como gasto o regalo interno sin tratarlos como cobro real.</p>
					</div>
					<button type="button" class="button button-secondary asdl-fin-modal-close-button" data-modal-close>Cerrar</button>
				</div>
				<div class="asdl-fin-stack">
					<div class="asdl-fin-note-box" data-order-assumption-context>
						<strong>Sin perfil seleccionado.</strong>
						<div>Abre esta herramienta desde el perfil o desde la cola de pendientes para revisar qu pedidos se gestionaran como gasto o regalo.</div>
					</div>
					<div class="asdl-fin-form-grid asdl-fin-assumption-config">
						<label class="asdl-fin-field">
							<span>Modo</span>
							<select data-order-assumption-mode>
								<option value="expense">Gasto</option>
								<option value="gift">Regalo</option>
							</select>
							<small>Regalo se reporta aparte, pero tambien se asume como consumo interno.</small>
						</label>
						<label class="asdl-fin-field" data-order-assumption-approved-wrapper hidden>
							<span>Aprobado por</span>
							<input type="text" value="" data-order-assumption-approved placeholder="Nombre o referencia de aprobacion" />
							<small>Obligatorio cuando el modo sea Regalo.</small>
						</label>
						<label class="asdl-fin-field asdl-fin-field-wide">
							<span>Nota operativa</span>
							<textarea rows="3" data-order-assumption-note placeholder="Explica por qu estos pedidos se gestionaran como gasto o regalo interno."></textarea>
							<small>Esta nota se guardara en el lote, en el documento y en el pedido Woo/OpenPOS.</small>
						</label>
						<label class="asdl-fin-field asdl-fin-field-wide" data-order-assumption-confirm-wrapper hidden>
							<span>Confirmacion adicional</span>
							<label class="asdl-fin-inline-checkbox">
								<input type="checkbox" value="1" data-order-assumption-confirm-non-internal />
								<span>Confirmo que asumire estos pedidos sobre un perfil no marcado como interno.</span>
							</label>
							<small>Usa esto solo cuando, operativamente, el perfil represente gasto interno o regalos de la tienda.</small>
						</label>
					</div>
				</div>
				<div class="asdl-fin-settlement-preview-body" data-order-assumption-body>
					<div class="asdl-fin-empty">
						<strong>Sin vista previa cargada.</strong>
						<p>Selecciona el perfil y configura el modo para validar qu pedidos podran gestionarse.</p>
					</div>
				</div>
				<div class="asdl-fin-inline-actions asdl-fin-settlement-preview-actions">
					<button type="button" class="button button-secondary" data-modal-close data-order-assumption-secondary>Cerrar</button>
					<button type="button" class="button button-secondary" data-order-assumption-preview disabled>Revisar pedidos</button>
					<button type="button" class="button button-primary" data-order-assumption-confirm disabled>Aplicar como gasto/regalo</button>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_payroll_manual_settlement_modal() {
		?>
		<div class="asdl-fin-modal asdl-fin-payroll-debt-modal" data-modal="payroll-manual-settlement" hidden>
			<div class="asdl-fin-modal-overlay" data-modal-close></div>
			<div class="asdl-fin-modal-dialog">
				<div class="asdl-fin-modal-header">
					<div>
						<h2>Detalles de deuda para nomina</h2>
						<p>Configura un descuento manual desde esta nomina sin salir de la cola operativa.</p>
					</div>
					<button type="button" class="button button-secondary asdl-fin-modal-close-button" data-modal-close>Cerrar</button>
				</div>
				<div class="asdl-fin-payroll-debt-body" data-payroll-debt-body>
					<div class="asdl-fin-empty">
						<strong>Sin empleado seleccionado.</strong>
						<p>Abre los detalles desde la fila del empleado para revisar deudas y preparar un abono manual.</p>
					</div>
				</div>
				<div class="asdl-fin-inline-actions asdl-fin-payroll-debt-actions">
					<button type="button" class="button button-secondary" data-modal-close>Cancelar</button>
					<button type="button" class="button button-primary" data-payroll-debt-confirm disabled>Usar en esta nomina</button>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_payroll_payment_modal() {
		?>
		<div class="asdl-fin-modal asdl-fin-payroll-payment-modal" data-modal="payroll-payment" hidden>
			<div class="asdl-fin-modal-overlay" data-modal-close></div>
			<div class="asdl-fin-modal-dialog">
				<div class="asdl-fin-modal-header">
					<div>
						<h2 data-payroll-payment-title>Procesar pago de nomina</h2>
						<p data-payroll-payment-description>Confirma cuenta, metodo, referencia y notas antes de registrar el pago.</p>
					</div>
					<button type="button" class="button button-secondary asdl-fin-modal-close-button" data-modal-close>Cerrar</button>
				</div>
				<div class="asdl-fin-payroll-payment-body" data-payroll-payment-body>
					<div class="asdl-fin-empty">
						<strong>Sin empleado seleccionado.</strong>
						<p>Abre el pago desde la cola o desde el perfil del empleado para gestionarlo dentro de este modal.</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private function payroll_payment_template_id( $scope, $contact_id, $payroll_id ) {
		return sprintf(
			'asdl-fin-payroll-payment-%1$s-%2$d-%3$d',
			sanitize_key( (string) $scope ),
			max( 0, (int) $contact_id ),
			max( 0, (int) $payroll_id )
		);
	}

	private function render_payroll_payment_form_template( $template_id, array $context, array $account_options, array $manual_summary = array() ) {
		$manual_has_debts = ! empty( $manual_summary['has_open_debts'] );
		$title            = sanitize_text_field( (string) ( $context['title'] ?? 'Periodo de nomina' ) );
		$description      = sanitize_text_field( (string) ( $context['description'] ?? 'Confirma cuenta, metodo y notas antes de registrar el pago.' ) );
		$return_page      = sanitize_key( (string) ( $context['return_page'] ?? 'asdl-fin-payroll' ) );
		$contact_id       = (int) ( $context['contact_id'] ?? 0 );
		$payroll_id       = (int) ( $context['payroll_id'] ?? 0 );
		$paid_at          = sanitize_text_field( (string) ( $context['paid_at'] ?? gmdate( 'Y-m-d' ) ) );
		$currency         = sanitize_text_field( (string) ( $context['currency'] ?? 'USD' ) );
		$cash_preview     = (float) ( $context['cash_preview'] ?? 0 );
		$net_preview      = (float) ( $context['net_preview'] ?? 0 );
		$account_fallback = (int) ( $context['account_fallback'] ?? 0 );
		$payment_account  = (int) ( $context['payment_account_id'] ?? $account_fallback );
		$payment_method   = sanitize_key( (string) ( $context['payment_method_key'] ?? 'bank_transfer' ) );
		?>
		<template id="<?php echo esc_attr( $template_id ); ?>">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-stack asdl-fin-payroll-process-form asdl-fin-payroll-process-modal-form" data-payroll-process-modal-form data-payroll-manual-settlement-form data-payroll-contact-id="<?php echo esc_attr( $contact_id ); ?>" data-payroll-currency="<?php echo esc_attr( $currency ); ?>" data-payroll-cash-preview="<?php echo esc_attr( $cash_preview ); ?>" data-payroll-net-preview="<?php echo esc_attr( $net_preview ); ?>" data-payroll-account-fallback="<?php echo esc_attr( $account_fallback ); ?>">
				<input type="hidden" name="action" value="asdl_fin_mark_payroll_period_paid" />
				<input type="hidden" name="return_page" value="<?php echo esc_attr( $return_page ); ?>" />
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( $contact_id ); ?>" />
				<input type="hidden" name="payroll_id" value="<?php echo esc_attr( $payroll_id ); ?>" />
				<input type="hidden" name="paid_at" value="<?php echo esc_attr( $paid_at ); ?>" />
				<input type="hidden" name="payroll_manual_settlement_target_type" value="" data-payroll-manual-target-type />
				<input type="hidden" name="payroll_manual_settlement_target_id" value="0" data-payroll-manual-target-id />
				<input type="hidden" name="payroll_manual_settlement_amount" value="" data-payroll-manual-amount />
				<input type="hidden" name="payroll_manual_settlement_force_dual" value="0" data-payroll-manual-force-dual />
				<input type="hidden" name="payroll_manual_settlement_preview_signature" value="" data-payroll-manual-preview-signature />
				<?php $this->render_current_fiscal_hidden_input(); ?>
				<?php wp_nonce_field( 'asdl_fin_mark_payroll_period_paid' ); ?>
				<div class="asdl-fin-note-box">
					<strong><?php echo esc_html( $title ); ?></strong>
					<div><?php echo esc_html( $description ); ?></div>
				</div>
				<label class="asdl-fin-field">
					<span>Cuenta</span>
					<select name="payment_account_id">
						<option value="">Usar sugerida</option>
						<?php echo $this->render_select_options( $account_options, (string) $payment_account ); ?>
					</select>
				</label>
				<?php $this->render_payment_method_select( 'payment_method_key', 'Metodo', $payment_method, true ); ?>
				<?php $this->render_input( 'reference', 'Referencia', 'text', '' ); ?>
				<?php $this->render_textarea( 'notes', 'Notas', 'Opcional: observacion corta del pago de nomina.' ); ?>
				<div class="asdl-fin-field asdl-fin-field-wide">
					<span>Abono manual desde nomina</span>
					<div class="asdl-fin-payroll-manual-toolbar">
						<button type="button" class="button button-secondary small asdl-fin-payroll-debt-button<?php echo $manual_has_debts ? ' is-alert' : ''; ?>" data-payroll-debt-open>
							<?php echo esc_html( $manual_has_debts ? sprintf( 'Detalles (%d)', (int) ( $manual_summary['target_count'] ?? 0 ) ) : 'Detalles' ); ?>
						</button>
						<button type="button" class="button button-secondary small" data-payroll-manual-clear hidden>Quitar</button>
					</div>
					<div class="asdl-fin-payroll-manual-summary" data-payroll-manual-summary>
						<?php if ( $manual_has_debts ) : ?>
							<small>Deudas abiertas detectadas: <?php echo wp_kses_post( $this->format_money( $manual_summary['total_amount'] ?? 0, $currency ) ); ?> en <?php echo esc_html( number_format_i18n( (int) ( $manual_summary['target_count'] ?? 0 ) ) ); ?> destino(s).</small>
						<?php else : ?>
							<small>Sin deudas abiertas para configurar un descuento manual desde esta nomina.</small>
						<?php endif; ?>
					</div>
				</div>
				<div class="asdl-fin-inline-actions asdl-fin-payroll-payment-submit">
					<?php submit_button( 'Procesar pago', 'primary', 'submit', false ); ?>
				</div>
			</form>
		</template>
		<?php
	}

	private function get_capability() {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	private function format_money( $amount, $currency = '' ) {
		if ( function_exists( 'wc_price' ) ) {
			$args = array();
			if ( '' !== $currency ) {
				$args['currency'] = sanitize_text_field( $currency );
			}

			return wc_price( $amount, $args );
		}

		return '$' . number_format_i18n( (float) $amount, 2 );
	}

	private function build_historical_year_options( array $years, $indexed_only = false, $closable_only = false ) {
		$options = array();

		foreach ( $years as $row ) {
			$fiscal_year = absint( $row['fiscal_year'] ?? 0 );
			if ( $fiscal_year <= 0 ) {
				continue;
			}

			if ( $indexed_only && empty( $row['status'] ) ) {
				continue;
			}

			if ( $indexed_only && 'indexed' !== sanitize_key( (string) ( $row['status'] ?? '' ) ) ) {
				continue;
			}

			if ( $closable_only && empty( $row['is_closable'] ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $row['label'] ?? ( 'FY ' . $fiscal_year ) ) );
			if ( $indexed_only ) {
				$label .= ' · indexado';
			}
			if ( ! empty( $row['compacted_at'] ) ) {
				$label .= ' · compactado';
			}
			if ( ! empty( $row['is_special_case'] ) ) {
				$label .= ' · caso especial admin';
			}

			$options[ $fiscal_year ] = $label;
		}

		return $options;
	}

	private function render_historical_batches_table( array $batches ) {
		if ( empty( $batches ) ) {
			echo '<div class="asdl-fin-empty"><strong>Sin lotes aplicados.</strong><p>Cuando ejecutes cierres administrativos historicos, apareceran aqui con su cantidad y monto total.</p></div>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Lote</th>
					<th>Rango fiscal</th>
					<th>Estado</th>
					<th>Pedidos</th>
					<th>Total</th>
					<th>Procesado</th>
					<th>Accion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $batches as $batch ) : ?>
					<tr>
						<td><strong>#<?php echo esc_html( (int) ( $batch['id'] ?? 0 ) ); ?></strong><br /><small><?php echo esc_html( sanitize_text_field( (string) ( $batch['reason_key'] ?? '' ) ) ); ?></small></td>
						<td><?php echo esc_html( sprintf( 'FY %1$d a FY %2$d', (int) ( $batch['fiscal_year_from'] ?? 0 ), (int) ( $batch['fiscal_year_to'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( sanitize_text_field( (string) ( $batch['status'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $batch['item_count'] ?? 0 ) ) ); ?></td>
						<td><?php echo wp_kses_post( $this->format_money( (float) ( $batch['balance_total'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $batch['processed_count'] ?? 0 ) ) ); ?> / <?php echo esc_html( number_format_i18n( (int) ( $batch['item_count'] ?? 0 ) ) ); ?></td>
						<td>
							<button type="button" class="button button-secondary small" data-historical-batch-detail="<?php echo esc_attr( (int) ( $batch['id'] ?? 0 ) ); ?>">Ver detalle</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_page_open( $title, $description ) {
		printf(
			'<div class="wrap asdl-fin-wrap"><div class="asdl-fin-page-heading"><h1>%1$s</h1><p>%2$s</p></div>',
			esc_html( $title ),
			esc_html( $description )
		);
		$this->render_fiscal_context_toolbar();
		$this->render_fiscal_readonly_notice();
		$this->render_payment_method_modal();
		$this->render_currency_modal();
		$this->render_order_settlement_preview_modal();
		$this->render_order_assumption_modal();
		$this->render_payroll_payment_modal();
		$this->render_payroll_manual_settlement_modal();
	}

	private function render_page_close() {
		echo '</div>';
	}

	private function render_runtime_card_skeleton( $count = 6 ) {
		$count = max( 1, (int) $count );
		?>
		<div class="asdl-fin-card-grid">
			<?php for ( $index = 0; $index < $count; $index++ ) : ?>
				<div class="asdl-fin-card asdl-fin-runtime-skeleton-card" aria-hidden="true">
					<div class="asdl-fin-runtime-line asdl-fin-runtime-line-label"></div>
					<div class="asdl-fin-runtime-line asdl-fin-runtime-line-value"></div>
					<div class="asdl-fin-runtime-line"></div>
					<div class="asdl-fin-runtime-line asdl-fin-runtime-line-short"></div>
				</div>
			<?php endfor; ?>
		</div>
		<?php
	}

	private function render_runtime_panel_skeleton( $title, $description ) {
		?>
		<section class="asdl-fin-panel asdl-fin-runtime-skeleton-panel">
			<div class="asdl-fin-panel-header">
				<h2><?php echo esc_html( $title ); ?></h2>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="asdl-fin-runtime-stack" aria-hidden="true">
				<div class="asdl-fin-runtime-line"></div>
				<div class="asdl-fin-runtime-line"></div>
				<div class="asdl-fin-runtime-line asdl-fin-runtime-line-short"></div>
			</div>
		</section>
		<?php
	}

	private function render_runtime_table_skeleton( $title, $description, $columns = 5, $rows = 5 ) {
		$columns = max( 3, (int) $columns );
		$rows    = max( 3, (int) $rows );
		?>
		<section class="asdl-fin-panel asdl-fin-runtime-skeleton-panel asdl-fin-runtime-table-panel">
			<div class="asdl-fin-panel-header">
				<h2><?php echo esc_html( $title ); ?></h2>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="asdl-fin-table-wrap asdl-fin-runtime-table-skeleton" aria-hidden="true">
				<table class="widefat striped asdl-fin-table">
					<thead>
						<tr>
							<?php for ( $column = 0; $column < $columns; $column++ ) : ?>
								<th><span class="asdl-fin-runtime-line asdl-fin-runtime-line-table-head"></span></th>
							<?php endfor; ?>
						</tr>
					</thead>
					<tbody>
						<?php for ( $row = 0; $row < $rows; $row++ ) : ?>
							<tr>
								<?php for ( $column = 0; $column < $columns; $column++ ) : ?>
									<td><span class="asdl-fin-runtime-line asdl-fin-runtime-line-table-cell"></span></td>
								<?php endfor; ?>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	private function render_runtime_contact_skeleton() {
		?>
		<div class="asdl-fin-card-grid" aria-hidden="true">
			<?php for ( $index = 0; $index < 6; $index++ ) : ?>
				<div class="asdl-fin-card asdl-fin-runtime-skeleton-card">
					<div class="asdl-fin-runtime-line asdl-fin-runtime-line-label"></div>
					<div class="asdl-fin-runtime-line asdl-fin-runtime-line-value"></div>
					<div class="asdl-fin-runtime-line"></div>
					<div class="asdl-fin-runtime-line asdl-fin-runtime-line-short"></div>
				</div>
			<?php endfor; ?>
		</div>
		<div class="asdl-fin-panel-grid" aria-hidden="true">
			<?php for ( $index = 0; $index < 3; $index++ ) : ?>
				<section class="asdl-fin-panel asdl-fin-runtime-skeleton-panel">
					<div class="asdl-fin-runtime-stack">
						<div class="asdl-fin-runtime-line"></div>
						<div class="asdl-fin-runtime-line"></div>
						<div class="asdl-fin-runtime-line asdl-fin-runtime-line-short"></div>
					</div>
				</section>
			<?php endfor; ?>
		</div>
		<?php
	}

	private function render_construction_page( $title, $description, array $items ) {
		$this->render_page_open( $title, $description );
		?>
		<section class="asdl-fin-panel">
			<div class="asdl-fin-panel-header">
				<h2>Fase de construccion</h2>
				<p>Este modulo ya queda contemplado en la arquitectura general, pero su flujo funcional aun no forma parte del MVP actual.</p>
			</div>
			<div class="asdl-fin-card-grid">
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Estado</span>
					<strong>En definicion</strong>
					<p>Base sembrada en menu, documentacion y direccion del producto.</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Objetivo</span>
					<strong>Modulo propio</strong>
					<p>Se desarrollara dentro de la suite, sin depender de ATUM.</p>
				</div>
			</div>
			<div class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Alcance previsto</h2>
					<p>Resumen base para que el modulo entre luego por fases con criterio claro.</p>
				</div>
				<ul class="asdl-fin-checklist">
					<?php foreach ( $items as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
		<?php
		$this->render_page_close();
	}

	private function current_fiscal_context() {
		static $context = null;

		if ( null !== $context ) {
			return $context;
		}

		$selected_year = absint( wp_unslash( $_GET['fiscal_year'] ?? 0 ) );
		$context       = ( new FiscalYearService() )->get_context( $selected_year );

		return $context;
	}

	private function is_fiscal_readonly_context() {
		$context = $this->current_fiscal_context();

		return empty( $context['is_active'] );
	}

	private function with_current_context_url( $url ) {
		$selected_year = absint( wp_unslash( $_GET['fiscal_year'] ?? 0 ) );
		if ( $selected_year <= 0 ) {
			return $url;
		}

		return add_query_arg( 'fiscal_year', $selected_year, $url );
	}

	private function render_current_fiscal_hidden_input() {
		$selected_year = absint( wp_unslash( $_GET['fiscal_year'] ?? 0 ) );
		if ( $selected_year <= 0 ) {
			return;
		}

		printf(
			'<input type="hidden" name="fiscal_year" value="%d" />',
			$selected_year
		);
	}

	private function render_current_context_hidden_inputs( $fallback_page = '' ) {
		$return_page = '' !== $fallback_page ? sanitize_key( $fallback_page ) : $this->current_page_slug();
		$contact_id  = $this->current_contact_id();
		$keys        = array(
			'range_from',
			'range_to',
			'order_limit',
			'from_date',
			'to_date',
			'limit',
			'expense_search',
			'expense_financial_status',
			'expense_payment_status',
			'expense_range_from',
			'expense_range_to',
			'expense_open_only',
			'expense_has_contact',
			'expense_contact_id',
		);

		printf(
			'<input type="hidden" name="return_page" value="%s" />',
			esc_attr( $return_page )
		);

		if ( $contact_id > 0 ) {
			printf(
				'<input type="hidden" name="contact_id" value="%d" />',
				$contact_id
			);
		}

		foreach ( $keys as $key ) {
			$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';
			if ( '' === $value ) {
				continue;
			}

			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( $key ),
				esc_attr( sanitize_text_field( (string) $value ) )
			);
		}

		$this->render_current_fiscal_hidden_input();
	}

	private function render_cancel_action_form( $action, $id_field, $id, $nonce_action, $placeholder = 'Motivo de anulacion', $button_label = 'Anular' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-cancel-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( sanitize_key( $action ) ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( sanitize_key( $id_field ) ); ?>" value="<?php echo esc_attr( absint( $id ) ); ?>" />
			<?php $this->render_current_context_hidden_inputs(); ?>
			<?php wp_nonce_field( $nonce_action ); ?>
			<div class="asdl-fin-inline-actions">
				<input type="text" name="cancel_reason" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" required />
				<?php submit_button( $button_label, 'secondary small', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

	private function render_fiscal_context_toolbar() {
		$context = $this->current_fiscal_context();
		$years   = ( new FiscalYearService() )->available_years( 6, 0 );
		$is_readonly = $this->is_fiscal_readonly_context();
		?>
		<section class="asdl-fin-panel asdl-fin-fiscal-toolbar">
			<details class="asdl-fin-fiscal-details" <?php echo $is_readonly ? 'open' : ''; ?>>
				<summary class="asdl-fin-fiscal-summary">
					<div class="asdl-fin-fiscal-summary-copy">
						<strong>Ejercicio fiscal</strong>
						<span>
							<?php
							echo esc_html(
								sprintf(
									'%1$s · %2$s al %3$s',
									$context['label'] ?? '',
									$context['start_date'] ?? '',
									$context['end_date'] ?? ''
								)
							);
							?>
						</span>
					</div>
					<div class="asdl-fin-fiscal-summary-meta">
						<?php echo wp_kses_post( $this->render_pill( $is_readonly ? 'Modo consulta' : 'Ejercicio activo', $is_readonly ? 'warning' : 'success' ) ); ?>
						<span class="asdl-fin-fiscal-summary-action"><?php echo $is_readonly ? 'Ajustar contexto' : 'Cambiar ejercicio'; ?></span>
					</div>
				</summary>
				<div class="asdl-fin-fiscal-panel-body">
					<div class="asdl-fin-panel-header">
						<h2>Ejercicio fiscal</h2>
						<p>El sistema opera por defecto en el ejercicio activo. Si consultas otro ejercicio entras en modo historico sin cambios operativos.</p>
					</div>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="asdl-fin-form-grid">
						<input type="hidden" name="page" value="<?php echo esc_attr( $this->current_page_slug() ); ?>" />
						<?php foreach ( $_GET as $key => $value ) : ?>
							<?php
							if ( in_array( $key, array( 'page', 'fiscal_year', 'asdl_fin_notice', 'asdl_fin_notice_text' ), true ) || is_array( $value ) ) {
								continue;
							}
							?>
							<input type="hidden" name="<?php echo esc_attr( sanitize_key( (string) $key ) ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( (string) $value ) ) ); ?>" />
						<?php endforeach; ?>
						<label class="asdl-fin-field">
							<span>Ejercicio consultado</span>
							<select name="fiscal_year">
								<?php echo $this->render_select_options( $years, (string) ( $context['start_year'] ?? '' ) ); ?>
							</select>
						</label>
						<div class="asdl-fin-field">
							<span>Periodo</span>
							<div class="asdl-fin-note-box">
								<?php
								echo esc_html(
									sprintf(
										'%1$s al %2$s',
										$context['start_date'] ?? '',
										$context['end_date'] ?? ''
									)
								);
								?>
							</div>
						</div>
						<div class="asdl-fin-field asdl-fin-field-wide">
							<span>Contexto</span>
							<div class="asdl-fin-inline-actions">
								<?php submit_button( 'Aplicar ejercicio', 'secondary', '', false ); ?>
								<?php if ( $is_readonly ) : ?>
									<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => $this->current_page_slug(), 'fiscal_year' => (int) ( $context['settings']['active_start_year'] ?? 0 ) ), admin_url( 'admin.php' ) ) ); ?>">Volver al ejercicio activo</a>
								<?php endif; ?>
							</div>
							<small>Activo: <?php echo esc_html( $context['active_label'] ?? '' ); ?>. El ejercicio seleccionado controla filtros y vistas historicas.</small>
						</div>
					</form>
				</div>
			</details>
		</section>
		<?php
	}

	private function render_fiscal_readonly_notice() {
		if ( ! $this->is_fiscal_readonly_context() ) {
			return;
		}

		$context = $this->current_fiscal_context();
		?>
		<div class="notice notice-warning">
			<p>
				<strong>Modo consulta historica.</strong>
				Estas viendo <?php echo esc_html( $context['label'] ?? 'otro ejercicio fiscal' ); ?>.
				Los formularios operativos quedan bloqueados para evitar registrar movimientos o compromisos en un ejercicio que no esta activo.
			</p>
		</div>
		<?php
	}

	private function render_fiscal_readonly_action_state( $title = 'Accion disponible solo en el ejercicio activo.', $description = '' ) {
		$context = $this->current_fiscal_context();

		if ( '' === $description ) {
			$description = sprintf(
				'Estas viendo %1$s en modo consulta. Para evitar errores operativos, este formulario queda bloqueado hasta volver a %2$s.',
				$context['label'] ?? 'un ejercicio historico',
				$context['active_label'] ?? 'el ejercicio activo'
			);
		}

		$this->render_empty_state( $title, $description );
	}

	private function render_input( $name, $label, $type, $value = '', $required = false, array $attributes = array() ) {
		$is_date = 'date' === $type;

		if ( $is_date && ! isset( $attributes['data-date-weekday-input'] ) ) {
			$attributes['data-date-weekday-input'] = '1';
		}

		$attributes = array_merge(
			array(
				'class' => 'regular-text',
			),
			$attributes
		);
		?>
		<label class="asdl-fin-field">
			<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				<?php foreach ( $attributes as $attr_name => $attr_value ) : ?>
					<?php echo esc_attr( $attr_name ); ?>="<?php echo esc_attr( $attr_value ); ?>"
				<?php endforeach; ?>
				<?php echo $required ? 'required' : ''; ?>
			/>
			<?php if ( $is_date ) : ?>
				<small class="asdl-fin-date-weekday" data-date-weekday-output hidden></small>
			<?php endif; ?>
		</label>
		<?php
	}

	private function render_select( $name, $label, array $options, $required = false, $placeholder = '', $selected = null, array $attributes = array() ) {
		$attributes = array_merge(
			array(
				'class' => '',
			),
			$attributes
		);
		?>
		<label class="asdl-fin-field">
			<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
			<select
				name="<?php echo esc_attr( $name ); ?>"
				<?php foreach ( $attributes as $attr_name => $attr_value ) : ?>
					<?php if ( '' !== (string) $attr_value ) : ?>
						<?php echo esc_attr( $attr_name ); ?>="<?php echo esc_attr( $attr_value ); ?>"
					<?php endif; ?>
				<?php endforeach; ?>
				<?php echo $required ? 'required' : ''; ?>
			>
				<?php if ( '' !== $placeholder ) : ?>
					<option value="" <?php selected( (string) $selected, '' ); ?>><?php echo esc_html( $placeholder ); ?></option>
				<?php endif; ?>
				<?php foreach ( $options as $value => $option_label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $selected, (string) $value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}

	private function render_contact_picker( $name, $label, $selected = null, $required = false, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'placeholder'          => 'Busca por nombre, correo o ID',
				'selected_label'       => '',
				'selected_meta'        => '',
				'help'                 => '',
				'limit'                => 10,
				'filters'              => array(),
				'fallback_options'     => array(),
				'fallback_placeholder' => 'Selecciona un perfil',
				'clear_label'          => 'Quitar',
				'field_class'          => '',
			)
		);

		$selected_id    = is_array( $selected ) ? (int) ( $selected['id'] ?? 0 ) : (int) $selected;
		$selected_label = (string) $args['selected_label'];
		$selected_meta  = (string) $args['selected_meta'];

		if ( $selected_id > 0 && '' === $selected_label ) {
			$selected_contact = ( new ContactsRepository() )->find( $selected_id );
			if ( ! empty( $selected_contact ) ) {
				$selected_label = (string) ( $selected_contact['display_name'] ?? '' );
				$meta_parts     = array_filter(
					array(
						$selected_contact['email'] ?? '',
						$selected_contact['profile_roles_label'] ?? '',
					)
				);
				$selected_meta  = implode( ' | ', $meta_parts );
			}
		}

		$field_classes = trim( 'asdl-fin-contact-picker-field ' . (string) $args['field_class'] );
		$data_attrs    = array(
			'data-contact-picker'            => '1',
			'data-contact-picker-limit'      => (string) max( 5, (int) $args['limit'] ),
			'data-contact-picker-min-chars'  => '2',
			'data-contact-picker-required'   => $required ? '1' : '0',
			'data-contact-picker-empty-text' => 'No se encontraron perfiles con ese termino.',
		);

		foreach ( (array) $args['filters'] as $filter_key => $filter_value ) {
			if ( '' === (string) $filter_value || null === $filter_value ) {
				continue;
			}

			$attr_name                = 'data-contact-picker-filter-' . str_replace( '_', '-', sanitize_key( (string) $filter_key ) );
			$data_attrs[ $attr_name ] = (string) $filter_value;
		}
		?>
		<div class="asdl-fin-field <?php echo esc_attr( trim( $field_classes ) ); ?>" <?php foreach ( $data_attrs as $attr_name => $attr_value ) : ?><?php echo esc_attr( $attr_name ); ?>="<?php echo esc_attr( $attr_value ); ?>" <?php endforeach; ?>>
			<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $selected_id ); ?>" data-contact-picker-hidden disabled="disabled" />
			<div class="asdl-fin-contact-picker-control">
				<input
					type="search"
					value="<?php echo esc_attr( $selected_label ); ?>"
					placeholder="<?php echo esc_attr( (string) $args['placeholder'] ); ?>"
					autocomplete="off"
					class="regular-text"
					data-contact-picker-input
				/>
				<button type="button" class="button button-secondary small" data-contact-picker-clear <?php echo $selected_id > 0 ? '' : 'hidden'; ?>>
					<?php echo esc_html( (string) $args['clear_label'] ); ?>
				</button>
			</div>
			<div class="asdl-fin-contact-picker-selection" data-contact-picker-selection <?php echo $selected_id > 0 ? '' : 'hidden'; ?>>
				<strong data-contact-picker-selection-label><?php echo esc_html( $selected_label ); ?></strong>
				<small data-contact-picker-selection-meta><?php echo esc_html( $selected_meta ); ?></small>
			</div>
			<div class="asdl-fin-contact-picker-results" data-contact-picker-results hidden></div>
			<?php if ( '' !== $args['help'] ) : ?>
				<small><?php echo esc_html( (string) $args['help'] ); ?></small>
			<?php endif; ?>
			<?php if ( ! empty( $args['fallback_options'] ) ) : ?>
				<noscript>
					<?php $this->render_select( $name, $label, (array) $args['fallback_options'], $required, (string) $args['fallback_placeholder'], $selected_id ); ?>
				</noscript>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_wp_user_picker( $name, $label, $selected = null, $required = false, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'placeholder'   => 'Busca por nombre, correo, login o ID',
				'selected_label'=> '',
				'selected_meta' => '',
				'help'          => '',
				'limit'         => 10,
				'clear_label'   => 'Quitar',
				'field_class'   => '',
				'empty_text'    => 'No se encontraron usuarios con ese termino.',
				'fallback_help' => 'Si no tienes JavaScript, introduce manualmente el ID del usuario WordPress.',
			)
		);

		$selected_id    = (int) $selected;
		$selected_label = (string) $args['selected_label'];
		$selected_meta  = (string) $args['selected_meta'];

		if ( $selected_id > 0 && '' === $selected_label ) {
			$selected_user = get_userdata( $selected_id );
			if ( $selected_user instanceof \WP_User ) {
				$selected_label = (string) ( $selected_user->display_name ?: $selected_user->user_login );
				$selected_meta  = $this->wp_user_picker_meta( $selected_user );
			}
		}
		?>
		<div
			class="asdl-fin-field <?php echo esc_attr( trim( 'asdl-fin-contact-picker-field ' . (string) $args['field_class'] ) ); ?>"
			data-wp-user-picker="1"
			data-wp-user-picker-limit="<?php echo esc_attr( (string) max( 5, (int) $args['limit'] ) ); ?>"
			data-wp-user-picker-min-chars="2"
			data-wp-user-picker-required="<?php echo $required ? '1' : '0'; ?>"
			data-wp-user-picker-empty-text="<?php echo esc_attr( (string) $args['empty_text'] ); ?>"
		>
			<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $selected_id ); ?>" data-wp-user-picker-hidden disabled="disabled" />
			<div class="asdl-fin-contact-picker-control">
				<input
					type="search"
					value="<?php echo esc_attr( $selected_label ); ?>"
					placeholder="<?php echo esc_attr( (string) $args['placeholder'] ); ?>"
					autocomplete="off"
					class="regular-text"
					data-wp-user-picker-input
				/>
				<button type="button" class="button button-secondary small" data-wp-user-picker-clear <?php echo $selected_id > 0 ? '' : 'hidden'; ?>>
					<?php echo esc_html( (string) $args['clear_label'] ); ?>
				</button>
			</div>
			<div class="asdl-fin-contact-picker-selection" data-wp-user-picker-selection <?php echo $selected_id > 0 ? '' : 'hidden'; ?>>
				<strong data-wp-user-picker-selection-label><?php echo esc_html( $selected_label ); ?></strong>
				<small data-wp-user-picker-selection-meta><?php echo esc_html( $selected_meta ); ?></small>
			</div>
			<div class="asdl-fin-contact-picker-results" data-wp-user-picker-results hidden></div>
			<?php if ( '' !== $args['help'] ) : ?>
				<small><?php echo esc_html( (string) $args['help'] ); ?></small>
			<?php endif; ?>
			<noscript>
				<label class="asdl-fin-field">
					<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
					<input type="number" min="1" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $selected_id ); ?>" <?php echo $required ? 'required' : ''; ?> />
					<small><?php echo esc_html( (string) $args['fallback_help'] ); ?></small>
				</label>
			</noscript>
		</div>
		<?php
	}

	private function render_profile_context_disclosure_start( $section_id, $title, $summary_text, array $summary_items = array(), $is_open = false ) {
		?>
		<details
			class="asdl-fin-disclosure asdl-fin-profile-context-disclosure"
			data-profile-context-disclosure="1"
			data-profile-context-anchor="<?php echo esc_attr( $section_id ); ?>"
			<?php echo $is_open ? 'open' : ''; ?>
		>
			<summary>
				<div class="asdl-fin-profile-context-summary">
					<div class="asdl-fin-profile-context-summary-copy">
						<strong><?php echo esc_html( $title ); ?></strong>
						<small><?php echo esc_html( $summary_text ); ?></small>
					</div>
					<?php if ( ! empty( $summary_items ) ) : ?>
						<div class="asdl-fin-profile-context-summary-meta">
							<?php foreach ( $summary_items as $summary_item ) : ?>
								<?php
								$summary_label = trim( (string) ( $summary_item['label'] ?? '' ) );
								$summary_value = trim( (string) ( $summary_item['value'] ?? '' ) );
								$label_html    = isset( $summary_item['label_html'] ) ? (string) $summary_item['label_html'] : '';
								$value_html    = isset( $summary_item['value_html'] ) ? (string) $summary_item['value_html'] : '';
								$extra_html    = isset( $summary_item['extra_html'] ) ? (string) $summary_item['extra_html'] : '';
								$chip_classes  = array( 'asdl-fin-profile-context-chip' );
								$raw_class     = trim( (string) ( $summary_item['class'] ?? '' ) );
								$attrs         = array();

								if ( '' !== $raw_class ) {
									foreach ( preg_split( '/\s+/', $raw_class ) as $class_name ) {
										$class_name = sanitize_html_class( $class_name );
										if ( '' !== $class_name ) {
											$chip_classes[] = $class_name;
										}
									}
								}

								foreach ( (array) ( $summary_item['attrs'] ?? array() ) as $attr_name => $attr_value ) {
									$attr_name = preg_replace( '/[^a-zA-Z0-9:_-]/', '', (string) $attr_name );
									if ( '' === $attr_name ) {
										continue;
									}

									if ( true === $attr_value || 'hidden' === $attr_value ) {
										$attrs[] = sprintf( '%s', esc_attr( $attr_name ) );
										continue;
									}

									$attrs[] = sprintf( '%1$s="%2$s"', esc_attr( $attr_name ), esc_attr( (string) $attr_value ) );
								}

								if ( '' === $summary_label && '' === $summary_value ) {
									if ( '' === $label_html && '' === $value_html && '' === $extra_html ) {
										continue;
									}
								}
								if ( '' === $summary_label && '' === $summary_value && '' === $label_html && '' === $value_html && '' === $extra_html ) {
									continue;
								}
								?>
								<span class="<?php echo esc_attr( implode( ' ', array_unique( $chip_classes ) ) ); ?>" <?php echo implode( ' ', $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
									<?php if ( '' !== $label_html ) : ?>
										<strong><?php echo wp_kses_post( $label_html ); ?></strong>
									<?php elseif ( '' !== $summary_label ) : ?>
										<strong><?php echo esc_html( $summary_label ); ?></strong>
									<?php endif; ?>
									<?php if ( '' !== $value_html ) : ?>
										<small><?php echo wp_kses_post( $value_html ); ?></small>
									<?php elseif ( '' !== $summary_value ) : ?>
										<small><?php echo esc_html( $summary_value ); ?></small>
									<?php endif; ?>
									<?php if ( '' !== $extra_html ) : ?>
										<?php echo wp_kses_post( $extra_html ); ?>
									<?php endif; ?>
								</span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</summary>
			<div class="asdl-fin-disclosure-body asdl-fin-profile-context-body">
		<?php
	}

	private function render_profile_context_disclosure_end() {
		?>
			</div>
		</details>
		<?php
	}

	private function commitment_collection_mode_options( $is_employee, $allow_unknown_payroll = false ) {
		if ( $is_employee || $allow_unknown_payroll ) {
			return array(
				'manual'               => 'Gestion manual',
				'payroll_deduction'    => 'Descuento por sueldo',
				'payroll_disbursement' => 'Pago por nomina',
				'mixed'                => 'Mixto',
			);
		}

		return array(
			'manual' => 'Gestion manual',
		);
	}

	private function commitment_planning_mode_options() {
		return array(
			'period_amount' => 'Definir monto por periodo',
			'period_count'  => 'Definir cantidad de periodos',
			'single_period' => 'Resolver en un solo periodo',
		);
	}

	private function commitment_frequency_options() {
		return array(
			'weekly'    => 'Semanal',
			'biweekly'  => 'Quincenal',
			'monthly'   => 'Mensual',
			'quarterly' => 'Trimestral',
		);
	}

	private function supplier_kind_options() {
		return array(
			'general'  => 'Por clasificar',
			'services' => 'Servicios',
			'products' => 'Productos',
			'mixed'    => 'Mixto',
		);
	}

	private function render_payment_method_select( $name, $label, $selected = '', $required = false, $placeholder = 'Selecciona un metodo', array $attributes = array() ) {
		$methods = ( new PaymentMethodsService() )->options();
		?>
		<div class="asdl-fin-field">
			<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
			<div class="asdl-fin-inline-actions asdl-fin-method-row">
				<select name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?> data-payment-method-select <?php foreach ( $attributes as $attr_name => $attr_value ) : ?><?php if ( '' !== (string) $attr_value ) : ?><?php echo esc_attr( $attr_name ); ?>="<?php echo esc_attr( $attr_value ); ?>" <?php endif; ?><?php endforeach; ?>>
					<?php if ( '' !== $placeholder ) : ?>
						<option value=""><?php echo esc_html( $placeholder ); ?></option>
					<?php endif; ?>
					<?php echo $this->render_select_options( $methods, $selected ); ?>
				</select>
				<button type="button" class="button button-secondary asdl-fin-open-modal" data-modal-target="payment-method" data-payment-method-open="1">Agregar</button>
			</div>
			<small>Si el metodo no existe, agregalo al catalogo y luego seleccionalo desde esta lista.</small>
		</div>
		<?php
	}

	private function render_currency_select( $name, $label, $selected = '', $required = false, $placeholder = 'Selecciona una moneda', array $attributes = array() ) {
		$currencies = ( new CurrenciesService() )->options();
		?>
		<div class="asdl-fin-field">
			<span><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></span>
			<div class="asdl-fin-inline-actions asdl-fin-method-row">
				<select
					name="<?php echo esc_attr( $name ); ?>"
					<?php echo $required ? 'required' : ''; ?>
					data-currency-select
					<?php foreach ( $attributes as $attr_name => $attr_value ) : ?>
						<?php if ( '' !== (string) $attr_value ) : ?>
							<?php echo esc_attr( $attr_name ); ?>="<?php echo esc_attr( $attr_value ); ?>"
						<?php endif; ?>
					<?php endforeach; ?>
				>
					<?php if ( '' !== $placeholder ) : ?>
						<option value=""><?php echo esc_html( $placeholder ); ?></option>
					<?php endif; ?>
					<?php echo $this->render_select_options( $currencies, $selected ); ?>
				</select>
				<button type="button" class="button button-secondary asdl-fin-open-modal" data-modal-target="currency" data-currency-open="1">Agregar</button>
			</div>
			<small>Si la moneda no existe, agregala al catalogo y luego seleccionala desde esta lista.</small>
		</div>
		<?php
	}

	private function render_payment_method_modal() {
		$return_page = $this->current_page_slug();
		$contact_id  = $this->current_contact_id();
		?>
		<div class="asdl-fin-modal" data-modal="payment-method" hidden>
			<div class="asdl-fin-modal-overlay" data-modal-close></div>
			<div class="asdl-fin-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="asdl-fin-payment-method-title">
				<div class="asdl-fin-modal-header">
					<div>
						<h2 id="asdl-fin-payment-method-title" data-payment-method-modal-title>Agregar metodo de pago</h2>
						<p data-payment-method-modal-description>Este metodo quedara disponible en cobros, pagos, abonos y nomina. Si lo marcas como elegible, podra usar precio dual solo cuando la moneda sea USD y el descuento global este activo.</p>
					</div>
					<button type="button" class="button-link asdl-fin-modal-close" data-modal-close aria-label="Cerrar modal">Cerrar</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid" data-payment-method-inline-form="1">
					<input type="hidden" name="action" value="asdl_fin_save_payment_method" />
					<input type="hidden" name="payment_method_key" value="" data-payment-method-key />
					<input type="hidden" name="return_page" value="<?php echo esc_attr( $return_page ); ?>" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php if ( $contact_id > 0 ) : ?>
						<input type="hidden" name="contact_id" value="<?php echo esc_attr( $contact_id ); ?>" />
					<?php endif; ?>
					<?php wp_nonce_field( 'asdl_fin_save_payment_method' ); ?>
					<div class="asdl-fin-note-box asdl-fin-payment-method-feedback is-hidden" data-payment-method-inline-feedback role="status" aria-live="polite"></div>
					<?php $this->render_input( 'payment_method_name', 'Nombre del metodo', 'text', '', true, array( 'maxlength' => '80', 'autocomplete' => 'off' ) ); ?>
					<div class="asdl-fin-note-box asdl-fin-payment-method-canonical is-hidden" data-payment-method-canonical-box>
						<strong>Clave efectiva</strong>
						<p><code data-payment-method-canonical-key></code></p>
						<small data-payment-method-canonical-help>Este nombre se guardara como metodo propio del catalogo ASD.</small>
					</div>
					<label class="asdl-fin-field asdl-fin-field-wide asdl-fin-checkbox-field">
						<span>Elegibilidad de precio dual</span>
						<div class="asdl-fin-checkbox-row">
							<input type="checkbox" name="payment_method_dual_eligible" value="1" data-payment-method-dual-eligible />
							<div class="asdl-fin-stack">
								<strong>Elegible para precio dual en USD</strong>
								<small>Si esta activo, este metodo del catalogo ASD podra aplicar el descuento dual cuando la moneda del abono o cobro sea <code>USD</code> y el descuento global este encendido.</small>
							</div>
						</div>
					</label>
					<div class="asdl-fin-field asdl-fin-field-wide">
						<span>Accion</span>
						<div class="asdl-fin-inline-actions">
							<?php submit_button( 'Guardar metodo', 'primary', 'submit', false ); ?>
							<button type="button" class="button button-secondary" data-modal-close>Cancelar</button>
						</div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_currency_modal() {
		$return_page = $this->current_page_slug();
		$contact_id  = $this->current_contact_id();
		?>
		<div class="asdl-fin-modal" data-modal="currency" hidden>
			<div class="asdl-fin-modal-overlay" data-modal-close></div>
			<div class="asdl-fin-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="asdl-fin-currency-title">
				<div class="asdl-fin-modal-header">
					<div>
						<h2 id="asdl-fin-currency-title">Agregar moneda</h2>
						<p>Esta moneda quedara disponible para sueldos, movimientos, cobros, pagos, compromisos, adelantos y nomina.</p>
					</div>
					<button type="button" class="button-link asdl-fin-modal-close" data-modal-close aria-label="Cerrar modal">Cerrar</button>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid" data-currency-inline-form="1">
					<input type="hidden" name="action" value="asdl_fin_save_currency" />
					<input type="hidden" name="return_page" value="<?php echo esc_attr( $return_page ); ?>" />
					<?php $this->render_current_fiscal_hidden_input(); ?>
					<?php if ( $contact_id > 0 ) : ?>
						<input type="hidden" name="contact_id" value="<?php echo esc_attr( $contact_id ); ?>" />
					<?php endif; ?>
					<?php wp_nonce_field( 'asdl_fin_save_currency' ); ?>
					<div class="asdl-fin-note-box asdl-fin-payment-method-feedback is-hidden" data-currency-inline-feedback role="status" aria-live="polite"></div>
					<?php $this->render_input( 'currency_code', 'Codigo de moneda', 'text', '', true, array( 'maxlength' => '10', 'autocomplete' => 'off' ) ); ?>
					<?php $this->render_input( 'currency_label', 'Etiqueta visible', 'text', '', false, array( 'maxlength' => '40', 'autocomplete' => 'off' ) ); ?>
					<div class="asdl-fin-field asdl-fin-field-wide">
						<span>Accion</span>
						<div class="asdl-fin-inline-actions">
							<?php submit_button( 'Guardar moneda', 'primary', 'submit', false ); ?>
							<button type="button" class="button button-secondary" data-modal-close>Cancelar</button>
						</div>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_textarea( $name, $label, $help_text = '' ) {
		?>
		<label class="asdl-fin-field asdl-fin-field-wide">
			<span><?php echo esc_html( $label ); ?></span>
			<textarea name="<?php echo esc_attr( $name ); ?>" rows="4"></textarea>
			<?php if ( '' !== $help_text ) : ?>
				<small><?php echo esc_html( $help_text ); ?></small>
			<?php endif; ?>
		</label>
		<?php
	}

	private function render_file_input( $name, $label, $help_text = '' ) {
		?>
		<label class="asdl-fin-field asdl-fin-field-wide">
			<span><?php echo esc_html( $label ); ?></span>
			<input type="file" name="<?php echo esc_attr( $name ); ?>" />
			<?php if ( '' !== $help_text ) : ?>
				<small><?php echo esc_html( $help_text ); ?></small>
			<?php endif; ?>
		</label>
		<?php
	}

	private function render_hidden_inputs( array $values ) {
		foreach ( $values as $name => $value ) {
			printf(
				'<input type="hidden" name="%1$s" value="%2$s" />',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}
	}

	private function render_summary_rows( array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		?>
		<div class="asdl-fin-summary-rows">
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$tone  = ! empty( $row['tone'] ) ? sanitize_html_class( (string) $row['tone'] ) : '';
				$class = 'asdl-fin-summary-row' . ( $tone ? ' is-' . $tone : '' );
				?>
				<div class="<?php echo esc_attr( $class ); ?>">
					<div class="asdl-fin-summary-row-copy">
						<strong><?php echo esc_html( $row['label'] ?? '' ); ?></strong>
						<?php if ( ! empty( $row['description'] ) ) : ?>
							<small><?php echo esc_html( $row['description'] ); ?></small>
						<?php endif; ?>
					</div>
					<span><?php echo wp_kses_post( $row['value'] ?? '' ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function weekday_label_for_date( $date ) {
		$date = sanitize_text_field( (string) $date );
		if ( '' === $date ) {
			return '';
		}

		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return '';
		}

		$days = array( 'Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado' );
		$day  = (int) gmdate( 'w', $timestamp );

		return $days[ $day ] ?? '';
	}

	private function format_date_with_weekday( $date, $fallback = 'Sin programar' ) {
		$date = sanitize_text_field( (string) $date );
		if ( '' === $date ) {
			return $fallback;
		}

		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return $date;
		}

		$weekday = $this->weekday_label_for_date( $date );
		$label   = gmdate( 'd/m/Y', $timestamp );

		return '' !== $weekday ? $label . ' · ' . $weekday : $label;
	}

	private function format_short_date( $date, $fallback = 'Sin fecha' ) {
		$date = sanitize_text_field( (string) $date );
		if ( '' === $date ) {
			return $fallback;
		}

		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return $date;
		}

		return gmdate( 'd/m/Y', $timestamp );
	}

	private function next_service_profile( array $profiles ) {
		$profiles = array_values(
			array_filter(
				$profiles,
				static function ( array $profile ) {
					return 'active' === sanitize_key( (string) ( $profile['status'] ?? '' ) )
						&& '' !== sanitize_text_field( (string) ( $profile['next_issue_date'] ?? '' ) );
				}
			)
		);

		if ( empty( $profiles ) ) {
			return null;
		}

		usort(
			$profiles,
			static function ( array $left, array $right ) {
				return strcmp(
					sanitize_text_field( (string) ( $left['next_issue_date'] ?? '' ) ),
					sanitize_text_field( (string) ( $right['next_issue_date'] ?? '' ) )
				);
			}
		);

		return $profiles[0] ?? null;
	}

	private function render_pending_collection_summary_badges( array $summary ) {
		$badges = array(
			array(
				'label'       => 'Total pendiente',
				'value'       => $this->format_money( $summary['pending_total'] ?? 0 ),
				'description' => sprintf( '%s perfiles o personas agrupadas', number_format_i18n( $summary['group_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Pedidos',
				'value'       => $this->format_money( $summary['order_pending_total'] ?? 0 ),
				'description' => sprintf( '%s pedidos abiertos', number_format_i18n( $summary['order_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Facturas / docs',
				'value'       => $this->format_money( $summary['invoice_pending_total'] ?? 0 ),
				'description' => sprintf( '%s documentos por cobrar', number_format_i18n( $summary['invoice_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Prestamos',
				'value'       => $this->format_money( $summary['loan_pending_total'] ?? 0 ),
				'description' => sprintf( '%s prestamos abiertos', number_format_i18n( $summary['loan_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Compromisos',
				'value'       => $this->format_money( $summary['commitment_pending_total'] ?? 0 ),
				'description' => sprintf( '%s compromisos por cobrar', number_format_i18n( $summary['commitment_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Adelantos',
				'value'       => $this->format_money( $summary['advance_pending_total'] ?? 0 ),
				'description' => sprintf( '%s adelantos por recuperar', number_format_i18n( $summary['advance_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'En periodo',
				'value'       => $this->format_money( $summary['in_range_pending_total'] ?? 0 ),
				'description' => sprintf( '%s items del rango actual', number_format_i18n( $summary['in_range_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Historico reciente',
				'value'       => $this->format_money( $summary['historical_pending_total'] ?? 0 ),
				'description' => sprintf( '%s items del ejercicio fiscal anterior o fuera del rango actual', number_format_i18n( $summary['historical_count'] ?? 0 ) ),
			),
		);
		?>
		<div class="asdl-fin-pending-summary-grid">
			<?php foreach ( $badges as $badge ) : ?>
				<div class="asdl-fin-pending-summary-card">
					<strong><?php echo esc_html( $badge['label'] ); ?></strong>
					<span><?php echo wp_kses_post( $badge['value'] ); ?></span>
					<small><?php echo esc_html( $badge['description'] ); ?></small>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_pending_payable_summary_badges( array $summary ) {
		$badges = array(
			array(
				'label'       => 'Total por pagar',
				'value'       => $this->format_money( $summary['pending_total'] ?? 0 ),
				'description' => sprintf( '%s perfiles o entidades agrupadas', number_format_i18n( $summary['group_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Docs / proveedor',
				'value'       => $this->format_money( $summary['invoice_pending_total'] ?? 0 ),
				'description' => sprintf( '%s documentos por pagar', number_format_i18n( $summary['invoice_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Perfiles / terceros',
				'value'       => $this->format_money( $summary['profile_credit_pending_total'] ?? 0 ),
				'description' => sprintf( '%s deudas con perfiles', number_format_i18n( $summary['profile_credit_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Prestamos',
				'value'       => $this->format_money( $summary['loan_pending_total'] ?? 0 ),
				'description' => sprintf( '%s prestamos abiertos', number_format_i18n( $summary['loan_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Compromisos',
				'value'       => $this->format_money( $summary['commitment_pending_total'] ?? 0 ),
				'description' => sprintf( '%s compromisos por pagar', number_format_i18n( $summary['commitment_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Compras',
				'value'       => $this->format_money( $summary['purchase_pending_total'] ?? 0 ),
				'description' => sprintf( '%s compras pendientes', number_format_i18n( $summary['purchase_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'En periodo',
				'value'       => $this->format_money( $summary['in_range_pending_total'] ?? 0 ),
				'description' => sprintf( '%s items del rango actual', number_format_i18n( $summary['in_range_count'] ?? 0 ) ),
			),
			array(
				'label'       => 'Historico por cerrar',
				'value'       => $this->format_money( $summary['historical_pending_total'] ?? 0 ),
				'description' => sprintf( '%s items fuera del rango actual', number_format_i18n( $summary['historical_count'] ?? 0 ) ),
			),
		);
		?>
		<div class="asdl-fin-pending-summary-grid">
			<?php foreach ( $badges as $badge ) : ?>
				<div class="asdl-fin-pending-summary-card">
					<strong><?php echo esc_html( $badge['label'] ); ?></strong>
					<span><?php echo wp_kses_post( $badge['value'] ); ?></span>
					<small><?php echo esc_html( $badge['description'] ); ?></small>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		}

		private function render_dashboard_queue_filters( $queue_key, array $origins, $placeholder ) {
			?>
			<div class="asdl-fin-dashboard-filter-grid" data-dashboard-filters data-dashboard-filter-group="<?php echo esc_attr( $queue_key ); ?>">
				<label class="asdl-fin-field">
					<span>Buscar</span>
					<input type="search" placeholder="<?php echo esc_attr( $placeholder ); ?>" data-dashboard-filter-search />
				</label>
				<label class="asdl-fin-field">
					<span>Origen</span>
					<select data-dashboard-filter-origin>
						<option value="">Todos</option>
						<?php foreach ( $origins as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="asdl-fin-field">
					<span>Rango</span>
					<select data-dashboard-filter-range>
						<option value="">Todo</option>
						<option value="current">En periodo</option>
						<option value="historical">Historico</option>
					</select>
				</label>
				<div class="asdl-fin-field asdl-fin-dashboard-filter-action">
					<span>Accion</span>
					<div class="asdl-fin-inline-actions">
						<button type="button" class="button button-secondary" data-dashboard-filter-reset>Limpiar</button>
					</div>
				</div>
			</div>
			<p class="asdl-fin-dashboard-filter-help">Busca por nombre, correo o referencia y luego filtra por origen o por rango para separar el periodo actual del historico.</p>
			<div class="asdl-fin-dashboard-filter-meta" data-dashboard-filter-meta hidden></div>
			<?php
		}

		private function pending_queue_search_text( array $group ) {
			$parts = array(
				(string) ( $group['display_name'] ?? '' ),
				(string) ( $group['email'] ?? '' ),
				(string) ( $group['profile_origin'] ?? '' ),
				(string) ( $group['search_text'] ?? '' ),
			);

			foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
				$parts[] = (string) ( $item['kind_label'] ?? '' );
				$parts[] = (string) ( $item['label'] ?? '' );
				$parts[] = (string) ( $item['description'] ?? '' );
				$parts[] = (string) ( $item['status'] ?? '' );
				$parts[] = (string) ( $item['date'] ?? '' );
				$parts[] = (string) ( $item['origin_bucket'] ?? '' );
				$parts[] = (string) ( $item['document_type'] ?? '' );
			}

			return sanitize_text_field( implode( ' ', array_filter( array_map( 'trim', $parts ) ) ) );
		}

		private function pending_queue_range_flags( array $group ) {
			$flags = array();

			if ( ! empty( $group['in_range_count'] ) ) {
				$flags[] = 'current';
			}

			if ( ! empty( $group['historical_count'] ) ) {
				$flags[] = 'historical';
			}

			if ( empty( $flags ) ) {
				$flags[] = 'current';
			}

			return implode( ' ', $flags );
		}

		private function pending_collection_origin_flags( array $group ) {
			$flags = array();

			if ( ! empty( $group['order_count'] ) ) {
				$flags[] = 'order';
			}

			if ( ! empty( $group['invoice_count'] ) ) {
				$flags[] = 'invoice';
			}

			if ( ! empty( $group['loan_count'] ) ) {
				$flags[] = 'loan';
			}

			if ( ! empty( $group['commitment_count'] ) ) {
				$flags[] = 'commitment';
			}

			if ( ! empty( $group['advance_count'] ) ) {
				$flags[] = 'advance';
			}

			if ( ! empty( $group['other_count'] ) ) {
				$flags[] = 'other';
			}

			return implode( ' ', $flags );
		}

		private function pending_payable_origin_flags( array $group ) {
			$flags = array();

			if ( ! empty( $group['invoice_count'] ) ) {
				$flags[] = 'invoice';
			}

			if ( ! empty( $group['profile_credit_count'] ) ) {
				$flags[] = 'profile_credit';
			}

			if ( ! empty( $group['loan_count'] ) ) {
				$flags[] = 'loan';
			}

			if ( ! empty( $group['purchase_count'] ) ) {
				$flags[] = 'purchase';
			}

			if ( ! empty( $group['commitment_count'] ) ) {
				$flags[] = 'commitment';
			}

			if ( ! empty( $group['other_count'] ) ) {
				$flags[] = 'other';
			}

			return implode( ' ', $flags );
		}

		private function render_accounts_table( array $accounts ) {
		if ( empty( $accounts ) ) {
			$this->render_empty_state( 'Aun no hay cuentas registradas.', 'Empieza creando al menos una cuenta para organizar los documentos y movimientos.' );
			return;
		}
		?>
		<table class="widefat striped asdl-fin-table">
			<thead>
				<tr>
					<th>Cuenta</th>
					<th>Codigo</th>
					<th>Tipo</th>
					<th>Moneda</th>
					<th>Estado</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $accounts as $account ) : ?>
					<tr>
						<td><?php echo esc_html( $account['name'] ); ?></td>
						<td><?php echo esc_html( $account['code'] ?: '—' ); ?></td>
						<td><?php echo esc_html( $this->label_for( 'account_type', $account['account_type'] ) ); ?></td>
						<td><?php echo esc_html( $account['currency'] ?: '—' ); ?></td>
						<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'status', $account['status'] ), $this->tone_for_status( $account['status'] ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_payment_methods_table( array $methods ) {
		if ( empty( $methods ) ) {
			$this->render_empty_state( 'Aun no hay metodos de pago configurados.', 'Agrega al menos un metodo para usarlo en cobros, pagos y nomina.' );
			return;
		}

		$dual_pricing = new DualPricingService();
		?>
		<div class="asdl-fin-table-wrap asdl-fin-settings-table-wrap">
		<table class="widefat striped asdl-fin-table asdl-fin-settings-payment-methods-table" data-payment-methods-table>
			<thead>
				<tr>
					<th>Metodo</th>
					<th>Clave</th>
					<th>Precio dual USD</th>
					<th>Como califica</th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
					<?php foreach ( $methods as $method ) : ?>
					<?php
					if ( empty( $method['selectable'] ) ) {
						continue;
					}

					$key      = sanitize_key( (string) ( $method['key'] ?? '' ) );
					$label    = sanitize_text_field( (string) ( $method['label'] ?? '' ) );
					$dual     = $dual_pricing->get_method_eligibility_snapshot( $key );
					$eligible = ! empty( $dual['eligible'] );
					?>
					<tr data-payment-method-row="<?php echo esc_attr( $key ); ?>">
						<td data-payment-method-label><?php echo esc_html( $label ); ?></td>
						<td><code><?php echo esc_html( $key ); ?></code></td>
						<td data-payment-method-eligibility><?php echo wp_kses_post( $this->render_pill( $eligible ? 'Elegible' : 'No elegible', $eligible ? 'success' : 'neutral' ) ); ?></td>
						<td data-payment-method-source><?php echo esc_html( sanitize_text_field( (string) ( $dual['source_label'] ?? 'No elegible' ) ) ); ?></td>
						<td data-payment-method-action>
							<button
								type="button"
								class="button button-secondary asdl-fin-open-modal asdl-fin-payment-method-edit"
								data-modal-target="payment-method"
								data-payment-method-open="1"
								data-payment-method-edit="1"
								data-payment-method-key="<?php echo esc_attr( $key ); ?>"
								data-payment-method-label="<?php echo esc_attr( $label ); ?>"
								data-payment-method-dual="<?php echo $eligible ? '1' : '0'; ?>"
								data-payment-method-kind="<?php echo esc_attr( sanitize_key( (string) ( $method['kind'] ?? 'default' ) ) ); ?>"
							>
								Configurar
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private function render_currencies_table( array $currencies ) {
		if ( empty( $currencies ) ) {
			$this->render_empty_state( 'Aun no hay monedas configuradas.', 'Agrega al menos una moneda para usarla en sueldos, movimientos, cobros, pagos y compromisos.' );
			return;
		}
		?>
		<div class="asdl-fin-table-wrap asdl-fin-settings-table-wrap">
		<table class="widefat striped asdl-fin-table asdl-fin-settings-currencies-table" data-currencies-table>
			<thead>
				<tr>
					<th>Codigo</th>
					<th>Etiqueta</th>
					<th>Origen</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $currencies as $currency ) : ?>
					<tr>
						<td><code><?php echo esc_html( $currency['code'] ?? '' ); ?></code></td>
						<td><?php echo esc_html( $currency['label'] ?? '' ); ?></td>
						<td><?php echo esc_html( 'custom' === ( $currency['kind'] ?? '' ) ? 'Personalizada' : 'Base del sistema' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private function render_contacts_table( array $contacts, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'per_page'           => 0,
				'expanded_per_page'  => 0,
				'show_expand_toggle' => false,
			)
		);

		$expanded_per_page      = max( (int) $args['expanded_per_page'], (int) $args['per_page'] );
		$per_page_attr          = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		$expanded_per_page_attr = ( ! empty( $args['show_expand_toggle'] ) && $expanded_per_page > (int) ( $args['per_page'] ?? 0 ) )
			? ' data-dashboard-per-page-expanded="' . esc_attr( $expanded_per_page ) . '"'
			: '';
		?>
		<table class="widefat striped asdl-fin-table"<?php echo $per_page_attr; ?><?php echo $expanded_per_page_attr; ?>>
			<thead>
				<tr>
					<th>Nombre</th>
					<th>Origen</th>
					<th>Roles</th>
					<th>Correo</th>
					<th>Estado</th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody data-contact-search-body>
				<?php if ( empty( $contacts ) ) : ?>
					<tr>
						<td colspan="6">
							<div class="asdl-fin-empty">
								<strong>Aun no hay perfiles o terceros para esta consulta.</strong>
								<p>Cuando existan usuarios enlazados, proveedores o terceros externos, apareceran aqui para su gestion.</p>
							</div>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $contacts as $contact ) : ?>
						<?php $this->render_contact_table_row( $contact ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_contact_table_row( array $contact ) {
		$missing_email_for_link = empty( $contact['wp_user_id'] ) && empty( $contact['email'] );
		?>
		<tr>
			<td>
				<div class="asdl-fin-stack">
					<strong><?php echo esc_html( $contact['display_name'] ); ?></strong>
					<small><?php echo esc_html( ! empty( $contact['wp_user_id'] ) ? 'Usuario WP #' . (int) $contact['wp_user_id'] : 'Sin usuario enlazado' ); ?></small>
				</div>
			</td>
			<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'profile_origin', $contact['profile_origin'] ?? '' ), 'wp_user' === ( $contact['profile_origin'] ?? '' ) ? 'success' : 'neutral' ) ); ?></td>
			<td><?php echo esc_html( $contact['profile_roles_label'] ?? $this->label_for( 'contact_type', $contact['contact_type'] ) ); ?></td>
			<td>
				<div class="asdl-fin-stack">
					<strong><?php echo esc_html( $contact['email'] ?: '—' ); ?></strong>
					<?php if ( $missing_email_for_link ) : ?>
						<small class="asdl-fin-table-note">Falta correo para poder vincularlo o crear su usuario interno.</small>
					<?php endif; ?>
				</div>
			</td>
			<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'status', $contact['status'] ), $this->tone_for_status( $contact['status'] ) ) ); ?></td>
			<td>
				<div class="asdl-fin-table-action-cell">
					<div class="asdl-fin-inline-actions">
						<a class="button button-small" href="<?php echo esc_url( $this->contact_detail_url( (int) $contact['id'] ) ); ?>">Ver detalle</a>
						<?php if ( ! empty( $contact['can_delete'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Solo se eliminara el perfil financiero vacio. El usuario WordPress no se borrara.');">
								<input type="hidden" name="action" value="asdl_fin_delete_contact" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_delete_contact' ); ?>
								<?php submit_button( 'Eliminar', 'secondary small', 'submit', false ); ?>
							</form>
						<?php endif; ?>
						<?php if ( empty( $contact['wp_user_id'] ) && ! empty( $contact['email'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="asdl_fin_promote_contact_to_user" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_promote_contact_to_user' ); ?>
								<?php submit_button( 'Vincular o crear usuario interno', 'secondary small', 'submit', false ); ?>
							</form>
						<?php endif; ?>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}

	private function render_profile_order_filters( $contact_id, array $filters ) {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="asdl-fin-form-grid">
			<input type="hidden" name="page" value="asdl-fin-contacts" />
			<input type="hidden" name="contact_id" value="<?php echo esc_attr( absint( $contact_id ) ); ?>" />
			<?php $this->render_current_fiscal_hidden_input(); ?>
			<?php $this->render_input( 'range_from', 'Desde', 'date', $filters['range_from'] ?? '' ); ?>
			<?php $this->render_input( 'range_to', 'Hasta', 'date', $filters['range_to'] ?? '' ); ?>
			<?php $this->render_input( 'order_limit', 'Maximo de pedidos cargados', 'number', (string) ( $filters['order_limit'] ?? 25 ), false, array( 'min' => '10', 'max' => '100', 'step' => '5' ) ); ?>
			<div class="asdl-fin-field asdl-fin-field-wide">
				<span>Consulta</span>
				<div class="asdl-fin-inline-actions">
					<?php submit_button( 'Aplicar rango', 'secondary', '', false ); ?>
					<a class="button button-secondary" href="<?php echo esc_url( $this->contact_detail_url( (int) $contact_id ) ); ?>">Quitar filtro</a>
				</div>
				<small>Este filtro afecta pedidos, consumo, promedio por pedido y el pendiente del periodo, sin alterar la deuda total acumulada del perfil.</small>
			</div>
		</form>
		<?php
	}

	private function render_orders_table( array $orders, $return_page = 'asdl-fin-contacts', array $return_context = array(), array $options = array() ) {
		$options         = wp_parse_args(
			$options,
			array(
				'mode'               => 'operational',
				'action_label'       => '',
				'table_title'        => '',
				'table_description'  => '',
				'per_page'           => 0,
				'expanded_per_page'  => 0,
				'show_expand_toggle' => false,
			)
		);
		$is_history_mode = 'history' === $options['mode'];

		if ( empty( $orders ) ) {
			if ( $is_history_mode ) {
				$this->render_empty_state( 'No se encontraron pedidos para este rango.', 'Cuando el perfil tenga pedidos Woo/OpenPOS dentro del periodo filtrado, apareceran aqui como lectura completa para consumo, frecuencia y deuda pendiente.' );
			} else {
				$this->render_empty_state( 'No hay pedidos abiertos para cruce o gestion.', 'Esta cola operativa solo muestra pedidos Woo/OpenPOS que sigan abiertos y aun admitan abonos, compensaciones o regularizacion.' );
			}
			return;
		}

		$action_label          = '' !== $options['action_label'] ? (string) $options['action_label'] : ( $is_history_mode ? 'Acceso' : 'Gestion' );
		$finance_fallback      = 'Sin movimiento aplicado';
		$per_page_attr         = ! empty( $options['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $options['per_page'] ) . '"' : '';
		$expanded_per_page     = max( 0, (int) ( $options['expanded_per_page'] ?? 0 ) );
		$expanded_per_page_attr = ( ! empty( $options['show_expand_toggle'] ) && $expanded_per_page > (int) ( $options['per_page'] ?? 0 ) )
			? ' data-dashboard-per-page-expanded="' . esc_attr( $expanded_per_page ) . '"'
			: '';
		?>
		<div class="asdl-fin-table-wrap">
			<?php if ( ! empty( $options['table_title'] ) || ! empty( $options['table_description'] ) ) : ?>
				<div class="asdl-fin-table-intro">
					<?php if ( ! empty( $options['table_title'] ) ) : ?>
						<strong><?php echo esc_html( $options['table_title'] ); ?></strong>
					<?php endif; ?>
					<?php if ( ! empty( $options['table_description'] ) ) : ?>
						<p><?php echo esc_html( $options['table_description'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<table class="widefat striped asdl-fin-table"<?php echo $per_page_attr; ?><?php echo $expanded_per_page_attr; ?>>
				<thead>
					<tr>
						<th>Pedido</th>
						<th>Origen</th>
						<th>Estado</th>
						<th>Fecha</th>
						<th>Items</th>
						<th>Total</th>
						<th>Finanzas ASD</th>
						<th><?php echo esc_html( $action_label ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders as $order ) : ?>
						<?php $order_edit_url = ! empty( $order['edit_url'] ) ? (string) $order['edit_url'] : $this->source_order_edit_url( 'shop_order', $order['order_id'] ?? 0 ); ?>
						<tr>
							<td>
								<?php if ( '' !== $order_edit_url ) : ?>
									<a href="<?php echo esc_url( $order_edit_url ); ?>">#<?php echo esc_html( $order['order_number'] ?: $order['order_id'] ); ?></a>
								<?php else : ?>
									#<?php echo esc_html( $order['order_number'] ?: $order['order_id'] ); ?>
								<?php endif; ?>
							</td>
								<td><?php echo esc_html( $this->label_for( 'provider', $order['provider'] ) ); ?></td>
								<td>
									<div class="asdl-fin-stack">
										<?php
										if ( ! empty( $order['is_managed'] ) && ! empty( $order['document_id'] ) ) {
											echo wp_kses_post( $this->render_pill( $order['status_label'] ?: $order['status'], $this->tone_for_status( $order['status'] ) ) );
											if ( ! empty( $order['has_order_sync_mismatch'] ) ) {
												echo wp_kses_post( $this->render_pill( 'Sincronizacion pendiente', 'warning' ) );
												echo '<small class="asdl-fin-context-line">' . esc_html( (string) ( $order['order_sync_mismatch_message'] ?? '' ) ) . '</small>';
											}
										} else {
											echo wp_kses_post( $this->render_pill( $order['status_label'] ?: $order['status'], $this->tone_for_status( $order['status'] ) ) );
										}
										?>
									</div>
								</td>
							<td><?php echo esc_html( $order['date_created'] ?: '—' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $order['item_count'] ) ); ?></td>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo wp_kses_post( $this->format_money( $order['effective_total'] ?? $order['total'], $order['currency'] ?? '' ) ); ?></strong>
									<?php if ( ! empty( $order['effective_paid_total'] ) ) : ?>
										<small>Abonado: <?php echo wp_kses_post( $this->format_money( $order['effective_paid_total'], $order['currency'] ?? '' ) ); ?></small>
									<?php endif; ?>
									<?php if ( ! empty( $order['is_effectively_open'] ) ) : ?>
										<small>Pendiente: <?php echo wp_kses_post( $this->format_money( $order['effective_due_total'] ?? 0, $order['currency'] ?? '' ) ); ?></small>
									<?php endif; ?>
								</div>
							</td>
							<td>
								<?php if ( ! empty( $order['document_id'] ) ) : ?>
									<a href="<?php echo esc_url( $this->document_detail_url( (int) $order['document_id'] ) ); ?>">Movimiento #<?php echo esc_html( (int) $order['document_id'] ); ?></a>
								<?php elseif ( $is_history_mode && ! empty( $order['is_effectively_open'] ) ) : ?>
									<span>Abierto sin gestion</span>
								<?php else : ?>
									<span><?php echo esc_html( $finance_fallback ); ?></span>
								<?php endif; ?>
							</td>
								<td>
									<div class="asdl-fin-inline-actions">
										<?php if ( ! empty( $order['is_managed'] ) && ! empty( $order['document_id'] ) ) : ?>
											<?php if ( ! empty( $order['has_order_sync_mismatch'] ) ) : ?>
												<?php $this->render_manage_order_form( (int) $order['order_id'], $return_page, $return_context, array( 'label' => 'Resync', 'mode' => 'resync' ) ); ?>
											<?php endif; ?>
											<a class="button button-small" href="<?php echo esc_url( $this->document_detail_url( (int) $order['document_id'] ) ); ?>">Abrir movimiento</a>
										<?php elseif ( ! $is_history_mode ) : ?>
											<?php $this->render_manage_order_form( (int) $order['order_id'], $return_page, $return_context ); ?>
										<?php endif; ?>
										<?php if ( '' !== $order_edit_url ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $order_edit_url ); ?>">Abrir pedido</a>
										<?php endif; ?>
									</div>
								</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_pending_collection_groups_table( array $groups, array $args = array() ) {
		if ( empty( $groups ) ) {
			$this->render_empty_state( 'Sin deuda operativa agrupada.', 'Cuando existan pedidos, documentos, prestamos, compromisos o adelantos por recuperar, apareceran aqui agrupados por perfil o persona.' );
			return;
		}
		$per_page_attr = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		?>
		<div class="asdl-fin-dashboard-queue" data-dashboard-queue="receivable">
			<?php
			$this->render_dashboard_queue_filters(
				'receivable',
				array(
					'order'      => 'Pedidos',
					'invoice'    => 'Facturas / docs',
					'loan'       => 'Prestamos',
					'commitment' => 'Compromisos',
					'advance'    => 'Adelantos',
					'other'      => 'Otros',
				),
				'Nombre, correo, pedido, documento o referencia'
			);
			?>
		<div class="asdl-fin-table-wrap">
		<table class="widefat striped asdl-fin-table" data-dashboard-custom-pagination="1" data-sortable-table="1"<?php echo $per_page_attr; ?>>
			<thead>
				<tr>
					<th data-sort-type="text" data-sort-default="asc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Persona</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th>Perfil</th>
					<th data-sort-type="number" data-sort-default="desc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Pedidos / otros</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="number" data-sort-default="desc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Total pendiente</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="date" data-sort-default="asc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Fecha mas antigua</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th>Gestion</th>
				</tr>
				</thead>
				<tbody>
					<?php foreach ( $groups as $group ) : ?>
						<?php
						$manage_url  = '';
						$manage_text = '';
						$can_open_detail = ! empty( $group['contact_id'] ) || ! empty( $group['wp_user_id'] ) || ! empty( $group['email'] );
						$row_search  = $this->pending_queue_search_text( $group );
						$row_origins = $this->pending_collection_origin_flags( $group );
						$row_ranges  = $this->pending_queue_range_flags( $group );

						if ( ! empty( $group['contact_id'] ) ) {
							$manage_url  = $this->contact_section_url( (int) $group['contact_id'], 'asdl-fin-contact-open' );
							$manage_text = 'Gestionar';
						}
						?>
						<tr data-dashboard-row="1" data-search-text="<?php echo esc_attr( $row_search ); ?>" data-origin-flags="<?php echo esc_attr( $row_origins ); ?>" data-range-flags="<?php echo esc_attr( $row_ranges ); ?>">
							<td data-sort-value="<?php echo esc_attr( mb_strtolower( (string) ( $group['display_name'] ?? '' ) . ' ' . (string) ( $group['email'] ?? '' ) ) ); ?>">
								<strong><?php echo esc_html( $group['display_name'] ?? 'Sin nombre' ); ?></strong><br />
								<small><?php echo esc_html( $group['email'] ?: 'Sin correo' ); ?></small>
						</td>
						<td>
							<?php if ( ! empty( $group['contact_id'] ) ) : ?>
								<?php echo wp_kses_post( $this->render_pill( 'Perfil listo', 'success' ) ); ?><br />
								<small>#<?php echo esc_html( (int) $group['contact_id'] ); ?></small>
							<?php elseif ( ! empty( $group['wp_user_id'] ) ) : ?>
								<?php echo wp_kses_post( $this->render_pill( 'Usuario WP sin perfil', 'warning' ) ); ?><br />
								<small>WP #<?php echo esc_html( (int) $group['wp_user_id'] ); ?></small>
							<?php else : ?>
								<?php echo wp_kses_post( $this->render_pill( 'Sin perfil', 'neutral' ) ); ?>
							<?php endif; ?>
						</td>
						<td data-sort-value="<?php echo esc_attr( (float) ( ( $group['order_count'] ?? 0 ) + ( $group['other_count'] ?? 0 ) ) ); ?>">
							<div class="asdl-fin-stack">
								<strong>Pedidos: <?php echo esc_html( number_format_i18n( $group['order_count'] ?? 0 ) ); ?> | Otros: <?php echo esc_html( number_format_i18n( $group['other_count'] ?? 0 ) ); ?></strong>
								<small>Facturas/docs: <?php echo esc_html( number_format_i18n( $group['invoice_count'] ?? 0 ) ); ?> · Prestamos: <?php echo esc_html( number_format_i18n( $group['loan_count'] ?? 0 ) ); ?> · Compromisos: <?php echo esc_html( number_format_i18n( $group['commitment_count'] ?? 0 ) ); ?> · Adelantos: <?php echo esc_html( number_format_i18n( $group['advance_count'] ?? 0 ) ); ?></small>
								<small>En periodo: <?php echo esc_html( number_format_i18n( $group['in_range_count'] ?? 0 ) ); ?> | Historico: <?php echo esc_html( number_format_i18n( $group['historical_count'] ?? 0 ) ); ?></small>
							</div>
						</td>
						<td data-sort-value="<?php echo esc_attr( (float) ( $group['pending_total'] ?? 0 ) ); ?>">
							<div class="asdl-fin-stack">
								<strong><?php echo wp_kses_post( $this->format_money( $group['pending_total'] ?? 0 ) ); ?></strong>
								<small>Pedidos: <?php echo wp_kses_post( $this->format_money( $group['order_pending_total'] ?? 0 ) ); ?> | Otros: <?php echo wp_kses_post( $this->format_money( $group['other_pending_total'] ?? 0 ) ); ?></small>
								<small>Historico por cerrar: <?php echo wp_kses_post( $this->format_money( $group['historical_pending_total'] ?? 0 ) ); ?></small>
							</div>
						</td>
						<td data-sort-value="<?php echo esc_attr( (string) ( $group['oldest_date'] ?? '' ) ); ?>"><?php echo esc_html( $group['oldest_date'] ?: 'Sin fecha' ); ?></td>
						<td>
							<div class="asdl-fin-stack">
								<?php if ( '' !== $manage_url ) : ?>
									<a class="button button-secondary small" href="<?php echo esc_url( $manage_url ); ?>"><?php echo esc_html( $manage_text ); ?></a>
									<?php if ( ! empty( $group['contact_id'] ) && ! empty( $group['order_count'] ) ) : ?>
										<button
											type="button"
											class="button button-secondary small"
											data-order-assumption-open="1"
											data-contact-id="<?php echo esc_attr( (int) ( $group['contact_id'] ?? 0 ) ); ?>"
											data-contact-label="<?php echo esc_attr( sanitize_text_field( (string) ( $group['display_name'] ?? 'Perfil' ) ) ); ?>"
											data-assumption-origin="pending_collections_assumption"
										>Gestionar gasto/regalo</button>
									<?php endif; ?>
								<?php elseif ( ! empty( $group['wp_user_id'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="asdl_fin_quick_link_wp_profile" />
										<input type="hidden" name="wp_user_id" value="<?php echo esc_attr( (int) $group['wp_user_id'] ); ?>" />
										<?php $this->render_current_fiscal_hidden_input(); ?>
										<?php wp_nonce_field( 'asdl_fin_quick_link_wp_profile' ); ?>
										<?php submit_button( 'Crear y gestionar', 'secondary small', 'submit', false ); ?>
									</form>
								<?php elseif ( ! empty( $group['email'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="asdl_fin_quick_create_external_profile" />
										<input type="hidden" name="display_name" value="<?php echo esc_attr( $group['display_name'] ?? '' ); ?>" />
										<input type="hidden" name="email" value="<?php echo esc_attr( $group['email'] ?? '' ); ?>" />
										<?php $this->render_current_fiscal_hidden_input(); ?>
										<?php wp_nonce_field( 'asdl_fin_quick_create_external_profile' ); ?>
										<?php submit_button( 'Crear y gestionar', 'secondary small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
								<?php if ( $can_open_detail ) : ?>
									<button
										type="button"
										class="button button-secondary small asdl-fin-open-order-list"
										data-modal-target="dashboard-order-list"
										data-group-label="<?php echo esc_attr( $group['display_name'] ?? 'Pedidos pendientes' ); ?>"
										data-receivable-group-detail="1"
										data-contact-id="<?php echo esc_attr( (int) ( $group['contact_id'] ?? 0 ) ); ?>"
										data-wp-user-id="<?php echo esc_attr( (int) ( $group['wp_user_id'] ?? 0 ) ); ?>"
										data-email="<?php echo esc_attr( (string) ( $group['email'] ?? '' ) ); ?>"
									>Ver detalle</button>
								<?php endif; ?>
							</div>
						</td>
						</tr>
					<?php endforeach; ?>
					<tr data-dashboard-empty-row hidden>
						<td colspan="6">
							<div class="asdl-fin-empty">
								<strong>No se encontraron grupos por cobrar con ese filtro.</strong>
								<p>Ajusta la busqueda, el origen o el rango para volver a la cola completa.</p>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}

	private function render_pending_payable_groups_table( array $groups, array $args = array() ) {
		if ( empty( $groups ) ) {
			$this->render_empty_state( 'Sin obligaciones agrupadas por pagar.', 'Cuando existan documentos, deudas con perfiles, prestamos, compromisos o compras pendientes de pago, apareceran aqui agrupados por contraparte.' );
			return;
		}
		$per_page_attr = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		?>
		<div class="asdl-fin-dashboard-queue" data-dashboard-queue="payable">
			<?php
			$this->render_dashboard_queue_filters(
				'payable',
				array(
					'invoice'        => 'Docs / proveedor',
					'profile_credit' => 'Perfiles / terceros',
					'loan'           => 'Prestamos',
					'purchase'       => 'Compras',
					'commitment'     => 'Compromisos',
					'other'          => 'Otros',
				),
				'Nombre, correo, proveedor, documento o compromiso'
			);
			?>
		<div class="asdl-fin-table-wrap">
		<table class="widefat striped asdl-fin-table" data-dashboard-custom-pagination="1" data-sortable-table="1"<?php echo $per_page_attr; ?>>
			<thead>
				<tr>
					<th data-sort-type="text" data-sort-default="asc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Persona / entidad</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th>Perfil / proveedor</th>
					<th data-sort-type="number" data-sort-default="desc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Facturas / compras / otros</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="number" data-sort-default="desc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Total pendiente</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="date" data-sort-default="asc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Fecha mas antigua</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $groups as $group ) : ?>
					<?php
					$modal_items  = $this->build_pending_payable_modal_items( $group );
					$manage_url   = '';
						$manage_text  = '';
						$contact_id   = (int) ( $group['contact_id'] ?? 0 );
						$is_supplier  = ! empty( $group['is_supplier'] );
						$profile_pill = $is_supplier ? 'Proveedor' : 'Perfil';
						$create_as_customer = ! $is_supplier;
						$row_search   = $this->pending_queue_search_text( $group );
						$row_origins  = $this->pending_payable_origin_flags( $group );
						$row_ranges   = $this->pending_queue_range_flags( $group );

						if ( $contact_id > 0 ) {
							$manage_url  = $this->contact_section_url( $contact_id, $this->pending_payable_manage_section( $group ) );
							$manage_text = 'Gestionar';
						}
						?>
						<tr data-dashboard-row="1" data-search-text="<?php echo esc_attr( $row_search ); ?>" data-origin-flags="<?php echo esc_attr( $row_origins ); ?>" data-range-flags="<?php echo esc_attr( $row_ranges ); ?>">
							<td data-sort-value="<?php echo esc_attr( mb_strtolower( (string) ( $group['display_name'] ?? '' ) . ' ' . (string) ( $group['email'] ?? '' ) ) ); ?>">
								<strong><?php echo esc_html( $group['display_name'] ?? 'Sin nombre' ); ?></strong><br />
								<small><?php echo esc_html( $group['email'] ?: 'Sin correo' ); ?></small>
						</td>
						<td>
							<?php if ( $contact_id > 0 ) : ?>
								<?php echo wp_kses_post( $this->render_pill( $profile_pill . ' listo', $is_supplier ? 'warning' : 'success' ) ); ?><br />
								<small>#<?php echo esc_html( $contact_id ); ?></small>
							<?php elseif ( ! empty( $group['wp_user_id'] ) ) : ?>
								<?php echo wp_kses_post( $this->render_pill( 'Usuario WP sin perfil', 'warning' ) ); ?><br />
								<small>WP #<?php echo esc_html( (int) $group['wp_user_id'] ); ?></small>
							<?php else : ?>
								<?php echo wp_kses_post( $this->render_pill( $is_supplier ? 'Proveedor sin perfil' : 'Sin perfil', 'neutral' ) ); ?>
							<?php endif; ?>
						</td>
						<td data-sort-value="<?php echo esc_attr( (float) ( ( $group['invoice_count'] ?? 0 ) + ( $group['profile_credit_count'] ?? 0 ) + ( $group['loan_count'] ?? 0 ) + ( $group['purchase_count'] ?? 0 ) + ( $group['commitment_count'] ?? 0 ) ) ); ?>">
							<div class="asdl-fin-stack">
								<strong>Docs/proveedor: <?php echo esc_html( number_format_i18n( $group['invoice_count'] ?? 0 ) ); ?> | Perfiles/terceros: <?php echo esc_html( number_format_i18n( $group['profile_credit_count'] ?? 0 ) ); ?></strong>
								<small>Prestamos: <?php echo esc_html( number_format_i18n( $group['loan_count'] ?? 0 ) ); ?> · Compras: <?php echo esc_html( number_format_i18n( $group['purchase_count'] ?? 0 ) ); ?> · Compromisos: <?php echo esc_html( number_format_i18n( $group['commitment_count'] ?? 0 ) ); ?></small>
								<small>En periodo: <?php echo esc_html( number_format_i18n( $group['in_range_count'] ?? 0 ) ); ?> | Historico: <?php echo esc_html( number_format_i18n( $group['historical_count'] ?? 0 ) ); ?></small>
							</div>
						</td>
						<td data-sort-value="<?php echo esc_attr( (float) ( $group['pending_total'] ?? 0 ) ); ?>">
							<div class="asdl-fin-stack">
								<strong><?php echo wp_kses_post( $this->format_money( $group['pending_total'] ?? 0 ) ); ?></strong>
								<small>Docs/proveedor: <?php echo wp_kses_post( $this->format_money( $group['invoice_pending_total'] ?? 0 ) ); ?> | Perfiles/terceros: <?php echo wp_kses_post( $this->format_money( $group['profile_credit_pending_total'] ?? 0 ) ); ?></small>
								<small>Prestamos + compras + compromisos: <?php echo wp_kses_post( $this->format_money( ( $group['loan_pending_total'] ?? 0 ) + ( $group['purchase_pending_total'] ?? 0 ) + ( $group['commitment_pending_total'] ?? 0 ) ) ); ?></small>
								<small>Historico por pagar: <?php echo wp_kses_post( $this->format_money( $group['historical_pending_total'] ?? 0 ) ); ?></small>
							</div>
						</td>
						<td data-sort-value="<?php echo esc_attr( (string) ( $group['oldest_date'] ?? '' ) ); ?>"><?php echo esc_html( $group['oldest_date'] ?: 'Sin fecha' ); ?></td>
						<td>
							<div class="asdl-fin-stack">
								<?php if ( '' !== $manage_url ) : ?>
									<a class="button button-secondary small" href="<?php echo esc_url( $manage_url ); ?>"><?php echo esc_html( $manage_text ); ?></a>
								<?php elseif ( ! empty( $group['wp_user_id'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="asdl_fin_quick_link_wp_profile" />
										<input type="hidden" name="wp_user_id" value="<?php echo esc_attr( (int) $group['wp_user_id'] ); ?>" />
										<?php if ( $create_as_customer ) : ?>
											<input type="hidden" name="is_customer" value="1" />
										<?php endif; ?>
										<?php if ( $is_supplier ) : ?>
											<input type="hidden" name="is_supplier" value="1" />
											<input type="hidden" name="supplier_kind" value="general" />
										<?php endif; ?>
										<?php $this->render_current_fiscal_hidden_input(); ?>
										<?php wp_nonce_field( 'asdl_fin_quick_link_wp_profile' ); ?>
										<?php submit_button( $is_supplier ? 'Crear proveedor y gestionar' : 'Crear perfil y gestionar', 'secondary small', 'submit', false ); ?>
									</form>
								<?php elseif ( ! empty( $group['email'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="asdl_fin_quick_create_external_profile" />
										<input type="hidden" name="display_name" value="<?php echo esc_attr( $group['display_name'] ?? '' ); ?>" />
										<input type="hidden" name="email" value="<?php echo esc_attr( $group['email'] ?? '' ); ?>" />
										<?php if ( $create_as_customer ) : ?>
											<input type="hidden" name="is_customer" value="1" />
										<?php endif; ?>
										<?php if ( $is_supplier ) : ?>
											<input type="hidden" name="is_supplier" value="1" />
											<input type="hidden" name="supplier_kind" value="general" />
										<?php endif; ?>
										<input type="hidden" name="notes" value="<?php echo esc_attr( $is_supplier ? 'Proveedor creado rapidamente desde pendientes por pagar.' : 'Tercero creado rapidamente desde pendientes por pagar.' ); ?>" />
										<?php $this->render_current_fiscal_hidden_input(); ?>
										<?php wp_nonce_field( 'asdl_fin_quick_create_external_profile' ); ?>
										<?php submit_button( $is_supplier ? 'Crear proveedor y gestionar' : 'Crear tercero y gestionar', 'secondary small', 'submit', false ); ?>
									</form>
								<?php endif; ?>
								<?php if ( ! empty( $modal_items ) ) : ?>
									<button
										type="button"
										class="button button-secondary small asdl-fin-open-order-list"
										data-modal-target="dashboard-order-list"
										data-group-label="<?php echo esc_attr( $group['display_name'] ?? 'Pendientes por pagar' ); ?>"
										data-order-list="<?php echo esc_attr( wp_json_encode( $modal_items ) ); ?>"
									>Ver detalle</button>
								<?php endif; ?>
							</div>
						</td>
						</tr>
					<?php endforeach; ?>
					<tr data-dashboard-empty-row hidden>
						<td colspan="6">
							<div class="asdl-fin-empty">
								<strong>No se encontraron grupos por pagar con ese filtro.</strong>
								<p>Ajusta la busqueda, el origen o el rango para volver a la cola completa.</p>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}

	private function render_payroll_queue_table( array $items, array $account_options, $compact = false, array $args = array() ) {
		if ( empty( $items ) ) {
			$this->render_empty_state( 'Sin empleados por pagar en este rango.', 'Ajusta el rango o termina de configurar perfiles laborales para que aparezcan en la cola general.' );
			return;
		}
		$per_page_attr = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		$readonly      = $this->is_fiscal_readonly_context();
		?>
		<div class="asdl-fin-table-wrap">
		<table class="widefat striped asdl-fin-table" data-sortable-table="1"<?php echo $per_page_attr; ?>>
			<thead>
				<tr>
					<th data-sort-type="text" data-sort-default="asc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Empleado</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="date" data-sort-default="asc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Proximo pago</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="text" data-sort-default="asc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Frecuencia</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="number" data-sort-default="desc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Bruto</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="number" data-sort-default="desc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Descuentos</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th data-sort-type="number" data-sort-default="desc"><button type="button" class="asdl-fin-sort-button" data-sort-trigger><span>Neto previsto</span><span class="asdl-fin-sort-indicator" aria-hidden="true"></span></button></th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<?php
					$planned_period = $item['planned_period'] ?? null;
					$profile_link   = $this->contact_detail_url( (int) $item['contact_id'] );
					$defaults       = $this->payroll_defaults_for_employee( $item );
					$manual_summary = is_array( $item['manual_debt_summary'] ?? null ) ? $item['manual_debt_summary'] : array();
					$manual_has_debts = ! empty( $manual_summary['has_open_debts'] );
					$total_deductions = (float) ( $item['advance_deduction'] ?? 0 ) + (float) ( $item['commitment_deduction'] ?? 0 ) + (float) ( $item['commitment_payment'] ?? 0 );
					$days_until_payment = isset( $item['days_until_payment'] ) ? (int) $item['days_until_payment'] : null;
					$queue_action_mode  = sanitize_key( (string) ( $item['queue_action_mode'] ?? '' ) );
					$queue_note         = '';

					if ( ! empty( $planned_period['id'] ) ) {
						$queue_note = 'Periodo listo para gestion inmediata desde esta cola.';
					} elseif ( null !== $days_until_payment ) {
						if ( $days_until_payment < 0 ) {
							$queue_note = 'Pago vencido. Conviene gestionarlo ahora.';
						} elseif ( 0 === $days_until_payment ) {
							$queue_note = 'Corresponde pagarlo hoy.';
						} elseif ( 1 === $days_until_payment ) {
							$queue_note = 'Falta 1 dia. Ya puedes gestionarlo desde aqui.';
						} elseif ( 2 === $days_until_payment ) {
							$queue_note = 'Faltan 2 dias. Conviene dejarlo gestionado ahora.';
						}
					}
					?>
					<tr>
						<td data-sort-value="<?php echo esc_attr( mb_strtolower( (string) ( $item['display_name'] ?? '' ) . ' ' . (string) ( $item['email'] ?? '' ) ) ); ?>">
							<strong><?php echo esc_html( $item['display_name'] ?? 'Empleado' ); ?></strong><br />
							<small><?php echo esc_html( $item['email'] ?? '' ); ?></small><br />
							<small>
								<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'employment_status', $item['employment_status'] ?? '' ), $this->tone_for_status( $item['employment_status'] ?? '' ) ) ); ?>
								<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'contract_status', $item['contract_status_key'] ?? '' ), $this->tone_for_status( $item['contract_status_key'] ?? '' ) ) ); ?>
							</small><br />
							<a href="<?php echo esc_url( $profile_link ); ?>">Abrir perfil</a>
						</td>
						<td data-sort-value="<?php echo esc_attr( (string) ( $item['next_payment_date'] ?? '' ) ); ?>">
							<?php echo esc_html( $item['next_payment_date'] ?? 'Sin fecha' ); ?>
							<?php if ( ! empty( $planned_period['id'] ) ) : ?>
								<br />
								<small><?php echo wp_kses_post( $this->render_pill( 'Periodo generado', 'success' ) ); ?></small>
							<?php elseif ( 'prepare' === $queue_action_mode ) : ?>
								<br />
								<small><?php echo wp_kses_post( $this->render_pill( 'Gestionar ahora', 'warning' ) ); ?></small>
							<?php endif; ?>
							<?php if ( '' !== $queue_note ) : ?>
								<br />
								<small><?php echo esc_html( $queue_note ); ?></small>
							<?php endif; ?>
						</td>
						<td data-sort-value="<?php echo esc_attr( mb_strtolower( (string) $this->label_for( 'frequency_key', $item['pay_frequency'] ?? '' ) ) ); ?>"><?php echo esc_html( $this->label_for( 'frequency_key', $item['pay_frequency'] ?? '' ) ); ?></td>
						<td data-sort-value="<?php echo esc_attr( (float) ( $item['salary_amount'] ?? 0 ) ); ?>"><?php echo wp_kses_post( $this->format_money( $item['salary_amount'] ?? 0, $item['salary_currency'] ?? '' ) ); ?></td>
						<td data-sort-value="<?php echo esc_attr( $total_deductions ); ?>">
							<small>Adelantos: <?php echo wp_kses_post( $this->format_money( $item['advance_deduction'] ?? 0, $item['salary_currency'] ?? '' ) ); ?></small><br />
							<small>Descuentos: <?php echo wp_kses_post( $this->format_money( $item['commitment_deduction'] ?? 0, $item['salary_currency'] ?? '' ) ); ?></small><br />
							<small>Pagos extra: <?php echo wp_kses_post( $this->format_money( $item['commitment_payment'] ?? 0, $item['salary_currency'] ?? '' ) ); ?></small>
						</td>
						<td data-sort-value="<?php echo esc_attr( (float) ( $item['net_preview'] ?? 0 ) ); ?>"><?php echo wp_kses_post( $this->format_money( $item['net_preview'] ?? 0, $item['salary_currency'] ?? '' ) ); ?></td>
						<td>
							<?php if ( $readonly ) : ?>
								<div class="asdl-fin-stack">
									<small>Modo consulta</small>
									<a class="button button-small" href="<?php echo esc_url( $profile_link ); ?>">Abrir perfil</a>
								</div>
							<?php elseif ( ! empty( $planned_period['id'] ) ) : ?>
								<?php
								$template_id   = $this->payroll_payment_template_id( $compact ? 'queue-compact' : 'queue', (int) $item['contact_id'], (int) $planned_period['id'] );
								$period_label  = sanitize_text_field( (string) ( $planned_period['title'] ?? 'Periodo de nomina' ) );
								$modal_title   = sprintf( 'Procesar pago de %s', sanitize_text_field( (string) ( $item['display_name'] ?? 'Empleado' ) ) );
								$modal_copy    = $period_label ? sprintf( '%s · pago previsto para %s.', $period_label, sanitize_text_field( (string) ( $planned_period['scheduled_payment_date'] ?? ( $item['next_payment_date'] ?? '' ) ) ) ) : 'Confirma el pago de este periodo desde un modal unico.';
								?>
								<div class="asdl-fin-stack">
									<button type="button" class="button button-secondary small" data-payroll-payment-open data-payroll-payment-template="<?php echo esc_attr( $template_id ); ?>" data-payroll-payment-title="<?php echo esc_attr( $modal_title ); ?>" data-payroll-payment-description="<?php echo esc_attr( $modal_copy ); ?>">Procesar pago</button>
									<?php
									$this->render_payroll_payment_form_template(
										$template_id,
										array(
											'title'              => $period_label ?: 'Periodo listo para pago',
											'description'        => 'Registra el pago desde este modal y la cola se actualizara al terminar.',
											'return_page'        => $compact ? 'asdl-finanzas' : 'asdl-fin-payroll',
											'contact_id'         => (int) $item['contact_id'],
											'payroll_id'         => (int) $planned_period['id'],
											'paid_at'            => $planned_period['scheduled_payment_date'] ?? ( $item['next_payment_date'] ?? gmdate( 'Y-m-d' ) ),
											'currency'           => $item['salary_currency'] ?? 'USD',
											'cash_preview'       => $item['salary_cash_preview'] ?? 0,
											'net_preview'        => $item['net_preview'] ?? 0,
											'account_fallback'   => (int) ( $planned_period['payment_account_id'] ?? $item['default_account_id'] ?? 0 ),
											'payment_account_id' => (int) ( $planned_period['payment_account_id'] ?? $item['default_account_id'] ?? 0 ),
											'payment_method_key' => $planned_period['payment_method_key'] ?? 'bank_transfer',
										),
										$account_options,
										$manual_summary
									);
									?>
								</div>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-stack">
									<input type="hidden" name="action" value="asdl_fin_save_payroll_period" />
									<input type="hidden" name="return_page" value="<?php echo esc_attr( $compact ? 'asdl-finanzas' : 'asdl-fin-payroll' ); ?>" />
									<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $item['contact_id'] ); ?>" />
									<input type="hidden" name="currency" value="<?php echo esc_attr( $item['salary_currency'] ?? 'USD' ); ?>" />
									<input type="hidden" name="scheduled_payment_date" value="<?php echo esc_attr( $item['next_payment_date'] ?? gmdate( 'Y-m-d' ) ); ?>" />
									<input type="hidden" name="period_start" value="<?php echo esc_attr( $defaults['period_start'] ?? '' ); ?>" />
									<input type="hidden" name="period_end" value="<?php echo esc_attr( $defaults['period_end'] ?? '' ); ?>" />
									<input type="hidden" name="gross_amount" value="<?php echo esc_attr( $item['salary_amount'] ?? 0 ); ?>" />
									<input type="hidden" name="payment_account_id" value="<?php echo esc_attr( (int) ( $item['default_account_id'] ?? 0 ) ); ?>" />
									<input type="hidden" name="payment_method_key" value="bank_transfer" />
									<?php $this->render_current_fiscal_hidden_input(); ?>
									<?php wp_nonce_field( 'asdl_fin_save_payroll_period' ); ?>
									<?php submit_button( 'prepare' === $queue_action_mode ? 'Gestionar ahora' : 'Generar periodo', 'secondary small', 'submit', false ); ?>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private function build_pending_collection_modal_items( array $group ) {
		$items = array();

		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			$entity_type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );
			$contact_id  = ! empty( $item['contact_id'] ) ? (int) $item['contact_id'] : (int) ( $group['contact_id'] ?? 0 );
			$status      = $this->pending_collection_status_label( $entity_type, (string) ( $item['status'] ?? '' ) );
			$range_label = 'historical' === sanitize_key( (string) ( $item['range_bucket'] ?? '' ) ) ? 'Historico' : 'En periodo';
			$open_url    = '';
			$open_label  = '';
			$manage_url  = '';
			$manage_label = '';

			switch ( $entity_type ) {
				case 'order':
					$open_url   = esc_url_raw( (string) ( $item['open_url'] ?? '' ) );
					$open_label = 'Abrir pedido';
					if ( $contact_id > 0 ) {
						$manage_url   = $this->contact_section_url( $contact_id, 'asdl-fin-contact-collections' );
						$manage_label = 'Gestionar';
					}
					break;
				case 'document':
					if ( ! empty( $item['entity_id'] ) ) {
						$open_url   = $this->document_detail_url( (int) $item['entity_id'] );
						$open_label = 'Abrir movimiento';
					}
					if ( $contact_id > 0 ) {
						$manage_url   = $this->contact_section_url( $contact_id, 'asdl-fin-contact-open' );
						$manage_label = 'Gestionar';
					}
					break;
				case 'installment_plan':
					$open_url   = $this->with_current_context_url( admin_url( 'admin.php?page=asdl-fin-installments' ) );
					$open_label = 'Abrir modulo';
					if ( $contact_id > 0 ) {
						$manage_url   = $this->contact_section_url( $contact_id, 'asdl-fin-contact-commitments' );
						$manage_label = 'Gestionar';
					}
					break;
				case 'salary_advance':
					$open_url   = $this->with_current_context_url( admin_url( 'admin.php?page=asdl-fin-payroll' ) );
					$open_label = 'Abrir nomina';
					if ( $contact_id > 0 ) {
						$manage_url   = $this->contact_section_url( $contact_id, 'asdl-fin-contact-employee' );
						$manage_label = 'Gestionar';
					}
					break;
			}

			$items[] = array(
				'kind_label'   => sanitize_text_field( (string) ( $item['kind_label'] ?? 'Pendiente' ) ),
				'label'        => sanitize_text_field( (string) ( $item['label'] ?? 'Pendiente' ) ),
				'description'  => sanitize_text_field( (string) ( $item['description'] ?? '' ) ),
				'status'       => $status,
				'date'         => sanitize_text_field( (string) ( $item['date'] ?? '' ) ),
				'total'        => wp_strip_all_tags( $this->format_money( $item['amount'] ?? 0, $item['currency'] ?? '' ) ),
				'range_label'  => $range_label,
				'open_url'     => $open_url,
				'open_label'   => $open_label,
				'manage_url'   => $manage_url,
				'manage_label' => $manage_label,
			);
		}

		return $items;
	}

	private function build_pending_payable_modal_items( array $group ) {
		$items = array();

		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			$entity_type   = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );
			$contact_id    = ! empty( $item['contact_id'] ) ? (int) $item['contact_id'] : (int) ( $group['contact_id'] ?? 0 );
			$status        = $this->pending_collection_status_label( $entity_type, (string) ( $item['status'] ?? '' ) );
			$range_label   = 'historical' === sanitize_key( (string) ( $item['range_bucket'] ?? '' ) ) ? 'Historico' : 'En periodo';
			$open_url      = '';
			$open_label    = '';
			$manage_url    = '';
			$manage_label  = '';

			switch ( $entity_type ) {
				case 'document':
					if ( ! empty( $item['entity_id'] ) ) {
						$open_url   = $this->document_detail_url( (int) $item['entity_id'], $this->document_page_slug_for_document( $item ) );
						$open_label = 'Abrir movimiento';
					}
					if ( $contact_id > 0 ) {
						$manage_url   = $this->contact_section_url( $contact_id, $this->pending_payable_manage_section_for_item( $item ) );
						$manage_label = 'Gestionar';
					}
					break;
				case 'installment_plan':
					$open_url   = $this->with_current_context_url( admin_url( 'admin.php?page=asdl-fin-installments' ) );
					$open_label = 'Abrir modulo';
					if ( $contact_id > 0 ) {
						$manage_url   = $this->contact_section_url( $contact_id, 'asdl-fin-contact-commitments' );
						$manage_label = 'Gestionar';
					}
					break;
			}

			$items[] = array(
				'kind_label'   => sanitize_text_field( (string) ( $item['kind_label'] ?? 'Pendiente por pagar' ) ),
				'label'        => sanitize_text_field( (string) ( $item['label'] ?? 'Pendiente por pagar' ) ),
				'description'  => sanitize_text_field( (string) ( $item['description'] ?? '' ) ),
				'status'       => $status,
				'date'         => sanitize_text_field( (string) ( $item['date'] ?? '' ) ),
				'total'        => wp_strip_all_tags( $this->format_money( $item['amount'] ?? 0, $item['currency'] ?? '' ) ),
				'range_label'  => $range_label,
				'open_url'     => $open_url,
				'open_label'   => $open_label,
				'manage_url'   => $manage_url,
				'manage_label' => $manage_label,
			);
		}

		return $items;
	}

	private function pending_payable_manage_section( array $group ) {
		$service_document_count = 0;
		$document_count         = 0;
		$commitment_count       = 0;
		$other_count            = 0;

		foreach ( (array) ( $group['items'] ?? array() ) as $item ) {
			$entity_type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );
			if ( 'document' === $entity_type ) {
				++$document_count;
				if ( 'service_expense' === sanitize_key( (string) ( $item['document_type'] ?? '' ) ) ) {
					++$service_document_count;
				}
				continue;
			}

			if ( 'installment_plan' === $entity_type ) {
				++$commitment_count;
				continue;
			}

			++$other_count;
		}

		if ( $document_count > 0 && $document_count === $service_document_count && 0 === $commitment_count && 0 === $other_count ) {
			return 'asdl-fin-contact-services';
		}

		if ( $commitment_count > 0 && 0 === $document_count && 0 === $other_count ) {
			return 'asdl-fin-contact-commitments';
		}

		return 'asdl-fin-contact-open';
	}

	private function pending_payable_manage_section_for_item( array $item ) {
		$entity_type = sanitize_key( (string) ( $item['entity_type'] ?? '' ) );

		if ( 'document' === $entity_type && 'service_expense' === sanitize_key( (string) ( $item['document_type'] ?? '' ) ) ) {
			return 'asdl-fin-contact-services';
		}

		if ( 'installment_plan' === $entity_type ) {
			return 'asdl-fin-contact-commitments';
		}

		return 'asdl-fin-contact-open';
	}

	private function render_third_party_directory_table( array $contacts, array $args = array() ) {
		if ( empty( $contacts ) ) {
			$this->render_empty_state( 'Sin proveedores o terceros base todavia.', 'Cuando existan proveedores, servicios externos o terceros con cuenta por pagar, apareceran aqui como base de gestion.' );
			return;
		}

		$per_page_attr = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		?>
		<div class="asdl-fin-table-wrap">
		<table class="widefat striped asdl-fin-table"<?php echo $per_page_attr; ?>>
			<thead>
				<tr>
					<th>Tercero</th>
					<th>Tipo</th>
					<th>Condiciones</th>
					<th>Por pagar</th>
					<th>Origen</th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $contacts as $contact ) : ?>
					<?php
					$contact_id    = (int) ( $contact['id'] ?? 0 );
					$is_supplier   = ! empty( $contact['is_supplier'] );
					$is_external   = 'external' === ( $contact['profile_origin'] ?? '' );
					$type_label    = $is_supplier ? 'Proveedor' : 'Tercero';
					$payable_total = (float) ( $contact['payable_total'] ?? 0 );
					?>
					<tr>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $contact['display_name'] ?? 'Sin nombre' ); ?></strong>
								<small><?php echo esc_html( $contact['email'] ?: 'Sin correo' ); ?></small>
								<?php if ( ! empty( $contact['phone'] ) ) : ?>
									<small><?php echo esc_html( $contact['phone'] ); ?></small>
								<?php endif; ?>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<?php echo wp_kses_post( $this->render_pill( $type_label, $is_supplier ? 'warning' : 'neutral' ) ); ?>
								<small><?php echo esc_html( $contact['profile_roles_label'] ?? $type_label ); ?></small>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $contact['payment_terms'] ?: 'Sin definir' ); ?></strong>
								<small><?php echo esc_html( ! empty( $contact['document_id'] ) ? $contact['document_id'] : 'Sin referencia fiscal' ); ?></small>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo wp_kses_post( $this->format_money( $payable_total ) ); ?></strong>
								<small><?php echo esc_html( number_format_i18n( (int) ( $contact['document_count'] ?? 0 ) ) ); ?> movimientos/documentos</small>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'profile_origin', $contact['profile_origin'] ?? '' ), $is_external ? 'neutral' : 'success' ) ); ?>
								<small><?php echo esc_html( ! empty( $contact['wp_user_id'] ) ? 'Usuario WP #' . (int) $contact['wp_user_id'] : 'Externo' ); ?></small>
							</div>
						</td>
						<td>
							<?php if ( $contact_id > 0 ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $this->contact_detail_url( $contact_id ) ); ?>">Abrir perfil</a>
							<?php else : ?>
								<span class="asdl-fin-label">Sin perfil</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private function service_directory_contacts( ContactsRepository $repository ) {
		$contacts = array_filter(
			$repository->list_for_admin( '', 200 ),
			static function ( array $contact ) {
				if ( ! empty( $contact['is_supplier'] ) ) {
					return true;
				}

				if ( 'external' === ( $contact['profile_origin'] ?? '' ) && empty( $contact['is_employee'] ) ) {
					return true;
				}

				return (float) ( $contact['payable_total'] ?? 0 ) > 0;
			}
		);

		return array_values( $contacts );
	}

	private function service_contact_options( array $contacts ) {
		$options = array();

		foreach ( $contacts as $contact ) {
			$contact_id = (int) ( $contact['id'] ?? 0 );
			if ( $contact_id <= 0 ) {
				continue;
			}

			$supplier_kind = sanitize_key( (string) ( $contact['supplier_kind'] ?? '' ) );
			if ( ! empty( $contact['is_supplier'] ) && 'products' === $supplier_kind ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $contact['display_name'] ?? 'Tercero' ) );
			if ( ! empty( $contact['email'] ) ) {
				$label .= ' | ' . sanitize_email( (string) $contact['email'] );
			}

			if ( ! empty( $contact['is_supplier'] ) ) {
				$label .= ' | Proveedor';
				$label .= ' | ' . $this->label_for( 'supplier_kind', $supplier_kind ?: 'general' );
			} elseif ( 'external' === ( $contact['profile_origin'] ?? '' ) ) {
				$label .= ' | Tercero';
			}

			$options[ $contact_id ] = $label;
		}

		return $options;
	}

	private function summarize_service_documents( array $documents ) {
		$summary = array(
			'service_count'        => count( $documents ),
			'open_count'           => 0,
			'open_balance_total'   => 0.0,
			'paid_total'           => 0.0,
			'linked_contact_count' => 0,
		);
		$contact_ids = array();

		foreach ( $documents as $document ) {
			$balance   = max( 0, (float) ( $document['balance'] ?? 0 ) );
			$paid_total = max( 0, (float) ( $document['paid_total'] ?? 0 ) );
			$contact_id = (int) ( $document['contact_id'] ?? 0 );

			if ( $balance > 0 ) {
				$summary['open_count']++;
				$summary['open_balance_total'] += $balance;
			}

			$summary['paid_total'] += $paid_total;

			if ( $contact_id > 0 ) {
				$contact_ids[ $contact_id ] = true;
			}
		}

		$summary['linked_contact_count'] = count( $contact_ids );

		return $summary;
	}

	private function render_service_profiles_table( array $profiles, array $args = array() ) {
		if ( empty( $profiles ) ) {
			$this->render_empty_state(
				$args['empty_title'] ?? 'Sin servicios recurrentes.',
				$args['empty_description'] ?? 'Cuando existan servicios recurrentes o programados, apareceran aqui.'
			);
			return;
		}

		$mode            = sanitize_key( (string) ( $args['mode'] ?? 'profiles' ) );
		$allow_generate  = ! empty( $args['allow_generate'] );
		$allow_toggle    = ! empty( $args['allow_toggle'] );
		$show_contact    = ! isset( $args['show_contact_column'] ) || ! empty( $args['show_contact_column'] );
		$show_profile_action = ! isset( $args['show_profile_action'] ) || ! empty( $args['show_profile_action'] );
		$return_page     = ! empty( $args['return_page'] ) ? sanitize_key( (string) $args['return_page'] ) : 'asdl-fin-services';
		$return_section  = ! empty( $args['return_section'] ) ? sanitize_key( (string) $args['return_section'] ) : '';
		$profile_section = ! empty( $args['profile_section'] ) ? sanitize_key( (string) $args['profile_section'] ) : '';
		$per_page_attr   = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		$frequency_map   = ( new ServiceProfilesRepository() )->frequency_options();
		$status_map      = ( new ServiceProfilesRepository() )->status_options();
		$has_actions     = $show_profile_action || $allow_generate || $allow_toggle;
		?>
		<div class="asdl-fin-table-wrap">
		<table class="widefat striped asdl-fin-table"<?php echo $per_page_attr; ?>>
			<thead>
				<tr>
					<th>Servicio</th>
					<?php if ( $show_contact ) : ?>
						<th>Proveedor / tercero</th>
					<?php endif; ?>
					<th>Frecuencia</th>
					<th>Proxima emision</th>
					<th>Monto</th>
					<th>Condiciones</th>
					<th>Estado</th>
					<?php if ( $has_actions ) : ?>
						<th>Gestion</th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $profiles as $profile ) : ?>
					<?php
					$profile_id        = (int) ( $profile['id'] ?? 0 );
					$contact_id        = (int) ( $profile['contact_id'] ?? 0 );
					$status            = sanitize_key( (string) ( $profile['status'] ?? 'active' ) );
					$next_issue_date   = sanitize_text_field( (string) ( $profile['next_issue_date'] ?? '' ) );
					$days_to_due       = '';
					$due_label         = '';
					$frequency_label   = $frequency_map[ $profile['frequency_key'] ?? '' ] ?? ucfirst( (string) ( $profile['frequency_key'] ?? '' ) );
					$state_label       = $status_map[ $status ] ?? ucfirst( $status );
					$contact_type_label = ! empty( $profile['contact_is_supplier'] ) ? 'Proveedor' : ( 'external' === ( $profile['contact_profile_origin'] ?? '' ) ? 'Tercero' : 'Perfil' );
					$profile_url       = $contact_id > 0
						? ( '' !== $profile_section ? $this->contact_section_url( $contact_id, $profile_section ) : $this->contact_detail_url( $contact_id ) )
						: '';

					if ( '' !== $next_issue_date ) {
						$today_ts = strtotime( gmdate( 'Y-m-d' ) );
						$next_ts  = strtotime( $next_issue_date );
						if ( false !== $today_ts && false !== $next_ts ) {
							$delta_days = (int) floor( ( $next_ts - $today_ts ) / DAY_IN_SECONDS );
							if ( $delta_days < 0 ) {
								$days_to_due = sprintf( 'Vencido hace %d dia(s)', abs( $delta_days ) );
								$due_label   = 'danger';
							} elseif ( 0 === $delta_days ) {
								$days_to_due = 'Se emite hoy';
								$due_label   = 'warning';
							} else {
								$days_to_due = sprintf( 'En %d dia(s)', $delta_days );
								$due_label   = 'neutral';
							}
						}
					}
					?>
					<tr>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $profile['title'] ?? 'Servicio recurrente' ); ?></strong>
								<small><?php echo esc_html( ! empty( $profile['external_reference'] ) ? $profile['external_reference'] : 'Sin referencia externa' ); ?></small>
								<?php if ( ! empty( $profile['service_category'] ) ) : ?>
									<small><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $profile['service_category'] ) ) ); ?></small>
								<?php endif; ?>
							</div>
						</td>
						<?php if ( $show_contact ) : ?>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( $profile['contact_display_name'] ?: 'Sin tercero' ); ?></strong>
									<small><?php echo esc_html( $contact_type_label ); ?></small>
									<?php if ( ! empty( $profile['contact_email'] ) ) : ?>
										<small><?php echo esc_html( $profile['contact_email'] ); ?></small>
									<?php endif; ?>
								</div>
							</td>
						<?php endif; ?>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $frequency_label ); ?></strong>
								<small><?php echo esc_html( ! empty( $profile['start_date'] ) ? 'Inicio: ' . $profile['start_date'] : 'Sin fecha base' ); ?></small>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $next_issue_date ?: 'Sin programar' ); ?></strong>
								<?php if ( '' !== $days_to_due ) : ?>
									<small><?php echo wp_kses_post( $this->render_pill( $days_to_due, $due_label ) ); ?></small>
								<?php endif; ?>
								<?php if ( ! empty( $profile['last_issued_date'] ) ) : ?>
									<small><?php echo esc_html( 'Ultima emision: ' . $profile['last_issued_date'] ); ?></small>
								<?php endif; ?>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo wp_kses_post( $this->format_money( $profile['amount'] ?? 0, $profile['currency'] ?? '' ) ); ?></strong>
								<small><?php echo esc_html( ! empty( $profile['account_id'] ) ? 'Cuenta vinculada #' . (int) $profile['account_id'] : 'Sin cuenta sugerida' ); ?></small>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( ! empty( $profile['payment_terms'] ) ? $profile['payment_terms'] : 'Sin definir' ); ?></strong>
								<small><?php echo esc_html( sprintf( 'Vence en %d dia(s)', max( 0, (int) ( $profile['default_due_days'] ?? 0 ) ) ) ); ?></small>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<?php echo wp_kses_post( $this->render_pill( $state_label, $this->tone_for_status( $status ) ) ); ?>
								<?php if ( 'queue' === $mode && ! empty( $profile['is_due'] ) ) : ?>
									<?php echo wp_kses_post( $this->render_pill( 'Listo para emitir', 'warning' ) ); ?>
								<?php elseif ( 'upcoming' === $mode ) : ?>
									<?php echo wp_kses_post( $this->render_pill( 'Programado', 'neutral' ) ); ?>
								<?php endif; ?>
							</div>
						</td>
						<?php if ( $has_actions ) : ?>
							<td>
								<div class="asdl-fin-inline-actions">
									<?php if ( $show_profile_action && $profile_url ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $profile_url ); ?>">Abrir perfil</a>
									<?php endif; ?>
									<?php if ( $allow_generate && 'active' === $status && in_array( $mode, array( 'queue', 'profiles' ), true ) ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-inline-form">
											<input type="hidden" name="action" value="asdl_fin_generate_service_document" />
											<input type="hidden" name="return_page" value="<?php echo esc_attr( $return_page ); ?>" />
											<?php if ( '' !== $return_section ) : ?>
												<input type="hidden" name="return_section" value="<?php echo esc_attr( $return_section ); ?>" />
											<?php endif; ?>
											<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>" />
											<input type="hidden" name="issue_date" value="<?php echo esc_attr( $next_issue_date ); ?>" />
											<?php $this->render_current_fiscal_hidden_input(); ?>
											<?php wp_nonce_field( 'asdl_fin_generate_service_document' ); ?>
											<?php submit_button( 'Emitir', 'secondary small', 'submit', false ); ?>
										</form>
									<?php endif; ?>
									<?php if ( $allow_toggle && $profile_id > 0 ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-inline-form">
											<input type="hidden" name="action" value="asdl_fin_set_service_profile_status" />
											<input type="hidden" name="return_page" value="<?php echo esc_attr( $return_page ); ?>" />
											<?php if ( '' !== $return_section ) : ?>
												<input type="hidden" name="return_section" value="<?php echo esc_attr( $return_section ); ?>" />
											<?php endif; ?>
											<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>" />
											<input type="hidden" name="status" value="<?php echo esc_attr( 'active' === $status ? 'paused' : 'active' ); ?>" />
											<?php $this->render_current_fiscal_hidden_input(); ?>
											<?php wp_nonce_field( 'asdl_fin_set_service_profile_status' ); ?>
											<?php submit_button( 'active' === $status ? 'Pausar' : 'Activar', 'secondary small', 'submit', false ); ?>
										</form>
									<?php endif; ?>
								</div>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private function render_employee_directory_table( array $employees, array $periods_by_contact = array(), array $args = array() ) {
		if ( empty( $employees ) ) {
			$this->render_empty_state( 'Sin empleados registrados.', 'Cuando una ficha laboral quede guardada sobre un perfil, aparecera aqui con su estado y contrato.' );
			return;
		}

		$per_page_attr = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		?>
		<div class="asdl-fin-table-wrap">
		<table class="widefat striped asdl-fin-table"<?php echo $per_page_attr; ?>>
			<thead>
				<tr>
					<th>Empleado</th>
					<th>Estado</th>
					<th>Contrato</th>
					<th>Ingreso</th>
					<th>Proximo pago</th>
					<th>Sueldo base</th>
					<th>Periodo actual</th>
					<th>Periodo anterior</th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $employees as $employee ) : ?>
					<?php
					$contact_id      = (int) ( $employee['contact_id'] ?? 0 );
					$profile_url     = admin_url( 'admin.php?page=asdl-fin-contacts&contact_id=' . $contact_id );
					$current_period  = $periods_by_contact[ $contact_id ][0] ?? null;
					$previous_period = $periods_by_contact[ $contact_id ][1] ?? null;
					$contract_file   = ! empty( $employee['contract_attachment_url'] );
					?>
					<tr>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $employee['contact_display_name'] ?? 'Empleado' ); ?></strong>
								<small><?php echo esc_html( $employee['contact_email'] ?? 'Sin correo' ); ?></small>
								<small><?php echo esc_html( ! empty( $employee['contact_profile_origin'] ) ? $this->label_for( 'profile_origin', $employee['contact_profile_origin'] ) : 'Perfil interno' ); ?></small>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'employment_status', $employee['employment_status'] ?? '' ), $this->tone_for_status( $employee['employment_status'] ?? '' ) ) ); ?>
								<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'contract_status', $employee['contract_status_key'] ?? '' ), $this->tone_for_status( $employee['contract_status_key'] ?? '' ) ) ); ?>
								<?php if ( ! empty( $employee['termination_type'] ) ) : ?>
									<small><?php echo esc_html( $this->label_for( 'termination_type', $employee['termination_type'] ) ); ?><?php echo ! empty( $employee['termination_date'] ) ? esc_html( ' | ' . $employee['termination_date'] ) : ''; ?></small>
								<?php endif; ?>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $this->label_for( 'contract_type', $employee['contract_type'] ?? '' ) ); ?></strong>
								<small>
									<?php echo esc_html( ! empty( $employee['contract_start_date'] ) ? $employee['contract_start_date'] : 'Sin inicio' ); ?>
									<?php echo ! empty( $employee['contract_end_date'] ) ? esc_html( ' al ' . $employee['contract_end_date'] ) : ''; ?>
								</small>
								<?php if ( ! empty( $employee['renewal_required'] ) ) : ?>
									<small><?php echo esc_html( $employee['renewal_message'] ?? 'Requiere renovacion.' ); ?></small>
								<?php elseif ( $contract_file ) : ?>
									<small><a href="<?php echo esc_url( $employee['contract_attachment_url'] ); ?>" target="_blank" rel="noopener noreferrer">Ver contrato</a></small>
								<?php endif; ?>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $employee['hire_date'] ?? 'Sin fecha' ); ?></strong>
								<small><?php echo esc_html( $employee['contract_elapsed_label'] ?? 'Sin seguimiento' ); ?></small>
							</div>
						</td>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $employee['next_payment_date'] ?? 'Sin fecha' ); ?></strong>
								<small><?php echo esc_html( ! empty( $employee['pay_frequency'] ) ? $this->label_for( 'frequency_key', $employee['pay_frequency'] ) : 'Sin frecuencia' ); ?></small>
							</div>
						</td>
						<td><?php echo wp_kses_post( $this->format_money( $employee['salary_amount'] ?? 0, $employee['salary_currency'] ?? '' ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_payroll_period_snippet( $current_period, $employee['salary_currency'] ?? '' ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_payroll_period_snippet( $previous_period, $employee['salary_currency'] ?? '' ) ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( $profile_url ); ?>">Abrir perfil</a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private function render_payroll_period_snippet( $period, $currency = '' ) {
		if ( empty( $period['id'] ) ) {
			return '<span class="asdl-fin-label">Sin periodo</span>';
		}

		$status = sanitize_key( (string) ( $period['status'] ?? '' ) );

		return sprintf(
			'<div class="asdl-fin-stack"><strong>%1$s</strong><small>%2$s</small><small>%3$s</small></div>',
			esc_html( $period['title'] ?? 'Periodo' ),
			wp_kses_post( $this->render_pill( $this->label_for( 'payroll_status', $status ), $this->tone_for_status( $status ) ) ),
			wp_kses_post( $this->format_money( $period['net_amount'] ?? 0, $currency ) )
		);
	}

	private function render_consumption_interactions_panel( array $snapshot ) {
		$current_timeline   = (array) ( $snapshot['consumption_timeline'] ?? array() );
		$current_summary    = (array) ( $snapshot['consumption_summary'] ?? array() );
		$historical_years   = (array) ( $snapshot['historical_consumption_years'] ?? array() );
		$historical_by_year = (array) ( $snapshot['historical_consumption_by_year'] ?? array() );
		$selected_year      = (int) ( $snapshot['historical_consumption_selected_year'] ?? 0 );
		$all_time_timeline  = (array) ( $snapshot['consumption_all_time_timeline'] ?? array() );
		$all_time_summary   = (array) ( $snapshot['consumption_all_time_summary'] ?? array() );
		?>
		<div class="asdl-fin-inline-tabs" data-inline-tabs data-inline-tab-default="current">
			<div class="asdl-fin-inline-tab-list" role="tablist" aria-label="Consumo e interacciones">
				<button type="button" class="button button-secondary" data-inline-tab-trigger="current">Periodo actual</button>
				<button type="button" class="button button-secondary" data-inline-tab-trigger="historical">Periodos anteriores</button>
				<button type="button" class="button button-secondary" data-inline-tab-trigger="all-time">Total acumulado</button>
			</div>

			<div class="asdl-fin-inline-tab-panel" data-inline-tab-panel="current">
				<?php $this->render_consumption_summary_grid( $current_summary, 'Lectura del periodo que tienes filtrado hoy.' ); ?>
				<?php $this->render_consumption_timeline_table( $current_timeline, 'Sin consumo para mostrar en este rango.', 'Cuando existan pedidos dentro del periodo consultado, veras aqui el resumen por mes.' ); ?>
			</div>

			<div class="asdl-fin-inline-tab-panel" data-inline-tab-panel="historical" hidden>
				<?php if ( empty( $historical_years ) ) : ?>
					<?php $this->render_empty_state( 'Sin historico indexado disponible.', 'Cuando el perfil tenga ejercicios historicos reconstruidos, podras revisar aqui su consumo por mes sin depender de lectura viva.' ); ?>
				<?php else : ?>
					<div class="asdl-fin-consumption-history-toolbar">
						<div class="asdl-fin-field">
							<span>Periodo anterior</span>
							<select data-consumption-history-selector>
								<?php foreach ( $historical_years as $year_row ) : ?>
									<?php $year = (int) ( $year_row['year'] ?? 0 ); ?>
									<option value="<?php echo esc_attr( (string) $year ); ?>" <?php selected( $year, $selected_year ); ?>><?php echo esc_html( $year_row['label'] ?? (string) $year ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>Vista mensual del periodo historico seleccionado.</small>
						</div>
					</div>
					<?php foreach ( $historical_years as $year_row ) : ?>
						<?php
						$year              = (int) ( $year_row['year'] ?? 0 );
						$year_snapshot     = (array) ( $historical_by_year[ $year ] ?? array() );
						$year_timeline     = (array) ( $year_snapshot['timeline'] ?? array() );
						$year_summary      = (array) ( $year_snapshot['summary'] ?? array() );
						$year_label        = (string) ( $year_snapshot['label'] ?? ( $year_row['label'] ?? '' ) );
						$is_selected_year  = $year === $selected_year;
						?>
						<div class="asdl-fin-consumption-history-panel" data-consumption-history-panel="<?php echo esc_attr( (string) $year ); ?>" <?php echo $is_selected_year ? '' : 'hidden'; ?>>
							<?php $this->render_consumption_summary_grid( $year_summary, sprintf( 'Resumen mensual historico de %s.', $year_label ) ); ?>
							<?php $this->render_consumption_timeline_table( $year_timeline, 'Sin consumo historico para este ejercicio.', 'No se encontraron pedidos historicos indexados para este ejercicio.' ); ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="asdl-fin-inline-tab-panel" data-inline-tab-panel="all-time" hidden>
				<?php $this->render_consumption_summary_grid( $all_time_summary, 'Total reunido entre el periodo actual y todos los periodos historicos indexados de este perfil.' ); ?>
				<?php $this->render_consumption_timeline_table( $all_time_timeline, 'Sin acumulado para mostrar.', 'Cuando existan pedidos en el ejercicio activo o en ejercicios historicos indexados, veras aqui el consumo total reunido.' ); ?>
			</div>
		</div>
		<?php
	}

	private function render_consumption_summary_grid( array $summary, $description = '' ) {
		?>
		<div class="asdl-fin-data-grid asdl-fin-consumption-summary-grid">
			<div><strong>Pedidos</strong><span><?php echo esc_html( number_format_i18n( (int) ( $summary['order_count'] ?? 0 ) ) ); ?></span></div>
			<div><strong>Web</strong><span><?php echo esc_html( number_format_i18n( (int) ( $summary['web_count'] ?? 0 ) ) ); ?></span></div>
			<div><strong>POS</strong><span><?php echo esc_html( number_format_i18n( (int) ( $summary['pos_count'] ?? 0 ) ) ); ?></span></div>
			<div><strong>Total consumido</strong><span><?php echo wp_kses_post( $this->format_money( $summary['total'] ?? 0 ) ); ?></span></div>
		</div>
		<?php if ( '' !== $description ) : ?>
			<p class="asdl-fin-table-intro asdl-fin-consumption-summary-note"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function render_consumption_timeline_table( array $timeline, $empty_title = 'Sin consumo para mostrar en este rango.', $empty_description = 'Cuando existan pedidos dentro del periodo consultado, veras aqui el resumen por mes.' ) {
		if ( empty( $timeline ) ) {
			$this->render_empty_state( $empty_title, $empty_description );
			return;
		}
		?>
		<table class="widefat striped asdl-fin-table">
			<thead>
				<tr>
					<th>Periodo</th>
					<th>Pedidos</th>
					<th>Web</th>
					<th>POS</th>
					<th>Total</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $timeline as $bucket ) : ?>
					<tr>
						<td><?php echo esc_html( $this->format_consumption_period_label( (string) ( $bucket['period'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $bucket['order_count'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $bucket['web_count'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $bucket['pos_count'] ) ); ?></td>
						<td><?php echo wp_kses_post( $this->format_money( $bucket['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function format_consumption_period_label( $period ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $period, $matches ) ) {
			return (string) $period;
		}

		$year       = (int) $matches[1];
		$month      = (int) $matches[2];
		$month_name = $this->month_name_es( $month );

		if ( '' === $month_name ) {
			return (string) $period;
		}

		return sprintf( '%1$s (%2$s)', $period, $month_name );
	}

	private function month_name_es( $month ) {
		$months = array(
			1  => 'Enero',
			2  => 'Febrero',
			3  => 'Marzo',
			4  => 'Abril',
			5  => 'Mayo',
			6  => 'Junio',
			7  => 'Julio',
			8  => 'Agosto',
			9  => 'Septiembre',
			10 => 'Octubre',
			11 => 'Noviembre',
			12 => 'Diciembre',
		);

		return $months[ (int) $month ] ?? '';
	}

	private function weekday_options() {
		return array(
			'0' => 'Domingo',
			'1' => 'Lunes',
			'2' => 'Martes',
			'3' => 'Miercoles',
			'4' => 'Jueves',
			'5' => 'Viernes',
			'6' => 'Sabado',
		);
	}

	private function payroll_defaults_for_employee( $employee_profile ) {
		$scheduled = ! empty( $employee_profile['next_payment_date'] ) ? $employee_profile['next_payment_date'] : gmdate( 'Y-m-d' );
		$frequency = sanitize_key( $employee_profile['pay_frequency'] ?? 'monthly' );
		$scheduled_dt = new \DateTimeImmutable( $scheduled );

		switch ( $frequency ) {
			case 'weekly':
				$period_start = $scheduled_dt->sub( new \DateInterval( 'P6D' ) )->format( 'Y-m-d' );
				break;
			case 'biweekly':
				$period_start = $scheduled_dt->sub( new \DateInterval( 'P13D' ) )->format( 'Y-m-d' );
				break;
			case 'monthly':
			default:
				$period_start = $scheduled_dt->modify( 'first day of this month' )->format( 'Y-m-d' );
				break;
		}

		return array(
			'period_start'           => $period_start,
			'period_end'             => $scheduled_dt->format( 'Y-m-d' ),
			'scheduled_payment_date' => $scheduled_dt->format( 'Y-m-d' ),
		);
	}

	private function commitment_payroll_schedule_options( $employee_profile, $limit = 8 ) {
		$limit = max( 1, min( 24, (int) $limit ) );
		$next_payment_date = ! empty( $employee_profile['next_payment_date'] ) ? sanitize_text_field( (string) $employee_profile['next_payment_date'] ) : '';
		$frequency = sanitize_key( (string) ( $employee_profile['pay_frequency'] ?? '' ) );

		if ( '' === $next_payment_date || '' === $frequency ) {
			return array();
		}

		try {
			$current = new \DateTimeImmutable( $next_payment_date );
		} catch ( \Exception $e ) {
			return array();
		}

		$options = array();

		for ( $index = 0; $index < $limit; $index++ ) {
			$key = $current->format( 'Y-m-d' );
			$options[ $key ] = $current->format( 'd/m/Y' );

			switch ( $frequency ) {
				case 'weekly':
					$current = $current->add( new \DateInterval( 'P7D' ) );
					break;
				case 'biweekly':
					$current = $current->add( new \DateInterval( 'P14D' ) );
					break;
				case 'monthly':
				default:
					$current = $current->modify( '+1 month' );
					break;
			}
		}

		return $options;
	}

	private function render_manage_order_form( $order_id, $return_page, array $return_context = array(), array $args = array() ) {
		$args  = wp_parse_args(
			$args,
			array(
				'label' => 'Caso especial',
				'mode'  => 'manage',
			)
		);
		$label = sanitize_text_field( (string) $args['label'] );
		$mode  = sanitize_key( (string) $args['mode'] );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="asdl_fin_manage_order" />
			<input type="hidden" name="order_id" value="<?php echo esc_attr( absint( $order_id ) ); ?>" />
			<input type="hidden" name="manage_mode" value="<?php echo esc_attr( $mode ); ?>" />
			<input type="hidden" name="return_page" value="<?php echo esc_attr( sanitize_key( $return_page ) ); ?>" />
			<?php $this->render_current_fiscal_hidden_input(); ?>
			<?php if ( ! empty( $return_context['contact_id'] ) ) : ?>
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( absint( $return_context['contact_id'] ) ); ?>" />
			<?php endif; ?>
			<?php if ( ! empty( $return_context['range_from'] ) ) : ?>
				<input type="hidden" name="range_from" value="<?php echo esc_attr( sanitize_text_field( $return_context['range_from'] ) ); ?>" />
			<?php endif; ?>
			<?php if ( ! empty( $return_context['range_to'] ) ) : ?>
				<input type="hidden" name="range_to" value="<?php echo esc_attr( sanitize_text_field( $return_context['range_to'] ) ); ?>" />
			<?php endif; ?>
			<?php if ( ! empty( $return_context['order_limit'] ) ) : ?>
				<input type="hidden" name="order_limit" value="<?php echo esc_attr( absint( $return_context['order_limit'] ) ); ?>" />
			<?php endif; ?>
			<?php wp_nonce_field( 'asdl_fin_manage_order' ); ?>
			<?php submit_button( $label, 'secondary small', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_documents_table( array $documents, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'per_page'           => 0,
				'expanded_per_page'  => 0,
				'show_expand_toggle' => false,
				'show_number'        => true,
				'allow_cancel'       => false,
			)
		);

		if ( empty( $documents ) ) {
			$this->render_empty_state(
				$args['empty_title'] ?? 'Aun no hay movimientos registrados.',
				$args['empty_description'] ?? 'Desde esta vista naceran ventas, gastos, sueldos, ajustes y prestamos.'
			);
			return;
		}
		$per_page_attr          = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		$expanded_per_page      = max( 0, (int) ( $args['expanded_per_page'] ?? 0 ) );
		$expanded_per_page_attr = ( ! empty( $args['show_expand_toggle'] ) && $expanded_per_page > (int) ( $args['per_page'] ?? 0 ) )
			? ' data-dashboard-per-page-expanded="' . esc_attr( $expanded_per_page ) . '"'
			: '';
		$show_number            = ! empty( $args['show_number'] );
		$allow_cancel           = ! empty( $args['allow_cancel'] ) && ! $this->is_fiscal_readonly_context();
		?>
		<div class="asdl-fin-table-wrap">
		<table class="widefat striped asdl-fin-table"<?php echo $per_page_attr; ?><?php echo $expanded_per_page_attr; ?>>
			<thead>
				<tr>
					<?php if ( $show_number ) : ?>
						<th>Numero</th>
					<?php endif; ?>
					<th>Titulo</th>
					<th>Tipo</th>
					<th>Origen</th>
					<th>Estado</th>
					<th>Pago</th>
					<th>Total</th>
					<th>Saldo</th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $documents as $document ) : ?>
					<?php
					$order_url       = $this->source_order_edit_url( $document['linked_object_type'] ?? '', $document['linked_external_id'] ?? '' );
					$detail_page     = ! empty( $args['detail_page'] ) ? sanitize_key( (string) $args['detail_page'] ) : $this->document_page_slug_for_document( $document );
					$title_markup    = ! empty( $order_url )
						? '<a href="' . esc_url( $order_url ) . '"><strong>' . esc_html( $document['title'] ) . '</strong></a>'
						: '<strong>' . esc_html( $document['title'] ) . '</strong>';
					$profile_markup  = $this->render_profile_reference( (int) ( $document['contact_id'] ?? 0 ), $document['contact_display_name'] ?? '', $document['contact_email'] ?? '', 'Sin perfil enlazado' );
					$reference_value = $document['external_reference'] ?? '';
					if ( '' === $reference_value && ! empty( $document['linked_external_ref'] ) && ! empty( $order_url ) ) {
						$reference_value = 'Pedido Woo/OpenPOS #' . sanitize_text_field( (string) $document['linked_external_ref'] );
					}
					?>
					<tr>
						<?php if ( $show_number ) : ?>
							<td><?php echo esc_html( $document['document_number'] ?: 'Pendiente' ); ?></td>
						<?php endif; ?>
						<td>
							<div class="asdl-fin-stack">
								<?php echo wp_kses_post( $title_markup ); ?>
								<span class="asdl-fin-context-line"><?php echo wp_kses_post( $profile_markup ); ?></span>
								<small><?php echo esc_html( $reference_value ?: 'Sin referencia externa' ); ?></small>
							</div>
						</td>
						<td><?php echo esc_html( $this->label_for( 'document_type', $document['document_type'] ) ); ?></td>
						<td><?php echo esc_html( $this->label_for( 'source_type', $document['linked_provider'] ?? $document['source_type'] ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'financial_status', $document['financial_status'] ), $this->tone_for_status( $document['financial_status'] ) ) ); ?></td>
						<td>
							<div class="asdl-fin-stack">
								<?php $document_payment_status_ui = $this->resolve_document_payment_status_for_ui( $document ); ?>
								<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'payment_status', $document_payment_status_ui ), $this->tone_for_status( $document_payment_status_ui ) ) ); ?>
								<?php if ( in_array( $document_payment_status_ui, array( 'partial', 'paid' ), true ) && ! empty( $document['last_payment_date'] ) ) : ?>
									<small class="asdl-fin-context-line">Ultimo abono: <?php echo esc_html( $this->format_short_date( $document['last_payment_date'] ) ); ?></small>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo wp_kses_post( $this->format_money( $document['total'] ) ); ?></td>
						<td><?php echo wp_kses_post( $this->format_money( $document['balance'] ) ); ?></td>
						<td>
							<div class="asdl-fin-stack">
								<a class="button button-small" href="<?php echo esc_url( $this->document_detail_url( (int) $document['id'], $detail_page ) ); ?>">Ver detalle</a>
								<?php if ( $allow_cancel && 'void' !== sanitize_key( (string) ( $document['financial_status'] ?? '' ) ) ) : ?>
									<?php $this->render_cancel_action_form( 'asdl_fin_cancel_document', 'document_id', (int) $document['id'], 'asdl_fin_cancel_document', 'Motivo para anular este movimiento' ); ?>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private function render_expenses_table( array $documents, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'per_page'     => 0,
				'allow_cancel' => false,
			)
		);

		if ( empty( $documents ) ) {
			$this->render_empty_state(
				'Aun no hay gastos registrados.',
				'Cuando registres el primer gasto de empresa o asumas un pedido como consumo interno, aparecera aqui con su saldo, contraparte y gestion.'
			);
			return;
		}

		$per_page_attr = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		$allow_cancel  = ! empty( $args['allow_cancel'] ) && ! $this->is_fiscal_readonly_context();
		?>
		<div class="asdl-fin-table-wrap">
			<table class="widefat striped asdl-fin-table"<?php echo $per_page_attr; ?>>
				<thead>
					<tr>
						<th>Numero</th>
						<th>Concepto</th>
						<th>Contraparte</th>
						<th>Fecha</th>
						<th>Estado</th>
						<th>Pago</th>
						<th>Total</th>
						<th>Saldo</th>
						<th>Gestion</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $documents as $document ) : ?>
						<?php
						$counterparty_markup = $this->render_profile_reference(
							(int) ( $document['contact_id'] ?? 0 ),
							$document['contact_display_name'] ?? '',
							$document['contact_email'] ?? '',
							'Empresa / sin contraparte'
						);
						$date_parts = array();
						if ( ! empty( $document['issue_date'] ) ) {
							$date_parts[] = 'Emision: ' . $this->format_short_date( (string) $document['issue_date'] );
						}
						if ( ! empty( $document['due_date'] ) ) {
							$date_parts[] = 'Vence: ' . $this->format_short_date( (string) $document['due_date'] );
						}
						?>
						<tr>
							<td><?php echo esc_html( $document['document_number'] ?: 'Pendiente' ); ?></td>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( $document['title'] ?: 'Gasto sin concepto' ); ?></strong>
									<small><?php echo esc_html( $this->expense_origin_label_for_document( $document ) ); ?></small>
									<small><?php echo esc_html( $document['external_reference'] ?: 'Sin referencia externa' ); ?></small>
								</div>
							</td>
							<td><?php echo wp_kses_post( $counterparty_markup ); ?></td>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( ! empty( $document['issue_date'] ) ? $this->format_short_date( (string) $document['issue_date'] ) : 'Sin fecha' ); ?></strong>
									<small><?php echo esc_html( ! empty( $date_parts ) ? implode( ' · ', $date_parts ) : 'Sin vencimiento definido' ); ?></small>
								</div>
							</td>
							<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'financial_status', $document['financial_status'] ), $this->tone_for_status( $document['financial_status'] ) ) ); ?></td>
							<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'payment_status', $this->resolve_document_payment_status_for_ui( $document ) ), $this->tone_for_status( $this->resolve_document_payment_status_for_ui( $document ) ) ) ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( $document['total'], $document['currency'] ?? '' ) ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( $document['balance'], $document['currency'] ?? '' ) ); ?></td>
							<td>
								<div class="asdl-fin-stack">
									<a class="button button-small" href="<?php echo esc_url( $this->document_detail_url( (int) $document['id'], $this->document_page_slug_for_document( $document ) ) ); ?>">Ver detalle</a>
									<?php if ( $allow_cancel && 'void' !== sanitize_key( (string) ( $document['financial_status'] ?? '' ) ) ) : ?>
										<?php $this->render_cancel_action_form( 'asdl_fin_cancel_document', 'document_id', (int) $document['id'], 'asdl_fin_cancel_document', 'Motivo para anular este gasto' ); ?>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_payments_table( array $payments, array $args = array() ) {
		if ( empty( $payments ) ) {
			$this->render_empty_state( 'Aun no hay cobros ni pagos registrados.', 'Los movimientos que registres aqui luego podran asignarse a documentos concretos.' );
			return;
		}
		$per_page_attr = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		$allow_cancel  = ! empty( $args['allow_cancel'] ) && ! $this->is_fiscal_readonly_context();
		?>
		<div class="asdl-fin-table-wrap">
		<table class="widefat striped asdl-fin-table"<?php echo $per_page_attr; ?>>
			<thead>
				<tr>
					<th>Numero</th>
					<th>Tipo</th>
					<th>Fecha</th>
					<th>Metodo</th>
					<th>Monto</th>
					<th>Disponible</th>
					<th>Estado</th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $payments as $payment ) : ?>
					<?php $payment_meta = $this->payment_meta( $payment ); ?>
					<tr>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $payment['payment_number'] ); ?></strong>
								<span class="asdl-fin-context-line"><?php echo wp_kses_post( $this->render_profile_reference( (int) ( $payment['contact_id'] ?? 0 ), $payment['contact_display_name'] ?? '', $payment['contact_email'] ?? '', 'Sin perfil enlazado' ) ); ?></span>
							</div>
						</td>
						<td><?php echo wp_kses_post( $this->render_payment_type_value( $payment, $payment_meta ) ); ?></td>
						<td><?php echo esc_html( $payment['payment_date'] ?: '—' ); ?></td>
						<td><?php echo wp_kses_post( $this->render_payment_method_value( $payment, $payment_meta ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_payment_amount_value( $payment, $payment_meta ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_payment_available_value( $payment, $payment_meta ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'payment_record_status', $payment['status'] ), $this->tone_for_status( $payment['status'] ) ) ); ?></td>
						<td>
							<div class="asdl-fin-stack">
								<a class="button button-small" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $this->receipt_url( 'payment', (int) $payment['id'] ) ); ?>">Comprobante</a>
								<?php if ( $allow_cancel && 'void' !== sanitize_key( (string) ( $payment['status'] ?? '' ) ) ) : ?>
									<?php if ( $this->is_dual_discount_child_payment( $payment, $payment_meta ) ) : ?>
										<small class="asdl-fin-context-line">Se revierte con el abono principal.</small>
									<?php else : ?>
										<?php $this->render_cancel_action_form( 'asdl_fin_cancel_payment', 'payment_id', (int) $payment['id'], 'asdl_fin_cancel_payment', 'Motivo para anular este pago o abono' ); ?>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	private function render_installment_plans_table( array $plans, array $args = array() ) {
		if ( empty( $plans ) ) {
			$this->render_empty_state( 'Aun no hay compromisos registrados.', 'Aqui apareceran prestamos, deudas acordadas y descuentos programados creados dentro del core.' );
			return;
		}
		$allow_cancel = ! empty( $args['allow_cancel'] ) && ! $this->is_fiscal_readonly_context();
		?>
		<div class="asdl-fin-table-wrap asdl-fin-commitments-table-wrap">
			<table class="widefat striped asdl-fin-table asdl-fin-table-compact asdl-fin-commitments-table">
				<thead>
					<tr>
						<th>Compromiso</th>
						<th>Sentido</th>
						<th>Origen</th>
						<th>Cobro / pago</th>
						<th>Cuotas</th>
						<th>Frecuencia</th>
						<th>Monto</th>
						<th>Saldo</th>
						<th>Estado</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $plans as $plan ) : ?>
						<tr>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( $plan['title'] ); ?></strong>
									<small><?php echo esc_html( $this->label_for( 'frequency_key', $plan['frequency_key'] ) ); ?></small>
								</div>
							</td>
							<td><?php echo esc_html( $this->label_for( 'settlement_direction', $plan['settlement_direction'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $this->label_for( 'commitment_origin', $plan['commitment_origin'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $this->label_for( 'collection_mode', $plan['collection_mode'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $plan['installment_count'] ) ); ?></td>
							<td><?php echo esc_html( $this->label_for( 'frequency_key', $plan['frequency_key'] ) ); ?></td>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo wp_kses_post( $this->format_money( $plan['total_amount'] ) ); ?></strong>
									<?php if ( ! empty( $plan['target_installment_amount'] ) ) : ?>
										<small>Cuota sugerida: <?php echo wp_kses_post( $this->format_money( $plan['target_installment_amount'] ) ); ?></small>
									<?php endif; ?>
								</div>
							</td>
							<td><?php echo wp_kses_post( $this->format_money( $plan['balance'] ) ); ?></td>
							<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'status', $plan['status'] ), $this->tone_for_status( $plan['status'] ) ) ); ?></td>
							<td>
								<div class="asdl-fin-stack">
									<?php if ( ! empty( $plan['meta']['last_payment_id'] ) ) : ?>
										<a class="button button-small" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $this->receipt_url( 'payment', (int) $plan['meta']['last_payment_id'] ) ); ?>">Ultimo comprobante</a>
									<?php else : ?>
										<span class="asdl-fin-label">Sin pagos aun</span>
									<?php endif; ?>
									<?php if ( $allow_cancel && ! in_array( sanitize_key( (string) ( $plan['status'] ?? '' ) ), array( 'inactive', 'closed', 'cancelled' ), true ) ) : ?>
										<?php $this->render_cancel_action_form( 'asdl_fin_cancel_installment_plan', 'plan_id', (int) $plan['id'], 'asdl_fin_cancel_installment_plan', 'Motivo para anular este compromiso' ); ?>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_allocations_table( array $allocations, array $args = array() ) {
		if ( empty( $allocations ) ) {
			$this->render_empty_state( 'Aun no hay asignaciones registradas.', 'Cuando apliques un pago a un documento, el historico aparecera aqui.' );
			return;
		}

		$per_page_attr = ! empty( $args['per_page'] ) ? ' data-dashboard-per-page="' . esc_attr( (int) $args['per_page'] ) . '"' : '';
		$table_class   = 'widefat striped asdl-fin-table';

		if ( ! empty( $args['compact'] ) ) {
			$table_class .= ' asdl-fin-table-compact';
		}
		?>
		<div class="asdl-fin-table-wrap">
			<table class="<?php echo esc_attr( $table_class ); ?>"<?php echo $per_page_attr; ?>>
				<thead>
					<tr>
						<th>Pago</th>
						<th>Movimiento</th>
						<th>Perfil</th>
						<th>Fecha</th>
						<th>Monto</th>
						<th>Notas</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $allocations as $allocation ) : ?>
						<tr>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( $allocation['payment_number'] ?: '—' ); ?></strong>
									<small><?php echo esc_html( $allocation['payment_type'] ?? 'Pago aplicado' ); ?></small>
								</div>
							</td>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( $allocation['document_number'] ?: '—' ); ?></strong>
									<small><?php echo esc_html( $allocation['document_title'] ?: 'Sin titulo' ); ?></small>
								</div>
							</td>
							<td><?php echo esc_html( $allocation['contact_name'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $allocation['created_at'] ?: '—' ); ?></td>
							<td><?php echo wp_kses_post( $this->format_money( $allocation['amount'] ) ); ?></td>
							<td><?php echo esc_html( $allocation['notes'] ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_events_table( array $events ) {
		if ( empty( $events ) ) {
			$this->render_empty_state( 'Aun no hay historial registrado.', 'Los cambios, anulaciones y eventos del documento apareceran aqui.' );
			return;
		}
		?>
		<div class="asdl-fin-table-wrap">
			<table class="widefat striped asdl-fin-table asdl-fin-table-compact">
				<thead>
					<tr>
						<th>Fecha</th>
						<th>Evento</th>
						<th>Actor</th>
						<th>Detalle</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $events as $event ) : ?>
						<tr>
							<td><?php echo esc_html( ! empty( $event['created_at'] ) ? $event['created_at'] : '—' ); ?></td>
							<td><?php echo esc_html( $event['event_type'] ?: 'evento' ); ?></td>
							<td><?php echo esc_html( $event['actor_label'] ?: 'Sistema' ); ?></td>
							<td><?php echo esc_html( $event['message'] ?: 'Sin detalle adicional.' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_salary_advances_table( array $advances, array $account_options, array $args = array() ) {
		if ( empty( $advances ) ) {
			?>
			<div class="asdl-fin-empty">
				<strong>Sin adelantos registrados.</strong>
				<p>Cuando registres un adelanto de sueldo, quedara aqui con su saldo pendiente para futura compensacion.</p>
			</div>
			<?php
			return;
		}
		$allow_cancel = ! empty( $args['allow_cancel'] ) && ! $this->is_fiscal_readonly_context();
		?>
		<div class="asdl-fin-table-wrap">
			<table class="widefat striped asdl-fin-table asdl-fin-table-compact asdl-fin-advance-table">
				<thead>
					<tr>
						<th>Adelanto</th>
						<th>Importes</th>
						<th>Recuperacion prevista</th>
						<th>Estado y referencia</th>
						<th>Gestion</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $advances as $advance ) : ?>
						<?php
						$advance_status      = sanitize_key( (string) ( $advance['status'] ?? '' ) );
						$advance_tone        = in_array( $advance_status, array( 'active', 'partial' ), true ) ? 'warning' : $this->tone_for_status( $advance_status );
						$source_account_name = ! empty( $advance['source_account_id'] ) ? ( $account_options[ (int) $advance['source_account_id'] ] ?? 'Cuenta #' . (int) $advance['source_account_id'] ) : 'Sin definir';
						?>
						<tr>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( $this->format_date_with_weekday( $advance['issued_at'] ?? '', 'Sin fecha' ) ); ?></strong>
									<small><?php echo esc_html( $this->label_for( 'advance_recovery_mode', $advance['recovery_mode'] ?? '' ) ); ?></small>
									<small>Cuenta: <?php echo esc_html( $source_account_name ); ?></small>
								</div>
							</td>
							<td>
								<div class="asdl-fin-stack">
									<strong>Monto: <?php echo wp_kses_post( $this->format_money( $advance['total_amount'] ?? 0, $advance['currency'] ?? '' ) ); ?></strong>
									<small>Recuperado: <?php echo wp_kses_post( $this->format_money( $advance['recovered_amount'] ?? 0, $advance['currency'] ?? '' ) ); ?></small>
									<small>Saldo: <?php echo wp_kses_post( $this->format_money( $advance['balance'] ?? 0, $advance['currency'] ?? '' ) ); ?></small>
								</div>
							</td>
							<td>
								<div class="asdl-fin-stack">
									<strong><?php echo esc_html( ! empty( $advance['expected_recovery_date'] ) ? $this->format_date_with_weekday( $advance['expected_recovery_date'] ) : 'Sin fecha prevista' ); ?></strong>
									<small>Recuperacion: <?php echo esc_html( $this->label_for( 'advance_recovery_mode', $advance['recovery_mode'] ?? '' ) ); ?></small>
								</div>
							</td>
							<td>
								<div class="asdl-fin-stack">
									<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'advance_status', $advance['status'] ?? '' ), $advance_tone ) ); ?>
									<small>Referencia: <?php echo esc_html( ! empty( $advance['reference'] ) ? $advance['reference'] : 'Sin referencia' ); ?></small>
								</div>
							</td>
							<td>
								<div class="asdl-fin-stack asdl-fin-advance-actions">
									<a class="button button-small" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $this->receipt_url( 'salary_advance', (int) $advance['id'] ) ); ?>">Comprobante</a>
									<?php if ( $allow_cancel && 'cancelled' !== sanitize_key( (string) ( $advance['status'] ?? '' ) ) ) : ?>
										<?php $this->render_cancel_action_form( 'asdl_fin_cancel_salary_advance', 'advance_id', (int) $advance['id'], 'asdl_fin_cancel_salary_advance', 'Motivo de anulacion' ); ?>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

		private function render_payroll_periods_table( array $periods, array $contact, array $account_options ) {
			if ( empty( $periods ) ) {
				?>
			<div class="asdl-fin-empty">
				<strong>Sin periodos de nomina.</strong>
				<p>Cuando generes la primera semana, quincena o mes a pagar, aparecera aqui con su estado.</p>
			</div>
				<?php
				return;
			}
			$manual_debt_snapshot = ( new \ASDLabs\Finance\Finance\PayrollManualSettlementService() )->get_open_debts_for_contact( (int) ( $contact['id'] ?? 0 ) );
			$manual_debt_summary  = is_wp_error( $manual_debt_snapshot ) ? array() : (array) ( $manual_debt_snapshot['summary'] ?? array() );
			$manual_has_debts     = ! empty( $manual_debt_summary['has_open_debts'] );
			?>
		<table class="widefat striped asdl-fin-table">
			<thead>
				<tr>
					<th>Periodo</th>
					<th>Pago previsto</th>
					<th>Bruto</th>
					<th>Adelantos</th>
					<th>Compromisos</th>
					<th>Neto</th>
					<th>Estado</th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $periods as $period ) : ?>
					<?php $payroll_status = sanitize_key( (string) ( $period['status'] ?? '' ) ); ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $period['title'] ?? '' ); ?></strong><br />
							<small><?php echo esc_html( ( $period['period_start'] ?? '' ) . ' al ' . ( $period['period_end'] ?? '' ) ); ?></small>
						</td>
						<td>
							<?php echo esc_html( $period['scheduled_payment_date'] ?? 'Sin fecha' ); ?>
							<?php if ( ! empty( $period['paid_at'] ) ) : ?>
								<br />
								<small>Pagado: <?php echo esc_html( $period['paid_at'] ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo wp_kses_post( $this->format_money( $period['gross_amount'] ?? 0, $period['currency'] ?? '' ) ); ?></td>
						<td><?php echo wp_kses_post( $this->format_money( $period['advance_deduction_amount'] ?? 0, $period['currency'] ?? '' ) ); ?></td>
						<td>
							<small>Desc.: <?php echo wp_kses_post( $this->format_money( $period['commitment_deduction_amount'] ?? 0, $period['currency'] ?? '' ) ); ?></small><br />
							<small>Pagos: <?php echo wp_kses_post( $this->format_money( $period['commitment_payout_amount'] ?? 0, $period['currency'] ?? '' ) ); ?></small>
						</td>
						<td><?php echo wp_kses_post( $this->format_money( $period['net_amount'] ?? 0, $period['currency'] ?? '' ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_pill( $this->label_for( 'payroll_status', $period['status'] ?? '' ), $this->tone_for_status( $payroll_status ) ) ); ?></td>
						<td>
							<?php if ( $this->is_fiscal_readonly_context() ) : ?>
								<span class="asdl-fin-label">Modo consulta</span><br />
								<?php if ( ! empty( $period['document_id'] ) ) : ?>
									<small>Documento #<?php echo esc_html( (int) $period['document_id'] ); ?></small>
								<?php endif; ?>
							<?php elseif ( 'planned' === $payroll_status ) : ?>
								<?php
								$salary_cash_preview = max(
									0,
									(float) ( $period['gross_amount'] ?? 0 )
									- (float) ( $period['other_deduction_amount'] ?? 0 )
									- (float) ( $period['advance_deduction_amount'] ?? 0 )
									- (float) ( $period['commitment_deduction_amount'] ?? 0 )
								);
								$template_id = $this->payroll_payment_template_id( 'contact-period', (int) ( $contact['id'] ?? 0 ), (int) ( $period['id'] ?? 0 ) );
								?>
								<div class="asdl-fin-stack">
									<button type="button" class="button button-secondary small" data-payroll-payment-open data-payroll-payment-template="<?php echo esc_attr( $template_id ); ?>" data-payroll-payment-title="<?php echo esc_attr( sprintf( 'Procesar pago de %s', sanitize_text_field( (string) ( $contact['display_name'] ?? 'Empleado' ) ) ) ); ?>" data-payroll-payment-description="<?php echo esc_attr( sprintf( '%s · pago previsto para %s.', sanitize_text_field( (string) ( $period['title'] ?? 'Periodo de nomina' ) ), sanitize_text_field( (string) ( $period['scheduled_payment_date'] ?? '' ) ) ) ); ?>">Procesar pago</button>
									<?php
									$this->render_payroll_payment_form_template(
										$template_id,
										array(
											'title'              => $period['title'] ?? 'Periodo de nomina',
											'description'        => 'Gestiona este pago desde un modal unico y actualiza el perfil al terminar.',
											'return_page'        => 'asdl-fin-contacts',
											'contact_id'         => (int) $contact['id'],
											'payroll_id'         => (int) $period['id'],
											'paid_at'            => $period['scheduled_payment_date'] ?? gmdate( 'Y-m-d' ),
											'currency'           => $period['currency'] ?? 'USD',
											'cash_preview'       => $salary_cash_preview,
											'net_preview'        => $period['net_amount'] ?? 0,
											'account_fallback'   => (int) ( $period['payment_account_id'] ?? 0 ),
											'payment_account_id' => (int) ( $period['payment_account_id'] ?? 0 ),
											'payment_method_key' => $period['payment_method_key'] ?? 'bank_transfer',
										),
										$account_options,
										$manual_debt_summary
									);
									?>
								</div>
							<?php else : ?>
								<span class="asdl-fin-label">Documento #<?php echo esc_html( (int) ( $period['document_id'] ?? 0 ) ); ?></span><br />
								<small><?php echo esc_html( ! empty( $period['payment_account_id'] ) ? ( $account_options[ (int) $period['payment_account_id'] ] ?? 'Cuenta #' . (int) $period['payment_account_id'] ) : 'Sin cuenta definida' ); ?></small><br />
								<a class="button button-small" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $this->receipt_url( 'payroll_period', (int) $period['id'], array( 'contact_id' => (int) $contact['id'] ) ) ); ?>">Comprobante</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_source_links_table( array $links ) {
		if ( empty( $links ) ) {
			$this->render_empty_state( 'Aun no hay vinculos sincronizados.', 'Despues de sincronizar pedidos Woo u OpenPOS, los vinculos apareceran aqui.' );
			return;
		}
		?>
		<table class="widefat striped asdl-fin-table">
			<thead>
				<tr>
					<th>Origen</th>
					<th>Objeto</th>
					<th>Referencia</th>
					<th>Movimiento</th>
					<th>Ultima sincronizacion</th>
					<th>Override</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $links as $link ) : ?>
					<tr>
						<td><?php echo esc_html( $this->label_for( 'provider', $link['provider'] ) ); ?></td>
						<td><?php echo esc_html( $link['object_type'] ); ?></td>
						<td><?php echo esc_html( $link['external_ref'] ?: $link['external_id'] ); ?></td>
						<td><a href="<?php echo esc_url( $this->document_detail_url( (int) $link['document_id'] ) ); ?>">#<?php echo esc_html( (int) $link['document_id'] ); ?></a></td>
						<td><?php echo esc_html( $link['last_synced_at'] ?: '—' ); ?></td>
						<td><?php echo wp_kses_post( $this->render_pill( ! empty( $link['override_locked'] ) ? 'Bloqueado' : 'Libre', ! empty( $link['override_locked'] ) ? 'warning' : 'neutral' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_rules_table( array $rules ) {
		if ( empty( $rules ) ) {
			$this->render_empty_state( 'Aun no hay automatizaciones registradas.', 'Cuando crees la primera automatizacion de clasificacion, aparecera aqui con sus condiciones y acciones.' );
			return;
		}
		?>
		<table class="widefat striped asdl-fin-table">
			<thead>
				<tr>
					<th>Automatizacion</th>
					<th>Alcance</th>
					<th>Condiciones</th>
					<th>Acciones</th>
					<th>Estado</th>
					<th>Gestion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rules as $rule ) : ?>
					<tr>
						<td>
							<div class="asdl-fin-stack">
								<strong><?php echo esc_html( $rule['rule_name'] ); ?></strong>
								<small>Prioridad: <?php echo esc_html( number_format_i18n( $rule['priority'] ) ); ?></small>
							</div>
						</td>
						<td><?php echo esc_html( $this->label_for( 'scope_type', $rule['scope_type'] ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_rule_conditions_summary( $rule['conditions'] ?? array() ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_rule_actions_summary( $rule['actions'] ?? array() ) ); ?></td>
						<td><?php echo wp_kses_post( $this->render_pill( ! empty( $rule['is_active'] ) ? 'Activa' : 'Inactiva', ! empty( $rule['is_active'] ) ? 'success' : 'neutral' ) ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( $this->rule_toggle_url( (int) $rule['id'], empty( $rule['is_active'] ) ) ); ?>">
								<?php echo esc_html( ! empty( $rule['is_active'] ) ? 'Desactivar' : 'Activar' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_document_files( array $files ) {
		if ( empty( $files ) ) {
			$this->render_empty_state( 'Aun no hay comprobantes adjuntos.', 'Cuando cargues una factura, foto o PDF, aparecera aqui como soporte del movimiento.' );
			return;
		}
		?>
		<table class="widefat striped asdl-fin-table">
			<thead>
				<tr>
					<th>Archivo</th>
					<th>Tipo</th>
					<th>Fecha</th>
					<th>Accion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $files as $file ) : ?>
					<?php $attachment_url = wp_get_attachment_url( (int) $file['attachment_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $file['title'] ?: get_the_title( (int) $file['attachment_id'] ) ?: 'Archivo adjunto' ); ?></td>
						<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $file['file_kind'] ) ) ); ?></td>
						<td><?php echo esc_html( $file['created_at'] ?: '—' ); ?></td>
						<td>
							<?php if ( $attachment_url ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $attachment_url ); ?>" target="_blank" rel="noopener noreferrer">Abrir archivo</a>
							<?php else : ?>
								<span>Sin archivo disponible</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_empty_state( $title, $description ) {
		?>
		<div class="asdl-fin-empty">
			<strong><?php echo esc_html( $title ); ?></strong>
			<p><?php echo esc_html( $description ); ?></p>
		</div>
		<?php
	}

	private function build_document_options( array $documents ) {
		$options = array();

		foreach ( $documents as $document ) {
			$options[ (int) $document['id'] ] = $document['document_number'] . ' - ' . $document['title'];
		}

		return $options;
	}

	private function build_user_options() {
		$options = array();

		foreach ( get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) ) as $user ) {
			$label = $user->display_name;

			if ( ! empty( $user->user_email ) ) {
				$label .= ' | ' . $user->user_email;
			}

			$options[ (int) $user->ID ] = $label;
		}

		return $options;
	}

	private function format_wp_user_picker_item( \WP_User $user, array $role_names ) {
		$role_labels = array();

		foreach ( (array) $user->roles as $role_key ) {
			$role_labels[] = isset( $role_names[ $role_key ]['name'] ) ? (string) $role_names[ $role_key ]['name'] : ucfirst( str_replace( '_', ' ', (string) $role_key ) );
		}

		return array(
			'id'           => (int) $user->ID,
			'display_name' => (string) ( $user->display_name ?: $user->user_login ),
			'user_email'   => (string) $user->user_email,
			'user_login'   => (string) $user->user_login,
			'roles_label'  => implode( ', ', array_filter( $role_labels ) ),
		);
	}

	private function wp_user_picker_meta( \WP_User $user ) {
		$item = $this->format_wp_user_picker_item( $user, wp_roles()->roles );
		$meta = array();

		if ( '' !== $item['user_email'] ) {
			$meta[] = $item['user_email'];
		}

		if ( '' !== $item['user_login'] ) {
			$meta[] = '@' . $item['user_login'];
		}

		if ( '' !== $item['roles_label'] ) {
			$meta[] = $item['roles_label'];
		}

		return implode( ' | ', $meta );
	}

	private function render_document_detail( array $document, array $source_links, array $files, array $account_options, array $contact_options ) {
		$primary_link     = ! empty( $source_links[0] ) ? $source_links[0] : null;
		$meta             = $this->decode_meta_json( $document['meta_json'] ?? '' );
		$class_trace      = ! empty( $meta['classification'] ) && is_array( $meta['classification'] ) ? $meta['classification'] : array();
		$payment_method_key   = sanitize_key( (string) ( $meta['payment_method_key'] ?? '' ) );
		$payment_method_label = '' !== $payment_method_key ? $this->payment_method_label( $payment_method_key ) : '';
		$is_expense_document  = $this->is_expense_document( $document );
		$is_external_expense  = 'external_expense' === sanitize_key( (string) ( $document['document_type'] ?? '' ) );
		$expense_has_payment  = $is_expense_document && ( (float) ( $document['paid_total'] ?? 0 ) > 0 || in_array( sanitize_key( (string) ( $document['payment_status'] ?? '' ) ), array( 'partial', 'paid' ), true ) );
		$document_id      = (int) ( $document['id'] ?? 0 );
		$detail_page      = $this->document_page_slug_for_document( $document );
		$close_detail_args = array( 'page' => $detail_page );
		if ( 'asdl-fin-expenses' === $detail_page ) {
			foreach ( array( 'expense_search', 'expense_financial_status', 'expense_payment_status', 'expense_range_from', 'expense_range_to', 'expense_open_only', 'expense_has_contact', 'expense_contact_id' ) as $expense_key ) {
				if ( ! isset( $_GET[ $expense_key ] ) ) {
					continue;
				}

				$expense_value = wp_unslash( $_GET[ $expense_key ] );
				if ( '' === $expense_value || null === $expense_value ) {
					continue;
				}

				$close_detail_args[ $expense_key ] = sanitize_text_field( (string) $expense_value );
			}
		}
		$close_detail_url = $this->with_current_context_url( add_query_arg( $close_detail_args, admin_url( 'admin.php' ) ) );
		$allocations      = $document_id > 0 ? ( new PaymentAllocationsRepository() )->for_document( $document_id, 50 ) : array();
		$events           = $document_id > 0 ? ( new EventsRepository() )->for_entity( 'document', $document_id, 30 ) : array();
		?>
		<section class="asdl-fin-panel asdl-fin-document-detail">
			<div class="asdl-fin-contact-header">
				<div>
					<h2><?php echo esc_html( $document['title'] ); ?></h2>
					<p>
						<?php echo esc_html( $document['document_number'] ?: 'Sin numero' ); ?>
						<?php echo esc_html( ' | ' . ( $is_expense_document ? $this->expense_origin_label_for_document( $document ) : $this->label_for( 'document_type', $document['document_type'] ) ) ); ?>
						<?php echo esc_html( ' | ' . $this->label_for( 'source_type', $document['source_type'] ) ); ?>
					</p>
				</div>
				<div class="asdl-fin-badge-group">
					<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'financial_status', $document['financial_status'] ), $this->tone_for_status( $document['financial_status'] ) ) ); ?>
					<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'payment_status', $this->resolve_document_payment_status_for_ui( $document ) ), $this->tone_for_status( $this->resolve_document_payment_status_for_ui( $document ) ) ) ); ?>
					<?php echo wp_kses_post( $this->render_pill( ! empty( $document['manual_override'] ) ? 'Gestion manual activa' : 'Clasificacion automatica', ! empty( $document['manual_override'] ) ? 'warning' : 'neutral' ) ); ?>
				</div>
			</div>

			<div class="asdl-fin-card-grid">
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Total</span>
					<strong><?php echo wp_kses_post( $this->format_money( $document['total'] ) ); ?></strong>
					<p>Monto registrado para este movimiento.</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Pagado / abonado</span>
					<strong><?php echo wp_kses_post( $this->format_money( $document['paid_total'] ) ); ?></strong>
					<p>Acumulado aplicado desde cobros o pagos.</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Saldo</span>
					<strong><?php echo wp_kses_post( $this->format_money( $document['balance'] ) ); ?></strong>
					<p>Saldo pendiente del movimiento.</p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Origen vinculado</span>
					<strong><?php echo esc_html( $primary_link ? $this->label_for( 'provider', $primary_link['provider'] ) : $this->label_for( 'source_type', $document['source_type'] ) ); ?></strong>
					<p><?php echo esc_html( $primary_link ? ( $primary_link['external_ref'] ?: $primary_link['external_id'] ) : 'Movimiento manual sin vinculo externo.' ); ?></p>
				</div>
				<div class="asdl-fin-card">
					<span class="asdl-fin-label">Clasificacion aplicada</span>
					<strong><?php echo esc_html( $this->classification_trace_label( $class_trace ) ); ?></strong>
					<p><?php echo esc_html( $this->classification_trace_detail( $class_trace ) ); ?></p>
				</div>
			</div>

			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Resumen del movimiento</h2>
						<p>Lectura rapida para no perder la relacion entre operacion, finanzas y sincronizacion.</p>
					</div>
					<div class="asdl-fin-data-grid">
						<div><strong>Intencion financiera</strong><span><?php echo esc_html( $this->label_for( 'financial_intent', $document['financial_intent'] ) ); ?></span></div>
						<div><strong>Naturaleza del saldo</strong><span><?php echo esc_html( $this->label_for( 'balance_nature', $document['balance_nature'] ) ); ?></span></div>
						<div><strong>Categoria</strong><span><?php echo esc_html( $document['category_key'] ?: 'Sin categoria' ); ?></span></div>
						<div><strong>Subcategoria</strong><span><?php echo esc_html( $document['subcategory_key'] ?: 'Sin subcategoria' ); ?></span></div>
						<div><strong>Estado operativo</strong><span><?php echo esc_html( $document['operational_status'] ?: 'Sin estado operativo' ); ?></span></div>
						<div><strong>Referencia externa</strong><span><?php echo esc_html( $document['external_reference'] ?: '—' ); ?></span></div>
						<?php if ( $is_external_expense ) : ?>
							<div><strong>Metodo de pago</strong><span><?php echo esc_html( $expense_has_payment ? ( $payment_method_label ?: 'Sin registrar' ) : 'Sin abono registrado' ); ?></span></div>
						<?php endif; ?>
						<div><strong>Fecha</strong><span><?php echo esc_html( $document['issue_date'] ?: '—' ); ?></span></div>
						<div><strong>Vencimiento</strong><span><?php echo esc_html( $document['due_date'] ?: '—' ); ?></span></div>
					</div>
					<?php if ( ! empty( $document['notes'] ) ) : ?>
						<div class="asdl-fin-note-box"><?php echo esc_html( $document['notes'] ); ?></div>
					<?php endif; ?>
				</section>

				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Gestion manual</h2>
						<p>Usa esta vista para reclasificar un movimiento sincronizado sin volver a mezclar la contabilidad con el estado del pedido Woo.</p>
					</div>
					<?php if ( $this->is_fiscal_readonly_context() ) : ?>
						<?php $this->render_fiscal_readonly_action_state( 'La edicion manual del movimiento queda bloqueada en modo consulta.' ); ?>
					<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid" enctype="multipart/form-data">
						<input type="hidden" name="action" value="asdl_fin_update_document" />
						<input type="hidden" name="document_id" value="<?php echo esc_attr( (int) $document['id'] ); ?>" />
						<?php $this->render_current_context_hidden_inputs( $this->current_page_slug() ); ?>
						<?php wp_nonce_field( 'asdl_fin_update_document' ); ?>
						<label class="asdl-fin-field asdl-fin-field-wide">
							<span>Titulo o concepto</span>
							<input type="text" name="title" value="<?php echo esc_attr( $document['title'] ); ?>" />
							<small>Este titulo puede ser mas claro para el equipo que el nombre original del pedido o la referencia externa.</small>
						</label>
						<label class="asdl-fin-field">
							<span>Perfil</span>
							<select name="contact_id">
								<option value="">Sin asignar</option>
								<?php echo $this->render_select_options( $contact_options, (string) $document['contact_id'] ); ?>
							</select>
						</label>
						<label class="asdl-fin-field">
							<span>Cuenta</span>
							<select name="account_id">
								<option value="">Sin asignar</option>
								<?php echo $this->render_select_options( $account_options, (string) $document['account_id'] ); ?>
							</select>
						</label>
						<label class="asdl-fin-field">
							<span>Estado del movimiento</span>
							<select name="financial_status">
								<?php echo $this->render_select_options( array( 'draft' => 'Borrador', 'posted' => 'Emitido', 'void' => 'Anulado' ), (string) $document['financial_status'] ); ?>
							</select>
						</label>
						<label class="asdl-fin-field">
							<span>Referencia externa</span>
							<input type="text" name="external_reference" value="<?php echo esc_attr( $document['external_reference'] ); ?>" />
						</label>
						<label class="asdl-fin-field">
							<span>Fecha</span>
							<input type="date" name="issue_date" value="<?php echo esc_attr( (string) ( $document['issue_date'] ?? '' ) ); ?>" />
						</label>
						<label class="asdl-fin-field">
							<span>Vencimiento</span>
							<input type="date" name="due_date" value="<?php echo esc_attr( (string) ( $document['due_date'] ?? '' ) ); ?>" />
						</label>
						<?php if ( $is_external_expense && $expense_has_payment ) : ?>
							<?php $this->render_payment_method_select( 'payment_method_key', 'Metodo de pago', $payment_method_key, false, 'Selecciona un metodo' ); ?>
							<label class="asdl-fin-field asdl-fin-field-wide">
								<span>Aclaratoria</span>
								<div class="asdl-fin-note-box">En gastos externos este dato solo documenta como se pago el gasto o abono ya registrado. No usa precio dual ni descuento por USD.</div>
							</label>
						<?php endif; ?>
						<label class="asdl-fin-field asdl-fin-field-wide asdl-fin-checkbox-field">
							<span class="asdl-fin-checkbox-row">
								<input type="checkbox" name="manual_override" value="1" <?php checked( ! empty( $document['manual_override'] ) ); ?> />
								<strong>Gestion manual prioritaria</strong>
							</span>
							<small>Cuando esta opcion esta activa, las proximas sincronizaciones respetan la clasificacion y la gestion manual de este movimiento.</small>
						</label>
						<label class="asdl-fin-field">
							<span>Intencion financiera</span>
							<select name="financial_intent">
								<?php echo $this->render_select_options( array( 'income' => 'Ingreso', 'expense' => 'Egreso', 'salary' => 'Sueldo', 'service' => 'Servicio', 'adjustment' => 'Ajuste', 'internal_consumption' => 'Consumo interno', 'loan' => 'Prestamo', 'neutral' => 'Neutral' ), (string) $document['financial_intent'] ); ?>
							</select>
						</label>
						<label class="asdl-fin-field">
							<span>Naturaleza del saldo</span>
							<select name="balance_nature">
								<?php echo $this->render_select_options( array( 'receivable' => 'Por cobrar', 'payable' => 'Por pagar', 'neutral' => 'Neutro' ), (string) $document['balance_nature'] ); ?>
							</select>
						</label>
						<label class="asdl-fin-field">
							<span>Categoria</span>
							<input type="text" name="category_key" value="<?php echo esc_attr( $document['category_key'] ); ?>" />
						</label>
						<label class="asdl-fin-field">
							<span>Subcategoria</span>
							<input type="text" name="subcategory_key" value="<?php echo esc_attr( $document['subcategory_key'] ); ?>" />
						</label>
						<label class="asdl-fin-field asdl-fin-field-wide">
							<span>Notas</span>
							<textarea name="notes" rows="4"><?php echo esc_textarea( $document['notes'] ); ?></textarea>
							<small>Estas notas se conservan cuando activas la gestion manual prioritaria.</small>
						</label>
						<?php $this->render_file_input( 'document_file', 'Nuevo comprobante o soporte', 'Si adjuntas un archivo nuevo, quedara agregado al historial documental de este movimiento.' ); ?>
						<div class="asdl-fin-field asdl-fin-field-wide">
							<span>Accion</span>
							<div class="asdl-fin-inline-actions">
								<?php submit_button( 'Actualizar movimiento', 'primary', 'submit', false ); ?>
								<a class="button button-secondary" href="<?php echo esc_url( $close_detail_url ); ?>">Cerrar detalle</a>
							</div>
						</div>
					</form>
					<?php if ( 'void' !== sanitize_key( (string) ( $document['financial_status'] ?? '' ) ) ) : ?>
						<div class="asdl-fin-note-box">
							<strong>Anulacion operativa</strong>
							<p>Usa esta accion solo si necesitas revertir el movimiento completo y dejar trazabilidad del motivo.</p>
							<?php $this->render_cancel_action_form( 'asdl_fin_cancel_document', 'document_id', (int) $document['id'], 'asdl_fin_cancel_document', 'Motivo para anular este movimiento' ); ?>
						</div>
					<?php endif; ?>
					<?php endif; ?>
				</section>
			</div>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Pagos aplicados</h2>
					<p>Asignaciones registradas sobre este documento para entender cuanto se ha cubierto y desde donde.</p>
				</div>
				<?php $this->render_allocations_table( $allocations, array( 'compact' => true ) ); ?>
			</section>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Comprobantes y soportes</h2>
					<p>Archivos adjuntos relacionados con este movimiento financiero.</p>
				</div>
				<?php $this->render_document_files( $files ); ?>
			</section>

			<?php if ( ! empty( $source_links ) ) : ?>
				<section class="asdl-fin-panel">
					<div class="asdl-fin-panel-header">
						<h2>Vinculos de sincronizacion</h2>
						<p>Relacion actual entre este movimiento y sus fuentes externas.</p>
					</div>
					<?php $this->render_source_links_table( $source_links ); ?>
				</section>
			<?php endif; ?>

			<section class="asdl-fin-panel">
				<div class="asdl-fin-panel-header">
					<h2>Historial del documento</h2>
					<p>Trazabilidad de creacion, actualizaciones y anulaciones registradas en el core financiero.</p>
				</div>
				<?php $this->render_events_table( $events ); ?>
			</section>
		</section>
		<?php
	}

	private function render_contact_detail( array $snapshot ) {
		$contact       = $snapshot['contact'];
		$summary       = $snapshot['summary'];
		$filters       = $snapshot['filters'] ?? array();
		$order_summary = $snapshot['order_summary'] ?? array();
		$pending_summary = $snapshot['pending_order_summary'] ?? array();
		$employee_profile = is_array( $snapshot['employee_profile'] ?? null ) ? $snapshot['employee_profile'] : array();
		$salary_advances = $snapshot['salary_advances'] ?? array();
		$salary_advance_summary = $snapshot['salary_advance_summary'] ?? array();
		$payroll_periods = $snapshot['payroll_periods'] ?? array();
		$payroll_summary = $snapshot['payroll_summary'] ?? array();
		$payroll_defaults = $this->payroll_defaults_for_employee( $employee_profile );
		$user          = ! empty( $contact['wp_user_id'] ) ? get_userdata( (int) $contact['wp_user_id'] ) : null;
		$is_employee   = ! empty( $contact['is_employee'] );
		$is_supplier   = ! empty( $contact['is_supplier'] );
		$is_customer   = ! empty( $contact['is_customer'] );
		$supplier_kind = $is_supplier ? sanitize_key( (string) ( $contact['supplier_kind'] ?? 'general' ) ) : '';
		$employee_salary_amount   = max( 0, (float) ( $employee_profile['salary_amount'] ?? 0 ) );
		$employee_salary_currency = sanitize_text_field( (string) ( $employee_profile['salary_currency'] ?? 'USD' ) );
		$employee_next_payment    = sanitize_text_field( (string) ( $employee_profile['next_payment_date'] ?? '' ) );
		$payroll_projection_date  = '' !== $employee_next_payment
			? $employee_next_payment
			: sanitize_text_field( (string) ( $payroll_defaults['scheduled_payment_date'] ?? gmdate( 'Y-m-d' ) ) );
		$payroll_commitment_preview = $is_employee
			? ( new CommitmentSettlementService() )->preview_payroll_deductions(
				(int) $contact['id'],
				$payroll_projection_date,
				$employee_salary_amount
			)
			: array();
		$commitment_preview_total   = max( 0, (float) ( $payroll_commitment_preview['planned_total'] ?? 0 ) );
		$active_advance_balance_total = max( 0, (float) ( $salary_advance_summary['balance_total'] ?? 0 ) );
		$account_options = ( new AccountsRepository() )->options();
		$active_commitment_options = array();
		foreach ( (array) ( $snapshot['plans'] ?? array() ) as $plan_item ) {
			if ( empty( $plan_item['id'] ) || (float) ( $plan_item['balance'] ?? 0 ) <= 0 || 'closed' === ( $plan_item['status'] ?? '' ) ) {
				continue;
			}

			$active_commitment_options[ (int) $plan_item['id'] ] = sprintf(
				'%s - %s (%s)',
				sanitize_text_field( $plan_item['title'] ?? 'Compromiso' ),
				$this->label_for( 'settlement_direction', $plan_item['settlement_direction'] ?? 'receivable' ),
				number_format_i18n( (float) ( $plan_item['balance'] ?? 0 ), 2 )
			);
		}
		$consolidated_receivable = (float) ( $summary['consolidated_receivable_total'] ?? 0 );
		$credit_total            = (float) ( $summary['credit_total'] ?? 0 );
		$available_credit_total  = (float) ( $summary['usable_credit_total'] ?? 0 );
		$usable_credit_total     = min( $available_credit_total, (float) ( $pending_summary['pending_order_total'] ?? 0 ) );
		$order_debt_gross_total  = (float) ( $summary['pending_order_gross_total'] ?? 0 );
		$order_debt_paid_total   = (float) ( $summary['pending_order_paid_total'] ?? 0 );
		$net_position_total      = (float) ( $summary['net_position_total'] ?? 0 );
		$period_open_total       = (float) ( $summary['open_order_total'] ?? 0 );
		$period_open_count       = (int) ( $summary['open_order_count'] ?? 0 );
		$total_pending_count     = (int) ( $summary['pending_order_count_total'] ?? 0 );
		$historical_pending_total = max( 0, round( (float) ( $summary['pending_order_total'] ?? 0 ) - $period_open_total, 6 ) );
		$historical_pending_count = max( 0, $total_pending_count - $period_open_count );
		$dual_pricing_service     = new DualPricingService();
		$dual_discount_config     = $dual_pricing_service->get_discount_config();
		$dual_discount_fraction   = (float) ( $dual_discount_config['fraction'] ?? 0 );
		$dual_discount_percent    = (float) ( $dual_discount_config['percent'] ?? 0 );
		$dual_discount_active     = ! empty( $dual_discount_config['active'] ) && $dual_discount_fraction > 0;
		$dual_pending_total       = $dual_discount_active
			? (float) ( $dual_pricing_service->compute_dual( (float) ( $pending_summary['pending_order_total'] ?? 0 ), PHP_FLOAT_MAX, $dual_discount_fraction )['net_effective'] ?? 0 )
			: 0.0;
		$dual_period_open_total   = $dual_discount_active
			? (float) ( $dual_pricing_service->compute_dual( $period_open_total, PHP_FLOAT_MAX, $dual_discount_fraction )['net_effective'] ?? 0 )
			: 0.0;
		$dual_historical_total    = $dual_discount_active
			? (float) ( $dual_pricing_service->compute_dual( $historical_pending_total, PHP_FLOAT_MAX, $dual_discount_fraction )['net_effective'] ?? 0 )
			: 0.0;
		$dual_discount_label      = $dual_discount_active
			? sprintf( 'Si paga en USD/divisa con %s%%: ', number_format_i18n( $dual_discount_percent, 2 ) )
			: '';
		$net_position_label      = abs( $net_position_total ) < 0.00001
			? 'En equilibrio'
			: ( $net_position_total > 0 ? 'A favor de la empresa' : 'A favor del perfil' );
		$filters_open            = isset( $_GET['range_from'] ) || isset( $_GET['range_to'] ) || isset( $_GET['order_limit'] );
		$readonly_context        = $this->is_fiscal_readonly_context();
		$service_summary         = is_array( $snapshot['service_summary'] ?? null ) ? $snapshot['service_summary'] : array();
		$service_profiles        = $snapshot['service_profiles'] ?? array();
		$due_service_profiles    = $snapshot['due_service_profiles'] ?? array();
		$upcoming_service_profiles = $snapshot['upcoming_service_profiles'] ?? array();
		$service_documents       = $snapshot['service_documents'] ?? array();
		$open_service_documents  = $snapshot['open_service_documents'] ?? array();
		$open_payable_documents  = $snapshot['open_payable_documents'] ?? array();
		$non_service_open_payable_documents = array_values(
			array_filter(
				$open_payable_documents,
				static function ( array $document ) {
					return 'service_expense' !== sanitize_key( (string) ( $document['document_type'] ?? '' ) );
				}
			)
		);
		$profile_documents       = array_values(
			array_filter(
				(array) ( $snapshot['documents'] ?? array() ),
				static function ( array $document ) {
					return 'service_expense' !== sanitize_key( (string) ( $document['document_type'] ?? '' ) );
				}
			)
		);
		$supplier_supports_services = in_array( $supplier_kind, array( 'services', 'mixed' ), true );
		$supplier_supports_products = in_array( $supplier_kind, array( 'products', 'mixed' ), true );
		$has_service_history     = ! empty( $service_profiles ) || ! empty( $service_documents );
		$has_service_section     = ( $is_supplier && $supplier_supports_services ) || $has_service_history;
		$is_supplier_only        = $is_supplier && ! $is_customer && ! $is_employee;
		$show_customer_sections  = ! $is_supplier_only;
		$service_open_balance    = (float) ( $service_summary['open_balance_total'] ?? 0 );
		$next_service_profile    = $this->next_service_profile( $service_profiles );
		$next_service_issue_date = ! empty( $next_service_profile['next_issue_date'] ) ? sanitize_text_field( (string) $next_service_profile['next_issue_date'] ) : '';
		$next_service_title      = ! empty( $next_service_profile['title'] ) ? sanitize_text_field( (string) $next_service_profile['title'] ) : '';
		$next_service_amount     = ! empty( $next_service_profile['amount'] ) ? (float) $next_service_profile['amount'] : 0.0;
		$next_service_currency   = ! empty( $next_service_profile['currency'] ) ? sanitize_text_field( (string) $next_service_profile['currency'] ) : 'USD';
		$next_service_due_label  = '' !== $next_service_issue_date ? $this->format_date_with_weekday( $next_service_issue_date ) : 'Sin programar';
		$payable_total           = (float) ( $summary['payable_total'] ?? 0 );
		$non_service_payable_total = max( 0, round( $payable_total - $service_open_balance, 6 ) );
		$registered_credit_total = (float) ( $summary['unapplied_payment_total'] ?? 0 );
		$display_open_payable_documents = $has_service_section ? $non_service_open_payable_documents : $open_payable_documents;
		$provider_kind_label     = $is_supplier ? $this->label_for( 'supplier_kind', $supplier_kind ?: 'general' ) : '';
		$header_role_label       = $contact['profile_roles_label'] ?? $this->label_for( 'contact_type', $contact['contact_type'] );
		if ( $is_supplier_only && '' !== $provider_kind_label ) {
			$header_role_label = 'Proveedor | ' . $provider_kind_label;
		}
		$provider_note           = '';
		if ( $is_supplier ) {
			if ( 'services' === $supplier_kind ) {
				$provider_note = 'Proveedor orientado a servicios. Aqui se gestionan servicios recurrentes, cargos puntuales y cuentas por pagar ligadas a esos servicios.';
			} elseif ( 'products' === $supplier_kind ) {
				$provider_note = 'Proveedor orientado a productos. Aqui se concentran cuentas por pagar y queda preparada la futura base del modulo de compras.';
			} elseif ( 'mixed' === $supplier_kind ) {
				$provider_note = 'Proveedor mixto. Puede operar con servicios y luego tambien con compras o abastecimiento de productos.';
			} else {
				$provider_note = 'Proveedor sin clasificar. Define si opera por servicios, productos o mixto para que el perfil muestre solo las secciones correctas.';
			}
		}
		?>
		<section class="asdl-fin-panel asdl-fin-contact-detail">
			<div class="asdl-fin-contact-header">
				<div>
					<h2><?php echo esc_html( $contact['display_name'] ); ?></h2>
					<p><?php echo esc_html( $header_role_label ); ?><?php echo ! empty( $contact['legal_name'] ) ? esc_html( ' | ' . $contact['legal_name'] ) : ''; ?></p>
				</div>
				<div class="asdl-fin-badge-group">
					<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'profile_origin', $contact['profile_origin'] ?? '' ), 'wp_user' === ( $contact['profile_origin'] ?? '' ) ? 'success' : 'neutral' ) ); ?>
					<?php if ( $is_supplier ) : ?>
						<?php echo wp_kses_post( $this->render_pill( $provider_kind_label, 'warning' ) ); ?>
					<?php endif; ?>
					<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'status', $contact['status'] ), $this->tone_for_status( $contact['status'] ) ) ); ?>
					<?php if ( empty( $contact['wp_user_id'] ) && ! empty( $contact['email'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="asdl_fin_promote_contact_to_user" />
							<input type="hidden" name="return_page" value="asdl-fin-contacts" />
							<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
							<?php $this->render_current_fiscal_hidden_input(); ?>
							<?php wp_nonce_field( 'asdl_fin_promote_contact_to_user' ); ?>
							<?php submit_button( 'Vincular o crear usuario interno', 'secondary small', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<div class="asdl-fin-anchor-links">
				<a href="#asdl-fin-contact-general">General</a>
				<a href="#asdl-fin-contact-statement">Estado de cuenta</a>
				<?php if ( $is_supplier_only ) : ?>
					<a href="#asdl-fin-contact-open">Cuentas por pagar</a>
					<?php if ( $has_service_section ) : ?>
						<a href="#asdl-fin-contact-services">Servicios</a>
					<?php endif; ?>
					<a href="#asdl-fin-contact-documents">Movimientos</a>
				<?php else : ?>
					<a href="#asdl-fin-contact-collections">Cobranza</a>
					<a href="#asdl-fin-contact-orders">Pedidos</a>
					<a href="#asdl-fin-contact-consumption">Consumo</a>
					<a href="#asdl-fin-contact-documents">Movimientos</a>
					<a href="#asdl-fin-contact-open">Cuentas por pagar</a>
					<?php if ( $has_service_section ) : ?>
						<a href="#asdl-fin-contact-services">Servicios</a>
					<?php endif; ?>
				<?php endif; ?>
				<a href="#asdl-fin-contact-commitments">Compromisos</a>
				<a href="#asdl-fin-contact-payments">Abonos y pagos</a>
				<a href="#asdl-fin-contact-history">Historico</a>
				<?php if ( $is_employee ) : ?>
					<a href="#asdl-fin-contact-employee">Empleado</a>
				<?php endif; ?>
			</div>

			<?php if ( $show_customer_sections ) : ?>
				<details class="asdl-fin-disclosure" <?php echo $filters_open ? 'open' : ''; ?>>
					<summary>Filtrar pedidos y consumo</summary>
					<div class="asdl-fin-disclosure-body">
						<?php $this->render_profile_order_filters( (int) $contact['id'], $filters ); ?>
					</div>
				</details>
			<?php endif; ?>

			<div class="asdl-fin-card-grid">
				<?php if ( $is_supplier_only ) : ?>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Por pagar total</span>
						<strong><?php echo wp_kses_post( $this->format_money( $credit_total ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Documentado por pagar: <?php echo wp_kses_post( $this->format_money( $payable_total ) ); ?></span>
							<span class="asdl-fin-card-breakdown">Saldo ya registrado a favor: <?php echo wp_kses_post( $this->format_money( $registered_credit_total ) ); ?></span>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Compromisos por pagar</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['payable_commitment_total'] ?? 0 ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Acuerdos activos a favor del proveedor o tercero.</span>
						</p>
					</div>
					<?php if ( $has_service_section ) : ?>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Servicios por pagar</span>
							<strong><?php echo wp_kses_post( $this->format_money( $service_open_balance ) ); ?></strong>
							<p>
								<span class="asdl-fin-card-breakdown">Servicios emitidos pendientes con este proveedor o tercero.</span>
							</p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Proximo cobro</span>
							<strong><?php echo esc_html( $next_service_due_label ); ?></strong>
							<p>
								<span class="asdl-fin-card-breakdown"><?php echo esc_html( '' !== $next_service_title ? $next_service_title : 'Sin servicio recurrente programado' ); ?></span>
								<?php if ( '' !== $next_service_issue_date ) : ?>
									<span class="asdl-fin-card-breakdown">Monto previsto: <?php echo wp_kses_post( $this->format_money( $next_service_amount, $next_service_currency ) ); ?></span>
								<?php endif; ?>
							</p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Por emitir hoy</span>
							<strong><?php echo esc_html( number_format_i18n( $service_summary['due_profile_count'] ?? 0 ) ); ?></strong>
							<p>
								<span class="asdl-fin-card-breakdown">Servicios recurrentes listos para convertirse en cuenta por pagar.</span>
							</p>
						</div>
						<div class="asdl-fin-card">
							<span class="asdl-fin-label">Servicios activos</span>
							<strong><?php echo esc_html( number_format_i18n( $service_summary['active_profile_count'] ?? 0 ) ); ?></strong>
							<p>
								<span class="asdl-fin-card-breakdown">Base recurrente configurada para este proveedor o tercero.</span>
							</p>
						</div>
					<?php endif; ?>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pagos registrados</span>
						<strong><?php echo esc_html( number_format_i18n( $summary['payment_count'] ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Pagos o abonos registrados para este proveedor o tercero.</span>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Total pagado</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['payments_total'] ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Total historico pagado o abonado a este proveedor o tercero.</span>
						</p>
					</div>
				<?php else : ?>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Por cobrar</span>
						<strong><?php echo wp_kses_post( $this->format_money( $consolidated_receivable ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Pedidos abiertos: <?php echo wp_kses_post( $this->format_money( $summary['pending_order_total'] ?? 0 ) ); ?></span>
							<span class="asdl-fin-card-breakdown">Adelantos por recuperar: <?php echo wp_kses_post( $this->format_money( $summary['salary_advance_balance'] ?? 0 ) ); ?></span>
							<span class="asdl-fin-card-breakdown">En periodo consultado: <?php echo wp_kses_post( $this->format_money( $period_open_total ) ); ?></span>
							<span class="asdl-fin-card-breakdown">Historico por cerrar: <?php echo wp_kses_post( $this->format_money( $historical_pending_total ) ); ?></span>
							<?php if ( $store_debt_commitment_planned_total > 0.00001 ) : ?>
								<span class="asdl-fin-card-breakdown">Deuda de tienda ya planificada: <?php echo wp_kses_post( $this->format_money( $store_debt_commitment_planned_total ) ); ?></span>
							<?php endif; ?>
							<span class="asdl-fin-card-breakdown">Compromisos adicionales por cobrar: <?php echo wp_kses_post( $this->format_money( $additional_receivable_commitment_total ) ); ?></span>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Saldo a favor</span>
						<strong><?php echo wp_kses_post( $this->format_money( $credit_total ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Disponible hoy: <?php echo wp_kses_post( $this->format_money( $available_credit_total ) ); ?></span>
							<span class="asdl-fin-card-breakdown">Incluye deuda documentada de la empresa y acuerdos a favor del perfil.</span>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pedidos pendientes</span>
						<strong><?php echo esc_html( number_format_i18n( $total_pending_count ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">En periodo consultado: <?php echo esc_html( number_format_i18n( $period_open_count ) ); ?></span>
							<span class="asdl-fin-card-breakdown">Historico por cerrar: <?php echo esc_html( number_format_i18n( $historical_pending_count ) ); ?></span>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pagos registrados</span>
						<strong><?php echo esc_html( number_format_i18n( $summary['payment_count'] ) ); ?></strong>
						<p>Cobros, pagos o abonos cargados a este perfil dentro del core.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Total abonado</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['payments_total'] ) ); ?></strong>
						<p>Total historico registrado en cobros, pagos o abonos aplicados a este perfil.</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pendiente periodo actual</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['open_order_total'] ?? 0 ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Abierto dentro del periodo actual filtrado.</span>
							<span class="asdl-fin-card-breakdown">Pendiente total abierto: <?php echo wp_kses_post( $this->format_money( max( 0, (float) ( $summary['pending_order_total'] ?? 0 ) + (float) $historical_pending_total ) ) ); ?></span>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Consumo periodo actual</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['orders_total'] ?? 0 ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Total comprado o consumido dentro del periodo actual.</span>
							<span class="asdl-fin-card-breakdown">Consumo total acumulado: <?php echo wp_kses_post( $this->format_money( (float) ( $snapshot['consumption_all_time_summary']['total'] ?? 0 ) ) ); ?></span>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pedidos periodo actual</span>
						<strong><?php echo esc_html( number_format_i18n( $summary['order_count'] ?? 0 ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Pedidos Woo/OpenPOS cargados segun el periodo actual y el limite visible.</span>
							<span class="asdl-fin-card-breakdown">Pedidos acumulados: <?php echo esc_html( number_format_i18n( (int) ( $snapshot['consumption_all_time_summary']['order_count'] ?? 0 ) ) ); ?></span>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Promedio por pedido</span>
						<strong><?php echo wp_kses_post( $this->format_money( $summary['average_ticket'] ?? 0 ) ); ?></strong>
						<p>Consumo promedio por pedido dentro del periodo consultado.</p>
					</div>
				<?php endif; ?>
			</div>

			<div class="asdl-fin-panel-grid">
				<section class="asdl-fin-panel" id="asdl-fin-contact-general">
					<div class="asdl-fin-panel-header">
						<h2>General</h2>
						<p>Datos basicos del perfil dentro del core financiero.</p>
					</div>
					<?php if ( ! empty( $contact['is_supplier'] ) && '' !== $provider_note ) : ?>
						<div class="asdl-fin-note-box"><?php echo esc_html( $provider_note ); ?></div>
					<?php endif; ?>
					<div class="asdl-fin-data-grid">
						<div><strong>Origen</strong><span><?php echo esc_html( $this->label_for( 'profile_origin', $contact['profile_origin'] ?? '' ) ); ?></span></div>
						<div><strong>Roles</strong><span><?php echo esc_html( $contact['profile_roles_label'] ?? $this->label_for( 'contact_type', $contact['contact_type'] ) ); ?></span></div>
						<div><strong>Perfil interno</strong><span><?php echo esc_html( ! empty( $contact['internal_use_profile'] ) ? 'Si' : 'No' ); ?></span></div>
						<?php if ( $is_supplier ) : ?>
							<div><strong>Tipo proveedor</strong><span><?php echo esc_html( $provider_kind_label ); ?></span></div>
						<?php endif; ?>
						<div><strong>Usuario WP</strong><span><?php echo esc_html( $user ? $user->user_login . ' (#' . (int) $user->ID . ')' : 'No vinculado' ); ?></span></div>
						<div><strong>Correo</strong><span><?php echo esc_html( $contact['email'] ?: '—' ); ?></span></div>
						<div><strong>Telefono</strong><span><?php echo esc_html( $contact['phone'] ?: '—' ); ?></span></div>
						<div><strong>Documento</strong><span><?php echo esc_html( $contact['document_id'] ?: '—' ); ?></span></div>
						<div><strong>Condiciones</strong><span><?php echo esc_html( $contact['payment_terms'] ?: '—' ); ?></span></div>
						<?php if ( $has_service_section ) : ?>
							<div><strong>Servicios activos</strong><span><?php echo esc_html( number_format_i18n( $service_summary['active_profile_count'] ?? 0 ) ); ?></span></div>
							<div><strong>Servicios por pagar</strong><span><?php echo wp_kses_post( $this->format_money( $service_summary['open_balance_total'] ?? 0 ) ); ?></span></div>
						<?php endif; ?>
					</div>
					<?php if ( $user && get_edit_user_link( $user->ID ) ) : ?>
						<div class="asdl-fin-inline-actions">
							<a class="button button-secondary" href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">Abrir usuario en WordPress</a>
						</div>
					<?php endif; ?>
					<?php if ( $is_supplier ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
							<input type="hidden" name="action" value="asdl_fin_update_contact_profile" />
							<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
							<input type="hidden" name="return_page" value="asdl-fin-contacts" />
							<?php $this->render_current_fiscal_hidden_input(); ?>
							<?php wp_nonce_field( 'asdl_fin_update_contact_profile' ); ?>
							<input type="hidden" name="display_name" value="<?php echo esc_attr( $contact['display_name'] ?? '' ); ?>" />
							<input type="hidden" name="legal_name" value="<?php echo esc_attr( $contact['legal_name'] ?? '' ); ?>" />
							<input type="hidden" name="email" value="<?php echo esc_attr( $contact['email'] ?? '' ); ?>" />
							<input type="hidden" name="phone" value="<?php echo esc_attr( $contact['phone'] ?? '' ); ?>" />
							<input type="hidden" name="document_id" value="<?php echo esc_attr( $contact['document_id'] ?? '' ); ?>" />
							<input type="hidden" name="profile_origin" value="<?php echo esc_attr( $contact['profile_origin'] ?? 'external' ); ?>" />
							<input type="hidden" name="is_supplier" value="1" />
							<?php if ( $is_customer ) : ?>
								<input type="hidden" name="is_customer" value="1" />
							<?php endif; ?>
							<?php if ( $is_employee ) : ?>
								<input type="hidden" name="is_employee" value="1" />
							<?php endif; ?>
							<?php $this->render_select( 'supplier_kind', 'Tipo de proveedor', $this->supplier_kind_options(), true, '', $supplier_kind ?: 'general' ); ?>
							<?php $this->render_input( 'payment_terms', 'Condiciones de pago', 'text', $contact['payment_terms'] ?? '' ); ?>
							<?php $this->render_select( 'status', 'Estado', array( 'active' => 'Activo', 'inactive' => 'Inactivo' ), true, '', $contact['status'] ?? 'active' ); ?>
							<label class="asdl-fin-field asdl-fin-field-wide">
								<span>Perfil interno de consumo / regalos</span>
								<label class="asdl-fin-inline-checkbox">
									<input type="checkbox" name="internal_use_profile" value="1" <?php checked( ! empty( $contact['internal_use_profile'] ) ); ?> />
									<span>Marcar este perfil para consumibles internos, compras asumidas por la tienda o regalos facturados operativamente.</span>
								</label>
								<small>Si no esta marcado, el flujo de asuncion seguira disponible pero pedira confirmacion reforzada.</small>
							</label>
							<label class="asdl-fin-field asdl-fin-field-wide">
								<span>Notas operativas</span>
								<textarea name="notes" rows="4"><?php echo esc_textarea( $contact['notes'] ?? '' ); ?></textarea>
								<small>Usa este campo para aclarar si el proveedor opera por servicios, productos o en esquema mixto.</small>
							</label>
							<?php submit_button( 'Guardar configuracion del proveedor', 'secondary', 'submit', false ); ?>
						</form>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
							<input type="hidden" name="action" value="asdl_fin_update_contact_profile" />
							<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
							<input type="hidden" name="return_page" value="asdl-fin-contacts" />
							<?php $this->render_current_fiscal_hidden_input(); ?>
							<?php wp_nonce_field( 'asdl_fin_update_contact_profile' ); ?>
							<input type="hidden" name="display_name" value="<?php echo esc_attr( $contact['display_name'] ?? '' ); ?>" />
							<input type="hidden" name="legal_name" value="<?php echo esc_attr( $contact['legal_name'] ?? '' ); ?>" />
							<input type="hidden" name="email" value="<?php echo esc_attr( $contact['email'] ?? '' ); ?>" />
							<input type="hidden" name="phone" value="<?php echo esc_attr( $contact['phone'] ?? '' ); ?>" />
							<input type="hidden" name="document_id" value="<?php echo esc_attr( $contact['document_id'] ?? '' ); ?>" />
							<input type="hidden" name="profile_origin" value="<?php echo esc_attr( $contact['profile_origin'] ?? 'external' ); ?>" />
							<?php if ( $is_customer ) : ?>
								<input type="hidden" name="is_customer" value="1" />
							<?php endif; ?>
							<?php if ( $is_employee ) : ?>
								<input type="hidden" name="is_employee" value="1" />
							<?php endif; ?>
							<?php if ( $is_supplier ) : ?>
								<input type="hidden" name="is_supplier" value="1" />
							<?php endif; ?>
							<?php $this->render_input( 'payment_terms', 'Condiciones de pago', 'text', $contact['payment_terms'] ?? '' ); ?>
							<?php $this->render_select( 'status', 'Estado', array( 'active' => 'Activo', 'inactive' => 'Inactivo' ), true, '', $contact['status'] ?? 'active' ); ?>
							<label class="asdl-fin-field asdl-fin-field-wide">
								<span>Perfil interno de consumo / regalos</span>
								<label class="asdl-fin-inline-checkbox">
									<input type="checkbox" name="internal_use_profile" value="1" <?php checked( ! empty( $contact['internal_use_profile'] ) ); ?> />
									<span>Marcar este perfil para consumibles internos, compras asumidas por la tienda o regalos facturados operativamente.</span>
								</label>
								<small>Si no esta marcado, el flujo de asuncion seguira disponible pero pedira confirmacion reforzada.</small>
							</label>
							<label class="asdl-fin-field asdl-fin-field-wide">
								<span>Notas operativas</span>
								<textarea name="notes" rows="4"><?php echo esc_textarea( $contact['notes'] ?? '' ); ?></textarea>
								<small>Usa este campo para dejar claro si este perfil representa consumo interno, regalos o un rol especial de operacion.</small>
							</label>
							<?php submit_button( 'Guardar configuracion del perfil', 'secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
					<?php if ( ! empty( $contact['notes'] ) && ! $is_supplier ) : ?>
						<div class="asdl-fin-note-box"><?php echo esc_html( $contact['notes'] ); ?></div>
					<?php endif; ?>
				</section>

				<section class="asdl-fin-panel" id="asdl-fin-contact-statement">
					<div class="asdl-fin-panel-header">
						<h2>Estado de cuenta</h2>
						<p><?php echo esc_html( $is_supplier_only ? 'Resumen practico de obligaciones abiertas, servicios y pagos de este proveedor o tercero.' : 'Resumen practico de deuda actual, credito a favor y compromisos asociados al perfil.' ); ?></p>
					</div>
					<?php if ( $is_supplier_only ) : ?>
						<?php $this->render_summary_rows(
							array(
								array(
									'label'       => 'Por pagar total',
									'description' => 'Suma total a favor de este proveedor o tercero entre documentos, servicios, pagos registrados y acuerdos pendientes.',
									'value'       => $this->format_money( $credit_total ),
								),
								array(
									'label'       => 'Documentos por pagar',
									'description' => 'Cuentas por pagar ya emitidas, excluyendo los servicios para no duplicar la lectura.',
									'value'       => $this->format_money( $non_service_payable_total ),
								),
								array(
									'label'       => 'Servicios por pagar',
									'description' => 'Servicios emitidos con saldo pendiente ligados a este proveedor o tercero.',
									'value'       => $this->format_money( $service_open_balance ),
								),
								array(
									'label'       => 'Compromisos por pagar',
									'description' => 'Acuerdos activos a favor del proveedor o tercero que siguen pendientes.',
									'value'       => $this->format_money( $summary['payable_commitment_total'] ?? 0 ),
								),
								array(
									'label'       => 'Saldo ya registrado a favor',
									'description' => 'Monto ya pagado o registrado a favor de este proveedor o tercero que sigue pendiente de cruce o regularizacion.',
									'value'       => $this->format_money( $registered_credit_total ),
								),
								array(
									'label'       => 'Pagos registrados',
									'description' => 'Pagos o abonos guardados sobre este proveedor o tercero.',
									'value'       => number_format_i18n( $summary['payment_count'] ),
								),
								array(
									'label'       => 'Total pagado',
									'description' => 'Monto historico pagado o abonado dentro del core.',
									'value'       => $this->format_money( $summary['payments_total'] ),
								),
							)
						); ?>
					<?php else : ?>
						<div class="asdl-fin-data-grid">
							<div><strong>Por cobrar actual</strong><span><?php echo wp_kses_post( $this->format_money( $consolidated_receivable ) ); ?></span></div>
							<div><strong>Saldo a favor total</strong><span><?php echo wp_kses_post( $this->format_money( $credit_total ) ); ?></span></div>
							<div><strong>Disponible hoy</strong><span><?php echo wp_kses_post( $this->format_money( $available_credit_total ) ); ?></span></div>
							<div><strong>Adelantos por recuperar</strong><span><?php echo wp_kses_post( $this->format_money( $summary['salary_advance_balance'] ?? 0 ) ); ?></span></div>
							<div><strong>Balance neto</strong><span><?php echo esc_html( $net_position_label ); ?><?php echo abs( $net_position_total ) > 0.00001 ? wp_kses_post( ' | ' . $this->format_money( abs( $net_position_total ) ) ) : ''; ?></span></div>
							<div><strong>Pedidos pendientes</strong><span><?php echo esc_html( number_format_i18n( $summary['pending_order_count_total'] ?? 0 ) ); ?></span></div>
							<div><strong>Base abierta en pedidos</strong><span><?php echo wp_kses_post( $this->format_money( $order_debt_gross_total ) ); ?></span></div>
							<div><strong>Abonado a pedidos abiertos</strong><span><?php echo wp_kses_post( $this->format_money( $order_debt_paid_total ) ); ?></span></div>
							<div><strong>Total pendiente en pedidos</strong><span><?php echo wp_kses_post( $this->format_money( $summary['pending_order_total'] ?? 0 ) ); ?></span></div>
							<div><strong>Compromisos activos</strong><span><?php echo esc_html( number_format_i18n( $summary['installment_plan_count'] ) ); ?></span></div>
							<div><strong>Total pendiente en compromisos</strong><span><?php echo wp_kses_post( $this->format_money( $summary['installment_balance'] ) ); ?></span></div>
							<div><strong>Total abonado o pagado</strong><span><?php echo wp_kses_post( $this->format_money( $summary['payments_total'] ) ); ?></span></div>
						</div>
					<?php endif; ?>
					<details class="asdl-fin-disclosure asdl-fin-financial-detail-disclosure">
						<summary><?php echo esc_html( $is_supplier_only ? 'Ver desglose tecnico del por pagar' : 'Ver desglose del saldo a favor' ); ?></summary>
						<div class="asdl-fin-disclosure-body">
							<?php
							$statement_breakdown_rows = $is_supplier_only
								? array(
									array(
										'label'       => 'Documentado por pagar',
										'description' => 'Documentos abiertos a favor de este proveedor o tercero, incluyendo los servicios ya emitidos.',
										'value'       => $this->format_money( $summary['payable_total'] ?? 0 ),
									),
									array(
										'label'       => 'Saldo ya registrado a favor',
										'description' => 'Monto ya pagado o registrado a favor de este proveedor o tercero que sigue pendiente de cruce o regularizacion.',
										'value'       => $this->format_money( $summary['unapplied_payment_total'] ?? 0 ),
									),
									array(
										'label'       => 'Compromisos por pagar',
										'description' => 'Acuerdos activos a favor del proveedor o tercero que todavia no se han completado.',
										'value'       => $this->format_money( $summary['payable_commitment_total'] ?? 0 ),
									),
									array(
										'label'       => 'Servicios por pagar',
										'description' => 'Parte del total documentado que corresponde a servicios emitidos y todavia abiertos.',
										'value'       => $this->format_money( $service_open_balance ),
									),
								)
								: array(
									array(
										'label'       => 'Deuda documentada de la empresa',
										'description' => 'Documentos abiertos donde la empresa le debe dinero a este perfil.',
										'value'       => $this->format_money( $summary['payable_total'] ?? 0 ),
									),
									array(
										'label'       => 'Saldo ya registrado a favor',
										'description' => 'Monto ya registrado a favor del perfil y todavia no cruzado contra pedidos o movimientos.',
										'value'       => $this->format_money( $summary['unapplied_payment_total'] ?? 0 ),
									),
									array(
										'label'       => 'Compromisos por pagar',
										'description' => 'Pagos futuros programados a favor del perfil; forman parte del saldo total, pero no siempre estan disponibles hoy.',
										'value'       => $this->format_money( $summary['payable_commitment_total'] ?? 0 ),
									),
									array(
										'label'       => 'Adelantos por recuperar',
										'description' => 'La empresa ya entrego este dinero. Se va descontando desde nomina o recuperacion manual, por eso no cuenta como saldo a favor.',
										'value'       => $this->format_money( $summary['salary_advance_balance'] ?? 0 ),
										'tone'        => 'warning',
									),
								);
							$this->render_summary_rows( $statement_breakdown_rows );
							?>
						</div>
					</details>
				</section>
			</div>

			<?php if ( $show_customer_sections ) : ?>
			<section class="asdl-fin-panel" id="asdl-fin-contact-collections">
				<div class="asdl-fin-panel-header">
					<h2>Pedidos pendientes por cobrar</h2>
					<p>Estos pedidos se leen directo desde Woo/OpenPOS y reflejan la deuda operativa real del perfil mientras sigan abiertos. El historico operativo se acota al ejercicio actual y el fiscal anterior.</p>
				</div>
				<div class="asdl-fin-card-grid asdl-fin-collections-summary-grid">
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pedidos abiertos</span>
						<strong><?php echo esc_html( number_format_i18n( $pending_summary['pending_count'] ?? 0 ) ); ?></strong>
						<p><span class="asdl-fin-card-breakdown">Total operativo pendiente por cobrar.</span></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pendiente total</span>
						<strong><?php echo wp_kses_post( $this->format_money( $pending_summary['pending_order_total'] ?? 0 ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Incluye solo el ejercicio actual y el fiscal anterior que sigan abiertos.</span>
							<?php if ( $dual_discount_active ) : ?>
								<span class="asdl-fin-card-breakdown"><?php echo esc_html( $dual_discount_label ); ?><?php echo wp_kses_post( $this->format_money( $dual_pending_total, 'USD' ) ); ?></span>
							<?php endif; ?>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">En periodo consultado</span>
						<strong><?php echo esc_html( number_format_i18n( $period_open_count ) ); ?></strong>
						<p><span class="asdl-fin-card-breakdown">Pedidos abiertos dentro del filtro actual.</span></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pendiente en periodo</span>
						<strong><?php echo wp_kses_post( $this->format_money( $period_open_total ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Saldo abierto del rango consultado.</span>
							<?php if ( $dual_discount_active ) : ?>
								<span class="asdl-fin-card-breakdown"><?php echo esc_html( $dual_discount_label ); ?><?php echo wp_kses_post( $this->format_money( $dual_period_open_total, 'USD' ) ); ?></span>
							<?php endif; ?>
						</p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Historico reciente</span>
						<strong><?php echo esc_html( number_format_i18n( $historical_pending_count ) ); ?></strong>
						<p><span class="asdl-fin-card-breakdown">Pedidos pendientes del ejercicio fiscal anterior o fuera del filtro actual.</span></p>
					</div>
					<div class="asdl-fin-card">
						<span class="asdl-fin-label">Pendiente historico</span>
						<strong><?php echo wp_kses_post( $this->format_money( $historical_pending_total ) ); ?></strong>
						<p>
							<span class="asdl-fin-card-breakdown">Saldo abierto acumulado del ejercicio fiscal anterior.</span>
							<?php if ( $dual_discount_active ) : ?>
								<span class="asdl-fin-card-breakdown"><?php echo esc_html( $dual_discount_label ); ?><?php echo wp_kses_post( $this->format_money( $dual_historical_total, 'USD' ) ); ?></span>
							<?php endif; ?>
						</p>
					</div>
				</div>
				<div class="asdl-fin-collections-stack">
					<section class="asdl-fin-panel asdl-fin-contact-settlement-panel">
						<div class="asdl-fin-panel-header">
							<h2>Registrar abono</h2>
							<p>Aplica el monto por antiguedad: cierra primero los pedidos mas viejos y deja parcial el siguiente si el abono no alcanza.</p>
						</div>
						<?php if ( $readonly_context ) : ?>
							<?php $this->render_fiscal_readonly_action_state( 'El registro de abonos queda bloqueado en modo consulta.' ); ?>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-contact-settlement-form" data-order-settlement-preview-form="1" data-order-settlement-origin="profile_settlement" data-settlement-open-total="<?php echo esc_attr( number_format( (float) ( $pending_summary['pending_order_total'] ?? 0 ), 2, '.', '' ) ); ?>" data-settlement-dual-total="<?php echo esc_attr( number_format( (float) $dual_pending_total, 2, '.', '' ) ); ?>" data-settlement-dual-percent="<?php echo esc_attr( number_format( (float) $dual_discount_percent, 2, '.', '' ) ); ?>" data-settlement-dual-reference-active="<?php echo esc_attr( $dual_discount_active ? '1' : '0' ); ?>" data-settlement-dual-reference-total="<?php echo esc_attr( number_format( (float) $dual_pending_total, 2, '.', '' ) ); ?>" data-settlement-dual-reference-percent="<?php echo esc_attr( number_format( (float) $dual_discount_percent, 2, '.', '' ) ); ?>">
								<input type="hidden" name="action" value="asdl_fin_settle_profile_orders" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<input type="hidden" name="range_from" value="<?php echo esc_attr( $filters['range_from'] ?? '' ); ?>" />
								<input type="hidden" name="range_to" value="<?php echo esc_attr( $filters['range_to'] ?? '' ); ?>" />
								<input type="hidden" name="order_limit" value="<?php echo esc_attr( $filters['order_limit'] ?? 25 ); ?>" />
								<input type="hidden" name="dual_discount_preview_confirmed" value="0" data-settlement-preview-confirmed />
								<input type="hidden" name="dual_discount_preview_signature" value="" data-settlement-preview-signature />
								<input type="hidden" name="dual_discount_mode" value="" data-settlement-dual-mode />
								<input type="hidden" name="selection_mode" value="oldest_first" data-settlement-selection-mode />
								<input type="hidden" name="include_credit_balance" value="0" data-settlement-include-credit />
								<input type="hidden" name="remainder_policy" value="create_credit" data-settlement-remainder-policy />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_settle_profile_orders' ); ?>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<div class="asdl-fin-settlement-controls">
										<label class="asdl-fin-inline-checkbox">
											<input type="checkbox" name="force_dual_discount" value="1" data-settlement-force-dual />
											<span>Descuento automatico</span>
										</label>
										<small class="asdl-fin-settlement-force-dual-help" data-settlement-force-dual-help>Cuando esta activo, el abono solo evaluara precio dual si la moneda registrada es USD y el metodo califica.</small>
										<?php if ( (float) $usable_credit_total > 0 ) : ?>
											<label class="asdl-fin-inline-checkbox">
												<input type="checkbox" value="1" data-settlement-include-credit-toggle />
												<span>Incluir saldo a favor disponible</span>
											</label>
											<small class="asdl-fin-settlement-credit-help">Disponible hoy: <?php echo wp_kses_post( $this->format_money( $usable_credit_total ) ); ?>. Si lo activas, el preview suma ese credito al efectivo del abono.</small>
										<?php endif; ?>
									</div>
								</div>
								<?php $this->render_select( 'account_id', 'Cuenta de entrada', $account_options, false, 'Opcional' ); ?>
								<?php $this->render_input( 'payment_date', 'Fecha del abono', 'date', gmdate( 'Y-m-d' ), true, array( 'data-settlement-payment-date' => '1' ) ); ?>
								<?php $this->render_input( 'total', 'Monto del abono', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01', 'data-settlement-total' => '1' ) ); ?>
								<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true, 'Selecciona una moneda', array( 'data-settlement-currency' => '1' ) ); ?>
								<?php $this->render_payment_method_select( 'method_key', 'Metodo', '', true ); ?>
								<div class="asdl-fin-note-box asdl-fin-field-wide">
									<strong>Como se usa la moneda en este abono</strong>
									<p>La moneda indica en que divisa se registrara este pago. Solo si queda en <code>USD</code> y el descuento automatico esta activo se evaluara precio dual.</p>
								</div>
								<?php $this->render_input( 'reference', 'Referencia', 'text', '' ); ?>
								<?php $this->render_textarea( 'notes', 'Notas', 'Si el monto supera varios pedidos, el sistema los ira cerrando por fecha y dejara parcial el siguiente si aplica.' ); ?>
								<div class="asdl-fin-inline-actions">
									<?php submit_button( 'Aplicar abono a pedidos', 'primary', 'submit', false ); ?>
									<button type="button" class="button button-secondary" data-order-settlement-specific-open="1">Pedidos especificos</button>
								</div>
							</form>
						<?php endif; ?>
					</section>
					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Registrar saldo a favor</h2>
							<p>Usa esta opcion cuando la empresa le deba dinero a este perfil y quieras dejarlo disponible para pago manual, compromiso o futura compensacion.</p>
						</div>
						<?php $this->render_summary_rows(
							array(
								array(
									'label'       => 'Saldo a favor total',
									'description' => 'Todo lo que hoy esta a favor del perfil.',
									'value'       => $this->format_money( $credit_total ),
								),
								array(
									'label'       => 'Disponible hoy',
									'description' => 'La parte del saldo a favor que ya puede cruzarse o gestionarse de inmediato.',
									'value'       => $this->format_money( $available_credit_total ),
								),
							)
						); ?>
						<details class="asdl-fin-disclosure asdl-fin-financial-detail-disclosure">
							<summary>Ver desglose</summary>
							<div class="asdl-fin-disclosure-body">
								<?php $this->render_summary_rows(
									array(
										array(
											'label'       => 'Deuda documentada de la empresa',
											'description' => 'Documentos abiertos por pagar a este perfil.',
											'value'       => $this->format_money( $summary['payable_total'] ?? 0 ),
										),
										array(
											'label'       => 'Saldo ya registrado a favor',
											'description' => 'Monto ya registrado a favor del perfil y todavia no usado en cruces o compensaciones.',
											'value'       => $this->format_money( $summary['unapplied_payment_total'] ?? 0 ),
										),
										array(
											'label'       => 'Compromisos por pagar',
											'description' => 'Acuerdos programados a favor del perfil que aun no se pagan.',
											'value'       => $this->format_money( $summary['payable_commitment_total'] ?? 0 ),
										),
										array(
											'label'       => 'Adelantos por recuperar',
											'description' => 'Se descuentan por nomina o recuperacion manual; no forman parte del saldo a favor.',
											'value'       => $this->format_money( $summary['salary_advance_balance'] ?? 0 ),
											'tone'        => 'warning',
										),
									)
								); ?>
							</div>
						</details>
						<?php if ( $readonly_context ) : ?>
							<?php $this->render_fiscal_readonly_action_state( 'El alta de saldo a favor queda bloqueada en modo consulta.' ); ?>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid" enctype="multipart/form-data">
								<input type="hidden" name="action" value="asdl_fin_save_document" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<input type="hidden" name="range_from" value="<?php echo esc_attr( $filters['range_from'] ?? '' ); ?>" />
								<input type="hidden" name="range_to" value="<?php echo esc_attr( $filters['range_to'] ?? '' ); ?>" />
								<input type="hidden" name="order_limit" value="<?php echo esc_attr( $filters['order_limit'] ?? 25 ); ?>" />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_save_document' ); ?>
								<?php $this->render_hidden_inputs(
									array(
										'document_type'    => 'manual_document',
										'source_type'      => 'manual',
										'financial_status' => 'posted',
										'payment_status'   => 'pending',
										'manual_override'  => '1',
										'financial_intent' => 'neutral',
										'balance_nature'   => 'payable',
									)
								); ?>
								<?php $this->render_input( 'title', 'Concepto', 'text', '', true ); ?>
								<?php $this->render_select( 'account_id', 'Cuenta', $account_options, false, 'Opcional' ); ?>
								<?php $this->render_input( 'issue_date', 'Fecha', 'date', gmdate( 'Y-m-d' ), true ); ?>
								<?php $this->render_input( 'total', 'Monto a favor', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01' ) ); ?>
								<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
								<?php $this->render_input( 'external_reference', 'Referencia', 'text', '' ); ?>
								<?php $this->render_textarea( 'notes', 'Notas', 'Este saldo quedara a favor del perfil. Luego podras pagarlo manualmente, convertirlo en compromiso o cruzarlo contra pedidos si aplica.' ); ?>
								<?php submit_button( 'Registrar saldo a favor', 'secondary', 'submit', false ); ?>
							</form>
						<?php endif; ?>
					</section>
					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Aplicar saldo a favor</h2>
							<p>Usa credito disponible del perfil para cruzarlo internamente contra pedidos abiertos sin registrar entrada de caja.</p>
						</div>
						<?php $this->render_summary_rows(
							array(
								array(
									'label'       => 'Saldo a favor total',
									'description' => 'Suma total a favor del perfil entre documentos y acuerdos pendientes a su favor.',
									'value'       => $this->format_money( $credit_total ),
								),
								array(
									'label'       => 'Disponible hoy',
									'description' => sprintf(
										'De ese total, hoy puedes cruzar hasta %s contra pedidos abiertos.',
										wp_strip_all_tags( $this->format_money( $usable_credit_total ) )
									),
									'value'       => $this->format_money( $available_credit_total ),
								),
								array(
									'label'       => 'Cruce maximo hoy contra pedidos',
									'description' => 'Tope operativo segun lo que hoy esta disponible y el pendiente real de pedidos abiertos.',
									'value'       => $this->format_money( $usable_credit_total ),
								),
							)
						); ?>
						<details class="asdl-fin-disclosure asdl-fin-financial-detail-disclosure">
							<summary>Ver desglose</summary>
							<div class="asdl-fin-disclosure-body">
								<?php $this->render_summary_rows(
									array(
										array(
											'label'       => 'Deuda documentada de la empresa',
											'description' => 'Documentos abiertos donde la empresa le debe dinero a este perfil.',
											'value'       => $this->format_money( $summary['payable_total'] ?? 0 ),
										),
										array(
											'label'       => 'Saldo ya registrado a favor',
											'description' => 'Monto ya registrado a favor del perfil y todavia no aplicado a pedidos o movimientos.',
											'value'       => $this->format_money( $summary['unapplied_payment_total'] ?? 0 ),
										),
										array(
											'label'       => 'Compromisos por pagar',
											'description' => 'Acuerdos a favor del perfil que aun no se cruzan automaticamente desde esta pantalla.',
											'value'       => $this->format_money( $summary['payable_commitment_total'] ?? 0 ),
										),
										array(
											'label'       => 'Adelantos por recuperar',
											'description' => 'Se rebajan cuando la nomina los descuenta. No se usan como saldo a favor.',
											'value'       => $this->format_money( $summary['salary_advance_balance'] ?? 0 ),
											'tone'        => 'warning',
										),
									)
								); ?>
							</div>
						</details>
						<?php if ( $readonly_context ) : ?>
							<?php $this->render_fiscal_readonly_action_state( 'La compensacion interna queda bloqueada en modo consulta.' ); ?>
						<?php elseif ( $usable_credit_total > 0 ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
								<input type="hidden" name="action" value="asdl_fin_apply_profile_credit" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<input type="hidden" name="range_from" value="<?php echo esc_attr( $filters['range_from'] ?? '' ); ?>" />
								<input type="hidden" name="range_to" value="<?php echo esc_attr( $filters['range_to'] ?? '' ); ?>" />
								<input type="hidden" name="order_limit" value="<?php echo esc_attr( $filters['order_limit'] ?? 25 ); ?>" />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_apply_profile_credit' ); ?>
								<?php $this->render_select( 'account_id', 'Cuenta interna', $account_options, false, 'Opcional' ); ?>
								<?php $this->render_input( 'payment_date', 'Fecha de compensacion', 'date', gmdate( 'Y-m-d' ), true ); ?>
								<?php $this->render_input( 'total', 'Monto a cruzar', 'number', (string) number_format( $usable_credit_total, 2, '.', '' ), true, array( 'step' => '0.01', 'min' => '0.01', 'max' => number_format( $usable_credit_total, 2, '.', '' ) ) ); ?>
								<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
								<?php $this->render_input( 'reference', 'Referencia interna', 'text', '' ); ?>
								<?php $this->render_textarea( 'notes', 'Notas', 'Cruce interno entre saldo a favor del perfil y pedidos pendientes. Si alcanza, el pedido se completara en Woo.' ); ?>
								<?php submit_button( 'Aplicar saldo a favor', 'secondary', 'submit', false ); ?>
							</form>
						<?php else : ?>
							<div class="asdl-fin-empty">
								<strong>Sin saldo utilizable en este momento.</strong>
								<p>Para cruzar pedidos necesitas credito disponible o documentos abiertos donde la empresa le deba dinero a este perfil.</p>
							</div>
						<?php endif; ?>
					</section>
				</div>
				<?php $this->render_orders_table(
					$snapshot['pending_orders'] ?? array(),
					'asdl-fin-contacts',
					array(
						'contact_id' => (int) $contact['id'],
						'range_from' => $filters['range_from'] ?? '',
						'range_to'   => $filters['range_to'] ?? '',
						'order_limit'=> $filters['order_limit'] ?? 25,
					),
					array(
						'mode'               => 'operational',
						'action_label'       => 'Gestion',
						'table_title'        => 'Pedidos abiertos para cruce / gestion',
						'table_description'  => 'Cola operativa de pedidos Woo/OpenPOS que siguen abiertos y aun puedes abonar, compensar o regularizar desde este perfil. Si un pedido ya quedo pagado, sale de esta lista por diseno.',
						'per_page'           => 15,
						'expanded_per_page'  => 30,
						'show_expand_toggle' => true,
					)
				); ?>
			</section>

			<section class="asdl-fin-panel" id="asdl-fin-contact-orders">
				<div class="asdl-fin-panel-header">
					<h2>Pedidos</h2>
					<p>Lectura historica del periodo filtrado para revisar consumo, frecuencia y deuda pendiente del perfil.</p>
				</div>
				<div class="asdl-fin-data-grid">
					<div><strong>Pedidos en rango</strong><span><?php echo esc_html( number_format_i18n( $order_summary['order_count'] ?? 0 ) ); ?></span></div>
					<div><strong>Pendientes en rango</strong><span><?php echo esc_html( number_format_i18n( $order_summary['open_order_count'] ?? 0 ) ); ?></span></div>
					<div><strong>Total consumido</strong><span><?php echo wp_kses_post( $this->format_money( $order_summary['orders_total'] ?? 0 ) ); ?></span></div>
					<div><strong>Total pendiente en rango</strong><span><?php echo wp_kses_post( $this->format_money( $order_summary['open_order_total'] ?? 0 ) ); ?></span></div>
				</div>
				<?php $this->render_orders_table(
					$snapshot['orders'] ?? array(),
					'asdl-fin-contacts',
					array(
						'contact_id' => (int) $contact['id'],
						'range_from' => $filters['range_from'] ?? '',
						'range_to'   => $filters['range_to'] ?? '',
						'order_limit'=> $filters['order_limit'] ?? 25,
					),
					array(
						'mode'               => 'history',
						'action_label'       => 'Gestion',
						'table_title'        => 'Vista amplia de pedidos del rango',
						'table_description'  => 'Esta tabla si mezcla lectura actual e historica del rango filtrado para revisar consumo, frecuencia, pedidos pagados y deuda pendiente del perfil.',
						'per_page'           => 15,
						'expanded_per_page'  => 30,
						'show_expand_toggle' => true,
					)
				); ?>
			</section>

			<section class="asdl-fin-panel" id="asdl-fin-contact-consumption">
				<div class="asdl-fin-panel-header">
					<h2>Consumo e interacciones</h2>
					<p>Resumen del mismo periodo filtrado para leer habito de compra, frecuencia e intensidad del perfil.</p>
				</div>
				<?php $this->render_consumption_interactions_panel( $snapshot ); ?>
			</section>
			<?php endif; ?>

			<?php if ( ! $is_supplier_only ) : ?>
			<section class="asdl-fin-panel" id="asdl-fin-contact-documents">
				<div class="asdl-fin-panel-header">
					<h2>Movimientos</h2>
					<p>Historial reciente de movimientos vinculados al perfil, excluyendo servicios que ya viven en su propia seccion.</p>
				</div>
				<?php $this->render_documents_table(
					$profile_documents,
					array(
						'allow_cancel'      => true,
						'per_page'          => 15,
						'expanded_per_page' => 30,
						'show_expand_toggle'=> true,
						'empty_title'       => 'Sin movimientos adicionales registrados.',
						'empty_description' => 'Los servicios se gestionan aparte; aqui veras el resto de movimientos del perfil.',
					)
				); ?>
			</section>
			<?php endif; ?>

			<section class="asdl-fin-panel" id="asdl-fin-contact-open">
				<div class="asdl-fin-panel-header">
					<h2>Cuentas por pagar</h2>
					<p>Obligaciones abiertas de la empresa con este perfil: documentos por pagar, servicios emitidos y acuerdos pendientes a favor.</p>
				</div>
				<?php $this->render_summary_rows(
					array(
						array(
							'label'       => 'Documentos por pagar abiertos',
							'description' => 'Documentos del core con saldo pendiente a favor de este perfil, sin contar los servicios que ya se detallan aparte.',
							'value'       => $this->format_money( $non_service_payable_total ),
						),
						array(
							'label'       => 'Servicios por pagar',
							'description' => 'Servicios emitidos que todavia no se han pagado por completo.',
							'value'       => $this->format_money( $service_summary['open_balance_total'] ?? 0 ),
						),
						array(
							'label'       => 'Compromisos por pagar',
							'description' => 'Acuerdos activos a favor del perfil que siguen pendientes.',
							'value'       => $this->format_money( $summary['payable_commitment_total'] ?? 0 ),
						),
					)
				); ?>
				<?php $this->render_documents_table(
					$display_open_payable_documents,
					array(
						'allow_cancel'      => true,
						'empty_title'       => 'Sin cuentas por pagar abiertas.',
						'empty_description' => 'Cuando la empresa tenga documentos pendientes con este perfil, apareceran aqui.',
					)
				); ?>
			</section>

			<?php if ( $has_service_section ) : ?>
				<section class="asdl-fin-panel" id="asdl-fin-contact-services">
					<div class="asdl-fin-panel-header">
						<h2>Servicios</h2>
						<p>Configuracion recurrente y documentos de servicio ligados a este proveedor o tercero.</p>
					</div>
					<?php $this->render_summary_rows(
						array(
							array(
								'label'       => 'Servicios recurrentes',
								'description' => 'Configuraciones activas, pausadas o inactivas asociadas a este perfil.',
								'value'       => number_format_i18n( $service_summary['profile_count'] ?? 0 ),
							),
							array(
								'label'       => 'Proximo cobro',
								'description' => '' !== $next_service_title ? $next_service_title . ' · ' . wp_strip_all_tags( $this->format_money( $next_service_amount, $next_service_currency ) ) : 'Aun no hay una siguiente emision programada para este proveedor.',
								'value'       => esc_html( $next_service_due_label ),
							),
							array(
								'label'       => 'Por emitir hoy',
								'description' => 'Servicios recurrentes cuya siguiente fecha ya vencio o corresponde al dia actual.',
								'value'       => number_format_i18n( $service_summary['due_profile_count'] ?? 0 ),
							),
							array(
								'label'       => 'Proximos 30 dias',
								'description' => 'Servicios programados dentro de la siguiente ventana operativa.',
								'value'       => number_format_i18n( $service_summary['upcoming_profile_count'] ?? 0 ),
							),
							array(
								'label'       => 'Servicios por pagar',
								'description' => 'Saldo pendiente de servicios ya emitidos para este proveedor o tercero.',
								'value'       => $this->format_money( $service_summary['open_balance_total'] ?? 0 ),
							),
						)
					); ?>

					<div class="asdl-fin-panel-grid asdl-fin-panel-grid-vertical asdl-fin-salary-advance-layout">
						<section class="asdl-fin-panel">
							<div class="asdl-fin-panel-header">
								<h2>Nuevo servicio recurrente</h2>
								<p>Semilla contratos, suscripciones o cargos repetitivos directamente sobre este perfil.</p>
							</div>
							<?php if ( $readonly_context ) : ?>
								<?php $this->render_fiscal_readonly_action_state( 'La configuracion de servicios queda bloqueada en modo consulta.' ); ?>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
									<input type="hidden" name="action" value="asdl_fin_save_service_profile" />
									<input type="hidden" name="return_page" value="asdl-fin-contacts" />
									<input type="hidden" name="return_section" value="asdl-fin-contact-services" />
									<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
									<?php $this->render_current_fiscal_hidden_input(); ?>
									<?php wp_nonce_field( 'asdl_fin_save_service_profile' ); ?>
									<?php $this->render_input( 'title', 'Servicio recurrente', 'text', '', true ); ?>
									<?php $this->render_select( 'account_id', 'Cuenta sugerida', $account_options, false, 'Opcional' ); ?>
									<?php $this->render_select( 'frequency_key', 'Frecuencia', ( new ServiceProfilesRepository() )->frequency_options(), true ); ?>
									<?php $this->render_input( 'start_date', 'Primera emision', 'date', gmdate( 'Y-m-d' ), true ); ?>
									<?php $this->render_input( 'next_issue_date', 'Proxima emision', 'date', gmdate( 'Y-m-d' ), true ); ?>
									<?php $this->render_input( 'default_due_days', 'Dias para vencimiento', 'number', '0', false, array( 'min' => '0', 'step' => '1' ) ); ?>
									<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
									<?php $this->render_input( 'amount', 'Monto base', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01' ) ); ?>
									<?php $this->render_input( 'payment_terms', 'Condiciones de pago', 'text', $contact['payment_terms'] ?? '' ); ?>
									<?php $this->render_input( 'external_reference', 'Referencia externa', 'text', '' ); ?>
									<?php $this->render_select( 'status', 'Estado', ( new ServiceProfilesRepository() )->status_options(), true, '', 'active' ); ?>
									<?php $this->render_textarea( 'notes', 'Notas', 'Observacion interna del servicio recurrente, contrato o suscripcion.' ); ?>
									<?php submit_button( 'Guardar servicio recurrente', 'primary', 'submit', false ); ?>
								</form>
							<?php endif; ?>
						</section>

						<section class="asdl-fin-panel">
							<div class="asdl-fin-panel-header">
								<h2>Registrar servicio puntual</h2>
								<p>Usa esta forma cuando el cargo no sea recurrente y deba nacer directamente como cuenta por pagar.</p>
							</div>
							<?php if ( $readonly_context ) : ?>
								<?php $this->render_fiscal_readonly_action_state( 'El alta de servicios puntuales queda bloqueada en modo consulta.' ); ?>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
									<input type="hidden" name="action" value="asdl_fin_save_document" />
									<input type="hidden" name="return_page" value="asdl-fin-contacts" />
									<input type="hidden" name="return_section" value="asdl-fin-contact-services" />
									<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
									<?php $this->render_current_fiscal_hidden_input(); ?>
									<?php wp_nonce_field( 'asdl_fin_save_document' ); ?>
									<?php $this->render_hidden_inputs( array( 'document_type' => 'service_expense', 'source_type' => 'manual', 'financial_status' => 'posted' ) ); ?>
									<?php $this->render_input( 'title', 'Concepto del servicio', 'text', '', true ); ?>
									<?php $this->render_select( 'account_id', 'Cuenta sugerida', $account_options, false, 'Opcional' ); ?>
									<?php $this->render_input( 'issue_date', 'Fecha del servicio', 'date', gmdate( 'Y-m-d' ), true ); ?>
									<?php $this->render_input( 'due_date', 'Vencimiento', 'date', gmdate( 'Y-m-d' ), false ); ?>
									<?php $this->render_currency_select( 'currency', 'Moneda', 'USD', true ); ?>
									<?php $this->render_input( 'total', 'Monto total', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01' ) ); ?>
									<?php $this->render_input( 'external_reference', 'Referencia', 'text', '' ); ?>
									<?php $this->render_textarea( 'notes', 'Notas', 'Describe el servicio puntual o cargo eventual asociado a este tercero.' ); ?>
									<?php submit_button( 'Registrar servicio puntual', 'secondary', 'submit', false ); ?>
								</form>
							<?php endif; ?>
						</section>
					</div>

					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Cola de servicios del perfil</h2>
							<p>Servicios recurrentes listos para emitir o programados dentro de la siguiente ventana.</p>
						</div>
						<section class="asdl-fin-panel asdl-fin-panel-subtle">
							<div class="asdl-fin-panel-header">
								<h2>Proximas emisiones</h2>
								<p>Servicios recurrentes ya programados dentro de la ventana corta.</p>
							</div>
							<?php $this->render_service_profiles_table(
								$upcoming_service_profiles,
								array(
									'mode'                => 'upcoming',
									'allow_generate'      => false,
									'allow_toggle'        => false,
									'show_contact_column' => false,
									'show_profile_action' => false,
									'return_page'         => 'asdl-fin-contacts',
									'return_section'      => 'asdl-fin-contact-services',
									'profile_section'     => 'asdl-fin-contact-services',
									'empty_title'         => 'Sin servicios proximos en la ventana.',
									'empty_description'   => 'Cuando existan emisiones futuras dentro de 30 dias, se veran aqui.',
								)
							); ?>
						</section>
						<section class="asdl-fin-panel asdl-fin-panel-subtle">
							<div class="asdl-fin-panel-header">
								<h2>Por emitir hoy</h2>
								<p>Servicios vencidos o listos para convertirse en cuenta por pagar real.</p>
							</div>
							<?php $this->render_service_profiles_table(
								$due_service_profiles,
								array(
									'mode'                => 'queue',
									'allow_generate'      => ! $readonly_context,
									'allow_toggle'        => false,
									'show_contact_column' => false,
									'show_profile_action' => false,
									'return_page'         => 'asdl-fin-contacts',
									'return_section'      => 'asdl-fin-contact-services',
									'profile_section'     => 'asdl-fin-contact-services',
									'empty_title'         => 'Sin servicios por emitir hoy para este perfil.',
									'empty_description'   => 'Cuando exista una emision vencida o del dia, aparecera aqui.',
								)
							); ?>
						</section>
					</section>

					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Servicios recurrentes configurados</h2>
							<p>Base viva del proveedor o tercero para emitir servicios sin salir del perfil.</p>
						</div>
						<?php $this->render_service_profiles_table(
							$service_profiles,
							array(
								'mode'                => 'profiles',
								'allow_generate'      => ! $readonly_context,
								'allow_toggle'        => ! $readonly_context,
								'show_contact_column' => false,
								'show_profile_action' => false,
								'return_page'         => 'asdl-fin-contacts',
								'return_section'      => 'asdl-fin-contact-services',
								'profile_section'     => 'asdl-fin-contact-services',
								'empty_title'         => 'Sin servicios recurrentes configurados para este perfil.',
								'empty_description'   => 'Guarda arriba el primer servicio recurrente para sembrar la operativa.',
							)
						); ?>
					</section>

					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Servicios por pagar</h2>
							<p>Servicios emitidos que aun no se han pagado por completo.</p>
						</div>
						<?php $this->render_documents_table(
							$open_service_documents,
							array(
								'allow_cancel'      => true,
								'detail_page'       => 'asdl-fin-services',
								'empty_title'       => 'Sin servicios por pagar abiertos.',
								'empty_description' => 'Los servicios emitidos con saldo pendiente apareceran aqui.',
							)
						); ?>
					</section>
					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Servicios registrados</h2>
							<p>Historial corto de servicios puntuales o emitidos para este perfil.</p>
						</div>
						<?php $this->render_documents_table(
							$service_documents,
							array(
								'allow_cancel'      => true,
								'detail_page'       => 'asdl-fin-services',
								'empty_title'       => 'Aun no hay servicios registrados para este perfil.',
								'empty_description' => 'Los servicios puntuales y recurrentes emitidos apareceran aqui.',
							)
						); ?>
					</section>
				</section>
			<?php endif; ?>

			<?php if ( $is_supplier_only ) : ?>
				<section class="asdl-fin-panel" id="asdl-fin-contact-documents">
					<div class="asdl-fin-panel-header">
						<h2>Movimientos</h2>
						<p>Historial reciente del perfil proveedor o tercero, excluyendo servicios que ya se gestionan en su propia seccion.</p>
					</div>
					<?php $this->render_documents_table(
						$profile_documents,
						array(
							'allow_cancel'      => true,
							'per_page'          => 15,
							'expanded_per_page' => 30,
							'show_expand_toggle'=> true,
							'empty_title'       => 'Sin movimientos adicionales registrados.',
							'empty_description' => 'Los servicios y cuentas por pagar ya se gestionan arriba; aqui veras el resto de movimientos del perfil.',
						)
					); ?>
				</section>
			<?php endif; ?>

			<section class="asdl-fin-panel" id="asdl-fin-contact-commitments">
				<?php
				$commitment_section_open  = ( (int) ( $summary['installment_plan_count'] ?? 0 ) > 0 ) || ( (float) ( $summary['installment_balance'] ?? 0 ) > 0 );
				$commitment_section_copy  = $commitment_section_open
					? sprintf(
						'%1$s compromiso(s) activo(s) con saldo pendiente de %2$s.',
						number_format_i18n( (int) ( $summary['installment_plan_count'] ?? 0 ) ),
						wp_strip_all_tags( $this->format_money( $summary['installment_balance'] ?? 0 ) )
					)
					: '0 compromisos activos en este perfil por ahora.';
				$commitment_section_items = array(
					array(
						'label' => 'Activos',
						'value' => number_format_i18n( (int) ( $summary['installment_plan_count'] ?? 0 ) ),
					),
					array(
						'label' => 'Saldo',
						'value' => wp_strip_all_tags( $this->format_money( $summary['installment_balance'] ?? 0 ) ),
					),
				);
				?>
				<?php $this->render_profile_context_disclosure_start( 'asdl-fin-contact-commitments', 'Compromisos', $commitment_section_copy, $commitment_section_items, $commitment_section_open ); ?>
				<div class="asdl-fin-panel-header asdl-fin-profile-context-header">
					<h2>Compromisos</h2>
					<p>Prestamos, deudas acordadas y descuentos programados directamente sobre este perfil.</p>
				</div>
				<div class="asdl-fin-panel-grid">
					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Resumen de compromisos</h2>
							<p>Lectura rapida del saldo comprometido y su forma de gestion actual.</p>
						</div>
						<div class="asdl-fin-data-grid">
							<div><strong>Compromisos activos</strong><span><?php echo esc_html( number_format_i18n( $summary['installment_plan_count'] ?? 0 ) ); ?></span></div>
							<div><strong>Saldo pendiente</strong><span><?php echo wp_kses_post( $this->format_money( $summary['installment_balance'] ?? 0 ) ); ?></span></div>
							<div><strong>Por cobrar adicional</strong><span><?php echo wp_kses_post( $this->format_money( $additional_receivable_commitment_total ) ); ?></span></div>
							<div><strong>Deuda de tienda ya planificada</strong><span><?php echo wp_kses_post( $this->format_money( $store_debt_commitment_planned_total ) ); ?></span></div>
							<div><strong>Por pagar</strong><span><?php echo wp_kses_post( $this->format_money( $summary['payable_commitment_total'] ?? 0 ) ); ?></span></div>
							<div><strong>Perfil empleado</strong><span><?php echo esc_html( $is_employee ? 'Si' : 'No' ); ?></span></div>
							<div><strong>Frecuencia laboral</strong><span><?php echo esc_html( ! empty( $employee_profile['pay_frequency'] ) ? $this->label_for( 'frequency_key', $employee_profile['pay_frequency'] ) : 'Sin definir' ); ?></span></div>
						</div>
						<?php $this->render_installment_plans_table( $snapshot['plans'], array( 'allow_cancel' => true ) ); ?>
					</section>
					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Crear compromiso</h2>
							<p>Define el monto comprometido y deja que el sistema calcule automaticamente cuantas semanas, quincenas o meses necesitara segun la forma de gestion.</p>
						</div>
						<?php if ( $readonly_context ) : ?>
							<?php $this->render_fiscal_readonly_action_state( 'La creacion de compromisos queda bloqueada en modo consulta.' ); ?>
						<?php else : ?>
							<?php
							$commitment_payroll_ready    = $is_employee && ! empty( $employee_profile['payroll_eligible'] ) && ! empty( $employee_profile['pay_frequency'] ) && ! empty( $employee_profile['next_payment_date'] );
							$commitment_payroll_schedule = $commitment_payroll_ready ? $this->commitment_payroll_schedule_options( $employee_profile ) : array();
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-commitment-form" data-commitment-form data-is-employee="<?php echo esc_attr( $is_employee ? '1' : '0' ); ?>" data-employee-frequency="<?php echo esc_attr( $employee_profile['pay_frequency'] ?? '' ); ?>" data-employee-next-payment="<?php echo esc_attr( $employee_next_payment ); ?>" data-employee-currency="<?php echo esc_attr( $employee_salary_currency ); ?>" data-employee-salary-amount="<?php echo esc_attr( (string) $employee_salary_amount ); ?>" data-employee-commitment-preview-total="<?php echo esc_attr( (string) $commitment_preview_total ); ?>" data-employee-payroll-ready="<?php echo esc_attr( $commitment_payroll_ready ? '1' : '0' ); ?>" data-has-profile-context="1" data-store-debt-total="<?php echo esc_attr( (string) ( $summary['pending_order_total'] ?? 0 ) ); ?>" data-store-debt-count="<?php echo esc_attr( (string) ( $summary['pending_order_count_total'] ?? 0 ) ); ?>" data-company-debt-total="<?php echo esc_attr( (string) ( $summary['payable_total'] ?? 0 ) ); ?>">
								<input type="hidden" name="action" value="asdl_fin_save_installment_plan" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="return_section" value="asdl-fin-contact-commitments" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<input type="hidden" name="target_installment_amount" value="" data-commitment-target-installment />
								<input type="hidden" name="installment_count" value="" data-commitment-installment-count />
								<input type="hidden" name="confirm_capacity_override" value="0" data-commitment-capacity-confirm />
								<input type="hidden" name="status" value="active" />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_save_installment_plan' ); ?>
								<?php $this->render_input( 'title', 'Nombre del compromiso', 'text', '', true ); ?>
								<?php $this->render_select( 'settlement_direction', 'Sentido', array( 'receivable' => 'El perfil paga a la empresa', 'payable' => 'La empresa paga al perfil' ), false, '', '', array( 'data-commitment-direction' => '1' ) ); ?>
								<?php $this->render_select( 'commitment_origin', 'Origen', array( 'loan' => 'Prestamo', 'store_debt' => 'Deuda de tienda', 'manual_charge' => 'Cargo manual', 'company_debt' => 'Deuda de la empresa' ), false, '', '', array( 'data-commitment-origin' => '1' ) ); ?>
								<label class="asdl-fin-field">
									<span>Forma de gestion</span>
									<select name="collection_mode" data-commitment-collection-mode>
										<?php echo $this->render_select_options( $this->commitment_collection_mode_options( $commitment_payroll_ready ), $commitment_payroll_ready ? 'payroll_deduction' : 'manual' ); ?>
									</select>
									<small data-commitment-mode-help><?php echo esc_html( $commitment_payroll_ready ? 'Si eliges descuento por sueldo o pago por nomina, este compromiso se alineara con la frecuencia laboral y las fechas reales de nomina del empleado.' : ( $is_employee ? 'Primero completa la ficha laboral del empleado con frecuencia y proximo pago para poder usar compromisos por nomina.' : 'Solo los perfiles marcados como empleado pueden usar nomina. Este compromiso se gestionara manualmente.' ) ); ?></small>
								</label>
								<?php $this->render_input( 'principal_amount', 'Monto comprometido', 'number', '', true, array( 'step' => '0.01', 'min' => '0.01', 'data-commitment-principal' => '1' ) ); ?>
								<div class="asdl-fin-field asdl-fin-field-wide asdl-fin-context-hint" data-commitment-origin-summary hidden>
									<span>Base detectada</span>
									<small data-commitment-origin-summary-text>Selecciona un origen para que el sistema sugiera el monto conocido del perfil cuando aplique.</small>
								</div>
								<label class="asdl-fin-field asdl-fin-field-wide asdl-fin-checkbox-field">
									<span class="asdl-fin-checkbox-row">
										<input type="checkbox" data-commitment-total-toggle />
										<strong>Usar un monto final distinto</strong>
									</span>
									<small>Activalo solo si el total que quieres recuperar o pagar no coincide con el monto comprometido base.</small>
								</label>
								<label class="asdl-fin-field" data-commitment-total-field hidden>
									<span>Monto total final</span>
									<input type="number" name="total_amount" value="" min="0" step="0.01" placeholder="Opcional si el total final es distinto" data-commitment-total />
									<small>Si lo dejas vacio, el sistema usara el mismo monto comprometido como total final.</small>
								</label>
								<?php $this->render_select( 'planning_mode', 'Como quieres planificarlo', $this->commitment_planning_mode_options(), true, '', 'period_amount', array( 'data-commitment-planning-mode' => '1' ) ); ?>
								<label class="asdl-fin-field" data-commitment-planning-field>
									<span data-commitment-planning-label>Monto por periodo *</span>
									<input type="number" name="planning_value" value="" min="0" step="0.01" required data-commitment-planning-value />
									<small data-commitment-planning-help>Indica cuanto se descontara o pagara por periodo para calcular automaticamente semanas, quincenas o meses.</small>
								</label>
								<label class="asdl-fin-field" data-commitment-frequency-field>
									<span>Frecuencia</span>
									<select name="frequency_key" data-commitment-frequency>
										<?php echo $this->render_select_options( $this->commitment_frequency_options(), $employee_profile['pay_frequency'] ?? 'monthly' ); ?>
									</select>
									<small data-commitment-frequency-help><?php echo esc_html( $is_employee ? 'Si el empleado ya tiene frecuencia laboral definida, la nomina usara esa cadencia real. Si aun no la tiene, esta frecuencia servira como base temporal de la proyeccion.' : 'Usa esta frecuencia para calcular el calendario del compromiso.' ); ?></small>
								</label>
								<label class="asdl-fin-field" data-commitment-start-field>
									<span>Fecha inicial</span>
									<input type="date" name="start_date" value="<?php echo esc_attr( $employee_profile['next_payment_date'] ?? gmdate( 'Y-m-d' ) ); ?>" required data-commitment-start-date />
									<small>Si el compromiso se gestiona manualmente, puedes fijar libremente la fecha inicial.</small>
								</label>
								<label class="asdl-fin-field" data-commitment-payroll-start-field hidden>
									<span>Aplicar desde la nomina de</span>
									<select data-commitment-payroll-start-select>
										<?php echo $this->render_select_options( $commitment_payroll_schedule, $employee_profile['next_payment_date'] ?? '' ); ?>
									</select>
									<small>Cuando el compromiso vaya por nomina, solo podra iniciar en una fecha de pago compatible con la configuracion del empleado.</small>
								</label>
								<?php $this->render_currency_select( 'currency', 'Moneda', $employee_profile['salary_currency'] ?? 'USD', true, 'Selecciona una moneda', array( 'data-commitment-currency' => '1' ) ); ?>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<span>Proyeccion automatica</span>
									<div class="asdl-fin-automation-projection asdl-fin-commitment-projection" data-commitment-projection>
										<div><strong>Frecuencia aplicada</strong><span data-projection-frequency><?php echo esc_html( ! empty( $employee_profile['pay_frequency'] ) ? $this->label_for( 'frequency_key', $employee_profile['pay_frequency'] ) : 'Mensual' ); ?></span></div>
										<div><strong>Monto por periodo</strong><span data-projection-amount>—</span></div>
										<div><strong>Primera cuota</strong><span data-projection-first>—</span></div>
										<div><strong>Cuota normal</strong><span data-projection-regular>—</span></div>
										<div><strong>Ultima cuota</strong><span data-projection-last>—</span></div>
										<div><strong>Periodos estimados</strong><span data-projection-count>—</span></div>
										<div><strong>Proxima aplicacion</strong><span data-projection-start><?php echo esc_html( $employee_profile['next_payment_date'] ?? gmdate( 'Y-m-d' ) ); ?></span></div>
										<div><strong>Cierre estimado</strong><span data-projection-end>—</span></div>
										<div><strong>Tratamiento</strong><span data-projection-mode><?php echo esc_html( $is_employee ? 'Integrado con nomina' : 'Gestion manual' ); ?></span></div>
										<div><strong>Capacidad del primer pago</strong><span data-projection-capacity>—</span></div>
										<div><strong>Falta por mover</strong><span data-projection-shortfall>—</span></div>
									</div>
									<small data-commitment-projection-summary><?php echo esc_html( $is_employee ? 'Configura el acuerdo y deja que el sistema estime impacto, periodos y calendario usando la ficha laboral del empleado cuando aplique.' : 'Configura el acuerdo y deja que el sistema estime automaticamente su calendario e impacto.' ); ?></small>
								</div>
								<?php $this->render_textarea( 'notes', 'Notas', 'Ejemplo: deuda por consumo de tienda, pago semanal de una deuda de la empresa o descuento fijo por sueldo.' ); ?>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<span>Accion</span>
									<div class="asdl-fin-inline-actions">
										<?php submit_button( 'Guardar compromiso', 'primary', 'submit', false ); ?>
										<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=asdl-fin-installments' ) ); ?>">Abrir gestion global</a>
									</div>
								</div>
							</form>
						<?php endif; ?>
						<hr class="asdl-fin-divider" />
						<div class="asdl-fin-panel-header">
							<h2>Registrar abono a compromiso</h2>
							<p>Aplica un pago o abono sobre un compromiso activo del perfil. Si el compromiso esta enlazado a un movimiento, el saldo financiero tambien se ajusta.</p>
						</div>
						<?php if ( $readonly_context ) : ?>
							<?php $this->render_fiscal_readonly_action_state( 'Los abonos a compromisos quedan bloqueados en modo consulta.' ); ?>
						<?php elseif ( empty( $active_commitment_options ) ) : ?>
							<div class="asdl-fin-empty">
								<strong>Sin compromisos abiertos.</strong>
								<p>Cuando registres un compromiso por cobrar o por pagar, podras gestionarlo desde aqui.</p>
							</div>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid">
								<input type="hidden" name="action" value="asdl_fin_apply_commitment_payment" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="return_section" value="asdl-fin-contact-commitments" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_apply_commitment_payment' ); ?>
								<?php $this->render_select( 'plan_id', 'Compromiso', $active_commitment_options, true ); ?>
								<?php $this->render_select( 'account_id', 'Cuenta operativa', $account_options, false, 'Opcional' ); ?>
								<?php $this->render_input( 'payment_date', 'Fecha del abono', 'date', gmdate( 'Y-m-d' ), true ); ?>
								<?php $this->render_input( 'amount', 'Monto a aplicar', 'number', '0', true, array( 'step' => '0.01', 'min' => '0.01' ) ); ?>
								<?php $this->render_currency_select( 'currency', 'Moneda', $employee_profile['salary_currency'] ?? 'USD', true ); ?>
								<?php $this->render_payment_method_select( 'method_key', 'Metodo', '', true ); ?>
								<?php $this->render_input( 'reference', 'Referencia', 'text', '' ); ?>
								<?php $this->render_textarea( 'notes', 'Notas', 'Usa este formulario para pagos directos, abonos extra o ajustes sobre acuerdos del perfil.' ); ?>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<span>Accion</span>
									<div class="asdl-fin-inline-actions">
										<?php submit_button( 'Aplicar pago o abono', 'secondary', 'submit', false ); ?>
									</div>
								</div>
							</form>
						<?php endif; ?>
					</section>
				</div>
				<?php $this->render_profile_context_disclosure_end(); ?>
			</section>

			<section class="asdl-fin-panel" id="asdl-fin-contact-payments">
				<div class="asdl-fin-panel-header">
					<h2>Abonos y pagos</h2>
					<p>Movimientos financieros registrados sobre este perfil.</p>
				</div>
				<?php $this->render_payments_table( $snapshot['payments'], array( 'allow_cancel' => true ) ); ?>
			</section>

			<section class="asdl-fin-panel" id="asdl-fin-contact-history">
				<div class="asdl-fin-panel-header">
					<h2>Historico</h2>
					<p>Asignaciones recientes y trazabilidad operativa del perfil.</p>
				</div>
				<div class="asdl-fin-panel-grid asdl-fin-history-grid">
					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Asignaciones</h2>
							<p>Abonos aplicados a documentos.</p>
						</div>
						<?php $this->render_allocations_table( $snapshot['allocations'], array( 'per_page' => 5, 'compact' => true ) ); ?>
					</section>
					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Referencia de compromisos</h2>
							<p>Los compromisos se gestionan en su propia seccion del perfil y desde el modulo global cuando haga falta.</p>
						</div>
						<div class="asdl-fin-empty">
							<strong>Gestion centrada en el perfil.</strong>
							<p>Usa la seccion `Compromisos` de este perfil para crear acuerdos, prestamos o descuentos programados sin salir del contexto del usuario.</p>
						</div>
					</section>
				</div>
			</section>

			<?php if ( $is_employee ) : ?>
				<?php
				$employee_payroll_ready_ui   = ! empty( $employee_profile['payroll_eligible'] ) && ! empty( $employee_profile['pay_frequency'] ) && ! empty( $employee_profile['next_payment_date'] );
				$employee_payroll_missing_ui = array();

				if ( empty( $employee_profile['pay_frequency'] ) ) {
					$employee_payroll_missing_ui[] = 'frecuencia de pago';
				}

				if ( empty( $employee_profile['next_payment_date'] ) ) {
					$employee_payroll_missing_ui[] = 'proximo pago';
				}

				if ( empty( $employee_profile['payroll_eligible'] ) ) {
					if ( ! empty( $employee_profile['renewal_required'] ) ) {
						$employee_payroll_missing_ui[] = 'renovacion de contrato';
					} elseif ( ! empty( $employee_profile['employment_status'] ) && 'active' !== $employee_profile['employment_status'] ) {
						$employee_payroll_missing_ui[] = 'estado laboral activo';
					} else {
						$employee_payroll_missing_ui[] = 'elegibilidad de nomina';
					}
				}

				$employee_payroll_missing_ui = array_values( array_unique( $employee_payroll_missing_ui ) );
				$employee_payroll_status_note = $employee_payroll_ready_ui
					? 'Este empleado ya puede entrar en nomina y en compromisos con gestion por nomina usando su frecuencia laboral real.'
					: 'Antes de usar nomina o compromisos por nomina, completa: ' . implode( ', ', $employee_payroll_missing_ui ) . '.';
				$labor_config_ready_ui = $employee_payroll_ready_ui
					&& ! empty( $employee_profile['salary_amount'] )
					&& ! empty( $employee_profile['salary_currency'] )
					&& ! empty( $employee_profile['pay_frequency'] );
				$labor_config_open     = ! $labor_config_ready_ui;
				$labor_summary_bits    = array_filter(
					array(
						! empty( $employee_profile['employment_status'] ) ? $this->label_for( 'employment_status', $employee_profile['employment_status'] ) : '',
						! empty( $employee_profile['salary_amount'] ) ? wp_strip_all_tags( $this->format_money( $employee_profile['salary_amount'], $employee_profile['salary_currency'] ?? 'USD' ) ) : '',
						! empty( $employee_profile['pay_frequency'] ) ? $this->label_for( 'frequency_key', $employee_profile['pay_frequency'] ) : '',
						$employee_profile['payday_summary'] ?? '',
						! empty( $employee_profile['next_payment_date'] ) ? 'Proximo pago ' . $employee_profile['next_payment_date'] : '',
					)
				);
				$labor_summary_text = ! empty( $labor_summary_bits )
					? implode( ' | ', $labor_summary_bits )
					: 'Completa esta ficha para dejar al empleado listo para nomina y compromisos por nomina.';
				?>
				<section class="asdl-fin-panel" id="asdl-fin-contact-employee">
					<div class="asdl-fin-panel-header">
						<h2>Sueldo, adelantos y nomina</h2>
						<p>Ficha laboral practica del empleado: contrato, fechas clave, sueldo base, frecuencia, adelantos y nomina por periodo.</p>
						<div class="asdl-fin-badge-group">
							<?php echo wp_kses_post( $this->render_pill( $employee_payroll_ready_ui ? 'Listo para nomina' : 'Falta configurar nomina', $employee_payroll_ready_ui ? 'success' : 'warning' ) ); ?>
						</div>
					</div>
					<div class="asdl-fin-panel-grid">
						<section class="asdl-fin-panel">
							<div class="asdl-fin-panel-header">
								<h2>Ficha laboral</h2>
								<p>Datos minimos del empleado y del contrato para seguimiento administrativo real.</p>
								<div class="asdl-fin-badge-group">
									<?php echo wp_kses_post( $this->render_pill( $employee_payroll_ready_ui ? 'Nomina habilitada' : 'Nomina pendiente', $employee_payroll_ready_ui ? 'success' : 'warning' ) ); ?>
									<?php if ( ! empty( $employee_profile['contract_status_key'] ) ) : ?>
										<?php echo wp_kses_post( $this->render_pill( $this->label_for( 'contract_status', $employee_profile['contract_status_key'] ), $this->tone_for_status( $employee_profile['contract_status_key'] ) ) ); ?>
									<?php endif; ?>
								</div>
							</div>
							<div class="asdl-fin-note-box">
								<?php echo esc_html( $employee_payroll_status_note ); ?>
							</div>
							<div class="asdl-fin-card-grid">
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Fecha de nacimiento</span>
									<strong><?php echo esc_html( $employee_profile['birth_date'] ?? 'Sin registrar' ); ?></strong>
									<p>Referencia basica para recordatorios como cumpleanos y archivo laboral.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Fecha de ingreso</span>
									<strong><?php echo esc_html( $employee_profile['hire_date'] ?? 'Sin registrar' ); ?></strong>
									<p>Inicio practico de la relacion laboral de este empleado.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Tipo de contratacion</span>
									<strong><?php echo esc_html( $this->label_for( 'contract_type', $employee_profile['contract_type'] ?? '' ) ); ?></strong>
									<p>Base para entender si es fijo, por tiempo determinado o por servicios.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Estado de contrato</span>
									<strong><?php echo esc_html( $this->label_for( 'contract_status', $employee_profile['contract_status_key'] ?? '' ) ); ?></strong>
									<p>Lectura automatica segun fechas, vigencia y estado laboral actual.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Tiempo transcurrido</span>
									<strong><?php echo esc_html( $employee_profile['contract_elapsed_label'] ?? 'Sin seguimiento' ); ?></strong>
									<p>Tiempo acumulado desde la fecha laboral o contractual de inicio.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Tiempo restante</span>
									<strong><?php echo esc_html( $employee_profile['contract_remaining_label'] ?? 'No aplica' ); ?></strong>
									<p>Solo aplica cuando el contrato tiene fecha de fin definida.</p>
								</div>
							</div>
							<div class="asdl-fin-meta-grid">
								<div><strong>Estado laboral</strong><span><?php echo esc_html( ! empty( $employee_profile['employment_status'] ) ? $this->label_for( 'employment_status', $employee_profile['employment_status'] ) : 'Sin definir' ); ?></span></div>
								<div><strong>Inicio de contrato</strong><span><?php echo esc_html( $employee_profile['contract_start_date'] ?? 'Sin registrar' ); ?></span></div>
								<div><strong>Fin de contrato</strong><span><?php echo esc_html( $employee_profile['contract_end_date'] ?? 'No aplica' ); ?></span></div>
								<div><strong>Contrato firmado</strong><span>
									<?php if ( ! empty( $employee_profile['contract_attachment_url'] ) ) : ?>
										<a href="<?php echo esc_url( $employee_profile['contract_attachment_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $employee_profile['contract_attachment_label'] ?: 'Ver archivo' ); ?></a>
									<?php else : ?>
										Sin archivo
									<?php endif; ?>
								</span></div>
							</div>
							<?php if ( ! empty( $employee_profile['renewal_required'] ) ) : ?>
								<div class="asdl-fin-note-box">
									<?php echo esc_html( $employee_profile['renewal_message'] ?? 'Este contrato requiere renovacion.' ); ?>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $employee_profile['termination_type'] ) || ! empty( $employee_profile['termination_reason'] ) ) : ?>
								<div class="asdl-fin-note-box">
									<strong>Finalizacion</strong><br />
									<?php echo esc_html( $this->label_for( 'termination_type', $employee_profile['termination_type'] ?? '' ) ); ?>
									<?php echo ! empty( $employee_profile['termination_date'] ) ? esc_html( ' | ' . $employee_profile['termination_date'] ) : ''; ?>
									<?php if ( ! empty( $employee_profile['termination_reason'] ) ) : ?>
										<br />
										<?php echo esc_html( $employee_profile['termination_reason'] ); ?>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</section>
						<section class="asdl-fin-panel">
							<div class="asdl-fin-panel-header">
								<h2>Resumen laboral</h2>
								<p>Lectura rapida de sueldo, frecuencia, fechas de pago y adelantos activos.</p>
								<div class="asdl-fin-badge-group">
									<?php echo wp_kses_post( $this->render_pill( $employee_payroll_ready_ui ? 'Listo para usar nomina' : 'Falta completar nomina', $employee_payroll_ready_ui ? 'success' : 'warning' ) ); ?>
								</div>
							</div>
							<div class="asdl-fin-card-grid">
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Sueldo base</span>
									<strong><?php echo wp_kses_post( $this->format_money( $employee_profile['salary_amount'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Monto base registrado para este empleado.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Frecuencia</span>
									<strong><?php echo esc_html( ! empty( $employee_profile['pay_frequency'] ) ? $this->label_for( 'frequency_key', $employee_profile['pay_frequency'] ) : 'Sin definir' ); ?></strong>
									<p>Periodicidad estimada del pago.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Dia de pago</span>
									<strong><?php echo esc_html( $employee_profile['payday_summary'] ?? 'Sin definir' ); ?></strong>
									<p>Referencia base para calcular la proxima fecha.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Proximo pago</span>
									<strong><?php echo esc_html( $employee_profile['next_payment_date'] ?? 'Sin definir' ); ?></strong>
									<p>Fecha prevista mas cercana segun la configuracion actual.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Ultimo pago</span>
									<strong><?php echo esc_html( $employee_profile['last_payment_date'] ?? 'Sin registro' ); ?></strong>
									<p>Campo base para futura nomina y seguimiento.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Adelantos activos</span>
									<strong><?php echo esc_html( number_format_i18n( (int) ( $salary_advance_summary['active_count'] ?? 0 ) ) ); ?></strong>
									<p>Adelantos de sueldo pendientes por descontar.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Saldo por descontar</span>
									<strong><?php echo wp_kses_post( $this->format_money( $salary_advance_summary['balance_total'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Monto acumulado de adelantos aun no compensados.</p>
								</div>
							</div>
							<?php if ( ! empty( $employee_profile['default_account_id'] ) ) : ?>
								<div class="asdl-fin-note-box">
									Cuenta sugerida para salida de sueldo: <?php echo esc_html( $account_options[ (int) $employee_profile['default_account_id'] ] ?? 'Cuenta #' . (int) $employee_profile['default_account_id'] ); ?>
								</div>
							<?php endif; ?>
						</section>
					</div>
					<section class="asdl-fin-panel asdl-fin-labor-config-panel">
						<details class="asdl-fin-disclosure asdl-fin-labor-config-details" <?php echo $labor_config_open ? 'open' : ''; ?>>
							<summary>
								<div class="asdl-fin-labor-config-summary">
									<div class="asdl-fin-labor-config-summary-copy">
										<strong>Configuracion laboral</strong>
										<small>Deja visible solo el resumen operativo y abre este panel cuando necesites editar contrato, sueldo, frecuencia o fechas base.</small>
									</div>
									<div class="asdl-fin-labor-config-summary-meta">
										<?php echo wp_kses_post( $this->render_pill( $labor_config_ready_ui ? 'Ficha suficiente' : 'Falta completar ficha', $labor_config_ready_ui ? 'success' : 'warning' ) ); ?>
										<span class="asdl-fin-labor-config-summary-list"><?php echo esc_html( $labor_summary_text ); ?></span>
									</div>
								</div>
							</summary>
							<div class="asdl-fin-disclosure-body">
						<?php if ( $readonly_context ) : ?>
							<?php $this->render_fiscal_readonly_action_state( 'La configuracion laboral queda bloqueada en modo consulta.' ); ?>
						<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-employee-profile-form" data-employee-profile-form data-default-account-label="<?php echo esc_attr( ! empty( $employee_profile['default_account_id'] ) ? ( $account_options[ (int) $employee_profile['default_account_id'] ] ?? '' ) : '' ); ?>">
							<input type="hidden" name="action" value="asdl_fin_save_employee_profile" />
							<input type="hidden" name="return_page" value="asdl-fin-contacts" />
							<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
							<?php $this->render_current_fiscal_hidden_input(); ?>
							<?php wp_nonce_field( 'asdl_fin_save_employee_profile' ); ?>
							<label class="asdl-fin-field">
								<span>Estado laboral</span>
								<select name="employment_status" data-employee-status-select>
									<?php echo $this->render_select_options( array( 'active' => 'Activo', 'paused' => 'En pausa', 'ended' => 'Finalizado' ), (string) ( $employee_profile['employment_status'] ?? 'active' ) ); ?>
								</select>
							</label>
							<label class="asdl-fin-field">
								<span>Sueldo base</span>
								<input type="number" name="salary_amount" value="<?php echo esc_attr( $employee_profile['salary_amount'] ?? 0 ); ?>" min="0" step="0.01" required data-employee-salary-amount />
							</label>
							<?php $this->render_currency_select( 'salary_currency', 'Moneda', $employee_profile['salary_currency'] ?? 'USD', true, 'Selecciona una moneda', array( 'data-employee-salary-currency' => '1' ) ); ?>
							<label class="asdl-fin-field">
								<span>Frecuencia de pago</span>
								<select name="pay_frequency" class="asdl-fin-pay-frequency-select" data-employee-frequency-select>
									<?php echo $this->render_select_options( array( 'weekly' => 'Semanal', 'biweekly' => 'Quincenal', 'monthly' => 'Mensual' ), (string) ( $employee_profile['pay_frequency'] ?? 'monthly' ) ); ?>
								</select>
							</label>
							<label class="asdl-fin-field">
								<span>Fecha de nacimiento</span>
								<input type="date" name="birth_date" value="<?php echo esc_attr( $employee_profile['birth_date'] ?? '' ); ?>" />
							</label>
							<label class="asdl-fin-field">
								<span>Fecha de ingreso</span>
								<input type="date" name="hire_date" value="<?php echo esc_attr( $employee_profile['hire_date'] ?? ( $employee_profile['effective_from'] ?? '' ) ); ?>" data-employee-hire-date />
							</label>
							<label class="asdl-fin-field">
								<span>Tipo de contratacion</span>
								<select name="contract_type" data-employee-contract-type-select>
									<option value="">Sin definir</option>
									<?php echo $this->render_select_options( array( 'indefinite' => 'Indeterminado', 'fixed_term' => 'Tiempo determinado', 'temporary' => 'Temporal', 'service_contract' => 'Servicios / honorarios' ), (string) ( $employee_profile['contract_type'] ?? '' ) ); ?>
								</select>
							</label>
							<label class="asdl-fin-field">
								<span>Inicio de contrato</span>
								<input type="date" name="contract_start_date" value="<?php echo esc_attr( $employee_profile['contract_start_date'] ?? ( $employee_profile['hire_date'] ?? '' ) ); ?>" data-employee-contract-start />
							</label>
							<label class="asdl-fin-field" data-employee-contract-end-field>
								<span>Fin de contrato</span>
								<input type="date" name="contract_end_date" value="<?php echo esc_attr( $employee_profile['contract_end_date'] ?? '' ); ?>" data-employee-contract-end-input />
								<small>Si el contrato es por tiempo determinado, esta fecha activa el control de renovacion.</small>
							</label>
							<label class="asdl-fin-field asdl-fin-field-weekday" data-employee-weekday-field>
								<span>Dia de pago</span>
								<select name="payday_value" data-employee-payday-weekday>
									<?php echo $this->render_select_options( $this->weekday_options(), (string) ( $employee_profile['pay_frequency'] ?? 'monthly' ) === 'monthly' ? '1' : (string) ( $employee_profile['payday_value'] ?? 1 ) ); ?>
								</select>
								<small>Para pagos semanales o quincenales, indica el dia base del ciclo.</small>
							</label>
							<label class="asdl-fin-field asdl-fin-field-monthday" data-employee-monthday-field>
								<span>Dia del mes</span>
								<input type="number" name="payday_value_monthly" value="<?php echo esc_attr( (string) ( ! empty( $employee_profile['pay_frequency'] ) && 'monthly' === $employee_profile['pay_frequency'] ? ( $employee_profile['payday_value'] ?? 1 ) : 1 ) ); ?>" min="1" max="31" step="1" data-employee-payday-monthday />
								<small>Para pagos mensuales, indica el dia del mes. Si el mes es mas corto, se toma el ultimo dia disponible.</small>
							</label>
							<label class="asdl-fin-field asdl-fin-field-wide asdl-fin-field-biweekly" data-employee-biweekly-field>
								<span>Fecha ancla quincenal</span>
								<input type="date" name="cycle_anchor_date" value="<?php echo esc_attr( $employee_profile['cycle_anchor_date'] ?? ( $employee_profile['effective_from'] ?? '' ) ); ?>" data-employee-cycle-anchor />
								<small>Marca desde que fecha comienza el ciclo quincenal para calcular la siguiente quincena correctamente.</small>
							</label>
							<label class="asdl-fin-field">
								<span>Inicio de vigencia</span>
								<input type="date" name="effective_from" value="<?php echo esc_attr( $employee_profile['effective_from'] ?? gmdate( 'Y-m-d' ) ); ?>" data-employee-effective-from />
							</label>
							<label class="asdl-fin-field">
								<span>Ultimo pago registrado</span>
								<input type="date" name="last_payment_date" value="<?php echo esc_attr( $employee_profile['last_payment_date'] ?? '' ); ?>" />
							</label>
							<label class="asdl-fin-field" data-employee-next-payment-field>
								<span>Proximo pago (override)</span>
								<input type="date" name="next_payment_date" value="<?php echo esc_attr( $employee_profile['next_payment_date'] ?? '' ); ?>" data-employee-next-payment-input />
								<small>Si lo dejas vacio, Finanzas ASD lo calcula automaticamente segun la frecuencia. Solo usalo para forzar una fecha concreta.</small>
							</label>
							<label class="asdl-fin-field">
								<span>Cuenta sugerida</span>
								<select name="default_account_id" data-employee-default-account-select>
									<option value="">Sin definir</option>
									<?php echo $this->render_select_options( $account_options, (string) ( $employee_profile['default_account_id'] ?? '' ) ); ?>
								</select>
							</label>
							<div class="asdl-fin-field asdl-fin-field-wide">
								<span>Proyeccion automatica</span>
								<div class="asdl-fin-automation-projection" data-employee-profile-projection>
									<div><strong>Frecuencia efectiva</strong><span data-employee-projection-frequency><?php echo esc_html( ! empty( $employee_profile['pay_frequency'] ) ? $this->label_for( 'frequency_key', $employee_profile['pay_frequency'] ) : 'Mensual' ); ?></span></div>
									<div><strong>Proximo pago estimado</strong><span data-employee-projection-next><?php echo esc_html( $employee_profile['next_payment_date'] ?? 'Sin definir' ); ?></span></div>
									<div><strong>Cuenta sugerida</strong><span data-employee-projection-account><?php echo esc_html( ! empty( $employee_profile['default_account_id'] ) ? ( $account_options[ (int) $employee_profile['default_account_id'] ] ?? 'Sin definir' ) : 'Sin definir' ); ?></span></div>
									<div><strong>Estado contractual</strong><span data-employee-projection-contract><?php echo esc_html( $this->label_for( 'contract_status', $employee_profile['contract_status_key'] ?? '' ) ); ?></span></div>
									<div><strong>Elegible para nomina</strong><span data-employee-projection-eligibility><?php echo esc_html( ! empty( $employee_profile['payroll_eligible'] ) ? 'Si' : 'Revisar contrato o estado' ); ?></span></div>
									<div><strong>Sueldo base</strong><span data-employee-projection-salary><?php echo wp_kses_post( $this->format_money( $employee_profile['salary_amount'] ?? 0, $employee_profile['salary_currency'] ?? 'USD' ) ); ?></span></div>
								</div>
								<small data-employee-profile-summary>Finanzas ASD usa esta configuracion para proponer la proxima nomina del empleado. Solo necesitas forzar el proximo pago si el ciclo real se desvio.</small>
							</div>
							<div class="asdl-fin-field asdl-fin-field-wide">
								<span>Contrato firmado</span>
								<?php $contract_picker_id = 'asdl-fin-contract-attachment-' . (int) $contact['id']; ?>
								<input type="hidden" name="contract_attachment_id" id="<?php echo esc_attr( $contract_picker_id ); ?>" value="<?php echo esc_attr( (int) ( $employee_profile['contract_attachment_id'] ?? 0 ) ); ?>" />
								<div class="asdl-fin-inline-actions">
									<button type="button" class="button button-secondary asdl-fin-select-media" data-target-input="<?php echo esc_attr( $contract_picker_id ); ?>" data-target-label="<?php echo esc_attr( $contract_picker_id . '-label' ); ?>" data-target-link="<?php echo esc_attr( $contract_picker_id . '-link' ); ?>" data-frame-title="Seleccionar contrato firmado" data-button-text="Usar este archivo">Seleccionar archivo</button>
									<button type="button" class="button button-secondary asdl-fin-clear-media" data-target-input="<?php echo esc_attr( $contract_picker_id ); ?>" data-target-label="<?php echo esc_attr( $contract_picker_id . '-label' ); ?>" data-target-link="<?php echo esc_attr( $contract_picker_id . '-link' ); ?>" data-empty-label="Sin contrato cargado">Quitar</button>
								</div>
								<div class="asdl-fin-file-meta">
									<strong id="<?php echo esc_attr( $contract_picker_id . '-label' ); ?>"><?php echo esc_html( ! empty( $employee_profile['contract_attachment_label'] ) ? $employee_profile['contract_attachment_label'] : 'Sin contrato cargado' ); ?></strong>
									<a id="<?php echo esc_attr( $contract_picker_id . '-link' ); ?>" href="<?php echo esc_url( $employee_profile['contract_attachment_url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer" <?php echo ! empty( $employee_profile['contract_attachment_url'] ) ? '' : 'hidden'; ?>>Ver archivo</a>
								</div>
								<small>Adjunta el contrato firmado o el documento laboral principal del empleado.</small>
							</div>
							<label class="asdl-fin-field" data-employee-termination-field>
								<span>Motivo de finalizacion</span>
								<select name="termination_type" data-employee-termination-type>
									<option value="">Sin motivo</option>
									<?php echo $this->render_select_options( array( 'resignation' => 'Renuncia', 'dismissal' => 'Despido', 'contract_end' => 'Fin de contrato', 'mutual_agreement' => 'Mutuo acuerdo', 'other' => 'Otro' ), (string) ( $employee_profile['termination_type'] ?? '' ) ); ?>
								</select>
							</label>
							<label class="asdl-fin-field" data-employee-termination-field>
								<span>Fecha de finalizacion</span>
								<input type="date" name="termination_date" value="<?php echo esc_attr( $employee_profile['termination_date'] ?? '' ); ?>" data-employee-termination-date />
							</label>
							<label class="asdl-fin-field asdl-fin-field-wide" data-employee-termination-field>
								<span>Detalle de finalizacion</span>
								<textarea name="termination_reason" rows="3" data-employee-termination-reason><?php echo esc_textarea( $employee_profile['termination_reason'] ?? '' ); ?></textarea>
								<small>Resume la razon si el contrato termino, hubo renuncia, despido o cierre del acuerdo.</small>
							</label>
							<label class="asdl-fin-field asdl-fin-field-wide">
								<span>Notas laborales</span>
								<textarea name="notes" rows="4"><?php echo esc_textarea( $employee_profile['notes'] ?? '' ); ?></textarea>
								<small>Observaciones internas sobre salario, condiciones, renovaciones o acuerdos.</small>
							</label>
							<div class="asdl-fin-field asdl-fin-field-wide">
								<span>Accion</span>
								<div class="asdl-fin-inline-actions">
									<?php submit_button( 'Guardar configuracion laboral', 'primary', 'submit', false ); ?>
									<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=asdl-fin-documents' ) ); ?>">Ir a Movimientos</a>
								</div>
							</div>
						</form>
						<?php endif; ?>
							</div>
						</details>
					</section>
					<div class="asdl-fin-panel-grid">
						<section class="asdl-fin-panel">
							<div class="asdl-fin-panel-header">
								<h2>Adelantos de sueldo</h2>
								<p>Registra anticipos descontables sin duplicar el gasto de nomina.</p>
							</div>
							<div class="asdl-fin-card-grid">
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Registrados</span>
									<strong><?php echo esc_html( number_format_i18n( (int) ( $salary_advance_summary['advance_count'] ?? 0 ) ) ); ?></strong>
									<p>Total de adelantos creados para este empleado.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Total adelantado</span>
									<strong><?php echo wp_kses_post( $this->format_money( $salary_advance_summary['total_amount'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Suma historica de adelantos registrados.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Recuperado</span>
									<strong><?php echo wp_kses_post( $this->format_money( $salary_advance_summary['recovered_amount'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Monto ya descontado o compensado sobre adelantos previos.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Pendiente</span>
									<strong><?php echo wp_kses_post( $this->format_money( $salary_advance_summary['balance_total'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Saldo que queda listo para descontar en la siguiente subfase.</p>
								</div>
							</div>
							<?php $this->render_salary_advances_table( $salary_advances, $account_options, array( 'allow_cancel' => true ) ); ?>
						</section>
						<section class="asdl-fin-panel">
							<div class="asdl-fin-panel-header">
								<h2>Registrar adelanto</h2>
								<p>Carga un adelanto de sueldo y define si quedara pendiente para el proximo pago o bajo gestion manual.</p>
							</div>
							<?php if ( $readonly_context ) : ?>
								<?php $this->render_fiscal_readonly_action_state( 'Los adelantos de sueldo quedan bloqueados en modo consulta.' ); ?>
							<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-salary-advance-form" data-salary-advance-form data-employee-frequency="<?php echo esc_attr( $employee_profile['pay_frequency'] ?? '' ); ?>" data-employee-next-payment="<?php echo esc_attr( $employee_next_payment ); ?>" data-employee-default-account="<?php echo esc_attr( ! empty( $employee_profile['default_account_id'] ) ? ( $account_options[ (int) $employee_profile['default_account_id'] ] ?? '' ) : '' ); ?>" data-employee-default-account-id="<?php echo esc_attr( (string) ( $employee_profile['default_account_id'] ?? '' ) ); ?>" data-employee-currency="<?php echo esc_attr( $employee_salary_currency ); ?>" data-employee-salary-amount="<?php echo esc_attr( (string) $employee_salary_amount ); ?>" data-employee-commitment-preview-total="<?php echo esc_attr( (string) $commitment_preview_total ); ?>">
								<input type="hidden" name="action" value="asdl_fin_save_salary_advance" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<input type="hidden" name="confirm_capacity_override" value="0" data-salary-advance-capacity-confirm />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_save_salary_advance' ); ?>
								<label class="asdl-fin-field">
									<span>Monto del adelanto</span>
									<input type="number" name="total_amount" value="" min="0" step="0.01" required data-salary-advance-amount />
								</label>
								<?php $this->render_currency_select( 'currency', 'Moneda', $employee_profile['salary_currency'] ?? 'USD', true, 'Selecciona una moneda', array( 'data-salary-advance-currency' => '1' ) ); ?>
								<label class="asdl-fin-field">
									<span>Fecha del adelanto</span>
									<input type="date" name="issued_at" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
								</label>
								<label class="asdl-fin-field">
									<span>Recuperacion prevista</span>
									<input type="date" name="expected_recovery_date" value="<?php echo esc_attr( $employee_profile['next_payment_date'] ?? '' ); ?>" data-salary-advance-recovery-date />
									<small>Si la dejas vacia, se intentara usar la siguiente fecha de pago configurada.</small>
								</label>
								<label class="asdl-fin-field">
									<span>Modo de descuento</span>
									<select name="recovery_mode" data-salary-advance-mode>
										<?php echo $this->render_select_options( array( 'next_payroll' => 'Descontar en proximo pago', 'manual' => 'Gestion manual' ), 'next_payroll' ); ?>
									</select>
								</label>
								<label class="asdl-fin-field">
									<span>Cuenta de salida</span>
									<select name="source_account_id" data-salary-advance-account>
										<option value="">Usar cuenta sugerida</option>
										<?php echo $this->render_select_options( $account_options, (string) ( $employee_profile['default_account_id'] ?? '' ) ); ?>
									</select>
								</label>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<span>Proyeccion automatica</span>
									<div class="asdl-fin-automation-projection" data-salary-advance-projection>
										<div><strong>Modo</strong><span data-advance-projection-mode><?php echo esc_html( $this->label_for( 'advance_recovery_mode', 'next_payroll' ) ); ?></span></div>
										<div><strong>Recuperacion estimada</strong><span data-advance-projection-date><?php echo esc_html( $employee_profile['next_payment_date'] ?? 'Sin definir' ); ?></span></div>
										<div><strong>Disponible para recuperar</strong><span data-advance-projection-available><?php echo wp_kses_post( $this->format_money( max( 0, $employee_salary_amount - $commitment_preview_total ), $employee_salary_currency ) ); ?></span></div>
										<div><strong>Entra en proximo pago</strong><span data-advance-projection-now><?php echo wp_kses_post( $this->format_money( 0, $employee_salary_currency ) ); ?></span></div>
										<div><strong>Se movera al siguiente</strong><span data-advance-projection-carry><?php echo wp_kses_post( $this->format_money( 0, $employee_salary_currency ) ); ?></span></div>
										<div><strong>Cuenta sugerida</strong><span data-advance-projection-account><?php echo esc_html( ! empty( $employee_profile['default_account_id'] ) ? ( $account_options[ (int) $employee_profile['default_account_id'] ] ?? 'Sin definir' ) : 'Sin definir' ); ?></span></div>
										<div><strong>Impacto</strong><span data-advance-projection-impact>Se descontara automaticamente en la proxima nomina disponible.</span></div>
									</div>
									<small data-salary-advance-summary>Si eliges descuento en proximo pago, primero se reservan los compromisos activos por sueldo y luego se intenta recuperar este adelanto.</small>
								</div>
								<label class="asdl-fin-field">
									<span>Referencia</span>
									<input type="text" name="reference" value="" />
								</label>
								<label class="asdl-fin-field asdl-fin-field-wide">
									<span>Notas</span>
									<textarea name="notes" rows="4"></textarea>
									<small>Usa este campo para observaciones internas del adelanto o del acuerdo de descuento.</small>
								</label>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<span>Accion</span>
									<div class="asdl-fin-inline-actions">
										<?php submit_button( 'Registrar adelanto', 'primary', 'submit', false ); ?>
										<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=asdl-fin-payments' ) ); ?>">Ver cobros y pagos</a>
									</div>
								</div>
							</form>
							<?php endif; ?>
						</section>
					</div>
					<div class="asdl-fin-panel-grid">
						<section class="asdl-fin-panel">
							<div class="asdl-fin-panel-header">
								<h2>Nomina por periodo</h2>
								<p>Genera la semana, quincena o mes a pagar y deja listo el descuento automatico de adelantos activos.</p>
							</div>
							<div class="asdl-fin-card-grid">
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Periodos</span>
									<strong><?php echo esc_html( number_format_i18n( (int) ( $payroll_summary['period_count'] ?? 0 ) ) ); ?></strong>
									<p>Periodos de nomina generados para este empleado.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Pendientes</span>
									<strong><?php echo esc_html( number_format_i18n( (int) ( $payroll_summary['planned_count'] ?? 0 ) ) ); ?></strong>
									<p>Periodos aun no procesados como pago.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Bruto acumulado</span>
									<strong><?php echo wp_kses_post( $this->format_money( $payroll_summary['gross_total'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Total bruto registrado en periodos de nomina.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Descuento por adelantos</span>
									<strong><?php echo wp_kses_post( $this->format_money( $payroll_summary['advance_deduction_total'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Monto ya compensado por adelantos de sueldo.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Descuento por compromisos</span>
									<strong><?php echo wp_kses_post( $this->format_money( $payroll_summary['commitment_deduction_total'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Monto recuperado desde nomina sobre prestamos, deudas o cargos programados.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Compromisos pagados</span>
									<strong><?php echo wp_kses_post( $this->format_money( $payroll_summary['commitment_payout_total'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Monto adicional pagado por acuerdos o deudas de la empresa con el empleado.</p>
								</div>
								<div class="asdl-fin-card">
									<span class="asdl-fin-label">Neto acumulado</span>
									<strong><?php echo wp_kses_post( $this->format_money( $payroll_summary['net_total'] ?? 0, $employee_profile['salary_currency'] ?? '' ) ); ?></strong>
									<p>Monto efectivamente pagado o previsto despues de descuentos.</p>
								</div>
							</div>
							<?php $this->render_payroll_periods_table( $payroll_periods, $contact, $account_options ); ?>
						</section>
						<section class="asdl-fin-panel">
							<div class="asdl-fin-panel-header">
								<h2>Generar periodo de nomina</h2>
								<p>Define el rango a pagar, la fecha prevista y cualquier deduccion adicional distinta a adelantos.</p>
							</div>
							<?php if ( $readonly_context ) : ?>
								<?php $this->render_fiscal_readonly_action_state( 'La generacion de nomina queda bloqueada en modo consulta.' ); ?>
							<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asdl-fin-form-grid asdl-fin-payroll-period-form" data-payroll-period-form data-payroll-frequency="<?php echo esc_attr( $employee_profile['pay_frequency'] ?? '' ); ?>" data-payroll-payday-summary="<?php echo esc_attr( $employee_profile['payday_summary'] ?? '' ); ?>" data-payroll-next-payment="<?php echo esc_attr( $employee_next_payment ); ?>" data-payroll-default-account="<?php echo esc_attr( ! empty( $employee_profile['default_account_id'] ) ? ( $account_options[ (int) $employee_profile['default_account_id'] ] ?? '' ) : '' ); ?>" data-payroll-currency="<?php echo esc_attr( $employee_salary_currency ); ?>" data-payroll-commitment-preview="<?php echo esc_attr( (string) $commitment_preview_total ); ?>" data-payroll-active-advance-balance="<?php echo esc_attr( (string) $active_advance_balance_total ); ?>">
								<input type="hidden" name="action" value="asdl_fin_save_payroll_period" />
								<input type="hidden" name="return_page" value="asdl-fin-contacts" />
								<input type="hidden" name="contact_id" value="<?php echo esc_attr( (int) $contact['id'] ); ?>" />
								<input type="hidden" name="currency" value="<?php echo esc_attr( $employee_profile['salary_currency'] ?? 'USD' ); ?>" />
								<?php $this->render_current_fiscal_hidden_input(); ?>
								<?php wp_nonce_field( 'asdl_fin_save_payroll_period' ); ?>
								<label class="asdl-fin-field">
									<span>Periodo desde</span>
									<input type="date" name="period_start" value="<?php echo esc_attr( $payroll_defaults['period_start'] ); ?>" data-payroll-period-start />
								</label>
								<label class="asdl-fin-field">
									<span>Periodo hasta</span>
									<input type="date" name="period_end" value="<?php echo esc_attr( $payroll_defaults['period_end'] ); ?>" data-payroll-period-end />
								</label>
								<label class="asdl-fin-field">
									<span>Fecha prevista de pago</span>
									<input type="date" name="scheduled_payment_date" value="<?php echo esc_attr( $payroll_defaults['scheduled_payment_date'] ); ?>" data-payroll-scheduled-date />
								</label>
								<label class="asdl-fin-field">
									<span>Monto bruto</span>
									<input type="number" name="gross_amount" value="<?php echo esc_attr( $employee_profile['salary_amount'] ?? 0 ); ?>" min="0" step="0.01" required data-payroll-gross-amount />
								</label>
								<label class="asdl-fin-field">
									<span>Deduccion adicional</span>
									<input type="number" name="other_deduction_amount" value="0" min="0" step="0.01" data-payroll-other-deduction />
									<small>No incluye adelantos ni compromisos con descuento por sueldo; esos se calculan automaticamente.</small>
								</label>
								<label class="asdl-fin-field">
									<span>Cuenta de pago</span>
									<select name="payment_account_id" data-payroll-account>
										<option value="">Usar cuenta sugerida</option>
										<?php echo $this->render_select_options( $account_options, (string) ( $employee_profile['default_account_id'] ?? '' ) ); ?>
									</select>
								</label>
								<?php $this->render_payment_method_select( 'payment_method_key', 'Metodo', 'bank_transfer', true ); ?>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<span>Proyeccion automatica</span>
									<div class="asdl-fin-automation-projection" data-payroll-projection>
										<div><strong>Frecuencia</strong><span data-payroll-projection-frequency><?php echo esc_html( ! empty( $employee_profile['pay_frequency'] ) ? $this->label_for( 'frequency_key', $employee_profile['pay_frequency'] ) : 'Sin definir' ); ?></span></div>
										<div><strong>Periodo propuesto</strong><span data-payroll-projection-window><?php echo esc_html( $payroll_defaults['period_start'] . ' al ' . $payroll_defaults['period_end'] ); ?></span></div>
										<div><strong>Pago previsto</strong><span data-payroll-projection-date><?php echo esc_html( $payroll_defaults['scheduled_payment_date'] ); ?></span></div>
										<div><strong>Bruto base</strong><span data-payroll-projection-gross><?php echo wp_kses_post( $this->format_money( $employee_profile['salary_amount'] ?? 0, $employee_profile['salary_currency'] ?? 'USD' ) ); ?></span></div>
										<div><strong>Deduccion manual</strong><span data-payroll-projection-deduction><?php echo wp_kses_post( $this->format_money( 0, $employee_profile['salary_currency'] ?? 'USD' ) ); ?></span></div>
										<div><strong>Compromisos por sueldo</strong><span data-payroll-projection-commitments><?php echo wp_kses_post( $this->format_money( $commitment_preview_total, $employee_salary_currency ) ); ?></span></div>
										<div><strong>Adelantos que entran</strong><span data-payroll-projection-advances><?php echo wp_kses_post( $this->format_money( min( $active_advance_balance_total, max( 0, $employee_salary_amount - $commitment_preview_total ) ), $employee_salary_currency ) ); ?></span></div>
										<div><strong>Adelantos que pasan al siguiente</strong><span data-payroll-projection-advance-carry><?php echo wp_kses_post( $this->format_money( max( 0, $active_advance_balance_total - max( 0, $employee_salary_amount - $commitment_preview_total ) ), $employee_salary_currency ) ); ?></span></div>
										<div><strong>Cuenta de salida</strong><span data-payroll-projection-account><?php echo esc_html( ! empty( $employee_profile['default_account_id'] ) ? ( $account_options[ (int) $employee_profile['default_account_id'] ] ?? 'Sin definir' ) : 'Sin definir' ); ?></span></div>
									</div>
									<small data-payroll-projection-summary>Primero se descuentan los compromisos por sueldo y luego se recuperan los adelantos activos hasta donde alcance este período.</small>
								</div>
								<label class="asdl-fin-field asdl-fin-field-wide">
									<span>Notas</span>
									<textarea name="notes" rows="4"></textarea>
									<small>Este periodo tomara automaticamente adelantos activos y compromisos configurados para descontarse por sueldo.</small>
								</label>
								<div class="asdl-fin-field asdl-fin-field-wide">
									<span>Accion</span>
									<div class="asdl-fin-inline-actions">
										<?php submit_button( 'Generar periodo', 'primary', 'submit', false ); ?>
										<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=asdl-fin-payments' ) ); ?>">Ver pagos</a>
									</div>
								</div>
							</form>
							<?php endif; ?>
						</section>
					</div>
					<section class="asdl-fin-panel">
						<div class="asdl-fin-panel-header">
							<h2>Siguiente capa</h2>
							<p>Con esto ya queda operativa la nomina basica por periodo y la compensacion de adelantos.</p>
						</div>
						<div class="asdl-fin-empty">
							<strong>Base lista para pruebas reales.</strong>
							<p>La siguiente iteracion ya puede enfocarse en avisos, vencimientos, calendarios y pulido de flujo segun las pruebas que hagas al reinstalar.</p>
						</div>
					</section>
				</section>
			<?php endif; ?>
		</section>
		<?php
	}

	private function label_for( $group, $value ) {
		$labels = array(
			'account_type' => array(
				'operating'   => 'Operativa',
				'cost_center' => 'Centro operativo',
				'cash'        => 'Caja',
				'loan'        => 'Prestamo',
				'wallet'      => 'Fondo',
			),
			'contact_type' => array(
				'client'   => 'Cliente',
				'supplier' => 'Proveedor',
				'employee' => 'Empleado',
				'mixed'    => 'Mixto',
			),
			'supplier_kind' => array(
				'general'  => 'Por clasificar',
				'services' => 'Servicios',
				'products' => 'Productos',
				'mixed'    => 'Mixto',
			),
			'profile_origin' => array(
				'wp_user'  => 'Usuario WP',
				'external' => 'Externo',
			),
				'document_type' => array(
					'woo_sale'         => 'Venta de tienda',
					'external_expense' => 'Gasto externo',
					'service_expense'  => 'Servicio',
					'salary_expense'   => 'Sueldo',
					'adjustment'       => 'Ajuste',
					'loan_receivable'  => 'Prestamo por cobrar',
					'loan_payable'     => 'Prestamo por pagar',
					'manual_document'  => 'Movimiento manual',
				),
			'financial_status' => array(
				'draft'  => 'Borrador',
				'posted' => 'Emitido',
				'void'   => 'Anulado',
			),
			'payment_status' => array(
				'unpaid'  => 'Pendiente',
				'pending' => 'Pendiente',
				'partial' => 'Abonado',
				'paid'    => 'Pagado',
				'overdue' => 'Vencido',
			),
			'payment_type' => array(
				'collection'   => 'Cobro',
				'disbursement' => 'Pago',
				'adjustment'   => 'Ajuste',
			),
			'payment_record_status' => array(
				'draft'  => 'Borrador',
				'posted' => 'Registrado',
				'void'   => 'Anulado',
			),
			'plan_type' => array(
				'loan'        => 'Prestamo',
				'installment' => 'Compromiso',
			),
			'commitment_origin' => array(
				'loan'         => 'Prestamo',
				'store_debt'   => 'Deuda de tienda',
				'manual_charge'=> 'Cargo manual',
				'company_debt' => 'Deuda de la empresa',
			),
			'settlement_direction' => array(
				'receivable' => 'El perfil paga a la empresa',
				'payable'    => 'La empresa paga al perfil',
			),
			'collection_mode' => array(
				'manual'            => 'Gestion manual',
				'payroll_deduction' => 'Descuento por sueldo',
				'payroll_disbursement' => 'Pago por nomina',
				'mixed'             => 'Mixto',
			),
			'frequency_key' => array(
				'weekly'    => 'Semanal',
				'biweekly'  => 'Quincenal',
				'monthly'   => 'Mensual',
				'quarterly' => 'Trimestral',
			),
				'status' => array(
					'active'   => 'Activo',
					'inactive' => 'Inactivo',
					'closed'   => 'Cerrado',
					'paused'   => 'En pausa',
					'cancelled'=> 'Anulado',
				),
			'employment_status' => array(
				'active' => 'Activo',
				'paused' => 'En pausa',
				'ended'  => 'Finalizado',
			),
			'contract_type' => array(
				'indefinite'       => 'Indeterminado',
				'fixed_term'       => 'Tiempo determinado',
				'temporary'        => 'Temporal',
				'service_contract' => 'Servicios / honorarios',
			),
			'contract_status' => array(
				'active'         => 'Activo',
				'renewal_due'    => 'Por renovar',
				'expired'        => 'Vencido',
				'ended'          => 'Finalizado',
				'not_configured' => 'Sin contrato',
			),
			'termination_type' => array(
				'resignation'      => 'Renuncia',
				'dismissal'        => 'Despido',
				'contract_end'     => 'Fin de contrato',
				'mutual_agreement' => 'Mutuo acuerdo',
				'other'            => 'Otro',
			),
			'advance_status' => array(
				'active'    => 'Activo',
				'partial'   => 'Parcialmente descontado',
				'settled'   => 'Compensado',
				'cancelled' => 'Anulado',
			),
			'advance_recovery_mode' => array(
				'next_payroll' => 'Proximo pago',
				'manual'       => 'Gestion manual',
			),
			'payroll_status' => array(
				'planned'   => 'Pendiente',
				'paid'      => 'Pagado',
				'cancelled' => 'Anulado',
			),
			'provider' => array(
				'woocommerce' => 'WooCommerce',
				'openpos'     => 'OpenPOS',
				'api'         => 'API propia',
			),
			'scope_type' => array(
				'source'   => 'Origen / documento',
				'document' => 'Origen / documento',
				'contact'  => 'Perfil',
				'account'  => 'Cuenta',
				'category' => 'Categoria',
			),
			'source_type' => array(
				'manual'      => 'Manual',
				'woocommerce' => 'WooCommerce',
				'openpos'     => 'OpenPOS',
				'external'    => 'Externo',
				'api'         => 'API propia',
			),
			'financial_intent' => array(
				'income'               => 'Ingreso',
				'expense'              => 'Egreso',
				'salary'               => 'Sueldo',
				'service'              => 'Servicio',
				'adjustment'           => 'Ajuste',
				'internal_consumption' => 'Consumo interno',
				'loan'                 => 'Prestamo',
				'neutral'              => 'Neutral',
			),
			'balance_nature' => array(
				'receivable' => 'Por cobrar',
				'payable'    => 'Por pagar',
				'neutral'    => 'Neutro',
			),
		);

		$value = sanitize_key( (string) $value );

		if ( isset( $labels[ $group ][ $value ] ) ) {
			return $labels[ $group ][ $value ];
		}

		return '' !== $value ? ucwords( str_replace( '_', ' ', $value ) ) : 'Sin definir';
	}

	private function render_select_options( array $options, $selected_value ) {
		$html = '';

		foreach ( $options as $value => $label ) {
			$html .= sprintf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $selected_value, (string) $value, false ),
				esc_html( $label )
			);
		}

		return $html;
	}

	private function payment_method_label( $method_key ) {
		return ( new PaymentMethodsService() )->label( $method_key );
	}

	private function payment_record_type_label( array $payment ) {
		$method_key = sanitize_key( (string) ( $payment['method_key'] ?? '' ) );
		if ( 'salary_advance' === $method_key ) {
			return 'Adelanto';
		}

		return $this->label_for( 'payment_type', $payment['payment_type'] ?? '' );
	}

	private function render_payment_available_value( array $payment, array $payment_meta = array() ) {
		$available_amount = (float) ( $payment['available_amount'] ?? 0 );
		$method_key       = sanitize_key( (string) ( $payment['method_key'] ?? '' ) );

		if ( 'salary_advance' === $method_key ) {
			return sprintf(
				'<div class="asdl-fin-stack"><strong>%1$s</strong><small>Por recuperar por sueldo</small></div>',
				wp_kses_post( $this->format_money( $available_amount, $payment['currency'] ?? '' ) )
			);
		}

		if ( $this->is_dual_discount_child_payment( $payment, $payment_meta ) ) {
			return sprintf(
				'<div class="asdl-fin-stack"><strong>%1$s</strong><small>No disponible; ajuste tecnico del descuento</small></div>',
				wp_kses_post( $this->format_money( 0, $payment['currency'] ?? '' ) )
			);
		}

		return $this->format_money( $available_amount, $payment['currency'] ?? '' );
	}

	private function render_payment_type_value( array $payment, array $payment_meta = array() ) {
		$parts = array(
			'<div class="asdl-fin-stack">',
			'<strong>' . esc_html( $this->payment_record_type_label( $payment ) ) . '</strong>',
		);

		if ( $this->is_dual_discount_parent_payment( $payment, $payment_meta ) ) {
			$parts[] = wp_kses_post( $this->render_pill( 'Precio dual', 'warning' ) );
			$parts[] = '<small>Abono aplicado con descuento automatico por pago en divisa.</small>';
		} elseif ( $this->is_dual_discount_child_payment( $payment, $payment_meta ) ) {
			$parts[] = wp_kses_post( $this->render_pill( 'Ajuste dual', 'neutral' ) );
			$parts[] = '<small>Movimiento tecnico del descuento concedido al abono principal.</small>';
		}

		$parts[] = '</div>';

		return implode( '', $parts );
	}

	private function render_payment_method_value( array $payment, array $payment_meta = array() ) {
		$method_label = $this->payment_method_label( $payment['method_key'] ?? '' );
		$parts        = array(
			'<div class="asdl-fin-stack">',
			'<strong>' . esc_html( $method_label ) . '</strong>',
		);

		if ( $this->is_dual_discount_parent_payment( $payment, $payment_meta ) ) {
			$order_count = $this->dual_discount_order_count( $payment_meta );
			$parts[]     = sprintf(
				'<small>Pedidos gestionados: %s</small>',
				esc_html( number_format_i18n( $order_count ) )
			);
		} elseif ( $this->is_dual_discount_child_payment( $payment, $payment_meta ) ) {
			$parent_number = $this->linked_payment_number( (int) ( $payment_meta['dual_discount_parent_payment_id'] ?? 0 ) );
			$parts[]       = sprintf(
				'<small>Ligado al abono %s</small>',
				esc_html( '' !== $parent_number ? $parent_number : '#' . (int) ( $payment_meta['dual_discount_parent_payment_id'] ?? 0 ) )
			);
		}

		$parts[] = '</div>';

		return implode( '', $parts );
	}

	private function render_payment_amount_value( array $payment, array $payment_meta = array() ) {
		$currency = $payment['currency'] ?? '';
		$parts    = array(
			'<div class="asdl-fin-stack">',
			'<strong>' . wp_kses_post( $this->format_money( $payment['total'] ?? 0, $currency ) ) . '</strong>',
		);

		if ( $this->is_dual_discount_parent_payment( $payment, $payment_meta ) ) {
			$discount_total = (float) ( $payment_meta['dual_discount_total'] ?? 0 );
			$discount_label = wp_strip_all_tags( $this->format_money( $discount_total, $currency ) );
			$discount_pct   = (float) ( $payment_meta['dual_discount_percent'] ?? 0 );
			$parts[]        = sprintf(
				'<small>Descuento concedido: %1$s (%2$s%%)</small>',
				esc_html( $discount_label ),
				esc_html( number_format_i18n( $discount_pct, 2 ) )
			);
		} elseif ( $this->is_dual_discount_child_payment( $payment, $payment_meta ) ) {
			$parts[] = '<small>Descuento tecnico asignado a pedidos Woo/OpenPOS.</small>';
		}

		$parts[] = '</div>';

		return implode( '', $parts );
	}

	private function payment_meta( array $payment ) {
		$meta = json_decode( (string) ( $payment['meta_json'] ?? '' ), true );

		return is_array( $meta ) ? $meta : array();
	}

	private function resolve_order_payment_status_for_ui( array $order ) {
		$currency = sanitize_text_field( (string) ( $order['currency'] ?? '' ) );
		$balance  = MoneyStateService::normalize_balance( (float) ( $order['effective_due_total'] ?? 0 ), $currency );
		$paid     = (float) ( $order['effective_paid_total'] ?? 0 );
		$status   = sanitize_key( (string) ( $order['document_payment_status'] ?? '' ) );

		if ( MoneyStateService::balance_is_zero( $balance, $currency ) ) {
			return 'paid';
		}

		if ( $paid > 0 ) {
			return 'partial';
		}

		return '' !== $status ? $status : 'pending';
	}

	private function resolve_document_payment_status_for_ui( array $document ) {
		$currency = sanitize_text_field( (string) ( $document['currency'] ?? '' ) );
		$balance  = MoneyStateService::normalize_balance( (float) ( $document['balance'] ?? 0 ), $currency );
		$paid     = (float) ( $document['paid_total'] ?? 0 );
		$status   = sanitize_key( (string) ( $document['payment_status'] ?? '' ) );

		if ( MoneyStateService::balance_is_zero( $balance, $currency ) ) {
			return 'paid';
		}

		if ( $paid > 0 ) {
			return 'partial';
		}

		return '' !== $status ? $status : 'pending';
	}

	private function is_dual_discount_parent_payment( array $payment, array $payment_meta = array() ) {
		if ( empty( $payment_meta ) ) {
			$payment_meta = $this->payment_meta( $payment );
		}

		return ! empty( $payment_meta['dual_discount_mode'] ) && (float) ( $payment_meta['dual_discount_total'] ?? 0 ) > 0;
	}

	private function is_dual_discount_child_payment( array $payment, array $payment_meta = array() ) {
		if ( empty( $payment_meta ) ) {
			$payment_meta = $this->payment_meta( $payment );
		}

		$method_key = sanitize_key( (string) ( $payment['method_key'] ?? '' ) );

		return 'dual_price_discount' === $method_key || ! empty( $payment_meta['dual_discount_parent_payment_id'] );
	}

	private function dual_discount_order_count( array $payment_meta ) {
		$order_ids = array_filter(
			array_map(
				'absint',
				(array) ( $payment_meta['dual_discount_order_ids'] ?? array() )
			)
		);

		return count( array_unique( $order_ids ) );
	}

	private function linked_payment_number( $payment_id ) {
		static $cache = array();

		$payment_id = absint( $payment_id );
		if ( $payment_id <= 0 ) {
			return '';
		}

		if ( array_key_exists( $payment_id, $cache ) ) {
			return $cache[ $payment_id ];
		}

		$payment = ( new PaymentsRepository() )->find( $payment_id );
		$cache[ $payment_id ] = ! empty( $payment['payment_number'] ) ? (string) $payment['payment_number'] : '';

		return $cache[ $payment_id ];
	}

	private function render_rule_conditions_summary( array $conditions ) {
		if ( empty( $conditions ) ) {
			return '<span>Sin condiciones especificas</span>';
		}

		$lines = array();

		foreach ( $conditions as $key => $value ) {
			$lines[] = sprintf(
				'<small><strong>%1$s:</strong> %2$s</small>',
				esc_html( $this->rule_condition_label( $key ) ),
				esc_html( $this->rule_condition_value( $key, $value ) )
			);
		}

		return '<div class="asdl-fin-stack">' . implode( '', $lines ) . '</div>';
	}

	private function render_rule_actions_summary( array $actions ) {
		$lines = array();

		foreach ( $actions as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}

			$lines[] = sprintf(
				'<small><strong>%1$s:</strong> %2$s</small>',
				esc_html( $this->rule_action_label( $key ) ),
				esc_html( $this->rule_condition_value( $key, $value ) )
			);
		}

		return empty( $lines ) ? '<span>Sin acciones</span>' : '<div class="asdl-fin-stack">' . implode( '', $lines ) . '</div>';
	}

	private function classification_trace_label( array $trace ) {
		$mode = sanitize_key( $trace['mode'] ?? 'fallback' );

		if ( 'manual_override' === $mode ) {
			return 'Gestion manual';
		}

		if ( 'rule' === $mode ) {
			return 'Regla automatica';
		}

		return 'Fallback del sistema';
	}

	private function classification_trace_detail( array $trace ) {
		$mode = sanitize_key( $trace['mode'] ?? 'fallback' );

		if ( 'rule' === $mode && ! empty( $trace['matched_rule']['rule_name'] ) ) {
			return 'Regla aplicada: ' . $trace['matched_rule']['rule_name'];
		}

		if ( 'manual_override' === $mode ) {
			return 'La clasificacion de este movimiento esta fijada manualmente.';
		}

		return 'Se aplico el criterio base segun el tipo y el origen del movimiento.';
	}

	private function rule_condition_label( $key ) {
		$labels = array(
			'document_type'               => 'Tipo',
			'source_type'                 => 'Origen',
			'contact_type'                => 'Rol base del perfil',
			'contact_id'                  => 'Perfil',
			'account_type'                => 'Tipo de cuenta',
			'account_id'                  => 'Cuenta',
			'category_key'                => 'Categoria',
			'operational_status'          => 'Estado operativo',
			'financial_intent'            => 'Intencion actual',
			'external_reference_contains' => 'Referencia contiene',
			'title_contains'              => 'Titulo contiene',
			'balance_nature'              => 'Naturaleza',
			'subcategory_key'             => 'Subcategoria',
		);

		return $labels[ $key ] ?? ucwords( str_replace( '_', ' ', (string) $key ) );
	}

	private function rule_action_label( $key ) {
		$labels = array(
			'financial_intent' => 'Nueva intencion',
			'balance_nature'   => 'Nueva naturaleza',
			'category_key'     => 'Nueva categoria',
			'subcategory_key'  => 'Nueva subcategoria',
		);

		return $labels[ $key ] ?? $this->rule_condition_label( $key );
	}

	private function rule_condition_value( $key, $value ) {
		switch ( $key ) {
			case 'document_type':
				return $this->label_for( 'document_type', $value );
			case 'source_type':
				return $this->label_for( 'source_type', $value );
			case 'contact_type':
				return $this->label_for( 'contact_type', $value );
			case 'account_type':
				return $this->label_for( 'account_type', $value );
			case 'financial_intent':
				return $this->label_for( 'financial_intent', $value );
			case 'balance_nature':
				return $this->label_for( 'balance_nature', $value );
			default:
				return (string) $value;
		}
	}

	private function decode_meta_json( $meta_json ) {
		$decoded = json_decode( (string) $meta_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function render_pill( $label, $tone = 'neutral' ) {
		return sprintf(
			'<span class="asdl-fin-pill asdl-fin-pill-%1$s">%2$s</span>',
			esc_attr( $tone ),
			esc_html( $label )
		);
	}

	private function tone_for_status( $value ) {
		$value = sanitize_key( (string) $value );

		if ( in_array( $value, array( 'paid', 'posted', 'active', 'registered', 'activa', 'vinculada', 'completed', 'settled' ), true ) ) {
			return 'success';
		}

		if ( in_array( $value, array( 'partial', 'pending', 'draft', 'paused', 'disponible', 'processing', 'on-hold', 'planned', 'renewal_due' ), true ) ) {
			return 'warning';
		}

		if ( in_array( $value, array( 'overdue', 'void', 'inactive', 'no_detectado', 'cancelled', 'expired' ), true ) ) {
			return 'danger';
		}

		return 'neutral';
	}

	private function route_url_from_signature( $signature ) {
		$parts = preg_split( '/\s+/', trim( (string) $signature ) );
		$path  = end( $parts );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		return home_url( $path );
	}

	private function contact_detail_url( $contact_id ) {
		return $this->with_current_context_url( add_query_arg(
			array(
				'page'       => 'asdl-fin-contacts',
				'contact_id' => absint( $contact_id ),
			),
			admin_url( 'admin.php' )
		) );
	}

	private function contact_section_url( $contact_id, $section = '' ) {
		$url = $this->contact_detail_url( $contact_id );
		$section = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $section );

		if ( '' === $section ) {
			return $url;
		}

		return $url . '#' . $section;
	}

	private function document_page_slug_for_type( $document_type ) {
		$document_type = sanitize_key( (string) $document_type );

		if ( 'service_expense' === $document_type ) {
			return 'asdl-fin-services';
		}

		if ( 'external_expense' === $document_type ) {
			return 'asdl-fin-expenses';
		}

		return 'asdl-fin-documents';
	}

	private function is_internal_expense_document( array $document ) {
		return 'internal_consumption' === sanitize_key( (string) ( $document['financial_intent'] ?? '' ) )
			&& in_array( sanitize_key( (string) ( $document['subcategory_key'] ?? '' ) ), array( 'internal_expense', 'internal_gift' ), true );
	}

	private function is_expense_document( array $document ) {
		return 'external_expense' === sanitize_key( (string) ( $document['document_type'] ?? '' ) )
			|| $this->is_internal_expense_document( $document );
	}

	private function expense_origin_label_for_document( array $document ) {
		if ( $this->is_internal_expense_document( $document ) ) {
			return 'internal_gift' === sanitize_key( (string) ( $document['subcategory_key'] ?? '' ) )
				? 'Regalo interno'
				: 'Consumo interno';
		}

		if ( 'external_expense' === sanitize_key( (string) ( $document['document_type'] ?? '' ) ) ) {
			return 'Gasto externo';
		}

		return $this->label_for( 'document_type', $document['document_type'] ?? '' );
	}

	private function document_page_slug_for_document( array $document ) {
		if ( $this->is_expense_document( $document ) ) {
			return 'asdl-fin-expenses';
		}

		return $this->document_page_slug_for_type( $document['document_type'] ?? '' );
	}

	private function document_detail_url( $document_id, $page = 'asdl-fin-documents' ) {
		return $this->with_current_context_url( add_query_arg(
			array(
				'page'        => sanitize_key( (string) $page ),
				'document_id' => absint( $document_id ),
			),
			admin_url( 'admin.php' )
		) );
	}

	private function pending_collection_status_label( $entity_type, $status ) {
		$entity_type = sanitize_key( (string) $entity_type );
		$raw_status  = sanitize_text_field( (string) $status );
		$status      = sanitize_key( $raw_status );

		if ( '' === $status ) {
			return '';
		}

		switch ( $entity_type ) {
			case 'document':
				return $this->label_for( 'payment_status', $status );
			case 'installment_plan':
				return $this->label_for( 'status', $status );
			case 'salary_advance':
				return $this->label_for( 'advance_status', $status );
			default:
				return $raw_status;
		}
	}

	private function current_page_slug() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'asdl-fin-settings';

		return 0 === strpos( $page, 'asdl-fin' ) ? $page : 'asdl-fin-settings';
	}

	private function current_contact_id() {
		return absint( wp_unslash( $_GET['contact_id'] ?? 0 ) );
	}

	private function receipt_url( $receipt_type, $entity_id, array $extra_args = array() ) {
		return $this->with_current_context_url( add_query_arg(
			array_merge(
				array(
					'page'         => 'asdl-fin-receipt',
					'receipt_type' => sanitize_key( $receipt_type ),
					'entity_id'    => absint( $entity_id ),
				),
				$extra_args
			),
			admin_url( 'admin.php' )
		) );
	}

	private function rule_toggle_url( $rule_id, $activate ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'asdl_fin_toggle_rule',
					'rule_id'   => absint( $rule_id ),
					'is_active' => $activate ? 1 : 0,
				),
				admin_url( 'admin-post.php' )
			),
			'asdl_fin_toggle_rule_' . absint( $rule_id )
		);
	}
}
