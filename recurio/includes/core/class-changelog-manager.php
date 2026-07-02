<?php
/**
 * Changelog Manager Class
 * Handles version updates, notifications, and changelog display.
 * Read state is stored in user meta (no custom table), same idea as Woolentor settings changelog API.
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_Changelog_Manager {

	/**
	 * User meta key for last changelog version the user has read through.
	 */
	const USER_META_READ_VERSION = 'recurio_changelog_read';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_migrate_legacy_changelog_table' ), 20 );
		add_action( 'admin_init', array( $this, 'check_for_updates' ) );
	}

	/**
	 * One-time move from recurio_changelog_views table to user meta, then drop table.
	 *
	 * @return void
	 */
	public function maybe_migrate_legacy_changelog_table() {
		global $wpdb;

		$legacy = $wpdb->prefix . 'recurio_changelog_views';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration-only schema check.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) );
		if ( $legacy !== $table_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration-only full table read.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; no dynamic values in this query
		$rows = $wpdb->get_results( "SELECT user_id, version FROM {$legacy}", ARRAY_A );
		if ( ! empty( $rows ) ) {
			$by_user = array();
			foreach ( $rows as $row ) {
				$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
				$ver = isset( $row['version'] ) ? (string) $row['version'] : '';
				if ( $uid < 1 || '' === $ver ) {
					continue;
				}
				if ( ! isset( $by_user[ $uid ] ) || version_compare( $by_user[ $uid ], $ver, '<' ) ) {
					$by_user[ $uid ] = $ver;
				}
			}
			foreach ( $by_user as $uid => $ver ) {
				$existing = get_user_meta( $uid, self::USER_META_READ_VERSION, true );
				if ( ! $existing || version_compare( (string) $existing, $ver, '<' ) ) {
					update_user_meta( $uid, self::USER_META_READ_VERSION, $ver );
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration: drop replaced table.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input; DDL has no value to prepare
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy}`" );
	}

	/**
	 * Last changelog version string stored for the user (empty if never marked read).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function get_user_last_read( $user_id ) {
		$v = get_user_meta( $user_id, self::USER_META_READ_VERSION, true );
		return $v ? (string) $v : '';
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
	 * Mark changelog as read through a version (or latest if no version).
	 *
	 * @param string|null $version Version string or null = latest.
	 * @param int|null    $user_id User ID; defaults to current user.
	 * @return bool
	 */
	public function mark_as_viewed( $version = null, $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		$target  = $version ? sanitize_text_field( $version ) : $this->get_latest_version();
		$current = $this->get_user_last_read( $user_id );

		if ( $current && version_compare( $target, $current, '<' ) ) {
			return true;
		}

		update_user_meta( $user_id, self::USER_META_READ_VERSION, $target );
		return true;
	}

	/**
	 * Changelog entries newer than the user's last-read pointer.
	 *
	 * @param int|null $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_unread_versions( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return array();
		}

		$changelog   = $this->get_changelog_data();
		$last_read   = $this->get_user_last_read( $user_id );
		$unread_logs = array();

		foreach ( $changelog as $log ) {
			if ( ! $last_read || version_compare( $last_read, $log['version'], '<' ) ) {
				$unread_logs[] = $log;
			}
		}

		return $unread_logs;
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
			update_option( 'recurio_last_changelog_version', $latest_version );

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

		$unread_versions        = $this->get_unread_versions( $user_id );
		$unread_version_numbers = array_column( $unread_versions, 'version' );

		foreach ( $changelog as &$log ) {
			$log['is_new'] = in_array( $log['version'], $unread_version_numbers, true );
		}

		return $changelog;
	}

	/**
	 * Reset read state for a user (show all entries as unread again).
	 *
	 * @param int|null $user_id User ID.
	 * @return bool
	 */
	public function clear_user_views( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		return (bool) delete_user_meta( $user_id, self::USER_META_READ_VERSION );
	}
}

// Initialize the changelog manager
Recurio_Changelog_Manager::get_instance();
