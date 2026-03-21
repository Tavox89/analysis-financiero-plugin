<?php

namespace ASDLabs\Finance\Finance;

use WP_Error;

final class ReceiptBrandingService {
	const OPTION_KEY = 'asdl_fin_receipt_branding';

	public function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'logo_mode'      => $this->sanitize_logo_mode( $saved['logo_mode'] ?? 'site_logo' ),
			'custom_logo_id' => absint( $saved['custom_logo_id'] ?? 0 ),
		);
	}

	public function save( array $data ) {
		$settings = array(
			'logo_mode'      => $this->sanitize_logo_mode( $data['logo_mode'] ?? 'site_logo' ),
			'custom_logo_id' => absint( $data['custom_logo_id'] ?? 0 ),
		);

		if ( 'custom_logo' === $settings['logo_mode'] && $settings['custom_logo_id'] <= 0 ) {
			return new WP_Error( 'asdl_fin_receipt_logo_missing', 'Selecciona un logo personalizado o cambia el modo de logo.' );
		}

		update_option( self::OPTION_KEY, $settings, false );

		return $settings;
	}

	public function get_snapshot() {
		$settings     = $this->get_settings();
		$resolved_url = $this->resolve_logo_url( $settings );

		return array(
			'logo_mode'       => $settings['logo_mode'],
			'custom_logo_id'  => $settings['custom_logo_id'],
			'resolved_logo'   => $resolved_url,
			'resolved_source' => $this->resolved_source_label( $settings, $resolved_url ),
			'show_logo'       => '' !== $resolved_url,
		);
	}

	public function resolve_logo_url( array $settings = array() ) {
		$settings  = ! empty( $settings ) ? $settings : $this->get_settings();
		$logo_mode = $this->sanitize_logo_mode( $settings['logo_mode'] ?? 'site_logo' );

		if ( 'none' === $logo_mode ) {
			return '';
		}

		if ( 'custom_logo' === $logo_mode ) {
			$custom_logo_id = absint( $settings['custom_logo_id'] ?? 0 );
			if ( $custom_logo_id > 0 ) {
				$custom_logo = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
				if ( $custom_logo ) {
					return $custom_logo;
				}
			}
		}

		$site_logo = $this->site_logo_url();

		return $site_logo ?: '';
	}

	public function site_logo_url() {
		$custom_logo_id = absint( get_theme_mod( 'custom_logo', 0 ) );
		if ( $custom_logo_id > 0 ) {
			$custom_logo = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
			if ( $custom_logo ) {
				return $custom_logo;
			}
		}

		$site_icon = get_site_icon_url( 192 );
		if ( $site_icon ) {
			return $site_icon;
		}

		return '';
	}

	private function sanitize_logo_mode( $value ) {
		$value = sanitize_key( (string) $value );

		if ( in_array( $value, array( 'site_logo', 'custom_logo', 'none' ), true ) ) {
			return $value;
		}

		return 'site_logo';
	}

	private function resolved_source_label( array $settings, $resolved_url ) {
		if ( '' === $resolved_url ) {
			return 'Sin logo';
		}

		$logo_mode = $this->sanitize_logo_mode( $settings['logo_mode'] ?? 'site_logo' );

		if ( 'custom_logo' === $logo_mode && absint( $settings['custom_logo_id'] ?? 0 ) > 0 ) {
			return 'Logo personalizado';
		}

		return 'Logo del sitio';
	}
}
