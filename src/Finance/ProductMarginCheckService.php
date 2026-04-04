<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\DualPricingService;
use ASDLabs\Finance\Legacy\AnalysisModule;

final class ProductMarginCheckService extends BaseRepository {
	const JOB_TTL = 21600;
	const SNAPSHOT_TTL = 7200;
	const DAILY_TTL = 93600;
	const JOB_PREFIX = 'asdl_fin_margin_job_';
	const SNAPSHOT_PREFIX = 'asdl_fin_margin_snapshot_';
	const DAILY_PREFIX = 'asdl_fin_margin_daily_';
	const CATALOG_VERSION_OPTION = 'asdl_fin_product_catalog_version';
	const TARGET_PERCENT_META_KEY = 'asdl_fin_pricing_target_percent';
	const PRICING_MODE_META_KEY = 'asdl_fin_pricing_mode';
	const LAST_POSITIVE_INVENTORY_AT_META_KEY = 'asdl_fin_last_positive_inventory_at';
	const ROW_LIMIT = 2000;
	const DEFAULT_BATCH_SIZE = 25;
	const REVIEW_MARGIN_THRESHOLD = 12.0;
	const REVIEW_TARGET_DEVIATION_THRESHOLD = 5.0;
	const LOW_STOCK_THRESHOLD = 5;

	public static function bump_catalog_version() {
		update_option( self::CATALOG_VERSION_OPTION, (string) microtime( true ), false );
	}

	public function current_catalog_version() {
		$version = get_option( self::CATALOG_VERSION_OPTION, '' );

		if ( '' === (string) $version ) {
			$version = (string) microtime( true );
			update_option( self::CATALOG_VERSION_OPTION, $version, false );
		}

		return (string) $version;
	}

	public function catalog_scope() {
		return $this->normalize_scope(
			array(
				'scope_kind' => 'catalog',
			)
		);
	}

	public function report_scope_from_filters( array $sales_filters = array() ) {
		return $this->normalize_scope(
			array(
				'scope_kind'             => 'report',
				'exclude_categories_raw' => (string) ( $sales_filters['exclude_categories_raw'] ?? '' ),
			)
		);
	}

	public function normalize_scope( array $args = array() ) {
		$settings       = $this->resolve_cost_settings();
		$dual_pricing   = new DualPricingService();
		$discount       = $dual_pricing->get_discount_config();
		$scope_kind     = sanitize_key( (string) ( $args['scope_kind'] ?? 'catalog' ) );
		$scope_kind     = in_array( $scope_kind, array( 'catalog', 'report' ), true ) ? $scope_kind : 'catalog';
		$exclude_raw    = sanitize_text_field( (string) ( $args['exclude_categories_raw'] ?? '' ) );
		$exclude_ids    = $this->parse_category_ids( $exclude_raw );
		$target_percent = $this->normalize_percent( (float) ( $discount['percent'] ?? 0 ) );
		$scope_label    = 'report' === $scope_kind ? 'Reporte maestro' : 'Catalogo de productos';

		if ( ! empty( $exclude_ids ) ) {
			$scope_label .= sprintf( ' · excluye %d categoria(s)', count( $exclude_ids ) );
		}

		return array(
			'scope_kind'             => $scope_kind,
			'scope_label'            => $scope_label,
			'day_key'                => current_time( 'Y-m-d' ),
			'exclude_categories_raw' => $exclude_raw,
			'exclude_category_ids'   => $exclude_ids,
			'cost_source'            => sanitize_key( (string) ( $settings['cost_source'] ?? 'yith' ) ),
			'cost_meta_key'          => sanitize_key( (string) ( $settings['cost_meta_key'] ?? 'yith_cog_cost' ) ),
			'catalog_version'        => $this->current_catalog_version(),
			'global_target_percent'  => $target_percent,
			'global_target_fraction' => $this->normalize_fraction( $target_percent / 100 ),
		);
	}

	public function get_workspace_result_for_scope( array $scope ) {
		$scope = $this->normalize_scope( $scope );
		$cached = get_transient( $this->snapshot_key( $scope ) );

		if ( ! is_array( $cached ) ) {
			return $this->unknown_result( $scope, false );
		}

		return $this->normalize_cached_result( $cached, $scope, true, false, true );
	}

	public function get_daily_result_for_scope( array $scope ) {
		$scope  = $this->normalize_scope( $scope );
		$cached = get_transient( $this->daily_key( $scope ) );

		if ( is_array( $cached ) ) {
			return $this->normalize_cached_result( $cached, $scope, true, true, false );
		}

		$snapshot = $this->get_workspace_result_for_scope( $scope );
		if ( ! empty( $snapshot['cache_valid'] ) ) {
			if ( $this->checked_today( (string) ( $snapshot['checked_at'] ?? '' ) ) ) {
				$daily_snapshot = $this->strip_snapshot_only_state( $snapshot );
				$this->save_daily_result( $scope, $daily_snapshot );
				return $this->normalize_cached_result( $daily_snapshot, $scope, true, true, false );
			}

			return $this->normalize_cached_result( $this->strip_snapshot_only_state( $snapshot ), $scope, true, false, false );
		}

		return $this->unknown_result( $scope, true );
	}

	public function get_report_view_result_for_scope( array $scope ) {
		$scope     = $this->normalize_scope( $scope );
		$workspace = $this->get_workspace_result_for_scope( $scope );
		$daily     = $this->get_daily_result_for_scope( $scope );

		if ( ! empty( $workspace['cache_valid'] ) ) {
			return $this->merge_report_certification_state( $workspace, $daily, 'workspace' );
		}

		if ( ! empty( $daily['cache_valid'] ) ) {
			return $this->merge_report_certification_state( $daily, $daily, 'daily' );
		}

		return $this->merge_report_certification_state( $this->unknown_result( $scope, false ), $daily, 'none' );
	}

	public function start_job( array $args = array() ) {
		$scope         = $this->normalize_scope( $args );
		$snapshot      = $this->get_workspace_result_for_scope( $scope );
		$require_today = ! empty( $args['require_today'] );
		$per_page      = max( 5, min( 100, (int) ( $args['batch_size'] ?? self::DEFAULT_BATCH_SIZE ) ) );

		$job = array(
			'job_id'             => wp_generate_uuid4(),
			'status'             => 'running',
			'scope'              => $scope,
			'current_page'       => 1,
			'per_page'           => $per_page,
			'total_products'     => $this->count_parent_products(),
			'processed_products' => 0,
			'last_batch'         => 0,
			'rows'               => array(),
			'critical_count'     => 0,
			'review_count'       => 0,
			'manual_count'       => 0,
			'ok_count'           => 0,
			'issue_count'        => 0,
			'total_rows'         => 0,
			'truncated'          => false,
			'message'            => 'Preparando revision operativa de productos y precios.',
			'created_at'         => $this->now(),
			'updated_at'         => $this->now(),
			'checked_at'         => '',
			'result'             => array(),
		);

		if ( ! empty( $snapshot['cache_valid'] ) && ( ! $require_today || $this->checked_today( (string) ( $snapshot['checked_at'] ?? '' ) ) ) ) {
			$job['status']             = 'completed';
			$job['message']            = 'Se reutilizo la vista rapida mas reciente para este alcance.';
			$job['checked_at']         = sanitize_text_field( (string) ( $snapshot['checked_at'] ?? '' ) );
			$job['rows']               = array_values( (array) ( $snapshot['rows'] ?? array() ) );
			$job['critical_count']     = (int) ( $snapshot['critical_count'] ?? 0 );
			$job['review_count']       = (int) ( $snapshot['review_count'] ?? 0 );
			$job['manual_count']       = (int) ( $snapshot['manual_count'] ?? 0 );
			$job['ok_count']           = (int) ( $snapshot['ok_count'] ?? 0 );
			$job['issue_count']        = (int) ( $snapshot['issue_count'] ?? 0 );
			$job['total_rows']         = (int) ( $snapshot['total_rows'] ?? count( $job['rows'] ) );
			$job['truncated']          = ! empty( $snapshot['truncated'] );
			$job['processed_products'] = (int) ( $job['total_products'] );
			$job['result']             = $snapshot;

			if ( $this->checked_today( $job['checked_at'] ) ) {
				$this->save_daily_result( $scope, $snapshot );
			}
		}

		$this->save_job( $job );

		return $job;
	}

