<?php
namespace Recurio;
use Recurio\Traits\Singleton;
use Recurio\Api;

/**
 * Admin class
 */
class Admin {

    use Singleton;

    /**
     * Initialize the class
     */
    public function __construct() {
        $this->remove_all_notices();
        $this->includes();
        $this->init();
    }

     /**
     * Include the controller classes
     *
     * @return void
     */
    private function includes() {
        if ( !class_exists( __NAMESPACE__ . '\admin\Menu'  ) ) {
            require_once __DIR__ . '/admin/Menu.php';
        }
        if ( !class_exists( __NAMESPACE__ . '\admin\Dashboard_Widget'  ) ) {
            require_once __DIR__ . '/admin/Dashboard_Widget.php';
        }
        if ( !class_exists( __NAMESPACE__ . '\admin\Pro_Upsell'  ) ) {
            require_once __DIR__ . '/admin/Pro_Upsell.php';
        }
        if ( !class_exists( 'Recurio\Api\Plans' ) ) {
            require_once __DIR__ . '/api/Plans.php';
        }
        if ( !class_exists( 'Recurio\Api\ChangeLog' ) ) {
            require_once __DIR__ . '/api/ChangeLog.php';
        }
    }

    /**
     * Admin Initilize
     *
     * @return void
     */
    public function init() {
        (new Admin\Menu())->init();
        (new Admin\Dashboard_Widget())->init();
        (new Admin\Pro_Upsell())->init();
        (new Api\Plans())->register_hooks();
        (new Api\ChangeLog())->register_hooks();

        // Clear the product-existence cache whenever a product is saved, trashed, or deleted.
        $clear_sub_transient = function() {
            delete_transient( 'recurio_has_subscription_products' );
        };
        add_action( 'save_post_product', $clear_sub_transient );
        add_action( 'before_delete_post', $clear_sub_transient );
        add_action( 'transition_post_status', function( $new_status, $old_status, $post ) use ( $clear_sub_transient ) {
            if ( get_post_type( $post ) === 'product' ) {
                $clear_sub_transient();
            }
        }, 10, 3 );
    }

    /**
     * [remove_all_notices] remove addmin notices
     * @return [void]
     */
    public function remove_all_notices(){
        add_action('in_admin_header', function (){
            $screen = get_current_screen();
            if ($screen && strpos($screen->id, 'recurio') !== false) {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
                // Re-attach our specific warning so it survives the purge.
                add_action('admin_notices', array( $this, 'display_no_gateways_warning' ), 5 );
            }
       }, 1000);
    }

    /**
     * Display an admin warning when no payment gateways are available for subscriptions.
     * Only shown when at least one subscription product exists and the current user
     * has the capability to act on the warning.
     */
    public function display_no_gateways_warning() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! function_exists( 'WC' ) ) {
            return;
        }

        // Avoid querying on every page load — cache result for 12 hours.
        $has_sub = get_transient( 'recurio_has_subscription_products' );
        if ( false === $has_sub ) {
            global $wpdb;
            $has_sub = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_recurio_subscription_enabled' AND meta_value = 'yes' LIMIT 1"
            );
            set_transient( 'recurio_has_subscription_products', $has_sub ?: '0', 12 * HOUR_IN_SECONDS );
        }

        if ( ! $has_sub || $has_sub === '0' ) {
            return;
        }

        $payment_methods = \Recurio_Payment_Methods::get_instance();
        $gateways        = $payment_methods->get_available_payment_gateways();

        if ( empty( $gateways ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' .
                esc_html__( 'Recurio: No payment gateways are currently available for subscription products. Please enable at least one compatible gateway in WooCommerce → Settings → Payments.', 'recurio' ) .
                '</p></div>';
        }
    }

}