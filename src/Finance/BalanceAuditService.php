<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Mobile\MobileFinanceService;

final class BalanceAuditService {
	const TOLERANCE = 0.01;

	public function audit_dashboard( array $args = array() ) {
		$range       = $this->normalize_range( $args );
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
		$overview    = ( new OverviewService() )->get_dashboard_snapshot(
			array(
				'range_from'     => $range['range_from'],
				'range_to'       => $range['range_to'],
				'include_recent' => false,
			)
		);

		$receivable_summary = is_array( $receivables['summary'] ?? null ) ? $receivables['summary'] : array();
		$payable_summary    = is_array( $payables['summary'] ?? null ) ? $payables['summary'] : array();

		$receivable_expected = round(
			(float) ( $receivable_summary['order_pending_total'] ?? 0 )
			+ (float) ( $receivable_summary['invoice_pending_total'] ?? 0 )
			+ (float) ( $receivable_summary['loan_pending_total'] ?? 0 ),
			6
		);
		$payable_expected = round(
			(float) ( $payable_summary['invoice_pending_total'] ?? 0 )
			+ (float) ( $payable_summary['profile_credit_pending_total'] ?? 0 )
			+ (float) ( $payable_summary['loan_pending_total'] ?? 0 )
			+ (float) ( $payable_summary['purchase_pending_total'] ?? 0 )
			+ (float) ( $payable_summary['commitment_pending_total'] ?? 0 ),
			6
		);

		$checks = array(
			$this->compare_amounts(
				'Dashboard por cobrar cuadra con su desglose',
				(float) ( $receivable_summary['pending_total'] ?? 0 ),
				$receivable_expected
			),
			$this->compare_amounts(
				'Dashboard por pagar cuadra con su desglose',
				(float) ( $payable_summary['pending_total'] ?? 0 ),
				$payable_expected
			),
			$this->compare_amounts(
				'Subtotal "otros" por cobrar cuadra',
				(float) ( $receivable_summary['other_pending_total'] ?? 0 ),
				round(
					(float) ( $receivable_summary['invoice_pending_total'] ?? 0 )
					+ (float) ( $receivable_summary['loan_pending_total'] ?? 0 ),
					6
				)
			),
			$this->compare_amounts(
				'Subtotal "otros" por pagar cuadra',
				(float) ( $payable_summary['other_pending_total'] ?? 0 ),
				round(
					(float) ( $payable_summary['profile_credit_pending_total'] ?? 0 )
					+ (float) ( $payable_summary['loan_pending_total'] ?? 0 )
					+ (float) ( $payable_summary['purchase_pending_total'] ?? 0 )
					+ (float) ( $payable_summary['commitment_pending_total'] ?? 0 ),
					6
				)
			),
			$this->assert_non_negative(
				'Overview receivable_total no es negativo',
				(float) ( $overview['receivable_total'] ?? 0 )
			),
			$this->assert_non_negative(
				'Overview payable_total no es negativo',
				(float) ( $overview['payable_total'] ?? 0 )
			),
			$this->compare_amounts(
				'Overview deuda ya planificada coincide con cartera',
				(float) ( $overview['receivable_planned_commitment_total'] ?? 0 ),
				(float) ( $receivable_summary['planned_commitment_total'] ?? 0 )
			),
		);

		return $this->build_report(
			'dashboard',
			array(
				'range'   => $range,
				'metrics' => array(
					$this->money_metric( 'Por cobrar dashboard', (float) ( $receivable_summary['pending_total'] ?? 0 ) ),
					$this->money_metric( 'Deuda ya planificada', (float) ( $receivable_summary['planned_commitment_total'] ?? 0 ) ),
					$this->money_metric( 'Por pagar dashboard', (float) ( $payable_summary['pending_total'] ?? 0 ) ),
					$this->money_metric( 'Receivable overview', (float) ( $overview['receivable_total'] ?? 0 ) ),
					$this->money_metric( 'Payable overview', (float) ( $overview['payable_total'] ?? 0 ) ),
					$this->number_metric( 'Grupos por cobrar', (int) ( $receivable_summary['group_count'] ?? 0 ) ),
					$this->number_metric( 'Grupos por pagar', (int) ( $payable_summary['group_count'] ?? 0 ) ),
				),
				'checks'  => $checks,
			)
		);
	}

