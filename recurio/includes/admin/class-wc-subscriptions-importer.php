<?php
/**
 * WooCommerce Subscriptions Importer
 *
 * Imports subscriptions from WooCommerce Subscriptions plugin to Recurio
 *
 * @package Recurio
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_WC_Subscriptions_Importer {

	/**
	 * Singleton instance
	 */
	private static $instance = null;

	/**
	 * Import progress option name
	 */
	const PROGRESS_OPTION = 'recurio_wcs_import_progress';

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// AJAX handlers
		add_action( 'wp_ajax_recurio_detect_wcs', array( $this, 'ajax_detect_wcs' ) );
		add_action( 'wp_ajax_recurio_import_preview', array( $this, 'ajax_import_preview' ) );
		add_action( 'wp_ajax_recurio_import_start', array( $this, 'ajax_import_start' ) );
		add_action( 'wp_ajax_recurio_import_batch', array( $this, 'ajax_import_batch' ) );
		add_action( 'wp_ajax_recurio_import_status', array( $this, 'ajax_import_status' ) );
	}

	/**
	 * Check if WooCommerce Subscriptions is installed and active
	 */
	public function detect_wc_subscriptions() {
		$result = array(
			'installed'          => false,
			'active'             => false,
			'version'            => null,
			'subscription_count' => 0,
			'active_count'       => 0,
			'paused_count'       => 0,
			'cancelled_count'    => 0,
		);

		// Check if WooCommerce Subscriptions class exists
		if ( class_exists( 'WC_Subscriptions' ) ) {
			$result['installed'] = true;
			$result['active']    = true;

			// Get version
			if ( defined( 'WCS_VERSION' ) ) {
				$result['version'] = WCS_VERSION;
			}

			// Count subscriptions
			$result['subscription_count'] = $this->count_wcs_subscriptions();
			$result['active_count']       = $this->count_wcs_subscriptions( 'wc-active' );
			$result['paused_count']       = $this->count_wcs_subscriptions( 'wc-on-hold' );
			$result['cancelled_count']    = $this->count_wcs_subscriptions( 'wc-cancelled' );
		} else {
			// Check if plugin files exist but not active
			$plugin_file = WP_PLUGIN_DIR . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
			if ( file_exists( $plugin_file ) ) {
				$result['installed'] = true;
			}
		}

		return $result;
	}

	/**
	 * Count WooCommerce Subscriptions
	 */
	private function count_wcs_subscriptions( $status = null ) {
		global $wpdb;

		$where = "WHERE post_type = 'shop_subscription'";
		if ( $status ) {
			$where .= $wpdb->prepare( ' AND post_status = %s', $status );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} {$where}" );

		return intval( $count );
	}

	/**
	 * Get WooCommerce Subscriptions for import
	 */
	public function get_wcs_subscriptions( $page = 1, $per_page = 50, $status = null ) {
		$args = array(
			'post_type'      => 'shop_subscription',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( $status ) {
			$args['post_status'] = $status;
		} else {
			$args['post_status'] = array( 'wc-active', 'wc-on-hold', 'wc-pending', 'wc-pending-cancel', 'wc-expired', 'wc-cancelled' );
		}

		$query         = new WP_Query( $args );
		$subscriptions = array();

		foreach ( $query->posts as $post ) {
			$subscriptions[] = $this->get_wcs_subscription_data( $post->ID );
		}

		return array(
			'subscriptions' => $subscriptions,
			'total'         => $query->found_posts,
			'pages'         => $query->max_num_pages,
			'current_page'  => $page,
		);
	}

	/**
	 * Get WooCommerce Subscription data
	 */
	private function get_wcs_subscription_data( $subscription_id ) {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return null;
		}

		// Get line items
		$items = array();
		foreach ( $subscription->get_items() as $item ) {
			$items[] = array(
				'product_id'   => $item->get_product_id(),
				'product_name' => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'total'        => $item->get_total(),
			);
		}

		return array(
			'id'                 => $subscription_id,
			'status'             => $subscription->get_status(),
			'customer_id'        => $subscription->get_customer_id(),
			'billing_period'     => $subscription->get_billing_period(),
			'billing_interval'   => $subscription->get_billing_interval(),
			'start_date'         => $subscription->get_date( 'start' ),
			'next_payment_date'  => $subscription->get_date( 'next_payment' ),
			'end_date'           => $subscription->get_date( 'end' ),
			'trial_end_date'     => $subscription->get_date( 'trial_end' ),
			'total'              => $subscription->get_total(),
			'payment_method'     => $subscription->get_payment_method(),
			'items'              => $items,
			'billing_address'    => $subscription->get_address( 'billing' ),
			'shipping_address'   => $subscription->get_address( 'shipping' ),
		);
	}

	/**
	 * Map WooCommerce Subscription status to Recurio status
	 */
	private function map_status( $wcs_status ) {
		$status_map = array(
			'active'         => 'active',
			'wc-active'      => 'active',
			'on-hold'        => 'paused',
			'wc-on-hold'     => 'paused',
			'pending'        => 'pending',
			'wc-pending'     => 'pending',
			'pending-cancel' => 'pending_cancellation',
			'wc-pending-cancel' => 'pending_cancellation',
			'cancelled'      => 'cancelled',
			'wc-cancelled'   => 'cancelled',
			'expired'        => 'completed',
			'wc-expired'     => 'completed',
		);

		return isset( $status_map[ $wcs_status ] ) ? $status_map[ $wcs_status ] : 'pending';
	}

	/**
	 * Map billing period
	 */
	private function map_billing_period( $wcs_period ) {
		$period_map = array(
			'day'   => 'day',
			'week'  => 'week',
			'month' => 'month',
			'year'  => 'year',
		);

		return isset( $period_map[ $wcs_period ] ) ? $period_map[ $wcs_period ] : 'month';
	}

	/**
	 * Import a single WooCommerce Subscription to Recurio
	 */
	public function import_subscription( $wcs_subscription_id, $options = array() ) {
		$defaults = array(
			'skip_existing'   => true,
			'import_history'  => true,
			'dry_run'         => false,
		);
		$options = wp_parse_args( $options, $defaults );

		// Get WCS subscription data
		$wcs_data = $this->get_wcs_subscription_data( $wcs_subscription_id );

		if ( ! $wcs_data ) {
			return new WP_Error( 'not_found', __( 'WooCommerce Subscription not found', 'recurio' ) );
		}

		// Check if already imported
		if ( $options['skip_existing'] ) {
			$existing = $this->find_existing_subscription( $wcs_subscription_id );
			if ( $existing ) {
				return array(
					'status'  => 'skipped',
					'message' => __( 'Already imported', 'recurio' ),
					'subscription_id' => $existing,
				);
			}
		}

		// If dry run, return what would be imported
		if ( $options['dry_run'] ) {
			return array(
				'status'  => 'preview',
				'data'    => $this->prepare_recurio_data( $wcs_data ),
			);
		}

		// Create Recurio subscription
		$recurio_data = $this->prepare_recurio_data( $wcs_data );

		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$subscription_id     = $subscription_engine->create_subscription( $recurio_data );

		if ( is_wp_error( $subscription_id ) ) {
			return $subscription_id;
		}

		// Store reference to original WCS subscription
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'recurio_subscriptions',
			array( 'subscription_metadata' => wp_json_encode( array_merge(
				json_decode( $recurio_data['subscription_metadata'], true ) ?: array(),
				array( 'imported_from_wcs' => $wcs_subscription_id )
			) ) ),
			array( 'id' => $subscription_id )
		);

		// Import payment history if requested
		if ( $options['import_history'] ) {
			$this->import_payment_history( $wcs_subscription_id, $subscription_id );
		}

		return array(
			'status'          => 'imported',
			'subscription_id' => $subscription_id,
			'wcs_id'          => $wcs_subscription_id,
		);
	}

	/**
	 * Prepare Recurio subscription data from WCS data
	 */
	private function prepare_recurio_data( $wcs_data ) {
		// Get the first product from items
		$product_id     = 0;
		$billing_amount = 0;

		if ( ! empty( $wcs_data['items'] ) ) {
			$first_item     = reset( $wcs_data['items'] );
			$product_id     = $first_item['product_id'];
			$billing_amount = floatval( $wcs_data['total'] );
		}

		$metadata = array(
			'imported_from'     => 'woocommerce_subscriptions',
			'original_id'       => $wcs_data['id'],
			'import_date'       => current_time( 'mysql' ),
		);

		return array(
			'customer_id'           => $wcs_data['customer_id'],
			'product_id'            => $product_id,
			'wc_subscription_id'    => $wcs_data['id'],
			'status'                => $this->map_status( $wcs_data['status'] ),
			'billing_period'        => $this->map_billing_period( $wcs_data['billing_period'] ),
			'billing_interval'      => intval( $wcs_data['billing_interval'] ) ?: 1,
			'billing_amount'        => $billing_amount,
			'payment_method'        => $wcs_data['payment_method'],
			'trial_end_date'        => $wcs_data['trial_end_date'] ?: null,
			'next_payment_date'     => $wcs_data['next_payment_date'] ?: null,
			'billing_address'       => wp_json_encode( $wcs_data['billing_address'] ),
			'shipping_address'      => wp_json_encode( $wcs_data['shipping_address'] ),
			'subscription_metadata' => wp_json_encode( $metadata ),
			'created_at'            => $wcs_data['start_date'] ?: current_time( 'mysql' ),
			'updated_at'            => current_time( 'mysql' ),
		);
	}

	/**
	 * Find existing Recurio subscription imported from WCS
	 */
	private function find_existing_subscription( $wcs_subscription_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$subscription_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}recurio_subscriptions
				WHERE wc_subscription_id = %d
				OR subscription_metadata LIKE %s",
				$wcs_subscription_id,
				'%"imported_from_wcs":' . $wcs_subscription_id . '%'
			)
		);

		return $subscription_id ? intval( $subscription_id ) : null;
	}

	/**
	 * Import payment history from WCS to Recurio
	 */
	private function import_payment_history( $wcs_subscription_id, $recurio_subscription_id ) {
		$subscription = wcs_get_subscription( $wcs_subscription_id );

		if ( ! $subscription ) {
			return false;
		}

		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$imported_count      = 0;

		// Get related orders (renewal orders)
		$related_orders = $subscription->get_related_orders( 'all', 'renewal' );

		foreach ( $related_orders as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order || ! $order->is_paid() ) {
				continue;
			}

			// Log revenue for each paid renewal order
			$subscription_engine->log_revenue(
				$recurio_subscription_id,
				$order->get_total(),
				$order->get_payment_method(),
				$order->get_transaction_id(),
				$order->get_date_paid() ? $order->get_date_paid()->format( 'Y-m-d H:i:s' ) : null
			);

			++$imported_count;
		}

		// Also import the initial order
		$parent_order_id = $subscription->get_parent_id();
		if ( $parent_order_id ) {
			$parent_order = wc_get_order( $parent_order_id );
			if ( $parent_order && $parent_order->is_paid() ) {
				$subscription_engine->log_revenue(
					$recurio_subscription_id,
					$parent_order->get_total(),
					$parent_order->get_payment_method(),
					$parent_order->get_transaction_id(),
					$parent_order->get_date_paid() ? $parent_order->get_date_paid()->format( 'Y-m-d H:i:s' ) : null
				);

				++$imported_count;
			}
		}

		return $imported_count;
	}

	/**
	 * Run batch import
	 */
	public function run_batch_import( $page = 1, $per_page = 10, $options = array() ) {
		$result = $this->get_wcs_subscriptions( $page, $per_page );

		$imported = 0;
		$skipped  = 0;
		$failed   = 0;
		$errors   = array();

		foreach ( $result['subscriptions'] as $wcs_subscription ) {
			if ( ! $wcs_subscription ) {
				continue;
			}

			$import_result = $this->import_subscription( $wcs_subscription['id'], $options );

			if ( is_wp_error( $import_result ) ) {
				++$failed;
				$errors[] = array(
					'id'    => $wcs_subscription['id'],
					'error' => $import_result->get_error_message(),
				);
			} elseif ( $import_result['status'] === 'skipped' ) {
				++$skipped;
			} else {
				++$imported;
			}
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'errors'   => $errors,
			'total'    => $result['total'],
			'pages'    => $result['pages'],
			'current_page' => $page,
			'has_more' => $page < $result['pages'],
		);
	}

	/**
	 * AJAX: Detect WooCommerce Subscriptions
	 */
	public function ajax_detect_wcs() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'recurio' ) );
		}

		$result = $this->detect_wc_subscriptions();
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Import preview
	 */
	public function ajax_import_preview() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'recurio' ) );
		}

		$result = $this->get_wcs_subscriptions( 1, 5 );

		// Add preview data for each subscription
		foreach ( $result['subscriptions'] as &$subscription ) {
			if ( $subscription ) {
				$subscription['recurio_preview'] = $this->prepare_recurio_data( $subscription );
				$subscription['already_imported'] = (bool) $this->find_existing_subscription( $subscription['id'] );
			}
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Start import
	 */
	public function ajax_import_start() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'recurio' ) );
		}

		// Reset progress
		$progress = array(
			'status'     => 'running',
			'started_at' => current_time( 'mysql' ),
			'page'       => 0,
			'imported'   => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'total'      => $this->count_wcs_subscriptions(),
		);

		update_option( self::PROGRESS_OPTION, $progress );

		wp_send_json_success( $progress );
	}

	/**
	 * AJAX: Import batch
	 */
	public function ajax_import_batch() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'recurio' ) );
		}

		$progress = get_option( self::PROGRESS_OPTION, array() );

		if ( empty( $progress ) || $progress['status'] !== 'running' ) {
			wp_send_json_error( __( 'Import not started', 'recurio' ) );
		}

		$page = $progress['page'] + 1;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$options = array(
			'skip_existing'  => isset( $_POST['skip_existing'] ) && $_POST['skip_existing'] === 'true',
			'import_history' => isset( $_POST['import_history'] ) && $_POST['import_history'] === 'true',
			'dry_run'        => false,
		);

		$result = $this->run_batch_import( $page, 10, $options );

		// Update progress
		$progress['page']     = $page;
		$progress['imported'] += $result['imported'];
		$progress['skipped']  += $result['skipped'];
		$progress['failed']   += $result['failed'];

		if ( ! $result['has_more'] ) {
			$progress['status']       = 'completed';
			$progress['completed_at'] = current_time( 'mysql' );
		}

		update_option( self::PROGRESS_OPTION, $progress );

		wp_send_json_success( array_merge( $progress, array(
			'batch_result' => $result,
		) ) );
	}

	/**
	 * AJAX: Get import status
	 */
	public function ajax_import_status() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'recurio' ) );
		}

		$progress = get_option( self::PROGRESS_OPTION, array() );
		wp_send_json_success( $progress );
	}

	/**
	 * Generate import report
	 */
	public function generate_report() {
		$progress = get_option( self::PROGRESS_OPTION, array() );

		if ( empty( $progress ) ) {
			return null;
		}

		return array(
			'status'           => $progress['status'],
			'started_at'       => $progress['started_at'],
			'completed_at'     => isset( $progress['completed_at'] ) ? $progress['completed_at'] : null,
			'total_processed'  => $progress['imported'] + $progress['skipped'] + $progress['failed'],
			'imported'         => $progress['imported'],
			'skipped'          => $progress['skipped'],
			'failed'           => $progress['failed'],
			'total_available'  => $progress['total'],
		);
	}
}
