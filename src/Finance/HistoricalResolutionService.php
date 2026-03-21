<?php

namespace ASDLabs\Finance\Finance;

final class HistoricalResolutionService {
	const OPTION_JOB_STATE = 'asdl_fin_historical_resolution_job_state';

	private $fiscal_years;
	private $index;
	private $rollups;
	private $batches;
	private $rebuilds;

	public function __construct() {
		$this->fiscal_years = new FiscalYearService();
		$this->index        = new CommerceOrderIndexRepository();
		$this->rollups      = new CommerceContactRollupsRepository();
		$this->batches      = new HistoricalResolutionBatchesRepository();
		$this->rebuilds     = new HistoricalIndexRebuildService();
	}

	public function get_status_snapshot() {
		return array(
			'job'     => $this->get_job_state(),
			'batches' => $this->batches->list_batches( 12 ),
		);
	}

	public function preview( array $args, $strict_commit = false ) {
		$normalized = $this->normalize_args( $args, $strict_commit );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$preview_limit = max( 25, min( 500, absint( $normalized['batch_size'] ?? 200 ) ) );
		$summary       = $this->index->summarize_resolution_candidates( $normalized );
		$items         = $this->index->list_resolution_candidates( $normalized, 0, $preview_limit );
		$year_stats = array();
		for ( $year = (int) $normalized['fiscal_year_from']; $year <= (int) $normalized['fiscal_year_to']; $year++ ) {
			$year_stats[] = array(
				'fiscal_year' => $year,
				'label'       => $this->fiscal_years->label_for_year( $year ),
				'is_indexed'  => $this->rebuilds->is_year_indexed( $year ),
				'is_closable' => $this->rebuilds->is_year_closable( $year ),
				'is_special_case' => $this->rebuilds->is_year_special_case_allowed( $year ),
			);
		}

		return array(
			'filters'         => $normalized,
			'summary'         => $summary,
			'items'           => $items,
			'years'           => $year_stats,
			'preview_limit'   => $preview_limit,
			'items_truncated' => (int) ( $summary['item_count'] ?? 0 ) > count( $items ),
		);
	}

