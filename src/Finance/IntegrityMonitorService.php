<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;

final class IntegrityMonitorService extends BaseRepository {
	const CASE_CREDIT_AND_DEBT_OVERLAP        = 'credit_and_debt_overlap';
	const CASE_DUAL_BATCH_INCOMPLETE          = 'dual_batch_incomplete';
	const CASE_SMALL_RESIDUAL_OPEN_BALANCE    = 'small_residual_open_balance';
	const CASE_CANCELLED_ORDER_COLLECTIBLE    = 'cancelled_order_still_collectible';
	const CASE_NEAR_ZERO_OPEN_DOCUMENT        = 'near_zero_open_document';

	const MATCH_TOLERANCE        = 0.05;
	const SMALL_RESIDUAL_LIMIT   = 0.05;
	const NEAR_ZERO_BALANCE_LIMIT = 0.05;

	private $cases;
	private $contacts;
	private $documents;
	private $payments;
	private $batches;
	private $events;
	private $order_sync;
	private $source_links;
	private $contact_overview;
	private $order_index;

	public function __construct( array $deps = array() ) {
		$this->cases            = $deps['cases'] ?? new IntegrityCasesRepository();
		$this->contacts         = $deps['contacts'] ?? new ContactsRepository();
		$this->documents        = $deps['documents'] ?? new DocumentsRepository();
		$this->payments         = $deps['payments'] ?? new PaymentsRepository();
		$this->batches          = $deps['batches'] ?? new OrderSettlementBatchesRepository();
		$this->events           = $deps['events'] ?? new EventsRepository();
		$this->order_sync       = $deps['order_sync'] ?? new OrderSyncService();
		$this->source_links     = $deps['source_links'] ?? new SourceLinksRepository();
		$this->contact_overview = $deps['contact_overview'] ?? new ContactOverviewService();
		$this->order_index      = $deps['order_index'] ?? new CommerceOrderIndexRepository();
	}

	public function scan( array $args = array() ) {
		$filters   = $this->normalize_scan_filters( $args );
		$types     = $this->resolve_scan_case_types( $filters );
		$results   = array(
			'filters'        => $filters,
			'scanned_types'  => $types,
			'detected_count' => 0,
			'created_count'  => 0,
			'updated_count'  => 0,
			'reopened_count' => 0,
			'cases'          => array(),
			'errors'         => array(),
		);

		foreach ( $types as $case_type ) {
			foreach ( $this->detect_cases_for_type( $case_type, $filters ) as $case ) {
				$stored = $this->cases->upsert_detected_case( $case );

				if ( is_wp_error( $stored ) ) {
					$results['errors'][] = array(
						'case_type' => $case_type,
						'message'   => $stored->get_error_message(),
					);
					continue;
				}

				$results['detected_count']++;
				$action = sanitize_key( (string) ( $stored['persistence_action'] ?? 'updated' ) );
				if ( 'created' === $action ) {
					$results['created_count']++;
				} elseif ( 'reopened' === $action ) {
					$results['reopened_count']++;
				} else {
					$results['updated_count']++;
				}

				$results['cases'][] = $stored;
			}
		}

		$this->events->log(
			'integrity_scan',
			null,
			'scan_completed',
			sprintf( 'Escaneo de integridad ejecutado. Casos detectados: %d.', (int) $results['detected_count'] ),
			array(
				'filters'        => $filters,
				'scanned_types'  => $types,
				'detected_count' => (int) $results['detected_count'],
				'created_count'  => (int) $results['created_count'],
				'updated_count'  => (int) $results['updated_count'],
				'reopened_count' => (int) $results['reopened_count'],
				'errors'         => $results['errors'],
			)
		);

		return $results;
	}

