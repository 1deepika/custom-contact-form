<?php
/**
 * Plugin Name: Custom Contact Form
 * Description: Simple shortcode contact form that saves submissions in WordPress.
 * Version: 1.1.0
 * Author: Deepika
 * Text Domain: custom-contact-form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CCF_PATH', plugin_dir_path( __FILE__ ) );

require_once CCF_PATH . 'includes/class-ccf-plugin.php';

function ccf_activate() {
    global $wpdb;
    $table = $wpdb->prefix . 'ccf_submissions';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        email varchar(190) NOT NULL,
        message text NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'ccf_activate' );

new CCF_Plugin();
