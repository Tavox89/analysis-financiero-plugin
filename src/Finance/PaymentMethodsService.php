<?php

namespace ASDLabs\Finance\Finance;

use WP_Error;

final class PaymentMethodsService {
	const OPTION_KEY = 'asdl_fin_payment_methods';

	public function options() {
		$methods = array();

		foreach ( $this->all() as $key => $label ) {
			$methods[ $key ] = $label;
		}

		return $methods;
	}

	public function all() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$methods = $this->defaults();

		foreach ( $saved as $key => $label ) {
			$key   = sanitize_key( (string) $key );
			$label = sanitize_text_field( (string) $label );

			if ( '' === $key || '' === $label ) {
				continue;
			}

			$methods[ $key ] = $label;
		}

		asort( $methods, SORT_NATURAL | SORT_FLAG_CASE );

		return $methods;
	}

	public function catalog() {
		$saved    = $this->saved_methods();
		$defaults = $this->defaults();
		$system   = $this->system_labels();
		$items    = array();

		foreach ( $this->all() as $key => $label ) {
			$items[] = array(
				'key'        => $key,
				'label'      => $label,
				'kind'       => isset( $saved[ $key ] ) ? 'custom' : 'default',
				'selectable' => true,
			);
		}

		foreach ( $system as $key => $label ) {
			$items[] = array(
				'key'        => $key,
				'label'      => $label,
				'kind'       => 'system',
				'selectable' => false,
			);
		}

		return $items;
	}

	public function label( $method_key ) {
		$method_key = sanitize_key( (string) $method_key );
		if ( '' === $method_key ) {
			return 'Sin definir';
		}

		$methods = $this->all();
		if ( isset( $methods[ $method_key ] ) ) {
			return $methods[ $method_key ];
		}

		$system = $this->system_labels();

		return $system[ $method_key ] ?? ucwords( str_replace( '_', ' ', $method_key ) );
	}

	public function is_valid_key( $method_key ) {
		$method_key = sanitize_key( (string) $method_key );
		if ( '' === $method_key ) {
			return false;
		}

		$methods = $this->all() + $this->system_labels();

		return isset( $methods[ $method_key ] );
	}

	public function create( array $data ) {
		$label = sanitize_text_field( $data['payment_method_name'] ?? '' );
		if ( '' === $label ) {
			return new WP_Error( 'asdl_fin_payment_method_name', 'Debes indicar el nombre del metodo de pago.' );
		}

		$methods = $this->all();
		$key     = sanitize_key( remove_accents( strtolower( $label ) ) );

		if ( '' === $key ) {
			return new WP_Error( 'asdl_fin_payment_method_key', 'No se pudo generar una clave valida para este metodo.' );
		}

		if ( isset( $methods[ $key ] ) ) {
			return new WP_Error( 'asdl_fin_payment_method_exists', 'Ese metodo ya existe dentro del catalogo.' );
		}

		$saved = $this->saved_methods();

		$saved[ $key ] = $label;
		update_option( self::OPTION_KEY, $saved, false );

		return array(
			'key'   => $key,
			'label' => $label,
		);
	}

	private function defaults() {
		return array(
			'bank_transfer' => 'Transferencia',
			'cash'          => 'Efectivo',
			'check'         => 'Cheque',
			'credit_card'   => 'Tarjeta de credito',
			'debit_card'    => 'Tarjeta de debito',
			'mobile_payment'=> 'Pago movil',
			'zelle'         => 'Zelle',
		);
	}

	private function system_labels() {
		return array(
			'salary_advance'      => 'Adelanto de sueldo',
			'payroll'             => 'Nomina',
			'payroll_deduction'   => 'Descuento por sueldo',
			'internal_compensation' => 'Compensacion interna',
			'internal_order_assumption' => 'Asuncion interna de pedido',
			'dual_price_discount' => 'Descuento precio dual',
		);
	}

	private function saved_methods() {
		$saved = get_option( self::OPTION_KEY, array() );

		return is_array( $saved ) ? $saved : array();
	}
}
