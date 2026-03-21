<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;
use ASDLabs\Finance\Integrations\Woo\ProfileOrderSettlementService;

final class PayrollManualSettlementService extends BaseRepository {
	const CACHE_TTL = 60;

	public function get_open_debts_for_contact( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_payroll_manual_contact', 'Debes indicar un empleado valido para revisar deudas abiertas.' );
		}

		$cache_key = 'asdl_fin_payroll_manual_debts_' . $contact_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$contacts = new ContactsRepository();
		$contact  = $contacts->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_payroll_manual_contact', 'No se encontro el empleado seleccionado.' );
		}

		$store_items      = $this->build_store_order_items( $contact );
		$document_items   = $this->query_open_receivable_documents_for_contact( $contact_id );
		$commitment_items = $this->query_open_receivable_commitments_for_contact( $contact_id );
		$targets          = array();
		$store_total      = array_sum( array_map( static function ( array $item ) { return (float) ( $item['amount'] ?? 0 ); }, $store_items ) );
		$document_total   = array_sum( array_map( static function ( array $item ) { return (float) ( $item['amount'] ?? 0 ); }, $document_items ) );
		$commitment_total = array_sum( array_map( static function ( array $item ) { return (float) ( $item['amount'] ?? 0 ); }, $commitment_items ) );

		if ( $store_total > 0 ) {
			$oldest_date = '';
			foreach ( $store_items as $store_item ) {
				$date = sanitize_text_field( (string) ( $store_item['date'] ?? '' ) );
				if ( '' !== $date && ( '' === $oldest_date || $date < $oldest_date ) ) {
					$oldest_date = $date;
				}
			}

			$targets[] = array(
				'target_type' => 'store_orders',
				'target_id'   => 0,
				'kind'        => 'store',
				'kind_label'  => 'Tienda',
				'label'       => 'Deuda de tienda',
				'description' => 'Abono por antiguedad sobre pedidos Woo/OpenPOS abiertos del empleado.',
				'amount'      => round( $store_total, 6 ),
				'currency'    => sanitize_text_field( (string) ( $store_items[0]['currency'] ?? 'USD' ) ),
				'count'       => count( $store_items ),
				'oldest_date' => $oldest_date,
				'items'       => $store_items,
			);
		}

		foreach ( $document_items as $document_item ) {
			$targets[] = $document_item;
		}

		foreach ( $commitment_items as $commitment_item ) {
			$targets[] = $commitment_item;
		}

		usort(
			$targets,
			static function ( array $left, array $right ) {
				$left_date  = sanitize_text_field( (string) ( $left['oldest_date'] ?? '' ) );
				$right_date = sanitize_text_field( (string) ( $right['oldest_date'] ?? '' ) );

				if ( $left_date === $right_date ) {
					return (float) ( $right['amount'] ?? 0 ) <=> (float) ( $left['amount'] ?? 0 );
				}

				if ( '' === $left_date ) {
					return 1;
				}

				if ( '' === $right_date ) {
					return -1;
				}

				return strcmp( $left_date, $right_date );
			}
		);

		$result = array(
			'contact_id' => $contact_id,
			'summary'    => array(
				'has_open_debts'   => ! empty( $targets ),
				'total_amount'     => round( $store_total + $document_total + $commitment_total, 6 ),
				'store_total'      => round( $store_total, 6 ),
				'document_total'   => round( $document_total, 6 ),
				'commitment_total' => round( $commitment_total, 6 ),
				'store_count'      => count( $store_items ),
				'document_count'   => count( $document_items ),
				'commitment_count' => count( $commitment_items ),
				'target_count'     => count( $targets ),
			),
			'targets'    => array_values( $targets ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	public function normalize_manual_settlement( $contact_id, array $data ) {
		$target_type = sanitize_key( (string) ( $data['payroll_manual_settlement_target_type'] ?? '' ) );
		$target_id   = absint( $data['payroll_manual_settlement_target_id'] ?? 0 );
		$amount      = max( 0, (float) ( $data['payroll_manual_settlement_amount'] ?? 0 ) );

		if ( '' === $target_type || $amount <= 0 ) {
			return array(
				'enabled' => false,
				'amount'  => 0.0,
			);
		}

		$snapshot = $this->get_open_debts_for_contact( $contact_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$selected = null;
		foreach ( (array) ( $snapshot['targets'] ?? array() ) as $target ) {
			$current_type = sanitize_key( (string) ( $target['target_type'] ?? '' ) );
			$current_id   = absint( $target['target_id'] ?? 0 );

			if ( $current_type !== $target_type ) {
				continue;
			}

			if ( 'store_orders' === $target_type ) {
				$selected = $target;
				break;
			}

			if ( $current_id === $target_id ) {
				$selected = $target;
				break;
			}
		}

		if ( empty( $selected['target_type'] ) ) {
			return $this->error( 'asdl_fin_payroll_manual_target', 'El destino seleccionado ya no esta disponible entre las deudas abiertas del empleado.' );
		}

		$selected_amount = max( 0, (float) ( $selected['amount'] ?? 0 ) );
		if ( $selected_amount <= 0 ) {
			return $this->error( 'asdl_fin_payroll_manual_amount', 'El destino seleccionado ya no tiene saldo pendiente.' );
		}

		if ( $amount > $selected_amount + 0.00001 ) {
			return $this->error( 'asdl_fin_payroll_manual_amount', 'El monto indicado supera el saldo pendiente del destino seleccionado.' );
		}

		return array(
			'enabled'          => true,
			'target_type'      => $target_type,
			'target_id'        => $target_id,
			'amount'           => round( $amount, 6 ),
			'label'            => sanitize_text_field( (string) ( $selected['label'] ?? '' ) ),
			'kind_label'       => sanitize_text_field( (string) ( $selected['kind_label'] ?? '' ) ),
			'destination_kind' => sanitize_key( (string) ( $selected['kind'] ?? '' ) ),
			'target_balance'   => round( $selected_amount, 6 ),
			'currency'         => sanitize_text_field( (string) ( $selected['currency'] ?? 'USD' ) ),
			'force_dual'       => 'store_orders' === $target_type && ! empty( $data['payroll_manual_settlement_force_dual'] ),
			'preview_signature'=> sanitize_text_field( (string) ( $data['payroll_manual_settlement_preview_signature'] ?? '' ) ),
		);
	}

	public function apply_manual_settlement( $contact_id, array $manual, array $context ) {
		if ( empty( $manual['enabled'] ) || empty( $manual['target_type'] ) ) {
			return array(
				'enabled' => false,
				'amount'  => 0.0,
			);
		}

		$contact_id   = absint( $contact_id );
		$target_type  = sanitize_key( (string) ( $manual['target_type'] ?? '' ) );
		$target_id    = absint( $manual['target_id'] ?? 0 );
		$amount       = max( 0, (float) ( $manual['amount'] ?? 0 ) );
		$payment_date = $this->sanitize_date( $context['payment_date'] ?? '' ) ?: gmdate( 'Y-m-d' );
		$account_id   = ! empty( $context['account_id'] ) ? absint( $context['account_id'] ) : null;
		$currency     = sanitize_text_field( (string) ( $context['currency'] ?? 'USD' ) );
		$payroll_id   = absint( $context['payroll_id'] ?? 0 );
		$payroll_title = sanitize_text_field( (string) ( $context['payroll_title'] ?? '' ) );

		if ( $amount <= 0 ) {
			return $this->error( 'asdl_fin_payroll_manual_amount', 'Debes indicar un monto valido para el abono manual desde nomina.' );
		}

		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_payroll_manual_contact', 'No se encontro el empleado para aplicar el abono manual.' );
		}

		switch ( $target_type ) {
			case 'store_orders':
				return $this->apply_store_settlement(
					$contact_id,
					$manual,
					array(
						'account_id'    => $account_id,
						'currency'      => $currency,
						'payment_date'  => $payment_date,
						'payroll_id'    => $payroll_id,
						'payroll_title' => $payroll_title,
					)
				);

			case 'document':
				return $this->apply_document_settlement(
					$contact_id,
					$target_id,
					$amount,
					array(
						'account_id'    => $account_id,
						'currency'      => $currency,
						'payment_date'  => $payment_date,
						'payroll_id'    => $payroll_id,
						'payroll_title' => $payroll_title,
					)
				);

			case 'installment_plan':
				return $this->apply_commitment_settlement(
					$contact_id,
					$target_id,
					$amount,
					array(
						'account_id'    => $account_id,
						'currency'      => $currency,
						'payment_date'  => $payment_date,
						'payroll_id'    => $payroll_id,
						'payroll_title' => $payroll_title,
					)
				);
		}

		return $this->error( 'asdl_fin_payroll_manual_target', 'El tipo de deuda seleccionado no puede gestionarse desde esta nomina.' );
	}

	private function apply_store_settlement( $contact_id, array $manual, array $context ) {
		$service     = new OrderSettlementBatchService();
		$force_dual  = ! empty( $manual['force_dual'] );
		$reference   = $this->build_reference( (int) ( $context['payroll_id'] ?? 0 ), 'store_orders' );
		$notes       = $this->build_notes( (string) ( $context['payroll_title'] ?? '' ), 'store_orders' );
		$payload     = array(
			'contact_id'           => $contact_id,
			'account_id'           => $context['account_id'] ?? null,
			'payment_date'         => $context['payment_date'] ?? gmdate( 'Y-m-d' ),
			'total'                => (float) ( $manual['amount'] ?? 0 ),
			'currency'             => $context['currency'] ?? 'USD',
			'method_key'           => 'payroll_deduction',
			'payment_type'         => 'adjustment',
			'reference'            => $reference,
			'notes'                => $notes,
			'manage_transaction'   => false,
			'force_dual_discount'  => $force_dual,
		);

		$preview = $service->preview( $payload, 'payroll_manual_store_settlement' );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( $force_dual ) {
			$current_signature = sanitize_text_field( (string) ( $preview['preview_signature'] ?? '' ) );
			$sent_signature    = sanitize_text_field( (string) ( $manual['preview_signature'] ?? '' ) );

			if ( '' === $current_signature || '' === $sent_signature || ! hash_equals( $current_signature, $sent_signature ) ) {
				return $this->error( 'asdl_fin_payroll_manual_preview', 'La simulacion de deuda de tienda cambio o ya no es valida. Abre otra vez los detalles de nomina y confirma de nuevo el abono.' );
			}
		}

		if ( 'fast_path' !== sanitize_key( (string) ( $preview['execution_mode'] ?? 'runner' ) ) ) {
			return $this->error( 'asdl_fin_payroll_manual_runner_required', 'Este abono a deuda de tienda requiere ejecucion por lotes. Procesalo desde el perfil del empleado para usar el runner con progreso y evitar bloqueos.' );
		}

		$snapshot = $service->start(
			array_merge(
				$payload,
				array(
					'origin'            => 'payroll_manual_store_settlement',
					'preview_signature' => sanitize_text_field( (string) ( $preview['preview_signature'] ?? '' ) ),
				)
			),
			'payroll_manual_store_settlement'
		);

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$result = (array) ( $snapshot['result'] ?? array() );
		if ( empty( $result ) ) {
			return $this->error( 'asdl_fin_payroll_manual_store_result', 'No se pudo completar el abono de tienda desde nomina.' );
		}

		$result['target_type'] = 'store_orders';
		$result['amount']      = (float) ( $manual['amount'] ?? 0 );
		$result['label']       = sanitize_text_field( (string) ( $manual['label'] ?? 'Deuda de tienda' ) );

		return $result;
	}

	private function apply_document_settlement( $contact_id, $document_id, $amount, array $context ) {
		$documents   = new DocumentsRepository();
		$payments    = new PaymentsRepository();
		$allocations = new PaymentAllocationService();
		$document    = $documents->find( $document_id );

		if ( empty( $document['id'] ) ) {
			return $this->error( 'asdl_fin_payroll_manual_document', 'No se encontro el movimiento por cobrar seleccionado.' );
		}

		if ( 'receivable' !== (string) ( $document['balance_nature'] ?? '' ) || (float) ( $document['balance'] ?? 0 ) <= 0 ) {
			return $this->error( 'asdl_fin_payroll_manual_document', 'El movimiento seleccionado ya no tiene saldo pendiente por cobrar.' );
		}

		if ( ! empty( $document['contact_id'] ) && (int) $document['contact_id'] !== $contact_id ) {
			return $this->error( 'asdl_fin_payroll_manual_document', 'El movimiento seleccionado pertenece a otro perfil.' );
		}

		$payment_id = $payments->create(
			array(
				'payment_type' => 'adjustment',
				'account_id'   => $context['account_id'] ?? null,
				'contact_id'   => $contact_id,
				'status'       => 'posted',
				'payment_date' => $context['payment_date'] ?? gmdate( 'Y-m-d' ),
				'currency'     => $context['currency'] ?? ( $document['currency'] ?? 'USD' ),
				'total'        => $amount,
				'method_key'   => 'payroll_deduction',
				'reference'    => $this->build_reference( (int) ( $context['payroll_id'] ?? 0 ), 'document' ),
				'notes'        => $this->build_notes( (string) ( $context['payroll_title'] ?? '' ), 'document' ),
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		$allocation = $allocations->allocate(
			array(
				'payment_id'         => (int) $payment_id,
				'document_id'        => (int) $document_id,
				'amount'             => $amount,
				'notes'              => 'Abono manual aplicado desde nomina.',
				'manage_transaction' => false,
			)
		);

		if ( is_wp_error( $allocation ) ) {
			return $allocation;
		}

		return array(
			'target_type'    => 'document',
			'target_id'      => (int) $document_id,
			'payment_id'     => (int) $payment_id,
			'applied_total'  => (float) $amount,
			'unapplied_total'=> 0.0,
			'label'          => sanitize_text_field( (string) ( $document['title'] ?? $document['document_number'] ?? 'Movimiento por cobrar' ) ),
			'allocation'     => $allocation,
		);
	}

	private function apply_commitment_settlement( $contact_id, $plan_id, $amount, array $context ) {
		$result = ( new CommitmentSettlementService() )->apply(
			array(
				'plan_id'            => (int) $plan_id,
				'amount'             => $amount,
				'create_payment'     => true,
				'payment_type'       => 'adjustment',
				'account_id'         => $context['account_id'] ?? null,
				'payment_date'       => $context['payment_date'] ?? gmdate( 'Y-m-d' ),
				'currency'           => $context['currency'] ?? 'USD',
				'method_key'         => 'payroll_deduction',
				'reference'          => $this->build_reference( (int) ( $context['payroll_id'] ?? 0 ), 'installment_plan' ),
				'notes'              => $this->build_notes( (string) ( $context['payroll_title'] ?? '' ), 'installment_plan' ),
				'origin'             => 'payroll_manual_deduction',
				'manage_transaction' => false,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['target_type'] = 'installment_plan';

		return $result;
	}

	private function build_store_order_items( array $contact ) {
		$order_service = new OrderSyncService();
		$open_orders   = $order_service->list_orders(
			array(
				'limit'       => 0,
				'customer_id' => ! empty( $contact['wp_user_id'] ) ? absint( $contact['wp_user_id'] ) : 0,
				'email'       => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
				'statuses'    => $order_service->collectible_statuses(),
			)
		);

		$open_orders = array_values(
			array_filter(
				$open_orders,
				static function ( array $order ) {
					return ! empty( $order['is_effectively_open'] );
				}
			)
		);

		usort(
			$open_orders,
			static function ( array $left, array $right ) {
				$left_ts  = ! empty( $left['date_created'] ) ? strtotime( (string) $left['date_created'] ) : 0;
				$right_ts = ! empty( $right['date_created'] ) ? strtotime( (string) $right['date_created'] ) : 0;
				if ( $left_ts === $right_ts ) {
					return (int) ( $left['order_id'] ?? 0 ) <=> (int) ( $right['order_id'] ?? 0 );
				}

				return $left_ts <=> $right_ts;
			}
		);

		$items = array();
		foreach ( $open_orders as $order_item ) {
			$sync_result = $order_service->sync_order( (int) $order_item['order_id'], array( 'trigger' => 'payroll_manual_debt' ) );
			if ( is_wp_error( $sync_result ) ) {
				continue;
			}

			$context = $order_service->get_linked_document_context( (int) $order_item['order_id'] );
			if ( empty( $context['document']['id'] ) ) {
				continue;
			}

			$document = $context['document'];
			$balance  = max( 0, (float) ( $document['balance'] ?? 0 ) );
			if ( $balance <= 0 ) {
				continue;
			}

			$items[] = array(
				'entity_type' => 'order',
				'entity_id'   => (int) ( $order_item['order_id'] ?? 0 ),
				'label'       => 'Pedido #' . sanitize_text_field( (string) ( $order_item['order_number'] ?? $order_item['order_id'] ?? '' ) ),
				'description' => 'Pedido Woo/OpenPOS pendiente por cobrar.',
				'amount'      => $balance,
				'currency'    => sanitize_text_field( (string) ( $document['currency'] ?? ( $order_item['currency'] ?? 'USD' ) ) ),
				'date'        => $this->normalize_item_date( $order_item['date_created'] ?? '' ),
			);
		}

		return $items;
	}

	private function query_open_receivable_documents_for_contact( $contact_id ) {
		global $wpdb;

		$documents_table = Tables::name( 'documents' );
		if ( ! $this->table_exists( $documents_table ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, contact_id, document_number, title, document_type, source_type, payment_status, financial_status, issue_date, created_at, currency, balance
				FROM {$documents_table}
				WHERE contact_id = %d
				AND balance_nature = 'receivable'
				AND balance > 0
				AND COALESCE(financial_status, '') <> 'void'
				AND COALESCE(document_type, '') <> 'woo_sale'
				ORDER BY COALESCE(issue_date, DATE(created_at)) ASC, id ASC",
				$contact_id
			),
			ARRAY_A
		);

		return array_map(
			function ( array $document ) {
				$document_type = sanitize_key( (string) ( $document['document_type'] ?? '' ) );
				$is_loan       = 'loan_receivable' === $document_type;
				$date          = $this->normalize_item_date( $document['issue_date'] ?? $document['created_at'] ?? '' );

				return array(
					'target_type' => 'document',
					'target_id'   => (int) ( $document['id'] ?? 0 ),
					'kind'        => $is_loan ? 'loan' : 'document',
					'kind_label'  => $is_loan ? 'Prestamo' : 'Documento',
					'label'       => sanitize_text_field( (string) ( $document['title'] ?: $document['document_number'] ?: 'Movimiento #' . (int) ( $document['id'] ?? 0 ) ) ),
					'description' => $is_loan ? 'Prestamo pendiente por cobrar.' : 'Movimiento manual pendiente por cobrar.',
					'amount'      => round( max( 0, (float) ( $document['balance'] ?? 0 ) ), 6 ),
					'currency'    => sanitize_text_field( (string) ( $document['currency'] ?? 'USD' ) ),
					'count'       => 1,
					'oldest_date' => $date,
					'items'       => array(),
				);
			},
			(array) $rows
		);
	}

	private function query_open_receivable_commitments_for_contact( $contact_id ) {
		global $wpdb;

		$plans_table = Tables::name( 'installment_plans' );
		if ( ! $this->table_exists( $plans_table ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, contact_id, document_id, title, currency, balance, status, start_date, created_at, meta_json
				FROM {$plans_table}
				WHERE contact_id = %d
				AND balance > 0
				AND ( document_id IS NULL OR document_id = 0 )
				AND status IN ('active', 'partial', 'paused')
				ORDER BY COALESCE(NULLIF(start_date, ''), DATE(created_at)) ASC, id ASC",
				$contact_id
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $plan ) {
			$meta      = json_decode( (string) ( $plan['meta_json'] ?? '' ), true );
			$meta      = is_array( $meta ) ? $meta : array();
			$direction = sanitize_key(
				(string) (
					$meta['settlement_direction']
					?? ( 'company_debt' === sanitize_key( (string) ( $meta['commitment_origin'] ?? '' ) ) ? 'payable' : 'receivable' )
				)
			);

			if ( 'receivable' !== $direction ) {
				continue;
			}

			$origin = sanitize_key( (string) ( $meta['commitment_origin'] ?? 'manual_charge' ) );
			$date   = $this->normalize_item_date( $plan['start_date'] ?? $plan['created_at'] ?? '' );
			$items[] = array(
				'target_type' => 'installment_plan',
				'target_id'   => (int) ( $plan['id'] ?? 0 ),
				'kind'        => 'commitment',
				'kind_label'  => 'Compromiso',
				'label'       => sanitize_text_field( (string) ( $plan['title'] ?: 'Compromiso #' . (int) ( $plan['id'] ?? 0 ) ) ),
				'description' => 'loan' === $origin ? 'Prestamo acordado por cobrar.' : 'Compromiso pendiente por cobrar.',
				'amount'      => round( max( 0, (float) ( $plan['balance'] ?? 0 ) ), 6 ),
				'currency'    => sanitize_text_field( (string) ( $plan['currency'] ?? 'USD' ) ),
				'count'       => 1,
				'oldest_date' => $date,
				'items'       => array(),
			);
		}

		return $items;
	}

	private function table_exists( $table_name ) {
		global $wpdb;

		$sql       = $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name );
		$table_row = $wpdb->get_var( $sql );

		return $table_name === $table_row;
	}

	private function normalize_item_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $value, $matches ) ) {
			return $matches[0];
		}

		return '';
	}

	private function build_reference( $payroll_id, $target_type ) {
		$payroll_id  = absint( $payroll_id );
		$target_type = sanitize_key( (string) $target_type );
		return sprintf( 'NOMINA-%d-%s', max( 1, $payroll_id ), strtoupper( $target_type ) );
	}

	private function build_notes( $payroll_title, $target_type ) {
		$payroll_title = sanitize_text_field( (string) $payroll_title );
		$target_type   = sanitize_key( (string) $target_type );
		$label_map     = array(
			'store_orders'     => 'deuda de tienda',
			'document'         => 'movimiento por cobrar',
			'installment_plan' => 'compromiso',
		);
		$label         = $label_map[ $target_type ] ?? 'deuda abierta';

		if ( '' === $payroll_title ) {
			return sprintf( 'Abono manual aplicado desde nomina sobre %s.', $label );
		}

		return sprintf( 'Abono manual aplicado desde nomina (%s) sobre %s.', $payroll_title, $label );
	}
}
