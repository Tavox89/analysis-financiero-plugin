<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Integrations\Woo\DualPricingService;

final class CreditEligibilityService {
	const METHOD_SALARY_ADVANCE = 'salary_advance';
	const METHOD_DUAL_DISCOUNT  = 'dual_price_discount';
	const METHOD_INTERNAL_COMPENSATION = 'internal_compensation';
	const METHOD_EXTRAORDINARY_CLOSURE = 'extraordinary_profile_closure';

	public static function technical_method_keys() {
		return array(
			self::METHOD_SALARY_ADVANCE,
			self::METHOD_DUAL_DISCOUNT,
			self::METHOD_INTERNAL_COMPENSATION,
			self::METHOD_EXTRAORDINARY_CLOSURE,
		);
	}

	public static function is_technical_method( $method_key ) {
		return in_array( sanitize_key( (string) $method_key ), self::technical_method_keys(), true );
	}

	public static function is_usable_payment( array $payment, $currency = '' ) {
		$payment_currency = strtoupper( sanitize_text_field( (string) ( $payment['currency'] ?? 'USD' ) ) );
		$target_currency  = strtoupper( sanitize_text_field( (string) $currency ) );
		if ( '' !== $target_currency && $payment_currency !== $target_currency ) {
			return false;
		}

		$method_key = sanitize_key( (string) ( $payment['method_key'] ?? '' ) );

		return 'posted' === sanitize_key( (string) ( $payment['status'] ?? '' ) )
			&& (float) ( $payment['available_amount'] ?? 0 ) > 0
			&& ! self::is_technical_method( $method_key )
			&& in_array( sanitize_key( (string) ( $payment['payment_type'] ?? '' ) ), array( 'collection', 'adjustment' ), true );
	}

	public static function is_dual_eligible_payment( array $payment, $currency = '', ?DualPricingService $dual_pricing = null ) {
		if ( ! self::is_usable_payment( $payment, $currency ) ) {
			return false;
		}

		if ( 'collection' !== sanitize_key( (string) ( $payment['payment_type'] ?? '' ) ) ) {
			return false;
		}

		$dual_pricing = $dual_pricing ?: new DualPricingService();

		return $dual_pricing->qualifies_for_dual_discount(
			sanitize_key( (string) ( $payment['method_key'] ?? '' ) ),
			strtoupper( sanitize_text_field( (string) ( $currency ?: ( $payment['currency'] ?? 'USD' ) ) ) )
		);
	}
}
