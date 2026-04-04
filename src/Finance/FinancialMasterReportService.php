<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Legacy\AnalysisModule;

final class FinancialMasterReportService extends BaseRepository {
	const CACHE_TTL = 300;

	protected $table_key = 'documents';

	public function get_payload( array $args = array() ) {
		$snapshot_id = absint( $args['snapshot_id'] ?? 0 );

		if ( $snapshot_id > 0 ) {
			$snapshot = ( new MonthlyCloseSnapshotService() )->find( $snapshot_id );
			if ( ! empty( $snapshot['id'] ) && ! empty( $snapshot['payload'] ) ) {
				$payload = is_array( $snapshot['payload'] ) ? $snapshot['payload'] : array();
				$payload['meta'] = array_merge(
					(array) ( $payload['meta'] ?? array() ),
					array(
						'mode'             => 'monthly_close',
						'month_key'        => sanitize_text_field( (string) ( $snapshot['month_key'] ?? '' ) ),
						'range_from'       => sanitize_text_field( (string) ( $snapshot['range_from'] ?? '' ) ),
						'range_to'         => sanitize_text_field( (string) ( $snapshot['range_to'] ?? '' ) ),
						'is_snapshot'      => true,
						'snapshot_id'      => (int) $snapshot['id'],
						'snapshot_version' => (int) ( $snapshot['version_no'] ?? 0 ),
						'snapshot_status'  => sanitize_key( (string) ( $snapshot['status'] ?? '' ) ),
						'is_official'      => ! empty( $snapshot['is_official'] ),
						'fiscal_context'   => (array) ( $snapshot['fiscal_context'] ?? array() ),
					)
				);

				return $payload;
			}
		}

		$context   = $this->resolve_context( $args );
		$cache_key = $this->cache_key_for_context( $context );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$payload = $this->build_live_payload( $context, true );
		set_transient( $cache_key, $payload, self::CACHE_TTL );

		return $payload;
	}

	public function resolve_context( array $args = array() ) {
		$fiscal_service = new FiscalYearService();
		$mode           = sanitize_key( (string) ( $args['mode'] ?? 'total' ) );
		$mode           = in_array( $mode, array( 'range', 'total', 'monthly_close' ), true ) ? $mode : 'total';
		$fiscal_year    = absint( $args['fiscal_year'] ?? 0 );
		$selected_range_from = $this->sanitize_date( $args['range_from'] ?? '' );
		$selected_range_to   = $this->sanitize_date( $args['range_to'] ?? '' );
		$month_key           = ( new MonthlyCloseSnapshotService() )->sanitize_month_key( $args['month_key'] ?? '' );
		$fiscal_context      = $fiscal_service->get_context( $fiscal_year );

		if ( 'monthly_close' === $mode ) {
			if ( '' === $month_key ) {
				$month_key = gmdate( 'Y-m' );
			}

			list( $range_from, $range_to ) = $this->month_range( $month_key );
			$fiscal_context = $fiscal_service->get_context(
				$fiscal_service->resolve_start_year_from_date( $range_from )
			);
		} elseif ( 'range' === $mode ) {
			$range_from = $selected_range_from ?: $fiscal_context['start_date'];
			$range_to   = $selected_range_to ?: $fiscal_context['end_date'];
			if ( $range_from > $range_to ) {
				$temp       = $range_from;
				$range_from = $range_to;
				$range_to   = $temp;
			}
			$month_key = gmdate( 'Y-m', strtotime( $range_from ) );
			$fiscal_context = $fiscal_service->get_context(
				$fiscal_service->resolve_start_year_from_date( $range_from )
			);
		} else {
			$range_from = $fiscal_context['start_date'];
			$range_to   = $fiscal_context['end_date'];
			$month_key  = gmdate( 'Y-m', strtotime( $range_to ) );
		}

		$sales_filters = $this->resolve_sales_filters( $args );

		return array(
			'mode'             => $mode,
			'fiscal_year'      => (int) ( $fiscal_context['start_year'] ?? 0 ),
			'fiscal_context'   => $fiscal_context,
			'range_from'       => $range_from,
			'range_to'         => $range_to,
			'month_key'        => $month_key,
			'sales_filters'    => $sales_filters,
			'is_snapshot'      => false,
			'snapshot_id'      => 0,
			'snapshot_version' => null,
		);
	}

