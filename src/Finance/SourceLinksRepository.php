<?php

namespace ASDLabs\Finance\Finance;

final class SourceLinksRepository extends BaseRepository {
	protected $table_key = 'source_links';

	public function all( $limit = 50, $provider = '' ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb     = $this->db();
		$limit    = max( 1, (int) $limit );
		$provider = sanitize_key( $provider );

		if ( '' !== $provider ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *
					FROM {$this->table()}
					WHERE provider = %s
					ORDER BY id DESC
					LIMIT %d",
					$provider,
					$limit
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	public function provider_counts() {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb = $this->db();
		$rows = $wpdb->get_results(
			"SELECT
				provider,
				COUNT(*) AS total_links,
				COALESCE(SUM(CASE WHEN override_locked = 1 THEN 1 ELSE 0 END), 0) AS locked_links,
				MAX(last_synced_at) AS last_synced_at
			FROM {$this->table()}
			GROUP BY provider",
			ARRAY_A
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$provider               = sanitize_key( $row['provider'] );
			$counts[ $provider ]    = array(
				'total_links'   => isset( $row['total_links'] ) ? (int) $row['total_links'] : 0,
				'locked_links'  => isset( $row['locked_links'] ) ? (int) $row['locked_links'] : 0,
				'last_synced_at' => isset( $row['last_synced_at'] ) ? (string) $row['last_synced_at'] : '',
			);
		}

		return $counts;
	}

	public function find_by_provider_object_external( $provider, $object_type, $external_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$provider    = sanitize_key( $provider );
		$object_type = sanitize_key( $object_type );
		$external_id = sanitize_text_field( $external_id );

		if ( '' === $provider || '' === $object_type || '' === $external_id ) {
			return null;
		}

		return $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE provider = %s
				AND object_type = %s
				AND external_id = %s
				LIMIT 1",
				$provider,
				$object_type,
				$external_id
			),
			ARRAY_A
		);
	}

	public function find_for_document( $document_id ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		return $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE document_id = %d
				ORDER BY id DESC",
				absint( $document_id )
			),
			ARRAY_A
		);
	}

	public function set_override_lock_for_document( $document_id, $locked ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$result = $this->db()->update(
			$this->table(),
			array(
				'override_locked' => $locked ? 1 : 0,
				'updated_at'      => $this->now(),
			),
			array( 'document_id' => absint( $document_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function upsert( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_source_links_missing', 'La tabla de vinculos externos aun no esta disponible.' );
		}

		$document_id = absint( $data['document_id'] ?? 0 );
		$provider    = sanitize_key( $data['provider'] ?? '' );
		$object_type = sanitize_key( $data['object_type'] ?? '' );
		$external_id = sanitize_text_field( $data['external_id'] ?? '' );

		if ( $document_id <= 0 || '' === $provider || '' === $object_type || '' === $external_id ) {
			return $this->error( 'asdl_fin_source_link_required', 'El vinculo externo necesita documento, proveedor, tipo y referencia externa.' );
		}

		$wpdb     = $this->db();
		$now      = $this->now();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				FROM {$this->table()}
				WHERE provider = %s
				AND object_type = %s
				AND external_id = %s
				LIMIT 1",
				$provider,
				$object_type,
				$external_id
			)
		);

		$existing_row = $existing ? $this->find_by_provider_object_external( $provider, $object_type, $external_id ) : null;

		$payload = array(
			'document_id'     => $document_id,
			'provider'        => $provider,
			'object_type'     => $object_type,
			'external_id'     => $external_id,
			'external_ref'    => sanitize_text_field( $data['external_ref'] ?? '' ),
			'sync_hash'       => sanitize_text_field( $data['sync_hash'] ?? '' ),
			'last_synced_at'  => sanitize_text_field( $data['last_synced_at'] ?? '' ) ?: null,
			'last_seen_at'    => sanitize_text_field( $data['last_seen_at'] ?? '' ) ?: $now,
			'override_locked' => array_key_exists( 'override_locked', $data ) ? ( ! empty( $data['override_locked'] ) ? 1 : 0 ) : ( ! empty( $existing_row['override_locked'] ) ? 1 : 0 ),
			'meta_json'       => ! empty( $data['meta_json'] ) ? wp_json_encode( $data['meta_json'] ) : null,
			'updated_at'      => $now,
		);

		if ( $existing ) {
			$result = $wpdb->update(
				$this->table(),
				$payload,
				array( 'id' => absint( $existing ) ),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				return $this->error( 'asdl_fin_source_link_update', 'No se pudo actualizar el vinculo externo.' );
			}

			return (int) $existing;
		}

		$payload['created_at'] = $now;

		$result = $wpdb->insert(
			$this->table(),
			$payload,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_source_link_insert', 'No se pudo guardar el vinculo externo.' );
		}

		return (int) $wpdb->insert_id;
	}
}
