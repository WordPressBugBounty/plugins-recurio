<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Main plugin class
class Recurio {

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
		if ( ! function_exists('is_plugin_active') ){ include_once( ABSPATH . 'wp-admin/includes/plugin.php' ); }
		
		// Load installer classes early — both must be available before the activation hook fires.
		require_once RECURIO_PLUGIN_DIR . 'includes/install/class-installer.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/install/class-db-migrations.php';

		add_action( 'plugins_loaded', array( $this, 'load_plugin' ) );
		add_action( 'init', array( $this, 'init' ) );

		// Delegate activation / deactivation to Recurio_Installer.
		register_activation_hook( RECURIO_PLUGIN_FILE, array( 'Recurio_Installer', 'activate' ) );
		register_deactivation_hook( RECURIO_PLUGIN_FILE, array( 'Recurio_Installer', 'deactivate' ) );
	}

	public function load_plugin() {

		// Check WooCommerce
        if ( !did_action( 'woocommerce_loaded' ) ) {
            add_action('admin_notices', [ $this, 'admin_notice_missing_woocommerce' ] );
            return;
        }

		// Run any outstanding schema migrations.
		Recurio_DB_Migrations::maybe_upgrade_database();

		// Load required files.
		$this->load_includes();

		// Initialize components.
		$this->init_components();
	}

	public function admin_notice_missing_woocommerce(){
		if( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        $woocommerce = 'woocommerce/woocommerce.php';
        if( $this->is_plugins_install( $woocommerce ) ) {
            $button['url'] = wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . $woocommerce . '&amp;plugin_status=all&amp;paged=1&amp;s', 'activate-plugin_' . $woocommerce );
            $button['text'] = __( 'Activate WooCommerce', 'recurio' );
            $message = sprintf( __( '%1$sRecurio%2$s requires %1$s"WooCommerce"%2$s plugin to be active. Please activate WooCommerce to continue.', 'recurio' ), '<strong>', '</strong>');
        }else{
            $button['url']  = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=woocommerce' ), 'install-plugin_woocommerce' );
            $button['text'] = __( 'Install WooCommerce', 'recurio' );
            $message = sprintf( __( '%1$sRecurio%2$s requires %1$s"WooCommerce"%2$s plugin to be installed and activated. Please install WooCommerce to continue.', 'recurio' ), '<strong>', '</strong>' );
        }

		?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo $message; ?><?php if( !empty($button['url']) ): ?><a class="button" style="margin-left: 5px;" href="<?php echo esc_url($button['url']); ?>"><?php echo esc_html($button['text']); ?></a><?php endif; ?></p>
			</div>
		<?php
	}

	public function is_plugins_install( $plugin_slug ) {
        $installed_plugins = get_plugins();
        return isset( $installed_plugins[ $plugin_slug ] );
    }

	public function init() {
		// Initialize post types, taxonomies, etc.
		do_action( 'recurio_init' );
	}

	private function load_includes() {
		require_once RECURIO_PLUGIN_DIR . 'includes/traits/singleton.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/Assets.php';
		// Pro Manager (must load first)
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-pro-manager.php';

		// Core classes
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-subscription-engine.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-email-custom-templates.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-email-notifications.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-billing-manager.php';
		require_once RECURIO_PLUGIN_DIR . 'includes/core/class-payment-methods.php';
		// Subscription Switching is a PRO feature - loaded by recurio-pro plugin

		// Admin classes
		require_once RECURIO_PLUGIN_DIR . 'includes/Admin.php';

		// WooCommerce integration
		if ( class_exists( 'WooCommerce' ) ) {
			if ( $this->is_request( 'admin' ) ) {
				require_once RECURIO_PLUGIN_DIR . 'includes/integrations/class-woocommerce-product.php';
			}
			require_once RECURIO_PLUGIN_DIR . 'includes/integrations/class-variable-subscriptions.php';
		}

		// Frontend classes
		require_once RECURIO_PLUGIN_DIR . 'includes/Frontend.php';

		// Integration classes
		require_once RECURIO_PLUGIN_DIR . 'includes/integrations/class-woocommerce.php';

		// API classes
		require_once RECURIO_PLUGIN_DIR . 'includes/Api.php';
	}

	private function init_components() {
		// Initialize Assets
		Recurio\Assets::get_instance();
		
		// Initialize Pro Manager first
		Recurio_Pro_Manager::get_instance();

		// Initialize core components
		Recurio_Subscription_Engine::get_instance();
		Recurio_Email_Notifications::get_instance();
		Recurio_Billing_Manager::get_instance();
		Recurio_Payment_Methods::get_instance();

		// Initialize admin components
		if ( $this->is_request( 'admin' ) ) {
			Recurio\Admin::get_instance();
		}

		// Initialize frontend components
		if ( $this->is_request( 'frontend' ) ) {
			Recurio\Frontend::get_instance();
		}

		// Initialize integrations
		Recurio_WooCommerce_Integration::get_instance();

		// Initialize API
		if($this->is_request( 'rest' )){
			Recurio\Api::get_instance();
		}
	}

	/**
     * Check if the current request is a REST API request.
     * @return bool
     */
    private function is_rest_api_request() {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }

        $rest_prefix         = trailingslashit( rest_get_url_prefix() );
        $is_rest_api_request = ( false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix ) ); // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        return $is_rest_api_request;
    }

    /**
     * What type of request is this?
     *
     * @param  string $type admin, ajax, cron or frontend.
     */
    private function is_request( $type ) {
        switch ( $type ) {
            case 'admin' :
                return is_admin();

            case 'ajax' :
                return defined( 'DOING_AJAX' );

            case 'rest' :
                return ( defined( 'REST_REQUEST' ) || $this->is_rest_api_request() );

            case 'cron' :
                return defined( 'DOING_CRON' );

            case 'frontend' :
                return ( ! is_admin() || defined( 'DOING_AJAX' ) || ( ! empty( $_REQUEST['action'] ) && 'elementor' === $_REQUEST['action'] ) ) && ! defined( 'DOING_CRON' );
        }
    }

}