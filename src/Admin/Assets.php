<?php

namespace ASDLabs\Finance\Admin;

use ASDLabs\Finance\Core\Contracts\Module;
use ASDLabs\Finance\Finance\PaymentMethodsService;
use ASDLabs\Finance\Integrations\Approvals\ApprovalBridge;
use ASDLabs\Finance\Integrations\Woo\DualPricingService;

final class Assets implements Module {
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$fiscal_year = isset( $_GET['fiscal_year'] ) ? absint( wp_unslash( $_GET['fiscal_year'] ) ) : 0;
		$dual_pricing = new DualPricingService();
		$payment_methods = new PaymentMethodsService();
		$approvals = new ApprovalBridge();
		$dual_snapshot = $dual_pricing->get_frontend_snapshot();

		if ( 0 !== strpos( $page, 'asdl-fin' ) ) {
			return;
		}

		wp_enqueue_style(
			'asdl-finance-admin',
			ASDL_FINANCE_URL . 'assets/css/finance-admin.css',
			array(),
			ASDL_FINANCE_VERSION
		);

		$script_dependencies = array();
		if ( $approvals->plugin_available() && function_exists( 'asdl_oa_enqueue_admin_bridge' ) ) {
			asdl_oa_enqueue_admin_bridge();
			$script_dependencies[] = 'asdl-oa-admin-bridge';
		}

		wp_enqueue_script(
			'asdl-finance-admin',
			ASDL_FINANCE_URL . 'assets/js/finance-admin.js',
			$script_dependencies,
			ASDL_FINANCE_VERSION,
			true
		);

