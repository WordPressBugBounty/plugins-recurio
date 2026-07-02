<?php
namespace Recurio\Api;

use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Neutralize CSV formula injection by prefixing cells that start with
 * formula trigger characters (=, +, -, @, tab, CR).
 *
 * @param string $value Raw cell value.
 * @return string Safe cell value.
 */
function recurio_csv_safe( $value ) {
	if ( is_string( $value ) && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
		return "'" . $value;
	}
	return $value;
}

/**
 * Import / Export API
 *
 * Handles CSV export of subscriptions, customers, and revenue data,
 * plus the WooCommerce Subscriptions batch-import flow.
 *
 * Export routes:
 *   GET  /recurio/v1/export/subscriptions
 *   GET  /recurio/v1/export/customers
 *   GET  /recurio/v1/export/revenue        (Pro only)
 *
 * Import routes:
 *   GET  /recurio/v1/import/detect
 *   GET  /recurio/v1/import/preview
 *   POST /recurio/v1/import/start
 *   POST /recurio/v1/import/batch
 *   GET  /recurio/v1/import/status
 *
 * @package Recurio
 * @since   1.2.0
 */
class ImportExport {

	/** REST namespace. */
	private string $namespace = 'recurio/v1';

	/** WP option key that stores import progress. */
	const PROGRESS_OPTION = 'recurio_wcs_import_progress';

	// -------------------------------------------------------------------------
	// Routes
	// -------------------------------------------------------------------------

