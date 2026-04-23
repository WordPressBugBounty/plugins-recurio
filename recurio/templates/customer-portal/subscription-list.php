<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$subscriptions = $subscriptions ?? array();
$status_filter = $status_filter ?? 'all';
?>

<div class="recurio-portal-subscription-list">
	<div class="recurio-portal-header">
		<h2><?php echo esc_html__( 'My Subscriptions', 'recurio' ); ?></h2>
		<?php if ( $status_filter !== 'all' ) : ?>
			<p class="recurio-portal-subtitle">
				<?php
				/* translators: %s: Subscription status filter */
				printf( esc_html__( 'Showing %s subscriptions', 'recurio' ), esc_html( $status_filter ) );
				?>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( empty( $subscriptions ) ) : ?>
		<div class="recurio-portal-empty">
			<p><?php echo esc_html__( 'No subscriptions found.', 'recurio' ); ?></p>
			<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="button recurio-button-primary">
				<?php echo esc_html__( 'Browse Products', 'recurio' ); ?>
			</a>
		</div>
	<?php else : ?>
		<div class="recurio-subscription-cards">
			<?php
			foreach ( $subscriptions as $subscription ) :
				$product      = wc_get_product( $subscription->product_id );
				$product_name = $product ? $product->get_name() : esc_html__( 'Product #', 'recurio' ) . $subscription->product_id;
				?>
				<div class="recurio-subscription-card">
					<div class="recurio-card-header">
						<h3><?php echo esc_html( $product_name ); ?></h3>
						<span class="recurio-status recurio-status-<?php echo esc_attr( $subscription->status ); ?>">
							<?php echo esc_html( ucfirst( str_replace( '_', ' ', $subscription->status ) ) ); ?>
						</span>
					</div>
					
					<div class="recurio-card-body">
						<div class="recurio-card-info">
							<div class="recurio-info-item">
								<span class="recurio-info-label"><?php echo esc_html__( 'Subscription ID:', 'recurio' ); ?></span>
								<span class="recurio-info-value">#<?php echo esc_html( $subscription->id ); ?></span>
							</div>
							
							<div class="recurio-info-item">
								<span class="recurio-info-label"><?php echo esc_html__( 'Price:', 'recurio' ); ?></span>
								<span class="recurio-info-value">
									<?php echo wp_kses_post( wc_price( $subscription->billing_amount ) ); ?> / <?php echo esc_html( $subscription->billing_period ); ?>
								</span>
							</div>
							
							<?php if ( in_array( $subscription->status, array( 'active', 'trial' ) ) ) : ?>
								<div class="recurio-info-item">
									<span class="recurio-info-label"><?php echo esc_html__( 'Next Payment:', 'recurio' ); ?></span>
									<span class="recurio-info-value">
										<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->next_payment_date ) ) ); ?>
									</span>
								</div>
							<?php endif; ?>
							
							<div class="recurio-info-item">
								<span class="recurio-info-label"><?php echo esc_html__( 'Started:', 'recurio' ); ?></span>
								<span class="recurio-info-value">
									<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->created_at ) ) ); ?>
								</span>
							</div>
							
							<?php if ( $subscription->cancellation_date && $subscription->cancellation_date !== '0000-00-00 00:00:00' ) : ?>
								<div class="recurio-info-item">
									<span class="recurio-info-label"><?php echo esc_html__( 'Cancelled:', 'recurio' ); ?></span>
									<span class="recurio-info-value">
										<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->cancellation_date ) ) ); ?>
									</span>
								</div>
							<?php endif; ?>
						</div>
					</div>
					
					<div class="recurio-card-footer">
						<a href="?subscription_id=<?php echo esc_attr( $subscription->id ); ?>" class="button">
							<?php echo esc_html__( 'View Details', 'recurio' ); ?>
						</a>
						
						<?php if ( $subscription->status === 'active' ) : ?>
							<button class="button recurio-pause-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
								<?php echo esc_html__( 'Pause', 'recurio' ); ?>
							</button>
						<?php elseif ( $subscription->status === 'paused' ) : ?>
							<button class="button recurio-resume-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
								<?php echo esc_html__( 'Resume', 'recurio' ); ?>
							</button>
						<?php endif; ?>
						
						<?php if ( ! in_array( $subscription->status, array( 'cancelled', 'expired' ) ) ) : ?>
							<button class="button button-link-delete recurio-cancel-subscription" data-subscription-id="<?php echo esc_attr( $subscription->id ); ?>">
								<?php echo esc_html__( 'Cancel', 'recurio' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>