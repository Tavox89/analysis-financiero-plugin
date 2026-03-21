<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use ASDLabs\Finance\Integrations\Woo\Module as WooModule;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class CancellationService extends BaseRepository {
	public function cancel_payment( $payment_id, $reason, array $context = array() ) {
		$payments     = new PaymentsRepository();
		$documents    = new DocumentsRepository();
		$allocations  = new PaymentAllocationsRepository();
		$installments = new InstallmentsRepository();
		$events       = new EventsRepository();
		$payment_id   = absint( $payment_id );
		$reason       = sanitize_textarea_field( (string) $reason );
		$manage_tx    = ! array_key_exists( 'manage_transaction', $context ) || ! empty( $context['manage_transaction'] );
		$origin       = sanitize_key( (string) ( $context['origin'] ?? 'admin' ) );

		if ( $payment_id <= 0 ) {
			return $this->error( 'asdl_fin_payment_missing', 'No se encontro el pago solicitado.' );
		}

		if ( '' === $reason ) {
			return $this->error( 'asdl_fin_cancel_reason', 'Debes indicar el motivo de la anulacion.' );
		}

		$payment = $payments->find( $payment_id );
		if ( empty( $payment['id'] ) ) {
			return $this->error( 'asdl_fin_payment_missing', 'No se encontro el pago solicitado.' );
		}

		$payment_meta = json_decode( (string) ( $payment['meta_json'] ?? '' ), true );
		$payment_meta = is_array( $payment_meta ) ? $payment_meta : array();
		$parent_dual_payment_id = ! empty( $payment_meta['dual_discount_parent_payment_id'] ) ? absint( $payment_meta['dual_discount_parent_payment_id'] ) : 0;
		$child_dual_payment_ids = array_values(
			array_filter(
				array_map(
					'absint',
					(array) ( $payment_meta['dual_discount_payment_ids'] ?? array() )
				)
			)
		);

		if ( 'void' === sanitize_key( (string) ( $payment['status'] ?? '' ) ) ) {
			return $this->error( 'asdl_fin_payment_void', 'Este pago ya se encuentra anulado.' );
		}

		if ( $parent_dual_payment_id > 0 && empty( $context['allow_dual_discount_child'] ) ) {
			return $this->error( 'asdl_fin_payment_dual_child_locked', 'Este ajuste forma parte de un abono con precio dual y debe anularse desde el pago principal.' );
		}

		if ( $this->is_payroll_payment( $payment_id ) ) {
			return $this->error( 'asdl_fin_payment_payroll_locked', 'Este pago forma parte de una nomina procesada y no se puede anular desde este flujo.' );
		}

		if ( 'salary_advance' === sanitize_key( (string) ( $payment['method_key'] ?? '' ) ) && empty( $context['allow_salary_advance'] ) ) {
			return $this->error( 'asdl_fin_payment_salary_advance_locked', 'Los adelantos de sueldo deben anularse desde su propio flujo para conservar trazabilidad.' );
		}

		$commitment_applications = $installments->applications_for_payment( $payment_id, 500 );
		foreach ( $commitment_applications as $application ) {
			if ( in_array( sanitize_key( (string) ( $application['origin'] ?? '' ) ), array( 'payroll_deduction', 'payroll_disbursement' ), true ) ) {
				return $this->error( 'asdl_fin_payment_commitment_payroll_locked', 'Este pago esta enlazado a compromisos ya aplicados por nomina y no se puede anular desde este flujo.' );
			}
		}

		$allocation_rows = $allocations->for_payment( $payment_id, 500 );

		if ( $manage_tx ) {
			$this->begin_transaction();
		}

		if ( ! empty( $child_dual_payment_ids ) ) {
			foreach ( $child_dual_payment_ids as $child_payment_id ) {
				if ( $child_payment_id <= 0 || $child_payment_id === $payment_id ) {
					continue;
				}

				$child_result = $this->cancel_payment(
					$child_payment_id,
					$reason,
					array(
						'origin'                   => $origin,
						'manage_transaction'       => false,
						'allow_dual_discount_child'=> true,
					)
				);

				if ( is_wp_error( $child_result ) ) {
					if ( $manage_tx ) {
						$this->rollback_transaction();
					}
					return $child_result;
				}
			}
		}

		$reversal = $installments->reverse_payment_applications(
			$payment_id,
			array(
				'reversed_at' => $this->now(),
				'reversed_by' => get_current_user_id(),
				'reason'      => $reason,
			)
		);

		if ( is_wp_error( $reversal ) ) {
			if ( $manage_tx ) {
				$this->rollback_transaction();
			}
			return $reversal;
		}

		$affected_documents = array();
		$allocation_ids     = array();

		foreach ( $allocation_rows as $allocation ) {
			$allocation_id = (int) ( $allocation['id'] ?? 0 );
			$document_id   = (int) ( $allocation['document_id'] ?? 0 );
			$amount        = max( 0, (float) ( $allocation['amount'] ?? 0 ) );

			if ( $allocation_id > 0 ) {
				$allocation_ids[] = $allocation_id;
			}

			if ( $document_id <= 0 || $amount <= 0 ) {
				continue;
			}

			if ( ! isset( $affected_documents[ $document_id ] ) ) {
				$affected_documents[ $document_id ] = 0.0;
			}

			$affected_documents[ $document_id ] += $amount;
		}

		if ( ! empty( $allocation_ids ) && ! $allocations->delete_ids( $allocation_ids ) ) {
			if ( $manage_tx ) {
				$this->rollback_transaction();
			}
			return $this->error( 'asdl_fin_payment_allocation_reverse', 'No se pudieron revertir las asignaciones del pago.' );
		}

		$reopened_order_ids = array();

		foreach ( $affected_documents as $document_id => $reversed_amount ) {
			$document = $documents->find( $document_id );
			if ( empty( $document['id'] ) ) {
				continue;
			}

			$new_paid    = round( max( 0, (float) $document['paid_total'] - $reversed_amount ), 6 );
			$new_balance = round( max( 0, (float) $document['total'] - $new_paid ), 6 );
			$new_status  = $this->resolve_document_payment_status( $new_paid, $new_balance, (string) ( $document['due_date'] ?? '' ) );

			if ( ! $documents->set_payment_progress( $document_id, $new_paid, $new_balance, $new_status ) ) {
				if ( $manage_tx ) {
					$this->rollback_transaction();
				}
				return $this->error( 'asdl_fin_payment_document_reverse', 'No se pudo restaurar el saldo de un movimiento afectado por la anulacion.' );
			}

			if ( 'income' === sanitize_key( (string) ( $document['financial_intent'] ?? '' ) ) && $new_balance > 0 ) {
				$reopened_order_ids = array_merge(
					$reopened_order_ids,
					$this->reopen_linked_orders( $document_id, 'Pedido reabierto automaticamente al anular un pago aplicado desde Finanzas ASD.' )
				);
			}
		}

		$meta_updates = array(
			'cancelled_at'      => $this->now(),
			'cancelled_by'      => get_current_user_id(),
			'cancel_reason'     => $reason,
			'cancel_origin'     => $origin,
			'reversed_total'    => round( array_sum( $affected_documents ) + (float) ( $reversal['reversed_total'] ?? 0 ), 6 ),
			'reopened_order_ids'=> array_values( array_unique( array_map( 'intval', $reopened_order_ids ) ) ),
		);

		if ( ! $payments->set_status( $payment_id, 'void', $meta_updates, 0 ) ) {
			if ( $manage_tx ) {
				$this->rollback_transaction();
			}
			return $this->error( 'asdl_fin_payment_void_update', 'No se pudo marcar el pago como anulado.' );
		}

		if ( $manage_tx ) {
			$this->commit_transaction();
		}

		$payload = array(
			'entity_type'             => 'payment',
			'entity_id'               => $payment_id,
			'origin'                  => $origin,
			'reason'                  => $reason,
			'contact_id'              => (int) ( $payment['contact_id'] ?? 0 ),
			'affected_document_ids'   => array_values( array_map( 'intval', array_keys( $affected_documents ) ) ),
			'affected_plan_ids'       => array_values( array_map( 'intval', (array) ( $reversal['plan_ids'] ?? array() ) ) ),
			'reopened_order_ids'      => array_values( array_unique( array_map( 'intval', $reopened_order_ids ) ) ),
			'reversed_allocation_ids' => array_values( array_map( 'intval', $allocation_ids ) ),
		);

		$events->log( 'payment', $payment_id, 'cancelled', 'Pago anulado correctamente.', $payload );
		do_action( 'asdl_fin_payment_cancelled', $payload );

		return array_merge(
			array(
				'payment_id'                 => $payment_id,
				'cancelled'                  => true,
				'reversed_allocation_total'  => round( array_sum( $affected_documents ), 6 ),
				'reversed_commitment_total'  => (float) ( $reversal['reversed_total'] ?? 0 ),
			),
			$payload
		);
	}

	public function cancel_salary_advance( $advance_id, $reason, array $context = array() ) {
		$advances   = new EmployeeAdvancesRepository();
		$events     = new EventsRepository();
		$advance_id = absint( $advance_id );
		$reason     = sanitize_textarea_field( (string) $reason );
		$origin     = sanitize_key( (string) ( $context['origin'] ?? 'admin' ) );

		if ( $advance_id <= 0 ) {
			return $this->error( 'asdl_fin_salary_advance_missing', 'No se encontro el adelanto solicitado.' );
		}

		if ( '' === $reason ) {
			return $this->error( 'asdl_fin_cancel_reason', 'Debes indicar el motivo de la anulacion.' );
		}

		$advance = $advances->find( $advance_id );
		if ( empty( $advance['id'] ) ) {
			return $this->error( 'asdl_fin_salary_advance_missing', 'No se encontro el adelanto solicitado.' );
		}

		if ( 'cancelled' === sanitize_key( (string) ( $advance['status'] ?? '' ) ) ) {
			return $this->error( 'asdl_fin_salary_advance_cancelled', 'Este adelanto ya se encuentra anulado.' );
		}

		if ( (float) ( $advance['recovered_amount'] ?? 0 ) > 0 ) {
			return $this->error( 'asdl_fin_salary_advance_recovered', 'Este adelanto ya tiene recuperaciones aplicadas y no puede anularse desde este flujo.' );
		}

		$this->begin_transaction();

		$payment_result = $this->cancel_payment(
			(int) ( $advance['payment_id'] ?? 0 ),
			$reason,
			array(
				'origin'               => $origin,
				'allow_salary_advance' => true,
				'manage_transaction'   => false,
			)
		);

		if ( is_wp_error( $payment_result ) ) {
			$this->rollback_transaction();
			return $payment_result;
		}

		if ( ! $advances->set_status(
			$advance_id,
			'cancelled',
			array(
				'cancelled_at' => $this->now(),
				'cancelled_by' => get_current_user_id(),
				'cancel_reason'=> $reason,
				'cancel_origin'=> $origin,
			),
			0
		) ) {
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_salary_advance_cancel_update', 'No se pudo marcar el adelanto como anulado.' );
		}

		$this->commit_transaction();

		$payload = array(
			'entity_type' => 'salary_advance',
			'entity_id'   => $advance_id,
			'origin'      => $origin,
			'reason'      => $reason,
			'payment_id'  => (int) ( $advance['payment_id'] ?? 0 ),
			'contact_id'  => (int) ( $advance['contact_id'] ?? 0 ),
		);

		$events->log( 'salary_advance', $advance_id, 'cancelled', 'Adelanto anulado correctamente.', $payload );

		return array_merge(
			array(
				'advance_id' => $advance_id,
				'cancelled'  => true,
			),
			$payload
		);
	}

	public function cancel_document( $document_id, $reason, array $context = array() ) {
		$documents   = new DocumentsRepository();
		$allocations = new PaymentAllocationsRepository();
		$plans       = new InstallmentPlansRepository();
		$events      = new EventsRepository();
		$document_id = absint( $document_id );
		$reason      = sanitize_textarea_field( (string) $reason );
		$origin      = sanitize_key( (string) ( $context['origin'] ?? 'admin' ) );

		if ( $document_id <= 0 ) {
			return $this->error( 'asdl_fin_document_missing', 'No se encontro el movimiento solicitado.' );
		}

		if ( '' === $reason ) {
			return $this->error( 'asdl_fin_cancel_reason', 'Debes indicar el motivo de la anulacion.' );
		}

		$document = $documents->find( $document_id );
		if ( empty( $document['id'] ) ) {
			return $this->error( 'asdl_fin_document_missing', 'No se encontro el movimiento solicitado.' );
		}

		if ( 'void' === sanitize_key( (string) ( $document['financial_status'] ?? '' ) ) ) {
			return $this->error( 'asdl_fin_document_void', 'Este movimiento ya se encuentra anulado.' );
		}

		if ( $allocations->count_for_document( $document_id ) > 0 ) {
			return $this->error( 'asdl_fin_document_allocation_locked', 'Este movimiento tiene asignaciones aplicadas y primero debes anular esos pagos o abonos.' );
		}

		if ( $plans->has_active_for_document( $document_id ) ) {
			return $this->error( 'asdl_fin_document_plan_locked', 'Este movimiento tiene compromisos activos enlazados y no puede anularse hasta resolverlos.' );
		}

		if ( $this->is_payroll_document( $document_id ) ) {
			return $this->error( 'asdl_fin_document_payroll_locked', 'Este movimiento forma parte de una nomina procesada y no puede anularse desde este flujo.' );
		}

		$this->begin_transaction();

		if ( ! $documents->set_financial_status(
			$document_id,
			'void',
			array(
				'paid_total'     => 0,
				'balance'        => 0,
				'payment_status' => 'pending',
			),
			array(
				'cancelled_at' => $this->now(),
				'cancelled_by' => get_current_user_id(),
				'cancel_reason'=> $reason,
				'cancel_origin'=> $origin,
			)
		) ) {
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_document_cancel_update', 'No se pudo anular el movimiento.' );
		}

		$reopened_order_ids = array();
		if ( 'income' === sanitize_key( (string) ( $document['financial_intent'] ?? '' ) ) ) {
			$reopened_order_ids = $this->reopen_linked_orders( $document_id, 'Pedido reabierto automaticamente al anular el movimiento financiero desde Finanzas ASD.' );
		}

		$this->commit_transaction();

		$payload = array(
			'entity_type'        => 'document',
			'entity_id'          => $document_id,
			'origin'             => $origin,
			'reason'             => $reason,
			'reopened_order_ids' => array_values( array_unique( array_map( 'intval', $reopened_order_ids ) ) ),
			'contact_id'         => (int) ( $document['contact_id'] ?? 0 ),
		);

		$events->log( 'document', $document_id, 'cancelled', 'Movimiento anulado correctamente.', $payload );
		do_action( 'asdl_fin_document_cancelled', $document_id );

		return array_merge(
			array(
				'document_id' => $document_id,
				'cancelled'   => true,
			),
			$payload
		);
	}

	public function cancel_installment_plan( $plan_id, $reason, array $context = array() ) {
		$plans    = new InstallmentPlansRepository();
		$items    = new InstallmentsRepository();
		$events   = new EventsRepository();
		$plan_id  = absint( $plan_id );
		$reason   = sanitize_textarea_field( (string) $reason );
		$origin   = sanitize_key( (string) ( $context['origin'] ?? 'admin' ) );

		if ( $plan_id <= 0 ) {
			return $this->error( 'asdl_fin_commitment_missing', 'No se encontro el compromiso solicitado.' );
		}

		if ( '' === $reason ) {
			return $this->error( 'asdl_fin_cancel_reason', 'Debes indicar el motivo de la anulacion.' );
		}

		$plan = $plans->find( $plan_id );
		if ( empty( $plan['id'] ) ) {
			return $this->error( 'asdl_fin_commitment_missing', 'No se encontro el compromiso solicitado.' );
		}

		if ( in_array( sanitize_key( (string) ( $plan['status'] ?? '' ) ), array( 'inactive', 'cancelled' ), true ) ) {
			return $this->error( 'asdl_fin_commitment_inactive', 'Este compromiso ya se encuentra inactivo o anulado.' );
		}

		$summary = $items->summary_for_plan( $plan_id );
		if ( (float) ( $summary['paid_total'] ?? 0 ) > 0 ) {
			return $this->error( 'asdl_fin_commitment_paid', 'Este compromiso ya tiene cuotas aplicadas y no puede anularse desde este flujo.' );
		}

		if ( ! $plans->set_status(
			$plan_id,
			'cancelled',
			array(
				'cancelled_at' => $this->now(),
				'cancelled_by' => get_current_user_id(),
				'cancel_reason'=> $reason,
				'cancel_origin'=> $origin,
			)
		) ) {
			return $this->error( 'asdl_fin_commitment_cancel_update', 'No se pudo anular el compromiso.' );
		}

		$payload = array(
			'entity_type' => 'installment_plan',
			'entity_id'   => $plan_id,
			'origin'      => $origin,
			'reason'      => $reason,
			'contact_id'  => (int) ( $plan['contact_id'] ?? 0 ),
			'document_id' => (int) ( $plan['document_id'] ?? 0 ),
		);

		$events->log( 'installment_plan', $plan_id, 'cancelled', 'Compromiso anulado correctamente.', $payload );

		return array_merge(
			array(
				'plan_id'    => $plan_id,
				'cancelled'  => true,
			),
			$payload
		);
	}

	private function is_payroll_payment( $payment_id ) {
		$table = Tables::name( 'payroll_periods' );

		return (int) $this->db()->get_var(
			$this->db()->prepare(
				"SELECT COUNT(*)
				FROM {$table}
				WHERE payment_id = %d",
				$payment_id
			)
		) > 0;
	}

	private function is_payroll_document( $document_id ) {
		$table = Tables::name( 'payroll_periods' );

		return (int) $this->db()->get_var(
			$this->db()->prepare(
				"SELECT COUNT(*)
				FROM {$table}
				WHERE document_id = %d",
				$document_id
			)
		) > 0;
	}

	private function reopen_linked_orders( $document_id, $note ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$source_links = new SourceLinksRepository();
		$order_sync   = new OrderSyncService();
		$reopened_ids = array();

		foreach ( $source_links->find_for_document( $document_id ) as $link ) {
			if ( ! in_array( $link['provider'] ?? '', array( 'woocommerce', 'openpos' ), true ) ) {
				continue;
			}

			$order = wc_get_order( absint( $link['external_id'] ?? 0 ) );
			if ( ! $order ) {
				continue;
			}

			$current_status = sanitize_key( $order->get_status() );
			if ( 'pending' === $current_status ) {
				continue;
			}

			$provider = sanitize_key( (string) ( $link['provider'] ?? '' ) );

			WooModule::run_without_status_guard(
				static function () use ( $order, $note, $provider ) {
					$order->update_status( 'pending', $note, true );

					// Una anulacion financiera debe limpiar cualquier rastro de pago
					// que Woo/OpenPOS pueda seguir usando para reconstruir el pedido
					// como pagado en la resincronizacion posterior.
					if ( method_exists( $order, 'set_date_paid' ) ) {
						$order->set_date_paid( null );
					}

					if ( 'openpos' === $provider ) {
						$total = (float) $order->get_total();
						$order->update_meta_data( '_op_order_total_paid', 0 );
						$order->update_meta_data( '_op_remain_paid', $total );
					}

					$order->save();
				}
			);

			$order_sync->sync_order( $order->get_id(), array( 'trigger' => 'financial_cancellation' ) );
			$reopened_ids[] = $order->get_id();
		}

		return array_values( array_unique( array_map( 'intval', $reopened_ids ) ) );
	}

	private function resolve_document_payment_status( $paid_total, $balance, $due_date ) {
		if ( $balance <= 0 ) {
			return 'paid';
		}

		if ( $paid_total > 0 ) {
			return 'partial';
		}

		if ( ! empty( $due_date ) && $due_date < gmdate( 'Y-m-d' ) ) {
			return 'overdue';
		}

		return 'pending';
	}
}