	public function rescan_case( $case_id ) {
		$case = $this->cases->find( $case_id );
		if ( empty( $case['id'] ) ) {
			return $this->error( 'asdl_fin_integrity_case_missing', 'No se encontro el caso de integridad solicitado.' );
		}

		$filters = array(
			'case_type'         => $case['case_type'],
			'contact_id'        => (int) ( $case['contact_id'] ?? 0 ),
			'external_order_id' => (int) ( $case['external_order_id'] ?? 0 ),
			'document_id'       => (int) ( $case['document_id'] ?? 0 ),
			'payment_id'        => (int) ( $case['payment_id'] ?? 0 ),
			'batch_id'          => (int) ( $case['batch_id'] ?? 0 ),
		);

		$match = null;
		foreach ( $this->detect_cases_for_type( $case['case_type'], $this->normalize_scan_filters( $filters ) ) as $candidate ) {
			if ( sanitize_text_field( (string) ( $candidate['case_key'] ?? '' ) ) === sanitize_text_field( (string) ( $case['case_key'] ?? '' ) ) ) {
				$match = $candidate;
				break;
			}
		}

		if ( null === $match ) {
			$this->cases->touch_last_scanned( (int) $case['id'] );
			$this->cases->update_status( (int) $case['id'], IntegrityCasesRepository::STATUS_RESOLVED, get_current_user_id(), 'El caso ya no se detecta en el reescaneo manual.' );

			$updated = $this->cases->find( (int) $case['id'] );
			$this->events->log(
				'integrity_case',
				(int) $case['id'],
				'rescan_resolved',
				'El caso quedo resuelto al reescanear.',
				array(
					'case_key'  => $case['case_key'],
					'case_type' => $case['case_type'],
				)
			);

			return array(
				'active'  => false,
				'case'    => $updated,
				'message' => 'El caso ya no se detecta y quedo marcado como resuelto.',
			);
		}

		$stored = $this->cases->upsert_detected_case( $match );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$this->events->log(
			'integrity_case',
			(int) $stored['id'],
			'rescan_active',
			'El caso sigue activo despues del reescaneo.',
			array(
				'case_key'  => $stored['case_key'],
				'case_type' => $stored['case_type'],
			)
		);

		return array(
			'active'  => true,
			'case'    => $stored,
			'message' => 'El caso sigue activo despues del reescaneo.',
		);
	}

	public static function case_type_options() {
		return array(
			self::CASE_CREDIT_AND_DEBT_OVERLAP     => 'Credito disponible + deuda abierta',
			self::CASE_DUAL_BATCH_INCOMPLETE       => 'Batch dual incompleto',
			self::CASE_SMALL_RESIDUAL_OPEN_BALANCE => 'Residual pequeño disponible',
			self::CASE_CANCELLED_ORDER_COLLECTIBLE => 'Pedido cancelado todavia cobrable',
			self::CASE_NEAR_ZERO_OPEN_DOCUMENT     => 'Documento casi pagado pero abierto',
		);
	}

	public static function severity_label( $severity ) {
		$options = IntegrityCasesRepository::severity_options();
		$key     = sanitize_key( (string) $severity );

		return $options[ $key ] ?? ucfirst( $key );
	}

	public static function status_label( $status ) {
		$options = IntegrityCasesRepository::status_options();
		$key     = sanitize_key( (string) $status );

		return $options[ $key ] ?? ucfirst( $key );
	}

	public static function case_type_label( $case_type ) {
		$options = self::case_type_options();
		$key     = sanitize_key( (string) $case_type );

		return $options[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );
	}

	public static function severity_tone( $severity ) {
		switch ( sanitize_key( (string) $severity ) ) {
			case IntegrityCasesRepository::SEVERITY_HIGH:
				return 'danger';
			case IntegrityCasesRepository::SEVERITY_MEDIUM:
				return 'warning';
			default:
				return 'neutral';
		}
	}

	private function resolve_scan_case_types( array $filters ) {
		$case_type = sanitize_key( (string) ( $filters['case_type'] ?? '' ) );
		if ( '' !== $case_type ) {
			return array( $case_type );
		}

		return array_keys( self::case_type_options() );
	}