	public function audit_mobile( array $args = array() ) {
		$range       = $this->normalize_range( $args );
		$mobile      = new MobileFinanceService();
		$overview    = $mobile->get_overview( $range );
		$comparison  = $mobile->get_comparison( $range );
		$receivables = $mobile->list_receivables(
			array(
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
				'limit'      => 200,
			)
		);
		$payables    = $mobile->list_payables(
			array(
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
				'limit'      => 200,
			)
		);
		$dashboard_receivables = ( new PendingCollectionsService() )->get_snapshot(
			array(
				'range_from'   => $range['range_from'],
				'range_to'     => $range['range_to'],
				'summary_only' => true,
			)
		);
		$dashboard_payables    = ( new PendingPayablesService() )->get_snapshot(
			array(
				'range_from'   => $range['range_from'],
				'range_to'     => $range['range_to'],
				'summary_only' => true,
			)
		);

		$checks = array(
			$this->compare_amounts(
				'Mobile overview receivable_total coincide con web',
				(float) ( $overview['receivable_total'] ?? 0 ),
				(float) ( $dashboard_receivables['summary']['pending_total'] ?? 0 )
			),
			$this->compare_amounts(
				'Mobile overview payable_total coincide con web',
				(float) ( $overview['payable_total'] ?? 0 ),
				(float) ( $dashboard_payables['summary']['pending_total'] ?? 0 )
			),
			$this->compare_amounts(
				'Mobile lista receivables usa la misma suma',
				(float) ( $receivables['summary']['pending_total'] ?? 0 ),
				(float) ( $dashboard_receivables['summary']['pending_total'] ?? 0 )
			),
			$this->compare_amounts(
				'Mobile lista payables usa la misma suma',
				(float) ( $payables['summary']['pending_total'] ?? 0 ),
				(float) ( $dashboard_payables['summary']['pending_total'] ?? 0 )
			),
			$this->compare_amounts(
				'Mobile comparison usa neto receivable-payable',
				(float) ( $comparison['current_amount'] ?? 0 ),
				round(
					(float) ( $overview['receivable_total'] ?? 0 ) - (float) ( $overview['payable_total'] ?? 0 ),
					2
				)
			),
		);

		return $this->build_report(
			'mobile',
			array(
				'range'   => $range,
				'metrics' => array(
					$this->money_metric( 'Receivable movil', (float) ( $overview['receivable_total'] ?? 0 ) ),
					$this->money_metric( 'Payable movil', (float) ( $overview['payable_total'] ?? 0 ) ),
					$this->money_metric( 'Neto movil', (float) ( $comparison['current_amount'] ?? 0 ) ),
					$this->number_metric( 'Items receivables movil', (int) count( (array) ( $receivables['items'] ?? array() ) ) ),
					$this->number_metric( 'Items payables movil', (int) count( (array) ( $payables['items'] ?? array() ) ) ),
				),
				'checks'  => $checks,
			)
		);
	}

	public function audit_contact( $contact_id, array $args = array() ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return new \WP_Error( 'invalid_contact', 'Selecciona un perfil valido para auditar.' );
		}

		$range    = $this->normalize_range( $args );
		$snapshot = ( new ContactOverviewService() )->get_contact_snapshot(
			$contact_id,
			array(
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
				'order_limit'=> 50,
			)
		);

		if ( empty( $snapshot['contact']['id'] ) ) {
			return new \WP_Error( 'missing_contact', 'No encontramos el perfil solicitado.' );
		}

