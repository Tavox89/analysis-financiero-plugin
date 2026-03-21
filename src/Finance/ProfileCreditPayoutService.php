<?php

namespace ASDLabs\Finance\Finance;

final class ProfileCreditPayoutService extends BaseRepository {
	public function pay_profile( array $data ) {
		$contact_id   = absint( $data['contact_id'] ?? 0 );
		$total        = max( 0, (float) ( $data['total'] ?? 0 ) );
		$payment_date = $this->sanitize_date( $data['payment_date'] ?? '' );
		$currency     = $this->sanitize_currency_code(
			$data['currency'] ?? '',
			'USD',
			'asdl_fin_profile_payout_currency',
			'Debes seleccionar una moneda valida para pagar al perfil.'
		);

		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_profile_payout_contact', 'Debes indicar el perfil al que vas a pagar.' );
		}

		if ( $total <= 0 ) {
			return $this->error( 'asdl_fin_profile_payout_total', 'Debes indicar un monto valido para pagar al perfil.' );
		}

		if ( empty( $payment_date ) ) {
			return $this->error( 'asdl_fin_profile_payout_date', 'Debes indicar una fecha valida para el pago al perfil.' );
		}

		if ( is_wp_error( $currency ) ) {
			return $currency;
		}

		$payable_documents = $this->get_payable_documents( $contact_id, (string) $currency );
		if ( empty( $payable_documents ) ) {
			return $this->error(
				'asdl_fin_profile_payout_missing',
				sprintf( 'Este perfil no tiene deuda documentada abierta en %s para pagar en este momento.', $currency )
			);
		}

		$payable_total = $this->sum_document_balances( $payable_documents );
		if ( $payable_total <= 0 ) {
			return $this->error( 'asdl_fin_profile_payout_missing', 'Este perfil no tiene deuda documentada abierta lista para pago manual.' );
		}

		if ( $total - $payable_total > 0.00001 ) {
			return $this->error(
				'asdl_fin_profile_payout_excess',
				sprintf(
					'No puedes pagar mas de %1$s en deuda documentada abierta para este perfil.',
					number_format_i18n( $payable_total, 2 )
				)
			);
		}

		$payments_service   = new PaymentsRepository();
		$allocation_service = new PaymentAllocationService();
		$notes              = sanitize_textarea_field( $data['notes'] ?? '' );
		$payment_notes      = '' !== $notes ? $notes : 'Pago aplicado a deuda documentada de la empresa a favor del perfil.';
		$allocation_notes   = '' !== $notes ? $notes : 'Pago manual aplicado a saldo a favor documentado del perfil.';

		if ( ! $this->begin_transaction() ) {
			return $this->error( 'asdl_fin_profile_payout_transaction', 'No se pudo iniciar la transaccion para pagar al perfil.' );
		}

		$payment_id = $payments_service->create(
			array(
				'payment_type' => 'disbursement',
				'account_id'   => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : 0,
				'contact_id'   => $contact_id,
				'status'       => 'posted',
				'payment_date' => $payment_date,
				'currency'     => $currency,
				'total'        => $total,
				'method_key'   => sanitize_key( $data['method_key'] ?? '' ),
				'reference'    => sanitize_text_field( $data['reference'] ?? '' ),
				'notes'        => $payment_notes,
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			$this->rollback_transaction();
			return $payment_id;
		}

		$remaining            = $total;
		$allocation_ids       = array();
		$settled_document_ids = array();
		$partial_document_ids = array();

		foreach ( $payable_documents as $document ) {
			if ( $remaining <= 0.00001 ) {
				break;
			}

			$document_balance = (float) ( $document['balance'] ?? 0 );
			if ( $document_balance <= 0 ) {
				continue;
			}

			$allocation_amount = min( $remaining, $document_balance );
			$allocation        = $allocation_service->allocate(
				array(
					'payment_id'          => (int) $payment_id,
					'document_id'         => (int) ( $document['id'] ?? 0 ),
					'amount'              => $allocation_amount,
					'notes'               => $allocation_notes,
					'manage_transaction'  => false,
				)
			);

			if ( is_wp_error( $allocation ) ) {
				$this->rollback_transaction();
				return $allocation;
			}

			$allocation_ids[] = (int) ( $allocation['allocation_id'] ?? 0 );
			$remaining        = round( $remaining - $allocation_amount, 6 );

			if ( (float) ( $allocation['document_balance'] ?? 0 ) <= 0.00001 ) {
				$settled_document_ids[] = (int) ( $document['id'] ?? 0 );
			} else {
				$partial_document_ids[] = (int) ( $document['id'] ?? 0 );
			}
		}

		if ( $remaining > 0.00001 ) {
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_profile_payout_unapplied', 'No se pudo aplicar todo el pago al saldo documentado del perfil.' );
		}

		$this->commit_transaction();

		return array(
			'contact_id'            => $contact_id,
			'payment_id'            => (int) $payment_id,
			'currency'              => (string) $currency,
			'total_applied'         => round( $total, 6 ),
			'allocation_ids'        => array_values( array_filter( $allocation_ids ) ),
			'settled_document_ids'  => array_values( array_filter( array_unique( $settled_document_ids ) ) ),
			'partial_document_ids'  => array_values( array_filter( array_unique( $partial_document_ids ) ) ),
		);
	}

	private function get_payable_documents( $contact_id, $currency ) {
		$documents = array_filter(
			( new DocumentsRepository() )->for_contact( $contact_id, 200, true ),
			static function ( array $document ) use ( $currency ) {
				return 'payable' === sanitize_key( (string) ( $document['balance_nature'] ?? '' ) )
					&& 'void' !== sanitize_key( (string) ( $document['financial_status'] ?? '' ) )
					&& (float) ( $document['balance'] ?? 0 ) > 0
					&& '' !== (string) ( $document['currency'] ?? '' )
					&& (string) ( $document['currency'] ?? '' ) === (string) $currency;
			}
		);

		usort(
			$documents,
			static function ( array $left, array $right ) {
				$left_ts  = ! empty( $left['issue_date'] ) ? strtotime( (string) $left['issue_date'] ) : 0;
				$right_ts = ! empty( $right['issue_date'] ) ? strtotime( (string) $right['issue_date'] ) : 0;

				if ( $left_ts === $right_ts ) {
					return (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 );
				}

				return $left_ts <=> $right_ts;
			}
		);

		return array_values( $documents );
	}

	private function sum_document_balances( array $documents ) {
		$total = 0.0;

		foreach ( $documents as $document ) {
			$total += (float) ( $document['balance'] ?? 0 );
		}

		return round( $total, 6 );
	}
}
