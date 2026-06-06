<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;
use WP_Error;

final class ContactMergeService {
	private $contacts;
	private $events;

	public function __construct() {
		$this->contacts = new ContactsRepository();
		$this->events   = new EventsRepository();
	}

	public function dryRun( $winner_contact_id, array $loser_contact_ids, $winner_wp_user_id = 0, array $context = array() ) {
		$winner_contact_id = absint( $winner_contact_id );
		$loser_contact_ids = $this->normalize_contact_ids( $loser_contact_ids, $winner_contact_id );
		$winner_wp_user_id = absint( $winner_wp_user_id );

		$contacts = $this->load_contacts( array_merge( array( $winner_contact_id ), $loser_contact_ids ) );
		$blockers = array();
		$warnings = array();

		if ( $winner_contact_id <= 0 || empty( $contacts[ $winner_contact_id ] ) ) {
			$blockers[] = 'winner_contact_missing';
		}

		foreach ( $loser_contact_ids as $contact_id ) {
			if ( empty( $contacts[ $contact_id ] ) ) {
				$blockers[] = 'loser_contact_missing';
			}
		}

		$all_contact_ids = array_values( array_keys( $contacts ) );
		$metrics         = $this->load_metrics( $all_contact_ids );
		$document_ids    = $this->load_document_ids( $loser_contact_ids );
		$employee_count  = $this->metric_sum( $metrics, 'employee_profiles_count' );
		$active_batches  = $this->metric_sum( $metrics, 'active_settlement_batches_count' ) + $this->metric_sum( $metrics, 'active_assumption_batches_count' );
		$document_issue  = $this->detect_document_conflict( $contacts, (string) ( $context['document_id'] ?? '' ) );

		if ( $employee_count > 1 ) {
			$blockers[] = 'multiple_employee_profiles';
		}

		if ( $winner_wp_user_id > 0 && $this->employee_profile_wp_user_conflict( $winner_wp_user_id, $all_contact_ids ) ) {
			$blockers[] = 'employee_profile_wp_user_conflict';
		}

		if ( $active_batches > 0 ) {
			$blockers[] = 'active_finance_batches';
		}

		if ( ! empty( $document_issue ) ) {
			$blockers[] = 'document_conflict';
			$warnings[] = $document_issue;
		}

		if ( $winner_wp_user_id > 0 ) {
			$linked = $this->contact_linked_to_other_wp_user( $winner_contact_id, $winner_wp_user_id, $contacts );

			if ( $linked > 0 ) {
				$warnings[] = sprintf( 'El contacto ganador #%1$d está enlazado al usuario WP #%2$d y se actualizará a #%3$d.', $winner_contact_id, $linked, $winner_wp_user_id );
			}
		}

		return array(
			'allowed'            => empty( $blockers ),
			'blockers'           => array_values( array_unique( $blockers ) ),
			'warnings'           => array_values( array_unique( array_filter( $warnings ) ) ),
			'winner_contact_id'  => $winner_contact_id,
			'winner_wp_user_id'  => $winner_wp_user_id,
			'loser_contact_ids'  => $loser_contact_ids,
			'contact_ids'        => $all_contact_ids,
			'metrics'            => $metrics,
			'affected_document_ids' => $document_ids,
			'operation_counts'   => $this->operation_counts( $loser_contact_ids ),
		);
	}