	public function build_live_payload( array $context, $include_comparison = true ) {
		$receivables = ( new PendingCollectionsService() )->get_snapshot(
			array(
				'limit'      => 60,
				'range_from' => $context['range_from'],
				'range_to'   => $context['range_to'],
			)
		);
		$payables = ( new PendingPayablesService() )->get_snapshot(
			array(
				'limit'      => 60,
				'range_from' => $context['range_from'],
				'range_to'   => $context['range_to'],
			)
		);
		$overview = ( new OverviewService() )->get_dashboard_snapshot(
			array(
				'range_from'     => $context['range_from'],
				'range_to'       => $context['range_to'],
				'include_recent' => false,
			)
		);
		$sales    = $this->build_sales_payload( $context );
		$expenses = $this->build_expenses_payload( $context );
		$preflight = $this->build_preflight_payload( $context );

		$payload = $this->compose_payload(
			$context,
			$sales,
			$receivables,
			$payables,
			$expenses,
			array(
				'open_documents' => (int) ( $overview['open_document_count'] ?? 0 ),
				'payment_count'  => (int) ( $overview['payment_count'] ?? 0 ),
				'document_count' => (int) ( $overview['document_count'] ?? 0 ),
			),
			$preflight
		);

		$payload['comparison'] = $include_comparison
			? $this->build_comparison_payload( $context, $payload )
			: array(
				'current_label'  => $this->range_label( $context['range_from'], $context['range_to'] ),
				'previous_label' => '',
				'basis'          => '',
				'rows'           => array(),
			);

		return $payload;
	}

	public function export_filename( array $payload ) {
		$meta      = (array) ( $payload['meta'] ?? array() );
		$mode      = sanitize_key( (string) ( $meta['mode'] ?? 'range' ) );
		$range_from = sanitize_text_field( (string) ( $meta['range_from'] ?? '' ) );
		$range_to   = sanitize_text_field( (string) ( $meta['range_to'] ?? '' ) );

		if ( ! empty( $meta['is_snapshot'] ) ) {
			return sprintf(
				'cierre-mensual-%s-v%s',
				sanitize_title( (string) ( $meta['month_key'] ?? 'mensual' ) ),
				max( 1, (int) ( $meta['snapshot_version'] ?? 1 ) )
			);
		}

		if ( 'total' === $mode ) {
			return 'reporte-maestro-total-ejercicio';
		}

		if ( 'monthly_close' === $mode ) {
			return 'reporte-maestro-mensual-' . sanitize_title( (string) ( $meta['month_key'] ?? '' ) );
		}

		return 'reporte-maestro-' . sanitize_title( $range_from . '-a-' . $range_to );
	}

