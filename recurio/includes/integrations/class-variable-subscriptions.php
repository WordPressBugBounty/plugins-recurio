<?php
/**
 * Variable product subscriptions — per-variation subscription settings
 *
 * @package Recurio
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variable subscription UI, cart, and storefront data (moved from recurio-pro).
 */
class Recurio_Variable_Subscriptions {

	/**
	 * Instance
	 *
	 * @var Recurio_Variable_Subscriptions|null
	 */
	private static $instance = null;

	/**
	 * Singleton
	 *
	 * @return Recurio_Variable_Subscriptions
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Hooks
	 */
	private function init_hooks() {
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_subscription_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_subscription_data' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( $this, 'add_variation_subscription_data' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_filter( 'recurio_subscription_cart_item_data', array( $this, 'filter_variation_cart_data' ), 10, 4 );
		add_filter( 'recurio_get_subscription_data', array( $this, 'filter_variation_subscription_data' ), 20, 2 );
	}

	/**
	 * Resolve variation object and IDs for variation meta hooks (WC_Product_Variation or legacy post).
	 *
	 * @param mixed $variation Variation object from WooCommerce.
	 * @return array{0: int, 1: int} [ variation_id, parent_id ]
	 */
	private function resolve_variation_context( $variation ) {
		if ( $variation instanceof WC_Product_Variation ) {
			return array( $variation->get_id(), $variation->get_parent_id() );
		}
		if ( is_object( $variation ) && isset( $variation->ID, $variation->post_parent ) ) {
			return array( (int) $variation->ID, (int) $variation->post_parent );
		}
		return array( 0, 0 );
	}