	public function execute( $winner_contact_id, array $loser_contact_ids, $winner_wp_user_id = 0, array $context = array() ) {
		$dry_run = $this->dryRun( $winner_contact_id, $loser_contact_ids, $winner_wp_user_id, $context );

		if ( empty( $dry_run['allowed'] ) ) {
			return new WP_Error(
				'asdl_fin_contact_merge_blocked',
				'El merge financiero no puede ejecutarse por bloqueos activos.',
				$dry_run
			);
		}

		$winner_contact_id = absint( $dry_run['winner_contact_id'] ?? 0 );
		$loser_contact_ids = array_values( array_filter( array_map( 'absint', (array) ( $dry_run['loser_contact_ids'] ?? array() ) ) ) );
		$winner_wp_user_id = absint( $dry_run['winner_wp_user_id'] ?? 0 );
		$before_contacts   = $this->load_contacts( array_merge( array( $winner_contact_id ), $loser_contact_ids ) );
		$document_ids      = (array) ( $dry_run['affected_document_ids'] ?? array() );
		$order_ids         = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $context['moved_order_ids'] ?? array() ) ) ) ) );
		$actor_user_id     = absint( $context['actor_user_id'] ?? get_current_user_id() );
		$updated_counts    = array();

		$this->begin_transaction();

		try {
			$updated_counts['documents'] = $this->reparent_table(
				Tables::name( 'documents' ),
				$loser_contact_ids,
				$winner_contact_id,
				'contact_id',
				$winner_wp_user_id > 0 ? array( 'wp_user_id' => $winner_wp_user_id ) : array()
			);
			$updated_counts['payments'] = $this->reparent_table( Tables::name( 'payments' ), $loser_contact_ids, $winner_contact_id );
			$updated_counts['service_profiles'] = $this->reparent_table( Tables::name( 'service_profiles' ), $loser_contact_ids, $winner_contact_id );
			$updated_counts['installment_plans'] = $this->reparent_table( Tables::name( 'installment_plans' ), $loser_contact_ids, $winner_contact_id );
			$updated_counts['employee_advances'] = $this->reparent_table(
				Tables::name( 'employee_advances' ),
				$loser_contact_ids,
				$winner_contact_id,
				'contact_id',
				$winner_wp_user_id > 0 ? array( 'wp_user_id' => $winner_wp_user_id ) : array()
			);
			$updated_counts['payroll_periods'] = $this->reparent_table(
				Tables::name( 'payroll_periods' ),
				$loser_contact_ids,
				$winner_contact_id,
				'contact_id',
				$winner_wp_user_id > 0 ? array( 'wp_user_id' => $winner_wp_user_id ) : array()
			);
			$updated_counts['order_settlement_batches'] = $this->reparent_table( Tables::name( 'order_settlement_batches' ), $loser_contact_ids, $winner_contact_id );
			$updated_counts['order_assumption_batches'] = $this->reparent_table( Tables::name( 'order_assumption_batches' ), $loser_contact_ids, $winner_contact_id );
			$updated_counts['employee_profiles'] = $this->move_single_employee_profile( $winner_contact_id, $loser_contact_ids, $winner_wp_user_id );

			$this->update_winner_contact( $winner_contact_id, $winner_wp_user_id, $context );
			$this->mark_loser_contacts( $loser_contact_ids, $winner_contact_id, $actor_user_id );

			$this->commit_transaction();
		} catch ( \Exception $exception ) {
			$this->rollback_transaction();

			return new WP_Error( 'asdl_fin_contact_merge_failed', $exception->getMessage(), $dry_run );
		}

		$this->refresh_after_merge( $winner_contact_id, $loser_contact_ids, $document_ids, $order_ids );

		$summary = array(
			'action'              => 'contact_merge',
			'winner_contact_id'   => $winner_contact_id,
			'winner_wp_user_id'   => $winner_wp_user_id,
			'loser_contact_ids'   => $loser_contact_ids,
			'updated_counts'      => $updated_counts,
			'affected_order_ids'  => $order_ids,
			'affected_document_ids' => array_values( array_unique( array_filter( array_map( 'absint', $document_ids ) ) ) ),
			'before_contacts'     => $before_contacts,
			'context'             => $this->sanitize_context( $context ),
		);

		$this->events->log(
			'contact',
			$winner_contact_id,
			'asdl_fin_contact_merged',
			'Perfiles financieros unificados desde normalización de cliente.',
			$summary,
			$actor_user_id > 0 ? $actor_user_id : null
		);

		return $summary;
	}

	private function normalize_contact_ids( array $contact_ids, $winner_contact_id ) {
		$winner_contact_id = absint( $winner_contact_id );
		$normalized = array();

		foreach ( $contact_ids as $contact_id ) {
			$contact_id = absint( $contact_id );

			if ( $contact_id > 0 && $contact_id !== $winner_contact_id ) {
				$normalized[ $contact_id ] = $contact_id;
			}
		}

		return array_values( $normalized );
	}

	private function load_contacts( array $contact_ids ) {
		$contacts = array();

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) ) as $contact_id ) {
			$contact = $this->contacts->find( $contact_id );

			if ( ! empty( $contact['id'] ) ) {
				$contacts[ absint( $contact['id'] ) ] = $contact;
			}
		}

		return $contacts;
	}

	private function load_metrics( array $contact_ids ) {
		global $wpdb;

		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		$metrics     = array();

		foreach ( $contact_ids as $contact_id ) {
			$metrics[ $contact_id ] = array(
				'documents_count' => 0,
				'payments_count' => 0,
				'service_profiles_count' => 0,
				'installment_plans_count' => 0,
				'employee_profiles_count' => 0,
				'employee_advances_count' => 0,
				'payroll_periods_count' => 0,
				'order_settlement_batches_count' => 0,
				'order_assumption_batches_count' => 0,
				'active_settlement_batches_count' => 0,
				'active_assumption_batches_count' => 0,
			);
		}

		if ( empty( $metrics ) ) {
			return $metrics;
		}

		$tables = array(
			'documents_count' => array( Tables::name( 'documents' ), 'contact_id', '' ),
			'payments_count' => array( Tables::name( 'payments' ), 'contact_id', '' ),
			'service_profiles_count' => array( Tables::name( 'service_profiles' ), 'contact_id', '' ),
			'installment_plans_count' => array( Tables::name( 'installment_plans' ), 'contact_id', '' ),
			'employee_profiles_count' => array( Tables::name( 'employee_profiles' ), 'contact_id', '' ),
			'employee_advances_count' => array( Tables::name( 'employee_advances' ), 'contact_id', "status <> 'cancelled'" ),
			'payroll_periods_count' => array( Tables::name( 'payroll_periods' ), 'contact_id', "status NOT IN ('cancelled')" ),
			'order_settlement_batches_count' => array( Tables::name( 'order_settlement_batches' ), 'contact_id', '' ),
			'order_assumption_batches_count' => array( Tables::name( 'order_assumption_batches' ), 'contact_id', '' ),
			'active_settlement_batches_count' => array( Tables::name( 'order_settlement_batches' ), 'contact_id', "status NOT IN ('completed', 'cancelled')" ),
			'active_assumption_batches_count' => array( Tables::name( 'order_assumption_batches' ), 'contact_id', "status NOT IN ('completed', 'cancelled')" ),
		);

		foreach ( $tables as $metric_key => $definition ) {
			list( $table, $column, $extra_where ) = $definition;
			$placeholders = implode( ', ', array_fill( 0, count( $contact_ids ), '%d' ) );
			$where = "{$column} IN ({$placeholders})";

			if ( '' !== $extra_where ) {
				$where .= ' AND ' . $extra_where;
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$column} AS contact_id, COUNT(*) AS total FROM {$table} WHERE {$where} GROUP BY {$column}",
					...$contact_ids
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$contact_id = absint( $row['contact_id'] ?? 0 );

				if ( isset( $metrics[ $contact_id ] ) ) {
					$metrics[ $contact_id ][ $metric_key ] = absint( $row['total'] ?? 0 );
				}
			}
		}

		return $metrics;
	}

	private function load_document_ids( array $contact_ids ) {
		global $wpdb;

		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );

		if ( empty( $contact_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $contact_ids ), '%d' ) );

		return array_values(
			array_filter(
				array_map(
					'absint',
					(array) $wpdb->get_col(
						$wpdb->prepare(
							'SELECT id FROM ' . Tables::name( 'documents' ) . " WHERE contact_id IN ({$placeholders})",
							...$contact_ids
						)
					)
				)
			)
		);
	}

	private function operation_counts( array $loser_contact_ids ) {
		$metrics = $this->load_metrics( $loser_contact_ids );
		$counts  = array();

		foreach ( $metrics as $contact_metrics ) {
			foreach ( $contact_metrics as $key => $value ) {
				$counts[ $key ] = absint( $counts[ $key ] ?? 0 ) + absint( $value );
			}
		}

		return $counts;
	}

	private function metric_sum( array $metrics, $key ) {
		$total = 0;

		foreach ( $metrics as $metric ) {
			$total += absint( $metric[ $key ] ?? 0 );
		}

		return $total;
	}

	private function detect_document_conflict( array $contacts, $requested_document ) {
		$keys = array();

		foreach ( $contacts as $contact ) {
			$key = $this->document_key( (string) ( $contact['document_id'] ?? '' ) );

			if ( '' !== $key ) {
				$keys[ $key ] = $key;
			}
		}

		$requested_key = $this->document_key( $requested_document );

		if ( '' !== $requested_key ) {
			foreach ( array_keys( $keys ) as $key ) {
				if ( $key !== $requested_key ) {
					return 'Hay documentos financieros distintos al documento final propuesto.';
				}
			}
		}

		if ( count( $keys ) > 1 ) {
			return 'Hay más de un documento financiero distinto entre los contactos seleccionados.';
		}

		return '';
	}

	private function document_key( $value ) {
		return preg_replace( '/[^A-Z0-9]/', '', strtoupper( trim( (string) $value ) ) );
	}

	private function contact_linked_to_other_wp_user( $contact_id, $winner_wp_user_id, array $contacts ) {
		$contact = $contacts[ absint( $contact_id ) ] ?? array();
		$current_wp_user_id = absint( $contact['wp_user_id'] ?? 0 );

		return $current_wp_user_id > 0 && $current_wp_user_id !== absint( $winner_wp_user_id ) ? $current_wp_user_id : 0;
	}

	private function employee_profile_wp_user_conflict( $winner_wp_user_id, array $selected_contact_ids ) {
		global $wpdb;

		$winner_wp_user_id = absint( $winner_wp_user_id );
		$selected_contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $selected_contact_ids ) ) ) );

		if ( $winner_wp_user_id < 1 || empty( $selected_contact_ids ) ) {
			return false;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $selected_contact_ids ), '%d' ) );
		$table = Tables::name( 'employee_profiles' );
		$args  = array_merge( array( $winner_wp_user_id ), $selected_contact_ids );
		$profile_id = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE wp_user_id = %d AND contact_id NOT IN ({$placeholders}) LIMIT 1",
					...$args
				)
			)
		);

		return $profile_id > 0;
	}

	private function reparent_table( $table, array $loser_contact_ids, $winner_contact_id, $contact_column = 'contact_id', array $extra_updates = array() ) {
		global $wpdb;

		$loser_contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $loser_contact_ids ) ) ) );

		if ( empty( $loser_contact_ids ) ) {
			return 0;
		}

		$updates = array_merge(
			array(
				$contact_column => absint( $winner_contact_id ),
			),
			$extra_updates
		);
		$sets = array();
		$args = array();

		foreach ( $updates as $column => $value ) {
			$sets[] = sanitize_key( $column ) . ' = %d';
			$args[] = absint( $value );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $loser_contact_ids ), '%d' ) );
		$args = array_merge( $args, $loser_contact_ids );
		$sql = "UPDATE {$table} SET " . implode( ', ', $sets ) . " WHERE {$contact_column} IN ({$placeholders})";
		$result = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );

		if ( false === $result ) {
			throw new \RuntimeException( 'No se pudo reparentar la tabla financiera ' . $table . '.' );
		}

		return absint( $result );
	}

	private function move_single_employee_profile( $winner_contact_id, array $loser_contact_ids, $winner_wp_user_id ) {
		global $wpdb;

		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', array_merge( array( $winner_contact_id ), $loser_contact_ids ) ) ) ) );

		if ( empty( $contact_ids ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $contact_ids ), '%d' ) );
		$table = Tables::name( 'employee_profiles' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, contact_id FROM {$table} WHERE contact_id IN ({$placeholders})",
				...$contact_ids
			),
			ARRAY_A
		);

		if ( count( $rows ) > 1 ) {
			throw new \RuntimeException( 'Hay más de un perfil de empleado entre los contactos seleccionados.' );
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$updates = array( 'contact_id' => absint( $winner_contact_id ) );

		if ( absint( $winner_wp_user_id ) > 0 ) {
			$updates['wp_user_id'] = absint( $winner_wp_user_id );
		}

		if ( absint( $rows[0]['contact_id'] ?? 0 ) === absint( $winner_contact_id ) && absint( $winner_wp_user_id ) <= 0 ) {
			return 0;
		}

		$result = $wpdb->update(
			$table,
			$updates,
			array( 'id' => absint( $rows[0]['id'] ?? 0 ) ),
			array_fill( 0, count( $updates ), '%d' ),
			array( '%d' )
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'No se pudo mover el perfil de empleado financiero.' );
		}

		return absint( $result );
	}

	private function update_winner_contact( $winner_contact_id, $winner_wp_user_id, array $context ) {
		$data = array();

		if ( absint( $winner_wp_user_id ) > 0 ) {
			$data['wp_user_id'] = absint( $winner_wp_user_id );
			$data['profile_origin'] = 'wp_user';
		}

		foreach ( array( 'display_name', 'legal_name', 'email', 'phone', 'document_id' ) as $field ) {
			if ( isset( $context[ $field ] ) && '' !== trim( (string) $context[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( (string) $context[ $field ] );
			}
		}

		if ( empty( $data ) ) {
			return;
		}

		$result = $this->contacts->update( $winner_contact_id, $data );

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}
	}

	private function mark_loser_contacts( array $loser_contact_ids, $winner_contact_id, $actor_user_id ) {
		global $wpdb;

		$loser_contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $loser_contact_ids ) ) ) );

		if ( empty( $loser_contact_ids ) ) {
			return;
		}

		$table = Tables::name( 'contacts' );
		$placeholders = implode( ', ', array_fill( 0, count( $loser_contact_ids ), '%d' ) );
		$note = sprintf(
			'Fusionado al contacto #%1$d por usuario WP #%2$d el %3$s.',
			absint( $winner_contact_id ),
			absint( $actor_user_id ),
			current_time( 'mysql' )
		);
		$args = array_merge( array( $note, current_time( 'mysql' ) ), $loser_contact_ids );
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'merged',
					wp_user_id = NULL,
					notes = TRIM(CONCAT(COALESCE(notes, ''), '\n', %s)),
					updated_at = %s
				WHERE id IN ({$placeholders})",
				...$args
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'No se pudieron marcar los contactos financieros alternos como fusionados.' );
		}
	}

	private function refresh_after_merge( $winner_contact_id, array $loser_contact_ids, array $document_ids, array $order_ids ) {
		RuntimeRefreshService::invalidate(
			array(
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
				RuntimeRefreshService::SCOPE_PAYROLL,
				RuntimeRefreshService::SCOPE_HISTORICAL_DATA,
			)
		);

		foreach ( array_merge( array( $winner_contact_id ), $loser_contact_ids ) as $contact_id ) {
			RuntimeRefreshService::invalidate(
				array( RuntimeRefreshService::SCOPE_CONTACT ),
				array( 'contact_id' => absint( $contact_id ) )
			);
		}

		OrderSyncService::invalidate_cached_views();

		$historical = new HistoricalIndexRebuildService();

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $document_ids ) ) ) ) as $document_id ) {
			$historical->refresh_document_links( $document_id );
		}

		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) ) as $order_id ) {
			$historical->refresh_order_index( $order_id );
		}

		$historical->refresh_contact_identity( $winner_contact_id );
	}

	private function sanitize_context( array $context ) {
		$allowed = array(
			'case_id',
			'reason',
			'document_id',
			'display_name',
			'legal_name',
			'email',
			'phone',
			'actor_user_id',
			'source',
			'moved_order_ids',
		);
		$result = array();

		foreach ( $allowed as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$result[ $key ] = 'moved_order_ids' === $key
					? array_values( array_unique( array_filter( array_map( 'absint', (array) $context[ $key ] ) ) ) )
					: ( is_scalar( $context[ $key ] ) ? sanitize_text_field( (string) $context[ $key ] ) : $context[ $key ] );
			}
		}

		return $result;
	}

	private function begin_transaction() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	private function commit_transaction() {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	private function rollback_transaction() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}
}
