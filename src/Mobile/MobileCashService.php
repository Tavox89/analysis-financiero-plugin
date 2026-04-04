<?php

namespace ASDLabs\Finance\Mobile;

use ASDLabs\Finance\Finance\CancellationService;
use ASDLabs\Finance\Finance\EventsRepository;
use ASDLabs\Finance\Finance\PaymentsRepository;
use WP_Error;

final class MobileCashService {
	private $payments;
	private $events;

	public function __construct() {
		$this->payments = new PaymentsRepository();
		$this->events   = new EventsRepository();
	}

	public function get_summary( array $args = array() ) {
		$range     = $this->resolve_range( $args );
		$movements = $this->payments->list_for_api(
			array(
				'limit'      => 200,
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
			)
		);
		$items              = (array) ( $movements['items'] ?? array() );
		$collections_total  = 0.0;
		$manual_total       = 0.0;
		$disbursement_total = 0.0;
		$pending_voids      = 0;

		foreach ( $items as $item ) {
			$total  = (float) ( $item['total'] ?? 0 );
			$status = sanitize_key( (string) ( $item['status'] ?? '' ) );
			$kind   = $this->movement_kind( $item );

			if ( 'void' === $status ) {
				$pending_voids++;
				continue;
			}

			if ( 'collection' === $kind ) {
				$collections_total += $total;
				continue;
			}

			if ( 'manual_delivery' === $kind ) {
				$manual_total += $total;
				$disbursement_total += $total;
				continue;
			}

			if ( 'disbursement' === $kind ) {
				$disbursement_total += $total;
			}
		}

		return array(
			'entry_today'              => round( $collections_total - $disbursement_total, 2 ),
			'collections_today'        => round( $collections_total, 2 ),
			'manual_deliveries_today'  => round( $manual_total, 2 ),
			'pending_voids'            => $pending_voids,
			'currency'                 => 'USD',
			'range'                    => $range,
		);
	}

	public function list_movements( array $args = array() ) {
		$range   = $this->resolve_range( $args );
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit   = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$payload = $this->payments->list_for_api(
			array(
				'page'       => $page,
				'limit'      => $limit,
				'range_from' => $range['range_from'],
				'range_to'   => $range['range_to'],
				'search'     => $args['search'] ?? '',
				'status'     => $args['status'] ?? '',
				'payment_type' => $args['payment_type'] ?? '',
			)
		);

		return array(
			'items' => array_map( array( $this, 'map_movement_item' ), (array) ( $payload['items'] ?? array() ) ),
			'meta'  => $payload['meta'] ?? array(),
		);
	}