		wp_localize_script(
			'asdl-finance-admin',
			'ASDLFinanceAdmin',
			array(
				'restBase'     => esc_url_raw( rest_url( 'asdl-fin/v1/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'      => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'adminPostUrl' => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'contactsPage' => esc_url_raw(
					add_query_arg(
						array_filter(
							array(
								'page'        => 'asdl-fin-contacts',
								'fiscal_year' => $fiscal_year > 0 ? $fiscal_year : null,
							),
							static function ( $value ) {
								return null !== $value && '' !== $value;
							}
						),
						admin_url( 'admin.php' )
					)
				),
				'currentFiscalYear' => $fiscal_year > 0 ? $fiscal_year : null,
				'dualPricing' => $dual_snapshot,
				'paymentMethods' => array(
					'defaultLabels' => $payment_methods->default_method_labels(),
					'aliasMap'      => $payment_methods->default_alias_map(),
				),
				'operationalApprovals' => array(
					'available' => $approvals->plugin_available(),
					'actions'   => $approvals->action_keys(),
				),
				'runtimeNonces' => array(
					'adminRuntime'   => wp_create_nonce( 'asdl_fin_admin_runtime' ),
					'dashboard'      => wp_create_nonce( 'asdl_fin_dashboard_runtime' ),
					'contactDetail'  => wp_create_nonce( 'asdl_fin_contact_detail_runtime' ),
					'receivableDetail' => wp_create_nonce( 'asdl_fin_receivable_group_detail' ),
					'balanceAudit'   => wp_create_nonce( 'asdl_fin_balance_audit' ),
					'masterReportStart' => wp_create_nonce( 'asdl_fin_master_report_start' ),
					'masterReportContinue' => wp_create_nonce( 'asdl_fin_master_report_continue' ),
					'masterReportStatus' => wp_create_nonce( 'asdl_fin_master_report_status' ),
					'masterReportResult' => wp_create_nonce( 'asdl_fin_master_report_result' ),
					'productMarginStart' => wp_create_nonce( 'asdl_fin_product_margin_check_start' ),
					'productMarginContinue' => wp_create_nonce( 'asdl_fin_product_margin_check_continue' ),
					'productMarginStatus' => wp_create_nonce( 'asdl_fin_product_margin_check_status' ),
					'productMarginResult' => wp_create_nonce( 'asdl_fin_product_margin_check_result' ),
					'productMarginUpdateCost' => wp_create_nonce( 'asdl_fin_product_margin_update_cost' ),
					'productMarginDiscardRow' => wp_create_nonce( 'asdl_fin_product_margin_discard_row' ),
					'productMarginReinstateRow' => wp_create_nonce( 'asdl_fin_product_margin_reinstate_row' ),
					'productMarginDiscardNoStock' => wp_create_nonce( 'asdl_fin_product_margin_discard_no_stock_visible' ),
					'historicalIndexStatus' => wp_create_nonce( 'asdl_fin_historical_index_status' ),
					'historicalIndexStart'  => wp_create_nonce( 'asdl_fin_historical_index_start' ),
					'historicalIndexContinue' => wp_create_nonce( 'asdl_fin_historical_index_continue' ),
					'historicalIndexDiagnostics' => wp_create_nonce( 'asdl_fin_historical_index_status' ),
					'historicalRollups'     => wp_create_nonce( 'asdl_fin_historical_index_rollups' ),
					'historicalCompact'     => wp_create_nonce( 'asdl_fin_historical_index_compact' ),
					'historicalResolutionPreview' => wp_create_nonce( 'asdl_fin_historical_resolution_preview' ),
					'historicalResolutionStart'   => wp_create_nonce( 'asdl_fin_historical_resolution_start' ),
					'historicalResolutionContinue'=> wp_create_nonce( 'asdl_fin_historical_resolution_continue' ),
					'historicalResolutionStatus'  => wp_create_nonce( 'asdl_fin_historical_resolution_status' ),
					'historicalResolutionBatchDetail' => wp_create_nonce( 'asdl_fin_historical_resolution_batch_detail' ),
					'orderSettlementPreview' => wp_create_nonce( 'asdl_fin_order_settlement_preview' ),
					'orderSettlementStart'   => wp_create_nonce( 'asdl_fin_order_settlement_start' ),
					'orderSettlementContinue'=> wp_create_nonce( 'asdl_fin_order_settlement_continue' ),
					'orderSettlementStatus'  => wp_create_nonce( 'asdl_fin_order_settlement_status' ),
					'orderSettlementResult'  => wp_create_nonce( 'asdl_fin_order_settlement_result' ),
					'orderSettlementTrace'   => wp_create_nonce( 'asdl_fin_order_settlement_trace' ),
					'orderAssumptionPreview' => wp_create_nonce( 'asdl_fin_order_assumption_preview' ),
					'orderAssumptionStart'   => wp_create_nonce( 'asdl_fin_order_assumption_start' ),
					'orderAssumptionContinue'=> wp_create_nonce( 'asdl_fin_order_assumption_continue' ),
					'orderAssumptionStatus'  => wp_create_nonce( 'asdl_fin_order_assumption_status' ),
					'orderAssumptionResult'  => wp_create_nonce( 'asdl_fin_order_assumption_result' ),
					'orderAssumptionReverseItem' => wp_create_nonce( 'asdl_fin_order_assumption_reverse_item' ),
					'orderAssumptionReverseBatch'=> wp_create_nonce( 'asdl_fin_order_assumption_reverse_batch' ),
				),
				'actionNonces' => array(
					'deleteContact'  => wp_create_nonce( 'asdl_fin_delete_contact' ),
					'promoteContact' => wp_create_nonce( 'asdl_fin_promote_contact_to_user' ),
					'searchWpUsers'  => wp_create_nonce( 'asdl_fin_search_wp_users' ),
					'savePaymentMethodInline' => wp_create_nonce( 'asdl_fin_save_payment_method_inline' ),
					'saveCurrencyInline' => wp_create_nonce( 'asdl_fin_save_currency_inline' ),
					'dualPricingSnapshot' => wp_create_nonce( 'asdl_fin_dual_pricing_snapshot' ),
				),
			)
		);

		if ( in_array( $page, array( 'asdl-fin-settings', 'asdl-fin-contacts' ), true ) ) {
			wp_enqueue_media();
		}
	}
}
