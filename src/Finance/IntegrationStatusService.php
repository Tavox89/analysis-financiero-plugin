<?php

namespace ASDLabs\Finance\Finance;

final class IntegrationStatusService {
	public function get_snapshot() {
		$links_repository = new SourceLinksRepository();
		$link_counts      = $links_repository->provider_counts();

		$providers = array(
			'woocommerce' => array(
				'name'        => 'WooCommerce',
				'detected'    => class_exists( 'WooCommerce' ),
				'description' => 'Origen principal para pedidos y flujo comercial. La capa financiera se enlaza solo cuando el pedido se gestiona.',
			),
			'openpos' => array(
				'name'        => 'OpenPOS',
				'detected'    => defined( 'OPENPOS_DIR' ) || class_exists( 'Openpos_Core' ),
				'description' => 'Canal POS que sigue operando como pedido Woo y se enlaza al core solo cuando haga falta gestion financiera.',
			),
			'api' => array(
				'name'        => 'API propia',
				'detected'    => true,
				'description' => 'Namespace REST del plugin para lectura, registro e integracion con terceros.',
			),
		);

		$items        = array();
		$total_links  = 0;
		$total_locked = 0;

		foreach ( $providers as $provider_key => $provider ) {
			$provider_counts = $link_counts[ $provider_key ] ?? array(
				'total_links'    => 0,
				'locked_links'   => 0,
				'last_synced_at' => '',
			);

			$total_links  += (int) $provider_counts['total_links'];
			$total_locked += (int) $provider_counts['locked_links'];

			$status = 'Disponible';

			if ( ! $provider['detected'] ) {
				$status = 'No detectado';
			} elseif ( 'api' === $provider_key ) {
				$status = 'Activa';
			} elseif ( $provider_counts['total_links'] > 0 ) {
				$status = 'Vinculada';
			}

			$items[] = array(
				'provider'       => $provider_key,
				'name'           => $provider['name'],
				'status'         => $status,
				'detected'       => (bool) $provider['detected'],
				'description'    => $provider['description'],
				'total_links'    => (int) $provider_counts['total_links'],
				'locked_links'   => (int) $provider_counts['locked_links'],
				'last_synced_at' => (string) $provider_counts['last_synced_at'],
			);
		}

		return array(
			'items'              => $items,
			'total_links'        => $total_links,
			'locked_links'       => $total_locked,
			'detected_count'     => count(
				array_filter(
					$items,
					static function ( $item ) {
						return ! empty( $item['detected'] );
					}
				)
			),
			'configured_count'   => count(
				array_filter(
					$items,
					static function ( $item ) {
						return (int) $item['total_links'] > 0 || 'Activa' === $item['status'];
					}
				)
			),
		);
	}
}
