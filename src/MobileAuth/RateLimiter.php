<?php

namespace ASDLabs\Finance\MobileAuth;

use WP_Error;

final class RateLimiter {
	public function ensure_not_limited( $scope, $subject, $limit = 10, $window = 900 ) {
		$key     = $this->transient_key( $scope, $subject );
		$payload = get_transient( $key );
		$count   = is_array( $payload ) ? (int) ( $payload['count'] ?? 0 ) : 0;

		if ( $count >= max( 1, (int) $limit ) ) {
			return new WP_Error(
				'clubsams_control_rate_limited',
				'Demasiados intentos. Espera un momento e intenta de nuevo.',
				array( 'status' => 429 )
			);
		}

		return true;
	}

	public function register_failure( $scope, $subject, $window = 900 ) {
		$key     = $this->transient_key( $scope, $subject );
		$payload = get_transient( $key );
		$count   = is_array( $payload ) ? (int) ( $payload['count'] ?? 0 ) : 0;

		set_transient(
			$key,
			array(
				'count' => $count + 1,
			),
			max( 60, (int) $window )
		);
	}

	public function clear( $scope, $subject ) {
		delete_transient( $this->transient_key( $scope, $subject ) );
	}

	private function transient_key( $scope, $subject ) {
		return 'asdl_fin_rl_' . md5( sanitize_key( (string) $scope ) . '|' . sanitize_text_field( (string) $subject ) );
	}
}
