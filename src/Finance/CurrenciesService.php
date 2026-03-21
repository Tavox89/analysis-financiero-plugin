<?php

namespace ASDLabs\Finance\Finance;

use WP_Error;

final class CurrenciesService {
	const OPTION_KEY = 'asdl_fin_currencies';

	public function options() {
		return $this->all();
	}

	public function all() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$currencies = $this->defaults();

		foreach ( $saved as $code => $label ) {
			$code  = $this->sanitize_code( $code );
			$label = sanitize_text_field( (string) $label );

			if ( '' === $code ) {
				continue;
			}

			$currencies[ $code ] = '' !== $label ? $label : $code;
		}

		ksort( $currencies, SORT_NATURAL | SORT_FLAG_CASE );

		return $currencies;
	}

	public function catalog() {
		$saved    = $this->saved_currencies();
		$defaults = $this->defaults();
		$items    = array();

		foreach ( $this->all() as $code => $label ) {
			$items[] = array(
				'code'  => $code,
				'label' => $label,
				'kind'  => isset( $saved[ $code ] ) && ! isset( $defaults[ $code ] ) ? 'custom' : 'default',
			);
		}

		return $items;
	}

	public function label( $code ) {
		$code = $this->sanitize_code( $code );
		if ( '' === $code ) {
			return '';
		}

		$currencies = $this->all();

		return $currencies[ $code ] ?? $code;
	}

	public function is_valid_code( $code ) {
		$code = $this->sanitize_code( $code );
		if ( '' === $code ) {
			return false;
		}

		return isset( $this->all()[ $code ] );
	}

	public function normalize_code( $code, $fallback = '' ) {
		$code = $this->sanitize_code( $code );
		if ( '' !== $code && $this->is_valid_code( $code ) ) {
			return $code;
		}

		$fallback = $this->sanitize_code( $fallback );
		if ( '' !== $fallback && $this->is_valid_code( $fallback ) ) {
			return $fallback;
		}

		return '';
	}

	public function create( array $data ) {
		$code = $this->sanitize_code( $data['currency_code'] ?? '' );
		if ( '' === $code ) {
			return new WP_Error( 'asdl_fin_currency_code', 'Debes indicar un codigo de moneda valido.' );
		}

		$currencies = $this->all();
		if ( isset( $currencies[ $code ] ) ) {
			return new WP_Error( 'asdl_fin_currency_exists', 'Esa moneda ya existe dentro del catalogo.' );
		}

		$label = sanitize_text_field( $data['currency_label'] ?? '' );
		if ( '' === $label ) {
			$label = $code;
		}

		$saved           = $this->saved_currencies();
		$saved[ $code ]  = $label;
		ksort( $saved, SORT_NATURAL | SORT_FLAG_CASE );
		update_option( self::OPTION_KEY, $saved, false );

		return array(
			'code'  => $code,
			'label' => $label,
		);
	}

	private function defaults() {
		return array(
			'USD' => 'USD',
			'VES' => 'VES',
			'EUR' => 'EUR',
			'COP' => 'COP',
		);
	}

	private function saved_currencies() {
		$saved = get_option( self::OPTION_KEY, array() );

		return is_array( $saved ) ? $saved : array();
	}

	private function sanitize_code( $code ) {
		$code = strtoupper( sanitize_text_field( (string) $code ) );
		$code = preg_replace( '/[^A-Z0-9]/', '', $code );

		return substr( (string) $code, 0, 10 );
	}
}
