<?php

namespace ASDLabs\Finance\Integrations\Woo;

use ASDLabs\Finance\Finance\BaseRepository;
use ASDLabs\Finance\Finance\ContactsRepository;
use ASDLabs\Finance\Finance\DocumentsRepository;
use ASDLabs\Finance\Finance\EventsRepository;
use ASDLabs\Finance\Finance\PaymentAllocationService;
use ASDLabs\Finance\Finance\PaymentsRepository;

final class ProfileOrderSettlementService extends BaseRepository {
	public function preview_oldest_first( array $data ) {
		$context = $this->prepare_order_settlement_context( $data, 'profile_payment_preview' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( empty( $context['dual_pricing']['enabled'] ) ) {
			return array(
				'requires_preview' => false,
				'mode'             => 'standard',
				'currency'         => $context['currency'],
				'payment_method'   => array(
					'key'   => $context['method_key'],
					'label' => ( new DualPricingService() )->method_label( $context['method_key'] ),
				),
			);
		}

		$plan = $this->build_dual_plan(
			$context['order_rows'],
			$context['amount'],
			(float) $context['dual_pricing']['discount_fraction']
		);

		return $this->build_dual_preview_payload( $context, $plan );
	}

	public function settle_oldest_first( array $data ) {
		$context = $this->prepare_order_settlement_context( $data, 'profile_payment' );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( ! empty( $context['dual_pricing']['enabled'] ) ) {
			return $this->execute_dual_settlement( $context );
		}

		return $this->execute_standard_settlement( $context );
	}

	public function apply_credit_balance( array $data ) {
		$contact_id = absint( $data['contact_id'] ?? 0 );
		$requested  = max( 0, (float) ( $data['total'] ?? 0 ) );

		if ( $contact_id <= 0 ) {
			return new \WP_Error( 'asdl_fin_contact_missing', 'Debes indicar el perfil al que vas a aplicar saldo a favor.' );
		}

		if ( $requested <= 0 ) {
			return new \WP_Error( 'asdl_fin_credit_total', 'Debes indicar un monto valido para aplicar desde el saldo a favor.' );
		}

		$contact = ( new ContactsRepository() )->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return new \WP_Error( 'asdl_fin_contact_missing', 'No se encontro el perfil seleccionado.' );
		}

		$order_service = new OrderSyncService();
		$open_orders   = $this->get_open_orders_for_contact( $contact, $order_service );

		if ( empty( $open_orders ) ) {
			return new \WP_Error( 'asdl_fin_no_open_orders', 'Este perfil no tiene pedidos pendientes para cruzar con saldo a favor.' );
		}

		$available_payments = $this->get_credit_payments( $contact_id );
		$payable_documents  = $this->get_payable_documents( $contact_id );
		$credit_total       = $this->sum_available_payments( $available_payments ) + $this->sum_document_balances( $payable_documents );

		if ( $credit_total <= 0 ) {
			return new \WP_Error( 'asdl_fin_credit_missing', 'Este perfil no tiene saldo a favor disponible para compensar pedidos.' );
		}

		$pending_total = 0.0;
		foreach ( $open_orders as $open_order ) {
			$pending_total += (float) ( $open_order['total'] ?? 0 );
		}

		$amount           = min( $requested, $credit_total, $pending_total );
		$currency         = $this->resolve_order_currency( $open_orders, $data['currency'] ?? '' );
		$payment_date     = sanitize_text_field( $data['payment_date'] ?? gmdate( 'Y-m-d' ) );
		$account_id       = ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null;
		$reference_base   = sanitize_text_field( $data['reference'] ?? '' );
		$notes            = sanitize_textarea_field( $data['notes'] ?? 'Compensacion interna aplicada desde saldo a favor del perfil.' );
		$reference_base   = '' !== $reference_base ? $reference_base : sprintf( 'COMP-%s-%04d', gmdate( 'YmdHis' ), wp_rand( 0, 9999 ) );
		$remaining        = $amount;
		$order_allocs     = array();
		$closed_order_ids = array();
		$partial_order_ids= array();
		$source_payments  = array();
		$source_documents = array();
		$compensation_ids = array();

		$this->begin_transaction();

		foreach ( $available_payments as $payment ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$using = min( $remaining, (float) ( $payment['available_amount'] ?? 0 ) );
			if ( $using <= 0 ) {
				continue;
			}

			$allocation_result = $this->apply_payment_to_orders(
				(int) $payment['id'],
				$using,
				$open_orders,
				$order_service,
				'Saldo a favor preexistente aplicado a pedidos pendientes.',
				$order_allocs,
				$closed_order_ids,
				$partial_order_ids
			);

			if ( is_wp_error( $allocation_result ) ) {
				$this->rollback_transaction();
				return $allocation_result;
			}

			$applied         = (float) ( $allocation_result['applied_total'] ?? 0 );
			$remaining       = round( max( 0, $remaining - $applied ), 6 );
			$source_payments[] = array(
				'payment_id'     => (int) $payment['id'],
				'payment_number' => sanitize_text_field( (string) ( $payment['payment_number'] ?? '' ) ),
				'applied_total'  => $applied,
			);
		}

		if ( $remaining > 0 ) {
			$payable_source_total = min( $remaining, $this->sum_document_balances( $payable_documents ) );

			if ( $payable_source_total > 0 ) {
				$payments    = new PaymentsRepository();
				$source_id   = $payments->create(
					array(
						'payment_type' => 'adjustment',
						'account_id'   => $account_id,
						'contact_id'   => $contact_id,
						'status'       => 'posted',
						'payment_date' => $payment_date,
						'currency'     => $currency,
						'total'        => $payable_source_total,
						'method_key'   => 'internal_compensation',
						'reference'    => $reference_base . '-SRC',
						'notes'        => 'Aplicacion interna del saldo a favor sobre documentos por pagar.',
					)
				);

				if ( is_wp_error( $source_id ) ) {
					$this->rollback_transaction();
					return $source_id;
				}

				$source_result = $this->apply_payment_to_documents(
					(int) $source_id,
					$payable_documents,
					$payable_source_total,
					'Compensacion interna sobre saldo a favor del perfil.'
				);

				if ( is_wp_error( $source_result ) ) {
					$this->rollback_transaction();
					return $source_result;
				}

				$target_id = $payments->create(
					array(
						'payment_type' => 'adjustment',
						'account_id'   => $account_id,
						'contact_id'   => $contact_id,
						'status'       => 'posted',
						'payment_date' => $payment_date,
						'currency'     => $currency,
						'total'        => $payable_source_total,
						'method_key'   => 'internal_compensation',
						'reference'    => $reference_base,
						'notes'        => $notes,
					)
				);

				if ( is_wp_error( $target_id ) ) {
					$this->rollback_transaction();
					return $target_id;
				}

				$target_result = $this->apply_payment_to_orders(
					(int) $target_id,
					$payable_source_total,
					$open_orders,
					$order_service,
					'Compensacion interna aplicada desde saldo a favor del perfil.',
					$order_allocs,
					$closed_order_ids,
					$partial_order_ids
				);

				if ( is_wp_error( $target_result ) ) {
					$this->rollback_transaction();
					return $target_result;
				}

				$applied = (float) ( $target_result['applied_total'] ?? 0 );

				$remaining         = round( max( 0, $remaining - $applied ), 6 );
				$source_documents   = $source_result['documents'];
				$compensation_ids[] = (int) $source_id;
				$compensation_ids[] = (int) $target_id;
			}
		}

		$this->commit_transaction();

		( new EventsRepository() )->log(
			'contact',
			$contact_id,
			'credit_applied_to_orders',
			'Saldo a favor aplicado sobre pedidos abiertos del perfil.',
			array(
				'contact_id'           => $contact_id,
				'requested_total'      => $requested,
				'applied_total'        => round( $amount - $remaining, 6 ),
				'unapplied_total'      => $remaining,
				'closed_order_ids'     => $closed_order_ids,
				'partial_order_ids'    => $partial_order_ids,
				'source_payments'      => $source_payments,
				'source_documents'     => $source_documents,
				'compensation_payment_ids' => $compensation_ids,
			)
		);

		return array(
			'contact_id'            => $contact_id,
			'requested_total'       => $requested,
			'applied_total'         => round( $amount - $remaining, 6 ),
			'unapplied_total'       => $remaining,
			'closed_order_ids'      => array_values( array_unique( array_map( 'intval', $closed_order_ids ) ) ),
			'partial_order_ids'     => array_values( array_unique( array_map( 'intval', $partial_order_ids ) ) ),
			'allocations'           => $order_allocs,
			'source_payments'       => $source_payments,
			'source_documents'      => $source_documents,
			'compensation_payment_ids' => $compensation_ids,
		);
	}

