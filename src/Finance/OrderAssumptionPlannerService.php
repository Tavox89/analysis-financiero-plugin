<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class OrderAssumptionPlannerService extends BaseRepository {
	const BATCH_SIZE_CURRENT    = 4;
	const BATCH_SIZE_HISTORICAL = 2;

	private $contacts;
	private $fiscal_years;
	private $historical_index;
	private $order_service;
	private $documents;
	private $allocations;

	public function __construct() {
		$this->contacts         = new ContactsRepository();
		$this->fiscal_years     = new FiscalYearService();
		$this->historical_index = new CommerceOrderIndexRepository();
		$this->order_service    = new OrderSyncService();
		$this->documents        = new DocumentsRepository();
		$this->allocations      = new PaymentAllocationsRepository();
	}

	public function preview( array $args, $origin = 'profile_order_assumption' ) {
		$context = $this->normalize_context( $args, $origin );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$candidates = $this->collect_candidates( $context );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$plan        = $this->build_plan( $context, $candidates );
		$batch_size  = $this->resolve_batch_size( $plan );
		$signature   = $this->build_preview_signature( $context, $plan, $batch_size );
		$contact     = (array) ( $context['contact'] ?? array() );
		$mode_label  = 'gift' === $context['mode'] ? 'Regalo' : 'Gasto';

		return array(
			'requires_preview'             => true,
			'origin'                       => sanitize_key( (string) $origin ),
			'mode'                         => sanitize_key( (string) $context['mode'] ),
			'mode_label'                   => $mode_label,
			'execution_mode'               => 'runner',
			'contact_id'                   => (int) $context['contact_id'],
			'contact_label'                => sanitize_text_field( (string) ( $contact['display_name'] ?? 'Perfil' ) ),
			'internal_use_profile'         => ! empty( $contact['internal_use_profile'] ),
			'requires_profile_confirmation'=> ! empty( $context['requires_profile_confirmation'] ),
			'current_fiscal_label'         => sanitize_text_field( (string) ( $context['fiscal_context']['label'] ?? '' ) ),
			'summary'                      => $plan['summary'],
			'items'                        => $plan['items'],
			'blocked_items'                => $plan['blocked_items'],
			'totals'                       => $plan['summary'],
			'thresholds'                   => array(
				'batch_size' => $batch_size,
			),
			'preview_signature'            => $signature,
			'batch_payload'                => array(
				'contact_id'                => (int) $context['contact_id'],
				'origin'                    => sanitize_key( (string) $origin ),
				'mode'                      => sanitize_key( (string) $context['mode'] ),
				'note'                      => sanitize_textarea_field( (string) $context['note'] ),
				'approved_by_label'         => sanitize_text_field( (string) ( $context['approved_by_label'] ?? '' ) ),
				'internal_use_profile'      => ! empty( $contact['internal_use_profile'] ),
				'confirm_non_internal'      => ! empty( $context['confirm_non_internal'] ),
				'batch_size'                => $batch_size,
			),
		);
	}

	private function normalize_context( array $args, $origin ) {
		$contact_id = absint( $args['contact_id'] ?? 0 );
		$mode       = sanitize_key( (string) ( $args['mode'] ?? 'expense' ) );
		$note       = sanitize_textarea_field( (string) ( $args['note'] ?? '' ) );

		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_order_assumption_contact', 'Debes indicar el perfil del que se asumiran los pedidos.' );
		}

		if ( ! in_array( $mode, array( 'expense', 'gift' ), true ) ) {
			return $this->error( 'asdl_fin_order_assumption_mode', 'Debes indicar si los pedidos se asumiran como gasto o como regalo.' );
		}

		if ( '' === $note ) {
			return $this->error( 'asdl_fin_order_assumption_note', 'Debes escribir una nota operativa para asumir estos pedidos.' );
		}

		$approved_by_label = sanitize_text_field( (string) ( $args['approved_by_label'] ?? '' ) );
		if ( 'gift' === $mode && '' === $approved_by_label ) {
			return $this->error( 'asdl_fin_order_assumption_gift_approval', 'Debes indicar quien aprobo el regalo para poder asumir estos pedidos.' );
		}

		$contact = $this->contacts->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_order_assumption_contact', 'No encontramos el perfil seleccionado para asumir pedidos.' );
		}

		$requires_profile_confirmation = empty( $contact['internal_use_profile'] );
		$confirm_non_internal          = ! empty( $args['confirm_non_internal'] );
		if ( $requires_profile_confirmation && ! $confirm_non_internal ) {
			return array(
				'contact_id'                  => $contact_id,
				'contact'                     => $contact,
				'origin'                      => sanitize_key( (string) $origin ),
				'mode'                        => $mode,
				'note'                        => $note,
				'approved_by_label'           => $approved_by_label,
				'confirm_non_internal'        => false,
				'requires_profile_confirmation' => true,
				'fiscal_context'              => $this->fiscal_years->get_context(),
			);
		}

		return array(
			'contact_id'                  => $contact_id,
			'contact'                     => $contact,
			'origin'                      => sanitize_key( (string) $origin ),
			'mode'                        => $mode,
			'note'                        => $note,
			'approved_by_label'           => $approved_by_label,
			'confirm_non_internal'        => $confirm_non_internal,
			'requires_profile_confirmation' => $requires_profile_confirmation,
			'fiscal_context'              => $this->fiscal_years->get_context(),
		);
	}

	private function collect_candidates( array $context ) {
		$contact      = (array) ( $context['contact'] ?? array() );
		$fiscal       = (array) ( $context['fiscal_context'] ?? array() );
		$active_start = $this->sanitize_date( $fiscal['start_date'] ?? '' );
		$active_end   = $this->sanitize_date( $fiscal['end_date'] ?? '' );

		if ( empty( $active_start ) || empty( $active_end ) ) {
			return $this->error( 'asdl_fin_order_assumption_fiscal', 'No pudimos resolver el ejercicio fiscal activo para asumir estos pedidos.' );
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
			$fiscal_year = $issue_date ? (int) $this->fiscal_years->resolve_start_year_from_date( $issue_date ) : (int) gmdate( 'Y' );
			$order_label = ! empty( $order['order_number'] ) ? 'Pedido #' . sanitize_text_field( (string) $order['order_number'] ) : ( 'Pedido #' . $order_id );

			$items[] = array(
				'source_kind'       => 'current_live',
				'provider'          => sanitize_key( (string) ( $order['provider'] ?? '' ) ),
				'external_order_id' => $order_id,
				'order_number'      => sanitize_text_field( (string) ( $order['order_number'] ?? '' ) ),
				'order_label'       => $order_label,
				'issue_date'        => $issue_date,
				'fiscal_year'       => $fiscal_year,
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
				'fiscal_year'       => (int) ( $row['fiscal_year'] ?? 0 ),
				'display_name'      => sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
				'customer_email'    => sanitize_email( (string) ( $row['customer_email'] ?? '' ) ),
				'currency'          => strtoupper( sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) ) ),
				'document_id'       => ! empty( $row['document_id'] ) ? absint( $row['document_id'] ) : 0,
				'balance_before'    => $balance,
				'edit_url'          => $order_id > 0 ? esc_url_raw( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) : '',
				'meta'              => array(
					'index_id'           => (int) ( $row['id'] ?? 0 ),
					'source_link_id'     => ! empty( $row['source_link_id'] ) ? absint( $row['source_link_id'] ) : 0,
					'group_key'          => sanitize_text_field( (string) ( $row['group_key'] ?? '' ) ),
					'historical_indexed' => true,
				),
			);
		}

		return $items;
	}

	private function build_plan( array $context, array $candidates ) {
		$items        = array();
		$blocked      = array();
		$summary      = array(
			'eligible_count'      => 0,
			'blocked_count'       => 0,
			'assumed_total'       => 0.0,
			'current_total'       => 0.0,
			'historical_total'    => 0.0,
			'current_count'       => 0,
			'historical_count'    => 0,
			'has_historical_items'=> false,
			'has_current_items'   => false,
		);

		foreach ( $candidates as $candidate ) {
			$evaluation = $this->evaluate_candidate( $candidate );

			$item = array(
				'item_key'          => $this->build_item_key( $candidate ),
				'sequence'          => 0,
				'source_kind'       => sanitize_key( (string) ( $candidate['source_kind'] ?? 'current_live' ) ),
				'provider'          => sanitize_key( (string) ( $candidate['provider'] ?? '' ) ),
				'external_order_id' => (int) ( $candidate['external_order_id'] ?? 0 ),
				'order_number'      => sanitize_text_field( (string) ( $candidate['order_number'] ?? '' ) ),
				'order_label'       => sanitize_text_field( (string) ( $candidate['order_label'] ?? '' ) ),
				'issue_date'        => sanitize_text_field( (string) ( $candidate['issue_date'] ?? '' ) ),
				'date_created'      => sanitize_text_field( (string) ( $candidate['issue_date'] ?? '' ) ),
				'fiscal_year'       => (int) ( $candidate['fiscal_year'] ?? 0 ),
				'document_id'       => (int) ( $candidate['document_id'] ?? 0 ),
				'balance_before'    => round( (float) ( $candidate['balance_before'] ?? 0 ), 6 ),
				'currency'          => strtoupper( sanitize_text_field( (string) ( $candidate['currency'] ?? 'USD' ) ) ),
				'edit_url'          => esc_url_raw( (string) ( $candidate['edit_url'] ?? '' ) ),
				'status_key'        => ! empty( $evaluation['eligible'] ) ? 'eligible' : 'blocked',
				'status_label'      => ! empty( $evaluation['eligible'] ) ? 'Elegible' : 'Bloqueado',
				'blocked_reason'    => sanitize_text_field( (string) ( $evaluation['reason'] ?? '' ) ),
				'meta'              => array(
					'display_name'          => sanitize_text_field( (string) ( $candidate['display_name'] ?? '' ) ),
					'customer_email'        => sanitize_email( (string) ( $candidate['customer_email'] ?? '' ) ),
					'document_id'           => (int) ( $evaluation['document_id'] ?? ( $candidate['document_id'] ?? 0 ) ),
					'document_paid_total'   => round( (float) ( $evaluation['document_paid_total'] ?? 0 ), 6 ),
					'allocations_count'     => (int) ( $evaluation['allocations_count'] ?? 0 ),
					'index_id'              => (int) ( $candidate['meta']['index_id'] ?? 0 ),
					'source_link_id'        => (int) ( $candidate['meta']['source_link_id'] ?? 0 ),
					'group_key'             => sanitize_text_field( (string) ( $candidate['meta']['group_key'] ?? '' ) ),
				),
			);

			if ( ! empty( $evaluation['eligible'] ) ) {
				$summary['eligible_count']++;
				$item['sequence'] = $summary['eligible_count'];
				$summary['assumed_total'] = round( $summary['assumed_total'] + (float) $item['balance_before'], 6 );

				if ( 'historical_index' === $item['source_kind'] ) {
					$summary['historical_total'] = round( $summary['historical_total'] + (float) $item['balance_before'], 6 );
					$summary['historical_count']++;
					$summary['has_historical_items'] = true;
				} else {
					$summary['current_total'] = round( $summary['current_total'] + (float) $item['balance_before'], 6 );
					$summary['current_count']++;
					$summary['has_current_items'] = true;
				}

				$items[] = $item;
				continue;
			}

			$summary['blocked_count']++;
			$blocked[] = $item;
		}

		return array(
			'items'         => $items,
			'blocked_items' => $blocked,
			'summary'       => $summary,
		);
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

	private function evaluate_candidate( array $candidate ) {
		$document_id = (int) ( $candidate['document_id'] ?? 0 );
		$document    = $document_id > 0 ? $this->documents->find( $document_id ) : null;

		if ( ! empty( $document['id'] ) ) {
			$allocations_count = (int) $this->allocations->count_for_document( (int) $document['id'] );
			$paid_total        = round( (float) ( $document['paid_total'] ?? 0 ), 6 );

			if ( 'internal_consumption' === sanitize_key( (string) ( $document['financial_intent'] ?? '' ) ) ) {
				return array(
					'eligible'          => false,
					'document_id'       => (int) $document['id'],
					'document_paid_total'=> $paid_total,
					'allocations_count' => $allocations_count,
					'reason'            => 'Este pedido ya fue asumido internamente y no debe volver a procesarse.',
				);
			}

			if ( $allocations_count > 0 || $paid_total > 0 ) {
				return array(
					'eligible'          => false,
					'document_id'       => (int) $document['id'],
					'document_paid_total'=> $paid_total,
					'allocations_count' => $allocations_count,
					'reason'            => 'Este pedido ya tiene pagos o abonos reales aplicados y no puede asumirse como gasto.',
				);
			}
		}

		return array(
			'eligible'           => true,
			'document_id'        => (int) ( $document['id'] ?? $document_id ),
			'document_paid_total'=> round( (float) ( $document['paid_total'] ?? 0 ), 6 ),
			'allocations_count'  => ! empty( $document['id'] ) ? (int) $this->allocations->count_for_document( (int) $document['id'] ) : 0,
			'reason'             => '',
		);
	}

	private function resolve_batch_size( array $plan ) {
		if ( ! empty( $plan['summary']['has_historical_items'] ) ) {
			return self::BATCH_SIZE_HISTORICAL;
		}

		return self::BATCH_SIZE_CURRENT;
	}

	private function build_preview_signature( array $context, array $plan, $batch_size ) {
		$items = array_map(
			static function ( array $item ) {
				return array(
					'source_kind'       => sanitize_key( (string) ( $item['source_kind'] ?? '' ) ),
					'provider'          => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
					'external_order_id' => (int) ( $item['external_order_id'] ?? 0 ),
					'document_id'       => (int) ( $item['document_id'] ?? 0 ),
					'balance_before'    => round( (float) ( $item['balance_before'] ?? 0 ), 6 ),
					'fiscal_year'       => (int) ( $item['fiscal_year'] ?? 0 ),
				);
			},
			(array) ( $plan['items'] ?? array() )
		);

		$blocked = array_map(
			static function ( array $item ) {
				return array(
					'source_kind'       => sanitize_key( (string) ( $item['source_kind'] ?? '' ) ),
					'provider'          => sanitize_key( (string) ( $item['provider'] ?? '' ) ),
					'external_order_id' => (int) ( $item['external_order_id'] ?? 0 ),
					'document_id'       => (int) ( $item['document_id'] ?? 0 ),
					'blocked_reason'    => sanitize_text_field( (string) ( $item['blocked_reason'] ?? '' ) ),
				);
			},
			(array) ( $plan['blocked_items'] ?? array() )
		);

		$payload = array(
			'origin'               => sanitize_key( (string) ( $context['origin'] ?? 'profile_order_assumption' ) ),
			'contact_id'           => (int) ( $context['contact_id'] ?? 0 ),
			'mode'                 => sanitize_key( (string) ( $context['mode'] ?? 'expense' ) ),
			'note'                 => sanitize_textarea_field( (string) ( $context['note'] ?? '' ) ),
			'approved_by_label'    => sanitize_text_field( (string) ( $context['approved_by_label'] ?? '' ) ),
			'confirm_non_internal' => ! empty( $context['confirm_non_internal'] ),
			'batch_size'           => (int) $batch_size,
			'summary'              => array(
				'eligible_count'   => (int) ( $plan['summary']['eligible_count'] ?? 0 ),
				'blocked_count'    => (int) ( $plan['summary']['blocked_count'] ?? 0 ),
				'assumed_total'    => round( (float) ( $plan['summary']['assumed_total'] ?? 0 ), 6 ),
				'current_total'    => round( (float) ( $plan['summary']['current_total'] ?? 0 ), 6 ),
				'historical_total' => round( (float) ( $plan['summary']['historical_total'] ?? 0 ), 6 ),
			),
			'items'                => $items,
			'blocked'              => $blocked,
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
