<?php

namespace ASDLabs\Finance\Mobile;

use ASDLabs\Finance\Finance\EventsRepository;

final class MobileAuditService {
	public function list_events( array $args = array() ) {
		$payload = ( new EventsRepository() )->list_for_api( $args );

		return array(
			'items' => array_map( array( $this, 'map_event_item' ), (array) ( $payload['items'] ?? array() ) ),
			'meta'  => $payload['meta'] ?? array(),
		);
	}

	private function map_event_item( array $item ) {
		return array(
			'id'               => sanitize_text_field( 'event-' . (int) ( $item['id'] ?? 0 ) ),
			'event_type'       => sanitize_key( (string) ( $item['event_type'] ?? '' ) ),
			'title'            => ucwords( str_replace( '_', ' ', sanitize_key( (string) ( $item['event_type'] ?? '' ) ) ) ),
			'message'          => sanitize_text_field( (string) ( $item['message'] ?? '' ) ),
			'actor_label'      => sanitize_text_field( (string) ( $item['actor_label'] ?? 'Sistema' ) ),
			'created_at_label' => sanitize_text_field( (string) ( $item['created_at'] ?? '' ) ),
			'entity_type'      => sanitize_key( (string) ( $item['entity_type'] ?? '' ) ),
			'entity_id'        => (int) ( $item['entity_id'] ?? 0 ),
		);
	}
}
