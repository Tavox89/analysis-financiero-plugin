<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class SearchIndexBackfillService {
	const BATCH_SIZE = 250;

	public function rebuild_all() {
		$this->rebuild_contacts();
		$this->rebuild_documents();
		$this->rebuild_payments();
		$this->rebuild_commerce_order_index();
		$this->rebuild_integrity_cases();
		$this->rebuild_events();
	}

	private function rebuild_contacts() {
		$table = Tables::name( 'contacts' );

		$this->backfill_table(
			$table,
			'c',
			'c.id, c.display_name, c.legal_name, c.email, c.phone, c.document_id, c.wp_user_id',
			static function ( array $row ) {
				return SearchIndexService::build_index(
					array(
						$row['id'] ?? '',
						$row['wp_user_id'] ?? '',
						$row['display_name'] ?? '',
						$row['legal_name'] ?? '',
						$row['email'] ?? '',
						$row['phone'] ?? '',
						$row['document_id'] ?? '',
					)
				);
			}
		);
	}

	private function rebuild_documents() {
		$table = Tables::name( 'documents' );

		$this->backfill_table(
			$table,
			'd',
			'd.id, d.document_number, d.title, d.external_reference',
			static function ( array $row ) {
				return SearchIndexService::build_index(
					array(
						$row['id'] ?? '',
						$row['document_number'] ?? '',
						$row['title'] ?? '',
						$row['external_reference'] ?? '',
					)
				);
			}
		);
	}

	private function rebuild_payments() {
		$table = Tables::name( 'payments' );

		$this->backfill_table(
			$table,
			'p',
			'p.id, p.payment_number, p.reference',
			static function ( array $row ) {
				return SearchIndexService::build_index(
					array(
						$row['id'] ?? '',
						$row['payment_number'] ?? '',
						$row['reference'] ?? '',
					)
				);
			}
		);
	}

	private function rebuild_commerce_order_index() {
		$table = Tables::name( 'commerce_order_index' );

		$this->backfill_table(
			$table,
			'coi',
			'coi.id, coi.external_order_id, coi.order_number, coi.customer_email, coi.display_name, coi.group_key',
			static function ( array $row ) {
				return SearchIndexService::build_index(
					array(
						$row['id'] ?? '',
						$row['external_order_id'] ?? '',
						$row['order_number'] ?? '',
						$row['customer_email'] ?? '',
						$row['display_name'] ?? '',
						$row['group_key'] ?? '',
					)
				);
			}
		);
	}

	private function rebuild_integrity_cases() {
		$table = Tables::name( 'integrity_cases' );

		$this->backfill_table(
			$table,
			'ic',
			'ic.id, ic.case_key, ic.case_type, ic.contact_label, ic.order_number, ic.summary, ic.batch_id, ic.external_order_id, ic.document_id, ic.payment_id',
			static function ( array $row ) {
				return SearchIndexService::build_index(
					array(
						$row['id'] ?? '',
						$row['case_key'] ?? '',
						$row['case_type'] ?? '',
						$row['contact_label'] ?? '',
						$row['order_number'] ?? '',
						$row['summary'] ?? '',
						$row['batch_id'] ?? '',
						$row['external_order_id'] ?? '',
						$row['document_id'] ?? '',
						$row['payment_id'] ?? '',
					)
				);
			}
		);
	}

	private function rebuild_events() {
		global $wpdb;

		$table = Tables::name( 'events' );

		$this->backfill_table(
			$table,
			'e',
			"e.id, e.entity_type, e.entity_id, e.event_type, e.message, COALESCE(u.display_name, '') AS actor_display_name",
			static function ( array $row ) {
				return SearchIndexService::build_index(
					array(
						$row['id'] ?? '',
						$row['entity_type'] ?? '',
						$row['entity_id'] ?? '',
						$row['event_type'] ?? '',
						$row['message'] ?? '',
						$row['actor_display_name'] ?? '',
					)
				);
			},
			"LEFT JOIN {$wpdb->users} u ON u.ID = e.actor_user_id"
		);
	}

	private function backfill_table( $table, $alias, $select_sql, callable $builder, $join_sql = '' ) {
		global $wpdb;

		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$alias   = trim( (string) $alias );
		$last_id = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$select_sql}
					FROM {$table} {$alias}
					{$join_sql}
					WHERE {$alias}.id > %d
					ORDER BY {$alias}.id ASC
					LIMIT %d",
					$last_id,
					self::BATCH_SIZE
				),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$row_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
				if ( $row_id <= 0 ) {
					continue;
				}

				$wpdb->update(
					$table,
					array(
						'search_index' => (string) call_user_func( $builder, $row ),
					),
					array( 'id' => $row_id ),
					array( '%s' ),
					array( '%d' )
				);

				$last_id = $row_id;
			}
		}
	}

	private function table_exists( $table ) {
		global $wpdb;

		$match = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		return $match === $table;
	}
}
