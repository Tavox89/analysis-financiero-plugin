<?php

namespace ASDLabs\Finance\Mobile;

use ASDLabs\Finance\Finance\FiscalYearService;
use ASDLabs\Finance\Finance\OverviewService;
use ASDLabs\Finance\Finance\PendingCollectionsService;
use ASDLabs\Finance\Finance\PendingPayablesService;

final class MobileFinanceService {
	public function get_overview( array $args = array() ) {
		$range              = $this->resolve_range( $args );
		$source             = sanitize_key( (string) ( $args['source'] ?? 'all' ) );
		$overview           = ( new OverviewService() )->get_dashboard_snapshot(
			array(
				'range_from'     => $range['range_from'],
				'range_to'       => $range['range_to'],
				'include_recent' => false,
			)
		);
		$receivables        = ( new PendingCollectionsService() )->get_snapshot(
			array(
				'limit'          => 1,
				'source'         => $source,
				'range_from'     => $range['range_from'],
				'range_to'       => $range['range_to'],
				'include_detail' => false,
			)
		);
		$payables           = ( new PendingPayablesService() )->get_snapshot(
			array(
				'limit'      => 1,
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
			)
		);
		$receivable_summary = $this->normalize_summary( $receivables['summary'] ?? array() );
		$payable_summary    = $this->normalize_summary( $payables['summary'] ?? array() );
		$receivable_open    = $this->summary_amount( $receivable_summary, 'pending_total', $overview['receivable_total'] ?? 0 );
		$payable_open       = $this->summary_amount( $payable_summary, 'pending_total', $overview['payable_total'] ?? 0 );
		$receivable_current = $this->summary_amount( $receivable_summary, 'in_range_pending_total', $overview['receivable_total'] ?? 0 );
		$payable_current    = $this->summary_amount( $payable_summary, 'in_range_pending_total', $overview['payable_total'] ?? 0 );
		$receivable_history = $this->summary_amount( $receivable_summary, 'historical_pending_total' );
		$payable_history    = $this->summary_amount( $payable_summary, 'historical_pending_total' );

		return array(
			// Keep legacy totals for existing mobile clients while exposing explicit balance buckets.
			'receivable_total'                  => $receivable_open,
			'payable_total'                     => $payable_open,
			'receivable_open_total'             => $receivable_open,
			'receivable_current_period_total'   => $receivable_current,
			'receivable_historical_open_total'  => $receivable_history,
			'receivable_group_count'            => (int) ( $receivable_summary['group_count'] ?? 0 ),
			'payable_open_total'                => $payable_open,
			'payable_current_period_total'      => $payable_current,
			'payable_historical_open_total'     => $payable_history,
			'payable_group_count'               => (int) ( $payable_summary['group_count'] ?? 0 ),
			'open_documents'                    => (int) ( $overview['open_document_count'] ?? 0 ),
			'payment_count'                     => (int) ( $overview['payment_count'] ?? 0 ),
			'currency'                          => 'USD',
			'range'                             => $range,
		);
	}

	public function list_receivables( array $args = array() ) {
		$range      = $this->resolve_range( $args );
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit      = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$search     = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$source     = sanitize_key( (string) ( $args['source'] ?? 'all' ) );
		$snapshot   = ( new PendingCollectionsService() )->get_snapshot(
			array(
				'limit'          => 0,
				'source'         => $source,
				'range_from'     => $range['range_from'],
				'range_to'       => $range['range_to'],
				'include_detail' => false,
			)
		);
		$all_items  = array_values( (array) ( $snapshot['items'] ?? array() ) );

		if ( '' !== $search ) {
			$all_items = array_values(
				array_filter(
					$all_items,
					function ( array $item ) use ( $search ) {
						return $this->matches_receivable_search( $item, $search );
					}
				)
			);
		}

		$summary    = '' !== $search
			? $this->build_receivable_summary_from_items( $all_items )
			: $this->normalize_summary( $snapshot['summary'] ?? array() );
		$pagination = $this->paginate_items( $all_items, $summary, $page, $limit );

		return array(
			'items'    => array_map( array( $this, 'map_receivable_item' ), $pagination['items'] ),
			'summary'  => $summary,
			'currency' => 'USD',
			'range'    => $range,
			'meta'     => $pagination['meta'],
		);
	}

