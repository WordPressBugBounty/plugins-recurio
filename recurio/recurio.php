<?php
/**
 * Plugin Name: Recurio – Ultimate Subscription for WooCommerce
 * Description: Ultimate Subscription Plugin for WooCommerce
 * Version: 1.1.1
 * Author: DevItems
 * Author URI: https://devitems.com
 * Plugin URI: https://wprecurio.com
 * License: GPL v2 or later
 * Text Domain: recurio
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.7.0
 */

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RECURIO_VERSION', '1.1.1');
define('RECURIO_PLUGIN_FILE', __FILE__);
define('RECURIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RECURIO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RECURIO_PLUGIN_BASENAME', plugin_basename(__FILE__));
// Load main class
require_once RECURIO_PLUGIN_DIR . 'includes/base/base.php';
// Initialize the plugin
Recurio::get_instance();

// Compatible With WooCommerce Custom Order Tables
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