	public function continue_job( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( empty( $job['job_id'] ) ) {
			return $this->error( 'asdl_fin_margin_job_missing', 'No se encontro la revision de productos solicitada.' );
		}

		if ( 'completed' === (string) ( $job['status'] ?? '' ) ) {
			return $job;
		}

		try {
			$query = $this->query_parent_products(
				(int) ( $job['current_page'] ?? 1 ),
				(int) ( $job['per_page'] ?? self::DEFAULT_BATCH_SIZE )
			);
			$ids   = (array) ( $query['ids'] ?? array() );
			$rows  = array_values( (array) ( $job['rows'] ?? array() ) );

			foreach ( $ids as $product_id ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $product_id ) ) : null;
				if ( ! $product ) {
					continue;
				}

				foreach ( $this->evaluate_product( $product, (array) ( $job['scope'] ?? array() ) ) as $row ) {
					++$job['total_rows'];

					switch ( sanitize_key( (string) ( $row['status'] ?? '' ) ) ) {
						case 'critical':
							++$job['critical_count'];
							++$job['issue_count'];
							break;
						case 'review':
							++$job['review_count'];
							++$job['issue_count'];
							break;
						case 'manual':
							++$job['manual_count'];
							break;
						default:
							++$job['ok_count'];
							break;
					}

					if ( count( $rows ) < self::ROW_LIMIT ) {
						$rows[] = $row;
					} else {
						$job['truncated'] = true;
					}
				}
			}

			$job['rows']               = $rows;
			$job['processed_products'] = min(
				(int) ( $job['total_products'] ?? 0 ),
				(int) ( $job['processed_products'] ?? 0 ) + count( $ids )
			);
			$job['last_batch'] = count( $ids );
			$job['message']    = count( $ids )
				? 'Revisando catalogo y calculando referencias de precio.'
				: 'No se encontraron mas productos por revisar.';
			$job['updated_at'] = $this->now();

			$has_more = ! empty( $query['has_more'] );
			if ( $has_more ) {
				$job['current_page'] = (int) ( $job['current_page'] ?? 1 ) + 1;
				$this->save_job( $job );
				return $job;
			}

			$job['status']     = 'completed';
			$job['checked_at'] = $this->now();
			$job['message']    = (int) ( $job['critical_count'] ?? 0 ) > 0
				? 'La revision detecto productos criticos para el cierre.'
				: ( (int) ( $job['review_count'] ?? 0 ) > 0
					? 'La revision termino con observaciones para revisar.'
					: 'La revision termino sin hallazgos bloqueantes.' );
			$job['result'] = $this->build_result_from_job( $job );
			$this->save_snapshot_result( (array) ( $job['scope'] ?? array() ), (array) $job['result'] );
			$this->save_daily_result( (array) ( $job['scope'] ?? array() ), (array) $job['result'] );
			$this->save_job( $job );