	public function register_routes(): void {

		// ── Export ────────────────────────────────────────────────────────────

		register_rest_route(
			$this->namespace,
			'/export/subscriptions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_subscriptions' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/export/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_customers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/export/revenue',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_revenue' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// ── Import ────────────────────────────────────────────────────────────

		register_rest_route(
			$this->namespace,
			'/import/detect',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'import_detect' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'import_preview' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_start' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_batch' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'skip_existing'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'import_history' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'import_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Export callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /export/subscriptions
	 * Streams a CSV of all subscriptions.
	 */
	public function export_subscriptions( $request ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$subscriptions = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe, no user input
			"SELECT
					s.*,
					u.display_name AS customer_name,
					u.user_email   AS customer_email,
					p.post_title   AS product_name
				FROM {$wpdb->prefix}recurio_subscriptions s
				LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
				LEFT JOIN {$wpdb->posts} p ON s.product_id  = p.ID
				ORDER BY s.created_at DESC"
		);

		$csv_lines   = array();
		$csv_lines[] = implode( ',', array(
			__( 'ID', 'recurio' ),
			__( 'Customer Name', 'recurio' ),
			__( 'Email', 'recurio' ),
			__( 'Product', 'recurio' ),
			__( 'Status', 'recurio' ),
			__( 'Amount', 'recurio' ),
			__( 'Billing Period', 'recurio' ),
			__( 'Billing Interval', 'recurio' ),
			__( 'Created Date', 'recurio' ),
			__( 'Next Payment Date', 'recurio' ),
			__( 'Trial End Date', 'recurio' ),
		) );

		foreach ( $subscriptions as $sub ) {
			$csv_lines[] = sprintf(
				'%d,"%s","%s","%s","%s",%s,"%s",%d,%s,%s,%s',
				$sub->id,
				str_replace( '"', '""', recurio_csv_safe( $sub->customer_name  ?: 'N/A' ) ),
				str_replace( '"', '""', recurio_csv_safe( $sub->customer_email ?: 'N/A' ) ),
				str_replace( '"', '""', recurio_csv_safe( $sub->product_name   ?: 'N/A' ) ),
				ucfirst( $sub->status ),
				number_format( $sub->billing_amount, 2, '.', '' ),
				ucfirst( $sub->billing_period ),
				$sub->billing_interval,
				$sub->created_at        ? gmdate( 'Y-m-d', strtotime( $sub->created_at ) )        : '',
				$sub->next_payment_date ? gmdate( 'Y-m-d', strtotime( $sub->next_payment_date ) ) : 'N/A',
				$sub->trial_end_date    ? gmdate( 'Y-m-d', strtotime( $sub->trial_end_date ) )    : 'N/A'
			);
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="subscriptions-' . gmdate( 'Y-m-d' ) . '.csv"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV output; values sanitized above.
		echo implode( "\r\n", $csv_lines );
		exit;
	}

	/**
	 * GET /export/customers
	 * Streams a CSV of all customers with subscription totals.
	 */
	public function export_customers( $request ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$customers = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe, no user input
			$wpdb->prepare(
				"SELECT
					u.ID,
					u.display_name  AS name,
					u.user_email    AS email,
					u.user_registered AS join_date,
					COUNT(DISTINCT s.id)                                          AS total_subscriptions,
					COUNT(DISTINCT CASE WHEN s.status = %s THEN s.id END)  AS active_subscriptions,
					COALESCE(SUM(s.billing_amount), 0)                            AS total_revenue
				FROM {$wpdb->users} u
				LEFT JOIN {$wpdb->prefix}recurio_subscriptions s ON u.ID = s.customer_id
				GROUP BY u.ID
				ORDER BY total_revenue DESC",
				'active'
			)
		);

		$csv_lines   = array();
		$csv_lines[] = implode( ',', array(
			__( 'ID', 'recurio' ),
			__( 'Name', 'recurio' ),
			__( 'Email', 'recurio' ),
			__( 'Join Date', 'recurio' ),
			__( 'Total Subscriptions', 'recurio' ),
			__( 'Active Subscriptions', 'recurio' ),
			__( 'Total Revenue', 'recurio' ),
		) );

		foreach ( $customers as $customer ) {
			$csv_lines[] = sprintf(
				'%d,"%s","%s",%s,%d,%d,%s',
				$customer->ID,
				str_replace( '"', '""', recurio_csv_safe( $customer->name  ?: 'N/A' ) ),
				str_replace( '"', '""', recurio_csv_safe( $customer->email ?: 'N/A' ) ),
				gmdate( 'Y-m-d', strtotime( $customer->join_date ) ),
				$customer->total_subscriptions  ?: 0,
				$customer->active_subscriptions ?: 0,
				number_format( $customer->total_revenue ?: 0, 2, '.', '' )
			);
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="customers-' . gmdate( 'Y-m-d' ) . '.csv"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV output; values sanitized above.
		echo implode( "\r\n", $csv_lines );
		exit;
	}

	/**
	 * GET /export/revenue  (Pro only)
	 * Streams a UTF-8 BOM CSV of all revenue transactions.
	 */
	public function export_revenue( $request ) {
		if ( ! recurio_is_pro_licensed() ) {
			return new WP_Error(
				'pro_feature_required',
				__( 'Transaction export is a Pro feature. Please upgrade to Recurio Pro to unlock this functionality.', 'recurio' ),
				array( 'status' => 403 )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revenue_data = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safe, no user input
			"SELECT
					r.*,
					s.customer_id,
					u.display_name AS customer_name,
					p.post_title   AS product_name
				FROM {$wpdb->prefix}recurio_subscription_revenue r
				LEFT JOIN {$wpdb->prefix}recurio_subscriptions s ON r.subscription_id = s.id
				LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
				LEFT JOIN {$wpdb->posts} p ON s.product_id  = p.ID
				ORDER BY r.created_at DESC"
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( 'php://temp', 'r+' );

		// UTF-8 BOM for Excel compatibility.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv( $output, array(
			__( 'Transaction ID', 'recurio' ),
			__( 'Date', 'recurio' ),
			__( 'Customer', 'recurio' ),
			__( 'Product', 'recurio' ),
			__( 'Amount', 'recurio' ),
			__( 'Currency', 'recurio' ),
			__( 'Payment Gateway', 'recurio' ),
			__( 'Period Type', 'recurio' ),
			__( 'Period Start', 'recurio' ),
			__( 'Period End', 'recurio' ),
		) );

		foreach ( $revenue_data as $revenue ) {
			fputcsv(
				$output,
				array(
					recurio_csv_safe( $revenue->transaction_id ?: 'N/A' ),
					$revenue->created_at   ? gmdate( 'Y-m-d H:i:s', strtotime( $revenue->created_at ) )  : '',
					recurio_csv_safe( $revenue->customer_name ?: 'N/A' ),
					recurio_csv_safe( $revenue->product_name  ?: 'N/A' ),
					number_format( $revenue->amount ?: 0, 2 ),
					$revenue->currency    ?: 'USD',
					$revenue->gateway     ?: 'N/A',
					$revenue->period_type ?: 'N/A',
					$revenue->period_start ? gmdate( 'Y-m-d', strtotime( $revenue->period_start ) ) : '',
					$revenue->period_end   ? gmdate( 'Y-m-d', strtotime( $revenue->period_end ) )   : '',
				)
			);
		}

		rewind( $output );
		$csv_content = stream_get_contents( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );

		return new WP_REST_Response(
			$csv_content,
			200,
			array(
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="revenue-' . gmdate( 'Y-m-d' ) . '.csv"',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Import callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /import/detect
	 */
	public function import_detect( $request ) {
		return rest_ensure_response( $this->detect_wc_subscriptions() );
	}

	/**
	 * GET /import/preview
	 */
	public function import_preview( $request ) {
		$detection = $this->detect_wc_subscriptions();

		if ( ! $detection['active'] ) {
			return new WP_REST_Response(
				array(
					'error'   => true,
					'message' => __( 'WooCommerce Subscriptions is not active', 'recurio' ),
				),
				400
			);
		}

		return rest_ensure_response( $this->get_wcs_subscriptions( 1, 5 ) );
	}

	/**
	 * POST /import/start
	 */
	public function import_start( $request ) {
		$detection = $this->detect_wc_subscriptions();

		if ( ! $detection['active'] ) {
			return new WP_REST_Response(
				array(
					'error'   => true,
					'message' => __( 'WooCommerce Subscriptions is not active', 'recurio' ),
				),
				400
			);
		}

		$progress = array(
			'status'     => 'running',
			'started_at' => current_time( 'mysql' ),
			'page'       => 0,
			'imported'   => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'total'      => $detection['subscription_count'],
		);

		update_option( self::PROGRESS_OPTION, $progress );

		return rest_ensure_response( $progress );
	}

	/**
	 * POST /import/batch
	 */
	public function import_batch( $request ) {
		$progress = get_option( self::PROGRESS_OPTION, array() );

		if ( empty( $progress ) || $progress['status'] !== 'running' ) {
			return new WP_REST_Response(
				array(
					'error'   => true,
					'message' => __( 'Import not started', 'recurio' ),
				),
				400
			);
		}

		$page   = $progress['page'] + 1;
		$result = $this->run_batch_import(
			$page,
			10,
			array(
				'skip_existing'  => (bool) $request->get_param( 'skip_existing' ),
				'import_history' => (bool) $request->get_param( 'import_history' ),
				'dry_run'        => false,
			)
		);

		$progress['page']      = $page;
		$progress['imported'] += $result['imported'];
		$progress['skipped']  += $result['skipped'];
		$progress['failed']   += $result['failed'];

		if ( ! $result['has_more'] ) {
			$progress['status']       = 'completed';
			$progress['completed_at'] = current_time( 'mysql' );
		}

		update_option( self::PROGRESS_OPTION, $progress );

		return rest_ensure_response(
			array_merge( $progress, array( 'batch_result' => $result ) )
		);
	}

	/**
	 * GET /import/status
	 */
	public function import_status( $request ) {
		$progress = get_option( self::PROGRESS_OPTION, array() );

		if ( empty( $progress ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'not_started',
					'message' => __( 'No import has been started', 'recurio' ),
				)
			);
		}

		return rest_ensure_response( $progress );
	}

	// -------------------------------------------------------------------------
	// WCS detection & data retrieval
	// -------------------------------------------------------------------------

	/**
	 * Check if WooCommerce Subscriptions is installed and active.
	 */
	private function detect_wc_subscriptions(): array {
		$result = array(
			'installed'          => false,
			'active'             => false,
			'version'            => null,
			'subscription_count' => 0,
			'active_count'       => 0,
			'paused_count'       => 0,
			'cancelled_count'    => 0,
		);

		if ( class_exists( 'WC_Subscriptions' ) ) {
			$result['installed'] = true;
			$result['active']    = true;

			if ( defined( 'WCS_VERSION' ) ) {
				$result['version'] = WCS_VERSION;
			}

			$result['subscription_count'] = $this->count_wcs_subscriptions();
			$result['active_count']       = $this->count_wcs_subscriptions( 'wc-active' );
			$result['paused_count']       = $this->count_wcs_subscriptions( 'wc-on-hold' );
			$result['cancelled_count']    = $this->count_wcs_subscriptions( 'wc-cancelled' );
		} else {
			$plugin_file = WP_PLUGIN_DIR . '/woocommerce-subscriptions/woocommerce-subscriptions.php';
			if ( file_exists( $plugin_file ) ) {
				$result['installed'] = true;
			}
		}

		return $result;
	}

	/**
	 * Count WooCommerce Subscriptions posts by status.
	 */
	private function count_wcs_subscriptions( string $status = '' ): int {
		global $wpdb;

		if ( $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_subscription' AND post_status = %s",
					$status
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'shop_subscription' )
			);
		}

		return intval( $count );
	}

	/**
	 * Fetch a page of WCS subscriptions.
	 */
	private function get_wcs_subscriptions( int $page = 1, int $per_page = 50, string $status = '' ): array {
		$args = array(
			'post_type'      => 'shop_subscription',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'post_status'    => $status ?: array( 'wc-active', 'wc-on-hold', 'wc-pending', 'wc-pending-cancel', 'wc-expired', 'wc-cancelled' ),
		);

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
	 * Build a normalised data array from a single WCS subscription.
	 */
	private function get_wcs_subscription_data( int $subscription_id ): ?array {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return null;
		}

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
			'id'               => $subscription_id,
			'status'           => $subscription->get_status(),
			'customer_id'      => $subscription->get_customer_id(),
			'billing_period'   => $subscription->get_billing_period(),
			'billing_interval' => $subscription->get_billing_interval(),
			'start_date'       => $subscription->get_date( 'start' ),
			'next_payment_date' => $subscription->get_date( 'next_payment' ),
			'end_date'         => $subscription->get_date( 'end' ),
			'trial_end_date'   => $subscription->get_date( 'trial_end' ),
			'total'            => $subscription->get_total(),
			'payment_method'   => $subscription->get_payment_method(),
			'items'            => $items,
			'billing_address'  => $subscription->get_address( 'billing' ),
			'shipping_address' => $subscription->get_address( 'shipping' ),
		);
	}

	// -------------------------------------------------------------------------
	// Batch import logic
	// -------------------------------------------------------------------------

	/**
	 * Run one batch of imports and return counts.
	 */
	private function run_batch_import( int $page, int $per_page, array $options ): array {
		$result   = $this->get_wcs_subscriptions( $page, $per_page );
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
			'imported'     => $imported,
			'skipped'      => $skipped,
			'failed'       => $failed,
			'errors'       => $errors,
			'total'        => $result['total'],
			'pages'        => $result['pages'],
			'current_page' => $page,
			'has_more'     => $page < $result['pages'],
		);
	}

	/**
	 * Import a single WCS subscription into Recurio.
	 *
	 * @return array|WP_Error
	 */
	private function import_subscription( int $wcs_subscription_id, array $options ) {
		$options = wp_parse_args(
			$options,
			array(
				'skip_existing'  => true,
				'import_history' => true,
				'dry_run'        => false,
			)
		);

		$wcs_data = $this->get_wcs_subscription_data( $wcs_subscription_id );

		if ( ! $wcs_data ) {
			return new WP_Error( 'not_found', __( 'WooCommerce Subscription not found', 'recurio' ) );
		}

		if ( $options['skip_existing'] ) {
			$existing = $this->find_existing_subscription( $wcs_subscription_id );
			if ( $existing ) {
				return array(
					'status'          => 'skipped',
					'message'         => __( 'Already imported', 'recurio' ),
					'subscription_id' => $existing,
				);
			}
		}

		if ( $options['dry_run'] ) {
			return array(
				'status' => 'preview',
				'data'   => $this->prepare_recurio_data( $wcs_data ),
			);
		}

		$recurio_data        = $this->prepare_recurio_data( $wcs_data );
		$subscription_engine = \Recurio_Subscription_Engine::get_instance();
		$subscription_id     = $subscription_engine->create_subscription( $recurio_data );

		if ( is_wp_error( $subscription_id ) ) {
			return $subscription_id;
		}

		// Store back-reference to the original WCS ID.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'recurio_subscriptions',
			array(
				'subscription_metadata' => wp_json_encode(
					array_merge(
						json_decode( $recurio_data['subscription_metadata'], true ) ?: array(),
						array( 'imported_from_wcs' => $wcs_subscription_id )
					)
				),
			),
			array( 'id' => $subscription_id )
		);

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
	 * Build the Recurio subscription data array from WCS data.
	 */
	private function prepare_recurio_data( array $wcs_data ): array {
		$product_id     = 0;
		$billing_amount = 0;

		if ( ! empty( $wcs_data['items'] ) ) {
			$first_item     = reset( $wcs_data['items'] );
			$product_id     = $first_item['product_id'];
			$billing_amount = floatval( $wcs_data['total'] );
		}

		$metadata = array(
			'imported_from' => 'woocommerce_subscriptions',
			'original_id'   => $wcs_data['id'],
			'import_date'   => current_time( 'mysql' ),
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
	 * Find an existing Recurio subscription previously imported from WCS.
	 */
	private function find_existing_subscription( int $wcs_subscription_id ): ?int {
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
	 * Import renewal and initial order payment history from a WCS subscription.
	 */
	private function import_payment_history( int $wcs_subscription_id, int $recurio_subscription_id ): int {
		$subscription = wcs_get_subscription( $wcs_subscription_id );

		if ( ! $subscription ) {
			return 0;
		}

		$subscription_engine = \Recurio_Subscription_Engine::get_instance();
		$imported_count      = 0;

		foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order || ! $order->is_paid() ) {
				continue;
			}

			$subscription_engine->log_revenue(
				$recurio_subscription_id,
				$order->get_total(),
				$order->get_payment_method(),
				$order->get_transaction_id(),
				$order->get_date_paid() ? $order->get_date_paid()->format( 'Y-m-d H:i:s' ) : null
			);

			++$imported_count;
		}

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

	// -------------------------------------------------------------------------
	// Status mapping helpers
	// -------------------------------------------------------------------------

	private function map_status( string $wcs_status ): string {
		$map = array(
			'active'            => 'active',
			'wc-active'         => 'active',
			'on-hold'           => 'paused',
			'wc-on-hold'        => 'paused',
			'pending'           => 'pending',
			'wc-pending'        => 'pending',
			'pending-cancel'    => 'pending_cancellation',
			'wc-pending-cancel' => 'pending_cancellation',
			'cancelled'         => 'cancelled',
			'wc-cancelled'      => 'cancelled',
			'expired'           => 'completed',
			'wc-expired'        => 'completed',
		);

		return $map[ $wcs_status ] ?? 'pending';
	}

	private function map_billing_period( string $wcs_period ): string {
		$map = array(
			'day'   => 'day',
			'week'  => 'week',
			'month' => 'month',
			'year'  => 'year',
		);

		return $map[ $wcs_period ] ?? 'month';
	}

	// -------------------------------------------------------------------------
	// Permission
	// -------------------------------------------------------------------------

	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}
}
