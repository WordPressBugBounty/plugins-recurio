<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$subscription      = $subscription ?? null;
$payment_history   = $payment_history ?? array();
$available_actions = $available_actions ?? array();
$portal_url        = $portal_url ?? '';

if ( ! $subscription ) {
	echo '<div class="recurio-portal-notice">' . esc_html__( 'Subscription not found.', 'recurio' ) . '</div>';
	return;
}

$product      = wc_get_product( $subscription->product_id );
$product_name = $product ? $product->get_name() : esc_html__( 'Product #', 'recurio' ) . $subscription->product_id;

// Helper function to format address
function recurio_format_address( $address_json ) {
	if ( empty( $address_json ) ) {
		return '';
	}

	// If it's already a formatted string, return it
	if ( ! is_string( $address_json ) || strpos( $address_json, '{' ) !== 0 ) {
		return $address_json;
	}

	// Decode JSON
	$address = json_decode( $address_json, true );
	if ( ! is_array( $address ) ) {
		return $address_json;
	}

	// Build formatted address
	$formatted = array();

	// Name
	$name_parts = array();
	if ( ! empty( $address['first_name'] ) ) {
		$name_parts[] = $address['first_name'];
	}
	if ( ! empty( $address['last_name'] ) ) {
		$name_parts[] = $address['last_name'];
	}
	if ( ! empty( $name_parts ) ) {
		$formatted[] = implode( ' ', $name_parts );
	}

	// Company
	if ( ! empty( $address['company'] ) ) {
		$formatted[] = $address['company'];
	}

	// Address lines
	if ( ! empty( $address['address_1'] ) ) {
		$formatted[] = $address['address_1'];
	}
	if ( ! empty( $address['address_2'] ) ) {
		$formatted[] = $address['address_2'];
	}

	// City, State, Postcode
	$location_parts = array();
	if ( ! empty( $address['city'] ) ) {
		$location_parts[] = $address['city'];
	}
	if ( ! empty( $address['state'] ) ) {
		$location_parts[] = $address['state'];
	}
	if ( ! empty( $address['postcode'] ) ) {
		$location_parts[] = $address['postcode'];
	}
	if ( ! empty( $location_parts ) ) {
		$formatted[] = implode( ', ', $location_parts );
	}

	// Country
	if ( ! empty( $address['country'] ) ) {
		// Get country name from code
		$countries    = WC()->countries->countries;
		$country_name = isset( $countries[ $address['country'] ] ) ? $countries[ $address['country'] ] : $address['country'];
		$formatted[]  = $country_name;
	}

	// Email (for billing)
	if ( ! empty( $address['email'] ) ) {
		$formatted[] = $address['email'];
	}

	// Phone (for billing)
	if ( ! empty( $address['phone'] ) ) {
		$formatted[] = $address['phone'];
	}

	return implode( "\n", $formatted );
}
?>

