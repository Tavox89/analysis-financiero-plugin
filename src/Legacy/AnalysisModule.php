<?php

namespace ASDLabs\Finance\Legacy;

use ASDLabs\Finance\Core\Contracts\Module;

final class AnalysisModule implements Module {
	private static $loaded = false;

	public function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'bootstrap' ), 20 );
	}

	public static function bootstrap() {
		if ( self::$loaded ) {
			return;
		}

		require_once ASDL_FINANCE_DIR . 'includes/class-afp-reports.php';
		require_once ASDL_FINANCE_DIR . 'includes/class-afp-ajax.php';
		require_once ASDL_FINANCE_DIR . 'includes/class-afp-settings.php';

		new \AFP_Ajax();

		if ( is_admin() ) {
			new \AFP_Settings();
		}

		self::$loaded = true;
	}

	public static function enqueue_assets() {
		self::bootstrap();

		wp_enqueue_style(
			'afp-admin',
			ASDL_FINANCE_URL . 'assets/css/admin.css',
			array(),
			ASDL_FINANCE_VERSION
		);

		wp_enqueue_script(
			'afp-admin',
			ASDL_FINANCE_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ASDL_FINANCE_VERSION,
			true
		);

		wp_localize_script(
			'afp-admin',
			'AFPAdmin',
			array(
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'afp_nonce' ),
				'alertMarginThreshold' => (float) \AFP_Settings::get_settings()['alert_margin_threshold'],
			)
		);
	}

	public static function render_report_page() {
		self::bootstrap();

		$template = ASDL_FINANCE_DIR . 'templates/admin-page.php';

		if ( file_exists( $template ) ) {
			include $template;
			return;
		}

		echo '<div class="notice notice-error"><p>No se encontro la plantilla del analisis financiero legado.</p></div>';
	}
}
