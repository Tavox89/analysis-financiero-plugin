<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class PaymentAllocationsRepository extends BaseRepository {
	protected $table_key = 'payment_allocations';

	public function all( $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb             = $this->db();
		$limit            = max( 1, (int) $limit );
		$payments_table   = Tables::name( 'payments' );
		$documents_table  = Tables::name( 'documents' );
		$contacts_table   = Tables::name( 'contacts' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT allocation.*, payment.payment_number, payment.payment_date, payment.payment_type, payment.currency AS payment_currency,
					document.document_number, document.title AS document_title, document.currency AS document_currency,
					contact.display_name AS contact_name
				FROM {$this->table()} allocation
				LEFT JOIN {$payments_table} payment ON payment.id = allocation.payment_id
				LEFT JOIN {$documents_table} document ON document.id = allocation.document_id
				LEFT JOIN {$contacts_table} contact ON contact.id = COALESCE(payment.contact_id, document.contact_id)
				ORDER BY allocation.id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_allocation_row' ), $rows );
	}

	public function for_contact( $contact_id, $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$contact_id       = absint( $contact_id );
		$limit            = max( 1, (int) $limit );
		$wpdb             = $this->db();
		$payments_table   = Tables::name( 'payments' );
		$documents_table  = Tables::name( 'documents' );
		$contacts_table   = Tables::name( 'contacts' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT allocation.*, payment.payment_number, payment.payment_date, payment.payment_type, payment.currency AS payment_currency,
					document.document_number, document.title AS document_title, document.currency AS document_currency,
					contact.display_name AS contact_name
				FROM {$this->table()} allocation
				LEFT JOIN {$payments_table} payment ON payment.id = allocation.payment_id
				LEFT JOIN {$documents_table} document ON document.id = allocation.document_id
				LEFT JOIN {$contacts_table} contact ON contact.id = COALESCE(payment.contact_id, document.contact_id)
				WHERE payment.contact_id = %d OR document.contact_id = %d
				ORDER BY allocation.id DESC
				LIMIT %d",
				$contact_id,
				$contact_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_allocation_row' ), $rows );
	}

	public function for_payment( $payment_id, $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$payment_id        = absint( $payment_id );
		$limit             = max( 1, (int) $limit );
		$wpdb              = $this->db();
		$payments_table    = Tables::name( 'payments' );
		$documents_table   = Tables::name( 'documents' );
		$contacts_table    = Tables::name( 'contacts' );

		if ( $payment_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT allocation.*, payment.payment_number, payment.payment_date, payment.payment_type, payment.currency AS payment_currency,
					document.document_number, document.title AS document_title, document.currency AS document_currency,
					contact.display_name AS contact_name
				FROM {$this->table()} allocation
				LEFT JOIN {$payments_table} payment ON payment.id = allocation.payment_id
				LEFT JOIN {$documents_table} document ON document.id = allocation.document_id
				LEFT JOIN {$contacts_table} contact ON contact.id = COALESCE(payment.contact_id, document.contact_id)
				WHERE allocation.payment_id = %d
				ORDER BY allocation.id DESC
				LIMIT %d",
				$payment_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_allocation_row' ), $rows );
	}

	public function for_document( $document_id, $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$document_id       = absint( $document_id );
		$limit             = max( 1, (int) $limit );
		$wpdb              = $this->db();
		$payments_table    = Tables::name( 'payments' );
		$documents_table   = Tables::name( 'documents' );
		$contacts_table    = Tables::name( 'contacts' );

		if ( $document_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT allocation.*, payment.payment_number, payment.payment_date, payment.payment_type, payment.currency AS payment_currency,
					document.document_number, document.title AS document_title, document.currency AS document_currency,
					contact.display_name AS contact_name
				FROM {$this->table()} allocation
				LEFT JOIN {$payments_table} payment ON payment.id = allocation.payment_id
				LEFT JOIN {$documents_table} document ON document.id = allocation.document_id
				LEFT JOIN {$contacts_table} contact ON contact.id = COALESCE(payment.contact_id, document.contact_id)
				WHERE allocation.document_id = %d
				ORDER BY allocation.id DESC
				LIMIT %d",
				$document_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_allocation_row' ), $rows );
	}

	public function create_record( $payment_id, $document_id, $amount, $notes = '' ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_allocations_missing', 'La tabla de asignaciones aun no esta disponible.' );
		}

		$amount = (float) $amount;
		if ( $payment_id <= 0 || $document_id <= 0 || $amount <= 0 ) {
			return $this->error( 'asdl_fin_allocation_invalid', 'La asignacion necesita un pago, un documento y un monto valido.' );
		}

		$wpdb = $this->db();
		$now  = $this->now();

		$result = $wpdb->insert(
			$this->table(),
			array(
				'payment_id'  => absint( $payment_id ),
				'document_id' => absint( $document_id ),
				'amount'      => $amount,
				'notes'       => sanitize_textarea_field( $notes ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%f', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_allocation_insert', 'No se pudo guardar la asignacion.' );
		}

		return (int) $wpdb->insert_id;
	}

	public function update_snapshot_fields( $allocation_id, array $snapshot ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$allocation_id = absint( $allocation_id );
		if ( $allocation_id <= 0 ) {
			return false;
		}

		$payload = array(
			'updated_at' => $this->now(),
		);
		$formats = array( '%s' );

		if ( array_key_exists( 'currency', $snapshot ) ) {
			$payload['currency'] = sanitize_text_field( (string) ( $snapshot['currency'] ?? '' ) );
			$formats[]           = '%s';
		}

		foreach ( array( 'document_paid_before', 'document_balance_before', 'document_paid_after', 'document_balance_after', 'payment_available_before', 'payment_available_after' ) as $float_key ) {
			if ( ! array_key_exists( $float_key, $snapshot ) ) {
				continue;
			}

			$payload[ $float_key ] = round( (float) $snapshot[ $float_key ], 6 );
			$formats[]             = '%f';
		}

		foreach ( array( 'document_payment_status_before', 'document_payment_status_after' ) as $text_key ) {
			if ( ! array_key_exists( $text_key, $snapshot ) ) {
				continue;
			}

			$payload[ $text_key ] = sanitize_key( (string) $snapshot[ $text_key ] );
			$formats[]            = '%s';
		}

		$result = $this->db()->update(
			$this->table(),
			$payload,
			array( 'id' => $allocation_id ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	public function count_for_document( $document_id ) {
		if ( ! $this->has_table() ) {
			return 0;
		}

		return (int) $this->db()->get_var(
			$this->db()->prepare(
				"SELECT COUNT(*)
				FROM {$this->table()}
				WHERE document_id = %d",
				absint( $document_id )
			)
		);
	}

	public function delete_ids( array $allocation_ids ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$allocation_ids = array_values(
			array_filter(
				array_map( 'absint', $allocation_ids )
			)
		);

		if ( empty( $allocation_ids ) ) {
			return true;
		}

		$placeholders = implode( ',', array_fill( 0, count( $allocation_ids ), '%d' ) );
		$sql          = $this->db()->prepare(
			"DELETE FROM {$this->table()} WHERE id IN ({$placeholders})",
			...$allocation_ids
		);
		$result       = $this->db()->query( $sql );

		return false !== $result;
	}

	private function normalize_allocation_row( array $row ) {
		$row['id']          = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['payment_id']  = isset( $row['payment_id'] ) ? (int) $row['payment_id'] : 0;
		$row['document_id'] = isset( $row['document_id'] ) ? (int) $row['document_id'] : 0;
		$row['amount']      = isset( $row['amount'] ) ? round( (float) $row['amount'], 6 ) : 0.0;
		$row['currency']    = sanitize_text_field(
			(string) (
				$row['currency']
				?? $row['document_currency']
				?? $row['payment_currency']
				?? 'USD'
			)
		);

		foreach ( array( 'document_paid_before', 'document_balance_before', 'document_paid_after', 'document_balance_after', 'payment_available_before', 'payment_available_after' ) as $key ) {
			$row[ $key ] = $this->normalize_nullable_decimal( $row[ $key ] ?? null );
		}

		foreach ( array( 'document_payment_status_before', 'document_payment_status_after' ) as $key ) {
			$row[ $key ] = ! empty( $row[ $key ] ) ? sanitize_key( (string) $row[ $key ] ) : '';
		}

		$row['snapshot_recorded'] = null !== $row['document_balance_before'] || null !== $row['document_balance_after'];

		return $row;
	}

	private function normalize_nullable_decimal( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return round( (float) $value, 6 );
	}
}
