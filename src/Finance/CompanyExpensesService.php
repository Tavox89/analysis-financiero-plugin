<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class CompanyExpensesService extends BaseRepository {
	protected $table_key = 'documents';

	public function get_snapshot( array $args = array() ) {
		$filters = $this->normalize_filters( $args );
		$items   = $this->query_items( $filters );

		return array(
			'filters' => $filters,
			'summary' => $this->build_summary( $filters ),
			'items'   => $items,
		);
	}

	public function normalize_filters( array $args = array() ) {
		$range_from = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to   = $this->sanitize_date( $args['range_to'] ?? '' );

		if ( ! empty( $range_from ) && ! empty( $range_to ) && $range_from > $range_to ) {
			$temp       = $range_from;
			$range_from = $range_to;
			$range_to   = $temp;
		}

		$has_contact = sanitize_key( (string) ( $args['has_contact'] ?? 'all' ) );
		if ( ! in_array( $has_contact, array( 'all', 'yes', 'no' ), true ) ) {
			$has_contact = 'all';
		}

		return array(
			'search'           => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'financial_status' => sanitize_key( (string) ( $args['financial_status'] ?? '' ) ),
			'payment_status'   => sanitize_key( (string) ( $args['payment_status'] ?? '' ) ),
			'open_only'        => ! empty( $args['open_only'] ),
			'has_contact'      => $has_contact,
			'contact_id'       => absint( $args['contact_id'] ?? 0 ),
			'range_from'       => $range_from,
			'range_to'         => $range_to,
			'limit'            => max( 1, min( 250, (int) ( $args['limit'] ?? 100 ) ) ),
		);
	}

	private function build_summary( array $filters ) {
		if ( ! $this->has_table() ) {
			return array(
				'total_issued'         => 0.0,
				'pending_total'        => 0.0,
				'open_count'           => 0,
				'paid_or_partial_count'=> 0,
				'without_contact_count'=> 0,
				'internal_count'       => 0,
				'internal_issued_total'=> 0.0,
				'total_count'          => 0,
			);
		}

		$wpdb           = $this->db();
		$contacts_table = Tables::name( 'contacts' );
		$where          = array( $this->expense_scope_where_sql() );
		$params         = array();

		if ( '' !== $filters['search'] ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(d.document_number LIKE %s OR d.title LIKE %s OR d.external_reference LIKE %s OR c.display_name LIKE %s OR c.email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $filters['contact_id'] > 0 ) {
			$where[]  = 'd.contact_id = %d';
			$params[] = $filters['contact_id'];
		} elseif ( 'yes' === $filters['has_contact'] ) {
			$where[] = 'COALESCE(d.contact_id, 0) > 0';
		} elseif ( 'no' === $filters['has_contact'] ) {
			$where[] = 'COALESCE(d.contact_id, 0) = 0';
		}

		if ( ! empty( $filters['open_only'] ) ) {
			$where[] = 'd.balance > 0';
		}

		foreach ( array( 'financial_status', 'payment_status' ) as $filter_key ) {
			if ( '' === $filters[ $filter_key ] ) {
				continue;
			}

			$where[]  = 'd.' . $filter_key . ' = %s';
			$params[] = $filters[ $filter_key ];
		}

		if ( ! empty( $filters['range_from'] ) ) {
			$where[]  = 'd.issue_date >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[]  = 'd.issue_date <= %s';
			$params[] = $filters['range_to'];
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT
				COUNT(*) AS total_count,
				COALESCE(SUM(CASE WHEN d.financial_status <> 'void' THEN d.total ELSE 0 END), 0) AS total_issued,
				COALESCE(SUM(CASE WHEN d.financial_status <> 'void' THEN d.balance ELSE 0 END), 0) AS pending_total,
				COALESCE(SUM(CASE WHEN d.financial_status <> 'void' AND d.balance > 0 THEN 1 ELSE 0 END), 0) AS open_count,
				COALESCE(SUM(CASE WHEN d.financial_status <> 'void' AND d.payment_status IN ('partial','paid') THEN 1 ELSE 0 END), 0) AS paid_or_partial_count,
				COALESCE(SUM(CASE WHEN COALESCE(d.contact_id, 0) = 0 THEN 1 ELSE 0 END), 0) AS without_contact_count,
				COALESCE(SUM(CASE WHEN d.financial_status <> 'void' AND d.financial_intent = 'internal_consumption' AND d.subcategory_key IN ('internal_expense', 'internal_gift') THEN 1 ELSE 0 END), 0) AS internal_count,
				COALESCE(SUM(CASE WHEN d.financial_status <> 'void' AND d.financial_intent = 'internal_consumption' AND d.subcategory_key IN ('internal_expense', 'internal_gift') THEN d.total ELSE 0 END), 0) AS internal_issued_total
			FROM {$this->table()} d
			LEFT JOIN {$contacts_table} c ON c.id = d.contact_id
			WHERE {$where_sql}";
		$row       = empty( $params )
			? $wpdb->get_row( $sql, ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		$row = is_array( $row ) ? $row : array();

		return array(
			'total_issued'          => round( (float) ( $row['total_issued'] ?? 0 ), 6 ),
			'pending_total'         => round( (float) ( $row['pending_total'] ?? 0 ), 6 ),
			'open_count'            => (int) ( $row['open_count'] ?? 0 ),
			'paid_or_partial_count' => (int) ( $row['paid_or_partial_count'] ?? 0 ),
			'without_contact_count' => (int) ( $row['without_contact_count'] ?? 0 ),
			'internal_count'        => (int) ( $row['internal_count'] ?? 0 ),
			'internal_issued_total' => round( (float) ( $row['internal_issued_total'] ?? 0 ), 6 ),
			'total_count'           => (int) ( $row['total_count'] ?? 0 ),
		);
	}

	private function query_items( array $filters ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb           = $this->db();
		$contacts_table = Tables::name( 'contacts' );
		$where          = array( $this->expense_scope_where_sql() );
		$params         = array();

		if ( '' !== $filters['search'] ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(d.document_number LIKE %s OR d.title LIKE %s OR d.external_reference LIKE %s OR c.display_name LIKE %s OR c.email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $filters['contact_id'] > 0 ) {
			$where[]  = 'd.contact_id = %d';
			$params[] = $filters['contact_id'];
		} elseif ( 'yes' === $filters['has_contact'] ) {
			$where[] = 'COALESCE(d.contact_id, 0) > 0';
		} elseif ( 'no' === $filters['has_contact'] ) {
			$where[] = 'COALESCE(d.contact_id, 0) = 0';
		}

		if ( ! empty( $filters['open_only'] ) ) {
			$where[] = 'd.balance > 0';
		}

		foreach ( array( 'financial_status', 'payment_status' ) as $filter_key ) {
			if ( '' === $filters[ $filter_key ] ) {
				continue;
			}

			$where[]  = 'd.' . $filter_key . ' = %s';
			$params[] = $filters[ $filter_key ];
		}

		if ( ! empty( $filters['range_from'] ) ) {
			$where[]  = 'COALESCE(d.issue_date, DATE(d.created_at)) >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[]  = 'COALESCE(d.issue_date, DATE(d.created_at)) <= %s';
			$params[] = $filters['range_to'];
		}

		$params[] = (int) $filters['limit'];
		$sql      = "SELECT
				d.id,
				d.document_number,
				d.document_type,
				d.financial_intent,
				d.subcategory_key,
				d.title,
				d.external_reference,
				d.issue_date,
				d.due_date,
				d.currency,
				d.total,
				d.balance,
				d.payment_status,
				d.financial_status,
				d.contact_id,
				c.display_name AS contact_display_name,
				c.email AS contact_email
			FROM {$this->table()} d
			LEFT JOIN {$contacts_table} c ON c.id = d.contact_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY COALESCE(d.issue_date, DATE(d.created_at)) DESC, d.id DESC
			LIMIT %d';

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, ...$params ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map(
			function ( array $row ) {
				$row['expense_origin_key']   = $this->expense_origin_key( $row );
				$row['expense_origin_label'] = $this->expense_origin_label( $row );
				return $row;
			},
			$rows
		);
	}

	private function expense_scope_where_sql() {
		return "(d.document_type = 'external_expense' OR (d.financial_intent = 'internal_consumption' AND d.subcategory_key IN ('internal_expense', 'internal_gift')))";
	}

	private function expense_origin_key( array $row ) {
		$document_type     = sanitize_key( (string) ( $row['document_type'] ?? '' ) );
		$financial_intent  = sanitize_key( (string) ( $row['financial_intent'] ?? '' ) );
		$subcategory_key   = sanitize_key( (string) ( $row['subcategory_key'] ?? '' ) );

		if ( 'internal_consumption' === $financial_intent ) {
			if ( 'internal_gift' === $subcategory_key ) {
				return 'internal_gift';
			}

			return 'internal_expense';
		}

		if ( 'external_expense' === $document_type ) {
			return 'external_expense';
		}

		return $document_type;
	}

	private function expense_origin_label( array $row ) {
		switch ( $this->expense_origin_key( $row ) ) {
			case 'internal_gift':
				return 'Regalo interno';
			case 'internal_expense':
				return 'Consumo interno';
			case 'external_expense':
			default:
				return 'Gasto externo';
		}
	}
}
