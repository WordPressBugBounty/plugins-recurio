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
			// Free: Register hooks that Pro will handle
			add_action( 'recurio_payment_failed', array( $this, 'send_payment_failed_email' ), 10, 3 );
			add_action( 'recurio_subscription_paused', array( $this, 'send_subscription_paused_email' ), 10, 2 );
			add_action( 'recurio_subscription_cancelled', array( $this, 'send_subscription_cancelled_email' ), 10, 3 );
			add_action( 'recurio_trial_ending', array( $this, 'send_trial_ending_email' ), 10, 2 );
		}

		// Always register these (no Pro equivalent yet)
		add_action( 'recurio_subscription_created', array( $this, 'send_subscription_created_email' ), 10, 2 );
		add_action( 'recurio_subscription_activated', array( $this, 'send_subscription_activated_email' ), 10, 2 );
		add_action( 'recurio_subscription_resumed', array( $this, 'send_subscription_resumed_email' ), 10, 2 );
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
		$product_name = $product ? $product->get_name() : 'Subscription';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Welcome! Your %s subscription has been created', 'recurio' ), $product_name );
		$message = $this->get_email_template(
			'subscription_created',
			array(
				'customer_name'     => $customer->display_name,
				'product_name'      => $product_name,
				'billing_amount'    => wc_price( $subscription_data['billing_amount'] ),
				'billing_period'    => $subscription_data['billing_period'],
				'next_payment_date' => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['next_payment_date'] ) ),
				'subscription_id'   => $subscription_id,
				'manage_url'        => $this->get_subscription_manage_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );

		// Send admin notification
		$this->send_admin_notification(
			'New Subscription Created',
			sprintf(
				'A new subscription (#%d) has been created for %s (%s) - Product: %s, Amount: %s',
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
		$product_name = $product ? $product->get_name() : 'Subscription';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription is now active', 'recurio' ), $product_name );
		$message = $this->get_email_template(
			'subscription_activated',
			array(
				'customer_name'   => $customer->display_name,
				'product_name'    => $product_name,
				'subscription_id' => $subscription_id,
				'manage_url'      => $this->get_subscription_manage_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );
	}

	/**
	 * Send subscription paused email
	 */
	public function send_subscription_paused_email( $subscription_id, $subscription_data ) {
		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : 'Subscription';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription has been paused', 'recurio' ), $product_name );
		$message = $this->get_email_template(
			'subscription_paused',
			array(
				'customer_name'   => $customer->display_name,
				'product_name'    => $product_name,
				'pause_duration'  => isset( $subscription_data['pause_duration'] ) ? $subscription_data['pause_duration'] : 'indefinitely',
				'subscription_id' => $subscription_id,
				'manage_url'      => $this->get_subscription_manage_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );
	}

	/**
	 * Send subscription resumed email
	 */
	public function send_subscription_resumed_email( $subscription_id, $subscription_data ) {
		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : 'Subscription';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription has been resumed', 'recurio' ), $product_name );
		$message = $this->get_email_template(
			'subscription_resumed',
			array(
				'customer_name'     => $customer->display_name,
				'product_name'      => $product_name,
				'next_payment_date' => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['next_payment_date'] ) ),
				'subscription_id'   => $subscription_id,
				'manage_url'        => $this->get_subscription_manage_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );
	}

	/**
	 * Send subscription cancelled email
	 */
	public function send_subscription_cancelled_email( $subscription_id, $subscription_data, $reason = '' ) {
		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : 'Subscription';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription has been cancelled', 'recurio' ), $product_name );

		$cancellation_date = isset( $subscription_data['cancellation_date'] ) ? $subscription_data['cancellation_date'] : 'immediately';

		$message = $this->get_email_template(
			'subscription_cancelled',
			array(
				'customer_name'     => $customer->display_name,
				'product_name'      => $product_name,
				'cancellation_date' => $cancellation_date === 'immediately' ? 'immediately' : date_i18n( get_option( 'date_format' ), strtotime( $cancellation_date ) ),
				'reason'            => $reason,
				'subscription_id'   => $subscription_id,
				'reactivate_url'    => $this->get_subscription_reactivate_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );

		// Send admin notification
		$this->send_admin_notification(
			'Subscription Cancelled',
			sprintf(
				'Subscription #%d has been cancelled for %s (%s) - Product: %s%s',
				$subscription_id,
				$customer->display_name,
				$customer->user_email,
				$product_name,
				$reason ? ', Reason: ' . $reason : ''
			)
		);
	}

	/**
	 * Send subscription expired email
	 */
	public function send_subscription_expired_email( $subscription_id, $subscription_data ) {
		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : 'Subscription';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your %s subscription has expired', 'recurio' ), $product_name );
		$message = $this->get_email_template(
			'subscription_expired',
			array(
				'customer_name'   => $customer->display_name,
				'product_name'    => $product_name,
				'subscription_id' => $subscription_id,
				'renew_url'       => $this->get_subscription_renew_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );
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
		$product_name = $product ? $product->get_name() : 'Subscription';

		$subject = __( 'Payment received for your subscription', 'recurio' );
		$message = $this->get_email_template(
			'payment_successful',
			array(
				'customer_name'     => $customer->display_name,
				'product_name'      => $product_name,
				'payment_amount'    => wc_price( $payment_data['amount'] ),
				'payment_date'      => date_i18n( get_option( 'date_format' ), strtotime( $payment_data['date'] ) ),
				'next_payment_date' => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['next_payment_date'] ) ),
				'subscription_id'   => $subscription_id,
				'invoice_url'       => $this->get_invoice_url( $payment_data['transaction_id'] ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );
	}

	/**
	 * Send payment failed email
	 */
	public function send_payment_failed_email( $subscription_id, $payment_data, $subscription_data ) {
		// Check if trigger is enabled
		if ( ! $this->is_email_trigger_enabled( 'sendFailedPayment' ) ) {
			return;
		}

		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : 'Subscription';

		$subject = __( 'Payment failed for your subscription', 'recurio' );
		$message = $this->get_email_template(
			'payment_failed',
			array(
				'customer_name'      => $customer->display_name,
				'product_name'       => $product_name,
				'payment_amount'     => wc_price( $payment_data['amount'] ),
				'failure_reason'     => isset( $payment_data['failure_reason'] ) ? $payment_data['failure_reason'] : 'Payment method declined',
				'retry_date'         => date_i18n( get_option( 'date_format' ), strtotime( '+3 days' ) ),
				'subscription_id'    => $subscription_id,
				'update_payment_url' => $this->get_update_payment_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );

		// Send admin notification
		$this->send_admin_notification(
			'Payment Failed',
			sprintf(
				'Payment failed for subscription #%d - Customer: %s (%s), Amount: %s',
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
		$customer = get_user_by( 'id', $subscription_data['customer_id'] );
		if ( ! $customer ) {
			return;
		}

		$product      = wc_get_product( $subscription_data['product_id'] );
		$product_name = $product ? $product->get_name() : 'Subscription';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Your trial for %s is ending soon', 'recurio' ), $product_name );
		$message = $this->get_email_template(
			'trial_ending',
			array(
				'customer_name'   => $customer->display_name,
				'product_name'    => $product_name,
				'trial_end_date'  => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['trial_end_date'] ) ),
				'billing_amount'  => wc_price( $subscription_data['billing_amount'] ),
				'billing_period'  => $subscription_data['billing_period'],
				'subscription_id' => $subscription_id,
				'manage_url'      => $this->get_subscription_manage_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );
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
		$product_name = $product ? $product->get_name() : 'Subscription';

		/* translators: %s: Product name */
		$subject = sprintf( __( 'Renewal reminder for your %s subscription', 'recurio' ), $product_name );
		$message = $this->get_email_template(
			'renewal_reminder',
			array(
				'customer_name'   => $customer->display_name,
				'product_name'    => $product_name,
				'renewal_date'    => date_i18n( get_option( 'date_format' ), strtotime( $subscription_data['next_payment_date'] ) ),
				'billing_amount'  => wc_price( $subscription_data['billing_amount'] ),
				'subscription_id' => $subscription_id,
				'manage_url'      => $this->get_subscription_manage_url( $subscription_id ),
			)
		);

		$this->send_email( $customer->user_email, $subject, $message );
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
		// Sanitize all variables for safe output
		$customer_name = isset( $variables['customer_name'] ) ? esc_html( $variables['customer_name'] ) : 'Customer';
		$product_name  = isset( $variables['product_name'] ) ? esc_html( $variables['product_name'] ) : 'subscription';

		// Sanitize other commonly used variables
		$billing_amount     = isset( $variables['billing_amount'] ) ? wp_kses_post( $variables['billing_amount'] ) : '';
		$billing_period     = isset( $variables['billing_period'] ) ? esc_html( $variables['billing_period'] ) : '';
		$next_payment_date  = isset( $variables['next_payment_date'] ) ? esc_html( $variables['next_payment_date'] ) : '';
		$manage_url         = isset( $variables['manage_url'] ) ? esc_url( $variables['manage_url'] ) : '#';
		$cancellation_date  = isset( $variables['cancellation_date'] ) ? esc_html( $variables['cancellation_date'] ) : '';
		$reason             = isset( $variables['reason'] ) ? esc_html( $variables['reason'] ) : '';
		$reactivate_url     = isset( $variables['reactivate_url'] ) ? esc_url( $variables['reactivate_url'] ) : '#';
		$payment_amount     = isset( $variables['payment_amount'] ) ? wp_kses_post( $variables['payment_amount'] ) : '';
		$failure_reason     = isset( $variables['failure_reason'] ) ? esc_html( $variables['failure_reason'] ) : '';
		$retry_date         = isset( $variables['retry_date'] ) ? esc_html( $variables['retry_date'] ) : '';
		$update_payment_url = isset( $variables['update_payment_url'] ) ? esc_url( $variables['update_payment_url'] ) : '#';

		$templates = array(
			'subscription_created'   => "
                <p>Hi {$customer_name},</p>
                <p>Thank you for subscribing to {$product_name}!</p>
                <p>Your subscription details:</p>
                <ul>
                    <li>Product: {$product_name}</li>
                    <li>Amount: {$billing_amount}</li>
                    <li>Next payment: {$next_payment_date}</li>
                </ul>
                <p><a href='{$manage_url}'>Manage your subscription</a></p>
                <p>Best regards,<br>The Team</p>
            ",
			'subscription_cancelled' => "
                <p>Hi {$customer_name},</p>
                <p>Your subscription to {$product_name} has been cancelled.</p>
                <p>The cancellation will take effect {$cancellation_date}.</p>
                " . ( $reason ? "<p>Reason: {$reason}</p>" : '' ) . "
                <p>We're sorry to see you go. If you change your mind, you can <a href='{$reactivate_url}'>reactivate your subscription</a>.</p>
                <p>Best regards,<br>The Team</p>
            ",
			'payment_failed'         => "
                <p>Hi {$customer_name},</p>
                <p>We were unable to process the payment for your {$product_name} subscription.</p>
                <p>Amount: {$payment_amount}</p>
                <p>Reason: {$failure_reason}</p>
                <p>We'll retry the payment on {$retry_date}.</p>
                <p>Please <a href='{$update_payment_url}'>update your payment method</a> to avoid service interruption.</p>
                <p>Best regards,<br>The Team</p>
            ",
		);

		return isset( $templates[ $template_name ] ) ? $templates[ $template_name ] : "<p>Hi {$customer_name},</p><p>This is a notification about your {$product_name} subscription.</p>";
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
		$template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .email-header { background: #f4f4f4; padding: 20px; text-align: center; }
                .email-content { padding: 20px; background: #fff; }
                .email-footer { background: #f4f4f4; padding: 10px; text-align: center; font-size: 12px; }
                a { color: #0073aa; text-decoration: none; }
                a:hover { text-decoration: underline; }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="email-header">
                    <h1>' . get_bloginfo( 'name' ) . '</h1>
                </div>
                <div class="email-content">
                    ' . $content . '
                </div>
                <div class="email-footer">
                    <p>&copy; ' . gmdate( 'Y' ) . ' ' . get_bloginfo( 'name' ) . '. All rights reserved.</p>
                </div>
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
		return add_query_arg(
			array(
				'update_payment' => $subscription_id,
				'nonce'          => wp_create_nonce( 'update_payment_' . $subscription_id ),
			),
			wc_get_account_endpoint_url( 'payment-methods' )
		);
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
		// Schedule trial ending reminder (3 days before)
		if ( ! empty( $subscription_data['trial_end_date'] ) ) {
			$trial_reminder_time = strtotime( $subscription_data['trial_end_date'] ) - ( 3 * DAY_IN_SECONDS );
			if ( $trial_reminder_time > time() ) {
				wp_schedule_single_event( $trial_reminder_time, 'recurio_trial_ending', array( $subscription_id, $subscription_data ) );
			}
		}

		// Schedule renewal reminder (3 days before)
		if ( ! empty( $subscription_data['next_payment_date'] ) ) {
			$renewal_reminder_time = strtotime( $subscription_data['next_payment_date'] ) - ( 3 * DAY_IN_SECONDS );
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
