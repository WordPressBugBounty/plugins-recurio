<?php
/**
 * Billing Manager - Handles subscription billing operations
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_Billing_Manager {

	private static $instance = null;
	private $subscription_engine;
	private $max_retry_attempts  = 3;
	private $retry_interval_days = 3;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Get subscription engine instance
	 */
	private function get_subscription_engine() {
		if ( ! $this->subscription_engine ) {
			$this->subscription_engine = Recurio_Subscription_Engine::get_instance();
		}
		return $this->subscription_engine;
	}

	private function init_hooks() {
		// Cron hooks for automated billing
		add_action( 'recurio_process_payments', array( $this, 'process_scheduled_payments' ) );
		add_action( 'recurio_retry_failed_payments', array( $this, 'retry_failed_payments' ) );
		add_action( 'recurio_send_renewal_reminders', array( $this, 'send_renewal_reminders' ) );

		// Payment gateway specific hooks
		add_action( 'woocommerce_scheduled_subscription_payment', array( $this, 'handle_gateway_recurring_payment' ), 10, 2 );

		// Webhook handlers for payment gateways
		add_action( 'woocommerce_api_recurio_stripe_webhook', array( $this, 'handle_stripe_webhook' ) );
		add_action( 'woocommerce_api_recurio_paypal_webhook', array( $this, 'handle_paypal_webhook' ) );

		// AJAX handlers for manual payment processing
		add_action( 'wp_ajax_recurio_process_payment', array( $this, 'ajax_process_payment' ) );
		add_action( 'wp_ajax_recurio_retry_payment', array( $this, 'ajax_retry_payment' ) );

		// Filter for custom payment methods
		add_filter( 'recurio_available_payment_methods', array( $this, 'get_available_payment_methods' ) );
	}

	/**
	 * Process all scheduled payments
	 */
	public function process_scheduled_payments() {

		global $wpdb;
		$table_name = $wpdb->prefix . 'recurio_subscriptions';

		// Get all active subscriptions with payments due today or earlier
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for billing management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time billing data
		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscriptions
            WHERE status = 'active'
            AND next_payment_date IS NOT NULL
            AND next_payment_date <= %s
            AND (trial_end_date IS NULL OR trial_end_date < %s)
            ORDER BY next_payment_date ASC",
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);

		$processed = 0;
		$failed    = 0;

		foreach ( $subscriptions as $subscription ) {
			$result = $this->process_subscription_payment( $subscription );

			if ( $result ) {
				++$processed;
			} else {
				++$failed;
			}
		}

		// Trigger action for reporting
		do_action( 'recurio_payment_batch_processed', $processed, $failed );

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			'total'     => count( $subscriptions ),
		);
	}

	/**
	 * Process payment for a single subscription
	 */
	public function process_subscription_payment( $subscription ) {
		try {
			// Check if subscription is still active
			if ( $subscription->status !== 'active' ) {
				return false;
			}

			// Allow Pro to override payment processing mode (manual vs automated)
			$processing_mode = apply_filters( 'recurio_payment_processing_mode', 'manual', $subscription->id );

			// Hook before payment processing
			do_action( 'recurio_before_payment_processed', $subscription->id, $subscription->billing_amount );

			// If Pro enables automated mode, let Pro handle the payment
			if ( $processing_mode === 'automated' ) {
				// Pro will hook into this and handle automated payment
				$result = apply_filters( 'recurio_process_automated_payment', false, $subscription );

				// If Pro handled it successfully, return the result
				if ( $result !== false ) {
					return $result;
				}
				// If Pro returned false, it means Pro wants Free to handle the payment processing
				// Skip the auto-renewal check and continue to payment processing below
			} else {
				// Free version: Manual payment mode
				// Check if auto-renewal is enabled for this subscription
				$auto_renewal = get_post_meta( $subscription->product_id, '_recurio_subscription_auto_renewal', true );
				if ( $auto_renewal !== 'yes' ) {
				// Mark subscription as pending renewal (requires manual payment)
				$this->get_subscription_engine()->update_subscription(
					$subscription->id,
					array(
						'status' => 'pending_renewal',
					)
				);

				// Send notification about manual renewal required
				do_action( 'recurio_manual_renewal_required', $subscription->id );

				return false;
			}
		}

		// Check if subscription has reached max renewals or max payments (split payments)
		$is_completed = false;
		$completion_reason = '';

		// Check max_renewals (traditional subscription length limit)
		if ( $subscription->max_renewals !== null && $subscription->renewal_count >= $subscription->max_renewals ) {
			$is_completed = true;
			$completion_reason = 'max_renewals_reached';
		}

		// Check max_payments for split payment subscriptions
		$payment_type = isset( $subscription->payment_type ) ? $subscription->payment_type : 'recurring';
		$max_payments = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;

		if ( $payment_type === 'split' && $max_payments > 0 && $subscription->renewal_count >= $max_payments ) {
			$is_completed = true;
			$completion_reason = 'split_payments_completed';
		}

		if ( $is_completed ) {
			// Mark subscription as completed
			$this->get_subscription_engine()->update_subscription(
				$subscription->id,
				array(
					'status' => 'completed',
				)
			);

			// Log event
			$this->get_subscription_engine()->log_event(
				$subscription->id,
				'subscription_completed',
				null,
				array(
					'reason'   => $completion_reason,
					'renewals' => $subscription->renewal_count,
				)
			);

			// Send notification
			do_action( 'recurio_subscription_completed', $subscription->id, $completion_reason );

			return false;
		}

		// Get customer
		$customer = get_user_by( 'id', $subscription->customer_id );
		if ( ! $customer ) {
			$error_message = sprintf( 'Customer not found (ID: %d)', $subscription->customer_id );
			$this->handle_payment_failure( $subscription, $error_message );
			return false;
		}

		// Get payment method
		$payment_method = $this->get_subscription_payment_method( $subscription );

		if ( ! $payment_method ) {
			$error_message = sprintf(
				'No payment method available for subscription #%d (Customer: %d, Gateway: %s, Token ID: %s). ' .
				'This subscription requires manual renewal. To enable automatic renewals, the customer must ' .
				'save a payment method or use a gateway that supports tokenization.',
				$subscription->id,
				$subscription->customer_id,
				$subscription->payment_method ?: 'none',
				$subscription->payment_token_id ?: 'none'
			);
			$this->handle_payment_failure( $subscription, $error_message );
			return false;
		}

		// Attempt payment based on gateway type
		$payment_result = false;

		switch ( $payment_method['gateway'] ) {
			case 'stripe':
				$payment_result = $this->process_stripe_payment( $subscription, $payment_method );
				break;

			case 'paypal':
			case 'ppcp-gateway':
				$payment_result = $this->process_paypal_payment( $subscription, $payment_method );
				break;

			default:
				// Try generic WooCommerce gateway processing
				$payment_result = $this->process_woocommerce_payment( $subscription, $payment_method );
				break;
		}

		// Allow custom payment processing
		$payment_result = apply_filters( 'recurio_process_subscription_payment', $payment_result, $subscription, $payment_method );

		if ( $payment_result && ! is_wp_error( $payment_result ) ) {
			$this->handle_payment_success( $subscription, $payment_result );
			return true;
		} else {
			$error_message = is_wp_error( $payment_result ) ? $payment_result->get_error_message() : 'Payment failed';
			$this->handle_payment_failure( $subscription, $error_message );
			return false;
		}
		} catch ( Exception $e ) {
			$this->handle_payment_failure( $subscription, 'Fatal error: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Process payment through Stripe gateway
	 */
	private function process_stripe_payment( $subscription, $payment_method ) {

		// Check if Stripe gateway is available
		if ( ! class_exists( 'WC_Gateway_Stripe' ) ) {
			return new WP_Error( 'gateway_not_available', 'Stripe gateway not available' );
		}

		try {
			// Create renewal order for this payment
			$order = $this->create_renewal_order( $subscription );

			if ( ! $order ) {
				return new WP_Error( 'order_creation_failed', 'Failed to create renewal order' );
			}

			// Set payment method on the order
			$order->set_payment_method( 'stripe' );

			// Set Stripe customer ID and payment method on order if available
			if ( ! empty( $payment_method['stripe_customer_id'] ) ) {
				$order->update_meta_data( '_stripe_customer_id', $payment_method['stripe_customer_id'] );
			}

			if ( ! empty( $payment_method['token_id'] ) ) {
				// Set payment token ID on order
				$order->update_meta_data( '_payment_token_id', $payment_method['token_id'] );

				// Add payment token object if available
				if ( ! empty( $payment_method['token_object'] ) && is_object( $payment_method['token_object'] ) ) {
					$order->add_payment_token( $payment_method['token_object'] );

					// CRITICAL for Stripe UPE: Set the payment method ID (pm_xxx) on the order
					// The WC_Stripe_UPE_Payment_Gateway REQUIRES this meta to process payments
					// Without this, PaymentIntent will fail with "missing payment method" error
					$stripe_pm_id = $payment_method['token_object']->get_token();
					if ( $stripe_pm_id ) {
						$order->update_meta_data( '_stripe_source_id', $stripe_pm_id );
						$order->update_meta_data( '_payment_method_id', $stripe_pm_id );
					}
				}
			}

			// Save order meta
			$order->save();
		// CRITICAL: Reload order from database to ensure fresh instance with all meta
		// This ensures Stripe gateway reads the updated payment method info
		$order = wc_get_order( $order->get_id() );

			// Get Stripe gateway instance
			$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();

			if ( ! isset( $payment_gateways['stripe'] ) ) {
				return new WP_Error( 'gateway_not_available', 'Stripe gateway not found in available gateways' );
			}

			$stripe_gateway = $payment_gateways['stripe'];

		// Check if gateway has a specific method for subscription/renewal payments
		// This is the standard way WooCommerce Subscriptions handles renewals
		if ( method_exists( $stripe_gateway, 'scheduled_subscription_payment' ) ) {

			try {
				// Use the subscription-specific payment method
				$stripe_gateway->scheduled_subscription_payment( $subscription->billing_amount, $order );

				// Check if payment was successful
				if ( $order->is_paid() || $order->has_status( array( 'processing', 'completed' ) ) ) {
					return array(
						'transaction_id' => $order->get_transaction_id(),
						'order_id'       => $order->get_id(),
						'amount'         => $subscription->billing_amount,
						'gateway'        => 'stripe',
					);
				} else {
					$error_message = 'Payment failed - order status: ' . $order->get_status();
					return new WP_Error( 'payment_failed', $error_message );
				}
			} catch ( Exception $e ) {
				$order->update_status( 'failed', 'Stripe error: ' . $e->getMessage() );
				return new WP_Error( 'stripe_error', $e->getMessage() );
			}
		}

		// Fallback to standard process_payment method if scheduled_subscription_payment not available

			// Process payment through Stripe
			$payment_result = $stripe_gateway->process_payment( $order->get_id() );

			if ( $payment_result && isset( $payment_result['result'] ) && $payment_result['result'] === 'success' ) {
				// Payment successful
				$order->payment_complete();

				return array(
					'transaction_id' => $order->get_transaction_id(),
					'order_id'       => $order->get_id(),
					'amount'         => $subscription->billing_amount,
					'gateway'        => 'stripe',
				);
			} else {
				// Extract detailed error message
				$error_message = 'Stripe payment failed';

				if ( isset( $payment_result['messages'] ) ) {
					$error_message = $payment_result['messages'];
				} elseif ( isset( $payment_result['message'] ) ) {
					$error_message = $payment_result['message'];
				} elseif ( isset( $payment_result['error'] ) ) {
					$error_message = is_string( $payment_result['error'] ) ? $payment_result['error'] : print_r( $payment_result['error'], true );
				}

				$order->update_status( 'failed', $error_message );
				return new WP_Error( 'payment_failed', $error_message );
			}
		} catch ( Exception $e ) {
			if ( isset( $order ) && $order ) {
				$order->update_status( 'failed', 'Stripe error: ' . $e->getMessage() );
			}
			return new WP_Error( 'stripe_error', $e->getMessage() );
		}
	}

	/**
	 * Process payment through PayPal gateway
	 */
	private function process_paypal_payment( $subscription, $payment_method ) {

		// Check for PayPal gateway availability
		$paypal_gateway = null;

		if ( class_exists( 'WC_Gateway_PPCP' ) ) {
			$paypal_gateway = new WC_Gateway_PPCP();
		} elseif ( class_exists( 'WC_Gateway_Paypal' ) ) {
			$paypal_gateway = new WC_Gateway_Paypal();
		}

		if ( ! $paypal_gateway ) {
			return new WP_Error( 'gateway_not_available', 'PayPal gateway not available' );
		}

		try {
			// Get billing agreement ID or subscription ID from PayPal
			$billing_agreement_id = isset( $payment_method['billing_agreement_id'] ) ? $payment_method['billing_agreement_id'] : '';

			if ( ! $billing_agreement_id ) {
				return new WP_Error( 'no_agreement', 'PayPal billing agreement not found' );
			}

			// Create renewal order
			$order = $this->create_renewal_order( $subscription );

			if ( ! $order ) {
				return new WP_Error( 'order_creation_failed', 'Failed to create renewal order' );
			}

			// Process payment through PayPal
			$payment_result = $paypal_gateway->process_payment( $order->get_id() );

			if ( $payment_result && $payment_result['result'] === 'success' ) {
				$order->payment_complete();

				return array(
					'transaction_id' => $order->get_transaction_id(),
					'order_id'       => $order->get_id(),
					'amount'         => $subscription->billing_amount,
					'gateway'        => 'paypal',
				);
			} else {
				$order->update_status( 'failed', 'PayPal payment failed' );
				return new WP_Error( 'payment_failed', 'PayPal payment failed' );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'paypal_error', $e->getMessage() );
		}
	}

	/**
	 * Process payment through generic WooCommerce gateway
	 */
	private function process_woocommerce_payment( $subscription, $payment_method ) {

		// Get the payment gateway
		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$gateway_id       = $payment_method['gateway'];

		if ( ! isset( $payment_gateways[ $gateway_id ] ) ) {
			return new WP_Error( 'gateway_not_found', 'Payment gateway not found: ' . $gateway_id );
		}

		$gateway = $payment_gateways[ $gateway_id ];

		// Check if gateway supports subscriptions
		if ( ! $gateway->supports( 'subscriptions' ) ) {
			// Gateway doesn't support subscriptions
		}

		// Create renewal order
		$order = $this->create_renewal_order( $subscription );

		if ( ! $order ) {
			return new WP_Error( 'order_creation_failed', 'Failed to create renewal order' );
		}

		// Set payment method on order
		$order->set_payment_method( $gateway );

		// Process payment
		$payment_result = $gateway->process_payment( $order->get_id() );

		if ( $payment_result && isset( $payment_result['result'] ) && $payment_result['result'] === 'success' ) {
			$order->payment_complete();

			return array(
				'transaction_id' => $order->get_transaction_id(),
				'order_id'       => $order->get_id(),
				'amount'         => $subscription->billing_amount,
				'gateway'        => $gateway_id,
			);
		} else {
			$order->update_status( 'failed', 'Payment failed' );
			return new WP_Error( 'payment_failed', 'Payment processing failed' );
		}
	}

	/**
	 * Create a renewal order for subscription payment
	 */
	public function create_renewal_order( $subscription ) {

		// Get the original order if available
		$original_order_id = $subscription->wc_subscription_id;
		$original_order    = $original_order_id ? wc_get_order( $original_order_id ) : null;

		// Create new order
		$order = wc_create_order(
			array(
				'customer_id' => $subscription->customer_id,
				'created_via' => 'subscription_renewal',
				'parent'      => $original_order_id,
			)
		);

		if ( ! $order ) {
			return false;
		}

		// Get product
		$product = wc_get_product( $subscription->product_id );

		if ( $product ) {
			// Add product to order
			$order->add_product(
				$product,
				1,
				array(
					'subtotal' => $subscription->billing_amount,
					'total'    => $subscription->billing_amount,
				)
			);
		} else {
			// Add a fee if product not found
			$order->add_fee(
				array(
					'name'       => __( 'Subscription Renewal', 'recurio' ),
					'amount'     => $subscription->billing_amount,
					'tax_status' => 'none',
				)
			);
		}

		// Copy billing/shipping from original order or customer
		if ( $original_order ) {
			$order->set_address( $original_order->get_address( 'billing' ), 'billing' );
			$order->set_address( $original_order->get_address( 'shipping' ), 'shipping' );
			$order->set_payment_method( $original_order->get_payment_method() );
		} else {
			// Get from customer meta
			$customer = new WC_Customer( $subscription->customer_id );
			$order->set_address(
				array(
					'first_name' => $customer->get_billing_first_name(),
					'last_name'  => $customer->get_billing_last_name(),
					'email'      => $customer->get_billing_email(),
					'phone'      => $customer->get_billing_phone(),
					'address_1'  => $customer->get_billing_address_1(),
					'address_2'  => $customer->get_billing_address_2(),
					'city'       => $customer->get_billing_city(),
					'state'      => $customer->get_billing_state(),
					'postcode'   => $customer->get_billing_postcode(),
					'country'    => $customer->get_billing_country(),
				),
				'billing'
			);
		}

		// Calculate totals
		$order->calculate_totals();

		// Add order meta
		$order->add_meta_data( '_recurio_subscription_id', $subscription->id );
		// $order->add_meta_data( '_subscription_renewal', 'yes' );

		// CRITICAL: Mark this as a renewal order to prevent duplicate subscription creation
		// This flag is checked by process_new_subscription() to skip renewal orders
		$order->add_meta_data( '_recurio_is_renewal_order', 'yes' );

		// Save order
		$order->save();

		return $order;
	}

	/**
	 * Get payment method for subscription
	 */
	private function get_subscription_payment_method( $subscription ) {
		$payment_info = array(
			'gateway'           => null,
			'token'             => null,
			'token_id'          => null,
			'token_object'      => null,
			'stripe_customer_id' => null,
		);

		// Check subscription metadata first
		$metadata = json_decode( $subscription->subscription_metadata, true );
		if ( isset( $metadata['payment_method'] ) && is_array( $metadata['payment_method'] ) ) {
			$payment_info = array_merge( $payment_info, $metadata['payment_method'] );
		}

		// Get gateway from subscription's payment_method column
		if ( ! empty( $subscription->payment_method ) ) {
			$payment_info['gateway'] = $subscription->payment_method;
		}

		// Try to get token from subscription's payment_token_id column
		if ( ! empty( $subscription->payment_token_id ) ) {
			$token_object = WC_Payment_Tokens::get( $subscription->payment_token_id );
			if ( $token_object ) {
				$payment_info['token_id']     = $subscription->payment_token_id;
				$payment_info['token']        = $token_object->get_token();
				$payment_info['token_object'] = $token_object;
				$payment_info['gateway']      = $token_object->get_gateway_id();

				// Get Stripe customer ID from token if it's a Stripe token
				if ( method_exists( $token_object, 'get_customer_id' ) ) {
					$payment_info['stripe_customer_id'] = $token_object->get_customer_id();
				}
			}
		}

		// Check original order if token not found yet
		if ( ! $payment_info['token_object'] && $subscription->wc_subscription_id ) {
			$order = wc_get_order( $subscription->wc_subscription_id );
			if ( $order ) {
				if ( ! $payment_info['gateway'] ) {
					$payment_info['gateway'] = $order->get_payment_method();
				}

				// Try to get token ID from order meta
				$token_id = $order->get_meta( '_payment_token_id' );
				if ( $token_id ) {
					$token_object = WC_Payment_Tokens::get( $token_id );
					if ( $token_object ) {
						$payment_info['token_id']     = $token_id;
						$payment_info['token']        = $token_object->get_token();
						$payment_info['token_object'] = $token_object;

						if ( method_exists( $token_object, 'get_customer_id' ) ) {
							$payment_info['stripe_customer_id'] = $token_object->get_customer_id();
						}
					}
				}

				// Get Stripe customer ID from order meta
				if ( ! $payment_info['stripe_customer_id'] ) {
					$stripe_customer_id = $order->get_meta( '_stripe_customer_id' );
					if ( $stripe_customer_id ) {
						$payment_info['stripe_customer_id'] = $stripe_customer_id;
					}
				}
			}
		}

		// Check customer's default saved payment method if still no token
		if ( ! $payment_info['token_object'] ) {
			$customer_id = $subscription->customer_id;
			$tokens      = WC_Payment_Tokens::get_customer_tokens( $customer_id, $payment_info['gateway'] );

			foreach ( $tokens as $token ) {
				if ( $token->is_default() || count( $tokens ) === 1 ) {
					$payment_info['token_id']     = $token->get_id();
					$payment_info['token']        = $token->get_token();
					$payment_info['token_object'] = $token;
					$payment_info['gateway']      = $token->get_gateway_id();

					if ( method_exists( $token, 'get_customer_id' ) ) {
						$payment_info['stripe_customer_id'] = $token->get_customer_id();
					}
					break;
				}
			}
		}

		// Get Stripe customer ID from user meta as last resort
		if ( ! $payment_info['stripe_customer_id'] && $payment_info['gateway'] === 'stripe' ) {
			$stripe_customer_id = get_user_meta( $subscription->customer_id, '_stripe_customer_id', true );
			if ( $stripe_customer_id ) {
				$payment_info['stripe_customer_id'] = $stripe_customer_id;
			}
		}

		// Return false if we don't have at least a gateway
		if ( ! $payment_info['gateway'] ) {
			return false;
		}

		return $payment_info;
	}

	/**
	 * Handle successful payment
	 */
	private function handle_payment_success( $subscription, $payment_data ) {
		// Calculate next payment date
		$next_payment = $this->get_subscription_engine()->calculate_next_payment_date(
			$subscription->billing_period,
			$subscription->billing_interval
		);

		// Increment renewal count
		$new_renewal_count = intval( $subscription->renewal_count ) + 1;

		// Update subscription with new renewal count
		$update_data = array(
			'next_payment_date'    => $next_payment,
			'failed_payment_count' => 0,
			'renewal_count'        => $new_renewal_count,
		);

		// Check if this was the final payment (for both recurring with max_renewals and split payments)
		$is_final_payment = false;

		// Check max_renewals (traditional subscription length limit)
		if ( $subscription->max_renewals !== null && $new_renewal_count >= $subscription->max_renewals ) {
			$is_final_payment = true;
		}

		// Check max_payments for split payment subscriptions
		$payment_type = isset( $subscription->payment_type ) ? $subscription->payment_type : 'recurring';
		$max_payments = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;

		if ( $payment_type === 'split' && $max_payments > 0 && $new_renewal_count >= $max_payments ) {
			$is_final_payment = true;
		}

		if ( $is_final_payment ) {
			// For split payments with 'after_full_payment' access timing, first activate then complete
			$access_timing = isset( $subscription->access_timing ) ? $subscription->access_timing : 'immediate';
			if ( $payment_type === 'split' && $access_timing === 'after_full_payment' && $subscription->status === 'pending_payment' ) {
				// Fire action for access granted
				do_action( 'recurio_split_payment_access_granted', $subscription->id );
			}

			$update_data['status']            = 'completed';
			$update_data['next_payment_date'] = null; // No more payments needed

			// Fire split payment completed action
			if ( $payment_type === 'split' ) {
				do_action( 'recurio_split_payment_completed', $subscription->id, $new_renewal_count );
			}
		}

		$this->get_subscription_engine()->update_subscription( $subscription->id, $update_data );

		// Log revenue
		$this->get_subscription_engine()->log_revenue(
			$subscription->id,
			$payment_data['amount'],
			$payment_data['gateway'],
			$payment_data['transaction_id']
		);

		// Log event
		$this->get_subscription_engine()->log_event(
			$subscription->id,
			'payment_processed',
			$payment_data['amount'],
			$payment_data
		);

		// Send notification (existing hook)
		do_action( 'recurio_payment_successful', $subscription->id, $payment_data, (array)$subscription );

		// Hook after successful payment for Pro (additional analytics, forecasting, etc.)
		do_action( 'recurio_after_payment_processed', $subscription->id, $payment_data['amount'], $payment_data['transaction_id'] );

		// Update customer analytics
		do_action( 'recurio_update_recurio_customer_analytics', $subscription->customer_id );
	}

	/**
	 * Handle failed payment
	 */
	private function handle_payment_failure( $subscription, $error_message ) {

		// Increment failed payment count
		$failed_count = intval( $subscription->failed_payment_count ) + 1;

		// Update subscription
		$update_data = array(
			'failed_payment_count' => $failed_count,
		);

		// Check if we should pause or cancel based on settings
		$settings     = get_option( 'recurio_settings', array() );
		$max_attempts = isset( $settings['dunning_attempts'] ) ? intval( $settings['dunning_attempts'] ) : $this->max_retry_attempts;

		if ( $failed_count >= $max_attempts ) {
			// Max attempts reached, cancel subscription
			$update_data['status']              = 'cancelled';
			$update_data['cancellation_reason'] = 'Maximum payment failures reached';
		} else {
			// Schedule retry
			$retry_date     = new DateTime();
			$retry_interval = isset( $settings['dunning_interval'] ) ? intval( $settings['dunning_interval'] ) : $this->retry_interval_days;
			$retry_date->add( new DateInterval( 'P' . $retry_interval . 'D' ) );
			$update_data['next_payment_date'] = $retry_date->format( 'Y-m-d H:i:s' );
		}

		$this->get_subscription_engine()->update_subscription( $subscription->id, $update_data );

		// Log event
		$this->get_subscription_engine()->log_event(
			$subscription->id,
			'payment_failed',
			null,
			array(
				'error'   => $error_message,
				'attempt' => $failed_count,
			)
		);

		// Send notification (existing hook)
		do_action( 'recurio_payment_failed', $subscription->id, $error_message, $failed_count );

		// Hook after failed payment for Pro (dunning, retry logic, etc.)
		do_action( 'recurio_after_payment_failed', $subscription->id, $error_message, $failed_count, $subscription );
	}

	/**
	 * Retry failed payments
	 */
	public function retry_failed_payments() {

		global $wpdb;
		$table_name = $wpdb->prefix . 'recurio_subscriptions';

		// Get subscriptions with failed payments
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for billing management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time billing data
		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscriptions
            WHERE status = 'active'
            AND failed_payment_count > 0
            AND failed_payment_count < %d
            AND next_payment_date <= %s",
				$this->max_retry_attempts,
				current_time( 'mysql' )
			)
		);

		foreach ( $subscriptions as $subscription ) {
			$this->process_subscription_payment( $subscription );
		}
	}

	/**
	 * AJAX handler for manual payment processing
	 */
	public function ajax_process_payment() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		$subscription    = $this->get_subscription_engine()->get_subscription( $subscription_id );

		if ( ! $subscription ) {
			wp_send_json_error( 'Subscription not found' );
		}

		$result = $this->process_subscription_payment( $subscription );

		if ( $result ) {
			wp_send_json_success( 'Payment processed successfully' );
		} else {
			wp_send_json_error( 'Payment processing failed' );
		}
	}

	/**
	 * Get available payment methods for subscriptions
	 */
	public function get_available_payment_methods( $methods = array() ) {
		$payment_gateways = WC()->payment_gateways->get_available_payment_gateways();

		foreach ( $payment_gateways as $gateway_id => $gateway ) {
			// Check if gateway supports subscriptions
			if ( $gateway->supports( 'subscriptions' ) || $gateway->supports( 'tokenization' ) ) {
				$methods[ $gateway_id ] = array(
					'title'                  => $gateway->get_title(),
					'description'            => $gateway->get_description(),
					'supports_subscriptions' => $gateway->supports( 'subscriptions' ),
					'supports_tokenization'  => $gateway->supports( 'tokenization' ),
				);
			}
		}

		// Allow Pro to modify available payment methods (e.g., add validation, restrictions)
		return apply_filters( 'recurio_available_payment_methods', $methods );
	}

	/**
	 * Send renewal reminder emails for upcoming subscriptions
	 */
	public function send_renewal_reminders() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'recurio_subscriptions';

		// Get settings for reminder days (default to 3 days before renewal)
		$settings      = get_option( 'recurio_settings', array() );
		$reminder_days = isset( $settings['billing']['reminderDays'] ) ? intval( $settings['billing']['reminderDays'] ) : 3;

		// Find subscriptions that:
		// 1. Are active
		// 2. Have auto-renewal enabled
		// 3. Have renewal reminder enabled for the product
		// 4. Are due for renewal in X days
		// 5. Haven't been reminded yet for this cycle
		$reminder_date = gmdate( 'Y-m-d', strtotime( "+{$reminder_days} days" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for billing management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time billing data
		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title as product_name
            FROM {$wpdb->prefix}recurio_subscriptions s
            LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
            WHERE s.status = 'active'
            AND DATE(s.next_payment_date) = %s",
				$reminder_date
			)
		);

		$email_notifications = Recurio_Email_Notifications::get_instance();
		$reminders_sent      = 0;

		foreach ( $subscriptions as $subscription ) {
			// Check if renewal reminder is enabled for this product
			$renewal_reminder = get_post_meta( $subscription->product_id, '_recurio_subscription_renewal_reminder', true );

			if ( $renewal_reminder !== 'yes' ) {
				continue; // Skip if reminder not enabled
			}

			// Check if we've already sent a reminder for this renewal cycle
			$last_reminder = get_post_meta( $subscription->product_id, '_recurio_last_reminder_' . $subscription->id, true );
			if ( $last_reminder === $subscription->next_payment_date ) {
				continue; // Already sent for this cycle
			}

			// Prepare subscription data for email
			$subscription_data = array(
				'customer_id'       => $subscription->customer_id,
				'product_id'        => $subscription->product_id,
				'billing_amount'    => $subscription->billing_amount,
				'next_payment_date' => $subscription->next_payment_date,
				'billing_period'    => $subscription->billing_period,
			);

			// Send reminder email
			do_action( 'recurio_renewal_reminder', $subscription->id, $subscription_data );

			// Mark as reminded for this cycle
			update_post_meta( $subscription->product_id, '_recurio_last_reminder_' . $subscription->id, $subscription->next_payment_date );

			++$reminders_sent;
		}

		return $reminders_sent;
	}
}
