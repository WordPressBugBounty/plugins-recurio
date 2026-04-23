<?php
/**
 * Pro Manager - Handles Pro version detection and feature availability
 *
 * @package Recurio
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recurio Pro Manager Class
 *
 * Manages the integration between Free and Pro versions.
 * Provides detection, feature checking, and hooks for Pro extensions.
 *
 * @since 1.1.0
 */
class Recurio_Pro_Manager {

	/**
	 * Singleton instance
	 *
	 * @var Recurio_Pro_Manager|null
	 */
	private static $instance = null;

	/**
	 * Pro plugin status
	 *
	 * @var bool
	 */
	private $pro_active = false;

	/**
	 * Pro plugin version
	 *
	 * @var string
	 */
	private $pro_version = '';

	/**
	 * Pro license status
	 *
	 * @var bool
	 */
	private $license_valid = false;

	/**
	 * Available Pro features
	 *
	 * @var array
	 */
	private $pro_features = array();

	/**
	 * Get singleton instance
	 *
	 * @return Recurio_Pro_Manager
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
		// Do NOT check Pro status in constructor - let plugins_loaded hook handle it
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Check Pro status AFTER all plugins are fully loaded (priority 100)
		// This ensures Pro plugin has registered its filters first
		add_action( 'plugins_loaded', array( $this, 'check_pro_status' ), 100 );

		// Enqueue Pro badge styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_pro_styles' ) );

		// Admin notices
		add_action( 'admin_notices', array( $this, 'show_upgrade_notices' ) );

		// AJAX handler for dismissing notices
		add_action( 'wp_ajax_recurio_dismiss_upgrade_notice', array( $this, 'ajax_dismiss_upgrade_notice' ) );
	}

	/**
	 * Enqueue Pro badge styles
	 */
	public function enqueue_pro_styles() {
		// Only load on Recurio admin pages
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'recurio' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'recurio-pro-badges',
			RECURIO_PLUGIN_URL . 'assets/css/pro-badges.css',
			array(),
			RECURIO_VERSION
		);
	}

	/**
	 * AJAX handler for dismissing upgrade notice
	 */
	public function ajax_dismiss_upgrade_notice() {
		check_ajax_referer( 'recurio_dismiss_notice', 'nonce' );

		update_user_meta( get_current_user_id(), 'recurio_upgrade_notice_dismissed', true );

		wp_send_json_success();
	}

	/**
	 * Check if Pro plugin is active and valid
	 */
	public function check_pro_status() {
		// Check if Pro plugin has registered itself
		$this->pro_active = apply_filters( 'recurio_pro_is_active', false );

		if ( $this->pro_active ) {
			$this->pro_version    = apply_filters( 'recurio_pro_version', '' );
			$this->license_valid  = apply_filters( 'recurio_pro_license_valid', false );
			$this->pro_features   = apply_filters( 'recurio_pro_available_features', array() );
		}

		// Trigger action after status check
		do_action( 'recurio_pro_status_checked', $this->pro_active, $this->license_valid );
	}

	/**
	 * Check if Pro plugin is active
	 *
	 * @return bool True if Pro is active
	 */
	public function is_pro_active() {
		return $this->pro_active;
	}

	/**
	 * Check if Pro license is valid
	 *
	 * @return bool True if license is valid
	 */
	public function is_license_valid() {
		return $this->pro_active && $this->license_valid;
	}

	/**
	 * Get Pro version
	 *
	 * @return string Pro version number
	 */
	public function get_pro_version() {
		return $this->pro_version;
	}

	/**
	 * Check if a specific Pro feature is available
	 *
	 * @param string $feature Feature name to check
	 * @return bool True if feature is available
	 */
	public function is_feature_available( $feature ) {
		// If Pro is not active or license invalid, feature is not available
		if ( ! $this->is_license_valid() ) {
			return false;
		}

		// If no features registered, assume all Pro features available
		if ( empty( $this->pro_features ) ) {
			return true;
		}

		// Check if feature is in available features list
		return in_array( $feature, $this->pro_features, true );
	}

	/**
	 * Get all available Pro features
	 *
	 * @return array List of available features
	 */
	public function get_available_features() {
		return $this->pro_features;
	}

	/**
	 * Get Pro feature label for UI
	 *
	 * @param string $type Badge type (badge, label, ribbon)
	 * @return string HTML for Pro badge
	 */
	public function get_pro_badge( $type = 'badge' ) {
		$badges = array(
			'badge'  => '<span class="recurio-pro-badge">PRO</span>',
			'label'  => '<span class="recurio-pro-label">Pro Feature</span>',
			'ribbon' => '<span class="recurio-pro-ribbon">Upgrade to Pro</span>',
		);

		return isset( $badges[ $type ] ) ? $badges[ $type ] : $badges['badge'];
	}

	/**
	 * Get upgrade URL
	 *
	 * @param string $feature Feature name for tracking
	 * @return string Upgrade URL
	 */
	public function get_upgrade_url( $feature = '' ) {
		$url = 'https://wprecurio.com/pricing/';

		if ( ! empty( $feature ) ) {
			$url = add_query_arg( 'feature', $feature, $url );
		}

		// Add UTM parameters for tracking
		$url = add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => 'admin',
				'utm_campaign' => 'upgrade',
			),
			$url
		);

		return apply_filters( 'recurio_upgrade_url', $url, $feature );
	}

	/**
	 * Show upgrade notice for specific feature
	 *
	 * @param string $feature Feature name
	 * @param string $message Custom message
	 * @return string HTML for upgrade notice
	 */
	public function get_upgrade_notice( $feature, $message = '' ) {
		if ( empty( $message ) ) {
			$message = sprintf(
				/* translators: %s: Feature name */
				__( 'Upgrade to Recurio Pro to unlock %s and other advanced features.', 'recurio' ),
				'<strong>' . esc_html( $feature ) . '</strong>'
			);
		}

		$upgrade_url = $this->get_upgrade_url( $feature );

		ob_start();
		?>
		<div class="recurio-upgrade-notice">
			<div class="recurio-upgrade-icon">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none">
					<path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>
			<div class="recurio-upgrade-content">
				<p><?php echo wp_kses_post( $message ); ?></p>
				<a href="<?php echo esc_url( $upgrade_url ); ?>" class="button button-primary" target="_blank">
					<?php esc_html_e( 'Upgrade to Pro', 'recurio' ); ?>
				</a>
				<a href="https://wprecurio.com/features/" class="button button-secondary" target="_blank">
					<?php esc_html_e( 'Learn More', 'recurio' ); ?>
				</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Show admin notices for Pro features
	 */
	public function show_upgrade_notices() {
		// Only show on Recurio admin pages
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'recurio' ) === false ) {
			return;
		}

		// Don't show if Pro is active and licensed
		if ( $this->is_license_valid() ) {
			return;
		}

		// Show Pro activation notice if installed but not licensed
		if ( $this->pro_active && ! $this->license_valid ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Recurio Pro is installed but not activated.', 'recurio' ); ?></strong>
					<?php esc_html_e( 'Please enter your license key to unlock all Pro features.', 'recurio' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// Optionally show upgrade notice (can be dismissed)
		$dismissed = get_user_meta( get_current_user_id(), 'recurio_upgrade_notice_dismissed', true );
		if ( $dismissed ) {
			return;
		}

		?>
		<div class="notice notice-info is-dismissible recurio-upgrade-admin-notice">
			<h3><?php esc_html_e( '🚀 Unlock the Full Power of Recurio', 'recurio' ); ?></h3>
			<p>
				<?php esc_html_e( 'You\'re using the free version of Recurio. Upgrade to Pro for advanced features:', 'recurio' ); ?>
			</p>
			<ul style="list-style: disc; margin-left: 20px;">
				<li><?php esc_html_e( '⚡ Automated billing with smart dunning management', 'recurio' ); ?></li>
				<li><?php esc_html_e( '📊 Advanced analytics with cohort analysis and revenue forecasting', 'recurio' ); ?></li>
				<li><?php esc_html_e( '💰 Revenue goals and performance tracking', 'recurio' ); ?></li>
				<li><?php esc_html_e( '🎨 Advanced customer portal with payment management', 'recurio' ); ?></li>
				<li><?php esc_html_e( '📧 Custom email templates and automation campaigns', 'recurio' ); ?></li>
			</ul>
			<p>
				<a href="<?php echo esc_url( $this->get_upgrade_url() ); ?>" class="button button-primary" target="_blank">
					<?php esc_html_e( 'Upgrade to Pro', 'recurio' ); ?>
				</a>
				<a href="https://wprecurio.com/features/" class="button button-secondary" target="_blank">
					<?php esc_html_e( 'Compare Features', 'recurio' ); ?>
				</a>
			</p>
		</div>
		<script>
		jQuery(document).on('click', '.recurio-upgrade-admin-notice .notice-dismiss', function() {
			jQuery.post(ajaxurl, {
				action: 'recurio_dismiss_upgrade_notice',
				nonce: '<?php echo esc_js( wp_create_nonce( 'recurio_dismiss_notice' ) ); ?>'
			});
		});
		</script>
		<?php
	}

	/**
	 * Get list of Pro-only features with descriptions
	 *
	 * @return array Feature list
	 */
	public function get_pro_feature_list() {
		return array(
			'automated_billing'       => array(
				'name'        => __( 'Automated Billing', 'recurio' ),
				'description' => __( 'Automatic payment processing with retry logic and dunning management', 'recurio' ),
				'category'    => 'automation',
			),
			'cohort_analysis'         => array(
				'name'        => __( 'Cohort Analysis', 'recurio' ),
				'description' => __( 'Track retention, revenue, and subscriptions by customer cohorts', 'recurio' ),
				'category'    => 'analytics',
			),
			'revenue_forecasting'     => array(
				'name'        => __( 'Revenue Forecasting', 'recurio' ),
				'description' => __( 'AI-powered revenue predictions and trend analysis', 'recurio' ),
				'category'    => 'analytics',
			),
			'churn_prediction'        => array(
				'name'        => __( 'Churn Prediction', 'recurio' ),
				'description' => __( 'Identify at-risk customers before they cancel', 'recurio' ),
				'category'    => 'analytics',
			),
			'revenue_goals'           => array(
				'name'        => __( 'Revenue Goals', 'recurio' ),
				'description' => __( 'Set and track revenue targets with progress visualization', 'recurio' ),
				'category'    => 'revenue',
			),
			'advanced_portal'         => array(
				'name'        => __( 'Advanced Customer Portal', 'recurio' ),
				'description' => __( 'Payment method and address management for customers', 'recurio' ),
				'category'    => 'portal',
			),
			'custom_email_templates'  => array(
				'name'        => __( 'Custom Email Templates', 'recurio' ),
				'description' => __( 'Visual email editor with dynamic content', 'recurio' ),
				'category'    => 'emails',
			),
			'subscription_upgrades'   => array(
				'name'        => __( 'Subscription Upgrades', 'recurio' ),
				'description' => __( 'Allow customers to upgrade/downgrade plans with proration', 'recurio' ),
				'category'    => 'lifecycle',
			),
			'bulk_operations'         => array(
				'name'        => __( 'Bulk Operations', 'recurio' ),
				'description' => __( 'Mass update, pause, or cancel subscriptions', 'recurio' ),
				'category'    => 'management',
			),
			'advanced_reporting'      => array(
				'name'        => __( 'Advanced Reporting', 'recurio' ),
				'description' => __( 'Custom report builder with scheduled delivery', 'recurio' ),
				'category'    => 'reporting',
			),
			'webhooks'                => array(
				'name'        => __( 'Webhooks', 'recurio' ),
				'description' => __( 'Trigger external services on subscription events', 'recurio' ),
				'category'    => 'integrations',
			),
			'payment_tokenization'    => array(
				'name'        => __( 'Payment Tokenization', 'recurio' ),
				'description' => __( 'Secure payment method storage for recurring billing', 'recurio' ),
				'category'    => 'payments',
			),
		);
	}
}