	private function get_open_orders_for_contact( array $contact, OrderSyncService $order_service ) {
		$open_orders = $order_service->list_orders(
			array(
				'limit'       => 0,
				'customer_id' => ! empty( $contact['wp_user_id'] ) ? absint( $contact['wp_user_id'] ) : 0,
				'email'       => sanitize_email( $contact['email'] ?? '' ),
				'statuses'    => $order_service->collectible_statuses(),
			)
		);

		usort(
			$open_orders,
			static function ( array $left, array $right ) {
				$left_ts  = ! empty( $left['date_created'] ) ? strtotime( $left['date_created'] ) : 0;
				$right_ts = ! empty( $right['date_created'] ) ? strtotime( $right['date_created'] ) : 0;

				if ( $left_ts === $right_ts ) {
					return (int) $left['order_id'] <=> (int) $right['order_id'];
				}

				return $left_ts <=> $right_ts;
			}
		);

		return $open_orders;
	}

	private function prepare_order_settlement_context( array $data, $trigger ) {
		$contact_id = absint( $data['contact_id'] ?? 0 );
		$amount     = max( 0, (float) ( $data['total'] ?? 0 ) );

		if ( $contact_id <= 0 ) {
			return new \WP_Error( 'asdl_fin_contact_missing', 'Debes indicar el perfil que recibira el abono.' );
		}

		if ( $amount <= 0 ) {
			return new \WP_Error( 'asdl_fin_payment_total', 'Debes indicar un monto valido para el abono.' );
		}

		$contact = ( new ContactsRepository() )->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return new \WP_Error( 'asdl_fin_contact_missing', 'No se encontro el perfil seleccionado.' );
		}

		$order_service = new OrderSyncService();
		$open_orders   = $this->get_open_orders_for_contact( $contact, $order_service );

