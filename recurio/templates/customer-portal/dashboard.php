<?php
/**
 * Customer portal dashboard template.
 *
 * @package Recurio
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$customer_id    = $customer_id ?? 0;
$show_cancelled = $show_cancelled ?? false;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal dashboard
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
$total_subscriptions = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE customer_id = %d",
		$customer_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal dashboard
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
$active_subscriptions = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE customer_id = %d AND status = 'active'",
		$customer_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal dashboard
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer data
$paused_subscriptions = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}recurio_subscriptions WHERE customer_id = %d AND status = 'paused'",
		$customer_id
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal dashboard
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time customer spending data
$total_spent = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT SUM(r.amount) FROM {$wpdb->prefix}recurio_subscription_revenue r
     JOIN {$wpdb->prefix}recurio_subscriptions s ON r.subscription_id = s.id
     WHERE s.customer_id = %d",
		$customer_id
	)
) ? : 0;

// Build the SQL query properly without interpolation.
if ( $show_cancelled ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal dashboard
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
	$subscriptions = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.*, p.post_title as product_name
         FROM {$wpdb->prefix}recurio_subscriptions s
         LEFT JOIN {$wpdb->prefix}posts p ON s.product_id = p.ID
         WHERE s.customer_id = %d
         ORDER BY s.created_at DESC",
			$customer_id
		)
	);
} else {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for customer portal dashboard
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time subscription data
	$subscriptions = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.*, p.post_title as product_name
         FROM {$wpdb->prefix}recurio_subscriptions s
         LEFT JOIN {$wpdb->prefix}posts p ON s.product_id = p.ID
         WHERE s.customer_id = %d AND s.status NOT IN ('cancelled', 'expired')
         ORDER BY s.created_at DESC",
			$customer_id
		)
	);
}

$user = wp_get_current_user();
?>

<div class="recurio-portal-dashboard">
	<div class="recurio-portal-header">
		<h2>
		<?php
		/* translators: %s: User display name */
		echo esc_html( sprintf( __( 'Welcome back, %s!', 'recurio' ), $user->display_name ) );
		?>
		</h2>
		<p class="recurio-portal-subtitle"><?php echo esc_html__( 'Manage your subscriptions and billing information', 'recurio' ); ?></p>
	</div>

	<div class="recurio-portal-stats">
		<div class="recurio-stat-card">
			<div class="recurio-stat-value"><?php echo esc_html( $active_subscriptions ); ?></div>
			<div class="recurio-stat-label"><?php echo esc_html__( 'Active Subscriptions', 'recurio' ); ?></div>
		</div>
		<div class="recurio-stat-card">
			<div class="recurio-stat-value"><?php echo esc_html( $paused_subscriptions ); ?></div>
			<div class="recurio-stat-label"><?php echo esc_html__( 'Paused Subscriptions', 'recurio' ); ?></div>
		</div>
		<div class="recurio-stat-card">
			<div class="recurio-stat-value"><?php echo wp_kses_post( wc_price( $total_spent ) ); ?></div>
			<div class="recurio-stat-label"><?php echo esc_html__( 'Total Spent', 'recurio' ); ?></div>
		</div>
		<div class="recurio-stat-card">
			<div class="recurio-stat-value"><?php echo esc_html( $total_subscriptions ); ?></div>
			<div class="recurio-stat-label"><?php echo esc_html__( 'Total Subscriptions', 'recurio' ); ?></div>
		</div>
	</div>

	<div class="recurio-portal-subscriptions">
		<h3><?php echo esc_html__( 'Your Subscriptions', 'recurio' ); ?></h3>
		
		<?php if ( empty( $subscriptions ) ) : ?>
			<div class="recurio-portal-empty">
				<p><?php echo esc_html__( 'You don\'t have any subscriptions yet.', 'recurio' ); ?></p>
				<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="button recurio-button-primary">
					<?php echo esc_html__( 'Browse Products', 'recurio' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div class="recurio-subscriptions-table">
				<table>
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Product', 'recurio' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'recurio' ); ?></th>
							<th><?php echo esc_html__( 'Price', 'recurio' ); ?></th>
							<th><?php echo esc_html__( 'Next Payment', 'recurio' ); ?></th>
							<th><?php echo esc_html__( 'Actions', 'recurio' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $subscriptions as $subscription ) : ?>
							<tr>
								<td>
									<div class="recurio-product-info">
<strong><?php echo esc_html( $subscription->product_name ? : ( __( 'Product #', 'recurio' ) . $subscription->product_id ) ); ?></strong>
										<span class="recurio-subscription-id">#<?php echo esc_html( $subscription->id ); ?></span>
									</div>
								</td>
								<td>
									<span class="recurio-status recurio-status-<?php echo esc_attr( $subscription->status ); ?>">
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', $subscription->status ) ) ); ?>
									</span>
								</td>
								<td>
									<?php
									$payment_type = isset( $subscription->payment_type ) ? $subscription->payment_type : 'recurring';
									$max_payments = isset( $subscription->max_payments ) ? intval( $subscription->max_payments ) : 0;

									if ( $payment_type === 'split' && $max_payments > 0 ) :
										// Split payment - show installment info with progress
										$payments_made    = isset( $subscription->renewal_count ) ? intval( $subscription->renewal_count ) : 0;
										$progress_percent = min( 100, ( $payments_made / $max_payments ) * 100 );
										?>
										<div class="recurio-progress-container">
											<span class="recurio-badge recurio-badge-info"><?php echo esc_html__( 'Split', 'recurio' ); ?></span>
											<?php echo wp_kses_post( wc_price( $subscription->billing_amount ) ); ?>
											<span class="recurio-billing-interval">
												× <?php echo esc_html( $max_payments ); ?>
											</span>
										</div>
										<div class="recurio-progress-container" style="margin-top: 5px;">
											<div class="recurio-progress-bar">
												<div class="recurio-progress-fill" style="width: <?php echo esc_attr( $progress_percent ); ?>%;"></div>
											</div>
											<span class="recurio-progress-text"><?php echo esc_html( $payments_made . '/' . $max_payments ); ?></span>
										</div>
									<?php else : ?>
										<?php echo wp_kses_post( wc_price( $subscription->billing_amount ) ); ?>
										<span class="recurio-billing-interval">
											/ <?php
											$interval = isset( $subscription->billing_interval ) ? intval( $subscription->billing_interval ) : 1;
											if ( $interval > 1 ) {
												/* translators: %1$d: interval number, %2$s: period */
												printf( esc_html__( 'every %1$d %2$ss', 'recurio' ), $interval, esc_html( $subscription->billing_period ) );
											} else {
												echo esc_html( $subscription->billing_period );
											}
											?>
										</span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( in_array( $subscription->status, array( 'active', 'trial' ), true ) ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->next_payment_date ) ) );
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<div class="recurio-actions">
										<a href="?view=subscription&id=<?php echo esc_attr( $subscription->id ); ?>" class="button button-small">
											<?php echo esc_html__( 'View', 'recurio' ); ?>
										</a>
										
							<?php if ( 'active' === $subscription->status ) : ?>
											<button class="button button-small recurio-pause-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
												<?php echo esc_html__( 'Pause', 'recurio' ); ?>
											</button>
							<?php elseif ( 'paused' === $subscription->status ) : ?>
											<button class="button button-small recurio-resume-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
												<?php echo esc_html__( 'Resume', 'recurio' ); ?>
											</button>
										<?php endif; ?>
										
							<?php if ( ! in_array( $subscription->status, array( 'cancelled', 'expired' ), true ) ) : ?>
											<button class="button button-small recurio-cancel-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
												<?php echo esc_html__( 'Cancel', 'recurio' ); ?>
											</button>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<!-- Cancel Subscription Modal -->
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