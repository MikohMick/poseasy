<?php
/**
 * Plugin Name: Nakuru Gift Shop Product Manager
 * Plugin URI:  https://example.com/ngs-product-manager
 * Description: Mobile-optimized product management dashboard for Nakuru Gift Shop POS system.
 * Version:     1.0.0
 * Author:      Nakuru Gift Shop
 * Text Domain: ngs-product-manager
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'NGS_VERSION',    '1.0.0' );
define( 'NGS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NGS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once NGS_PLUGIN_DIR . 'includes/class-ngs-db.php';
require_once NGS_PLUGIN_DIR . 'includes/class-ngs-admin.php';
require_once NGS_PLUGIN_DIR . 'includes/class-ngs-ajax.php';
require_once NGS_PLUGIN_DIR . 'includes/class-ngs-export.php';

register_activation_hook( __FILE__, 'ngs_activate' );
function ngs_activate() {
    NGS_DB::create_tables();
    add_option( 'ngs_version', NGS_VERSION );
}

register_deactivation_hook( __FILE__, 'ngs_deactivate' );
function ngs_deactivate() {
    // Do NOT drop tables on deactivate (data preservation)
}

new NGS_Admin();
new NGS_Ajax();
