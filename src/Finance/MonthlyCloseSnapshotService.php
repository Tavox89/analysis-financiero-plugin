<?php

namespace ASDLabs\Finance\Finance;

final class MonthlyCloseSnapshotService extends BaseRepository {
	const REPORT_KEY_MASTER = 'financial_master_monthly';

	protected $table_key = 'report_snapshots';

	public function create_snapshot( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_report_snapshot_table', 'La tabla de snapshots del cierre mensual no esta disponible.' );
		}

		$month_key = $this->sanitize_month_key( $data['month_key'] ?? '' );
		if ( '' === $month_key ) {
			return $this->error( 'asdl_fin_report_snapshot_month', 'Debes indicar un mes calendario valido para generar el cierre mensual.' );
		}

		$range_from = $this->sanitize_date( $data['range_from'] ?? '' );
		$range_to   = $this->sanitize_date( $data['range_to'] ?? '' );

		if ( ! $range_from || ! $range_to ) {
			return $this->error( 'asdl_fin_report_snapshot_range', 'No se pudo resolver el rango del cierre mensual.' );
		}

		$report_key = sanitize_key( (string) ( $data['report_key'] ?? self::REPORT_KEY_MASTER ) );
		if ( '' === $report_key ) {
			$report_key = self::REPORT_KEY_MASTER;
		}

		$version_no = $this->next_version_no( $month_key, $report_key );
		$created_by = get_current_user_id() > 0 ? (int) get_current_user_id() : null;
		$payload    = is_array( $data['payload'] ?? null ) ? (array) $data['payload'] : array();
		$filters    = is_array( $data['filters'] ?? null ) ? (array) $data['filters'] : array();
		$fiscal     = is_array( $data['fiscal_context'] ?? null ) ? (array) $data['fiscal_context'] : array();
		$now        = $this->now();

		$inserted = false !== $this->db()->insert(
			$this->table(),
			array(
				'report_key'         => $report_key,
				'month_key'          => $month_key,
				'range_from'         => $range_from,
				'range_to'           => $range_to,
				'fiscal_context_json'=> wp_json_encode( $fiscal ),
				'version_no'         => $version_no,
				'is_official'        => ! empty( $data['is_official'] ) ? 1 : 0,
				'status'             => ! empty( $data['is_official'] ) ? 'official' : sanitize_key( (string) ( $data['status'] ?? 'generated' ) ),
				'filters_json'       => wp_json_encode( $filters ),
				'payload_json'       => wp_json_encode( $payload ),
				'created_by'         => $created_by,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				null === $created_by ? '%s' : '%d',
				'%s',
				'%s',
			)
		);

		if ( ! $inserted ) {
			return $this->error( 'asdl_fin_report_snapshot_create', 'No se pudo guardar la nueva version del cierre mensual.' );
		}

		$snapshot_id = (int) $this->db()->insert_id;

		if ( ! empty( $data['is_official'] ) ) {
			$this->mark_official( $snapshot_id );
		}

		return $snapshot_id;
	}

	public function find( $snapshot_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$snapshot_id = absint( $snapshot_id );
		if ( $snapshot_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$snapshot_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate_snapshot( $row ) : null;
	}

	public function list_versions( $month_key, $report_key = self::REPORT_KEY_MASTER, $limit = 24 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$month_key = $this->sanitize_month_key( $month_key );
		if ( '' === $month_key ) {
			return array();
		}

		$report_key = sanitize_key( (string) $report_key );
		$limit      = max( 1, min( 100, (int) $limit ) );
		$rows       = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE month_key = %s
					AND report_key = %s
				ORDER BY version_no DESC, id DESC
				LIMIT %d",
				$month_key,
				$report_key,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_snapshot' ), is_array( $rows ) ? $rows : array() );
	}

	public function find_official_for_month( $month_key, $report_key = self::REPORT_KEY_MASTER ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$month_key = $this->sanitize_month_key( $month_key );
		if ( '' === $month_key ) {
			return null;
		}

		$report_key = sanitize_key( (string) $report_key );
		$row        = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE month_key = %s
					AND report_key = %s
					AND is_official = 1
				ORDER BY version_no DESC, id DESC
				LIMIT 1",
				$month_key,
				$report_key
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate_snapshot( $row ) : null;
	}

	public function mark_official( $snapshot_id ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_report_snapshot_table', 'La tabla de snapshots del cierre mensual no esta disponible.' );
		}

		$snapshot = $this->find( $snapshot_id );
		if ( empty( $snapshot['id'] ) ) {
			return $this->error( 'asdl_fin_report_snapshot_missing', 'No se encontro la version del cierre mensual seleccionada.' );
		}

		$this->begin_transaction();

		$this->db()->update(
			$this->table(),
			array(
				'is_official' => 0,
				'status'      => 'generated',
				'updated_at'  => $this->now(),
			),
			array(
				'month_key'  => (string) $snapshot['month_key'],
				'report_key' => (string) $snapshot['report_key'],
			),
			array( '%d', '%s', '%s' ),
			array( '%s', '%s' )
		);

		$result = $this->db()->update(
			$this->table(),
			array(
				'is_official' => 1,
				'status'      => 'official',
				'updated_at'  => $this->now(),
			),
			array( 'id' => (int) $snapshot['id'] ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_report_snapshot_official', 'No se pudo marcar esta version como oficial.' );
		}

		$this->commit_transaction();

		return true;
	}

	public function sanitize_month_key( $value ) {
		$value = sanitize_text_field( (string) $value );

		return preg_match( '/^\d{4}-\d{2}$/', $value ) ? $value : '';
	}

	private function next_version_no( $month_key, $report_key ) {
		$current = (int) $this->db()->get_var(
			$this->db()->prepare(
				"SELECT COALESCE(MAX(version_no), 0)
				FROM {$this->table()}
				WHERE month_key = %s
					AND report_key = %s",
				$month_key,
				$report_key
			)
		);

		return $current + 1;
	}

	private function hydrate_snapshot( array $row ) {
		$row['id']             = (int) ( $row['id'] ?? 0 );
		$row['version_no']     = (int) ( $row['version_no'] ?? 0 );
		$row['is_official']    = ! empty( $row['is_official'] );
		$row['created_by']     = (int) ( $row['created_by'] ?? 0 );
		$row['fiscal_context'] = $this->decode_json_array( $row['fiscal_context_json'] ?? '' );
		$row['filters']        = $this->decode_json_array( $row['filters_json'] ?? '' );
		$row['payload']        = $this->decode_json_array( $row['payload_json'] ?? '' );

		return $row;
	}

	private function decode_json_array( $json ) {
		$decoded = json_decode( (string) $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