	public function list_payables( array $args = array() ) {
		$range      = $this->resolve_range( $args );
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit      = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$snapshot   = ( new PendingPayablesService() )->get_snapshot(
			array(
				'limit'      => 0,
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
			)
		);
		$summary    = $this->normalize_summary( $snapshot['summary'] ?? array() );
		$all_items  = array_values( (array) ( $snapshot['items'] ?? array() ) );
		$pagination = $this->paginate_items( $all_items, $summary, $page, $limit );

		return array(
			'items'    => array_map( array( $this, 'map_payable_item' ), $pagination['items'] ),
			'summary'  => $summary,
			'currency' => 'USD',
			'range'    => $range,
			'meta'     => $pagination['meta'],
		);
	}

	public function get_comparison( array $args = array() ) {
		$current  = $this->resolve_range( $args );
		$previous = $this->previous_range( $current['range_from'], $current['range_to'] );
		$current_overview = $this->get_overview(
			array(
				'range_from' => $current['range_from'],
				'range_to'   => $current['range_to'],
			)
		);
		$previous_overview = $this->get_overview(
			array(
				'range_from' => $previous['range_from'],
				'range_to'   => $previous['range_to'],
			)
		);
		$current_amount  = round( (float) $current_overview['receivable_total'] - (float) $current_overview['payable_total'], 2 );
		$previous_amount = round( (float) $previous_overview['receivable_total'] - (float) $previous_overview['payable_total'], 2 );
		$delta_percent   = 0.0;

		if ( 0.0 !== $previous_amount ) {
			$delta_percent = round( ( ( $current_amount - $previous_amount ) / abs( $previous_amount ) ) * 100, 2 );
		}

		return array(
			'current_label'  => $this->range_label( $current ),
			'previous_label' => $this->range_label( $previous ),
			'delta_percent'  => $delta_percent,
			'current_amount' => $current_amount,
			'previous_amount'=> $previous_amount,
			'currency'       => 'USD',
			'basis'          => 'net_open_position',
		);
	}

	private function map_receivable_item( array $item ) {
		$oldest    = sanitize_text_field( (string) ( $item['oldest_date'] ?? '' ) );
		$contact_id = absint( $item['contact_id'] ?? 0 );

		return array(
			'id'           => sanitize_text_field( (string) ( $item['group_key'] ?? '' ) ),
			'title'        => sanitize_text_field( (string) ( $item['display_name'] ?? 'Pendiente por cobrar' ) ),
			'counterparty' => sanitize_text_field( (string) ( $item['email'] ?? $item['profile_origin'] ?? '' ) ),
			'amount'       => round( (float) ( $item['pending_total'] ?? 0 ), 2 ),
			'currency'     => 'USD',
			'due_label'    => '' !== $oldest ? 'Desde ' . $oldest : 'Sin fecha visible',
			'source_label' => $this->receivable_source_label( $item ),
			'contact_id'   => $contact_id,
			'has_linked_profile' => $contact_id > 0,
		);
	}

	private function map_payable_item( array $item ) {
		$oldest = sanitize_text_field( (string) ( $item['oldest_date'] ?? '' ) );

		return array(
			'id'           => sanitize_text_field( (string) ( $item['group_key'] ?? '' ) ),
			'title'        => sanitize_text_field( (string) ( $item['display_name'] ?? 'Pendiente por pagar' ) ),
			'counterparty' => sanitize_text_field( (string) ( $item['email'] ?? $item['profile_origin'] ?? '' ) ),
			'amount'       => round( (float) ( $item['pending_total'] ?? 0 ), 2 ),
			'currency'     => 'USD',
			'due_label'    => '' !== $oldest ? 'Desde ' . $oldest : 'Sin fecha visible',
			'source_label' => sanitize_text_field( (string) ( $item['profile_origin'] ?? 'finance' ) ),
		);
	}

