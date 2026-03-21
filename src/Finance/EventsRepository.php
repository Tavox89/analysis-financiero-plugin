<?php

namespace ASDLabs\Finance\Finance;

final class EventsRepository extends BaseRepository {
	protected $table_key = 'events';

	public function log( $entity_type, $entity_id, $event_type, $message, array $payload = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$wpdb = $this->db();

		$result = $wpdb->insert(
			$this->table(),
			array(
				'entity_type'   => sanitize_key( $entity_type ),
				'entity_id'     => $entity_id ? absint( $entity_id ) : null,
				'event_type'    => sanitize_key( $event_type ),
				'actor_user_id' => get_current_user_id() ? get_current_user_id() : null,
				'message'       => sanitize_text_field( $message ),
				'payload_json'  => ! empty( $payload ) ? wp_json_encode( $payload ) : null,
				'created_at'    => $this->now(),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return false !== $result ? (int) $wpdb->insert_id : false;
	}
}
