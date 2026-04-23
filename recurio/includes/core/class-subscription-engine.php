<?php
/**
 * Subscription Engine - Core subscription management functionality
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_Subscription_Engine {

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
		$this->table_name = $wpdb->prefix . 'recurio_subscriptions';
		$this->init_hooks();
	}

	private function init_hooks() {
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'sync_subscription_status' ) );

		// Custom hooks for subscription management
		// NOTE: recurio_process_payments is handled by Billing_Manager class (not here)
		// This class focuses on subscription CRUD and status management only
		add_action( 'recurio_check_paused_subscriptions', array( $this, 'check_paused_subscriptions' ) );

		// Admin AJAX handlers (for dashboard use)
		add_action( 'wp_ajax_recurio_admin_update_subscription', array( $this, 'ajax_update_subscription' ) );
		add_action( 'wp_ajax_recurio_admin_pause_subscription', array( $this, 'ajax_pause_subscription' ) );
		add_action( 'wp_ajax_recurio_admin_resume_subscription', array( $this, 'ajax_resume_subscription' ) );
		add_action( 'wp_ajax_recurio_admin_cancel_subscription', array( $this, 'ajax_cancel_subscription' ) );
	}

	/**
	 * Create a new subscription
	 */
	public function create_subscription( $data ) {
		global $wpdb;

		$defaults = array(
			'customer_id'           => 0,
			'product_id'            => 0,
			'status'                => 'pending',
			'billing_period'        => 'month',
			'billing_interval'      => 1,
			'billing_amount'        => 0,
			'trial_end_date'        => null,
			'next_payment_date'     => null,
			'subscription_metadata' => array(),
			'created_at'            => current_time( 'mysql' ),
			'updated_at'            => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		// Allow Pro to modify subscription data before creation
		$data = apply_filters( 'recurio_subscription_data_before_create', $data );

		// Trigger action before subscription creation (Pro can hook here)
		do_action( 'recurio_before_subscription_created', $data );

		// Validate required fields
		if ( empty( $data['customer_id'] ) || empty( $data['product_id'] ) ) {
			return new WP_Error( 'missing_data', __( 'Customer ID and Product ID are required', 'recurio' ) );
		}

		// Calculate next payment date if not provided
		if ( empty( $data['next_payment_date'] ) ) {
			$data['next_payment_date'] = $this->calculate_next_payment_date(
				$data['billing_period'],
				$data['billing_interval'],
				$data['trial_end_date']
			);
		}

		// Serialize metadata if array
		if ( is_array( $data['subscription_metadata'] ) ) {
			$data['subscription_metadata'] = json_encode( $data['subscription_metadata'] );
		}

		// Calculate customer LTV based on billing amount and period
		$customer_ltv = $this->calculate_customer_ltv(
			$data['billing_amount'],
			$data['billing_period'],
			$data['billing_interval']
		);

		// Prepare data for insertion - only include columns that exist in the table
		$insert_data = array(
			'customer_id'           => intval( $data['customer_id'] ),
			'product_id'            => intval( $data['product_id'] ),
			'status'                => $data['status'],
			'billing_period'        => $data['billing_period'],
			'billing_interval'      => intval( $data['billing_interval'] ),
			'billing_amount'        => floatval( $data['billing_amount'] ),
			'customer_ltv'          => floatval( $customer_ltv ),
			'trial_end_date'        => ! empty( $data['trial_end_date'] ) ? $data['trial_end_date'] : null,
			'next_payment_date'     => $data['next_payment_date'],
			'subscription_metadata' => $data['subscription_metadata'],
			'created_at'            => $data['created_at'],
			'updated_at'            => $data['updated_at'],
		);

		// Add payment method if provided
		if ( isset( $data['payment_method'] ) && ! empty( $data['payment_method'] ) ) {
			$insert_data['payment_method'] = $data['payment_method'];
		}

		// Add payment token ID if provided
		if ( isset( $data['payment_token_id'] ) && ! empty( $data['payment_token_id'] ) ) {
			$insert_data['payment_token_id'] = intval( $data['payment_token_id'] );
		}

		// Add WooCommerce subscription ID if provided
		if ( isset( $data['wc_subscription_id'] ) && ! empty( $data['wc_subscription_id'] ) ) {
			$insert_data['wc_subscription_id'] = intval( $data['wc_subscription_id'] );
		}

		// Add renewal count if provided
		if ( isset( $data['renewal_count'] ) ) {
			$insert_data['renewal_count'] = intval( $data['renewal_count'] );
		}

		// Add max renewals if provided
		if ( isset( $data['max_renewals'] ) ) {
			$insert_data['max_renewals'] = ! is_null( $data['max_renewals'] ) ? intval( $data['max_renewals'] ) : null;
		}

		// Add split payment fields if provided
		if ( isset( $data['payment_type'] ) && ! empty( $data['payment_type'] ) ) {
			$insert_data['payment_type'] = $data['payment_type'];
		}

		if ( isset( $data['max_payments'] ) ) {
			$insert_data['max_payments'] = intval( $data['max_payments'] );
		}

		if ( isset( $data['access_timing'] ) && ! empty( $data['access_timing'] ) ) {
			$insert_data['access_timing'] = $data['access_timing'];
		}

		if ( isset( $data['access_duration_value'] ) ) {
			$insert_data['access_duration_value'] = intval( $data['access_duration_value'] );
		}

		if ( isset( $data['access_duration_unit'] ) && ! empty( $data['access_duration_unit'] ) ) {
			$insert_data['access_duration_unit'] = $data['access_duration_unit'];
		}

		if ( isset( $data['access_end_date'] ) && ! empty( $data['access_end_date'] ) ) {
			$insert_data['access_end_date'] = $data['access_end_date'];
		}

		// Add billing address if provided
		if ( isset( $data['billing_address'] ) && ! empty( $data['billing_address'] ) ) {
			// If it's already JSON encoded, use as is, otherwise encode it
			if ( is_array( $data['billing_address'] ) ) {
				$insert_data['billing_address'] = json_encode( $data['billing_address'] );
			} else {
				$insert_data['billing_address'] = $data['billing_address'];
			}
		}

		// Add shipping address if provided
		if ( isset( $data['shipping_address'] ) && ! empty( $data['shipping_address'] ) ) {
			// If it's already JSON encoded, use as is, otherwise encode it
			if ( is_array( $data['shipping_address'] ) ) {
				$insert_data['shipping_address'] = json_encode( $data['shipping_address'] );
			} else {
				$insert_data['shipping_address'] = $data['shipping_address'];
			}
		}

		// Add optional fields if they exist in the data
		$metadata = array();
		if ( isset( $data['notes'] ) && ! empty( $data['notes'] ) ) {
			$metadata['notes'] = $data['notes'];
		}
		if ( ! empty( $metadata ) ) {
			$insert_data['subscription_metadata'] = json_encode( $metadata );
		}

		// Debug: Log what we're inserting

		// Insert subscription with proper format specifiers
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$result = $wpdb->insert(
			$this->table_name,
			$insert_data
		);

		// Let WordPress determine the format automatically, or explicitly set if needed
		if ( $result === false ) {
			// Debug: Log the last error
		} else {
		}

		if ( $result === false ) {
			$error_message = sprintf(
				/* translators: %s: Database error message */
				__( 'Failed to create subscription. Database error: %s', 'recurio' ),
				$wpdb->last_error
			);
			return new WP_Error( 'db_error', $error_message );
		}

		$subscription_id = $wpdb->insert_id;

		// Log subscription creation event with the subscription's created_at date
		$this->log_event( $subscription_id, 'created', $data['billing_amount'], null, $data['created_at'] );

		// Trigger action for other components (existing hook)
		do_action( 'recurio_subscription_created', $subscription_id, $data );

		// Additional hook for Pro features (after creation complete)
		do_action( 'recurio_after_subscription_created', $subscription_id, $data );

		// Schedule email reminders if email notifications class exists
		if ( class_exists( 'Recurio_Email_Notifications' ) ) {
			$email_notifications = Recurio_Email_Notifications::get_instance();
			$email_notifications->schedule_reminders( $subscription_id, $data );
		}

		return $subscription_id;
	}

	/**
	 * Update an existing subscription
	 */
	public function update_subscription( $subscription_id, $data ) {
		global $wpdb;

		// Get existing subscription data for Pro hooks
		$old_subscription = $this->get_subscription( $subscription_id );

		// Allow Pro to modify update data
		$data = apply_filters( 'recurio_subscription_data_before_update', $data, $subscription_id, $old_subscription );

		// Trigger before update hook
		do_action( 'recurio_before_subscription_updated', $subscription_id, $data, $old_subscription );

		// Remove any fields that shouldn't be updated directly
		unset( $data['id'] );
		unset( $data['created_at'] );

		// Handle notes field - should be stored in metadata
		if ( isset( $data['notes'] ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT subscription_metadata FROM {$wpdb->prefix}recurio_subscriptions WHERE id = %d",
					$subscription_id
				)
			);

			$metadata = array();
			if ( $existing && ! empty( $existing->subscription_metadata ) ) {
				$metadata = json_decode( $existing->subscription_metadata, true ) ?: array();
			}
			$metadata['notes']             = $data['notes'];
			$data['subscription_metadata'] = json_encode( $metadata );
			unset( $data['notes'] );
		}

		// Ensure updated_at is set
		$data['updated_at'] = current_time( 'mysql' );

		// Serialize metadata if array
		if ( isset( $data['subscription_metadata'] ) && is_array( $data['subscription_metadata'] ) ) {
			$data['subscription_metadata'] = json_encode( $data['subscription_metadata'] );
		}

		// Log the data being updated for debugging

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$result = $wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => $subscription_id )
		);

		if ( $result === false ) {
			return new WP_Error(
				'db_error',
				sprintf(
				/* translators: %s: Database error message */
					__( 'Failed to update subscription. Database error: %s', 'recurio' ),
					$wpdb->last_error
				)
			);
		}

		// Log update event
		$this->log_event( $subscription_id, 'updated', null, $data );

		// Trigger action for other components (existing hook)
		do_action( 'recurio_subscription_updated', $subscription_id, $data );

		// Additional hook for Pro features (after update complete)
		do_action( 'recurio_after_subscription_updated', $subscription_id, $data, $old_subscription );

		return true;
	}

	/**
	 * Get subscription by ID
	 */
	public function get_subscription( $subscription_id ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscriptions WHERE id = %d",
				$subscription_id
			)
		);

		if ( $subscription && ! empty( $subscription->subscription_metadata ) ) {
			$subscription->subscription_metadata = json_decode( $subscription->subscription_metadata, true );
		}

		return $subscription;
	}

	/**
	 * Get subscriptions by customer
	 */
	public function get_customer_subscriptions( $customer_id, $status = null ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		if ( $status ) {
			$subscriptions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}recurio_subscriptions
                WHERE customer_id = %d AND status = %s
                ORDER BY created_at DESC",
					$customer_id,
					$status
				)
			);
		} else {
			$subscriptions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}recurio_subscriptions
                WHERE customer_id = %d
                ORDER BY created_at DESC",
					$customer_id
				)
			);
		}

		foreach ( $subscriptions as $subscription ) {
			if ( ! empty( $subscription->subscription_metadata ) ) {
				$subscription->subscription_metadata = json_decode( $subscription->subscription_metadata, true );
			}
		}

		return $subscriptions;
	}

	/**
	 * Pause a subscription
	 */
	public function pause_subscription( $subscription_id, $pause_duration = null ) {
		$subscription = $this->get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found', 'recurio' ) );
		}

		if ( $subscription->status !== 'active' ) {
			return new WP_Error( 'invalid_status', __( 'Only active subscriptions can be paused', 'recurio' ) );
		}

		// Hook before status change for Pro
		do_action( 'recurio_before_subscription_status_change', $subscription_id, 'active', 'paused' );

		$pause_data = array(
			'status'           => 'paused',
			'pause_start_date' => current_time( 'mysql' ),
		);

		if ( $pause_duration ) {
			$pause_end = new DateTime();
			$pause_end->add( new DateInterval( 'P' . $pause_duration . 'D' ) );
			$pause_data['pause_end_date'] = $pause_end->format( 'Y-m-d H:i:s' );
		}

		$result = $this->update_subscription( $subscription_id, $pause_data );

		if ( ! is_wp_error( $result ) ) {
			$this->log_event( $subscription_id, 'paused', null, $pause_data );
			// Include subscription data in the action
			$subscription_data                   = (array) $subscription;
			$subscription_data['pause_duration'] = $pause_duration;
			do_action( 'recurio_subscription_paused', $subscription_id, $subscription_data );

			// Hook after status change for Pro
			do_action( 'recurio_after_subscription_status_change', $subscription_id, 'active', 'paused' );
		}

		return $result;
	}

	/**
	 * Resume a paused subscription
	 */
	public function resume_subscription( $subscription_id ) {
		$subscription = $this->get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found', 'recurio' ) );
		}

		if ( $subscription->status !== 'paused' ) {
			return new WP_Error( 'invalid_status', __( 'Only paused subscriptions can be resumed', 'recurio' ) );
		}

		// Hook before status change for Pro
		do_action( 'recurio_before_subscription_status_change', $subscription_id, 'paused', 'active' );

		// Calculate new next payment date
		$pause_duration = 0;
		if ( $subscription->pause_start_date ) {
			$pause_start    = new DateTime( $subscription->pause_start_date );
			$now            = new DateTime();
			$pause_duration = $pause_start->diff( $now )->days;
		}

		$next_payment = new DateTime( $subscription->next_payment_date );
		$next_payment->add( new DateInterval( 'P' . $pause_duration . 'D' ) );

		$resume_data = array(
			'status'            => 'active',
			'pause_start_date'  => null,
			'pause_end_date'    => null,
			'next_payment_date' => $next_payment->format( 'Y-m-d H:i:s' ),
		);

		$result = $this->update_subscription( $subscription_id, $resume_data );

		if ( ! is_wp_error( $result ) ) {
			$this->log_event( $subscription_id, 'resumed' );
			// Include subscription data in the action
			$subscription_data                      = (array) $subscription;
			$subscription_data['next_payment_date'] = $next_payment->format( 'Y-m-d H:i:s' );
			do_action( 'recurio_subscription_resumed', $subscription_id, $subscription_data );

			// Hook after status change for Pro
			do_action( 'recurio_after_subscription_status_change', $subscription_id, 'paused', 'active' );

			// Reschedule email reminders if email notifications class exists
			if ( class_exists( 'Recurio_Email_Notifications' ) ) {
				$email_notifications = Recurio_Email_Notifications::get_instance();
				$email_notifications->clear_scheduled_reminders( $subscription_id );
				$email_notifications->schedule_reminders( $subscription_id, $subscription_data );
			}
		}

		return $result;
	}

	/**
	 * Cancel a subscription
	 */
	public function cancel_subscription( $subscription_id, $reason = '', $timing = 'immediate' ) {
		$subscription = $this->get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found', 'recurio' ) );
		}

		if ( $subscription->status === 'cancelled' || $subscription->status === 'pending_cancellation' ) {
			return new WP_Error( 'already_cancelled', __( 'Subscription is already cancelled or pending cancellation', 'recurio' ) );
		}

		$cancel_data = array(
			'cancellation_reason' => $reason,
		);

		if ( $timing === 'end_of_period' ) {
			// Schedule cancellation for end of billing period
			$cancel_data['status']            = 'pending_cancellation';
			$cancel_data['cancellation_date'] = $subscription->next_payment_date;

			// Log the scheduled cancellation
			$this->log_event(
				$subscription_id,
				'cancellation_scheduled',
				null,
				array(
					'reason'         => $reason,
					'scheduled_date' => $subscription->next_payment_date,
				)
			);
		} else {
			// Cancel immediately
			$cancel_data['status']            = 'cancelled';
			$cancel_data['cancellation_date'] = current_time( 'mysql' );

			// Log the immediate cancellation
			$this->log_event( $subscription_id, 'cancelled', null, array( 'reason' => $reason ) );
		}

		// Log the data being sent for update

		$result = $this->update_subscription( $subscription_id, $cancel_data );

		if ( ! is_wp_error( $result ) ) {
			// Verify the update was successful
			$updated_subscription = $this->get_subscription( $subscription_id );

			// Include subscription data in the action
			$subscription_data                      = (array) $subscription;
			$subscription_data['cancellation_date'] = $cancel_data['cancellation_date'];
			do_action( 'recurio_subscription_cancelled', $subscription_id, $subscription_data, $reason );

			// Clear scheduled reminders if email notifications class exists
			if ( class_exists( 'Recurio_Email_Notifications' ) ) {
				$email_notifications = Recurio_Email_Notifications::get_instance();
				$email_notifications->clear_scheduled_reminders( $subscription_id );
			}
		} else {
		}

		return $result;
	}

	/**
	 * Process early renewal for a subscription
	 *
	 * Allows customers to renew their subscription before the due date.
	 * Creates a renewal order and extends the subscription period.
	 *
	 * @param int $subscription_id The subscription ID.
	 * @return array|WP_Error Result with order_id on success, WP_Error on failure.
	 * @since 1.3.0
	 */
	public function process_early_renewal( $subscription_id ) {
		$subscription = $this->get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found', 'recurio' ) );
		}

		// Only active subscriptions can be renewed early
		if ( ! in_array( $subscription->status, array( 'active', 'trial' ), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Only active subscriptions can be renewed early', 'recurio' ) );
		}

		// Check if subscription has a max renewal limit and if it's reached
		if ( ! empty( $subscription->max_renewals ) && $subscription->renewal_count >= $subscription->max_renewals ) {
			return new WP_Error( 'max_renewals_reached', __( 'This subscription has reached its maximum number of renewals', 'recurio' ) );
		}

		// Get billing manager to create renewal order
		$billing_manager = Recurio_Billing_Manager::get_instance();

		// Create the renewal order
		$order = $billing_manager->create_renewal_order( $subscription );

		if ( ! $order ) {
			return new WP_Error( 'order_creation_failed', __( 'Failed to create renewal order', 'recurio' ) );
		}

		// Mark this as an early renewal order
		$order->add_meta_data( '_recurio_is_early_renewal', 'yes' );
		$order->add_meta_data( '_recurio_early_renewal_subscription_id', $subscription_id );
		$order->save();

		// Calculate new next payment date from CURRENT next payment date (not from today)
		// This ensures the subscription period is properly extended
		$current_next_payment = new DateTime( $subscription->next_payment_date );
		$new_next_payment = clone $current_next_payment;

		$period   = $subscription->billing_period;
		$interval = $subscription->billing_interval ?: 1;

		switch ( $period ) {
			case 'day':
				$new_next_payment->add( new DateInterval( 'P' . $interval . 'D' ) );
				break;
			case 'week':
				$new_next_payment->add( new DateInterval( 'P' . ( $interval * 7 ) . 'D' ) );
				break;
			case 'month':
				$new_next_payment->add( new DateInterval( 'P' . $interval . 'M' ) );
				break;
			case 'year':
				$new_next_payment->add( new DateInterval( 'P' . $interval . 'Y' ) );
				break;
		}

		// Update subscription with new next payment date and increment renewal count
		$update_data = array(
			'next_payment_date' => $new_next_payment->format( 'Y-m-d H:i:s' ),
			'renewal_count'     => $subscription->renewal_count + 1,
			'updated_at'        => current_time( 'mysql' ),
		);

		$result = $this->update_subscription( $subscription_id, $update_data );

		if ( is_wp_error( $result ) ) {
			// If subscription update fails, we should still return the order for manual handling
			$this->log_event(
				$subscription_id,
				'early_renewal_partial',
				$subscription->billing_amount,
				array(
					'order_id' => $order->get_id(),
					'error'    => $result->get_error_message(),
				)
			);
		} else {
			// Log the successful early renewal
			$this->log_event(
				$subscription_id,
				'early_renewal',
				$subscription->billing_amount,
				array(
					'order_id'              => $order->get_id(),
					'previous_next_payment' => $subscription->next_payment_date,
					'new_next_payment'      => $new_next_payment->format( 'Y-m-d H:i:s' ),
				)
			);

			// Record the payment/revenue
			$this->log_revenue(
				$subscription_id,
				$subscription->billing_amount,
				$order->get_payment_method(),
				'early_renewal_' . $order->get_id()
			);

			// Fire action for other plugins/modules to hook into
			do_action( 'recurio_subscription_early_renewal', $subscription_id, $order->get_id(), $subscription );
		}

		return array(
			'success'           => true,
			'order_id'          => $order->get_id(),
			'order_url'         => $order->get_checkout_payment_url(),
			'new_next_payment'  => $new_next_payment->format( 'Y-m-d H:i:s' ),
			'subscription_id'   => $subscription_id,
		);
	}

	/**
	 * Calculate next payment date
	 */
	public function calculate_next_payment_date( $period, $interval, $trial_end_date = null, $has_signup_fee = false ) {
		// If there's a trial period, use trial end date
		if ( $trial_end_date && strtotime( $trial_end_date ) > time() ) {
			return $trial_end_date;
		}

		$next_payment = new DateTime();

		// If there's a sign-up fee, the initial order already included the first recurring payment
		// So we need to calculate from the next period, not the current one
		if ( $has_signup_fee ) {
			// Add one full billing period from now since the first period is already paid
			switch ( $period ) {
				case 'day':
					$next_payment->add( new DateInterval( 'P' . $interval . 'D' ) );
					break;
				case 'week':
					$next_payment->add( new DateInterval( 'P' . ( $interval * 7 ) . 'D' ) );
					break;
				case 'month':
					$next_payment->add( new DateInterval( 'P' . $interval . 'M' ) );
					break;
				case 'year':
					$next_payment->add( new DateInterval( 'P' . $interval . 'Y' ) );
					break;
			}
		} else {
			// For subscriptions without sign-up fee, we typically charge immediately
			// but since this is coming from an order, the first payment is also already made
			switch ( $period ) {
				case 'day':
					$next_payment->add( new DateInterval( 'P' . $interval . 'D' ) );
					break;
				case 'week':
					$next_payment->add( new DateInterval( 'P' . ( $interval * 7 ) . 'D' ) );
					break;
				case 'month':
					$next_payment->add( new DateInterval( 'P' . $interval . 'M' ) );
					break;
				case 'year':
					$next_payment->add( new DateInterval( 'P' . $interval . 'Y' ) );
					break;
			}
		}

		return $next_payment->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Calculate customer lifetime value
	 */
	private function calculate_customer_ltv( $billing_amount, $billing_period, $billing_interval = 1 ) {
		// Average subscription lifetime in months (industry average is 24 months)
		$avg_lifetime_months = 24;

		// Convert billing period to monthly equivalent
		$monthly_amount = 0;

		switch ( $billing_period ) {
			case 'day':
				$monthly_amount = ( $billing_amount * 30 ) / $billing_interval;
				break;
			case 'week':
				$monthly_amount = ( $billing_amount * 4.33 ) / $billing_interval;
				break;
			case 'month':
				$monthly_amount = $billing_amount / $billing_interval;
				break;
			case 'year':
				$monthly_amount = $billing_amount / ( $billing_interval * 12 );
				break;
			default:
				$monthly_amount = $billing_amount;
		}

		// Calculate LTV (monthly amount * average lifetime in months)
		$ltv = $monthly_amount * $avg_lifetime_months;

		return round( $ltv, 2 );
	}

	/**
	 * Log subscription event
	 */
	public function log_event( $subscription_id, $event_type, $event_value = null, $metadata = null, $event_date = null ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time event logging
		$wpdb->insert(
			$wpdb->prefix . 'recurio_subscription_events',
			array(
				'subscription_id' => $subscription_id,
				'event_type'      => $event_type,
				'event_value'     => $event_value,
				'event_metadata'  => $metadata ? json_encode( $metadata ) : null,
				'created_at'      => $event_date ? $event_date : current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Log revenue
	 */
	public function log_revenue( $subscription_id, $amount, $payment_method = null, $transaction_id = null, $revenue_date = null ) {
		global $wpdb;

		$subscription = $this->get_subscription( $subscription_id );
		$settings     = get_option( 'recurio_settings', array() );

		// Use provided date or current time
		$revenue_date = $revenue_date ? $revenue_date : current_time( 'mysql' );

		// Generate a unique transaction ID if not provided
		if ( ! $transaction_id ) {
			$transaction_id = 'sub_' . $subscription_id . '_' . time();
		}

		// Determine payment method from subscription's payment_method field (gateway ID)
		if ( ! $payment_method ) {
			$payment_method = $subscription->payment_method ?: 'manual';
		}

		// For backward compatibility, if we receive a title, try to get the gateway ID
		// Common payment method titles to gateway IDs mapping
		$title_to_gateway = array(
			'Credit Card'          => 'stripe',
			'PayPal'               => 'paypal_payments',
			'Direct bank transfer' => 'bacs',
			'Check payments'       => 'cheque',
			'Cash on delivery'     => 'cod',
		);

		// If the payment_method looks like a title (contains spaces or uppercase), convert it
		if ( strpos( $payment_method, ' ' ) !== false || $payment_method !== strtolower( $payment_method ) ) {
			$payment_method = isset( $title_to_gateway[ $payment_method ] ) ? $title_to_gateway[ $payment_method ] : 'manual';
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time revenue logging
		$wpdb->insert(
			$wpdb->prefix . 'recurio_subscription_revenue',
			array(
				'subscription_id' => $subscription_id,
				'amount'          => $amount,
				'currency'        => $settings['currency'] ?? 'USD',
				'period_type'     => $subscription->billing_period,
				'period_start'    => gmdate( 'Y-m-01', strtotime( $revenue_date ) ),
				'period_end'      => gmdate( 'Y-m-t', strtotime( $revenue_date ) ),
				'transaction_id'  => $transaction_id,
				'gateway'         => $payment_method, // Store the actual gateway ID
				'payment_method'  => $payment_method, // Store the same gateway ID for consistency
				'created_at'      => $revenue_date,
			)
		);

		$revenue_id = $wpdb->insert_id;

		// Automatically update revenue goals with the revenue date
		$this->update_revenue_goals( $amount, $revenue_date );

		return $revenue_id;
	}

	/**
	 * Update revenue goals when a payment is recorded
	 */
	private function update_revenue_goals( $amount, $revenue_date = null ) {
		global $wpdb;
		$table_name   = $wpdb->prefix . 'recurio_revenue_goals';
		$current_date = $revenue_date ? gmdate( 'Y-m-d', strtotime( $revenue_date ) ) : current_time( 'Y-m-d' );

		// Find all active goals that include the revenue date
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time revenue goals
		$active_goals = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, current_amount FROM {$table_name}
            WHERE status = 'active'
            AND %s BETWEEN start_date AND end_date",
				$current_date
			)
		);

		// Update each relevant goal
		foreach ( $active_goals as $goal ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time revenue goals
			$wpdb->update(
				$table_name,
				array(
					'current_amount' => $goal->current_amount + $amount,
					'updated_at'     => current_time( 'mysql' ),
				),
				array( 'id' => $goal->id )
			);
		}
	}

	/**
	 * Manually record a payment for a subscription
	 */
	public function record_payment( $subscription_id, $amount = null, $payment_method = null, $transaction_id = null ) {
		$subscription = $this->get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return new WP_Error( 'not_found', __( 'Subscription not found', 'recurio' ) );
		}

		// Use subscription billing amount if not specified
		if ( $amount === null ) {
			$amount = $subscription->billing_amount;
		}

		// Log the revenue
		$revenue_id = $this->log_revenue( $subscription_id, $amount, $payment_method, $transaction_id );

		if ( $revenue_id ) {
			// Update next payment date
			$next_payment = $this->calculate_next_payment_date(
				$subscription->billing_period,
				$subscription->billing_interval
			);

			$this->update_subscription(
				$subscription_id,
				array(
					'next_payment_date' => $next_payment,
				)
			);

			// Log event
			$this->log_event(
				$subscription_id,
				'payment_recorded',
				$amount,
				array(
					'payment_method' => $payment_method,
					'transaction_id' => $transaction_id,
				)
			);

			do_action( 'recurio_payment_recorded', $subscription_id, $amount, $payment_method, $transaction_id );

			return $revenue_id;
		}

		return new WP_Error( 'payment_failed', __( 'Failed to record payment', 'recurio' ) );
	}

	/**
	 * Get subscription statistics
	 */
	public function get_statistics() {
		global $wpdb;

		$stats = array();

		// Total subscriptions
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is safe, no user input
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time statistics
		$stats['total'] = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions"
		);

		// Active subscriptions
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is safe, no user input
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time statistics
		$stats['active'] = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE status = 'active'"
		);

		// Paused subscriptions
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is safe, no user input
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time statistics
		$stats['paused'] = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE status = 'paused'"
		);

		// Cancelled subscriptions
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is safe, no user input
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time statistics
		$stats['cancelled'] = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE status = 'cancelled'"
		);

		// Monthly recurring revenue (MRR)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, no user input
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time statistics
		$stats['mrr'] = $wpdb->get_var(
			"SELECT SUM(billing_amount) FROM {$wpdb->prefix}recurio_subscriptions
            WHERE status = 'active' AND billing_period = 'month'"
		);

		// Annual recurring revenue (ARR)
		$stats['arr'] = $stats['mrr'] * 12;

		return $stats;
	}

	/**
	 * AJAX handler for updating subscription
	 */
	public function ajax_update_subscription() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'recurio' ) );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized in update_subscription method
		$data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();

		$result = $this->update_subscription( $subscription_id, $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success( __( 'Subscription updated successfully', 'recurio' ) );
		}
	}

	/**
	 * Process new subscription from WooCommerce order
	 */
	public function process_new_subscription( $order_id ) {
		// Get order object
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// CRITICAL: Check if this is a renewal order - if so, skip subscription creation
		// Renewal orders are created by the billing manager for recurring payments
		// They should NOT create new subscriptions
		$is_renewal_order = $order->get_meta( '_recurio_is_renewal_order', true );
		if ( $is_renewal_order === 'yes' ) {
			return false; // This is a renewal order, not a new subscription
		}

		// Get customer ID from order
		$customer_id = $order->get_customer_id();
		if ( ! $customer_id ) {
			return false;
		}

		// IMPORTANT: Check if we've already processed this order
		$order_processed = get_post_meta( $order_id, '_recurio_subscriptions_created', true );
		if ( $order_processed === 'yes' ) {
			return false; // Order already processed, don't create duplicates
		}

		// Also check if any subscriptions exist for this order
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$existing_order_subscriptions = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions
            WHERE wc_subscription_id = %d",
				$order_id
			)
		);

		if ( $existing_order_subscriptions > 0 ) {
			// Mark as processed to avoid future attempts
			update_post_meta( $order_id, '_recurio_subscriptions_created', 'yes' );
			return false; // Subscriptions already exist for this order
		}

		$subscriptions_created = array();

		// Process each item in the order
		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$product      = $item->get_product();

			// Skip if not a product
			if ( ! $product ) {
				continue;
			}

			// Skip if this is a one-time purchase from Subscribe & Save
			$purchase_type = wc_get_order_item_meta( $item_id, '_recurio_purchase_type', true );
			if ( $purchase_type === 'one-time' ) {
				continue; // One-time purchase, no subscription to create
			}

			// Check if this is a subscription product using our custom meta
			$is_subscription = get_post_meta( $product_id, '_recurio_subscription_enabled', true ) === 'yes';
			if ( ! $is_subscription ) {
				// Also check legacy fields
				$has_subscription_price  = get_post_meta( $product_id, '_subscription_price', true );
				$has_subscription_period = get_post_meta( $product_id, '_subscription_period', true );
				if ( ! $has_subscription_price && ! $has_subscription_period ) {
					continue;
				}
			}

			// Check if variation has overridden subscription settings (PRO feature)
			$variation_override = false;
			if ( $variation_id && Recurio_Pro_Manager::get_instance()->is_license_valid() ) {
				$variation_override = get_post_meta( $variation_id, '_recurio_override_subscription', true ) === 'yes';
			}

			// Check if subscription already exists for this specific order item
			$existing_item_subscription = wc_get_order_item_meta( $item_id, '_recurio_subscription_id', true );
			if ( $existing_item_subscription ) {
				continue; // Already processed this item
			}

			// Get subscription details from product
			// Determine which ID to use for settings (variation or parent)
			$settings_id = ( $variation_override && $variation_id ) ? $variation_id : $product_id;

			// First check for custom billing period (Pro feature)
			$is_pro = Recurio_Pro_Manager::get_instance()->is_license_valid();

			// For variations with override, check variation's custom period setting first
			$use_custom_period = false;
			if ( $variation_override && $variation_id ) {
				$use_custom_period = get_post_meta( $variation_id, '_recurio_use_custom_period', true ) === 'yes';
			}
			// Fall back to parent's custom period if not overridden
			if ( ! $use_custom_period && ! $variation_override ) {
				$use_custom_period = $is_pro && get_post_meta( $product_id, '_recurio_use_custom_period', true ) === 'yes';
			}

			if ( $use_custom_period ) {
				// Use custom billing period settings from the appropriate source
				$interval = intval( get_post_meta( $settings_id, '_recurio_subscription_billing_interval', true ) ) ?: 1;
				$period   = get_post_meta( $settings_id, '_recurio_subscription_billing_unit', true ) ?: 'month';
			} else {
				// Check standard billing period
				$billing_period = null;

				// For variations with override, check variation's billing period first
				if ( $variation_override && $variation_id ) {
					$billing_period = get_post_meta( $variation_id, '_recurio_subscription_billing_period', true );
				}

				// Fall back to parent's billing period
				if ( ! $billing_period ) {
					$billing_period = get_post_meta( $product_id, '_recurio_subscription_billing_period', true );
				}

				if ( ! $billing_period ) {
					// Fallback to legacy fields
					$period   = get_post_meta( $product_id, '_subscription_period', true ) ?: 'month';
					$interval = get_post_meta( $product_id, '_subscription_interval', true ) ?: 1;
				} else {
					// Convert our billing period to period/interval
					$period_map = array(
						'daily'     => array(
							'period'   => 'day',
							'interval' => 1,
						),
						'weekly'    => array(
							'period'   => 'week',
							'interval' => 1,
						),
						'monthly'   => array(
							'period'   => 'month',
							'interval' => 1,
						),
						'quarterly' => array(
							'period'   => 'month',
							'interval' => 3,
						),
						'yearly'    => array(
							'period'   => 'year',
							'interval' => 1,
						),
					);

					$period_data = isset( $period_map[ $billing_period ] ) ? $period_map[ $billing_period ] : $period_map['monthly'];
					$period      = $period_data['period'];
					$interval    = $period_data['interval'];
				}
			}

			// Get the recurring price (excluding sign-up fee)
			// First check if there's a discounted subscription price from Subscribe & Save
			$subscription_price_meta = wc_get_order_item_meta( $item_id, '_subscription_price', true );

			if ( ! empty( $subscription_price_meta ) && floatval( $subscription_price_meta ) > 0 ) {
				// Use the discounted price from cart/checkout (Subscribe & Save)
				$recurring_price = floatval( $subscription_price_meta );
			} else {
				// Check if Subscribe & Save discount is configured on the product
				$is_pro         = Recurio_Pro_Manager::get_instance()->is_license_valid();
				$allow_one_time = $is_pro && get_post_meta( $product_id, '_recurio_allow_one_time_purchase', true ) === 'yes';

				$base_product    = wc_get_product( $product_id );
				$recurring_price = $base_product->get_regular_price();
				if ( empty( $recurring_price ) ) {
					$recurring_price = $base_product->get_price();
				}

				// Apply Subscribe & Save discount if enabled
				if ( $allow_one_time && ! empty( $recurring_price ) ) {
					$discount_type  = get_post_meta( $product_id, '_recurio_subscription_discount_type', true ) ?: 'percentage';
					$discount_value = floatval( get_post_meta( $product_id, '_recurio_subscription_discount_value', true ) );

					if ( $discount_value > 0 ) {
						if ( $discount_type === 'percentage' ) {
							$discount_amount = floatval( $recurring_price ) * ( $discount_value / 100 );
						} else {
							$discount_amount = $discount_value;
						}
						$recurring_price = max( 0, floatval( $recurring_price ) - $discount_amount );
					}
				}
			}

			// If still no price, fall back to item total (but this might include sign-up fee)
			if ( empty( $recurring_price ) || $recurring_price == 0 ) {
				// Get sign-up fee to subtract it (check variation first if override enabled)
				$temp_signup_fee = 0;
				if ( $variation_override && $variation_id ) {
					$var_signup = get_post_meta( $variation_id, '_recurio_subscription_signup_fee', true );
					$temp_signup_fee = ( $var_signup !== '' && $var_signup !== false ) ? floatval( $var_signup ) : 0;
				}
				if ( ! $temp_signup_fee ) {
					$temp_signup_fee = floatval( get_post_meta( $product_id, '_recurio_subscription_signup_fee', true ) ) ?: 0;
				}
				$total_price     = $item->get_total() / $item->get_quantity();
				$recurring_price = $total_price - $temp_signup_fee;

				// Ensure we don't have negative price
				$recurring_price = max( 0, $recurring_price );
			}

			$price = $recurring_price;

			// Get trial information (check variation first if override enabled)
			$trial_days = 0;
			if ( $variation_override && $variation_id ) {
				$var_trial = get_post_meta( $variation_id, '_recurio_subscription_trial_days', true );
				if ( $var_trial !== '' && $var_trial !== false ) {
					$trial_days = intval( $var_trial );
				}
			}
			// Fall back to parent if no variation trial or no override
			if ( ! $trial_days && ! $variation_override ) {
				$trial_days = intval( get_post_meta( $product_id, '_recurio_subscription_trial_days', true ) ) ?: 0;
			}
			// Also check parent if variation override is set but trial is empty (inherit)
			if ( $variation_override && $variation_id && ! $trial_days ) {
				$var_trial = get_post_meta( $variation_id, '_recurio_subscription_trial_days', true );
				if ( $var_trial === '' || $var_trial === false ) {
					// Empty means inherit from parent
					$trial_days = intval( get_post_meta( $product_id, '_recurio_subscription_trial_days', true ) ) ?: 0;
				}
			}

			$trial_end_date = null;
			if ( $trial_days > 0 ) {
				$trial_end = new DateTime();
				$trial_end->add( new DateInterval( 'P' . $trial_days . 'D' ) );
				$trial_end_date = $trial_end->format( 'Y-m-d H:i:s' );
			}

			// Get subscription length (max renewals) - always from parent
			$subscription_length = get_post_meta( $product_id, '_recurio_subscription_length', true );
			$max_renewals        = ( $subscription_length && $subscription_length > 0 ) ? intval( $subscription_length ) : null;

			// Get sign-up fee for metadata (check variation first if override enabled)
			$signup_fee = 0;
			if ( $variation_override && $variation_id ) {
				$var_signup = get_post_meta( $variation_id, '_recurio_subscription_signup_fee', true );
				if ( $var_signup !== '' && $var_signup !== false ) {
					$signup_fee = floatval( $var_signup );
				}
			}
			// Fall back to parent if no variation signup fee or no override
			if ( ! $signup_fee && ! $variation_override ) {
				$signup_fee = floatval( get_post_meta( $product_id, '_recurio_subscription_signup_fee', true ) ) ?: 0;
			}
			// Also check parent if variation override is set but signup fee is empty (inherit)
			if ( $variation_override && $variation_id && ! $signup_fee ) {
				$var_signup = get_post_meta( $variation_id, '_recurio_subscription_signup_fee', true );
				if ( $var_signup === '' || $var_signup === false ) {
					// Empty means inherit from parent
					$signup_fee = floatval( get_post_meta( $product_id, '_recurio_subscription_signup_fee', true ) ) ?: 0;
				}
			}

			// Get payment method information from the order
			$payment_method       = $order->get_payment_method();
			$payment_method_title = $order->get_payment_method_title();

			// Get payment token ID from order for future renewals
			$payment_token_id = null;
			$payment_tokens   = $order->get_payment_tokens();

			if ( ! empty( $payment_tokens ) ) {
				// Get the first (most recent) token
				$payment_token_id = reset( $payment_tokens );
			}

			// If no token found in order tokens, try to get from order meta
			if ( ! $payment_token_id ) {
				$payment_token_id = $order->get_meta( '_payment_token_id', true );
			}

			// Also check for Stripe-specific token meta
			if ( ! $payment_token_id && $payment_method === 'stripe' ) {
				$stripe_source = $order->get_meta( '_stripe_source_id', true );
				if ( $stripe_source ) {
					// Try to find the WC payment token for this source
					$tokens = WC_Payment_Tokens::get_customer_tokens( $customer_id, 'stripe' );
					foreach ( $tokens as $token ) {
						if ( $token->get_token() === $stripe_source ) {
							$payment_token_id = $token->get_id();
							break;
						}
					}
				}
			}

			// Get billing address from order
			$billing_address = array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'company'    => $order->get_billing_company(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
				'email'      => $order->get_billing_email(),
				'phone'      => $order->get_billing_phone(),
			);

			// Get shipping address from order
			$shipping_address = array(
				'first_name' => $order->get_shipping_first_name(),
				'last_name'  => $order->get_shipping_last_name(),
				'company'    => $order->get_shipping_company(),
				'address_1'  => $order->get_shipping_address_1(),
				'address_2'  => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'state'      => $order->get_shipping_state(),
				'postcode'   => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
			);

			// Get split payment settings from product
			$payment_type          = get_post_meta( $product_id, '_recurio_payment_type', true ) ?: 'recurring';
			$max_payments          = intval( get_post_meta( $product_id, '_recurio_max_payments', true ) ) ?: 0;
			$access_timing         = get_post_meta( $product_id, '_recurio_access_timing', true ) ?: 'immediate';
			$access_duration_value = intval( get_post_meta( $product_id, '_recurio_access_duration_value', true ) ) ?: 1;
			$access_duration_unit  = get_post_meta( $product_id, '_recurio_access_duration_unit', true ) ?: 'month';

			// For split payments, calculate the installment amount
			$billing_amount = $price;
			if ( $payment_type === 'split' && $max_payments > 0 ) {
				$billing_amount = $price / $max_payments; // Each installment amount
			}

			// Prepare metadata (keep only essential data in metadata)
			$metadata = array(
				'signup_fee'           => $signup_fee,
				'initial_order_id'     => $order_id,
				'order_item_id'        => $item_id,
				'payment_method_title' => $payment_method_title, // Keep human-readable title in metadata
			);

			// Store variation_id if this is a variation
			if ( $variation_id ) {
				$metadata['variation_id'] = $variation_id;
			}

			// For split payments, also store the total price in metadata
			if ( $payment_type === 'split' && $max_payments > 0 ) {
				$metadata['total_price'] = $price;
			}

			// Determine initial status based on payment type and access timing
			$initial_status = 'active';
			if ( $payment_type === 'split' && $access_timing === 'after_full_payment' ) {
				$initial_status = 'pending_payment'; // Will become active after all payments
			}

			// Calculate access end date for custom duration
			$access_end_date = null;
			if ( $access_timing === 'custom_duration' && $access_duration_value > 0 ) {
				$access_end = new DateTime();
				switch ( $access_duration_unit ) {
					case 'day':
						$access_end->add( new DateInterval( 'P' . $access_duration_value . 'D' ) );
						break;
					case 'week':
						$access_end->add( new DateInterval( 'P' . ( $access_duration_value * 7 ) . 'D' ) );
						break;
					case 'month':
						$access_end->add( new DateInterval( 'P' . $access_duration_value . 'M' ) );
						break;
					case 'year':
						$access_end->add( new DateInterval( 'P' . $access_duration_value . 'Y' ) );
						break;
				}
				$access_end_date = $access_end->format( 'Y-m-d H:i:s' );
			}

			// Determine initial renewal count
			// For split payments, first installment is already paid with initial order
			// BUT if there's a trial period, only sign-up fee is paid initially - no installment yet
			$initial_renewal_count = 0;
			$has_trial = ( $trial_days > 0 );

			if ( $payment_type === 'split' ) {
				// For split payments: count as 1 only if no trial (first installment paid with initial order)
				// If there's a trial, installments start after trial ends
				if ( ! $has_trial ) {
					$initial_renewal_count = 1; // First installment paid
				}
				// With trial: initial_renewal_count stays 0 (only sign-up fee paid, no installment)
			} elseif ( $signup_fee > 0 && ! $has_trial ) {
				// For recurring with signup fee and no trial: first recurring payment included
				$initial_renewal_count = 1;
			}

			// Create subscription
			$subscription_data = array(
				'customer_id'           => $customer_id,
				'product_id'            => $product_id,
				'wc_subscription_id'    => $order_id, // Store order ID as reference
				'status'                => $initial_status,
				'billing_period'        => $period,
				'billing_interval'      => $interval,
				'billing_amount'        => $billing_amount, // Installment amount for split payments, full price for recurring
				'payment_method'        => $payment_method, // Store payment gateway ID in dedicated column
				'payment_token_id'      => $payment_token_id, // Store payment token ID for renewals
				'billing_address'       => json_encode( $billing_address ), // Store billing address as JSON
				'shipping_address'      => json_encode( $shipping_address ), // Store shipping address as JSON
				'trial_end_date'        => $trial_end_date,
				'next_payment_date'     => $this->calculate_next_payment_date( $period, $interval, $trial_end_date, $signup_fee > 0 || $payment_type === 'split' ),
				'renewal_count'         => $initial_renewal_count,
				'max_renewals'          => $max_renewals, // Set max renewals from product settings
				'payment_type'          => $payment_type, // 'recurring' or 'split'
				'max_payments'          => $max_payments, // Number of installment payments (0 = unlimited)
				'access_timing'         => $access_timing, // 'immediate', 'after_full_payment', or 'custom_duration'
				'access_duration_value' => $access_duration_value, // Duration value for custom access
				'access_duration_unit'  => $access_duration_unit, // Duration unit for custom access
				'access_end_date'       => $access_end_date, // Calculated access end date for custom duration
				'subscription_metadata' => json_encode( $metadata ), // Store sign-up fee and other data
				'created_at'            => current_time( 'mysql' ),
				'updated_at'            => current_time( 'mysql' ),
			);

			$subscription_id = $this->create_subscription( $subscription_data );

			if ( $subscription_id && ! is_wp_error( $subscription_id ) ) {
				// Store subscription ID in order item meta
				wc_add_order_item_meta( $item_id, '_recurio_subscription_id', $subscription_id );

				// Track created subscription
				$subscriptions_created[] = $subscription_id;

				// Log the initial payment (including sign-up fee if any)
				$initial_payment = $item->get_total() / $item->get_quantity(); // This includes sign-up fee
				$payment_gateway = $order->get_payment_method(); // Use gateway ID, not title
				$transaction_id  = $order->get_transaction_id() ?: 'order_' . $order_id;

				// Record the initial payment
				$this->log_revenue( $subscription_id, $initial_payment, $payment_gateway, $transaction_id );

				// Log event
				$this->log_event( $subscription_id, 'subscription_created', $price, array( 'order_id' => $order_id ) );
				$this->log_event(
					$subscription_id,
					'initial_payment',
					$initial_payment,
					array(
						'order_id'       => $order_id,
						'payment_method' => $payment_gateway,
						'transaction_id' => $transaction_id,
						'signup_fee'     => $signup_fee,
					)
				);

			}
		}

		// If we created any subscriptions, mark the order as processed
		if ( ! empty( $subscriptions_created ) ) {
			// Mark order as processed to prevent duplicate creation
			update_post_meta( $order_id, '_recurio_subscriptions_created', 'yes' );

			// Store all subscription IDs in order meta
			update_post_meta( $order_id, '_recurio_subscription_ids', $subscriptions_created );

			// Add order note
			$order->add_order_note(
				sprintf(
				/* translators: %d: Number of subscriptions created */
					__( 'Created %d subscription(s) from this order.', 'recurio' ),
					count( $subscriptions_created )
				)
			);

			// Trigger action
			do_action( 'recurio_subscriptions_created_from_order', $subscriptions_created, $order_id );

			return $subscriptions_created;
		}

		return false;
	}

	/**
	 * Sync subscription status with order status
	 */
	public function sync_subscription_status( $order_id, $old_status, $new_status ) {
		$subscription_id = get_post_meta( $order_id, '_recurio_subscription_id', true );

		if ( ! $subscription_id ) {
			return;
		}

		// Map order status to subscription status
		$status_map = array(
			'completed'  => 'active',
			'processing' => 'active',
			'on-hold'    => 'paused',
			'cancelled'  => 'cancelled',
			'refunded'   => 'cancelled',
			'failed'     => 'paused',
		);

		if ( isset( $status_map[ $new_status ] ) ) {
			$this->update_subscription(
				$subscription_id,
				array(
					'status' => $status_map[ $new_status ],
				)
			);

			$this->log_event( $subscription_id, 'status_synced', $new_status );
		}
	}

	/**
	 * Check and resume paused subscriptions
	 */
	public function check_paused_subscriptions() {
		global $wpdb;

		$today = current_time( 'mysql' );

		// Get paused subscriptions that should be resumed
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for subscription management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}recurio_subscriptions
                WHERE status = 'paused'
                AND pause_end_date IS NOT NULL
                AND pause_end_date <= %s",
				$today
			)
		);

		foreach ( $subscriptions as $subscription ) {
			$this->resume_subscription( $subscription->id );
			$this->log_event(
				$subscription->id,
				'auto_resumed',
				null,
				array(
					'pause_end_date' => $subscription->pause_end_date,
				)
			);
		}

		return count( $subscriptions );
	}

	/**
	 * AJAX handler for pausing subscription
	 */
	public function ajax_pause_subscription() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'recurio' ) );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		$duration        = isset( $_POST['duration'] ) ? intval( $_POST['duration'] ) : null;

		$result = $this->pause_subscription( $subscription_id, $duration );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success( __( 'Subscription paused successfully', 'recurio' ) );
		}
	}

	/**
	 * AJAX handler for resuming subscription
	 */
	public function ajax_resume_subscription() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'recurio' ) );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;

		$result = $this->resume_subscription( $subscription_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success( __( 'Subscription resumed successfully', 'recurio' ) );
		}
	}

	/**
	 * AJAX handler for cancelling subscription
	 */
	public function ajax_cancel_subscription() {
		check_ajax_referer( 'recurio_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'recurio' ) );
		}

		$subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
		$reason          = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		$result = $this->cancel_subscription( $subscription_id, $reason );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success( __( 'Subscription cancelled successfully', 'recurio' ) );
		}
	}
}