	private function normalize_scan_filters( array $args ) {
		$filters = array(
			'case_type'         => sanitize_key( (string) ( $args['case_type'] ?? '' ) ),
			'contact_id'        => absint( $args['contact_id'] ?? 0 ),
			'external_order_id' => absint( $args['external_order_id'] ?? $args['order_id'] ?? 0 ),
			'document_id'       => absint( $args['document_id'] ?? 0 ),
			'payment_id'        => absint( $args['payment_id'] ?? 0 ),
			'batch_id'          => absint( $args['batch_id'] ?? 0 ),
			'range_from'        => $this->sanitize_date( $args['range_from'] ?? '' ),
			'range_to'          => $this->sanitize_date( $args['range_to'] ?? '' ),
		);

		if ( $filters['contact_id'] <= 0 && $filters['batch_id'] > 0 ) {
			$batch = $this->batches->find( $filters['batch_id'] );
			$filters['contact_id'] = ! empty( $batch['contact_id'] ) ? (int) $batch['contact_id'] : 0;
		}

		if ( $filters['contact_id'] <= 0 && $filters['document_id'] > 0 ) {
			$document = $this->documents->find( $filters['document_id'] );
			$filters['contact_id'] = ! empty( $document['contact_id'] ) ? (int) $document['contact_id'] : 0;
		}

		if ( $filters['contact_id'] <= 0 && $filters['payment_id'] > 0 ) {
			$payment = $this->payments->find( $filters['payment_id'] );
			$filters['contact_id'] = ! empty( $payment['contact_id'] ) ? (int) $payment['contact_id'] : 0;
		}

		if ( $filters['contact_id'] <= 0 && $filters['external_order_id'] > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $filters['external_order_id'] );
			if ( $order ) {
				$snapshot = $this->order_sync->describe_order( $order );
				$filters['contact_id'] = ! empty( $snapshot['contact_id'] ) ? (int) $snapshot['contact_id'] : 0;
			}
		}

