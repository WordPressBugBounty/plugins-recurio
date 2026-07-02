<?php
/**
 * Incremental database schema migrations.
 *
 * Each upgrade_to_* method represents one schema version. Add a new method
 * and a corresponding version_compare block in maybe_upgrade_database() whenever
 * the schema changes. Never edit or remove existing migration methods — they
 * must stay in place for sites that haven't run them yet.
 *
 * @package Recurio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recurio_DB_Migrations
 *
 * Runs outstanding schema migrations on plugins_loaded (after WooCommerce
 * is available). All methods are static — no instantiation needed.
 */
class Recurio_DB_Migrations {

	/**
	 * Check the stored DB version and run any outstanding migrations in order.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_database() {
		$db_version = get_option( 'recurio_db_version', '1.0.0' );

		// v1.0.1 — access-duration and subscription-switch columns.
		// Already applied on all existing sites; kept here for the rare site
		// that was never activated cleanly (e.g. manual table copy).
		if ( version_compare( $db_version, '1.0.1', '<' ) ) {
			self::upgrade_to_1_0_1();
			update_option( 'recurio_db_version', '1.0.1' );
			$db_version = '1.0.1';
		}

		// v1.1.0 — skip_count column + event_metadata LONGTEXT + shipping columns + Plans table.
		if ( version_compare( $db_version, '1.1.0', '<' ) ) {
			self::upgrade_to_1_1_0();
			update_option( 'recurio_db_version', '1.1.0' );
			$db_version = '1.1.0'; // phpcs:ignore -- kept for future migration blocks.
		}
	}

	// -------------------------------------------------------------------------
	// Migrations
	// -------------------------------------------------------------------------

	/**
	 * v1.0.1 — Add access-duration and subscription-switch columns to recurio_subscriptions.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_0_1() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'recurio_subscriptions';

		// Guard against corrupted installs where the table is missing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $table_name !== $table_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );

		if ( ! in_array( 'access_duration_value', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN access_duration_value INT DEFAULT 1 AFTER access_timing" );
		}

		if ( ! in_array( 'access_duration_unit', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN access_duration_unit VARCHAR(20) DEFAULT 'month' AFTER access_duration_value" );
		}

		if ( ! in_array( 'access_end_date', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN access_end_date DATETIME DEFAULT NULL AFTER access_duration_unit" );
		}

		if ( ! in_array( 'switched_from_id', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN switched_from_id BIGINT DEFAULT NULL AFTER access_end_date" );
		}

		if ( ! in_array( 'switched_to_id', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN switched_to_id BIGINT DEFAULT NULL AFTER switched_from_id" );
		}

		if ( ! in_array( 'switch_type', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN switch_type VARCHAR(20) DEFAULT NULL AFTER switched_to_id" );
		}
	}

	/**
	 * v1.1.0 — Four schema changes applied to existing sites upgrading from 1.0.x.
	 *
	 * 1. skip_count column on recurio_subscriptions.
	 * 2. event_metadata promoted to LONGTEXT on recurio_subscription_events.
	 * 3. shipping_amount and shipping_method columns on recurio_subscriptions.
	 * 4. recurio_plans table creation (idempotent via dbDelta).
	 *
	 * Steps 1–3 guard against a missing subscriptions table individually.
	 * Step 4 (Plans table) runs regardless — it has no dependency on other tables.
	 *
	 * @return void
	 */
	private static function upgrade_to_1_1_0() {
		global $wpdb;

		$charset_collate      = $wpdb->get_charset_collate();
		$subscriptions_table  = $wpdb->prefix . 'recurio_subscriptions';
		$events_table         = $wpdb->prefix . 'recurio_subscription_events';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
		$subs_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subscriptions_table ) );

		if ( $subscriptions_table === $subs_table_exists ) {

			// Fetch all columns once for the three subscriptions-table checks below.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$subscriptions_table}" );

			// 1. skip_count — tracks how many billing cycles a customer has skipped.
			if ( ! in_array( 'skip_count', $columns, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
				$wpdb->query( "ALTER TABLE {$subscriptions_table} ADD COLUMN skip_count INT NOT NULL DEFAULT 0 AFTER renewal_count" );
			}

			// 3. shipping_amount and shipping_method for shipping address changes.
			if ( ! in_array( 'shipping_amount', $columns, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
				$wpdb->query( "ALTER TABLE {$subscriptions_table} ADD COLUMN shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER shipping_address" );
			}
			if ( ! in_array( 'shipping_method', $columns, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
				$wpdb->query( "ALTER TABLE {$subscriptions_table} ADD COLUMN shipping_method VARCHAR(100) NOT NULL DEFAULT '' AFTER shipping_amount" );
			}
		}

		// 2. Promote event_metadata to LONGTEXT (supports large JSON payloads, e.g. cancellation surveys).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
		$events_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) );
		if ( $events_table === $events_table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input
			$col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$events_table}` LIKE %s", 'event_metadata' ) );
			if ( $col && ! empty( $col->Type ) && stripos( $col->Type, 'longtext' ) === false ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time ALTER
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
				$wpdb->query( "ALTER TABLE `{$events_table}` MODIFY COLUMN event_metadata LONGTEXT" );
			}
		}

		// 4. Plans table — dbDelta is idempotent, safe on installs that already have it.
		$plans_table = $wpdb->prefix . 'recurio_plans';
		$sql         = "CREATE TABLE $plans_table (
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
}
