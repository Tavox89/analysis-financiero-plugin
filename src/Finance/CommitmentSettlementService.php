<?php

namespace ASDLabs\Finance\Finance;

final class CommitmentSettlementService extends BaseRepository {
	public function preview_payroll_deductions( $contact_id, $scheduled_payment_date, $available_amount ) {
		return $this->preview_payroll_effects(
			$contact_id,
			$scheduled_payment_date,
			$available_amount,
			'receivable',
			array( 'payroll_deduction', 'mixed' )
		);
	}

	public function preview_payroll_disbursements( $contact_id, $scheduled_payment_date, $available_amount = null ) {
		return $this->preview_payroll_effects(
			$contact_id,
			$scheduled_payment_date,
			$available_amount,
			'payable',
			array( 'payroll_disbursement', 'mixed' )
		);
	}

	private function preview_payroll_effects( $contact_id, $scheduled_payment_date, $available_amount, $direction, array $allowed_modes ) {
		$contact_id             = absint( $contact_id );
		$scheduled_payment_date = $this->sanitize_date( $scheduled_payment_date ?? '' ) ?: gmdate( 'Y-m-d' );
		$direction              = sanitize_key( (string) $direction );
		$available_amount       = null === $available_amount ? null : max( 0, (float) $available_amount );
		$plans_repository       = new InstallmentPlansRepository();
		$installments           = new InstallmentsRepository();
		$documents              = new DocumentsRepository();
		$items                  = array();
		$planned_total          = 0.0;
		$candidates             = array();

		if ( $contact_id <= 0 || ! $plans_repository->exists() || ! $installments->exists() ) {
			return array(
				'planned_total' => 0,
				'items'         => array(),
			);
		}

		if ( null !== $available_amount && $available_amount <= 0 ) {
			return array(
				'planned_total' => 0,
				'items'         => array(),
			);
		}

		$plans = $plans_repository->for_contact( $contact_id, 200 );
		foreach ( $plans as $plan ) {
			$plan_status = sanitize_key( (string) ( $plan['status'] ?? 'active' ) );
			if (
				empty( $plan['id'] )
				|| in_array( $plan_status, array( 'closed', 'paused', 'inactive', 'cancelled' ), true )
				|| (float) ( $plan['balance'] ?? 0 ) <= 0
			) {
				continue;
			}

			$settlement_direction = sanitize_key( (string) ( $plan['settlement_direction'] ?? 'receivable' ) );
			if ( $settlement_direction !== $direction ) {
				continue;
			}

			$collection_mode = sanitize_key( (string) ( $plan['collection_mode'] ?? 'manual' ) );
			if ( ! in_array( $collection_mode, $allowed_modes, true ) ) {
				continue;
			}

			$open_installments = $installments->open_for_plan( (int) $plan['id'], 50 );
			if ( empty( $open_installments ) ) {
				continue;
			}

			$document_balance = null;
			if ( ! empty( $plan['document_id'] ) ) {
				$document = $documents->find( (int) $plan['document_id'] );
				if ( empty( $document['id'] ) || (float) ( $document['balance'] ?? 0 ) <= 0 ) {
					continue;
				}

				$document_balance = (float) $document['balance'];
			}

			foreach ( $open_installments as $installment ) {
				$due_date = sanitize_text_field( (string) ( $installment['due_date'] ?? '' ) );
				if ( $due_date && $due_date > $scheduled_payment_date ) {
					continue;
				}

				$apply_amount = (float) $installment['balance'];
				if ( null !== $document_balance ) {
					$apply_amount = min( $apply_amount, $document_balance );
				}

				if ( $apply_amount <= 0 ) {
					continue;
				}

				$candidates[] = array(
					'plan_id'            => (int) $plan['id'],
					'installment_id'     => (int) $installment['id'],
					'document_id'        => ! empty( $plan['document_id'] ) ? (int) $plan['document_id'] : 0,
					'title'              => sanitize_text_field( (string) ( $plan['title'] ?? '' ) ),
					'settlement_direction' => $settlement_direction,
					'collection_mode'    => $collection_mode,
					'commitment_origin'  => sanitize_key( (string) ( $plan['commitment_origin'] ?? 'manual_charge' ) ),
					'installment_title'  => sanitize_text_field( (string) ( $installment['title'] ?? '' ) ),
					'due_date'           => $due_date,
					'planned_amount'     => $apply_amount,
					'installment_balance'=> (float) $installment['balance'],
					'sequence_no'        => (int) ( $installment['sequence_no'] ?? 0 ),
				);
			}
		}

		if ( empty( $candidates ) ) {
			return array(
				'planned_total' => 0,
				'items'         => array(),
			);
		}

		usort(
			$candidates,
			static function ( array $left, array $right ) {
				$left_due  = sanitize_text_field( (string) ( $left['due_date'] ?? '' ) );
				$right_due = sanitize_text_field( (string) ( $right['due_date'] ?? '' ) );
				if ( $left_due !== $right_due ) {
					return strcmp( $left_due, $right_due );
				}

				$left_sequence  = (int) ( $left['sequence_no'] ?? 0 );
				$right_sequence = (int) ( $right['sequence_no'] ?? 0 );
				if ( $left_sequence !== $right_sequence ) {
					return $left_sequence <=> $right_sequence;
				}

				return (int) ( $left['plan_id'] ?? 0 ) <=> (int) ( $right['plan_id'] ?? 0 );
			}
		);

		foreach ( $candidates as $candidate ) {
			if ( null !== $available_amount && $planned_total >= $available_amount ) {
				break;
			}

			$apply_amount = (float) ( $candidate['planned_amount'] ?? 0 );
			if ( null !== $available_amount ) {
				$apply_amount = min( $apply_amount, max( 0, $available_amount - $planned_total ) );
			}

			if ( $apply_amount <= 0 ) {
				continue;
			}

			$candidate['planned_amount'] = $apply_amount;
			$planned_total += $apply_amount;
			$items[] = $candidate;
		}

		return array(
			'planned_total' => round( $planned_total, 6 ),
			'items'         => $items,
		);
	}

