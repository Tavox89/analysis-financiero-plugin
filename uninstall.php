<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'afp_settings' );

if ( defined( 'MINUTE_IN_SECONDS' ) ) {
    global $wpdb;
    $like = $wpdb->esc_like( '_transient_afp_report_' ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

    $like_timeout = $wpdb->esc_like( '_transient_timeout_afp_report_' ) . '%';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );
}
