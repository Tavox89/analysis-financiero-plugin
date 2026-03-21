<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Contracts\Module;

final class HistoricalCommerceModule implements Module {
	public function register() {
		add_action( 'asdl_fin_sync_completed', array( $this, 'handle_sync_completed' ) );
		add_action( 'asdl_fin_document_created', array( $this, 'handle_document_change' ) );
		add_action( 'asdl_fin_document_updated', array( $this, 'handle_document_change' ) );
		add_action( 'asdl_fin_document_cancelled', array( $this, 'handle_document_change' ) );
		add_action( 'asdl_fin_payment_allocated', array( $this, 'handle_payment_allocated' ) );
		add_action( 'asdl_fin_payment_cancelled', array( $this, 'handle_payment_cancelled' ) );
		add_action( 'asdl_fin_profile_linked', array( $this, 'handle_contact_identity_change' ) );
		add_action( 'asdl_fin_profile_promoted', array( $this, 'handle_contact_identity_change' ) );
		add_action( 'asdl_fin_contact_created', array( $this, 'handle_contact_created' ) );
	}

	public function handle_sync_completed( $payload ) {
		if ( ! is_array( $payload ) ) {
			return;
		}

		$order_id = absint( $payload['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return;
		}

		( new HistoricalIndexRebuildService() )->refresh_order_index( $order_id );
	}

	public function handle_document_change( $document_id ) {
		$document_id = absint( $document_id );
		if ( $document_id <= 0 ) {
			return;
		}

		( new HistoricalIndexRebuildService() )->refresh_document_links( $document_id );
	}

	public function handle_payment_allocated( $result ) {
		if ( ! is_array( $result ) ) {
			return;
		}

		$document_id = absint( $result['document_id'] ?? 0 );
		if ( $document_id <= 0 ) {
			return;
		}

		( new HistoricalIndexRebuildService() )->refresh_document_links( $document_id );
	}

	public function handle_payment_cancelled( $result ) {
		if ( ! is_array( $result ) ) {
			return;
		}

		$service = new HistoricalIndexRebuildService();

		foreach ( (array) ( $result['affected_document_ids'] ?? array() ) as $document_id ) {
			$service->refresh_document_links( (int) $document_id );
		}

		foreach ( (array) ( $result['reopened_order_ids'] ?? array() ) as $order_id ) {
			$service->refresh_order_index( (int) $order_id );
		}
	}

	public function handle_contact_identity_change( $result ) {
		if ( ! is_array( $result ) ) {
			return;
		}

		$contact_id = absint( $result['contact_id'] ?? 0 );
		if ( $contact_id <= 0 ) {
			return;
		}

		( new HistoricalIndexRebuildService() )->refresh_contact_identity( $contact_id );
	}

	public function handle_contact_created( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return;
		}

		( new HistoricalIndexRebuildService() )->refresh_contact_identity( $contact_id );
	}
}