	public function build_sales_payload( array $context ) {
		$filters = (array) ( $context['sales_filters'] ?? array() );

		try {
			AnalysisModule::bootstrap();

			$defaults = class_exists( 'AFP_Settings' ) ? \AFP_Settings::get_settings() : array();
			$exclude_term_ids = class_exists( 'AFP_Reports' )
				? \AFP_Reports::parse_exclude_categories( (string) ( $filters['exclude_categories_raw'] ?? '' ) )
				: array();
			$summary = class_exists( 'AFP_Reports' )
				? \AFP_Reports::get_summary(
					$context['range_from'],
					$context['range_to'],
					$exclude_term_ids,
					array(
						'sales_statuses'   => (array) ( $filters['sales_statuses'] ?? ( $defaults['sales_statuses'] ?? array() ) ),
						'pending_statuses' => (array) ( $filters['pending_statuses'] ?? ( $defaults['pending_statuses'] ?? array() ) ),
					)
				)
				: array();
		} catch ( \Throwable $throwable ) {
			return $this->empty_sales_payload( $context, $throwable->getMessage() );
		}
		$totals       = (array) ( $summary['totals'] ?? array() );
		$gross_profit = round( (float) ( $totals['ventas_netas'] ?? 0 ) - (float) ( $totals['inversion'] ?? 0 ), 6 );

		return array(
			'totals' => array(
				'gross_sales'                => round( (float) ( $totals['ventas_brutas'] ?? 0 ), 6 ),
				'refunds'                    => round( (float) ( $totals['reembolsos'] ?? 0 ), 6 ),
				'net_sales'                  => round( (float) ( $totals['ventas_netas'] ?? 0 ), 6 ),
				'cogs'                       => round( (float) ( $totals['inversion'] ?? 0 ), 6 ),
				'gross_profit'               => $gross_profit,
				'orders'                     => (int) ( $totals['pedidos'] ?? 0 ),
				'units'                      => round( (float) ( $totals['unidades'] ?? 0 ), 6 ),
				'gross_margin_percent'       => round( (float) ( $totals['margen_bruto_pct'] ?? 0 ), 2 ),
				'refund_rate_percent'        => round( (float) ( $totals['tasa_reembolso'] ?? 0 ), 2 ),
				'avg_unit_cost'              => round( (float) ( $totals['costo_promedio_unidad'] ?? 0 ), 6 ),
				'avg_unit_sale'              => round( (float) ( $totals['venta_promedio_unidad'] ?? 0 ), 6 ),
				'woo_pending_reference_total'=> round( (float) ( $summary['pendiente'] ?? 0 ), 6 ),
				'woo_pending_reference_count'=> (int) ( $summary['pendiente_count'] ?? 0 ),
			),
			'series'      => (array) ( $summary['series'] ?? array() ),
			'top_days'    => array(
				'best'  => (array) ( $totals['top_mas_vendido'] ?? array() ),
				'worst' => (array) ( $totals['top_menos_vendido'] ?? array() ),
			),
			'comparison_reference' => array(
				'range'  => (array) ( $summary['compare']['range'] ?? array() ),
				'totals' => array(
					'gross_sales'          => round( (float) ( $summary['compare']['totals']['ventas_brutas'] ?? 0 ), 6 ),
					'refunds'              => round( (float) ( $summary['compare']['totals']['reembolsos'] ?? 0 ), 6 ),
					'net_sales'            => round( (float) ( $summary['compare']['totals']['ventas_netas'] ?? 0 ), 6 ),
					'cogs'                 => round( (float) ( $summary['compare']['totals']['inversion'] ?? 0 ), 6 ),
					'gross_profit'         => round( (float) ( $summary['compare']['totals']['ventas_netas'] ?? 0 ) - (float) ( $summary['compare']['totals']['inversion'] ?? 0 ), 6 ),
					'orders'               => (int) ( $summary['compare']['totals']['pedidos'] ?? 0 ),
					'units'                => round( (float) ( $summary['compare']['totals']['unidades'] ?? 0 ), 6 ),
					'gross_margin_percent' => round( (float) ( $summary['compare']['totals']['margen_bruto_pct'] ?? 0 ), 2 ),
				),
			),
			'products'    => (array) ( $summary['productos'] ?? array() ),
			'categories'  => (array) ( $summary['categorias'] ?? array() ),
			'filters'     => array(
				'exclude_categories_raw' => sanitize_text_field( (string) ( $filters['exclude_categories_raw'] ?? '' ) ),
				'sales_statuses'         => array_values( array_map( 'sanitize_text_field', (array) ( $filters['sales_statuses'] ?? array() ) ) ),
				'pending_statuses'       => array_values( array_map( 'sanitize_text_field', (array) ( $filters['pending_statuses'] ?? array() ) ) ),
				'exclude_term_ids'       => array_values( array_map( 'absint', $exclude_term_ids ) ),
			),
			'error_message' => '',
		);
	}

