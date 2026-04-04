<?php

namespace ASDLabs\Finance\Mobile;

use ASDLabs\Finance\Finance\FiscalYearService;
use ASDLabs\Finance\Finance\OverviewService;
use ASDLabs\Finance\Finance\PendingCollectionsService;
use ASDLabs\Finance\Finance\PendingPayablesService;

final class MobileFinanceService {
	public function get_overview( array $args = array() ) {
		$range       = $this->resolve_range( $args );
		$overview    = ( new OverviewService() )->get_dashboard_snapshot(
			array(
				'range_from'     => $range['range_from'],
				'range_to'       => $range['range_to'],
				'include_recent' => false,
			)
		);
		$receivables = ( new PendingCollectionsService() )->get_snapshot(
			array(
				'range_from'   => $range['range_from'],
				'range_to'     => $range['range_to'],
				'summary_only' => true,
			)
		);
		$payables    = ( new PendingPayablesService() )->get_snapshot(
			array(
				'range_from'   => $range['range_from'],
				'range_to'     => $range['range_to'],
				'summary_only' => true,
			)
		);

		return array(
			'receivable_total' => round( (float) ( $receivables['summary']['pending_total'] ?? $overview['receivable_total'] ?? 0 ), 2 ),
			'payable_total'    => round( (float) ( $payables['summary']['pending_total'] ?? $overview['payable_total'] ?? 0 ), 2 ),
			'open_documents'   => (int) ( $overview['open_document_count'] ?? 0 ),
			'payment_count'    => (int) ( $overview['payment_count'] ?? 0 ),
			'currency'         => 'USD',
			'range'            => $range,
		);
	}

	public function list_receivables( array $args = array() ) {
		$range    = $this->resolve_range( $args );
		$limit    = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$source   = sanitize_key( (string) ( $args['source'] ?? 'all' ) );
		$snapshot = ( new PendingCollectionsService() )->get_snapshot(
			array(
				'limit'          => $limit,
				'source'         => $source,
				'range_from'     => $range['range_from'],
				'range_to'       => $range['range_to'],
				'include_detail' => false,
			)
		);

		return array(
			'items'    => array_map( array( $this, 'map_receivable_item' ), (array) ( $snapshot['items'] ?? array() ) ),
			'summary'  => $snapshot['summary'] ?? array(),
			'currency' => 'USD',
			'range'    => $range,
		);
	}

	public function list_payables( array $args = array() ) {
		$range    = $this->resolve_range( $args );
		$limit    = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$snapshot = ( new PendingPayablesService() )->get_snapshot(
			array(
				'limit'      => $limit,
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
			)
		);

		return array(
			'items'    => array_map( array( $this, 'map_payable_item' ), (array) ( $snapshot['items'] ?? array() ) ),
			'summary'  => $snapshot['summary'] ?? array(),
			'currency' => 'USD',
			'range'    => $range,
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
		$providers = array_values( array_filter( array_map( 'sanitize_key', (array) ( $item['providers'] ?? array() ) ) ) );
		$oldest    = sanitize_text_field( (string) ( $item['oldest_date'] ?? '' ) );

		return array(
			'id'           => sanitize_text_field( (string) ( $item['group_key'] ?? '' ) ),
			'title'        => sanitize_text_field( (string) ( $item['display_name'] ?? 'Pendiente por cobrar' ) ),
			'counterparty' => sanitize_text_field( (string) ( $item['email'] ?? $item['profile_origin'] ?? '' ) ),
			'amount'       => round( (float) ( $item['pending_total'] ?? 0 ), 2 ),
			'currency'     => 'USD',
			'due_label'    => '' !== $oldest ? 'Desde ' . $oldest : 'Sin fecha visible',
			'source_label' => ! empty( $providers ) ? implode( ', ', $providers ) : sanitize_text_field( (string) ( $item['profile_origin'] ?? 'finance' ) ),
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

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}
}