	public function create_collection( array $payload, $actor_user_id = 0 ) {
		$payment_id = $this->payments->create(
			array(
				'payment_type' => 'collection',
				'account_id'   => absint( $payload['account_id'] ?? 0 ),
				'contact_id'   => absint( $payload['contact_id'] ?? 0 ),
				'payment_date' => $payload['payment_date'] ?? current_time( 'Y-m-d' ),
				'currency'     => $payload['currency'] ?? 'USD',
				'total'        => $payload['total'] ?? 0,
				'method_key'   => $payload['method_key'] ?? 'cash',
				'reference'    => $payload['reference'] ?? '',
				'notes'        => $payload['notes'] ?? '',
				'meta'         => array(
					'origin'            => 'clubsams-control/v1',
					'operation_kind'    => 'cash_collection',
					'client_operation_id' => sanitize_text_field( (string) ( $payload['client_operation_id'] ?? '' ) ),
				),
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		$this->events->log(
			'payment',
			(int) $payment_id,
			'cash_collection_created',
			'Cobro de caja registrado desde ClubSams Control.',
			array(
				'payment_id' => (int) $payment_id,
				'origin'     => 'clubsams-control/v1',
			),
			$actor_user_id > 0 ? (int) $actor_user_id : null
		);

		return array(
			'payment'   => $this->payments->find( $payment_id ),
			'operation' => $this->operation_payload( 'cash_collection', $payment_id, $payload['client_operation_id'] ?? '' ),
		);
	}

	public function create_manual_delivery( array $payload, $actor_user_id = 0 ) {
		$payment_id = $this->payments->create(
			array(
				'payment_type' => 'disbursement',
				'account_id'   => absint( $payload['account_id'] ?? 0 ),
				'contact_id'   => absint( $payload['contact_id'] ?? 0 ),
				'payment_date' => $payload['payment_date'] ?? current_time( 'Y-m-d' ),
				'currency'     => $payload['currency'] ?? 'USD',
				'total'        => $payload['total'] ?? 0,
				'method_key'   => $payload['method_key'] ?? 'cash',
				'reference'    => $payload['reference'] ?? '',
				'notes'        => $payload['notes'] ?? '',
				'meta'         => array(
					'origin'            => 'clubsams-control/v1',
					'operation_kind'    => 'manual_delivery',
					'client_operation_id' => sanitize_text_field( (string) ( $payload['client_operation_id'] ?? '' ) ),
				),
			)
		);

		if ( is_wp_error( $payment_id ) ) {
			return $payment_id;
		}

		$this->events->log(
			'payment',
			(int) $payment_id,
			'manual_delivery_created',
			'Entrega manual de caja registrada desde ClubSams Control.',
			array(
				'payment_id' => (int) $payment_id,
				'origin'     => 'clubsams-control/v1',
			),
			$actor_user_id > 0 ? (int) $actor_user_id : null
		);

		return array(
			'payment'   => $this->payments->find( $payment_id ),
			'operation' => $this->operation_payload( 'cash_manual_delivery', $payment_id, $payload['client_operation_id'] ?? '' ),
		);
	}

	public function void_movement( array $payload, $actor_user_id = 0 ) {
		$payment_id = absint( $payload['payment_id'] ?? $payload['id'] ?? 0 );
		if ( $payment_id <= 0 ) {
			return new WP_Error( 'clubsams_control_validation_error', 'Debes indicar el payment_id del movimiento a anular.', array( 'status' => 400 ) );
		}

		$result = ( new CancellationService() )->cancel_payment(
			$payment_id,
			sanitize_textarea_field( (string) ( $payload['reason'] ?? $payload['cancel_reason'] ?? '' ) ),
			array(
				'origin' => 'clubsams-control/v1',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->events->log(
			'payment',
			$payment_id,
			'cash_void_created',
			'Movimiento de caja anulado desde ClubSams Control.',
			array(
				'payment_id' => $payment_id,
				'origin'     => 'clubsams-control/v1',
			),
			$actor_user_id > 0 ? (int) $actor_user_id : null
		);

		return array(
			'result'    => $result,
			'operation' => $this->operation_payload( 'cash_void', $payment_id, $payload['client_operation_id'] ?? '' ),
		);
	}

	private function map_movement_item( array $item ) {
		$contact = is_array( $item['contact'] ?? null ) ? $item['contact'] : array();
		$kind    = $this->movement_kind( $item );
		$title   = 'Movimiento financiero';

		if ( 'collection' === $kind ) {
			$title = 'Cobro registrado';
		} elseif ( 'manual_delivery' === $kind ) {
			$title = 'Entrega manual registrada';
		}

		if ( 'void' === $kind ) {
			$title = 'Movimiento anulado';
		} elseif ( 'disbursement' === $kind ) {
			$title = 'Pago registrado';
		} elseif ( 'adjustment' === $kind ) {
			$title = 'Ajuste registrado';
		}

		return array(
			'id'           => (int) ( $item['id'] ?? 0 ),
			'kind'         => $kind,
			'title'        => $title,
			'subtitle'     => sanitize_text_field( (string) ( $contact['display_name'] ?? $item['notes'] ?? '' ) ),
			'amount'       => round( (float) ( $item['total'] ?? 0 ), 2 ),
			'currency'     => sanitize_text_field( (string) ( $item['currency'] ?? 'USD' ) ),
			'status'       => sanitize_key( (string) ( $item['status'] ?? '' ) ),
			'reference'    => sanitize_text_field( (string) ( $item['reference'] ?? '' ) ),
			'recorded_at'  => sanitize_text_field( (string) ( $item['payment_date'] ?? $item['created_at'] ?? '' ) ),
			'source_label' => $this->source_label( $item ),
		);
	}

	private function movement_kind( array $item ) {
		$status      = sanitize_key( (string) ( $item['status'] ?? '' ) );
		$payment_type = sanitize_key( (string) ( $item['payment_type'] ?? '' ) );
		$meta        = json_decode( (string) ( $item['meta_json'] ?? '' ), true );
		$meta        = is_array( $meta ) ? $meta : array();
		$operation_kind = sanitize_key( (string) ( $meta['operation_kind'] ?? '' ) );

		if ( 'void' === $status ) {
			return 'void';
		}

		if ( 'manual_delivery' === $operation_kind ) {
			return 'manual_delivery';
		}

		if ( in_array( $payment_type, array( 'collection', 'disbursement', 'adjustment' ), true ) ) {
			return $payment_type;
		}

		return 'adjustment';
	}

	private function source_label( array $item ) {
		$meta = json_decode( (string) ( $item['meta_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();

		if ( ! empty( $meta['origin'] ) ) {
			return sanitize_text_field( (string) $meta['origin'] );
		}

		return 'finance_api';
	}

	private function resolve_range( array $args = array() ) {
		$today      = current_time( 'Y-m-d' );
		$range_from = $this->sanitize_date( $args['range_from'] ?? '' ) ?: $today;
		$range_to   = $this->sanitize_date( $args['range_to'] ?? '' ) ?: $today;

		if ( $range_from > $range_to ) {
			$temp       = $range_from;
			$range_from = $range_to;
			$range_to   = $temp;
		}

		return array(
			'range_from' => $range_from,
			'range_to'   => $range_to,
		);
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	private function operation_payload( $type, $payment_id, $client_operation_id = '' ) {
		$payload = array(
			'type'       => sanitize_key( (string) $type ),
			'status'     => 'completed',
			'payment_id' => (int) $payment_id,
		);
		$seed = array( $payload['type'], $payload['payment_id'] );

		if ( '' !== (string) $client_operation_id ) {
			$payload['client_operation_id'] = sanitize_text_field( (string) $client_operation_id );
			$seed[] = $payload['client_operation_id'];
		}

		$payload['operation_id'] = $payload['type'] . ':' . md5( wp_json_encode( $seed ) );

		return $payload;
	}
}
