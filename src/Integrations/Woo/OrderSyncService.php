<?php

namespace ASDLabs\Finance\Integrations\Woo;

use ASDLabs\Finance\Core\Tables;
use ASDLabs\Finance\Finance\ContactsRepository;
use ASDLabs\Finance\Finance\DocumentsRepository;
use ASDLabs\Finance\Finance\EventsRepository;
use ASDLabs\Finance\Finance\PaymentAllocationsRepository;
use ASDLabs\Finance\Finance\SourceLinksRepository;

final class OrderSyncService {
	const ORDER_LIST_CACHE_TTL = 60;

	private $contacts;
	private $documents;
	private $events;
	private $allocations;
	private $source_links;

	public function __construct() {
		$this->contacts     = new ContactsRepository();
		$this->documents    = new DocumentsRepository();
		$this->events       = new EventsRepository();
		$this->allocations  = new PaymentAllocationsRepository();
		$this->source_links = new SourceLinksRepository();
	}

	public function sync_recent_orders( array $args = array() ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'asdl_fin_wc_missing', 'WooCommerce no esta disponible para sincronizar pedidos.' );
		}

		$limit        = max( 1, (int) ( $args['limit'] ?? 25 ) );
		$days         = max( 1, (int) ( $args['days'] ?? 30 ) );
		$source       = sanitize_key( $args['source'] ?? 'all' );
		$order_ids    = wc_get_orders(
			array(
				'limit'   => $limit,
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);
		$after_ts     = strtotime( '-' . $days . ' days', current_time( 'timestamp' ) );
		$results      = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'errors'    => 0,
			'processed' => 0,
			'items'     => array(),
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$created = $order->get_date_created();
			if ( $created && $created->getTimestamp() < $after_ts ) {
				continue;
			}

			$provider = $this->detect_provider( $order );
			if ( 'all' !== $source && $provider !== $source ) {
				continue;
			}

			$result = $this->sync_order( $order_id, array( 'trigger' => 'manual_batch' ) );

			if ( is_wp_error( $result ) ) {
				$results['errors']++;
				$results['items'][] = array(
					'order_id' => (int) $order_id,
					'status'   => 'error',
					'message'  => $result->get_error_message(),
				);
				continue;
			}

			$status = $result['status'] ?? 'unchanged';
			if ( isset( $results[ $status ] ) ) {
				$results[ $status ]++;
			}

			$results['processed']++;
			$results['items'][] = $result;
		}

		return $results;
	}

	public function list_orders( array $args = array() ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$raw_limit   = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$limit       = $raw_limit <= 0 ? 0 : max( 1, min( 2000, $raw_limit ) );
		$source      = sanitize_key( $args['source'] ?? 'all' );
		$customer_id = ! empty( $args['customer_id'] ) ? absint( $args['customer_id'] ) : 0;
		$email       = sanitize_email( $args['email'] ?? '' );
		$range_from  = sanitize_text_field( (string) ( $args['range_from'] ?? '' ) );
		$range_to    = sanitize_text_field( (string) ( $args['range_to'] ?? '' ) );
		$statuses    = ! empty( $args['statuses'] ) && is_array( $args['statuses'] )
			? array_map( 'sanitize_key', $args['statuses'] )
			: array( 'pending', 'on-hold', 'processing', 'completed' );
		sort( $statuses );
		$batch_size  = 200;
		$max_pages   = $limit > 0 ? max( 3, (int) ceil( ( $limit * 12 ) / $batch_size ) + 2 ) : 50;
		$cache_key   = 'asdl_fin_order_list_v2_' . md5(
			wp_json_encode(
				array(
					'limit'       => $limit,
					'source'      => $source,
					'customer_id' => $customer_id,
					'email'       => $email,
					'statuses'    => $statuses,
					'range_from'  => $range_from,
					'range_to'    => $range_to,
				)
			)
		);
		$cached      = get_transient( $cache_key );
		$items       = array();

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query_args = array(
			'limit'   => $batch_size,
			'type'    => 'shop_order',
			'status'  => $statuses,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		);

		$date_created_filter = $this->build_wc_order_date_filter( $range_from, $range_to );
		if ( '' !== $date_created_filter ) {
			$query_args['date_created'] = $date_created_filter;
		}

		$order_ids  = $this->collect_order_ids( $query_args, $max_pages, $customer_id, $email );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$provider = $this->detect_provider( $order );
			if ( 'all' !== $source && $provider !== $source ) {
				continue;
			}

			if ( $customer_id > 0 && (int) $order->get_customer_id() !== $customer_id ) {
				if ( '' === $email || sanitize_email( $order->get_billing_email() ) !== $email ) {
					continue;
				}
			} elseif ( '' !== $email && $customer_id <= 0 && sanitize_email( $order->get_billing_email() ) !== $email ) {
				continue;
			}

			if ( ! $this->order_matches_date_range( $order, $args ) ) {
				continue;
			}

			$items[] = $this->describe_order( $order );

			if ( $limit > 0 && count( $items ) >= $limit ) {
				break;
			}
		}

		set_transient( $cache_key, $items, self::ORDER_LIST_CACHE_TTL );

		return $items;
	}

	public function summarize_open_orders( array $args = array() ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'pending_total'         => 0.0,
				'order_count'           => 0,
				'group_count'           => 0,
				'linked_profiles'       => 0,
				'unlinked_groups'       => 0,
				'in_range_pending_total'=> 0.0,
				'historical_pending_total' => 0.0,
				'in_range_count'        => 0,
				'historical_count'      => 0,
			);
		}

		$source              = sanitize_key( $args['source'] ?? 'all' );
		$customer_id         = ! empty( $args['customer_id'] ) ? absint( $args['customer_id'] ) : 0;
		$email               = sanitize_email( $args['email'] ?? '' );
		$query_range_from    = sanitize_text_field( (string) ( $args['range_from'] ?? '' ) );
		$query_range_to      = sanitize_text_field( (string) ( $args['range_to'] ?? '' ) );
		$classify_range_from = sanitize_text_field( (string) ( $args['classify_range_from'] ?? $query_range_from ) );
		$classify_range_to   = sanitize_text_field( (string) ( $args['classify_range_to'] ?? $query_range_to ) );
		$statuses            = ! empty( $args['statuses'] ) && is_array( $args['statuses'] )
			? array_map( 'sanitize_key', $args['statuses'] )
			: $this->collectible_statuses();
		sort( $statuses );

		$cache_key = 'asdl_fin_order_summary_v1_' . md5(
			wp_json_encode(
				array(
					'source'              => $source,
					'customer_id'         => $customer_id,
					'email'               => $email,
					'query_range_from'    => $query_range_from,
					'query_range_to'      => $query_range_to,
					'classify_range_from' => $classify_range_from,
					'classify_range_to'   => $classify_range_to,
					'statuses'            => $statuses,
				)
			)
		);
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$batch_size  = 200;
		$max_pages   = 50;
		$query_args  = array(
			'limit'   => $batch_size,
			'type'    => 'shop_order',
			'status'  => $statuses,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		);
		$date_filter = $this->build_wc_order_date_filter( $query_range_from, $query_range_to );
		if ( '' !== $date_filter ) {
			$query_args['date_created'] = $date_filter;
		}

		$order_ids = $this->collect_order_ids( $query_args, $max_pages, $customer_id, $email );
		$context   = $this->load_order_document_context_map( $order_ids );
		$summary   = array(
			'pending_total'            => 0.0,
			'order_count'              => 0,
			'group_count'              => 0,
			'linked_profiles'          => 0,
			'unlinked_groups'          => 0,
			'in_range_pending_total'   => 0.0,
			'historical_pending_total' => 0.0,
			'in_range_count'           => 0,
			'historical_count'         => 0,
		);
		$group_types = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$provider = $this->detect_provider( $order );
			if ( 'all' !== $source && $provider !== $source ) {
				continue;
			}

			if ( $customer_id > 0 && (int) $order->get_customer_id() !== $customer_id ) {
				if ( '' === $email || sanitize_email( $order->get_billing_email() ) !== $email ) {
					continue;
				}
			} elseif ( '' !== $email && $customer_id <= 0 && sanitize_email( $order->get_billing_email() ) !== $email ) {
				continue;
			}

			if ( ! $this->order_matches_date_range(
				$order,
				array(
					'range_from' => $query_range_from,
					'range_to'   => $query_range_to,
				)
			) ) {
				continue;
			}

			$link     = $context['links'][ $provider . ':' . (int) $order->get_id() ] ?? null;
			$document = null;
			if ( ! empty( $link['document_id'] ) ) {
				$document = $context['documents'][ (int) $link['document_id'] ] ?? null;
			}

			$status  = sanitize_key( $order->get_status() );
			$total   = (float) $order->get_total();
			$paid    = ! empty( $document['id'] )
				? (float) ( $document['paid_total'] ?? 0 )
				: $this->map_paid_total( $order, $status, $total, $provider );
			$balance = ! empty( $document['id'] )
				? (float) ( $document['balance'] ?? 0 )
				: max( 0, round( $total - $paid, 6 ) );

			if ( $balance <= 0.00001 || ! $this->is_collectible_status( $status ) ) {
				continue;
			}

			$date     = '';
			$created  = $order->get_date_created();
			if ( $created ) {
				$date = $created->date_i18n( 'Y-m-d' );
			}
			$in_range = true;
			if ( '' !== $date ) {
				if ( '' !== $classify_range_from && $date < $classify_range_from ) {
					$in_range = false;
				}
				if ( '' !== $classify_range_to && $date > $classify_range_to ) {
					$in_range = false;
				}
			}

			$summary['pending_total'] += $balance;
			$summary['order_count']++;

			if ( $in_range ) {
				$summary['in_range_pending_total'] += $balance;
				$summary['in_range_count']++;
			} else {
				$summary['historical_pending_total'] += $balance;
				$summary['historical_count']++;
			}

			$order_customer_id = (int) $order->get_customer_id();
			$order_email       = sanitize_email( $order->get_billing_email() );
			if ( $order_customer_id > 0 ) {
				$group_types[ 'wp:' . $order_customer_id ] = 'linked';
			} elseif ( '' !== $order_email ) {
				$group_types[ 'email:' . $order_email ] = 'unlinked';
			} else {
				$group_types[ 'order:' . (int) $order->get_id() ] = 'unlinked';
			}
		}

		$summary['group_count'] = count( $group_types );
		foreach ( $group_types as $group_type ) {
			if ( 'linked' === $group_type ) {
				$summary['linked_profiles']++;
			} else {
				$summary['unlinked_groups']++;
			}
		}

		foreach ( $summary as $key => $value ) {
			if ( is_float( $value ) ) {
				$summary[ $key ] = round( $value, 6 );
			}
		}

		set_transient( $cache_key, $summary, self::ORDER_LIST_CACHE_TTL );

		return $summary;
	}

	private function build_wc_order_date_filter( $from, $to ) {
		$from = sanitize_text_field( (string) $from );
		$to   = sanitize_text_field( (string) $to );

		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '';
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '';

		if ( '' !== $from && '' !== $to ) {
			return $from . '...' . $to;
		}

		if ( '' !== $from ) {
			return '>=' . $from;
		}

		if ( '' !== $to ) {
			return '<=' . $to;
		}

		return '';
	}

	private function load_order_document_context_map( array $order_ids ) {
		global $wpdb;

		$maps = array(
			'links'     => array(),
			'documents' => array(),
		);

		$order_ids = array_values(
			array_filter(
				array_map( 'absint', $order_ids )
			)
		);

		if ( empty( $order_ids ) ) {
			return $maps;
		}

		$source_links_table = Tables::name( 'source_links' );
		$documents_table    = Tables::name( 'documents' );
		$source_repo        = new SourceLinksRepository();
		$documents_repo     = new DocumentsRepository();

		if ( ! $source_repo->exists() || ! $documents_repo->exists() ) {
			return $maps;
		}

		$link_placeholders = implode( ', ', array_fill( 0, count( $order_ids ), '%s' ) );
		$link_params       = array_merge(
			array( 'shop_order', 'woocommerce', 'openpos' ),
			array_map( 'strval', $order_ids )
		);
		$link_sql          = "SELECT id, document_id, provider, external_id
			FROM {$source_links_table}
			WHERE object_type = %s
			AND provider IN (%s, %s)
			AND external_id IN ({$link_placeholders})
			ORDER BY id DESC";
		$links             = $wpdb->get_results(
			$wpdb->prepare( $link_sql, $link_params ),
			ARRAY_A
		);

		$document_ids = array();
		foreach ( $links as $link ) {
			$key = sanitize_key( (string) ( $link['provider'] ?? '' ) ) . ':' . absint( $link['external_id'] ?? 0 );
			if ( '' === $key || isset( $maps['links'][ $key ] ) ) {
				continue;
			}

			$maps['links'][ $key ] = $link;
			if ( ! empty( $link['document_id'] ) ) {
				$document_ids[] = absint( $link['document_id'] );
			}
		}

		$document_ids = array_values( array_unique( array_filter( $document_ids ) ) );
		if ( empty( $document_ids ) ) {
			return $maps;
		}

		$document_placeholders = implode( ', ', array_fill( 0, count( $document_ids ), '%d' ) );
		$document_sql          = "SELECT id, total, paid_total, balance, payment_status, financial_status
			FROM {$documents_table}
			WHERE id IN ({$document_placeholders})";
		$documents             = $wpdb->get_results(
			$wpdb->prepare( $document_sql, $document_ids ),
			ARRAY_A
		);

		foreach ( $documents as $document ) {
			$maps['documents'][ (int) $document['id'] ] = $document;
		}

		return $maps;
	}

	private function collect_order_ids( array $query_args, $max_pages, $customer_id, $email ) {
		$queries = array();

		if ( $customer_id > 0 ) {
			$queries[] = array_merge(
				$query_args,
				array(
					'customer' => $customer_id,
				)
			);
		}

		if ( '' !== $email ) {
			$queries[] = array_merge(
				$query_args,
				array(
					'customer' => $email,
				)
			);
		}

		if ( empty( $queries ) ) {
			$queries[] = $query_args;
		}

		$ids  = array();
		$seen = array();

		foreach ( $queries as $query ) {
			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$order_ids = wc_get_orders(
					array_merge(
						$query,
						array(
							'paged' => $page,
						)
					)
				);

				if ( empty( $order_ids ) ) {
					break;
				}

				foreach ( $order_ids as $order_id ) {
					$order_id = (int) $order_id;
					if ( $order_id <= 0 || isset( $seen[ $order_id ] ) ) {
						continue;
					}

					$seen[ $order_id ] = true;
					$ids[]             = $order_id;
				}

				if ( count( $order_ids ) < (int) ( $query_args['limit'] ?? 200 ) ) {
					break;
				}
			}
		}

		return $ids;
	}

	public function describe_order( $order ) {
		$provider = $this->detect_provider( $order );
		$link     = $this->find_existing_link( $order->get_id() );
		$document = ! empty( $link['document_id'] ) ? $this->documents->find( (int) $link['document_id'] ) : null;
		$contact_id = $this->resolve_contact_id( $order, $document );
		$date     = $order->get_date_created();
		$status   = sanitize_key( $order->get_status() );
		$total    = (float) $order->get_total();
		$paid     = ! empty( $document['id'] )
			? (float) ( $document['paid_total'] ?? 0 )
			: $this->map_paid_total( $order, $status, $total, $provider );
		$balance  = ! empty( $document['id'] )
			? (float) ( $document['balance'] ?? 0 )
			: max( 0, round( $total - $paid, 6 ) );
		$is_open  = $balance > 0.00001 && $this->is_collectible_status( $status );

		return array(
			'order_id'       => (int) $order->get_id(),
			'order_number'   => (string) $order->get_order_number(),
			'customer_id'    => (int) $order->get_customer_id(),
			'billing_email'  => sanitize_email( $order->get_billing_email() ),
			'display_name'   => $this->resolve_order_display_name( $order ),
			'status'         => $status,
			'status_label'   => function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $status,
			'provider'       => $provider,
			'date_created'   => $date ? $date->date_i18n( 'Y-m-d H:i' ) : '',
			'total'          => $total,
			'currency'       => (string) $order->get_currency(),
			'item_count'     => method_exists( $order, 'get_item_count' ) ? (int) $order->get_item_count() : 0,
			'contact_id'     => $contact_id,
			'document_id'    => ! empty( $link['document_id'] ) ? (int) $link['document_id'] : 0,
			'source_link_id' => ! empty( $link['id'] ) ? (int) $link['id'] : 0,
			'is_managed'     => ! empty( $link['document_id'] ),
			'effective_total'     => ! empty( $document['id'] ) ? (float) ( $document['total'] ?? $total ) : $total,
			'effective_paid_total'=> $paid,
			'effective_due_total' => $balance,
			'is_effectively_open' => $is_open,
			'document_payment_status' => ! empty( $document['payment_status'] ) ? sanitize_key( (string) $document['payment_status'] ) : $this->map_payment_status( $status, $paid, $balance, $this->map_financial_status( $status ) ),
			'edit_url'       => $this->resolve_order_edit_url( $order ),
		);
	}

	public function detect_order_provider_for_admin( $order ) {
		return $this->detect_provider( $order );
	}

	public function collectible_statuses() {
		return array( 'pending', 'on-hold', 'processing' );
	}

	public function is_financially_managed_order( $order_id ) {
		$context = $this->get_linked_document_context( $order_id );

		return ! empty( $context['document']['id'] );
	}

	public function sync_order( $order_id, array $args = array() ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'asdl_fin_wc_missing', 'WooCommerce no esta disponible para sincronizar pedidos.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'asdl_fin_order_missing', 'No se encontro el pedido indicado para sincronizar.' );
		}

		$provider         = $this->detect_provider( $order );
		$link             = $this->find_existing_link( $order->get_id() );
		$document         = ! empty( $link['document_id'] ) ? $this->documents->find( (int) $link['document_id'] ) : null;
		$allocations      = ! empty( $document['id'] ) ? $this->allocations->count_for_document( (int) $document['id'] ) : 0;
		$preserve_payment = $allocations > 0;
		$preserve_class   = ( ! empty( $document['manual_override'] ) || ! empty( $link['override_locked'] ) );
		$contact_id       = $this->resolve_contact_id( $order, $document );
		$payload          = $this->build_document_payload( $order, $provider, $document, $contact_id, $preserve_payment );
		$sync_hash        = $this->build_sync_hash( $payload, $provider, $order, $preserve_payment, $preserve_class );
		$status           = 'updated';

		if ( ! empty( $link['sync_hash'] ) && $link['sync_hash'] === $sync_hash && ! empty( $document['id'] ) ) {
			$this->source_links->upsert(
				array(
					'document_id'    => (int) $document['id'],
					'provider'       => $provider,
					'object_type'    => 'shop_order',
					'external_id'    => (string) $order->get_id(),
					'external_ref'   => (string) $order->get_order_number(),
					'sync_hash'      => $sync_hash,
					'last_synced_at' => current_time( 'mysql' ),
					'last_seen_at'   => current_time( 'mysql' ),
					'meta_json'      => $this->build_link_meta( $order, $provider ),
				)
			);

			return array(
				'status'      => 'unchanged',
				'order_id'    => (int) $order->get_id(),
				'document_id' => (int) $document['id'],
				'provider'    => $provider,
				'message'     => 'Sin cambios detectados.',
			);
		}

		if ( ! empty( $document['id'] ) ) {
			$updated = $this->documents->update_from_sync(
				(int) $document['id'],
				$payload,
				array(
					'preserve_classification' => $preserve_class,
					'preserve_payment'        => $preserve_payment,
				)
			);

			if ( ! $updated ) {
				return new \WP_Error( 'asdl_fin_sync_update_failed', 'No se pudo actualizar el movimiento sincronizado.' );
			}

			$document_id = (int) $document['id'];
		} else {
			$document_id = $this->documents->create( $payload );

			if ( is_wp_error( $document_id ) ) {
				return $document_id;
			}

			$status = 'created';
		}

		$this->source_links->upsert(
			array(
				'document_id'    => (int) $document_id,
				'provider'       => $provider,
				'object_type'    => 'shop_order',
				'external_id'    => (string) $order->get_id(),
				'external_ref'   => (string) $order->get_order_number(),
				'sync_hash'      => $sync_hash,
				'last_synced_at' => current_time( 'mysql' ),
				'last_seen_at'   => current_time( 'mysql' ),
				'meta_json'      => $this->build_link_meta( $order, $provider ),
			)
		);

		$this->events->log(
			'document',
			(int) $document_id,
			'synced',
			'Movimiento sincronizado desde pedido Woo/OpenPOS.',
			array(
				'order_id'         => (int) $order->get_id(),
				'provider'         => $provider,
				'trigger'          => sanitize_key( $args['trigger'] ?? 'unknown' ),
				'preserve_payment' => $preserve_payment,
				'preserve_class'   => $preserve_class,
				'action'           => $status,
			)
		);

		do_action(
			'asdl_fin_sync_completed',
			array(
				'provider'    => $provider,
				'object_type' => 'shop_order',
				'order_id'    => (int) $order->get_id(),
				'document_id' => (int) $document_id,
				'status'      => $status,
			)
		);

		return array(
			'status'      => $status,
			'order_id'    => (int) $order->get_id(),
			'document_id' => (int) $document_id,
			'provider'    => $provider,
			'message'     => 'Movimiento sincronizado correctamente.',
		);
	}

	public function get_linked_document_context( $order_id ) {
		$link = $this->find_existing_link( $order_id );

		if ( empty( $link['document_id'] ) ) {
			return null;
		}

		$document = $this->documents->find( (int) $link['document_id'] );
		if ( empty( $document ) ) {
			return null;
		}

		return array(
			'link'             => $link,
			'document'         => $document,
			'allocations_count'=> $this->allocations->count_for_document( (int) $document['id'] ),
		);
	}

	public function pending_orders_snapshot( array $args = array() ) {
		$orders = $this->list_orders(
			array(
				'limit'    => max( 1, (int) ( $args['limit'] ?? 20 ) ),
				'source'   => sanitize_key( $args['source'] ?? 'all' ),
				'statuses' => $this->collectible_statuses(),
			)
		);
		$orders = array_values(
			array_filter(
				$orders,
				static function ( array $order ) {
					return ! empty( $order['is_effectively_open'] );
				}
			)
		);

		$summary = array(
			'pending_count'   => count( $orders ),
			'managed_count'   => 0,
			'unmanaged_count' => 0,
			'pending_total'   => 0.0,
		);

		foreach ( $orders as $order ) {
			if ( ! empty( $order['is_managed'] ) ) {
				$summary['managed_count']++;
			} else {
				$summary['unmanaged_count']++;
			}

			$summary['pending_total'] += (float) ( $order['effective_due_total'] ?? $order['total'] ?? 0 );
		}

		return array(
			'summary' => $summary,
			'orders'  => $orders,
		);
	}

	private function detect_provider( $order ) {
		$source = sanitize_key( (string) $order->get_meta( '_op_order_source' ) );

		return 'openpos' === $source ? 'openpos' : 'woocommerce';
	}

	private function find_existing_link( $order_id ) {
		$order_id = (string) absint( $order_id );

		$link = $this->source_links->find_by_provider_object_external( 'openpos', 'shop_order', $order_id );
		if ( ! empty( $link ) ) {
			return $link;
		}

		$link = $this->source_links->find_by_provider_object_external( 'woocommerce', 'shop_order', $order_id );
		if ( ! empty( $link ) ) {
			return $link;
		}

		return null;
	}

	private function order_matches_date_range( $order, array $args ) {
		$date = $order->get_date_created();
		if ( ! $date ) {
			return false;
		}

		$from = sanitize_text_field( (string) ( $args['range_from'] ?? '' ) );
		$to   = sanitize_text_field( (string) ( $args['range_to'] ?? '' ) );
		$ymd  = $date->date_i18n( 'Y-m-d' );

		if ( '' !== $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && $ymd < $from ) {
			return false;
		}

		if ( '' !== $to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && $ymd > $to ) {
			return false;
		}

		return true;
	}

	private function resolve_order_display_name( $order ) {
		$display_name = trim(
			implode(
				' ',
				array_filter(
					array(
						sanitize_text_field( (string) $order->get_billing_first_name() ),
						sanitize_text_field( (string) $order->get_billing_last_name() ),
					)
				)
			)
		);

		if ( '' !== $display_name ) {
			return $display_name;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			$user = get_user_by( 'id', $customer_id );
			if ( $user ) {
				return sanitize_text_field( (string) $user->display_name );
			}
		}

		$email = sanitize_email( $order->get_billing_email() );
		if ( '' !== $email ) {
			return $email;
		}

		return 'Pedido #' . $order->get_order_number();
	}

	private function resolve_contact_id( $order, $existing_document ) {
		if ( ! empty( $existing_document['contact_id'] ) ) {
			return (int) $existing_document['contact_id'];
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			$contact = $this->contacts->find_by_wp_user_id( $customer_id );

			if ( ! empty( $contact['id'] ) ) {
				return (int) $contact['id'];
			}
		}

		$email = sanitize_email( $order->get_billing_email() );
		if ( '' !== $email ) {
			$contact = $this->contacts->find_by_email( $email );

			if ( ! empty( $contact['id'] ) ) {
				return (int) $contact['id'];
			}
		}

		$display_name = trim( $order->get_formatted_billing_full_name() );

		if ( '' === $display_name && $customer_id > 0 ) {
			$user = get_user_by( 'id', $customer_id );
			if ( $user ) {
				$display_name = $user->display_name;
			}
		}

		if ( '' === $display_name ) {
			$display_name = 'Cliente pedido #' . $order->get_order_number();
		}

		$contact_id = $this->contacts->create(
			array(
				'display_name' => $display_name,
				'legal_name'   => trim( $order->get_billing_company() ),
				'contact_type' => 'client',
				'wp_user_id'   => $customer_id > 0 ? $customer_id : null,
				'email'        => $email,
				'phone'        => sanitize_text_field( $order->get_billing_phone() ),
				'document_id'  => '',
				'status'       => 'active',
				'notes'        => 'Contacto creado automaticamente desde sincronizacion Woo/OpenPOS.',
			)
		);

		return is_wp_error( $contact_id ) ? 0 : (int) $contact_id;
	}

	private function build_document_payload( $order, $provider, $existing_document, $contact_id, $preserve_payment ) {
		$order_status     = sanitize_key( $order->get_status() );
		$total            = (float) $order->get_total();
		$financial_status = $this->map_financial_status( $order_status );
		$paid_total       = $preserve_payment && ! empty( $existing_document ) ? (float) $existing_document['paid_total'] : $this->map_paid_total( $order, $order_status, $total, $provider );
		$balance          = $preserve_payment && ! empty( $existing_document ) ? (float) $existing_document['balance'] : max( 0, round( $total - $paid_total, 6 ) );
		$payment_status   = $preserve_payment && ! empty( $existing_document ) ? sanitize_key( $existing_document['payment_status'] ) : $this->map_payment_status( $order_status, $paid_total, $balance, $financial_status );
		$issue_date       = $order->get_date_created();
		$due_date         = in_array( $order_status, array( 'pending', 'on-hold' ), true ) ? $issue_date : null;

		$payload = array(
			'document_number'    => (string) $order->get_order_number(),
			'document_type'      => 'woo_sale',
			'source_type'        => $provider,
			'contact_id'         => $contact_id > 0 ? $contact_id : null,
			'wp_user_id'         => (int) $order->get_customer_id() > 0 ? (int) $order->get_customer_id() : null,
			'title'              => 'Pedido #' . $order->get_order_number(),
			'external_reference' => 'shop_order:' . $order->get_id(),
			'issue_date'         => $issue_date ? $issue_date->date_i18n( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'due_date'           => $due_date ? $due_date->date_i18n( 'Y-m-d' ) : null,
			'currency'           => strtoupper( (string) $order->get_currency() ),
			'total'              => $total,
			'paid_total'         => $paid_total,
			'balance'            => $balance,
			'operational_status' => $order_status,
			'financial_status'   => $financial_status,
			'payment_status'     => $payment_status,
			'manual_override'    => ! empty( $existing_document['manual_override'] ) ? 1 : 0,
			'notes'              => sprintf( 'Sincronizado desde pedido %s #%s.', 'openpos' === $provider ? 'OpenPOS' : 'WooCommerce', $order->get_order_number() ),
		);

		if ( ! empty( $existing_document['manual_override'] ) ) {
			$payload['financial_intent'] = (string) $existing_document['financial_intent'];
			$payload['balance_nature']   = (string) $existing_document['balance_nature'];
			$payload['category_key']     = (string) $existing_document['category_key'];
			$payload['subcategory_key']  = (string) $existing_document['subcategory_key'];
		}

		return $payload;
	}

	private function map_financial_status( $order_status ) {
		return in_array( $order_status, array( 'cancelled', 'failed', 'refunded', 'trash' ), true ) ? 'void' : 'posted';
	}

	private function is_collectible_status( $order_status ) {
		return in_array( sanitize_key( (string) $order_status ), $this->collectible_statuses(), true );
	}

	private function map_paid_total( $order, $order_status, $total, $provider ) {
		if ( 'openpos' === $provider ) {
			$remain_paid = (float) $order->get_meta( '_op_remain_paid' );
			$total_paid  = (float) $order->get_meta( '_op_order_total_paid' );

			if ( $remain_paid > 0 ) {
				return min( $total, max( 0, round( $total - $remain_paid, 6 ) ) );
			}

			if ( $total_paid > 0 ) {
				return min( $total, $total_paid );
			}
		}

		// Si el pedido volvio a un estado cobrable, priorizamos ese estado sobre
		// cualquier fecha de pago vieja que Woo haya dejado marcada.
		if ( in_array( $order_status, array( 'pending', 'on-hold', 'failed', 'cancelled', 'refunded', 'trash' ), true ) ) {
			return 0;
		}

		if ( $order->get_date_paid() || in_array( $order_status, array( 'processing', 'completed' ), true ) ) {
			return $total;
		}

		return 0;
	}

	private function map_payment_status( $order_status, $paid_total, $balance, $financial_status ) {
		if ( 'void' === $financial_status ) {
			return $balance <= 0 ? 'paid' : 'pending';
		}

		if ( $balance <= 0 && $paid_total > 0 ) {
			return 'paid';
		}

		if ( $paid_total > 0 ) {
			return 'partial';
		}

		return in_array( $order_status, array( 'pending', 'on-hold' ), true ) ? 'pending' : 'unpaid';
	}

	private function build_sync_hash( array $payload, $provider, $order, $preserve_payment, $preserve_class ) {
		return md5(
			wp_json_encode(
				array(
					'provider'          => $provider,
					'order_id'          => (int) $order->get_id(),
					'status'            => $payload['operational_status'],
					'document_number'   => $payload['document_number'],
					'total'             => (float) $payload['total'],
					'paid_total'        => (float) $payload['paid_total'],
					'balance'           => (float) $payload['balance'],
					'currency'          => $payload['currency'],
					'contact_id'        => (int) $payload['contact_id'],
					'preserve_payment'  => $preserve_payment,
					'preserve_class'    => $preserve_class,
				)
			)
		);
	}

	private function build_link_meta( $order, $provider ) {
		return array(
			'order_id'            => (int) $order->get_id(),
			'order_number'        => (string) $order->get_order_number(),
			'provider'            => $provider,
			'op_session_id'       => (string) $order->get_meta( '_op_session_id' ),
			'op_source_type'      => (string) $order->get_meta( '_op_source_type' ),
			'op_source'           => (string) $order->get_meta( '_op_source' ),
			'op_total_paid'       => (float) $order->get_meta( '_op_order_total_paid' ),
			'op_remain_paid'      => (float) $order->get_meta( '_op_remain_paid' ),
			'order_status'        => sanitize_key( $order->get_status() ),
			'date_paid'           => $order->get_date_paid() ? $order->get_date_paid()->date_i18n( 'Y-m-d H:i:s' ) : '',
		);
	}

	private function resolve_order_edit_url( $order ) {
		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			return $order->get_edit_order_url();
		}

		$order_id = (int) $order->get_id();

		return get_edit_post_link( $order_id, '' ) ?: admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}
}
