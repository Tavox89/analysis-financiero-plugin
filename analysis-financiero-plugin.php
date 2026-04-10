<?php
/**
 * Plugin Name: Finanzas ASD
 * Description: Suite financiera modular de ASD Labs para documentos, cobros, pagos, cuotas y analisis operativo.
 * Version: 2.1.11
 * Author: ASD Labs
 * Text Domain: asd-labs-finanzas
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ASDL_FINANCE_VERSION' ) ) {
	define( 'ASDL_FINANCE_VERSION', '2.1.11' );
}

if ( ! defined( 'ASDL_FINANCE_PLUGIN_FILE' ) ) {
	define( 'ASDL_FINANCE_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'ASDL_FINANCE_DIR' ) ) {
	define( 'ASDL_FINANCE_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ASDL_FINANCE_URL' ) ) {
	define( 'ASDL_FINANCE_URL', plugin_dir_url( __FILE__ ) );
}

require_once ASDL_FINANCE_DIR . 'src/Core/Autoloader.php';

$asdl_finance_autoloader = new \ASDLabs\Finance\Core\Autoloader( ASDL_FINANCE_DIR . 'src/' );
$asdl_finance_autoloader->register();

\ASDLabs\Finance\Core\Plugin::boot( __FILE__ );

register_activation_hook( __FILE__, array( '\ASDLabs\Finance\Core\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\ASDLabs\Finance\Core\Plugin', 'deactivate' ) );
