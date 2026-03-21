<?php

namespace ASDLabs\Finance\Admin;

use ASDLabs\Finance\Core\Contracts\Module;
use ASDLabs\Finance\Legacy\AnalysisModule;

final class Assets implements Module {
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$fiscal_year = isset( $_GET['fiscal_year'] ) ? absint( wp_unslash( $_GET['fiscal_year'] ) ) : 0;

		if ( 0 !== strpos( $page, 'asdl-fin' ) ) {
			return;
		}

		wp_enqueue_style(
			'asdl-finance-admin',
			ASDL_FINANCE_URL . 'assets/css/finance-admin.css',
			array(),
			ASDL_FINANCE_VERSION
		);

		wp_enqueue_script(
			'asdl-finance-admin',
			ASDL_FINANCE_URL . 'assets/js/finance-admin.js',
			array(),
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
				'runtimeNonces' => array(
					'adminRuntime'   => wp_create_nonce( 'asdl_fin_admin_runtime' ),
					'dashboard'      => wp_create_nonce( 'asdl_fin_dashboard_runtime' ),
					'contactDetail'  => wp_create_nonce( 'asdl_fin_contact_detail_runtime' ),
					'receivableDetail' => wp_create_nonce( 'asdl_fin_receivable_group_detail' ),
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
				),
			)
		);

		if ( in_array( $page, array( 'asdl-fin-settings', 'asdl-fin-contacts' ), true ) ) {
			wp_enqueue_media();
		}

		if ( 'asdl-fin-reports' === $page ) {
			AnalysisModule::enqueue_assets();
		}
	}
}
