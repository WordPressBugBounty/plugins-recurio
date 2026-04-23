<?php
/**
 * WooCommerce Integration
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_WooCommerce_Integration {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {

		// Cart and checkout
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_subscription_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_subscription_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_subscription_order_item_meta' ), 10, 4 );

		// Subscribe & Save: Frontend purchase option selector
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'display_subscribe_save_options' ), 15 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );

		// Custom button text for subscription products
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'subscription_add_to_cart_text' ), 10, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( $this, 'subscription_add_to_cart_text' ), 10, 2 );

		// Handle sign-up fees in cart
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'add_signup_fee_to_cart_item' ), 10, 1 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'display_cart_item_signup_fee' ), 10, 3 );

		// Validate subscription limits
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_subscription_limit' ), 10, 5 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_subscription_limits' ) );

		// Filter payment gateways for subscription products (high priority to run after other filters)
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_payment_gateways_for_subscriptions' ), 999 );

		// Force payment methods to show for free trial subscriptions (even when cart total is 0)
		add_filter( 'woocommerce_cart_needs_payment', array( $this, 'cart_needs_payment_for_subscriptions' ), 10, 2 );

		// Force Stripe to save payment methods for subscription checkouts
		add_filter( 'wc_stripe_force_save_source', array( $this, 'force_stripe_save_source' ), 10, 2 );
		add_filter( 'wc_stripe_save_to_subs_checked', array( $this, 'stripe_save_to_subs_checked' ) );

		// Also hook into the AJAX update checkout to ensure filtering
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'force_payment_gateway_refresh' ) );

		// Enqueue checkout scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );

		// Order processing - use single comprehensive hook to avoid duplicate calls
		// woocommerce_payment_complete fires when payment is successful (covers most scenarios)
		add_action( 'woocommerce_payment_complete', array( $this, 'create_subscription_from_order' ) );

		// Order status change hook as fallback for edge cases (e.g., manual status changes, offline payments)
		// This handles all status transitions in one place
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 10, 3 );

		// Order deletion hooks - delete associated subscriptions
		add_action( 'before_delete_post', array( $this, 'handle_order_deletion' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'handle_order_trash' ), 10, 1 );
		add_action( 'untrash_post', array( $this, 'handle_order_untrash' ), 10, 1 );

		// Product pricing display
		add_filter( 'woocommerce_get_price_html', array( $this, 'subscription_price_html' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'subscription_cart_price_html' ), 10, 3 );

		// Admin columns
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_subscription_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'display_subscription_order_column' ), 10, 2 );

		// AJAX handlers
		add_action( 'wp_ajax_recurio_get_subscription_variations', array( $this, 'ajax_get_subscription_variations' ) );
		add_action( 'wp_ajax_nopriv_recurio_get_subscription_variations', array( $this, 'ajax_get_subscription_variations' ) );
	}

	/**
	 * Add subscription product type
	 */
	public function add_subscription_product_type( $types ) {
		$types['subscription']          = esc_html__( 'Subscription product', 'recurio' );
		$types['variable_subscription'] = esc_html__( 'Variable subscription', 'recurio' );
		return $types;
	}

	/**
	 * Add subscription tab to product data
	 */
	public function add_subscription_product_tab( $tabs ) {
		$tabs['subscription'] = array(
			'label'    => esc_html__( 'Subscription', 'recurio' ),
			'target'   => 'subscription_product_data',
			'class'    => array( 'show_if_subscription', 'show_if_variable_subscription' ),
			'priority' => 11,
		);
		return $tabs;
	}

	/**
	 * Filter payment gateways for subscription products in cart
	 */
	public function filter_payment_gateways_for_subscriptions( $available_gateways ) {

		// Only filter on checkout page, during AJAX requests, or payment processing
		if ( ! is_checkout() && ! wp_doing_ajax() && ! is_checkout_pay_page() ) {
			return $available_gateways;
		}

		// Check if cart exists and has items
		if ( ! WC()->cart ) {
			return $available_gateways;
		}

		if ( WC()->cart->is_empty() ) {
			return $available_gateways;
		}

		$has_subscription      = false;
		$subscription_products = array();

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			// Check purchase type - one-time purchases don't need subscription payment filtering
			$purchase_type = isset( $cart_item['recurio_purchase_type'] ) ? $cart_item['recurio_purchase_type'] : null;

			// Skip if this is a one-time purchase from Subscribe & Save
			if ( $purchase_type === 'one-time' ) {
				continue;
			}

			// Only check for subscription if it's explicitly a subscription purchase
			// or if no purchase type is set (regular subscription product without Subscribe & Save)
			if ( $purchase_type !== 'subscription' && $purchase_type !== null ) {
				continue; // Unknown purchase type, skip
			}

			// Check if cart item has subscription meta
			if ( ! empty( $cart_item['subscription'] ) ) {
				$has_subscription        = true;
				$subscription_products[] = $cart_item['data']->get_name();
				continue;
			}

			// Also check if product is a subscription type
			$product = $cart_item['data'];
			if ( $product && $this->is_subscription_product( $product ) ) {
				$has_subscription = true;
				if ( ! in_array( $product->get_name(), $subscription_products ) ) {
					$subscription_products[] = $product->get_name();
				}
			}
		}

		// If no subscription products in cart, return all gateways
		if ( ! $has_subscription ) {
			return $available_gateways;
		}

		// Get allowed payment methods from settings
		$settings        = get_option( 'recurio_settings', array() );
		$allowed_methods = isset( $settings['billing']['allowedPaymentMethods'] )
			? $settings['billing']['allowedPaymentMethods']
			: array();

		// Define offline payment methods that should be excluded by default
		$offline_methods = array( 'cod', 'bacs', 'cheque' );

		// If no settings configured, use intelligent defaults
		if ( empty( $allowed_methods ) ) {
			// Allow online payment methods by default, exclude offline ones
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( in_array( $gateway_id, $offline_methods ) ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		} else {
			// Filter gateways based on settings
			$gateways_to_remove = array();

			foreach ( $available_gateways as $gateway_id => $gateway ) {
				// Check if this gateway is explicitly set in our settings
				if ( isset( $allowed_methods[ $gateway_id ] ) ) {
					// If it's set to false (disabled), mark for removal
					if ( empty( $allowed_methods[ $gateway_id ] ) || $allowed_methods[ $gateway_id ] === false || $allowed_methods[ $gateway_id ] === 'false' || $allowed_methods[ $gateway_id ] === '0' ) {
						$gateways_to_remove[] = $gateway_id;
					}
				} else {
					// Gateway not in our settings - apply default rules
					// By default, disable known offline payment methods for subscriptions
					if ( in_array( $gateway_id, $offline_methods ) ) {
						$gateways_to_remove[] = $gateway_id;
					}
				}
			}

			// Remove marked gateways
			foreach ( $gateways_to_remove as $gateway_id ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}

		// Add a notice if all payment methods were filtered out
		if ( empty( $available_gateways ) ) {
			wc_add_notice(
				esc_html__( 'No payment methods are available for subscription products. Please contact the store administrator.', 'recurio' ),
				'error'
			);
		}

		return $available_gateways;
	}

	/**
	 * Force cart to need payment for subscription products with free trial
	 * This ensures payment methods are shown even when cart total is 0
	 * so we can capture payment method for future recurring charges
	 *
	 * @param bool   $needs_payment Whether the cart needs payment.
	 * @param object $cart The cart object.
	 * @return bool
	 */
	public function cart_needs_payment_for_subscriptions( $needs_payment, $cart ) {
		// If already needs payment, return true
		if ( $needs_payment ) {
			return true;
		}

		// Check if cart has subscription products
		if ( ! $cart || $cart->is_empty() ) {
			return $needs_payment;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			// Skip one-time purchases from Subscribe & Save
			if ( isset( $cart_item['recurio_purchase_type'] ) && $cart_item['recurio_purchase_type'] === 'one-time' ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( ! $product ) {
				continue;
			}

			// Check if this is a subscription product
			if ( $this->is_subscription_product( $product ) ) {
				// For subscription products, we always need payment method
				// to capture card/payment details for future recurring charges
				return true;
			}

			// Also check cart item subscription meta
			if ( ! empty( $cart_item['subscription'] ) ) {
				return true;
			}
		}

		return $needs_payment;
	}

	/**
	 * Force Stripe to save payment source for subscription orders
	 * This ensures payment methods are saved even for free trial (zero-total) checkouts
	 *
	 * @param bool     $force_save Whether to force save.
	 * @param WC_Order $order The order object.
	 * @return bool
	 */
	public function force_stripe_save_source( $force_save, $order = null ) {
		// If already forcing save, return true
		if ( $force_save ) {
			return true;
		}

		// Check cart for subscription products
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				// Skip one-time purchases
				if ( isset( $cart_item['recurio_purchase_type'] ) && $cart_item['recurio_purchase_type'] === 'one-time' ) {
					continue;
				}

				$product = $cart_item['data'];
				if ( $product && $this->is_subscription_product( $product ) ) {
					return true;
				}

				if ( ! empty( $cart_item['subscription'] ) ) {
					return true;
				}
			}
		}

		// Also check order items if order is provided
		if ( $order && is_a( $order, 'WC_Order' ) ) {
			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_product_id();
				if ( get_post_meta( $product_id, '_recurio_subscription_enabled', true ) === 'yes' ) {
					return true;
				}
			}
		}

		return $force_save;
	}

	/**
	 * Force "save payment info" checkbox to be checked for subscriptions
	 *
	 * @param bool $checked Whether the checkbox is checked.
	 * @return bool
	 */
	public function stripe_save_to_subs_checked( $checked ) {
		// Check if cart has subscription products
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				// Skip one-time purchases
				if ( isset( $cart_item['recurio_purchase_type'] ) && $cart_item['recurio_purchase_type'] === 'one-time' ) {
					continue;
				}

				$product = $cart_item['data'];
				if ( $product && $this->is_subscription_product( $product ) ) {
					return true;
				}

				if ( ! empty( $cart_item['subscription'] ) ) {
					return true;
				}
			}
		}

		return $checked;
	}

	/**
	 * Enqueue checkout scripts
	 */
	public function enqueue_checkout_scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		// Check if cart has subscription products (not one-time purchases)
		$has_subscription = false;
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$purchase_type = isset( $cart_item['recurio_purchase_type'] ) ? $cart_item['recurio_purchase_type'] : null;

				// Skip one-time purchases - they don't need subscription checkout handling
				if ( $purchase_type === 'one-time' ) {
					continue;
				}

				// Only consider as subscription if:
				// 1. Has subscription data in cart, OR
				// 2. Product is subscription product AND purchase type is 'subscription' or not set
				if ( ! empty( $cart_item['subscription'] ) ) {
					$has_subscription = true;
					break;
				}

				if ( $purchase_type === 'subscription' || $purchase_type === null ) {
					$product = $cart_item['data'];
					if ( $product && $this->is_subscription_product( $product ) ) {
						$has_subscription = true;
						break;
					}
				}
			}
		}

		if ( ! $has_subscription ) {
			return;
		}

		// Enqueue the checkout script
		wp_enqueue_script(
			'recurio-checkout-subscriptions',
			RECURIO_PLUGIN_URL . 'assets/js/checkout-subscriptions.js',
			array( 'jquery', 'wc-checkout' ),
			RECURIO_VERSION,
			true
		);

		// Get settings for allowed payment methods
		$settings        = get_option( 'recurio_settings', array() );
		$allowed_methods = isset( $settings['billing']['allowedPaymentMethods'] )
			? $settings['billing']['allowedPaymentMethods']
			: array();

		// Localize script with data
		wp_localize_script(
			'recurio-checkout-subscriptions',
			'recurio_checkout_params',
			array(
				'allowed_payment_methods'    => $allowed_methods,
				'no_payment_methods_message' => esc_html__( 'No payment methods are available for subscription products. Please contact the store administrator.', 'recurio' ),
				'has_subscription'           => true,
			)
		);
	}

	/**
	 * Force payment gateway refresh on checkout update
	 */
	public function force_payment_gateway_refresh( $checkout_data ) {

		// Parse the checkout data if it's a string
		if ( is_string( $checkout_data ) ) {
			parse_str( $checkout_data, $data );
		}

		// Check if we have subscription products in cart
		if ( WC()->cart && ! WC()->cart->is_empty() ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				// Skip one-time purchases from Subscribe & Save
				if ( isset( $cart_item['recurio_purchase_type'] ) && $cart_item['recurio_purchase_type'] === 'one-time' ) {
					continue;
				}

				if ( ! empty( $cart_item['subscription'] ) ||
					( $cart_item['data'] && $this->is_subscription_product( $cart_item['data'] ) ) ) {
					// Force WooCommerce to refresh available payment gateways
					WC()->payment_gateways()->get_available_payment_gateways();
					break;
				}
			}
		}
	}

	/**
	 * Add subscription data to cart item
	 */
	public function add_subscription_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $this->is_subscription_product( $product ) ) {
			return $cart_item_data;
		}

		// Check for Subscribe & Save selection (Pro feature)
		$is_pro = Recurio_Pro_Manager::get_instance()->is_license_valid();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Cart action, nonce verified by WooCommerce
		$purchase_type = isset( $_POST['recurio_purchase_type'] ) ? sanitize_text_field( wp_unslash( $_POST['recurio_purchase_type'] ) ) : 'subscription';

		// Check if one-time purchase is allowed and selected (Pro feature)
		$allow_one_time = $is_pro && get_post_meta( $product_id, '_recurio_allow_one_time_purchase', true ) === 'yes';

		if ( $allow_one_time && $purchase_type === 'one-time' ) {
			// One-time purchase selected - don't add subscription data
			$cart_item_data['recurio_purchase_type'] = 'one-time';
			return $cart_item_data;
		}

		// Get subscription data - use variation product if available
		$data_product = $variation_id ? wc_get_product( $variation_id ) : $product;
		$subscription_data = $this->get_subscription_data_from_product( $data_product );

		// Ensure we have a price - use product regular price if subscription price is 0
		if ( empty( $subscription_data['price'] ) || $subscription_data['price'] == 0 ) {
			$subscription_data['price'] = $product->get_regular_price();
			if ( empty( $subscription_data['price'] ) ) {
				$subscription_data['price'] = $product->get_price();
			}
		}

		// Apply subscription discount if Subscribe & Save is enabled
		if ( $allow_one_time ) {
			$discount_type  = get_post_meta( $product_id, '_recurio_subscription_discount_type', true ) ?: 'percentage';
			$discount_value = floatval( get_post_meta( $product_id, '_recurio_subscription_discount_value', true ) );

			if ( $discount_value > 0 ) {
				$original_price = floatval( $subscription_data['price'] );

				if ( $discount_type === 'percentage' ) {
					$discount_amount = $original_price * ( $discount_value / 100 );
				} else {
					$discount_amount = $discount_value;
				}

				$subscription_data['price']          = max( 0, $original_price - $discount_amount );
				$subscription_data['original_price'] = $original_price;
				$subscription_data['discount_type']  = $discount_type;
				$subscription_data['discount_value'] = $discount_value;
				$subscription_data['discount_amount'] = $discount_amount;
			}
		}

		// Check for custom billing period (Pro feature)
		$use_custom_period = $is_pro && get_post_meta( $product_id, '_recurio_use_custom_period', true ) === 'yes';
		if ( $use_custom_period ) {
			$subscription_data['interval'] = get_post_meta( $product_id, '_recurio_subscription_billing_interval', true ) ?: 1;
			$subscription_data['period']   = get_post_meta( $product_id, '_recurio_subscription_billing_unit', true ) ?: 'month';
		}

		// Add split payment info to subscription data
		$payment_type = get_post_meta( $product_id, '_recurio_payment_type', true ) ?: 'recurring';
		$subscription_data['payment_type'] = $payment_type;

		if ( $payment_type === 'split' ) {
			$max_payments  = intval( get_post_meta( $product_id, '_recurio_max_payments', true ) ) ?: 0;
			$access_timing = get_post_meta( $product_id, '_recurio_access_timing', true ) ?: 'immediate';

			$subscription_data['max_payments']  = $max_payments;
			$subscription_data['access_timing'] = $access_timing;

			// Store total price and calculate installment amount
			if ( $max_payments > 0 ) {
				$subscription_data['total_price']       = floatval( $subscription_data['price'] );
				$subscription_data['installment_price'] = floatval( $subscription_data['price'] ) / $max_payments;
			}
		}

		// Add subscription meta to cart item
		$cart_item_data['subscription']          = $subscription_data;
		$cart_item_data['recurio_purchase_type'] = 'subscription';

		return $cart_item_data;
	}

	/**
	 * Display subscription data in cart
	 */
	public function display_subscription_cart_item_data( $item_data, $cart_item ) {
		// Check if this is a one-time purchase from Subscribe & Save
		if ( isset( $cart_item['recurio_purchase_type'] ) && $cart_item['recurio_purchase_type'] === 'one-time' ) {
			$item_data[] = array(
				'key'   => esc_html__( 'Purchase', 'recurio' ),
				'value' => '<span class="recurio-purchase-type">' . esc_html__( 'One-time purchase', 'recurio' ) . '</span>',
			);
			return $item_data;
		}

		if ( empty( $cart_item['subscription'] ) ) {
			return $item_data;
		}

		$subscription = $cart_item['subscription'];

		// Ensure interval is at least 1
		$interval = max( 1, intval( $subscription['interval'] ) );

		// Billing schedule
		if ( $interval > 1 ) {
			/* translators: %1$d: billing interval (number), %2$s: billing period (days/weeks/months/years) */
			$billing_schedule = sprintf(
				esc_html__( 'Every %1$d %2$s', 'recurio' ),
				$interval,
				$this->get_period_string( $subscription['period'], $interval )
			);
		} else {
			// For interval of 1, just show "Every month" or "Every year" etc.
			/* translators: %s: billing period (month/year/week/day) */
			$billing_schedule = sprintf(
				esc_html__( 'Every %s', 'recurio' ),
				$this->get_period_string( $subscription['period'], 1 )
			);
		}

		$item_data[] = array(
			'key'   => esc_html__( 'Billing', 'recurio' ),
			'value' => '<span class="subscription-details recurio-subscription-indicator" data-subscription="yes" data-recurio-subscription="yes">' . $billing_schedule . '</span>',
		);

		// Subscribe & Save discount
		if ( ! empty( $subscription['discount_amount'] ) && $subscription['discount_amount'] > 0 ) {
			$item_data[] = array(
				'key'   => esc_html__( 'Subscribe & Save', 'recurio' ),
				'value' => '<span class="recurio-discount">-' . wc_price( $subscription['discount_amount'] ) . '</span>',
			);
		}

		// Trial period
		if ( ! empty( $subscription['trial_length'] ) && $subscription['trial_length'] > 0 ) {
			/* translators: %1$d: trial length (number), %2$s: trial period (days/weeks/months/years) */
			$trial_string = sprintf(
				esc_html__( '%1$d %2$s free trial', 'recurio' ),
				$subscription['trial_length'],
				$this->get_period_string( $subscription['trial_period'], $subscription['trial_length'] )
			);

			$item_data[] = array(
				'key'   => esc_html__( 'Trial', 'recurio' ),
				'value' => $trial_string,
			);
		}

		// Sign-up fee
		if ( ! empty( $subscription['sign_up_fee'] ) && $subscription['sign_up_fee'] > 0 ) {
			$item_data[] = array(
				'key'   => esc_html__( 'Sign-up fee', 'recurio' ),
				'value' => wc_price( $subscription['sign_up_fee'] ),
			);
		}

		// Subscription length (max renewals)
		if ( ! empty( $subscription['length'] ) && $subscription['length'] > 0 ) {
			/* translators: %1$d: subscription length (number), %2$s: period string or 'renewals' */
			$length_string = sprintf(
				esc_html__( '%1$d %2$s', 'recurio' ),
				$subscription['length'],
				$interval == 1 ? $this->get_period_string( $subscription['period'], $subscription['length'] ) : esc_html__( 'renewals', 'recurio' )
			);

			$item_data[] = array(
				'key'   => esc_html__( 'Length', 'recurio' ),
				'value' => $length_string,
			);
		}

		// Split payments info
		if ( ! empty( $subscription['payment_type'] ) && $subscription['payment_type'] === 'split' && ! empty( $subscription['max_payments'] ) ) {
			$max_payments     = intval( $subscription['max_payments'] );
			$total_price      = floatval( $subscription['price'] );
			$installment      = $total_price / $max_payments;

			/* translators: %1$d: number of payments, %2$s: installment price */
			$split_string = sprintf(
				esc_html__( '%1$d payments of %2$s', 'recurio' ),
				$max_payments,
				wc_price( $installment )
			);

			$item_data[] = array(
				'key'   => esc_html__( 'Split Payment', 'recurio' ),
				'value' => '<span class="recurio-split-payment">' . $split_string . '</span>',
			);

			// Show total price
			/* translators: %s: total price */
			$item_data[] = array(
				'key'   => esc_html__( 'Total', 'recurio' ),
				'value' => wc_price( $total_price ),
			);
		}

		return $item_data;
	}

	/**
	 * Create subscription from order
	 */
	public function create_subscription_from_order( $order_id ) {

		// Check if order ID is valid
		if ( empty( $order_id ) ) {
			return false;
		}

		// Get the order object
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Delegate to the subscription engine's improved method
		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$result              = $subscription_engine->process_new_subscription( $order_id );

		return $result;
	}

	/**
	 * Activate subscription when order is completed
	 */
	public function activate_subscription_from_order( $order_id ) {
		// Enhanced debug logging

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if order has subscription products
		$has_subscription_products = false;
		$all_items_debug           = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product         = $item->get_product();
			$is_subscription = $product ? $this->is_subscription_product( $product ) : false;

			$all_items_debug[] = array(
				'item_id'         => $item_id,
				'product_id'      => $product ? $product->get_id() : 'none',
				'product_name'    => $item->get_name(),
				'is_subscription' => $is_subscription,
			);

			if ( $is_subscription ) {
				$has_subscription_products = true;
			}
		}

		if ( ! $has_subscription_products ) {
			return;
		}

		$subscription_engine = Recurio_Subscription_Engine::get_instance();
		$activated_count     = 0;

		// Look for subscription IDs in order meta or order items
		$subscription_ids = get_post_meta( $order_id, '_recurio_subscription_ids', true );

		if ( $subscription_ids && is_array( $subscription_ids ) ) {
			// Use subscription IDs from order meta
			foreach ( $subscription_ids as $subscription_id ) {

				// First check if subscription exists
				global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for WooCommerce integration
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
				$subscription = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}recurio_subscriptions WHERE id = %d",
						$subscription_id
					)
				);

				if ( ! $subscription ) {
					continue;
				}

				$result = $subscription_engine->update_subscription(
					$subscription_id,
					array(
						'status' => 'active',
					)
				);

				if ( $result && ! is_wp_error( $result ) ) {
					++$activated_count;
				} else {
				}
			}
		} else {
			// Fallback: Look for subscription IDs in order item meta
			foreach ( $order->get_items() as $item_id => $item ) {
				$subscription_id = wc_get_order_item_meta( $item_id, '_recurio_subscription_id', true );

				if ( ! $subscription_id ) {
					continue;
				}

				// Check if subscription exists and get current status
				global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for WooCommerce integration
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
				$subscription = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}recurio_subscriptions WHERE id = %d",
						$subscription_id
					)
				);

				if ( ! $subscription ) {
					continue;
				}

				// Activate subscription
				$result = $subscription_engine->update_subscription(
					$subscription_id,
					array(
						'status' => 'active',
					)
				);

				if ( $result && ! is_wp_error( $result ) ) {
					++$activated_count;
				} else {
				}
			}
		}

		// Add order note if any subscriptions were activated
		if ( $activated_count > 0 ) {
			/* translators: %d: number of activated subscriptions */
			$order->add_order_note(
				sprintf(
					esc_html__( 'Activated %d subscription(s) when order completed', 'recurio' ),
					$activated_count
				)
			);
		} else {
			$order->add_order_note( esc_html__( 'No subscriptions found to activate', 'recurio' ) );
		}
	}

	/**
	 * Handle order status changes
	 * This is the comprehensive fallback that handles ALL status transitions
	 */
	public function handle_order_status_change( $order_id, $old_status, $new_status ) {

		// Create subscription when order moves to processing or completed
		// This handles: bank transfers, checks, COD, manual admin approvals, etc.
		if ( in_array( $new_status, array( 'processing', 'completed' ) ) ) {
			// Only create if coming from a non-paid status
			if ( in_array( $old_status, array( 'pending', 'on-hold', 'failed' ) ) ) {
				$this->create_subscription_from_order( $order_id );
			}

			// Handle renewal order payment completion (for split payments/installments).
			$this->handle_renewal_order_payment( $order_id );

			// Subscription switch order payment is handled by PRO plugin
		}

		// Activate subscription when order is completed
		// This ensures subscription is active for digital products, services, etc.
		if ( $new_status === 'completed' ) {
			$this->activate_subscription_from_order( $order_id );
		}

		// Handle cancellation
		if ( $new_status === 'cancelled' ) {
			$this->cancel_subscription_from_order( $order_id );
		}

		// Handle refund
		if ( $new_status === 'refunded' ) {
			$this->handle_subscription_refund( $order_id );
		}
	}

	/**
	 * Handle renewal order payment completion.
	 * Updates subscription renewal_count and checks for split payment completion.
	 *
	 * @param int $order_id The order ID.
	 * @since 1.2.0
	 */
	private function handle_renewal_order_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if this is a renewal order.
		$is_renewal_order = $order->get_meta( '_recurio_is_renewal_order', true );
		if ( $is_renewal_order !== 'yes' ) {
			return;
		}

		// Check if we've already processed this renewal order.
		$renewal_processed = $order->get_meta( '_recurio_renewal_processed', true );
		if ( $renewal_processed === 'yes' ) {
			return;
		}

		// Check if this is an early renewal order - revenue already logged by process_early_renewal()
		$is_early_renewal = $order->get_meta( '_recurio_is_early_renewal', true ) === 'yes';

		// Get the subscription ID.
		$subscription_id = $order->get_meta( '_recurio_subscription_id', true );
		if ( ! $subscription_id ) {
			return;
		}

		// Get the subscription.
		global $wpdb;
		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscriptions WHERE id = %d",
				$subscription_id
			)
		);

		if ( ! $subscription ) {
			return;
		}

		$subscription_engine = Recurio_Subscription_Engine::get_instance();

		// For early renewal orders, revenue and renewal count are already logged by process_early_renewal()
		// Only log revenue and update renewal count for regular renewal orders
		if ( ! $is_early_renewal ) {
			// Update renewal count.
			$new_renewal_count = intval( $subscription->renewal_count ) + 1;

			// Log the payment in revenue table.
			$subscription_engine->log_revenue(
				$subscription_id,
				$order->get_total(),
				$order->get_payment_method(),
				$order->get_transaction_id() ?: 'order_' . $order_id
			);

			// Log the renewal event.
			$subscription_engine->log_event(
				$subscription_id,
				'renewal_payment',
				$order->get_total(),
				array(
					'order_id'       => $order_id,
					'payment_method' => $order->get_payment_method(),
					'renewal_count'  => $new_renewal_count,
				)
			);
		} else {
			// Early renewal - renewal count and next_payment_date already updated by process_early_renewal()
			// Just mark order as processed and skip the rest
			$order->update_meta_data( '_recurio_renewal_processed', 'yes' );
			$order->save();
			return;
		}

		// Prepare update data.
		$update_data = array(
			'renewal_count' => $new_renewal_count,
		);

		// Check if this is a split payment subscription and if all payments are done.
		$payment_type = isset( $subscription->payment_type ) ? $subscription->payment_type : 'recurring';
		$max_payments = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;

		if ( $payment_type === 'split' && $max_payments > 0 && $new_renewal_count >= $max_payments ) {
			// All installments paid - mark as completed.
			$update_data['status']            = 'completed';
			$update_data['next_payment_date'] = null;

			// Fire split payment completed action.
			do_action( 'recurio_split_payment_completed', $subscription_id, $new_renewal_count );

			// Log completion event.
			$subscription_engine->log_event(
				$subscription_id,
				'split_payment_completed',
				0,
				array( 'total_payments' => $new_renewal_count )
			);

			$order->add_order_note(
				sprintf(
					/* translators: %1$d: payment number, %2$d: total payments */
					__( 'Final installment (%1$d of %2$d) paid. Subscription completed.', 'recurio' ),
					$new_renewal_count,
					$max_payments
				)
			);
		} else {
			// Calculate next payment date for split payments or regular renewals.
			$next_payment_date = $subscription_engine->calculate_next_payment_date(
				$subscription->billing_period,
				$subscription->billing_interval,
				null,
				true // Skip initial delay since this is a renewal.
			);
			$update_data['next_payment_date'] = $next_payment_date;

			// For split payments with "after_full_payment" access, keep status as pending_payment.
			// For split payments with "immediate" access, set to active if not already.
			$access_timing = isset( $subscription->access_timing ) ? $subscription->access_timing : 'immediate';
			if ( $payment_type === 'split' && $access_timing === 'immediate' && $subscription->status === 'pending_payment' ) {
				$update_data['status'] = 'active';
			}

			$order->add_order_note(
				sprintf(
					/* translators: %1$d: payment number, %2$d: total payments */
					__( 'Installment %1$d of %2$d paid successfully.', 'recurio' ),
					$new_renewal_count,
					$max_payments > 0 ? $max_payments : '∞'
				)
			);
		}

		// Update the subscription.
		$subscription_engine->update_subscription( $subscription_id, $update_data );

		// Mark this renewal order as processed to prevent duplicate processing.
		$order->add_meta_data( '_recurio_renewal_processed', 'yes', true );
		$order->save();
	}

	/**
	 * Modify cart item price HTML for subscriptions
	 */
	public function subscription_cart_price_html( $price, $cart_item, $cart_item_key ) {
		if ( empty( $cart_item['subscription'] ) ) {
			return $price;
		}

		$subscription = $cart_item['subscription'];

		// Build subscription price string
		$subscription_price    = wc_price( $subscription['price'] );
		$billing_period_string = $this->get_period_string( $subscription['period'], $subscription['interval'] );

		if ( $subscription['interval'] > 1 ) {
			/* translators: %1$s: price, %2$d: interval (number), %3$s: period (days/weeks/months/years) */
			$price_string = sprintf(
				esc_html__( '%1$s every %2$d %3$s', 'recurio' ),
				$subscription_price,
				$subscription['interval'],
				$billing_period_string
			);
		} else {
			/* translators: %1$s: price, %2$s: period (day/week/month/year) */
			$price_string = sprintf(
				esc_html__( '%1$s / %2$s', 'recurio' ),
				$subscription_price,
				$billing_period_string
			);
		}

		return $price_string;
	}

	/**
	 * Change add to cart button text for subscription products
	 */
	public function subscription_add_to_cart_text( $text, $product ) {
		// Check if product is a subscription
		if ( ! $this->is_subscription_product( $product ) ) {
			return $text;
		}

		// Get the custom button text from settings
		$settings    = get_option( 'recurio_settings', array() );
		$button_text = isset( $settings['general']['subscriptionButtonText'] ) ? $settings['general']['subscriptionButtonText'] : esc_html__( 'Subscribe Now', 'recurio' );

		// Return the custom text if it's set, otherwise use default
		return ! empty( $button_text ) ? $button_text : $text;
	}

	/**
	 * Validate subscription limit when adding to cart
	 */
	public function validate_subscription_limit( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		// Get the actual product ID (could be variation)
		$actual_product_id = $variation_id ? $variation_id : $product_id;
		$product           = wc_get_product( $actual_product_id );

		if ( ! $this->is_subscription_product( $product ) ) {
			return $passed;
		}

		// Get subscription limit for this product
		$subscription_limit = get_post_meta( $product_id, '_recurio_subscription_limit', true );

		if ( empty( $subscription_limit ) || $subscription_limit == 0 ) {
			return $passed; // No limit set
		}

		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			return $passed; // Can't check limits for guests
		}

		$customer_id = get_current_user_id();

		// Count active subscriptions for this product
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for WooCommerce integration
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription count
		$active_subscriptions = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions
            WHERE customer_id = %d
            AND product_id = %d
            AND status IN ('active', 'paused', 'pending')",
				$customer_id,
				$product_id
			)
		);

		// Check quantity in cart
		$cart_quantity = 0;
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( $cart_item['product_id'] == $product_id ) {
					$cart_quantity += $cart_item['quantity'];
				}
			}
		}

		$total_subscriptions = $active_subscriptions + $cart_quantity + $quantity;

		if ( $total_subscriptions > $subscription_limit ) {
			$remaining = max( 0, $subscription_limit - $active_subscriptions - $cart_quantity );

			if ( $active_subscriptions >= $subscription_limit ) {
				/* translators: %1$d: subscription limit, %2$s: product name */
				wc_add_notice(
					sprintf(
						esc_html__( 'You have already reached the maximum limit of %1$d subscription(s) for "%2$s".', 'recurio' ),
						$subscription_limit,
						$product->get_name()
					),
					'error'
				);
			} else {
				/* translators: %1$d: remaining subscriptions allowed, %2$s: product name, %3$d: active subscription count */
				wc_add_notice(
					sprintf(
						esc_html__( 'You can only add %1$d more subscription(s) for "%2$s". You currently have %3$d active subscription(s).', 'recurio' ),
						$remaining,
						$product->get_name(),
						$active_subscriptions
					),
					'error'
				);
			}
			return false;
		}

		return $passed;
	}

	/**
	 * Validate subscription limits in cart during checkout
	 */
	public function validate_cart_subscription_limits() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$customer_id = get_current_user_id();
		global $wpdb;

		// Group cart items by product
		$cart_products = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( $this->is_subscription_product( $cart_item['data'] ) ) {
				$product_id = $cart_item['product_id'];
				if ( ! isset( $cart_products[ $product_id ] ) ) {
					$cart_products[ $product_id ] = 0;
				}
				$cart_products[ $product_id ] += $cart_item['quantity'];
			}
		}

		// Validate each product's limit
		foreach ( $cart_products as $product_id => $quantity ) {
			$subscription_limit = get_post_meta( $product_id, '_recurio_subscription_limit', true );

			if ( empty( $subscription_limit ) || $subscription_limit == 0 ) {
				continue; // No limit
			}

			// Count existing active subscriptions
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for WooCommerce integration
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription count
			$active_subscriptions = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions
                WHERE customer_id = %d
                AND product_id = %d
                AND status IN ('active', 'paused', 'pending')",
					$customer_id,
					$product_id
				)
			);

			$total = $active_subscriptions + $quantity;

			if ( $total > $subscription_limit ) {
				$product = wc_get_product( $product_id );
				/* translators: %1$d: quantity trying to purchase, %2$s: product name, %3$d: subscription limit, %4$d: active subscription count */
				wc_add_notice(
					sprintf(
						esc_html__( 'Cannot proceed: You are trying to purchase %1$d subscription(s) for "%2$s" but the limit is %3$d and you already have %4$d active subscription(s).', 'recurio' ),
						$quantity,
						$product->get_name(),
						$subscription_limit,
						$active_subscriptions
					),
					'error'
				);
			}
		}
	}

	/**
	 * Add sign-up fee to cart item price
	 */
	public function add_signup_fee_to_cart_item( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Avoid infinite loops
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];

			// Skip one-time purchases from Subscribe & Save
			if ( isset( $cart_item['recurio_purchase_type'] ) && $cart_item['recurio_purchase_type'] === 'one-time' ) {
				continue;
			}

			if ( ! $this->is_subscription_product( $product ) ) {
				continue;
			}

			// Use subscription data from cart item if available (includes split payment info)
			$subscription_data = isset( $cart_item['subscription'] ) ? $cart_item['subscription'] : $this->get_subscription_data_from_product( $product );

			// Handle pricing for first payment
			$has_trial      = ! empty( $subscription_data['trial_length'] ) && $subscription_data['trial_length'] > 0;
			$has_signup_fee = ! empty( $subscription_data['sign_up_fee'] ) && $subscription_data['sign_up_fee'] > 0;

			// Check for split payment
			$is_split_payment = isset( $subscription_data['payment_type'] ) && $subscription_data['payment_type'] === 'split';
			$max_payments     = isset( $subscription_data['max_payments'] ) ? intval( $subscription_data['max_payments'] ) : 0;

			// Store original recurring price (full price for split payments)
			if ( ! isset( $cart_item['subscription_recurring_price'] ) ) {
				$original_price = $product->get_price();
				WC()->cart->cart_contents[ $cart_item_key ]['subscription_recurring_price'] = $original_price;

				// For split payments, store the total price
				if ( $is_split_payment && $max_payments > 0 ) {
					WC()->cart->cart_contents[ $cart_item_key ]['subscription_total_price'] = $original_price;
				}
			}

			// Calculate initial payment
			$initial_price = 0;

			// If there's a trial, don't charge the recurring fee initially
			if ( ! $has_trial ) {
				// Ensure we have a valid price
				$recurring_price = floatval( $subscription_data['price'] );
				if ( $recurring_price == 0 ) {
					// Try to get price from product
					$recurring_price = floatval( $product->get_regular_price() );
					if ( $recurring_price == 0 ) {
						$recurring_price = floatval( $product->get_price() );
					}
				}

				// For split payments, charge only the installment amount
				if ( $is_split_payment && $max_payments > 0 ) {
					$installment_price = $recurring_price / $max_payments;
					$initial_price     = $installment_price;

					// Store split payment info for display
					WC()->cart->cart_contents[ $cart_item_key ]['subscription_is_split']       = true;
					WC()->cart->cart_contents[ $cart_item_key ]['subscription_max_payments']   = $max_payments;
					WC()->cart->cart_contents[ $cart_item_key ]['subscription_installment']    = $installment_price;
					WC()->cart->cart_contents[ $cart_item_key ]['subscription_total_price']    = $recurring_price;
				} else {
					$initial_price = $recurring_price;
				}
			}

			// Add sign-up fee if exists
			if ( $has_signup_fee ) {
				$initial_price += floatval( $subscription_data['sign_up_fee'] );
				WC()->cart->cart_contents[ $cart_item_key ]['subscription_signup_fee'] = $subscription_data['sign_up_fee'];
			}

			// Store trial info for display
			if ( $has_trial ) {
				WC()->cart->cart_contents[ $cart_item_key ]['subscription_trial_length'] = $subscription_data['trial_length'];
				WC()->cart->cart_contents[ $cart_item_key ]['subscription_trial_period'] = $subscription_data['trial_period'];
			}

			// Set the cart item price
			$product->set_price( $initial_price );
		}
	}

	/**
	 * Display cart item with sign-up fee information
	 */
	public function display_cart_item_signup_fee( $subtotal, $cart_item, $cart_item_key ) {
		// Skip one-time purchases from Subscribe & Save
		if ( isset( $cart_item['recurio_purchase_type'] ) && $cart_item['recurio_purchase_type'] === 'one-time' ) {
			return $subtotal;
		}

		if ( ! $this->is_subscription_product( $cart_item['data'] ) ) {
			return $subtotal;
		}

		$details         = array();
		$recurring_price = isset( $cart_item['subscription_recurring_price'] ) ? $cart_item['subscription_recurring_price'] : $cart_item['data']->get_price();

		// Add recurring price info
		$details[] = sprintf(
			'%s %s',
			esc_html__( 'Recurring:', 'recurio' ),
			wc_price( $recurring_price * $cart_item['quantity'] )
		);

		// Add trial info if exists
		if ( ! empty( $cart_item['subscription_trial_length'] ) ) {
			$trial_length = $cart_item['subscription_trial_length'];
			$trial_period = isset( $cart_item['subscription_trial_period'] ) ? $cart_item['subscription_trial_period'] : 'day';
			$details[]    = sprintf(
				'%d %s %s',
				$trial_length,
				$trial_length > 1 ? $trial_period . 's' : $trial_period,
				esc_html__( 'free trial', 'recurio' )
			);
		}

		// Add sign-up fee if exists
		if ( ! empty( $cart_item['subscription_signup_fee'] ) ) {
			$signup_fee = $cart_item['subscription_signup_fee'];
			$details[]  = sprintf(
				'%s %s',
				esc_html__( 'Sign-up fee:', 'recurio' ),
				wc_price( $signup_fee * $cart_item['quantity'] )
			);
		}

		if ( ! empty( $details ) ) {
			$subtotal_text = sprintf(
				'<span class="subscription-details">%s<br/><small>%s</small></span>',
				$subtotal,
				implode( ' + ', $details )
			);
			return $subtotal_text;
		}

		return $subtotal;
	}

	/**
	 * Modify product price HTML for subscriptions
	 */
	public function subscription_price_html( $price, $product ) {
		if ( ! $this->is_subscription_product( $product ) ) {
			return $price;
		}

		$subscription_data = $this->get_subscription_data_from_product( $product );

		// Build subscription price string
		$subscription_price    = wc_price( $subscription_data['price'] );
		$billing_period_string = $this->get_period_string( $subscription_data['period'], $subscription_data['interval'] );

		if ( $subscription_data['interval'] > 1 ) {
			/* translators: %1$s: price, %2$d: interval (number), %3$s: period (days/weeks/months/years) */
			$price_string = sprintf(
				esc_html__( '%1$s every %2$d %3$s', 'recurio' ),
				$subscription_price,
				$subscription_data['interval'],
				$billing_period_string
			);
		} else {
			/* translators: %1$s: price, %2$s: period (day/week/month/year) */
			$price_string = sprintf(
				esc_html__( '%1$s / %2$s', 'recurio' ),
				$subscription_price,
				$billing_period_string
			);
		}

		// Add trial period if exists
		if ( ! empty( $subscription_data['trial_length'] ) && $subscription_data['trial_length'] > 0 ) {
			/* translators: %1$d: trial length (number), %2$s: trial period (days/weeks/months/years) */
			$trial_string  = sprintf(
				esc_html__( ' with %1$d %2$s free trial', 'recurio' ),
				$subscription_data['trial_length'],
				$this->get_period_string( $subscription_data['trial_period'], $subscription_data['trial_length'] )
			);
			$price_string .= $trial_string;
		}

		// Add sign-up fee if exists
		if ( ! empty( $subscription_data['sign_up_fee'] ) && $subscription_data['sign_up_fee'] > 0 ) {
			/* translators: %s: sign-up fee amount */
			$sign_up_string = sprintf(
				esc_html__( ' and %s sign-up fee', 'recurio' ),
				wc_price( $subscription_data['sign_up_fee'] )
			);
			$price_string  .= $sign_up_string;
		}

		return $price_string;
	}

	/**
	 * Check if product is a subscription
	 */
	public function is_subscription_product( $product ) {
		if ( ! $product ) {
			return false;
		}

		// Check if product has subscription meta data
		$product_id = $product->get_id();

		// For variations, check parent product
		if ( $product->get_type() === 'variation' ) {
			$parent_id       = $product->get_parent_id();
			$is_subscription = get_post_meta( $parent_id, '_recurio_subscription_enabled', true );
			if ( $is_subscription === 'yes' ) {
				return true;
			}else{
				return false;
			}
		}

		// Check for our subscription metadata (primary check)
		$is_subscription = get_post_meta( $product_id, '_recurio_subscription_enabled', true );
		if ( $is_subscription === 'yes' ) {
			return true;
		}else{
			return false;
		}

		// Legacy check for old subscription price/period fields
		$subscription_price  = get_post_meta( $product_id, '_subscription_price', true );
		$subscription_period = get_post_meta( $product_id, '_subscription_period', true );

		if ( ! empty( $subscription_price ) || ! empty( $subscription_period ) ) {
			return true;
		}else{
			return false;
		}

		// Check product type (for compatibility with other subscription plugins)
		$product_type = $product->get_type();
		return in_array( $product_type, array( 'subscription', 'variable_subscription', 'variation' ) );
	}

	/**
	 * Get subscription data from product
	 */
	private function get_subscription_data_from_product( $product ) {
		$product_id = $product->get_id();

		// For variations, check parent product for subscription enabled status
		$parent_id = null;
		if ( $product->get_type() === 'variation' ) {
			$parent_id  = $product->get_parent_id();
			$enabled    = get_post_meta( $parent_id, '_recurio_subscription_enabled', true );
		} else {
			$enabled = get_post_meta( $product_id, '_recurio_subscription_enabled', true );
		}

		if ( $enabled === 'yes' ) {
			// Determine which product ID to use for settings (parent for variations)
			$settings_product_id = $parent_id ? $parent_id : $product_id;

			// Check for custom billing period first (Pro feature)
			$is_pro            = Recurio_Pro_Manager::get_instance()->is_license_valid();
			$use_custom_period = $is_pro && get_post_meta( $settings_product_id, '_recurio_use_custom_period', true ) === 'yes';

			if ( $use_custom_period ) {
				// Use custom billing period settings
				$interval = intval( get_post_meta( $settings_product_id, '_recurio_subscription_billing_interval', true ) ) ?: 1;
				$period   = get_post_meta( $settings_product_id, '_recurio_subscription_billing_unit', true ) ?: 'month';
			} else {
				// Use standard billing period
				$billing_period = get_post_meta( $settings_product_id, '_recurio_subscription_billing_period', true );

				// Fallback to periods array for backward compatibility
				if ( ! $billing_period ) {
					$periods        = get_post_meta( $settings_product_id, '_recurio_subscription_periods', true );
					$periods        = $periods ? maybe_unserialize( $periods ) : array( 'monthly' );
					$billing_period = reset( $periods );
				}

				$billing_period = $billing_period ?: 'monthly';

				// Convert period names
				$period_map = array(
					'daily'     => 'day',
					'weekly'    => 'week',
					'monthly'   => 'month',
					'quarterly' => 'month', // Will use interval of 3
					'yearly'    => 'year',
				);

				$period   = isset( $period_map[ $billing_period ] ) ? $period_map[ $billing_period ] : 'month';
				$interval = ( $billing_period === 'quarterly' ) ? 3 : 1;
			}

			$subscription_data = array(
				'price'            => $product->get_price(),
				'period'           => $period,
				'interval'         => $interval,
				'length'           => get_post_meta( $settings_product_id, '_recurio_subscription_length', true ) ?: 0,
				'trial_length'     => get_post_meta( $settings_product_id, '_recurio_subscription_trial_days', true ) ?: 0,
				'trial_period'     => 'day',
				'sign_up_fee'      => get_post_meta( $settings_product_id, '_recurio_subscription_signup_fee', true ) ?: 0,
				'limit'            => get_post_meta( $settings_product_id, '_recurio_subscription_limit', true ) ?: 0,
				'auto_renewal'     => get_post_meta( $settings_product_id, '_recurio_subscription_auto_renewal', true ) === 'yes',
				'renewal_reminder' => get_post_meta( $settings_product_id, '_recurio_subscription_renewal_reminder', true ) === 'yes',
			);

			/**
			 * Filter subscription data from product.
			 *
			 * Allows PRO features to modify subscription data for variations.
			 *
			 * @param array      $subscription_data Subscription data array.
			 * @param WC_Product $product           Product object.
			 * @since 1.2.0
			 */
			return apply_filters( 'recurio_get_subscription_data', $subscription_data, $product );
		}

		// Fallback to legacy fields
		return array(
			'price'        => get_post_meta( $product_id, '_subscription_price', true ) ?: $product->get_price(),
			'period'       => get_post_meta( $product_id, '_subscription_period', true ) ?: 'month',
			'interval'     => get_post_meta( $product_id, '_subscription_interval', true ) ?: 1,
			'length'       => get_post_meta( $product_id, '_subscription_length', true ) ?: 0,
			'trial_length' => get_post_meta( $product_id, '_subscription_trial_length', true ) ?: 0,
			'trial_period' => get_post_meta( $product_id, '_subscription_trial_period', true ) ?: 'day',
			'sign_up_fee'  => get_post_meta( $product_id, '_subscription_sign_up_fee', true ) ?: 0,
		);
	}

	/**
	 * Get period string for display
	 */
	private function get_period_string( $period, $interval ) {
		$periods = array(
			'day'   => _n( 'day', 'days', $interval, 'recurio' ),
			'week'  => _n( 'week', 'weeks', $interval, 'recurio' ),
			'month' => _n( 'month', 'months', $interval, 'recurio' ),
			'year'  => _n( 'year', 'years', $interval, 'recurio' ),
		);

		return isset( $periods[ $period ] ) ? $periods[ $period ] : $period;
	}

	/**
	 * Get DateInterval from period and length
	 */
	private function get_date_interval( $period, $length ) {
		switch ( $period ) {
			case 'day':
				return new DateInterval( 'P' . $length . 'D' );
			case 'week':
				return new DateInterval( 'P' . ( $length * 7 ) . 'D' );
			case 'month':
				return new DateInterval( 'P' . $length . 'M' );
			case 'year':
				return new DateInterval( 'P' . $length . 'Y' );
			default:
				return new DateInterval( 'P' . $length . 'D' );
		}
	}

	// REMOVED: load_subscription_template() - Template loading handled by Customer Portal class

	/**
	 * Add subscription order item meta during checkout
	 */
	public function add_subscription_order_item_meta( $item, $cart_item_key, $values, $order ) {
		// Save purchase type (one-time or subscription) from Subscribe & Save
		if ( isset( $values['recurio_purchase_type'] ) ) {
			$item->add_meta_data( '_recurio_purchase_type', $values['recurio_purchase_type'], true );
		}

		// Only save subscription data for subscription purchases
		if ( ! empty( $values['subscription'] ) && ( ! isset( $values['recurio_purchase_type'] ) || $values['recurio_purchase_type'] === 'subscription' ) ) {
			foreach ( $values['subscription'] as $key => $value ) {
				if ( ! empty( $value ) ) {
					$item->add_meta_data( '_subscription_' . $key, $value, true );
				}
			}
		}
	}

	/**
	 * Cancel subscription when order is cancelled
	 */
	public function cancel_subscription_from_order( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$subscription_engine = Recurio_Subscription_Engine::get_instance();

		foreach ( $order->get_items() as $item_id => $item ) {
			$subscription_id = wc_get_order_item_meta( $item_id, '_recurio_subscription_id', true );

			if ( ! $subscription_id ) {
				continue;
			}

			// Cancel subscription
			$subscription_engine->cancel_subscription( $subscription_id, 'Order cancelled' );

			// Add order note
			/* translators: %d: subscription ID */
			$order->add_order_note(
				sprintf(
					esc_html__( 'Subscription #%d cancelled', 'recurio' ),
					$subscription_id
				)
			);
		}
	}

	/**
	 * Handle subscription refund
	 */
	public function handle_subscription_refund( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Add order note
		$order->add_order_note( esc_html__( 'Subscription refund processed', 'recurio' ) );
	}

	/**
	 * Handle order deletion - permanently delete associated subscriptions
	 */
	public function handle_order_deletion( $post_id ) {
		// Check if this is a shop_order post type
		$post_type = get_post_type( $post_id );
		if ( $post_type !== 'shop_order' ) {
			return;
		}

		// Get associated subscriptions
		$subscription_ids = $this->get_order_subscription_ids( $post_id );

		if ( empty( $subscription_ids ) ) {
			return;
		}

		// Delete each subscription
		global $wpdb;
		$table_name = $wpdb->prefix . 'recurio_subscriptions';

		foreach ( $subscription_ids as $subscription_id ) {
			// Delete subscription from database
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for WooCommerce integration
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for subscription deletion
			$result = $wpdb->delete(
				$table_name,
				array( 'id' => $subscription_id ),
				array( '%d' )
			);

			if ( $result !== false ) {

				// Delete related subscription events
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for WooCommerce integration
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for event deletion
				$wpdb->delete(
					$wpdb->prefix . 'recurio_subscription_events',
					array( 'subscription_id' => $subscription_id ),
					array( '%d' )
				);

				// Delete related revenue records
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for WooCommerce integration
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for revenue deletion
				$wpdb->delete(
					$wpdb->prefix . 'recurio_subscription_revenue',
					array( 'subscription_id' => $subscription_id ),
					array( '%d' )
				);

				// Trigger action for other plugins to handle subscription deletion
				do_action( 'recurio_subscription_deleted', $subscription_id, $post_id );
			} else {
			}
		}
	}

	/**
	 * Handle order trash - cancel associated subscriptions
	 */
	public function handle_order_trash( $post_id ) {
		// Check if this is a shop_order post type
		$post_type = get_post_type( $post_id );
		if ( $post_type !== 'shop_order' ) {
			return;
		}

		// Get associated subscriptions
		$subscription_ids = $this->get_order_subscription_ids( $post_id );

		if ( empty( $subscription_ids ) ) {
			return;
		}

		$subscription_engine = Recurio_Subscription_Engine::get_instance();

		foreach ( $subscription_ids as $subscription_id ) {
			// Cancel subscription (not delete, in case order is restored)
			$result = $subscription_engine->update_subscription(
				$subscription_id,
				array(
					'status'              => esc_html__( 'cancelled', 'recurio' ),
					'cancellation_reason' => esc_html__( 'Order moved to trash', 'recurio' ),
				)
			);

			if ( $result && ! is_wp_error( $result ) ) {

				// Store the previous status in meta so we can restore it if needed
				update_post_meta( $post_id, '_recurio_subscription_' . $subscription_id . '_prev_status', 'active' );
			}
		}
	}

	/**
	 * Handle order untrash - restore associated subscriptions
	 */
	public function handle_order_untrash( $post_id ) {
		// Check if this is a shop_order post type
		$post_type = get_post_type( $post_id );
		if ( $post_type !== 'shop_order' ) {
			return;
		}

		// Get associated subscriptions
		$subscription_ids = $this->get_order_subscription_ids( $post_id );

		if ( empty( $subscription_ids ) ) {
			return;
		}

		$subscription_engine = Recurio_Subscription_Engine::get_instance();

		foreach ( $subscription_ids as $subscription_id ) {
			// Get the previous status if stored
			$prev_status    = get_post_meta( $post_id, '_recurio_subscription_' . $subscription_id . '_prev_status', true );
			$restore_status = $prev_status ?: 'active';

			// Restore subscription to previous status
			$result = $subscription_engine->update_subscription(
				$subscription_id,
				array(
					'status'              => $restore_status,
					'cancellation_reason' => '',
				)
			);

			if ( $result && ! is_wp_error( $result ) ) {

				// Clean up the temporary meta
				delete_post_meta( $post_id, '_recurio_subscription_' . $subscription_id . '_prev_status' );
			}
		}
	}

	/**
	 * Get subscription IDs associated with an order
	 */
	private function get_order_subscription_ids( $order_id ) {
		$subscription_ids = array();

		// First check order meta for subscription IDs
		$meta_subscription_ids = get_post_meta( $order_id, '_recurio_subscription_ids', true );
		if ( $meta_subscription_ids && is_array( $meta_subscription_ids ) ) {
			$subscription_ids = array_merge( $subscription_ids, $meta_subscription_ids );
		}

		// Also check order items for subscription IDs
		$order = wc_get_order( $order_id );
		if ( $order ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				$item_subscription_id = wc_get_order_item_meta( $item_id, '_recurio_subscription_id', true );
				if ( $item_subscription_id && ! in_array( $item_subscription_id, $subscription_ids ) ) {
					$subscription_ids[] = $item_subscription_id;
				}
			}
		}

		// Also check database directly for subscriptions linked to this order
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for WooCommerce integration
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription lookup
		$db_subscriptions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}recurio_subscriptions WHERE wc_subscription_id = %d",
				$order_id
			)
		);

		if ( ! empty( $db_subscriptions ) ) {
			$subscription_ids = array_merge( $subscription_ids, $db_subscriptions );
		}

		// Remove duplicates and return
		return array_unique( array_filter( $subscription_ids ) );
	}

	/**
	 * Add subscription column to orders list
	 */
	public function add_subscription_order_column( $columns ) {
		$columns['subscription'] = esc_html__( 'Subscription', 'recurio' );
		return $columns;
	}

	/**
	 * Display subscription column content
	 */
	public function display_subscription_order_column( $column, $post_id ) {
		if ( $column === 'subscription' ) {
			$order            = wc_get_order( $post_id );
			$has_subscription = false;

			foreach ( $order->get_items() as $item_id => $item ) {
				$subscription_id = wc_get_order_item_meta( $item_id, '_recurio_subscription_id', true );
				$subscription_id = $subscription_id ? $subscription_id : $order->get_meta( '_recurio_subscription_id', true );
				if ( $subscription_id ) {
					$has_subscription = true;
					// Link to Vue.js dashboard with subscription details modal
					echo '<a href="' . esc_url( admin_url( 'admin.php?page=recurio#/subscriptions?view=' . $subscription_id ) ) . '" class="recurio-subscription-link">';
					echo '#' . esc_html( $subscription_id );
					echo '</a><br>';
				}
			}

			if ( ! $has_subscription ) {
				echo '—';
			}
		}
	}

	/**
	 * AJAX handler for getting subscription variations
	 */
	public function ajax_get_subscription_variations() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Nonce verified above, intval provides sanitization
		$product_id = intval( $_POST['product_id'] );
		$product    = wc_get_product( $product_id );

		if ( ! $product || $product->get_type() !== 'variable_subscription' ) {
			wp_send_json_error( 'Invalid product' );
		}

		$variations           = array();
		$available_variations = $product->get_available_variations();

		foreach ( $available_variations as $variation_data ) {
			$variation    = wc_get_product( $variation_data['variation_id'] );
			$variations[] = array(
				'id'                => $variation->get_id(),
				'attributes'        => $variation->get_variation_attributes(),
				'price'             => $variation->get_price(),
				'subscription_data' => $this->get_subscription_data_from_product( $variation ),
			);
		}

		wp_send_json_success( $variations );
	}

	/**
	 * Display Subscribe & Save options on product page
	 */
	public function display_subscribe_save_options() {
		global $product;

		if ( ! $product ) {
			return;
		}

		// Subscribe & Save is a Pro feature
		if ( ! Recurio_Pro_Manager::get_instance()->is_license_valid() ) {
			return;
		}

		// Check if this is a subscription product
		if ( ! $this->is_subscription_product( $product ) ) {
			return;
		}

		$product_id = $product->get_id();

		// Check if Subscribe & Save (one-time purchase option) is enabled
		$allow_one_time = get_post_meta( $product_id, '_recurio_allow_one_time_purchase', true ) === 'yes';

		if ( ! $allow_one_time ) {
			return; // Only subscription mode, no options to show
		}

		// Get subscription data
		$subscription_data = $this->get_subscription_data_from_product( $product );

		// Get discount settings
		$discount_type  = get_post_meta( $product_id, '_recurio_subscription_discount_type', true ) ?: 'percentage';
		$discount_value = floatval( get_post_meta( $product_id, '_recurio_subscription_discount_value', true ) );
		$show_savings   = get_post_meta( $product_id, '_recurio_show_savings', true ) !== 'no';

		// Get prices
		$regular_price = floatval( $product->get_regular_price() );
		if ( ! $regular_price ) {
			$regular_price = floatval( $product->get_price() );
		}

		// Calculate subscription price with discount
		$subscription_price = $regular_price;
		$savings_amount     = 0;
		$savings_percent    = 0;

		if ( $discount_value > 0 ) {
			if ( $discount_type === 'percentage' ) {
				$savings_percent    = $discount_value;
				$savings_amount     = $regular_price * ( $discount_value / 100 );
				$subscription_price = $regular_price - $savings_amount;
			} else {
				$savings_amount     = $discount_value;
				$subscription_price = $regular_price - $discount_value;
				$savings_percent    = ( $savings_amount / $regular_price ) * 100;
			}
			$subscription_price = max( 0, $subscription_price );
		}

		// Get billing period display
		$billing_period_text = $this->get_billing_period_text( $product_id, $subscription_data );

		// Output the Subscribe & Save options
		?>
		<div class="recurio-purchase-options">
			<label class="recurio-option">
				<input type="radio" name="recurio_purchase_type" value="one-time" />
				<span class="recurio-option-content">
					<span class="recurio-option-label"><?php echo esc_html__( 'One-time purchase', 'recurio' ); ?></span>
					<span class="recurio-option-price"><?php echo wp_kses_post( wc_price( $regular_price ) ); ?></span>
				</span>
			</label>

			<label class="recurio-option recurio-option-subscription">
				<input type="radio" name="recurio_purchase_type" value="subscription" checked="checked" />
				<span class="recurio-option-content">
					<span class="recurio-option-label">
						<?php echo esc_html__( 'Subscribe', 'recurio' ); ?>
						<?php if ( $savings_amount > 0 && $show_savings ) : ?>
							<span class="recurio-save-badge"><?php echo esc_html( sprintf( 'Save %s%%', round( $savings_percent ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<?php endif; ?>
					</span>
					<span class="recurio-option-price">
						<?php if ( $savings_amount > 0 ) : ?>
							<del><?php echo wp_kses_post( wc_price( $regular_price ) ); ?></del>
						<?php endif; ?>
						<?php echo wp_kses_post( wc_price( $subscription_price ) ); ?>
						<span class="recurio-billing-period"><?php echo esc_html( $billing_period_text ); ?></span>
					</span>
					<?php if ( $savings_amount > 0 && $show_savings ) : ?>
						<span class="recurio-savings">
							<?php
								/* translators: %s: savings amount */
								echo wp_kses_post( sprintf( 'You save %s', wc_price( $savings_amount ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</span>
					<?php endif; ?>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Get billing period text for display
	 */
	private function get_billing_period_text( $product_id, $subscription_data = array() ) {
		// Check if using custom period (Pro feature)
		$is_pro            = Recurio_Pro_Manager::get_instance()->is_license_valid();
		$use_custom_period = $is_pro && get_post_meta( $product_id, '_recurio_use_custom_period', true ) === 'yes';

		if ( $use_custom_period ) {
			$interval = get_post_meta( $product_id, '_recurio_subscription_billing_interval', true ) ?: 1;
			$unit     = get_post_meta( $product_id, '_recurio_subscription_billing_unit', true ) ?: 'month';

			return $this->format_billing_period( $interval, $unit );
		}

		// Use standard period
		$period   = isset( $subscription_data['period'] ) ? $subscription_data['period'] : 'month';
		$interval = isset( $subscription_data['interval'] ) ? $subscription_data['interval'] : 1;

		return $this->format_billing_period( $interval, $period );
	}

	/**
	 * Format billing period for display
	 */
	public function format_billing_period( $interval, $unit ) {
		$interval = intval( $interval );

		$period_names = array(
			'day'   => array(
				'single' => __( 'day', 'recurio' ),
				'plural' => __( 'days', 'recurio' ),
			),
			'week'  => array(
				'single' => __( 'week', 'recurio' ),
				'plural' => __( 'weeks', 'recurio' ),
			),
			'month' => array(
				'single' => __( 'month', 'recurio' ),
				'plural' => __( 'months', 'recurio' ),
			),
			'year'  => array(
				'single' => __( 'year', 'recurio' ),
				'plural' => __( 'years', 'recurio' ),
			),
		);

		if ( ! isset( $period_names[ $unit ] ) ) {
			$unit = 'month';
		}

		$period_name = $interval === 1 ? $period_names[ $unit ]['single'] : $period_names[ $unit ]['plural'];

		if ( $interval === 1 ) {
			/* translators: %s: period name (month, year, etc.) */
			return sprintf( __( '/ %s', 'recurio' ), $period_name );
		} else {
			/* translators: 1: interval number, 2: period name (months, years, etc.) */
			return sprintf( __( 'every %1$d %2$s', 'recurio' ), $interval, $period_name );
		}
	}

	/**
	 * Enqueue frontend scripts for Subscribe & Save
	 */
	public function enqueue_frontend_scripts() {
		if ( ! is_product() ) {
			return;
		}

		// Get product ID from the queried object
		$product_id = get_queried_object_id();

		if ( ! $product_id ) {
			return;
		}

		// Get the WC_Product object properly
		$product = wc_get_product( $product_id );

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		// Subscribe & Save is a Pro feature
		if ( ! Recurio_Pro_Manager::get_instance()->is_license_valid() ) {
			return;
		}

		// Check if this product has Subscribe & Save enabled
		$allow_one_time = get_post_meta( $product->get_id(), '_recurio_allow_one_time_purchase', true ) === 'yes';

		if ( ! $allow_one_time && ! $this->is_subscription_product( $product ) ) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style(
			'recurio-subscribe-save',
			RECURIO_PLUGIN_URL . 'assets/css/subscribe-save.css',
			array(),
			RECURIO_VERSION
		);

		// Enqueue JS
		wp_enqueue_script(
			'recurio-subscribe-save',
			RECURIO_PLUGIN_URL . 'assets/js/subscribe-save.js',
			array( 'jquery' ),
			RECURIO_VERSION,
			true
		);

		// Get discount settings for JS
		$discount_type  = get_post_meta( $product->get_id(), '_recurio_subscription_discount_type', true ) ?: 'percentage';
		$discount_value = floatval( get_post_meta( $product->get_id(), '_recurio_subscription_discount_value', true ) );

		wp_localize_script(
			'recurio-subscribe-save',
			'recurioSubscribeSave',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'recurio_nonce' ),
				'discountType'  => $discount_type,
				'discountValue' => $discount_value,
				'i18n'          => array(
					'subscribe'  => __( 'Subscribe Now', 'recurio' ),
					'addToCart'  => __( 'Add to cart', 'recurio' ),
					'savePrefix' => __( 'Save', 'recurio' ),
				),
			)
		);
	}
}
