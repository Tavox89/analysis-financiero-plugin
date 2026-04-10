<?php

namespace ASDLabs\Finance\Finance;

final class MoneyStateService {
	public static function decimals( $currency = '' ) {
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );

		if ( function_exists( 'wc_get_price_decimals' ) ) {
			$decimals = (int) wc_get_price_decimals();
			if ( $decimals >= 0 ) {
				return $decimals;
			}
		}

		return 2;
	}

	public static function normalize_amount( $amount, $currency = '' ) {
		return round( (float) $amount, self::decimals( $currency ) );
	}

	public static function normalize_balance( $amount, $currency = '' ) {
		$rounded = self::normalize_amount( max( 0, (float) $amount ), $currency );

		return $rounded > 0 ? $rounded : 0.0;
	}

	public static function balance_is_zero( $amount, $currency = '' ) {
		return 0.0 === self::normalize_balance( $amount, $currency );
	}

	public static function is_paid_like( $paid_total, $balance, $currency = '' ) {
		return (float) $paid_total > 0 && self::balance_is_zero( $balance, $currency );
	}
}
