<?php

namespace ASDLabs\Finance\MobileAuth;

use WP_User;

final class TokenService {
	const ACCESS_TTL  = 1800;
	const REFRESH_TTL = 2592000;

	public function issue_token_pair( WP_User $user, array $args = array() ) {
		$access_ttl  = max( 300, (int) ( $args['access_ttl'] ?? self::ACCESS_TTL ) );
		$refresh_ttl = max( $access_ttl, (int) ( $args['refresh_ttl'] ?? self::REFRESH_TTL ) );
		$issued_at   = current_time( 'timestamp', true );
		$access_token  = $this->generate_plain_token();
		$refresh_token = $this->generate_plain_token();

		return array(
			'access_token'       => $access_token,
			'refresh_token'      => $refresh_token,
			'access_token_hash'  => $this->hash_token( $access_token ),
			'refresh_token_hash' => $this->hash_token( $refresh_token ),
			'auth_state_hash'    => $this->auth_state_hash( $user ),
			'expires_in'         => $access_ttl,
			'refresh_expires_in' => $refresh_ttl,
			'access_expires_at'  => gmdate( 'Y-m-d H:i:s', $issued_at + $access_ttl ),
			'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $issued_at + $refresh_ttl ),
		);
	}

	public function hash_token( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	public function auth_state_hash( WP_User $user ) {
		return hash_hmac(
			'sha256',
			implode(
				'|',
				array(
					(int) $user->ID,
					(string) $user->user_login,
					(string) $user->user_pass,
				)
			),
			wp_salt( 'secure_auth' )
		);
	}

	public function is_expired( $date_time ) {
		$timestamp = strtotime( (string) $date_time );

		if ( ! $timestamp ) {
			return true;
		}

		return $timestamp <= current_time( 'timestamp', true );
	}

	private function generate_plain_token() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return wp_generate_password( 64, false, false );
		}
	}
}
