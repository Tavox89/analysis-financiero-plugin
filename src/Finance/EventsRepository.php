<?php

namespace ASDLabs\Finance\Finance;

final class EventsRepository extends BaseRepository {
	protected $table_key = 'events';

	public function log( $entity_type, $entity_id, $event_type, $message, array $payload = array(), $actor_user_id = null ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$wpdb = $this->db();
		$actor_user_id = null !== $actor_user_id ? absint( $actor_user_id ) : ( get_current_user_id() ? get_current_user_id() : null );

		$result = $wpdb->insert(
			$this->table(),
			array(
				'entity_type'   => sanitize_key( $entity_type ),
				'entity_id'     => $entity_id ? absint( $entity_id ) : null,
				'event_type'    => sanitize_key( $event_type ),
				'actor_user_id' => $actor_user_id ? $actor_user_id : null,
				'message'       => sanitize_text_field( $message ),
				'payload_json'  => ! empty( $payload ) ? wp_json_encode( $payload ) : null,
				'created_at'    => $this->now(),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $result ? (int) $wpdb->insert_id : false;
	}

	public function list_for_api( array $args = array() ) {
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit      = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset     = ( $page - 1 ) * $limit;
		$search     = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$entity_type = sanitize_key( (string) ( $args['entity_type'] ?? '' ) );
		$event_type = sanitize_key( (string) ( $args['event_type'] ?? '' ) );
		$range_from = $this->sanitize_date( $args['range_from'] ?? '' );
		$range_to   = $this->sanitize_date( $args['range_to'] ?? '' );
		$filters    = array(
			'search'     => $search,
			'entity_type'=> $entity_type,
			'event_type' => $event_type,
			'range_from' => $range_from,
			'range_to'   => $range_to,
		);

		if ( ! $this->has_table() ) {
			return array(
				'items' => array(),
				'meta'  => array(
					'count'       => 0,
					'total'       => 0,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => 0,
					'filters'     => $filters,
				),
			);
		}

		$wpdb  = $this->db();
		$where = array( '1=1' );
		$params = array();

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(e.message LIKE %s OR e.event_type LIKE %s OR u.display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $entity_type ) {
			$where[] = 'e.entity_type = %s';
			$params[] = $entity_type;
		}

		if ( '' !== $event_type ) {
			$where[] = 'e.event_type = %s';
			$params[] = $event_type;
		}

		if ( ! empty( $range_from ) ) {
			$where[] = 'DATE(e.created_at) >= %s';
			$params[] = $range_from;
		}

		if ( ! empty( $range_to ) ) {
			$where[] = 'DATE(e.created_at) <= %s';
			$params[] = $range_to;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$this->table()} e LEFT JOIN {$wpdb->users} u ON u.ID = e.actor_user_id WHERE {$where_sql}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, u.display_name AS actor_display_name
				FROM {$this->table()} e
				LEFT JOIN {$wpdb->users} u ON u.ID = e.actor_user_id
				WHERE {$where_sql}
				ORDER BY e.created_at DESC, e.id DESC
				LIMIT %d OFFSET %d",
				...array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);

		$items = array_map( array( $this, 'map_event_for_api' ), $rows );

		return array(
			'items' => $items,
			'meta'  => array(
				'count'       => count( $items ),
				'total'       => $total,
				'page'        => $page,
				'limit'       => $limit,
				'total_pages' => $total > 0 ? (int) ceil( $total / $limit ) : 0,
				'filters'     => $filters,
			),
		);
	}

	public function for_entity( $entity_type, $entity_id, $limit = 30 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$entity_type = sanitize_key( (string) $entity_type );
		$entity_id   = absint( $entity_id );
		$limit       = max( 1, min( 100, (int) $limit ) );

		if ( '' === $entity_type || $entity_id <= 0 ) {
			return array();
		}

		$wpdb = $this->db();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, u.display_name AS actor_display_name
				FROM {$this->table()} e
				LEFT JOIN {$wpdb->users} u ON u.ID = e.actor_user_id
				WHERE e.entity_type = %s
					AND e.entity_id = %d
				ORDER BY e.created_at DESC, e.id DESC
				LIMIT %d",
				$entity_type,
				$entity_id,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'map_event_for_api' ), $rows );
	}

	private function map_event_for_api( array $row ) {
		return array(
			'id'              => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'entity_type'     => sanitize_key( (string) ( $row['entity_type'] ?? '' ) ),
			'entity_id'       => ! empty( $row['entity_id'] ) ? (int) $row['entity_id'] : 0,
			'event_type'      => sanitize_key( (string) ( $row['event_type'] ?? '' ) ),
			'actor_user_id'   => ! empty( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : 0,
			'actor_label'     => sanitize_text_field( (string) ( $row['actor_display_name'] ?? '' ) ),
			'message'         => sanitize_text_field( (string) ( $row['message'] ?? '' ) ),
			'payload'         => $this->decode_payload( $row['payload_json'] ?? '' ),
			'created_at'      => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	private function decode_payload( $payload_json ) {
		$payload = json_decode( (string) $payload_json, true );
		return is_array( $payload ) ? $payload : array();
	}
}