	public function build_expenses_payload( array $context ) {
		$empty = array(
			'external' => array( 'count' => 0, 'issued_total' => 0.0, 'pending_total' => 0.0 ),
			'internal' => array( 'count' => 0, 'issued_total' => 0.0, 'pending_total' => 0.0 ),
			'services' => array( 'count' => 0, 'issued_total' => 0.0, 'pending_total' => 0.0 ),
			'payroll'  => array( 'count' => 0, 'issued_total' => 0.0, 'pending_total' => 0.0 ),
			'total'    => array( 'count' => 0, 'issued_total' => 0.0, 'pending_total' => 0.0 ),
			'items'    => array(),
		);

		if ( ! $this->has_table() ) {
			return $empty;
		}

		$wpdb           = $this->db();
		$contacts_table = \ASDLabs\Finance\Core\Tables::name( 'contacts' );
		$range_from     = $context['range_from'];
		$range_to       = $context['range_to'];
		$type_map       = array(
			'external_expense' => 'external',
			'internal_expense' => 'internal',
			'internal_gift'    => 'internal',
			'service_expense'  => 'services',
			'salary_expense'   => 'payroll',
		);
		$rows           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.id, d.document_number, d.document_type, d.financial_intent, d.subcategory_key, d.title, d.issue_date, d.currency, d.total, d.balance, d.payment_status, d.financial_status,
					d.contact_id, c.display_name AS contact_display_name, c.email AS contact_email
				FROM {$this->table()} d
				LEFT JOIN {$contacts_table} c ON c.id = d.contact_id
				WHERE (
						d.document_type IN ('external_expense', 'service_expense', 'salary_expense')
						OR (
							d.financial_intent = 'internal_consumption'
							AND d.subcategory_key IN ('internal_expense', 'internal_gift')
						)
					)
					AND COALESCE(d.financial_status, '') <> 'void'
					AND COALESCE(d.issue_date, DATE(d.created_at)) >= %s
					AND COALESCE(d.issue_date, DATE(d.created_at)) <= %s
				ORDER BY COALESCE(d.issue_date, DATE(d.created_at)) DESC, d.id DESC
				LIMIT 120",
				$range_from,
				$range_to
			),
			ARRAY_A
		);

		$payload = $empty;

		foreach ( $rows as $row ) {
			$expense_origin_key = $this->expense_origin_key_from_row( $row );
			$bucket_key         = $type_map[ $expense_origin_key ] ?? '';
			if ( '' === $bucket_key ) {
				continue;
			}

			$payload[ $bucket_key ]['count']++;
			$payload[ $bucket_key ]['issued_total'] += (float) ( $row['total'] ?? 0 );
			$payload[ $bucket_key ]['pending_total'] += (float) ( $row['balance'] ?? 0 );
			$payload['total']['count']++;
			$payload['total']['issued_total'] += (float) ( $row['total'] ?? 0 );
			$payload['total']['pending_total'] += (float) ( $row['balance'] ?? 0 );
			$payload['items'][] = array(
				'id'                => (int) ( $row['id'] ?? 0 ),
				'document_number'   => sanitize_text_field( (string) ( $row['document_number'] ?? '' ) ),
				'document_type'     => sanitize_key( (string) ( $row['document_type'] ?? '' ) ),
				'financial_intent'  => sanitize_key( (string) ( $row['financial_intent'] ?? '' ) ),
				'subcategory_key'   => sanitize_key( (string) ( $row['subcategory_key'] ?? '' ) ),
				'expense_origin_key'=> $expense_origin_key,
				'expense_origin_label' => $this->expense_origin_label_from_key( $expense_origin_key ),
				'title'             => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
				'issue_date'        => sanitize_text_field( (string) ( $row['issue_date'] ?? '' ) ),
				'currency'          => sanitize_text_field( (string) ( $row['currency'] ?? 'USD' ) ),
				'total'             => round( (float) ( $row['total'] ?? 0 ), 6 ),
				'balance'           => round( (float) ( $row['balance'] ?? 0 ), 6 ),
				'payment_status'    => sanitize_key( (string) ( $row['payment_status'] ?? '' ) ),
				'financial_status'  => sanitize_key( (string) ( $row['financial_status'] ?? '' ) ),
				'contact_id'        => (int) ( $row['contact_id'] ?? 0 ),
				'contact_display_name' => sanitize_text_field( (string) ( $row['contact_display_name'] ?? '' ) ),
				'contact_email'     => sanitize_email( (string) ( $row['contact_email'] ?? '' ) ),
			);
		}

		foreach ( array( 'external', 'internal', 'services', 'payroll', 'total' ) as $bucket ) {
			$payload[ $bucket ]['issued_total'] = round( (float) $payload[ $bucket ]['issued_total'], 6 );
			$payload[ $bucket ]['pending_total'] = round( (float) $payload[ $bucket ]['pending_total'], 6 );
		}

		return $payload;
	}

	private function expense_origin_key_from_row( array $row ) {
		$document_type    = sanitize_key( (string) ( $row['document_type'] ?? '' ) );
		$financial_intent = sanitize_key( (string) ( $row['financial_intent'] ?? '' ) );
		$subcategory_key  = sanitize_key( (string) ( $row['subcategory_key'] ?? '' ) );

		if ( 'internal_consumption' === $financial_intent ) {
			if ( 'internal_gift' === $subcategory_key ) {
				return 'internal_gift';
			}

			return 'internal_expense';
		}

		return $document_type;
	}

	private function expense_origin_label_from_key( $origin_key ) {
		switch ( sanitize_key( (string) $origin_key ) ) {
			case 'internal_gift':
				return 'Regalo interno';
			case 'internal_expense':
				return 'Consumo interno';
			case 'service_expense':
				return 'Servicio';
			case 'salary_expense':
				return 'Nomina';
			case 'external_expense':
			default:
				return 'Gasto externo';
		}
	}

	public function build_comparison_payload( array $context, array $current_payload ) {
		$previous_executive = array();
		$previous_label     = '';
		$basis              = '';

		if ( 'monthly_close' === $context['mode'] ) {
			$previous_month_key = $this->shift_month_key( $context['month_key'], -1 );
			$official_previous  = ( new MonthlyCloseSnapshotService() )->find_official_for_month( $previous_month_key );

			if ( ! empty( $official_previous['payload'] ) ) {
				$previous_payload   = is_array( $official_previous['payload'] ) ? $official_previous['payload'] : array();
				$previous_executive = (array) ( $previous_payload['executive'] ?? array() );
				$previous_label     = sprintf( '%s · version oficial v%s', $previous_month_key, (int) ( $official_previous['version_no'] ?? 0 ) );
				$basis              = 'snapshot_official';
			} else {
				list( $prev_from, $prev_to ) = $this->month_range( $previous_month_key );
				$previous_context = array_merge(
					$context,
					array(
						'range_from' => $prev_from,
						'range_to'   => $prev_to,
						'month_key'  => $previous_month_key,
					)
				);
				$previous_sales = $this->build_sales_payload( $previous_context );
				$previous_expenses = $this->build_expenses_payload( $previous_context );
				$previous_receivables = ( new PendingCollectionsService() )->get_snapshot(
					array(
						'range_from'   => $prev_from,
						'range_to'     => $prev_to,
						'summary_only' => true,
					)
				);
				$previous_payables = ( new PendingPayablesService() )->get_snapshot(
					array(
						'range_from'   => $prev_from,
						'range_to'     => $prev_to,
						'summary_only' => true,
					)
				);
				$previous_executive = $this->build_executive_metrics(
					$previous_sales,
					$previous_expenses,
					$previous_receivables,
					$previous_payables
				);
				$previous_label = $this->range_label( $prev_from, $prev_to );
				$basis          = 'month_previous_live';
			}
		} else {
			list( $prev_from, $prev_to ) = $this->previous_equivalent_range( $context['range_from'], $context['range_to'] );
			$comparison_reference = (array) ( $current_payload['sales']['comparison_reference'] ?? array() );
			$previous_sales = array(
				'totals' => (array) ( $comparison_reference['totals'] ?? array() ),
			);
			$previous_expenses = $this->build_expenses_payload(
				array_merge(
					$context,
					array(
						'range_from' => $prev_from,
						'range_to'   => $prev_to,
						'month_key'  => gmdate( 'Y-m', strtotime( $prev_from ) ),
					)
				)
			);
			$previous_receivables = ( new PendingCollectionsService() )->get_snapshot(
				array(
					'range_from'   => $prev_from,
					'range_to'     => $prev_to,
					'summary_only' => true,
				)
			);
			$previous_payables = ( new PendingPayablesService() )->get_snapshot(
				array(
					'range_from'   => $prev_from,
					'range_to'     => $prev_to,
					'summary_only' => true,
				)
			);
			$previous_executive = $this->build_executive_metrics(
				$previous_sales,
				$previous_expenses,
				$previous_receivables,
				$previous_payables
			);
			$previous_label = $this->range_label( $prev_from, $prev_to );
			$basis          = 'previous_equivalent_range';
		}

		$rows = array();

		foreach ( array(
			'sales_net'            => 'Ventas netas',
			'cogs'                 => 'Costo de productos vendidos',
			'gross_profit'         => 'Ganancia bruta',
			'period_expense_total' => 'Gastos del periodo',
			'operating_result'     => 'Resultado operativo',
			'receivable_open_total'=> 'Por cobrar abierto',
			'payable_open_total'   => 'Por pagar abierto',
			'net_open_position'    => 'Posicion neta abierta',
		) as $key => $label ) {
			$current  = (float) ( $current_payload['executive'][ $key ] ?? 0 );
			$previous = (float) ( $previous_executive[ $key ] ?? 0 );
			$delta    = round( $current - $previous, 6 );
			$percent  = 0.0;

			if ( abs( $previous ) > 0.00001 ) {
				$percent = round( ( $delta / abs( $previous ) ) * 100, 2 );
			}

			$rows[] = array(
				'key'           => $key,
				'label'         => $label,
				'current'       => round( $current, 6 ),
				'previous'      => round( $previous, 6 ),
				'delta'         => $delta,
				'delta_percent' => $percent,
			);
		}

		return array(
			'current_label'  => $this->range_label( $context['range_from'], $context['range_to'] ),
			'previous_label' => $previous_label,
			'basis'          => $basis,
			'rows'           => $rows,
		);
	}

	private function resolve_sales_filters( array $args ) {
		try {
			AnalysisModule::bootstrap();
			$defaults = class_exists( 'AFP_Settings' ) ? \AFP_Settings::get_settings() : array(
				'sales_statuses'   => array(),
				'pending_statuses' => array(),
			);
		} catch ( \Throwable $throwable ) {
			$defaults = array(
				'sales_statuses'   => array(),
				'pending_statuses' => array(),
			);
		}

		return array(
			'exclude_categories_raw' => sanitize_text_field( (string) ( $args['sales_exclude_categories'] ?? '' ) ),
			'sales_statuses'         => class_exists( 'AFP_Settings' )
				? \AFP_Settings::sanitize_status_list( $args['sales_statuses'] ?? array(), (array) ( $defaults['sales_statuses'] ?? array() ) )
				: array_values( array_map( 'sanitize_text_field', (array) ( $args['sales_statuses'] ?? array() ) ) ),
			'pending_statuses'       => class_exists( 'AFP_Settings' )
				? \AFP_Settings::sanitize_status_list( $args['pending_statuses'] ?? array(), (array) ( $defaults['pending_statuses'] ?? array() ) )
				: array_values( array_map( 'sanitize_text_field', (array) ( $args['pending_statuses'] ?? array() ) ) ),
		);
	}

	private function month_range( $month_key ) {
		$month_key  = ( new MonthlyCloseSnapshotService() )->sanitize_month_key( $month_key );
		$range_from = $month_key . '-01';
		$range_to   = gmdate( 'Y-m-t', strtotime( $range_from ) );

		return array( $range_from, $range_to );
	}

	private function shift_month_key( $month_key, $months ) {
		list( $range_from ) = $this->month_range( $month_key );

		return gmdate( 'Y-m', strtotime( sprintf( '%s %d month', $range_from, (int) $months ) ) );
	}

	private function previous_equivalent_range( $range_from, $range_to ) {
		$from_timestamp = strtotime( (string) $range_from );
		$to_timestamp   = strtotime( (string) $range_to );
		$days           = max( 1, (int) floor( ( $to_timestamp - $from_timestamp ) / DAY_IN_SECONDS ) + 1 );
		$previous_to    = gmdate( 'Y-m-d', $from_timestamp - DAY_IN_SECONDS );
		$previous_from  = gmdate( 'Y-m-d', strtotime( $previous_to ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		return array( $previous_from, $previous_to );
	}

	private function range_label( $range_from, $range_to ) {
		return sanitize_text_field( (string) $range_from ) . ' al ' . sanitize_text_field( (string) $range_to );
	}

	public function build_executive_metrics( array $sales, array $expenses, array $receivables, array $payables ) {
		return array(
			'sales_net'             => round( (float) ( $sales['totals']['net_sales'] ?? 0 ), 6 ),
			'cogs'                  => round( (float) ( $sales['totals']['cogs'] ?? 0 ), 6 ),
			'gross_profit'          => round( (float) ( $sales['totals']['gross_profit'] ?? 0 ), 6 ),
			'period_expense_total'  => round( (float) ( $expenses['total']['issued_total'] ?? 0 ), 6 ),
			'operating_result'      => round( (float) ( $sales['totals']['gross_profit'] ?? 0 ) - (float) ( $expenses['total']['issued_total'] ?? 0 ), 6 ),
			'receivable_open_total' => round( (float) ( $receivables['summary']['pending_total'] ?? 0 ), 6 ),
			'payable_open_total'    => round( (float) ( $payables['summary']['pending_total'] ?? 0 ), 6 ),
			'net_open_position'     => round( (float) ( $receivables['summary']['pending_total'] ?? 0 ) - (float) ( $payables['summary']['pending_total'] ?? 0 ), 6 ),
		);
	}

	public function compose_payload( array $context, array $sales, array $receivables, array $payables, array $expenses, array $overview, array $preflight = array() ) {
		$executive = $this->build_executive_metrics( $sales, $expenses, $receivables, $payables );
		$preflight = $this->normalize_preflight_payload( $preflight, $context );

		return array(
			'meta' => array(
				'mode'               => $context['mode'],
				'range_from'         => $context['range_from'],
				'range_to'           => $context['range_to'],
				'month_key'          => $context['month_key'],
				'fiscal_context'     => $context['fiscal_context'],
				'fiscal_label'       => sanitize_text_field( (string) ( $context['fiscal_context']['label'] ?? '' ) ),
				'is_snapshot'        => false,
				'snapshot_id'        => 0,
				'snapshot_version'   => null,
				'currency'           => 'USD',
				'monthly_close_gate' => $this->build_monthly_close_gate( $context, $preflight ),
			),
			'preflight'   => $preflight,
			'executive'   => $executive,
			'sales'       => $sales,
			'receivables' => $receivables,
			'payables'    => $payables,
			'expenses'    => $expenses,
			'overview'    => $overview,
			'annexes'     => array(
				'expense_items'    => (array) ( $expenses['items'] ?? array() ),
				'receivable_items' => (array) ( $receivables['items'] ?? array() ),
				'payable_items'    => (array) ( $payables['items'] ?? array() ),
			),
		);
	}

	public function cache_payload_for_context( array $context, array $payload ) {
		set_transient( $this->cache_key_for_context( $context ), $payload, self::CACHE_TTL );
	}

	private function cache_key_for_context( array $context ) {
		$margin_service = new ProductMarginCheckService();
		$preflight = $margin_service->get_report_view_result_for_scope(
			$margin_service->report_scope_from_filters( (array) ( $context['sales_filters'] ?? array() ) )
		);

		return 'asdl_fin_master_report_v1_' . md5(
			wp_json_encode(
				array(
					'mode'           => $context['mode'],
					'range_from'     => $context['range_from'],
					'range_to'       => $context['range_to'],
					'month_key'      => $context['month_key'],
					'fiscal_year'    => (int) ( $context['fiscal_year'] ?? 0 ),
					'sales_filters'  => (array) ( $context['sales_filters'] ?? array() ),
					'history_ver'    => ( new HistoricalIndexRebuildService() )->get_data_version(),
					'dashboard_ver'  => (string) get_transient( 'asdl_fin_dashboard_snapshot_version' ),
					'payables_ver'   => (string) get_transient( 'asdl_fin_pending_payables_version' ),
					'payroll_ver'    => (string) get_transient( 'asdl_fin_payroll_queue_version' ),
					'product_ver'    => $margin_service->current_catalog_version(),
					'preflight'      => array(
						'status'               => sanitize_key( (string) ( $preflight['status'] ?? 'unknown' ) ),
						'checked_at'           => sanitize_text_field( (string) ( $preflight['checked_at'] ?? '' ) ),
						'issues'               => (int) ( $preflight['listed_issue_count'] ?? $preflight['issue_count'] ?? 0 ),
						'criticals'            => (int) ( $preflight['visible_critical_count'] ?? $preflight['critical_count'] ?? 0 ),
						'review'               => (int) ( $preflight['visible_review_count'] ?? $preflight['review_count'] ?? 0 ),
						'discarded'            => (int) ( $preflight['discarded_count'] ?? 0 ),
						'view_source'          => sanitize_key( (string) ( $preflight['report_view_source'] ?? '' ) ),
						'certification_state'  => array(
							'checked_at' => sanitize_text_field( (string) ( $preflight['certification_checked_at'] ?? '' ) ),
							'criticals'  => (int) ( $preflight['certification_critical_count'] ?? 0 ),
							'valid'      => ! empty( $preflight['certification_cache_valid'] ),
						),
						'scope_hash'           => sanitize_text_field( (string) ( $preflight['scope_hash'] ?? '' ) ),
					),
					'plugin_version' => defined( 'ASDL_FINANCE_VERSION' ) ? ASDL_FINANCE_VERSION : 'dev',
				)
			)
		);
	}

	private function build_preflight_payload( array $context ) {
		$service = new ProductMarginCheckService();
		$scope   = $service->report_scope_from_filters( (array) ( $context['sales_filters'] ?? array() ) );

		return $service->get_report_view_result_for_scope( $scope );
	}

	private function normalize_preflight_payload( array $preflight, array $context ) {
		$scope_label    = sanitize_text_field( (string) ( $preflight['scope_label'] ?? 'Catalogo de productos' ) );
		$cache_valid    = ! empty( $preflight['cache_valid'] );
		$issue_count    = (int) ( $preflight['listed_issue_count'] ?? $preflight['issue_count'] ?? 0 );
		$critical_count = (int) ( $preflight['visible_critical_count'] ?? $preflight['critical_count'] ?? 0 );
		$review_count   = (int) ( $preflight['visible_review_count'] ?? $preflight['review_count'] ?? 0 );
		$manual_count   = (int) ( $preflight['visible_manual_count'] ?? $preflight['manual_count'] ?? 0 );
		$ok_count       = (int) ( $preflight['visible_ok_count'] ?? $preflight['ok_count'] ?? 0 );
		$status         = sanitize_key( (string) ( $preflight['status'] ?? 'unknown' ) );

		if ( '' === $status ) {
			$status = $cache_valid ? ( $critical_count > 0 ? 'issues' : ( $review_count > 0 ? 'review' : 'ok' ) ) : 'unknown';
		}

		return array(
			'status'                    => $status,
			'checked_at'                => sanitize_text_field( (string) ( $preflight['checked_at'] ?? '' ) ),
			'cache_valid'               => $cache_valid,
			'certified_today'           => ! empty( $preflight['certified_today'] ),
			'certification_cache_valid' => ! empty( $preflight['certification_cache_valid'] ),
			'certification_checked_at'  => sanitize_text_field( (string) ( $preflight['certification_checked_at'] ?? '' ) ),
			'certification_critical_count' => (int) ( $preflight['certification_critical_count'] ?? $preflight['critical_count'] ?? 0 ),
			'issue_count'               => $issue_count,
			'critical_count'            => $critical_count,
			'review_count'              => $review_count,
			'manual_count'              => $manual_count,
			'ok_count'                  => $ok_count,
			'discarded_count'           => (int) ( $preflight['discarded_count'] ?? 0 ),
			'report_view_source'        => sanitize_key( (string) ( $preflight['report_view_source'] ?? '' ) ),
			'blocking_for_monthly_close'=> 'monthly_close' === (string) ( $context['mode'] ?? '' ) && ( empty( $preflight['certification_cache_valid'] ) || (int) ( $preflight['certification_critical_count'] ?? $preflight['critical_count'] ?? 0 ) > 0 ),
			'scope_label'               => $scope_label,
		);
	}

	private function build_monthly_close_gate( array $context, array $preflight ) {
		$mode = sanitize_key( (string) ( $context['mode'] ?? '' ) );
		$current_month = current_time( 'Y-m' );
		$selected_month = sanitize_text_field( (string) ( $context['month_key'] ?? '' ) );
		$is_month_mode = 'monthly_close' === $mode;
		$is_closed_month = $is_month_mode && '' !== $selected_month && $selected_month < $current_month;
		$critical_count      = (int) ( $preflight['certification_critical_count'] ?? $preflight['critical_count'] ?? 0 );
		$has_valid_preflight = ! empty( $preflight['certification_cache_valid'] ) && 0 === $critical_count;
		$can_generate = $is_month_mode && $is_closed_month && $has_valid_preflight;
		$reason = '';

		if ( ! $is_month_mode ) {
			$reason = 'El cierre oficial solo aplica en modo mensual.';
		} elseif ( ! $is_closed_month ) {
			$reason = 'Solo puedes oficializar meses calendario ya cerrados.';
		} elseif ( $critical_count > 0 ) {
			$reason = 'Hay productos criticos en la revision del catalogo. Corrige esos precios o costos antes de oficializar el mes.';
		} elseif ( empty( $preflight['certification_cache_valid'] ) ) {
			$reason = 'Falta la verificacion diaria de margenes para este alcance.';
		}

		return array(
			'is_monthly_close'       => $is_month_mode,
			'is_provisional'         => $is_month_mode && ! $can_generate,
			'can_generate_official'  => $can_generate,
			'reason'                 => sanitize_text_field( $reason ),
		);
	}

	private function empty_sales_payload( array $context, $error_message = '' ) {
		return array(
			'totals' => array(
				'gross_sales'                 => 0.0,
				'refunds'                     => 0.0,
				'net_sales'                   => 0.0,
				'cogs'                        => 0.0,
				'gross_profit'                => 0.0,
				'orders'                      => 0,
				'units'                       => 0.0,
				'gross_margin_percent'        => 0.0,
				'refund_rate_percent'         => 0.0,
				'avg_unit_cost'               => 0.0,
				'avg_unit_sale'               => 0.0,
				'woo_pending_reference_total' => 0.0,
				'woo_pending_reference_count' => 0,
			),
			'series' => array(),
			'top_days' => array(
				'best'  => array(),
				'worst' => array(),
			),
			'comparison_reference' => array(
				'range'  => array(
					'start' => '',
					'end'   => '',
				),
				'totals' => array(),
			),
			'products' => array(),
			'categories' => array(),
			'filters' => array(
				'exclude_categories_raw' => sanitize_text_field( (string) ( $context['sales_filters']['exclude_categories_raw'] ?? '' ) ),
				'sales_statuses'         => array_values( array_map( 'sanitize_text_field', (array) ( $context['sales_filters']['sales_statuses'] ?? array() ) ) ),
				'pending_statuses'       => array_values( array_map( 'sanitize_text_field', (array) ( $context['sales_filters']['pending_statuses'] ?? array() ) ) ),
				'exclude_term_ids'       => array(),
			),
			'error_message' => sanitize_text_field( (string) $error_message ),
		);
	}
}
