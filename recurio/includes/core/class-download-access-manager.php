<?php
/**
 * Download Access Manager - Ties WooCommerce downloadable-product permissions
 * to subscription status (pause/resume/cancel), when explicitly enabled via
 * Settings > General > "Suspend Downloads on Pause/Cancel".
 *
 * Fully opt-in and off by default: with the setting off, this class only ever
 * reads the setting and returns, touching nothing else in the plugin.
 *
 * @package Recurio
 * @since 1.1.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_Download_Access_Manager {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const GRACE_PERIOD_HOOK = 'recurio_download_access_grace_period_ended';

	private function __construct() {
		add_action( 'recurio_subscription_paused', array( $this, 'handle_subscription_paused' ), 10, 2 );
		add_action( 'recurio_subscription_resumed', array( $this, 'handle_subscription_resumed' ), 10, 2 );
		add_action( 'recurio_subscription_cancelled', array( $this, 'handle_subscription_cancelled' ), 10, 3 );
		add_action( 'recurio_subscription_expired', array( $this, 'handle_subscription_expired' ), 10, 2 );
		add_action( self::GRACE_PERIOD_HOOK, array( $this, 'handle_grace_period_ended' ) );
	}

	/**
	 * Whether the merchant has opted in to this behavior.
	 */
	private function is_enabled() {
		$settings = get_option( 'recurio_settings', array() );
		return isset( $settings['general']['suspendDownloadAccessOnPauseCancel'] )
			? (bool) $settings['general']['suspendDownloadAccessOnPauseCancel']
			: false;
	}

	/**
	 * Pausing doesn't revoke access immediately — the customer already paid for the
	 * current period, so they keep it through `next_payment_date` the same way an
	 * end-of-period cancellation does. We schedule a one-off event for that moment
	 * instead; if they resume before then, `handle_subscription_resumed()` cancels
	 * it and nothing was ever suspended.
	 */
	public function handle_subscription_paused( $subscription_id, $subscription_data ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$subscription = Recurio_Subscription_Engine::get_instance()->get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}

		$grace_until = ! empty( $subscription->next_payment_date ) ? strtotime( $subscription->next_payment_date ) : false;

		wp_clear_scheduled_hook( self::GRACE_PERIOD_HOOK, array( $subscription_id ) );

		if ( $grace_until && $grace_until > time() ) {
			wp_schedule_single_event( $grace_until, self::GRACE_PERIOD_HOOK, array( $subscription_id ) );
		} else {
			// Nothing left paid for (no next_payment_date on record, or it's already passed).
			$this->suspend_download_access( $subscription_id );
		}
	}

	/**
	 * Fires once the paid-for period a pause happened during actually ends. Re-checks
	 * live status first — the customer may have resumed in the meantime, in which case
	 * this is a no-op (the pending event would already have been cleared on resume, but
	 * we guard here too in case of a race with an in-flight cron run).
	 */
	public function handle_grace_period_ended( $subscription_id ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$subscription = Recurio_Subscription_Engine::get_instance()->get_subscription( $subscription_id );
		if ( ! $subscription || 'paused' !== $subscription->status ) {
			return;
		}

		$this->suspend_download_access( $subscription_id );
	}

	public function handle_subscription_resumed( $subscription_id, $subscription_data ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Cancel a still-pending grace-period suspension (nothing was suspended yet).
		wp_clear_scheduled_hook( self::GRACE_PERIOD_HOOK, array( $subscription_id ) );

		// If the grace period already ended before they resumed, this restores what
		// was suspended; if nothing was ever suspended, it's a safe no-op.
		$this->restore_download_access( $subscription_id );
	}

	/**
	 * Only revoke on an actual, immediate cancellation. A scheduled end-of-period
	 * cancellation leaves the subscription in 'pending_cancellation' until the
	 * period actually ends — the customer already paid for that period, so their
	 * download access should continue until then. We re-check the subscription's
	 * live status here rather than trusting the action payload, since that payload
	 * carries the pre-update status snapshot.
	 */
	public function handle_subscription_cancelled( $subscription_id, $subscription_data, $reason = '' ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$subscription = Recurio_Subscription_Engine::get_instance()->get_subscription( $subscription_id );
		if ( ! $subscription || 'cancelled' !== $subscription->status ) {
			return;
		}

		// A paused subscription being cancelled may still have a pending grace-period
		// suspension scheduled — revoke supersedes it, so drop the stray job.
		wp_clear_scheduled_hook( self::GRACE_PERIOD_HOOK, array( $subscription_id ) );

		$this->revoke_download_access( $subscription_id, $subscription );
	}

	/**
	 * A subscription that runs out its renewal count / access window and expires is
	 * just as terminal as an immediate cancellation — no more billing will happen, so
	 * revoke the same way. Unlike pause, no grace period: by the time a subscription
	 * has actually reached 'expired' it has already run past whatever period it was
	 * last paid for.
	 */
	public function handle_subscription_expired( $subscription_id, $subscription_data ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$subscription = Recurio_Subscription_Engine::get_instance()->get_subscription( $subscription_id );
		if ( ! $subscription || 'expired' !== $subscription->status ) {
			return;
		}

		wp_clear_scheduled_hook( self::GRACE_PERIOD_HOOK, array( $subscription_id ) );

		$this->revoke_download_access( $subscription_id, $subscription );
	}

	/**
	 * Every WooCommerce order (initial + each renewal) independently gets its own
	 * downloadable-permission row(s) when payment_complete() runs on it, so we need
	 * every order tied to this subscription, not just the initial one.
	 */
	private function get_subscription_order_ids( $subscription ) {
		$order_ids = array();

		if ( ! empty( $subscription->wc_subscription_id ) ) {
			$order_ids[] = (int) $subscription->wc_subscription_id;
		}

		$renewal_order_ids = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'meta_key'   => '_recurio_subscription_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $subscription->id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array_unique( array_merge( $order_ids, array_map( 'intval', $renewal_order_ids ) ) );
	}

	/**
	 * Zero out downloads_remaining for every permission row tied to this subscription's
	 * orders, snapshotting the original values first (via the existing subscription
	 * events log) so resume can restore them exactly, including "unlimited" (null).
	 */
	private function suspend_download_access( $subscription_id ) {
		global $wpdb;

		$subscription = Recurio_Subscription_Engine::get_instance()->get_subscription( $subscription_id );
		if ( ! $subscription ) {
			return;
		}

		$order_ids = $this->get_subscription_order_ids( $subscription );
		if ( empty( $order_ids ) ) {
			return;
		}

		$table        = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$permissions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT permission_id, downloads_remaining FROM {$table} WHERE order_id IN ({$placeholders})",
				$order_ids
			)
		);

		if ( empty( $permissions ) ) {
			return;
		}

		$snapshot = array();
		foreach ( $permissions as $permission ) {
			$snapshot[] = array(
				'permission_id'       => (int) $permission->permission_id,
				'downloads_remaining' => $permission->downloads_remaining, // may be null (unlimited)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'downloads_remaining' => 0 ),
				array( 'permission_id' => $permission->permission_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		Recurio_Subscription_Engine::get_instance()->log_event( $subscription_id, 'download_access_suspended', null, $snapshot );
	}

	/**
	 * Restore whatever downloads_remaining values were snapshotted at the most
	 * recent suspend. Uses a raw UPDATE (rather than $wpdb->update()) so a
	 * snapshotted "unlimited" (null) value is written back as real SQL NULL.
	 */
	private function restore_download_access( $subscription_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT event_metadata FROM {$wpdb->prefix}recurio_subscription_events
				 WHERE subscription_id = %d AND event_type = 'download_access_suspended'
				 ORDER BY created_at DESC LIMIT 1",
				$subscription_id
			)
		);

		if ( ! $event || empty( $event->event_metadata ) ) {
			return;
		}

		$snapshot = json_decode( $event->event_metadata, true );
		if ( ! is_array( $snapshot ) ) {
			return;
		}

		$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

		foreach ( $snapshot as $entry ) {
			if ( empty( $entry['permission_id'] ) ) {
				continue;
			}

			$permission_id = (int) $entry['permission_id'];
			$remaining     = $entry['downloads_remaining'];

			if ( is_null( $remaining ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET downloads_remaining = NULL WHERE permission_id = %d", $permission_id ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET downloads_remaining = %d WHERE permission_id = %d", (int) $remaining, $permission_id ) );
			}
		}

		Recurio_Subscription_Engine::get_instance()->log_event( $subscription_id, 'download_access_restored' );
	}

	/**
	 * Permanently revoke: zero the remaining download count and expire access
	 * immediately. Unlike pause, this isn't meant to be restored.
	 */
	private function revoke_download_access( $subscription_id, $subscription ) {
		global $wpdb;

		$order_ids = $this->get_subscription_order_ids( $subscription );
		if ( empty( $order_ids ) ) {
			return;
		}

		$table        = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET downloads_remaining = 0, access_expires = %s WHERE order_id IN ({$placeholders})",
				array_merge( array( current_time( 'mysql' ) ), $order_ids )
			)
		);

		Recurio_Subscription_Engine::get_instance()->log_event( $subscription_id, 'download_access_revoked' );
	}
}
