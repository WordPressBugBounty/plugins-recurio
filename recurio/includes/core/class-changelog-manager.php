<?php
/**
 * Changelog Manager Class
 * Handles version updates, notifications, and changelog display
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_Changelog_Manager {

	private static $instance = null;
	private $table_name;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'recurio_changelog_views';

		// Hook for database creation
		add_action( 'init', array( $this, 'maybe_create_table' ) );

		// Check for updates on admin init
		add_action( 'admin_init', array( $this, 'check_for_updates' ) );
	}

	/**
	 * Create database table for tracking changelog views
	 */
	public function maybe_create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            version varchar(20) NOT NULL,
            viewed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_version (user_id, version),
            KEY user_id (user_id),
            KEY version (version)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get changelog data
	 */
	public function get_changelog_data() {
		return array(
			array(
				'version' => '1.0.2',
				'date'    => '2026-04-15',
				'changes' => array(
					'Fixes'         => array(
						esc_html__( 'Offer text showing issue in product page.', 'recurio' ),
						esc_html__( 'Checkout page order summary showing issue with subscription product.', 'recurio' ),
						esc_html__( 'Subscribe status showing issue with non subscription product.', 'recurio' ),
						esc_html__( 'Email template worng billing periods showing issue with custom billing type.', 'recurio' ),
					),
					'Compatibility' => array(
						esc_html__( 'Latest WordPress and WooCommerce version', 'recurio' ),
					),
				),
			),
			array(
				'version' => '1.0.0',
				'date'    => '2025-09-18',
				'changes' => array(
					'New Features'  => array(
						esc_html__( 'Initial release', 'recurio' ),
					),
					'Fixes'         => array(),
					'Improvements'  => array(),
					'Compatibility' => array(
						esc_html__( 'Latest WordPress and WooCommerce version', 'recurio' ),
					),
				),
			),
		);
	}

	/**
	 * Get latest version
	 */
	public function get_latest_version() {
		$changelog = $this->get_changelog_data();
		return ! empty( $changelog[0]['version'] ) ? $changelog[0]['version'] : '1.0.0';
	}

	/**
	 * Mark version as viewed by user
	 */
	public function mark_as_viewed( $version = null, $user_id = null ) {
		global $wpdb;

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		// If no version specified, mark all current changelog as viewed
		if ( ! $version ) {
			$changelog = $this->get_changelog_data();
			foreach ( $changelog as $log ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for changelog management
				$wpdb->insert(
					$this->table_name,
					array(
						'user_id' => $user_id,
						'version' => $log['version'],
					),
					array( '%d', '%s' )
				);
			}
			return true;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for changelog management
		return $wpdb->insert(
			$this->table_name,
			array(
				'user_id' => $user_id,
				'version' => $version,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Get unread versions for user
	 */
	public function get_unread_versions( $user_id = null ) {
		global $wpdb;

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return array();
		}

		$changelog    = $this->get_changelog_data();
		$all_versions = array_column( $changelog, 'version' );

		// Get viewed versions
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for changelog management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time changelog data
		$viewed_versions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT version FROM {$wpdb->prefix}recurio_changelog_views WHERE user_id = %d",
				$user_id
			)
		);

		// Return unread versions
		$unread = array_diff( $all_versions, $viewed_versions );

		// Return full changelog data for unread versions
		$unread_changelog = array();
		foreach ( $changelog as $log ) {
			if ( in_array( $log['version'], $unread ) ) {
				$unread_changelog[] = $log;
			}
		}

		return $unread_changelog;
	}

	/**
	 * Get unread count for user
	 */
	public function get_unread_count( $user_id = null ) {
		return count( $this->get_unread_versions( $user_id ) );
	}

	/**
	 * Check if user has unread updates
	 */
	public function has_unread_updates( $user_id = null ) {
		return $this->get_unread_count( $user_id ) > 0;
	}

	/**
	 * Check for plugin updates and mark new versions
	 */
	public function check_for_updates() {
		$last_checked_version = get_option( 'recurio_last_changelog_version', '1.0.0' );
		$latest_version       = $this->get_latest_version();

		if ( version_compare( $latest_version, $last_checked_version, '>' ) ) {
			// New version available, update the option
			update_option( 'recurio_last_changelog_version', $latest_version );

			// Optionally trigger an action for other components
			do_action( 'recurio_new_version_available', $latest_version );
		}
	}

	/**
	 * Get formatted changelog for display
	 */
	public function get_formatted_changelog( $limit = null ) {
		$changelog = $this->get_changelog_data();
		$user_id   = get_current_user_id();

		if ( $limit ) {
			$changelog = array_slice( $changelog, 0, $limit );
		}

		// Add 'is_new' flag for unread versions
		$unread_versions        = $this->get_unread_versions( $user_id );
		$unread_version_numbers = array_column( $unread_versions, 'version' );

		foreach ( $changelog as &$log ) {
			$log['is_new'] = in_array( $log['version'], $unread_version_numbers );
		}

		return $changelog;
	}

	/**
	 * Clear all viewed records for a user
	 */
	public function clear_user_views( $user_id = null ) {
		global $wpdb;

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for changelog management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time changelog data
		return $wpdb->delete(
			$this->table_name,
			array( 'user_id' => $user_id ),
			array( '%d' )
		);
	}
}

// Initialize the changelog manager
Recurio_Changelog_Manager::get_instance();