	public function start_batch( array $args ) {
		$preview = $this->preview( $args, true );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$summary = $preview['summary'] ?? array();
		if ( empty( $summary['item_count'] ) ) {
			return new \WP_Error( 'asdl_fin_historical_resolution_empty', 'No hay pedidos historicos elegibles para este cierre administrativo.' );
		}

		$filters  = $preview['filters'] ?? array();
		$batch_id = $this->batches->create_batch(
			array(
				'batch_key'        => wp_generate_uuid4(),
				'fiscal_year_from' => (int) ( $filters['fiscal_year_from'] ?? 0 ),
				'fiscal_year_to'   => (int) ( $filters['fiscal_year_to'] ?? 0 ),
				'status'           => 'running',
				'reason_key'       => sanitize_key( (string) ( $filters['reason_key'] ?? 'historical_cleanup' ) ),
				'note'             => sanitize_textarea_field( (string) ( $filters['note'] ?? '' ) ),
				'item_count'       => (int) ( $summary['item_count'] ?? 0 ),
				'balance_total'    => (float) ( $summary['balance_total'] ?? 0 ),
				'processed_count'  => 0,
				'processed_total'  => 0,
				'meta_json'        => array(
					'filters' => $filters,
				),
			)
		);

		if ( is_wp_error( $batch_id ) ) {
			return $batch_id;
		}

		$state = array(
			'type'            => 'historical_resolution',
			'status'          => 'running',
			'batch_id'        => $batch_id,
			'batch_size'      => max( 25, min( 500, absint( $filters['batch_size'] ?? 200 ) ) ),
			'cursor_id'       => 0,
			'fiscal_year_from'=> (int) ( $filters['fiscal_year_from'] ?? 0 ),
			'fiscal_year_to'  => (int) ( $filters['fiscal_year_to'] ?? 0 ),
			'filters'         => $filters,
			'item_count'      => (int) ( $summary['item_count'] ?? 0 ),
			'balance_total'   => (float) ( $summary['balance_total'] ?? 0 ),
			'processed_count' => 0,
			'processed_total' => 0.0,
			'last_batch'      => 0,
			'started_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);

		update_option( self::OPTION_JOB_STATE, $state, false );

		return $this->get_job_state();
	}

	public function continue_batch() {
		$state = $this->get_job_state();
		if ( empty( $state['status'] ) || 'running' !== $state['status'] ) {
			return new \WP_Error( 'asdl_fin_historical_resolution_idle', 'No hay un cierre administrativo historico ejecutandose.' );
		}

		$filters = isset( $state['filters'] ) && is_array( $state['filters'] ) ? $state['filters'] : array();
		$cursor  = absint( $state['cursor_id'] ?? 0 );
		$limit   = max( 25, min( 500, absint( $state['batch_size'] ?? 200 ) ) );
		$rows    = $this->index->list_resolution_candidates( $filters, $cursor, $limit );

		if ( empty( $rows ) ) {
			$this->batches->update_batch(
				(int) ( $state['batch_id'] ?? 0 ),
				array(
					'status'          => 'completed',
					'processed_count' => (int) ( $state['processed_count'] ?? 0 ),
					'processed_total' => (float) ( $state['processed_total'] ?? 0 ),
				)
			);
			$this->rebuild_rollups_for_range(
				(int) ( $state['fiscal_year_from'] ?? 0 ),
				(int) ( $state['fiscal_year_to'] ?? 0 )
			);
			$state['status']       = 'completed';
			$state['completed_at'] = current_time( 'mysql' );
			$state['updated_at']   = current_time( 'mysql' );
			update_option( self::OPTION_JOB_STATE, $state, false );
			$this->rebuilds->bump_data_version();

			return $state;
		}

		$row_ids        = array_map( static function ( array $row ) { return (int) ( $row['id'] ?? 0 ); }, $rows );
		$batch_id       = (int) ( $state['batch_id'] ?? 0 );
		$reason_key     = sanitize_key( (string) ( $filters['reason_key'] ?? 'historical_cleanup' ) );
		$note           = sanitize_textarea_field( (string) ( $filters['note'] ?? '' ) );
		$apply_result   = $this->index->apply_historical_resolution( $row_ids, $batch_id, $reason_key, $note );

		if ( false === $apply_result ) {
			return new \WP_Error( 'asdl_fin_historical_resolution_apply', 'No se pudo aplicar el cierre administrativo al lote actual.' );
		}

		$items          = array();
		$processed_total = 0.0;
		$last_id        = $cursor;
		foreach ( $rows as $row ) {
			$balance_before = round( (float) ( $row['balance'] ?? 0 ), 6 );
			$processed_total += $balance_before;
			$last_id         = max( $last_id, (int) ( $row['id'] ?? 0 ) );
			$items[]         = array(
				'order_index_id'        => (int) ( $row['id'] ?? 0 ),
				'provider'              => sanitize_key( (string) ( $row['provider'] ?? '' ) ),
				'external_order_id'     => (int) ( $row['external_order_id'] ?? 0 ),
				'previous_status'       => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'new_resolution_status' => 'administratively_closed',
				'balance_before'        => $balance_before,
				'balance_after'         => 0,
				'note'                  => $note,
				'meta_json'             => array(
					'order_number' => sanitize_text_field( (string) ( $row['order_number'] ?? '' ) ),
					'fiscal_year'  => (int) ( $row['fiscal_year'] ?? 0 ),
				),
			);
			$this->annotate_order( $row, $batch_id, $reason_key, $note );
		}

		$this->batches->append_items( $batch_id, $items );
		$state['cursor_id']       = $last_id;
		$state['processed_count'] = (int) ( $state['processed_count'] ?? 0 ) + count( $items );
		$state['processed_total'] = round( (float) ( $state['processed_total'] ?? 0 ) + $processed_total, 6 );
		$state['last_batch']      = count( $items );
		$state['updated_at']      = current_time( 'mysql' );
		update_option( self::OPTION_JOB_STATE, $state, false );

		$this->batches->update_batch(
			$batch_id,
			array(
				'processed_count' => $state['processed_count'],
				'processed_total' => $state['processed_total'],
				'status'          => 'running',
			)
		);

		return $state;
	}

	public function get_job_state() {
		$state = get_option( self::OPTION_JOB_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	private function normalize_args( array $args, $strict_commit = false ) {
		$active_year       = (int) $this->fiscal_years->get_context()['start_year'];
		$fiscal_year_from  = absint( $args['fiscal_year_from'] ?? 0 );
		$fiscal_year_to    = absint( $args['fiscal_year_to'] ?? $fiscal_year_from );
		$fiscal_year_from  = $fiscal_year_from > 0 ? $fiscal_year_from : $fiscal_year_to;
		$fiscal_year_to    = $fiscal_year_to > 0 ? $fiscal_year_to : $fiscal_year_from;
		$contact_id        = absint( $args['contact_id'] ?? 0 );
		$provider          = sanitize_key( (string) ( $args['provider'] ?? 'all' ) );
		$search            = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$reason_key        = sanitize_key( (string) ( $args['reason_key'] ?? 'historical_cleanup' ) );
		$note              = sanitize_textarea_field( (string) ( $args['note'] ?? '' ) );
		$batch_size        = max( 25, min( 500, absint( $args['batch_size'] ?? 200 ) ) );
		$special_previous_year = ! empty( $args['special_previous_year'] );
		$previous_year     = $active_year - 1;
		$selected_row_ids  = $this->sanitize_selected_row_ids( $args['selected_row_ids'] ?? '' );

		if ( $fiscal_year_from <= 0 || $fiscal_year_to <= 0 ) {
			return new \WP_Error( 'asdl_fin_historical_resolution_years', 'Debes indicar un rango fiscal valido para el cierre historico.' );
		}

		if ( $fiscal_year_from > $fiscal_year_to ) {
			$temp             = $fiscal_year_from;
			$fiscal_year_from = $fiscal_year_to;
			$fiscal_year_to   = $temp;
		}

		if ( $fiscal_year_to >= $active_year ) {
			return new \WP_Error( 'asdl_fin_historical_resolution_guard', 'El cierre administrativo no puede tocar el ejercicio fiscal actual.' );
		}

		$includes_previous_year = $fiscal_year_from <= $previous_year && $fiscal_year_to >= $previous_year;
		if ( $includes_previous_year ) {
			if ( ! $special_previous_year ) {
				return new \WP_Error( 'asdl_fin_historical_resolution_previous_year', 'El ejercicio inmediatamente anterior solo puede cerrarse bajo Caso especial.' );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error( 'asdl_fin_historical_resolution_previous_year_cap', 'Solo un administrador puede ejecutar el Caso especial sobre el ejercicio inmediatamente anterior.' );
			}

			if ( $strict_commit && '' === $note ) {
				return new \WP_Error( 'asdl_fin_historical_resolution_previous_year_note', 'Debes escribir una nota obligatoria para aplicar el Caso especial del ejercicio inmediatamente anterior.' );
			}
		} elseif ( $fiscal_year_to >= ( $active_year - 1 ) ) {
			return new \WP_Error( 'asdl_fin_historical_resolution_guard', 'El cierre administrativo solo puede tocar ejercicios anteriores al inmediatamente anterior. El ejercicio actual y el anterior estan bloqueados.' );
		}

		for ( $year = $fiscal_year_from; $year <= $fiscal_year_to; $year++ ) {
			if ( ! $this->rebuilds->is_year_indexed( $year ) ) {
				return new \WP_Error( 'asdl_fin_historical_resolution_unindexed', 'Debes indexar primero todos los ejercicios incluidos en el cierre historico.' );
			}
		}

		return array(
			'fiscal_year_from'  => $fiscal_year_from,
			'fiscal_year_to'    => $fiscal_year_to,
			'contact_id'        => $contact_id,
			'provider'          => $provider,
			'search'            => $search,
			'reason_key'        => $reason_key,
			'note'              => $note,
			'batch_size'        => $batch_size,
			'special_previous_year' => $special_previous_year,
			'only_without_paid' => ! empty( $args['only_without_paid'] ),
			'min_balance'       => isset( $args['min_balance'] ) && '' !== (string) $args['min_balance'] ? (float) $args['min_balance'] : null,
			'max_balance'       => isset( $args['max_balance'] ) && '' !== (string) $args['max_balance'] ? (float) $args['max_balance'] : null,
			'selected_row_ids'  => $selected_row_ids,
		);
	}

	private function sanitize_selected_row_ids( $raw ) {
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'absint', $raw ) ) );
		}

		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					'absint',
					preg_split( '/\s*,\s*/', $raw )
				)
			)
		);
	}

	private function rebuild_rollups_for_range( $from, $to ) {
		$from = absint( $from );
		$to   = absint( $to );
		if ( $from <= 0 || $to <= 0 ) {
			return;
		}

		for ( $year = $from; $year <= $to; $year++ ) {
			$this->rollups->rebuild_for_year( $year );
		}
	}

	private function annotate_order( array $row, $batch_id, $reason_key, $note ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order_id = absint( $row['external_order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$message = sprintf(
			'Finanzas ASD: pedido excluido de la cobranza operativa por cierre administrativo historico. Lote #%1$d, motivo %2$s, ejercicio %3$d.',
			(int) $batch_id,
			$reason_key,
			(int) ( $row['fiscal_year'] ?? 0 )
		);

		if ( '' !== $note ) {
			$message .= ' Nota: ' . $note;
		}

		$order->add_order_note( $message );
		$order->update_meta_data(
			'_asdl_fin_historical_resolution',
			array(
				'batch_id'    => (int) $batch_id,
				'reason_key'  => $reason_key,
				'note'        => $note,
				'resolved_at' => current_time( 'mysql' ),
				'resolved_by' => get_current_user_id(),
			)
		);
		$order->save();
	}
}
