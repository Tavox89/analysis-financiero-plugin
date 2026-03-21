<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class HistoricalIndexRebuildService {
	const OPTION_JOB_STATE    = 'asdl_fin_historical_index_job_state';
	const OPTION_YEAR_REGISTRY = 'asdl_fin_historical_index_year_registry';
	const OPTION_DATA_VERSION = 'asdl_fin_historical_data_version';

	private $fiscal_years;
	private $index;
	private $rollups;
	private $order_service;
	private $source_links;
	private $contacts;

	public function __construct() {
		$this->fiscal_years  = new FiscalYearService();
		$this->index         = new CommerceOrderIndexRepository();
		$this->rollups       = new CommerceContactRollupsRepository();
		$this->order_service = new OrderSyncService();
		$this->source_links  = new SourceLinksRepository();
		$this->contacts      = new ContactsRepository();
	}

	public function get_status_snapshot() {
		$registry    = $this->get_year_registry();
		$year_stats  = $this->index->list_year_stats();
		$rollup_stats = $this->rollups->list_year_stats();
		$stats_by_year = array();

		foreach ( $year_stats as $row ) {
			$stats_by_year[ (int) $row['fiscal_year'] ]['index'] = $row;
		}

		foreach ( $rollup_stats as $row ) {
			$stats_by_year[ (int) $row['fiscal_year'] ]['rollup'] = $row;
		}

		$years = array();
		foreach ( $this->detect_available_years() as $year ) {
			$registry_row = $registry[ $year ] ?? array();
			$index_row    = $stats_by_year[ $year ]['index'] ?? array();
			$rollup_row   = $stats_by_year[ $year ]['rollup'] ?? array();
			$years[]      = array(
				'fiscal_year'         => $year,
				'label'               => $this->fiscal_years->label_for_year( $year ),
				'status'              => sanitize_key( (string) ( $registry_row['status'] ?? ( ! empty( $index_row ) ? 'indexed' : 'pending' ) ) ),
				'order_count'         => (int) ( $index_row['order_count'] ?? 0 ),
				'balance_total'       => round( (float) ( $index_row['balance_total'] ?? 0 ), 6 ),
				'collectible_balance' => round( (float) ( $index_row['collectible_balance_total'] ?? 0 ), 6 ),
				'rollup_count'        => (int) ( $rollup_row['rollup_count'] ?? 0 ),
				'indexed_at'          => sanitize_text_field( (string) ( $registry_row['indexed_at'] ?? '' ) ),
				'compacted_at'        => sanitize_text_field( (string) ( $registry_row['compacted_at'] ?? '' ) ),
				'is_closable'         => $this->is_year_closable( $year ),
				'is_special_case'     => $this->is_year_special_case_allowed( $year ),
			);
		}

		return array(
			'global' => $this->index->global_summary(),
			'years'  => $years,
			'job'    => $this->get_job_state(),
			'data_version' => $this->get_data_version(),
		);
	}

	public function detect_available_years() {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$active_year = (int) $this->fiscal_years->get_context()['start_year'];
		$statuses    = array_keys( wc_get_order_statuses() );
		$oldest_ids  = wc_get_orders(
			array(
				'limit'   => 1,
				'type'    => 'shop_order',
				'status'  => $statuses,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		if ( empty( $oldest_ids ) ) {
			return array();
		}

		$oldest_order = wc_get_order( (int) $oldest_ids[0] );
		if ( ! $oldest_order || ! $oldest_order->get_date_created() ) {
			return array();
		}

		$oldest_date = $oldest_order->get_date_created()->date_i18n( 'Y-m-d' );
		$oldest_year = (int) $this->fiscal_years->resolve_start_year_from_date( $oldest_date );
		$last_year   = $active_year - 1;
		$years       = array();

		for ( $year = $last_year; $year >= $oldest_year; $year-- ) {
			$years[] = $year;
		}

		return $years;
	}

	public function start_rebuild( $fiscal_year, $batch_size = 250, $force = false ) {
		$fiscal_year = absint( $fiscal_year );
		$batch_size  = max( 50, min( 500, absint( $batch_size ) ) );

		if ( $fiscal_year <= 0 ) {
			return new \WP_Error( 'asdl_fin_historical_index_year_missing', 'Selecciona un ejercicio fiscal historico para reconstruir.' );
		}

		if ( ! $this->is_year_indexable( $fiscal_year ) ) {
			return new \WP_Error( 'asdl_fin_historical_index_year', 'Solo puedes indexar ejercicios fiscales anteriores al ejercicio actual.' );
		}

		$page = $this->query_year_page( $fiscal_year, 1, $batch_size );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		if ( $force ) {
			$this->index->delete_for_year( $fiscal_year );
			$this->rollups->delete_for_year( $fiscal_year );
		}

		$state = array(
			'type'         => 'rebuild',
			'status'       => 'running',
			'fiscal_year'  => $fiscal_year,
			'batch_size'   => $batch_size,
			'current_page' => 1,
			'max_pages'    => (int) ( $page['max_pages'] ?? 0 ),
			'total'        => (int) ( $page['total'] ?? 0 ),
			'processed'    => 0,
			'last_batch'   => 0,
			'errors'       => array(),
			'started_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);

		update_option( self::OPTION_JOB_STATE, $state, false );
		$this->set_year_registry_row(
			$fiscal_year,
			array(
				'status'     => 'running',
				'started_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			)
		);

		return $this->get_job_state();
	}

	public function continue_rebuild() {
		$state = $this->get_job_state();
		if ( empty( $state['status'] ) || 'running' !== $state['status'] || 'rebuild' !== ( $state['type'] ?? '' ) ) {
			return new \WP_Error( 'asdl_fin_historical_index_idle', 'No hay una reconstruccion historica activa.' );
		}

		$fiscal_year = absint( $state['fiscal_year'] ?? 0 );
		$page_number = max( 1, absint( $state['current_page'] ?? 1 ) );
		$batch_size  = max( 50, min( 500, absint( $state['batch_size'] ?? 250 ) ) );
		$page        = $this->query_year_page( $fiscal_year, $page_number, $batch_size );

		if ( is_wp_error( $page ) ) {
			$state['status'] = 'error';
			$state['errors'][] = $page->get_error_message();
			$state['updated_at'] = current_time( 'mysql' );
			update_option( self::OPTION_JOB_STATE, $state, false );
			$this->set_year_registry_row(
				$fiscal_year,
				array(
					'status'     => 'error',
					'updated_at' => current_time( 'mysql' ),
				)
			);
			return $page;
		}

		$processed_batch = 0;
		$order_ids       = (array) ( $page['order_ids'] ?? array() );
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order ) {
				continue;
			}

			$result = $this->index_order( $order );
			if ( is_wp_error( $result ) ) {
				$state['errors'][] = $result->get_error_message();
				continue;
			}

			$processed_batch++;
		}

		$state['processed']    = min( (int) ( $state['processed'] ?? 0 ) + $processed_batch, (int) ( $page['total'] ?? 0 ) );
		$state['last_batch']   = $processed_batch;
		$state['max_pages']    = (int) ( $page['max_pages'] ?? $state['max_pages'] ?? 0 );
		$state['total']        = (int) ( $page['total'] ?? $state['total'] ?? 0 );
		$state['updated_at']   = current_time( 'mysql' );
		$state['current_page'] = $page_number + 1;

		$is_complete = empty( $order_ids ) || $page_number >= (int) ( $page['max_pages'] ?? 0 );
		if ( $is_complete ) {
			$rollup_result = $this->rollups->rebuild_for_year( $fiscal_year );
			$state['status']      = 'completed';
			$state['completed_at'] = current_time( 'mysql' );
			update_option( self::OPTION_JOB_STATE, $state, false );
			$this->set_year_registry_row(
				$fiscal_year,
				array(
					'status'      => 'indexed',
					'indexed_at'  => current_time( 'mysql' ),
					'updated_at'  => current_time( 'mysql' ),
					'order_count' => (int) ( $state['processed'] ?? 0 ),
					'rollup_count'=> (int) ( $rollup_result['rollup_count'] ?? 0 ),
				)
			);
			$this->bump_data_version();
		} else {
			update_option( self::OPTION_JOB_STATE, $state, false );
			$this->set_year_registry_row(
				$fiscal_year,
				array(
					'status'     => 'running',
					'updated_at' => current_time( 'mysql' ),
				)
			);
		}

		return $this->get_job_state();
	}

	public function rebuild_rollups( $fiscal_year ) {
		$fiscal_year = absint( $fiscal_year );
		if ( $fiscal_year <= 0 ) {
			return new \WP_Error( 'asdl_fin_historical_rollups_year', 'Debes indicar un ejercicio fiscal valido.' );
		}

		$result = $this->rollups->rebuild_for_year( $fiscal_year );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->set_year_registry_row(
			$fiscal_year,
			array(
				'status'      => 'indexed',
				'updated_at'  => current_time( 'mysql' ),
				'rollup_count'=> (int) ( $result['rollup_count'] ?? 0 ),
			)
		);
		$this->bump_data_version();

		return $result;
	}

	public function compact_year( $fiscal_year ) {
		$fiscal_year = absint( $fiscal_year );
		if ( $fiscal_year <= 0 ) {
			return new \WP_Error( 'asdl_fin_historical_compact_year', 'Debes indicar un ejercicio fiscal valido.' );
		}

		$updated = $this->index->mark_year_as_carryforward( $fiscal_year );
		$this->set_year_registry_row(
			$fiscal_year,
			array(
				'compacted_at' => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			)
		);
		$this->bump_data_version();

		return array(
			'fiscal_year'    => $fiscal_year,
			'updated_orders' => (int) $updated,
		);
	}

	public function diagnostics( $fiscal_year ) {
		$fiscal_year = absint( $fiscal_year );
		if ( $fiscal_year <= 0 ) {
			return new \WP_Error( 'asdl_fin_historical_diagnostics_year', 'Debes indicar un ejercicio fiscal valido para el diagnostico.' );
		}

		$year_row   = array();
		$rollup_row = array();

		foreach ( $this->index->list_year_stats() as $row ) {
			if ( (int) ( $row['fiscal_year'] ?? 0 ) === $fiscal_year ) {
				$year_row = $row;
				break;
			}
		}

		foreach ( $this->rollups->list_year_stats() as $row ) {
			if ( (int) ( $row['fiscal_year'] ?? 0 ) === $fiscal_year ) {
				$rollup_row = $row;
				break;
			}
		}

		return array(
			'fiscal_year' => $fiscal_year,
			'label'       => $this->fiscal_years->label_for_year( $fiscal_year ),
			'indexed'     => $this->is_year_indexed( $fiscal_year ),
			'year'        => $year_row,
			'rollups'     => $rollup_row,
			'diagnostics' => $this->index->diagnostics_for_year( $fiscal_year ),
		);
	}

	public function refresh_order_index( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return false;
		}

		$provider = $this->order_service->detect_order_provider_for_admin( $order );
		$year     = $this->resolve_order_fiscal_year( $order );
		if ( ! $this->is_year_indexable( $year ) ) {
			$this->index->delete_by_provider_external( $provider, (int) $order->get_id() );
			return false;
		}

		$result = $this->index_order( $order );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$this->rollups->rebuild_for_year( $year );
		$this->bump_data_version();

		return true;
	}

	public function refresh_document_links( $document_id ) {
		$document_id = absint( $document_id );
		if ( $document_id <= 0 ) {
			return false;
		}

		foreach ( $this->source_links->find_for_document( $document_id ) as $link ) {
			if ( 'shop_order' !== sanitize_key( (string) ( $link['object_type'] ?? '' ) ) ) {
				continue;
			}

			$this->refresh_order_index( (int) ( $link['external_id'] ?? 0 ) );
		}

		return true;
	}

	public function refresh_contact_identity( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return false;
		}

		$contact = $this->contacts->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return false;
		}

		$order_ids = array();
		foreach ( $this->collect_indexed_orders_for_contact_identity( $contact ) as $row ) {
			$order_id = absint( $row['external_order_id'] ?? 0 );
			if ( $order_id > 0 ) {
				$order_ids[ $order_id ] = $order_id;
			}
		}

		if ( empty( $order_ids ) ) {
			return false;
		}

		$affected_years = array();
		foreach ( $order_ids as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! $order ) {
				continue;
			}

			$provider = $this->order_service->detect_order_provider_for_admin( $order );
			$year     = $this->resolve_order_fiscal_year( $order );

			if ( ! $this->is_year_indexable( $year ) ) {
				$this->index->delete_by_provider_external( $provider, (int) $order->get_id() );
				continue;
			}

			$result = $this->index_order( $order );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			$affected_years[ $year ] = true;
		}

		if ( empty( $affected_years ) ) {
			return false;
		}

		foreach ( array_keys( $affected_years ) as $year ) {
			$this->rollups->rebuild_for_year( (int) $year );
		}

		$this->bump_data_version();
		return true;
	}

	public function get_job_state() {
		$state = get_option( self::OPTION_JOB_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	public function get_data_version() {
		return (int) get_option( self::OPTION_DATA_VERSION, 1 );
	}

	public function bump_data_version() {
		$current = $this->get_data_version();
		update_option( self::OPTION_DATA_VERSION, $current + 1, false );
	}

	public function get_year_registry() {
		$registry = get_option( self::OPTION_YEAR_REGISTRY, array() );
		return is_array( $registry ) ? $registry : array();
	}

	public function is_year_indexed( $fiscal_year ) {
		$registry = $this->get_year_registry();
		$row      = $registry[ absint( $fiscal_year ) ] ?? array();
		return 'indexed' === sanitize_key( (string) ( $row['status'] ?? '' ) );
	}

	public function is_year_indexable( $fiscal_year ) {
		$fiscal_year = absint( $fiscal_year );
		return $fiscal_year > 0 && $fiscal_year < (int) $this->fiscal_years->get_context()['start_year'];
	}

	public function is_year_closable( $fiscal_year ) {
		$fiscal_year = absint( $fiscal_year );
		$active_year = (int) $this->fiscal_years->get_context()['start_year'];

		return $fiscal_year > 0 && $fiscal_year < ( $active_year - 1 );
	}

	public function is_year_special_case_allowed( $fiscal_year ) {
		$fiscal_year = absint( $fiscal_year );
		$active_year = (int) $this->fiscal_years->get_context()['start_year'];

		return $fiscal_year > 0 && $fiscal_year === ( $active_year - 1 ) && current_user_can( 'manage_options' );
	}

	private function query_year_page( $fiscal_year, $page, $batch_size ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
			return new \WP_Error( 'asdl_fin_historical_wc', 'WooCommerce no esta disponible para reconstruir el indice historico.' );
		}

		$bounds = $this->fiscal_years->get_context( $fiscal_year );
		$query  = wc_get_orders(
			array(
				'limit'        => max( 1, min( 500, absint( $batch_size ) ) ),
				'paged'        => max( 1, absint( $page ) ),
				'paginate'     => true,
				'type'         => 'shop_order',
				'status'       => array_keys( wc_get_order_statuses() ),
				'orderby'      => 'date',
				'order'        => 'ASC',
				'return'       => 'ids',
				'date_created' => $bounds['start_date'] . '...' . $bounds['end_date'],
			)
		);

		if ( ! is_object( $query ) ) {
			return array(
				'order_ids'  => array_values( (array) $query ),
				'total'      => count( (array) $query ),
				'max_pages'  => count( (array) $query ) > 0 ? 1 : 0,
			);
		}

		return array(
			'order_ids' => array_values( (array) ( $query->orders ?? array() ) ),
			'total'     => (int) ( $query->total ?? 0 ),
			'max_pages' => (int) ( $query->max_num_pages ?? 0 ),
		);
	}

	private function index_order( $order ) {
		$descriptor  = $this->order_service->describe_order( $order );
		$fiscal_year = $this->resolve_order_fiscal_year( $order );
		$group_key   = $this->build_group_key(
			(int) ( $descriptor['contact_id'] ?? 0 ),
			(int) ( $descriptor['customer_id'] ?? 0 ),
			(string) ( $descriptor['billing_email'] ?? '' ),
			(string) ( $descriptor['provider'] ?? 'woocommerce' ),
			(int) ( $descriptor['order_id'] ?? 0 )
		);

		$payload = array(
			'provider'                  => sanitize_key( (string) ( $descriptor['provider'] ?? 'woocommerce' ) ),
			'external_order_id'         => absint( $descriptor['order_id'] ?? 0 ),
			'order_number'              => sanitize_text_field( (string) ( $descriptor['order_number'] ?? '' ) ),
			'group_key'                 => $group_key,
			'contact_id'                => absint( $descriptor['contact_id'] ?? 0 ),
			'wp_user_id'                => absint( $descriptor['customer_id'] ?? 0 ),
			'customer_email'            => sanitize_email( (string) ( $descriptor['billing_email'] ?? '' ) ),
			'display_name'              => sanitize_text_field( (string) ( $descriptor['display_name'] ?? '' ) ),
			'issue_date'                => ! empty( $descriptor['date_created'] ) ? substr( (string) $descriptor['date_created'], 0, 10 ) : null,
			'fiscal_year'               => $fiscal_year,
			'status'                    => sanitize_key( (string) ( $descriptor['status'] ?? '' ) ),
			'currency'                  => sanitize_text_field( (string) ( $descriptor['currency'] ?? '' ) ),
			'gross_total'               => (float) ( $descriptor['effective_total'] ?? $descriptor['total'] ?? 0 ),
			'paid_total'                => (float) ( $descriptor['effective_paid_total'] ?? 0 ),
			'balance'                   => (float) ( $descriptor['effective_due_total'] ?? 0 ),
			'item_count'                => (int) ( $descriptor['item_count'] ?? 0 ),
			'is_open'                   => ! empty( $descriptor['is_effectively_open'] ),
			'operationally_collectible' => ! empty( $descriptor['is_effectively_open'] ) ? 1 : 0,
			'document_id'               => absint( $descriptor['document_id'] ?? 0 ),
			'source_link_id'            => absint( $descriptor['source_link_id'] ?? 0 ),
			'meta_json'                 => array(
				'status_label'            => sanitize_text_field( (string) ( $descriptor['status_label'] ?? '' ) ),
				'document_payment_status' => sanitize_key( (string) ( $descriptor['document_payment_status'] ?? '' ) ),
				'edit_url'                => esc_url_raw( (string) ( $descriptor['edit_url'] ?? '' ) ),
				'is_managed'              => ! empty( $descriptor['is_managed'] ),
			),
		);

		return $this->index->upsert( $payload );
	}

	private function resolve_order_fiscal_year( $order ) {
		$date = $order && $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		return (int) $this->fiscal_years->resolve_start_year_from_date( $date );
	}

	private function build_group_key( $contact_id, $wp_user_id, $email, $provider, $order_id ) {
		if ( $contact_id > 0 ) {
			return 'contact:' . $contact_id;
		}

		if ( $wp_user_id > 0 ) {
			return 'wp_user:' . $wp_user_id;
		}

		if ( '' !== $email ) {
			return 'email:' . md5( $email );
		}

		return sanitize_key( $provider ) . ':' . absint( $order_id );
	}

	private function collect_indexed_orders_for_contact_identity( array $contact ) {
		$rows = array();
		$seen = array();
		$identities = array();

		$identities[] = array( 'contact_id' => (int) ( $contact['id'] ?? 0 ) );

		if ( ! empty( $contact['wp_user_id'] ) ) {
			$identities[] = array( 'wp_user_id' => (int) $contact['wp_user_id'] );
		}

		if ( ! empty( $contact['email'] ) ) {
			$identities[] = array( 'email' => sanitize_email( (string) $contact['email'] ) );
		}

		foreach ( $identities as $identity ) {
			foreach ( $this->index->list_orders_for_identity( array_merge( $identity, array( 'limit' => 5000 ) ) ) as $row ) {
				$row_id = absint( $row['id'] ?? 0 );
				if ( $row_id <= 0 || isset( $seen[ $row_id ] ) ) {
					continue;
				}

				$seen[ $row_id ] = true;
				$rows[]          = $row;
			}
		}

		return $rows;
	}

	private function set_year_registry_row( $fiscal_year, array $data ) {
		$fiscal_year = absint( $fiscal_year );
		if ( $fiscal_year <= 0 ) {
			return;
		}

		$registry = $this->get_year_registry();
		$current  = isset( $registry[ $fiscal_year ] ) && is_array( $registry[ $fiscal_year ] ) ? $registry[ $fiscal_year ] : array();
		$registry[ $fiscal_year ] = array_merge( $current, $data );
		update_option( self::OPTION_YEAR_REGISTRY, $registry, false );
	}
}
