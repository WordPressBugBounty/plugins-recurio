<?php
/**
 * Email Notifications Class
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_Email_Notifications {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Delay hook registration until after Pro status is checked (priority 150 > Pro's 100)
		add_action( 'plugins_loaded', array( $this, 'init_hooks' ), 150 );
	}

	public function init_hooks() {
		// Check if Pro advanced emails are active
		$pro_emails_active = function_exists( 'recurio_pro_has_advanced_emails' ) && recurio_pro_has_advanced_emails();

		if ( ! $pro_emails_active ) {
			// Free only: Pro layers dunning / trial campaigns on these hooks when licensed.
			add_action( 'recurio_payment_failed', array( $this, 'send_payment_failed_email' ), 10, 3 );
			add_action( 'recurio_trial_ending', array( $this, 'send_trial_ending_email' ), 10, 2 );
		}

		// Immediate transactional emails must always run: Pro only schedules follow-ups (pause reminders,
		// win-back) on the same actions and does not replace these wp_mail notifications.
		add_action( 'recurio_subscription_paused', array( $this, 'send_subscription_paused_email' ), 10, 2 );
		add_action( 'recurio_subscription_cancelled', array( $this, 'send_subscription_cancelled_email' ), 10, 3 );

		// Always register these (no Pro equivalent yet)
		add_action( 'recurio_subscription_created', array( $this, 'send_subscription_created_email' ), 10, 2 );
		add_action( 'recurio_subscription_activated', array( $this, 'send_subscription_activated_email' ), 10, 2 );
		add_action( 'recurio_subscription_resumed', array( $this, 'send_subscription_resumed_email' ), 10, 2 );
		add_action( 'recurio_subscription_skipped', array( $this, 'send_subscription_skipped_email' ), 10, 2 );
		add_action( 'recurio_subscription_expired', array( $this, 'send_subscription_expired_email' ), 10, 2 );
		add_action( 'recurio_payment_successful', array( $this, 'send_payment_successful_email' ), 10, 3 );
		add_action( 'recurio_renewal_reminder', array( $this, 'send_renewal_reminder_email' ), 10, 2 );
	}

	/**
	 * Check if email trigger is enabled
	 *
	 * @param string $trigger_key Setting key for the trigger.
	 * @return bool
	 */
	private function is_email_trigger_enabled( $trigger_key ) {
		$settings = get_option( 'recurio_settings', array() );

		// If emails section doesn't exist, default to enabled for backwards compatibility
		if ( ! isset( $settings['emails'] ) ) {
			return true;
		}

		// Check if specific trigger is enabled (default to true if not set)
		return isset( $settings['emails'][ $trigger_key ] ) ? (bool) $settings['emails'][ $trigger_key ] : true;
	}

	/**
	 * Merge optional Pro custom template overrides into subject/body.
	 *
	 * @param string               $template_key    Template slug.
	 * @param string               $default_subject Default subject line.
	 * @param array<string, mixed> $variables       Variables passed to the template.
	 * @return array{subject:string,body:string}
	 */
	private function finalize_email( $template_key, $default_subject, $variables ) {
		$body = $this->get_email_template( $template_key, $variables );

		if ( ! function_exists( 'recurio_email_finalize_template' ) ) {
			return array(
				'subject' => $default_subject,
				'body'    => $body,
			);
		}

		return recurio_email_finalize_template( $template_key, $default_subject, $body, $variables );
	}

	/**
	 * Send subscription created email
	 */
	public function send_subscription_created_email( $subscription_id, $subscription_data ) {
		// Check if trigger is enabled
		if ( ! $this->is_email_trigger_enabled( 'sendWelcome' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Welcome! Your %s subscription has been created', 'recurio' ), $product_name );
		$variables = array(
			'customer_name'     => $customer->display_name,
			'product_name'      => $product_name,
			'billing_amount'    => wc_price( $subscription_data['billing_amount'] ),
			'billing_period'    => $subscription_data['billing_period'],
			'next_payment_date' => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['next_payment_date'] ) ),
			'subscription_id'   => $subscription_id,
			'manage_url'        => $this->get_subscription_manage_url( $subscription_id ),
		);
		$email   = $this->finalize_email( 'subscription_created', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );

		// Send admin notification
		$this->send_admin_notification(
			__( 'New Subscription Created', 'recurio' ),
			sprintf(
				/* translators: 1: subscription ID, 2: customer name, 3: customer email, 4: product name, 5: billing amount */
				__( 'A new subscription (#%1$d) has been created for %2$s (%3$s) - Product: %4$s, Amount: %5$s', 'recurio' ),
				$subscription_id,
				$customer->display_name,
				$customer->user_email,
				$product_name,
				wc_price( $subscription_data['billing_amount'] )
			)
		);
	}

	/**
	 * Send subscription activated email
	 */
	public function send_subscription_activated_email( $subscription_id, $subscription_data ) {
		// Check if trigger is enabled (using sendWelcome for activation emails too)
		if ( ! $this->is_email_trigger_enabled( 'sendWelcome' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription is now active', 'recurio' ), $product_name );
		$next_payment_fmt = '';
		if ( ! empty( $subscription_data['next_payment_date'] ) ) {
			$next_payment_fmt = date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['next_payment_date'] ) );
		}
		$variables = array(
			'customer_name'     => $customer->display_name,
			'product_name'      => $product_name,
			'subscription_id'   => $subscription_id,
			'manage_url'        => $this->get_subscription_manage_url( $subscription_id ),
			'next_payment_date' => $next_payment_fmt,
		);
		$email     = $this->finalize_email( 'subscription_activated', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Send subscription paused email
	 */
	public function send_subscription_paused_email( $subscription_id, $subscription_data ) {
		if ( ! $this->is_email_trigger_enabled( 'sendSubscriptionPaused' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription has been paused', 'recurio' ), $product_name );
		$variables = array(
			'customer_name'   => $customer->display_name,
			'product_name'    => $product_name,
			'pause_duration'  => isset( $subscription_data['pause_duration'] ) ? $subscription_data['pause_duration'] : 'indefinitely',
			'subscription_id' => $subscription_id,
			'manage_url'      => $this->get_subscription_manage_url( $subscription_id ),
		);
		$email     = $this->finalize_email( 'subscription_paused', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Notify customer after skipping the next billing cycle
	 *
	 * @param int                  $subscription_id Subscription ID.
	 * @param array<string, mixed> $payload         Payload from recurio_subscription_skipped.
	 */
	public function send_subscription_skipped_email( $subscription_id, $payload ) {
		if ( ! $this->is_email_trigger_enabled( 'subscriptionSkipped' ) ) {
			return;
		}

		$subscription = Recurio_Subscription_Engine::get_instance()->get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription->customer_id );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription->product_id );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		$skipped_date_fmt = isset( $payload['previous_next_payment'] )
			? date_i18n( get_option( 'date_format' ), strtotime( $payload['previous_next_payment'] ) )
			: '';
		$new_date_fmt = isset( $payload['new_next_payment'] )
			? date_i18n( get_option( 'date_format' ), strtotime( $payload['new_next_payment'] ) )
			: '';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your upcoming %s payment was skipped', 'recurio' ), $product_name );
		$variables = array(
			'customer_name'          => $customer->display_name,
			'product_name'           => $product_name,
			'skipped_amount'         => wc_price( $subscription->billing_amount ),
			'skipped_payment_date'   => $skipped_date_fmt,
			'new_next_payment_date'  => $new_date_fmt,
			'subscription_id'        => $subscription_id,
			'manage_url'             => $this->get_subscription_manage_url( $subscription_id ),
		);
		$email     = $this->finalize_email( 'subscription_skipped', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Send subscription resumed email
	 */
	public function send_subscription_resumed_email( $subscription_id, $subscription_data ) {
		if ( ! $this->is_email_trigger_enabled( 'sendSubscriptionResumed' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription has been resumed', 'recurio' ), $product_name );
		$variables = array(
			'customer_name'     => $customer->display_name,
			'product_name'      => $product_name,
			'next_payment_date' => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['next_payment_date'] ) ),
			'subscription_id'   => $subscription_id,
			'manage_url'        => $this->get_subscription_manage_url( $subscription_id ),
		);
		$email     = $this->finalize_email( 'subscription_resumed', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Send subscription cancelled email
	 */
	public function send_subscription_cancelled_email( $subscription_id, $subscription_data, $reason = '' ) {
		if ( ! $this->is_email_trigger_enabled( 'sendCancellation' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription has been cancelled', 'recurio' ), $product_name );

		$cancellation_date = isset( $subscription_data['cancellation_date'] ) ? $subscription_data['cancellation_date'] : 'immediately';

		$variables = array(
			'customer_name'     => $customer->display_name,
			'product_name'      => $product_name,
			'cancellation_date' => $cancellation_date === 'immediately' ? __( 'immediately', 'recurio' ) : date_i18n( get_option( 'date_format' ), strtotime( $cancellation_date ) ),
			'reason'            => $reason,
			'subscription_id'   => $subscription_id,
			'reactivate_url'    => $this->get_subscription_reactivate_url( $subscription_id ),
		);
		$email     = $this->finalize_email( 'subscription_cancelled', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );

		// Send admin notification
		$this->send_admin_notification(
			__( 'Subscription Cancelled', 'recurio' ),
			sprintf(
				/* translators: 1: subscription ID, 2: customer name, 3: customer email, 4: product name, 5: cancellation reason (may be empty) */
				__( 'Subscription #%1$d has been cancelled for %2$s (%3$s) - Product: %4$s%5$s', 'recurio' ),
				$subscription_id,
				$customer->display_name,
				$customer->user_email,
				$product_name,
				/* translators: %s: cancellation reason */
				$reason ? sprintf( __( ', Reason: %s', 'recurio' ), $reason ) : ''
			)
		);
	}

	/**
	 * Send subscription expired email
	 */
	public function send_subscription_expired_email( $subscription_id, $subscription_data ) {
		if ( ! $this->is_email_trigger_enabled( 'sendSubscriptionExpired' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription has expired', 'recurio' ), $product_name );
		$variables = array(
			'customer_name'   => $customer->display_name,
			'product_name'    => $product_name,
			'subscription_id' => $subscription_id,
			'renew_url'       => $this->get_subscription_renew_url( $subscription_id ),
		);
		$email     = $this->finalize_email( 'subscription_expired', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Send payment successful email
	 */
	public function send_payment_successful_email( $subscription_id, $payment_data, $subscription_data ) {
		// Check if trigger is enabled
		if ( ! $this->is_email_trigger_enabled( 'sendReceipt' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		$subject = __( 'Payment received for your subscription', 'recurio' );
		$next_payment_fmt = '';
		if ( ! empty( $subscription_data['next_payment_date'] ) ) {
			$next_ts = strtotime( $subscription_data['next_payment_date'] );
			if ( $next_ts ) {
				$next_payment_fmt = date_i18n( get_option( 'date_format' ), $next_ts );
			}
		}
		$variables = array(
			'customer_name'     => $customer->display_name,
			'product_name'      => $product_name,
			'payment_amount'    => wc_price( $payment_data['amount'] ),
			'payment_date'      => date_i18n( get_option( 'date_format' ), strtotime( $payment_data['date'] ) ),
			'next_payment_date' => $next_payment_fmt !== '' ? $next_payment_fmt : __( 'Not scheduled', 'recurio' ),
			'subscription_id'   => $subscription_id,
			'invoice_url'       => $this->get_invoice_url( $payment_data['transaction_id'] ),
		);
		$email     = $this->finalize_email( 'payment_successful', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Send payment failed email
	 */
	public function send_payment_failed_email( $subscription_id, $payment_data, $subscription_data ) {
		if ( is_object( $subscription_data ) ) {
			$subscription_data = (array) $subscription_data;
		}
		if ( ! is_array( $payment_data ) ) {
			$failure_reason = is_string( $payment_data ) ? $payment_data : __( 'Payment method declined', 'recurio' );
			$payment_data   = array(
				'amount'         => isset( $subscription_data['billing_amount'] ) ? floatval( $subscription_data['billing_amount'] ) : 0,
				'failure_reason' => $failure_reason,
			);
		}

		// Check if trigger is enabled
		if ( ! $this->is_email_trigger_enabled( 'sendFailedPayment' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		$subject = __( 'Payment failed for your subscription', 'recurio' );
		$variables = array(
			'customer_name'      => $customer->display_name,
			'product_name'       => $product_name,
			'payment_amount'     => wc_price( $payment_data['amount'] ),
			'failure_reason'     => isset( $payment_data['failure_reason'] ) ? $payment_data['failure_reason'] : __( 'Payment method declined', 'recurio' ),
			'retry_date'         => date_i18n( get_option( 'date_format' ), strtotime( '+3 days' ) ),
			'subscription_id'    => $subscription_id,
			'update_payment_url' => $this->get_update_payment_url( $subscription_id ),
		);
		$email     = $this->finalize_email( 'payment_failed', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );

		// Send admin notification
		$this->send_admin_notification(
			__( 'Payment Failed', 'recurio' ),
			sprintf(
				/* translators: 1: subscription ID, 2: customer name, 3: customer email, 4: payment amount */
				__( 'Payment failed for subscription #%1$d - Customer: %2$s (%3$s), Amount: %4$s', 'recurio' ),
				$subscription_id,
				$customer->display_name,
				$customer->user_email,
				wc_price( $payment_data['amount'] )
			)
		);
	}

	/**
	 * Send trial ending email
	 */
	public function send_trial_ending_email( $subscription_id, $subscription_data ) {
		if ( ! $this->is_email_trigger_enabled( 'sendTrialReminder' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your trial for %s is ending soon', 'recurio' ), $product_name );
		$variables = array(
			'customer_name'   => $customer->display_name,
			'product_name'    => $product_name,
			'trial_end_date'  => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['trial_end_date'] ) ),
			'billing_amount'  => wc_price( $subscription_data['billing_amount'] ),
			'billing_period'  => $subscription_data['billing_period'],
			'subscription_id' => $subscription_id,
			'manage_url'      => $this->get_subscription_manage_url( $subscription_id ),
		);
		$email     = $this->finalize_email( 'trial_ending', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Send renewal reminder email
	 */
	public function send_renewal_reminder_email( $subscription_id, $subscription_data ) {
		// Check if trigger is enabled
		if ( ! $this->is_email_trigger_enabled( 'sendRenewalReminder' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : __( 'Subscription', 'recurio' );

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Renewal reminder for your %s subscription', 'recurio' ), $product_name );
		$variables = array(
			'customer_name'   => $customer->display_name,
			'product_name'    => $product_name,
			'renewal_date'    => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['next_payment_date'] ) ),
			'billing_amount'  => wc_price( $subscription_data['billing_amount'] ),
			'subscription_id' => $subscription_id,
			'manage_url'      => $this->get_subscription_manage_url( $subscription_id ),
		);
		$email     = $this->finalize_email( 'renewal_reminder', $subject, $variables );

		$this->send_email( $customer->user_email, $email['subject'], $email['body'] );
	}

	/**
	 * Get email template
	 */
	private function get_email_template( $template_name, $variables = array() ) {
		// Extract variables for use in template
		extract( $variables );

		// Start output buffering
		ob_start();

		// Load template file if it exists, otherwise use default
		$template_file = RECURIO_PLUGIN_DIR . 'templates/emails/' . $template_name . '.php';
		if ( file_exists( $template_file ) ) {
			include $template_file;
		} else {
			// Use default template
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML email content, already sanitized in get_default_template
			echo wp_kses_post( $this->get_default_template( $template_name, $variables ) );
		}

		// Get and return the buffer content
		return ob_get_clean();
	}

	/**
	 * Get default email template
	 */
	private function get_default_template( $template_name, $variables ) {
		// Sanitize all variables for safe HTML output.
		$customer_name         = isset( $variables['customer_name'] ) ? esc_html( $variables['customer_name'] ) : 'Customer';
		$product_name          = isset( $variables['product_name'] ) ? esc_html( $variables['product_name'] ) : 'subscription';
		$subscription_id       = isset( $variables['subscription_id'] ) ? esc_html( $variables['subscription_id'] ) : '';
		$billing_amount        = isset( $variables['billing_amount'] ) ? wp_kses_post( $variables['billing_amount'] ) : '';
		$billing_period        = isset( $variables['billing_period'] ) ? esc_html( $variables['billing_period'] ) : '';
		$next_payment_date     = isset( $variables['next_payment_date'] ) ? esc_html( $variables['next_payment_date'] ) : '';
		$manage_url            = isset( $variables['manage_url'] ) ? esc_url( $variables['manage_url'] ) : '#';
		$cancellation_date     = isset( $variables['cancellation_date'] ) ? esc_html( $variables['cancellation_date'] ) : '';
		$reason                = isset( $variables['reason'] ) ? esc_html( $variables['reason'] ) : '';
		$reactivate_url        = isset( $variables['reactivate_url'] ) ? esc_url( $variables['reactivate_url'] ) : '#';
		$renew_url             = isset( $variables['renew_url'] ) ? esc_url( $variables['renew_url'] ) : '#';
		$payment_amount        = isset( $variables['payment_amount'] ) ? wp_kses_post( $variables['payment_amount'] ) : '';
		$payment_date          = isset( $variables['payment_date'] ) ? esc_html( $variables['payment_date'] ) : '';
		$failure_reason        = isset( $variables['failure_reason'] ) ? esc_html( $variables['failure_reason'] ) : '';
		$retry_date            = isset( $variables['retry_date'] ) ? esc_html( $variables['retry_date'] ) : '';
		$update_payment_url    = isset( $variables['update_payment_url'] ) ? esc_url( $variables['update_payment_url'] ) : '#';
		$invoice_url           = isset( $variables['invoice_url'] ) ? esc_url( $variables['invoice_url'] ) : '#';
		$skipped_amount        = isset( $variables['skipped_amount'] ) ? wp_kses_post( $variables['skipped_amount'] ) : '';
		$skipped_payment_date  = isset( $variables['skipped_payment_date'] ) ? esc_html( $variables['skipped_payment_date'] ) : '';
		$new_next_payment_date = isset( $variables['new_next_payment_date'] ) ? esc_html( $variables['new_next_payment_date'] ) : '';
		$trial_end_date        = isset( $variables['trial_end_date'] ) ? esc_html( $variables['trial_end_date'] ) : '';
		$renewal_date          = isset( $variables['renewal_date'] ) ? esc_html( $variables['renewal_date'] ) : '';

		$btn  = 'display:inline-block;padding:14px 36px;font-size:15px;font-weight:700;text-decoration:none;border-radius:8px;color:#ffffff;';
		$panel = 'border-radius:10px;padding:18px 22px;margin:24px 0;';

		$templates = array(
			'subscription_created' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Thank you for subscribing to <strong>{$product_name}</strong>! Your subscription is now set up and ready to go.</p>
<div style=\"background:#f8fafc;{$panel}border-left:4px solid #6366f1;\">
  <p style=\"margin:0 0 8px;font-size:13px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:0.06em;\">Subscription Details</p>
  <p style=\"margin:0 0 6px;\"><strong>Plan:</strong> {$product_name}</p>
  <p style=\"margin:0 0 6px;\"><strong>Amount:</strong> {$billing_amount} / {$billing_period}</p>
  <p style=\"margin:0;\"><strong>Next payment:</strong> {$next_payment_date}</p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$manage_url}\" style=\"{$btn}background:#6366f1;\">Manage your subscription &rarr;</a>
</p>
<p>If you have any questions, just reply to this email — we're always happy to help.</p>
<p>Best regards,<br><strong>The Team</strong></p>",

			'subscription_activated' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Great news — your <strong>{$product_name}</strong> subscription is now fully active. Everything is set up and ready to use!</p>
<div style=\"background:#f0fdf4;{$panel}border-left:4px solid #22c55e;\">
  <p style=\"margin:0 0 6px;\"><strong>Plan:</strong> {$product_name}</p>
  <p style=\"margin:0;\"><strong>Subscription ID:</strong> #{$subscription_id}</p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$manage_url}\" style=\"{$btn}background:#22c55e;\">Manage your subscription &rarr;</a>
</p>
<p>Best regards,<br><strong>The Team</strong></p>",

			'subscription_paused' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Your <strong>{$product_name}</strong> subscription has been paused. No further charges will be made while it's paused — you can resume it at any time.</p>
<div style=\"background:#fffbeb;{$panel}border-left:4px solid #f59e0b;\">
  <p style=\"margin:0 0 6px;\"><strong>Plan:</strong> {$product_name}</p>
  <p style=\"margin:0 0 6px;\"><strong>Subscription ID:</strong> #{$subscription_id}</p>
  <p style=\"margin:0;\"><strong>Status:</strong> <span style=\"color:#d97706;font-weight:700;\">Paused</span></p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$manage_url}\" style=\"{$btn}background:#f59e0b;\">Resume subscription &rarr;</a>
</p>
<p>Ready to come back? We'll be here when you are.</p>
<p>Best regards,<br><strong>The Team</strong></p>",

			'subscription_resumed' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Welcome back! Your <strong>{$product_name}</strong> subscription has been successfully resumed and is now active. Billing will continue on your normal schedule.</p>
<div style=\"background:#f0fdfa;{$panel}border-left:4px solid #14b8a6;\">
  <p style=\"margin:0 0 6px;\"><strong>Plan:</strong> {$product_name}</p>
  <p style=\"margin:0 0 6px;\"><strong>Subscription ID:</strong> #{$subscription_id}</p>
  <p style=\"margin:0;\"><strong>Next payment:</strong> {$next_payment_date}</p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$manage_url}\" style=\"{$btn}background:#14b8a6;\">Manage your subscription &rarr;</a>
</p>
<p>Thank you for being with us!<br><strong>The Team</strong></p>",

			'subscription_skipped' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Your billing cycle for <strong>{$product_name}</strong> has been skipped as requested. No charge was made for this period.</p>
<div style=\"background:#eef2ff;{$panel}border-left:4px solid #6366f1;\">
  <p style=\"margin:0 0 6px;\"><strong>Skipped charge:</strong> {$skipped_amount}</p>
  <p style=\"margin:0 0 6px;\"><strong>Skipped date:</strong> {$skipped_payment_date}</p>
  <p style=\"margin:0;\"><strong>Next billing date:</strong> <span style=\"color:#6366f1;font-weight:700;\">{$new_next_payment_date}</span></p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$manage_url}\" style=\"{$btn}background:#6366f1;\">View your subscription &rarr;</a>
</p>
<p>Best regards,<br><strong>The Team</strong></p>",

			'subscription_cancelled' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Your subscription to <strong>{$product_name}</strong> has been cancelled. We're sorry to see you go.</p>
<div style=\"background:#f8fafc;{$panel}border-left:4px solid #94a3b8;\">
  <p style=\"margin:0 0 6px;\"><strong>Plan:</strong> {$product_name}</p>
  <p style=\"margin:0 0 6px;\"><strong>Cancelled on:</strong> {$cancellation_date}</p>
  <p style=\"margin:0;\"><strong>Reason:</strong> {$reason}</p>
</div>
<p>Changed your mind? You can reactivate at any time — we'd love to have you back.</p>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$reactivate_url}\" style=\"{$btn}background:#6366f1;\">Reactivate subscription</a>
</p>
<p>Wishing you all the best,<br><strong>The Team</strong></p>",

			'subscription_expired' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Your <strong>{$product_name}</strong> subscription has expired. We hope you enjoyed the service — you can renew at any time to regain access.</p>
<div style=\"background:#fff7ed;{$panel}border-left:4px solid #f97316;\">
  <p style=\"margin:0 0 6px;\"><strong>Plan:</strong> {$product_name}</p>
  <p style=\"margin:0;\"><strong>Subscription ID:</strong> #{$subscription_id}</p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$renew_url}\" style=\"{$btn}background:#f97316;\">Renew subscription &rarr;</a>
</p>
<p>We'd love to have you back!<br><strong>The Team</strong></p>",

			'payment_successful' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Great news — your payment for <strong>{$product_name}</strong> was processed successfully. Here's your receipt.</p>
<div style=\"background:#f0fdf4;{$panel}border-left:4px solid #10b981;\">
  <p style=\"margin:0 0 6px;font-size:13px;font-weight:700;color:#10b981;text-transform:uppercase;letter-spacing:0.06em;\">Payment Receipt</p>
  <p style=\"margin:0 0 6px;\"><strong>Plan:</strong> {$product_name}</p>
  <p style=\"margin:0 0 6px;\"><strong>Amount:</strong> <span style=\"color:#059669;font-weight:700;\">{$payment_amount}</span></p>
  <p style=\"margin:0 0 6px;\"><strong>Payment date:</strong> {$payment_date}</p>
  <p style=\"margin:0;\"><strong>Next renewal:</strong> {$next_payment_date}</p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$invoice_url}\" style=\"{$btn}background:#10b981;\">View invoice &rarr;</a>
</p>
<p>Thank you for your continued support!<br><strong>The Team</strong></p>",

			'payment_failed' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>We were unable to process your payment for <strong>{$product_name}</strong>. Please update your payment details as soon as possible to keep your subscription active.</p>
<div style=\"background:#fef2f2;{$panel}border-left:4px solid #ef4444;\">
  <p style=\"margin:0 0 6px;font-size:13px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:0.06em;\">Payment Failed</p>
  <p style=\"margin:0 0 6px;\"><strong>Amount due:</strong> <span style=\"color:#dc2626;font-weight:700;\">{$payment_amount}</span></p>
  <p style=\"margin:0 0 6px;\"><strong>Reason:</strong> {$failure_reason}</p>
  <p style=\"margin:0;\"><strong>Next retry:</strong> {$retry_date}</p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$update_payment_url}\" style=\"{$btn}background:#ef4444;\">Update payment method &rarr;</a>
</p>
<p>Need help? Just reply to this email.<br><strong>The Team</strong></p>",

			'trial_ending' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Your free trial for <strong>{$product_name}</strong> is ending soon. To continue without interruption, no action is needed if your payment details are already on file.</p>
<div style=\"background:#faf5ff;{$panel}border-left:4px solid #8b5cf6;\">
  <p style=\"margin:0 0 6px;\"><strong>Trial ends:</strong> <span style=\"color:#7c3aed;font-weight:700;\">{$trial_end_date}</span></p>
  <p style=\"margin:0 0 6px;\"><strong>Billing starts:</strong> {$billing_amount} / {$billing_period}</p>
  <p style=\"margin:0;\"><strong>Plan:</strong> {$product_name}</p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$manage_url}\" style=\"{$btn}background:#8b5cf6;\">Manage subscription &rarr;</a>
</p>
<p>Thanks for giving us a try!<br><strong>The Team</strong></p>",

			'renewal_reminder' => "
<p>Hi <strong>{$customer_name}</strong>,</p>
<p>Just a heads-up — your <strong>{$product_name}</strong> subscription is due for renewal. We'll automatically charge the card on file unless you make changes before the renewal date.</p>
<div style=\"background:#eff6ff;{$panel}border-left:4px solid #3b82f6;\">
  <p style=\"margin:0 0 6px;font-size:13px;font-weight:700;color:#3b82f6;text-transform:uppercase;letter-spacing:0.06em;\">Upcoming Renewal</p>
  <p style=\"margin:0 0 6px;\"><strong>Plan:</strong> {$product_name}</p>
  <p style=\"margin:0 0 6px;\"><strong>Amount:</strong> <span style=\"color:#1d4ed8;font-weight:700;\">{$billing_amount}</span></p>
  <p style=\"margin:0;\"><strong>Renewal date:</strong> {$renewal_date}</p>
</div>
<p style=\"text-align:center;margin:28px 0;\">
  <a href=\"{$manage_url}\" style=\"{$btn}background:#3b82f6;\">Manage subscription &rarr;</a>
</p>
<p>To cancel or change your plan before renewal, visit your subscription portal.</p>
<p>Best regards,<br><strong>The Team</strong></p>",
		);

		return isset( $templates[ $template_name ] ) ? $templates[ $template_name ] : "<p>Hi <strong>{$customer_name}</strong>,</p><p>This is a notification about your <strong>{$product_name}</strong> subscription.</p>";
	}

	/**
	 * Send email
	 */
	private function send_email( $to, $subject, $message, $headers = array() ) {
		$default_headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		$headers = array_merge( $default_headers, $headers );

		// Wrap message in email template
		$wrapped_message = $this->wrap_email_template( $message );

		// Send email
		return wp_mail( $to, $subject, $wrapped_message, $headers );
	}

	/**
	 * Send admin notification
	 */
	private function send_admin_notification( $subject, $message ) {
		$settings = get_option( 'recurio_settings', array() );

		// Check if admin notifications are enabled
		if ( ! isset( $settings['enable_admin_notifications'] ) || ! $settings['enable_admin_notifications'] ) {
			return;
		}

		$admin_email = isset( $settings['admin_notification_email'] ) ? $settings['admin_notification_email'] : get_option( 'admin_email' );

		$full_subject = '[' . get_bloginfo( 'name' ) . '] ' . $subject;

		$this->send_email( $admin_email, $full_subject, '<p>' . esc_html( $message ) . '</p>' );
	}

	/**
	 * Wrap email in template
	 */
	private function wrap_email_template( $content ) {
		$settings       = get_option( 'recurio_settings', array() );
		$email_settings = isset( $settings['emails'] ) ? $settings['emails'] : array();
		$site_name      = esc_html( get_bloginfo( 'name' ) );
		$year           = gmdate( 'Y' );

		// ── Header ────────────────────────────────────────────────────────────
		// Logo always shows when uploaded (white bar).
		// Custom headerHtml appears as a sub-line in the dark bar below the logo,
		// or as the sole content in the dark bar when no logo is set.
		// If neither is set, the site name fills the dark bar.
		$custom_header_html = ! empty( $email_settings['headerHtml'] ) ? $email_settings['headerHtml'] : '';
		$header_inner       = function_exists( 'recurio_get_subscription_email_header_inner_html' )
			? recurio_get_subscription_email_header_inner_html()
			: '';
		$has_logo           = $header_inner && strpos( $header_inner, '<img' ) !== false;

		$font_stack = '-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif';

		if ( $has_logo && $custom_header_html ) {
			// Logo (white bar) + custom text below in dark accent bar.
			$header_block = '
			<div style="background:#ffffff;padding:24px 40px;text-align:center;border-bottom:1px solid #e2e8f0;">
				' . $header_inner . '
			</div>
			<div style="background:#1e293b;padding:14px 40px;text-align:center;">
				<div style="font-family:' . $font_stack . ';font-size:14px;color:rgba(248,250,252,0.85);line-height:1.5;">'
				. $custom_header_html .
				'</div>
			</div>';
		} elseif ( $has_logo ) {
			// Logo only — white bar.
			$header_block = '
			<div style="background:#ffffff;padding:28px 40px;text-align:center;border-bottom:1px solid #e2e8f0;">
				' . $header_inner . '
			</div>';
		} elseif ( $custom_header_html ) {
			// No logo — custom text in dark bar.
			$header_block = '
			<div style="background:#1e293b;padding:28px 40px;text-align:center;">
				<div style="font-family:' . $font_stack . ';font-size:15px;color:#f8fafc;line-height:1.5;">'
				. $custom_header_html .
				'</div>
			</div>';
		} else {
			// Nothing set — site name in dark bar.
			$header_block = '
			<div style="background:#1e293b;padding:28px 40px;text-align:center;">
				<span style="font-family:' . $font_stack . ';font-size:20px;font-weight:700;color:#f8fafc;letter-spacing:0.04em;">'
				. $site_name .
				'</span>
			</div>';
		}

		// ── Footer ────────────────────────────────────────────────────────────
		// Custom footerHtml replaces only the inner content — the styled container
		// always stays intact so the design never breaks.
		$custom_footer_html = ! empty( $email_settings['footerHtml'] ) ? $email_settings['footerHtml'] : '';
		if ( $custom_footer_html ) {
			$custom_footer_rendered = str_replace(
				array( '{{site_name}}', '{{year}}' ),
				array( $site_name, $year ),
				$custom_footer_html
			);
			$footer_block = '
			<div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
				<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;font-size:13px;color:#64748b;line-height:1.6;">'
				. $custom_footer_rendered .
				'</div>
			</div>';
		} else {
			$footer_block = '
			<div style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
				<p style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;font-size:13px;color:#94a3b8;">&copy; '
				. $year . ' ' . $site_name . '. All rights reserved.</p>
			</div>';
		}

		$template = '<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width,initial-scale=1.0" />
	<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
	<style>
		body,table,td,p,a { -webkit-text-size-adjust:100%;-ms-text-size-adjust:100%; }
		table,td { mso-table-lspace:0;mso-table-rspace:0;border-collapse:collapse; }
		img { border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic; }
		body { margin:0;padding:0;background-color:#f1f5f9; }

		/* Content typography */
		.email-content { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; }
		.email-content p { margin:0 0 16px;font-size:16px;line-height:1.7;color:#374151; }
		.email-content p:last-child { margin-bottom:0; }
		.email-content strong, .email-content b { color:#1e293b; }
		.email-content a { color:#6366f1;text-decoration:none; }
		.email-content a:hover { text-decoration:underline; }
		.email-content ul,
		.email-content ol { margin:0 0 16px;padding-left:24px; }
		.email-content li { font-size:16px;line-height:1.7;color:#374151;margin-bottom:6px; }
		.email-content h1,.email-content h2,.email-content h3 {
			color:#1e293b;line-height:1.3;margin:0 0 12px;
		}

		@media only screen and (max-width:620px) {
			.email-outer-td { padding:16px !important; }
			.email-card { border-radius:12px !important; }
			.email-body-td { padding:28px 24px !important; }
			.email-header-td { padding:24px !important; }
			.email-footer-td { padding:20px 24px !important; }
		}
	</style>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;">

	<!-- Outer wrapper -->
	<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;">
	<tr>
	<td class="email-outer-td" align="center" style="padding:40px 20px;">

		<!-- Email card -->
		<table class="email-card" width="600" cellpadding="0" cellspacing="0"
			style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

			<!-- Header -->
			<tr>
				<td class="email-header-td" style="padding:0;">
					' . $header_block . '
				</td>
			</tr>

			<!-- Body -->
			<tr>
				<td class="email-body-td" style="padding:40px;">
					<div class="email-content">
						' . $content . '
					</div>
				</td>
			</tr>

			<!-- Footer -->
			<tr>
				<td class="email-footer-td" style="padding:0;">
					' . $footer_block . '
				</td>
			</tr>

		</table>
		<!-- /Email card -->

	</td>
	</tr>
	</table>
	<!-- /Outer wrapper -->

	<!-- Gmail anti-clipping: invisible zero-width characters prevent Gmail from
	     showing the "Show trimmed content" (...) button on repeated footers. -->
	<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;" aria-hidden="true">
		&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
	</div>

</body>
</html>';

		return $template;
	}

	/**
	 * Get custom My Account endpoint from settings
	 *
	 * @return string Custom endpoint or default 'subscriptions'.
	 */
	private function get_my_account_endpoint() {
		$settings = get_option( 'recurio_settings', array() );
		return isset( $settings['general']['myAccountEndpoint'] ) && ! empty( $settings['general']['myAccountEndpoint'] )
			? $settings['general']['myAccountEndpoint']
			: 'subscriptions';
	}

	/**
	 * Get subscription manage URL
	 */
	private function get_subscription_manage_url( $subscription_id ) {
		$endpoint = $this->get_my_account_endpoint();
		return wc_get_account_endpoint_url( $endpoint ) . '?view=subscription&id=' . $subscription_id;
	}

	/**
	 * Get subscription reactivate URL
	 */
	private function get_subscription_reactivate_url( $subscription_id ) {
		$endpoint = $this->get_my_account_endpoint();
		return add_query_arg(
			array(
				'reactivate_subscription' => $subscription_id,
				'nonce'                   => wp_create_nonce( 'reactivate_subscription_' . $subscription_id ),
			),
			wc_get_account_endpoint_url( $endpoint )
		);
	}

	/**
	 * Get subscription renew URL
	 */
	private function get_subscription_renew_url( $subscription_id ) {
		return add_query_arg(
			array(
				'renew_subscription' => $subscription_id,
				'nonce'              => wp_create_nonce( 'renew_subscription_' . $subscription_id ),
			),
			wc_get_checkout_url()
		);
	}

	/**
	 * Get update payment URL
	 */
	private function get_update_payment_url( $subscription_id ) {
		$url = add_query_arg(
			array(
				'update_payment' => $subscription_id,
				'nonce'          => wp_create_nonce( 'update_payment_' . $subscription_id ),
			),
			wc_get_account_endpoint_url( 'payment-methods' )
		);

		/**
		 * Filters the payment update URL sent in the payment_failed email.
		 * Recurio Pro overrides this with a secure one-click tokenised URL.
		 *
		 * @param string $url             Default URL (requires customer login).
		 * @param int    $subscription_id Subscription ID.
		 */
		return apply_filters( 'recurio_payment_update_url', $url, $subscription_id );
	}

	/**
	 * Get invoice URL
	 */
	private function get_invoice_url( $transaction_id ) {
		$endpoint = $this->get_my_account_endpoint();
		return add_query_arg(
			array(
				'invoice' => $transaction_id,
				'nonce'   => wp_create_nonce( 'invoice_' . $transaction_id ),
			),
			wc_get_account_endpoint_url( $endpoint )
		);
	}

	/**
	 * Schedule email reminders
	 */
	public function schedule_reminders( $subscription_id, $subscription_data ) {
		$settings = get_option( 'recurio_settings', array() );

		// Schedule trial ending reminder (3 days before) — single built-in ping when subscription is created.
		if ( ! empty( $subscription_data['trial_end_date'] ) ) {
			$trial_reminder_time = strtotime( $subscription_data['trial_end_date'] ) - ( 3 * DAY_IN_SECONDS );
			if ( $trial_reminder_time > time() ) {
				wp_schedule_single_event( $trial_reminder_time, 'recurio_trial_ending', array( $subscription_id, $subscription_data ) );
			}
		}

		// Schedule renewal reminder using dashboard setting (default 3 days before charge).
		if ( ! empty( $subscription_data['next_payment_date'] ) ) {
			$renewal_days = 3;
			if ( isset( $settings['emails']['reminderDays'] ) ) {
				$renewal_days = max( 1, absint( $settings['emails']['reminderDays'] ) );
			}
			$renewal_reminder_time = strtotime( $subscription_data['next_payment_date'] ) - ( $renewal_days * DAY_IN_SECONDS );
			if ( $renewal_reminder_time > time() ) {
				wp_schedule_single_event( $renewal_reminder_time, 'recurio_renewal_reminder', array( $subscription_id, $subscription_data ) );
			}
		}
	}

	/**
	 * Clear scheduled reminders
	 */
	public function clear_scheduled_reminders( $subscription_id ) {
		wp_clear_scheduled_hook( 'recurio_trial_ending', array( $subscription_id ) );
		wp_clear_scheduled_hook( 'recurio_renewal_reminder', array( $subscription_id ) );
	}
}

/**
 * Inner HTML for the subscription email header (custom banner image for Pro, or site title).
 *
 * @return string
 */
function recurio_get_subscription_email_header_inner_html() {
	$settings      = get_option( 'recurio_settings', array() );
	$attachment_id = isset( $settings['emails']['headerImageId'] ) ? absint( $settings['emails']['headerImageId'] ) : 0;
	$pro_ok        = function_exists( 'recurio_is_pro_licensed' ) && recurio_is_pro_licensed();

	if ( $pro_ok && $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
		$url = wp_get_attachment_image_url( $attachment_id, 'medium' );
		if ( $url ) {
			return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="max-width:100%;height:auto;display:block;margin:0 auto;" />';
		}
	}

	return '<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
}