		if ( empty( $open_orders ) ) {
			return new \WP_Error( 'asdl_fin_no_open_orders', 'Este perfil no tiene pedidos pendientes por cobrar en Woo/OpenPOS.' );
		}

		$currency           = $this->resolve_order_currency( $open_orders, $data['currency'] ?? '' );
		$method_key         = sanitize_key( (string) ( $data['method_key'] ?? '' ) );
		$dual_service       = new DualPricingService();
		$discount_cfg       = $dual_service->get_discount_config();
		$dual_discount_mode = $this->resolve_dual_discount_mode( $data );
		$force_dual         = 'force' === $dual_discount_mode;
		$dual_enabled       = $this->resolve_dual_pricing_enabled( $dual_discount_mode, $method_key, $currency, $dual_service, $discount_cfg );
		$required_coverage = $this->calculate_required_collectible_coverage(
			$amount,
			array(
				'enabled'           => $dual_enabled,
				'discount_fraction' => (float) ( $discount_cfg['fraction'] ?? 0 ),
				'forced'            => $force_dual,
			)
		);
		$order_rows        = $this->build_collectible_order_documents( $open_orders, $order_service, $trigger, $required_coverage );

		if ( is_wp_error( $order_rows ) ) {
			return $order_rows;
		}

		if ( empty( $order_rows ) ) {
			return new \WP_Error( 'asdl_fin_no_collectible_documents', 'No se encontraron movimientos cobrables para los pedidos abiertos del perfil.' );
		}

