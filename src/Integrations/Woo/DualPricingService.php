<?php

namespace ASDLabs\Finance\Integrations\Woo;

use ASDLabs\Finance\Finance\PaymentMethodsService;

final class DualPricingService {
	private function fallback_divisa_method_keys() {
		return array( 'cash', 'zelle' );
	}

	private function fallback_discount_config() {
		$active  = (bool) get_option( 'csfx_discount_enabled', 1 );
		$percent = $active ? max( 0, (float) get_option( 'csfx_discount_percent', 31.0 ) ) : 0.0;

		return array(
			'active'   => $active && $percent > 0,
			'percent'  => $percent,
			'fraction' => $this->normalize_fraction( $percent / 100 ),
		);
	}

	public function get_discount_config() {
		$config = $this->fallback_discount_config();

		if ( function_exists( 'csfx_get_discount' ) ) {
			$raw = csfx_get_discount();
			if ( is_array( $raw ) ) {
				$raw_active  = ! empty( $raw['active'] );
				$raw_percent = max( 0, (float) ( $raw['percent'] ?? 0 ) );

				if ( $raw_active || $raw_percent > 0 ) {
					$config['active']  = $raw_active;
					$config['percent'] = $raw_percent;
				}
			}
		}

		$config['fraction'] = $this->normalize_fraction( $config['percent'] / 100 );

		if ( $config['fraction'] <= 0 ) {
			$config['active'] = false;
		}

		return $config;
	}

	public function get_rate_snapshot() {
		if ( ! function_exists( 'csfx_get_rate' ) ) {
			return null;
		}

		$rate = csfx_get_rate();

		return is_array( $rate ) ? $rate : null;
	}

