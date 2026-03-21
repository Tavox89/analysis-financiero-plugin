<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class PendingPayablesService {
	const CACHE_TTL = 60;

	public function get_snapshot( array $args = array() ) {
		$limit        = max( 1, min( 300, (int) ( $args['limit'] ?? 60 ) ) );
		$range_from   = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to     = $this->sanitize_date( $args['range_to'] ?? '' );
		$summary_only = ! empty( $args['summary_only'] );

		if ( $range_from && $range_to && $range_from > $range_to ) {
			$temp       = $range_from;
			$range_from = $range_to;
			$range_to   = $temp;
		}

		$cache_key = 'asdl_fin_pending_payables_v2_' . md5(
			wp_json_encode(
				array(
					'limit'        => $limit,
					'range_from'   => $range_from,
					'range_to'     => $range_to,
					'summary_only' => $summary_only ? 1 : 0,
				)
			)
		);
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( $summary_only ) {
			$result = $this->build_summary_only_snapshot( $range_from, $range_to );
			set_transient( $cache_key, $result, self::CACHE_TTL );
			return $result;
		}

		$contacts = new ContactsRepository();
		$groups   = array();

		foreach ( $this->query_open_payable_documents() as $document ) {
			$contact_id = absint( $document['contact_id'] ?? 0 );
			if ( $contact_id <= 0 ) {
				continue;
			}

			$resolved = $this->resolve_contact( $contact_id, $contacts );
			if ( empty( $resolved['group_key'] ) ) {
				continue;
			}

			$key = $this->ensure_group( $groups, $resolved );
			$this->attach_document_item( $groups[ $key ], $document, $range_from, $range_to, $summary_only );
		}

		foreach ( $this->query_open_payable_commitments() as $plan ) {
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

			if ( 'payable' !== $direction ) {
				continue;
			}

			$resolved = $this->resolve_contact( $contact_id, $contacts );
			if ( empty( $resolved['group_key'] ) ) {
				continue;
			}

			$key = $this->ensure_group( $groups, $resolved );
			$this->attach_commitment_item( $groups[ $key ], $plan, $meta, $range_from, $range_to, $summary_only );
		}

		$items = array_values(
			array_map(
				function ( array $group ) {
					$group['pending_total']               = round( (float) $group['pending_total'], 6 );
					$group['invoice_pending_total']       = round( (float) $group['invoice_pending_total'], 6 );
					$group['profile_credit_pending_total'] = round( (float) $group['profile_credit_pending_total'], 6 );
					$group['loan_pending_total']          = round( (float) $group['loan_pending_total'], 6 );
					$group['purchase_pending_total']      = round( (float) $group['purchase_pending_total'], 6 );
					$group['commitment_pending_total']    = round( (float) $group['commitment_pending_total'], 6 );
					$group['other_pending_total']         = round( (float) $group['other_pending_total'], 6 );
					$group['in_range_pending_total']      = round( (float) $group['in_range_pending_total'], 6 );
					$group['historical_pending_total']    = round( (float) $group['historical_pending_total'], 6 );

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
			'group_count'                  => count( $items ),
			'pending_total'                => array_sum( array_map( static function ( array $item ) { return (float) ( $item['pending_total'] ?? 0 ); }, $items ) ),
			'item_count'                   => array_sum( array_map( static function ( array $item ) { return (int) ( $item['item_count'] ?? 0 ); }, $items ) ),
			'invoice_count'                => array_sum( array_map( static function ( array $item ) { return (int) ( $item['invoice_count'] ?? 0 ); }, $items ) ),
			'profile_credit_count'         => array_sum( array_map( static function ( array $item ) { return (int) ( $item['profile_credit_count'] ?? 0 ); }, $items ) ),
			'loan_count'                   => array_sum( array_map( static function ( array $item ) { return (int) ( $item['loan_count'] ?? 0 ); }, $items ) ),
			'purchase_count'               => array_sum( array_map( static function ( array $item ) { return (int) ( $item['purchase_count'] ?? 0 ); }, $items ) ),
			'commitment_count'             => array_sum( array_map( static function ( array $item ) { return (int) ( $item['commitment_count'] ?? 0 ); }, $items ) ),
			'other_count'                  => array_sum( array_map( static function ( array $item ) { return (int) ( $item['other_count'] ?? 0 ); }, $items ) ),
			'invoice_pending_total'        => array_sum( array_map( static function ( array $item ) { return (float) ( $item['invoice_pending_total'] ?? 0 ); }, $items ) ),
			'profile_credit_pending_total' => array_sum( array_map( static function ( array $item ) { return (float) ( $item['profile_credit_pending_total'] ?? 0 ); }, $items ) ),
			'loan_pending_total'           => array_sum( array_map( static function ( array $item ) { return (float) ( $item['loan_pending_total'] ?? 0 ); }, $items ) ),
			'purchase_pending_total'       => array_sum( array_map( static function ( array $item ) { return (float) ( $item['purchase_pending_total'] ?? 0 ); }, $items ) ),
			'commitment_pending_total'     => array_sum( array_map( static function ( array $item ) { return (float) ( $item['commitment_pending_total'] ?? 0 ); }, $items ) ),
			'other_pending_total'          => array_sum( array_map( static function ( array $item ) { return (float) ( $item['other_pending_total'] ?? 0 ); }, $items ) ),
			'in_range_pending_total'       => array_sum( array_map( static function ( array $item ) { return (float) ( $item['in_range_pending_total'] ?? 0 ); }, $items ) ),
			'historical_pending_total'     => array_sum( array_map( static function ( array $item ) { return (float) ( $item['historical_pending_total'] ?? 0 ); }, $items ) ),
			'in_range_count'               => array_sum( array_map( static function ( array $item ) { return (int) ( $item['in_range_count'] ?? 0 ); }, $items ) ),
			'historical_count'             => array_sum( array_map( static function ( array $item ) { return (int) ( $item['historical_count'] ?? 0 ); }, $items ) ),
		);

		$result = array(
			'summary' => $summary,
			'items'   => $summary_only ? array() : array_slice( $items, 0, $limit ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	private function build_summary_only_snapshot( $range_from, $range_to ) {
		$summary    = array(
			'group_count'                  => 0,
			'pending_total'                => 0.0,
			'item_count'                   => 0,
			'invoice_count'                => 0,
			'profile_credit_count'         => 0,
			'loan_count'                   => 0,
			'purchase_count'               => 0,
			'commitment_count'             => 0,
			'other_count'                  => 0,
			'invoice_pending_total'        => 0.0,
			'profile_credit_pending_total' => 0.0,
			'loan_pending_total'           => 0.0,
			'purchase_pending_total'       => 0.0,
			'commitment_pending_total'     => 0.0,
			'other_pending_total'          => 0.0,
			'in_range_pending_total'       => 0.0,
			'historical_pending_total'     => 0.0,
			'in_range_count'               => 0,
			'historical_count'             => 0,
		);
		$group_keys = array();
		$contacts   = new ContactsRepository();

		foreach ( $this->query_open_payable_documents() as $document ) {
			$amount     = (float) ( $document['balance'] ?? 0 );
			$contact_id = absint( $document['contact_id'] ?? 0 );
			$date       = $this->normalize_item_date( $document['issue_date'] ?? ( $document['created_at'] ?? '' ) );
			$in_range   = $this->is_date_in_range( $date, $range_from, $range_to );

			if ( $amount <= 0 || $contact_id <= 0 ) {
				continue;
			}

			$group_keys[ 'contact:' . $contact_id ] = true;
			$summary['pending_total'] += $amount;
			$summary['item_count']++;

			$contact = $contacts->find( $contact_id );
			$is_supplier = ! empty( $contact['is_supplier'] );
			$document_type = sanitize_key( (string) ( $document['document_type'] ?? 'manual_document' ) );
			$source_type   = sanitize_key( (string) ( $document['source_type'] ?? '' ) );

			if ( 'loan_payable' === $document_type ) {
				$summary['loan_count']++;
				$summary['loan_pending_total'] += $amount;
			} elseif ( 'purchase' === $source_type || 'purchase' === $document_type ) {
				$summary['purchase_count']++;
				$summary['purchase_pending_total'] += $amount;
			} elseif ( $is_supplier ) {
				$summary['invoice_count']++;
				$summary['invoice_pending_total'] += $amount;
			} else {
				$summary['profile_credit_count']++;
				$summary['profile_credit_pending_total'] += $amount;
			}

			if ( $in_range ) {
				$summary['in_range_pending_total'] += $amount;
				$summary['in_range_count']++;
			} else {
				$summary['historical_pending_total'] += $amount;
				$summary['historical_count']++;
			}
		}

		foreach ( $this->query_open_payable_commitments() as $plan ) {
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

			if ( $amount <= 0 || $contact_id <= 0 || 'payable' !== $direction ) {
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

		$summary['group_count']         = count( $group_keys );
		$summary['other_count']         = (int) $summary['profile_credit_count'] + (int) $summary['loan_count'] + (int) $summary['purchase_count'] + (int) $summary['commitment_count'];
		$summary['other_pending_total'] = round(
			(float) $summary['profile_credit_pending_total']
			+ (float) $summary['loan_pending_total']
			+ (float) $summary['purchase_pending_total']
			+ (float) $summary['commitment_pending_total'],
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

	private function ensure_group( array &$groups, array $resolved ) {
		$key = sanitize_text_field( (string) ( $resolved['group_key'] ?? '' ) );
		if ( '' === $key ) {
			$key = 'group:' . md5( wp_json_encode( $resolved ) );
		}

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'group_key'                   => $key,
				'contact_id'                  => (int) ( $resolved['contact_id'] ?? 0 ),
				'wp_user_id'                  => (int) ( $resolved['wp_user_id'] ?? 0 ),
				'display_name'                => sanitize_text_field( (string) ( $resolved['display_name'] ?? '' ) ),
				'email'                       => sanitize_email( (string) ( $resolved['email'] ?? '' ) ),
				'profile_origin'              => sanitize_key( (string) ( $resolved['profile_origin'] ?? '' ) ),
				'is_supplier'                 => ! empty( $resolved['is_supplier'] ),
				'pending_total'               => 0.0,
				'item_count'                  => 0,
				'invoice_count'               => 0,
				'profile_credit_count'        => 0,
				'loan_count'                  => 0,
				'purchase_count'              => 0,
				'commitment_count'            => 0,
				'other_count'                 => 0,
				'invoice_pending_total'       => 0.0,
				'profile_credit_pending_total'=> 0.0,
				'loan_pending_total'          => 0.0,
				'purchase_pending_total'      => 0.0,
				'commitment_pending_total'    => 0.0,
				'other_pending_total'         => 0.0,
				'in_range_pending_total'      => 0.0,
				'historical_pending_total'    => 0.0,
				'in_range_count'              => 0,
				'historical_count'            => 0,
				'oldest_date'                 => '',
				'items'                       => array(),
			);
		}

		return $key;
	}

	private function attach_document_item( array &$group, array $document, $range_from, $range_to, $summary_only = false ) {
		$classification = $this->classify_document( $document, $group );
		$amount         = (float) ( $document['balance'] ?? 0 );
		$date           = $this->normalize_item_date( $document['issue_date'] ?? ( $document['created_at'] ?? '' ) );
		$in_range       = $this->is_date_in_range( $date, $range_from, $range_to );
		$count_key      = $classification['count_key'];
		$amount_key     = $classification['amount_key'];

		$group[ $count_key ]++;

		$this->register_item(
			$group,
			$amount,
			$amount_key,
			$date,
			$in_range,
			array(
				'entity_type'   => 'document',
				'entity_id'     => (int) ( $document['id'] ?? 0 ),
				'contact_id'    => (int) ( $group['contact_id'] ?? 0 ),
				'kind_label'    => $classification['kind_label'],
				'origin_bucket' => $classification['origin_bucket'],
				'label'         => sanitize_text_field( (string) ( $document['document_number'] ?: 'DOC-' . (int) ( $document['id'] ?? 0 ) ) ),
				'description'   => sanitize_text_field( (string) ( $document['title'] ?: $classification['description'] ) ),
				'status'        => sanitize_text_field( (string) ( $document['payment_status'] ?? $document['financial_status'] ?? '' ) ),
				'date'          => $date,
				'amount'        => $amount,
				'currency'      => sanitize_text_field( (string) ( $document['currency'] ?? '' ) ),
				'document_type' => sanitize_key( (string) ( $document['document_type'] ?? '' ) ),
				'range_bucket'  => $in_range ? 'current' : 'historical',
			),
			$summary_only
		);
	}

	private function attach_commitment_item( array &$group, array $plan, array $meta, $range_from, $range_to, $summary_only = false ) {
		$amount   = (float) ( $plan['balance'] ?? 0 );
		$date     = $this->normalize_item_date( $plan['start_date'] ?? ( $plan['created_at'] ?? '' ) );
		$in_range = $this->is_date_in_range( $date, $range_from, $range_to );
		$origin   = sanitize_key( (string) ( $meta['commitment_origin'] ?? '' ) );

		$group['commitment_count']++;

		$this->register_item(
			$group,
			$amount,
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
				'description'   => 'loan' === $origin ? 'Prestamo acordado por pagar' : ( 'company_debt' === $origin ? 'Pago acordado a favor del perfil' : 'Compromiso por pagar' ),
				'status'        => sanitize_text_field( (string) ( $plan['status'] ?? '' ) ),
				'date'          => $date,
				'amount'        => $amount,
				'currency'      => sanitize_text_field( (string) ( $plan['currency'] ?? '' ) ),
				'range_bucket'  => $in_range ? 'current' : 'historical',
			),
			$summary_only
		);
	}

	private function register_item( array &$group, $amount, $amount_key, $date, $in_range, array $item, $summary_only = false ) {
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

		if ( ! $summary_only ) {
			$group['items'][] = $item;
		}
	}

	private function classify_document( array $document, array $group ) {
		$document_type = sanitize_key( (string) ( $document['document_type'] ?? 'manual_document' ) );
		$source_type   = sanitize_key( (string) ( $document['source_type'] ?? '' ) );
		$is_supplier   = ! empty( $group['is_supplier'] );

		if ( 'loan_payable' === $document_type ) {
			return array(
				'count_key'    => 'loan_count',
				'amount_key'   => 'loan_pending_total',
				'kind_label'   => 'Prestamo',
				'origin_bucket'=> 'loan',
				'description'  => 'Prestamo por pagar',
			);
		}

		if ( 'purchase' === $source_type || 'purchase' === $document_type ) {
			return array(
				'count_key'    => 'purchase_count',
				'amount_key'   => 'purchase_pending_total',
				'kind_label'   => 'Compra',
				'origin_bucket'=> 'purchase',
				'description'  => 'Compra pendiente de pago',
			);
		}

		if ( $is_supplier ) {
			return array(
				'count_key'    => 'invoice_count',
				'amount_key'   => 'invoice_pending_total',
				'kind_label'   => 'Documento',
				'origin_bucket'=> 'invoice',
				'description'  => 'Documento por pagar a proveedor',
			);
		}

		return array(
			'count_key'    => 'profile_credit_count',
			'amount_key'   => 'profile_credit_pending_total',
			'kind_label'   => 'Deuda con perfil',
			'origin_bucket'=> 'profile_credit',
			'description'  => 'Deuda documentada a favor del perfil',
		);
	}

	private function query_open_payable_documents() {
		global $wpdb;

		$documents_table = Tables::name( 'documents' );
		if ( ! $this->table_exists( $documents_table ) ) {
			return array();
		}

		return $wpdb->get_results(
			"SELECT id, contact_id, document_number, title, document_type, source_type, payment_status, financial_status, issue_date, created_at, currency, balance
			FROM {$documents_table}
			WHERE contact_id IS NOT NULL
			AND contact_id > 0
			AND balance_nature = 'payable'
			AND balance > 0
			AND COALESCE(financial_status, '') <> 'void'
			AND COALESCE(document_type, '') <> 'salary_expense'
			ORDER BY COALESCE(issue_date, DATE(created_at)) ASC, id ASC",
			ARRAY_A
		);
	}

	private function query_open_payable_commitments() {
		global $wpdb;

		$plans_table = Tables::name( 'installment_plans' );
		if ( ! $this->table_exists( $plans_table ) ) {
			return array();
		}

		return $wpdb->get_results(
			"SELECT id, contact_id, document_id, title, currency, balance, status, start_date, created_at, meta_json
			FROM {$plans_table}
			WHERE contact_id IS NOT NULL
			AND contact_id > 0
			AND balance > 0
			AND ( document_id IS NULL OR document_id = 0 )
			AND status IN ('active', 'partial', 'paused')
			ORDER BY COALESCE(NULLIF(start_date, ''), DATE(created_at)) ASC, id ASC",
			ARRAY_A
		);
	}

	private function resolve_contact( $contact_id, ContactsRepository $contacts ) {
		$contact = $contacts->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return array(
				'group_key'      => 'contact:' . absint( $contact_id ),
				'contact_id'     => absint( $contact_id ),
				'wp_user_id'     => 0,
				'display_name'   => 'Perfil #' . absint( $contact_id ),
				'email'          => '',
				'profile_origin' => 'external',
				'is_supplier'    => false,
			);
		}

		return array(
			'group_key'      => 'contact:' . (int) $contact['id'],
			'contact_id'     => (int) $contact['id'],
			'wp_user_id'     => ! empty( $contact['wp_user_id'] ) ? (int) $contact['wp_user_id'] : 0,
			'display_name'   => sanitize_text_field( (string) ( $contact['display_name'] ?? '' ) ),
			'email'          => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
			'profile_origin' => sanitize_key( (string) ( $contact['profile_origin'] ?? '' ) ),
			'is_supplier'    => ! empty( $contact['is_supplier'] ),
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
