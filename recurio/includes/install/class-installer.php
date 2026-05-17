<?php
/**
 * Plugin activation, deactivation, table creation, default options, and cron scheduling.
 *
 * @package Recurio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recurio_Installer
 *
 * Handles one-time setup that runs on activation and deactivation.
 * All methods are static so the class is callable inside register_activation_hook,
 * before the main Recurio singleton is initialised.
 */
class Recurio_Installer {

	// -------------------------------------------------------------------------
	// Activation / Deactivation
	// -------------------------------------------------------------------------

	/**
	 * Run on plugin activation. No WooCommerce dependency required.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_database_tables();
		self::set_default_options();
		self::schedule_cron_jobs();

		// Flush rewrite rules on next page load once endpoints are registered.
		update_option( 'recurio_flush_rewrite_rules', true );

		// Mark DB schema at current version so migrations are skipped on fresh installs.
		update_option( 'recurio_db_version', '1.1.0' );
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::clear_cron_jobs();
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Schema creation (fresh installs)
	// -------------------------------------------------------------------------

	/**
	 * Create all plugin tables using dbDelta (idempotent — safe to call repeatedly).
	 *
	 * IMPORTANT: dbDelta requires:
	 *   - Each field on its own line.
	 *   - KEY (not INDEX) for all index definitions.
	 *   - PRIMARY KEY on its own line with two spaces before the opening parenthesis.
	 *   - No COMMENT clauses on column definitions.
	 *
	 * @return void
	 */
	private static function create_database_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Subscriptions table.
		$table_name = $wpdb->prefix . 'recurio_subscriptions';
		$sql        = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wc_subscription_id BIGINT UNSIGNED DEFAULT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			billing_period VARCHAR(20) NOT NULL,
			billing_interval INT NOT NULL DEFAULT 1,
			billing_amount DECIMAL(10,2) NOT NULL,
			payment_method VARCHAR(50) DEFAULT NULL,
			payment_token_id BIGINT UNSIGNED DEFAULT NULL,
			billing_address TEXT,
			shipping_address TEXT,
			shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			shipping_method VARCHAR(100) NOT NULL DEFAULT '',
			trial_end_date DATETIME DEFAULT NULL,
			next_payment_date DATETIME DEFAULT NULL,
			pause_start_date DATETIME DEFAULT NULL,
			pause_end_date DATETIME DEFAULT NULL,
			cancellation_date DATETIME DEFAULT NULL,
			cancellation_reason TEXT,
			failed_payment_count INT NOT NULL DEFAULT 0,
			renewal_count INT NOT NULL DEFAULT 0,
			skip_count INT NOT NULL DEFAULT 0,
			max_renewals INT DEFAULT NULL,
			payment_type VARCHAR(20) NOT NULL DEFAULT 'recurring',
			max_payments INT NOT NULL DEFAULT 0,
			access_timing VARCHAR(50) NOT NULL DEFAULT 'immediate',
			access_duration_value INT NOT NULL DEFAULT 1,
			access_duration_unit VARCHAR(20) NOT NULL DEFAULT 'month',
			access_end_date DATETIME DEFAULT NULL,
			switched_from_id BIGINT UNSIGNED DEFAULT NULL,
			switched_to_id BIGINT UNSIGNED DEFAULT NULL,
			switch_type VARCHAR(20) DEFAULT NULL,
			churn_risk_score DECIMAL(3,2) NOT NULL DEFAULT 0.00,
			customer_ltv DECIMAL(10,2) DEFAULT NULL,
			subscription_metadata LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_next_payment (next_payment_date),
			KEY idx_churn_risk (churn_risk_score),
			KEY idx_payment_type (payment_type)
		) $charset_collate;";
		dbDelta( $sql );

		// Subscription events table.
		$table_name = $wpdb->prefix . 'recurio_subscription_events';
		$sql        = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscription_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(50) NOT NULL,
			event_value DECIMAL(10,2) DEFAULT NULL,
			event_metadata LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_recurio_subscription_events (subscription_id, created_at),
			KEY idx_event_type (event_type)
		) $charset_collate;";
		dbDelta( $sql );

		// Revenue tracking table.
		$table_name = $wpdb->prefix . 'recurio_subscription_revenue';
		$sql        = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscription_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(10,2) NOT NULL,
			currency VARCHAR(3) NOT NULL DEFAULT 'USD',
			period_type VARCHAR(20) DEFAULT NULL,
			period_start DATE DEFAULT NULL,
			period_end DATE DEFAULT NULL,
			transaction_id VARCHAR(100) DEFAULT NULL,
			gateway VARCHAR(50) DEFAULT NULL,
			payment_method VARCHAR(50) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_revenue_date (period_start, period_end),
			KEY idx_recurio_subscription_revenue (subscription_id)
		) $charset_collate;";
		dbDelta( $sql );

		// Customer analytics table.
		$table_name = $wpdb->prefix . 'recurio_customer_analytics';
		$sql        = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_id BIGINT UNSIGNED NOT NULL,
			total_subscriptions INT NOT NULL DEFAULT 0,
			active_subscriptions INT NOT NULL DEFAULT 0,
			total_revenue DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			average_order_value DECIMAL(10,2) DEFAULT NULL,
			churn_probability DECIMAL(3,2) NOT NULL DEFAULT 0.00,
			customer_lifetime_value DECIMAL(10,2) DEFAULT NULL,
			last_activity_date DATETIME DEFAULT NULL,
			customer_segment VARCHAR(50) DEFAULT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_customer (customer_id)
		) $charset_collate;";
		dbDelta( $sql );

		// Revenue goals table.
		$table_name = $wpdb->prefix . 'recurio_revenue_goals';
		$sql        = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			target_amount DECIMAL(10,2) NOT NULL,
			current_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			period_type VARCHAR(20) NOT NULL,
			start_date DATE NOT NULL,
			end_date DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_dates (start_date, end_date),
			KEY idx_period (period_type)
		) $charset_collate;";
		dbDelta( $sql );

		// Webhooks table.
		$table_name = $wpdb->prefix . 'recurio_webhooks';
		$sql        = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			url VARCHAR(500) NOT NULL,
			events LONGTEXT NOT NULL,
			secret VARCHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			failure_count INT NOT NULL DEFAULT 0,
			last_triggered_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_status (status)
		) $charset_collate;";
		dbDelta( $sql );

		// Webhook logs table.
		$table_name = $wpdb->prefix . 'recurio_webhook_logs';
		$sql        = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			webhook_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(100) NOT NULL,
			payload LONGTEXT NOT NULL,
			response_code INT DEFAULT NULL,
			response_body TEXT DEFAULT NULL,
			response_time INT DEFAULT NULL,
			success TINYINT(1) NOT NULL DEFAULT 0,
			attempt_number INT NOT NULL DEFAULT 1,
			error_message TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_webhook_id (webhook_id),
			KEY idx_event (event),
			KEY idx_success (success),
			KEY idx_created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql );

		// Plans table.
		$table_name = $wpdb->prefix . 'recurio_plans';
		$sql        = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			description TEXT DEFAULT NULL,
			slug VARCHAR(255) NOT NULL,
			type VARCHAR(50) NOT NULL DEFAULT 'recurring',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			settings LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_slug (slug),
			KEY idx_status (status),
			KEY idx_type (type)
		) $charset_collate;";
		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// Default options
	// -------------------------------------------------------------------------

	/**
	 * Set default plugin options on first activation only (uses add_option, not update_option).
	 *
	 * @return void
	 */
	private static function set_default_options() {
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

	// -------------------------------------------------------------------------
	// Cron
	// -------------------------------------------------------------------------

	/**
	 * Schedule recurring cron events if not already scheduled.
	 *
	 * @return void
	 */
	private static function schedule_cron_jobs() {
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

	/**
	 * Clear all plugin cron hooks on deactivation.
	 *
	 * @return void
	 */
	private static function clear_cron_jobs() {
		wp_clear_scheduled_hook( 'recurio_process_payments' );
		wp_clear_scheduled_hook( 'recurio_calculate_analytics' );
		wp_clear_scheduled_hook( 'recurio_predict_churn' );
		wp_clear_scheduled_hook( 'recurio_send_renewal_reminders' );
	}
}