		$summary          = is_array( $snapshot['summary'] ?? null ) ? $snapshot['summary'] : array();
		$receivable_items = ( new PendingCollectionsService() )->get_group_items(
			array(
				'contact_id'   => $contact_id,
				'range_from'   => $range['range_from'],
				'range_to'     => $range['range_to'],
				'order_limit'  => 0,
			)
		);
		$payable_items    = ( new PendingPayablesService() )->get_group_items(
			array(
				'contact_id' => $contact_id,
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
			)
		);

		$receivable_queue_total = $this->sum_items_amount( $receivable_items );
		$payable_queue_total    = $this->sum_items_amount( $payable_items );

		$checks = array(
			$this->compare_amounts(
				'Por cobrar consolidado cuadra',
				(float) ( $summary['consolidated_receivable_total'] ?? 0 ),
				round(
					(float) ( $summary['pending_order_total'] ?? 0 )
					+ (float) ( $summary['non_order_receivable_total'] ?? 0 ),
					6
				)
			),
			$this->compare_amounts(
				'Credito total cuadra',
				(float) ( $summary['credit_total'] ?? 0 ),
				round(
					(float) ( $summary['payable_total'] ?? 0 )
					+ (float) ( $summary['payable_commitment_total'] ?? 0 )
					+ (float) ( $summary['unapplied_payment_total'] ?? 0 ),
					6
				)
			),
			$this->compare_amounts(
				'Disponible hoy cuadra',
				(float) ( $summary['usable_credit_total'] ?? 0 ),
				round(
					(float) ( $summary['payable_total'] ?? 0 )
					+ (float) ( $summary['unapplied_payment_total'] ?? 0 ),
					6
				)
			),
			$this->compare_amounts(
				'Balance neto cuadra',
				(float) ( $summary['net_position_total'] ?? 0 ),
				round(
					(float) ( $summary['consolidated_receivable_total'] ?? 0 )
					- (float) ( $summary['credit_total'] ?? 0 ),
					6
				)
			),
			$this->compare_amounts(
				'Cola por cobrar coincide con snapshot del perfil',
				$receivable_queue_total,
				(float) ( $summary['consolidated_receivable_total'] ?? 0 )
			),
			$this->assert_non_negative(
				'Deuda ya planificada no es negativa',
				(float) ( $summary['receivable_planned_commitment_total'] ?? 0 )
			),
			$this->compare_amounts(
				'Cola por pagar documentada coincide con perfil',
				$payable_queue_total,
				round(
					(float) ( $summary['payable_total'] ?? 0 )
					+ (float) ( $summary['payable_commitment_total'] ?? 0 ),
					6
				)
			),
			$this->compare_amounts(
				'Saldo a favor extra por pagos no aplicados coincide',
				round(
					(float) ( $summary['credit_total'] ?? 0 ) - $payable_queue_total,
					6
				),
				(float) ( $summary['unapplied_payment_total'] ?? 0 )
			),
			$this->assert_non_negative( 'Por cobrar consolidado no es negativo', (float) ( $summary['consolidated_receivable_total'] ?? 0 ) ),
			$this->assert_non_negative( 'Credito total no es negativo', (float) ( $summary['credit_total'] ?? 0 ) ),
			$this->assert_non_negative( 'Disponible hoy no es negativo', (float) ( $summary['usable_credit_total'] ?? 0 ) ),
		);

