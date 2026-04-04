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
		$methods = $this->defaults();

		foreach ( $this->saved_methods() as $key => $item ) {
			if ( '' === $key || '' === (string) ( $item['label'] ?? '' ) ) {
				continue;
			}

			$methods[ $key ] = (string) $item['label'];
		}

		asort( $methods, SORT_NATURAL | SORT_FLAG_CASE );

		return $methods;
	}

	public function default_method_labels() {
		return $this->defaults();
	}

	public function default_alias_map() {
		return $this->default_aliases();
	}

	public function catalog() {
		$saved  = $this->saved_methods();
		$system = $this->system_labels();
		$items    = array();

		foreach ( $this->all() as $key => $label ) {
			$item = $saved[ $key ] ?? array(
				'label'         => $label,
				'dual_eligible' => false,
			);

			$items[] = array(
				'key'        => $key,
				'label'      => $label,
				'kind'       => $this->method_kind( $key, $saved ),
				'selectable' => true,
				'dual_eligible' => ! empty( $item['dual_eligible'] ),
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
		$method_key = $this->resolve_key( $method_key );
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
		$method_key = $this->resolve_key( $method_key );
		if ( '' === $method_key ) {
			return false;
		}

		$methods = $this->all() + $this->system_labels();

		return isset( $methods[ $method_key ] );
	}

	public function create( array $data ) {
		return $this->save( $data );
	}

	public function save( array $data ) {
		$label = sanitize_text_field( $data['payment_method_name'] ?? '' );
		if ( '' === $label ) {
			return new WP_Error( 'asdl_fin_payment_method_name', 'Debes indicar el nombre del metodo de pago.' );
		}

		$requested_key = sanitize_key( (string) ( $data['payment_method_key'] ?? '' ) );
		$methods       = $this->all();
		$generated_key = sanitize_key( remove_accents( strtolower( $label ) ) );
		$key           = $requested_key;
		$alias_fused   = false;

		if ( '' !== $this->canonical_default_key( $requested_key ) ) {
			$key         = $this->canonical_default_key( $requested_key );
			$alias_fused = '' !== $requested_key && $key !== $requested_key;
		} elseif ( '' !== $this->canonical_default_key( $label ) ) {
			$key         = $this->canonical_default_key( $label );
			$alias_fused = '' === $requested_key || $generated_key !== $key;
		} elseif ( '' === $key ) {
			$key = $generated_key;
		}

		if ( '' === $key ) {
			return new WP_Error( 'asdl_fin_payment_method_key', 'No se pudo generar una clave valida para este metodo.' );
		}

		$is_default = isset( $this->defaults()[ $key ] );

		if ( '' === $requested_key && ! $is_default && isset( $methods[ $key ] ) ) {
			return new WP_Error( 'asdl_fin_payment_method_exists', 'Ese metodo ya existe dentro del catalogo.' );
		}

		$saved = $this->saved_methods();
		$label = $is_default ? ( $this->defaults()[ $key ] ?? $label ) : $label;

		$saved[ $key ] = array(
			'label'         => $label,
			'dual_eligible' => ! empty( $data['payment_method_dual_eligible'] ),
		);
		update_option( self::OPTION_KEY, $this->export_saved_methods( $saved ), false );

		return array(
			'key'           => $key,
			'label'         => $label,
			'dual_eligible' => ! empty( $saved[ $key ]['dual_eligible'] ),
			'is_update'     => '' !== $requested_key || isset( $methods[ $key ] ),
			'kind'          => $this->method_kind( $key, $saved ),
			'alias_fused'   => $alias_fused,
		);
	}

	public function dual_eligible_keys() {
		$keys = array();

		foreach ( $this->saved_methods() as $key => $item ) {
			if ( ! empty( $item['dual_eligible'] ) ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	public function method_snapshot( $method_key ) {
		$method_key = $this->resolve_key( $method_key );
		if ( '' === $method_key ) {
			return null;
		}

		$label = $this->label( $method_key );
		if ( '' === $label ) {
			return null;
		}

		$saved = $this->saved_methods();
		$item  = $saved[ $method_key ] ?? array(
			'label'         => $label,
			'dual_eligible' => false,
		);

		return array(
			'key'           => $method_key,
			'label'         => $label,
			'kind'          => $this->method_kind( $method_key, $saved ),
			'dual_eligible' => ! empty( $item['dual_eligible'] ),
			'selectable'    => true,
		);
	}

	public function resolve_key( $value ) {
		$token = $this->normalize_token( $value );
		if ( '' === $token ) {
			return '';
		}

		$system = $this->system_labels();
		if ( isset( $system[ $token ] ) ) {
			return $token;
		}

		foreach ( $system as $method_key => $label ) {
			if ( $this->normalize_token( $label ) === $token ) {
				return sanitize_key( (string) $method_key );
			}
		}

		$default_key = $this->canonical_default_key( $token );
		if ( '' !== $default_key ) {
			return $default_key;
		}

		$methods = $this->all();
		if ( isset( $methods[ $token ] ) ) {
			return $token;
		}

		foreach ( $methods as $method_key => $label ) {
			if ( $this->normalize_token( $label ) === $token ) {
				return sanitize_key( (string) $method_key );
			}
		}

		return '';
	}

	public function canonical_default_key( $value ) {
		$token = $this->normalize_token( $value );
		if ( '' === $token ) {
			return '';
		}

		$defaults = $this->defaults();
		if ( isset( $defaults[ $token ] ) ) {
			return $token;
		}

		foreach ( $defaults as $method_key => $label ) {
			if ( $this->normalize_token( $label ) === $token ) {
				return sanitize_key( (string) $method_key );
			}
		}

		$aliases = $this->default_aliases();

		return sanitize_key( (string) ( $aliases[ $token ] ?? '' ) );
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

	private function default_aliases() {
		return array(
			'efectivo'              => 'cash',
			'efectivodolar'         => 'cash',
			'efectivousd'           => 'cash',
			'cashusd'               => 'cash',
			'usdcash'               => 'cash',
			'divisa'                => 'cash',
			'dolares'               => 'cash',
			'dolar'                 => 'cash',
			'cheque'                => 'check',
			'transferencia'         => 'bank_transfer',
			'transfer'              => 'bank_transfer',
			'banktransfer'          => 'bank_transfer',
			'transferencianacional' => 'bank_transfer',
			'pagomovil'             => 'mobile_payment',
			'movil'                 => 'mobile_payment',
			'mobilepayment'         => 'mobile_payment',
			'pagomobile'            => 'mobile_payment',
			'creditcard'            => 'credit_card',
			'tarjetacredito'        => 'credit_card',
			'tarjetadecredito'      => 'credit_card',
			'debitcard'             => 'debit_card',
			'tarjetadebito'         => 'debit_card',
			'tarjetadedebito'       => 'debit_card',
			'zellepayment'          => 'zelle',
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
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$saved = array();

		foreach ( $raw as $key => $item ) {
			$raw_key = sanitize_key( (string) $key );
			if ( '' === $raw_key ) {
				continue;
			}

			$normalized = $this->normalize_saved_item( $raw_key, $item );
			if ( null === $normalized ) {
				continue;
			}

			$key = $this->canonical_default_key( $raw_key );

			if ( '' === $key ) {
				$key = $this->canonical_default_key( $normalized['label'] );
			}

			if ( '' === $key ) {
				$key = $raw_key;
			}

			$label = isset( $this->defaults()[ $key ] ) ? ( $this->defaults()[ $key ] ?? $normalized['label'] ) : $normalized['label'];

			if ( ! isset( $saved[ $key ] ) ) {
				$saved[ $key ] = array(
					'label'         => $label,
					'dual_eligible' => ! empty( $normalized['dual_eligible'] ),
				);
				continue;
			}

			$saved[ $key ]['dual_eligible'] = ! empty( $saved[ $key ]['dual_eligible'] ) || ! empty( $normalized['dual_eligible'] );
		}

		uksort( $saved, 'strnatcasecmp' );

		$normalized_storage = $this->export_saved_methods( $saved );
		if ( $this->storage_needs_normalization( $raw, $normalized_storage ) ) {
			update_option( self::OPTION_KEY, $normalized_storage, false );
		}

		return $saved;
	}

	private function normalize_saved_item( $key, $item ) {
		if ( is_array( $item ) ) {
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			if ( '' === $label ) {
				$label = $this->defaults()[ $key ] ?? '';
			}

			if ( '' === $label ) {
				return null;
			}

			return array(
				'label'         => $label,
				'dual_eligible' => ! empty( $item['dual_eligible'] ),
			);
		}

		$label = sanitize_text_field( (string) $item );
		if ( '' === $label ) {
			return null;
		}

		return array(
			'label'         => $label,
			'dual_eligible' => false,
		);
	}

	private function method_kind( $method_key, array $saved ) {
		$defaults = $this->defaults();

		if ( isset( $saved[ $method_key ] ) && ! isset( $defaults[ $method_key ] ) ) {
			return 'custom';
		}

		return 'default';
	}

	private function export_saved_methods( array $saved ) {
		$normalized = array();

		foreach ( $saved as $key => $item ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$normalized[ $key ] = array(
				'label'         => sanitize_text_field( (string) ( $item['label'] ?? '' ) ),
				'dual_eligible' => ! empty( $item['dual_eligible'] ),
			);
		}

		uksort( $normalized, 'strnatcasecmp' );

		return $normalized;
	}

	private function storage_needs_normalization( $raw, array $normalized_storage ) {
		if ( ! is_array( $raw ) ) {
			return ! empty( $normalized_storage );
		}

		$current = array();

		foreach ( $raw as $key => $item ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			$normalized = $this->normalize_saved_item( $key, $item );
			if ( null === $normalized ) {
				continue;
			}

			$current[ $key ] = array(
				'label'         => sanitize_text_field( (string) ( $normalized['label'] ?? '' ) ),
				'dual_eligible' => ! empty( $normalized['dual_eligible'] ),
			);
		}

		uksort( $current, 'strnatcasecmp' );

		return wp_json_encode( $current ) !== wp_json_encode( $normalized_storage );
	}

	private function normalize_token( $value ) {
		return sanitize_key( remove_accents( strtolower( sanitize_text_field( (string) $value ) ) ) );
	}
}
