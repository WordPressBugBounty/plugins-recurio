<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_Payment_Methods {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		// Hook into WooCommerce payment method saved
		add_action( 'woocommerce_payment_token_added_to_customer', array( $this, 'handle_payment_token_added' ), 10, 4 );
		add_action( 'woocommerce_payment_token_deleted', array( $this, 'handle_payment_token_deleted' ), 10, 2 );
		add_action( 'woocommerce_payment_token_set_default', array( $this, 'handle_payment_token_set_default' ), 10, 2 );
	}

	/**
	 * Get available payment gateways for subscriptions
	 */
	public function get_available_payment_gateways( $for_customer = true ) {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}

		$settings        = get_option( 'recurio_settings', array() );
		$allowed_methods = isset( $settings['billing']['allowedPaymentMethods'] )
			? $settings['billing']['allowedPaymentMethods']
			: array();

		$available_gateways    = WC()->payment_gateways->get_available_payment_gateways();
		$subscription_gateways = array();

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			// Check if this gateway is allowed for subscriptions
			if ( isset( $allowed_methods[ $gateway_id ] ) && $allowed_methods[ $gateway_id ] === false ) {
				continue;
			}

			// Check if gateway supports subscriptions
			if ( $this->gateway_supports_subscriptions( $gateway ) ) {
				$subscription_gateways[ $gateway_id ] = $gateway;
			}
		}

		return $subscription_gateways;
	}

	/**
	 * Check if a payment gateway supports subscriptions
	 */
	public function gateway_supports_subscriptions( $gateway ) {
		// Check if gateway has subscription support
		if ( isset( $gateway->supports ) && is_array( $gateway->supports ) ) {
			// Check for subscription-specific features
			$subscription_features = array(
				'subscriptions',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_amount_changes',
				'subscription_date_changes',
				'subscription_payment_method_change',
				'tokenization',
			);

			foreach ( $subscription_features as $feature ) {
				if ( in_array( $feature, $gateway->supports ) ) {
					return true;
				}
			}
		}

		// Check for known subscription-capable gateways
		$subscription_capable = array( 'stripe', 'paypal', 'square', 'authorize_net' );
		if ( in_array( $gateway->id, $subscription_capable ) ) {
			return true;
		}

		// Exclude known offline payment methods
		$offline_methods = array( 'cod', 'bacs', 'cheque' );
		if ( in_array( $gateway->id, $offline_methods ) ) {
			return false;
		}

		// If gateway supports tokenization, it might support subscriptions
		if ( isset( $gateway->supports ) && in_array( 'tokenization', $gateway->supports ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get customer's saved payment methods
	 */
	public function get_customer_payment_methods( $customer_id ) {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}

		$tokens          = WC_Payment_Tokens::get_customer_tokens( $customer_id );
		$payment_methods = array();

		foreach ( $tokens as $token ) {
			$gateway_id = $token->get_gateway_id();
			$gateway    = WC()->payment_gateways->get_available_payment_gateways()[ $gateway_id ] ?? null;

			if ( $gateway && $this->gateway_supports_subscriptions( $gateway ) ) {
				$payment_methods[] = array(
					'id'            => $token->get_id(),
					'gateway_id'    => $gateway_id,
					'gateway_title' => $gateway->get_title(),
					'last4'         => $token->get_last4(),
					'card_type'     => method_exists( $token, 'get_card_type' ) ? $token->get_card_type() : '',
					'expiry'        => method_exists( $token, 'get_expiry_month' ) && method_exists( $token, 'get_expiry_year' )
						? $token->get_expiry_month() . '/' . $token->get_expiry_year()
						: '',
					'is_default'    => $token->is_default(),
					'type'          => $token->get_type(),
				);
			}
		}

		return $payment_methods;
	}

	/**
	 * Update subscription payment method
	 */
	public function update_subscription_payment_method( $subscription_id, $token_id ) {
		global $wpdb;

		// Get the subscription
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscriptions WHERE id = %d",
				$subscription_id
			)
		);

		if ( ! $subscription ) {
			return new WP_Error( 'invalid_subscription', __( 'Invalid subscription ID', 'recurio' ) );
		}

		// Get the payment token
		$token = WC_Payment_Tokens::get( $token_id );

		if ( ! $token || $token->get_user_id() != $subscription->customer_id ) {
			return new WP_Error( 'invalid_token', __( 'Invalid payment method', 'recurio' ) );
		}

		// Update the subscription
		$gateway_id = $token->get_gateway_id();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$result = $wpdb->update(
			"{$wpdb->prefix}recurio_subscriptions",
			array(
				'payment_method'   => $gateway_id,
				'payment_token_id' => $token_id,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $subscription_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( $result !== false ) {
			// Log the event
			do_action( 'recurio_payment_method_updated', $subscription_id, $gateway_id, $token_id );

			// Send notification email
			$email_notifications = Recurio_Email_Notifications::get_instance();
			if ( method_exists( $email_notifications, 'send_payment_method_updated_email' ) ) {
				$email_notifications->send_payment_method_updated_email( $subscription_id );
			}

			return true;
		}

		return new WP_Error( 'update_failed', __( 'Failed to update payment method', 'recurio' ) );
	}

	/**
	 * Handle payment token added
	 */
	public function handle_payment_token_added( $token_id, $token, $customer_id, $customer ) {
		// Check if this is from subscription portal
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce hook callback
		if ( isset( $_POST['recurio_subscription_id'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce hook callback
			$subscription_id = intval( $_POST['recurio_subscription_id'] );
			$this->update_subscription_payment_method( $subscription_id, $token_id );
		}
	}

	/**
	 * Handle payment token deleted
	 */
	public function handle_payment_token_deleted( $token_id, $token ) {
		global $wpdb;

		// Check if any subscriptions use this token
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}recurio_subscriptions
             WHERE payment_token_id = %d AND status IN ('active', 'paused')",
				$token_id
			)
		);

		// Clear the payment token from affected subscriptions
		foreach ( $subscriptions as $subscription ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
			$wpdb->update(
				"{$wpdb->prefix}recurio_subscriptions",
				array( 'payment_token_id' => null ),
				array( 'id' => $subscription->id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Handle payment token set as default
	 */
	public function handle_payment_token_set_default( $token_id, $token ) {
		// Optional: Update subscriptions to use the new default payment method
		// This depends on your business logic
	}

	/**
	 * Check if Stripe is available
	 */
	public function is_stripe_available() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		return isset( $gateways['stripe'] ) || isset( $gateways['stripe_cc'] ) || isset( $gateways['stripe_sepa'] );
	}

	/**
	 * Check if PayPal is available
	 */
	public function is_paypal_available() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		return isset( $gateways['paypal'] ) || isset( $gateways['ppec_paypal'] ) || isset( $gateways['paypal_express'] );
	}
}
