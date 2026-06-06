<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class ContactOverviewService {
	private static function cache_bust_key( $contact_id ) {
		return 'asdl_fin_contact_snapshot_version_' . absint( $contact_id );
	}

	public static function bump_contact_snapshot_cache_version( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return;
		}

		set_transient(
			self::cache_bust_key( $contact_id ),
			(string) microtime( true ),
			30 * DAY_IN_SECONDS
		);
	}

	private function contact_snapshot_cache_version( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return '0';
		}

		$version = get_transient( self::cache_bust_key( $contact_id ) );

		return is_scalar( $version ) && '' !== (string) $version ? (string) $version : '0';
	}

	public function get_contact_snapshot_cached( $contact_id, array $args = array() ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return null;
		}

		$filters = $this->normalize_order_filters( $args );
		$cache_key = 'asdl_fin_contact_snapshot_' . md5(
			wp_json_encode(
				array(
					'version'     => defined( 'ASDL_FINANCE_VERSION' ) ? ASDL_FINANCE_VERSION : 'dev',
					'cache_bust'  => $this->contact_snapshot_cache_version( $contact_id ),
					'contact_id'  => $contact_id,
					'range_from'  => $filters['range_from'] ?? '',
					'range_to'    => $filters['range_to'] ?? '',
					'order_limit' => (int) ( $filters['order_limit'] ?? 25 ),
				)
			)
		);

		$cached = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$snapshot = $this->get_contact_snapshot( $contact_id, $filters );
		if ( is_array( $snapshot ) ) {
			set_transient( $cache_key, $snapshot, MINUTE_IN_SECONDS * 5 );
		}

		return $snapshot;
	}

	public function get_contact_snapshot( $contact_id, array $args = array() ) {
		$contacts    = new ContactsRepository();
		$documents   = new DocumentsRepository();
		$employee_profiles = new EmployeeProfilesRepository();
		$employee_advances = new EmployeeAdvancesRepository();
		$payroll_periods = new PayrollPeriodsRepository();
		$payroll_queue   = new PayrollQueueService();
		$payments    = new PaymentsRepository();
		$plans       = new InstallmentPlansRepository();
		$services    = new ServiceProfilesRepository();
		$allocations = new PaymentAllocationsRepository();
		$contact     = $contacts->find( $contact_id );

		if ( empty( $contact ) ) {
			return null;
		}

		global $wpdb;

		$documents_table = Tables::name( 'documents' );
		$payments_table  = Tables::name( 'payments' );
		$filters         = $this->normalize_order_filters( $args );
		$orders_snapshot  = $this->build_orders_snapshot( $contact, $filters );
		$pending_snapshot = $this->build_pending_orders_snapshot( $contact, $filters );
		$service_snapshot = $this->build_services_snapshot( $contact_id, $services, $documents );
		$historical_consumption_snapshot = $this->build_historical_consumption_snapshot( $contact );
		$all_time_consumption_snapshot   = $this->build_all_time_consumption_snapshot( $contact, $historical_consumption_snapshot );

		$summary = array(
			'document_count'          => 0,
			'open_document_count'     => 0,
			'receivable_total'        => 0,
			'non_order_receivable_total' => 0,
			'managed_order_receivable_total' => 0,
			'managed_order_total'     => 0,
			'managed_order_paid_total'=> 0,
			'payable_total'           => 0,
			'credit_total'            => 0,
			'usable_credit_total'     => 0,
			'consolidated_receivable_total' => 0,
			'net_position_total'      => 0,
			'payment_count'           => 0,
			'payments_total'          => 0,
			'unapplied_payment_total' => 0,
			'installment_plan_count'  => 0,
			'installment_balance'     => 0,
			'receivable_commitment_total' => 0,
			'receivable_commitment_balance_total' => 0,
			'receivable_planned_commitment_total' => 0,
			'receivable_planned_commitment_count' => 0,
			'receivable_store_debt_commitment_total' => 0,
			'receivable_store_debt_commitment_applied_to_orders_total' => 0,
			'payable_commitment_total'    => 0,
			'receivable_commitment_count' => 0,
			'receivable_store_debt_commitment_count' => 0,
			'payable_commitment_count'    => 0,
			'order_count'             => $orders_snapshot['summary']['order_count'],
			'open_order_count'        => $orders_snapshot['summary']['open_order_count'],
			'open_order_total'        => $orders_snapshot['summary']['open_order_total'],
			'completed_order_count'   => $orders_snapshot['summary']['completed_order_count'],
			'orders_total'            => $orders_snapshot['summary']['orders_total'],
			'average_ticket'          => $orders_snapshot['summary']['average_ticket'],
			'web_order_count'         => $orders_snapshot['summary']['web_order_count'],
			'pos_order_count'         => $orders_snapshot['summary']['pos_order_count'],
			'synced_order_count'      => $orders_snapshot['summary']['synced_order_count'],
			'pending_order_total'     => $pending_snapshot['summary']['pending_order_total'],
			'pending_order_gross_total' => $pending_snapshot['summary']['pending_order_gross_total'],
			'pending_order_paid_total' => $pending_snapshot['summary']['pending_order_paid_total'],
			'pending_order_count_total' => $pending_snapshot['summary']['pending_count'],
			'managed_pending_order_count' => $pending_snapshot['summary']['managed_count'],
			'salary_advance_count'    => 0,
			'salary_advance_active_count' => 0,
			'salary_advance_total'    => 0,
			'salary_advance_recovered_total' => 0,
			'salary_advance_balance'  => 0,
			'payroll_period_count'    => 0,
			'payroll_planned_count'   => 0,
			'payroll_paid_count'      => 0,
			'payroll_gross_total'     => 0,
			'payroll_advance_total'   => 0,
			'payroll_net_total'       => 0,
			'service_profile_count'   => (int) ( $service_snapshot['summary']['profile_count'] ?? 0 ),
			'service_profile_active_count' => (int) ( $service_snapshot['summary']['active_profile_count'] ?? 0 ),
			'service_due_count'       => (int) ( $service_snapshot['summary']['due_profile_count'] ?? 0 ),
			'service_upcoming_count'  => (int) ( $service_snapshot['summary']['upcoming_profile_count'] ?? 0 ),
			'service_document_count'  => (int) ( $service_snapshot['summary']['document_count'] ?? 0 ),
			'service_open_document_count' => (int) ( $service_snapshot['summary']['open_document_count'] ?? 0 ),
			'service_open_balance_total' => (float) ( $service_snapshot['summary']['open_balance_total'] ?? 0 ),
			'service_paid_total'      => (float) ( $service_snapshot['summary']['paid_total'] ?? 0 ),
		);

		if ( $documents->exists() ) {
			$doc_summary = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
					COUNT(*) AS document_count,
					COALESCE(SUM(CASE WHEN COALESCE(financial_status, '') <> 'void' AND balance > 0 THEN 1 ELSE 0 END), 0) AS open_document_count,
					COALESCE(SUM(CASE WHEN COALESCE(financial_status, '') <> 'void' AND balance_nature = 'receivable' AND balance > 0 THEN balance ELSE 0 END), 0) AS receivable_total,
					COALESCE(SUM(CASE WHEN COALESCE(financial_status, '') <> 'void' AND balance_nature = 'receivable' AND balance > 0 AND document_type <> 'woo_sale' THEN balance ELSE 0 END), 0) AS non_order_receivable_total,
					COALESCE(SUM(CASE WHEN COALESCE(financial_status, '') <> 'void' AND balance_nature = 'receivable' AND balance > 0 AND document_type = 'woo_sale' THEN balance ELSE 0 END), 0) AS managed_order_receivable_total,
					COALESCE(SUM(CASE WHEN COALESCE(financial_status, '') <> 'void' AND balance_nature = 'receivable' AND document_type = 'woo_sale' THEN total ELSE 0 END), 0) AS managed_order_total,
					COALESCE(SUM(CASE WHEN COALESCE(financial_status, '') <> 'void' AND balance_nature = 'receivable' AND document_type = 'woo_sale' THEN paid_total ELSE 0 END), 0) AS managed_order_paid_total,
					COALESCE(SUM(CASE WHEN COALESCE(financial_status, '') <> 'void' AND balance_nature = 'payable' AND balance > 0 THEN balance ELSE 0 END), 0) AS payable_total
					FROM {$documents_table}
					WHERE contact_id = %d",
					$contact_id
				),
				ARRAY_A
			);

			if ( is_array( $doc_summary ) ) {
				$summary['document_count']      = isset( $doc_summary['document_count'] ) ? (int) $doc_summary['document_count'] : 0;
				$summary['open_document_count'] = isset( $doc_summary['open_document_count'] ) ? (int) $doc_summary['open_document_count'] : 0;
				$summary['receivable_total']    = isset( $doc_summary['receivable_total'] ) ? (float) $doc_summary['receivable_total'] : 0;
				$summary['non_order_receivable_total'] = isset( $doc_summary['non_order_receivable_total'] ) ? (float) $doc_summary['non_order_receivable_total'] : 0;
				$summary['managed_order_receivable_total'] = isset( $doc_summary['managed_order_receivable_total'] ) ? (float) $doc_summary['managed_order_receivable_total'] : 0;
				$summary['managed_order_total'] = isset( $doc_summary['managed_order_total'] ) ? (float) $doc_summary['managed_order_total'] : 0;
				$summary['managed_order_paid_total'] = isset( $doc_summary['managed_order_paid_total'] ) ? (float) $doc_summary['managed_order_paid_total'] : 0;
				$summary['payable_total']       = isset( $doc_summary['payable_total'] ) ? (float) $doc_summary['payable_total'] : 0;
			}
		}

		if ( $payments->exists() ) {
			$payment_summary = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COUNT(*) AS payment_count,
						COALESCE(SUM(total), 0) AS payments_total,
						COALESCE(SUM(CASE
							WHEN status = 'posted'
								AND available_amount > 0
								AND payment_type IN ('collection', 'adjustment')
								AND COALESCE(method_key, '') NOT IN ('salary_advance', 'dual_price_discount', 'internal_compensation', 'extraordinary_profile_closure')
							THEN available_amount
							ELSE 0
						END), 0) AS unapplied_payment_total
					FROM {$payments_table}
					WHERE contact_id = %d",
					$contact_id
				),
				ARRAY_A
			);

			if ( is_array( $payment_summary ) ) {
				$summary['payment_count']           = isset( $payment_summary['payment_count'] ) ? (int) $payment_summary['payment_count'] : 0;
				$summary['payments_total']          = isset( $payment_summary['payments_total'] ) ? (float) $payment_summary['payments_total'] : 0;
				$summary['unapplied_payment_total'] = isset( $payment_summary['unapplied_payment_total'] ) ? (float) $payment_summary['unapplied_payment_total'] : 0;
			}
		}

		if ( $plans->exists() ) {
			$plan_items         = $plans->for_contact( $contact_id, 200 );
			$open_plan_statuses = array( 'active', 'partial', 'paused' );
			$summary['installment_plan_count'] = count( $plan_items );
			$summary['installment_balance']    = array_sum(
				array_map(
					static function ( array $plan ) use ( $open_plan_statuses ) {
						if ( ! in_array( sanitize_key( (string) ( $plan['status'] ?? '' ) ), $open_plan_statuses, true ) ) {
							return 0;
						}
						return max( 0, (float) ( $plan['balance'] ?? 0 ) );
					},
					$plan_items
				)
			);

			$backing_context = array(
				'order_backing_totals'    => array(
					$contact_id => (float) ( $summary['pending_order_total'] ?? 0 ),
				),
				'document_backing_totals' => $this->open_receivable_document_balance_map( $contact_id ),
			);
			$receivable_summary = CommitmentExposureService::summarize_receivable_plans( $plan_items, $backing_context );

			$summary['receivable_commitment_total'] = (float) ( $receivable_summary['additional_exposure_total'] ?? 0 );
			$summary['receivable_commitment_balance_total'] = (float) ( $receivable_summary['balance_total'] ?? 0 );
			$summary['receivable_planned_commitment_total'] = (float) ( $receivable_summary['planned_recovery_total'] ?? 0 );
			$summary['receivable_planned_commitment_count'] = (int) ( $receivable_summary['planned_recovery_count'] ?? 0 );
			$summary['receivable_commitment_count'] = (int) ( $receivable_summary['additional_exposure_count'] ?? 0 );
			$summary['receivable_store_debt_commitment_total'] = (float) ( $receivable_summary['planned_recovery_total'] ?? 0 );
			$summary['receivable_store_debt_commitment_applied_to_orders_total'] = (float) ( $receivable_summary['planned_recovery_total'] ?? 0 );
			$summary['receivable_store_debt_commitment_count'] = (int) ( $receivable_summary['planned_recovery_count'] ?? 0 );
			$plan_items = $receivable_summary['enriched_items'] ?? $plan_items;

			foreach ( $plan_items as $plan_item ) {
				if ( ! in_array( sanitize_key( (string) ( $plan_item['status'] ?? '' ) ), $open_plan_statuses, true ) ) {
					continue;
				}

				$balance   = max( 0, (float) ( $plan_item['balance'] ?? 0 ) );
				$direction = sanitize_key( (string) ( $plan_item['settlement_direction'] ?? 'receivable' ) );
				if ( $balance <= 0 || 'receivable' === $direction ) {
					continue;
				}

				$summary['payable_commitment_total'] += $balance;
				++$summary['payable_commitment_count'];
			}
		}

		$advance_summary = $employee_advances->summary_for_contact( $contact_id );
		$summary['salary_advance_count']           = (int) ( $advance_summary['advance_count'] ?? 0 );
		$summary['salary_advance_active_count']    = (int) ( $advance_summary['active_count'] ?? 0 );
		$summary['salary_advance_total']           = (float) ( $advance_summary['total_amount'] ?? 0 );
		$summary['salary_advance_recovered_total'] = (float) ( $advance_summary['recovered_amount'] ?? 0 );
		$summary['salary_advance_balance']         = (float) ( $advance_summary['balance_total'] ?? 0 );

		$payroll_summary = $payroll_periods->summary_for_contact( $contact_id );
		$payroll_queue_diagnostic = ! empty( $contact['is_employee'] )
			? $payroll_queue->describe_contact_queue_status(
				$contact_id,
				array(
					'from_date' => $filters['range_from'] ?? '',
					'to_date'   => $filters['range_to'] ?? '',
				)
			)
			: array();
		$summary['payroll_period_count']  = (int) ( $payroll_summary['period_count'] ?? 0 );
		$summary['payroll_planned_count'] = (int) ( $payroll_summary['planned_count'] ?? 0 );
		$summary['payroll_paid_count']    = (int) ( $payroll_summary['paid_count'] ?? 0 );
		$summary['payroll_gross_total']   = (float) ( $payroll_summary['gross_total'] ?? 0 );
		$summary['payroll_advance_total'] = (float) ( $payroll_summary['advance_deduction_total'] ?? 0 );
		$summary['payroll_net_total']     = (float) ( $payroll_summary['net_total'] ?? 0 );
		$summary['usable_credit_total']   = round( (float) $summary['payable_total'] + (float) $summary['unapplied_payment_total'], 6 );
		$summary['credit_total']          = round( (float) $summary['payable_total'] + (float) $summary['payable_commitment_total'] + (float) $summary['unapplied_payment_total'], 6 );
		$summary['consolidated_receivable_total'] = round( (float) $summary['pending_order_total'] + (float) $summary['non_order_receivable_total'], 6 );
		$summary['net_position_total']    = round( (float) $summary['consolidated_receivable_total'] - (float) $summary['credit_total'], 6 );

		$documents_for_contact     = $documents->for_contact( $contact_id, 50, false );
		$open_documents_for_contact = $documents->for_contact( $contact_id, 50, true );

		return array(
			'contact'             => $contact,
			'filters'             => $filters,
			'summary'             => $summary,
			'documents'           => $documents_for_contact,
			'open_documents'      => $open_documents_for_contact,
			'open_payable_documents' => $this->filter_documents( $open_documents_for_contact, 'payable' ),
			'payments'            => $payments->for_contact( $contact_id, 50 ),
			'allocations'         => $allocations->for_contact( $contact_id, 50 ),
			'plans'               => isset( $plan_items ) ? array_slice( $plan_items, 0, 50 ) : $plans->for_contact( $contact_id, 50 ),
			'employee_profile'    => $employee_profiles->find_by_contact_id( $contact_id ),
			'salary_advances'     => $employee_advances->for_contact( $contact_id, 50 ),
			'salary_advance_summary' => $advance_summary,
			'payroll_periods'     => $payroll_periods->for_contact( $contact_id, 24 ),
			'payroll_summary'     => $payroll_summary,
			'payroll_queue_diagnostic' => $payroll_queue_diagnostic,
			'pending_orders'      => $pending_snapshot['orders'],
			'pending_order_summary' => $pending_snapshot['summary'],
			'orders'              => $orders_snapshot['orders'],
			'order_summary'       => $orders_snapshot['summary'],
			'consumption_timeline'=> $orders_snapshot['timeline'],
			'consumption_summary' => $this->summarize_consumption_timeline( $orders_snapshot['timeline'] ),
			'historical_consumption_years' => $historical_consumption_snapshot['years'],
			'historical_consumption_selected_year' => $historical_consumption_snapshot['selected_year'],
			'historical_consumption_summary' => $historical_consumption_snapshot['summary'],
			'historical_consumption_timeline' => $historical_consumption_snapshot['timeline'],
			'historical_consumption_by_year' => $historical_consumption_snapshot['by_year'],
			'consumption_all_time_summary' => $all_time_consumption_snapshot['summary'],
			'consumption_all_time_timeline' => $all_time_consumption_snapshot['timeline'],
			'service_profiles'    => $service_snapshot['profiles'],
			'due_service_profiles' => $service_snapshot['due_profiles'],
			'upcoming_service_profiles' => $service_snapshot['upcoming_profiles'],
			'service_documents'   => $service_snapshot['documents'],
			'open_service_documents' => $service_snapshot['open_documents'],
			'service_summary'     => $service_snapshot['summary'],
		);
	}

	private function open_receivable_document_balance_map( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return array();
		}

		$map = array();
		foreach ( ( new DocumentsRepository() )->for_contact( $contact_id, 500, true ) as $document ) {
			if ( 'receivable' !== sanitize_key( (string) ( $document['balance_nature'] ?? '' ) ) ) {
				continue;
			}

			if ( 'void' === sanitize_key( (string) ( $document['financial_status'] ?? '' ) ) ) {
				continue;
			}

			$document_id = (int) ( $document['id'] ?? 0 );
			if ( $document_id <= 0 ) {
				continue;
			}

			$map[ $document_id ] = max( 0, (float) ( $document['balance'] ?? 0 ) );
		}

		return $map;
	}

	private function build_services_snapshot( $contact_id, ServiceProfilesRepository $services, DocumentsRepository $documents ) {
		$service_profiles      = $services->for_contact( $contact_id, 50 );
		$today                 = gmdate( 'Y-m-d' );
		$upcoming_window_start = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$upcoming_window_end   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		$due_profiles          = array();
		$upcoming_profiles     = array();

		foreach ( $service_profiles as $profile ) {
			$next_issue_date = sanitize_text_field( (string) ( $profile['next_issue_date'] ?? '' ) );
			if ( '' === $next_issue_date || 'active' !== sanitize_key( (string) ( $profile['status'] ?? '' ) ) ) {
				continue;
			}

			if ( $next_issue_date <= $today ) {
				$due_profiles[] = $profile;
				continue;
			}

			if ( $next_issue_date >= $upcoming_window_start && $next_issue_date <= $upcoming_window_end ) {
				$upcoming_profiles[] = $profile;
			}
		}

		$service_documents      = $documents->list_admin(
			array(
				'contact_id'     => $contact_id,
				'document_type'  => 'service_expense',
				'limit'          => 50,
			)
		);
		$open_service_documents = $documents->list_admin(
			array(
				'contact_id'     => $contact_id,
				'document_type'  => 'service_expense',
				'open_only'      => true,
				'limit'          => 50,
			)
		);
		$summary                = $this->summarize_service_documents( $service_documents );
		$profile_summary        = $services->summary_for_contact( $contact_id, $today, $upcoming_window_end );

		return array(
			'profiles'       => $service_profiles,
			'due_profiles'   => $due_profiles,
			'upcoming_profiles' => $upcoming_profiles,
			'documents'      => $service_documents,
			'open_documents' => $open_service_documents,
			'summary'        => array(
				'profile_count'         => (int) ( $profile_summary['total_count'] ?? 0 ),
				'active_profile_count'  => (int) ( $profile_summary['active_count'] ?? 0 ),
				'paused_profile_count'  => (int) ( $profile_summary['paused_count'] ?? 0 ),
				'inactive_profile_count'=> (int) ( $profile_summary['inactive_count'] ?? 0 ),
				'due_profile_count'     => (int) ( $profile_summary['due_count'] ?? 0 ),
				'upcoming_profile_count'=> (int) ( $profile_summary['upcoming_count'] ?? 0 ),
				'due_amount_total'      => (float) ( $profile_summary['due_amount_total'] ?? 0 ),
				'upcoming_amount_total' => (float) ( $profile_summary['upcoming_amount_total'] ?? 0 ),
				'document_count'        => (int) ( $summary['service_count'] ?? 0 ),
				'open_document_count'   => (int) ( $summary['open_count'] ?? 0 ),
				'open_balance_total'    => (float) ( $summary['open_balance_total'] ?? 0 ),
				'paid_total'            => (float) ( $summary['paid_total'] ?? 0 ),
			),
		);
	}

	private function summarize_service_documents( array $documents ) {
		$summary = array(
			'service_count'      => count( $documents ),
			'open_count'         => 0,
			'open_balance_total' => 0.0,
			'paid_total'         => 0.0,
		);

		foreach ( $documents as $document ) {
			$balance    = max( 0, (float) ( $document['balance'] ?? 0 ) );
			$paid_total = max( 0, (float) ( $document['paid_total'] ?? 0 ) );

			if ( $balance > 0 ) {
				$summary['open_count']++;
				$summary['open_balance_total'] += $balance;
			}

			$summary['paid_total'] += $paid_total;
		}

		return $summary;
	}

	private function filter_documents( array $documents, $balance_nature = '', $document_type = '' ) {
		$balance_nature = sanitize_key( (string) $balance_nature );
		$document_type  = sanitize_key( (string) $document_type );

		return array_values(
			array_filter(
				$documents,
				static function ( array $document ) use ( $balance_nature, $document_type ) {
					if ( '' !== $balance_nature && sanitize_key( (string) ( $document['balance_nature'] ?? '' ) ) !== $balance_nature ) {
						return false;
					}

					if ( '' !== $document_type && sanitize_key( (string) ( $document['document_type'] ?? '' ) ) !== $document_type ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	public function get_pending_orders_snapshot( $contact_id, array $args = array() ) {
		$contacts = new ContactsRepository();
		$contact  = $contacts->find( $contact_id );

		if ( empty( $contact ) ) {
			return null;
		}

		$snapshot = $this->build_pending_orders_snapshot( $contact );
		$limit    = ! empty( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;

		return array(
			'contact' => $contact,
			'summary' => $snapshot['summary'],
			'orders'  => array_slice( $snapshot['orders'], 0, $limit ),
			'total'   => count( $snapshot['orders'] ),
			'limit'   => $limit,
		);
	}

	private function build_pending_orders_snapshot( array $contact, array $filters = array() ) {
		$empty = array(
			'summary' => array(
				'pending_count'            => 0,
				'managed_count'            => 0,
				'unmanaged_count'          => 0,
				'pending_order_total'      => 0,
				'pending_order_gross_total'=> 0,
				'pending_order_paid_total' => 0,
			),
			'orders' => array(),
		);

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $empty;
		}

		$history_from  = $this->resolve_pending_history_window_start( $filters );
		$history_until = $this->sanitize_date( $filters['range_to'] ?? '' );
		$orders        = $this->load_hybrid_pending_orders( $contact, $history_from, $history_until );

		if ( empty( $orders ) ) {
			return $empty;
		}

		usort(
			$orders,
			static function ( array $left, array $right ) {
				$left_ts  = ! empty( $left['date_created'] ) ? strtotime( $left['date_created'] ) : 0;
				$right_ts = ! empty( $right['date_created'] ) ? strtotime( $right['date_created'] ) : 0;

				if ( $left_ts === $right_ts ) {
					return (int) $left['order_id'] <=> (int) $right['order_id'];
				}

				return $left_ts <=> $right_ts;
			}
		);

		$summary = array(
			'pending_count'             => 0,
			'managed_count'             => 0,
			'unmanaged_count'           => 0,
			'pending_order_total'       => 0,
			'pending_order_gross_total' => 0,
			'pending_order_paid_total'  => 0,
		);
		$open_orders = array();

		foreach ( $orders as $order ) {
			if ( empty( $order['is_effectively_open'] ) ) {
				continue;
			}

			$gross_total = (float) ( $order['effective_total'] ?? $order['total'] ?? 0 );
			$paid_total  = (float) ( $order['effective_paid_total'] ?? 0 );
			$due_total   = (float) ( $order['effective_due_total'] ?? $order['total'] ?? 0 );

			$summary['pending_count']++;
			$summary['pending_order_total'] += $due_total;
			$summary['pending_order_gross_total'] += $gross_total;
			$summary['pending_order_paid_total'] += $paid_total;
			if ( ! empty( $order['is_managed'] ) ) {
				$summary['managed_count']++;
			} else {
				$summary['unmanaged_count']++;
			}

			$open_orders[] = $order;
		}

		return array(
			'summary' => $summary,
			'orders'  => $open_orders,
		);
	}

	private function resolve_pending_history_window_start( array $filters ) {
		$range_from = $this->sanitize_date( $filters['range_from'] ?? '' );
		$range_to   = $this->sanitize_date( $filters['range_to'] ?? '' );

		if ( $range_from ) {
			return gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $range_from ) ) );
		}

		if ( $range_to ) {
			return gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $range_to ) ) );
		}

		return '';
	}

	private function normalize_order_filters( array $args ) {
		$from  = $this->sanitize_date( $args['range_from'] ?? '' );
		$to    = $this->sanitize_date( $args['range_to'] ?? '' );
		$limit = max( 10, min( 100, (int) ( $args['order_limit'] ?? 25 ) ) );

		if ( ! $from && ! $to ) {
			$from = gmdate( 'Y-m-d', strtotime( '-180 days', current_time( 'timestamp' ) ) );
			$to   = gmdate( 'Y-m-d' );
		}

		if ( $from && $to && $from > $to ) {
			$temp = $from;
			$from = $to;
			$to   = $temp;
		}

		return array(
			'range_from' => $from,
			'range_to'   => $to,
			'order_limit'=> $limit,
		);
	}

	private function build_orders_snapshot( array $contact, array $filters ) {
		$empty = array(
			'summary' => array(
				'order_count'           => 0,
				'open_order_count'      => 0,
				'open_order_total'      => 0,
				'completed_order_count' => 0,
				'orders_total'          => 0,
				'average_ticket'        => 0,
				'web_order_count'       => 0,
				'pos_order_count'       => 0,
				'synced_order_count'    => 0,
			),
			'orders'  => array(),
			'timeline'=> array(),
		);

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $empty;
		}

		$orders = $this->load_hybrid_orders( $contact, $filters );

		if ( empty( $orders ) ) {
			return $empty;
		}

		$summary      = $empty['summary'];
		$timeline_map = array();
		$total    = 0.0;

		foreach ( $orders as $order ) {
			$date_key = ! empty( $order['date_created'] ) ? gmdate( 'Y-m', strtotime( $order['date_created'] ) ) : gmdate( 'Y-m' );
			$order_total = (float) ( $order['effective_total'] ?? $order['total'] ?? 0 );
			$order_due   = (float) ( $order['effective_due_total'] ?? 0 );
			$total      += $order_total;

			$summary['order_count']++;
			$summary['orders_total'] += $order_total;
			$summary['completed_order_count'] += in_array( sanitize_key( $order['status'] ), array( 'completed' ), true ) ? 1 : 0;
			$summary['open_order_count'] += ! empty( $order['is_effectively_open'] ) ? 1 : 0;
			$summary['open_order_total'] += ! empty( $order['is_effectively_open'] ) ? $order_due : 0;
			$summary['web_order_count'] += 'woocommerce' === $order['provider'] ? 1 : 0;
			$summary['pos_order_count'] += 'openpos' === $order['provider'] ? 1 : 0;
			$summary['synced_order_count'] += ! empty( $order['document_id'] ) ? 1 : 0;

			$timeline_map = $this->push_order_into_consumption_map( $timeline_map, $order, $date_key, $order_total, $order_due );
		}

		if ( $summary['order_count'] > 0 ) {
			$summary['average_ticket'] = round( $total / $summary['order_count'], 6 );
		}

		$timeline = $this->finalize_consumption_timeline( $timeline_map );

		return array(
			'summary' => $summary,
			'orders'  => array_slice( $orders, 0, (int) $filters['order_limit'] ),
			'timeline'=> $timeline,
		);
	}

	private function build_historical_consumption_snapshot( array $contact ) {
		$empty      = array(
			'years'         => array(),
			'selected_year' => 0,
			'summary'       => $this->empty_consumption_summary(),
			'timeline'      => array(),
			'by_year'       => array(),
			'all_timeline'  => array(),
			'all_summary'   => $this->empty_consumption_summary(),
		);
		$index_repo = new CommerceOrderIndexRepository();
		$fiscal     = new FiscalYearService();
		$identity   = $this->contact_identity_args( $contact );
		$active     = $fiscal->get_context();
		$active_start = $this->sanitize_date( $active['start_date'] ?? '' );
		$history_to = $active_start ? gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $active_start ) ) ) : '';

		if ( ! $history_to ) {
			return $empty;
		}

		$rows = $index_repo->list_orders_for_identity(
			array_merge(
				$identity,
				array(
					'range_to' => $history_to,
					'limit'    => 5000,
				)
			)
		);

		if ( empty( $rows ) ) {
			return $empty;
		}

		$by_year_maps = array();
		$all_map      = array();

		foreach ( $rows as $row ) {
			$year = (int) ( $row['fiscal_year'] ?? 0 );
			if ( $year <= 0 ) {
				$year = (int) $fiscal->resolve_start_year_from_date( (string) ( $row['issue_date'] ?? '' ) );
			}

			if ( $year <= 0 ) {
				continue;
			}

			$order = $this->map_index_row_to_order( $row );
			$by_year_maps[ $year ] = $this->push_order_into_consumption_map( $by_year_maps[ $year ] ?? array(), $order );
			$all_map               = $this->push_order_into_consumption_map( $all_map, $order );
		}

		if ( empty( $by_year_maps ) ) {
			return $empty;
		}

		krsort( $by_year_maps );

		$years         = array();
		$by_year       = array();
		$selected_year = 0;

		foreach ( $by_year_maps as $year => $timeline_map ) {
			$timeline = $this->finalize_consumption_timeline( $timeline_map );
			$summary  = $this->summarize_consumption_timeline( $timeline );

			if ( 0 === $selected_year ) {
				$selected_year = (int) $year;
			}

			$years[] = array(
				'year'  => (int) $year,
				'label' => $fiscal->label_for_year( (int) $year ),
			);

			$by_year[ (int) $year ] = array(
				'label'   => $fiscal->label_for_year( (int) $year ),
				'timeline'=> $timeline,
				'summary' => $summary,
			);
		}

		$selected_snapshot = $by_year[ $selected_year ] ?? array(
			'timeline' => array(),
			'summary'  => $this->empty_consumption_summary(),
		);

		$all_timeline = $this->finalize_consumption_timeline( $all_map );

		return array(
			'years'         => $years,
			'selected_year' => $selected_year,
			'summary'       => (array) ( $selected_snapshot['summary'] ?? $this->empty_consumption_summary() ),
			'timeline'      => (array) ( $selected_snapshot['timeline'] ?? array() ),
			'by_year'       => $by_year,
			'all_timeline'  => $all_timeline,
			'all_summary'   => $this->summarize_consumption_timeline( $all_timeline ),
		);
	}

	private function build_all_time_consumption_snapshot( array $contact, array $historical_snapshot ) {
		$fiscal        = new FiscalYearService();
		$active        = $fiscal->get_context();
		$current_range = array(
			'range_from'  => $this->sanitize_date( $active['start_date'] ?? '' ),
			'range_to'    => $this->sanitize_date( $active['end_date'] ?? '' ),
			'order_limit' => 5000,
		);
		$current_year  = $this->build_orders_snapshot( $contact, $current_range );
		$timeline      = $this->merge_consumption_timelines(
			(array) ( $current_year['timeline'] ?? array() ),
			(array) ( $historical_snapshot['all_timeline'] ?? array() )
		);

		return array(
			'timeline' => $timeline,
			'summary'  => $this->summarize_consumption_timeline( $timeline ),
		);
	}

	private function load_hybrid_pending_orders( array $contact, $history_from, $range_to ) {
		$order_service = new OrderSyncService();
		$index_repo    = new CommerceOrderIndexRepository();
		$history_tools = new HistoricalIndexRebuildService();
		$identity      = $this->contact_identity_args( $contact );
		$active        = ( new FiscalYearService() )->get_context();
		$active_start  = $this->sanitize_date( $active['start_date'] ?? '' );
		$active_end    = $this->sanitize_date( $active['end_date'] ?? '' );
		$items         = array();
		$live_from     = $this->max_date( $history_from, $active_start );
		$live_to       = $this->min_date( $range_to, $active_end );

		if ( $this->date_range_is_valid( $live_from, $live_to ) ) {
			foreach (
				$order_service->list_orders(
					array(
						'limit'       => 0,
						'customer_id' => $identity['wp_user_id'],
						'email'       => $identity['email'],
						'statuses'    => $order_service->collectible_statuses(),
						'range_from'  => $live_from,
						'range_to'    => $live_to,
					)
				) as $order
			) {
				if ( empty( $order['is_effectively_open'] ) ) {
					continue;
				}
				$items[] = $order;
			}
		}

		$historical_to = $active_start ? gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $active_start ) ) ) : $range_to;
		$historical_to = $this->min_date( $range_to, $historical_to );

		if ( $this->date_range_is_valid( $history_from, $historical_to ) ) {
			$historical_rows = $index_repo->list_orders_for_identity(
				array_merge(
					$identity,
					array(
						'range_from'       => $history_from,
						'range_to'         => $historical_to,
						'limit'            => 5000,
						'open_only'        => true,
						'collectible_only' => true,
					)
				)
			);

			if ( ! empty( $historical_rows ) ) {
				foreach ( $historical_rows as $row ) {
					$items[] = $this->map_index_row_to_order( $row );
				}
			} elseif ( $this->historical_range_requires_live_fallback( $history_from, $historical_to, $history_tools ) ) {
				foreach (
					$order_service->list_orders(
						array(
							'limit'       => 0,
							'customer_id' => $identity['wp_user_id'],
							'email'       => $identity['email'],
							'statuses'    => $order_service->collectible_statuses(),
							'range_from'  => $history_from,
							'range_to'    => $historical_to,
						)
					) as $order
				) {
					if ( empty( $order['is_effectively_open'] ) ) {
						continue;
					}
					$items[] = $order;
				}
			}
		}

		usort(
			$items,
			static function ( array $left, array $right ) {
				$left_date  = (string) ( $left['date_created'] ?? '' );
				$right_date = (string) ( $right['date_created'] ?? '' );

				if ( $left_date === $right_date ) {
					return (int) ( $left['order_id'] ?? 0 ) <=> (int) ( $right['order_id'] ?? 0 );
				}

				return strcmp( $left_date, $right_date );
			}
		);

		return $items;
	}

	private function load_hybrid_orders( array $contact, array $filters ) {
		$order_service = new OrderSyncService();
		$index_repo    = new CommerceOrderIndexRepository();
		$history_tools = new HistoricalIndexRebuildService();
		$identity      = $this->contact_identity_args( $contact );
		$active        = ( new FiscalYearService() )->get_context();
		$active_start  = $this->sanitize_date( $active['start_date'] ?? '' );
		$active_end    = $this->sanitize_date( $active['end_date'] ?? '' );
		$range_from    = $this->sanitize_date( $filters['range_from'] ?? '' );
		$range_to      = $this->sanitize_date( $filters['range_to'] ?? '' );
		$items         = array();
		$live_from     = $this->max_date( $range_from, $active_start );
		$live_to       = $this->min_date( $range_to, $active_end );

		if ( $this->date_range_is_valid( $live_from, $live_to ) ) {
			$items = array_merge(
				$items,
				$order_service->list_orders(
					array(
						'limit'       => 0,
						'customer_id' => $identity['wp_user_id'],
						'email'       => $identity['email'],
						'range_from'  => $live_from,
						'range_to'    => $live_to,
						'statuses'    => array_keys( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() ),
					)
				)
			);
		}

		$historical_to = $active_start ? gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $active_start ) ) ) : $range_to;
		$historical_to = $this->min_date( $range_to, $historical_to );

		if ( $this->date_range_is_valid( $range_from, $historical_to ) ) {
			$historical_rows = $index_repo->list_orders_for_identity(
				array_merge(
					$identity,
					array(
						'range_from' => $range_from,
						'range_to'   => $historical_to,
						'limit'      => 5000,
					)
				)
			);

			if ( ! empty( $historical_rows ) ) {
				foreach ( $historical_rows as $row ) {
					$items[] = $this->map_index_row_to_order( $row );
				}
			} elseif ( $this->historical_range_requires_live_fallback( $range_from, $historical_to, $history_tools ) ) {
				$items = array_merge(
					$items,
					$order_service->list_orders(
						array(
							'limit'       => 0,
							'customer_id' => $identity['wp_user_id'],
							'email'       => $identity['email'],
							'range_from'  => $range_from,
							'range_to'    => $historical_to,
							'statuses'    => array_keys( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() ),
						)
					)
				);
			}
		}

		usort(
			$items,
			static function ( array $left, array $right ) {
				$left_date  = ! empty( $left['date_created'] ) ? strtotime( (string) $left['date_created'] ) : 0;
				$right_date = ! empty( $right['date_created'] ) ? strtotime( (string) $right['date_created'] ) : 0;

				if ( $left_date === $right_date ) {
					return (int) ( $right['order_id'] ?? 0 ) <=> (int) ( $left['order_id'] ?? 0 );
				}

				return $right_date <=> $left_date;
			}
		);

		return $items;
	}

	private function contact_identity_args( array $contact ) {
		return array(
			'contact_id' => absint( $contact['id'] ?? 0 ),
			'wp_user_id' => absint( $contact['wp_user_id'] ?? 0 ),
			'email'      => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
			'group_key'  => ! empty( $contact['id'] ) ? 'contact:' . absint( $contact['id'] ) : '',
		);
	}

	private function historical_range_requires_live_fallback( $from, $to, HistoricalIndexRebuildService $history_tools ) {
		$from = $this->sanitize_date( $from );
		$to   = $this->sanitize_date( $to );
		if ( ! $this->date_range_is_valid( $from, $to ) ) {
			return false;
		}

		$from_year = (int) ( new FiscalYearService() )->resolve_start_year_from_date( $from );
		$to_year   = (int) ( new FiscalYearService() )->resolve_start_year_from_date( $to );
		if ( $from_year > $to_year ) {
			$temp      = $from_year;
			$from_year = $to_year;
			$to_year   = $temp;
		}

		for ( $year = $from_year; $year <= $to_year; $year++ ) {
			if ( ! $history_tools->is_year_indexed( $year ) ) {
				return true;
			}
		}

		return false;
	}

	private function map_index_row_to_order( array $row ) {
		$meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();

		return array(
			'order_id'                => (int) ( $row['external_order_id'] ?? 0 ),
			'order_number'            => sanitize_text_field( (string) ( $row['order_number'] ?? '' ) ),
			'customer_id'             => (int) ( $row['wp_user_id'] ?? 0 ),
			'billing_email'           => sanitize_email( (string) ( $row['customer_email'] ?? '' ) ),
			'display_name'            => sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
			'status'                  => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'status_label'            => sanitize_text_field( (string) ( $meta['status_label'] ?? ( $row['status'] ?? '' ) ) ),
			'provider'                => sanitize_key( (string) ( $row['provider'] ?? 'woocommerce' ) ),
			'date_created'            => sanitize_text_field( (string) ( $row['issue_date'] ?? '' ) ),
			'total'                   => (float) ( $row['gross_total'] ?? 0 ),
			'currency'                => sanitize_text_field( (string) ( $row['currency'] ?? '' ) ),
			'item_count'              => (int) ( $row['item_count'] ?? 0 ),
			'document_id'             => (int) ( $row['document_id'] ?? 0 ),
			'is_managed'              => ! empty( $meta['is_managed'] ) || ! empty( $row['document_id'] ),
			'effective_total'         => (float) ( $row['gross_total'] ?? 0 ),
			'effective_paid_total'    => (float) ( $row['paid_total'] ?? 0 ),
			'effective_due_total'     => (float) ( $row['balance'] ?? 0 ),
			'is_effectively_open'     => ! empty( $row['is_open'] ),
		);
	}

	private function empty_consumption_summary() {
		return array(
			'order_count' => 0,
			'web_count'   => 0,
			'pos_count'   => 0,
			'total'       => 0,
			'period_count' => 0,
		);
	}

	private function push_order_into_consumption_map( array $timeline_map, array $order, $date_key = '', $order_total = null, $order_due = null ) {
		$date_key   = $date_key ?: ( ! empty( $order['date_created'] ) ? gmdate( 'Y-m', strtotime( (string) $order['date_created'] ) ) : gmdate( 'Y-m' ) );
		$order_total = null !== $order_total ? (float) $order_total : (float) ( $order['effective_total'] ?? $order['total'] ?? 0 );
		$order_due   = null !== $order_due ? (float) $order_due : (float) ( $order['effective_due_total'] ?? 0 );

		if ( ! isset( $timeline_map[ $date_key ] ) ) {
			$timeline_map[ $date_key ] = array(
				'period'        => $date_key,
				'order_count'   => 0,
				'total'         => 0,
				'pending_total' => 0,
				'web_count'     => 0,
				'pos_count'     => 0,
			);
		}

		$timeline_map[ $date_key ]['order_count']++;
		$timeline_map[ $date_key ]['total'] += $order_total;
		$timeline_map[ $date_key ]['pending_total'] += ! empty( $order['is_effectively_open'] ) ? $order_due : 0;
		$timeline_map[ $date_key ]['web_count'] += 'woocommerce' === ( $order['provider'] ?? '' ) ? 1 : 0;
		$timeline_map[ $date_key ]['pos_count'] += 'openpos' === ( $order['provider'] ?? '' ) ? 1 : 0;

		return $timeline_map;
	}

	private function finalize_consumption_timeline( array $timeline_map ) {
		if ( empty( $timeline_map ) ) {
			return array();
		}

		krsort( $timeline_map );

		return array_values( $timeline_map );
	}

	private function summarize_consumption_timeline( array $timeline ) {
		$summary = $this->empty_consumption_summary();

		foreach ( $timeline as $bucket ) {
			$summary['order_count'] += (int) ( $bucket['order_count'] ?? 0 );
			$summary['web_count'] += (int) ( $bucket['web_count'] ?? 0 );
			$summary['pos_count'] += (int) ( $bucket['pos_count'] ?? 0 );
			$summary['total'] += (float) ( $bucket['total'] ?? 0 );
			$summary['period_count']++;
		}

		return $summary;
	}

	private function merge_consumption_timelines( array ...$timeline_sets ) {
		$merged_map = array();

		foreach ( $timeline_sets as $timeline_set ) {
			foreach ( $timeline_set as $bucket ) {
				$period = (string) ( $bucket['period'] ?? '' );
				if ( '' === $period ) {
					continue;
				}

				if ( ! isset( $merged_map[ $period ] ) ) {
					$merged_map[ $period ] = array(
						'period'        => $period,
						'order_count'   => 0,
						'total'         => 0,
						'pending_total' => 0,
						'web_count'     => 0,
						'pos_count'     => 0,
					);
				}

				$merged_map[ $period ]['order_count'] += (int) ( $bucket['order_count'] ?? 0 );
				$merged_map[ $period ]['total'] += (float) ( $bucket['total'] ?? 0 );
				$merged_map[ $period ]['pending_total'] += (float) ( $bucket['pending_total'] ?? 0 );
				$merged_map[ $period ]['web_count'] += (int) ( $bucket['web_count'] ?? 0 );
				$merged_map[ $period ]['pos_count'] += (int) ( $bucket['pos_count'] ?? 0 );
			}
		}

		return $this->finalize_consumption_timeline( $merged_map );
	}

	private function date_range_is_valid( $from, $to ) {
		if ( empty( $from ) && empty( $to ) ) {
			return false;
		}

		if ( ! empty( $from ) && ! empty( $to ) && $from > $to ) {
			return false;
		}

		return true;
	}

	private function max_date( $left, $right ) {
		if ( empty( $left ) ) {
			return $right;
		}

		if ( empty( $right ) ) {
			return $left;
		}

		return $left >= $right ? $left : $right;
	}

	private function min_date( $left, $right ) {
		if ( empty( $left ) ) {
			return $right;
		}

		if ( empty( $right ) ) {
			return $left;
		}

		return $left <= $right ? $left : $right;
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}
}
