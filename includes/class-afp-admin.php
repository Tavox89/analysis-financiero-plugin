<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AFP_Admin {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_financial_analysis_submenu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    public function add_financial_analysis_submenu() {
        add_submenu_page(
            'woocommerce',
            'Analisis Financiero',
            'Analisis Financiero',
            'manage_woocommerce',
            'analysis-financiero',
            array( $this, 'render_financial_analysis_page' )
        );
    }

    public function render_financial_analysis_page() {
        $template = AFP_PLUGIN_DIR . 'templates/admin-page.php';
        if ( file_exists( $template ) ) {
            include $template;
        } else {
            echo '<div class="notice notice-error"><p>No se encontro la plantilla del plugin.</p></div>';
        }
    }

    public function enqueue_admin_assets( $hook ) {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'analysis-financiero' ) {
            return;
        }

        wp_enqueue_style(
            'afp-admin',
            AFP_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AFP_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'afp-admin',
            AFP_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            AFP_PLUGIN_VERSION,
            true
        );

        wp_localize_script(
            'afp-admin',
            'AFPAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'afp_nonce' ),
                'alertMarginThreshold' => (float) AFP_Settings::get_settings()['alert_margin_threshold'],
            )
        );
    }
}
