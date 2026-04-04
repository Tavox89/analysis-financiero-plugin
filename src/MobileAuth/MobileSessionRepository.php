<?php

namespace ASDLabs\Finance\MobileAuth;

use ASDLabs\Finance\Finance\BaseRepository;
use WP_User;

final class MobileSessionRepository extends BaseRepository {
	protected $table_key = 'mobile_sessions';

	public function create_session( WP_User $user, array $device, array $token_pair ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'clubsams_control_mobile_sessions_missing', 'La tabla de sesiones moviles aun no esta disponible.' );
		}

		$now    = $this->now();
		$result = $this->db()->insert(
			$this->table(),
			array(
				'user_id'           => (int) $user->ID,
				'device_name'       => sanitize_text_field( (string) ( $device['device_name'] ?? '' ) ),
				'platform'          => sanitize_key( (string) ( $device['platform'] ?? 'ios' ) ),
				'app_version'       => sanitize_text_field( (string) ( $device['app_version'] ?? '' ) ),
				'access_token_hash' => sanitize_text_field( (string) ( $token_pair['access_token_hash'] ?? '' ) ),
				'refresh_token_hash'=> sanitize_text_field( (string) ( $token_pair['refresh_token_hash'] ?? '' ) ),
				'auth_state_hash'   => sanitize_text_field( (string) ( $token_pair['auth_state_hash'] ?? '' ) ),
				'access_expires_at' => sanitize_text_field( (string) ( $token_pair['access_expires_at'] ?? '' ) ),
				'refresh_expires_at'=> sanitize_text_field( (string) ( $token_pair['refresh_expires_at'] ?? '' ) ),
				'last_used_at'      => $now,
				'last_ip'           => sanitize_text_field( (string) ( $device['ip'] ?? '' ) ),
				'user_agent'        => sanitize_text_field( (string) ( $device['user_agent'] ?? '' ) ),
				'meta_json'         => wp_json_encode(
					array(
						'device_name' => sanitize_text_field( (string) ( $device['device_name'] ?? '' ) ),
						'platform'    => sanitize_key( (string) ( $device['platform'] ?? 'ios' ) ),
						'app_version' => sanitize_text_field( (string) ( $device['app_version'] ?? '' ) ),
						'origin'      => 'clubsams-control/v1',
					)
				),
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'clubsams_control_mobile_session_insert', 'No se pudo abrir la sesion del dispositivo.' );
		}

		return (int) $this->db()->insert_id;
	}

	public function find( $session_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				absint( $session_id )
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->normalize_session_row( $row );
	}

	public function find_active_by_access_token( $token ) {
		return $this->find_active_by_hash( 'access_token_hash', $token );
	}

	public function find_active_by_refresh_token( $token ) {
		return $this->find_active_by_hash( 'refresh_token_hash', $token );
	}

	public function rotate_session_tokens( $session_id, array $token_pair, array $device = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$payload = array(
			'access_token_hash' => sanitize_text_field( (string) ( $token_pair['access_token_hash'] ?? '' ) ),
			'refresh_token_hash'=> sanitize_text_field( (string) ( $token_pair['refresh_token_hash'] ?? '' ) ),
			'auth_state_hash'   => sanitize_text_field( (string) ( $token_pair['auth_state_hash'] ?? '' ) ),
			'access_expires_at' => sanitize_text_field( (string) ( $token_pair['access_expires_at'] ?? '' ) ),
			'refresh_expires_at'=> sanitize_text_field( (string) ( $token_pair['refresh_expires_at'] ?? '' ) ),
			'last_used_at'      => $this->now(),
			'last_ip'           => sanitize_text_field( (string) ( $device['ip'] ?? '' ) ),
			'user_agent'        => sanitize_text_field( (string) ( $device['user_agent'] ?? '' ) ),
			'updated_at'        => $this->now(),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( isset( $device['device_name'] ) ) {
			$payload['device_name'] = sanitize_text_field( (string) $device['device_name'] );
			$formats[] = '%s';
		}

		if ( isset( $device['platform'] ) ) {
			$payload['platform'] = sanitize_key( (string) $device['platform'] );
			$formats[] = '%s';
		}

		if ( isset( $device['app_version'] ) ) {
			$payload['app_version'] = sanitize_text_field( (string) $device['app_version'] );
			$formats[] = '%s';
		}

		return false !== $this->db()->update(
			$this->table(),
			$payload,
			array( 'id' => absint( $session_id ) ),
			$formats,
			array( '%d' )
		);
	}

	public function touch_session( $session_id, array $context = array() ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		return false !== $this->db()->update(
			$this->table(),
			array(
				'last_used_at' => $this->now(),
				'last_ip'      => sanitize_text_field( (string) ( $context['ip'] ?? '' ) ),
				'user_agent'   => sanitize_text_field( (string) ( $context['user_agent'] ?? '' ) ),
				'updated_at'   => $this->now(),
			),
			array( 'id' => absint( $session_id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function revoke_session( $session_id, $reason = 'logout' ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$session = $this->find( $session_id );
		if ( empty( $session['id'] ) ) {
			return false;
		}

		$meta = is_array( $session['meta'] ?? null ) ? $session['meta'] : array();
		$meta['revoked_reason'] = sanitize_key( (string) $reason );

		return false !== $this->db()->update(
			$this->table(),
			array(
				'revoked_at' => $this->now(),
				'meta_json'  => wp_json_encode( $meta ),
				'updated_at' => $this->now(),
			),
			array( 'id' => absint( $session_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private function find_active_by_hash( $column, $token ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$token_service = new TokenService();
		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE {$column} = %s
				AND revoked_at IS NULL
				LIMIT 1",
				$token_service->hash_token( $token )
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->normalize_session_row( $row );
	}

	private function normalize_session_row( array $row ) {
		return array(
			'id'                => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'user_id'           => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
			'device_name'       => sanitize_text_field( (string) ( $row['device_name'] ?? '' ) ),
			'platform'          => sanitize_key( (string) ( $row['platform'] ?? '' ) ),
			'app_version'       => sanitize_text_field( (string) ( $row['app_version'] ?? '' ) ),
			'access_token_hash' => sanitize_text_field( (string) ( $row['access_token_hash'] ?? '' ) ),
			'refresh_token_hash'=> sanitize_text_field( (string) ( $row['refresh_token_hash'] ?? '' ) ),
			'auth_state_hash'   => sanitize_text_field( (string) ( $row['auth_state_hash'] ?? '' ) ),
			'access_expires_at' => sanitize_text_field( (string) ( $row['access_expires_at'] ?? '' ) ),
			'refresh_expires_at'=> sanitize_text_field( (string) ( $row['refresh_expires_at'] ?? '' ) ),
			'last_used_at'      => sanitize_text_field( (string) ( $row['last_used_at'] ?? '' ) ),
			'last_ip'           => sanitize_text_field( (string) ( $row['last_ip'] ?? '' ) ),
			'user_agent'        => sanitize_text_field( (string) ( $row['user_agent'] ?? '' ) ),
			'revoked_at'        => sanitize_text_field( (string) ( $row['revoked_at'] ?? '' ) ),
			'created_at'        => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			'updated_at'        => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
			'meta'              => $this->decode_meta( $row['meta_json'] ?? '' ),
		);
	}

	private function decode_meta( $meta_json ) {
		$meta = json_decode( (string) $meta_json, true );
		return is_array( $meta ) ? $meta : array();
	}
}