	/**
	 * Add subscription fields to each variation row
	 *
	 * @param int   $loop           Loop index.
	 * @param array $variation_data Variation export data.
	 * @param mixed $variation      WC_Product_Variation or legacy post object.
	 */
	public function add_variation_subscription_fields( $loop, $variation_data, $variation ) {
		list( $variation_id, $parent_id ) = $this->resolve_variation_context( $variation );
		if ( ! $parent_id ) {
			return;
		}

		$product = wc_get_product( $parent_id );
		if ( ! $product ) {
			return;
		}

		$parent_enabled = get_post_meta( $parent_id, '_recurio_subscription_enabled', true ) === 'yes';

		$override_settings  = get_post_meta( $variation_id, '_recurio_override_subscription', true ) === 'yes';
		$billing_period     = get_post_meta( $variation_id, '_recurio_subscription_billing_period', true );
		$trial_days         = get_post_meta( $variation_id, '_recurio_subscription_trial_days', true );
		$signup_fee         = get_post_meta( $variation_id, '_recurio_subscription_signup_fee', true );
		$subscription_price = get_post_meta( $variation_id, '_recurio_subscription_price', true );
		$billing_interval   = get_post_meta( $variation_id, '_recurio_subscription_billing_interval', true ) ?: 1;
		$billing_unit       = get_post_meta( $variation_id, '_recurio_subscription_billing_unit', true ) ?: 'month';
		$use_custom_period  = get_post_meta( $variation_id, '_recurio_use_custom_period', true ) === 'yes';

		$settings        = get_option( 'recurio_settings', array() );
		$enabled_periods = isset( $settings['billing']['periods'] ) ? $settings['billing']['periods'] : array( 'monthly', 'yearly' );

		?>
		<div class="recurio-variation-subscription-settings <?php echo $parent_enabled ? '' : 'recurio-hidden'; ?>">
			<p class="form-row form-row-full recurio-variation-header">
				<strong><?php esc_html_e( 'Subscription Settings', 'recurio' ); ?></strong>
			</p>

			<p class="form-row form-row-full recurio-override-row">
				<label>
					<input type="checkbox" class="checkbox recurio-override-subscription" name="_recurio_override_subscription[<?php echo esc_attr( $loop ); ?>]" value="yes" <?php checked( $override_settings ); ?> />
					<?php esc_html_e( 'Override parent subscription settings', 'recurio' ); ?>
				</label>
			</p>

			<div class="recurio-variation-subscription-options <?php echo $override_settings ? '' : 'recurio-hidden'; ?>">
				<p class="form-row form-row-first">
					<label for="_recurio_subscription_price_<?php echo esc_attr( $loop ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: currency symbol */
								__( 'Subscription Price (%s)', 'recurio' ),
								get_woocommerce_currency_symbol()
							)
						);
						?>
					</label>
					<input type="text" class="wc_input_price" name="_recurio_subscription_price[<?php echo esc_attr( $loop ); ?>]" id="_recurio_subscription_price_<?php echo esc_attr( $loop ); ?>" value="<?php echo esc_attr( $subscription_price ); ?>" placeholder="<?php esc_attr_e( 'Use variation price', 'recurio' ); ?>" />
				</p>

				<p class="form-row form-row-last">
					<label for="_recurio_subscription_trial_days_<?php echo esc_attr( $loop ); ?>">
						<?php esc_html_e( 'Trial Period (days)', 'recurio' ); ?>
					</label>
					<input type="number" class="short" name="_recurio_subscription_trial_days[<?php echo esc_attr( $loop ); ?>]" id="_recurio_subscription_trial_days_<?php echo esc_attr( $loop ); ?>" value="<?php echo esc_attr( $trial_days ); ?>" placeholder="<?php esc_attr_e( 'Inherit from parent', 'recurio' ); ?>" min="0" step="1" />
				</p>

				<p class="form-row form-row-first">
					<label for="_recurio_subscription_signup_fee_<?php echo esc_attr( $loop ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: currency symbol */
								__( 'Sign-up Fee (%s)', 'recurio' ),
								get_woocommerce_currency_symbol()
							)
						);
						?>
					</label>
					<input type="text" class="wc_input_price" name="_recurio_subscription_signup_fee[<?php echo esc_attr( $loop ); ?>]" id="_recurio_subscription_signup_fee_<?php echo esc_attr( $loop ); ?>" value="<?php echo esc_attr( $signup_fee ); ?>" placeholder="<?php esc_attr_e( 'Inherit from parent', 'recurio' ); ?>" />
				</p>

				<p class="form-row form-row-last recurio-custom-period-toggle">
					<label>
						<input type="checkbox" class="checkbox recurio-variation-custom-period" name="_recurio_use_custom_period[<?php echo esc_attr( $loop ); ?>]" value="yes" <?php checked( $use_custom_period ); ?> />
						<?php esc_html_e( 'Custom billing period', 'recurio' ); ?>
					</label>
				</p>

				<div class="recurio-variation-standard-period form-row form-row-full <?php echo $use_custom_period ? 'recurio-hidden' : ''; ?>">
					<label><?php esc_html_e( 'Billing Period', 'recurio' ); ?></label>
					<select name="_recurio_subscription_billing_period[<?php echo esc_attr( $loop ); ?>]" class="select short">
						<option value=""><?php esc_html_e( '— Inherit from parent —', 'recurio' ); ?></option>
						<?php
						$all_periods = array(
							'daily'     => __( 'Daily', 'recurio' ),
							'weekly'    => __( 'Weekly', 'recurio' ),
							'monthly'   => __( 'Monthly', 'recurio' ),
							'quarterly' => __( 'Quarterly', 'recurio' ),
							'yearly'    => __( 'Yearly', 'recurio' ),
						);
						foreach ( $all_periods as $key => $label ) {
							$is_enabled = in_array( $key, $enabled_periods, true );
							if ( $is_enabled || $billing_period === $key ) {
								echo '<option value="' . esc_attr( $key ) . '" ' . selected( $billing_period, $key, false ) . '>' . esc_html( $label ) . '</option>';
							}
						}
						?>
					</select>
				</div>

				<div class="recurio-variation-custom-period-fields form-row form-row-full <?php echo $use_custom_period ? '' : 'recurio-hidden'; ?>">
					<label><?php esc_html_e( 'Bill Every', 'recurio' ); ?></label>
					<span class="recurio-custom-period-wrapper">
						<input type="number" class="short" name="_recurio_subscription_billing_interval[<?php echo esc_attr( $loop ); ?>]" value="<?php echo esc_attr( $billing_interval ); ?>" min="1" max="365" step="1" style="width: 60px;" />
						<select name="_recurio_subscription_billing_unit[<?php echo esc_attr( $loop ); ?>]" class="select short">
							<option value="day" <?php selected( $billing_unit, 'day' ); ?>><?php esc_html_e( 'Day(s)', 'recurio' ); ?></option>
							<option value="week" <?php selected( $billing_unit, 'week' ); ?>><?php esc_html_e( 'Week(s)', 'recurio' ); ?></option>
							<option value="month" <?php selected( $billing_unit, 'month' ); ?>><?php esc_html_e( 'Month(s)', 'recurio' ); ?></option>
							<option value="year" <?php selected( $billing_unit, 'year' ); ?>><?php esc_html_e( 'Year(s)', 'recurio' ); ?></option>
						</select>
					</span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save variation subscription meta
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 */
	public function save_variation_subscription_data( $variation_id, $loop ) {
		$nonce_ok = false;
		if ( isset( $_POST['woocommerce_meta_nonce'] ) ) {
			$nonce_ok = wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' );
		}
		if ( ! $nonce_ok && isset( $_POST['security'] ) ) {
			$nonce_ok = wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' );
		}
		if ( ! $nonce_ok ) {
			return;
		}

		$variation_id = absint( $variation_id );
		if ( ! $variation_id || ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce product/variation save

		$loop_key = is_numeric( $loop ) ? absint( $loop ) : $loop;

		$override = isset( $_POST['_recurio_override_subscription'][ $loop_key ] ) ? 'yes' : 'no';
		update_post_meta( $variation_id, '_recurio_override_subscription', $override );

		if ( $override !== 'yes' ) {
			delete_post_meta( $variation_id, '_recurio_subscription_price' );
			delete_post_meta( $variation_id, '_recurio_subscription_trial_days' );
			delete_post_meta( $variation_id, '_recurio_subscription_signup_fee' );
			delete_post_meta( $variation_id, '_recurio_use_custom_period' );
			delete_post_meta( $variation_id, '_recurio_subscription_billing_period' );
			delete_post_meta( $variation_id, '_recurio_subscription_billing_interval' );
			delete_post_meta( $variation_id, '_recurio_subscription_billing_unit' );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( isset( $_POST['_recurio_subscription_price'][ $loop_key ] ) ) {
			$price = wc_format_decimal( wp_unslash( $_POST['_recurio_subscription_price'][ $loop_key ] ) );
			update_post_meta( $variation_id, '_recurio_subscription_price', $price );
		}

		if ( isset( $_POST['_recurio_subscription_trial_days'][ $loop_key ] ) ) {
			$trial = sanitize_text_field( wp_unslash( $_POST['_recurio_subscription_trial_days'][ $loop_key ] ) );
			update_post_meta( $variation_id, '_recurio_subscription_trial_days', $trial );
		}

		if ( isset( $_POST['_recurio_subscription_signup_fee'][ $loop_key ] ) ) {
			$fee = wc_format_decimal( wp_unslash( $_POST['_recurio_subscription_signup_fee'][ $loop_key ] ) );
			update_post_meta( $variation_id, '_recurio_subscription_signup_fee', $fee );
		}

		$use_custom = isset( $_POST['_recurio_use_custom_period'][ $loop_key ] ) ? 'yes' : 'no';
		update_post_meta( $variation_id, '_recurio_use_custom_period', $use_custom );

		if ( isset( $_POST['_recurio_subscription_billing_period'][ $loop_key ] ) ) {
			$period = sanitize_text_field( wp_unslash( $_POST['_recurio_subscription_billing_period'][ $loop_key ] ) );
			update_post_meta( $variation_id, '_recurio_subscription_billing_period', $period );
		}

		if ( isset( $_POST['_recurio_subscription_billing_interval'][ $loop_key ] ) ) {
			$interval = absint( wp_unslash( $_POST['_recurio_subscription_billing_interval'][ $loop_key ] ) );
			$interval = max( 1, $interval );
			update_post_meta( $variation_id, '_recurio_subscription_billing_interval', $interval );
		}

		if ( isset( $_POST['_recurio_subscription_billing_unit'][ $loop_key ] ) ) {
			$unit        = sanitize_text_field( wp_unslash( $_POST['_recurio_subscription_billing_unit'][ $loop_key ] ) );
			$valid_units = array( 'day', 'week', 'month', 'year' );
			if ( in_array( $unit, $valid_units, true ) ) {
				update_post_meta( $variation_id, '_recurio_subscription_billing_unit', $unit );
			}
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Expose subscription snapshot on each variation for product-page JS
	 *
	 * @param array                $variation_data Variation data for frontend.
	 * @param WC_Product           $product        Parent product.
	 * @param WC_Product_Variation $variation      Variation.
	 * @return array
	 */
	public function add_variation_subscription_data( $variation_data, $product, $variation ) {
		$parent_enabled = get_post_meta( $product->get_id(), '_recurio_subscription_enabled', true ) === 'yes';
		if ( ! $parent_enabled ) {
			return $variation_data;
		}

		$variation_id        = $variation->get_id();
		$subscription_detail = $this->get_variation_subscription_data( $variation_id, $product->get_id() );

		$variation_data['recurio_subscription'] = array(
			'enabled'         => true,
			'price'           => $subscription_detail['price'],
			'period'          => $subscription_detail['period'],
			'interval'        => $subscription_detail['interval'],
			'trial_days'      => $subscription_detail['trial_days'],
			'signup_fee'      => $subscription_detail['signup_fee'],
			'price_html'      => $this->format_subscription_price_html( $subscription_detail, $variation ),
			'billing_text'    => $this->format_billing_period_text( $subscription_detail ),
			'override_parent' => get_post_meta( $variation_id, '_recurio_override_subscription', true ) === 'yes',
		);

		return $variation_data;
	}

	/**
	 * Build subscription amounts/schedule for a variation (parent fallbacks)
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $parent_id    Parent product ID.
	 * @return array{price: float, period: string, interval: int, trial_days: int, signup_fee: float}
	 */
	public function get_variation_subscription_data( $variation_id, $parent_id ) {
		$override = get_post_meta( $variation_id, '_recurio_override_subscription', true ) === 'yes';

		$parent_trial    = get_post_meta( $parent_id, '_recurio_subscription_trial_days', true ) ?: 0;
		$parent_signup   = get_post_meta( $parent_id, '_recurio_subscription_signup_fee', true ) ?: 0;
		$parent_period   = get_post_meta( $parent_id, '_recurio_subscription_billing_period', true ) ?: 'monthly';
		$parent_custom   = get_post_meta( $parent_id, '_recurio_use_custom_period', true ) === 'yes';
		$parent_interval = get_post_meta( $parent_id, '_recurio_subscription_billing_interval', true ) ?: 1;
		$parent_unit     = get_post_meta( $parent_id, '_recurio_subscription_billing_unit', true ) ?: 'month';

		$variation_product = wc_get_product( $variation_id );
		$price             = $variation_product ? $variation_product->get_price() : 0;

		if ( $override ) {
			$var_price = get_post_meta( $variation_id, '_recurio_subscription_price', true );
			if ( $var_price !== '' && $var_price !== false ) {
				$price = floatval( $var_price );
			}

			$var_trial  = get_post_meta( $variation_id, '_recurio_subscription_trial_days', true );
			$trial_days = ( $var_trial !== '' && $var_trial !== false ) ? $var_trial : $parent_trial;

			$var_signup = get_post_meta( $variation_id, '_recurio_subscription_signup_fee', true );
			$signup_fee = ( $var_signup !== '' && $var_signup !== false ) ? $var_signup : $parent_signup;

			$use_custom = get_post_meta( $variation_id, '_recurio_use_custom_period', true ) === 'yes';

			if ( $use_custom ) {
				$interval = get_post_meta( $variation_id, '_recurio_subscription_billing_interval', true ) ?: 1;
				$period   = get_post_meta( $variation_id, '_recurio_subscription_billing_unit', true ) ?: 'month';
			} else {
				$var_period = get_post_meta( $variation_id, '_recurio_subscription_billing_period', true );
				if ( $var_period ) {
					$period_map = array(
						'daily'     => array( 'period' => 'day', 'interval' => 1 ),
						'weekly'    => array( 'period' => 'week', 'interval' => 1 ),
						'monthly'   => array( 'period' => 'month', 'interval' => 1 ),
						'quarterly' => array( 'period' => 'month', 'interval' => 3 ),
						'yearly'    => array( 'period' => 'year', 'interval' => 1 ),
					);
					$mapped   = isset( $period_map[ $var_period ] ) ? $period_map[ $var_period ] : array( 'period' => 'month', 'interval' => 1 );
					$period   = $mapped['period'];
					$interval = $mapped['interval'];
				} else {
					$period   = $this->convert_parent_period( $parent_period, $parent_custom, $parent_unit );
					$interval = $this->get_parent_interval( $parent_period, $parent_custom, $parent_interval );
				}
			}
		} else {
			$trial_days = $parent_trial;
			$signup_fee = $parent_signup;
			$period     = $this->convert_parent_period( $parent_period, $parent_custom, $parent_unit );
			$interval   = $this->get_parent_interval( $parent_period, $parent_custom, $parent_interval );
		}

		return array(
			'price'      => floatval( $price ),
			'period'     => $period,
			'interval'   => intval( $interval ),
			'trial_days' => intval( $trial_days ),
			'signup_fee' => floatval( $signup_fee ),
		);
	}

	/**
	 * Parent preset -> WC period unit
	 *
	 * @param string $parent_period Parent billing period slug.
	 * @param bool   $use_custom    Custom period enabled on parent.
	 * @param string $custom_unit   Parent custom unit.
	 * @return string
	 */
	private function convert_parent_period( $parent_period, $use_custom, $custom_unit ) {
		if ( $use_custom ) {
			return $custom_unit;
		}

		$period_map = array(
			'daily'     => 'day',
			'weekly'    => 'week',
			'monthly'   => 'month',
			'quarterly' => 'month',
			'yearly'    => 'year',
		);

		return isset( $period_map[ $parent_period ] ) ? $period_map[ $parent_period ] : 'month';
	}

	/**
	 * Parent interval for standard presets
	 *
	 * @param string $parent_period   Parent period slug.
	 * @param bool   $use_custom      Parent custom period.
	 * @param int    $custom_interval Parent custom interval.
	 * @return int
	 */
	private function get_parent_interval( $parent_period, $use_custom, $custom_interval ) {
		if ( $use_custom ) {
			return intval( $custom_interval );
		}
		if ( $parent_period === 'quarterly' ) {
			return 3;
		}
		return 1;
	}

	/**
	 * Price + cadence display snippet
	 *
	 * @param array                $subscription_data Computed subscription row.
	 * @param WC_Product_Variation $variation         Variation product.
	 * @return string
	 */
	private function format_subscription_price_html( $subscription_data, $variation ) {
		$price_html = wc_price( $subscription_data['price'] );

		$billing_text = $this->format_billing_period_text( $subscription_data );
		$price_html  .= ' <span class="recurio-billing-period">' . esc_html( $billing_text ) . '</span>';

		if ( $subscription_data['trial_days'] > 0 ) {
			$price_html .= ' <span class="recurio-trial">' . sprintf(
				/* translators: %d: trial days */
				esc_html__( '(%d days free trial)', 'recurio' ),
				$subscription_data['trial_days']
			) . '</span>';
		}

		if ( $subscription_data['signup_fee'] > 0 ) {
			$price_html .= ' <span class="recurio-signup-fee">' . sprintf(
				/* translators: %s: signup fee */
				esc_html__( '+ %s sign-up fee', 'recurio' ),
				wc_price( $subscription_data['signup_fee'] )
			) . '</span>';
		}

		return $price_html;
	}

	/**
	 * Human-readable billing cadence
	 *
	 * @param array $subscription_data Data from get_variation_subscription_data().
	 * @return string
	 */
	private function format_billing_period_text( $subscription_data ) {
		$interval = intval( $subscription_data['interval'] );
		$period   = $subscription_data['period'];

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

		if ( ! isset( $period_names[ $period ] ) ) {
			$period = 'month';
		}

		$period_name = $interval === 1 ? $period_names[ $period ]['single'] : $period_names[ $period ]['plural'];

		if ( $interval === 1 ) {
			return sprintf(
				/* translators: %s: period (day, month, …) */
				__( '/ %s', 'recurio' ),
				$period_name
			);
		}

		return sprintf(
			/* translators: 1: interval count, 2: period name (plural) */
			__( 'every %1$d %2$s', 'recurio' ),
			$interval,
			$period_name
		);
	}

	/**
	 * Enqueue admin assets for variation UI
	 *
	 * @param string $hook Current screen hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		global $post;

		if ( ( $hook === 'post.php' || $hook === 'post-new.php' ) && $post && $post->post_type === 'product' ) {
			wp_enqueue_style(
				'recurio-variable-subscriptions',
				RECURIO_PLUGIN_URL . 'assets/css/variable-subscriptions.css',
				array(),
				RECURIO_VERSION
			);

			wp_enqueue_script(
				'recurio-variable-subscriptions',
				RECURIO_PLUGIN_URL . 'assets/js/variable-subscriptions.js',
				array( 'jquery', 'wc-admin-variation-meta-boxes' ),
				RECURIO_VERSION,
				true
			);

			wp_localize_script(
				'recurio-variable-subscriptions',
				'recurioVariableSubscriptions',
				array(
					'i18n' => array(
						'inheritParent' => __( 'Inherit from parent', 'recurio' ),
					),
				)
			);
		}
	}

	/**
	 * Merge computed variation subscription schedule into cart line
	 *
	 * @param array $cart_item_data Cart item payload.
	 * @param int   $product_id     Parent product ID.
	 * @param int   $variation_id   Variation ID.
	 * @param array $unused_attrs    Reserved (variation attribute array from request).
	 * @return array
	 */
	public function filter_variation_cart_data( $cart_item_data, $product_id, $variation_id, $unused_attrs = array() ) {
		unset( $unused_attrs );
		if ( ! $variation_id ) {
			return $cart_item_data;
		}

		$parent_enabled = get_post_meta( $product_id, '_recurio_subscription_enabled', true ) === 'yes';
		if ( ! $parent_enabled ) {
			return $cart_item_data;
		}

		$subscription_data = $this->get_variation_subscription_data( $variation_id, $product_id );

		if ( isset( $cart_item_data['subscription'] ) ) {
			$cart_item_data['subscription'] = array_merge(
				$cart_item_data['subscription'],
				array(
					'price'        => $subscription_data['price'],
					'period'       => $subscription_data['period'],
					'interval'     => $subscription_data['interval'],
					'trial_length' => $subscription_data['trial_days'],
					'trial_period' => 'day',
					'sign_up_fee'  => $subscription_data['signup_fee'],
				)
			);
		}

		return $cart_item_data;
	}

	/**
	 * Normalize subscription array for variation products
	 *
	 * @param array      $subscription_data Built by core integration.
	 * @param WC_Product $product          Product (variation or simple).
	 * @return array
	 */
	public function filter_variation_subscription_data( $subscription_data, $product ) {
		if ( ! $product || $product->get_type() !== 'variation' ) {
			return $subscription_data;
		}

		$variation_id = $product->get_id();
		$parent_id    = $product->get_parent_id();

		$parent_enabled = get_post_meta( $parent_id, '_recurio_subscription_enabled', true ) === 'yes';
		if ( ! $parent_enabled ) {
			return $subscription_data;
		}

		$var_data = $this->get_variation_subscription_data( $variation_id, $parent_id );

		return array(
			'price'            => $var_data['price'],
			'period'           => $var_data['period'],
			'interval'         => $var_data['interval'],
			'trial_length'     => $var_data['trial_days'],
			'trial_period'     => 'day',
			'sign_up_fee'      => $var_data['signup_fee'],
			'length'           => isset( $subscription_data['length'] ) ? $subscription_data['length'] : 0,
			'limit'            => isset( $subscription_data['limit'] ) ? $subscription_data['limit'] : 0,
			'auto_renewal'     => isset( $subscription_data['auto_renewal'] ) ? $subscription_data['auto_renewal'] : true,
			'renewal_reminder' => isset( $subscription_data['renewal_reminder'] ) ? $subscription_data['renewal_reminder'] : false,
		);
	}
}

Recurio_Variable_Subscriptions::get_instance();
