<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class CommerceContactRollupsRepository extends BaseRepository {
	protected $table_key = 'commerce_contact_rollups';

	public function rebuild_for_year( $fiscal_year ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_rollups_missing', 'La tabla de rollups historicos aun no esta disponible.' );
		}

		$fiscal_year = absint( $fiscal_year );
		if ( $fiscal_year <= 0 ) {
			return $this->error( 'asdl_fin_rollups_year', 'Debes indicar un ejercicio fiscal valido.' );
		}

		$index_table = Tables::name( 'commerce_order_index' );
		$wpdb        = $this->db();
		$rows        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					group_key,
					MAX(contact_id) AS contact_id,
					MAX(wp_user_id) AS wp_user_id,
					MAX(customer_email) AS customer_email,
					MAX(display_name) AS display_name,
					fiscal_year,
					COUNT(*) AS order_count,
					COALESCE(SUM(CASE WHEN is_open = 1 THEN 1 ELSE 0 END), 0) AS open_order_count,
					COALESCE(SUM(gross_total), 0) AS gross_total,
					COALESCE(SUM(paid_total), 0) AS paid_total,
					COALESCE(SUM(balance), 0) AS balance_total,
					COALESCE(SUM(CASE WHEN operationally_collectible = 1 THEN balance ELSE 0 END), 0) AS collectible_balance_total,
					COALESCE(SUM(CASE WHEN historical_resolution_status = 'administratively_closed' THEN balance ELSE 0 END), 0) AS administratively_closed_balance,
					MIN(issue_date) AS first_order_date,
					MAX(issue_date) AS last_order_date
				FROM {$index_table}
				WHERE fiscal_year = %d
				GROUP BY group_key, fiscal_year
				ORDER BY group_key ASC",
				$fiscal_year
			),
			ARRAY_A
		);

		$wpdb->delete( $this->table(), array( 'fiscal_year' => $fiscal_year ), array( '%d' ) );
		if ( empty( $rows ) ) {
			return array(
				'fiscal_year' => $fiscal_year,
				'rollup_count' => 0,
			);
		}

		$now = $this->now();
		foreach ( $rows as $row ) {
			$order_count = max( 0, (int) ( $row['order_count'] ?? 0 ) );
			$gross_total = round( (float) ( $row['gross_total'] ?? 0 ), 6 );
			$average     = $order_count > 0 ? round( $gross_total / $order_count, 6 ) : 0;
			$payload     = array(
				'group_key'                       => sanitize_text_field( (string) ( $row['group_key'] ?? '' ) ),
				'contact_id'                      => ! empty( $row['contact_id'] ) ? absint( $row['contact_id'] ) : null,
				'wp_user_id'                      => ! empty( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : null,
				'customer_email'                  => sanitize_email( (string) ( $row['customer_email'] ?? '' ) ),
				'display_name'                    => sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
				'fiscal_year'                     => $fiscal_year,
				'order_count'                     => $order_count,
				'open_order_count'                => max( 0, (int) ( $row['open_order_count'] ?? 0 ) ),
				'gross_total'                     => $gross_total,
				'paid_total'                      => round( (float) ( $row['paid_total'] ?? 0 ), 6 ),
				'balance_total'                   => round( (float) ( $row['balance_total'] ?? 0 ), 6 ),
				'collectible_balance_total'       => round( (float) ( $row['collectible_balance_total'] ?? 0 ), 6 ),
				'administratively_closed_balance' => round( (float) ( $row['administratively_closed_balance'] ?? 0 ), 6 ),
				'first_order_date'                => $this->sanitize_date( $row['first_order_date'] ?? '' ),
				'last_order_date'                 => $this->sanitize_date( $row['last_order_date'] ?? '' ),
				'average_ticket'                  => $average,
				'meta_json'                       => null,
				'created_at'                      => $now,
				'updated_at'                      => $now,
			);
			$wpdb->insert(
				$this->table(),
				$payload,
				array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%f', '%s', '%s' )
			);
		}

		return array(
			'fiscal_year'  => $fiscal_year,
			'rollup_count' => count( $rows ),
		);
	}

	public function delete_for_year( $fiscal_year ) {
		if ( ! $this->has_table() ) {
			return 0;
		}

		$result = $this->db()->delete(
			$this->table(),
			array( 'fiscal_year' => absint( $fiscal_year ) ),
			array( '%d' )
		);

		return false === $result ? 0 : (int) $result;
	}

	public function list_year_stats() {
		if ( ! $this->has_table() ) {
			return array();
		}

		return $this->db()->get_results(
			"SELECT
				fiscal_year,
				COUNT(*) AS rollup_count,
				COALESCE(SUM(order_count), 0) AS order_count,
				COALESCE(SUM(collectible_balance_total), 0) AS collectible_balance_total,
				COALESCE(SUM(administratively_closed_balance), 0) AS administratively_closed_balance,
				MAX(updated_at) AS updated_at
			FROM {$this->table()}
			GROUP BY fiscal_year
			ORDER BY fiscal_year DESC",
			ARRAY_A
		);
	}

	public function summarize_for_identity( array $identity, $fiscal_year_from = 0, $fiscal_year_to = 0 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$where    = array();
		$params   = array();
		$group_key = sanitize_text_field( (string) ( $identity['group_key'] ?? '' ) );
		$contact_id = absint( $identity['contact_id'] ?? 0 );
		$wp_user_id = absint( $identity['wp_user_id'] ?? 0 );
		$email      = sanitize_email( (string) ( $identity['email'] ?? '' ) );

		if ( '' !== $group_key ) {
			$where[]  = 'group_key = %s';
			$params[] = $group_key;
		} elseif ( $contact_id > 0 ) {
			$where[]  = 'contact_id = %d';
			$params[] = $contact_id;
		} elseif ( $wp_user_id > 0 ) {
			$where[]  = 'wp_user_id = %d';
			$params[] = $wp_user_id;
		} elseif ( '' !== $email ) {
			$where[]  = 'customer_email = %s';
			$params[] = $email;
		} else {
			return array();
		}

		if ( $fiscal_year_from > 0 ) {
			$where[]  = 'fiscal_year >= %d';
			$params[] = absint( $fiscal_year_from );
		}

		if ( $fiscal_year_to > 0 ) {
			$where[]  = 'fiscal_year <= %d';
			$params[] = absint( $fiscal_year_to );
		}

		$sql = "SELECT
			COALESCE(SUM(order_count), 0) AS order_count,
			COALESCE(SUM(open_order_count), 0) AS open_order_count,
			COALESCE(SUM(gross_total), 0) AS gross_total,
			COALESCE(SUM(paid_total), 0) AS paid_total,
			COALESCE(SUM(balance_total), 0) AS balance_total,
			COALESCE(SUM(collectible_balance_total), 0) AS collectible_balance_total,
			COALESCE(SUM(administratively_closed_balance), 0) AS administratively_closed_balance,
			MIN(first_order_date) AS first_order_date,
			MAX(last_order_date) AS last_order_date
			FROM {$this->table()}
			WHERE " . implode( ' AND ', $where );

		return $this->db()->get_row(
			$this->db()->prepare( $sql, $params ),
			ARRAY_A
		);
	}
}
