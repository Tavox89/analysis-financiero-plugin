<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class PendingCollectionsService {
	const CACHE_TTL = 60;

	public function get_snapshot( array $args = array() ) {
		global $wpdb;

		$raw_limit     = isset( $args['limit'] ) ? (int) $args['limit'] : 60;
		$limit         = $raw_limit <= 0 ? 0 : max( 1, min( 1000, $raw_limit ) );
		$source        = sanitize_key( $args['source'] ?? 'all' );
		$raw_order_limit = isset( $args['order_limit'] ) ? (int) $args['order_limit'] : 0;
		$order_limit   = $raw_order_limit <= 0 ? 0 : max( 1, min( 2000, $raw_order_limit ) );
		$range_from    = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to      = $this->sanitize_date( $args['range_to'] ?? '' );
		$summary_only  = ! empty( $args['summary_only'] );
		$include_detail = ! $summary_only && ( ! isset( $args['include_detail'] ) || ! empty( $args['include_detail'] ) );
		if ( $range_from && $range_to && $range_from > $range_to ) {
			$temp       = $range_from;
			$range_from = $range_to;
			$range_to   = $temp;
		}
		$history_from = $this->resolve_history_window_start( $range_from, $range_to );

		$cache_key = 'asdl_fin_pending_queue_v7_' . md5(
			wp_json_encode(
				array(
					'limit'          => $limit,
					'order_limit'    => $order_limit,
					'source'         => $source,
					'range_from'     => $range_from,
					'range_to'       => $range_to,
					'history_from'   => $history_from,
					'summary_only'   => $summary_only ? 1 : 0,
					'include_detail' => $include_detail ? 1 : 0,
					'data_version'   => ( new HistoricalIndexRebuildService() )->get_data_version(),
					'plugin_version' => defined( 'ASDL_FINANCE_VERSION' ) ? ASDL_FINANCE_VERSION : 'dev',
				)
			)
		);
		if ( $summary_only ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( $summary_only ) {
			$result = $this->build_summary_only_snapshot( $source, $range_from, $range_to, $history_from );
			set_transient( $cache_key, $result, self::CACHE_TTL );
			return $result;
		}

		$contacts      = new ContactsRepository();
		$groups        = array();
		$orders        = $this->load_collectible_order_rows(
			array(
				'limit'       => $order_limit,
				'source'      => $source,
				'range_from'  => $range_from,
				'range_to'    => $range_to,
				'history_from'=> $history_from,
			)
		);

		$collect_detail = ! $summary_only && $include_detail;

		foreach ( $orders as $order ) {
			$resolved = 'index' === ( $order['runtime_source'] ?? '' )
				? $this->resolve_index_profile( $order, $contacts )
				: $this->resolve_profile( $order, $contacts );
			$key      = $this->ensure_group( $groups, $resolved );
			$this->attach_order_item( $groups[ $key ], $order, $range_from, $range_to, $collect_detail );
		}

		foreach ( $this->query_open_receivable_documents( $history_from, $range_to ) as $document ) {
			$contact_id = absint( $document['contact_id'] ?? 0 );
			if ( $contact_id <= 0 ) {
				continue;
			}

			$resolved = $this->resolve_contact( $contact_id, $contacts );
			if ( empty( $resolved['group_key'] ) ) {
				continue;
			}

			$key = $this->ensure_group( $groups, $resolved );
			$this->attach_document_item( $groups[ $key ], $document, $range_from, $range_to, $collect_detail );
		}

		foreach ( $this->query_open_receivable_commitments( $history_from, $range_to ) as $plan ) {
			$contact_id = absint( $plan['contact_id'] ?? 0 );
			if ( $contact_id <= 0 ) {
				continue;
			}

			$meta      = json_decode( (string) ( $plan['meta_json'] ?? '' ), true );
			$meta      = is_array( $meta ) ? $meta : array();
			$direction = sanitize_key(
				(string) (
					$meta['settlement_direction']
					?? ( 'company_debt' === sanitize_key( (string) ( $meta['commitment_origin'] ?? '' ) ) ? 'payable' : 'receivable' )
				)
			);

			if ( 'receivable' !== $direction ) {
				continue;
			}

			$resolved = $this->resolve_contact( $contact_id, $contacts );
			if ( empty( $resolved['group_key'] ) ) {
				continue;
			}

			$key = $this->ensure_group( $groups, $resolved );
			$this->attach_commitment_item( $groups[ $key ], $plan, $meta, $range_from, $range_to, $collect_detail );
		}

		foreach ( $this->query_open_salary_advances( $history_from, $range_to ) as $advance ) {
			$contact_id = absint( $advance['contact_id'] ?? 0 );
			if ( $contact_id <= 0 ) {
				continue;
			}

			$resolved = $this->resolve_contact( $contact_id, $contacts );
			if ( empty( $resolved['group_key'] ) ) {
				continue;
			}

			$key = $this->ensure_group( $groups, $resolved );
			$this->attach_advance_item( $groups[ $key ], $advance, $range_from, $range_to, $collect_detail );
		}

		$items = array_values(
			array_map(
				function ( array $group ) use ( $include_detail ) {
					$group['pending_total']            = round( (float) $group['pending_total'], 6 );
					$group['order_pending_total']      = round( (float) $group['order_pending_total'], 6 );
					$group['invoice_pending_total']    = round( (float) $group['invoice_pending_total'], 6 );
					$group['loan_pending_total']       = round( (float) $group['loan_pending_total'], 6 );
					$group['commitment_pending_total'] = round( (float) $group['commitment_pending_total'], 6 );
					$group['advance_pending_total']    = round( (float) $group['advance_pending_total'], 6 );
					$group['other_pending_total']      = round(
						(float) $group['invoice_pending_total']
						+ (float) $group['loan_pending_total']
						+ (float) $group['commitment_pending_total']
						+ (float) $group['advance_pending_total'],
						6
					);
					$group['in_range_pending_total']   = round( (float) $group['in_range_pending_total'], 6 );
					$group['historical_pending_total'] = round( (float) $group['historical_pending_total'], 6 );
					$group['other_count']              = (int) $group['invoice_count'] + (int) $group['loan_count'] + (int) $group['commitment_count'] + (int) $group['advance_count'];
					$group['providers']                = array_keys( $group['providers'] );
					$group['sample_order']             = $group['orders'][0] ?? null;

					if ( ! empty( $group['items'] ) ) {
						usort(
							$group['items'],
							static function ( array $left, array $right ) {
								$left_date  = (string) ( $left['date'] ?? '' );
								$right_date = (string) ( $right['date'] ?? '' );

								if ( $left_date === $right_date ) {
									return (float) ( $right['amount'] ?? 0 ) <=> (float) ( $left['amount'] ?? 0 );
								}

								if ( '' === $left_date ) {
									return 1;
								}

								if ( '' === $right_date ) {
									return -1;
								}

								return strcmp( $left_date, $right_date );
							}
						);
					}

					if ( ! $include_detail ) {
						$group['orders']       = array();
						$group['items']        = array();
						$group['sample_order'] = null;
					}

					return $group;
				},
				$groups
			)
		);

		usort(
			$items,
			static function ( array $left, array $right ) {
				$left_date  = $left['oldest_date'] ?? '';
				$right_date = $right['oldest_date'] ?? '';

				if ( $left_date === $right_date ) {
					return (float) ( $right['pending_total'] ?? 0 ) <=> (float) ( $left['pending_total'] ?? 0 );
				}

				if ( '' === $left_date ) {
					return 1;
				}

				if ( '' === $right_date ) {
					return -1;
				}

				return strcmp( $left_date, $right_date );
			}
		);

		$summary = array(
			'group_count'              => count( $items ),
			'pending_total'            => array_sum( array_map( static function ( array $item ) { return (float) ( $item['pending_total'] ?? 0 ); }, $items ) ),
			'item_count'               => array_sum( array_map( static function ( array $item ) { return (int) ( $item['item_count'] ?? 0 ); }, $items ) ),
			'order_count'              => array_sum( array_map( static function ( array $item ) { return (int) ( $item['order_count'] ?? 0 ); }, $items ) ),
			'invoice_count'            => array_sum( array_map( static function ( array $item ) { return (int) ( $item['invoice_count'] ?? 0 ); }, $items ) ),
			'loan_count'               => array_sum( array_map( static function ( array $item ) { return (int) ( $item['loan_count'] ?? 0 ); }, $items ) ),
			'commitment_count'         => array_sum( array_map( static function ( array $item ) { return (int) ( $item['commitment_count'] ?? 0 ); }, $items ) ),
			'advance_count'            => array_sum( array_map( static function ( array $item ) { return (int) ( $item['advance_count'] ?? 0 ); }, $items ) ),
			'other_count'              => array_sum( array_map( static function ( array $item ) { return (int) ( $item['other_count'] ?? 0 ); }, $items ) ),
			'linked_profiles'          => count( array_filter( $items, static function ( array $item ) { return ! empty( $item['contact_id'] ); } ) ),
			'unlinked_groups'          => count( array_filter( $items, static function ( array $item ) { return empty( $item['contact_id'] ); } ) ),
			'order_pending_total'      => array_sum( array_map( static function ( array $item ) { return (float) ( $item['order_pending_total'] ?? 0 ); }, $items ) ),
			'invoice_pending_total'    => array_sum( array_map( static function ( array $item ) { return (float) ( $item['invoice_pending_total'] ?? 0 ); }, $items ) ),
			'loan_pending_total'       => array_sum( array_map( static function ( array $item ) { return (float) ( $item['loan_pending_total'] ?? 0 ); }, $items ) ),
			'commitment_pending_total' => array_sum( array_map( static function ( array $item ) { return (float) ( $item['commitment_pending_total'] ?? 0 ); }, $items ) ),
			'advance_pending_total'    => array_sum( array_map( static function ( array $item ) { return (float) ( $item['advance_pending_total'] ?? 0 ); }, $items ) ),
			'other_pending_total'      => array_sum( array_map( static function ( array $item ) { return (float) ( $item['other_pending_total'] ?? 0 ); }, $items ) ),
			'in_range_pending_total'   => array_sum( array_map( static function ( array $item ) { return (float) ( $item['in_range_pending_total'] ?? 0 ); }, $items ) ),
			'historical_pending_total' => array_sum( array_map( static function ( array $item ) { return (float) ( $item['historical_pending_total'] ?? 0 ); }, $items ) ),
			'in_range_count'           => array_sum( array_map( static function ( array $item ) { return (int) ( $item['in_range_count'] ?? 0 ); }, $items ) ),
			'historical_count'         => array_sum( array_map( static function ( array $item ) { return (int) ( $item['historical_count'] ?? 0 ); }, $items ) ),
		);

		$result = array(
			'summary' => $summary,
			'items'   => $summary_only ? array() : ( $limit > 0 ? array_slice( $items, 0, $limit ) : $items ),
		);

		if ( $summary_only ) {
			set_transient( $cache_key, $result, self::CACHE_TTL );
		}

		return $result;
	}

	public function get_group_items( array $args = array() ) {
		$contact_id    = absint( $args['contact_id'] ?? 0 );
		$wp_user_id    = absint( $args['wp_user_id'] ?? 0 );
		$email         = sanitize_email( $args['email'] ?? '' );
		$display_name  = sanitize_text_field( (string) ( $args['display_name'] ?? '' ) );
		$source        = sanitize_key( $args['source'] ?? 'all' );
		$raw_order_limit = isset( $args['order_limit'] ) ? (int) $args['order_limit'] : 300;
		$order_limit     = $raw_order_limit <= 0 ? 0 : max( 1, min( 1000, $raw_order_limit ) );
		$range_from    = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to      = $this->sanitize_date( $args['range_to'] ?? '' );

		if ( $range_from && $range_to && $range_from > $range_to ) {
			$temp       = $range_from;
			$range_from = $range_to;
			$range_to   = $temp;
		}

		if ( $contact_id <= 0 && $wp_user_id <= 0 && '' === $email ) {
			return array();
		}

		$history_from  = $this->resolve_history_window_start( $range_from, $range_to );
		$contacts      = new ContactsRepository();
		$groups        = array();

		if ( $contact_id > 0 ) {
			$resolved = $this->resolve_contact( $contact_id, $contacts );
			if ( empty( $resolved['group_key'] ) ) {
				return array();
			}
		} elseif ( $wp_user_id > 0 ) {
			$resolved = array(
				'group_key'      => 'wp_user:' . $wp_user_id,
				'contact_id'     => 0,
				'wp_user_id'     => $wp_user_id,
				'display_name'   => $display_name ?: 'Usuario #' . $wp_user_id,
				'email'          => $email,
				'profile_origin' => 'wp_user',
			);
		} else {
			$resolved = array(
				'group_key'      => 'email:' . md5( $email ),
				'contact_id'     => 0,
				'wp_user_id'     => 0,
				'display_name'   => $display_name ?: $email,
				'email'          => $email,
				'profile_origin' => 'external',
			);
		}

		$key    = $this->ensure_group( $groups, $resolved );
		$orders = $this->load_collectible_order_rows(
			array(
				'limit'        => $order_limit,
				'source'       => $source,
				'customer_id'  => $wp_user_id,
				'email'        => $email,
				'contact_id'   => $contact_id,
				'range_from'   => $range_from,
				'range_to'     => $range_to,
				'history_from' => $history_from,
			)
		);

		foreach ( $orders as $order ) {
			$this->attach_order_item( $groups[ $key ], $order, $range_from, $range_to, true );
		}

		if ( $contact_id > 0 ) {
			foreach ( $this->query_open_receivable_documents( $history_from, $range_to, $contact_id ) as $document ) {
				$this->attach_document_item( $groups[ $key ], $document, $range_from, $range_to, true );
			}

			foreach ( $this->query_open_receivable_commitments( $history_from, $range_to, $contact_id ) as $plan ) {
				$meta      = json_decode( (string) ( $plan['meta_json'] ?? '' ), true );
				$meta      = is_array( $meta ) ? $meta : array();
				$direction = sanitize_key(
					(string) (
						$meta['settlement_direction']
						?? ( 'company_debt' === sanitize_key( (string) ( $meta['commitment_origin'] ?? '' ) ) ? 'payable' : 'receivable' )
					)
				);

				if ( 'receivable' !== $direction ) {
					continue;
				}

				$this->attach_commitment_item( $groups[ $key ], $plan, $meta, $range_from, $range_to, true );
			}

			foreach ( $this->query_open_salary_advances( $history_from, $range_to, $contact_id ) as $advance ) {
				$this->attach_advance_item( $groups[ $key ], $advance, $range_from, $range_to, true );
			}
		}

		$items = (array) ( $groups[ $key ]['items'] ?? array() );
		usort(
			$items,
			static function ( array $left, array $right ) {
				$left_date  = (string) ( $left['date'] ?? '' );
				$right_date = (string) ( $right['date'] ?? '' );

				if ( $left_date === $right_date ) {
					return (float) ( $right['amount'] ?? 0 ) <=> (float) ( $left['amount'] ?? 0 );
				}

				if ( '' === $left_date ) {
					return 1;
				}

				if ( '' === $right_date ) {
					return -1;
				}

				return strcmp( $left_date, $right_date );
			}
		);

		return $items;
	}

	private function build_summary_only_snapshot( $source, $range_from, $range_to, $history_from ) {
		$group_keys    = array();
		$summary       = array(
			'group_count'              => 0,
			'pending_total'            => 0.0,
			'item_count'               => 0,
			'order_count'              => 0,
			'invoice_count'            => 0,
			'loan_count'               => 0,
			'commitment_count'         => 0,
			'advance_count'            => 0,
			'other_count'              => 0,
			'linked_profiles'          => 0,
			'unlinked_groups'          => 0,
			'order_pending_total'      => 0.0,
			'invoice_pending_total'    => 0.0,
			'loan_pending_total'       => 0.0,
			'commitment_pending_total' => 0.0,
			'advance_pending_total'    => 0.0,
			'other_pending_total'      => 0.0,
			'in_range_pending_total'   => 0.0,
			'historical_pending_total' => 0.0,
			'in_range_count'           => 0,
			'historical_count'         => 0,
		);

		foreach (
			$this->load_collectible_order_rows(
				array(
					'source'       => $source,
					'limit'        => 0,
					'range_from'   => $range_from,
					'range_to'     => $range_to,
					'history_from' => $history_from,
				)
			) as $order
		) {
			$amount   = max( 0, (float) ( $order['effective_due_total'] ?? $order['total'] ?? 0 ) );
			$date     = $this->normalize_item_date( $order['date_created'] ?? '' );
			$in_range = $this->is_date_in_range( $date, $range_from, $range_to );
			$group_key = $this->resolve_runtime_group_key( $order );

			$group_keys[ $group_key ] = true;
			$summary['pending_total'] += $amount;
			$summary['item_count']++;
			$summary['order_count']++;
			$summary['order_pending_total'] += $amount;

			if ( 0 !== (int) ( $order['contact_id'] ?? 0 ) || 0 !== (int) ( $order['customer_id'] ?? 0 ) ) {
				$summary['linked_profiles']++;
			}

			if ( $in_range ) {
				$summary['in_range_pending_total'] += $amount;
				$summary['in_range_count']++;
			} else {
				$summary['historical_pending_total'] += $amount;
				$summary['historical_count']++;
			}
		}

		$summary['linked_profiles'] = count(
			array_filter(
				array_keys( $group_keys ),
				static function ( $key ) {
					return 0 === strpos( (string) $key, 'contact:' ) || 0 === strpos( (string) $key, 'wp_user:' );
				}
			)
		);
		foreach ( $group_keys as $group_key => $enabled ) {
			if ( 0 === strpos( (string) $group_key, 'contact:' ) || 0 === strpos( (string) $group_key, 'wp_user:' ) ) {
				continue;
			}
			if ( $enabled ) {
				$summary['unlinked_groups']++;
			}
		}

		foreach ( $this->query_open_receivable_documents( $history_from, $range_to ) as $document ) {
			$amount        = (float) ( $document['balance'] ?? 0 );
			$document_type = sanitize_key( (string) ( $document['document_type'] ?? 'manual_document' ) );
			$is_loan       = 'loan_receivable' === $document_type;
			$date          = $this->normalize_item_date( $document['issue_date'] ?? ( $document['created_at'] ?? '' ) );
			$in_range      = $this->is_date_in_range( $date, $range_from, $range_to );
			$contact_id    = absint( $document['contact_id'] ?? 0 );

			if ( $amount <= 0 || $contact_id <= 0 ) {
				continue;
			}

			$group_keys[ 'contact:' . $contact_id ] = true;
			$summary['pending_total'] += $amount;
			$summary['item_count']++;

			if ( $is_loan ) {
				$summary['loan_count']++;
				$summary['loan_pending_total'] += $amount;
			} else {
				$summary['invoice_count']++;
				$summary['invoice_pending_total'] += $amount;
			}

			if ( $in_range ) {
				$summary['in_range_pending_total'] += $amount;
				$summary['in_range_count']++;
			} else {
				$summary['historical_pending_total'] += $amount;
				$summary['historical_count']++;
			}
		}

		foreach ( $this->query_open_receivable_commitments( $history_from, $range_to ) as $plan ) {
			$amount     = (float) ( $plan['balance'] ?? 0 );
			$contact_id = absint( $plan['contact_id'] ?? 0 );
			$meta       = json_decode( (string) ( $plan['meta_json'] ?? '' ), true );
			$meta       = is_array( $meta ) ? $meta : array();
			$direction  = sanitize_key(
				(string) (
					$meta['settlement_direction']
					?? ( 'company_debt' === sanitize_key( (string) ( $meta['commitment_origin'] ?? '' ) ) ? 'payable' : 'receivable' )
				)
			);
			$date       = $this->normalize_item_date( $plan['start_date'] ?? ( $plan['created_at'] ?? '' ) );
			$in_range   = $this->is_date_in_range( $date, $range_from, $range_to );

			if ( $amount <= 0 || $contact_id <= 0 || 'receivable' !== $direction ) {
				continue;
			}

			$group_keys[ 'contact:' . $contact_id ] = true;
			$summary['pending_total'] += $amount;
			$summary['item_count']++;
			$summary['commitment_count']++;
			$summary['commitment_pending_total'] += $amount;

			if ( $in_range ) {
				$summary['in_range_pending_total'] += $amount;
				$summary['in_range_count']++;
			} else {
				$summary['historical_pending_total'] += $amount;
				$summary['historical_count']++;
			}
		}

		foreach ( $this->query_open_salary_advances( $history_from, $range_to ) as $advance ) {
			$amount     = (float) ( $advance['balance'] ?? 0 );
			$contact_id = absint( $advance['contact_id'] ?? 0 );
			$date       = $this->normalize_item_date( $advance['issued_at'] ?? '' );
			$in_range   = $this->is_date_in_range( $date, $range_from, $range_to );

			if ( $amount <= 0 || $contact_id <= 0 ) {
				continue;
			}

			$group_keys[ 'contact:' . $contact_id ] = true;
			$summary['pending_total'] += $amount;
			$summary['item_count']++;
			$summary['advance_count']++;
			$summary['advance_pending_total'] += $amount;

			if ( $in_range ) {
				$summary['in_range_pending_total'] += $amount;
				$summary['in_range_count']++;
			} else {
				$summary['historical_pending_total'] += $amount;
				$summary['historical_count']++;
			}
		}

		$summary['group_count']         = count( $group_keys );
		$summary['other_count']         = (int) $summary['invoice_count'] + (int) $summary['loan_count'] + (int) $summary['commitment_count'] + (int) $summary['advance_count'];
		$summary['other_pending_total'] = round(
			(float) $summary['invoice_pending_total']
			+ (float) $summary['loan_pending_total']
			+ (float) $summary['commitment_pending_total']
			+ (float) $summary['advance_pending_total'],
			6
		);

		foreach ( $summary as $key => $value ) {
			if ( is_float( $value ) ) {
				$summary[ $key ] = round( $value, 6 );
			}
		}

		return array(
			'summary' => $summary,
			'items'   => array(),
		);
	}

	private function resolve_history_window_start( $range_from, $range_to ) {
		$range_from = $this->sanitize_date( $range_from );
		$range_to   = $this->sanitize_date( $range_to );

		if ( $range_from ) {
			return gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $range_from ) ) );
		}

		if ( $range_to ) {
			return gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $range_to ) ) );
		}

		return '';
	}

	private function load_collectible_order_rows( array $args ) {
		$order_service = new OrderSyncService();
		$index_repo    = new CommerceOrderIndexRepository();
		$history_tools = new HistoricalIndexRebuildService();
		$source        = sanitize_key( (string) ( $args['source'] ?? 'all' ) );
		$limit         = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		$range_from    = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to      = $this->sanitize_date( $args['range_to'] ?? '' );
		$history_from  = $this->sanitize_date( $args['history_from'] ?? '' );
		$customer_id   = absint( $args['customer_id'] ?? 0 );
		$email         = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$contact_id    = absint( $args['contact_id'] ?? 0 );
		$active_bounds = ( new FiscalYearService() )->get_context();
		$active_start  = $this->sanitize_date( $active_bounds['start_date'] ?? '' );
		$active_end    = $this->sanitize_date( $active_bounds['end_date'] ?? '' );
		$live_from     = $this->max_date( $history_from, $active_start );
		$live_to       = $this->min_date( $range_to, $active_end );
		$historical_to = $active_start ? gmdate( 'Y-m-d', strtotime( '-1 day', strtotime( $active_start ) ) ) : $range_to;
		$historical_to = $this->min_date( $range_to, $historical_to );
		$items         = array();

		if ( $this->date_range_is_valid( $live_from, $live_to ) ) {
			foreach (
				$order_service->list_orders(
					array(
						'limit'       => $limit > 0 ? $limit : 0,
						'source'      => $source,
						'customer_id' => $customer_id,
						'email'       => $email,
						'range_from'  => $live_from,
						'range_to'    => $live_to,
					)
				) as $order
			) {
				if ( empty( $order['is_effectively_open'] ) ) {
					continue;
				}
				$order['runtime_source'] = 'live';
				$items[] = $order;
			}
		}

		if ( $this->date_range_is_valid( $history_from, $historical_to ) ) {
			$indexed_rows = $index_repo->list_collectible_orders(
				array(
					'limit'      => $limit > 0 ? $limit : 0,
					'source'     => $source,
					'contact_id' => $contact_id,
					'wp_user_id' => $customer_id,
					'email'      => $email,
					'range_from' => $history_from,
					'range_to'   => $historical_to,
				)
			);

			if ( ! empty( $indexed_rows ) ) {
				foreach ( $indexed_rows as $row ) {
					$items[] = $this->map_index_row_to_order( $row );
				}
			} elseif ( $this->historical_range_requires_live_fallback( $history_from, $historical_to, $history_tools ) ) {
				foreach (
					$order_service->list_orders(
						array(
							'limit'       => $limit > 0 ? $limit : 0,
							'source'      => $source,
							'customer_id' => $customer_id,
							'email'       => $email,
							'range_from'  => $history_from,
							'range_to'    => $historical_to,
						)
					) as $order
				) {
					if ( empty( $order['is_effectively_open'] ) ) {
						continue;
					}
					$order['runtime_source'] = 'live-fallback';
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

				if ( '' === $left_date ) {
					return 1;
				}

				if ( '' === $right_date ) {
					return -1;
				}

				return strcmp( $left_date, $right_date );
			}
		);

		return $items;
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
			'contact_id'              => (int) ( $row['contact_id'] ?? 0 ),
			'document_id'             => (int) ( $row['document_id'] ?? 0 ),
			'source_link_id'          => (int) ( $row['source_link_id'] ?? 0 ),
			'is_managed'              => ! empty( $meta['is_managed'] ) || ! empty( $row['document_id'] ),
			'effective_total'         => (float) ( $row['gross_total'] ?? 0 ),
			'effective_paid_total'    => (float) ( $row['paid_total'] ?? 0 ),
			'effective_due_total'     => (float) ( $row['balance'] ?? 0 ),
			'is_effectively_open'     => ! empty( $row['is_open'] ),
			'document_payment_status' => sanitize_key( (string) ( $meta['document_payment_status'] ?? '' ) ),
			'edit_url'                => esc_url_raw( (string) ( $meta['edit_url'] ?? admin_url( 'post.php?post=' . absint( $row['external_order_id'] ?? 0 ) . '&action=edit' ) ) ),
			'runtime_source'          => 'index',
			'group_key'               => sanitize_text_field( (string) ( $row['group_key'] ?? '' ) ),
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

	private function resolve_runtime_group_key( array $order ) {
		if ( ! empty( $order['contact_id'] ) ) {
			return 'contact:' . (int) $order['contact_id'];
		}

		if ( ! empty( $order['customer_id'] ) ) {
			return 'wp_user:' . (int) $order['customer_id'];
		}

		if ( ! empty( $order['billing_email'] ) ) {
			return 'email:' . md5( sanitize_email( (string) $order['billing_email'] ) );
		}

		return 'order:' . absint( $order['order_id'] ?? 0 );
	}

	private function resolve_index_profile( array $order, ContactsRepository $contacts ) {
		$contact_id = absint( $order['contact_id'] ?? 0 );
		if ( $contact_id > 0 ) {
			$resolved = $this->resolve_contact( $contact_id, $contacts );
			if ( ! empty( $resolved['group_key'] ) ) {
				return $resolved;
			}
		}

		$wp_user_id = absint( $order['customer_id'] ?? 0 );
		$email      = sanitize_email( (string) ( $order['billing_email'] ?? '' ) );
		$display    = sanitize_text_field( (string) ( $order['display_name'] ?? '' ) );

		if ( $wp_user_id > 0 ) {
			return array(
				'group_key'      => 'wp_user:' . $wp_user_id,
				'contact_id'     => 0,
				'wp_user_id'     => $wp_user_id,
				'display_name'   => $display ?: 'Usuario #' . $wp_user_id,
				'email'          => $email,
				'profile_origin' => 'wp_user',
			);
		}

		if ( '' !== $email ) {
			return array(
				'group_key'      => 'email:' . md5( $email ),
				'contact_id'     => 0,
				'wp_user_id'     => 0,
				'display_name'   => $display ?: $email,
				'email'          => $email,
				'profile_origin' => 'external',
			);
		}

		return array(
			'group_key'      => sanitize_text_field( (string) ( $order['group_key'] ?? 'order:' . absint( $order['order_id'] ?? 0 ) ) ),
			'contact_id'     => 0,
			'wp_user_id'     => 0,
			'display_name'   => $display ?: 'Pedido #' . sanitize_text_field( (string) ( $order['order_number'] ?? $order['order_id'] ?? '' ) ),
			'email'          => '',
			'profile_origin' => 'external',
		);
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

	private function ensure_group( array &$groups, array $resolved ) {
		$key = sanitize_text_field( (string) ( $resolved['group_key'] ?? '' ) );
		if ( '' === $key ) {
			$key = 'group:' . md5( wp_json_encode( $resolved ) );
		}

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'group_key'                 => $key,
				'contact_id'                => (int) ( $resolved['contact_id'] ?? 0 ),
				'wp_user_id'                => (int) ( $resolved['wp_user_id'] ?? 0 ),
				'display_name'              => sanitize_text_field( (string) ( $resolved['display_name'] ?? '' ) ),
				'email'                     => sanitize_email( (string) ( $resolved['email'] ?? '' ) ),
				'profile_origin'            => sanitize_key( (string) ( $resolved['profile_origin'] ?? '' ) ),
				'pending_total'             => 0.0,
				'item_count'                => 0,
				'order_count'               => 0,
				'invoice_count'             => 0,
				'loan_count'                => 0,
				'commitment_count'          => 0,
				'advance_count'             => 0,
				'other_count'               => 0,
				'managed_count'             => 0,
				'unmanaged_count'           => 0,
				'order_pending_total'       => 0.0,
				'invoice_pending_total'     => 0.0,
				'loan_pending_total'        => 0.0,
				'commitment_pending_total'  => 0.0,
				'advance_pending_total'     => 0.0,
				'other_pending_total'       => 0.0,
				'in_range_pending_total'    => 0.0,
				'historical_pending_total'  => 0.0,
				'in_range_count'            => 0,
				'historical_count'          => 0,
				'oldest_date'               => '',
				'providers'                 => array(),
				'orders'                    => array(),
				'items'                     => array(),
			);
		}

		return $key;
	}

	private function attach_order_item( array &$group, array $order, $range_from, $range_to, $collect_detail = false ) {
		$amount    = (float) ( $order['effective_due_total'] ?? $order['total'] ?? 0 );
		$date      = $this->normalize_item_date( $order['date_created'] ?? '' );
		$in_range  = $this->is_date_in_range( $date, $range_from, $range_to );
		$provider  = sanitize_key( (string) ( $order['provider'] ?? 'woocommerce' ) );
		$provider_label = 'openpos' === $provider ? 'OpenPOS' : 'WooCommerce';

		$group['order_count']++;
		$group['managed_count'] += ! empty( $order['is_managed'] ) ? 1 : 0;
		$group['unmanaged_count'] += empty( $order['is_managed'] ) ? 1 : 0;
		$group['providers'][ $provider ] = true;
		if ( $collect_detail ) {
			$group['orders'][] = $order;
		}

		$this->register_item(
			$group,
			$amount,
			'order',
			'order_pending_total',
			$date,
			$in_range,
			array(
				'entity_type'  => 'order',
				'entity_id'    => (int) ( $order['order_id'] ?? 0 ),
				'document_id'  => ! empty( $order['document_id'] ) ? (int) $order['document_id'] : 0,
				'contact_id'   => (int) ( $group['contact_id'] ?? 0 ),
				'kind_label'   => 'Pedido',
				'origin_bucket'=> 'order',
				'label'        => '#' . sanitize_text_field( (string) ( $order['order_number'] ?: $order['order_id'] ) ),
				'description'  => sprintf(
					'%1$s · %2$s',
					$provider_label,
					! empty( $order['is_managed'] ) ? 'Gestionado en finanzas' : 'Sin gestion aplicada'
				),
				'status'       => sanitize_text_field( (string) ( $order['status_label'] ?: $order['status'] ?: '' ) ),
				'date'         => $date,
				'amount'       => $amount,
				'currency'     => sanitize_text_field( (string) ( $order['currency'] ?? '' ) ),
				'open_url'     => esc_url_raw( (string) ( $order['edit_url'] ?? '' ) ),
				'range_bucket' => $in_range ? 'current' : 'historical',
				'provider'     => $provider_label,
			),
			$collect_detail
		);
	}

	private function attach_document_item( array &$group, array $document, $range_from, $range_to, $collect_detail = false ) {
		$document_type = sanitize_key( (string) ( $document['document_type'] ?? 'manual_document' ) );
		$is_loan       = 'loan_receivable' === $document_type;
		$amount        = (float) ( $document['balance'] ?? 0 );
		$date          = $this->normalize_item_date( $document['issue_date'] ?? ( $document['created_at'] ?? '' ) );
		$in_range      = $this->is_date_in_range( $date, $range_from, $range_to );
		$bucket_key    = $is_loan ? 'loan_pending_total' : 'invoice_pending_total';
		$count_key     = $is_loan ? 'loan_count' : 'invoice_count';

		$group[ $count_key ]++;

		$this->register_item(
			$group,
			$amount,
			$bucket_key,
			$bucket_key,
			$date,
			$in_range,
			array(
				'entity_type'  => 'document',
				'entity_id'    => (int) ( $document['id'] ?? 0 ),
				'contact_id'   => (int) ( $group['contact_id'] ?? 0 ),
				'kind_label'   => $is_loan ? 'Prestamo' : 'Documento',
				'origin_bucket'=> $is_loan ? 'loan' : 'invoice',
				'label'        => sanitize_text_field( (string) ( $document['document_number'] ?: 'DOC-' . (int) ( $document['id'] ?? 0 ) ) ),
				'description'  => sanitize_text_field( (string) ( $document['title'] ?: ( $is_loan ? 'Prestamo por cobrar' : 'Documento por cobrar' ) ) ),
				'status'       => sanitize_text_field( (string) ( $document['payment_status'] ?? $document['financial_status'] ?? '' ) ),
				'date'         => $date,
				'amount'       => $amount,
				'currency'     => sanitize_text_field( (string) ( $document['currency'] ?? '' ) ),
				'document_type'=> $document_type,
				'range_bucket' => $in_range ? 'current' : 'historical',
			),
			$collect_detail
		);
	}

	private function attach_commitment_item( array &$group, array $plan, array $meta, $range_from, $range_to, $collect_detail = false ) {
		$amount   = (float) ( $plan['balance'] ?? 0 );
		$date     = $this->normalize_item_date( $plan['start_date'] ?? ( $plan['created_at'] ?? '' ) );
		$in_range = $this->is_date_in_range( $date, $range_from, $range_to );
		$origin   = sanitize_key( (string) ( $meta['commitment_origin'] ?? '' ) );

		$group['commitment_count']++;

		$this->register_item(
			$group,
			$amount,
			'commitment_pending_total',
			'commitment_pending_total',
			$date,
			$in_range,
			array(
				'entity_type'   => 'installment_plan',
				'entity_id'     => (int) ( $plan['id'] ?? 0 ),
				'contact_id'    => (int) ( $group['contact_id'] ?? 0 ),
				'kind_label'    => 'Compromiso',
				'origin_bucket' => 'commitment',
				'label'         => sanitize_text_field( (string) ( $plan['title'] ?: 'Compromiso #' . (int) ( $plan['id'] ?? 0 ) ) ),
				'description'   => 'loan' === $origin ? 'Prestamo acordado por cobrar' : 'Compromiso por cobrar',
				'status'        => sanitize_text_field( (string) ( $plan['status'] ?? '' ) ),
				'date'          => $date,
				'amount'        => $amount,
				'currency'      => sanitize_text_field( (string) ( $plan['currency'] ?? '' ) ),
				'range_bucket'  => $in_range ? 'current' : 'historical',
			),
			$collect_detail
		);
	}

	private function attach_advance_item( array &$group, array $advance, $range_from, $range_to, $collect_detail = false ) {
		$amount   = (float) ( $advance['balance'] ?? 0 );
		$date     = $this->normalize_item_date( $advance['issued_at'] ?? '' );
		$in_range = $this->is_date_in_range( $date, $range_from, $range_to );

		$group['advance_count']++;

		$this->register_item(
			$group,
			$amount,
			'advance_pending_total',
			'advance_pending_total',
			$date,
			$in_range,
			array(
				'entity_type'   => 'salary_advance',
				'entity_id'     => (int) ( $advance['id'] ?? 0 ),
				'payment_id'    => ! empty( $advance['payment_id'] ) ? (int) $advance['payment_id'] : 0,
				'contact_id'    => (int) ( $group['contact_id'] ?? 0 ),
				'kind_label'    => 'Adelanto',
				'origin_bucket' => 'advance',
				'label'         => 'Adelanto #' . (int) ( $advance['id'] ?? 0 ),
				'description'   => 'Por recuperar por sueldo',
				'status'        => sanitize_text_field( (string) ( $advance['status'] ?? '' ) ),
				'date'          => $date,
				'amount'        => $amount,
				'currency'      => sanitize_text_field( (string) ( $advance['currency'] ?? '' ) ),
				'range_bucket'  => $in_range ? 'current' : 'historical',
			),
			$collect_detail
		);
	}

	private function register_item( array &$group, $amount, $count_context, $amount_key, $date, $in_range, array $item, $collect_detail = false ) {
		$amount = max( 0, (float) $amount );
		if ( $amount <= 0 ) {
			return;
		}

		$group['pending_total'] += $amount;
		$group['item_count']++;
		$group[ $amount_key ] += $amount;

		if ( $in_range ) {
			$group['in_range_pending_total'] += $amount;
			$group['in_range_count']++;
		} else {
			$group['historical_pending_total'] += $amount;
			$group['historical_count']++;
		}

		if ( '' !== $date && ( '' === $group['oldest_date'] || $date < $group['oldest_date'] ) ) {
			$group['oldest_date'] = $date;
		}

		if ( $collect_detail ) {
			$group['items'][] = $item;
		}
	}

	private function query_open_receivable_documents( $history_from = '', $range_to = '', $contact_id = 0 ) {
		global $wpdb;

		$documents_table = Tables::name( 'documents' );
		if ( ! $this->table_exists( $documents_table ) ) {
			return array();
		}

		$history_from = $this->sanitize_date( $history_from );
		$range_to     = $this->sanitize_date( $range_to );
		$params       = array();
		$where        = array(
			'contact_id IS NOT NULL',
			'contact_id > 0',
			"balance_nature = 'receivable'",
			'balance > 0',
			"COALESCE(financial_status, '') <> 'void'",
			"COALESCE(document_type, '') <> 'woo_sale'",
		);

		if ( $history_from ) {
			$where[]  = 'COALESCE(issue_date, DATE(created_at)) >= %s';
			$params[] = $history_from;
		}

		if ( $range_to ) {
			$where[]  = 'COALESCE(issue_date, DATE(created_at)) <= %s';
			$params[] = $range_to;
		}

		if ( $contact_id > 0 ) {
			$where[]  = 'contact_id = %d';
			$params[] = absint( $contact_id );
		}

		$sql = "SELECT id, contact_id, document_number, title, document_type, source_type, payment_status, financial_status, issue_date, created_at, currency, balance
			FROM {$documents_table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY COALESCE(issue_date, DATE(created_at)) ASC, id ASC';

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	private function query_open_receivable_commitments( $history_from = '', $range_to = '', $contact_id = 0 ) {
		global $wpdb;

		$plans_table = Tables::name( 'installment_plans' );
		if ( ! $this->table_exists( $plans_table ) ) {
			return array();
		}

		$history_from = $this->sanitize_date( $history_from );
		$range_to     = $this->sanitize_date( $range_to );
		$params       = array();
		$where        = array(
			'contact_id IS NOT NULL',
			'contact_id > 0',
			'balance > 0',
			'( document_id IS NULL OR document_id = 0 )',
			"status IN ('active', 'partial', 'paused')",
		);

		if ( $history_from ) {
			$where[]  = "COALESCE(NULLIF(start_date, ''), DATE(created_at)) >= %s";
			$params[] = $history_from;
		}

		if ( $range_to ) {
			$where[]  = "COALESCE(NULLIF(start_date, ''), DATE(created_at)) <= %s";
			$params[] = $range_to;
		}

		if ( $contact_id > 0 ) {
			$where[]  = 'contact_id = %d';
			$params[] = absint( $contact_id );
		}

		$sql = "SELECT id, contact_id, document_id, title, currency, balance, status, start_date, created_at, meta_json
			FROM {$plans_table}
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY COALESCE(NULLIF(start_date, ''), DATE(created_at)) ASC, id ASC";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	private function query_open_salary_advances( $history_from = '', $range_to = '', $contact_id = 0 ) {
		global $wpdb;

		$advances_table = Tables::name( 'employee_advances' );
		if ( ! $this->table_exists( $advances_table ) ) {
			return array();
		}

		$history_from = $this->sanitize_date( $history_from );
		$range_to     = $this->sanitize_date( $range_to );
		$params       = array();
		$where        = array(
			'contact_id IS NOT NULL',
			'contact_id > 0',
			'balance > 0',
			"status IN ('active', 'partial')",
		);

		if ( $history_from ) {
			$where[]  = 'COALESCE(issued_at, DATE(created_at)) >= %s';
			$params[] = $history_from;
		}

		if ( $range_to ) {
			$where[]  = 'COALESCE(issued_at, DATE(created_at)) <= %s';
			$params[] = $range_to;
		}

		if ( $contact_id > 0 ) {
			$where[]  = 'contact_id = %d';
			$params[] = absint( $contact_id );
		}

		$sql = "SELECT id, contact_id, payment_id, issued_at, currency, balance, status
			FROM {$advances_table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY COALESCE(issued_at, DATE(created_at)) ASC, id ASC';

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	private function resolve_profile( array $order, ContactsRepository $contacts ) {
		$wp_user_id = ! empty( $order['customer_id'] ) ? absint( $order['customer_id'] ) : 0;
		$email      = sanitize_email( $order['billing_email'] ?? '' );
		$display    = sanitize_text_field( (string) ( $order['display_name'] ?? '' ) );
		$contact    = null;

		if ( $wp_user_id > 0 ) {
			$contact = $contacts->find_by_wp_user_id( $wp_user_id );
		}

		if ( empty( $contact['id'] ) && '' !== $email ) {
			$contact = $contacts->find_by_email( $email );
		}

		if ( ! empty( $contact['id'] ) ) {
			return array(
				'group_key'      => 'contact:' . (int) $contact['id'],
				'contact_id'     => (int) $contact['id'],
				'wp_user_id'     => ! empty( $contact['wp_user_id'] ) ? (int) $contact['wp_user_id'] : $wp_user_id,
				'display_name'   => sanitize_text_field( (string) ( $contact['display_name'] ?? $display ) ),
				'email'          => sanitize_email( $contact['email'] ?? $email ),
				'profile_origin' => sanitize_key( (string) ( $contact['profile_origin'] ?? '' ) ),
			);
		}

		if ( $wp_user_id > 0 ) {
			return array(
				'group_key'      => 'wp_user:' . $wp_user_id,
				'contact_id'     => 0,
				'wp_user_id'     => $wp_user_id,
				'display_name'   => $display ?: 'Usuario #' . $wp_user_id,
				'email'          => $email,
				'profile_origin' => 'wp_user',
			);
		}

		if ( '' !== $email ) {
			return array(
				'group_key'      => 'email:' . md5( $email ),
				'contact_id'     => 0,
				'wp_user_id'     => 0,
				'display_name'   => $display ?: $email,
				'email'          => $email,
				'profile_origin' => 'external',
			);
		}

		return array(
			'group_key'      => 'order:' . absint( $order['order_id'] ?? 0 ),
			'contact_id'     => 0,
			'wp_user_id'     => 0,
			'display_name'   => $display ?: 'Pedido #' . sanitize_text_field( (string) ( $order['order_number'] ?? $order['order_id'] ?? '' ) ),
			'email'          => '',
			'profile_origin' => 'external',
		);
	}

	private function resolve_contact( $contact_id, ContactsRepository $contacts ) {
		$contact = $contacts->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return array();
		}

		return array(
			'group_key'      => 'contact:' . (int) $contact['id'],
			'contact_id'     => (int) $contact['id'],
			'wp_user_id'     => ! empty( $contact['wp_user_id'] ) ? (int) $contact['wp_user_id'] : 0,
			'display_name'   => sanitize_text_field( (string) ( $contact['display_name'] ?? '' ) ),
			'email'          => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
			'profile_origin' => sanitize_key( (string) ( $contact['profile_origin'] ?? '' ) ),
		);
	}

	private function is_date_in_range( $date, $range_from, $range_to ) {
		if ( '' === $date ) {
			return empty( $range_from ) && empty( $range_to );
		}

		if ( empty( $range_from ) && empty( $range_to ) ) {
			return true;
		}

		if ( ! empty( $range_from ) && $date < $range_from ) {
			return false;
		}

		if ( ! empty( $range_to ) && $date > $range_to ) {
			return false;
		}

		return true;
	}

	private function normalize_item_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $value, $matches ) ) {
			return $matches[0];
		}

		return '';
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
}
