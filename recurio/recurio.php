<?php
/**
 * Plugin Name: Recurio – Ultimate Subscription for WooCommerce
 * Description: Ultimate Subscription Plugin for WooCommerce
 * Version: 1.0.2
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
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'RECURIO_VERSION', '1.0.2' );
define( 'RECURIO_PLUGIN_FILE', __FILE__ );
define( 'RECURIO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RECURIO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RECURIO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Check if WooCommerce is active
function recurio_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Recurio requires WooCommerce to be installed and active.', 'recurio' ); ?></p>
			</div>
				<?php
			}
		);
		return false;
	}
	return true;
}

// Main plugin class
class Recurio {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_plugin' ) );
		add_action( 'init', array( $this, 'init' ) );

		// Activation and deactivation hooks
		register_activation_hook( RECURIO_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( RECURIO_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Compatible With WooCommerce Custom Order Tables
		$this->compatibility_woocommerce_custom_order();
	}

	public function load_plugin() {
		if ( ! recurio_check_woocommerce() ) {
			return;
		}

		// Check for database upgrades
		$this->maybe_upgrade_database();

		// Load required files
		$this->load_includes();

		// Initialize components
		$this->init_components();
	}

	/**
	 * Check and upgrade database if needed
	 */
	private function maybe_upgrade_database() {
		$db_version = get_option( 'recurio_db_version', '1.0.0' );

		// Version 1.1.0 - Add custom access duration columns
		if ( version_compare( $db_version, '1.0.1', '<' ) ) {
			$this->upgrade_to_1_1_0();
			update_option( 'recurio_db_version', '1.0.1' );
		}

	}

	/**
	 * Database upgrade to version 1.1.0
	 * Adds custom access duration columns for split payments
	 */
	private function upgrade_to_1_1_0() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'recurio_subscriptions';

		// Check if columns exist before adding
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );

		if ( ! in_array( 'access_duration_value', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN access_duration_value INT DEFAULT 1 AFTER access_timing" );
		}

		if ( ! in_array( 'access_duration_unit', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN access_duration_unit VARCHAR(20) DEFAULT 'month' AFTER access_duration_value" );
		}

		if ( ! in_array( 'access_end_date', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN access_end_date DATETIME DEFAULT NULL AFTER access_duration_unit" );
		}

		if ( ! in_array( 'switched_from_id', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN switched_from_id BIGINT DEFAULT NULL AFTER access_end_date" );
		}

		if ( ! in_array( 'switched_to_id', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN switched_to_id BIGINT DEFAULT NULL AFTER switched_from_id" );
		}

		if ( ! in_array( 'switch_type', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN switch_type VARCHAR(20) DEFAULT NULL AFTER switched_to_id" );
		}
	}

	public function compatibility_woocommerce_custom_order(){
		// Compatible With WooCommerce Custom Order Tables
		add_action( 'before_woocommerce_init', function() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		} );
	}

	public function init() {
		// Initialize post types, taxonomies, etc.
		do_action( 'recurio_init' );
	}

	private function load_includes() {
		// Pro Manager (must load first)
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-pro-manager.php';

		// Core classes
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-subscription-engine.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-email-notifications.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-billing-manager.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-payment-methods.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-changelog-manager.php';
		// Subscription Switching is a PRO feature - loaded by recurio-pro plugin

		// Admin classes
		require_once RECURIO_PLUGIN_DIR . 'includes/admin/class-dashboard.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/admin/class-vue-app.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/admin/class-wc-subscriptions-importer.php';

		// WooCommerce integration
		if ( class_exists( 'WooCommerce' ) ) {
			require_once RECURIO_PLUGIN_DIR . 'includes/integrations/class-woocommerce-product.php';
		}

		// Frontend classes
		require_once RECURIO_PLUGIN_DIR . 'includes/frontend/class-customer-portal.php';

		// Admin classes
		require_once RECURIO_PLUGIN_DIR . 'includes/admin/class-pro-upsell.php';

		// Integration classes
		require_once RECURIO_PLUGIN_DIR . 'includes/integrations/class-woocommerce.php';

		// API classes
		require_once RECURIO_PLUGIN_DIR . 'includes/api/class-rest-api.php';
	}

	private function init_components() {
		// Initialize Pro Manager first
		Recurio_Pro_Manager::get_instance();

		// Initialize core components
		Recurio_Subscription_Engine::get_instance();
		Recurio_Email_Notifications::get_instance();
		Recurio_Billing_Manager::get_instance();
		Recurio_Payment_Methods::get_instance();
		// Subscription Switching is initialized by PRO plugin

		// Initialize admin components
		if ( is_admin() ) {
			Recurio_Dashboard::get_instance();
			Recurio_Vue_App::get_instance();
			Recurio_Pro_Upsell::get_instance();
			Recurio_WC_Subscriptions_Importer::get_instance();
		}

		// Initialize frontend components
		// Note: AJAX requests are considered admin requests, so we need to initialize for AJAX too
		if ( ! is_admin() || wp_doing_ajax() ) {
			Recurio_Customer_Portal::get_instance();
		}

		// Initialize integrations
		Recurio_WooCommerce_Integration::get_instance();

		// Initialize API
		Recurio_Rest_API::get_instance();
	}

	public function activate() {
		// Create database tables
		$this->create_database_tables();

		// Set default options
		$this->set_default_options();

		// Schedule cron jobs
		$this->schedule_cron_jobs();

		// Set flag to flush rewrite rules on next page load
		// This ensures endpoints are properly registered before flushing
		update_option( 'recurio_flush_rewrite_rules', true );
	}

	public function deactivate() {
		// Clear scheduled cron jobs
		$this->clear_cron_jobs();

		// Flush rewrite rules immediately to remove custom endpoints
		flush_rewrite_rules();
	}

	private function create_database_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Subscriptions table
		$table_name = $wpdb->prefix . 'recurio_subscriptions';
		$sql        = "CREATE TABLE $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            wc_subscription_id BIGINT,
            customer_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            status VARCHAR(20) DEFAULT 'active',
            billing_period VARCHAR(20) NOT NULL,
            billing_interval INT DEFAULT 1,
            billing_amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(50) DEFAULT NULL,
            payment_token_id BIGINT DEFAULT NULL,
            billing_address TEXT,
            shipping_address TEXT,
            trial_end_date DATETIME,
            next_payment_date DATETIME,
            pause_start_date DATETIME,
            pause_end_date DATETIME,
            cancellation_date DATETIME,
            cancellation_reason TEXT,
            failed_payment_count INT DEFAULT 0,
            renewal_count INT DEFAULT 0,
            max_renewals INT DEFAULT NULL,
            payment_type VARCHAR(20) DEFAULT 'recurring',
            max_payments INT DEFAULT 0,
            access_timing VARCHAR(50) DEFAULT 'immediate',
            access_duration_value INT DEFAULT 1,
            access_duration_unit VARCHAR(20) DEFAULT 'month',
            access_end_date DATETIME DEFAULT NULL,
            switched_from_id BIGINT DEFAULT NULL,
            switched_to_id BIGINT DEFAULT NULL,
            switch_type VARCHAR(20) DEFAULT NULL,
            churn_risk_score DECIMAL(3,2) DEFAULT 0,
            customer_ltv DECIMAL(10,2),
            subscription_metadata LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_next_payment (next_payment_date),
            INDEX idx_churn_risk (churn_risk_score),
            INDEX idx_payment_type (payment_type)
        ) $charset_collate;";
		dbDelta( $sql );

		// Subscription events table
		$table_name = $wpdb->prefix . 'recurio_subscription_events';
		$sql        = "CREATE TABLE $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            subscription_id BIGINT NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_value DECIMAL(10,2),
            event_metadata LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recurio_subscription_events (subscription_id, created_at),
            INDEX idx_event_type (event_type)
        ) $charset_collate;";
		dbDelta( $sql );

		// Revenue tracking table
		$table_name = $wpdb->prefix . 'recurio_subscription_revenue';
		$sql        = "CREATE TABLE $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            subscription_id BIGINT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(3) DEFAULT 'USD',
            period_type VARCHAR(20),
            period_start DATE,
            period_end DATE,
            transaction_id VARCHAR(100),
            gateway VARCHAR(50),
            payment_method VARCHAR(50),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_revenue_date (period_start, period_end),
            INDEX idx_recurio_subscription_revenue (subscription_id)
        ) $charset_collate;";
		dbDelta( $sql );

		// Customer analytics table
		$table_name = $wpdb->prefix . 'recurio_customer_analytics';
		$sql        = "CREATE TABLE $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            customer_id BIGINT NOT NULL,
            total_subscriptions INT DEFAULT 0,
            active_subscriptions INT DEFAULT 0,
            total_revenue DECIMAL(10,2) DEFAULT 0,
            average_order_value DECIMAL(10,2),
            churn_probability DECIMAL(3,2) DEFAULT 0,
            customer_lifetime_value DECIMAL(10,2),
            last_activity_date DATETIME,
            customer_segment VARCHAR(50),
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_customer (customer_id)
        ) $charset_collate;";
		dbDelta( $sql );

		// Revenue goals table (added in v1.5 but created here for new installs)
		$table_name = $wpdb->prefix . 'recurio_revenue_goals';
		$sql        = "CREATE TABLE $table_name (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            target_amount DECIMAL(10,2) NOT NULL,
            current_amount DECIMAL(10,2) DEFAULT 0,
            period_type VARCHAR(20) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date),
            INDEX idx_period (period_type)
        ) $charset_collate;";
		dbDelta( $sql );

		// Webhooks table
		$table_name = $wpdb->prefix . 'recurio_webhooks';
		$sql        = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			url varchar(500) NOT NULL,
			events longtext NOT NULL COMMENT 'JSON array of event names',
			secret varchar(64) NOT NULL COMMENT 'HMAC secret for signature verification',
			status varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active, paused, failed',
			failure_count int(11) NOT NULL DEFAULT 0,
			last_triggered_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status)
		) $charset_collate;";
		dbDelta( $sql );

		// Webhook logs table
		$table_name = $wpdb->prefix . 'recurio_webhook_logs';
		$sql        = "CREATE TABLE $table_name (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			webhook_id bigint(20) UNSIGNED NOT NULL,
			event varchar(100) NOT NULL,
			payload longtext NOT NULL COMMENT 'JSON payload sent',
			response_code int(11) DEFAULT NULL,
			response_body text DEFAULT NULL,
			response_time int(11) DEFAULT NULL COMMENT 'Response time in milliseconds',
			success tinyint(1) NOT NULL DEFAULT 0,
			attempt_number int(11) NOT NULL DEFAULT 1,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY webhook_id (webhook_id),
			KEY event (event),
			KEY success (success),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql );
	}

	private function set_default_options() {
		add_option( 'recurio_version', RECURIO_VERSION );
		add_option(
			'recurio_settings',
			array(
				'enable_customer_portal' => true,
				'dunning_attempts'       => 3,
				'dunning_interval'       => 3,
				'enable_analytics'       => true,
				'currency'               => 'USD',
				'date_format'            => 'Y-m-d',
				'enable_debug'           => false,
				'billing'                => array(
					'periods'         => array( 'daily', 'weekly', 'monthly', 'quarterly', 'yearly' ),
					'autoRenewal'     => true,
					'enableProration' => true,
					'trialLength'     => 14,
					'trialUnit'       => 'days',
				),
			)
		);
	}

	private function schedule_cron_jobs() {

		if ( ! wp_next_scheduled( 'recurio_process_payments' ) ) {
			wp_schedule_event( time(), 'daily', 'recurio_process_payments' );
		}

		if ( ! wp_next_scheduled( 'recurio_calculate_analytics' ) ) {
			wp_schedule_event( time(), 'hourly', 'recurio_calculate_analytics' );
		}

		if ( ! wp_next_scheduled( 'recurio_predict_churn' ) ) {
			wp_schedule_event( time(), 'daily', 'recurio_predict_churn' );
		}

		if ( ! wp_next_scheduled( 'recurio_send_renewal_reminders' ) ) {
			wp_schedule_event( time(), 'daily', 'recurio_send_renewal_reminders' );
		}
	}

	private function clear_cron_jobs() {
		wp_clear_scheduled_hook( 'recurio_process_payments' );
		wp_clear_scheduled_hook( 'recurio_calculate_analytics' );
		wp_clear_scheduled_hook( 'recurio_predict_churn' );
		wp_clear_scheduled_hook( 'recurio_send_renewal_reminders' );
	}
}

// Initialize the plugin
Recurio::get_instance();