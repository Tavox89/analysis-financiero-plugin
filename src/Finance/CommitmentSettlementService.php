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
		$items                  = array();
		$planned_total          = 0.0;
		$blocked_total          = 0.0;
		$blocked_count          = 0;
		$blocking_message       = '';
		$candidates             = array();

		if ( $contact_id <= 0 || ! $plans_repository->exists() || ! $installments->exists() ) {
			return array(
				'planned_total'              => 0,
				'items'                      => array(),
				'blocked_total'              => 0,
				'blocked_count'              => 0,
				'execution_blocked'          => false,
				'execution_blocked_message'  => '',
			);
		}

		if ( null !== $available_amount && $available_amount <= 0 ) {
			return array(
				'planned_total'              => 0,
				'items'                      => array(),
				'blocked_total'              => 0,
				'blocked_count'              => 0,
				'execution_blocked'          => false,
				'execution_blocked_message'  => '',
			);
		}

		$plans           = $plans_repository->for_contact( $contact_id, 200 );
		$backing_context = $this->build_backing_context_for_contact( $contact_id );
		$remaining_backing = array();

		if ( $contact_id > 0 ) {
			$remaining_backing[ 'orders:' . $contact_id ] = round( (float) ( $backing_context['order_backing_totals'][ $contact_id ] ?? 0 ), 6 );
			$remaining_backing[ 'documents:' . $contact_id ] = round( (float) ( $backing_context['document_backing_totals_by_contact'][ $contact_id ] ?? 0 ), 6 );
		}

		foreach ( (array) ( $backing_context['document_backing_totals'] ?? array() ) as $document_id => $balance ) {
			$remaining_backing[ 'document:' . absint( $document_id ) ] = round( max( 0, (float) $balance ), 6 );
		}

		foreach ( $plans as $plan ) {
			$plan = CommitmentExposureService::enrich_plan( $plan, $backing_context );

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

			foreach ( $open_installments as $installment ) {
				$due_date = sanitize_text_field( (string) ( $installment['due_date'] ?? '' ) );
				if ( $due_date && $due_date > $scheduled_payment_date ) {
					continue;
				}

				$candidates[] = array(
					'plan_id'               => (int) $plan['id'],
					'installment_id'        => (int) $installment['id'],
					'document_id'           => ! empty( $plan['document_id'] ) ? (int) $plan['document_id'] : 0,
					'title'                 => sanitize_text_field( (string) ( $plan['title'] ?? '' ) ),
					'settlement_direction'  => $settlement_direction,
					'collection_mode'       => $collection_mode,
					'commitment_origin'     => sanitize_key( (string) ( $plan['commitment_origin'] ?? 'manual_charge' ) ),
					'installment_title'     => sanitize_text_field( (string) ( $installment['title'] ?? '' ) ),
					'due_date'              => $due_date,
					'planned_amount'        => 0.0,
					'installment_balance'   => (float) $installment['balance'],
					'sequence_no'           => (int) ( $installment['sequence_no'] ?? 0 ),
					'exposure_kind'         => sanitize_key( (string) ( $plan['exposure_kind'] ?? '' ) ),
					'backing_source_type'   => sanitize_key( (string) ( $plan['backing_source_type'] ?? '' ) ),
					'backing_document_id'   => absint( $plan['backing_document_id'] ?? 0 ),
					'backing_debt_scope'    => sanitize_key( (string) ( $plan['backing_debt_scope'] ?? '' ) ),
					'is_recovery_plan'      => ! empty( $plan['is_recovery_plan'] ),
					'backing_open_balance'  => round( (float) ( $plan['backing_open_balance'] ?? 0 ), 6 ),
					'blocked'               => false,
					'blocked_reason_key'    => '',
					'blocked_reason_message'=> '',
					'recovery_helper'       => ! empty( $plan['is_recovery_plan'] )
						? 'Este cobro liquida primero la deuda base y luego descuenta la cuota del compromiso por el mismo monto.'
						: '',
				);
			}
		}

		if ( empty( $candidates ) ) {
			return array(
				'planned_total'              => 0,
				'items'                      => array(),
				'blocked_total'              => 0,
				'blocked_count'              => 0,
				'execution_blocked'          => false,
				'execution_blocked_message'  => '',
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
			$capacity_key = $this->resolve_backing_capacity_key( $candidate );
			$apply_amount = max( 0, (float) ( $candidate['installment_balance'] ?? 0 ) );

			if ( ! empty( $candidate['is_recovery_plan'] ) ) {
				$backing_remaining = round( max( 0, (float) ( $remaining_backing[ $capacity_key ] ?? 0 ) ), 6 );
				if ( $backing_remaining <= 0 ) {
					$candidate['blocked'] = true;
					$candidate['blocked_reason_key'] = 'missing_backing_balance';
					$candidate['blocked_reason_message'] = 'El compromiso ya no tiene deuda base abierta suficiente; corrige el vinculo o el saldo antes de cobrar.';
					$candidate['planned_amount'] = 0.0;
					$blocked_total += max( 0, (float) ( $candidate['installment_balance'] ?? 0 ) );
					$blocked_count++;
					if ( '' === $blocking_message ) {
						$blocking_message = $candidate['blocked_reason_message'];
					}
					$items[] = $candidate;
					continue;
				}

				$apply_amount = min( $apply_amount, $backing_remaining );
			}

			if ( null !== $available_amount ) {
				$remaining_payroll_amount = max( 0, $available_amount - $planned_total );
				if ( $remaining_payroll_amount <= 0 ) {
					break;
				}

				$apply_amount = min( $apply_amount, $remaining_payroll_amount );
			}

			if ( $apply_amount <= 0 ) {
				continue;
			}

			$candidate['planned_amount'] = round( $apply_amount, 6 );
			$planned_total += $candidate['planned_amount'];
			$items[] = $candidate;

			if ( ! empty( $candidate['is_recovery_plan'] ) && '' !== $capacity_key ) {
				$remaining_backing[ $capacity_key ] = round(
					max( 0, (float) ( $remaining_backing[ $capacity_key ] ?? 0 ) - $candidate['planned_amount'] ),
					6
				);
			}
		}

		return array(
			'planned_total'             => round( $planned_total, 6 ),
			'items'                     => $items,
			'blocked_total'             => round( $blocked_total, 6 ),
			'blocked_count'             => $blocked_count,
			'execution_blocked'         => $blocked_count > 0,
			'execution_blocked_message' => $blocking_message,
		);
	}

	public function apply( array $data ) {
		$plans             = new InstallmentPlansRepository();
		$installments      = new InstallmentsRepository();
		$payments          = new PaymentsRepository();
		$documents         = new DocumentsRepository();
		$allocations       = new PaymentAllocationService();
		$order_settlement  = new OrderSettlementBatchService();
		$events            = new EventsRepository();
		$plan_id           = absint( $data['plan_id'] ?? 0 );
		$request_amt       = max( 0, (float) ( $data['amount'] ?? $data['total'] ?? 0 ) );
		$payment_date      = $this->sanitize_date( $data['payment_date'] ?? '' ) ?: gmdate( 'Y-m-d' );
		$create_payment    = ! array_key_exists( 'create_payment', $data ) || ! empty( $data['create_payment'] );
		$payment_id        = absint( $data['payment_id'] ?? 0 );
		$manage_tx         = ! array_key_exists( 'manage_transaction', $data ) || ! empty( $data['manage_transaction'] );

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

		$target_installment_ids = array();
		if ( ! empty( $data['target_installment_ids'] ) ) {
			$target_installment_ids = array_values( array_filter( array_map( 'absint', (array) $data['target_installment_ids'] ) ) );
		} elseif ( ! empty( $data['target_installment_id'] ) ) {
			$target_installment_ids = array( absint( $data['target_installment_id'] ) );
		}

		if ( ! empty( $target_installment_ids ) ) {
			$target_map        = array_fill_keys( $target_installment_ids, true );
			$open_installments = array_values(
				array_filter(
					$open_installments,
					static function ( array $installment ) use ( $target_map ) {
						return ! empty( $target_map[ (int) ( $installment['id'] ?? 0 ) ] );
					}
				)
			);
		}

		if ( empty( $open_installments ) ) {
			return $this->error( 'asdl_fin_commitment_target_installment_missing', 'La cuota prevista para este compromiso ya no esta disponible para esta gestion.' );
		}

		$backing_context = $this->build_backing_context_for_contact( (int) ( $plan['contact_id'] ?? 0 ) );
		$plan            = CommitmentExposureService::enrich_plan( $plan, $backing_context );
		$request_cap     = min( $request_amt, max( 0, (float) ( $plan['balance'] ?? 0 ) ) );

		if ( $request_cap <= 0 ) {
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

			if ( $request_cap > (float) ( $payment['available_amount'] ?? 0 ) ) {
				return $this->error( 'asdl_fin_commitment_payment_available', 'El pago seleccionado no tiene saldo disponible suficiente.' );
			}
		}

		$is_recovery = ! empty( $plan['is_recovery_plan'] ) && 'receivable' === $settlement_direction;

		if ( $is_recovery ) {
			$this->log_recovery_event(
				$events,
				$plan,
				'recovery_commitment_payment_requested',
				'Se solicito un cobro sobre un compromiso de recuperacion respaldado por deuda existente.',
				array(
					'requested_total' => round( $request_amt, 6 ),
					'payment_id'      => $payment_id,
					'origin'          => sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) ),
				)
			);
		}

		if ( $manage_tx ) {
			$this->begin_transaction();
		}

		if ( $create_payment ) {
			$payment_result = $payments->create(
				$this->build_payment_payload(
					$plan,
					$request_cap,
					$payment_date,
					array(
						'payment_type' => sanitize_key( (string) ( $data['payment_type'] ?? ( 'payable' === $settlement_direction ? 'disbursement' : 'collection' ) ) ),
						'account_id'   => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null,
						'currency'     => strtoupper( sanitize_text_field( (string) ( $data['currency'] ?? ( $plan['currency'] ?? '' ) ) ) ),
						'method_key'   => sanitize_key( (string) ( $data['method_key'] ?? ( 'payable' === $settlement_direction ? 'payroll' : '' ) ) ),
						'reference'    => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
						'notes'        => sanitize_textarea_field(
							(string) (
								$data['notes']
								?? ( 'payable' === $settlement_direction ? 'Pago aplicado a compromiso a favor del perfil.' : 'Abono aplicado a compromiso.' )
							)
						),
					)
				)
			);

			if ( is_wp_error( $payment_result ) ) {
				if ( $manage_tx ) {
					$this->rollback_transaction();
				}
				if ( $is_recovery ) {
					$this->log_recovery_event(
						$events,
						$plan,
						'recovery_commitment_payment_blocked',
						'El cobro del compromiso de recuperacion no pudo crear el pago operativo.',
						array(
							'requested_total' => round( $request_amt, 6 ),
							'payment_id'      => 0,
							'origin'          => sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) ),
							'blocked_code'    => sanitize_key( (string) $payment_result->get_error_code() ),
							'blocked_message' => (string) $payment_result->get_error_message(),
						)
					);
				}
				return $payment_result;
			}

			$payment_id = (int) $payment_result;
			$payment    = $payments->find( $payment_id );
		}

		if ( $is_recovery ) {
			$result = $this->apply_recovery_plan_settlement(
				$plan,
				$open_installments,
				$request_cap,
				$payment_id,
				$payment,
				$payment_date,
				$data,
				$documents,
				$allocations,
				$installments,
				$order_settlement
			);
		} else {
			$result = $this->apply_standalone_plan_settlement(
				$plan,
				$open_installments,
				$request_cap,
				$payment_id,
				$payment,
				$payment_date,
				$data,
				$documents,
				$allocations,
				$installments
			);
		}

		if ( is_wp_error( $result ) ) {
			if ( $manage_tx ) {
				$this->rollback_transaction();
			}

			if ( $is_recovery ) {
				$this->log_recovery_event(
					$events,
					$plan,
					'recovery_commitment_payment_blocked',
					'El cobro del compromiso de recuperacion quedo bloqueado antes de reflejarse sobre el plan.',
					array(
						'requested_total' => round( $request_amt, 6 ),
						'payment_id'      => $payment_id,
						'origin'          => sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) ),
						'blocked_code'    => sanitize_key( (string) $result->get_error_code() ),
						'blocked_message' => (string) $result->get_error_message(),
					)
				);
			}

			return $result;
		}

		$plan_summary = $installments->summary_for_plan( $plan_id );
		$plan_balance = (float) ( $plan_summary['balance_total'] ?? 0 );
		$plan_status  = $plan_balance <= 0 ? 'closed' : ( 'paused' === ( $plan['status'] ?? '' ) ? 'paused' : 'active' );
		$meta_updates = array(
			'last_payment_id'     => $payment_id,
			'last_applied_at'     => $payment_date,
			'last_applied_total'  => (float) ( $result['applied_total'] ?? 0 ),
			'last_origin'         => sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) ),
			'last_backing_source_type' => sanitize_key( (string) ( $result['backing_source_type'] ?? '' ) ),
			'last_backing_applied_total' => round( (float) ( $result['backing_applied_total'] ?? 0 ), 6 ),
			'last_plan_reflected_total' => round( (float) ( $result['plan_reflected_total'] ?? 0 ), 6 ),
		);

		if ( ! $plans->set_balance_status( $plan_id, $plan_balance, $plan_status, $meta_updates ) ) {
			if ( $manage_tx ) {
				$this->rollback_transaction();
			}
			$error = $this->error( 'asdl_fin_commitment_plan_update', 'No se pudo actualizar el saldo del compromiso.' );
			if ( $is_recovery ) {
				$this->log_recovery_event(
					$events,
					$plan,
					'recovery_commitment_payment_blocked',
					'El cobro del compromiso de recuperacion liquido la deuda base, pero no pudo cerrar el saldo del plan.',
					array(
						'requested_total' => round( $request_amt, 6 ),
						'payment_id'      => $payment_id,
						'origin'          => sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) ),
						'blocked_code'    => sanitize_key( (string) $error->get_error_code() ),
						'blocked_message' => (string) $error->get_error_message(),
					)
				);
			}
			return $error;
		}

		$result['plan_balance'] = $plan_balance;
		$result['plan_status']  = $plan_status;

		if ( $manage_tx ) {
			$this->commit_transaction();
		}

		if ( $is_recovery ) {
			$this->log_recovery_event(
				$events,
				$plan,
				'recovery_commitment_payment_succeeded',
				'El compromiso de recuperacion liquido primero la deuda base y reflejo el mismo monto sobre el plan.',
				array(
					'payment_id'              => (int) ( $result['payment_id'] ?? 0 ),
					'requested_total'         => round( $request_amt, 6 ),
					'applied_total'           => round( (float) ( $result['applied_total'] ?? 0 ), 6 ),
					'backing_applied_total'   => round( (float) ( $result['backing_applied_total'] ?? 0 ), 6 ),
					'plan_reflected_total'    => round( (float) ( $result['plan_reflected_total'] ?? 0 ), 6 ),
					'backing_source_type'     => sanitize_key( (string) ( $result['backing_source_type'] ?? '' ) ),
					'backing_document_id'     => (int) ( $result['backing_document_id'] ?? 0 ),
					'backing_document_ids'    => array_values( array_map( 'intval', (array) ( $result['backing_document_ids'] ?? array() ) ) ),
					'backing_order_ids'       => array_values( array_map( 'intval', (array) ( $result['backing_order_ids'] ?? array() ) ) ),
					'origin'                  => sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) ),
				)
			);
		}

		return $result;
	}

	private function apply_recovery_plan_settlement(
		array $plan,
		array $open_installments,
		$request_cap,
		$payment_id,
		$payment,
		$payment_date,
		array $data,
		DocumentsRepository $documents,
		PaymentAllocationService $allocations,
		InstallmentsRepository $installments,
		OrderSettlementBatchService $order_settlement
	) {
		$backing_source_type = sanitize_key( (string) ( $plan['backing_source_type'] ?? '' ) );
		$backing_document_id = absint( $plan['backing_document_id'] ?? 0 );
		$origin              = sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) );

		if ( 'document' === $backing_source_type ) {
			$document_id = $backing_document_id > 0 ? $backing_document_id : absint( $plan['document_id'] ?? 0 );
			$document    = $document_id > 0 ? $documents->find( $document_id ) : null;
			$document_balance = max( 0, (float) ( $document['balance'] ?? 0 ) );

			if ( empty( $document['id'] ) || $document_balance <= 0 ) {
				return $this->error(
					'asdl_fin_recovery_backing_missing',
					'El compromiso ya no tiene deuda base abierta suficiente; corrige el vínculo o el saldo antes de cobrar.'
				);
			}

			$backing_amount = min( $request_cap, $document_balance );
			$allocation     = $allocations->allocate(
				array(
					'payment_id'         => $payment_id,
					'document_id'        => (int) $document['id'],
					'amount'             => $backing_amount,
					'notes'              => sanitize_textarea_field(
						(string) (
							$data['notes']
							?? 'Cobro de compromiso de recuperacion aplicado primero sobre el documento base.'
						)
					),
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $allocation ) ) {
				return $allocation;
			}

			do_action( 'asdl_fin_payment_allocated', $allocation );

			$mirrored = $this->mirror_installment_applications(
				$plan,
				$open_installments,
				(float) ( $allocation['amount'] ?? 0 ),
				array(
					'payment_id'            => $payment_id,
					'document_id'           => (int) $document['id'],
					'origin'                => $origin,
					'applied_at'            => $payment_date,
					'reference'             => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
					'notes'                 => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
					'exposure_kind'         => sanitize_key( (string) ( $plan['exposure_kind'] ?? '' ) ),
					'backing_source_type'   => 'document',
					'backing_document_id'   => (int) $document['id'],
					'backing_debt_scope'    => sanitize_key( (string) ( $plan['backing_debt_scope'] ?? '' ) ),
					'is_recovery_plan'      => true,
					'backing_applied_total' => round( (float) ( $allocation['amount'] ?? 0 ), 6 ),
					'reflected_plan_total'  => round( (float) ( $allocation['amount'] ?? 0 ), 6 ),
					'backing_document_ids'  => array( (int) $document['id'] ),
					'backing_order_ids'     => array(),
				),
				$installments
			);

			if ( is_wp_error( $mirrored ) ) {
				return $mirrored;
			}

			return array(
				'plan_id'               => (int) $plan['id'],
				'contact_id'            => ! empty( $plan['contact_id'] ) ? (int) $plan['contact_id'] : 0,
				'settlement_direction'  => sanitize_key( (string) ( $plan['settlement_direction'] ?? 'receivable' ) ),
				'payment_id'            => (int) $payment_id,
				'requested_total'       => round( (float) $request_cap, 6 ),
				'applied_total'         => round( (float) ( $mirrored['applied_total'] ?? 0 ), 6 ),
				'unapplied_total'       => round( max( 0, (float) $request_cap - (float) ( $mirrored['applied_total'] ?? 0 ) ), 6 ),
				'installments'          => (array) ( $mirrored['installments'] ?? array() ),
				'document_id'           => (int) $document['id'],
				'allocation'            => $allocation,
				'is_recovery_plan'      => true,
				'backing_source_type'   => 'document',
				'backing_document_id'   => (int) $document['id'],
				'backing_document_ids'  => array( (int) $document['id'] ),
				'backing_order_ids'     => array(),
				'backing_applied_total' => round( (float) ( $allocation['amount'] ?? 0 ), 6 ),
				'plan_reflected_total'  => round( (float) ( $mirrored['applied_total'] ?? 0 ), 6 ),
				'recovery_blocked'      => false,
			);
		}

		if ( 'orders' === $backing_source_type ) {
			$preview = $order_settlement->preview(
				array(
					'contact_id'            => (int) ( $plan['contact_id'] ?? 0 ),
					'account_id'            => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null,
					'payment_date'          => $payment_date,
					'total'                 => round( (float) $request_cap, 6 ),
					'currency'              => sanitize_text_field( (string) ( $data['currency'] ?? ( $plan['currency'] ?? 'USD' ) ) ),
					'method_key'            => sanitize_key( (string) ( $data['method_key'] ?? 'payroll_deduction' ) ),
					'payment_type'          => sanitize_key( (string) ( $data['payment_type'] ?? 'adjustment' ) ),
					'reference'             => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
					'notes'                 => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
					'selection_mode'        => 'oldest_first',
					'include_credit_balance'=> false,
					'force_dual_discount'   => false,
					'dual_discount_mode'    => 'off',
				),
				'recovery_commitment_payment'
			);

			if ( is_wp_error( $preview ) ) {
				return $preview;
			}

			if ( ! empty( $preview['execution_blocked'] ) ) {
				return $this->error(
					'asdl_fin_recovery_backing_blocked',
					(string) ( $preview['execution_blocked_message'] ?? 'El compromiso ya no tiene deuda base abierta suficiente; corrige el vínculo o el saldo antes de cobrar.' )
				);
			}

			if ( 'fast_path' !== sanitize_key( (string) ( $preview['execution_mode'] ?? 'runner' ) ) ) {
				return $this->error(
					'asdl_fin_recovery_runner_required',
					'Este compromiso de recuperación requiere ejecución por lotes sobre la deuda base. Corrige el respaldo o procésalo desde el flujo de pedidos con runner antes de cobrar el plan.'
				);
			}

			if ( empty( $preview['items'] ) || (float) ( $preview['summary']['payment_applied_total'] ?? 0 ) <= 0 ) {
				return $this->error(
					'asdl_fin_recovery_backing_missing',
					'El compromiso ya no tiene deuda base abierta suficiente; corrige el vínculo o el saldo antes de cobrar.'
				);
			}

			$backing_result = $order_settlement->apply_fast_path_preview(
				$preview,
				array(
					'payment_id'         => $payment_id,
					'origin'             => 'recovery_commitment_payment',
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $backing_result ) ) {
				return $backing_result;
			}

			$backing_applied_total = round( (float) ( $backing_result['applied_total'] ?? 0 ), 6 );
			if ( $backing_applied_total <= 0 ) {
				return $this->error(
					'asdl_fin_recovery_backing_missing',
					'El compromiso ya no tiene deuda base abierta suficiente; corrige el vínculo o el saldo antes de cobrar.'
				);
			}

			$backing_document_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $backing_result['document_ids'] ?? array() ) ) ) ) );
			$backing_order_ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $backing_result['order_ids'] ?? array() ) ) ) ) );
			$mirrored             = $this->mirror_installment_applications(
				$plan,
				$open_installments,
				$backing_applied_total,
				array(
					'payment_id'            => $payment_id,
					'document_id'           => ! empty( $backing_document_ids ) ? (int) $backing_document_ids[0] : 0,
					'origin'                => $origin,
					'applied_at'            => $payment_date,
					'reference'             => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
					'notes'                 => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
					'exposure_kind'         => sanitize_key( (string) ( $plan['exposure_kind'] ?? '' ) ),
					'backing_source_type'   => 'orders',
					'backing_document_id'   => 0,
					'backing_debt_scope'    => sanitize_key( (string) ( $plan['backing_debt_scope'] ?? '' ) ),
					'is_recovery_plan'      => true,
					'backing_applied_total' => $backing_applied_total,
					'reflected_plan_total'  => $backing_applied_total,
					'backing_document_ids'  => $backing_document_ids,
					'backing_order_ids'     => $backing_order_ids,
				),
				$installments
			);

			if ( is_wp_error( $mirrored ) ) {
				return $mirrored;
			}

			return array(
				'plan_id'               => (int) $plan['id'],
				'contact_id'            => ! empty( $plan['contact_id'] ) ? (int) $plan['contact_id'] : 0,
				'settlement_direction'  => sanitize_key( (string) ( $plan['settlement_direction'] ?? 'receivable' ) ),
				'payment_id'            => (int) $payment_id,
				'requested_total'       => round( (float) $request_cap, 6 ),
				'applied_total'         => round( (float) ( $mirrored['applied_total'] ?? 0 ), 6 ),
				'unapplied_total'       => round( max( 0, (float) $request_cap - (float) ( $mirrored['applied_total'] ?? 0 ) ), 6 ),
				'installments'          => (array) ( $mirrored['installments'] ?? array() ),
				'document_id'           => ! empty( $backing_document_ids ) ? (int) $backing_document_ids[0] : 0,
				'allocation'            => null,
				'is_recovery_plan'      => true,
				'backing_source_type'   => 'orders',
				'backing_document_id'   => 0,
				'backing_document_ids'  => $backing_document_ids,
				'backing_order_ids'     => $backing_order_ids,
				'backing_applied_total' => $backing_applied_total,
				'plan_reflected_total'  => round( (float) ( $mirrored['applied_total'] ?? 0 ), 6 ),
				'covered_total'         => round( (float) ( $backing_result['covered_total'] ?? $backing_applied_total ), 6 ),
				'recovery_blocked'      => false,
			);
		}

		return $this->error(
			'asdl_fin_recovery_backing_missing',
			'El compromiso ya no tiene deuda base abierta suficiente; corrige el vínculo o el saldo antes de cobrar.'
		);
	}

	private function apply_standalone_plan_settlement(
		array $plan,
		array $open_installments,
		$request_cap,
		$payment_id,
		$payment,
		$payment_date,
		array $data,
		DocumentsRepository $documents,
		PaymentAllocationService $allocations,
		InstallmentsRepository $installments
	) {
		$document_balance = null;
		$allocation_result = null;

		if ( ! empty( $plan['document_id'] ) ) {
			$linked_document = $documents->find( (int) $plan['document_id'] );
			if ( ! empty( $linked_document['id'] ) ) {
				$document_balance = max( 0, (float) ( $linked_document['balance'] ?? 0 ) );
			}
		}

		$applicable_total = $request_cap;
		if ( null !== $document_balance ) {
			$applicable_total = min( $applicable_total, $document_balance );
		}

		if ( $applicable_total <= 0 ) {
			return $this->error( 'asdl_fin_commitment_no_balance', 'No hay saldo util para aplicar sobre este compromiso.' );
		}

		$mirrored = $this->mirror_installment_applications(
			$plan,
			$open_installments,
			$applicable_total,
			array(
				'payment_id'            => $payment_id,
				'document_id'           => ! empty( $plan['document_id'] ) ? (int) $plan['document_id'] : 0,
				'origin'                => sanitize_key( (string) ( $data['origin'] ?? 'manual_commitment' ) ),
				'applied_at'            => $payment_date,
				'reference'             => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
				'notes'                 => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
				'exposure_kind'         => sanitize_key( (string) ( $plan['exposure_kind'] ?? 'standalone_obligation' ) ),
				'backing_source_type'   => sanitize_key( (string) ( $plan['backing_source_type'] ?? 'none' ) ),
				'backing_document_id'   => absint( $plan['backing_document_id'] ?? 0 ),
				'backing_debt_scope'    => sanitize_key( (string) ( $plan['backing_debt_scope'] ?? 'standalone' ) ),
				'is_recovery_plan'      => false,
				'backing_applied_total' => 0.0,
				'reflected_plan_total'  => round( (float) $applicable_total, 6 ),
				'backing_document_ids'  => array(),
				'backing_order_ids'     => array(),
			),
			$installments
		);

		if ( is_wp_error( $mirrored ) ) {
			return $mirrored;
		}

		if ( ! empty( $plan['document_id'] ) && $payment_id > 0 ) {
			$allocation_result = $allocations->allocate(
				array(
					'payment_id'         => $payment_id,
					'document_id'        => (int) $plan['document_id'],
					'amount'             => round( (float) ( $mirrored['applied_total'] ?? 0 ), 6 ),
					'notes'              => sanitize_textarea_field(
						(string) (
							$data['notes']
							?? 'Abono aplicado a compromiso con documento enlazado.'
						)
					),
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $allocation_result ) ) {
				return $allocation_result;
			}

			do_action( 'asdl_fin_payment_allocated', $allocation_result );
		} elseif ( $payment_id > 0 && ! empty( $payment['id'] ) ) {
			$new_available = round( max( 0, (float) ( $payment['available_amount'] ?? 0 ) - (float) ( $mirrored['applied_total'] ?? 0 ) ), 6 );
			if ( ! ( new PaymentsRepository() )->set_available_amount( $payment_id, $new_available ) ) {
				return $this->error( 'asdl_fin_commitment_payment_update', 'No se pudo descontar el monto aplicado del pago usado en este compromiso.' );
			}
		}

		return array(
			'plan_id'              => (int) $plan['id'],
			'contact_id'           => ! empty( $plan['contact_id'] ) ? (int) $plan['contact_id'] : 0,
			'settlement_direction' => sanitize_key( (string) ( $plan['settlement_direction'] ?? 'receivable' ) ),
			'payment_id'           => (int) $payment_id,
			'requested_total'      => round( (float) $request_cap, 6 ),
			'applied_total'        => round( (float) ( $mirrored['applied_total'] ?? 0 ), 6 ),
			'unapplied_total'      => round( max( 0, (float) $request_cap - (float) ( $mirrored['applied_total'] ?? 0 ) ), 6 ),
			'installments'         => (array) ( $mirrored['installments'] ?? array() ),
			'document_id'          => ! empty( $plan['document_id'] ) ? (int) $plan['document_id'] : 0,
			'allocation'           => $allocation_result,
			'is_recovery_plan'     => false,
			'backing_source_type'  => 'none',
			'backing_document_id'  => 0,
			'backing_document_ids' => array(),
			'backing_order_ids'    => array(),
			'backing_applied_total'=> 0.0,
			'plan_reflected_total' => round( (float) ( $mirrored['applied_total'] ?? 0 ), 6 ),
			'recovery_blocked'     => false,
		);
	}

	private function mirror_installment_applications( array $plan, array $open_installments, $amount, array $context, InstallmentsRepository $installments ) {
		$remaining     = round( max( 0, (float) $amount ), 6 );
		$applied_items = array();

		foreach ( $open_installments as $installment ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$applied = $installments->apply_amount( (int) $installment['id'], $remaining, $context );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}

			$remaining       = round( max( 0, $remaining - (float) ( $applied['applied_amount'] ?? 0 ) ), 6 );
			$applied_items[] = $applied;
		}

		$applied_total = round( max( 0, (float) $amount - $remaining ), 6 );
		if ( $applied_total <= 0 ) {
			return $this->error( 'asdl_fin_commitment_apply_failed', 'No se pudo reflejar saldo util sobre este compromiso.' );
		}

		return array(
			'applied_total' => $applied_total,
			'installments'  => $applied_items,
		);
	}

	private function build_payment_payload( array $plan, $amount, $payment_date, array $args ) {
		return array(
			'payment_type' => sanitize_key( (string) ( $args['payment_type'] ?? 'collection' ) ),
			'account_id'   => ! empty( $args['account_id'] ) ? absint( $args['account_id'] ) : null,
			'contact_id'   => ! empty( $plan['contact_id'] ) ? (int) $plan['contact_id'] : null,
			'status'       => 'posted',
			'payment_date' => $payment_date,
			'currency'     => strtoupper( sanitize_text_field( (string) ( $args['currency'] ?? ( $plan['currency'] ?? 'USD' ) ) ) ),
			'total'        => round( max( 0, (float) $amount ), 6 ),
			'method_key'   => sanitize_key( (string) ( $args['method_key'] ?? '' ) ),
			'reference'    => sanitize_text_field( (string) ( $args['reference'] ?? '' ) ),
			'notes'        => sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) ),
		);
	}

	private function build_backing_context_for_contact( $contact_id ) {
		$contact_id    = absint( $contact_id );
		$document_map  = $this->query_open_receivable_document_balance_map( $contact_id );
		$order_balance = $this->query_open_receivable_order_balance_total( $contact_id, $document_map );
		$document_total = 0.0;

		foreach ( $document_map as $document_id => $balance ) {
			$document_total += max( 0, (float) $balance );
		}

		return array(
			'order_backing_totals' => array(
				$contact_id => round( $order_balance, 6 ),
			),
			'document_backing_totals' => $document_map,
			'document_backing_totals_by_contact' => array(
				$contact_id => round( $document_total, 6 ),
			),
		);
	}

	private function query_open_receivable_document_balance_map( $contact_id ) {
		global $wpdb;

		$documents_table = \ASDLabs\Finance\Core\Tables::name( 'documents' );
		$contact_id      = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, balance
				FROM {$documents_table}
				WHERE contact_id = %d
				AND balance_nature = 'receivable'
				AND balance > 0
				AND COALESCE(financial_status, '') <> 'void'",
				$contact_id
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$document_id = absint( $row['id'] ?? 0 );
			if ( $document_id <= 0 ) {
				continue;
			}

			$map[ $document_id ] = round( max( 0, (float) ( $row['balance'] ?? 0 ) ), 6 );
		}

		return $map;
	}

	private function query_open_receivable_order_balance_total( $contact_id, array $document_map = array() ) {
		global $wpdb;

		$documents_table = \ASDLabs\Finance\Core\Tables::name( 'documents' );
		$contact_id      = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return 0.0;
		}

		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(balance), 0)
				FROM {$documents_table}
				WHERE contact_id = %d
				AND balance_nature = 'receivable'
				AND balance > 0
				AND COALESCE(financial_status, '') <> 'void'
				AND (
					document_type = 'woo_sale'
					OR source_type IN ('woocommerce', 'openpos')
					OR external_reference LIKE 'shop_order:%'
				)",
				$contact_id
			)
		);

		if ( null === $total ) {
			$total = 0.0;
		}

		return round( max( 0, (float) $total ), 6 );
	}

	private function resolve_backing_capacity_key( array $plan ) {
		$source_type = sanitize_key( (string) ( $plan['backing_source_type'] ?? '' ) );
		if ( 'document' === $source_type ) {
			return 'document:' . absint( $plan['backing_document_id'] ?? 0 );
		}

		if ( 'orders' === $source_type ) {
			return 'orders:' . absint( $plan['contact_id'] ?? 0 );
		}

		if ( 'documents' === $source_type ) {
			return 'documents:' . absint( $plan['contact_id'] ?? 0 );
		}

		return '';
	}

	private function log_recovery_event( EventsRepository $events, array $plan, $event_type, $message, array $payload ) {
		$events->log(
			'installment_plan',
			(int) ( $plan['id'] ?? 0 ),
			$event_type,
			$message,
			array_merge(
				array(
					'plan_id'               => (int) ( $plan['id'] ?? 0 ),
					'contact_id'            => (int) ( $plan['contact_id'] ?? 0 ),
					'exposure_kind'         => sanitize_key( (string) ( $plan['exposure_kind'] ?? '' ) ),
					'backing_source_type'   => sanitize_key( (string) ( $plan['backing_source_type'] ?? '' ) ),
					'backing_document_id'   => (int) ( $plan['backing_document_id'] ?? 0 ),
					'backing_debt_scope'    => sanitize_key( (string) ( $plan['backing_debt_scope'] ?? '' ) ),
					'is_recovery_plan'      => ! empty( $plan['is_recovery_plan'] ) ? 1 : 0,
				),
				$payload
			)
		);
	}
}