	public function apply( array $data ) {
		$plans        = new InstallmentPlansRepository();
		$installments = new InstallmentsRepository();
		$payments     = new PaymentsRepository();
		$documents    = new DocumentsRepository();
		$allocations  = new PaymentAllocationService();
		$plan_id      = absint( $data['plan_id'] ?? 0 );
		$request_amt  = max( 0, (float) ( $data['amount'] ?? $data['total'] ?? 0 ) );
		$payment_date = $this->sanitize_date( $data['payment_date'] ?? '' ) ?: gmdate( 'Y-m-d' );
		$create_payment = ! array_key_exists( 'create_payment', $data ) || ! empty( $data['create_payment'] );
		$payment_id     = absint( $data['payment_id'] ?? 0 );
		$manage_tx      = ! array_key_exists( 'manage_transaction', $data ) || ! empty( $data['manage_transaction'] );

		if ( ! $plans->exists() || ! $installments->exists() ) {
			return $this->error( 'asdl_fin_commitment_missing_tables', 'El esquema de compromisos aun no esta disponible.' );
		}

		if ( $plan_id <= 0 ) {
			return $this->error( 'asdl_fin_commitment_plan', 'Debes indicar el compromiso que deseas gestionar.' );
		}

		if ( $request_amt <= 0 ) {
			return $this->error( 'asdl_fin_commitment_amount', 'Debes indicar un monto valido para el compromiso.' );
		}

		if ( ! $create_payment && $payment_id <= 0 ) {
			return $this->error( 'asdl_fin_commitment_payment_required', 'Debes indicar un pago registrado o permitir que el sistema cree uno para este compromiso.' );
		}

		$plan = $plans->find( $plan_id );
		if ( empty( $plan['id'] ) ) {
			return $this->error( 'asdl_fin_commitment_missing', 'No se encontro el compromiso solicitado.' );
		}

		$settlement_direction = sanitize_key( (string) ( $plan['settlement_direction'] ?? 'receivable' ) );

		if ( 'closed' === ( $plan['status'] ?? '' ) || (float) ( $plan['balance'] ?? 0 ) <= 0 ) {
			return $this->error( 'asdl_fin_commitment_closed', 'Este compromiso ya no tiene saldo pendiente.' );
		}

		if ( 'inactive' === ( $plan['status'] ?? '' ) ) {
			return $this->error( 'asdl_fin_commitment_inactive', 'Este compromiso no se puede gestionar mientras este inactivo.' );
		}

		$open_installments = $installments->open_for_plan( $plan_id, 200 );
		if ( empty( $open_installments ) ) {
			return $this->error( 'asdl_fin_commitment_without_installments', 'Este compromiso no tiene cuotas abiertas para recibir abonos.' );
		}

		$document_balance = null;
		$linked_document  = null;
		if ( ! empty( $plan['document_id'] ) ) {
			$linked_document = $documents->find( (int) $plan['document_id'] );
			if ( ! empty( $linked_document['id'] ) ) {
				$document_balance = max( 0, (float) ( $linked_document['balance'] ?? 0 ) );
			}
		}

		$applicable_total = min( $request_amt, (float) $plan['balance'] );
		if ( null !== $document_balance ) {
			$applicable_total = min( $applicable_total, $document_balance );
		}

		if ( $applicable_total <= 0 ) {
			return $this->error( 'asdl_fin_commitment_no_balance', 'No hay saldo util para aplicar sobre este compromiso.' );
		}

		$payment = null;
		if ( $payment_id > 0 ) {
			$payment = $payments->find( $payment_id );
			if ( empty( $payment['id'] ) ) {
				return $this->error( 'asdl_fin_commitment_payment_missing', 'No se encontro el pago indicado para aplicar este compromiso.' );
			}

			if ( 'posted' !== ( $payment['status'] ?? '' ) ) {
				return $this->error( 'asdl_fin_commitment_payment_status', 'Solo puedes aplicar pagos ya registrados sobre un compromiso.' );
			}

			if ( ! empty( $payment['contact_id'] ) && ! empty( $plan['contact_id'] ) && (int) $payment['contact_id'] !== (int) $plan['contact_id'] ) {
				return $this->error( 'asdl_fin_commitment_contact_mismatch', 'El pago seleccionado pertenece a otro perfil.' );
			}

			if ( $applicable_total > (float) ( $payment['available_amount'] ?? 0 ) ) {
				return $this->error( 'asdl_fin_commitment_payment_available', 'El pago seleccionado no tiene saldo disponible suficiente.' );
			}
		}

		if ( $manage_tx ) {
			$this->begin_transaction();
		}

		if ( $create_payment ) {
			$default_payment_type = 'payable' === $settlement_direction ? 'disbursement' : 'collection';
			$payment_result = $payments->create(
				array(
					'payment_type' => sanitize_key( $data['payment_type'] ?? $default_payment_type ),
					'account_id'   => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null,
					'contact_id'   => ! empty( $plan['contact_id'] ) ? (int) $plan['contact_id'] : null,
					'status'       => 'posted',
					'payment_date' => $payment_date,
					'currency'     => strtoupper( sanitize_text_field( $data['currency'] ?? ( $plan['currency'] ?? '' ) ) ),
					'total'        => $applicable_total,
					'method_key'   => sanitize_key( $data['method_key'] ?? ( 'payable' === $settlement_direction ? 'payroll' : '' ) ),
					'reference'    => sanitize_text_field( $data['reference'] ?? '' ),
					'notes'        => sanitize_textarea_field( $data['notes'] ?? ( 'payable' === $settlement_direction ? 'Pago aplicado a compromiso a favor del perfil.' : 'Abono aplicado a compromiso.' ) ),
				)
			);

			if ( is_wp_error( $payment_result ) ) {
				if ( $manage_tx ) {
					$this->rollback_transaction();
				}
				return $payment_result;
			}

			$payment_id = (int) $payment_result;
			$payment    = $payments->find( $payment_id );
		}

		$remaining         = $applicable_total;
		$applied_items     = array();
		$application_ctx   = array(
			'payment_id'  => $payment_id,
			'document_id' => ! empty( $plan['document_id'] ) ? (int) $plan['document_id'] : 0,
			'origin'      => sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) ),
			'applied_at'  => $payment_date,
			'reference'   => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
			'notes'       => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
		);

		foreach ( $open_installments as $installment ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$applied = $installments->apply_amount( (int) $installment['id'], $remaining, $application_ctx );
			if ( is_wp_error( $applied ) ) {
				if ( $manage_tx ) {
					$this->rollback_transaction();
				}
				return $applied;
			}

			$remaining     = round( max( 0, $remaining - (float) ( $applied['applied_amount'] ?? 0 ) ), 6 );
			$applied_items[] = $applied;
		}

		$applied_total = round( $applicable_total - $remaining, 6 );
		if ( $applied_total <= 0 ) {
			if ( $manage_tx ) {
				$this->rollback_transaction();
			}
			return $this->error( 'asdl_fin_commitment_apply_failed', 'No se pudo aplicar saldo util sobre este compromiso.' );
		}

		$allocation_result = null;
		if ( ! empty( $plan['document_id'] ) && $payment_id > 0 ) {
			$allocation_result = $allocations->allocate(
				array(
					'payment_id'         => $payment_id,
					'document_id'        => (int) $plan['document_id'],
					'amount'             => $applied_total,
					'notes'              => sanitize_textarea_field( $data['notes'] ?? 'Abono aplicado a compromiso con documento enlazado.' ),
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $allocation_result ) ) {
				if ( $manage_tx ) {
					$this->rollback_transaction();
				}
				return $allocation_result;
			}
		} elseif ( $payment_id > 0 && ! empty( $payment['id'] ) ) {
			$new_available = round( max( 0, (float) ( $payment['available_amount'] ?? 0 ) - $applied_total ), 6 );
			if ( ! $payments->set_available_amount( $payment_id, $new_available ) ) {
				if ( $manage_tx ) {
					$this->rollback_transaction();
				}
				return $this->error( 'asdl_fin_commitment_payment_update', 'No se pudo descontar el monto aplicado del pago usado en este compromiso.' );
			}
		}

		$plan_summary = $installments->summary_for_plan( $plan_id );
		$plan_balance = (float) ( $plan_summary['balance_total'] ?? 0 );
		$plan_status  = $plan_balance <= 0 ? 'closed' : ( 'paused' === ( $plan['status'] ?? '' ) ? 'paused' : 'active' );
		$meta_updates = array(
			'last_payment_id'   => $payment_id,
			'last_applied_at'   => $payment_date,
			'last_applied_total'=> $applied_total,
			'last_origin'       => $application_ctx['origin'],
		);

		if ( ! $plans->set_balance_status( $plan_id, $plan_balance, $plan_status, $meta_updates ) ) {
			if ( $manage_tx ) {
				$this->rollback_transaction();
			}
			return $this->error( 'asdl_fin_commitment_plan_update', 'No se pudo actualizar el saldo del compromiso.' );
		}

		if ( $manage_tx ) {
			$this->commit_transaction();
		}

		$result = array(
			'plan_id'          => $plan_id,
			'contact_id'       => ! empty( $plan['contact_id'] ) ? (int) $plan['contact_id'] : 0,
			'settlement_direction' => $settlement_direction,
			'payment_id'       => $payment_id,
			'requested_total'  => $request_amt,
			'applied_total'    => $applied_total,
			'unapplied_total'  => round( max( 0, $request_amt - $applied_total ), 6 ),
			'plan_balance'     => $plan_balance,
			'plan_status'      => $plan_status,
			'installments'     => $applied_items,
			'document_id'      => ! empty( $plan['document_id'] ) ? (int) $plan['document_id'] : 0,
			'allocation'       => $allocation_result,
		);

		if ( is_array( $allocation_result ) ) {
			do_action( 'asdl_fin_payment_allocated', $allocation_result );
		}

		return $result;
	}
}
