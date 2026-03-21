<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\Module as WooModule;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class OrderAssumptionBatchService extends BaseRepository {
	const CONTINUE_TIME_BUDGET_SECONDS = 2.4;
	const CONTINUE_SAFE_BATCH_CURRENT  = 2;
	const CONTINUE_SAFE_BATCH_MIXED    = 1;

	private $planner;
	private $batches;
	private $payments;
	private $allocations;
	private $allocation_service;
	private $documents;
	private $events;
	private $order_service;
	private $historical_index;

	public function __construct() {
		$this->planner            = new OrderAssumptionPlannerService();
		$this->batches            = new OrderAssumptionBatchesRepository();
		$this->payments           = new PaymentsRepository();
		$this->allocations        = new PaymentAllocationsRepository();
		$this->allocation_service = new PaymentAllocationService();
		$this->documents          = new DocumentsRepository();
		$this->events             = new EventsRepository();
		$this->order_service      = new OrderSyncService();
		$this->historical_index   = new CommerceOrderIndexRepository();
	}

	public function preview( array $args, $origin = 'profile_order_assumption' ) {
		return $this->planner->preview( $args, $origin );
	}

	public function start( array $args, $origin = 'profile_order_assumption' ) {
		$preview = $this->planner->preview( $args, $origin );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( ! empty( $preview['requires_profile_confirmation'] ) && empty( $args['confirm_non_internal'] ) ) {
			return $this->error( 'asdl_fin_order_assumption_confirm_non_internal', 'Debes confirmar explicitamente que asumirás estos pedidos sobre un perfil no marcado como interno.' );
		}

		if ( (int) ( $preview['summary']['eligible_count'] ?? 0 ) <= 0 ) {
			return $this->error( 'asdl_fin_order_assumption_empty', 'No hay pedidos elegibles para asumir en este lote.' );
		}

		$signature = sanitize_text_field( (string) ( $args['preview_signature'] ?? ( $preview['preview_signature'] ?? '' ) ) );
		if ( '' === $signature || '' === (string) ( $preview['preview_signature'] ?? '' ) || ! hash_equals( (string) $preview['preview_signature'], $signature ) ) {
			return $this->error( 'asdl_fin_order_assumption_preview_signature', 'La vista previa de asuncion cambio o ya no es valida. Recalcula antes de confirmar.' );
		}

		$selection           = $this->filter_preview_items_by_selection(
			(array) ( $preview['items'] ?? array() ),
			$this->sanitize_selected_item_keys( $args['selected_item_keys'] ?? array() )
		);
		$selected_items      = (array) ( $selection['items'] ?? array() );
		$selected_item_keys  = (array) ( $selection['selected_keys'] ?? array() );
		$selected_summary    = $this->summarize_selected_items( $selected_items );

		if ( empty( $selected_items ) ) {
			return $this->error( 'asdl_fin_order_assumption_selection', 'Selecciona al menos un pedido elegible antes de iniciar la asuncion.' );
		}

		$commit_signature = $this->build_commit_signature( $signature, $selected_item_keys );

		$contact_id = (int) ( $preview['contact_id'] ?? 0 );
		$existing   = $this->batches->find_by_preview_signature( $contact_id, $origin, $commit_signature );
		if ( ! empty( $existing['id'] ) && in_array( (string) ( $existing['status'] ?? '' ), array( 'pending', 'running', 'completed', 'completed_with_errors' ), true ) ) {
			return $this->get_status_snapshot( (int) $existing['id'] );
		}

		$batch_payload = (array) ( $preview['batch_payload'] ?? array() );
		$batch_id      = $this->batches->create_batch(
			array(
				'batch_key'         => wp_generate_uuid4(),
				'contact_id'        => $contact_id,
				'origin'            => sanitize_key( (string) $origin ),
				'mode'              => sanitize_key( (string) ( $preview['mode'] ?? 'expense' ) ),
				'status'            => 'running',
				'preview_signature' => $commit_signature,
				'assumed_total'     => (float) ( $selected_summary['assumed_total'] ?? 0 ),
				'current_total'     => (float) ( $selected_summary['current_total'] ?? 0 ),
				'historical_total'  => (float) ( $selected_summary['historical_total'] ?? 0 ),
				'item_count'        => (int) ( $selected_summary['eligible_count'] ?? 0 ),
				'blocked_count'     => (int) ( $preview['summary']['blocked_count'] ?? 0 ),
				'processed_count'   => 0,
				'meta_json'         => array(
					'context'         => $batch_payload,
					'preview'         => array(
						'summary'       => (array) ( $preview['summary'] ?? array() ),
						'blocked_items' => (array) ( $preview['blocked_items'] ?? array() ),
						'selected_item_keys' => $selected_item_keys,
						'selected_summary'   => $selected_summary,
					),
					'execution_mode'  => 'runner',
					'batch_size'      => max( 1, (int) ( $preview['thresholds']['batch_size'] ?? 2 ) ),
					'last_batch'      => 0,
					'result'          => array(),
					'base_preview_signature' => $signature,
					'commit_signature'       => $commit_signature,
				),
			)
		);

		if ( is_wp_error( $batch_id ) ) {
			return $batch_id;
		}

		$items = array();
		foreach ( $selected_items as $item ) {
			$items[] = array(
				'sort_index'        => (int) ( $item['sequence'] ?? 0 ),
				'source_kind'       => sanitize_key( (string) ( $item['source_kind'] ?? 'current_live' ) ),
				'provider'          => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
				'external_order_id' => (int) ( $item['external_order_id'] ?? 0 ),
				'document_id'       => (int) ( $item['document_id'] ?? 0 ),
				'order_number'      => sanitize_text_field( (string) ( $item['order_number'] ?? '' ) ),
				'issue_date'        => sanitize_text_field( (string) ( $item['issue_date'] ?? '' ) ),
				'balance_before'    => round( (float) ( $item['balance_before'] ?? 0 ), 6 ),
				'status'            => 'pending',
				'meta_json'         => array(
					'item_key'             => sanitize_text_field( (string) ( $item['item_key'] ?? '' ) ),
					'order_label'          => sanitize_text_field( (string) ( $item['order_label'] ?? '' ) ),
					'edit_url'             => esc_url_raw( (string) ( $item['edit_url'] ?? '' ) ),
					'fiscal_year'          => (int) ( $item['fiscal_year'] ?? 0 ),
					'display_name'         => sanitize_text_field( (string) ( $item['display_name'] ?? '' ) ),
					'customer_email'       => sanitize_email( (string) ( $item['customer_email'] ?? '' ) ),
					'currency'             => strtoupper( sanitize_text_field( (string) ( $item['currency'] ?? 'USD' ) ) ),
					'index_id'             => (int) ( $item['meta']['index_id'] ?? 0 ),
					'source_link_id'       => (int) ( $item['meta']['source_link_id'] ?? 0 ),
					'group_key'            => sanitize_text_field( (string) ( $item['meta']['group_key'] ?? '' ) ),
					'status_label'         => sanitize_text_field( (string) ( $item['status_label'] ?? '' ) ),
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
						array( 'last_error' => 'No se pudieron congelar los pedidos del lote.' )
					),
				)
			);
			return $this->error( 'asdl_fin_order_assumption_items', 'No se pudieron congelar los pedidos del lote de asuncion.' );
		}

		return $this->get_status_snapshot( (int) $batch_id );
	}

	public function continue_batch( $batch_id ) {
		$batch = $this->batches->find( $batch_id );
		if ( empty( $batch['id'] ) ) {
			return $this->error( 'asdl_fin_order_assumption_batch', 'No encontramos el lote de asuncion solicitado.' );
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
		$last_batch      = 0;
		$started_at      = microtime( true );

		foreach ( $items as $item ) {
			$last_batch++;
			$result = $this->process_item( $batch, $item );

			$update_data = array(
				'status'        => sanitize_key( (string) ( $result['status'] ?? 'error' ) ),
				'document_id'   => ! empty( $result['document_id'] ) ? (int) $result['document_id'] : (int) ( $item['document_id'] ?? 0 ),
				'balance_before'=> round( (float) ( $result['balance_before'] ?? ( $item['balance_before'] ?? 0 ) ), 6 ),
				'error_message' => ! empty( $result['error_message'] ) ? (string) $result['error_message'] : '',
				'meta_json'     => array_merge(
					(array) ( $item['meta'] ?? array() ),
					array(
						'applied_at'      => current_time( 'mysql' ),
						'payment_id'      => ! empty( $result['payment_id'] ) ? (int) $result['payment_id'] : 0,
						'allocation_id'   => ! empty( $result['allocation_id'] ) ? (int) $result['allocation_id'] : 0,
						'assumed_amount'  => round( (float) ( $result['assumed_amount'] ?? 0 ), 6 ),
						'final_status'    => sanitize_key( (string) ( $result['final_status'] ?? '' ) ),
						'final_status_label' => sanitize_text_field( (string) ( $result['final_status_label'] ?? '' ) ),
						'snapshots'       => (array) ( $result['snapshots'] ?? array() ),
					)
				),
			);

			$this->batches->update_item( (int) $item['id'], $update_data );
			$processed_count++;
			$meta['last_batch'] = $last_batch;

			$this->batches->update_batch(
				(int) $batch['id'],
				array(
					'status'          => 'running',
					'processed_count' => $processed_count,
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
		$origin     = sanitize_key( (string) ( $args['origin'] ?? 'profile_order_assumption' ) );
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
			return $this->error( 'asdl_fin_order_assumption_batch', 'No encontramos el lote de asuncion solicitado.' );
		}

		$batch = $this->batches->find( $batch_id );
		if ( empty( $batch['id'] ) ) {
			return $this->error( 'asdl_fin_order_assumption_batch', 'No encontramos el lote de asuncion solicitado.' );
		}

		$snapshot                 = $this->get_status_snapshot( $batch_id );
		$snapshot['items']        = $this->batches->list_batch_items( $batch_id, 500 );
		$snapshot['blocked_items']= (array) ( $snapshot['batch']['meta']['preview']['blocked_items'] ?? array() );

		return $snapshot;
	}

	public function reverse_item( array $args ) {
		$batch_id = absint( $args['batch_id'] ?? 0 );
		$item_id  = absint( $args['item_id'] ?? 0 );
		$batch    = $this->batches->find( $batch_id );

		if ( empty( $batch['id'] ) || $item_id <= 0 ) {
			return $this->error( 'asdl_fin_order_assumption_reversal', 'No encontramos el item del lote que intentabas revertir.' );
		}

		$item = null;
		foreach ( $this->batches->list_batch_items( $batch_id, 5000 ) as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $item_id ) {
				$item = $row;
				break;
			}
		}

		if ( empty( $item['id'] ) ) {
			return $this->error( 'asdl_fin_order_assumption_reversal', 'No encontramos el item del lote que intentabas revertir.' );
		}

		return $this->reverse_single_item( $batch, $item );
	}

	public function reverse_batch( array $args ) {
		$batch_id = absint( $args['batch_id'] ?? 0 );
		$batch    = $this->batches->find( $batch_id );

		if ( empty( $batch['id'] ) ) {
			return $this->error( 'asdl_fin_order_assumption_reversal', 'No encontramos el lote de asuncion que intentabas revertir.' );
		}

		$items          = $this->batches->list_applied_items( $batch_id, 5000 );
		$reversed_count = 0;
		$error_count    = 0;

		foreach ( $items as $item ) {
			$result = $this->reverse_single_item( $batch, $item );
			if ( is_wp_error( $result ) ) {
				$error_count++;
				continue;
			}
			$reversed_count++;
		}

		$meta                     = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$meta['reversal_result']  = array(
			'reversed_count' => $reversed_count,
			'error_count'    => $error_count,
			'reversed_at'    => current_time( 'mysql' ),
		);

		$this->batches->update_batch(
			$batch_id,
			array(
				'status'    => $error_count > 0 ? 'partially_reversed' : 'reversed',
				'meta_json' => $meta,
			)
		);

		ContactOverviewService::bump_contact_snapshot_cache_version( (int) ( $batch['contact_id'] ?? 0 ) );
		( new HistoricalIndexRebuildService() )->bump_data_version();

		return $this->result( array( 'batch_id' => $batch_id ) );
	}

	private function process_item( array $batch, array $item ) {
		$batch_meta      = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$context         = (array) ( $batch_meta['context'] ?? array() );
		$order_id        = (int) ( $item['external_order_id'] ?? 0 );
		$source_kind     = sanitize_key( (string) ( $item['source_kind'] ?? 'current_live' ) );
		$planned_balance = round( max( 0, (float) ( $item['balance_before'] ?? 0 ) ), 6 );
		$document_id     = (int) ( $item['document_id'] ?? 0 );
		$sync            = null;

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
					'trigger' => 'order_assumption_batch',
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
					'trigger' => 'order_assumption_batch_recovery',
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

		$current_balance   = round( max( 0, (float) ( $document['balance'] ?? 0 ) ), 6 );
		$allocations_count = (int) $this->allocations->count_for_document( (int) $document['id'] );
		$paid_total        = round( (float) ( $document['paid_total'] ?? 0 ), 6 );

		if ( $current_balance <= 0 ) {
			return array(
				'status'        => 'skipped',
				'document_id'   => (int) ( $document['id'] ?? 0 ),
				'balance_before'=> $planned_balance,
				'error_message' => 'El pedido ya no tiene saldo pendiente.',
			);
		}

		if ( abs( $current_balance - $planned_balance ) > 0.01 ) {
			return array(
				'status'        => 'skipped',
				'document_id'   => (int) ( $document['id'] ?? 0 ),
				'balance_before'=> $planned_balance,
				'error_message' => 'El saldo del pedido cambio desde la vista previa. Recalcula antes de asumirlo.',
			);
		}

		if ( $allocations_count > 0 || $paid_total > 0 ) {
			return array(
				'status'        => 'skipped',
				'document_id'   => (int) ( $document['id'] ?? 0 ),
				'balance_before'=> $planned_balance,
				'error_message' => 'El pedido ya tiene pagos o abonos reales y no puede asumirse como gasto.',
			);
		}

		$this->begin_transaction();

		$payment_id = $this->payments->create(
			array(
				'payment_type' => 'adjustment',
				'account_id'   => null,
				'contact_id'   => (int) ( $batch['contact_id'] ?? 0 ),
				'status'       => 'posted',
				'payment_date' => gmdate( 'Y-m-d' ),
				'currency'     => sanitize_text_field( (string) ( $document['currency'] ?? 'USD' ) ),
				'total'        => $current_balance,
				'method_key'   => 'internal_order_assumption',
				'reference'    => sprintf( 'ASSUME-%1$d-%2$d', (int) ( $batch['id'] ?? 0 ), $order_id ),
				'notes'        => sanitize_textarea_field( sprintf( 'Asuncion interna del pedido #%s como %s.', sanitize_text_field( (string) ( $item['order_number'] ?? $order_id ) ), 'gift' === ( $context['mode'] ?? 'expense' ) ? 'regalo' : 'gasto' ) ),
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			$this->rollback_transaction();
			return array(
				'status'        => 'error',
				'document_id'   => (int) ( $document['id'] ?? 0 ),
				'error_message' => $payment_id->get_error_message(),
			);
		}

		$allocation = $this->allocation_service->allocate(
			array(
				'payment_id'         => (int) $payment_id,
				'document_id'        => (int) $document['id'],
				'amount'             => $current_balance,
				'notes'              => sprintf( 'Asuncion interna del pedido #%s.', sanitize_text_field( (string) ( $item['order_number'] ?? $order_id ) ) ),
				'manage_transaction' => false,
			)
		);

		if ( is_wp_error( $allocation ) ) {
			$this->rollback_transaction();
			return array(
				'status'        => 'error',
				'document_id'   => (int) ( $document['id'] ?? 0 ),
				'error_message' => $allocation->get_error_message(),
			);
		}

		$woo_status_snapshot  = $this->capture_order_status_snapshot( $order_id );
		$index_snapshot       = $this->historical_index->find_by_provider_external( sanitize_key( (string) ( $item['provider'] ?? '' ) ), $order_id );
		$document_snapshot    = $this->capture_document_snapshot( $document );
		$trace                = $this->build_assumption_trace( $batch, $item, $context, $document_snapshot, $woo_status_snapshot, $index_snapshot );
		$document_update_ok   = $this->documents->apply_order_assumption_state(
			(int) $document['id'],
			array(
				'financial_intent' => 'internal_consumption',
				'balance_nature'   => 'neutral',
				'financial_status' => 'posted',
				'payment_status'   => 'paid',
				'paid_total'       => round( (float) ( $document['total'] ?? $current_balance ), 6 ),
				'balance'          => 0,
				'manual_override'  => 1,
				'category_key'     => 'internal_consumption',
				'subcategory_key'  => 'gift' === ( $context['mode'] ?? 'expense' ) ? 'internal_gift' : 'internal_expense',
				'notes'            => $this->append_document_note( (string) ( $document['notes'] ?? '' ), $context ),
			),
			$trace
		);

		if ( ! $document_update_ok ) {
			$this->rollback_transaction();
			return array(
				'status'        => 'error',
				'document_id'   => (int) ( $document['id'] ?? 0 ),
				'error_message' => 'No se pudo reclasificar el documento financiero del pedido.',
			);
		}

		$this->commit_transaction();

		$this->append_order_note( $order_id, $context, $current_balance );
		$this->complete_order( $order_id, $context );
		$this->update_historical_index( $item, $document, $context, $batch, $index_snapshot );

		return array(
			'status'            => 'applied',
			'document_id'       => (int) ( $document['id'] ?? 0 ),
			'balance_before'    => $planned_balance,
			'assumed_amount'    => $current_balance,
			'payment_id'        => (int) $payment_id,
			'allocation_id'     => (int) ( $allocation['allocation_id'] ?? 0 ),
			'final_status'      => 'assumed',
			'final_status_label'=> 'Asumido',
			'snapshots'         => array(
				'document' => $document_snapshot,
				'woo'      => $woo_status_snapshot,
				'index'    => $index_snapshot,
				'context'  => array(
					'mode'              => sanitize_key( (string) ( $context['mode'] ?? 'expense' ) ),
					'note'              => sanitize_textarea_field( (string) ( $context['note'] ?? '' ) ),
					'approved_by_label' => sanitize_text_field( (string) ( $context['approved_by_label'] ?? '' ) ),
				),
			),
		);
	}

	private function reverse_single_item( array $batch, array $item ) {
		$item_meta      = is_array( $item['meta'] ?? null ) ? $item['meta'] : array();
		$snapshots      = (array) ( $item_meta['snapshots'] ?? array() );
		$document_id    = (int) ( $item['document_id'] ?? 0 );
		$payment_id     = (int) ( $item_meta['payment_id'] ?? 0 );
		$allocation_id  = (int) ( $item_meta['allocation_id'] ?? 0 );

		if ( 'applied' !== sanitize_key( (string) ( $item['status'] ?? '' ) ) || $document_id <= 0 ) {
			return $this->error( 'asdl_fin_order_assumption_reversal', 'Este item ya no esta disponible para reversa.' );
		}

		$this->begin_transaction();

		if ( $allocation_id > 0 && ! $this->allocations->delete_ids( array( $allocation_id ) ) ) {
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_order_assumption_reversal', 'No se pudo revertir la asignacion interna del pedido.' );
		}

		if ( $payment_id > 0 && ! $this->payments->set_status( $payment_id, 'void', array( 'order_assumption_reversed' => 1 ), 0 ) ) {
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_order_assumption_reversal', 'No se pudo anular el pago interno generado para este pedido.' );
		}

		if ( ! $this->documents->restore_order_assumption_state( $document_id, (array) ( $snapshots['document'] ?? array() ), array( 'reversed_at' => current_time( 'mysql' ), 'reversed_by' => get_current_user_id() ) ) ) {
			$this->rollback_transaction();
			return $this->error( 'asdl_fin_order_assumption_reversal', 'No se pudo restaurar el documento del pedido.' );
		}

		$this->commit_transaction();

		$this->restore_order_status( (int) ( $item['external_order_id'] ?? 0 ), (array) ( $snapshots['woo'] ?? array() ) );
		$this->restore_historical_index( $item, (array) ( $snapshots['index'] ?? array() ) );

		$this->batches->update_item(
			(int) $item['id'],
			array(
				'status'    => 'reversed',
				'meta_json' => array_merge(
					$item_meta,
					array(
						'reversed_at' => current_time( 'mysql' ),
						'reversed_by' => get_current_user_id(),
					)
				),
			)
		);

		$this->events->log(
			'contact',
			(int) ( $batch['contact_id'] ?? 0 ),
			'order_assumption_reversed',
			'Asuncion de pedido revertida desde lote operativo.',
			array(
				'batch_id'   => (int) ( $batch['id'] ?? 0 ),
				'item_id'    => (int) ( $item['id'] ?? 0 ),
				'order_id'   => (int) ( $item['external_order_id'] ?? 0 ),
				'document_id'=> $document_id,
			)
		);

		ContactOverviewService::bump_contact_snapshot_cache_version( (int) ( $batch['contact_id'] ?? 0 ) );
		( new HistoricalIndexRebuildService() )->bump_data_version();

		return $this->result( array( 'batch_id' => (int) ( $batch['id'] ?? 0 ) ) );
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
		$requested = max( 1, (int) ( $meta['batch_size'] ?? 2 ) );
		$has_mixed = (float) ( $batch['historical_total'] ?? 0 ) > 0;

		if ( $has_mixed ) {
			return min( $requested, self::CONTINUE_SAFE_BATCH_MIXED );
		}

		return min( $requested, self::CONTINUE_SAFE_BATCH_CURRENT );
	}

	private function finalize_batch( array $batch ) {
		$batch_id = (int) ( $batch['id'] ?? 0 );
		if ( $batch_id <= 0 ) {
			return;
		}

		$items           = $this->batches->list_batch_items( $batch_id, 5000 );
		$meta            = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$assumed_total   = 0.0;
		$current_total   = 0.0;
		$historical_total= 0.0;
		$applied_count   = 0;
		$error_count     = 0;
		$skipped_count   = 0;
		$current_count   = 0;
		$historical_count= 0;
		$order_ids       = array();

		foreach ( $items as $item ) {
			$status = sanitize_key( (string) ( $item['status'] ?? '' ) );
			if ( 'applied' === $status ) {
				$applied_count++;
				$amount        = round( (float) ( $item['meta']['assumed_amount'] ?? $item['balance_before'] ?? 0 ), 6 );
				$assumed_total = round( $assumed_total + $amount, 6 );
				$order_ids[]   = (int) ( $item['external_order_id'] ?? 0 );
				if ( 'historical_index' === sanitize_key( (string) ( $item['source_kind'] ?? '' ) ) ) {
					$historical_total = round( $historical_total + $amount, 6 );
					$historical_count++;
				} else {
					$current_total = round( $current_total + $amount, 6 );
					$current_count++;
				}
			} elseif ( 'error' === $status ) {
				$error_count++;
			} elseif ( 'skipped' === $status ) {
				$skipped_count++;
			}
		}

		$result = array(
			'batch_id'          => $batch_id,
			'contact_id'        => (int) ( $batch['contact_id'] ?? 0 ),
			'mode'              => sanitize_key( (string) ( $batch['mode'] ?? 'expense' ) ),
			'assumed_total'     => $assumed_total,
			'current_total'     => $current_total,
			'historical_total'  => $historical_total,
			'applied_count'     => $applied_count,
			'current_count'     => $current_count,
			'historical_count'  => $historical_count,
			'error_count'       => $error_count,
			'skipped_count'     => $skipped_count,
			'order_ids'         => array_values( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) ),
		);

		$meta['result']        = $result;
		$meta['error_count']   = $error_count;
		$meta['skipped_count'] = $skipped_count;

		$this->batches->update_batch(
			$batch_id,
			array(
				'status'          => $error_count > 0 ? 'completed_with_errors' : 'completed',
				'processed_count' => (int) ( $batch['item_count'] ?? 0 ),
				'assumed_total'   => $assumed_total,
				'current_total'   => $current_total,
				'historical_total'=> $historical_total,
				'meta_json'       => $meta,
			)
		);

		$this->events->log(
			'contact',
			(int) ( $batch['contact_id'] ?? 0 ),
			'order_assumption_applied',
			'Pedidos asumidos como gasto o regalo desde lote operativo.',
			$result
		);

		ContactOverviewService::bump_contact_snapshot_cache_version( (int) ( $batch['contact_id'] ?? 0 ) );
		( new HistoricalIndexRebuildService() )->bump_data_version();
	}

	private function get_status_snapshot( $batch_id ) {
		$batch = $this->batches->find( $batch_id );
		if ( empty( $batch['id'] ) ) {
			return array( 'job' => array() );
		}

		$meta        = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
		$error_count = $this->batches->count_items_by_status( $batch_id, 'error' ) + $this->batches->count_items_by_status( $batch_id, 'skipped' );
		$job         = array(
			'batch_id'        => (int) $batch['id'],
			'status'          => sanitize_key( (string) ( $batch['status'] ?? '' ) ),
			'processed_count' => (int) ( $batch['processed_count'] ?? 0 ),
			'item_count'      => (int) ( $batch['item_count'] ?? 0 ),
			'assumed_total'   => round( (float) ( $batch['assumed_total'] ?? 0 ), 6 ),
			'current_total'   => round( (float) ( $batch['current_total'] ?? 0 ), 6 ),
			'historical_total'=> round( (float) ( $batch['historical_total'] ?? 0 ), 6 ),
			'blocked_count'   => (int) ( $batch['blocked_count'] ?? 0 ),
			'errors_count'    => $error_count,
			'last_batch'      => (int) ( $meta['last_batch'] ?? 0 ),
			'updated_at'      => sanitize_text_field( (string) ( $batch['updated_at'] ?? '' ) ),
			'execution_mode'  => sanitize_key( (string) ( $meta['execution_mode'] ?? 'runner' ) ),
			'batch_size'      => (int) ( $meta['batch_size'] ?? 0 ),
			'contact_id'      => (int) ( $batch['contact_id'] ?? 0 ),
			'origin'          => sanitize_key( (string) ( $batch['origin'] ?? 'profile_order_assumption' ) ),
			'mode'            => sanitize_key( (string) ( $batch['mode'] ?? 'expense' ) ),
		);

		return array(
			'batch'   => $batch,
			'job'     => $job,
			'result'  => (array) ( $meta['result'] ?? array() ),
			'errors'  => $error_count > 0 ? $this->batches->list_batch_errors( $batch_id, 20 ) : array(),
		);
	}

	private function capture_document_snapshot( array $document ) {
		return array(
			'financial_intent' => sanitize_key( (string) ( $document['financial_intent'] ?? '' ) ),
			'balance_nature'   => sanitize_key( (string) ( $document['balance_nature'] ?? '' ) ),
			'financial_status' => sanitize_key( (string) ( $document['financial_status'] ?? '' ) ),
			'payment_status'   => sanitize_key( (string) ( $document['payment_status'] ?? '' ) ),
			'paid_total'       => round( (float) ( $document['paid_total'] ?? 0 ), 6 ),
			'balance'          => round( (float) ( $document['balance'] ?? 0 ), 6 ),
			'category_key'     => sanitize_key( (string) ( $document['category_key'] ?? '' ) ),
			'subcategory_key'  => sanitize_key( (string) ( $document['subcategory_key'] ?? '' ) ),
			'notes'            => sanitize_textarea_field( (string) ( $document['notes'] ?? '' ) ),
			'manual_override'  => ! empty( $document['manual_override'] ) ? 1 : 0,
			'posted_at'        => sanitize_text_field( (string) ( $document['posted_at'] ?? '' ) ),
		);
	}

	private function build_assumption_trace( array $batch, array $item, array $context, array $document_snapshot, array $woo_snapshot, $index_snapshot ) {
		return array(
			'active'               => 1,
			'batch_id'             => (int) ( $batch['id'] ?? 0 ),
			'mode'                 => sanitize_key( (string) ( $context['mode'] ?? 'expense' ) ),
			'assumed_by_user_id'   => get_current_user_id(),
			'assumed_at'           => current_time( 'mysql' ),
			'assumption_note'      => sanitize_textarea_field( (string) ( $context['note'] ?? '' ) ),
			'approved_by_label'    => sanitize_text_field( (string) ( $context['approved_by_label'] ?? '' ) ),
			'order_id'             => (int) ( $item['external_order_id'] ?? 0 ),
			'provider'             => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
			'original_document'    => $document_snapshot,
			'original_woo_status'  => sanitize_key( (string) ( $woo_snapshot['status'] ?? '' ) ),
			'original_index'       => is_array( $index_snapshot ) ? $index_snapshot : array(),
		);
	}

	private function append_document_note( $existing_notes, array $context ) {
		$line = sprintf(
			'Asuncion interna registrada como %1$s. Nota: %2$s%3$s',
			'gift' === ( $context['mode'] ?? 'expense' ) ? 'regalo' : 'gasto',
			sanitize_textarea_field( (string) ( $context['note'] ?? '' ) ),
			! empty( $context['approved_by_label'] ) ? ' | Aprobado por: ' . sanitize_text_field( (string) $context['approved_by_label'] ) : ''
		);

		$existing_notes = trim( (string) $existing_notes );
		return '' !== $existing_notes ? $existing_notes . "\n\n" . $line : $line;
	}

	private function capture_order_status_snapshot( $order_id ) {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}

		return array(
			'status' => sanitize_key( (string) $order->get_status() ),
		);
	}

	private function append_order_note( $order_id, array $context, $amount ) {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$current_user = wp_get_current_user();
		$user_label   = $current_user && ! empty( $current_user->display_name ) ? $current_user->display_name : 'Finanzas ASD';
		$mode_label   = 'gift' === ( $context['mode'] ?? 'expense' ) ? 'REGALO' : 'GASTO INTERNO';
		$approval     = ! empty( $context['approved_by_label'] ) ? ' | Aprobado por: ' . sanitize_text_field( (string) $context['approved_by_label'] ) : '';

		$order->add_order_note(
			sprintf(
				'Finanzas ASD asumio este pedido como %1$s. Monto asumido: %2$s | Nota: %3$s%4$s | Operado por: %5$s.',
				$mode_label,
				wp_strip_all_tags( $this->format_amount( $amount, $order->get_currency() ) ),
				sanitize_textarea_field( (string) ( $context['note'] ?? '' ) ),
				$approval,
				$user_label
			),
			false,
			true
		);
	}

	private function complete_order( $order_id, array $context ) {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$current_status = sanitize_key( (string) $order->get_status() );
		if ( 'completed' === $current_status ) {
			return;
		}

		WooModule::run_without_status_guard(
			static function () use ( $order, $context ) {
				$order->update_status(
					'completed',
					sprintf(
						'Pedido asumido como %1$s desde Finanzas ASD.',
						'gift' === ( $context['mode'] ?? 'expense' ) ? 'regalo' : 'gasto interno'
					),
					true
				);
			}
		);
	}

	private function update_historical_index( array $item, array $document, array $context, array $batch, $index_snapshot ) {
		$provider = sanitize_key( (string) ( $item['provider'] ?? '' ) );
		$order_id = (int) ( $item['external_order_id'] ?? 0 );
		if ( '' === $provider || $order_id <= 0 ) {
			return;
		}

		$row = is_array( $index_snapshot ) && ! empty( $index_snapshot['id'] )
			? $index_snapshot
			: $this->historical_index->find_by_provider_external( $provider, $order_id );

		if ( empty( $row['id'] ) ) {
			return;
		}

		$meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();
		$meta['order_assumption'] = array(
			'active'            => 1,
			'batch_id'          => (int) ( $batch['id'] ?? 0 ),
			'mode'              => sanitize_key( (string) ( $context['mode'] ?? 'expense' ) ),
			'assumed_at'        => current_time( 'mysql' ),
			'assumption_note'   => sanitize_textarea_field( (string) ( $context['note'] ?? '' ) ),
			'approved_by_label' => sanitize_text_field( (string) ( $context['approved_by_label'] ?? '' ) ),
		);

		$this->historical_index->upsert(
			array(
				'provider'                     => $provider,
				'external_order_id'            => $order_id,
				'order_number'                 => $row['order_number'] ?? ( $item['order_number'] ?? '' ),
				'group_key'                    => $row['group_key'] ?? ( $provider . ':' . $order_id ),
				'contact_id'                   => ! empty( $row['contact_id'] ) ? (int) $row['contact_id'] : (int) ( $document['contact_id'] ?? 0 ),
				'wp_user_id'                   => ! empty( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : (int) ( $document['wp_user_id'] ?? 0 ),
				'customer_email'               => $row['customer_email'] ?? '',
				'display_name'                 => $row['display_name'] ?? '',
				'issue_date'                   => $row['issue_date'] ?? ( $item['issue_date'] ?? '' ),
				'fiscal_year'                  => (int) ( $row['fiscal_year'] ?? ( $item['meta']['fiscal_year'] ?? 0 ) ),
				'status'                       => 'completed',
				'currency'                     => $row['currency'] ?? ( $document['currency'] ?? 'USD' ),
				'gross_total'                  => round( (float) ( $row['gross_total'] ?? ( $document['total'] ?? 0 ) ), 6 ),
				'paid_total'                   => round( (float) ( $document['total'] ?? $row['gross_total'] ?? 0 ), 6 ),
				'balance'                      => 0,
				'item_count'                   => (int) ( $row['item_count'] ?? 0 ),
				'is_open'                      => 0,
				'operationally_collectible'    => 0,
				'document_id'                  => (int) ( $document['id'] ?? 0 ),
				'source_link_id'               => ! empty( $row['source_link_id'] ) ? (int) $row['source_link_id'] : 0,
				'meta_json'                    => $meta,
			)
		);
	}

	private function restore_order_status( $order_id, array $snapshot ) {
		$previous_status = sanitize_key( (string) ( $snapshot['status'] ?? '' ) );
		if ( $order_id <= 0 || '' === $previous_status || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $previous_status === sanitize_key( (string) $order->get_status() ) ) {
			return;
		}

		WooModule::run_without_status_guard(
			static function () use ( $order, $previous_status ) {
				$order->update_status( $previous_status, 'Estado restaurado tras revertir asuncion interna del pedido.', true );
			}
		);
	}

	private function restore_historical_index( array $item, array $snapshot ) {
		$provider = sanitize_key( (string) ( $item['provider'] ?? '' ) );
		$order_id = (int) ( $item['external_order_id'] ?? 0 );
		if ( '' === $provider || $order_id <= 0 ) {
			return;
		}

		if ( empty( $snapshot['id'] ) ) {
			$existing = $this->historical_index->find_by_provider_external( $provider, $order_id );
			if ( ! empty( $existing['id'] ) ) {
				$meta = json_decode( (string) ( $existing['meta_json'] ?? '' ), true );
				$meta = is_array( $meta ) ? $meta : array();
				unset( $meta['order_assumption'] );
				$this->historical_index->upsert(
					array(
						'provider'                  => $provider,
						'external_order_id'         => $order_id,
						'order_number'              => $existing['order_number'] ?? '',
						'group_key'                 => $existing['group_key'] ?? ( $provider . ':' . $order_id ),
						'contact_id'                => ! empty( $existing['contact_id'] ) ? (int) $existing['contact_id'] : 0,
						'wp_user_id'                => ! empty( $existing['wp_user_id'] ) ? (int) $existing['wp_user_id'] : 0,
						'customer_email'            => $existing['customer_email'] ?? '',
						'display_name'              => $existing['display_name'] ?? '',
						'issue_date'                => $existing['issue_date'] ?? '',
						'fiscal_year'               => (int) ( $existing['fiscal_year'] ?? 0 ),
						'status'                    => $existing['status'] ?? '',
						'currency'                  => $existing['currency'] ?? 'USD',
						'gross_total'               => round( (float) ( $existing['gross_total'] ?? 0 ), 6 ),
						'paid_total'                => round( (float) ( $existing['paid_total'] ?? 0 ), 6 ),
						'balance'                   => round( (float) ( $existing['balance'] ?? 0 ), 6 ),
						'item_count'                => (int) ( $existing['item_count'] ?? 0 ),
						'is_open'                   => ! empty( $existing['is_open'] ),
						'operationally_collectible' => ! empty( $existing['balance'] ),
						'document_id'               => ! empty( $existing['document_id'] ) ? (int) $existing['document_id'] : 0,
						'source_link_id'            => ! empty( $existing['source_link_id'] ) ? (int) $existing['source_link_id'] : 0,
						'meta_json'                 => $meta,
					)
				);
			}

			return;
		}

		$meta = json_decode( (string) ( $snapshot['meta_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();
		if ( isset( $meta['order_assumption'] ) ) {
			unset( $meta['order_assumption'] );
		}

		$this->historical_index->upsert(
			array(
				'provider'                     => $snapshot['provider'] ?? $provider,
				'external_order_id'            => (int) ( $snapshot['external_order_id'] ?? $order_id ),
				'order_number'                 => $snapshot['order_number'] ?? '',
				'group_key'                    => $snapshot['group_key'] ?? ( $provider . ':' . $order_id ),
				'contact_id'                   => ! empty( $snapshot['contact_id'] ) ? (int) $snapshot['contact_id'] : 0,
				'wp_user_id'                   => ! empty( $snapshot['wp_user_id'] ) ? (int) $snapshot['wp_user_id'] : 0,
				'customer_email'               => $snapshot['customer_email'] ?? '',
				'display_name'                 => $snapshot['display_name'] ?? '',
				'issue_date'                   => $snapshot['issue_date'] ?? '',
				'fiscal_year'                  => (int) ( $snapshot['fiscal_year'] ?? 0 ),
				'status'                       => $snapshot['status'] ?? '',
				'currency'                     => $snapshot['currency'] ?? 'USD',
				'gross_total'                  => round( (float) ( $snapshot['gross_total'] ?? 0 ), 6 ),
				'paid_total'                   => round( (float) ( $snapshot['paid_total'] ?? 0 ), 6 ),
				'balance'                      => round( (float) ( $snapshot['balance'] ?? 0 ), 6 ),
				'item_count'                   => (int) ( $snapshot['item_count'] ?? 0 ),
				'is_open'                      => ! empty( $snapshot['is_open'] ),
				'operationally_collectible'    => ! empty( $snapshot['operationally_collectible'] ),
				'historical_resolution_status' => $snapshot['historical_resolution_status'] ?? '',
				'historical_resolution_note'   => $snapshot['historical_resolution_note'] ?? '',
				'historical_resolution_batch_id'=> ! empty( $snapshot['historical_resolution_batch_id'] ) ? (int) $snapshot['historical_resolution_batch_id'] : 0,
				'document_id'                  => ! empty( $snapshot['document_id'] ) ? (int) $snapshot['document_id'] : 0,
				'source_link_id'               => ! empty( $snapshot['source_link_id'] ) ? (int) $snapshot['source_link_id'] : 0,
				'meta_json'                    => $meta,
			)
		);
	}

	private function format_amount( $amount, $currency = '' ) {
		$amount = round( (float) $amount, 2 );

		if ( function_exists( 'wc_price' ) ) {
			$args = array();
			if ( '' !== $currency ) {
				$args['currency'] = sanitize_text_field( (string) $currency );
			}

			return wc_price( $amount, $args );
		}

		$prefix = '' !== $currency ? strtoupper( sanitize_text_field( (string) $currency ) ) . ' ' : '$';

		return $prefix . number_format_i18n( $amount, 2 );
	}

	private function sanitize_selected_item_keys( $raw ) {
		if ( is_array( $raw ) ) {
			return array_values(
				array_filter(
					array_map(
						static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
						$raw
					)
				)
			);
		}

		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $value ) {
						return sanitize_text_field( (string) $value );
					},
					preg_split( '/\s*,\s*/', $raw )
				)
			)
		);
	}

	private function filter_preview_items_by_selection( array $items, array $selected_item_keys ) {
		$items_by_key = array();

		foreach ( $items as $item ) {
			$key = $this->preview_item_key( $item );
			if ( '' === $key ) {
				continue;
			}
			$item['item_key']   = $key;
			$items_by_key[ $key ] = $item;
		}

		if ( empty( $selected_item_keys ) ) {
			return array(
				'items'         => array_values( $items_by_key ),
				'selected_keys' => array_keys( $items_by_key ),
			);
		}

		$filtered      = array();
		$filtered_keys = array();
		foreach ( $selected_item_keys as $key ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' === $key || empty( $items_by_key[ $key ] ) ) {
				continue;
			}

			$filtered[]      = $items_by_key[ $key ];
			$filtered_keys[] = $key;
		}

		return array(
			'items'         => $filtered,
			'selected_keys' => array_values( array_unique( $filtered_keys ) ),
		);
	}

	private function summarize_selected_items( array $items ) {
		$summary = array(
			'eligible_count'   => 0,
			'assumed_total'    => 0.0,
			'current_total'    => 0.0,
			'historical_total' => 0.0,
			'current_count'    => 0,
			'historical_count' => 0,
		);

		foreach ( $items as $item ) {
			$amount = round( (float) ( $item['balance_before'] ?? 0 ), 6 );
			if ( $amount <= 0 ) {
				continue;
			}

			$summary['eligible_count']++;
			$summary['assumed_total'] = round( $summary['assumed_total'] + $amount, 6 );

			if ( 'historical_index' === sanitize_key( (string) ( $item['source_kind'] ?? 'current_live' ) ) ) {
				$summary['historical_total'] = round( $summary['historical_total'] + $amount, 6 );
				$summary['historical_count']++;
			} else {
				$summary['current_total'] = round( $summary['current_total'] + $amount, 6 );
				$summary['current_count']++;
			}
		}

		return $summary;
	}

	private function build_commit_signature( $preview_signature, array $selected_item_keys ) {
		sort( $selected_item_keys );

		return hash_hmac(
			'sha256',
			wp_json_encode(
				array(
					'preview_signature'  => sanitize_text_field( (string) $preview_signature ),
					'selected_item_keys' => array_values( $selected_item_keys ),
				)
			),
			wp_salt( 'auth' )
		);
	}

	private function preview_item_key( array $item ) {
		$explicit = sanitize_text_field( (string) ( $item['item_key'] ?? '' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		return implode(
			':',
			array(
				sanitize_key( (string) ( $item['source_kind'] ?? 'current_live' ) ),
				sanitize_key( (string) ( $item['provider'] ?? '' ) ),
				(int) ( $item['external_order_id'] ?? 0 ),
				(int) ( $item['document_id'] ?? 0 ),
			)
		);
	}
}