	private function resolve_range( array $args = array() ) {
		$fiscal_service = new FiscalYearService();
		$selected_year  = absint( $args['fiscal_year'] ?? 0 );
		$context        = $fiscal_service->get_context( $selected_year );
		$range_from     = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to       = $this->sanitize_date( $args['range_to'] ?? '' );

		if ( ! $range_from || $range_from < $context['start_date'] ) {
			$range_from = $context['start_date'];
		}

		if ( ! $range_to || $range_to > $context['end_date'] ) {
			$range_to = $context['end_date'];
		}

		if ( $range_from > $range_to ) {
			$temp       = $range_from;
			$range_from = $range_to;
			$range_to   = $temp;
		}

		return array(
			'fiscal_year' => (int) $context['id'],
			'range_from'  => $range_from,
			'range_to'    => $range_to,
		);
	}

	private function previous_range( $range_from, $range_to ) {
		$from_timestamp = strtotime( (string) $range_from );
		$to_timestamp   = strtotime( (string) $range_to );
		$days           = max( 1, (int) floor( ( $to_timestamp - $from_timestamp ) / DAY_IN_SECONDS ) + 1 );
		$previous_to    = gmdate( 'Y-m-d', $from_timestamp - DAY_IN_SECONDS );
		$previous_from  = gmdate( 'Y-m-d', strtotime( $previous_to ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		return array(
			'range_from' => $previous_from,
			'range_to'   => $previous_to,
		);
	}

	private function range_label( array $range ) {
		return sanitize_text_field( (string) ( $range['range_from'] ?? '' ) ) . ' / ' . sanitize_text_field( (string) ( $range['range_to'] ?? '' ) );
	}

	private function normalize_summary( array $summary ) {
		$normalized = $summary;
		$amount_keys = array(
			'pending_total',
			'in_range_pending_total',
			'historical_pending_total',
		);

		foreach ( $amount_keys as $key ) {
			if ( array_key_exists( $key, $normalized ) ) {
				$normalized[ $key ] = round( (float) $normalized[ $key ], 2 );
			}
		}

		return $normalized;
	}

	private function build_receivable_summary_from_items( array $items ) {
		$summary = array(
			'pending_total'            => 0.0,
			'in_range_pending_total'   => 0.0,
			'historical_pending_total' => 0.0,
			'group_count'              => count( $items ),
		);

		foreach ( $items as $item ) {
			$summary['pending_total']            += (float) ( $item['pending_total'] ?? 0 );
			$summary['in_range_pending_total']   += (float) ( $item['in_range_pending_total'] ?? 0 );
			$summary['historical_pending_total'] += (float) ( $item['historical_pending_total'] ?? 0 );
		}

		return $this->normalize_summary( $summary );
	}

	private function matches_receivable_search( array $item, $search ) {
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $search ) : strtolower( (string) $search );
		$haystack = implode(
			' ',
			array(
				(string) ( $item['display_name'] ?? '' ),
				(string) ( $item['email'] ?? '' ),
				(string) ( $item['group_key'] ?? '' ),
				(string) ( $item['profile_origin'] ?? '' ),
				$this->receivable_source_label( $item ),
			)
		);
		$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

		return '' === $needle ? true : false !== strpos( $haystack, $needle );
	}

	private function receivable_source_label( array $item ) {
		$providers = array_values( array_filter( array_map( 'sanitize_key', (array) ( $item['providers'] ?? array() ) ) ) );

		if ( ! empty( $providers ) ) {
			return implode( ', ', $providers );
		}

		return sanitize_text_field( (string) ( $item['profile_origin'] ?? 'finance' ) );
	}

	private function summary_amount( array $summary, $key, $fallback = 0.0 ) {
		if ( array_key_exists( $key, $summary ) ) {
			return round( (float) $summary[ $key ], 2 );
		}

		return round( (float) $fallback, 2 );
	}

	private function paginate_items( array $items, array $summary, $page, $limit ) {
		$total       = max( 0, (int) ( $summary['group_count'] ?? count( $items ) ) );
		$total_pages = $total > 0 ? (int) ceil( $total / $limit ) : 0;
		$page        = $total_pages > 0 ? min( max( 1, (int) $page ), $total_pages ) : 1;
		$offset      = $total_pages > 0 ? ( $page - 1 ) * $limit : 0;
		$page_items  = $total_pages > 0 ? array_slice( $items, $offset, $limit ) : array();

		return array(
			'items' => $page_items,
			'meta'  => array(
				'count'       => count( $page_items ),
				'total'       => $total,
				'page'        => $page,
				'total_pages' => $total_pages,
				'limit'       => $limit,
			),
		);
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}
}
