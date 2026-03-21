<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class HistoricalResolutionBatchesRepository extends BaseRepository {
	protected $table_key = 'historical_resolution_batches';

	public function create_batch( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_resolution_batches_missing', 'La tabla de lotes de cierre historico aun no esta disponible.' );
		}

		$payload = array(
			'batch_key'         => sanitize_text_field( (string) ( $data['batch_key'] ?? '' ) ),
			'fiscal_year_from'  => absint( $data['fiscal_year_from'] ?? 0 ),
			'fiscal_year_to'    => absint( $data['fiscal_year_to'] ?? 0 ),
			'status'            => sanitize_key( (string) ( $data['status'] ?? 'pending' ) ),
			'reason_key'        => sanitize_key( (string) ( $data['reason_key'] ?? '' ) ),
			'note'              => sanitize_textarea_field( (string) ( $data['note'] ?? '' ) ),
			'item_count'        => max( 0, (int) ( $data['item_count'] ?? 0 ) ),
			'balance_total'     => round( (float) ( $data['balance_total'] ?? 0 ), 6 ),
			'processed_count'   => max( 0, (int) ( $data['processed_count'] ?? 0 ) ),
			'processed_total'   => round( (float) ( $data['processed_total'] ?? 0 ), 6 ),
			'created_by'        => ! empty( $data['created_by'] ) ? absint( $data['created_by'] ) : get_current_user_id(),
			'meta_json'         => ! empty( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null,
			'created_at'        => $this->now(),
			'updated_at'        => $this->now(),
		);

		$result = $this->db()->insert(
			$this->table(),
			$payload,
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%d', '%f', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_resolution_batch_insert', 'No se pudo registrar el lote de cierre historico.' );
		}

		return (int) $this->db()->insert_id;
	}

	public function update_batch( $batch_id, array $data ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$batch_id = absint( $batch_id );
		if ( $batch_id <= 0 ) {
			return false;
		}

		$payload = array( 'updated_at' => $this->now() );
		$formats = array( '%s' );

		if ( array_key_exists( 'status', $data ) ) {
			$payload['status'] = sanitize_key( (string) $data['status'] );
			$formats[]         = '%s';
		}

		foreach ( array( 'item_count', 'processed_count', 'fiscal_year_from', 'fiscal_year_to' ) as $int_key ) {
			if ( array_key_exists( $int_key, $data ) ) {
				$payload[ $int_key ] = absint( $data[ $int_key ] );
				$formats[]           = '%d';
			}
		}

		foreach ( array( 'balance_total', 'processed_total' ) as $float_key ) {
			if ( array_key_exists( $float_key, $data ) ) {
				$payload[ $float_key ] = round( (float) $data[ $float_key ], 6 );
				$formats[]             = '%f';
			}
		}

		foreach ( array( 'reason_key', 'note' ) as $text_key ) {
			if ( array_key_exists( $text_key, $data ) ) {
				$payload[ $text_key ] = 'reason_key' === $text_key
					? sanitize_key( (string) $data[ $text_key ] )
					: sanitize_textarea_field( (string) $data[ $text_key ] );
				$formats[] = '%s';
			}
		}

		if ( array_key_exists( 'meta_json', $data ) ) {
			$payload['meta_json'] = ! empty( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null;
			$formats[]            = '%s';
		}

		$result = $this->db()->update(
			$this->table(),
			$payload,
			array( 'id' => $batch_id ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	public function append_items( $batch_id, array $items ) {
		$table = Tables::name( 'historical_resolution_items' );
		if ( empty( $items ) ) {
			return true;
		}

		foreach ( $items as $item ) {
			$payload = array(
				'batch_id'              => absint( $batch_id ),
				'order_index_id'        => absint( $item['order_index_id'] ?? 0 ),
				'provider'              => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
				'external_order_id'     => ! empty( $item['external_order_id'] ) ? absint( $item['external_order_id'] ) : null,
				'previous_status'       => sanitize_key( (string) ( $item['previous_status'] ?? '' ) ),
				'new_resolution_status' => sanitize_key( (string) ( $item['new_resolution_status'] ?? '' ) ),
				'balance_before'        => round( (float) ( $item['balance_before'] ?? 0 ), 6 ),
				'balance_after'         => round( (float) ( $item['balance_after'] ?? 0 ), 6 ),
				'note'                  => sanitize_textarea_field( (string) ( $item['note'] ?? '' ) ),
				'meta_json'             => ! empty( $item['meta_json'] ) ? wp_json_encode( $item['meta_json'] ) : null,
				'created_at'            => $this->now(),
				'updated_at'            => $this->now(),
			);

			$result = $this->db()->insert(
				$table,
				$payload,
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s' )
			);

			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	public function list_batches( $limit = 20 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		return $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				ORDER BY id DESC
				LIMIT %d",
				max( 1, min( 100, (int) $limit ) )
			),
			ARRAY_A
		);
	}

	public function find( $batch_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		return $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				absint( $batch_id )
			),
			ARRAY_A
		);
	}

	public function list_batch_items( $batch_id, $limit = 200 ) {
		$table = Tables::name( 'historical_resolution_items' );
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		return $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$table}
				WHERE batch_id = %d
				ORDER BY id ASC
				LIMIT %d",
				absint( $batch_id ),
				max( 1, min( 1000, (int) $limit ) )
			),
			ARRAY_A
		);
	}

	private function table_exists( $table_name ) {
		$table_name = (string) $table_name;
		if ( '' === $table_name ) {
			return false;
		}

		$found = $this->db()->get_var(
			$this->db()->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);

		return $found === $table_name;
	}
}
