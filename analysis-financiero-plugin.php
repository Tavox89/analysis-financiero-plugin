<?php
/**
 * Plugin Name: Analisis Financiero WooCommerce
 * Description: Analiza ventas, costos y rentabilidad con filtros opcionales por categoria en WooCommerce.
 * Version: 1.4.1
 * Author: Tu Nombre
 * Text Domain: analysis-financiero
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'AFP_PLUGIN_VERSION' ) ) {
    define( 'AFP_PLUGIN_VERSION', '1.4.1' );
}

define( 'AFP_PLUGIN_FILE', __FILE__ );
define( 'AFP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AFP_PLUGIN_DIR . 'includes/class-afp-plugin.php';

AFP_Plugin::instance();
