<?php
/**
 * Customer Portal frontend class
 *
 * Handles customer portal shortcode, My Account integration,
 * and AJAX endpoints for subscription management.
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer Portal frontend class
 *
 * Manages the customer portal functionality including shortcodes,
 * WooCommerce My Account integration, and AJAX endpoints for
 * subscription management operations.
 *
 * @since 1.0.0
 */
class Recurio_Customer_Portal {
	/**
	 * Single instance of the class
	 *
	 * @var Recurio_Customer_Portal|null
	 */
	private static $instance = null;

	/**
	 * Get single instance of the class
	 *
	 * @return Recurio_Customer_Portal
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - sets up hooks and actions based on portal location setting
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Get portal location setting.
		$settings        = get_option( 'recurio_settings', array() );
		$portal_location = isset( $settings['general']['portalLocation'] ) ? $settings['general']['portalLocation'] : 'standalone';

		// Always register AJAX handlers.
		add_action( 'wp_ajax_recurio_update_subscription', array( $this, 'ajax_update_subscription' ) );
		add_action( 'wp_ajax_recurio_cancel_subscription', array( $this, 'ajax_cancel_subscription' ) );
		add_action( 'wp_ajax_recurio_pause_subscription', array( $this, 'ajax_pause_subscription' ) );
		add_action( 'wp_ajax_recurio_resume_subscription', array( $this, 'ajax_resume_subscription' ) );
		add_action( 'wp_ajax_recurio_update_payment_method', array( $this, 'ajax_update_payment_method' ) );
		add_action( 'wp_ajax_recurio_early_renewal', array( $this, 'ajax_early_renewal' ) );
		add_action( 'wp_ajax_recurio_pay_installment', array( $this, 'ajax_pay_installment' ) );

		if ( 'myaccount' === $portal_location ) {
			// Get custom endpoint from settings.
			$endpoint = isset( $settings['general']['myAccountEndpoint'] ) && ! empty( $settings['general']['myAccountEndpoint'] )
				? $settings['general']['myAccountEndpoint']
				: 'subscriptions';

			// Register WooCommerce My Account endpoint.
			add_action( 'init', array( $this, 'add_my_account_endpoint' ) );
			add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
			add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_items' ) );
			add_action( 'woocommerce_account_' . $endpoint . '_endpoint', array( $this, 'render_my_account_content' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts_my_account' ) );
		} else {
			// Register shortcode for standalone page.
			add_action( 'init', array( $this, 'register_shortcodes' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}
	}

	/**
	 * Register shortcodes for the customer portal
	 *
	 * @since 1.0.0
	 */
	public function register_shortcodes() {
		add_shortcode( 'recurio_customer_portal', array( $this, 'render_customer_portal' ) );
	}

	/**
	 * Enqueue scripts and styles for the customer portal
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		if ( is_page() && has_shortcode( get_post()->post_content, 'recurio_customer_portal' ) ) {

			wp_enqueue_style( 'recurio-customer-portal', RECURIO_PLUGIN_URL . 'assets/css/customer-portal.css', array(), RECURIO_VERSION );
			wp_enqueue_script( 'recurio-customer-portal', RECURIO_PLUGIN_URL . 'assets/js/customer-portal.js', array( 'jquery' ), RECURIO_VERSION, true );

			wp_localize_script(
				'recurio-customer-portal',
				'recurio_portal',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'recurio_portal_nonce' ),
					'strings'  => array(
						'processing' => __( 'Processing...', 'recurio' ),
						'error'      => __( 'An error occurred. Please try again.', 'recurio' ),
					),
				)
			);
		}
	}

	/**
	 * Render the customer portal shortcode
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Portal HTML output.
	 * @since 1.0.0
	 */
	public function render_customer_portal( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="recurio-portal-notice">' . esc_html__( 'Please log in to view your subscriptions.', 'recurio' ) . '</div>';
		}

		$atts = shortcode_atts(
			array(
				'show_cancelled' => 'no',
			),
			$atts
		);

