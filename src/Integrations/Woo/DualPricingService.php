<?php

namespace ASDLabs\Finance\Integrations\Woo;

use ASDLabs\Finance\Finance\PaymentMethodsService;

final class DualPricingService {
	public function get_discount_config() {
		$config = array(
			'active'   => false,
			'percent'  => 0.0,
			'fraction' => 0.0,
		);

		if ( function_exists( 'csfx_get_discount' ) ) {
			$raw = csfx_get_discount();
			if ( is_array( $raw ) ) {
				$config['active']  = ! empty( $raw['active'] );
				$config['percent'] = max( 0, (float) ( $raw['percent'] ?? 0 ) );
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
		$raw_methods = function_exists( 'csfx_get_divisa_methods' ) ? (array) csfx_get_divisa_methods() : array();
		$keys        = array();

		foreach ( $raw_methods as $raw_method ) {
			$key = $this->normalize_method_key( $raw_method );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		if ( empty( $keys ) ) {
			$keys = array( 'cash', 'zelle' );
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	public function qualifies_for_dual_discount( $method_key, $currency ) {
		$config = $this->get_discount_config();
		if ( empty( $config['active'] ) || (float) $config['fraction'] <= 0 ) {
			return false;
		}

		$normalized_currency = strtoupper( sanitize_text_field( (string) $currency ) );
		if ( 'USD' !== $normalized_currency ) {
			return false;
		}

		$normalized_method = $this->normalize_method_key( $method_key );
		if ( '' === $normalized_method ) {
			return false;
		}

		return in_array( $normalized_method, $this->get_divisa_method_keys(), true );
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
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$token = sanitize_key( remove_accents( strtolower( $value ) ) );
		if ( '' === $token ) {
			return '';
		}

		$methods = ( new PaymentMethodsService() )->options();
		if ( isset( $methods[ $token ] ) ) {
			return $token;
		}

		foreach ( $methods as $method_key => $label ) {
			$normalized_label = sanitize_key( remove_accents( strtolower( (string) $label ) ) );
			if ( $normalized_label === $token ) {
				return $method_key;
			}
		}

		$aliases = array(
			'efectivo'             => 'cash',
			'efectivousd'          => 'cash',
			'cashusd'              => 'cash',
			'usdcash'              => 'cash',
			'divisa'               => 'cash',
			'dolares'              => 'cash',
			'transferencia'        => 'bank_transfer',
			'transferencianacional'=> 'bank_transfer',
			'pagomovil'            => 'mobile_payment',
			'pagomobile'           => 'mobile_payment',
			'creditcard'           => 'credit_card',
			'tarjetadecredito'     => 'credit_card',
			'debitcard'            => 'debit_card',
			'tarjetadedebito'      => 'debit_card',
		);

		return $aliases[ $token ] ?? '';
	}

	private function normalize_fraction( $fraction ) {
		$fraction = max( 0, (float) $fraction );

		if ( $fraction >= 0.995 ) {
			$fraction = 0.995;
		}

		return round( $fraction, 6 );
	}
}