			return $job;
		} catch ( \Throwable $throwable ) {
			$job['status']     = 'error';
			$job['message']    = $throwable->getMessage();
			$job['updated_at'] = $this->now();
			$this->save_job( $job );

			return $job;
		}
	}

	public function get_job( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return array();
		}

		$job = get_transient( self::JOB_PREFIX . md5( $job_id ) );

		return is_array( $job ) ? $job : array();
	}

	public function get_result( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( empty( $job['job_id'] ) ) {
			return $this->error( 'asdl_fin_margin_job_missing', 'No se encontro la revision solicitada.' );
		}

		if ( 'completed' !== (string) ( $job['status'] ?? '' ) ) {
			return $this->error( 'asdl_fin_margin_job_running', 'La revision todavia no ha terminado.' );
		}

		return (array) ( $job['result'] ?? $this->build_result_from_job( $job ) );
	}

	public function update_pricing( array $args = array() ) {
		$scope = $this->normalize_scope(
			array(
				'scope_kind'             => sanitize_key( (string) ( $args['scope_kind'] ?? 'catalog' ) ),
				'exclude_categories_raw' => sanitize_text_field( (string) ( $args['exclude_categories_raw'] ?? '' ) ),
			)
		);

		$product_id              = absint( $args['product_id'] ?? 0 );
		$row_signature           = sanitize_text_field( (string) ( $args['row_signature'] ?? '' ) );
		$cost_target_product_id  = absint( $args['cost_target_product_id'] ?? 0 );
		$category_target_product_id = absint( $args['category_target_product_id'] ?? 0 );
		$cost_meta_key           = sanitize_key( (string) ( $args['cost_meta_key'] ?? '' ) );
		$cost                    = round( max( 0, (float) ( $args['cost'] ?? 0 ) ), 6 );
		$regular_price           = round( max( 0, (float) ( $args['regular_price'] ?? 0 ) ), 6 );
		$target_percent          = $this->normalize_percent( (float) ( $args['target_percent'] ?? 0 ) );
		$inherit_target          = ! empty( $args['inherit_target'] );
		$strategy_mode           = $this->normalize_strategy_mode( (string) ( $args['strategy_mode'] ?? 'formula' ) );
		$category_ids            = $this->normalize_term_ids( $args['category_ids_csv'] ?? '' );
		$current_row             = $this->get_live_row_for_product( $product_id, $scope );

		if ( is_wp_error( $current_row ) ) {
			return $current_row;
		}

		if ( '' !== $row_signature && $row_signature !== (string) ( $current_row['signature'] ?? '' ) ) {
			$error = $this->error(
				'asdl_fin_product_margin_conflict',
				'El producto cambio desde que abriste el editor. Refresca la fila antes de guardar.'
			);
			$error->add_data(
				array(
					'current_row' => $current_row,
				)
			);
			return $error;
		}

		if ( $cost_target_product_id <= 0 || '' === $cost_meta_key ) {
			$cost_target_product_id = (int) ( $current_row['cost_target_product_id'] ?? 0 );
			$cost_meta_key          = sanitize_key( (string) ( $current_row['cost_meta_key'] ?? '' ) );
		}

		if ( $cost_target_product_id <= 0 || '' === $cost_meta_key ) {
			return $this->error( 'asdl_fin_margin_cost_target', 'No se pudo resolver donde guardar el costo de este producto.' );
		}

		if ( $category_target_product_id <= 0 ) {
			$category_target_product_id = (int) ( $current_row['category_target_product_id'] ?? 0 );
		}

		if ( $category_target_product_id <= 0 ) {
			return $this->error( 'asdl_fin_margin_category_target', 'No se pudo resolver donde guardar las categorias de este producto.' );
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return $this->error( 'asdl_fin_margin_product_missing', 'No se pudo cargar el producto que se va a actualizar.' );
		}

		update_post_meta( $cost_target_product_id, $cost_meta_key, $cost );

		$product->set_regular_price( wc_format_decimal( $regular_price, 6 ) );
		if ( '' === (string) $product->get_sale_price() ) {
			$product->set_price( wc_format_decimal( $regular_price, 6 ) );
		}
		$product->save();

		wp_set_post_terms( $category_target_product_id, $category_ids, 'product_cat', false );

		if ( $inherit_target ) {
			delete_post_meta( $product_id, self::TARGET_PERCENT_META_KEY );
		} else {
			update_post_meta( $product_id, self::TARGET_PERCENT_META_KEY, $target_percent );
		}

		update_post_meta( $product_id, self::PRICING_MODE_META_KEY, $strategy_mode );

		$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		$this->flush_product_runtime_cache( $product_id );
		if ( $cost_target_product_id > 0 && $cost_target_product_id !== $product_id ) {
			$this->flush_product_runtime_cache( $cost_target_product_id );
		}
		if ( $category_target_product_id > 0 && $category_target_product_id !== $product_id && $category_target_product_id !== $cost_target_product_id ) {
			$this->flush_product_runtime_cache( $category_target_product_id );
		}
		if ( $parent_id > 0 && $parent_id !== $product_id ) {
			$this->flush_product_runtime_cache( $parent_id );
		}

		self::bump_catalog_version();

		$updated_row = $this->get_live_row_for_product( $product_id, $scope );
		if ( is_wp_error( $updated_row ) ) {
			return $updated_row;
		}

		return array(
			'row'      => $updated_row,
			'message'  => 'Producto actualizado. La vista actual quedo pendiente de recalculo y conviene volver a revisar el catalogo.',
			'stale'    => true,
		);
	}

	public function update_cost( $product_id, $meta_key, $value ) {
		return $this->update_pricing(
			array(
				'product_id'    => $product_id,
				'cost_meta_key' => $meta_key,
				'cost'          => $value,
				'strategy_mode' => 'formula',
				'inherit_target'=> true,
			)
		);
	}

	public function discard_from_active_snapshot( array $scope, $product_id, $reason = '' ) {
		$scope      = $this->normalize_scope( $scope );
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return $this->error( 'asdl_fin_margin_discard_missing', 'No se pudo identificar el producto que se quiere descartar en esta vista.' );
		}

		$snapshot = get_transient( $this->snapshot_key( $scope ) );
		if ( ! is_array( $snapshot ) || empty( $snapshot['rows'] ) ) {
			return $this->error( 'asdl_fin_margin_snapshot_missing', 'Primero actualiza la vista para poder descartar hallazgos de este catalogo.' );
		}

		$found = false;
		foreach ( (array) $snapshot['rows'] as $row ) {
			if ( $product_id === (int) ( $row['product_id'] ?? 0 ) ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return $this->error( 'asdl_fin_margin_row_missing', 'Ese producto ya no forma parte de la vista actual.' );
		}

		$snapshot = $this->persist_snapshot_discard(
			$scope,
			$snapshot,
			array(
				$product_id => array(
					'reason'       => sanitize_text_field( (string) $reason ),
					'discarded_at' => $this->now(),
				),
			)
		);

		return $this->normalize_cached_result( $snapshot, $scope, true, false, true );
	}

	public function reinstate_from_active_snapshot( array $scope, $product_id ) {
		$scope      = $this->normalize_scope( $scope );
		$product_id = absint( $product_id );

		if ( $product_id <= 0 ) {
			return $this->error( 'asdl_fin_margin_reinstate_missing', 'No se pudo identificar el producto que se quiere reincluir.' );
		}

		$snapshot = get_transient( $this->snapshot_key( $scope ) );
		if ( ! is_array( $snapshot ) || empty( $snapshot['rows'] ) ) {
			return $this->error( 'asdl_fin_margin_snapshot_missing', 'Primero actualiza la vista para poder reincluir productos.' );
		}

		$discarded_ids  = array_values( array_filter( array_map( 'absint', (array) ( $snapshot['discarded_ids'] ?? array() ) ) ) );
		$discarded_meta = is_array( $snapshot['discarded_meta'] ?? null ) ? $snapshot['discarded_meta'] : array();

		$discarded_ids = array_values(
			array_filter(
				$discarded_ids,
				static function ( $id ) use ( $product_id ) {
					return (int) $id !== $product_id;
				}
			)
		);
		unset( $discarded_meta[ (string) $product_id ] );

		$snapshot['discarded_ids']   = $discarded_ids;
		$snapshot['discarded_meta']  = $discarded_meta;
		$snapshot['discarded_count'] = count( $discarded_ids );

		$this->save_snapshot_result( $scope, $snapshot );

		return $this->normalize_cached_result( $snapshot, $scope, true, false, true );
	}

	public function discard_visible_no_stock_from_active_snapshot( array $scope, array $visible_product_ids = array() ) {
		$scope    = $this->normalize_scope( $scope );
		$snapshot = get_transient( $this->snapshot_key( $scope ) );

		if ( ! is_array( $snapshot ) || empty( $snapshot['rows'] ) ) {
			return $this->error( 'asdl_fin_margin_snapshot_missing', 'Primero actualiza la vista para poder descartar hallazgos sin inventario.' );
		}

		$visible_lookup = array();
		foreach ( array_values( array_filter( array_map( 'absint', $visible_product_ids ) ) ) as $product_id ) {
			$visible_lookup[ $product_id ] = true;
		}

		$to_discard = array();
		foreach ( (array) ( $snapshot['rows'] ?? array() ) as $row ) {
			$product_id = (int) ( $row['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}

			if ( ! empty( $visible_lookup ) && empty( $visible_lookup[ $product_id ] ) ) {
				continue;
			}

			if ( empty( $row['is_issue'] ) ) {
				continue;
			}

			if ( 'danger' !== sanitize_key( (string) ( $row['inventory_tone'] ?? '' ) ) ) {
				continue;
			}

			if ( ! empty( $row['inventory_managed'] ) && (float) ( $row['inventory_current'] ?? 0 ) > 0 ) {
				continue;
			}

			$to_discard[ $product_id ] = array(
				'reason'       => 'Sin inventario en esta vista',
				'discarded_at' => $this->now(),
			);
		}

		if ( empty( $to_discard ) ) {
			return $this->error( 'asdl_fin_margin_no_visible_no_stock', 'No hay hallazgos visibles sin inventario para descartar en esta vista.' );
		}

		$snapshot = $this->persist_snapshot_discard( $scope, $snapshot, $to_discard );

		return $this->normalize_cached_result( $snapshot, $scope, true, false, true );
	}

	public static function track_positive_inventory_timestamp( $product_or_id ) {
		$product = is_object( $product_or_id ) ? $product_or_id : ( function_exists( 'wc_get_product' ) ? wc_get_product( absint( $product_or_id ) ) : null );

		if ( ! $product || ! is_object( $product ) ) {
			return;
		}

		$service     = new self();
		$tracking_id = $service->inventory_tracking_source_id( $product );

		if ( $tracking_id <= 0 ) {
			return;
		}

		$tracking_product = function_exists( 'wc_get_product' ) ? wc_get_product( $tracking_id ) : null;
		if ( ! $tracking_product || ! is_object( $tracking_product ) ) {
			return;
		}

		$quantity       = $tracking_product->get_stock_quantity();
		$stock_status   = sanitize_key( (string) $tracking_product->get_stock_status() );
		$has_inventory  = ( null !== $quantity && (float) $quantity > 0 ) || in_array( $stock_status, array( 'instock', 'onbackorder' ), true );

		if ( ! $has_inventory ) {
			return;
		}

		update_post_meta( $tracking_id, self::LAST_POSITIVE_INVENTORY_AT_META_KEY, current_time( 'mysql' ) );
	}

	public function get_live_row_for_product( $product_id, array $scope = array() ) {
		$scope   = $this->normalize_scope( $scope );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $product_id ) ) : null;

		if ( ! $product ) {
			return $this->error( 'asdl_fin_product_margin_missing', 'No se encontro el producto solicitado.' );
		}

		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ) {
			return $this->error( 'asdl_fin_product_margin_variable', 'Selecciona una fila concreta del catalogo para editarla.' );
		}

		$catalog_product = $product;
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$catalog_product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product->get_parent_id() ) : $product;
		}

		$row = $this->evaluate_margin_row( $product, $catalog_product, $scope );

		if ( empty( $row ) ) {
			return $this->error( 'asdl_fin_product_margin_filtered', 'La fila ya no pertenece al alcance actual o no se pudo recalcular.' );
		}

		return $row;
	}

	private function save_job( array $job ) {
		$job_id = sanitize_text_field( (string) ( $job['job_id'] ?? '' ) );
		if ( '' === $job_id ) {
			return false;
		}

		return set_transient( self::JOB_PREFIX . md5( $job_id ), $job, self::JOB_TTL );
	}

	private function save_snapshot_result( array $scope, array $result ) {
		return set_transient( $this->snapshot_key( $scope ), $result, self::SNAPSHOT_TTL );
	}

	private function save_daily_result( array $scope, array $result ) {
		return set_transient( $this->daily_key( $scope ), $this->strip_snapshot_only_state( $result ), self::DAILY_TTL );
	}

	private function build_result_from_job( array $job ) {
		$scope  = (array) ( $job['scope'] ?? array() );
		$rows   = array_values( (array) ( $job['rows'] ?? array() ) );
		$status = 'ok';

		if ( (int) ( $job['critical_count'] ?? 0 ) > 0 ) {
			$status = 'issues';
		} elseif ( (int) ( $job['review_count'] ?? 0 ) > 0 ) {
			$status = 'review';
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				$order = array(
					'critical' => 0,
					'review'   => 1,
					'manual'   => 2,
					'ok'       => 3,
				);
				$left_status  = sanitize_key( (string) ( $left['status'] ?? 'ok' ) );
				$right_status = sanitize_key( (string) ( $right['status'] ?? 'ok' ) );
				$left_rank    = array_key_exists( $left_status, $order ) ? $order[ $left_status ] : 99;
				$right_rank   = array_key_exists( $right_status, $order ) ? $order[ $right_status ] : 99;

				if ( $left_rank === $right_rank ) {
					return strnatcasecmp(
						sanitize_text_field( (string) ( $left['product_name'] ?? '' ) ),
						sanitize_text_field( (string) ( $right['product_name'] ?? '' ) )
					);
				}

				return $left_rank <=> $right_rank;
			}
		);

		$visible_counts = $this->visible_status_counts( $rows );

		return array(
			'status'                     => $status,
			'cache_valid'                => true,
			'snapshot_valid'             => true,
			'certified_today'            => true,
			'checked_at'                 => sanitize_text_field( (string) ( $job['checked_at'] ?? $this->now() ) ),
			'issue_count'                => (int) ( $job['issue_count'] ?? 0 ),
			'critical_count'             => (int) ( $job['critical_count'] ?? 0 ),
			'review_count'               => (int) ( $job['review_count'] ?? 0 ),
			'manual_count'               => (int) ( $job['manual_count'] ?? 0 ),
			'ok_count'                   => (int) ( $job['ok_count'] ?? 0 ),
			'total_rows'                 => (int) ( $job['total_rows'] ?? count( $rows ) ),
			'listed_row_count'           => count( $rows ),
			'listed_issue_count'         => (int) $visible_counts['issue_count'],
			'listed_verified_count'      => (int) $visible_counts['verified_count'],
			'visible_critical_count'     => (int) $visible_counts['critical_count'],
			'visible_review_count'       => (int) $visible_counts['review_count'],
			'visible_manual_count'       => (int) $visible_counts['manual_count'],
			'visible_ok_count'           => (int) $visible_counts['ok_count'],
			'truncated'                  => ! empty( $job['truncated'] ),
			'rows'                       => $rows,
			'scope'                      => $scope,
			'scope_hash'                 => $this->scope_hash( $scope ),
			'snapshot_hash'              => $this->snapshot_hash( $scope ),
			'scope_label'                => sanitize_text_field( (string) ( $scope['scope_label'] ?? 'Catalogo de productos' ) ),
			'global_target_percent'      => (float) ( $scope['global_target_percent'] ?? 0 ),
			'blocking_for_monthly_close' => (int) ( $job['critical_count'] ?? 0 ) > 0,
			'discarded_ids'              => array(),
			'discarded_meta'             => array(),
			'discarded_count'            => 0,
		);
	}

	private function normalize_cached_result( array $cached, array $scope, $cache_valid, $certified_today, $workspace_view = false ) {
		$cached['scope']                      = array_merge( $scope, (array) ( $cached['scope'] ?? array() ) );
		$cached['scope_hash']                 = $this->scope_hash( $scope );
		$cached['snapshot_hash']              = $this->snapshot_hash( $scope );
		$cached['scope_label']                = sanitize_text_field( (string) ( $cached['scope_label'] ?? $scope['scope_label'] ?? 'Catalogo de productos' ) );
		$cached['cache_valid']                = (bool) $cache_valid;
		$cached['snapshot_valid']             = true;
		$cached['certified_today']            = (bool) $certified_today;
		$cached['critical_count']             = (int) ( $cached['critical_count'] ?? 0 );
		$cached['review_count']               = (int) ( $cached['review_count'] ?? 0 );
		$cached['manual_count']               = (int) ( $cached['manual_count'] ?? 0 );
		$cached['ok_count']                   = (int) ( $cached['ok_count'] ?? 0 );
		$cached['issue_count']                = (int) ( $cached['issue_count'] ?? ( $cached['critical_count'] + $cached['review_count'] ) );
		$cached['total_rows']                 = (int) ( $cached['total_rows'] ?? count( (array) ( $cached['rows'] ?? array() ) ) );
		$cached['listed_row_count']           = (int) ( $cached['listed_row_count'] ?? count( (array) ( $cached['rows'] ?? array() ) ) );
		$cached['listed_issue_count']         = (int) ( $cached['listed_issue_count'] ?? 0 );
		$cached['listed_verified_count']      = (int) ( $cached['listed_verified_count'] ?? 0 );
		$cached['blocking_for_monthly_close'] = (int) ( $cached['critical_count'] ?? 0 ) > 0;
		$cached['global_target_percent']      = (float) ( $cached['global_target_percent'] ?? $scope['global_target_percent'] ?? 0 );
		$cached['discarded_ids']              = array_values( array_filter( array_map( 'absint', (array) ( $cached['discarded_ids'] ?? array() ) ) ) );
		$cached['discarded_meta']             = is_array( $cached['discarded_meta'] ?? null ) ? $cached['discarded_meta'] : array();
		$cached['discarded_count']            = (int) ( $cached['discarded_count'] ?? count( $cached['discarded_ids'] ) );

		if ( $workspace_view && ! empty( $cached['rows'] ) ) {
			$cached = $this->apply_snapshot_view_state( $cached );
		} else {
			$cached['rows'] = array_values(
				array_map(
					array( $this, 'normalize_row_snapshot_flags' ),
					(array) ( $cached['rows'] ?? array() )
				)
			);
		}

		$visible_counts = $this->visible_status_counts( (array) ( $cached['rows'] ?? array() ) );
		$cached['listed_issue_count']     = (int) $visible_counts['issue_count'];
		$cached['listed_verified_count']  = (int) $visible_counts['verified_count'];
		$cached['visible_critical_count'] = (int) $visible_counts['critical_count'];
		$cached['visible_review_count']   = (int) $visible_counts['review_count'];
		$cached['visible_manual_count']   = (int) $visible_counts['manual_count'];
		$cached['visible_ok_count']       = (int) $visible_counts['ok_count'];

		return $cached;
	}

	private function unknown_result( array $scope, $certified_today ) {
		return array(
			'status'                     => 'unknown',
			'cache_valid'                => false,
			'snapshot_valid'             => false,
			'certified_today'            => (bool) $certified_today,
			'checked_at'                 => '',
			'issue_count'                => 0,
			'critical_count'             => 0,
			'review_count'               => 0,
			'manual_count'               => 0,
			'ok_count'                   => 0,
			'total_rows'                 => 0,
			'listed_row_count'           => 0,
			'listed_issue_count'         => 0,
			'listed_verified_count'      => 0,
			'visible_critical_count'     => 0,
			'visible_review_count'       => 0,
			'visible_manual_count'       => 0,
			'visible_ok_count'           => 0,
			'truncated'                  => false,
			'rows'                       => array(),
			'scope'                      => $scope,
			'scope_hash'                 => $this->scope_hash( $scope ),
			'snapshot_hash'              => $this->snapshot_hash( $scope ),
			'scope_label'                => sanitize_text_field( (string) ( $scope['scope_label'] ?? 'Catalogo de productos' ) ),
			'global_target_percent'      => (float) ( $scope['global_target_percent'] ?? 0 ),
			'blocking_for_monthly_close' => true,
			'discarded_ids'              => array(),
			'discarded_meta'             => array(),
			'discarded_count'            => 0,
		);
	}

	private function daily_key( array $scope ) {
		return self::DAILY_PREFIX . $this->scope_hash( $scope );
	}

	private function snapshot_key( array $scope ) {
		return self::SNAPSHOT_PREFIX . $this->snapshot_hash( $scope );
	}

	private function scope_hash( array $scope ) {
		return md5(
			wp_json_encode(
				array(
					'day_key'                => sanitize_text_field( (string) ( $scope['day_key'] ?? '' ) ),
					'exclude_categories_raw' => sanitize_text_field( (string) ( $scope['exclude_categories_raw'] ?? '' ) ),
					'cost_source'            => sanitize_key( (string) ( $scope['cost_source'] ?? '' ) ),
					'cost_meta_key'          => sanitize_key( (string) ( $scope['cost_meta_key'] ?? '' ) ),
					'catalog_version'        => sanitize_text_field( (string) ( $scope['catalog_version'] ?? '' ) ),
					'global_target_percent'  => round( (float) ( $scope['global_target_percent'] ?? 0 ), 6 ),
				)
			)
		);
	}

	private function snapshot_hash( array $scope ) {
		return md5(
			wp_json_encode(
				array(
					'exclude_categories_raw' => sanitize_text_field( (string) ( $scope['exclude_categories_raw'] ?? '' ) ),
					'cost_source'            => sanitize_key( (string) ( $scope['cost_source'] ?? '' ) ),
					'cost_meta_key'          => sanitize_key( (string) ( $scope['cost_meta_key'] ?? '' ) ),
					'catalog_version'        => sanitize_text_field( (string) ( $scope['catalog_version'] ?? '' ) ),
					'global_target_percent'  => round( (float) ( $scope['global_target_percent'] ?? 0 ), 6 ),
				)
			)
		);
	}

	private function resolve_cost_settings() {
		try {
			AnalysisModule::bootstrap();
			if ( class_exists( 'AFP_Settings' ) ) {
				return (array) \AFP_Settings::get_settings();
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}

		return array(
			'cost_source'   => 'yith',
			'cost_meta_key' => 'yith_cog_cost',
		);
	}

	private function parse_category_ids( $raw_value ) {
		$raw_value = sanitize_text_field( (string) $raw_value );

		if ( '' === $raw_value ) {
			return array();
		}

		try {
			AnalysisModule::bootstrap();
			if ( class_exists( 'AFP_Reports' ) ) {
				return array_values( array_map( 'absint', (array) \AFP_Reports::parse_exclude_categories( $raw_value ) ) );
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}

		$tokens = array_filter( array_map( 'trim', explode( ',', $raw_value ) ) );
		$ids    = array();

		foreach ( $tokens as $token ) {
			if ( is_numeric( $token ) ) {
				$ids[] = absint( $token );
				continue;
			}

			$term = get_term_by( 'slug', sanitize_title( $token ), 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	private function count_parent_products() {
		if ( ! class_exists( 'WP_Query' ) ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);

		return max( 0, (int) $query->found_posts );
	}

	private function query_parent_products( $page, $per_page ) {
		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish' ),
				'posts_per_page'         => max( 1, min( 100, (int) $per_page ) ),
				'paged'                  => max( 1, (int) $page ),
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);

		return array(
			'ids'      => array_values( array_map( 'absint', (array) $query->posts ) ),
			'total'    => max( 0, (int) $query->found_posts ),
			'has_more' => max( 0, (int) $page ) < max( 1, (int) $query->max_num_pages ),
		);
	}

	private function evaluate_product( $product, array $scope ) {
		$rows = array();

		if ( ! $product || ! is_object( $product ) ) {
			return $rows;
		}

		if ( $this->is_excluded_product( $product, $scope ) ) {
			return $rows;
		}

		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ) {
			$children = method_exists( $product, 'get_visible_children' )
				? (array) $product->get_visible_children()
				: (array) $product->get_children();

			foreach ( $children as $child_id ) {
				$variation = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $child_id ) ) : null;
				if ( ! $variation ) {
					continue;
				}

				$row = $this->evaluate_margin_row( $variation, $product, $scope );
				if ( ! empty( $row ) ) {
					$rows[] = $row;
				}
			}

			if ( ! empty( $rows ) ) {
				return $rows;
			}
		}

		$row = $this->evaluate_margin_row( $product, $product, $scope );
		if ( ! empty( $row ) ) {
			$rows[] = $row;
		}

		return $rows;
	}

	private function evaluate_margin_row( $product, $catalog_product, array $scope ) {
		$regular_price   = $this->resolve_regular_price( $product );
		$cost_details    = $this->resolve_cost_details( $product, sanitize_key( (string) ( $scope['cost_meta_key'] ?? '' ) ) );
		$cost            = (float) ( $cost_details['cost'] ?? 0 );
		$target_details  = $this->resolve_target_details( $product, $scope );
		$target_percent  = (float) ( $target_details['target_percent'] ?? 0 );
		$estimated_real  = $this->compute_estimated_real_price( $regular_price, $target_percent );
		$price_gap_pct   = $this->compute_price_gap_percent( $regular_price, $estimated_real );
		$margin_real_pct = $this->compute_real_margin_percent( $estimated_real, $cost );
		$strategy_mode   = $this->resolve_strategy_mode( $product );
		$global_percent  = (float) ( $scope['global_target_percent'] ?? 0 );
		$edit_product_id = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id()
			? (int) $product->get_parent_id()
			: (int) $product->get_id();
		$category_labels = $this->category_labels( $catalog_product );
		$inventory       = $this->resolve_inventory_details( $product );
		$classify        = $this->classify_row(
			array(
				'regular_price'   => $regular_price,
				'cost'            => $cost,
				'estimated_real'  => $estimated_real,
				'margin_real_pct' => $margin_real_pct,
				'target_percent'  => $target_percent,
				'global_percent'  => $global_percent,
				'target_inherited'=> ! empty( $target_details['target_inherited'] ),
				'strategy_mode'   => $strategy_mode,
			)
		);

		$row = array(
			'product_id'              => (int) $product->get_id(),
			'edit_product_id'         => $edit_product_id,
			'category_target_product_id' => $this->category_source_id( $catalog_product ),
			'product_name'            => sanitize_text_field( (string) $product->get_name() ),
			'product_internal_id'     => (int) $product->get_id(),
			'sku'                     => sanitize_text_field( (string) $product->get_sku() ),
			'product_type'            => sanitize_key( (string) $product->get_type() ),
			'category_ids'            => $this->category_ids( $catalog_product ),
			'category_labels'         => $category_labels,
			'category_label'          => sanitize_text_field( implode( ', ', array_slice( $category_labels, 0, 3 ) ) ),
			'regular_price'           => round( $regular_price, 6 ),
			'cost'                    => round( $cost, 6 ),
			'target_percent'          => round( $target_percent, 6 ),
			'target_inherited'        => ! empty( $target_details['target_inherited'] ),
			'target_source_label'     => sanitize_text_field( (string) ( $target_details['target_source_label'] ?? 'Hereda % global' ) ),
			'global_target_percent'   => round( $global_percent, 6 ),
			'estimated_real_price'    => round( $estimated_real, 6 ),
			'price_gap_percent'       => round( $price_gap_pct, 4 ),
			'real_margin_pct'         => round( $margin_real_pct, 4 ),
			'price_gap_amount'        => round( max( 0, $regular_price - $estimated_real ), 6 ),
			'real_margin_amount'      => round( $estimated_real - $cost, 6 ),
			'strategy_mode'           => $strategy_mode,
			'strategy_label'          => 'manual' === $strategy_mode ? 'Manual' : 'Segun regla',
			'inventory_current'       => $inventory['inventory_current'],
			'inventory_label'         => $inventory['inventory_label'],
			'inventory_tone'          => $inventory['inventory_tone'],
			'inventory_readonly'      => true,
			'inventory_managed'       => ! empty( $inventory['inventory_managed'] ),
			'last_inventory_at'       => $inventory['last_inventory_at'],
			'last_inventory_label'    => $inventory['last_inventory_label'],
			'cost_meta_key'           => sanitize_key( (string) ( $cost_details['meta_key'] ?? '' ) ),
			'cost_target_product_id'  => (int) ( $cost_details['target_product_id'] ?? $product->get_id() ),
			'cost_source_label'       => sanitize_text_field( (string) ( $cost_details['source_label'] ?? 'Costo del producto' ) ),
			'edit_url'                => esc_url_raw( admin_url( 'post.php?post=' . $edit_product_id . '&action=edit' ) ),
			'status'                  => sanitize_key( (string) ( $classify['status'] ?? 'ok' ) ),
			'status_label'            => sanitize_text_field( (string) ( $classify['label'] ?? 'OK' ) ),
			'status_help'             => sanitize_text_field( (string) ( $classify['help'] ?? '' ) ),
			'is_issue'                => in_array( sanitize_key( (string) ( $classify['status'] ?? '' ) ), array( 'critical', 'review' ), true ),
			'blocking_for_monthly_close' => 'critical' === sanitize_key( (string) ( $classify['status'] ?? '' ) ),
			'snapshot_discarded'      => false,
			'snapshot_discard_reason' => '',
			'snapshot_discarded_at'   => '',
		);

		$row['signature'] = $this->build_row_signature( $row );

		return $row;
	}

	private function resolve_regular_price( $product ) {
		$regular = (float) $product->get_regular_price();

		if ( $regular > 0 ) {
			return $regular;
		}

		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$parent = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product->get_parent_id() ) : null;
			if ( $parent ) {
				$regular = (float) $parent->get_regular_price();
			}
		}

		return max( 0, $regular );
	}

	private function resolve_cost_details( $product, $meta_key ) {
		$product_id = (int) $product->get_id();
		$cost       = (float) get_post_meta( $product_id, $meta_key, true );
		$target_id  = $product_id;
		$target_key = $meta_key;
		$source     = 'Costo del producto';

		if ( $cost <= 0 && method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$parent_id = (int) $product->get_parent_id();

			if ( '_wc_cog_cost' === $meta_key ) {
				$parent_variable_cost = (float) get_post_meta( $parent_id, '_wc_cog_cost_variable', true );
				if ( $parent_variable_cost > 0 ) {
					$cost       = $parent_variable_cost;
					$target_id  = $parent_id;
					$target_key = '_wc_cog_cost_variable';
					$source     = 'Costo variable del padre';
				}
			}

			if ( $cost <= 0 ) {
				$parent_cost = (float) get_post_meta( $parent_id, $meta_key, true );
				if ( $parent_cost > 0 ) {
					$cost       = $parent_cost;
					$target_id  = $parent_id;
					$target_key = $meta_key;
					$source     = 'Costo heredado del padre';
				}
			}
		}

		return array(
			'cost'              => max( 0, $cost ),
			'target_product_id' => $target_id,
			'meta_key'          => $target_key,
			'source_label'      => $source,
		);
	}

	private function resolve_target_details( $product, array $scope ) {
		$global_percent = $this->normalize_percent( (float) ( $scope['global_target_percent'] ?? 0 ) );
		$product_id     = (int) $product->get_id();
		$raw_value      = get_post_meta( $product_id, self::TARGET_PERCENT_META_KEY, true );

		if ( '' !== (string) $raw_value && is_numeric( $raw_value ) ) {
			return array(
				'target_percent'      => $this->normalize_percent( (float) $raw_value ),
				'target_inherited'    => false,
				'target_source_label' => 'Ajuste propio',
			);
		}

		return array(
			'target_percent'      => $global_percent,
			'target_inherited'    => true,
			'target_source_label' => 'Usa la regla general',
		);
	}

	private function resolve_strategy_mode( $product ) {
		$product_id = (int) $product->get_id();
		$raw_mode   = sanitize_key( (string) get_post_meta( $product_id, self::PRICING_MODE_META_KEY, true ) );

		if ( in_array( $raw_mode, array( 'formula', 'manual' ), true ) ) {
			return $raw_mode;
		}

		return 'formula';
	}

	private function classify_row( array $row ) {
		$regular_price   = max( 0, (float) ( $row['regular_price'] ?? 0 ) );
		$cost            = max( 0, (float) ( $row['cost'] ?? 0 ) );
		$estimated_real  = max( 0, (float) ( $row['estimated_real'] ?? 0 ) );
		$margin_real_pct = (float) ( $row['margin_real_pct'] ?? 0 );
		$target_percent  = max( 0, (float) ( $row['target_percent'] ?? 0 ) );
		$global_percent  = max( 0, (float) ( $row['global_percent'] ?? 0 ) );
		$strategy_mode   = $this->normalize_strategy_mode( (string) ( $row['strategy_mode'] ?? 'formula' ) );
		$deviation       = abs( $target_percent - $global_percent );

		if ( $regular_price <= 0 ) {
			return array(
				'status' => 'critical',
				'label'  => 'Critico',
				'help'   => 'No tiene precio publicado.',
			);
		}

		if ( $estimated_real <= 0 ) {
			return array(
				'status' => 'critical',
				'label'  => 'Critico',
				'help'   => 'El precio real estimado quedo invalido.',
			);
		}

		if ( $cost <= 0 ) {
			return array(
				'status' => 'critical',
				'label'  => 'Critico',
				'help'   => 'Falta el costo base del producto.',
			);
		}

		if ( $cost >= $estimated_real ) {
			return array(
				'status' => 'critical',
				'label'  => 'Critico',
				'help'   => 'El costo es mayor o igual al precio real estimado.',
			);
		}

		if ( 'manual' === $strategy_mode ) {
			return array(
				'status' => 'manual',
				'label'  => 'Manual',
				'help'   => 'Este producto usa estrategia manual y no se valida contra la formula global.',
			);
		}

		if ( $margin_real_pct <= self::REVIEW_MARGIN_THRESHOLD ) {
			return array(
				'status' => 'review',
				'label'  => 'Revisar',
				'help'   => 'El margen sobre el precio real estimado quedo bajo.',
			);
		}

		if ( empty( $row['target_inherited'] ) && $deviation >= self::REVIEW_TARGET_DEVIATION_THRESHOLD ) {
			return array(
				'status' => 'review',
				'label'  => 'Revisar',
				'help'   => 'El porcentaje objetivo se desvia bastante de la referencia global.',
			);
		}

		return array(
			'status' => 'ok',
			'label'  => 'OK',
			'help'   => 'Pricing consistente y margen sano.',
		);
	}

	private function is_excluded_product( $product, array $scope ) {
		$excluded = array_values( array_map( 'absint', (array) ( $scope['exclude_category_ids'] ?? array() ) ) );
		if ( empty( $excluded ) ) {
			return false;
		}

		return (bool) array_intersect( $excluded, $this->category_ids( $product ) );
	}

	private function category_ids( $product ) {
		$source_id = $this->category_source_id( $product );
		$terms = wp_get_post_terms( $source_id, 'product_cat', array( 'fields' => 'ids' ) );

		return is_array( $terms ) ? array_values( array_map( 'absint', $terms ) ) : array();
	}

	private function category_labels( $product ) {
		$source_id = $this->category_source_id( $product );
		$terms = wp_get_post_terms( $source_id, 'product_cat' );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_map(
				static function ( $term ) {
					return sanitize_text_field( (string) ( $term->name ?? '' ) );
				},
				$terms
			)
		);
	}

	private function resolve_inventory_details( $product ) {
		$tracking_id      = $this->inventory_tracking_source_id( $product );
		$tracking_product = $tracking_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $tracking_id ) : $product;
		$quantity         = is_object( $tracking_product ) && method_exists( $tracking_product, 'get_stock_quantity' ) ? $tracking_product->get_stock_quantity() : null;
		$stock_status     = is_object( $tracking_product ) && method_exists( $tracking_product, 'get_stock_status' ) ? sanitize_key( (string) $tracking_product->get_stock_status() ) : '';
		$managing_stock   = is_object( $tracking_product ) && method_exists( $tracking_product, 'managing_stock' ) ? (bool) $tracking_product->managing_stock() : false;
		$inventory_current = null;
		$inventory_label   = 'Sin control';
		$inventory_tone    = 'neutral';

		if ( null !== $quantity && '' !== (string) $quantity ) {
			$inventory_current = round( (float) $quantity, 2 );
		}

		if ( $managing_stock ) {
			if ( null === $inventory_current ) {
				$inventory_current = 0.0;
			}

			if ( $inventory_current <= 0 ) {
				$inventory_label = 'Inv. actual: ' . number_format_i18n( 0, 2 );
				$inventory_tone  = 'danger';
			} elseif ( $inventory_current <= self::LOW_STOCK_THRESHOLD ) {
				$inventory_label = 'Inv. actual: ' . number_format_i18n( $inventory_current, 2 );
				$inventory_tone  = 'warning';
			} else {
				$inventory_label = 'Inv. actual: ' . number_format_i18n( $inventory_current, 2 );
				$inventory_tone  = 'success';
			}
		} else {
			if ( in_array( $stock_status, array( 'outofstock', 'onbackorder' ), true ) ) {
				$inventory_label = 'Sin control';
				$inventory_tone  = 'neutral';
			} elseif ( 'instock' === $stock_status ) {
				$inventory_label = 'Sin control';
				$inventory_tone  = 'neutral';
			}
		}

		$last_inventory_at = $tracking_id > 0 ? sanitize_text_field( (string) get_post_meta( $tracking_id, self::LAST_POSITIVE_INVENTORY_AT_META_KEY, true ) ) : '';
		$last_inventory_label = '' !== $last_inventory_at
			? 'Ultimo inventario: ' . $this->format_user_datetime( $last_inventory_at )
			: 'Sin historial';

		return array(
			'inventory_current'    => $inventory_current,
			'inventory_label'      => $inventory_label,
			'inventory_tone'       => $inventory_tone,
			'inventory_managed'    => $managing_stock,
			'last_inventory_at'    => $last_inventory_at,
			'last_inventory_label' => $last_inventory_label,
		);
	}

	private function inventory_tracking_source_id( $product ) {
		if ( ! $product || ! is_object( $product ) ) {
			return 0;
		}

		if ( method_exists( $product, 'managing_stock' ) && $product->managing_stock() ) {
			return (int) $product->get_id();
		}

		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			return (int) $product->get_parent_id();
		}

		return (int) $product->get_id();
	}

	private function format_user_datetime( $mysql_datetime ) {
		$mysql_datetime = sanitize_text_field( (string) $mysql_datetime );

		if ( '' === $mysql_datetime ) {
			return '';
		}

		$timestamp = strtotime( $mysql_datetime );
		if ( false === $timestamp ) {
			return $mysql_datetime;
		}

		return wp_date( 'd/m/Y H:i', $timestamp );
	}

	private function merge_report_certification_state( array $view, array $daily, $source ) {
		$view['report_view_source']           = sanitize_key( (string) $source );
		$view['report_view_source_label']     = 'workspace' === $source ? 'vista activa' : ( 'daily' === $source ? 'revision del dia' : 'sin revision' );
		$view['certification_cache_valid']    = ! empty( $daily['cache_valid'] );
		$view['certification_checked_at']     = sanitize_text_field( (string) ( $daily['checked_at'] ?? '' ) );
		$view['certification_status']         = sanitize_key( (string) ( $daily['status'] ?? 'unknown' ) );
		$view['certification_issue_count']    = (int) ( $daily['issue_count'] ?? 0 );
		$view['certification_critical_count'] = (int) ( $daily['critical_count'] ?? 0 );
		$view['certification_review_count']   = (int) ( $daily['review_count'] ?? 0 );
		$view['certification_manual_count']   = (int) ( $daily['manual_count'] ?? 0 );
		$view['certification_ok_count']       = (int) ( $daily['ok_count'] ?? 0 );
		$view['certified_today']              = ! empty( $daily['certified_today'] );

		return $view;
	}

	private function visible_status_counts( array $rows ) {
		$counts = array(
			'critical_count' => 0,
			'review_count'   => 0,
			'manual_count'   => 0,
			'ok_count'       => 0,
			'issue_count'    => 0,
			'verified_count' => 0,
		);

		foreach ( $rows as $row ) {
			$status = sanitize_key( (string) ( $row['status'] ?? 'ok' ) );

			switch ( $status ) {
				case 'critical':
					++$counts['critical_count'];
					++$counts['issue_count'];
					break;
				case 'review':
					++$counts['review_count'];
					++$counts['issue_count'];
					break;
				case 'manual':
				case 'discarded':
					++$counts['manual_count'];
					++$counts['verified_count'];
					break;
				default:
					++$counts['ok_count'];
					++$counts['verified_count'];
					break;
			}
		}

		return $counts;
	}

	private function persist_snapshot_discard( array $scope, array $snapshot, array $discard_map ) {
		$discarded_ids  = array_values( array_filter( array_map( 'absint', (array) ( $snapshot['discarded_ids'] ?? array() ) ) ) );
		$discarded_meta = is_array( $snapshot['discarded_meta'] ?? null ) ? $snapshot['discarded_meta'] : array();

		foreach ( $discard_map as $product_id => $meta ) {
			$product_id = absint( $product_id );
			if ( $product_id <= 0 ) {
				continue;
			}

			if ( ! in_array( $product_id, $discarded_ids, true ) ) {
				$discarded_ids[] = $product_id;
			}

			$discarded_meta[ (string) $product_id ] = array(
				'reason'       => sanitize_text_field( (string) ( $meta['reason'] ?? 'Descartado en esta vista' ) ),
				'discarded_at' => sanitize_text_field( (string) ( $meta['discarded_at'] ?? $this->now() ) ),
			);
		}

		$snapshot['discarded_ids']   = array_values( array_unique( $discarded_ids ) );
		$snapshot['discarded_meta']  = $discarded_meta;
		$snapshot['discarded_count'] = count( $snapshot['discarded_ids'] );

		$this->save_snapshot_result( $scope, $snapshot );

		return $snapshot;
	}

	private function strip_snapshot_only_state( array $result ) {
		$result['discarded_ids']   = array();
		$result['discarded_meta']  = array();
		$result['discarded_count'] = 0;
		$result['rows']            = array_values(
			array_map(
				array( $this, 'normalize_row_snapshot_flags' ),
				(array) ( $result['rows'] ?? array() )
			)
		);

		return $result;
	}

	private function apply_snapshot_view_state( array $result ) {
		$discarded_lookup = array();
		foreach ( (array) ( $result['discarded_ids'] ?? array() ) as $discarded_id ) {
			$discarded_lookup[ absint( $discarded_id ) ] = true;
		}

		$discarded_meta = is_array( $result['discarded_meta'] ?? null ) ? $result['discarded_meta'] : array();
		$rows           = array();

		foreach ( (array) ( $result['rows'] ?? array() ) as $row ) {
			$product_id = (int) ( $row['product_id'] ?? 0 );
			$meta       = ! empty( $discarded_lookup[ $product_id ] ) ? (array) ( $discarded_meta[ (string) $product_id ] ?? array() ) : array();
			$rows[]     = $this->normalize_row_snapshot_flags( $row, $meta );
		}

		$result['rows']               = $rows;
		$result['listed_issue_count'] = 0;
		$result['listed_verified_count'] = 0;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['is_issue'] ) ) {
				++$result['listed_issue_count'];
				continue;
			}

			++$result['listed_verified_count'];
		}

		return $result;
	}

	private function normalize_row_snapshot_flags( array $row, array $discard_meta = array() ) {
		$row['snapshot_discarded']      = ! empty( $discard_meta );
		$row['snapshot_discard_reason'] = sanitize_text_field( (string) ( $discard_meta['reason'] ?? '' ) );
		$row['snapshot_discarded_at']   = sanitize_text_field( (string) ( $discard_meta['discarded_at'] ?? '' ) );
		$row['inventory_readonly']      = true;

		if ( empty( $discard_meta ) ) {
			return $row;
		}

		$row['base_status']      = sanitize_key( (string) ( $row['status'] ?? 'ok' ) );
		$row['base_status_label']= sanitize_text_field( (string) ( $row['status_label'] ?? 'OK' ) );
		$row['base_status_help'] = sanitize_text_field( (string) ( $row['status_help'] ?? '' ) );
		$row['status']           = 'discarded';
		$row['status_label']     = 'Descartado en esta vista';
		$row['status_help']      = '' !== $row['snapshot_discard_reason']
			? $row['snapshot_discard_reason']
			: 'Se saco temporalmente de Hallazgos hasta que actualices esta vista.';
		$row['is_issue']         = false;
		$row['blocking_for_monthly_close'] = false;

		return $row;
	}

	private function build_row_signature( array $row ) {
		$category_ids = array_values( array_filter( array_map( 'absint', (array) ( $row['category_ids'] ?? array() ) ) ) );
		sort( $category_ids );

		return md5(
			wp_json_encode(
				array(
					'product_id'             => (int) ( $row['product_id'] ?? 0 ),
					'cost_target_product_id' => (int) ( $row['cost_target_product_id'] ?? 0 ),
					'category_target_product_id' => (int) ( $row['category_target_product_id'] ?? 0 ),
					'cost_meta_key'          => sanitize_key( (string) ( $row['cost_meta_key'] ?? '' ) ),
					'regular_price'          => round( (float) ( $row['regular_price'] ?? 0 ), 6 ),
					'cost'                   => round( (float) ( $row['cost'] ?? 0 ), 6 ),
					'target_percent'         => round( (float) ( $row['target_percent'] ?? 0 ), 6 ),
					'target_inherited'       => ! empty( $row['target_inherited'] ),
					'strategy_mode'          => sanitize_key( (string) ( $row['strategy_mode'] ?? 'formula' ) ),
					'category_ids'           => $category_ids,
				)
			)
		);
	}

	private function category_source_id( $product ) {
		return method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) && $product->get_parent_id()
			? (int) $product->get_parent_id()
			: (int) $product->get_id();
	}

	private function normalize_term_ids( $raw_value ) {
		if ( is_array( $raw_value ) ) {
			return array_values( array_filter( array_map( 'absint', $raw_value ) ) );
		}

		$tokens = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $raw_value ) ) ) );

		return array_values( array_filter( array_map( 'absint', $tokens ) ) );
	}

	private function compute_estimated_real_price( $regular_price, $target_percent ) {
		$regular_price  = max( 0, (float) $regular_price );
		$target_percent = $this->normalize_percent( (float) $target_percent );
		$fraction       = $this->normalize_fraction( $target_percent / 100 );

		return round( max( 0, $regular_price * ( 1 - $fraction ) ), 6 );
	}

	private function compute_price_gap_percent( $regular_price, $estimated_real_price ) {
		$regular_price        = max( 0, (float) $regular_price );
		$estimated_real_price = max( 0, (float) $estimated_real_price );

		if ( $estimated_real_price <= 0 || $regular_price <= $estimated_real_price ) {
			return 0.0;
		}

		return round( ( ( $regular_price - $estimated_real_price ) / $estimated_real_price ) * 100, 4 );
	}

	private function compute_real_margin_percent( $estimated_real_price, $cost ) {
		$estimated_real_price = max( 0, (float) $estimated_real_price );
		$cost                 = max( 0, (float) $cost );

		if ( $estimated_real_price <= 0 ) {
			return 0.0;
		}

		return round( ( ( $estimated_real_price - $cost ) / $estimated_real_price ) * 100, 4 );
	}

	private function checked_today( $checked_at ) {
		$checked_at = sanitize_text_field( (string) $checked_at );
		if ( '' === $checked_at ) {
			return false;
		}

		return 0 === strpos( $checked_at, current_time( 'Y-m-d' ) );
	}

	private function normalize_percent( $percent ) {
		return round( max( 0, min( 95, (float) $percent ) ), 6 );
	}

	private function normalize_fraction( $fraction ) {
		$fraction = max( 0, (float) $fraction );

		if ( $fraction >= 0.995 ) {
			$fraction = 0.995;
		}

		return round( $fraction, 6 );
	}

	private function normalize_strategy_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );

		return in_array( $mode, array( 'formula', 'manual' ), true ) ? $mode : 'formula';
	}

	private function flush_product_runtime_cache( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		clean_post_cache( $product_id );
	}
}
