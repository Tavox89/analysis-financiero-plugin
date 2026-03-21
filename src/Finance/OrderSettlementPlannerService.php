<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\DualPricingService;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class OrderSettlementPlannerService extends BaseRepository {
	const FAST_PATH_MAX_ITEMS     = 10;
	const BATCH_SIZE_STANDARD     = 12;
	const BATCH_SIZE_STANDARD_MIX = 4;
	const BATCH_SIZE_DUAL         = 1;

	private $contacts;
	private $fiscal_years;
	private $historical_index;
	private $order_service;
	private $dual_pricing;

	public function __construct() {
		$this->contacts         = new ContactsRepository();
		$this->fiscal_years     = new FiscalYearService();
		$this->historical_index = new CommerceOrderIndexRepository();
		$this->order_service    = new OrderSyncService();
		$this->dual_pricing     = new DualPricingService();
	}

	public function preview( array $args, $origin = 'profile_settlement' ) {
		$context = $this->normalize_context( $args, $origin );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$candidates = $this->collect_candidates( $context );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		if ( empty( $candidates ) ) {
			return $this->error( 'asdl_fin_order_settlement_empty', 'No encontramos deuda cobrable de tienda para construir esta vista previa.' );
		}

		$plan = $this->build_plan( $context, $candidates );
		if ( empty( $plan['items'] ) ) {
			return $this->error( 'asdl_fin_order_settlement_empty', 'No encontramos deuda cobrable de tienda para construir esta vista previa.' );
		}

		$execution_mode = $this->resolve_execution_mode( $context, $plan );
		$batch_size     = $this->resolve_batch_size( $context, $plan );
		$signature      = $this->build_preview_signature( $context, $plan, $execution_mode, $batch_size );

		return array(
			'requires_preview'   => true,
			'origin'             => sanitize_key( (string) $origin ),
			'mode'               => ! empty( $context['uses_dual'] ) ? 'dual_discount' : 'standard',
			'execution_mode'     => $execution_mode,
			'currency'           => $context['currency'],
			'payment_method'     => array(
				'key'   => $context['method_key'],
				'label' => $context['method_label'],
			),
			'uses_dual'          => ! empty( $context['uses_dual'] ),
			'discount'           => array(
				'percent'  => (float) ( $context['discount_percent'] ?? 0 ),
				'fraction' => (float) ( $context['discount_fraction'] ?? 0 ),
			),
			'rate_snapshot'      => $context['rate_snapshot'] ?? null,
			'summary'            => $plan['summary'],
			'items'              => $plan['items'],
			'totals'             => $plan['summary'],
			'thresholds'         => array(
				'fast_path_max_items' => self::FAST_PATH_MAX_ITEMS,
				'batch_size'          => $batch_size,
			),
			'preview_signature'  => $signature,
			'contact_id'         => $context['contact_id'],
			'account_id'         => $context['account_id'],
			'payment_date'       => $context['payment_date'],
			'reference'          => $context['reference'],
			'notes'              => $context['notes'],
			'payment_type'       => $context['payment_type'],
			'batch_payload'      => array(
				'contact_id'         => $context['contact_id'],
				'origin'             => sanitize_key( (string) $origin ),
				'account_id'         => $context['account_id'],
				'payment_date'       => $context['payment_date'],
				'currency'           => $context['currency'],
				'method_key'         => $context['method_key'],
				'payment_type'       => $context['payment_type'],
				'reference'          => $context['reference'],
				'notes'              => $context['notes'],
				'total'              => round( (float) $context['amount'], 6 ),
				'uses_dual'          => ! empty( $context['uses_dual'] ),
				'discount_percent'   => round( (float) ( $context['discount_percent'] ?? 0 ), 6 ),
				'discount_fraction'  => round( (float) ( $context['discount_fraction'] ?? 0 ), 6 ),
				'execution_mode'     => $execution_mode,
				'batch_size'         => $batch_size,
			),
		);
	}

	private function normalize_context( array $args, $origin ) {
		$contact_id = absint( $args['contact_id'] ?? 0 );
		$amount     = max( 0, (float) ( $args['total'] ?? 0 ) );

		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_contact_missing', 'Debes indicar el perfil que recibira el abono.' );
		}

		if ( $amount <= 0 ) {
			return $this->error( 'asdl_fin_payment_total', 'Debes indicar un monto valido para el abono.' );
		}

		$contact = $this->contacts->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_contact_missing', 'No encontramos el perfil asociado al abono.' );
		}

		$currency        = strtoupper( sanitize_text_field( (string) ( $args['currency'] ?? 'USD' ) ) );
		$method_key      = sanitize_key( (string) ( $args['method_key'] ?? '' ) );
		$payment_date    = sanitize_text_field( (string) ( $args['payment_date'] ?? gmdate( 'Y-m-d' ) ) );
		$payment_type    = sanitize_key( (string) ( $args['payment_type'] ?? 'collection' ) );
		$reference       = sanitize_text_field( (string) ( $args['reference'] ?? '' ) );
		$notes           = sanitize_textarea_field( (string) ( $args['notes'] ?? 'Abono aplicado desde el perfil del cliente.' ) );
		$discount_config = $this->dual_pricing->get_discount_config();
		$force_dual      = ! empty( $args['force_dual_discount'] );
		$uses_dual       = $force_dual
			? ! empty( $discount_config['active'] ) && 'USD' === $currency
			: $this->dual_pricing->qualifies_for_dual_discount( $method_key, $currency );

		return array(
			'origin'            => sanitize_key( (string) $origin ),
			'contact_id'        => $contact_id,
			'contact'           => $contact,
			'amount'            => round( $amount, 6 ),
			'account_id'        => ! empty( $args['account_id'] ) ? absint( $args['account_id'] ) : null,
			'payment_date'      => $payment_date,
			'currency'          => $currency,
			'method_key'        => $method_key,
			'method_label'      => $this->dual_pricing->method_label( $method_key ),
			'payment_type'      => $payment_type,
			'reference'         => $reference,
			'notes'             => $notes,
			'uses_dual'         => $uses_dual,
			'force_dual'        => $force_dual,
			'discount_percent'  => (float) ( $discount_config['percent'] ?? 0 ),
			'discount_fraction' => (float) ( $discount_config['fraction'] ?? 0 ),
			'rate_snapshot'     => $this->dual_pricing->get_rate_snapshot(),
			'fiscal_context'    => $this->fiscal_years->get_context(),
		);
	}

	private function collect_candidates( array $context ) {
		$contact      = (array) ( $context['contact'] ?? array() );
		$fiscal       = (array) ( $context['fiscal_context'] ?? array() );
		$active_start = $this->sanitize_date( $fiscal['start_date'] ?? '' );
		$active_end   = $this->sanitize_date( $fiscal['end_date'] ?? '' );

		if ( empty( $active_start ) || empty( $active_end ) ) {
			return $this->error( 'asdl_fin_fiscal_context', 'No pudimos resolver el ejercicio fiscal activo para construir el plan de abono.' );
		}

		$current_live = $this->collect_current_live_candidates( $contact, $active_start, $active_end );
		$historical   = $this->collect_historical_candidates( $contact, gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $active_start ) ) ) );
		$candidates   = array_merge( $historical, $current_live );

		usort(
			$candidates,
			static function ( array $left, array $right ) {
				$left_ts  = ! empty( $left['issue_date'] ) ? strtotime( $left['issue_date'] ) : 0;
				$right_ts = ! empty( $right['issue_date'] ) ? strtotime( $right['issue_date'] ) : 0;

				if ( $left_ts === $right_ts ) {
					return (int) ( $left['external_order_id'] ?? 0 ) <=> (int) ( $right['external_order_id'] ?? 0 );
				}

				return $left_ts <=> $right_ts;
			}
		);

		return $candidates;
	}

	private function collect_current_live_candidates( array $contact, $range_from, $range_to ) {
		$orders = $this->order_service->list_orders(
			array(
				'limit'       => 0,
				'customer_id' => ! empty( $contact['wp_user_id'] ) ? absint( $contact['wp_user_id'] ) : 0,
				'email'       => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
				'statuses'    => $this->order_service->collectible_statuses(),
				'range_from'  => $range_from,
				'range_to'    => $range_to,
			)
		);

		$items = array();
		foreach ( $orders as $order ) {
			if ( empty( $order['is_effectively_open'] ) ) {
				continue;
			}

			$balance = round( (float) ( $order['effective_due_total'] ?? 0 ), 6 );
			if ( $balance <= 0 ) {
				continue;
			}

			$order_id    = (int) ( $order['order_id'] ?? 0 );
			$issue_date  = $this->extract_issue_date( (string) ( $order['date_created'] ?? '' ) );
			$order_label = ! empty( $order['order_number'] ) ? 'Pedido #' . sanitize_text_field( (string) $order['order_number'] ) : ( 'Pedido #' . $order_id );
			$items[]     = array(
				'source_kind'       => 'current_live',
				'provider'          => sanitize_key( (string) ( $order['provider'] ?? '' ) ),
				'external_order_id' => $order_id,
				'order_number'      => sanitize_text_field( (string) ( $order['order_number'] ?? '' ) ),
				'order_label'       => $order_label,
				'issue_date'        => $issue_date,
				'display_name'      => sanitize_text_field( (string) ( $order['display_name'] ?? '' ) ),
				'customer_email'    => sanitize_email( (string) ( $order['billing_email'] ?? '' ) ),
				'currency'          => strtoupper( sanitize_text_field( (string) ( $order['currency'] ?? 'USD' ) ) ),
				'document_id'       => ! empty( $order['document_id'] ) ? absint( $order['document_id'] ) : 0,
				'balance_before'    => $balance,
				'edit_url'          => esc_url_raw( (string) ( $order['edit_url'] ?? '' ) ),
				'meta'              => array(
					'status'       => sanitize_key( (string) ( $order['status'] ?? '' ) ),
					'status_label' => sanitize_text_field( (string) ( $order['status_label'] ?? '' ) ),
				),
			);
		}

		return $items;
	}

	private function collect_historical_candidates( array $contact, $historical_end ) {
		$args = array(
			'range_to' => $historical_end,
		);

		if ( ! empty( $contact['id'] ) ) {
			$args['contact_id'] = absint( $contact['id'] );
		}
		if ( ! empty( $contact['wp_user_id'] ) ) {
			$args['wp_user_id'] = absint( $contact['wp_user_id'] );
		}
		if ( ! empty( $contact['email'] ) ) {
			$args['email'] = sanitize_email( (string) $contact['email'] );
		}

		$rows  = $this->historical_index->list_collectible_orders( $args );
		$items = array();

		foreach ( $rows as $row ) {
			$balance = round( (float) ( $row['balance'] ?? 0 ), 6 );
			if ( $balance <= 0 ) {
				continue;
			}

			$order_id    = (int) ( $row['external_order_id'] ?? 0 );
			$order_label = ! empty( $row['order_number'] ) ? 'Pedido #' . sanitize_text_field( (string) $row['order_number'] ) : ( 'Pedido #' . $order_id );
			$items[]     = array(
				'source_kind'       => 'historical_index',
				'provider'          => sanitize_key( (string) ( $row['provider'] ?? '' ) ),
				'external_order_id' => $order_id,
				'order_number'      => sanitize_text_field( (string) ( $row['order_number'] ?? '' ) ),
				'order_label'       => $order_label,
				'issue_date'        => $this->sanitize_date( $row['issue_date'] ?? '' ) ?: '',
				'display_name'      => sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
				'customer_email'    => sanitize_email( (string) ( $row['customer_email'] ?? '' ) ),
				'currency'          => strtoupper( sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) ) ),
				'document_id'       => ! empty( $row['document_id'] ) ? absint( $row['document_id'] ) : 0,
				'balance_before'    => $balance,
				'edit_url'          => $order_id > 0 ? esc_url_raw( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) : '',
				'meta'              => array(
					'index_id'               => (int) ( $row['id'] ?? 0 ),
					'fiscal_year'            => (int) ( $row['fiscal_year'] ?? 0 ),
					'source_link_id'         => ! empty( $row['source_link_id'] ) ? absint( $row['source_link_id'] ) : 0,
					'group_key'              => sanitize_text_field( (string) ( $row['group_key'] ?? '' ) ),
					'historical_indexed'     => true,
				),
			);
		}

		return $items;
	}

	private function build_plan( array $context, array $candidates ) {
		$remaining_customer = round( max( 0, (float) ( $context['amount'] ?? 0 ) ), 6 );
		$discount_fraction  = (float) ( $context['discount_fraction'] ?? 0 );
		$items              = array();
		$summary            = array(
			'requested_total'         => $remaining_customer,
			'payment_applied_total'   => 0.0,
			'discount_applied_total'  => 0.0,
			'covered_total'           => 0.0,
			'unapplied_total'         => 0.0,
			'closed_count'            => 0,
			'partial_count'           => 0,
			'item_count'              => 0,
			'has_historical_items'    => false,
			'has_current_live_items'  => false,
		);

		foreach ( $candidates as $candidate ) {
			if ( $remaining_customer <= 0 ) {
				break;
			}

			$balance = round( max( 0, (float) ( $candidate['balance_before'] ?? 0 ) ), 6 );
			if ( $balance <= 0 ) {
				continue;
			}

			$payment_applied = 0.0;
			$discount_amount = 0.0;
			$covered_total   = 0.0;

			if ( ! empty( $context['uses_dual'] ) ) {
				$calc            = $this->dual_pricing->compute_dual( $balance, $remaining_customer, $discount_fraction );
				$payment_applied = round( (float) ( $calc['net_effective'] ?? 0 ), 6 );
				$discount_amount = round( (float) ( $calc['discount'] ?? 0 ), 6 );
				$covered_total   = round( (float) ( $calc['gross_covered'] ?? 0 ), 6 );
			} else {
				$payment_applied = round( min( $remaining_customer, $balance ), 6 );
				$covered_total   = $payment_applied;
			}

			if ( $covered_total <= 0 || $payment_applied <= 0 ) {
				continue;
			}

			$remaining_customer = round( max( 0, $remaining_customer - $payment_applied ), 6 );
			$remaining_balance  = round( max( 0, $balance - $covered_total ), 6 );
			$closes_order       = $remaining_balance <= 0.00001;
			$summary['item_count']++;
			$summary['payment_applied_total']  = round( $summary['payment_applied_total'] + $payment_applied, 6 );
			$summary['discount_applied_total'] = round( $summary['discount_applied_total'] + $discount_amount, 6 );
			$summary['covered_total']          = round( $summary['covered_total'] + $covered_total, 6 );
			if ( 'historical_index' === ( $candidate['source_kind'] ?? '' ) ) {
				$summary['has_historical_items'] = true;
			} else {
				$summary['has_current_live_items'] = true;
			}
			if ( $closes_order ) {
				$summary['closed_count']++;
			} else {
				$summary['partial_count']++;
			}

			$items[] = array(
				'sequence'                   => $summary['item_count'],
				'source_kind'                => sanitize_key( (string) ( $candidate['source_kind'] ?? 'current_live' ) ),
				'provider'                   => sanitize_key( (string) ( $candidate['provider'] ?? '' ) ),
				'external_order_id'          => (int) ( $candidate['external_order_id'] ?? 0 ),
				'order_id'                   => (int) ( $candidate['external_order_id'] ?? 0 ),
				'order_number'               => sanitize_text_field( (string) ( $candidate['order_number'] ?? '' ) ),
				'order_label'                => sanitize_text_field( (string) ( $candidate['order_label'] ?? '' ) ),
				'date_created'               => sanitize_text_field( (string) ( $candidate['issue_date'] ?? '' ) ),
				'issue_date'                 => sanitize_text_field( (string) ( $candidate['issue_date'] ?? '' ) ),
				'currency'                   => sanitize_text_field( (string) ( $candidate['currency'] ?? $context['currency'] ) ),
				'document_id'                => (int) ( $candidate['document_id'] ?? 0 ),
				'document_balance'           => $balance,
				'balance_before'             => $balance,
				'payment_applied_total'      => $payment_applied,
				'customer_paid_amount'       => $payment_applied,
				'discount_applied_total'     => $discount_amount,
				'discount_amount'            => $discount_amount,
				'covered_total'              => $covered_total,
				'cover_amount'               => $covered_total,
				'remaining_document_balance' => $remaining_balance,
				'expected_balance_after'     => $remaining_balance,
				'remaining_request_total'    => $remaining_customer,
				'edit_url'                   => esc_url_raw( (string) ( $candidate['edit_url'] ?? '' ) ),
				'closes_order'               => $closes_order,
				'status_key'                 => $closes_order ? 'closed' : 'partial',
				'status_label'               => $closes_order ? 'Cerrado' : 'Parcial',
				'meta'                       => array(
					'display_name'   => sanitize_text_field( (string) ( $candidate['display_name'] ?? '' ) ),
					'customer_email' => sanitize_email( (string) ( $candidate['customer_email'] ?? '' ) ),
					'meta'           => (array) ( $candidate['meta'] ?? array() ),
				),
			);
		}

		$summary['unapplied_total'] = $remaining_customer;

		return array(
			'items'   => $items,
			'summary' => $summary,
		);
	}

	private function resolve_execution_mode( array $context, array $plan ) {
		$item_count      = (int) ( $plan['summary']['item_count'] ?? 0 );
		$has_historical  = ! empty( $plan['summary']['has_historical_items'] );
		$uses_dual       = ! empty( $context['uses_dual'] );

		if ( ! $uses_dual && ! $has_historical && $item_count <= self::FAST_PATH_MAX_ITEMS ) {
			return 'fast_path';
		}

		return 'runner';
	}

	private function resolve_batch_size( array $context, array $plan ) {
		if ( ! empty( $context['uses_dual'] ) ) {
			return self::BATCH_SIZE_DUAL;
		}

		if ( ! empty( $plan['summary']['has_historical_items'] ) ) {
			return self::BATCH_SIZE_STANDARD_MIX;
		}

		return self::BATCH_SIZE_STANDARD;
	}

	private function build_preview_signature( array $context, array $plan, $execution_mode, $batch_size ) {
		$items = array_map(
			static function ( array $item ) {
				return array(
					'source_kind'            => sanitize_key( (string) ( $item['source_kind'] ?? '' ) ),
					'provider'               => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
					'external_order_id'      => (int) ( $item['external_order_id'] ?? 0 ),
					'document_id'            => (int) ( $item['document_id'] ?? 0 ),
					'balance_before'         => round( (float) ( $item['balance_before'] ?? 0 ), 6 ),
					'cover_amount'           => round( (float) ( $item['cover_amount'] ?? 0 ), 6 ),
					'customer_paid_amount'   => round( (float) ( $item['customer_paid_amount'] ?? 0 ), 6 ),
					'discount_amount'        => round( (float) ( $item['discount_amount'] ?? 0 ), 6 ),
					'expected_balance_after' => round( (float) ( $item['expected_balance_after'] ?? 0 ), 6 ),
				);
			},
			(array) ( $plan['items'] ?? array() )
		);

		$payload = array(
			'origin'            => sanitize_key( (string) ( $context['origin'] ?? 'profile_settlement' ) ),
			'contact_id'        => (int) ( $context['contact_id'] ?? 0 ),
			'account_id'        => ! empty( $context['account_id'] ) ? (int) $context['account_id'] : 0,
			'payment_date'      => sanitize_text_field( (string) ( $context['payment_date'] ?? '' ) ),
			'currency'          => strtoupper( sanitize_text_field( (string) ( $context['currency'] ?? 'USD' ) ) ),
			'method_key'        => sanitize_key( (string) ( $context['method_key'] ?? '' ) ),
			'payment_type'      => sanitize_key( (string) ( $context['payment_type'] ?? 'collection' ) ),
			'amount'            => round( (float) ( $context['amount'] ?? 0 ), 6 ),
			'uses_dual'         => ! empty( $context['uses_dual'] ),
			'discount_percent'  => round( (float) ( $context['discount_percent'] ?? 0 ), 6 ),
			'discount_fraction' => round( (float) ( $context['discount_fraction'] ?? 0 ), 6 ),
			'execution_mode'    => sanitize_key( (string) $execution_mode ),
			'batch_size'        => (int) $batch_size,
			'summary'           => array(
				'requested_total'        => round( (float) ( $plan['summary']['requested_total'] ?? 0 ), 6 ),
				'payment_applied_total'  => round( (float) ( $plan['summary']['payment_applied_total'] ?? 0 ), 6 ),
				'discount_applied_total' => round( (float) ( $plan['summary']['discount_applied_total'] ?? 0 ), 6 ),
				'covered_total'          => round( (float) ( $plan['summary']['covered_total'] ?? 0 ), 6 ),
				'unapplied_total'        => round( (float) ( $plan['summary']['unapplied_total'] ?? 0 ), 6 ),
				'item_count'             => (int) ( $plan['summary']['item_count'] ?? 0 ),
			),
			'items'             => $items,
		);

		return hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'auth' ) );
	}

	private function extract_issue_date( $date_created ) {
		$date_created = sanitize_text_field( (string) $date_created );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $date_created, $matches ) ) {
			return $matches[0];
		}

		return '';
	}
}
