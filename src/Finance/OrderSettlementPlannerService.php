<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\DualPricingService;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class OrderSettlementPlannerService extends BaseRepository {
	const FAST_PATH_MAX_ITEMS     = 10;
	const BATCH_SIZE_STANDARD     = 12;
	const BATCH_SIZE_STANDARD_MIX = 4;
	const BATCH_SIZE_DUAL         = 1;
	const DISCOUNT_PERCENT_MATCH_TOLERANCE = 0.05;

	private $contacts;
	private $fiscal_years;
	private $historical_index;
	private $order_service;
	private $dual_pricing;
	private $payments;
	private $documents;
	private $discount_snapshot_cache = array();

	public function __construct() {
		$this->contacts         = new ContactsRepository();
		$this->fiscal_years     = new FiscalYearService();
		$this->historical_index = new CommerceOrderIndexRepository();
		$this->order_service    = new OrderSyncService();
		$this->dual_pricing     = new DualPricingService();
		$this->payments         = new PaymentsRepository();
		$this->documents        = new DocumentsRepository();
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
		$dual_status    = $this->build_dual_status(
			$context['dual_discount_mode'] ?? 'auto',
			$context['uses_dual'] ?? false,
			$context['method_key'] ?? '',
			$context['currency'] ?? 'USD',
			array(
				'active'   => ! empty( $context['discount_percent'] ) || ! empty( $context['discount_fraction'] ),
				'percent'  => (float) ( $context['discount_percent'] ?? 0 ),
				'fraction' => (float) ( $context['discount_fraction'] ?? 0 ),
			)
		);

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
			'dual_discount_mode' => sanitize_key( (string) ( $context['dual_discount_mode'] ?? 'auto' ) ),
			'force_dual_discount'=> ! empty( $context['force_dual'] ),
			'dual_status'        => $dual_status,
			'selection_mode'     => $context['selection_mode'],
			'include_credit_balance' => ! empty( $context['include_credit_balance'] ),
			'remainder_policy'   => $context['remainder_policy'],
			'discount'           => array(
				'percent'  => (float) ( $context['discount_percent'] ?? 0 ),
				'fraction' => (float) ( $context['discount_fraction'] ?? 0 ),
			),
			'rate_snapshot'      => $context['rate_snapshot'] ?? null,
			'summary'            => $plan['summary'],
			'items'              => $plan['items'],
			'eligible_items'     => $plan['eligible_items'],
			'selected_item_keys' => $plan['selected_item_keys'],
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
				'dual_discount_mode' => sanitize_key( (string) ( $context['dual_discount_mode'] ?? 'auto' ) ),
				'force_dual_discount'=> ! empty( $context['force_dual'] ),
				'selection_mode'     => $context['selection_mode'],
				'include_credit_balance' => ! empty( $context['include_credit_balance'] ),
				'remainder_policy'   => $context['remainder_policy'],
				'selected_item_keys' => $plan['selected_item_keys'],
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

		$currency               = strtoupper( sanitize_text_field( (string) ( $args['currency'] ?? 'USD' ) ) );
		$raw_method_key         = sanitize_text_field( (string) ( $args['method_key'] ?? '' ) );
		$method_key             = ( new PaymentMethodsService() )->resolve_key( $raw_method_key );
		if ( '' === $method_key ) {
			$method_key = sanitize_key( $raw_method_key );
		}
		$payment_date           = sanitize_text_field( (string) ( $args['payment_date'] ?? gmdate( 'Y-m-d' ) ) );
		$payment_type           = sanitize_key( (string) ( $args['payment_type'] ?? 'collection' ) );
		$reference              = sanitize_text_field( (string) ( $args['reference'] ?? '' ) );
		$notes                  = sanitize_textarea_field( (string) ( $args['notes'] ?? 'Abono aplicado desde el perfil del cliente.' ) );
		$discount_config        = $this->dual_pricing->get_discount_config();
		$dual_discount_mode     = $this->resolve_dual_discount_mode( $args );
		$force_dual             = 'force' === $dual_discount_mode;
		$selection_mode  = sanitize_key( (string) ( $args['selection_mode'] ?? 'oldest_first' ) );
		$remainder_policy = sanitize_key( (string) ( $args['remainder_policy'] ?? 'create_credit' ) );
		$include_credit_balance = ! empty( $args['include_credit_balance'] );
		$selected_item_keys = $this->sanitize_selected_item_keys( $args['selected_item_keys'] ?? array() );

		if ( ! in_array( $selection_mode, array( 'oldest_first', 'specific' ), true ) ) {
			$selection_mode = 'oldest_first';
		}

		if ( ! in_array( $remainder_policy, array( 'create_credit', 'discard' ), true ) ) {
			$remainder_policy = 'create_credit';
		}

		$uses_dual = $this->resolve_uses_dual(
			$dual_discount_mode,
			$method_key,
			$currency,
			$discount_config
		);

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
			'dual_discount_mode'=> $dual_discount_mode,
			'force_dual'        => $force_dual,
			'selection_mode'    => $selection_mode,
			'include_credit_balance' => $include_credit_balance,
			'remainder_policy'  => $remainder_policy,
			'selected_item_keys'=> $selected_item_keys,
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

			$currency = strtoupper( sanitize_text_field( (string) ( $order['currency'] ?? 'USD' ) ) );
			$balance  = $this->normalize_balance_amount( (float) ( $order['effective_due_total'] ?? 0 ), $currency );
			if ( $this->money_balance_is_zero( $balance, $currency ) ) {
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
				'currency'          => $currency,
				'document_id'       => ! empty( $order['document_id'] ) ? absint( $order['document_id'] ) : 0,
				'balance_before'    => $balance,
				'edit_url'          => esc_url_raw( (string) ( $order['edit_url'] ?? '' ) ),
				'discount_snapshot' => $this->normalize_discount_snapshot( (array) ( $order['discount_snapshot'] ?? array() ) ),
				'meta'              => array(
					'status'       => sanitize_key( (string) ( $order['status'] ?? '' ) ),
					'status_label' => sanitize_text_field( (string) ( $order['status_label'] ?? '' ) ),
				),
			);
			$items[ count( $items ) - 1 ]['item_key'] = $this->build_item_key( $items[ count( $items ) - 1 ] );
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
			$currency = strtoupper( sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) ) );
			$balance  = $this->normalize_balance_amount( (float) ( $row['balance'] ?? 0 ), $currency );
			if ( $this->money_balance_is_zero( $balance, $currency ) ) {
				continue;
			}

			$row_meta     = $this->decode_meta_json( $row['meta_json'] ?? array() );
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
				'currency'          => $currency,
				'document_id'       => ! empty( $row['document_id'] ) ? absint( $row['document_id'] ) : 0,
				'balance_before'    => $balance,
				'edit_url'          => $order_id > 0 ? esc_url_raw( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) : '',
				'discount_snapshot' => $this->normalize_discount_snapshot( (array) ( $row_meta['discount_snapshot'] ?? array() ) ),
				'meta'              => array(
					'index_id'               => (int) ( $row['id'] ?? 0 ),
					'fiscal_year'            => (int) ( $row['fiscal_year'] ?? 0 ),
					'source_link_id'         => ! empty( $row['source_link_id'] ) ? absint( $row['source_link_id'] ) : 0,
					'group_key'              => sanitize_text_field( (string) ( $row['group_key'] ?? '' ) ),
					'historical_indexed'     => true,
					'meta_json'              => $row_meta,
				),
			);
			$items[ count( $items ) - 1 ]['item_key'] = $this->build_item_key( $items[ count( $items ) - 1 ] );
		}

		return $items;
	}

	private function build_plan( array $context, array $candidates ) {
		$requested_total       = round( max( 0, (float) ( $context['amount'] ?? 0 ) ), 6 );
		$eligible_items        = $this->decorate_candidates_with_discount_detection( array_values( $candidates ), $context );
		$selected_keys         = array_values( (array) ( $context['selected_item_keys'] ?? array() ) );
		$selection_mode        = sanitize_key( (string) ( $context['selection_mode'] ?? 'oldest_first' ) );
		$credit_available_total = ! empty( $context['include_credit_balance'] )
			? $this->resolve_credit_available_total( $context )
			: 0.0;
		$summary               = array(
			'requested_total'              => $requested_total,
			'cash_total'                   => $requested_total,
			'credit_applied_total'         => 0.0,
			'credit_available_total'       => $credit_available_total,
			'total_available'              => $requested_total,
			'payment_applied_total'        => 0.0,
			'payment_recorded_total'       => $requested_total,
			'discount_applied_total'       => 0.0,
			'covered_total'                => 0.0,
			'unapplied_total'              => 0.0,
			'remainder_total'              => 0.0,
			'remainder_action_required'    => false,
			'remainder_consumed_oldest_first' => false,
			'closed_count'                 => 0,
			'partial_count'                => 0,
			'item_count'                   => 0,
			'selected_count'               => 0,
			'selected_total'               => 0.0,
			'has_historical_items'         => false,
			'has_current_live_items'       => false,
		);
		$items                 = array();
		$remaining_cash_total  = $requested_total;
		$remaining_credit_total = $credit_available_total;

		if ( 'specific' === $selection_mode ) {
			if ( empty( $selected_keys ) ) {
				$selected_keys = array_values(
					array_filter(
						array_map(
							static function ( array $candidate ) {
								return (string) ( $candidate['item_key'] ?? '' );
							},
							$eligible_items
						)
					)
				);
			}

			$selected_map        = array_fill_keys( $selected_keys, true );
			$selected_candidates = array();
			$fallback_candidates = array();

			foreach ( $eligible_items as $candidate ) {
				$item_key = (string) ( $candidate['item_key'] ?? '' );
				if ( '' !== $item_key && isset( $selected_map[ $item_key ] ) ) {
					$selected_candidates[] = $candidate;
					$summary['selected_count']++;
					$summary['selected_total'] = round( $summary['selected_total'] + (float) ( $candidate['balance_before'] ?? 0 ), 6 );
					continue;
				}

				$fallback_candidates[] = $candidate;
			}

			$this->append_candidates_to_plan( $selected_candidates, $context, $summary, $items, $remaining_cash_total, $remaining_credit_total, 'selected' );

			if ( ( $remaining_cash_total > 0 || $remaining_credit_total > 0 ) && ! empty( $fallback_candidates ) ) {
				$this->append_candidates_to_plan( $fallback_candidates, $context, $summary, $items, $remaining_cash_total, $remaining_credit_total, 'auto_remainder' );
				$summary['remainder_consumed_oldest_first'] = true;
			}

			if ( $remaining_cash_total > 0 ) {
				$summary['remainder_action_required'] = true;
			}
		} else {
			$this->append_candidates_to_plan( $eligible_items, $context, $summary, $items, $remaining_cash_total, $remaining_credit_total, 'oldest_first' );
		}

		$summary['credit_applied_total'] = round( max( 0, $credit_available_total - $remaining_credit_total ), 6 );
		$summary['total_available']      = round( $requested_total + $summary['credit_applied_total'], 6 );
		$summary['unapplied_total']      = round( $remaining_cash_total, 6 );
		$summary['remainder_total']      = round( $remaining_cash_total, 6 );

		if ( 'specific' === $selection_mode && 'discard' === ( $context['remainder_policy'] ?? 'create_credit' ) ) {
			$summary['payment_recorded_total'] = round( max( 0, $requested_total - $remaining_cash_total ), 6 );
		}

		return array(
			'items'              => $items,
			'eligible_items'     => $eligible_items,
			'selected_item_keys' => $selected_keys,
			'summary'            => $summary,
		);
	}

	private function append_candidates_to_plan( array $candidates, array $context, array &$summary, array &$items, float &$remaining_cash_total, float &$remaining_credit_total, $selection_origin ) {
		$discount_fraction = (float) ( $context['discount_fraction'] ?? 0 );

		foreach ( $candidates as $candidate ) {
			if ( $remaining_cash_total <= 0 && $remaining_credit_total <= 0 ) {
				break;
			}

			$item_currency = sanitize_text_field( (string) ( $candidate['currency'] ?? $context['currency'] ?? 'USD' ) );
			$balance       = $this->normalize_balance_amount( (float) ( $candidate['balance_before'] ?? 0 ), $item_currency );
			if ( $this->money_balance_is_zero( $balance, $item_currency ) ) {
				continue;
			}

			$payment_applied = 0.0;
			$discount_amount = 0.0;
			$credit_applied  = 0.0;
			$covered_total   = 0.0;
			$discount_detection = (array) ( $candidate['discount_detection'] ?? $this->resolve_discount_detection( $candidate, $context ) );
			$discount_status    = sanitize_key( (string) ( $discount_detection['status'] ?? 'none' ) );
			$already_discounted = ! empty( $discount_detection['already_discounted'] );
			$can_apply_dual     = ! empty( $context['uses_dual'] ) && ! in_array( $discount_status, array( 'same_dual', 'different' ), true );

			if ( $remaining_cash_total > 0 ) {
				if ( $can_apply_dual ) {
					$calc            = $this->dual_pricing->compute_dual( $balance, $remaining_cash_total, $discount_fraction );
					$payment_applied = round( (float) ( $calc['net_effective'] ?? 0 ), 6 );
					$discount_amount = round( (float) ( $calc['discount'] ?? 0 ), 6 );
					$covered_total   = round( (float) ( $calc['gross_covered'] ?? 0 ), 6 );
				} else {
					$payment_applied = round( min( $remaining_cash_total, $balance ), 6 );
					$covered_total   = $payment_applied;
				}
			}

			if ( $payment_applied > 0 ) {
				$remaining_cash_total = round( max( 0, $remaining_cash_total - $payment_applied ), 6 );
			}

			$remaining_balance_after_cash = round( max( 0, $balance - $covered_total ), 6 );

			if ( $remaining_balance_after_cash > 0 && $remaining_credit_total > 0 ) {
				$credit_applied        = round( min( $remaining_credit_total, $remaining_balance_after_cash ), 6 );
				$remaining_credit_total = round( max( 0, $remaining_credit_total - $credit_applied ), 6 );
				$covered_total         = round( $covered_total + $credit_applied, 6 );
			}

			if ( $covered_total <= 0 ) {
				continue;
			}

			$remaining_balance = $this->normalize_balance_amount( $balance - $covered_total, $item_currency );
			$closes_order      = $this->money_balance_is_zero( $remaining_balance, $item_currency );
			$summary['item_count']++;
			$summary['payment_applied_total']  = round( $summary['payment_applied_total'] + $payment_applied, 6 );
			$summary['discount_applied_total'] = round( $summary['discount_applied_total'] + $discount_amount, 6 );
			$summary['credit_applied_total']   = round( $summary['credit_applied_total'] + $credit_applied, 6 );
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
				'item_key'                   => sanitize_text_field( (string) ( $candidate['item_key'] ?? '' ) ),
				'selection_origin'           => sanitize_key( (string) $selection_origin ),
				'source_kind'                => sanitize_key( (string) ( $candidate['source_kind'] ?? 'current_live' ) ),
				'provider'                   => sanitize_key( (string) ( $candidate['provider'] ?? '' ) ),
				'external_order_id'          => (int) ( $candidate['external_order_id'] ?? 0 ),
				'order_id'                   => (int) ( $candidate['external_order_id'] ?? 0 ),
				'order_number'               => sanitize_text_field( (string) ( $candidate['order_number'] ?? '' ) ),
				'order_label'                => sanitize_text_field( (string) ( $candidate['order_label'] ?? '' ) ),
				'date_created'               => sanitize_text_field( (string) ( $candidate['issue_date'] ?? '' ) ),
				'issue_date'                 => sanitize_text_field( (string) ( $candidate['issue_date'] ?? '' ) ),
				'currency'                   => $item_currency,
				'document_id'                => (int) ( $candidate['document_id'] ?? 0 ),
				'document_balance'           => $balance,
				'balance_before'             => $balance,
				'payment_applied_total'      => $payment_applied,
				'customer_paid_amount'       => $payment_applied,
				'credit_applied_amount'      => $credit_applied,
				'discount_effective_amount'  => $discount_amount,
				'discount_applied_total'     => $discount_amount,
				'discount_amount'            => $discount_amount,
				'already_discounted'         => $already_discounted,
				'discount_detection'         => $discount_detection,
				'covered_total'              => $covered_total,
				'cover_amount'               => $covered_total,
				'remaining_document_balance' => $remaining_balance,
				'expected_balance_after'     => $remaining_balance,
				'remaining_request_total'    => $remaining_cash_total,
				'edit_url'                   => esc_url_raw( (string) ( $candidate['edit_url'] ?? '' ) ),
				'closes_order'               => $closes_order,
				'status_key'                 => $closes_order ? 'closed' : 'partial',
				'status_label'               => $closes_order ? 'Pagado' : 'Abonado',
				'meta'                       => array(
					'display_name'   => sanitize_text_field( (string) ( $candidate['display_name'] ?? '' ) ),
					'customer_email' => sanitize_email( (string) ( $candidate['customer_email'] ?? '' ) ),
					'discount_snapshot' => $this->normalize_discount_snapshot( (array) ( $candidate['discount_snapshot'] ?? array() ) ),
					'discount_detection' => $discount_detection,
					'meta'           => (array) ( $candidate['meta'] ?? array() ),
				),
			);
		}
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
						'status'           => sanitize_key( (string) ( $item['discount_detection']['status'] ?? 'none' ) ),
						'detected_percent' => round( (float) ( $item['discount_detection']['detected_percent'] ?? 0 ), 6 ),
					),
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
			'dual_discount_mode'=> sanitize_key( (string) ( $context['dual_discount_mode'] ?? 'auto' ) ),
			'force_dual_discount' => ! empty( $context['force_dual'] ),
			'selection_mode'    => sanitize_key( (string) ( $context['selection_mode'] ?? 'oldest_first' ) ),
			'include_credit_balance' => ! empty( $context['include_credit_balance'] ),
			'remainder_policy'  => sanitize_key( (string) ( $context['remainder_policy'] ?? 'create_credit' ) ),
			'selected_item_keys'=> array_values( (array) ( $context['selected_item_keys'] ?? array() ) ),
			'discount_percent'  => round( (float) ( $context['discount_percent'] ?? 0 ), 6 ),
			'discount_fraction' => round( (float) ( $context['discount_fraction'] ?? 0 ), 6 ),
			'execution_mode'    => sanitize_key( (string) $execution_mode ),
			'batch_size'        => (int) $batch_size,
			'summary'           => array(
				'cash_total'              => round( (float) ( $plan['summary']['cash_total'] ?? 0 ), 6 ),
				'credit_applied_total'    => round( (float) ( $plan['summary']['credit_applied_total'] ?? 0 ), 6 ),
				'credit_available_total'  => round( (float) ( $plan['summary']['credit_available_total'] ?? 0 ), 6 ),
				'total_available'         => round( (float) ( $plan['summary']['total_available'] ?? 0 ), 6 ),
				'requested_total'        => round( (float) ( $plan['summary']['requested_total'] ?? 0 ), 6 ),
				'payment_recorded_total' => round( (float) ( $plan['summary']['payment_recorded_total'] ?? 0 ), 6 ),
				'payment_applied_total'  => round( (float) ( $plan['summary']['payment_applied_total'] ?? 0 ), 6 ),
				'discount_applied_total' => round( (float) ( $plan['summary']['discount_applied_total'] ?? 0 ), 6 ),
				'covered_total'          => round( (float) ( $plan['summary']['covered_total'] ?? 0 ), 6 ),
				'unapplied_total'        => round( (float) ( $plan['summary']['unapplied_total'] ?? 0 ), 6 ),
				'remainder_total'        => round( (float) ( $plan['summary']['remainder_total'] ?? 0 ), 6 ),
				'remainder_action_required' => ! empty( $plan['summary']['remainder_action_required'] ),
				'remainder_consumed_oldest_first' => ! empty( $plan['summary']['remainder_consumed_oldest_first'] ),
				'item_count'             => (int) ( $plan['summary']['item_count'] ?? 0 ),
				'selected_count'         => (int) ( $plan['summary']['selected_count'] ?? 0 ),
			),
			'items'             => $items,
		);

		return hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'auth' ) );
	}

	private function build_item_key( array $candidate ) {
		return implode(
			':',
			array(
				sanitize_key( (string) ( $candidate['source_kind'] ?? 'current_live' ) ),
				sanitize_key( (string) ( $candidate['provider'] ?? '' ) ),
				(int) ( $candidate['external_order_id'] ?? 0 ),
				(int) ( $candidate['document_id'] ?? 0 ),
			)
		);
	}

	private function decorate_candidates_with_discount_detection( array $candidates, array $context ) {
		$decorated = array();

		foreach ( $candidates as $candidate ) {
			$candidate['discount_snapshot']   = $this->ensure_candidate_discount_snapshot( $candidate );
			$candidate['discount_detection']  = $this->resolve_discount_detection( $candidate, $context );
			$decorated[] = $candidate;
		}

		return $decorated;
	}

	private function sanitize_selected_item_keys( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array_filter(
				array_map(
					'trim',
					explode( ',', (string) $raw )
				)
			);
		}

		$keys = array_map(
			static function ( $value ) {
				return sanitize_text_field( (string) $value );
			},
			(array) $raw
		);

		$keys = array_values(
			array_unique(
				array_filter(
					$keys,
					static function ( $value ) {
						return '' !== $value;
					}
				)
			)
		);

		return $keys;
	}

	private function decode_meta_json( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function normalize_discount_snapshot( array $raw ) {
		return array(
			'known'             => array_key_exists( 'known', $raw ) ? ! empty( $raw['known'] ) : false,
			'discount_total'    => round( max( 0, (float) ( $raw['discount_total'] ?? 0 ) ), 6 ),
			'discount_percent'  => round( max( 0, (float) ( $raw['discount_percent'] ?? 0 ) ), 4 ),
			'discount_subtotal' => round( max( 0, (float) ( $raw['discount_subtotal'] ?? 0 ) ), 6 ),
			'source'            => sanitize_key( (string) ( $raw['source'] ?? '' ) ),
		);
	}

	private function ensure_candidate_discount_snapshot( array $candidate ) {
		$snapshot = $this->normalize_discount_snapshot( (array) ( $candidate['discount_snapshot'] ?? array() ) );
		if ( ! empty( $snapshot['known'] ) ) {
			return $snapshot;
		}

		$order_id = (int) ( $candidate['external_order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return $snapshot;
		}

		$cache_key = sanitize_key( (string) ( $candidate['provider'] ?? 'woocommerce' ) ) . ':' . $order_id;
		if ( isset( $this->discount_snapshot_cache[ $cache_key ] ) ) {
			return $this->discount_snapshot_cache[ $cache_key ];
		}

		$resolved = $this->normalize_discount_snapshot( (array) $this->order_service->get_order_discount_snapshot( $order_id ) );
		$this->discount_snapshot_cache[ $cache_key ] = $resolved;

		return $resolved;
	}

	private function resolve_dual_discount_mode( array $args ) {
		$mode = sanitize_key( (string) ( $args['dual_discount_mode'] ?? '' ) );

		if ( in_array( $mode, array( 'off', 'auto', 'force' ), true ) ) {
			return $mode;
		}

		if ( array_key_exists( 'force_dual_discount', $args ) ) {
			return ! empty( $args['force_dual_discount'] ) ? 'force' : 'off';
		}

		return 'auto';
	}

	private function resolve_uses_dual( $mode, $method_key, $currency, array $discount_config ) {
		$mode     = sanitize_key( (string) $mode );
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );

		if ( 'off' === $mode ) {
			return false;
		}

		if ( 'force' === $mode ) {
			return ! empty( $discount_config['active'] ) && 'USD' === $currency;
		}

		return $this->dual_pricing->qualifies_for_dual_discount( $method_key, $currency );
	}

	private function build_dual_status( $mode, $uses_dual, $method_key, $currency, array $discount_config ) {
		$mode     = sanitize_key( (string) $mode );
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );

		if ( 'off' === $mode ) {
			return array(
				'key'   => 'off',
				'label' => 'Descuento automatico apagado',
			);
		}

		if ( empty( $discount_config['active'] ) || (float) ( $discount_config['fraction'] ?? 0 ) <= 0 ) {
			return array(
				'key'   => 'global_off',
				'label' => 'El descuento general esta apagado',
			);
		}

		if ( 'USD' !== $currency ) {
			return array(
				'key'   => 'currency',
				'label' => 'La moneda registrada no es USD',
			);
		}

		if ( 'force' === $mode ) {
			return array(
				'key'   => 'force',
				'label' => 'Precio dual forzado',
			);
		}

		if ( ! $uses_dual ) {
			return array(
				'key'   => 'method',
				'label' => 'El metodo no califica para precio dual',
			);
		}

		return array(
			'key'   => 'active',
			'label' => 'Precio dual activo',
		);
	}

	private function resolve_discount_detection( array $candidate, array $context ) {
		$snapshot         = $this->ensure_candidate_discount_snapshot( $candidate );
		$detected_total   = (float) ( $snapshot['discount_total'] ?? 0 );
		$detected_percent = round( max( 0, (float) ( $snapshot['discount_percent'] ?? 0 ) ), 4 );
		$expected_percent = round( max( 0, (float) ( $context['discount_percent'] ?? 0 ) ), 4 );

		if ( empty( $snapshot['known'] ) || ( $detected_total <= 0.00001 && $detected_percent <= 0.00001 ) ) {
			return array(
				'status'             => 'none',
				'label'              => 'Sin descuento previo',
				'detected_percent'   => 0.0,
				'already_discounted' => false,
			);
		}

		if ( $expected_percent > 0 && abs( $detected_percent - $expected_percent ) <= self::DISCOUNT_PERCENT_MATCH_TOLERANCE ) {
			return array(
				'status'             => 'same_dual',
				'label'              => 'Descuento dual ya aplicado',
				'detected_percent'   => $detected_percent,
				'already_discounted' => true,
			);
		}

		return array(
			'status'             => 'different',
			'label'              => 'Descuento previo distinto',
			'detected_percent'   => $detected_percent,
			'already_discounted' => true,
		);
	}

	private function resolve_credit_available_total( array $context ) {
		$contact_id = absint( $context['contact_id'] ?? 0 );
		if ( $contact_id <= 0 ) {
			return 0.0;
		}

		$currency         = strtoupper( sanitize_text_field( (string) ( $context['currency'] ?? 'USD' ) ) );
		$credit_payments  = $this->get_credit_payments( $contact_id, $currency );
		$payable_documents = $this->get_payable_documents( $contact_id, $currency );

		return round( $this->sum_available_payments( $credit_payments ) + $this->sum_document_balances( $payable_documents ), 6 );
	}

	private function get_credit_payments( $contact_id, $currency = '' ) {
		$payments = array_filter(
			(array) $this->payments->for_contact( $contact_id, 200 ),
			static function ( array $payment ) use ( $currency ) {
				$payment_currency = strtoupper( sanitize_text_field( (string) ( $payment['currency'] ?? 'USD' ) ) );
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
				$left_ts  = ! empty( $left['payment_date'] ) ? strtotime( $left['payment_date'] ) : 0;
				$right_ts = ! empty( $right['payment_date'] ) ? strtotime( $right['payment_date'] ) : 0;

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
				$left_ts  = ! empty( $left['issue_date'] ) ? strtotime( $left['issue_date'] ) : 0;
				$right_ts = ! empty( $right['issue_date'] ) ? strtotime( $right['issue_date'] ) : 0;

				if ( $left_ts === $right_ts ) {
					return (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 );
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

	private function extract_issue_date( $date_created ) {
		$date_created = sanitize_text_field( (string) $date_created );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $date_created, $matches ) ) {
			return $matches[0];
		}

		return '';
	}
}