		return $filters;
	}

	private function detect_cases_for_type( $case_type, array $filters ) {
		switch ( sanitize_key( (string) $case_type ) ) {
			case self::CASE_CREDIT_AND_DEBT_OVERLAP:
				return $this->detect_credit_and_debt_overlap( $filters );
			case self::CASE_DUAL_BATCH_INCOMPLETE:
				return $this->detect_dual_batch_incomplete( $filters );
			case self::CASE_SMALL_RESIDUAL_OPEN_BALANCE:
				return $this->detect_small_residual_open_balance( $filters );
			case self::CASE_CANCELLED_ORDER_COLLECTIBLE:
				return $this->detect_cancelled_order_still_collectible( $filters );
			case self::CASE_NEAR_ZERO_OPEN_DOCUMENT:
				return $this->detect_near_zero_open_document( $filters );
			default:
				return array();
		}
	}

	private function detect_credit_and_debt_overlap( array $filters ) {
		$contact_ids = $this->resolve_credit_overlap_contact_ids( $filters );
		$cases       = array();

		foreach ( $contact_ids as $contact_id ) {
			$snapshot = $this->contact_overview->get_contact_snapshot_cached(
				$contact_id,
				array(
					'range_from'  => $filters['range_from'],
					'range_to'    => $filters['range_to'],
					'order_limit' => 250,
				)
			);

			if ( empty( $snapshot['contact']['id'] ) ) {
				continue;
			}

			$available_total = round( max( 0, (float) ( $snapshot['summary']['unapplied_payment_total'] ?? 0 ) ), 6 );
			$pending_total   = round( max( 0, (float) ( $snapshot['summary']['pending_order_total'] ?? 0 ) ), 6 );

			if ( $available_total <= 0 || $pending_total <= 0 ) {
				continue;
			}

			$pending_orders = array();
			foreach ( (array) ( $snapshot['pending_orders'] ?? array() ) as $order ) {
				$balance = round( max( 0, (float) ( $order['effective_due_total'] ?? 0 ) ), 6 );
				if ( $balance <= 0 ) {
					continue;
				}

				$pending_orders[] = array(
					'order_id'     => (int) ( $order['order_id'] ?? 0 ),
					'order_number' => sanitize_text_field( (string) ( $order['order_number'] ?? '' ) ),
					'document_id'  => (int) ( $order['document_id'] ?? 0 ),
					'balance'      => $balance,
					'currency'     => sanitize_text_field( (string) ( $order['currency'] ?? '' ) ),
				);
			}

			$available_payments = array();
			foreach ( (array) ( $snapshot['payments'] ?? array() ) as $payment ) {
				$available = round( max( 0, (float) ( $payment['available_amount'] ?? 0 ) ), 6 );
				if ( $available <= 0 || 'salary_advance' === sanitize_key( (string) ( $payment['method_key'] ?? '' ) ) ) {
					continue;
				}

				$available_payments[] = array(
					'payment_id'        => (int) ( $payment['id'] ?? 0 ),
					'payment_number'    => sanitize_text_field( (string) ( $payment['payment_number'] ?? '' ) ),
					'available_amount'  => $available,
					'currency'          => sanitize_text_field( (string) ( $payment['currency'] ?? '' ) ),
					'method_key'        => sanitize_key( (string) ( $payment['method_key'] ?? '' ) ),
				);
			}

			if ( empty( $pending_orders ) || empty( $available_payments ) ) {
				continue;
			}

			$currency  = $this->resolve_common_currency(
				array_merge(
					wp_list_pluck( $pending_orders, 'currency' ),
					wp_list_pluck( $available_payments, 'currency' )
				)
			);
			$difference = round( abs( $available_total - $pending_total ), 6 );
			$severity   = $difference <= self::MATCH_TOLERANCE ? IntegrityCasesRepository::SEVERITY_HIGH : IntegrityCasesRepository::SEVERITY_MEDIUM;

			$cases[] = array(
				'case_key'      => sprintf( '%s:contact:%d', self::CASE_CREDIT_AND_DEBT_OVERLAP, (int) $contact_id ),
				'case_type'     => self::CASE_CREDIT_AND_DEBT_OVERLAP,
				'severity'      => $severity,
				'contact_id'    => (int) $contact_id,
				'contact_label' => sanitize_text_field( (string) ( $snapshot['contact']['display_name'] ?? ( '#' . $contact_id ) ) ),
				'amount'        => round( min( $available_total, $pending_total ), 6 ),
				'currency'      => $currency,
				'summary'       => $difference <= self::MATCH_TOLERANCE
					? sprintf( 'El perfil tiene credito disponible (%s) y deuda abierta (%s) casi equivalentes.', number_format_i18n( $available_total, 2 ), number_format_i18n( $pending_total, 2 ) )
					: sprintf( 'El perfil mantiene credito disponible (%s) y deuda abierta (%s) al mismo tiempo.', number_format_i18n( $available_total, 2 ), number_format_i18n( $pending_total, 2 ) ),
				'payload'       => array(
					'available_payment_total' => $available_total,
					'pending_order_total'     => $pending_total,
					'difference'              => $difference,
					'available_payments'      => $available_payments,
					'pending_orders'          => $pending_orders,
				),
			);
		}

		return $cases;
	}

	private function detect_dual_batch_incomplete( array $filters ) {
		$batch_ids = $this->resolve_dual_batch_ids( $filters );
		$cases     = array();

		foreach ( $batch_ids as $batch_id ) {
			$batch = $this->batches->find( $batch_id );
			if ( empty( $batch['id'] ) ) {
				continue;
			}

			$meta               = is_array( $batch['meta'] ?? null ) ? $batch['meta'] : array();
			$main_payment_id    = (int) ( $meta['main_payment_id'] ?? 0 );
			$discount_payment_id= (int) ( $meta['discount_payment_id'] ?? 0 );
			$items              = $this->batches->list_batch_items( (int) $batch['id'], 5000 );
			$error_items        = array();
			$open_documents     = array();
			$open_total         = 0.0;
			$error_count        = 0;

			foreach ( $items as $item ) {
				$item_status = sanitize_key( (string) ( $item['status'] ?? '' ) );
				if ( 'error' === $item_status ) {
					$error_count++;
					$error_items[] = array(
						'item_id'          => (int) ( $item['id'] ?? 0 ),
						'order_id'         => (int) ( $item['external_order_id'] ?? 0 ),
						'order_number'     => sanitize_text_field( (string) ( $item['order_number'] ?? '' ) ),
						'document_id'      => (int) ( $item['document_id'] ?? 0 ),
						'error_message'    => sanitize_text_field( (string) ( $item['error_message'] ?? '' ) ),
						'cover_amount'     => round( (float) ( $item['cover_amount'] ?? 0 ), 6 ),
						'discount_amount'  => round( (float) ( $item['discount_amount'] ?? 0 ), 6 ),
					);
				}

				$document_id = (int) ( $item['document_id'] ?? 0 );
				if ( $document_id <= 0 ) {
					continue;
				}

				$document = $this->documents->find( $document_id );
				if ( empty( $document['id'] ) ) {
					continue;
				}

				$balance = round( max( 0, (float) ( $document['balance'] ?? 0 ) ), 6 );
				if ( $balance <= 0 || 'void' === sanitize_key( (string) ( $document['financial_status'] ?? '' ) ) ) {
					continue;
				}

				$open_total += $balance;
				$open_documents[] = array(
					'document_id'   => (int) $document['id'],
					'order_id'      => (int) ( $item['external_order_id'] ?? 0 ),
					'order_number'  => sanitize_text_field( (string) ( $item['order_number'] ?? '' ) ),
					'balance'       => $balance,
					'currency'      => sanitize_text_field( (string) ( $document['currency'] ?? $batch['currency'] ?? '' ) ),
					'payment_status'=> sanitize_key( (string) ( $document['payment_status'] ?? '' ) ),
				);
			}

			$available_payments = array();
			$available_total    = 0.0;
			foreach ( array_filter( array( $main_payment_id, $discount_payment_id ) ) as $payment_id ) {
				$payment = $this->payments->find( $payment_id );
				if ( empty( $payment['id'] ) ) {
					continue;
				}

				$available = round( max( 0, (float) ( $payment['available_amount'] ?? 0 ) ), 6 );
				if ( $available <= 0 ) {
					continue;
				}

				$available_total += $available;
				$available_payments[] = array(
					'payment_id'        => (int) ( $payment['id'] ?? 0 ),
					'payment_number'    => sanitize_text_field( (string) ( $payment['payment_number'] ?? '' ) ),
					'payment_type'      => sanitize_key( (string) ( $payment['payment_type'] ?? '' ) ),
					'method_key'        => sanitize_key( (string) ( $payment['method_key'] ?? '' ) ),
					'available_amount'  => $available,
					'currency'          => sanitize_text_field( (string) ( $payment['currency'] ?? $batch['currency'] ?? '' ) ),
				);
			}

			$open_total      = round( $open_total, 6 );
			$available_total = round( $available_total, 6 );
			$has_active_inconsistency = $open_total > 0 || $available_total > 0;

			if ( ! $has_active_inconsistency ) {
				continue;
			}

			$difference = round( abs( $available_total - $open_total ), 6 );
			$severity   = ( $available_total > 0 && $open_total > 0 && $difference <= self::MATCH_TOLERANCE )
				? IntegrityCasesRepository::SEVERITY_HIGH
				: IntegrityCasesRepository::SEVERITY_MEDIUM;
			$contact_id = (int) ( $batch['contact_id'] ?? 0 );
			$contact    = $contact_id > 0 ? $this->contacts->find( $contact_id ) : null;

			$cases[] = array(
				'case_key'      => sprintf( '%s:batch:%d', self::CASE_DUAL_BATCH_INCOMPLETE, (int) $batch['id'] ),
				'case_type'     => self::CASE_DUAL_BATCH_INCOMPLETE,
				'severity'      => $severity,
				'contact_id'    => $contact_id,
				'contact_label' => sanitize_text_field( (string) ( $contact['display_name'] ?? ( '#' . $contact_id ) ) ),
				'batch_id'      => (int) $batch['id'],
				'amount'        => round( max( $available_total, $open_total ), 6 ),
				'currency'      => sanitize_text_field( (string) ( $batch['currency'] ?? '' ) ),
				'summary'       => ( $available_total > 0 && $open_total > 0 && $difference <= self::MATCH_TOLERANCE )
					? sprintf( 'El batch dual #%d dejo disponible y deuda abierta por el mismo orden de monto.', (int) $batch['id'] )
					: sprintf( 'El batch dual #%d quedo incompleto o con remanentes tecnicos.', (int) $batch['id'] ),
				'payload'       => array(
					'batch_status'         => sanitize_key( (string) ( $batch['status'] ?? '' ) ),
					'mode'                 => sanitize_key( (string) ( $batch['mode'] ?? '' ) ),
					'currency'             => sanitize_text_field( (string) ( $batch['currency'] ?? '' ) ),
					'method_key'           => sanitize_key( (string) ( $batch['method_key'] ?? '' ) ),
					'error_count'          => $error_count,
					'available_payment_total' => $available_total,
					'open_document_total'  => $open_total,
					'difference'           => $difference,
					'main_payment_id'      => $main_payment_id,
					'discount_payment_id'  => $discount_payment_id,
					'available_payments'   => $available_payments,
					'open_documents'       => $open_documents,
					'errored_items'        => $error_items,
				),
			);
		}

		return $cases;
	}

	private function detect_small_residual_open_balance( array $filters ) {
		$payment_ids = $this->resolve_small_residual_payment_ids( $filters );
		$cases       = array();

		foreach ( $payment_ids as $payment_id ) {
			$payment = $this->payments->find( $payment_id );
			if ( empty( $payment['id'] ) ) {
				continue;
			}

			$available = round( max( 0, (float) ( $payment['available_amount'] ?? 0 ) ), 6 );
			if ( $available <= 0 || $available > self::SMALL_RESIDUAL_LIMIT ) {
				continue;
			}

			$meta       = $this->decode_json( $payment['meta_json'] ?? '' );
			$contact_id = (int) ( $payment['contact_id'] ?? 0 );
			$contact    = $contact_id > 0 ? $this->contacts->find( $contact_id ) : null;

			$cases[] = array(
				'case_key'      => sprintf( '%s:payment:%d', self::CASE_SMALL_RESIDUAL_OPEN_BALANCE, (int) $payment['id'] ),
				'case_type'     => self::CASE_SMALL_RESIDUAL_OPEN_BALANCE,
				'severity'      => IntegrityCasesRepository::SEVERITY_LOW,
				'contact_id'    => $contact_id,
				'contact_label' => sanitize_text_field( (string) ( $contact['display_name'] ?? ( '#' . $contact_id ) ) ),
				'payment_id'    => (int) $payment['id'],
				'batch_id'      => ! empty( $meta['order_settlement_batch_id'] ) ? absint( $meta['order_settlement_batch_id'] ) : 0,
				'amount'        => $available,
				'currency'      => sanitize_text_field( (string) ( $payment['currency'] ?? '' ) ),
				'summary'       => sprintf( 'El pago %s conserva un residual tecnico disponible de %s.', sanitize_text_field( (string) ( $payment['payment_number'] ?? ( '#' . (int) $payment['id'] ) ) ), number_format_i18n( $available, 2 ) ),
				'payload'       => array(
					'payment_number'       => sanitize_text_field( (string) ( $payment['payment_number'] ?? '' ) ),
					'payment_type'         => sanitize_key( (string) ( $payment['payment_type'] ?? '' ) ),
					'method_key'           => sanitize_key( (string) ( $payment['method_key'] ?? '' ) ),
					'available_amount'     => $available,
					'meta'                 => $meta,
				),
			);
		}

		return $cases;
	}

	private function detect_cancelled_order_still_collectible( array $filters ) {
		$rows  = $this->list_potentially_collectible_order_rows( $filters );
		$cases = array();

		if ( ! function_exists( 'wc_get_order' ) ) {
			return $cases;
		}

		foreach ( $rows as $row ) {
			$order_id = (int) ( $row['external_order_id'] ?? 0 );
			if ( $order_id <= 0 ) {
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$actual_status = sanitize_key( (string) $order->get_status() );
			if ( ! in_array( $actual_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
				continue;
			}

			$balance = round( max( 0, (float) ( $row['balance'] ?? 0 ) ), 6 );
			$cases[] = array(
				'case_key'          => sprintf( '%s:order:%d', self::CASE_CANCELLED_ORDER_COLLECTIBLE, $order_id ),
				'case_type'         => self::CASE_CANCELLED_ORDER_COLLECTIBLE,
				'severity'          => IntegrityCasesRepository::SEVERITY_HIGH,
				'contact_id'        => ! empty( $row['contact_id'] ) ? absint( $row['contact_id'] ) : 0,
				'contact_label'     => sanitize_text_field( (string) ( $row['display_name'] ?? '' ) ),
				'external_order_id' => $order_id,
				'order_number'      => sanitize_text_field( (string) ( $row['order_number'] ?? '' ) ),
				'document_id'       => ! empty( $row['document_id'] ) ? absint( $row['document_id'] ) : 0,
				'amount'            => $balance,
				'currency'          => sanitize_text_field( (string) ( $row['currency'] ?? ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : '' ) ) ),
				'summary'           => sprintf( 'El pedido #%s ya esta %s en Woo/OpenPOS pero la capa financiera aun lo mantiene cobrable.', sanitize_text_field( (string) ( $row['order_number'] ?? $order_id ) ), sanitize_text_field( (string) $actual_status ) ),
				'payload'           => array(
					'actual_order_status'       => $actual_status,
					'indexed_status'            => sanitize_key( (string) ( $row['status'] ?? '' ) ),
					'indexed_balance'           => $balance,
					'indexed_is_open'           => ! empty( $row['is_open'] ),
					'indexed_operationally_collectible' => ! empty( $row['operationally_collectible'] ),
					'indexed_fiscal_year'       => (int) ( $row['fiscal_year'] ?? 0 ),
					'indexed_document_id'       => ! empty( $row['document_id'] ) ? absint( $row['document_id'] ) : 0,
					'indexed_source_link_id'    => ! empty( $row['source_link_id'] ) ? absint( $row['source_link_id'] ) : 0,
				),
			);
		}

		return $cases;
	}

	private function detect_near_zero_open_document( array $filters ) {
		$document_ids = $this->resolve_near_zero_document_ids( $filters );
		$cases        = array();

		foreach ( $document_ids as $document_id ) {
			$document = $this->documents->find( $document_id );
			if ( empty( $document['id'] ) ) {
				continue;
			}

			$balance = round( max( 0, (float) ( $document['balance'] ?? 0 ) ), 6 );
			if ( $balance <= 0 || $balance > self::NEAR_ZERO_BALANCE_LIMIT || 'void' === sanitize_key( (string) ( $document['financial_status'] ?? '' ) ) ) {
				continue;
			}

			$contact_id = (int) ( $document['contact_id'] ?? 0 );
			$contact    = $contact_id > 0 ? $this->contacts->find( $contact_id ) : null;
			$links      = $this->source_links->find_for_document( (int) $document['id'] );
			$order_id   = 0;
			$order_ref  = '';
			foreach ( $links as $link ) {
				if ( 'shop_order' === sanitize_key( (string) ( $link['object_type'] ?? '' ) ) ) {
					$order_id  = ! empty( $link['external_id'] ) ? absint( $link['external_id'] ) : 0;
					$order_ref = sanitize_text_field( (string) ( $link['external_ref'] ?? '' ) );
					break;
				}
			}

			$cases[] = array(
				'case_key'          => sprintf( '%s:document:%d', self::CASE_NEAR_ZERO_OPEN_DOCUMENT, (int) $document['id'] ),
				'case_type'         => self::CASE_NEAR_ZERO_OPEN_DOCUMENT,
				'severity'          => IntegrityCasesRepository::SEVERITY_LOW,
				'contact_id'        => $contact_id,
				'contact_label'     => sanitize_text_field( (string) ( $contact['display_name'] ?? ( '#' . $contact_id ) ) ),
				'external_order_id' => $order_id,
				'order_number'      => $order_ref,
				'document_id'       => (int) $document['id'],
				'amount'            => $balance,
				'currency'          => sanitize_text_field( (string) ( $document['currency'] ?? '' ) ),
				'summary'           => sprintf( 'El documento #%d sigue abierto con un residual pequeno de %s.', (int) $document['id'], number_format_i18n( $balance, 2 ) ),
				'payload'           => array(
					'document_type'     => sanitize_key( (string) ( $document['document_type'] ?? '' ) ),
					'payment_status'    => sanitize_key( (string) ( $document['payment_status'] ?? '' ) ),
					'financial_status'  => sanitize_key( (string) ( $document['financial_status'] ?? '' ) ),
					'external_reference'=> sanitize_text_field( (string) ( $document['external_reference'] ?? '' ) ),
					'linked_order_id'   => $order_id,
					'linked_order_ref'  => $order_ref,
				),
			);
		}

		return $cases;
	}

	private function resolve_credit_overlap_contact_ids( array $filters ) {
		if ( ! empty( $filters['contact_id'] ) ) {
			return array( (int) $filters['contact_id'] );
		}

		$payments_table = Tables::name( 'payments' );
		$where          = array(
			'contact_id > 0',
			"status = 'posted'",
			'available_amount > 0',
			"COALESCE(method_key, '') <> 'salary_advance'",
		);
		$params         = array();

		if ( ! empty( $filters['range_from'] ) ) {
			$where[]  = 'payment_date >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[]  = 'payment_date <= %s';
			$params[] = $filters['range_to'];
		}

		$sql = "SELECT DISTINCT contact_id FROM {$payments_table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY contact_id ASC';
		$ids = empty( $params )
			? $this->db()->get_col( $sql )
			: $this->db()->get_col( $this->db()->prepare( $sql, ...$params ) );

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	private function resolve_dual_batch_ids( array $filters ) {
		if ( ! empty( $filters['batch_id'] ) ) {
			return array( (int) $filters['batch_id'] );
		}

		$table  = Tables::name( 'order_settlement_batches' );
		$where  = array(
			"mode = 'dual'",
			"status = 'completed_with_errors'",
		);
		$params = array();

		if ( ! empty( $filters['contact_id'] ) ) {
			$where[]  = 'contact_id = %d';
			$params[] = (int) $filters['contact_id'];
		}

		if ( ! empty( $filters['range_from'] ) ) {
			$where[]  = 'DATE(created_at) >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[]  = 'DATE(created_at) <= %s';
			$params[] = $filters['range_to'];
		}

		$sql = "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC';
		$ids = empty( $params )
			? $this->db()->get_col( $sql )
			: $this->db()->get_col( $this->db()->prepare( $sql, ...$params ) );

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	private function resolve_small_residual_payment_ids( array $filters ) {
		if ( ! empty( $filters['payment_id'] ) ) {
			return array( (int) $filters['payment_id'] );
		}

		$table  = Tables::name( 'payments' );
		$where  = array(
			"status = 'posted'",
			'available_amount > 0',
			'available_amount <= %f',
			"(method_key IN ('dual_price_discount', 'internal_compensation') OR meta_json LIKE '%order_settlement_batch_id%')",
		);
		$params = array( self::SMALL_RESIDUAL_LIMIT );

		if ( ! empty( $filters['contact_id'] ) ) {
			$where[]  = 'contact_id = %d';
			$params[] = (int) $filters['contact_id'];
		}

		if ( ! empty( $filters['batch_id'] ) ) {
			$where[]  = 'meta_json LIKE %s';
			$params[] = '%"order_settlement_batch_id":' . (int) $filters['batch_id'] . '%';
		}

		if ( ! empty( $filters['range_from'] ) ) {
			$where[]  = 'payment_date >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[]  = 'payment_date <= %s';
			$params[] = $filters['range_to'];
		}

		$sql = "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY payment_date DESC, id DESC';
		$ids = $this->db()->get_col( $this->db()->prepare( $sql, ...$params ) );

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	private function resolve_near_zero_document_ids( array $filters ) {
		if ( ! empty( $filters['document_id'] ) ) {
			return array( (int) $filters['document_id'] );
		}

		$table  = Tables::name( 'documents' );
		$where  = array(
			"balance_nature = 'receivable'",
			"financial_status <> 'void'",
			"payment_status <> 'paid'",
			'balance > 0',
			'balance <= %f',
		);
		$params = array( self::NEAR_ZERO_BALANCE_LIMIT );

		if ( ! empty( $filters['contact_id'] ) ) {
			$where[]  = 'contact_id = %d';
			$params[] = (int) $filters['contact_id'];
		}

		if ( ! empty( $filters['range_from'] ) ) {
			$where[]  = 'issue_date >= %s';
			$params[] = $filters['range_from'];
		}

		if ( ! empty( $filters['range_to'] ) ) {
			$where[]  = 'issue_date <= %s';
			$params[] = $filters['range_to'];
		}

		$sql = "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY issue_date DESC, id DESC';
		$ids = $this->db()->get_col( $this->db()->prepare( $sql, ...$params ) );

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	private function list_potentially_collectible_order_rows( array $filters ) {
		if ( is_object( $this->order_index ) && is_callable( array( $this->order_index, 'list_collectible_orders' ) ) ) {
			return (array) $this->order_index->list_collectible_orders(
				array(
					'external_order_id' => (int) ( $filters['external_order_id'] ?? 0 ),
					'contact_id'        => (int) ( $filters['contact_id'] ?? 0 ),
					'range_from'        => $filters['range_from'] ?? '',
					'range_to'          => $filters['range_to'] ?? '',
					'limit'             => 5000,
				)
			);
		}

		return array();
	}

	private function resolve_common_currency( array $currencies ) {
		$currencies = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $currency ) {
							return strtoupper( sanitize_text_field( (string) $currency ) );
						},
						$currencies
					)
				)
			)
		);

		if ( 1 === count( $currencies ) ) {
			return (string) $currencies[0];
		}

		return count( $currencies ) > 1 ? 'MIXED' : '';
	}

	private function decode_json( $json ) {
		$data = json_decode( (string) $json, true );

		return is_array( $data ) ? $data : array();
	}
}
