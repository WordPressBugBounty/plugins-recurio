<?php
/**
 * WooCommerce Product Integration
 * Adds subscription options to WooCommerce product edit page
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recurio_WooCommerce_Product {

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
		// Enqueue admin scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Add subscription tab to product data
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_subscription_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'add_subscription_panel' ) );

		// Save subscription data
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_subscription_data' ) );

		// Add subscription badge on product list
		add_filter( 'manage_product_posts_columns', array( $this, 'add_subscription_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'display_subscription_column' ), 10, 2 );

		// Add bulk edit option
		add_action( 'woocommerce_product_bulk_edit_start', array( $this, 'add_bulk_edit_fields' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'save_bulk_edit_fields' ) );
	}

	/**
	 * Enqueue admin scripts and styles for product pages
	 */
	public function enqueue_admin_scripts( $hook ) {
		global $post;

		// Only load on product edit pages
		if ( ( $hook === 'post.php' || $hook === 'post-new.php' ) && $post && $post->post_type === 'product' ) {
			wp_enqueue_style(
				'recurio-admin-product',
				RECURIO_PLUGIN_URL . 'assets/css/admin-product.css',
				array(),
				RECURIO_VERSION
			);

			wp_enqueue_script(
				'recurio-admin-product',
				RECURIO_PLUGIN_URL . 'assets/js/admin-product.js',
				array( 'jquery' ),
				RECURIO_VERSION,
				true
			);

			// Localize script with Pro license data
			wp_localize_script(
				'recurio-admin-product',
				'recurioProductData',
				array(
					'isProLicensed' => $this->is_pro_licensed(),
					'proUpgradeUrl' => $this->get_pro_upgrade_url(),
					'enabledPeriods' => $this->get_enabled_periods(),
				)
			);
		}
	}

	/**
	 * Add subscription tab to product data tabs
	 */
	public function add_subscription_tab( $tabs ) {
		$tabs['recurio_subscription'] = array(
			'label'    => __( 'Subscription', 'recurio' ),
			'target'   => 'recurio_subscription_data',
			'class'    => array( 'show_if_simple', 'show_if_variable', 'recurio_subscription_tab' ),
			'priority' => 75,
		);

		return $tabs;
	}

	/**
	 * Add subscription panel content
	 */
	public function add_subscription_panel() {
		global $post;

		// Get existing values
		$enabled        = get_post_meta( $post->ID, '_recurio_subscription_enabled', true ) === 'yes';
		$billing_period = get_post_meta( $post->ID, '_recurio_subscription_billing_period', true );

		// Get enabled periods from settings
		$settings        = get_option( 'recurio_settings', array() );
		$enabled_periods = isset( $settings['billing']['periods'] ) ? $settings['billing']['periods'] : array( 'monthly', 'yearly' );

		// If saved period is not in enabled periods, default to monthly (or first enabled period if monthly not available)
		if ( ! $billing_period || ! in_array( $billing_period, $enabled_periods ) ) {
			$billing_period = in_array( 'monthly', $enabled_periods ) ? 'monthly' : ( ! empty( $enabled_periods ) ? reset( $enabled_periods ) : 'monthly' );
		}
		$trial_days       = get_post_meta( $post->ID, '_recurio_subscription_trial_days', true );
		$signup_fee       = get_post_meta( $post->ID, '_recurio_subscription_signup_fee', true );
		$length           = get_post_meta( $post->ID, '_recurio_subscription_length', true );
		$limit            = get_post_meta( $post->ID, '_recurio_subscription_limit', true );
		$renewal_reminder = get_post_meta( $post->ID, '_recurio_subscription_renewal_reminder', true ) === 'yes';
		$auto_renewal     = get_post_meta( $post->ID, '_recurio_subscription_auto_renewal', true );
		$auto_renewal     = ( $auto_renewal === '' || $auto_renewal === 'yes' ) ? true : false;

		// Subscribe & Save options
		$allow_one_time    = get_post_meta( $post->ID, '_recurio_allow_one_time_purchase', true ) === 'yes';
		$discount_type     = get_post_meta( $post->ID, '_recurio_subscription_discount_type', true ) ?: 'percentage';
		$discount_value    = get_post_meta( $post->ID, '_recurio_subscription_discount_value', true );
		$show_savings      = get_post_meta( $post->ID, '_recurio_show_savings', true ) !== 'no'; // Default to yes

		// Custom billing interval
		$billing_interval = get_post_meta( $post->ID, '_recurio_subscription_billing_interval', true ) ?: 1;
		$billing_unit     = get_post_meta( $post->ID, '_recurio_subscription_billing_unit', true ) ?: 'month';
		$use_custom_period = get_post_meta( $post->ID, '_recurio_use_custom_period', true ) === 'yes';
		?>
		<div id="recurio_subscription_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field _recurio_subscription_enabled_field">
					<label for="_recurio_subscription_enabled"><?php echo esc_html__( 'Enable Subscription', 'recurio' ); ?></label>
					<input type="checkbox" class="checkbox" name="_recurio_subscription_enabled" id="_recurio_subscription_enabled" value="yes" <?php checked( $enabled ); ?> />
					<?php echo wp_kses_post( wc_help_tip( __( 'Allow this product to be purchased as a subscription', 'recurio' ) ) ); ?>
				</p>
			</div>

			<!-- Subscribe & Save Section (Pro Feature) -->
			<div class="options_group subscription_options <?php echo $enabled ? '' : 'recurio-hidden'; ?>">
				<h4 class="recurio-section-title">
					<?php echo esc_html__( 'Subscribe & Save', 'recurio' ); ?>
					<?php if ( ! $this->is_pro_licensed() ) : ?>
						<span class="recurio-pro-badge">PRO</span>
					<?php endif; ?>
				</h4>

				<?php if ( $this->is_pro_licensed() ) : ?>
					<p class="form-field _recurio_allow_one_time_purchase_field">
						<label for="_recurio_allow_one_time_purchase"><?php echo esc_html__( 'Allow One-Time Purchase', 'recurio' ); ?></label>
						<input type="checkbox" class="checkbox" name="_recurio_allow_one_time_purchase" id="_recurio_allow_one_time_purchase" value="yes" <?php checked( $allow_one_time ); ?> />
						<?php echo wp_kses_post( wc_help_tip( __( 'Let customers choose between one-time purchase or subscription (Subscribe & Save)', 'recurio' ) ) ); ?>
					</p>

					<div class="recurio-subscribe-save-options <?php echo $allow_one_time ? '' : 'recurio-hidden'; ?>">
						<p class="form-field _recurio_subscription_discount_type_field">
							<label for="_recurio_subscription_discount_type"><?php echo esc_html__( 'Subscription Discount', 'recurio' ); ?></label>
							<select name="_recurio_subscription_discount_type" id="_recurio_subscription_discount_type" class="select short">
								<option value="percentage" <?php selected( $discount_type, 'percentage' ); ?>><?php echo esc_html__( 'Percentage (%)', 'recurio' ); ?></option>
								<option value="fixed" <?php selected( $discount_type, 'fixed' ); ?>><?php echo esc_html__( 'Fixed Amount', 'recurio' ); ?></option>
							</select>
							<?php echo wp_kses_post( wc_help_tip( __( 'Type of discount to apply when customer chooses subscription', 'recurio' ) ) ); ?>
						</p>

						<p class="form-field _recurio_subscription_discount_value_field">
							<label for="_recurio_subscription_discount_value"><?php echo esc_html__( 'Discount Value', 'recurio' ); ?></label>
							<input type="number" class="short" name="_recurio_subscription_discount_value" id="_recurio_subscription_discount_value" value="<?php echo esc_attr( $discount_value ); ?>" placeholder="0" min="0" step="0.01" />
							<?php echo wp_kses_post( wc_help_tip( __( 'Discount amount (percentage or fixed) for subscription purchases', 'recurio' ) ) ); ?>
						</p>

						<p class="form-field _recurio_show_savings_field">
							<label for="_recurio_show_savings"><?php echo esc_html__( 'Show Savings', 'recurio' ); ?></label>
							<input type="checkbox" class="checkbox" name="_recurio_show_savings" id="_recurio_show_savings" value="yes" <?php checked( $show_savings ); ?> />
							<?php echo wp_kses_post( wc_help_tip( __( 'Display savings amount to customers on product page', 'recurio' ) ) ); ?>
						</p>
					</div>
				<?php else : ?>
					<p class="recurio-pro-upsell">
						<?php echo esc_html__( 'Subscribe & Save lets customers choose between one-time purchase or subscription with automatic discounts.', 'recurio' ); ?>
						<a href="<?php echo esc_url( $this->get_pro_upgrade_url() ); ?>" class="button button-primary button-small"><?php echo esc_html__( 'Upgrade to Pro', 'recurio' ); ?></a>
					</p>
				<?php endif; ?>
			</div>

			<!-- Billing Period Section -->
			<div class="options_group subscription_options <?php echo $enabled ? '' : 'recurio-hidden'; ?>">
				<h4 class="recurio-section-title"><?php echo esc_html__( 'Billing Schedule', 'recurio' ); ?></h4>

				<?php if ( $this->is_pro_licensed() ) : ?>
					<p class="form-field _recurio_use_custom_period_field">
						<label for="_recurio_use_custom_period"><?php echo esc_html__( 'Custom Billing Period', 'recurio' ); ?></label>
						<input type="checkbox" class="checkbox" name="_recurio_use_custom_period" id="_recurio_use_custom_period" value="yes" <?php checked( $use_custom_period ); ?> />
						<?php echo wp_kses_post( wc_help_tip( __( 'Enable custom billing intervals like "every 2 weeks" or "every 3 months"', 'recurio' ) ) ); ?>
					</p>
				<?php else : ?>
					<p class="form-field _recurio_use_custom_period_field">
						<label>
							<?php echo esc_html__( 'Custom Billing Period', 'recurio' ); ?>
							<span class="recurio-pro-badge">PRO</span>
						</label>
						<input type="checkbox" class="checkbox" disabled />
						<span class="description"><?php echo esc_html__( 'Set custom intervals like "every 2 weeks" or "every 3 months"', 'recurio' ); ?></span>
					</p>
				<?php endif; ?>

				<!-- Standard Period Selection (always show when custom period is not enabled) -->
				<div class="recurio-standard-period <?php echo ( $this->is_pro_licensed() && $use_custom_period ) ? 'recurio-hidden' : ''; ?>">
					<p class="form-field _recurio_subscription_billing_period_field">
						<label><?php echo esc_html__( 'Billing Period', 'recurio' ); ?></label>
						<span class="subscription-periods">
							<?php
							// Get enabled billing periods from global settings
							$enabled_periods = $this->get_enabled_periods();

							// Check if Pro is licensed
							$is_pro_licensed = $this->is_pro_licensed();

							// Pro-only periods
							$pro_only_periods = array( 'daily', 'weekly', 'quarterly' );

							$all_periods = array(
								'daily'     => __( 'Daily', 'recurio' ),
								'weekly'    => __( 'Weekly', 'recurio' ),
								'monthly'   => __( 'Monthly', 'recurio' ),
								'quarterly' => __( 'Quarterly', 'recurio' ),
								'yearly'    => __( 'Yearly', 'recurio' ),
							);

							// Show ALL periods with Pro badges for disabled ones
							foreach ( $all_periods as $key => $label ) {
								$is_pro_only = in_array( $key, $pro_only_periods, true );
								$is_enabled  = in_array( $key, $enabled_periods, true );
								$is_disabled = $is_pro_only && ! $is_pro_licensed;

								$label_class = $is_disabled ? 'recurio-period-disabled' : '';
								$data_pro    = $is_pro_only ? 'yes' : 'no';

								echo '<label class="' . esc_attr( $label_class ) . '">';
								echo '<input type="radio" name="_recurio_subscription_billing_period" value="' . esc_attr( $key ) . '" ' . checked( $billing_period, $key, false ) . ' ' . disabled( $is_disabled, true, false ) . ' data-pro-only="' . esc_attr( $data_pro ) . '" />';
								echo esc_html( $label );
								if ( $is_disabled ) {
									echo ' <span class="recurio-pro-badge">PRO</span>';
								}
								echo '</label>';
							}
							?>
						</span>
						<?php echo wp_kses_post( wc_help_tip( __( 'Select the billing period for this subscription product. Pro periods (Daily, Weekly, Quarterly) require Recurio Pro.', 'recurio' ) ) ); ?>
					</p>
				</div>

				<!-- Custom Period Selection (Pro Only) -->
				<?php if ( $this->is_pro_licensed() ) : ?>
					<div class="recurio-custom-period <?php echo $use_custom_period ? '' : 'recurio-hidden'; ?>">
						<p class="form-field _recurio_subscription_billing_interval_field">
							<label><?php echo esc_html__( 'Bill Every', 'recurio' ); ?></label>
							<span class="recurio-custom-period-wrapper">
								<input type="number" class="short" name="_recurio_subscription_billing_interval" id="_recurio_subscription_billing_interval" value="<?php echo esc_attr( $billing_interval ); ?>" min="1" max="365" step="1" style="width: 60px;" />
								<select name="_recurio_subscription_billing_unit" id="_recurio_subscription_billing_unit" class="select short">
									<option value="day" <?php selected( $billing_unit, 'day' ); ?>><?php echo esc_html__( 'Day(s)', 'recurio' ); ?></option>
									<option value="week" <?php selected( $billing_unit, 'week' ); ?>><?php echo esc_html__( 'Week(s)', 'recurio' ); ?></option>
									<option value="month" <?php selected( $billing_unit, 'month' ); ?>><?php echo esc_html__( 'Month(s)', 'recurio' ); ?></option>
									<option value="year" <?php selected( $billing_unit, 'year' ); ?>><?php echo esc_html__( 'Year(s)', 'recurio' ); ?></option>
								</select>
							</span>
							<?php echo wp_kses_post( wc_help_tip( __( 'Set a custom billing interval, e.g., "every 2 weeks" or "every 3 months"', 'recurio' ) ) ); ?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( ! $is_pro_licensed ) : ?>
				<div class="recurio-product-pro-notice">
					<p>
						<strong>💎 Unlock Daily, Weekly & Quarterly Billing Periods</strong>
					</p>
					<p>
						Upgrade to <strong>Recurio Pro</strong> to offer flexible billing options like daily subscriptions for premium content, weekly meal kits, or quarterly memberships.
					</p>
					<a href="<?php echo esc_url( $this->get_pro_upgrade_url() ); ?>" target="_blank">
						Upgrade to Pro
					</a>
				</div>
				<?php endif; ?>

				<p class="form-field _recurio_subscription_trial_days_field">
					<label for="_recurio_subscription_trial_days"><?php echo esc_html__( 'Trial Period (days)', 'recurio' ); ?></label>
					<input type="number" class="short" name="_recurio_subscription_trial_days" id="_recurio_subscription_trial_days" value="<?php echo esc_attr( $trial_days ); ?>" placeholder="0" min="0" step="1" />
					<?php echo wp_kses_post( wc_help_tip( __( 'Number of days for free trial (0 for no trial)', 'recurio' ) ) ); ?>
				</p>
				
				<p class="form-field _recurio_subscription_signup_fee_field">
					<label for="_recurio_subscription_signup_fee"><?php echo esc_html__( 'Sign-up Fee', 'recurio' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</label>
					<input type="text" class="wc_input_price short" name="_recurio_subscription_signup_fee" id="_recurio_subscription_signup_fee" value="<?php echo esc_attr( $signup_fee ); ?>" placeholder="0" />
					<?php echo wp_kses_post( wc_help_tip( __( 'One-time fee charged when subscription starts', 'recurio' ) ) ); ?>
				</p>
				
				<p class="form-field _recurio_subscription_length_field">
					<label for="_recurio_subscription_length"><?php echo esc_html__( 'Subscription Length', 'recurio' ); ?></label>
					<input type="number" class="short" name="_recurio_subscription_length" id="_recurio_subscription_length" value="<?php echo esc_attr( $length ); ?>" placeholder="0" min="0" step="1" />
					<?php echo wp_kses_post( wc_help_tip( __( 'Number of billing periods (0 for unlimited)', 'recurio' ) ) ); ?>
				</p>
				
				<p class="form-field _recurio_subscription_limit_field">
					<label for="_recurio_subscription_limit"><?php echo esc_html__( 'Limit Subscriptions', 'recurio' ); ?></label>
					<select name="_recurio_subscription_limit" id="_recurio_subscription_limit" class="select short">
						<option value="0" <?php selected( $limit, '0' ); ?>><?php echo esc_html__( 'No limit', 'recurio' ); ?></option>
						<option value="1" <?php selected( $limit, '1' ); ?>><?php echo esc_html__( '1 subscription', 'recurio' ); ?></option>
						<option value="2" <?php selected( $limit, '2' ); ?>><?php echo esc_html__( '2 subscriptions', 'recurio' ); ?></option>
						<option value="3" <?php selected( $limit, '3' ); ?>><?php echo esc_html__( '3 subscriptions', 'recurio' ); ?></option>
						<option value="5" <?php selected( $limit, '5' ); ?>><?php echo esc_html__( '5 subscriptions', 'recurio' ); ?></option>
					</select>
					<?php echo wp_kses_post( wc_help_tip( __( 'Limit active subscriptions per customer', 'recurio' ) ) ); ?>
				</p>
				
				<p class="form-field _recurio_subscription_renewal_reminder_field">
					<label for="_recurio_subscription_renewal_reminder"><?php echo esc_html__( 'Send Renewal Reminder', 'recurio' ); ?></label>
					<input type="checkbox" class="checkbox" name="_recurio_subscription_renewal_reminder" id="_recurio_subscription_renewal_reminder" value="yes" <?php checked( $renewal_reminder ); ?> />
					<?php echo wp_kses_post( wc_help_tip( __( 'Send email reminder before subscription renewal', 'recurio' ) ) ); ?>
				</p>
				
				<p class="form-field _recurio_subscription_auto_renewal_field">
					<label for="_recurio_subscription_auto_renewal"><?php echo esc_html__( 'Auto-renewal', 'recurio' ); ?></label>
					<input type="checkbox" class="checkbox" name="_recurio_subscription_auto_renewal" id="_recurio_subscription_auto_renewal" value="yes" <?php checked( $auto_renewal ); ?> />
					<?php echo wp_kses_post( wc_help_tip( __( 'Automatically renew subscription at the end of billing period', 'recurio' ) ) ); ?>
				</p>
			</div>

			<!-- Split Payments / Installments Section -->
			<?php
			$payment_type          = get_post_meta( $post->ID, '_recurio_payment_type', true ) ?: 'recurring';
			$max_payments          = get_post_meta( $post->ID, '_recurio_max_payments', true ) ?: 0;
			$access_timing         = get_post_meta( $post->ID, '_recurio_access_timing', true ) ?: 'immediate';
			$access_duration_value = get_post_meta( $post->ID, '_recurio_access_duration_value', true ) ?: 1;
			$access_duration_unit  = get_post_meta( $post->ID, '_recurio_access_duration_unit', true ) ?: 'month';
			?>
			<div class="options_group subscription_options <?php echo $enabled ? '' : 'recurio-hidden'; ?>">
				<h4 class="recurio-section-title"><?php echo esc_html__( 'Payment Type', 'recurio' ); ?></h4>

				<p class="form-field _recurio_payment_type_field">
					<label><?php echo esc_html__( 'Payment Type', 'recurio' ); ?></label>
					<span class="recurio-payment-type-options">
						<label>
							<input type="radio" name="_recurio_payment_type" value="recurring" <?php checked( $payment_type, 'recurring' ); ?> />
							<?php echo esc_html__( 'Recurring (ongoing)', 'recurio' ); ?>
						</label>
						<label>
							<input type="radio" name="_recurio_payment_type" value="split" <?php checked( $payment_type, 'split' ); ?> />
							<?php echo esc_html__( 'Split Payments (installments)', 'recurio' ); ?>
						</label>
					</span>
					<?php echo wp_kses_post( wc_help_tip( __( 'Recurring: Subscription continues indefinitely. Split: Fixed number of payments then subscription completes.', 'recurio' ) ) ); ?>
				</p>

				<div class="recurio-split-payment-options <?php echo $payment_type === 'split' ? '' : 'recurio-hidden'; ?>">
					<p class="form-field _recurio_max_payments_field">
						<label for="_recurio_max_payments"><?php echo esc_html__( 'Number of Payments', 'recurio' ); ?></label>
						<input type="number" class="short" name="_recurio_max_payments" id="_recurio_max_payments" value="<?php echo esc_attr( $max_payments ?: 2 ); ?>" placeholder="2" min="2" step="1" <?php echo $payment_type !== 'split' ? 'disabled' : ''; ?> />
						<?php echo wp_kses_post( wc_help_tip( __( 'Total number of installment payments (minimum 2)', 'recurio' ) ) ); ?>
					</p>

					<p class="form-field _recurio_access_timing_field">
						<label for="_recurio_access_timing"><?php echo esc_html__( 'Product Access', 'recurio' ); ?></label>
						<select name="_recurio_access_timing" id="_recurio_access_timing" class="select short">
							<option value="immediate" <?php selected( $access_timing, 'immediate' ); ?>><?php echo esc_html__( 'Immediate (access until all payments done)', 'recurio' ); ?></option>
							<option value="after_full_payment" <?php selected( $access_timing, 'after_full_payment' ); ?>><?php echo esc_html__( 'After full payment', 'recurio' ); ?></option>
							<option value="custom_duration" <?php selected( $access_timing, 'custom_duration' ); ?>><?php echo esc_html__( 'Custom access duration', 'recurio' ); ?></option>
						</select>
						<?php echo wp_kses_post( wc_help_tip( __( 'Immediate: Access from first payment until completion. After full payment: Access only after all payments. Custom duration: Access for a specific time after first payment.', 'recurio' ) ) ); ?>
					</p>

					<div class="recurio-custom-duration-options <?php echo $access_timing === 'custom_duration' ? '' : 'recurio-hidden'; ?>">
						<p class="form-field _recurio_access_duration_field">
							<label><?php echo esc_html__( 'Custom Access Duration', 'recurio' ); ?></label>
							<span class="recurio-custom-period-wrapper">
								<input type="number" class="short" name="_recurio_access_duration_value" id="_recurio_access_duration_value" value="<?php echo esc_attr( $access_duration_value ); ?>" min="1" max="365" step="1" style="width: 60px;" />
								<select name="_recurio_access_duration_unit" id="_recurio_access_duration_unit" class="select short">
									<option value="day" <?php selected( $access_duration_unit, 'day' ); ?>><?php echo esc_html__( 'Day(s)', 'recurio' ); ?></option>
									<option value="week" <?php selected( $access_duration_unit, 'week' ); ?>><?php echo esc_html__( 'Week(s)', 'recurio' ); ?></option>
									<option value="month" <?php selected( $access_duration_unit, 'month' ); ?>><?php echo esc_html__( 'Month(s)', 'recurio' ); ?></option>
									<option value="year" <?php selected( $access_duration_unit, 'year' ); ?>><?php echo esc_html__( 'Year(s)', 'recurio' ); ?></option>
								</select>
							</span>
							<?php echo wp_kses_post( wc_help_tip( __( 'Define how long access should continue after the first payment is completed.', 'recurio' ) ) ); ?>
						</p>
					</div>

					<p class="recurio-split-payment-info">
						<?php
						/* translators: Example showing split payment calculation */
						echo esc_html__( 'Example: A $120 product with 4 monthly payments = $30/month for 4 months.', 'recurio' );
						?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save subscription data
	 */
	public function save_subscription_data( $post_id ) {

		if ( isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			// Save checkbox
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			$subscription_enabled = isset( $_POST['_recurio_subscription_enabled'] ) ? 'yes' : 'no';
			update_post_meta( $post_id, '_recurio_subscription_enabled', $subscription_enabled );

			// ===== Subscribe & Save Options (Pro Feature) =====
			if ( $this->is_pro_licensed() ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
				$allow_one_time = isset( $_POST['_recurio_allow_one_time_purchase'] ) ? 'yes' : 'no';
				update_post_meta( $post_id, '_recurio_allow_one_time_purchase', $allow_one_time );

				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
				if ( isset( $_POST['_recurio_subscription_discount_type'] ) ) {
					$discount_type = sanitize_text_field( wp_unslash( $_POST['_recurio_subscription_discount_type'] ) );
					update_post_meta( $post_id, '_recurio_subscription_discount_type', $discount_type );
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
				if ( isset( $_POST['_recurio_subscription_discount_value'] ) ) {
					$discount_value = wc_format_decimal( wp_unslash( $_POST['_recurio_subscription_discount_value'] ) );
					update_post_meta( $post_id, '_recurio_subscription_discount_value', $discount_value );
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
				$show_savings = isset( $_POST['_recurio_show_savings'] ) ? 'yes' : 'no';
				update_post_meta( $post_id, '_recurio_show_savings', $show_savings );
			}

			// ===== Custom Billing Period Options (Pro Feature) =====
			if ( $this->is_pro_licensed() ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
				$use_custom_period = isset( $_POST['_recurio_use_custom_period'] ) ? 'yes' : 'no';
				update_post_meta( $post_id, '_recurio_use_custom_period', $use_custom_period );

				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
				if ( isset( $_POST['_recurio_subscription_billing_interval'] ) ) {
					$billing_interval = absint( wp_unslash( $_POST['_recurio_subscription_billing_interval'] ) );
					$billing_interval = max( 1, $billing_interval ); // Ensure at least 1
					update_post_meta( $post_id, '_recurio_subscription_billing_interval', $billing_interval );
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
				if ( isset( $_POST['_recurio_subscription_billing_unit'] ) ) {
					$billing_unit = sanitize_text_field( wp_unslash( $_POST['_recurio_subscription_billing_unit'] ) );
					$valid_units  = array( 'day', 'week', 'month', 'year' );
					if ( in_array( $billing_unit, $valid_units, true ) ) {
						update_post_meta( $post_id, '_recurio_subscription_billing_unit', $billing_unit );
					}
				}
			}

			// Save billing period (single value) - only if not using custom period
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			$billing_period = isset( $_POST['_recurio_subscription_billing_period'] ) ? sanitize_text_field( wp_unslash( $_POST['_recurio_subscription_billing_period'] ) ) : 'monthly';

			// Validate that the selected period is enabled in settings
			$settings        = get_option( 'recurio_settings', array() );
			$enabled_periods = isset( $settings['billing']['periods'] ) ? $settings['billing']['periods'] : array( 'monthly', 'yearly' );

			// If selected period is not enabled, default to first enabled period
			if ( ! in_array( $billing_period, $enabled_periods ) ) {
				$billing_period = ! empty( $enabled_periods ) ? reset( $enabled_periods ) : 'monthly';
			}

			update_post_meta( $post_id, '_recurio_subscription_billing_period', $billing_period );

			// For backward compatibility, also save as periods array
			update_post_meta( $post_id, '_recurio_subscription_periods', array( $billing_period ) );

			// Save trial days
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_subscription_trial_days'] ) ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- WooCommerce product save hook, validation handled by WooCommerce
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- absint with wp_unslash handles this properly
				update_post_meta( $post_id, '_recurio_subscription_trial_days', absint( wp_unslash( $_POST['_recurio_subscription_trial_days'] ) ) ); /* phpcs:ignore WordPress.Security.NonceVerification.Missing */
			}

			// Save signup fee
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_subscription_signup_fee'] ) ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- WooCommerce wc_format_decimal handles unslashing
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce wc_format_decimal handles sanitization
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- WooCommerce product save hook, validation handled by WooCommerce
				update_post_meta( $post_id, '_recurio_subscription_signup_fee', wc_format_decimal( $_POST['_recurio_subscription_signup_fee'] ) ); /* phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized */
			}

			// Save subscription length
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_subscription_length'] ) ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- WooCommerce product save hook, validation handled by WooCommerce
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- absint with wp_unslash handles this properly
				update_post_meta( $post_id, '_recurio_subscription_length', absint( wp_unslash( $_POST['_recurio_subscription_length'] ) ) ); /* phpcs:ignore WordPress.Security.NonceVerification.Missing */
			}

			// Save subscription limit
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_subscription_limit'] ) ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- WooCommerce product save hook, validation handled by WooCommerce
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- absint with wp_unslash handles this properly
				update_post_meta( $post_id, '_recurio_subscription_limit', absint( wp_unslash( $_POST['_recurio_subscription_limit'] ) ) ); /* phpcs:ignore WordPress.Security.NonceVerification.Missing */
			}

			// Save renewal reminder
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Simple yes/no value, WooCommerce hook context
			$renewal_reminder = isset( $_POST['_recurio_subscription_renewal_reminder'] ) ? 'yes' : 'no'; /* phpcs:ignore WordPress.Security.NonceVerification.Missing */
			update_post_meta( $post_id, '_recurio_subscription_renewal_reminder', $renewal_reminder );

			// Save auto-renewal
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Simple yes/no value, WooCommerce hook context
			$auto_renewal = isset( $_POST['_recurio_subscription_auto_renewal'] ) ? 'yes' : 'no'; /* phpcs:ignore WordPress.Security.NonceVerification.Missing */
			update_post_meta( $post_id, '_recurio_subscription_auto_renewal', $auto_renewal );

			// ===== Split Payments / Installments Options =====
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_payment_type'] ) ) {
				$payment_type = sanitize_text_field( wp_unslash( $_POST['_recurio_payment_type'] ) );
				$valid_types  = array( 'recurring', 'split' );
				if ( in_array( $payment_type, $valid_types, true ) ) {
					update_post_meta( $post_id, '_recurio_payment_type', $payment_type );
				}
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_max_payments'] ) ) {
				$max_payments = absint( wp_unslash( $_POST['_recurio_max_payments'] ) );
				// Ensure minimum of 2 for split payments
				if ( $payment_type === 'split' && $max_payments < 2 ) {
					$max_payments = 2;
				}
				update_post_meta( $post_id, '_recurio_max_payments', $max_payments );
			} elseif ( $payment_type !== 'split' ) {
				// Reset max_payments when not using split payment (field is disabled)
				update_post_meta( $post_id, '_recurio_max_payments', 0 );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_access_timing'] ) ) {
				$access_timing = sanitize_text_field( wp_unslash( $_POST['_recurio_access_timing'] ) );
				$valid_timings = array( 'immediate', 'after_full_payment', 'custom_duration' );
				if ( in_array( $access_timing, $valid_timings, true ) ) {
					update_post_meta( $post_id, '_recurio_access_timing', $access_timing );
				}
			}

			// Save custom access duration fields
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_access_duration_value'] ) ) {
				$duration_value = absint( wp_unslash( $_POST['_recurio_access_duration_value'] ) );
				$duration_value = max( 1, $duration_value ); // Ensure at least 1
				update_post_meta( $post_id, '_recurio_access_duration_value', $duration_value );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is a WooCommerce product save hook callback
			if ( isset( $_POST['_recurio_access_duration_unit'] ) ) {
				$duration_unit = sanitize_text_field( wp_unslash( $_POST['_recurio_access_duration_unit'] ) );
				$valid_units   = array( 'day', 'week', 'month', 'year' );
				if ( in_array( $duration_unit, $valid_units, true ) ) {
					update_post_meta( $post_id, '_recurio_access_duration_unit', $duration_unit );
				}
			}

		}
	}

	/**
	 * Add subscription column to product list
	 */
	public function add_subscription_column( $columns ) {
		$columns['subscription'] = '<span class="dashicons dashicons-update" title="' . esc_attr__( 'Subscription', 'recurio' ) . '"></span>';
		return $columns;
	}

	/**
	 * Display subscription column content
	 */
	public function display_subscription_column( $column, $post_id ) {
		if ( $column === 'subscription' ) {
			$enabled = get_post_meta( $post_id, '_recurio_subscription_enabled', true );
			if ( $enabled === 'yes' ) {
				echo '<span class="dashicons dashicons-yes recurio-subscription-yes" title="' . esc_attr__( 'Subscription enabled', 'recurio' ) . '"></span>';
			} else {
				echo '<span class="dashicons dashicons-minus recurio-subscription-no" title="' . esc_attr__( 'Subscription disabled', 'recurio' ) . '"></span>';
			}
		}
	}

	/**
	 * Add bulk edit fields
	 */
	public function add_bulk_edit_fields() {
		?>
		<div class="inline-edit-group">
			<label class="alignleft">
				<span class="title"><?php echo esc_html__( 'Subscription', 'recurio' ); ?></span>
				<span class="input-text-wrap">
					<select class="subscription_enabled" name="_recurio_subscription_enabled">
						<option value=""><?php echo esc_html__( '— No change —', 'recurio' ); ?></option>
						<option value="yes"><?php echo esc_html__( 'Enable', 'recurio' ); ?></option>
						<option value="no"><?php echo esc_html__( 'Disable', 'recurio' ); ?></option>
					</select>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Save bulk edit fields
	 */
	public function save_bulk_edit_fields( $product ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a WooCommerce bulk edit hook callback
		if ( ! empty( $_REQUEST['_recurio_subscription_enabled'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- WooCommerce bulk edit hook, validation handled by WooCommerce
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitize_text_field with wp_unslash handles this properly
			$value = sanitize_text_field( wp_unslash( $_REQUEST['_recurio_subscription_enabled'] ) ); /* phpcs:ignore WordPress.Security.NonceVerification.Recommended */
			if ( $value !== '' ) {
				update_post_meta( $product->get_id(), '_recurio_subscription_enabled', $value );
			}
		}
	}

	/**
	 * Check if Pro version is licensed
	 *
	 * @return bool
	 */
	private function is_pro_licensed() {
		// Use Pro Manager to check license status
		return Recurio_Pro_Manager::get_instance()->is_license_valid();
	}

	/**
	 * Get Pro upgrade URL
	 *
	 * @return string
	 */
	private function get_pro_upgrade_url() {
		return admin_url( 'admin.php?page=recurio-upgrade' );
	}

	/**
	 * Get enabled billing periods from settings
	 *
	 * @return array
	 */
	private function get_enabled_periods() {
		$settings = get_option( 'recurio_settings', array() );
		$periods  = isset( $settings['billing']['periods'] ) ? $settings['billing']['periods'] : array( 'monthly', 'yearly' );

		// If no periods are enabled in settings, default to monthly and yearly
		if ( empty( $periods ) ) {
			$periods = array( 'monthly', 'yearly' );
		}

		return $periods;
	}
}

// Initialize
Recurio_WooCommerce_Product::get_instance();