<div class="recurio-portal-subscription-detail">
	<div class="recurio-portal-header">
		<a href="<?php echo esc_url( $portal_url ?: '#' ); ?>" class="recurio-back-link" <?php echo ! $portal_url ? 'onclick="history.back(); return false;"' : ''; ?>>← <?php echo esc_html__( 'Back to Subscriptions', 'recurio' ); ?></a>
		<h2><?php echo esc_html( $product_name ); ?></h2>
		<span class="recurio-status recurio-status-<?php echo esc_attr( $subscription->status ); ?>">
			<?php echo esc_html( ucfirst( str_replace( '_', ' ', $subscription->status ) ) ); ?>
		</span>
	</div>

	<div class="recurio-detail-grid">
		<div class="recurio-detail-section">
			<h3><?php echo esc_html__( 'Subscription Details', 'recurio' ); ?></h3>
			
			<div class="recurio-detail-info">
				<div class="recurio-detail-row">
					<span class="recurio-detail-label"><?php echo esc_html__( 'Subscription ID:', 'recurio' ); ?></span>
					<span class="recurio-detail-value">#<?php echo esc_html( $subscription->id ); ?></span>
				</div>
				
				<div class="recurio-detail-row">
					<span class="recurio-detail-label"><?php echo esc_html__( 'Status:', 'recurio' ); ?></span>
					<span class="recurio-detail-value">
						<?php echo esc_html( ucfirst( str_replace( '_', ' ', $subscription->status ) ) ); ?>
					</span>
				</div>
				
				<div class="recurio-detail-row">
					<?php
					$payment_type_check = isset( $subscription->payment_type ) ? $subscription->payment_type : 'recurring';
					$max_payments_check = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;

					if ( $payment_type_check === 'split' && $max_payments_check > 0 ) :
						// Split payment - show installment format
						$total_price = $subscription->billing_amount * $max_payments_check;
						?>
						<span class="recurio-detail-label"><?php echo esc_html__( 'Installment:', 'recurio' ); ?></span>
						<span class="recurio-detail-value">
							<?php
							/* translators: %1$s: installment amount, %2$d: number of payments, %3$s: total amount */
							printf(
								esc_html__( '%1$s × %2$d = %3$s total', 'recurio' ),
								wp_kses_post( wc_price( $subscription->billing_amount ) ),
								$max_payments_check,
								wp_kses_post( wc_price( $total_price ) )
							);
							?>
						</span>
					<?php else : ?>
						<span class="recurio-detail-label"><?php echo esc_html__( 'Price:', 'recurio' ); ?></span>
						<span class="recurio-detail-value">
							<?php
							echo wp_kses_post( wc_price( $subscription->billing_amount ) );
							echo ' / ';
							$interval = isset( $subscription->billing_interval ) ? intval( $subscription->billing_interval ) : 1;
							if ( $interval > 1 ) {
								/* translators: %1$d: interval number, %2$s: period (day/week/month/year) */
								printf( esc_html__( 'every %1$d %2$ss', 'recurio' ), $interval, esc_html( $subscription->billing_period ) );
							} else {
								echo esc_html( $subscription->billing_period );
							}
							?>
						</span>
					<?php endif; ?>
				</div>
				
				<div class="recurio-detail-row">
					<span class="recurio-detail-label"><?php echo esc_html__( 'Start Date:', 'recurio' ); ?></span>
					<span class="recurio-detail-value">
						<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->created_at ) ) ); ?>
					</span>
				</div>
				
				<?php if ( in_array( $subscription->status, array( 'active', 'trial', 'pending_payment' ), true ) && ! empty( $subscription->next_payment_date ) ) : ?>
					<div class="recurio-detail-row">
						<span class="recurio-detail-label"><?php echo esc_html__( 'Next Payment:', 'recurio' ); ?></span>
						<span class="recurio-detail-value">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->next_payment_date ) ) ); ?>
						</span>
					</div>
				<?php endif; ?>

				<?php
				// Show split payment progress if applicable
				$payment_type = isset( $subscription->payment_type ) ? $subscription->payment_type : 'recurring';
				$max_payments = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;
				if ( $payment_type === 'split' && $max_payments > 0 ) :
					$payments_made    = isset( $subscription->renewal_count ) ? intval( $subscription->renewal_count ) : 0;
					$progress_percent = min( 100, ( $payments_made / $max_payments ) * 100 );
					?>
					<div class="recurio-detail-row">
						<span class="recurio-detail-label"><?php echo esc_html__( 'Payment Plan:', 'recurio' ); ?></span>
						<span class="recurio-detail-value">
							<span class="recurio-badge recurio-badge-info"><?php echo esc_html__( 'Installments', 'recurio' ); ?></span>
						</span>
					</div>

					<div class="recurio-detail-row">
						<span class="recurio-detail-label"><?php echo esc_html__( 'Payments:', 'recurio' ); ?></span>
						<span class="recurio-detail-value">
							<?php
							/* translators: %1$d: payments made, %2$d: total payments */
							printf( esc_html__( '%1$d of %2$d completed', 'recurio' ), $payments_made, $max_payments );
							?>
						</span>
					</div>

					<div class="recurio-detail-row">
						<span class="recurio-detail-label"><?php echo esc_html__( 'Progress:', 'recurio' ); ?></span>
						<span class="recurio-detail-value recurio-progress-container">
							<div class="recurio-progress-bar">
								<div class="recurio-progress-fill" style="width: <?php echo esc_attr( $progress_percent ); ?>%;"></div>
							</div>
							<span class="recurio-progress-text"><?php echo esc_html( round( $progress_percent ) ); ?>%</span>
						</span>
					</div>

					<?php
					$access_timing = isset( $subscription->access_timing ) ? $subscription->access_timing : 'immediate';
					if ( $access_timing === 'after_full_payment' && $subscription->status === 'pending_payment' ) :
						?>
						<div class="recurio-portal-notice recurio-notice-info" style="margin-top: 15px;">
							<?php echo esc_html__( 'Product access will be granted after all payments are complete.', 'recurio' ); ?>
						</div>
					<?php elseif ( $access_timing === 'custom_duration' && ! empty( $subscription->access_end_date ) ) : ?>
						<div class="recurio-detail-row">
							<span class="recurio-detail-label"><?php echo esc_html__( 'Access Until:', 'recurio' ); ?></span>
							<span class="recurio-detail-value">
								<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->access_end_date ) ) ); ?>
							</span>
						</div>
						<?php
						// Check if access has expired
						if ( strtotime( $subscription->access_end_date ) < time() ) :
							?>
							<div class="recurio-portal-notice recurio-notice-warning" style="margin-top: 15px;">
								<?php echo esc_html__( 'Your access period has expired. Payments may still continue until all installments are complete.', 'recurio' ); ?>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $subscription->cancellation_date && $subscription->cancellation_date !== '0000-00-00 00:00:00' ) : ?>
					<div class="recurio-detail-row">
						<span class="recurio-detail-label"><?php echo esc_html__( 'Cancellation Date:', 'recurio' ); ?></span>
						<span class="recurio-detail-value">
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->cancellation_date ) ) ); ?>
						</span>
					</div>
				<?php endif; ?>
				
				<div class="recurio-detail-row">
					<span class="recurio-detail-label"><?php echo esc_html__( 'Payment Method:', 'recurio' ); ?></span>
					<span class="recurio-detail-value">
						<?php echo esc_html( $subscription->payment_method_display ?: $subscription->payment_method ?: esc_html__( 'Not specified', 'recurio' ) ); ?>
					</span>
				</div>
			</div>
		</div>

		<div class="recurio-detail-section">
			<h3><?php echo esc_html__( 'Actions', 'recurio' ); ?></h3>
			
			<div class="recurio-action-buttons">
				<?php if ( in_array( 'pause', $available_actions ) ) : ?>
					<button class="button recurio-pause-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
						<?php echo esc_html__( 'Pause Subscription', 'recurio' ); ?>
					</button>
				<?php endif; ?>
				
				<?php if ( in_array( 'resume', $available_actions ) ) : ?>
					<button class="button recurio-resume-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
						<?php echo esc_html__( 'Resume Subscription', 'recurio' ); ?>
					</button>
				<?php endif; ?>
				
				<?php if ( in_array( 'cancel', $available_actions ) ) : ?>
					<button class="button button-secondary recurio-cancel-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
						<?php echo esc_html__( 'Cancel Subscription', 'recurio' ); ?>
					</button>
				<?php endif; ?>
				
				<?php if ( in_array( 'update_payment', $available_actions ) ) : ?>
					<button class="button recurio-update-payment" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
						<?php echo esc_html__( 'Update Payment Method', 'recurio' ); ?>
					</button>
				<?php endif; ?>
				
				<?php if ( in_array( 'reactivate', $available_actions ) ) : ?>
					<button class="button recurio-button-primary recurio-reactivate-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
						<?php echo esc_html__( 'Reactivate Subscription', 'recurio' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( in_array( 'early_renewal', $available_actions ) ) : ?>
					<button class="button recurio-button-success recurio-early-renewal" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
						<?php echo esc_html__( 'Renew Now', 'recurio' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( in_array( 'pay_installment', $available_actions ) ) : ?>
					<?php
					$max_payments  = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;
					$payments_made = isset( $subscription->renewal_count ) ? intval( $subscription->renewal_count ) : 0;
					?>
					<button class="button recurio-button-primary recurio-pay-installment" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
						<?php
						/* translators: %1$d: next installment number, %2$d: total installments */
						printf( esc_html__( 'Pay Installment %1$d of %2$d', 'recurio' ), $payments_made + 1, $max_payments );
						?>
					</button>
				<?php endif; ?>

				<?php if ( in_array( 'switch_plan', $available_actions ) ) : ?>
					<button class="button recurio-button-info recurio-switch-plan" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
						<?php echo esc_html__( 'Switch Plan', 'recurio' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php if ( $subscription->billing_address || $subscription->shipping_address ) : ?>
		<div class="recurio-detail-addresses">
			<?php if ( $subscription->billing_address ) : ?>
				<div class="recurio-detail-section">
					<h3><?php echo esc_html__( 'Billing Address', 'recurio' ); ?></h3>
					<div class="recurio-address-content">
						<?php
						$formatted_billing = recurio_format_address( $subscription->billing_address );
						echo wp_kses_post( nl2br( $formatted_billing ) );
						?>
					</div>
					<button class="button button-small recurio-edit-address" data-type="billing">
						<?php echo esc_html__( 'Edit', 'recurio' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<?php if ( $subscription->shipping_address ) : ?>
				<div class="recurio-detail-section">
					<h3><?php echo esc_html__( 'Shipping Address', 'recurio' ); ?></h3>
					<div class="recurio-address-content">
						<?php
						$formatted_shipping = recurio_format_address( $subscription->shipping_address );
						echo wp_kses_post( nl2br( $formatted_shipping ) );
						?>
					</div>
					<button class="button button-small recurio-edit-address" data-type="shipping">
						<?php echo esc_html__( 'Edit', 'recurio' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="recurio-detail-section recurio-payment-history">
		<h3><?php echo esc_html__( 'Payment History', 'recurio' ); ?></h3>
		
		<?php if ( empty( $payment_history ) ) : ?>
			<p><?php echo esc_html__( 'No payment history available.', 'recurio' ); ?></p>
		<?php else : ?>
			<table class="recurio-payment-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Date', 'recurio' ); ?></th>
						<th><?php echo esc_html__( 'Amount', 'recurio' ); ?></th>
						<th><?php echo esc_html__( 'Method', 'recurio' ); ?></th>
						<th><?php echo esc_html__( 'Transaction ID', 'recurio' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $payment_history as $payment ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $payment->created_at ) ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $payment->amount ) ); ?></td>
							<td><?php echo esc_html( $payment->payment_method ?: '—' ); ?></td>
							<td><?php echo esc_html( $payment->transaction_id ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<div id="recurio-cancel-modal" class="recurio-modal is-hidden">
	<div class="recurio-modal-content">
		<button class="recurio-modal-close">&times;</button>
		<h3><?php echo esc_html__( 'Cancel Subscription', 'recurio' ); ?></h3>
		<p><?php echo esc_html__( 'When would you like to cancel this subscription?', 'recurio' ); ?></p>
		
		<div class="recurio-portal-notice recurio-notice-warning">
			<strong><?php echo esc_html__( '⚠️ This action cannot be undone', 'recurio' ); ?></strong><br>
			<?php echo esc_html__( 'Once cancelled, this subscription cannot be reactivated.', 'recurio' ); ?>
		</div>
		
		<div class="recurio-cancel-options">
			<label>
				<input type="radio" name="cancel_at" value="immediately" checked>
				<?php echo esc_html__( 'Cancel immediately', 'recurio' ); ?>
			</label>
			<label>
				<input type="radio" name="cancel_at" value="end_of_period">
				<?php echo esc_html__( 'Cancel at end of current billing period', 'recurio' ); ?>
			</label>
		</div>
		
		<div class="recurio-modal-actions">
			<button class="button recurio-modal-keep recurio-modal-close"><?php echo esc_html__( 'Keep Subscription', 'recurio' ); ?></button>
			<button class="button button-primary recurio-confirm-cancel"><?php echo esc_html__( 'Confirm Cancellation', 'recurio' ); ?></button>
		</div>
	</div>
</div>

<!-- Pause Subscription Modal -->
<div id="recurio-pause-modal" class="recurio-modal is-hidden">
	<div class="recurio-modal-content">
		<button class="recurio-modal-close">&times;</button>
		<h3><?php echo esc_html__( 'Pause Subscription', 'recurio' ); ?></h3>
		<p><?php echo esc_html__( 'Are you sure you want to pause this subscription?', 'recurio' ); ?></p>
		
		<div class="recurio-portal-notice recurio-notice-info">
			<strong><?php echo esc_html__( 'ℹ️ What happens when you pause:', 'recurio' ); ?></strong><br>
			<?php echo esc_html__( '• No charges will be made while paused', 'recurio' ); ?><br>
			<?php echo esc_html__( '• You can resume your subscription anytime', 'recurio' ); ?><br>
			<?php echo esc_html__( '• Your subscription benefits will be temporarily suspended', 'recurio' ); ?>
		</div>
		
		<div class="recurio-modal-actions">
			<button class="button recurio-modal-keep recurio-modal-close"><?php echo esc_html__( 'Keep Active', 'recurio' ); ?></button>
			<button class="button button-primary recurio-confirm-pause"><?php echo esc_html__( 'Pause Subscription', 'recurio' ); ?></button>
		</div>
	</div>
</div>

<!-- Resume Subscription Modal -->
<div id="recurio-resume-modal" class="recurio-modal is-hidden">
	<div class="recurio-modal-content">
		<button class="recurio-modal-close">&times;</button>
		<h3><?php echo esc_html__( 'Resume Subscription', 'recurio' ); ?></h3>
		<p><?php echo esc_html__( 'Are you sure you want to resume this subscription?', 'recurio' ); ?></p>
		
		<div class="recurio-portal-notice recurio-notice-success">
			<strong><?php echo esc_html__( '✅ What happens when you resume:', 'recurio' ); ?></strong><br>
			<?php echo esc_html__( '• Your subscription will be reactivated immediately', 'recurio' ); ?><br>
			<?php echo esc_html__( '• Regular billing will resume on your next payment date', 'recurio' ); ?><br>
			<?php echo esc_html__( '• You will regain access to all subscription benefits', 'recurio' ); ?>
		</div>
		
		<div class="recurio-modal-actions">
			<button class="button recurio-modal-keep recurio-modal-close"><?php echo esc_html__( 'Keep Paused', 'recurio' ); ?></button>
			<button class="button button-primary recurio-confirm-resume"><?php echo esc_html__( 'Resume Subscription', 'recurio' ); ?></button>
		</div>
	</div>
</div>

<div id="recurio-address-modal" class="recurio-modal is-hidden">
	<div class="recurio-modal-content">
		<h3><?php echo esc_html__( 'Edit Address', 'recurio' ); ?></h3>

		<form id="recurio-address-form">
			<textarea name="address" rows="5" class="recurio-address-input"></textarea>
			<input type="hidden" name="address_type" value="">
			<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->id ); ?>">
		</form>

		<div class="recurio-modal-actions">
			<button class="button recurio-modal-keep recurio-modal-close"><?php echo esc_html__( 'Cancel', 'recurio' ); ?></button>
			<button class="button button-primary recurio-save-address"><?php echo esc_html__( 'Save Address', 'recurio' ); ?></button>
		</div>
	</div>
</div>

<!-- Early Renewal Modal -->
<div id="recurio-early-renewal-modal" class="recurio-modal is-hidden">
	<div class="recurio-modal-content">
		<button class="recurio-modal-close">&times;</button>
		<h3><?php echo esc_html__( 'Renew Subscription Early', 'recurio' ); ?></h3>
		<p><?php echo esc_html__( 'Would you like to renew this subscription now?', 'recurio' ); ?></p>

		<div class="recurio-portal-notice recurio-notice-info">
			<strong><?php echo esc_html__( 'ℹ️ What happens when you renew early:', 'recurio' ); ?></strong><br>
			<?php echo esc_html__( '• A renewal order will be created for payment', 'recurio' ); ?><br>
			<?php echo esc_html__( '• Your next payment date will be extended by one billing period', 'recurio' ); ?><br>
			<?php echo esc_html__( '• The extension is calculated from your current next payment date', 'recurio' ); ?>
		</div>

		<div class="recurio-early-renewal-details">
			<div class="recurio-detail-row">
				<span class="recurio-detail-label"><?php echo esc_html__( 'Current Next Payment:', 'recurio' ); ?></span>
				<span class="recurio-detail-value">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->next_payment_date ) ) ); ?>
				</span>
			</div>
			<div class="recurio-detail-row">
				<span class="recurio-detail-label"><?php echo esc_html__( 'Renewal Amount:', 'recurio' ); ?></span>
				<span class="recurio-detail-value">
					<?php echo wp_kses_post( wc_price( $subscription->billing_amount ) ); ?>
				</span>
			</div>
		</div>

		<div class="recurio-modal-actions">
			<button class="button recurio-modal-keep recurio-modal-close"><?php echo esc_html__( 'Not Now', 'recurio' ); ?></button>
			<button class="button button-primary recurio-confirm-early-renewal"><?php echo esc_html__( 'Renew Now', 'recurio' ); ?></button>
		</div>
	</div>
