<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AFP_Plugin {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_components();
    }

    private function includes() {
        require_once AFP_PLUGIN_DIR . 'includes/class-afp-admin.php';
        require_once AFP_PLUGIN_DIR . 'includes/class-afp-ajax.php';
        require_once AFP_PLUGIN_DIR . 'includes/class-afp-reports.php';
        require_once AFP_PLUGIN_DIR . 'includes/class-afp-settings.php';
    }

    private function init_components() {
        new AFP_Settings();
        new AFP_Admin();
        new AFP_Ajax();
    }
}
