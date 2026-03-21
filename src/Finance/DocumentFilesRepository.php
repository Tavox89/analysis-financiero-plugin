<?php

namespace ASDLabs\Finance\Finance;

final class DocumentFilesRepository extends BaseRepository {
	protected $table_key = 'document_files';

	public function find( $file_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				absint( $file_id )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function for_document( $document_id ) {
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

	public function find_for_api( $file_id ) {
		$row = $this->find( $file_id );

		if ( empty( $row ) ) {
			return null;
		}

		return $this->map_for_api( $row );
	}

	public function for_document_api( $document_id ) {
		return array_map( array( $this, 'map_for_api' ), $this->for_document( $document_id ) );
	}

	public function create_record( $document_id, $attachment_id, $file_kind = 'supporting_document', $title = '' ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_document_files_missing', 'La tabla de adjuntos aun no esta disponible.' );
		}

		$document_id   = absint( $document_id );
		$attachment_id = absint( $attachment_id );

		if ( $document_id <= 0 || $attachment_id <= 0 ) {
			return $this->error( 'asdl_fin_document_file_required', 'El adjunto necesita un movimiento y un archivo validos.' );
		}

		$wpdb = $this->db();
		$now  = $this->now();

		$result = $wpdb->insert(
			$this->table(),
			array(
				'document_id'   => $document_id,
				'attachment_id' => $attachment_id,
				'file_kind'     => sanitize_key( $file_kind ),
				'title'         => sanitize_text_field( $title ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_document_file_insert', 'No se pudo vincular el comprobante al movimiento.' );
		}

		return (int) $wpdb->insert_id;
	}

	public function store_uploaded_file( $document_id, $field_name = 'document_file', $file_kind = 'supporting_document' ) {
		if ( empty( $_FILES[ $field_name ] ) || ! is_array( $_FILES[ $field_name ] ) ) {
			return null;
		}

		$file = $_FILES[ $field_name ];
		if ( empty( $file['name'] ) || (int) $file['error'] === UPLOAD_ERR_NO_FILE ) {
			return null;
		}

		if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
			return $this->error( 'asdl_fin_document_file_upload', 'No se pudo subir el comprobante seleccionado.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload(
			$field_name,
			0,
			array(
				'post_title' => 'Comprobante movimiento #' . absint( $document_id ),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$result = $this->create_record(
			$document_id,
			$attachment_id,
			$file_kind,
			get_the_title( $attachment_id )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'            => (int) $result,
			'attachment_id' => (int) $attachment_id,
		);
	}

	private function map_for_api( array $row ) {
		$attachment_id = ! empty( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
		$attachment    = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		$file_url      = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : '';
		$file_path     = $attachment_id > 0 ? get_attached_file( $attachment_id ) : '';

		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'document_id'   => (int) ( $row['document_id'] ?? 0 ),
			'attachment_id' => $attachment_id,
			'file_kind'     => sanitize_key( (string) ( $row['file_kind'] ?? '' ) ),
			'title'         => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
			'created_at'    => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			'updated_at'    => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
			'attachment'    => array(
				'id'         => $attachment_id,
				'title'      => $attachment ? sanitize_text_field( (string) $attachment->post_title ) : '',
				'url'        => $file_url ? esc_url_raw( $file_url ) : '',
				'mime_type'  => $attachment_id > 0 ? sanitize_text_field( (string) get_post_mime_type( $attachment_id ) ) : '',
				'file_name'  => $file_path ? sanitize_file_name( wp_basename( $file_path ) ) : '',
				'is_missing' => ! $attachment,
			),
		);
	}
}