		// Check if we're viewing a specific subscription.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- URL parameters for display purposes only
		$view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'dashboard';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- URL parameters for display purposes only
		$subscription_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;

		ob_start();

		if ( 'subscription' === $view && $subscription_id ) {
			// Show subscription detail view.
			$subscription = $this->get_subscription( $subscription_id );

			if ( ! $subscription || get_current_user_id() != $subscription->customer_id ) {
				echo '<div class="recurio-portal-notice">' . esc_html__( 'You do not have permission to view this subscription.', 'recurio' ) . '</div>';
			} else {
				$this->load_template(
					'customer-portal/subscription-detail',
					array(
						'subscription'      => $subscription,
						'payment_history'   => $this->get_payment_history( $subscription_id ),
						'available_actions' => $this->get_available_actions( $subscription ),
						'portal_url'        => remove_query_arg( array( 'view', 'id' ) ),
					)
				);
			}
		} else {
			// Show dashboard view.
			$this->load_template(
				'customer-portal/dashboard',
				array(
					'customer_id'    => get_current_user_id(),
					'show_cancelled' => 'yes' === $atts['show_cancelled'],
				)
			);
		}

		return ob_get_clean();
	}

	/**
	 * Get customer subscriptions from database
	 *
	 * @param int    $customer_id Customer user ID.
	 * @param string $status      Subscription status filter.
	 * @param int    $limit       Number of results to return.
	 * @return array Array of subscription objects.
	 * @since 1.0.0
	 */
	private function get_customer_subscriptions( $customer_id, $status = 'all', $limit = 10 ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		if ( 'all' !== $status ) {
			$subscriptions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}recurio_subscriptions
                WHERE customer_id = %d AND status = %s
                ORDER BY created_at DESC
                LIMIT %d",
					$customer_id,
					$status,
					$limit
				)
			);
		} else {
			$subscriptions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}recurio_subscriptions
                WHERE customer_id = %d
                ORDER BY created_at DESC
                LIMIT %d",
					$customer_id,
					$limit
				)
			);
		}

		// Extract payment method from metadata for each subscription.
		foreach ( $subscriptions as $subscription ) {
			if ( ! empty( $subscription->subscription_metadata ) ) {
				$metadata = json_decode( $subscription->subscription_metadata, true );
				if ( isset( $metadata['payment_method_title'] ) ) {
					$subscription->payment_method = $metadata['payment_method_title'];
				} elseif ( isset( $metadata['payment_method'] ) ) {
					$subscription->payment_method = $metadata['payment_method'];
				} else {
					$subscription->payment_method = null;
				}
			} else {
				$subscription->payment_method = null;
			}
		}

		return $subscriptions;
	}

	/**
	 * Get single subscription by ID
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return object|null Subscription object or null if not found.
	 * @since 1.0.0
	 */
	private function get_subscription( $subscription_id ) {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscriptions WHERE id = %d",
				$subscription_id
			)
		);

		if ( $subscription ) {
			// Use dedicated payment_method column first.
			if ( ! empty( $subscription->payment_method ) ) {
				// Try to get human-readable title from metadata.
				if ( ! empty( $subscription->subscription_metadata ) ) {
					$metadata = json_decode( $subscription->subscription_metadata, true );
					if ( isset( $metadata['payment_method_title'] ) ) {
						$subscription->payment_method_display = $metadata['payment_method_title'];
					} else {
						$subscription->payment_method_display = $subscription->payment_method;
					}
				} else {
					$subscription->payment_method_display = $subscription->payment_method;
				}
			} elseif ( ! empty( $subscription->subscription_metadata ) ) {
				// Fallback to metadata for older subscriptions.
					$metadata = json_decode( $subscription->subscription_metadata, true );
				if ( isset( $metadata['payment_method_title'] ) ) {
					$subscription->payment_method_display = $metadata['payment_method_title'];
					$subscription->payment_method         = $metadata['payment_method'] ?? null;
				} elseif ( isset( $metadata['payment_method'] ) ) {
					$subscription->payment_method         = $metadata['payment_method'];
					$subscription->payment_method_display = $metadata['payment_method'];
				} else {
					$subscription->payment_method_display = null;
				}
			} else {
				$subscription->payment_method_display = null;
			}
		}

		return $subscription;
	}

	/**
	 * Get payment history for a subscription
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return array Array of payment objects.
	 * @since 1.0.0
	 */
	private function get_payment_history( $subscription_id ) {
		global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal management
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time payment history data
		$payments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscription_revenue
             WHERE subscription_id = %d
             ORDER BY created_at DESC
             LIMIT 10",
				$subscription_id
			)
		);

		// Format payment method for display.
		foreach ( $payments as $payment ) {
			// If payment_method is empty or 'Manual', use a more descriptive text.
			if ( empty( $payment->payment_method ) || 'Manual' === $payment->payment_method ) {
				// Try to get from subscription metadata for the first payment.
				$subscription = $this->get_subscription( $subscription_id );
				if ( $subscription && $subscription->payment_method ) {
					$payment->payment_method = $subscription->payment_method;
				} else {
					$payment->payment_method = __( 'Manual', 'recurio' );
				}
			}
		}

		return $payments;
	}

	/**
	 * Get available actions for a subscription based on its status
	 *
	 * @param object $subscription Subscription object.
	 * @return array Array of available action strings.
	 * @since 1.0.0
	 */
	private function get_available_actions( $subscription ) {
		$actions = array();

		// Check if early renewal is enabled in settings.
		$settings              = get_option( 'recurio_settings', array() );
		$early_renewal_enabled = isset( $settings['general']['enableEarlyRenewal'] ) ? $settings['general']['enableEarlyRenewal'] : true;

		// Check if this is a split payment subscription with remaining payments.
		$is_split_payment     = isset( $subscription->payment_type ) && $subscription->payment_type === 'split';
		$max_payments         = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;
		$payments_made        = isset( $subscription->renewal_count ) ? intval( $subscription->renewal_count ) : 0;
		$has_remaining_payments = $is_split_payment && $max_payments > 0 && $payments_made < $max_payments;

		// Subscription switching is a PRO feature - handled via recurio_portal_actions filter

		switch ( $subscription->status ) {
			case 'active':
				$actions[] = 'pause';
				$actions[] = 'cancel';
				$actions[] = 'update_payment';
				if ( $early_renewal_enabled && ! $is_split_payment ) {
					$actions[] = 'early_renewal';
				}
				// Add pay installment for split payments with remaining payments.
				if ( $has_remaining_payments ) {
					$actions[] = 'pay_installment';
				}
				break;
			case 'trial':
				$actions[] = 'cancel';
				if ( $early_renewal_enabled ) {
					$actions[] = 'early_renewal';
				}
				break;
			case 'paused':
				$actions[] = 'resume';
				$actions[] = 'cancel';
				break;
			case 'pending_cancellation':
				$actions[] = 'reactivate';
				break;
			case 'pending_payment':
				// For split payments waiting for full payment.
				$actions[] = 'cancel';
				if ( $has_remaining_payments ) {
					$actions[] = 'pay_installment';
				}
				break;
		}

		/**
		 * Allow Pro plugin to add additional portal actions.
		 *
		 * Pro can use this hook to add custom actions like:
		 * - 'upgrade' - Upgrade subscription plan
		 * - 'downgrade' - Downgrade subscription plan
		 * - 'add_addon' - Add subscription add-ons
		 * - 'skip_renewal' - Skip next renewal
		 * - 'change_frequency' - Change billing frequency
		 *
		 * @since 1.1.0
		 * @param array  $actions      Array of action strings
		 * @param object $subscription Subscription object
		 */
		$actions = apply_filters( 'recurio_portal_actions', $actions, $subscription );

		return $actions;
	}

	/**
	 * Handle AJAX request to update subscription details
	 *
	 * @since 1.0.0
	 */
	public function ajax_update_subscription() {
		check_ajax_referer( 'recurio_portal_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in' );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		$subscription    = $this->get_subscription( $subscription_id );

		if ( ! $subscription || get_current_user_id() != $subscription->customer_id ) {
			wp_send_json_error( 'Invalid subscription' );
		}

		$allowed_fields = array( 'shipping_address', 'billing_address' );
		$update_data    = array();

		foreach ( $allowed_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$update_data[ $field ] = sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) );
			}
		}

		if ( empty( $update_data ) ) {
			wp_send_json_error( 'No data to update' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal management
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$result = $wpdb->update(
			"{$wpdb->prefix}recurio_subscriptions",
			$update_data,
			array( 'id' => $subscription_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			wp_send_json_success( 'Subscription updated successfully' );
		} else {
			wp_send_json_error( 'Failed to update subscription' );
		}
	}

	/**
	 * Handle AJAX request to cancel subscription
	 *
	 * @since 1.0.0
	 */
	public function ajax_cancel_subscription() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'recurio_portal_nonce' ) ) {
			wp_die( '-1' );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in' );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Simple sanitization sufficient for dropdown value
		$cancel_at = sanitize_text_field( $_POST['cancel_at'] ?? 'end_of_period' );

		$subscription = $this->get_subscription( $subscription_id );

		if ( ! $subscription || get_current_user_id() != $subscription->customer_id ) {

			wp_send_json_error( 'Invalid subscription' );
		}

		$engine = Recurio_Subscription_Engine::get_instance();
		// cancel_subscription expects ($subscription_id, $reason, $timing).
		$result = $engine->cancel_subscription( $subscription_id, '', $cancel_at );

		if ( $result && ! is_wp_error( $result ) ) {
			wp_send_json_success( 'Subscription cancelled successfully' );
		} else {
			$error_message = is_wp_error( $result ) ? $result->get_error_message() : 'Failed to cancel subscription';
			wp_send_json_error( $error_message );
		}
	}

	/**
	 * Handle AJAX request to pause subscription
	 *
	 * @since 1.0.0
	 */
	public function ajax_pause_subscription() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'recurio_portal_nonce' ) ) {
			wp_die( '-1' );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in' );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		$subscription    = $this->get_subscription( $subscription_id );

		if ( ! $subscription || get_current_user_id() != $subscription->customer_id ) {
			wp_send_json_error( 'Invalid subscription' );
		}

		$engine = Recurio_Subscription_Engine::get_instance();
		$result = $engine->pause_subscription( $subscription_id );

		if ( $result && ! is_wp_error( $result ) ) {
			wp_send_json_success( 'Subscription paused successfully' );
		} else {
			$error_message = is_wp_error( $result ) ? $result->get_error_message() : 'Failed to pause subscription';
			wp_send_json_error( $error_message );
		}
	}

	/**
	 * Handle AJAX request to resume subscription
	 *
	 * @since 1.0.0
	 */
	public function ajax_resume_subscription() {
		check_ajax_referer( 'recurio_portal_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in' );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		$subscription    = $this->get_subscription( $subscription_id );

		if ( ! $subscription || get_current_user_id() != $subscription->customer_id ) {
			wp_send_json_error( 'Invalid subscription' );
		}

		$engine = Recurio_Subscription_Engine::get_instance();
		$result = $engine->resume_subscription( $subscription_id );

		if ( $result && ! is_wp_error( $result ) ) {
			wp_send_json_success( 'Subscription resumed successfully' );
		} else {
			$error_message = is_wp_error( $result ) ? $result->get_error_message() : 'Failed to resume subscription';
			wp_send_json_error( $error_message );
		}
	}

	/**
	 * Handle AJAX request for early renewal
	 *
	 * @since 1.2.0
	 */
	public function ajax_early_renewal() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'recurio_portal_nonce' ) ) {
			wp_die( '-1' );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'Not logged in', 'recurio' ) );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		$subscription    = $this->get_subscription( $subscription_id );

		if ( ! $subscription || get_current_user_id() != $subscription->customer_id ) {
			wp_send_json_error( __( 'Invalid subscription', 'recurio' ) );
		}

		// Check if early renewal is enabled in settings.
		$settings              = get_option( 'recurio_settings', array() );
		$early_renewal_enabled = isset( $settings['general']['enableEarlyRenewal'] ) ? $settings['general']['enableEarlyRenewal'] : true;

		if ( ! $early_renewal_enabled ) {
			wp_send_json_error( __( 'Early renewal is not available', 'recurio' ) );
		}

		$engine = Recurio_Subscription_Engine::get_instance();
		$result = $engine->process_early_renewal( $subscription_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'          => __( 'Renewal order created successfully. Please complete the payment.', 'recurio' ),
				'order_id'         => $result['order_id'],
				'order_url'        => $result['order_url'],
				'new_next_payment' => $result['new_next_payment'],
			)
		);
	}

	/**
	 * Handle AJAX request to pay next installment for split payment subscriptions
	 *
	 * @since 1.2.0
	 */
	public function ajax_pay_installment() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'recurio_portal_nonce' ) ) {
			wp_die( '-1' );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'Not logged in', 'recurio' ) );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		$subscription    = $this->get_subscription( $subscription_id );

		if ( ! $subscription || get_current_user_id() != $subscription->customer_id ) {
			wp_send_json_error( __( 'Invalid subscription', 'recurio' ) );
		}

		// Verify this is a split payment subscription.
		$payment_type = isset( $subscription->payment_type ) ? $subscription->payment_type : 'recurring';
		if ( $payment_type !== 'split' ) {
			wp_send_json_error( __( 'This is not a split payment subscription', 'recurio' ) );
		}

		// Check if there are remaining payments.
		$max_payments  = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;
		$payments_made = isset( $subscription->renewal_count ) ? intval( $subscription->renewal_count ) : 0;

		if ( $max_payments <= 0 || $payments_made >= $max_payments ) {
			wp_send_json_error( __( 'All installments have been paid', 'recurio' ) );
		}

		// Create a renewal order for the next installment.
		$billing_manager = Recurio_Billing_Manager::get_instance();
		$order           = $billing_manager->create_renewal_order( $subscription );

		if ( ! $order ) {
			wp_send_json_error( __( 'Failed to create payment order', 'recurio' ) );
		}

		// Set order status to pending payment.
		$order->set_status( 'pending' );
		$order->save();

		$payment_url = $order->get_checkout_payment_url();

		wp_send_json_success(
			array(
				'message'     => sprintf(
					/* translators: %1$d: current installment number, %2$d: total installments */
					__( 'Installment %1$d of %2$d payment order created. Redirecting to payment...', 'recurio' ),
					$payments_made + 1,
					$max_payments
				),
				'order_id'    => $order->get_id(),
				'payment_url' => $payment_url,
			)
		);
	}

	/**
	 * Handle AJAX request to update payment method
	 *
	 * @since 1.0.0
	 */
	public function ajax_update_payment_method() {
		check_ajax_referer( 'recurio_portal_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in' );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Simple sanitization sufficient for action type
		$action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( $_POST['action_type'] ) : 'get_methods';

		$subscription = $this->get_subscription( $subscription_id );

		if ( ! $subscription || get_current_user_id() != $subscription->customer_id ) {
			wp_send_json_error( 'Invalid subscription' );
		}

		$payment_methods = Recurio_Payment_Methods::get_instance();

		if ( 'get_methods' === $action_type ) {
			// Get available payment methods.
			$customer_methods   = $payment_methods->get_customer_payment_methods( get_current_user_id() );
			$available_gateways = $payment_methods->get_available_payment_gateways();

			wp_send_json_success(
				array(
					'saved_methods'      => $customer_methods,
					'available_gateways' => array_map(
						function ( $gateway ) {
							return array(
								'id'                    => $gateway->id,
								'title'                 => $gateway->get_title(),
								'description'           => $gateway->get_description(),
								'supports_tokenization' => in_array( 'tokenization', $gateway->supports ?? array(), true ),
							);
						},
						$available_gateways
					),
					'current_method'     => $subscription->payment_method,
					'add_payment_url'    => wc_get_account_endpoint_url( 'payment-methods' ),
				)
			);
		} elseif ( 'update_method' === $action_type ) {
			// Update payment method.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Array index checked with isset() in conditional above
			$token_id = intval( $_POST['token_id'] );
			$result   = $payment_methods->update_subscription_payment_method( $subscription_id, $token_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			} else {
				wp_send_json_success( 'Payment method updated successfully' );
			}
		}
	}

	/**
	 * Load a template file with given variables
	 *
	 * @param string $template_name Template name (without .php extension).
	 * @param array  $args          Variables to extract in template.
	 * @since 1.0.0
	 */
	private function load_template( $template_name, $args = array() ) {
		$template_path = RECURIO_PLUGIN_DIR . 'templates/' . $template_name . '.php';

		/**
		 * Allow Pro plugin to override portal template.
		 *
		 * Pro can use this hook to provide custom templates for advanced features like:
		 * - Advanced subscription detail view with more data
		 * - Enhanced payment method management UI
		 * - Custom billing address editor
		 * - Advanced payment history display
		 *
		 * @since 1.1.0
		 * @param string $template_path The template file path
		 * @param string $template_name The template name (without .php extension)
		 * @param array  $args          Template variables
		 */
		$template_path = apply_filters( 'recurio_portal_template', $template_path, $template_name, $args );

		if ( file_exists( $template_path ) ) {
			/**
			 * Fires before portal template content is rendered.
			 *
			 * Pro can use this hook to inject additional content before the template like:
			 * - Pro feature banners
			 * - Additional subscription information
			 * - Payment method management UI
			 * - Custom notices or alerts
			 *
			 * @since 1.1.0
			 * @param string $template_name The template being loaded
			 * @param array  $args          Template variables
			 */
			do_action( 'recurio_before_portal_content', $template_name, $args );

			// Make template variables available in the template scope.
			foreach ( $args as $key => $value ) {
				${$key} = $value;
			}
			include $template_path;

			/**
			 * Fires after portal template content is rendered.
			 *
			 * Pro can use this hook to append additional content after the template like:
			 * - Upgrade notices
			 * - Related subscription offers
			 * - Customer support links
			 * - Analytics tracking
			 *
			 * @since 1.1.0
			 * @param string $template_name The template being loaded
			 * @param array  $args          Template variables
			 */
			do_action( 'recurio_after_portal_content', $template_name, $args );
		} else {
			/* translators: %s: Template name */
			echo '<div class="recurio-portal-notice">' . sprintf( esc_html__( 'Template %s not found.', 'recurio' ), esc_html( $template_name ) ) . '</div>';
		}
	}

	/**
	 * Add endpoint to WooCommerce My Account
	 */
	public function add_my_account_endpoint() {
		// Get custom endpoint from settings.
		$settings = get_option( 'recurio_settings', array() );
		$endpoint = isset( $settings['general']['myAccountEndpoint'] ) && ! empty( $settings['general']['myAccountEndpoint'] )
			? $settings['general']['myAccountEndpoint']
			: 'subscriptions';

		// Register endpoint with WooCommerce query system
		WC()->query->query_vars[ $endpoint ] = $endpoint;

		// Add rewrite endpoint using WooCommerce's mask
		$mask = WC()->query->get_endpoints_mask();
		add_rewrite_endpoint( $endpoint, $mask );

		// Check if we need to flush rewrite rules
		// This flag is set when portal settings are changed OR during plugin activation/deactivation
		if ( get_option( 'recurio_flush_rewrite_rules' ) ) {
			delete_option( 'recurio_flush_rewrite_rules' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Add query vars
	 *
	 * @param array $vars Query variables array.
	 * @return array Modified query variables array.
	 */
	public function add_query_vars( $vars ) {
		// Get custom endpoint from settings.
		$settings = get_option( 'recurio_settings', array() );
		$endpoint = isset( $settings['general']['myAccountEndpoint'] ) && ! empty( $settings['general']['myAccountEndpoint'] )
		? $settings['general']['myAccountEndpoint']
		: 'subscriptions';

		$vars[] = $endpoint;
		return $vars;
	}

	/**
	 * Add menu items to My Account
	 *
	 * @param array $items Menu items array.
	 * @return array Modified menu items array.
	 */
	public function add_menu_items( $items ) {
		// Get custom settings.
		$settings = get_option( 'recurio_settings', array() );
		$endpoint = isset( $settings['general']['myAccountEndpoint'] ) && ! empty( $settings['general']['myAccountEndpoint'] )
		? $settings['general']['myAccountEndpoint']
		: 'subscriptions';
		$label    = isset( $settings['general']['myAccountLabel'] ) && ! empty( $settings['general']['myAccountLabel'] )
		? $settings['general']['myAccountLabel']
		: __( 'Subscriptions', 'recurio' );

		// Insert after orders.
		$new_items = array();
		foreach ( $items as $key => $value ) {
			$new_items[ $key ] = $value;
			if ( 'orders' === $key ) {
				$new_items[ $endpoint ] = $label;
			}
		}
		return $new_items;
	}

	/**
	 * Render content in My Account page
	 */
	public function render_my_account_content() {
		// Get custom endpoint from settings.
		$settings = get_option( 'recurio_settings', array() );
		$endpoint = isset( $settings['general']['myAccountEndpoint'] ) && ! empty( $settings['general']['myAccountEndpoint'] )
		? $settings['general']['myAccountEndpoint']
		: 'subscriptions';

		// Get view from query vars for My Account.
		global $wp;

		// Check if we're viewing a specific subscription.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public portal view, no sensitive operations
		if ( isset( $_GET['view'] ) && 'subscription' === $_GET['view'] && isset( $_GET['id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public portal view, no sensitive operations
			$subscription_id = intval( $_GET['id'] );
			$subscription    = $this->get_subscription( $subscription_id );

			if (!$subscription || $subscription->customer_id != get_current_user_id()) {
				echo '<div class="recurio-portal-notice">' . esc_html__( 'You do not have permission to view this subscription.', 'recurio' ) . '</div>';
			} else {
				$this->load_template(
					'customer-portal/subscription-detail',
					array(
						'subscription'      => $subscription,
						'payment_history'   => $this->get_payment_history( $subscription_id ),
						'available_actions' => $this->get_available_actions( $subscription ),
						'portal_url'        => wc_get_account_endpoint_url( $endpoint ),
					)
				);
			}
		} else {
			// Show dashboard view.
			$this->load_template(
				'customer-portal/dashboard',
				array(
					'customer_id'    => get_current_user_id(),
					'show_cancelled' => false,
				)
			);
		}
	}

	/**
	 * Enqueue scripts for My Account page
	 */
	public function enqueue_scripts_my_account() {
		if ( is_account_page() ) {
			global $wp;

			// Get custom endpoint from settings.
			$settings = get_option( 'recurio_settings', array() );
			$endpoint = isset( $settings['general']['myAccountEndpoint'] ) && ! empty( $settings['general']['myAccountEndpoint'] )
			? $settings['general']['myAccountEndpoint']
			: 'subscriptions';

			// Check if we're on the subscriptions endpoint (using dynamic endpoint).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public portal view, no sensitive operations
			if ( isset( $wp->query_vars[ $endpoint ] ) || isset( $_GET['view'] ) ) {
				wp_enqueue_style( 'recurio-customer-portal', RECURIO_PLUGIN_URL . 'assets/css/customer-portal.css', array(), RECURIO_VERSION );
				wp_enqueue_script( 'recurio-customer-portal', RECURIO_PLUGIN_URL . 'assets/js/customer-portal.js', array( 'jquery' ), RECURIO_VERSION, true );

				wp_localize_script(
					'recurio-customer-portal',
					'recurio_portal',
					array(
						'ajax_url' => admin_url( 'admin-ajax.php' ),
						'nonce'    => wp_create_nonce( 'recurio_portal_nonce' ),
						'strings'  => array(
							'processing' => __( 'Processing...', 'recurio' ),
							'error'      => __( 'An error occurred. Please try again.', 'recurio' ),
						),
					)
				);
			}
		}
	}
}
