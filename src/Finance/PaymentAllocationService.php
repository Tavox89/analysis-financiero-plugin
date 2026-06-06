<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

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
		$currency = (string) ( $document['currency'] ?? $payment['currency'] ?? '' );

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

		if ( ! $allow_non_available_payment && $this->money_balance_is_zero( (float) $payment['available_amount'], (string) ( $payment['currency'] ?? $currency ) ) ) {
			return $this->error( 'asdl_fin_payment_empty', 'Este pago ya no tiene monto disponible para asignar.' );
		}

		$document_balance = $this->normalize_balance_amount( (float) ( $document['balance'] ?? 0 ), $currency );

		if ( $this->money_balance_is_zero( $document_balance, $currency ) ) {
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

		if ( ! $allow_non_available_payment && $amount > $this->normalize_balance_amount( (float) ( $payment['available_amount'] ?? 0 ), (string) ( $payment['currency'] ?? $currency ) ) ) {
			return $this->error( 'asdl_fin_allocation_available', 'El monto supera lo disponible en el pago seleccionado.' );
		}

		if ( $amount > $document_balance ) {
			return $this->error( 'asdl_fin_allocation_balance', 'El monto supera el saldo pendiente del documento.' );
		}

		$document_paid_before           = round( (float) ( $document['paid_total'] ?? 0 ), 6 );
		$document_balance_before        = $document_balance;
		$document_payment_status_before = sanitize_key( (string) ( $document['payment_status'] ?? '' ) );
		$payment_available_before       = $this->normalize_balance_amount( (float) ( $payment['available_amount'] ?? 0 ), (string) ( $payment['currency'] ?? $currency ) );
		$new_available = $allow_non_available_payment
			? (float) $payment['available_amount']
			: $this->normalize_balance_amount( (float) $payment['available_amount'] - $amount, (string) ( $payment['currency'] ?? $currency ) );
		$new_paid      = round( (float) $document['paid_total'] + $amount, 6 );
		$new_balance   = $this->normalize_balance_amount( (float) $document['total'] - $new_paid, $currency );
		if ( $this->money_balance_is_zero( $new_balance, $currency ) ) {
			$new_balance = 0.0;
			$new_paid    = round( (float) $document['total'], 6 );
		}
		$new_status    = $this->resolve_document_payment_status( $new_paid, $new_balance, (string) $document['due_date'], $currency );

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

		$snapshot_payload = array(
			'currency'                       => sanitize_text_field( (string) $currency ),
			'document_paid_before'           => $document_paid_before,
			'document_balance_before'        => $document_balance_before,
			'document_payment_status_before' => $document_payment_status_before,
			'document_paid_after'            => $new_paid,
			'document_balance_after'         => $new_balance,
			'document_payment_status_after'  => $new_status,
			'payment_available_before'       => $payment_available_before,
			'payment_available_after'        => $new_available,
		);

		if ( method_exists( $allocations, 'update_snapshot_fields' ) && false === $allocations->update_snapshot_fields( $allocation_id, $snapshot_payload ) ) {
			if ( $manage_transaction ) {
				$this->rollback_transaction();
			}
			return $this->error( 'asdl_fin_allocation_snapshot', 'No se pudo congelar el saldo antes y despues de la asignacion.' );
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

		if ( $this->document_affects_order_views( $document ) ) {
			OrderSyncService::invalidate_cached_views();
		}

		return array(
			'allocation_id'                   => (int) $allocation_id,
			'payment_id'                      => $payment_id,
			'document_id'                     => $document_id,
			'contact_id'                      => ! empty( $document['contact_id'] ) ? (int) $document['contact_id'] : ( ! empty( $payment['contact_id'] ) ? (int) $payment['contact_id'] : 0 ),
			'amount'                          => $amount,
			'currency'                        => sanitize_text_field( (string) $currency ),
			'available_amount'                => $new_available,
			'payment_available_before'        => $payment_available_before,
			'payment_available_after'         => $new_available,
			'document_paid_before'            => $document_paid_before,
			'document_paid_after'             => $new_paid,
			'document_balance_before'         => $document_balance_before,
			'document_balance_after'          => $new_balance,
			'document_payment_status_before'  => $document_payment_status_before,
			'document_payment_status_after'   => $new_status,
			'document_balance'                => $new_balance,
			'document_status'                 => $new_status,
		);
	}

	private function document_affects_order_views( array $document ) {
		$document_type      = sanitize_key( (string) ( $document['document_type'] ?? '' ) );
		$source_type        = sanitize_key( (string) ( $document['source_type'] ?? '' ) );
		$external_reference = (string) ( $document['external_reference'] ?? '' );

		if ( in_array( $document_type, array( 'woo_sale' ), true ) ) {
			return true;
		}

		if ( in_array( $source_type, array( 'woocommerce', 'openpos' ), true ) ) {
			return true;
		}

		return 0 === strpos( $external_reference, 'shop_order:' );
	}

	private function resolve_document_payment_status( $paid_total, $balance, $due_date, $currency = '' ) {
		if ( $this->money_balance_is_zero( $balance, $currency ) ) {
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
