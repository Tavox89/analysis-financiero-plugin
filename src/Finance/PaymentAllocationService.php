<?php

namespace ASDLabs\Finance\Finance;

final class PaymentAllocationService extends BaseRepository {
	protected $table_key = 'payment_allocations';

	public function allocate( array $data ) {
		$payments    = new PaymentsRepository();
		$documents   = new DocumentsRepository();
		$allocations = new PaymentAllocationsRepository();

		if ( ! $payments->exists() || ! $documents->exists() || ! $allocations->exists() ) {
			return $this->error( 'asdl_fin_allocation_tables', 'El esquema necesario para asignaciones aun no esta disponible.' );
		}

		$payment_id  = absint( $data['payment_id'] ?? 0 );
		$document_id = absint( $data['document_id'] ?? 0 );
		$amount      = max( 0, (float) ( $data['amount'] ?? 0 ) );
		$notes       = sanitize_textarea_field( $data['notes'] ?? '' );
		$manage_transaction = ! array_key_exists( 'manage_transaction', $data ) || ! empty( $data['manage_transaction'] );

		if ( $payment_id <= 0 || $document_id <= 0 || $amount <= 0 ) {
			return $this->error( 'asdl_fin_allocation_required', 'Debes seleccionar un pago, un documento y un monto valido.' );
		}

		$payment  = $payments->find( $payment_id );
		$document = $documents->find( $document_id );

		if ( empty( $payment ) ) {
			return $this->error( 'asdl_fin_payment_missing', 'No se encontro el pago seleccionado.' );
		}

		if ( empty( $document ) ) {
			return $this->error( 'asdl_fin_document_missing', 'No se encontro el documento seleccionado.' );
		}

		$allow_non_available_payment = ! empty( $data['allow_non_available_payment'] )
			&& 'salary_advance' === sanitize_key( (string) ( $payment['method_key'] ?? '' ) );

		if ( 'posted' !== (string) $payment['status'] ) {
			return $this->error( 'asdl_fin_payment_not_posted', 'Solo puedes asignar pagos que ya esten registrados.' );
		}

		if ( 'void' === (string) $document['financial_status'] ) {
			return $this->error( 'asdl_fin_document_void', 'No puedes asignar un pago a un documento anulado.' );
		}

		if ( ! $allow_non_available_payment && (float) $payment['available_amount'] <= 0 ) {
			return $this->error( 'asdl_fin_payment_empty', 'Este pago ya no tiene monto disponible para asignar.' );
		}

		if ( (float) $document['balance'] <= 0 ) {
			return $this->error( 'asdl_fin_document_settled', 'El documento seleccionado ya no tiene saldo pendiente.' );
		}

		if ( '' !== (string) $payment['currency'] && '' !== (string) $document['currency'] && (string) $payment['currency'] !== (string) $document['currency'] ) {
			return $this->error( 'asdl_fin_currency_mismatch', 'La moneda del pago no coincide con la del documento.' );
		}

		if ( ! empty( $payment['contact_id'] ) && ! empty( $document['contact_id'] ) && (int) $payment['contact_id'] !== (int) $document['contact_id'] ) {
			return $this->error( 'asdl_fin_contact_mismatch', 'El pago y el documento pertenecen a contactos distintos.' );
		}

		if ( 'collection' === (string) $payment['payment_type'] && 'payable' === (string) $document['balance_nature'] ) {
			return $this->error( 'asdl_fin_direction_mismatch', 'Un cobro no debe asignarse a un documento por pagar.' );
		}

		if ( 'disbursement' === (string) $payment['payment_type'] && 'receivable' === (string) $document['balance_nature'] ) {
			return $this->error( 'asdl_fin_direction_mismatch', 'Un pago no debe asignarse a un documento por cobrar.' );
		}

		if ( ! $allow_non_available_payment && $amount > (float) $payment['available_amount'] ) {
			return $this->error( 'asdl_fin_allocation_available', 'El monto supera lo disponible en el pago seleccionado.' );
		}

		if ( $amount > (float) $document['balance'] ) {
			return $this->error( 'asdl_fin_allocation_balance', 'El monto supera el saldo pendiente del documento.' );
		}

		$new_available = $allow_non_available_payment
			? (float) $payment['available_amount']
			: round( (float) $payment['available_amount'] - $amount, 6 );
		$new_paid      = round( (float) $document['paid_total'] + $amount, 6 );
		$new_balance   = round( max( 0, (float) $document['total'] - $new_paid ), 6 );
		$new_status    = $this->resolve_document_payment_status( $new_paid, $new_balance, (string) $document['due_date'] );

		if ( $manage_transaction ) {
			$this->begin_transaction();
		}

		$allocation_id = $allocations->create_record( $payment_id, $document_id, $amount, $notes );
		if ( is_wp_error( $allocation_id ) ) {
			if ( $manage_transaction ) {
				$this->rollback_transaction();
			}
			return $allocation_id;
		}

		if ( ! $allow_non_available_payment && ! $payments->set_available_amount( $payment_id, $new_available ) ) {
			if ( $manage_transaction ) {
				$this->rollback_transaction();
			}
			return $this->error( 'asdl_fin_payment_update', 'No se pudo actualizar el saldo disponible del pago.' );
		}

		if ( ! $documents->set_payment_progress( $document_id, $new_paid, $new_balance, $new_status ) ) {
			if ( $manage_transaction ) {
				$this->rollback_transaction();
			}
			return $this->error( 'asdl_fin_document_update', 'No se pudo actualizar el saldo del documento.' );
		}

		if ( $manage_transaction ) {
			$this->commit_transaction();
		}

		return array(
			'allocation_id'     => (int) $allocation_id,
			'payment_id'        => $payment_id,
			'document_id'       => $document_id,
			'amount'            => $amount,
			'available_amount'  => $new_available,
			'document_balance'  => $new_balance,
			'document_status'   => $new_status,
		);
	}

	private function resolve_document_payment_status( $paid_total, $balance, $due_date ) {
		if ( $balance <= 0 ) {
			return 'paid';
		}

		if ( $paid_total > 0 ) {
			return 'partial';
		}

		if ( $due_date && $due_date < gmdate( 'Y-m-d' ) ) {
			return 'overdue';
		}

		return 'pending';
	}
}
