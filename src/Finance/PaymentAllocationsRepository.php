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

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT allocation.*, payment.payment_number, payment.payment_date, payment.payment_type,
					document.document_number, document.title AS document_title,
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

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT allocation.*, payment.payment_number, payment.payment_date, payment.payment_type,
					document.document_number, document.title AS document_title,
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

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT allocation.*, payment.payment_number, payment.payment_date, payment.payment_type,
					document.document_number, document.title AS document_title,
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

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT allocation.*, payment.payment_number, payment.payment_date, payment.payment_type,
					document.document_number, document.title AS document_title,
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
}