	public function get_divisa_method_keys() {
		$methods       = new PaymentMethodsService();
		$external_keys = $this->get_external_divisa_method_keys();
		$catalog_keys  = $this->get_catalog_divisa_method_keys();
		$keys          = array_merge( $external_keys, $catalog_keys );

		foreach ( $this->fallback_divisa_method_keys() as $fallback_key ) {
			$resolved_fallback = $methods->resolve_key( $fallback_key );
			if ( '' === $resolved_fallback || $methods->has_catalog_configuration( $resolved_fallback ) ) {
				continue;
			}

			$keys[] = $resolved_fallback;
		}

		$keys = array_map( array( $methods, 'resolve_key' ), $keys );

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	public function get_frontend_snapshot() {
		$config  = $this->get_discount_config();
		$methods = new PaymentMethodsService();
		$items   = array();

		foreach ( array_keys( $methods->all() ) as $method_key ) {
			$items[ $method_key ] = $this->get_method_eligibility_snapshot( $method_key );
		}

		foreach ( $this->fallback_divisa_method_keys() as $method_key ) {
			$resolved = $methods->resolve_key( $method_key );
			if ( '' === $resolved || isset( $items[ $resolved ] ) ) {
				continue;
			}

			$items[ $resolved ] = $this->get_method_eligibility_snapshot( $resolved );
		}

		return array(
			'active'           => ! empty( $config['active'] ),
			'percent'          => (float) ( $config['percent'] ?? 0 ),
			'fraction'         => (float) ( $config['fraction'] ?? 0 ),
			'divisaMethodKeys' => array_values( $this->get_divisa_method_keys() ),
			'eligibilityByKey' => $items,
		);
	}

	public function get_method_eligibility_snapshot( $method_key ) {
		$method_key = $this->normalize_method_key( $method_key );
		if ( '' === $method_key ) {
			return array(
				'eligible'     => false,
				'source'       => 'none',
				'source_label' => 'No elegible',
			);
		}

		$methods       = new PaymentMethodsService();
		$catalog_keys  = $this->get_catalog_divisa_method_keys();
		$external_keys = $this->get_external_divisa_method_keys();
		$fallback_keys = array_map( array( $methods, 'resolve_key' ), $this->fallback_divisa_method_keys() );
		$catalog       = in_array( $method_key, $catalog_keys, true );
		$external      = in_array( $method_key, $external_keys, true );
		$fallback      = in_array( $method_key, $fallback_keys, true ) && ! $methods->has_catalog_configuration( $method_key );

		if ( $catalog && $external ) {
			return array(
				'eligible'     => true,
				'source'       => 'catalog_external',
				'source_label' => 'Catalogo + integracion externa',
			);
		}

		if ( $catalog ) {
			return array(
				'eligible'     => true,
				'source'       => 'catalog',
				'source_label' => 'Catalogo ASD',
			);
		}

		if ( $external ) {
			return array(
				'eligible'     => true,
				'source'       => 'external',
				'source_label' => 'Integracion externa',
			);
		}

		if ( $fallback ) {
			return array(
				'eligible'     => true,
				'source'       => 'fallback',
				'source_label' => 'Fallback del core',
			);
		}

		return array(
			'eligible'     => false,
			'source'       => 'none',
			'source_label' => 'No elegible',
		);
	}

	public function qualifies_for_dual_discount( $method_key, $currency ) {
		$request = $this->evaluate_discount_request( 'auto', $method_key, $currency );

		return ! empty( $request['uses_dual'] );
	}

	public function evaluate_discount_request( $mode, $method_key, $currency, array $config = array() ) {
		$mode     = $this->normalize_discount_mode( $mode );
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		$config   = ! empty( $config ) ? $config : $this->get_discount_config();
		$config   = array(
			'active'   => ! empty( $config['active'] ),
			'percent'  => (float) ( $config['percent'] ?? 0 ),
			'fraction' => $this->normalize_fraction( (float) ( $config['fraction'] ?? 0 ) ),
		);

		if ( $config['fraction'] <= 0 ) {
			$config['active'] = false;
		}

		$normalized_method = $this->normalize_method_key( $method_key );
		$method_snapshot   = '' !== $normalized_method
			? $this->get_method_eligibility_snapshot( $normalized_method )
			: array(
				'eligible'     => false,
				'source'       => 'none',
				'source_label' => 'No elegible',
			);
		$status_key        = 'off';
		$status_label      = 'Descuento automatico apagado';
		$uses_dual         = false;

		if ( 'off' === $mode ) {
			$status_key   = 'off';
			$status_label = 'Descuento automatico apagado';
		} elseif ( empty( $config['active'] ) ) {
			$status_key   = 'global_off';
			$status_label = 'El descuento general esta apagado';
		} elseif ( 'USD' !== $currency ) {
			$status_key   = 'currency';
			$status_label = 'La moneda registrada no es USD';
		} elseif ( '' === $normalized_method ) {
			$status_key   = 'method_missing';
			$status_label = 'Falta confirmar el metodo final';
		} elseif ( empty( $method_snapshot['eligible'] ) ) {
			$status_key   = 'method';
			$status_label = 'El metodo no califica para precio dual';
		} else {
			$uses_dual    = true;
			$status_key   = 'force' === $mode ? 'force' : 'active';
			$status_label = 'force' === $mode ? 'Precio dual forzado' : 'Precio dual activo';
		}

		$execution_blocked = 'off' !== $mode && ! $uses_dual && in_array( $status_key, array( 'method', 'method_missing' ), true );
		$execution_message = '';

		if ( $execution_blocked ) {
			if ( 'method_missing' === $status_key ) {
				$execution_message = 'El descuento automatico esta activo, pero falta confirmar un metodo de pago elegible para precio dual. Selecciona uno valido o apaga el descuento antes de confirmar.';
			} else {
				$execution_message = sprintf(
					'El descuento automatico esta activo, pero el metodo %s no califica para precio dual. Cambia el metodo o apaga el descuento antes de confirmar.',
					$this->method_label( $normalized_method )
				);
			}
		}

		return array(
			'mode'               => $mode,
			'requested'          => 'off' !== $mode,
			'uses_dual'          => $uses_dual,
			'status'             => array(
				'key'   => $status_key,
				'label' => $status_label,
			),
			'status_key'         => $status_key,
			'status_label'       => $status_label,
			'method_key'         => $normalized_method,
			'method_label'       => $this->method_label( $normalized_method ),
			'method_eligibility' => $method_snapshot,
			'currency'           => $currency,
			'config'             => $config,
			'execution_blocked'  => $execution_blocked,
			'execution_message'  => $execution_message,
		);
	}

	public function compute_dual( $base_total, $net_amount, $fraction ) {
		$base_total = max( 0, (float) $base_total );
		$net_amount = max( 0, (float) $net_amount );
		$fraction   = $this->normalize_fraction( $fraction );

		if ( $base_total <= 0 ) {
			return array(
				'net_requested'  => $net_amount,
				'net_effective'  => 0.0,
				'gross_covered'  => 0.0,
				'discount'       => 0.0,
				'remainder_usd'  => 0.0,
				'trimmed'        => false,
				'final_total'    => 0.0,
			);
		}

		$discount_factor = max( 0.005, 1 - $fraction );
		$max_net         = round( $base_total * $discount_factor, 6 );
		$effective_net   = min( $net_amount, $max_net );
		$gross_covered   = $effective_net > 0 ? round( $effective_net / $discount_factor, 6 ) : 0.0;
		$gross_covered   = min( $gross_covered, $base_total );
		$discount        = round( max( 0, $gross_covered - $effective_net ), 6 );
		$remainder_usd   = round( max( 0, $base_total - $gross_covered ), 6 );
		$final_total     = round( max( 0, $base_total - $discount ), 6 );

		return array(
			'net_requested' => $net_amount,
			'net_effective' => $effective_net,
			'gross_covered' => $gross_covered,
			'discount'      => $discount,
			'remainder_usd' => $remainder_usd,
			'trimmed'       => $net_amount > $effective_net + 0.00001,
			'final_total'   => $final_total,
		);
	}

	public function method_label( $method_key ) {
		return ( new PaymentMethodsService() )->label( $method_key );
	}

	private function normalize_method_key( $value ) {
		return ( new PaymentMethodsService() )->resolve_key( $value );
	}

	private function normalize_discount_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );

		if ( in_array( $mode, array( 'off', 'auto', 'force' ), true ) ) {
			return $mode;
		}

		return 'auto';
	}

	private function get_external_divisa_method_keys() {
		$raw_methods = function_exists( 'csfx_get_divisa_methods' ) ? (array) csfx_get_divisa_methods() : array();
		$keys        = array();

		foreach ( $raw_methods as $raw_method ) {
			$key = $this->normalize_method_key( $raw_method );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	private function get_catalog_divisa_method_keys() {
		return ( new PaymentMethodsService() )->dual_eligible_keys();
	}

	private function normalize_fraction( $fraction ) {
		$fraction = max( 0, (float) $fraction );

		if ( $fraction >= 0.995 ) {
			$fraction = 0.995;
		}

		return round( $fraction, 6 );
	}
}
