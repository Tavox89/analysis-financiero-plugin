<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class OverviewService {
	const DASHBOARD_CACHE_KEY = 'asdl_fin_dashboard_snapshot_v9';
	const CACHE_TTL = 60;

	private static function cache_bust_key() {
		return 'asdl_fin_dashboard_snapshot_version';
	}

	public static function bump_cache_version() {
		set_transient(
			self::cache_bust_key(),
			(string) microtime( true ),
			30 * DAY_IN_SECONDS
		);
	}

	private function cache_version() {
		$version = get_transient( self::cache_bust_key() );

		return is_scalar( $version ) && '' !== (string) $version ? (string) $version : '0';
	}

	public function get_dashboard_snapshot( array $args = array() ) {
		global $wpdb;

		$range          = $this->normalize_date_range( $args );
		$include_recent = ! isset( $args['include_recent'] ) || (bool) $args['include_recent'];
		$cache_key      = self::DASHBOARD_CACHE_KEY . '_' . md5(
			wp_json_encode(
				array(
					'range'          => $range,
					'include_recent' => $include_recent ? 1 : 0,
					'version'        => $this->cache_version(),
				)
			)
		);
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$accounts_table          = Tables::name( 'accounts' );
		$contacts_table          = Tables::name( 'contacts' );
		$documents_table         = Tables::name( 'documents' );
		$payments_table          = Tables::name( 'payments' );
		$rules_table             = Tables::name( 'rules' );
		$events_table            = Tables::name( 'events' );
		$installment_plans_table = Tables::name( 'installment_plans' );
		$employee_advances_table = Tables::name( 'employee_advances' );
		$source_links_table      = Tables::name( 'source_links' );

		$defaults = array(
			'account_count'          => 0,
			'contact_count'          => 0,
			'document_count'         => 0,
			'open_document_count'    => 0,
			'void_document_count'    => 0,
			'payment_count'          => 0,
			'void_payment_count'     => 0,
			'rule_count'             => 0,
			'installment_plan_count' => 0,
			'cancelled_installment_count' => 0,
			'receivable_total'       => 0,
			'receivable_document_total' => 0,
			'receivable_order_document_total' => 0,
			'receivable_commitment_total' => 0,
			'receivable_store_debt_commitment_total' => 0,
			'receivable_store_debt_commitment_applied_to_orders_total' => 0,
			'salary_advance_receivable_total' => 0,
			'payable_total'          => 0,
			'payable_document_total' => 0,
			'payable_commitment_total' => 0,
			'payments_last_30'       => 0,
			'payments_in_period'     => 0,
			'recent_documents'       => array(),
			'recent_payments'        => array(),
			'recent_activity'        => array(),
			'range_from'             => $range['range_from'],
			'range_to'               => $range['range_to'],
		);

		if ( $this->table_exists( $accounts_table ) ) {
			$defaults['account_count'] = $this->count_rows( $accounts_table );
		}

		if ( $this->table_exists( $contacts_table ) ) {
			$defaults['contact_count'] = $this->count_rows( $contacts_table );
		}

		if ( $this->table_exists( $documents_table ) ) {
			$doc_params = array();
			$doc_where  = $this->build_date_where_clause( "COALESCE(issue_date, DATE(created_at))", $range, $doc_params );
			$totals = $wpdb->get_row(
				$this->prepare_query(
					$wpdb,
					"SELECT
					COUNT(*) AS document_count,
					COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) AS open_document_count,
					COALESCE(SUM(CASE WHEN financial_status = 'void' THEN 1 ELSE 0 END), 0) AS void_document_count,
					COALESCE(SUM(CASE WHEN balance_nature = 'receivable' AND balance > 0 THEN balance ELSE 0 END), 0) AS receivable_document_total,
					COALESCE(SUM(CASE WHEN balance_nature = 'receivable' AND balance > 0 AND document_type = 'woo_sale' THEN balance ELSE 0 END), 0) AS receivable_order_document_total,
					COALESCE(SUM(CASE WHEN balance_nature = 'payable' AND balance > 0 THEN balance ELSE 0 END), 0) AS payable_document_total
				FROM {$documents_table}
				{$doc_where}",
					$doc_params
				),
				ARRAY_A
			);

			$defaults['document_count']      = isset( $totals['document_count'] ) ? (int) $totals['document_count'] : 0;
			$defaults['open_document_count'] = isset( $totals['open_document_count'] ) ? (int) $totals['open_document_count'] : 0;
			$defaults['void_document_count'] = isset( $totals['void_document_count'] ) ? (int) $totals['void_document_count'] : 0;
			$defaults['receivable_document_total'] = isset( $totals['receivable_document_total'] ) ? (float) $totals['receivable_document_total'] : 0;
			$defaults['receivable_order_document_total'] = isset( $totals['receivable_order_document_total'] ) ? (float) $totals['receivable_order_document_total'] : 0;
			$defaults['payable_document_total'] = isset( $totals['payable_document_total'] ) ? (float) $totals['payable_document_total'] : 0;
			if ( $include_recent ) {
				$defaults['recent_documents'] = $this->get_latest_documents(
					24,
					array(
						'range_from' => $range['range_from'],
						'range_to'   => $range['range_to'],
					)
				);
			}
		}

		if ( $this->table_exists( $payments_table ) ) {
			$payment_params = array();
			$payment_where  = $this->build_date_where_clause( "COALESCE(p.payment_date, DATE(p.created_at))", $range, $payment_params );
			$payment_totals = $wpdb->get_row(
				$this->prepare_query(
					$wpdb,
					"SELECT
					COUNT(*) AS payment_count,
					COALESCE(SUM(CASE WHEN p.status = 'void' THEN 1 ELSE 0 END), 0) AS void_payment_count
					FROM {$payments_table} p
					{$payment_where}",
					$payment_params
				),
				ARRAY_A
			);
			$defaults['payment_count'] = isset( $payment_totals['payment_count'] ) ? (int) $payment_totals['payment_count'] : 0;
			$defaults['void_payment_count'] = isset( $payment_totals['void_payment_count'] ) ? (int) $payment_totals['void_payment_count'] : 0;
			$defaults['payments_in_period'] = $defaults['payment_count'];
			$defaults['payments_last_30']   = $defaults['payment_count'];
			if ( $include_recent ) {
				$defaults['recent_payments'] = $wpdb->get_results(
					$this->prepare_query(
						$wpdb,
						"SELECT p.id, p.payment_number, p.payment_type, p.status, p.payment_date, p.method_key, p.total, p.available_amount,
							p.contact_id, c.display_name AS contact_display_name, c.email AS contact_email
						FROM {$payments_table} p
						LEFT JOIN {$contacts_table} c ON c.id = p.contact_id
						{$payment_where}
						ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.id DESC
						LIMIT 24",
						$payment_params
					),
					ARRAY_A
				);
			}
		}

		if ( $this->table_exists( $rules_table ) ) {
			$defaults['rule_count'] = $this->count_rows( $rules_table );
		}

		if ( $this->table_exists( $installment_plans_table ) ) {
			$plan_params = array();
			$plan_where  = $this->build_date_where_clause( "COALESCE(NULLIF(start_date, ''), DATE(created_at))", $range, $plan_params );
			$plan_rows   = $wpdb->get_results(
				$this->prepare_query(
					$wpdb,
					"SELECT id, document_id, balance, status, meta_json
					FROM {$installment_plans_table}
					{$plan_where}",
					$plan_params
				),
				ARRAY_A
			);
			$defaults['installment_plan_count'] = count( $plan_rows );

			foreach ( $plan_rows as $plan_row ) {
				$plan_status = sanitize_key( (string) ( $plan_row['status'] ?? '' ) );
				if ( 'cancelled' === $plan_status ) {
					$defaults['cancelled_installment_count']++;
				}

				if ( ! in_array( $plan_status, array( 'active', 'partial', 'paused' ), true ) ) {
					continue;
				}

				if ( (float) ( $plan_row['balance'] ?? 0 ) <= 0 || ! empty( $plan_row['document_id'] ) ) {
					continue;
				}

				$meta      = json_decode( (string) ( $plan_row['meta_json'] ?? '' ), true );
				$meta      = is_array( $meta ) ? $meta : array();
				$direction = sanitize_key( (string) ( $meta['settlement_direction'] ?? ( 'company_debt' === sanitize_key( (string) ( $meta['commitment_origin'] ?? '' ) ) ? 'payable' : 'receivable' ) ) );
				$balance   = max( 0, (float) ( $plan_row['balance'] ?? 0 ) );

				if ( 'payable' === $direction ) {
					$defaults['payable_commitment_total'] += $balance;
					continue;
				}

				$origin = sanitize_key( (string) ( $meta['commitment_origin'] ?? '' ) );
				if ( 'store_debt' === $origin ) {
					$defaults['receivable_store_debt_commitment_total'] += $balance;
					continue;
				}

				$defaults['receivable_commitment_total'] += $balance;
			}
		}

		if ( $this->table_exists( $employee_advances_table ) ) {
			$advance_params = array();
			$advance_where  = $this->build_date_where_clause( "COALESCE(issued_at, DATE(created_at))", $range, $advance_params );
			$defaults['salary_advance_receivable_total'] = (float) $wpdb->get_var(
				$this->prepare_query(
					$wpdb,
					"SELECT
					COALESCE(SUM(CASE WHEN status IN ('active', 'partial') AND balance > 0 THEN balance ELSE 0 END), 0)
					FROM {$employee_advances_table}
					{$advance_where}",
					$advance_params
				)
			);
		}

		if ( $defaults['receivable_store_debt_commitment_total'] > 0 ) {
			$defaults['receivable_store_debt_commitment_applied_to_orders_total'] = min(
				(float) $defaults['receivable_store_debt_commitment_total'],
				(float) $defaults['receivable_order_document_total']
			);
			$defaults['receivable_commitment_total'] += max(
				0,
				(float) $defaults['receivable_store_debt_commitment_total'] - (float) $defaults['receivable_store_debt_commitment_applied_to_orders_total']
			);
		}

		$defaults['receivable_total'] = round(
			(float) $defaults['receivable_document_total']
			+ (float) $defaults['receivable_commitment_total']
			+ (float) $defaults['salary_advance_receivable_total'],
			6
		);
		$defaults['payable_total']    = round( (float) $defaults['payable_document_total'] + (float) $defaults['payable_commitment_total'], 6 );

		if ( $include_recent && $this->table_exists( $events_table ) ) {
			$event_params = array();
			$event_where  = $this->build_date_where_clause( 'DATE(e.created_at)', $range, $event_params );
			$defaults['recent_activity'] = $wpdb->get_results(
				$this->prepare_query(
					$wpdb,
					"SELECT e.event_type, e.entity_type, e.entity_id, e.message, e.created_at, e.actor_user_id, u.display_name AS actor_display_name
					FROM {$events_table} e
					LEFT JOIN {$wpdb->users} u ON u.ID = e.actor_user_id
					{$event_where}
					ORDER BY e.created_at DESC
					LIMIT 24",
					$event_params
				),
				ARRAY_A
			);
		}

		set_transient( $cache_key, $defaults, self::CACHE_TTL );

		return $defaults;
	}

	public function get_latest_documents( $limit = 20, array $args = array() ) {
		global $wpdb;

		$documents_table    = Tables::name( 'documents' );
		$contacts_table     = Tables::name( 'contacts' );
		$source_links_table = Tables::name( 'source_links' );

		if ( ! $this->table_exists( $documents_table ) ) {
			return array();
		}

		$limit       = max( 1, (int) $limit );
		$range       = $this->normalize_date_range( $args );
		$doc_params  = array();
		$doc_where   = $this->build_date_where_clause( "COALESCE(d.issue_date, DATE(d.created_at))", $range, $doc_params );

		return $wpdb->get_results(
			$this->prepare_query(
				$wpdb,
				"SELECT d.id, d.document_number, d.title, d.document_type, d.source_type, d.external_reference,
					d.financial_status, d.payment_status, d.financial_intent, d.total, d.balance, d.issue_date,
					d.contact_id, c.display_name AS contact_display_name, c.email AS contact_email,
					sl.provider AS linked_provider, sl.object_type AS linked_object_type,
					sl.external_id AS linked_external_id, sl.external_ref AS linked_external_ref
				FROM {$documents_table} d
				LEFT JOIN {$contacts_table} c ON c.id = d.contact_id
				LEFT JOIN {$source_links_table} sl ON sl.id = (
					SELECT sl2.id
					FROM {$source_links_table} sl2
					WHERE sl2.document_id = d.id
					ORDER BY sl2.id DESC
					LIMIT 1
				)
				{$doc_where}
				ORDER BY COALESCE(d.issue_date, DATE(d.created_at)) DESC, d.id DESC
				LIMIT %d",
				array_merge( $doc_params, array( $limit ) )
			),
			ARRAY_A
		);
	}

	private function normalize_date_range( array $args ) {
		$range_from = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to   = $this->sanitize_date( $args['range_to'] ?? '' );

		if ( $range_from && $range_to && $range_from > $range_to ) {
			$temp       = $range_from;
			$range_from = $range_to;
			$range_to   = $temp;
		}

		return array(
			'range_from' => $range_from,
			'range_to'   => $range_to,
		);
	}

	private function build_date_where_clause( $expression, array $range, array &$params ) {
		$clauses = array();

		if ( ! empty( $range['range_from'] ) ) {
			$clauses[] = "{$expression} >= %s";
			$params[]  = $range['range_from'];
		}

		if ( ! empty( $range['range_to'] ) ) {
			$clauses[] = "{$expression} <= %s";
			$params[]  = $range['range_to'];
		}

		if ( empty( $clauses ) ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $clauses );
	}

	private function prepare_query( $wpdb, $query, array $params ) {
		if ( empty( $params ) ) {
			return $query;
		}

		return call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $query ), $params ) );
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	private function table_exists( $table_name ) {
		global $wpdb;

		$sql = $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name );

		return $table_name === $wpdb->get_var( $sql );
	}

	private function count_rows( $table_name ) {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}
}