		return $this->build_report(
			'contact',
			array(
				'range'   => $range,
				'subject' => array(
					'contact_id'   => (int) ( $snapshot['contact']['id'] ?? 0 ),
					'display_name' => sanitize_text_field( (string) ( $snapshot['contact']['display_name'] ?? '' ) ),
					'email'        => sanitize_email( (string) ( $snapshot['contact']['email'] ?? '' ) ),
				),
				'metrics' => array(
					$this->money_metric( 'Por cobrar perfil', (float) ( $summary['consolidated_receivable_total'] ?? 0 ) ),
					$this->money_metric( 'Deuda ya planificada', (float) ( $summary['receivable_planned_commitment_total'] ?? 0 ) ),
					$this->money_metric( 'Saldo a favor total', (float) ( $summary['credit_total'] ?? 0 ) ),
					$this->money_metric( 'Disponible hoy', (float) ( $summary['usable_credit_total'] ?? 0 ) ),
					$this->money_metric( 'Neto', (float) ( $summary['net_position_total'] ?? 0 ) ),
					$this->money_metric( 'Cola por cobrar', $receivable_queue_total ),
					$this->money_metric( 'Cola por pagar', $payable_queue_total ),
					$this->number_metric( 'Items por cobrar', count( $receivable_items ) ),
					$this->number_metric( 'Items por pagar', count( $payable_items ) ),
				),
				'checks'  => $checks,
			)
		);
	}

	private function normalize_range( array $args ) {
		$range_from = sanitize_text_field( (string) ( $args['range_from'] ?? '' ) );
		$range_to   = sanitize_text_field( (string) ( $args['range_to'] ?? '' ) );

		return array(
			'range_from' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $range_from ) ? $range_from : '',
			'range_to'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $range_to ) ? $range_to : '',
		);
	}

	private function build_report( $kind, array $payload ) {
		$checks     = array_values( array_filter( (array) ( $payload['checks'] ?? array() ) ) );
		$mismatches = array_values(
			array_filter(
				$checks,
				static function ( array $check ) {
					return ! in_array( (string) ( $check['status'] ?? 'neutral' ), array( 'ok', 'success' ), true );
				}
			)
		);
		$status     = empty( $mismatches ) ? 'ok' : 'danger';

		return array(
			'kind'       => sanitize_key( (string) $kind ),
			'status'     => $status,
			'label'      => empty( $mismatches ) ? 'Sin diferencias detectadas.' : 'Se detectaron diferencias que requieren revision.',
			'range'      => is_array( $payload['range'] ?? null ) ? $payload['range'] : array(),
			'subject'    => is_array( $payload['subject'] ?? null ) ? $payload['subject'] : array(),
			'metrics'    => array_values( array_filter( (array) ( $payload['metrics'] ?? array() ) ) ),
			'checks'     => $checks,
			'mismatches' => $mismatches,
			'tolerance'  => self::TOLERANCE,
		);
	}

	private function compare_amounts( $label, $actual, $expected ) {
		$actual     = round( (float) $actual, 6 );
		$expected   = round( (float) $expected, 6 );
		$difference = round( $actual - $expected, 6 );

		return array(
			'label'      => sanitize_text_field( (string) $label ),
			'status'     => abs( $difference ) <= self::TOLERANCE ? 'ok' : 'danger',
			'actual'     => $actual,
			'expected'   => $expected,
			'difference' => $difference,
			'type'       => 'money',
		);
	}

	private function assert_non_negative( $label, $actual ) {
		$actual = round( (float) $actual, 6 );

		return array(
			'label'      => sanitize_text_field( (string) $label ),
			'status'     => $actual >= - self::TOLERANCE ? 'ok' : 'danger',
			'actual'     => $actual,
			'expected'   => 0.0,
			'difference' => $actual < 0 ? $actual : 0.0,
			'type'       => 'money',
		);
	}

	private function money_metric( $label, $value ) {
		return array(
			'label' => sanitize_text_field( (string) $label ),
			'value' => round( (float) $value, 6 ),
			'type'  => 'money',
		);
	}

	private function number_metric( $label, $value ) {
		return array(
			'label' => sanitize_text_field( (string) $label ),
			'value' => (int) $value,
			'type'  => 'number',
		);
	}

	private function sum_items_amount( array $items ) {
		return round(
			array_sum(
				array_map(
					static function ( array $item ) {
						return max( 0, (float) ( $item['amount'] ?? 0 ) );
					},
					$items
				)
			),
			6
		);
	}
}