</div>

<!-- Switch Plan Modal -->
<div id="recurio-switch-plan-modal" class="recurio-modal is-hidden">
	<div class="recurio-modal-content recurio-modal-large">
		<button class="recurio-modal-close">&times;</button>
		<h3><?php echo esc_html__( 'Switch Your Plan', 'recurio' ); ?></h3>
		<p><?php echo esc_html__( 'Choose a new plan for your subscription. Your unused balance will be credited toward the new plan.', 'recurio' ); ?></p>

		<div class="recurio-switch-current-plan">
			<h4><?php echo esc_html__( 'Current Plan', 'recurio' ); ?></h4>
			<div class="recurio-current-plan-info">
				<span class="recurio-plan-name"><?php echo esc_html( $product_name ); ?></span>
				<span class="recurio-plan-price"><?php echo wp_kses_post( wc_price( $subscription->billing_amount ) ); ?> / <?php echo esc_html( $subscription->billing_period ); ?></span>
			</div>
		</div>

		<div class="recurio-switch-plans-loading">
			<span class="spinner is-active"></span>
			<?php echo esc_html__( 'Loading available plans...', 'recurio' ); ?>
		</div>

		<div class="recurio-switch-plans-list is-hidden">
			<h4><?php echo esc_html__( 'Available Plans', 'recurio' ); ?></h4>
			<div class="recurio-plans-container">
				<!-- Plans will be loaded via AJAX -->
			</div>
		</div>

		<div class="recurio-switch-proration is-hidden">
			<h4><?php echo esc_html__( 'Price Breakdown', 'recurio' ); ?></h4>
			<div class="recurio-proration-details">
				<div class="recurio-detail-row">
					<span class="recurio-detail-label"><?php echo esc_html__( 'New Plan Price:', 'recurio' ); ?></span>
					<span class="recurio-detail-value recurio-new-price">-</span>
				</div>
				<div class="recurio-detail-row">
					<span class="recurio-detail-label"><?php echo esc_html__( 'Unused Credit:', 'recurio' ); ?></span>
					<span class="recurio-detail-value recurio-credit">-</span>
				</div>
				<div class="recurio-detail-row recurio-total-row">
					<span class="recurio-detail-label"><strong><?php echo esc_html__( 'Amount Due Today:', 'recurio' ); ?></strong></span>
					<span class="recurio-detail-value recurio-amount-due"><strong>-</strong></span>
				</div>
			</div>
		</div>

		<div class="recurio-modal-actions">
			<button class="button recurio-modal-keep recurio-modal-close"><?php echo esc_html__( 'Cancel', 'recurio' ); ?></button>
			<button class="button button-primary recurio-confirm-switch" disabled><?php echo esc_html__( 'Switch Plan', 'recurio' ); ?></button>
		</div>
	</div>
</div>
