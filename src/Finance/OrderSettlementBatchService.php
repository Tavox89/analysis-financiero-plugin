<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class OrderSettlementBatchService extends BaseRepository {
	const CONTINUE_TIME_BUDGET_SECONDS   = 2.4;
	const CONTINUE_SAFE_BATCH_DUAL       = 1;
	const CONTINUE_SAFE_BATCH_MIXED      = 3;
	const CONTINUE_SAFE_BATCH_STANDARD   = 8;

	private $planner;
	private $batches;
	private $payments;
	private $allocations;
	private $documents;
	private $events;
	private $order_service;

	public function __construct() {
		$this->planner      = new OrderSettlementPlannerService();
		$this->batches      = new OrderSettlementBatchesRepository();
		$this->payments     = new PaymentsRepository();
		$this->allocations  = new PaymentAllocationService();
		$this->documents    = new DocumentsRepository();
		$this->events       = new EventsRepository();
		$this->order_service = new OrderSyncService();
	}

	public function preview( array $args, $origin = 'profile_settlement' ) {
		return $this->planner->preview( $args, $origin );
	}

	public function start( array $args, $origin = 'profile_settlement' ) {
		$preview = $this->planner->preview( $args, $origin );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$signature = sanitize_text_field( (string) ( $args['preview_signature'] ?? ( $preview['preview_signature'] ?? '' ) ) );
		if ( '' === $signature || '' === (string) ( $preview['preview_signature'] ?? '' ) || ! hash_equals( (string) $preview['preview_signature'], $signature ) ) {
			return $this->error( 'asdl_fin_order_settlement_preview_signature', 'La vista previa del abono cambio o ya no es valida. Calculala otra vez antes de confirmar.' );
		}

		$contact_id = (int) ( $preview['contact_id'] ?? 0 );
		$existing   = $this->batches->find_by_preview_signature( $contact_id, $origin, $signature );
		if ( ! empty( $existing['id'] ) && in_array( (string) ( $existing['status'] ?? '' ), array( 'pending', 'running', 'completed', 'completed_with_errors' ), true ) ) {
			return $this->get_status_snapshot( (int) $existing['id'] );
		}

		$batch_payload = (array) ( $preview['batch_payload'] ?? array() );
		$batch_id      = $this->batches->create_batch(
			array(
				'batch_key'         => wp_generate_uuid4(),
				'contact_id'        => $contact_id,
				'origin'            => sanitize_key( (string) $origin ),
				'mode'              => ! empty( $preview['uses_dual'] ) ? 'dual' : 'standard',
				'status'            => 'pending',
				'currency'          => sanitize_text_field( (string) ( $preview['currency'] ?? 'USD' ) ),
				'method_key'        => sanitize_key( (string) ( $batch_payload['method_key'] ?? '' ) ),
				'preview_signature' => $signature,
				'total_received'    => (float) ( $preview['summary']['payment_recorded_total'] ?? ( $preview['summary']['requested_total'] ?? 0 ) ),
				'total_covered'     => (float) ( $preview['summary']['covered_total'] ?? 0 ),
				'discount_total'    => (float) ( $preview['summary']['discount_applied_total'] ?? 0 ),
				'item_count'        => (int) ( $preview['summary']['item_count'] ?? 0 ),
				'processed_count'   => 0,
				'processed_total'   => 0,
				'meta_json'         => array(
					'context'        => $batch_payload,
					'preview'        => array(
						'summary'       => (array) ( $preview['summary'] ?? array() ),
						'items'         => array_map(
							static function ( array $item ) {
								return array(
									'item_key'                => sanitize_text_field( (string) ( $item['item_key'] ?? '' ) ),
									'selection_origin'        => sanitize_key( (string) ( $item['selection_origin'] ?? '' ) ),
									'source_kind'            => sanitize_key( (string) ( $item['source_kind'] ?? '' ) ),
									'provider'               => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
									'external_order_id'      => (int) ( $item['external_order_id'] ?? 0 ),
									'document_id'            => (int) ( $item['document_id'] ?? 0 ),
									'balance_before'         => round( (float) ( $item['balance_before'] ?? 0 ), 6 ),
									'cover_amount'           => round( (float) ( $item['cover_amount'] ?? 0 ), 6 ),
									'customer_paid_amount'   => round( (float) ( $item['customer_paid_amount'] ?? 0 ), 6 ),
									'credit_applied_amount'  => round( (float) ( $item['credit_applied_amount'] ?? 0 ), 6 ),
									'discount_effective_amount' => round( (float) ( $item['discount_effective_amount'] ?? 0 ), 6 ),
									'discount_amount'        => round( (float) ( $item['discount_amount'] ?? 0 ), 6 ),
									'already_discounted'     => ! empty( $item['already_discounted'] ),
									'discount_detection'     => array(
										'status'             => sanitize_key( (string) ( $item['discount_detection']['status'] ?? 'none' ) ),
										'label'              => sanitize_text_field( (string) ( $item['discount_detection']['label'] ?? '' ) ),
										'detected_percent'   => round( (float) ( $item['discount_detection']['detected_percent'] ?? 0 ), 6 ),
										'already_discounted' => ! empty( $item['discount_detection']['already_discounted'] ?? false ),
									),
									'expected_balance_after' => round( (float) ( $item['expected_balance_after'] ?? 0 ), 6 ),
								);
							},
							(array) ( $preview['items'] ?? array() )
						),
					),
					'uses_dual'      => ! empty( $preview['uses_dual'] ),
					'has_historical_items' => ! empty( $preview['summary']['has_historical_items'] ),
					'execution_mode' => sanitize_key( (string) ( $preview['execution_mode'] ?? 'runner' ) ),
					'batch_size'     => max( 1, (int) ( $preview['thresholds']['batch_size'] ?? 5 ) ),
					'last_batch'     => 0,
				),
			)
		);

		if ( is_wp_error( $batch_id ) ) {
			return $batch_id;
		}

		$payment_ids = $this->create_batch_payments( $batch_id, $preview );
		if ( is_wp_error( $payment_ids ) ) {
			$this->batches->update_batch( $batch_id, array( 'status' => 'failed' ) );
			return $payment_ids;
		}

		$items = array();
		foreach ( (array) ( $preview['items'] ?? array() ) as $item ) {
			$items[] = array(
				'sort_index'             => (int) ( $item['sequence'] ?? 0 ),
				'source_kind'            => sanitize_key( (string) ( $item['source_kind'] ?? 'current_live' ) ),
				'provider'               => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
				'external_order_id'      => (int) ( $item['external_order_id'] ?? 0 ),
				'document_id'            => (int) ( $item['document_id'] ?? 0 ),
				'order_number'           => sanitize_text_field( (string) ( $item['order_number'] ?? '' ) ),
				'issue_date'             => sanitize_text_field( (string) ( $item['issue_date'] ?? '' ) ),
				'balance_before'         => round( (float) ( $item['balance_before'] ?? 0 ), 6 ),
				'cover_amount'           => round( (float) ( $item['cover_amount'] ?? 0 ), 6 ),
				'customer_paid_amount'   => round( (float) ( $item['customer_paid_amount'] ?? 0 ), 6 ),
				'discount_amount'        => round( (float) ( $item['discount_amount'] ?? 0 ), 6 ),
				'expected_balance_after' => round( (float) ( $item['expected_balance_after'] ?? 0 ), 6 ),
				'status'                 => 'pending',
				'meta_json'              => array(
					'item_key'         => sanitize_text_field( (string) ( $item['item_key'] ?? '' ) ),
					'selection_origin' => sanitize_key( (string) ( $item['selection_origin'] ?? 'oldest_first' ) ),
					'order_label'     => sanitize_text_field( (string) ( $item['order_label'] ?? '' ) ),
					'edit_url'        => esc_url_raw( (string) ( $item['edit_url'] ?? '' ) ),
					'status_key'      => sanitize_key( (string) ( $item['status_key'] ?? '' ) ),
					'status_label'    => sanitize_text_field( (string) ( $item['status_label'] ?? '' ) ),
					'sequence'        => (int) ( $item['sequence'] ?? 0 ),
					'credit_applied_amount' => round( (float) ( $item['credit_applied_amount'] ?? 0 ), 6 ),
					'discount_effective_amount' => round( (float) ( $item['discount_effective_amount'] ?? 0 ), 6 ),
					'discount_detection' => array(
						'status'             => sanitize_key( (string) ( $item['discount_detection']['status'] ?? 'none' ) ),
						'label'              => sanitize_text_field( (string) ( $item['discount_detection']['label'] ?? '' ) ),
						'detected_percent'   => round( (float) ( $item['discount_detection']['detected_percent'] ?? 0 ), 6 ),
						'already_discounted' => ! empty( $item['discount_detection']['already_discounted'] ?? false ),
					),
					'preview_meta'    => (array) ( $item['meta'] ?? array() ),
				),
			);
		}

		if ( ! $this->batches->append_items( $batch_id, $items ) ) {
			$this->batches->update_batch(
				$batch_id,
				array(
					'status'   => 'failed',
					'meta_json'=> array_merge(
						(array) ( $this->batches->find( $batch_id )['meta'] ?? array() ),
						array( 'last_error' => 'No se pudieron congelar los items del lote.' )
					),
				)
			);
			return $this->error( 'asdl_fin_order_settlement_items', 'No se pudieron congelar los pedidos del lote de abono.' );
		}

		$batch = $this->batches->find( $batch_id );
		$meta  = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$meta['main_payment_id']     = (int) ( $payment_ids['main_payment_id'] ?? 0 );
		$meta['discount_payment_id'] = (int) ( $payment_ids['discount_payment_id'] ?? 0 );
		$meta['result']              = array();
		$this->batches->update_batch(
			$batch_id,
			array(
				'status'   => 'running',
				'meta_json'=> $meta,
			)
		);

		if ( 'fast_path' === sanitize_key( (string) ( $preview['execution_mode'] ?? 'runner' ) ) ) {
			$guard = 0;
			do {
				$status = $this->continue_batch( $batch_id );
				if ( is_wp_error( $status ) ) {
					return $status;
				}
				$guard++;
			} while ( in_array( (string) ( $status['job']['status'] ?? '' ), array( 'pending', 'running' ), true ) && $guard < 100 );
		}

		return $this->get_status_snapshot( $batch_id );
	}

	public function continue_batch( $batch_id ) {
		$batch = $this->batches->find( $batch_id );
		if ( empty( $batch['id'] ) ) {
			return $this->error( 'asdl_fin_order_settlement_batch', 'No encontramos el lote del abono.' );
		}

		$status = sanitize_key( (string) ( $batch['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'pending', 'running' ), true ) ) {
			return $this->get_status_snapshot( (int) $batch['id'] );
		}

		$meta       = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$batch_size = $this->resolve_continue_batch_size( $batch, $meta );
		$items      = $this->batches->list_pending_items( (int) $batch['id'], $batch_size );

		if ( empty( $items ) ) {
			$this->finalize_batch( $batch );
			return $this->get_status_snapshot( (int) $batch['id'] );
		}

		$processed_count = (int) ( $batch['processed_count'] ?? 0 );
		$processed_total = (float) ( $batch['processed_total'] ?? 0 );
		$last_batch      = 0;
		$started_at      = microtime( true );

		foreach ( $items as $item ) {
			$last_batch++;
			$result = $this->process_item( $batch, $item );

			$update_data = array(
				'status'                 => sanitize_key( (string) ( $result['status'] ?? 'error' ) ),
				'document_id'            => ! empty( $result['document_id'] ) ? (int) $result['document_id'] : (int) ( $item['document_id'] ?? 0 ),
				'balance_before'         => round( (float) ( $result['balance_before'] ?? ( $item['balance_before'] ?? 0 ) ), 6 ),
				'cover_amount'           => round( (float) ( $result['cover_amount'] ?? 0 ), 6 ),
				'customer_paid_amount'   => round( (float) ( $result['customer_paid_amount'] ?? 0 ), 6 ),
				'discount_amount'        => round( (float) ( $result['discount_amount'] ?? 0 ), 6 ),
				'expected_balance_after' => round( (float) ( $result['expected_balance_after'] ?? ( $item['expected_balance_after'] ?? 0 ) ), 6 ),
				'error_message'          => ! empty( $result['error_message'] ) ? (string) $result['error_message'] : '',
				'meta_json'              => array_merge(
					(array) ( $item['meta'] ?? array() ),
					array(
						'final_status'     => sanitize_key( (string) ( $result['final_status'] ?? '' ) ),
						'final_status_label'=> sanitize_text_field( (string) ( $result['final_status_label'] ?? '' ) ),
						'applied_at'       => current_time( 'mysql' ),
						'applied_payment_id'=> ! empty( $result['payment_id'] ) ? (int) $result['payment_id'] : 0,
						'credit_applied_amount' => round( (float) ( $result['credit_applied_amount'] ?? 0 ), 6 ),
						'discount_detection_status' => sanitize_key( (string) ( $result['discount_detection_status'] ?? '' ) ),
						'compensation_payment_ids' => array_values( array_map( 'intval', (array) ( $result['compensation_payment_ids'] ?? array() ) ) ),
					)
				),
			);

			$this->batches->update_item( (int) $item['id'], $update_data );
			$processed_count++;
			$processed_total = round( $processed_total + (float) ( $result['cover_amount'] ?? 0 ), 6 );

			$meta['last_batch'] = $last_batch;
			$this->batches->update_batch(
				(int) $batch['id'],
				array(
					'status'          => 'running',
					'processed_count' => $processed_count,
					'processed_total' => $processed_total,
					'meta_json'       => $meta,
				)
			);

			if ( ( microtime( true ) - $started_at ) >= self::CONTINUE_TIME_BUDGET_SECONDS ) {
				break;
			}
		}

		if ( 0 === count( $this->batches->list_pending_items( (int) $batch['id'], 1 ) ) ) {
			$this->finalize_batch( $this->batches->find( (int) $batch['id'] ) );
		}

		return $this->get_status_snapshot( (int) $batch['id'] );
	}

	public function status( array $args ) {
		$batch_id = absint( $args['batch_id'] ?? 0 );
		if ( $batch_id > 0 ) {
			return $this->get_status_snapshot( $batch_id );
		}

		$contact_id = absint( $args['contact_id'] ?? 0 );
		$origin     = sanitize_key( (string) ( $args['origin'] ?? 'profile_settlement' ) );
		if ( $contact_id <= 0 ) {
			return array( 'job' => array() );
		}

		$batch = $this->batches->find_active_for_contact( $contact_id, $origin );
		if ( empty( $batch['id'] ) ) {
			return array( 'job' => array() );
		}

		return $this->get_status_snapshot( (int) $batch['id'] );
	}

	public function result( array $args ) {
		$batch_id = absint( $args['batch_id'] ?? 0 );
		if ( $batch_id <= 0 ) {
			return $this->error( 'asdl_fin_order_settlement_batch', 'No encontramos el lote del abono solicitado.' );
		}

		$batch = $this->batches->find( $batch_id );
		if ( empty( $batch['id'] ) ) {
			return $this->error( 'asdl_fin_order_settlement_batch', 'No encontramos el lote del abono solicitado.' );
		}

		$snapshot          = $this->get_status_snapshot( $batch_id );
		$snapshot['items'] = $this->batches->list_batch_items( $batch_id, 500 );

		return $snapshot;
	}

	private function create_batch_payments( $batch_id, array $preview ) {
		$batch_payload = (array) ( $preview['batch_payload'] ?? array() );
		$reference     = sanitize_text_field( (string) ( $batch_payload['reference'] ?? '' ) );
		$reference     = '' !== $reference ? $reference : sprintf( 'SET-%1$d-%2$s', (int) $batch_id, gmdate( 'YmdHis' ) );
		$notes         = sanitize_textarea_field( (string) ( $batch_payload['notes'] ?? 'Abono aplicado desde el perfil del cliente.' ) );
		$main_payment  = $this->payments->create(
			array(
				'payment_type' => sanitize_key( (string) ( $batch_payload['payment_type'] ?? 'collection' ) ),
				'account_id'   => ! empty( $batch_payload['account_id'] ) ? absint( $batch_payload['account_id'] ) : null,
				'contact_id'   => (int) ( $preview['contact_id'] ?? 0 ),
				'status'       => 'posted',
				'payment_date' => sanitize_text_field( (string) ( $batch_payload['payment_date'] ?? gmdate( 'Y-m-d' ) ) ),
				'currency'     => sanitize_text_field( (string) ( $preview['currency'] ?? 'USD' ) ),
				'total'        => (float) ( $preview['summary']['payment_recorded_total'] ?? ( $preview['summary']['requested_total'] ?? 0 ) ),
				'method_key'   => sanitize_key( (string) ( $batch_payload['method_key'] ?? '' ) ),
				'reference'    => $reference,
				'notes'        => $notes,
			)
		);

		if ( is_wp_error( $main_payment ) ) {
			return $main_payment;
		}

		$discount_payment = 0;
		if ( ! empty( $preview['uses_dual'] ) && (float) ( $preview['summary']['discount_applied_total'] ?? 0 ) > 0 ) {
			$discount_payment = $this->payments->create(
				array(
					'payment_type' => 'adjustment',
					'account_id'   => ! empty( $batch_payload['account_id'] ) ? absint( $batch_payload['account_id'] ) : null,
					'contact_id'   => (int) ( $preview['contact_id'] ?? 0 ),
					'status'       => 'posted',
					'payment_date' => sanitize_text_field( (string) ( $batch_payload['payment_date'] ?? gmdate( 'Y-m-d' ) ) ),
					'currency'     => sanitize_text_field( (string) ( $preview['currency'] ?? 'USD' ) ),
					'total'        => (float) ( $preview['summary']['discount_applied_total'] ?? 0 ),
					'method_key'   => 'dual_price_discount',
					'reference'    => $reference . '-DUAL',
					'notes'        => 'Descuento precio dual aplicado automaticamente sobre pedidos de tienda.',
				)
			);

			if ( is_wp_error( $discount_payment ) ) {
				return $discount_payment;
			}
		}

		return array(
			'main_payment_id'     => (int) $main_payment,
			'discount_payment_id' => (int) $discount_payment,
		);
	}

	private function process_item( array $batch, array $item ) {
		$meta                = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$context             = (array) ( $meta['context'] ?? array() );
		$main_payment_id     = (int) ( $meta['main_payment_id'] ?? 0 );
		$discount_payment_id = (int) ( $meta['discount_payment_id'] ?? 0 );
		$order_id            = (int) ( $item['external_order_id'] ?? 0 );
		$source_kind         = sanitize_key( (string) ( $item['source_kind'] ?? 'current_live' ) );
		$document_id         = (int) ( $item['document_id'] ?? 0 );
		$sync                = null;

		if ( $order_id <= 0 ) {
			return array(
				'status'        => 'error',
				'error_message' => 'El pedido del lote no tiene un identificador valido.',
			);
		}

		if ( 'current_live' === $source_kind || $document_id <= 0 ) {
			$sync = $this->order_service->sync_order(
				$order_id,
				array(
					'trigger' => 'order_settlement_batch',
				)
			);

			if ( is_wp_error( $sync ) ) {
				return array(
					'status'        => 'error',
					'error_message' => $sync->get_error_message(),
				);
			}
		}

		$document = $this->resolve_item_document( $item, $sync );
		if ( empty( $document['id'] ) && 'historical_index' === $source_kind ) {
			$sync = $this->order_service->sync_order(
				$order_id,
				array(
					'trigger' => 'order_settlement_batch_recovery',
				)
			);

			if ( ! is_wp_error( $sync ) ) {
				$document = $this->resolve_item_document( $item, $sync );
			}
		}

		if ( empty( $document['id'] ) ) {
			return array(
				'status'        => 'skipped',
				'error_message' => 'El pedido ya no tiene un documento cobrable enlazado.',
			);
		}

		$currency        = (string) ( $document['currency'] ?? $batch['currency'] ?? '' );
		$current_balance = $this->normalize_balance_amount( (float) ( $document['balance'] ?? 0 ), $currency );
		$planned_balance = $this->normalize_balance_amount( (float) ( $item['balance_before'] ?? 0 ), $currency );
		$planned_cash    = round( max( 0, (float) ( $item['customer_paid_amount'] ?? 0 ) ), 6 );
		$planned_credit  = round( max( 0, (float) ( $item['meta']['credit_applied_amount'] ?? $item['credit_applied_amount'] ?? 0 ) ), 6 );
		$discount_detection = (array) ( $item['meta']['discount_detection'] ?? ( $item['meta']['preview_meta']['discount_detection'] ?? array() ) );

		if ( $this->money_balance_is_zero( $current_balance, $currency ) ) {
			return array(
				'status'         => 'skipped',
				'document_id'    => (int) ( $document['id'] ?? 0 ),
				'balance_before' => $planned_balance,
				'error_message'  => 'El pedido ya no tiene saldo pendiente.',
			);
		}

		if ( abs( $current_balance - $planned_balance ) > 0.01 ) {
			return array(
				'status'                 => 'skipped',
				'document_id'            => (int) ( $document['id'] ?? 0 ),
				'balance_before'         => $planned_balance,
				'expected_balance_after' => $current_balance,
				'error_message'          => 'El saldo del pedido cambio desde la vista previa. Recalcula el abono antes de intentarlo de nuevo.',
			);
		}

		if ( $planned_cash <= 0 && $planned_credit <= 0 ) {
			return array(
				'status'        => 'skipped',
				'document_id'   => (int) ( $document['id'] ?? 0 ),
				'error_message' => 'No quedo monto suficiente para aplicar este pedido.',
			);
		}

		$main_payment            = null;
		$customer_paid           = 0.0;
		$discount_amount         = round( max( 0, (float) ( $item['discount_amount'] ?? 0 ) ), 6 );
		$credit_applied          = 0.0;
		$cover_amount            = 0.0;
		$document_status         = 'pending';
		$compensation_payment_ids = array();
		$discount_status         = sanitize_key( (string) ( $discount_detection['status'] ?? 'none' ) );

		if ( in_array( $discount_status, array( 'same_dual', 'different' ), true ) ) {
			$discount_amount = 0.0;
		}

		if ( $planned_cash > 0 ) {
			if ( $main_payment_id <= 0 ) {
				return array(
					'status'        => 'error',
					'document_id'   => (int) ( $document['id'] ?? 0 ),
					'error_message' => 'No encontramos el pago principal asociado a este lote.',
				);
			}

			$main_payment = $this->payments->find( $main_payment_id );
			if ( empty( $main_payment['id'] ) || (float) ( $main_payment['available_amount'] ?? 0 ) <= 0 ) {
				return array(
					'status'        => 'error',
					'document_id'   => (int) ( $document['id'] ?? 0 ),
					'error_message' => 'El pago principal del lote ya no tiene monto disponible.',
				);
			}

			$customer_paid = min(
				$planned_cash,
				$current_balance,
				round( (float) ( $main_payment['available_amount'] ?? 0 ), 6 )
			);
		}

		$this->begin_transaction();

		if ( $customer_paid > 0 ) {
			$main_allocation = $this->allocations->allocate(
				array(
					'payment_id'         => $main_payment_id,
					'document_id'        => (int) $document['id'],
					'amount'             => $customer_paid,
					'notes'              => sprintf( 'Abono por lote sobre pedido #%s.', sanitize_text_field( (string) ( $item['order_number'] ?? '' ) ) ),
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $main_allocation ) ) {
				$this->rollback_transaction();
				return array(
					'status'        => 'error',
					'document_id'   => (int) ( $document['id'] ?? 0 ),
					'error_message' => $main_allocation->get_error_message(),
				);
			}

			do_action( 'asdl_fin_payment_allocated', $main_allocation );
			$cover_amount    = round( $cover_amount + $customer_paid, 6 );
			$document_status = sanitize_key( (string) ( $main_allocation['document_status'] ?? $document_status ) );
		}

		if ( $discount_amount > 0 ) {
			$planned_discount_amount = $discount_amount;
			$discount_payment = $this->payments->find( $discount_payment_id );
			if ( empty( $discount_payment['id'] ) ) {
				$this->rollback_transaction();
				return array(
					'status'        => 'error',
					'document_id'   => (int) ( $document['id'] ?? 0 ),
					'error_message' => 'El ajuste tecnico del precio dual ya no tiene monto disponible.',
				);
			}

			$document_after_cash = $this->documents->find( (int) $document['id'] );
			$discount_balance_limit = $this->normalize_balance_amount( (float) ( $document_after_cash['balance'] ?? 0 ), $currency );
			$discount_payment_available = $this->normalize_balance_amount(
				(float) ( $discount_payment['available_amount'] ?? 0 ),
				(string) ( $discount_payment['currency'] ?? $currency )
			);

			$document_drift = max( 0, $planned_discount_amount - $discount_balance_limit );
			if ( ! $this->money_balance_is_zero( $document_drift, $currency ) ) {
				$this->rollback_transaction();
				return array(
					'status'        => 'error',
					'document_id'   => (int) ( $document['id'] ?? 0 ),
					'error_message' => 'El saldo del pedido cambio durante el descuento dual. Recalcula el abono antes de intentarlo otra vez.',
				);
			}

			$payment_drift = max( 0, $planned_discount_amount - $discount_payment_available );
			if ( ! $this->money_balance_is_zero( $payment_drift, (string) ( $discount_payment['currency'] ?? $currency ) ) ) {
				$this->rollback_transaction();
				return array(
					'status'        => 'error',
					'document_id'   => (int) ( $document['id'] ?? 0 ),
					'error_message' => 'El ajuste tecnico del precio dual ya no tiene monto disponible.',
				);
			}

			$discount_amount = round( min( $planned_discount_amount, $discount_balance_limit, $discount_payment_available ), 6 );
			if ( $this->money_balance_is_zero( $discount_amount, $currency ) ) {
				$discount_amount = 0.0;
			}
		}

		if ( $discount_amount > 0 ) {

			$discount_allocation = $this->allocations->allocate(
				array(
					'payment_id'         => $discount_payment_id,
					'document_id'        => (int) $document['id'],
					'amount'             => $discount_amount,
					'notes'              => sprintf( 'Descuento precio dual aplicado sobre pedido #%s.', sanitize_text_field( (string) ( $item['order_number'] ?? '' ) ) ),
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $discount_allocation ) ) {
				$this->rollback_transaction();
				return array(
					'status'        => 'error',
					'document_id'   => (int) ( $document['id'] ?? 0 ),
					'error_message' => $discount_allocation->get_error_message(),
				);
			}

			do_action( 'asdl_fin_payment_allocated', $discount_allocation );
			$cover_amount    = round( $cover_amount + $discount_amount, 6 );
			$document_status = sanitize_key( (string) ( $discount_allocation['document_status'] ?? $document_status ) );
		}

		if ( $planned_credit > 0 ) {
			$document_after_cash = $this->documents->find( (int) $document['id'] );
			$credit_limit        = min( $planned_credit, round( max( 0, (float) ( $document_after_cash['balance'] ?? 0 ) ), 6 ) );

			if ( $credit_limit > 0 ) {
				$credit_result = $this->apply_credit_to_document(
					$batch,
					$context,
					(array) $document_after_cash,
					$credit_limit,
					array_filter( array( $main_payment_id, $discount_payment_id ) )
				);

				if ( is_wp_error( $credit_result ) ) {
					$this->rollback_transaction();
					return array(
						'status'        => 'error',
						'document_id'   => (int) ( $document['id'] ?? 0 ),
						'error_message' => $credit_result->get_error_message(),
					);
				}

				$credit_applied = round( (float) ( $credit_result['applied_total'] ?? 0 ), 6 );

				if ( $credit_applied + 0.00001 < $planned_credit ) {
					$this->rollback_transaction();
					return array(
						'status'        => 'error',
						'document_id'   => (int) ( $document['id'] ?? 0 ),
						'error_message' => 'El saldo a favor disponible cambio desde la vista previa. Recalcula el abono antes de intentarlo otra vez.',
					);
				}

				$cover_amount             = round( $cover_amount + $credit_applied, 6 );
				$compensation_payment_ids = array_values( array_map( 'intval', (array) ( $credit_result['compensation_payment_ids'] ?? array() ) ) );
			}
		}

		$this->commit_transaction();

		$final_document = $this->documents->find( (int) $document['id'] );
		if ( ! empty( $final_document['id'] ) ) {
			$expected_balance_after = $this->normalize_balance_amount( (float) ( $final_document['balance'] ?? 0 ), $currency );
			$document_status        = sanitize_key( (string) ( $final_document['payment_status'] ?? $document_status ) );
		} else {
			$expected_balance_after = $this->normalize_balance_amount( $planned_balance - $cover_amount, $currency );
			$document_status        = $this->money_balance_is_zero( $expected_balance_after, $currency ) ? 'paid' : ( $cover_amount > 0 ? 'partial' : $document_status );
		}

		$this->append_order_note( $context, $item, $customer_paid, $discount_amount, $credit_applied, $cover_amount, $document_status );

		return array(
			'status'                   => 'applied',
			'document_id'              => (int) ( $document['id'] ?? 0 ),
			'balance_before'           => $planned_balance,
			'cover_amount'             => $cover_amount,
			'customer_paid_amount'     => $customer_paid,
			'discount_amount'          => $discount_amount,
			'credit_applied_amount'    => $credit_applied,
			'discount_detection_status'=> $discount_status,
			'compensation_payment_ids' => $compensation_payment_ids,
			'expected_balance_after'   => $expected_balance_after,
			'payment_id'               => $main_payment_id,
			'final_status'             => $document_status,
			'final_status_label'       => 'paid' === $document_status ? 'Pagado' : 'Abonado',
		);
	}

	private function resolve_item_document( array $item, $sync = null ) {
		$document_id = 0;

		if ( is_array( $sync ) && ! empty( $sync['document_id'] ) ) {
			$document_id = (int) $sync['document_id'];
		}

		if ( $document_id <= 0 ) {
			$document_id = (int) ( $item['document_id'] ?? 0 );
		}

		if ( $document_id > 0 ) {
			$document = $this->documents->find( $document_id );
			if ( ! empty( $document['id'] ) ) {
				return (array) $document;
			}
		}

		$order_id = (int) ( $item['external_order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return null;
		}

		$linked = $this->order_service->get_linked_document_context( $order_id );
		if ( empty( $linked['document']['id'] ) ) {
			return null;
		}

		return (array) $linked['document'];
	}

	private function resolve_continue_batch_size( array $batch, array $meta ) {
		$requested = max( 1, (int) ( $meta['batch_size'] ?? 5 ) );
		$context   = (array) ( $meta['context'] ?? array() );
		$has_dual  = ! empty( $context['uses_dual'] ) || 'dual_discount' === sanitize_key( (string) ( $batch['mode'] ?? '' ) );
		$has_mixed = ! empty( $meta['has_historical_items'] );

		if ( $has_dual ) {
			return min( $requested, self::CONTINUE_SAFE_BATCH_DUAL );
		}

		if ( $has_mixed ) {
			return min( $requested, self::CONTINUE_SAFE_BATCH_MIXED );
		}

		return min( $requested, self::CONTINUE_SAFE_BATCH_STANDARD );
	}

	private function finalize_batch( array $batch ) {
		$batch_id = (int) ( $batch['id'] ?? 0 );
		if ( $batch_id <= 0 ) {
			return;
		}

		$items            = $this->batches->list_batch_items( $batch_id, 5000 );
		$meta             = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$context          = (array) ( $meta['context'] ?? array() );
		$main_payment_id  = (int) ( $meta['main_payment_id'] ?? 0 );
		$discount_payment = (int) ( $meta['discount_payment_id'] ?? 0 );
		$applied_total    = 0.0;
		$covered_total    = 0.0;
		$discount_total   = 0.0;
		$credit_total     = 0.0;
		$error_count      = 0;
		$skipped_count    = 0;
		$closed_order_ids = array();
		$partial_order_ids= array();

		foreach ( $items as $item ) {
			$status = sanitize_key( (string) ( $item['status'] ?? '' ) );
			if ( 'applied' === $status ) {
				$applied_total  = round( $applied_total + (float) ( $item['customer_paid_amount'] ?? 0 ), 6 );
				$covered_total  = round( $covered_total + (float) ( $item['cover_amount'] ?? 0 ), 6 );
				$discount_total = round( $discount_total + (float) ( $item['discount_amount'] ?? 0 ), 6 );
				$credit_total   = round( $credit_total + (float) ( $item['meta']['credit_applied_amount'] ?? 0 ), 6 );
				if ( 'paid' === sanitize_key( (string) ( $item['meta']['final_status'] ?? '' ) ) ) {
					$closed_order_ids[] = (int) ( $item['external_order_id'] ?? 0 );
				} else {
					$partial_order_ids[] = (int) ( $item['external_order_id'] ?? 0 );
				}
			} elseif ( 'error' === $status ) {
				$error_count++;
			} elseif ( 'skipped' === $status ) {
				$skipped_count++;
			}
		}

		$unapplied_total = round( max( 0, (float) ( $batch['total_received'] ?? 0 ) - $applied_total ), 6 );
		$result          = array(
			'contact_id'             => (int) ( $batch['contact_id'] ?? 0 ),
			'payment_id'             => $main_payment_id,
			'discount_payment_ids'   => $discount_payment > 0 ? array( $discount_payment ) : array(),
			'applied_total'          => $applied_total,
			'covered_total'          => $covered_total,
			'credit_applied_total'   => $credit_total,
			'dual_discount_applied'  => $discount_total > 0,
			'dual_discount_percent'  => round( (float) ( $context['discount_percent'] ?? 0 ), 6 ),
			'dual_discount_total'    => $discount_total,
			'unapplied_total'        => $unapplied_total,
			'closed_order_ids'       => array_values( array_unique( array_filter( array_map( 'intval', $closed_order_ids ) ) ) ),
			'partial_order_ids'      => array_values( array_unique( array_filter( array_map( 'intval', $partial_order_ids ) ) ) ) ,
			'item_count'             => (int) ( $batch['item_count'] ?? 0 ),
			'processed_count'        => (int) ( $batch['processed_count'] ?? 0 ),
			'error_count'            => $error_count,
			'skipped_count'          => $skipped_count,
			'origin'                 => sanitize_key( (string) ( $batch['origin'] ?? 'profile_settlement' ) ),
			'batch_id'               => $batch_id,
		);

		$main_meta = array(
			'order_settlement_batch_id'      => $batch_id,
			'order_settlement_origin'        => sanitize_key( (string) ( $batch['origin'] ?? 'profile_settlement' ) ),
			'order_settlement_execution_mode'=> sanitize_key( (string) ( $meta['execution_mode'] ?? 'runner' ) ),
			'order_settlement_order_ids'     => array_values( array_map( 'intval', wp_list_pluck( $items, 'external_order_id' ) ) ),
			'credit_applied_total'           => $credit_total,
			'dual_discount_mode'             => $discount_total > 0 ? sanitize_key( (string) ( $context['dual_discount_mode'] ?? ( ! empty( $context['uses_dual'] ) ? 'auto' : '' ) ) ) : '',
			'dual_discount_total'            => $discount_total,
			'dual_discount_payment_ids'      => $discount_payment > 0 ? array( $discount_payment ) : array(),
		);
		if ( $main_payment_id > 0 ) {
			$this->payments->set_status( $main_payment_id, 'posted', $main_meta );
		}

		if ( $discount_payment > 0 ) {
			$this->payments->set_status(
				$discount_payment,
				'posted',
				array(
					'order_settlement_batch_id'        => $batch_id,
					'dual_discount_parent_payment_id'  => $main_payment_id,
					'dual_discount_total'              => $discount_total,
					'dual_discount_percent'            => round( (float) ( $context['discount_percent'] ?? 0 ), 6 ),
				)
			);
		}

		$meta['result']      = $result;
		$meta['error_count'] = $error_count;
		$meta['skipped_count'] = $skipped_count;

		$this->batches->update_batch(
			$batch_id,
			array(
				'status'          => $error_count > 0 ? 'completed_with_errors' : 'completed',
				'processed_count' => (int) ( $batch['item_count'] ?? 0 ),
				'processed_total' => $covered_total,
				'discount_total'  => $discount_total,
				'meta_json'       => $meta,
			)
		);

		$this->events->log(
			'contact',
			(int) ( $batch['contact_id'] ?? 0 ),
			'profile_payment_applied',
			'Abono aplicado por lotes sobre pedidos Woo/OpenPOS del perfil.',
			$result
		);

		do_action( 'asdl_fin_profile_payment_applied', $result );

		$scopes = array(
			RuntimeRefreshService::SCOPE_CONTACT,
			RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
		);

		if ( ! empty( $result['closed_order_ids'] ) || ! empty( $result['partial_order_ids'] ) ) {
			$scopes[] = RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES;
			$scopes[] = RuntimeRefreshService::SCOPE_HISTORICAL_DATA;
			OrderSyncService::invalidate_cached_views();
		}

		RuntimeRefreshService::invalidate(
			$scopes,
			array(
				'contact_id' => (int) ( $batch['contact_id'] ?? 0 ),
			)
		);
	}

	private function get_status_snapshot( $batch_id ) {
		$batch = $this->batches->find( $batch_id );
		if ( empty( $batch['id'] ) ) {
			return array( 'job' => array() );
		}

		$meta       = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$error_count = $this->batches->count_items_by_status( $batch_id, 'error' );
		$job = array(
			'batch_id'        => (int) $batch['id'],
			'status'          => sanitize_key( (string) ( $batch['status'] ?? '' ) ),
			'processed_count' => (int) ( $batch['processed_count'] ?? 0 ),
			'item_count'      => (int) ( $batch['item_count'] ?? 0 ),
			'processed_total' => round( (float) ( $batch['processed_total'] ?? 0 ), 6 ),
			'total_received'  => round( (float) ( $batch['total_received'] ?? 0 ), 6 ),
			'total_covered'   => round( (float) ( $batch['total_covered'] ?? 0 ), 6 ),
			'credit_applied_total' => round( (float) ( $meta['result']['credit_applied_total'] ?? 0 ), 6 ),
			'discount_total'  => round( (float) ( $batch['discount_total'] ?? 0 ), 6 ),
			'errors_count'    => $error_count,
			'last_batch'      => (int) ( $meta['last_batch'] ?? 0 ),
			'updated_at'      => sanitize_text_field( (string) ( $batch['updated_at'] ?? '' ) ),
			'execution_mode'  => sanitize_key( (string) ( $meta['execution_mode'] ?? 'runner' ) ),
			'batch_size'      => (int) ( $meta['batch_size'] ?? 0 ),
			'contact_id'      => (int) ( $batch['contact_id'] ?? 0 ),
			'origin'          => sanitize_key( (string) ( $batch['origin'] ?? 'profile_settlement' ) ),
		);

		return array(
			'batch'  => $batch,
			'job'    => $job,
			'result' => (array) ( $meta['result'] ?? array() ),
			'errors' => $error_count > 0 ? $this->batches->list_batch_errors( $batch_id, 20 ) : array(),
		);
	}

	private function append_order_note( array $context, array $item, $customer_paid, $discount_amount, $credit_amount, $cover_amount, $document_status ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( (int) ( $item['external_order_id'] ?? 0 ) );
		if ( ! $order ) {
			return;
		}

		$current_user = wp_get_current_user();
		$user_label   = $current_user && ! empty( $current_user->display_name ) ? $current_user->display_name : 'Finanzas ASD';
		$method_label = ! empty( $context['method_key'] ) ? sanitize_key( (string) $context['method_key'] ) : 'collection';
		$status_line  = 'paid' === sanitize_key( (string) $document_status ) ? 'Pedido cerrado por completo.' : 'Pedido con saldo remanente tras el abono.';
		$credit_note  = $credit_amount > 0
			? sprintf(
				' | Saldo a favor aplicado: %s',
				wp_strip_all_tags( $this->format_money( $credit_amount, $context['currency'] ?? 'USD' ) )
			)
			: '';

		if ( $discount_amount > 0 ) {
			$note = sprintf(
				'Finanzas ASD aplico un abono con precio dual por lote. Metodo: %1$s | Neto recibido: %2$s%3$s | Descuento: %4$s (%5$s%%) | Deuda cubierta: %6$s | %7$s | Operado por: %8$s.',
				$method_label,
				wp_strip_all_tags( $this->format_money( $customer_paid, $context['currency'] ?? 'USD' ) ),
				$credit_note,
				wp_strip_all_tags( $this->format_money( $discount_amount, $context['currency'] ?? 'USD' ) ),
				number_format_i18n( (float) ( $context['discount_percent'] ?? 0 ), 2 ),
				wp_strip_all_tags( $this->format_money( $cover_amount, $context['currency'] ?? 'USD' ) ),
				$status_line,
				$user_label
			);
		} else {
			$note = sprintf(
				'Finanzas ASD aplico un abono por lote desde el perfil. Metodo: %1$s | Monto recibido: %2$s%3$s | Deuda cubierta: %4$s | %5$s | Operado por: %6$s.',
				$method_label,
				wp_strip_all_tags( $this->format_money( $customer_paid, $context['currency'] ?? 'USD' ) ),
				$credit_note,
				wp_strip_all_tags( $this->format_money( $cover_amount, $context['currency'] ?? 'USD' ) ),
				$status_line,
				$user_label
			);
		}

		$order->add_order_note( $note, false, true );
	}

	private function apply_credit_to_document( array $batch, array $context, array $document, $limit_amount, array $exclude_payment_ids = array() ) {
		$remaining       = max( 0, (float) $limit_amount );
		$contact_id      = ! empty( $document['contact_id'] ) ? (int) $document['contact_id'] : (int) ( $batch['contact_id'] ?? ( $context['contact_id'] ?? 0 ) );
		$currency        = strtoupper( sanitize_text_field( (string) ( $document['currency'] ?? ( $context['currency'] ?? 'USD' ) ) ) );
		$payment_date    = sanitize_text_field( (string) ( $context['payment_date'] ?? current_time( 'Y-m-d' ) ) );
		$account_id      = ! empty( $context['account_id'] ) ? absint( $context['account_id'] ) : 0;
		$reference_base  = sprintf( 'SET-%1$d-%2$d', (int) ( $batch['id'] ?? 0 ), (int) ( $document['id'] ?? 0 ) );
		$source_payments = array();
		$compensation_ids = array();

		if ( $contact_id <= 0 || $remaining <= 0 ) {
			return array(
				'applied_total'            => 0.0,
				'remaining'                => $remaining,
				'documents'                => array(),
				'compensation_payment_ids' => array(),
			);
		}

		$credit_payments = $this->get_credit_payments( $contact_id, $currency, $exclude_payment_ids );
		foreach ( $credit_payments as $payment ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$available = round( max( 0, (float) ( $payment['available_amount'] ?? 0 ) ), 6 );
			if ( $available <= 0 ) {
				continue;
			}

			$apply_amount = min( $remaining, $available );
			$result       = $this->allocations->allocate(
				array(
					'payment_id'         => (int) ( $payment['id'] ?? 0 ),
					'document_id'        => (int) ( $document['id'] ?? 0 ),
					'amount'             => $apply_amount,
					'notes'              => 'Saldo a favor preexistente aplicado dentro del lote de abonos.',
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			do_action( 'asdl_fin_payment_allocated', $result );
			$remaining         = round( max( 0, $remaining - $apply_amount ), 6 );
			$source_payments[] = array(
				'payment_id'     => (int) ( $payment['id'] ?? 0 ),
				'payment_number' => sanitize_text_field( (string) ( $payment['payment_number'] ?? '' ) ),
				'applied_total'  => $apply_amount,
			);
		}

		if ( $remaining > 0 ) {
			$payable_documents = $this->get_payable_documents( $contact_id, $currency );
			$payable_total     = min( $remaining, $this->sum_document_balances( $payable_documents ) );

			if ( $payable_total + 0.00001 < $remaining ) {
				return $this->error( 'asdl_fin_order_settlement_credit_shortfall', 'El saldo a favor documentado disponible ya no alcanza para completar este pedido.' );
			}

			if ( $payable_total > 0 ) {
				$source_id = $this->payments->create(
					array(
						'payment_type' => 'adjustment',
						'account_id'   => $account_id,
						'contact_id'   => $contact_id,
						'status'       => 'posted',
						'payment_date' => $payment_date,
						'currency'     => $currency,
						'total'        => $payable_total,
						'method_key'   => 'internal_compensation',
						'reference'    => $reference_base . '-SRC',
						'notes'        => 'Aplicacion interna del saldo a favor sobre documentos por pagar.',
					)
				);

				if ( is_wp_error( $source_id ) ) {
					return $source_id;
				}

				$source_result = $this->apply_payment_to_documents(
					(int) $source_id,
					$payable_documents,
					$payable_total,
					'Compensacion interna sobre saldo a favor del perfil.'
				);

				if ( is_wp_error( $source_result ) ) {
					return $source_result;
				}

				$target_id = $this->payments->create(
					array(
						'payment_type' => 'adjustment',
						'account_id'   => $account_id,
						'contact_id'   => $contact_id,
						'status'       => 'posted',
						'payment_date' => $payment_date,
						'currency'     => $currency,
						'total'        => $payable_total,
						'method_key'   => 'internal_compensation',
						'reference'    => $reference_base . '-CR',
						'notes'        => 'Saldo a favor aplicado desde el perfil dentro del lote de abonos.',
					)
				);

				if ( is_wp_error( $target_id ) ) {
					return $target_id;
				}

				$target_result = $this->allocations->allocate(
					array(
						'payment_id'         => (int) $target_id,
						'document_id'        => (int) ( $document['id'] ?? 0 ),
						'amount'             => $payable_total,
						'notes'              => 'Compensacion interna aplicada desde saldo a favor del perfil.',
						'manage_transaction' => false,
					)
				);

				if ( is_wp_error( $target_result ) ) {
					return $target_result;
				}

				do_action( 'asdl_fin_payment_allocated', $target_result );
				$remaining         = round( max( 0, $remaining - $payable_total ), 6 );
				$source_payments   = array_merge( $source_payments, (array) ( $source_result['documents'] ?? array() ) );
				$compensation_ids[] = (int) $source_id;
				$compensation_ids[] = (int) $target_id;
			}
		}

		return array(
			'applied_total'            => round( max( 0, (float) $limit_amount - $remaining ), 6 ),
			'remaining'                => $remaining,
			'documents'                => $source_payments,
			'compensation_payment_ids' => array_values( array_unique( array_filter( array_map( 'intval', $compensation_ids ) ) ) ),
		);
	}

	private function get_credit_payments( $contact_id, $currency = '', array $exclude_payment_ids = array() ) {
		$exclude_map = array_fill_keys( array_filter( array_map( 'intval', $exclude_payment_ids ) ), true );
		$payments    = array_filter(
			(array) $this->payments->for_contact( $contact_id, 200 ),
			static function ( array $payment ) use ( $currency, $exclude_map ) {
				$payment_id       = (int) ( $payment['id'] ?? 0 );
				$payment_currency = strtoupper( sanitize_text_field( (string) ( $payment['currency'] ?? 'USD' ) ) );
				if ( isset( $exclude_map[ $payment_id ] ) ) {
					return false;
				}

				if ( '' !== $currency && $payment_currency !== strtoupper( sanitize_text_field( (string) $currency ) ) ) {
					return false;
				}

				return 'posted' === sanitize_key( (string) ( $payment['status'] ?? '' ) )
					&& (float) ( $payment['available_amount'] ?? 0 ) > 0
					&& 'salary_advance' !== sanitize_key( (string) ( $payment['method_key'] ?? '' ) )
					&& in_array( sanitize_key( (string) ( $payment['payment_type'] ?? '' ) ), array( 'collection', 'adjustment' ), true );
			}
		);

		usort(
			$payments,
			static function ( array $left, array $right ) {
				$left_ts  = ! empty( $left['payment_date'] ) ? strtotime( (string) $left['payment_date'] ) : 0;
				$right_ts = ! empty( $right['payment_date'] ) ? strtotime( (string) $right['payment_date'] ) : 0;

				if ( $left_ts === $right_ts ) {
					return (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 );
				}

				return $left_ts <=> $right_ts;
			}
		);

		return array_values( $payments );
	}

	private function get_payable_documents( $contact_id, $currency = '' ) {
		$documents = array_filter(
			(array) $this->documents->for_contact( $contact_id, 200, true ),
			static function ( array $document ) use ( $currency ) {
				$document_currency = strtoupper( sanitize_text_field( (string) ( $document['currency'] ?? 'USD' ) ) );
				if ( '' !== $currency && $document_currency !== strtoupper( sanitize_text_field( (string) $currency ) ) ) {
					return false;
				}

				return 'payable' === sanitize_key( (string) ( $document['balance_nature'] ?? '' ) )
					&& 'void' !== sanitize_key( (string) ( $document['financial_status'] ?? '' ) )
					&& (float) ( $document['balance'] ?? 0 ) > 0;
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

	private function apply_payment_to_documents( $payment_id, array $documents, $limit_amount, $notes ) {
		$remaining         = max( 0, (float) $limit_amount );
		$applied_documents = array();

		foreach ( $documents as $document ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$balance = (float) ( $document['balance'] ?? 0 );
			if ( $balance <= 0 ) {
				continue;
			}

			$apply_amount = min( $remaining, $balance );
			$result       = $this->allocations->allocate(
				array(
					'payment_id'         => $payment_id,
					'document_id'        => (int) ( $document['id'] ?? 0 ),
					'amount'             => $apply_amount,
					'notes'              => $notes,
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			do_action( 'asdl_fin_payment_allocated', $result );
			$remaining           = round( max( 0, $remaining - $apply_amount ), 6 );
			$applied_documents[] = array(
				'document_id'    => (int) ( $document['id'] ?? 0 ),
				'document_title' => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
				'applied_total'  => $apply_amount,
			);
		}

		return array(
			'applied_total' => round( max( 0, (float) $limit_amount - $remaining ), 6 ),
			'remaining'     => $remaining,
			'documents'     => $applied_documents,
		);
	}

	private function format_money( $amount, $currency = '' ) {
		if ( function_exists( 'wc_price' ) ) {
			$args = array();
			if ( '' !== $currency ) {
				$args['currency'] = sanitize_text_field( (string) $currency );
			}

			return wc_price( $amount, $args );
		}

		return '$' . number_format_i18n( (float) $amount, 2 );
	}
}
