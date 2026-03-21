<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class OrderAssumptionBatchesRepository extends BaseRepository {
	protected $table_key = 'order_assumption_batches';

	public function create_batch( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_order_assumption_batches_missing', 'La tabla de lotes de asuncion aun no esta disponible.' );
		}

		$payload = array(
			'batch_key'          => sanitize_text_field( (string) ( $data['batch_key'] ?? '' ) ),
			'contact_id'         => absint( $data['contact_id'] ?? 0 ),
			'origin'             => sanitize_key( (string) ( $data['origin'] ?? 'profile_order_assumption' ) ),
			'mode'               => sanitize_key( (string) ( $data['mode'] ?? 'expense' ) ),
			'status'             => sanitize_key( (string) ( $data['status'] ?? 'pending' ) ),
			'preview_signature'  => sanitize_text_field( (string) ( $data['preview_signature'] ?? '' ) ),
			'assumed_total'      => round( (float) ( $data['assumed_total'] ?? 0 ), 6 ),
			'current_total'      => round( (float) ( $data['current_total'] ?? 0 ), 6 ),
			'historical_total'   => round( (float) ( $data['historical_total'] ?? 0 ), 6 ),
			'item_count'         => max( 0, (int) ( $data['item_count'] ?? 0 ) ),
			'blocked_count'      => max( 0, (int) ( $data['blocked_count'] ?? 0 ) ),
			'processed_count'    => max( 0, (int) ( $data['processed_count'] ?? 0 ) ),
			'created_by'         => ! empty( $data['created_by'] ) ? absint( $data['created_by'] ) : get_current_user_id(),
			'meta_json'          => ! empty( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null,
			'created_at'         => $this->now(),
			'updated_at'         => $this->now(),
		);

		$result = $this->db()->insert(
			$this->table(),
			$payload,
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_order_assumption_batch_insert', 'No se pudo registrar el lote de asuncion.' );
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

		$payload = array(
			'updated_at' => $this->now(),
		);
		$formats = array( '%s' );

		foreach ( array( 'origin', 'mode', 'status', 'preview_signature' ) as $text_key ) {
			if ( ! array_key_exists( $text_key, $data ) ) {
				continue;
			}

			$payload[ $text_key ] = 'preview_signature' === $text_key
				? sanitize_text_field( (string) $data[ $text_key ] )
				: sanitize_key( (string) $data[ $text_key ] );
			$formats[] = '%s';
		}

		foreach ( array( 'contact_id', 'item_count', 'blocked_count', 'processed_count' ) as $int_key ) {
			if ( ! array_key_exists( $int_key, $data ) ) {
				continue;
			}

			$payload[ $int_key ] = absint( $data[ $int_key ] );
			$formats[]           = '%d';
		}

		foreach ( array( 'assumed_total', 'current_total', 'historical_total' ) as $float_key ) {
			if ( ! array_key_exists( $float_key, $data ) ) {
				continue;
			}

			$payload[ $float_key ] = round( (float) $data[ $float_key ], 6 );
			$formats[]             = '%f';
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
		$table = Tables::name( 'order_assumption_batch_items' );
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			$payload = array(
				'batch_id'          => absint( $batch_id ),
				'sort_index'        => max( 0, (int) ( $item['sort_index'] ?? 0 ) ),
				'source_kind'       => sanitize_key( (string) ( $item['source_kind'] ?? 'current_live' ) ),
				'provider'          => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
				'external_order_id' => ! empty( $item['external_order_id'] ) ? absint( $item['external_order_id'] ) : null,
				'document_id'       => ! empty( $item['document_id'] ) ? absint( $item['document_id'] ) : null,
				'order_number'      => sanitize_text_field( (string) ( $item['order_number'] ?? '' ) ),
				'issue_date'        => $this->sanitize_date( $item['issue_date'] ?? '' ),
				'balance_before'    => round( (float) ( $item['balance_before'] ?? 0 ), 6 ),
				'status'            => sanitize_key( (string) ( $item['status'] ?? 'pending' ) ),
				'error_message'     => ! empty( $item['error_message'] ) ? sanitize_textarea_field( (string) $item['error_message'] ) : null,
				'meta_json'         => ! empty( $item['meta_json'] ) ? wp_json_encode( $item['meta_json'] ) : null,
				'created_at'        => $this->now(),
				'updated_at'        => $this->now(),
			);

			$result = $this->db()->insert(
				$table,
				$payload,
				array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $result ) {
				return false;
			}
		}

		return true;
	}

	public function update_item( $item_id, array $data ) {
		$table = Tables::name( 'order_assumption_batch_items' );
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$item_id = absint( $item_id );
		if ( $item_id <= 0 ) {
			return false;
		}

		$payload = array(
			'updated_at' => $this->now(),
		);
		$formats = array( '%s' );

		foreach ( array( 'source_kind', 'provider', 'order_number', 'status' ) as $text_key ) {
			if ( ! array_key_exists( $text_key, $data ) ) {
				continue;
			}

			$payload[ $text_key ] = in_array( $text_key, array( 'source_kind', 'provider', 'status' ), true )
				? sanitize_key( (string) $data[ $text_key ] )
				: sanitize_text_field( (string) $data[ $text_key ] );
			$formats[] = '%s';
		}

		foreach ( array( 'batch_id', 'sort_index', 'external_order_id', 'document_id' ) as $int_key ) {
			if ( ! array_key_exists( $int_key, $data ) ) {
				continue;
			}

			$payload[ $int_key ] = ! empty( $data[ $int_key ] ) ? absint( $data[ $int_key ] ) : null;
			$formats[]           = '%d';
		}

		if ( array_key_exists( 'balance_before', $data ) ) {
			$payload['balance_before'] = round( (float) $data['balance_before'], 6 );
			$formats[]                 = '%f';
		}

		if ( array_key_exists( 'issue_date', $data ) ) {
			$payload['issue_date'] = $this->sanitize_date( $data['issue_date'] ?? '' );
			$formats[]             = '%s';
		}

		if ( array_key_exists( 'error_message', $data ) ) {
			$payload['error_message'] = ! empty( $data['error_message'] ) ? sanitize_textarea_field( (string) $data['error_message'] ) : null;
			$formats[]                = '%s';
		}

		if ( array_key_exists( 'meta_json', $data ) ) {
			$payload['meta_json'] = ! empty( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null;
			$formats[]            = '%s';
		}

		$result = $this->db()->update(
			$table,
			$payload,
			array( 'id' => $item_id ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	public function find( $batch_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				absint( $batch_id )
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->normalize_batch_row( $row );
	}

	public function find_by_preview_signature( $contact_id, $origin, $preview_signature ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE contact_id = %d
				AND origin = %s
				AND preview_signature = %s
				ORDER BY id DESC
				LIMIT 1",
				absint( $contact_id ),
				sanitize_key( (string) $origin ),
				sanitize_text_field( (string) $preview_signature )
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->normalize_batch_row( $row );
	}

	public function find_active_for_contact( $contact_id, $origin = 'profile_order_assumption' ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE contact_id = %d
				AND origin = %s
				AND status IN ('pending', 'running')
				ORDER BY id DESC
				LIMIT 1",
				absint( $contact_id ),
				sanitize_key( (string) $origin )
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->normalize_batch_row( $row );
	}

	public function list_pending_items( $batch_id, $limit ) {
		return $this->list_items_by_status( $batch_id, array( 'pending' ), $limit );
	}

	public function list_applied_items( $batch_id, $limit = 2000 ) {
		return $this->list_items_by_status( $batch_id, array( 'applied' ), $limit );
	}

	public function list_batch_items( $batch_id, $limit = 200 ) {
		return $this->list_items_by_status( $batch_id, array(), $limit );
	}

	public function list_batch_errors( $batch_id, $limit = 50 ) {
		$rows = $this->list_items_by_status( $batch_id, array( 'error', 'skipped' ), $limit );

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) {
					return ! empty( $row['error_message'] );
				}
			)
		);
	}

	public function count_items_by_status( $batch_id, $status ) {
		$table = Tables::name( 'order_assumption_batch_items' );
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		return (int) $this->db()->get_var(
			$this->db()->prepare(
				"SELECT COUNT(*)
				FROM {$table}
				WHERE batch_id = %d
				AND status = %s",
				absint( $batch_id ),
				sanitize_key( (string) $status )
			)
		);
	}

	private function list_items_by_status( $batch_id, array $statuses, $limit ) {
		$table = Tables::name( 'order_assumption_batch_items' );
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$limit  = max( 1, min( 5000, (int) $limit ) );
		$params = array( absint( $batch_id ) );
		$where  = array( 'batch_id = %d' );

		if ( ! empty( $statuses ) ) {
			$where[] = 'status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params  = array_merge( $params, array_map( 'sanitize_key', $statuses ) );
		}

		$params[] = $limit;

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$table}
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY sort_index ASC, id ASC
				LIMIT %d",
				$params
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_item_row' ), $rows );
	}

	private function normalize_batch_row( array $row ) {
		$row['id']              = (int) ( $row['id'] ?? 0 );
		$row['contact_id']      = (int) ( $row['contact_id'] ?? 0 );
		$row['item_count']      = (int) ( $row['item_count'] ?? 0 );
		$row['blocked_count']   = (int) ( $row['blocked_count'] ?? 0 );
		$row['processed_count'] = (int) ( $row['processed_count'] ?? 0 );

		foreach ( array( 'assumed_total', 'current_total', 'historical_total' ) as $key ) {
			$row[ $key ] = round( (float) ( $row[ $key ] ?? 0 ), 6 );
		}

		$meta        = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
		$row['meta'] = is_array( $meta ) ? $meta : array();

		return $row;
	}

	private function normalize_item_row( array $row ) {
		$row['id']                = (int) ( $row['id'] ?? 0 );
		$row['batch_id']          = (int) ( $row['batch_id'] ?? 0 );
		$row['sort_index']        = (int) ( $row['sort_index'] ?? 0 );
		$row['external_order_id'] = (int) ( $row['external_order_id'] ?? 0 );
		$row['document_id']       = (int) ( $row['document_id'] ?? 0 );
		$row['balance_before']    = round( (float) ( $row['balance_before'] ?? 0 ), 6 );

		$meta        = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
		$row['meta'] = is_array( $meta ) ? $meta : array();

		return $row;
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