		return array(
			'contact_id'     => $contact_id,
			'contact'        => $contact,
			'amount'         => $amount,
			'account_id'     => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : null,
			'payment_date'   => sanitize_text_field( $data['payment_date'] ?? gmdate( 'Y-m-d' ) ),
			'currency'       => $currency,
			'method_key'     => $method_key,
			'method_label'   => $dual_service->method_label( $method_key ),
			'payment_type'   => sanitize_key( (string) ( $data['payment_type'] ?? 'collection' ) ),
			'reference'      => sanitize_text_field( $data['reference'] ?? '' ),
			'notes'          => sanitize_textarea_field( $data['notes'] ?? 'Abono aplicado desde el perfil del cliente.' ),
			'manage_transaction' => ! array_key_exists( 'manage_transaction', $data ) || ! empty( $data['manage_transaction'] ),
			'trigger'        => sanitize_key( (string) $trigger ),
			'order_service'  => $order_service,
			'open_orders'    => $open_orders,
			'order_rows'     => $order_rows,
			'dual_pricing'   => array(
				'enabled'           => $dual_enabled,
				'discount_percent'  => (float) ( $discount_cfg['percent'] ?? 0 ),
				'discount_fraction' => (float) ( $discount_cfg['fraction'] ?? 0 ),
				'rate_snapshot'     => $dual_service->get_rate_snapshot(),
				'divisa_methods'    => $dual_service->get_divisa_method_keys(),
				'forced'            => $force_dual,
				'mode'              => $dual_discount_mode,
			),
		);
	}

	private function resolve_dual_discount_mode( array $data ) {
		$mode = sanitize_key( (string) ( $data['dual_discount_mode'] ?? '' ) );

		if ( in_array( $mode, array( 'off', 'auto', 'force' ), true ) ) {
			return $mode;
		}

		if ( array_key_exists( 'force_dual_discount', $data ) ) {
			return ! empty( $data['force_dual_discount'] ) ? 'force' : 'off';
		}

		return 'auto';
	}

	private function resolve_dual_pricing_enabled( $mode, $method_key, $currency, DualPricingService $dual_service, array $discount_cfg ) {
		$mode = sanitize_key( (string) $mode );

		if ( 'off' === $mode ) {
			return false;
		}

		if ( 'force' === $mode ) {
			return ! empty( $discount_cfg['active'] ) && 'USD' === strtoupper( sanitize_text_field( (string) $currency ) );
		}

		return $dual_service->qualifies_for_dual_discount( $method_key, $currency );
	}

	private function build_collectible_order_documents( array $open_orders, OrderSyncService $order_service, $trigger, $required_coverage = 0 ) {
		$rows              = array();
		$collected_balance = 0.0;
		$required_coverage = max( 0, (float) $required_coverage );

		foreach ( $open_orders as $order_item ) {
			$sync_result = $order_service->sync_order( (int) $order_item['order_id'], array( 'trigger' => sanitize_key( (string) $trigger ) ) );
			if ( is_wp_error( $sync_result ) ) {
				return $sync_result;
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

			$rows[] = array(
				'order_id'          => (int) $order_item['order_id'],
				'order_number'      => sanitize_text_field( (string) ( $order_item['order_number'] ?? '' ) ),
				'order_label'       => 'Pedido #' . sanitize_text_field( (string) ( $order_item['order_number'] ?? '' ) ),
				'date_created'      => sanitize_text_field( (string) ( $order_item['date_created'] ?? '' ) ),
				'currency'          => sanitize_text_field( (string) ( $document['currency'] ?? ( $order_item['currency'] ?? 'USD' ) ) ),
				'document_id'       => (int) $document['id'],
				'document_number'   => sanitize_text_field( (string) ( $document['document_number'] ?? '' ) ),
				'document_title'    => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
				'document_balance'  => $balance,
				'edit_url'          => esc_url_raw( (string) ( $order_item['edit_url'] ?? '' ) ),
			);

			$collected_balance = round( $collected_balance + $balance, 6 );

			if ( $required_coverage > 0 && $collected_balance >= $required_coverage - 0.00001 ) {
				break;
			}
		}

		return $rows;
	}

	private function calculate_required_collectible_coverage( $amount, array $dual_pricing ) {
		$requested = max( 0, (float) $amount );

		if ( $requested <= 0 ) {
			return 0.0;
		}

		if ( empty( $dual_pricing['enabled'] ) ) {
			return round( $requested, 6 );
		}

		$discount_factor = max( 0.005, 1 - max( 0, min( 0.95, (float) ( $dual_pricing['discount_fraction'] ?? 0 ) ) ) );

		return round( $requested / $discount_factor, 6 );
	}

	private function build_dual_plan( array $order_rows, $amount, $discount_fraction ) {
		$dual_service       = new DualPricingService();
		$remaining_customer = max( 0, (float) $amount );
		$closed_order_ids   = array();
		$partial_order_ids  = array();
		$items              = array();
		$summary            = array(
			'requested_total'        => round( max( 0, (float) $amount ), 6 ),
			'payment_applied_total'  => 0.0,
			'discount_applied_total' => 0.0,
			'covered_total'          => 0.0,
			'unapplied_total'        => 0.0,
			'closed_count'           => 0,
			'partial_count'          => 0,
		);

		foreach ( $order_rows as $index => $row ) {
			if ( $remaining_customer <= 0 ) {
				break;
			}

			$balance = max( 0, (float) ( $row['document_balance'] ?? 0 ) );
			if ( $balance <= 0 ) {
				continue;
			}

			$calc = $dual_service->compute_dual( $balance, $remaining_customer, $discount_fraction );
			if ( (float) ( $calc['gross_covered'] ?? 0 ) <= 0 ) {
				continue;
			}

			$payment_applied  = round( (float) ( $calc['net_effective'] ?? 0 ), 6 );
			$discount_applied = round( (float) ( $calc['discount'] ?? 0 ), 6 );
			$covered_total    = round( (float) ( $calc['gross_covered'] ?? 0 ), 6 );
			$remaining_doc    = round( max( 0, $balance - $covered_total ), 6 );
			$remaining_customer = round( max( 0, $remaining_customer - $payment_applied ), 6 );
			$closes_order     = $remaining_doc <= 0.00001;

			if ( $closes_order ) {
				$closed_order_ids[] = (int) $row['order_id'];
			} else {
				$partial_order_ids[] = (int) $row['order_id'];
			}

			$items[] = array(
				'sequence'                   => $index + 1,
				'order_id'                   => (int) $row['order_id'],
				'order_number'               => sanitize_text_field( (string) ( $row['order_number'] ?? '' ) ),
				'order_label'                => sanitize_text_field( (string) ( $row['order_label'] ?? '' ) ),
				'date_created'               => sanitize_text_field( (string) ( $row['date_created'] ?? '' ) ),
				'currency'                   => sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) ),
				'document_id'                => (int) ( $row['document_id'] ?? 0 ),
				'document_balance'           => $balance,
				'payment_applied_total'      => $payment_applied,
				'discount_applied_total'     => $discount_applied,
				'covered_total'              => $covered_total,
				'remaining_document_balance' => $remaining_doc,
				'remaining_request_total'    => $remaining_customer,
				'edit_url'                   => esc_url_raw( (string) ( $row['edit_url'] ?? '' ) ),
				'closes_order'               => $closes_order,
				'status_key'                 => $closes_order ? 'closed' : 'partial',
				'status_label'               => $closes_order ? 'Cerrado' : 'Parcial',
			);

			$summary['payment_applied_total']  += $payment_applied;
			$summary['discount_applied_total'] += $discount_applied;
			$summary['covered_total']          += $covered_total;
		}

		$summary['payment_applied_total']  = round( $summary['payment_applied_total'], 6 );
		$summary['discount_applied_total'] = round( $summary['discount_applied_total'], 6 );
		$summary['covered_total']          = round( $summary['covered_total'], 6 );
		$summary['unapplied_total']        = round( $remaining_customer, 6 );
		$summary['closed_count']           = count( $closed_order_ids );
		$summary['partial_count']          = count( $partial_order_ids );

		return array(
			'items'             => $items,
			'summary'           => $summary,
			'closed_order_ids'  => array_values( array_unique( $closed_order_ids ) ),
			'partial_order_ids' => array_values( array_unique( $partial_order_ids ) ),
		);
	}

	private function build_dual_preview_payload( array $context, array $plan ) {
		return array(
			'requires_preview' => true,
			'mode'             => 'dual_discount',
			'currency'         => $context['currency'],
			'payment_method'   => array(
				'key'   => $context['method_key'],
				'label' => $context['method_label'],
			),
			'discount'         => array(
				'percent'  => (float) ( $context['dual_pricing']['discount_percent'] ?? 0 ),
				'fraction' => (float) ( $context['dual_pricing']['discount_fraction'] ?? 0 ),
			),
			'rate_snapshot'    => $context['dual_pricing']['rate_snapshot'] ?? null,
			'summary'          => $plan['summary'],
			'items'            => $plan['items'],
			'preview_signature'=> $this->build_dual_preview_signature( $context, $plan ),
		);
	}

	private function build_dual_preview_signature( array $context, array $plan ) {
		$items = array_map(
			static function ( array $item ) {
				return array(
					'order_id'                   => (int) ( $item['order_id'] ?? 0 ),
					'document_id'                => (int) ( $item['document_id'] ?? 0 ),
					'document_balance'           => round( (float) ( $item['document_balance'] ?? 0 ), 6 ),
					'payment_applied_total'      => round( (float) ( $item['payment_applied_total'] ?? 0 ), 6 ),
					'discount_applied_total'     => round( (float) ( $item['discount_applied_total'] ?? 0 ), 6 ),
					'covered_total'              => round( (float) ( $item['covered_total'] ?? 0 ), 6 ),
					'remaining_document_balance' => round( (float) ( $item['remaining_document_balance'] ?? 0 ), 6 ),
					'status_key'                 => sanitize_key( (string) ( $item['status_key'] ?? '' ) ),
				);
			},
			(array) ( $plan['items'] ?? array() )
		);

		$payload = array(
			'contact_id'      => (int) ( $context['contact_id'] ?? 0 ),
			'account_id'      => ! empty( $context['account_id'] ) ? (int) $context['account_id'] : 0,
			'payment_date'    => sanitize_text_field( (string) ( $context['payment_date'] ?? '' ) ),
			'currency'        => strtoupper( sanitize_text_field( (string) ( $context['currency'] ?? 'USD' ) ) ),
			'method_key'      => sanitize_key( (string) ( $context['method_key'] ?? '' ) ),
			'payment_type'    => sanitize_key( (string) ( $context['payment_type'] ?? 'collection' ) ),
			'amount'          => round( (float) ( $context['amount'] ?? 0 ), 6 ),
			'discount_percent'=> round( (float) ( $context['dual_pricing']['discount_percent'] ?? 0 ), 6 ),
			'divisa_methods'  => array_values( array_map( 'sanitize_key', (array) ( $context['dual_pricing']['divisa_methods'] ?? array() ) ) ),
			'forced_dual'     => ! empty( $context['dual_pricing']['forced'] ),
			'summary'         => array(
				'requested_total'        => round( (float) ( $plan['summary']['requested_total'] ?? 0 ), 6 ),
				'payment_applied_total'  => round( (float) ( $plan['summary']['payment_applied_total'] ?? 0 ), 6 ),
				'discount_applied_total' => round( (float) ( $plan['summary']['discount_applied_total'] ?? 0 ), 6 ),
				'covered_total'          => round( (float) ( $plan['summary']['covered_total'] ?? 0 ), 6 ),
				'unapplied_total'        => round( (float) ( $plan['summary']['unapplied_total'] ?? 0 ), 6 ),
				'closed_count'           => (int) ( $plan['summary']['closed_count'] ?? 0 ),
				'partial_count'          => (int) ( $plan['summary']['partial_count'] ?? 0 ),
			),
			'items'           => $items,
		);

		return hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'auth' ) );
	}

	private function execute_standard_settlement( array $context ) {
		$payments     = new PaymentsRepository();
		$payment_id   = $payments->create(
			array(
				'payment_type' => $context['payment_type'] ?? 'collection',
				'account_id'   => $context['account_id'],
				'contact_id'   => $context['contact_id'],
				'status'       => 'posted',
				'payment_date' => $context['payment_date'],
				'currency'     => $context['currency'],
				'total'        => $context['amount'],
				'method_key'   => $context['method_key'],
				'reference'    => $context['reference'],
				'notes'        => $context['notes'],
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		$allocation_service = new PaymentAllocationService();
		$remaining          = $context['amount'];
		$allocations        = array();
		$closed_order_ids   = array();
		$partial_order_ids  = array();

		foreach ( $context['order_rows'] as $row ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$apply_amount = min( $remaining, (float) $row['document_balance'] );
			if ( $apply_amount <= 0 ) {
				continue;
			}

			$result = $allocation_service->allocate(
				array(
					'payment_id'  => (int) $payment_id,
					'document_id' => (int) $row['document_id'],
					'amount'      => $apply_amount,
					'notes'       => sprintf( 'Abono automatico por antiguedad sobre pedido #%s.', $row['order_number'] ),
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$remaining     = round( max( 0, $remaining - $apply_amount ), 6 );
			$allocations[] = $result;
			do_action( 'asdl_fin_payment_allocated', $result );

			if ( 'paid' === ( $result['document_status'] ?? '' ) ) {
				$closed_order_ids[] = (int) $row['order_id'];
			} else {
				$partial_order_ids[] = (int) $row['order_id'];
			}
		}

		( new EventsRepository() )->log(
			'contact',
			$context['contact_id'],
			'profile_payment_applied',
			'Abono aplicado por antiguedad sobre pedidos Woo/OpenPOS del perfil.',
			array(
				'contact_id'        => $context['contact_id'],
				'payment_id'        => (int) $payment_id,
				'applied_total'     => round( $context['amount'] - $remaining, 6 ),
				'unapplied_total'   => $remaining,
				'closed_order_ids'  => $closed_order_ids,
				'partial_order_ids' => $partial_order_ids,
				'allocations_count' => count( $allocations ),
			)
		);

		return array(
			'contact_id'        => $context['contact_id'],
			'payment_id'        => (int) $payment_id,
			'applied_total'     => round( $context['amount'] - $remaining, 6 ),
			'unapplied_total'   => $remaining,
			'closed_order_ids'  => array_values( array_unique( $closed_order_ids ) ),
			'partial_order_ids' => array_values( array_unique( $partial_order_ids ) ),
			'allocations'       => $allocations,
		);
	}

	private function execute_dual_settlement( array $context ) {
		$plan = $this->build_dual_plan(
			$context['order_rows'],
			$context['amount'],
			(float) $context['dual_pricing']['discount_fraction']
		);

		if ( empty( $plan['items'] ) ) {
			return new \WP_Error( 'asdl_fin_dual_preview_empty', 'No se pudo construir una simulacion valida para este abono con precio dual.' );
		}

		$payments           = new PaymentsRepository();
		$allocation_service = new PaymentAllocationService();
		$events             = new EventsRepository();
		$main_payment_id    = 0;
		$discount_payment_id= 0;
		$main_allocations   = array();
		$discount_allocs    = array();

		if ( ! empty( $context['manage_transaction'] ) ) {
			$this->begin_transaction();
		}

		$main_payment_id = $payments->create(
			array(
				'payment_type' => $context['payment_type'] ?? 'collection',
				'account_id'   => $context['account_id'],
				'contact_id'   => $context['contact_id'],
				'status'       => 'posted',
				'payment_date' => $context['payment_date'],
				'currency'     => $context['currency'],
				'total'        => $context['amount'],
				'method_key'   => $context['method_key'],
				'reference'    => $context['reference'],
				'notes'        => $context['notes'],
			)
		);

			if ( is_wp_error( $main_payment_id ) ) {
			if ( ! empty( $context['manage_transaction'] ) ) {
				$this->rollback_transaction();
			}
			return $main_payment_id;
		}

		$discount_total = (float) ( $plan['summary']['discount_applied_total'] ?? 0 );
		if ( $discount_total > 0 ) {
			$discount_payment_id = $payments->create(
				array(
					'payment_type' => 'adjustment',
					'account_id'   => $context['account_id'],
					'contact_id'   => $context['contact_id'],
					'status'       => 'posted',
					'payment_date' => $context['payment_date'],
					'currency'     => $context['currency'],
					'total'        => $discount_total,
					'method_key'   => 'dual_price_discount',
					'reference'    => $context['reference'] ? $context['reference'] . '-DUAL' : '',
					'notes'        => 'Descuento precio dual aplicado automaticamente sobre pedidos de tienda.',
				)
			);

			if ( is_wp_error( $discount_payment_id ) ) {
				if ( ! empty( $context['manage_transaction'] ) ) {
					$this->rollback_transaction();
				}
				return $discount_payment_id;
			}
		}

		foreach ( $plan['items'] as $item ) {
			$document_id = (int) ( $item['document_id'] ?? 0 );
			if ( $document_id <= 0 ) {
				continue;
			}

			$net_amount = (float) ( $item['payment_applied_total'] ?? 0 );
			if ( $net_amount > 0 ) {
				$allocation = $allocation_service->allocate(
					array(
						'payment_id'         => (int) $main_payment_id,
						'document_id'        => $document_id,
						'amount'             => $net_amount,
						'notes'              => sprintf( 'Abono en divisa aplicado por precio dual sobre pedido #%s.', $item['order_number'] ?? '' ),
						'manage_transaction' => false,
					)
				);

				if ( is_wp_error( $allocation ) ) {
					if ( ! empty( $context['manage_transaction'] ) ) {
						$this->rollback_transaction();
					}
					return $allocation;
				}

				$main_allocations[] = $allocation;
				do_action( 'asdl_fin_payment_allocated', $allocation );
			}

			$discount_amount = (float) ( $item['discount_applied_total'] ?? 0 );
			if ( $discount_payment_id > 0 && $discount_amount > 0 ) {
				$allocation = $allocation_service->allocate(
					array(
						'payment_id'         => (int) $discount_payment_id,
						'document_id'        => $document_id,
						'amount'             => $discount_amount,
						'notes'              => sprintf( 'Descuento precio dual (%1$s%%) aplicado sobre pedido #%2$s.', number_format_i18n( (float) ( $context['dual_pricing']['discount_percent'] ?? 0 ), 2 ), $item['order_number'] ?? '' ),
						'manage_transaction' => false,
					)
				);

				if ( is_wp_error( $allocation ) ) {
					if ( ! empty( $context['manage_transaction'] ) ) {
						$this->rollback_transaction();
					}
					return $allocation;
				}

				$discount_allocs[] = $allocation;
				do_action( 'asdl_fin_payment_allocated', $allocation );
			}
		}

		$main_meta = array(
			'dual_discount_mode'        => $discount_total > 0 ? sanitize_key( (string) ( $context['dual_pricing']['mode'] ?? ( ! empty( $context['dual_pricing']['enabled'] ) ? 'auto' : '' ) ) ) : '',
			'dual_discount_percent'     => (float) ( $context['dual_pricing']['discount_percent'] ?? 0 ),
			'dual_discount_total'       => round( $discount_total, 6 ),
			'dual_discount_payment_ids' => $discount_payment_id > 0 ? array( (int) $discount_payment_id ) : array(),
			'dual_discount_order_ids'   => array_values( array_map( 'intval', wp_list_pluck( $plan['items'], 'order_id' ) ) ),
		);

		if ( ! $payments->set_status( (int) $main_payment_id, 'posted', $main_meta ) ) {
			if ( ! empty( $context['manage_transaction'] ) ) {
				$this->rollback_transaction();
			}
			return new \WP_Error( 'asdl_fin_dual_meta_update', 'No se pudo guardar la trazabilidad del abono con precio dual.' );
		}

		if ( $discount_payment_id > 0 && ! $payments->set_status(
			(int) $discount_payment_id,
			'posted',
			array(
				'dual_discount_parent_payment_id' => (int) $main_payment_id,
				'dual_discount_percent'           => (float) ( $context['dual_pricing']['discount_percent'] ?? 0 ),
				'dual_discount_total'             => round( $discount_total, 6 ),
				'dual_discount_order_ids'         => array_values( array_map( 'intval', wp_list_pluck( $plan['items'], 'order_id' ) ) ),
			)
		) ) {
			if ( ! empty( $context['manage_transaction'] ) ) {
				$this->rollback_transaction();
			}
			return new \WP_Error( 'asdl_fin_dual_child_meta_update', 'No se pudo guardar la trazabilidad del descuento precio dual.' );
		}

		if ( ! empty( $context['manage_transaction'] ) ) {
			$this->commit_transaction();
		}

		$this->append_dual_order_notes( $context, $plan );

		$events->log(
			'contact',
			$context['contact_id'],
			'profile_payment_applied',
			'Abono en divisa aplicado con precio dual sobre pedidos Woo/OpenPOS del perfil.',
			array(
				'contact_id'             => $context['contact_id'],
				'payment_id'             => (int) $main_payment_id,
				'discount_payment_id'    => (int) $discount_payment_id,
				'applied_total'          => (float) ( $plan['summary']['payment_applied_total'] ?? 0 ),
				'covered_total'          => (float) ( $plan['summary']['covered_total'] ?? 0 ),
				'dual_discount_total'    => $discount_total,
				'unapplied_total'        => (float) ( $plan['summary']['unapplied_total'] ?? 0 ),
				'closed_order_ids'       => $plan['closed_order_ids'],
				'partial_order_ids'      => $plan['partial_order_ids'],
				'allocations_count'      => count( $main_allocations ) + count( $discount_allocs ),
			)
		);

		return array(
			'contact_id'             => $context['contact_id'],
			'payment_id'             => (int) $main_payment_id,
			'discount_payment_ids'   => $discount_payment_id > 0 ? array( (int) $discount_payment_id ) : array(),
			'applied_total'          => (float) ( $plan['summary']['payment_applied_total'] ?? 0 ),
			'covered_total'          => (float) ( $plan['summary']['covered_total'] ?? 0 ),
			'dual_discount_applied'  => true,
			'dual_discount_percent'  => (float) ( $context['dual_pricing']['discount_percent'] ?? 0 ),
			'dual_discount_total'    => $discount_total,
			'unapplied_total'        => (float) ( $plan['summary']['unapplied_total'] ?? 0 ),
			'closed_order_ids'       => $plan['closed_order_ids'],
			'partial_order_ids'      => $plan['partial_order_ids'],
			'allocations'            => array_merge( $main_allocations, $discount_allocs ),
			'items'                  => $plan['items'],
		);
	}

	private function get_credit_payments( $contact_id ) {
		$payments = array_filter(
			( new PaymentsRepository() )->for_contact( $contact_id, 200 ),
			static function ( array $payment ) {
				return 'posted' === ( $payment['status'] ?? '' )
					&& (float) ( $payment['available_amount'] ?? 0 ) > 0
					&& in_array( sanitize_key( $payment['payment_type'] ?? '' ), array( 'collection', 'adjustment' ), true );
			}
		);

		usort(
			$payments,
			static function ( array $left, array $right ) {
				$left_ts  = ! empty( $left['payment_date'] ) ? strtotime( $left['payment_date'] ) : 0;
				$right_ts = ! empty( $right['payment_date'] ) ? strtotime( $right['payment_date'] ) : 0;

				if ( $left_ts === $right_ts ) {
					return (int) $left['id'] <=> (int) $right['id'];
				}

				return $left_ts <=> $right_ts;
			}
		);

		return array_values( $payments );
	}

	private function get_payable_documents( $contact_id ) {
		$documents = array_filter(
			( new DocumentsRepository() )->for_contact( $contact_id, 200, true ),
			static function ( array $document ) {
				return 'payable' === sanitize_key( $document['balance_nature'] ?? '' )
					&& 'void' !== sanitize_key( $document['financial_status'] ?? '' )
					&& (float) ( $document['balance'] ?? 0 ) > 0;
			}
		);

		usort(
			$documents,
			static function ( array $left, array $right ) {
				$left_ts  = ! empty( $left['issue_date'] ) ? strtotime( $left['issue_date'] ) : 0;
				$right_ts = ! empty( $right['issue_date'] ) ? strtotime( $right['issue_date'] ) : 0;

				if ( $left_ts === $right_ts ) {
					return (int) $left['id'] <=> (int) $right['id'];
				}

				return $left_ts <=> $right_ts;
			}
		);

		return array_values( $documents );
	}

	private function sum_available_payments( array $payments ) {
		$total = 0.0;

		foreach ( $payments as $payment ) {
			$total += (float) ( $payment['available_amount'] ?? 0 );
		}

		return round( $total, 6 );
	}

	private function sum_document_balances( array $documents ) {
		$total = 0.0;

		foreach ( $documents as $document ) {
			$total += (float) ( $document['balance'] ?? 0 );
		}

		return round( $total, 6 );
	}

	private function resolve_order_currency( array $orders, $fallback = '' ) {
		foreach ( $orders as $order ) {
			if ( ! empty( $order['currency'] ) ) {
				return strtoupper( sanitize_text_field( $order['currency'] ) );
			}
		}

		return strtoupper( sanitize_text_field( $fallback ?: 'USD' ) );
	}

	private function apply_payment_to_orders( $payment_id, $limit_amount, array $open_orders, OrderSyncService $order_service, $notes, array &$allocations, array &$closed_order_ids, array &$partial_order_ids ) {
		$remaining          = max( 0, (float) $limit_amount );
		$allocation_service = new PaymentAllocationService();

		foreach ( $open_orders as $order_item ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$sync_result = $order_service->sync_order( (int) $order_item['order_id'], array( 'trigger' => 'profile_credit_compensation' ) );
			if ( is_wp_error( $sync_result ) ) {
				return $sync_result;
			}

			$context = $order_service->get_linked_document_context( (int) $order_item['order_id'] );
			if ( empty( $context['document']['id'] ) ) {
				continue;
			}

			$document = $context['document'];
			$balance  = (float) ( $document['balance'] ?? 0 );

			if ( $balance <= 0 ) {
				continue;
			}

			$apply_amount = min( $remaining, $balance );
			$result       = $allocation_service->allocate(
				array(
					'payment_id'         => $payment_id,
					'document_id'        => (int) $document['id'],
					'amount'             => $apply_amount,
					'notes'              => $notes,
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$remaining     = round( max( 0, $remaining - $apply_amount ), 6 );
			$allocations[] = $result;
			do_action( 'asdl_fin_payment_allocated', $result );

			if ( 'paid' === ( $result['document_status'] ?? '' ) ) {
				$closed_order_ids[] = (int) $order_item['order_id'];
			} else {
				$partial_order_ids[] = (int) $order_item['order_id'];
			}
		}

		return array(
			'applied_total' => round( $limit_amount - $remaining, 6 ),
			'remaining'     => $remaining,
		);
	}

	private function apply_payment_to_documents( $payment_id, array $documents, $limit_amount, $notes ) {
		$remaining          = max( 0, (float) $limit_amount );
		$allocation_service = new PaymentAllocationService();
		$applied_documents  = array();

		foreach ( $documents as $document ) {
			if ( $remaining <= 0 ) {
				break;
			}

			$balance = (float) ( $document['balance'] ?? 0 );
			if ( $balance <= 0 ) {
				continue;
			}

			$apply_amount = min( $remaining, $balance );
			$result       = $allocation_service->allocate(
				array(
					'payment_id'         => $payment_id,
					'document_id'        => (int) $document['id'],
					'amount'             => $apply_amount,
					'notes'              => $notes,
					'manage_transaction' => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$remaining           = round( max( 0, $remaining - $apply_amount ), 6 );
			$applied_documents[] = array(
				'document_id'    => (int) $document['id'],
				'document_title' => sanitize_text_field( (string) ( $document['title'] ?? '' ) ),
				'applied_total'  => $apply_amount,
			);
		}

		return array(
			'applied_total' => round( $limit_amount - $remaining, 6 ),
			'remaining'     => $remaining,
			'documents'     => $applied_documents,
		);
	}

	private function append_dual_order_notes( array $context, array $plan ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$current_user = wp_get_current_user();
		$user_label   = $current_user && ! empty( $current_user->display_name ) ? $current_user->display_name : 'Finanzas ASD';
		$method_label = $context['method_label'] ?: $context['method_key'];
		$percent      = number_format_i18n( (float) ( $context['dual_pricing']['discount_percent'] ?? 0 ), 2 );

		foreach ( $plan['items'] as $item ) {
			$order = wc_get_order( (int) ( $item['order_id'] ?? 0 ) );
			if ( ! $order ) {
				continue;
			}

			$status_line = ! empty( $item['closes_order'] ) ? 'Pedido cerrado por completo.' : 'Pedido con saldo remanente tras el abono.';
			$note        = sprintf(
				'Finanzas ASD aplico un abono con precio dual desde el perfil. Metodo: %1$s | Neto recibido: %2$s | Descuento: %3$s (%4$s%%) | Deuda cubierta: %5$s | %6$s | Operado por: %7$s.',
				$method_label,
				wp_strip_all_tags( $this->format_money( $item['payment_applied_total'] ?? 0, $context['currency'] ) ),
				wp_strip_all_tags( $this->format_money( $item['discount_applied_total'] ?? 0, $context['currency'] ) ),
				$percent,
				wp_strip_all_tags( $this->format_money( $item['covered_total'] ?? 0, $context['currency'] ) ),
				$status_line,
				$user_label
			);

			$order->add_order_note( $note, false, true );
		}
	}
}