/**
 * Helper function to check if Pro is active
 *
 * @return bool True if Pro is active
 */
function recurio_is_pro_active() {
	return Recurio_Pro_Manager::get_instance()->is_pro_active();
}

/**
 * Helper function to check if Pro license is valid
 *
 * @return bool True if license is valid
 */
function recurio_is_pro_licensed() {
	return Recurio_Pro_Manager::get_instance()->is_license_valid();
}

/**
 * Helper function to get Pro version
 *
 * @return string Pro version or empty string
 */
function recurio_get_pro_version() {
	return apply_filters( 'recurio_pro_version', '' );
}

/**
 * Helper function to check if a Pro feature is available
 *
 * @param string $feature Feature name
 * @return bool True if feature is available
 */
function recurio_pro_feature_available( $feature ) {
	return Recurio_Pro_Manager::get_instance()->is_feature_available( $feature );
}

/**
 * Helper function to get Pro badge HTML
 *
 * @param string $type Badge type
 * @return string HTML for Pro badge
 */
function recurio_get_pro_badge( $type = 'badge' ) {
	return Recurio_Pro_Manager::get_instance()->get_pro_badge( $type );
}

/**
 * Helper function to get upgrade URL
 *
 * @param string $feature Feature name
 * @return string Upgrade URL
 */
function recurio_get_upgrade_url( $feature = '' ) {
	return Recurio_Pro_Manager::get_instance()->get_upgrade_url( $feature );
}

/**
 * Helper function to get upgrade notice HTML
 *
 * @param string $feature Feature name
 * @param string $message Custom message
 * @return string HTML for upgrade notice
 */
function recurio_get_upgrade_notice( $feature, $message = '' ) {
	return Recurio_Pro_Manager::get_instance()->get_upgrade_notice( $feature, $message );
}
