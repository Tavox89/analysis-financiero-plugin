<?php

namespace ASDLabs\Finance\Core;

use ASDLabs\Finance\Admin\Assets;
use ASDLabs\Finance\Admin\CrudController;
use ASDLabs\Finance\Admin\Menu;
use ASDLabs\Finance\Api\ClubsamsControlRoutes;
use ASDLabs\Finance\Api\Routes;
use ASDLabs\Finance\Finance\HistoricalCommerceModule;
use ASDLabs\Finance\Finance\IntegrityMonitorModule;
use ASDLabs\Finance\Integrations\Woo\Module as WooModule;
use ASDLabs\Finance\Legacy\AnalysisModule;
use ASDLabs\Finance\MobileAuth\Module as MobileAuthModule;

final class Plugin {
	private static $instance = null;
	private $modules = array();

	public static function boot( $plugin_file ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
		}

		return self::$instance;
	}

	public static function activate() {
		SchemaInstaller::activate();
		CapabilityManager::activate();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	private function __construct( $plugin_file ) {
		unset( $plugin_file );

		$this->modules = array(
			new SchemaInstaller(),
			new CapabilityManager(),
			new AnalysisModule(),
			new Menu(),
			new Assets(),
			new CrudController(),
			new MobileAuthModule(),
			new Routes(),
			new ClubsamsControlRoutes(),
			new WooModule(),
			new HistoricalCommerceModule(),
			new IntegrityMonitorModule(),
		);
		
		$this->register_modules();
	}

	private function register_modules() {
		foreach ( $this->modules as $module ) {
			$module->register();
		}
	}
}
