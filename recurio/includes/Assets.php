<?php
namespace Recurio;
use Recurio\Traits\Singleton;
/**
 * Vue App Loader Class
 *
 * @package    Recurio
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Assets
 *
 * Handles Vue application asset registration and enqueueing.
 */
class Assets {
    use Singleton;

    /** Base URL for assets/admin/ */
    private $admin_assets_url;

    /** Whether the Vite dev server appears to be running */
    private $is_dev;

    const DEV_SERVER = 'http://localhost:5173';

    private function __construct() {
        $this->admin_assets_url = RECURIO_PLUGIN_URL . 'assets/admin';
        $this->is_dev           = $this->is_vite_running();

        if ( is_admin() ) {
            add_action( 'admin_init', [ $this, 'register_assets' ] );
        } else {
            add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ], 5 );
        }
        
        add_filter( 'script_loader_tag', [ $this, 'add_type_module' ], 10, 3 );
    }

    /**
     * All scripts.
     *
     * @return array
     */
    private function get_scripts() {
        if ( $this->is_dev ) {
            return array(
                'recurio-vite-client' => array(
                    'src'       => self::DEV_SERVER . '/@vite/client',
                    'deps'      => array(),
                    'version'   => null,
                    'in_footer' => true,
                ),
                'recurio-vue-app' => array(
                    'src'       => self::DEV_SERVER . '/src/main.js',
                    'deps'      => array( 'recurio-vite-client' ),
                    'version'   => null,
                    'in_footer' => true,
                ),
            );
        }

        return array(
            'recurio-element-plus' => array(
                'src'       => $this->admin_assets_url . '/js/element-plus.js',
                'deps'      => array(),
                'version'   => RECURIO_VERSION,
                'in_footer' => true,
            ),
            'recurio-vendor' => array(
                'src'       => $this->admin_assets_url . '/js/vendor.js',
                'deps'      => array( 'recurio-element-plus' ),
                'version'   => RECURIO_VERSION,
                'in_footer' => true,
            ),
            'recurio-vue-app' => array(
                'src'       => $this->admin_assets_url . '/js/admin.js',
                'deps'      => array( 'recurio-element-plus', 'recurio-vendor' ),
                'version'   => RECURIO_VERSION,
                'in_footer' => true,
            ),
        );
    }

    /**
     * All admin styles.
     *
     * @return array
     */
    private function get_styles() {
        if ( $this->is_dev ) {
            return array(
                'recurio-admin' => array(
                    'src'     => $this->admin_assets_url . '/css/admin.css',
                    'deps'    => array(),
                    'version' => RECURIO_VERSION,
                ),
            ); 
        }

        return array(
            'recurio-vue-app-style' => array(
                'src'     => $this->admin_assets_url . '/css/main.css',
                'deps'    => array(),
                'version' => RECURIO_VERSION,
            ),
            'recurio-admin' => array(
                'src'     => $this->admin_assets_url . '/css/admin.css',
                'deps'    => array(),
                'version' => RECURIO_VERSION,
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Registration helpers
    // -------------------------------------------------------------------------

    public function register_assets() {
        $this->register_scripts( $this->get_scripts() );
        $this->register_styles( $this->get_styles() );
    }

    private function register_scripts( array $scripts ) {
        foreach ( $scripts as $handle => $script ) {

            $deps      = isset( $script['deps'] ) ? $script['deps'] : [];
            $in_footer = isset( $script['in_footer'] ) ? $script['in_footer'] : false;
            $version   = isset( $script['version'] ) ? $script['version'] : RECURIO_VERSION;

            wp_register_script( $handle, $script['src'], $deps, $version, $in_footer );
        }
    }

    private function register_styles( array $styles ) {
        foreach ( $styles as $handle => $style ) {

            $deps      = isset( $style['deps'] ) ? $style['deps'] : [];
            $version   = isset( $style['version'] ) ? $style['version'] : RECURIO_VERSION;

            wp_register_style( $handle, $style['src'], $deps, $version, false );
        }
    }

    /**
     * Enqueue assets and pass data to the JS app.
     */
    public function enqueue_admin_assets() {
        wp_enqueue_script( 'recurio-vue-app' );
        wp_enqueue_style( 'recurio-vue-app-style' );

        //Enqueue admin style
        wp_enqueue_style( 'recurio-admin' );

        $current_user     = wp_get_current_user();
        $user_display_name = ! empty( $current_user->first_name )
            ? $current_user->first_name
            : $current_user->display_name;

        wp_localize_script( 'recurio-vue-app', 'recurioData', array(
            'apiUrl'      => rest_url( 'recurio/v1' ),
            'rest_url'    => rest_url( 'recurio/v1' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'licenseNonce'=> wp_create_nonce( 'recuriopro_license_r' ),
            'adminUrl'    => admin_url(),
            'pluginUrl'   => RECURIO_PLUGIN_URL,
            'currentUser' => array(
                'id'           => get_current_user_id(),
                'name'         => $user_display_name,
                'fullName'     => $current_user->display_name,
                'firstName'    => $current_user->first_name,
                'lastName'     => $current_user->last_name,
                'email'        => $current_user->user_email,
                'capabilities' => $current_user->allcaps,
            ),
            'settings'    => get_option( 'recurio_settings', array() ),
            'currency'    => array(
                'code'               => get_woocommerce_currency(),
                'symbol'             => get_woocommerce_currency_symbol(),
                'position'           => get_option( 'woocommerce_currency_pos' ),
                'thousand_separator' => wc_get_price_thousand_separator(),
                'decimal_separator'  => wc_get_price_decimal_separator(),
                'decimals'           => wc_get_price_decimals(),
            ),
            'dateFormat'  => get_option( 'date_format' ),
            'timeFormat'  => get_option( 'time_format' ),
            'timezone'    => wp_timezone_string(),
            'locale'      => get_locale(),
            'i18n'        => $this->get_translations(),
            'pro'         => array(
                'active'     => recurio_is_pro_active(),
                'licensed'   => recurio_is_pro_licensed(),
                'version'    => recurio_get_pro_version(),
                'upgradeUrl' => admin_url( 'admin.php?page=recurio#/upgrade-to-pro' ),
            ),
        ) );

        // Add inline CSS to hide notices and title on our pages
        $custom_css = '
            /* Hide all WordPress notices on UWS pages */
            .notice, .notice-error, .notice-warning, .notice-success, .notice-info, .updated, .error, .update-nag {
                display: none !important;
            }
            /* Hide the page title */
            .wrap > h1 {
                display: none !important;
            }
            /* Adjust spacing */
            .wrap {
                margin-top: 0 !important;
            }
            #recurio-dashboard-app {
                margin-top: 20px;
            }
        ';
        wp_add_inline_style('recurio-admin', $custom_css);
    }

    /**
     * Add type="module" to Vue / Vite script tags.
     */
    public function add_type_module( $tag, $handle, $src ) {
        $module_handles = array( 'recurio-vue-app', 'recurio-vite-client' );
        if ( in_array( $handle, $module_handles, true ) ) {
            $tag = str_replace( '<script ', '<script type="module" ', $tag );
        }
        return $tag;
    }

    /**
     * Check whether the Vite dev server is running on localhost:5173.
     */
    private function is_vite_running() {
        if ( ! function_exists( 'curl_init' ) ) {
            return false;
        }
        $handle = curl_init( self::DEV_SERVER );
        curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $handle, CURLOPT_NOBODY, true );
        curl_setopt( $handle, CURLOPT_TIMEOUT, 1 );
        curl_exec( $handle );
        $error = curl_errno( $handle );
        curl_close( $handle );
        return ! $error;
    }

    /**
     * Translations passed to the Vue app via recurioData.i18n.
     */
    private function get_translations() {
        return array(
            'general' => array(
                'save'    => esc_html__( 'Save', 'recurio' ),
                'cancel'  => esc_html__( 'Cancel', 'recurio' ),
                'delete'  => esc_html__( 'Delete', 'recurio' ),
                'edit'    => esc_html__( 'Edit', 'recurio' ),
                'view'    => esc_html__( 'View', 'recurio' ),
                'search'  => esc_html__( 'Search', 'recurio' ),
                'filter'  => esc_html__( 'Filter', 'recurio' ),
                'export'  => esc_html__( 'Export', 'recurio' ),
                'import'  => esc_html__( 'Import', 'recurio' ),
                'loading' => esc_html__( 'Loading...', 'recurio' ),
                'noData'  => esc_html__( 'No data available', 'recurio' ),
                'confirm' => esc_html__( 'Are you sure?', 'recurio' ),
                'success' => esc_html__( 'Success!', 'recurio' ),
                'error'   => esc_html__( 'Error!', 'recurio' ),
                'warning' => esc_html__( 'Warning!', 'recurio' ),
                'info'    => esc_html__( 'Info', 'recurio' ),
            ),
            'subscriptions' => array(
                'title'           => esc_html__( 'Subscriptions', 'recurio' ),
                'newSubscription' => esc_html__( 'New Subscription', 'recurio' ),
                'editSubscription'=> esc_html__( 'Edit Subscription', 'recurio' ),
                'status'          => esc_html__( 'Status', 'recurio' ),
                'active'          => esc_html__( 'Active', 'recurio' ),
                'paused'          => esc_html__( 'Paused', 'recurio' ),
                'cancelled'       => esc_html__( 'Cancelled', 'recurio' ),
                'pending'         => esc_html__( 'Pending', 'recurio' ),
                'expired'         => esc_html__( 'Expired', 'recurio' ),
                'customer'        => esc_html__( 'Customer', 'recurio' ),
                'product'         => esc_html__( 'Product', 'recurio' ),
                'nextPayment'     => esc_html__( 'Next Payment', 'recurio' ),
                'billingPeriod'   => esc_html__( 'Billing Period', 'recurio' ),
                'amount'          => esc_html__( 'Amount', 'recurio' ),
                'actions'         => esc_html__( 'Actions', 'recurio' ),
                'pause'           => esc_html__( 'Pause', 'recurio' ),
                'resume'          => esc_html__( 'Resume', 'recurio' ),
                'cancel'          => esc_html__( 'Cancel', 'recurio' ),
                'bulkActions'     => esc_html__( 'Bulk Actions', 'recurio' ),
            ),
            'dashboard' => array(
                'title'                    => esc_html__( 'Dashboard', 'recurio' ),
                'overview'                 => esc_html__( 'Overview', 'recurio' ),
                'activeSubscriptions'      => esc_html__( 'Active Subscriptions', 'recurio' ),
                'monthlyRecurringRevenue'  => esc_html__( 'Monthly Recurring Revenue', 'recurio' ),
                'annualRecurringRevenue'   => esc_html__( 'Annual Recurring Revenue', 'recurio' ),
                'churnRate'                => esc_html__( 'Churn Rate', 'recurio' ),
                'customerLifetimeValue'    => esc_html__( 'Customer Lifetime Value', 'recurio' ),
                'recentActivity'           => esc_html__( 'Recent Activity', 'recurio' ),
                'revenueTrend'             => esc_html__( 'Revenue Trend', 'recurio' ),
                'subscriptionGrowth'       => esc_html__( 'Subscription Growth', 'recurio' ),
                'atRiskSubscriptions'      => esc_html__( 'At-Risk Subscriptions', 'recurio' ),
            ),
            'customers' => array(
                'title'        => esc_html__( 'Customers', 'recurio' ),
                'name'         => esc_html__( 'Name', 'recurio' ),
                'email'        => esc_html__( 'Email', 'recurio' ),
                'subscriptions'=> esc_html__( 'Subscriptions', 'recurio' ),
                'totalSpent'   => esc_html__( 'Total Spent', 'recurio' ),
                'joinDate'     => esc_html__( 'Join Date', 'recurio' ),
                'lastActivity' => esc_html__( 'Last Activity', 'recurio' ),
                'segment'      => esc_html__( 'Segment', 'recurio' ),
            ),
            'analytics' => array(
                'title'         => esc_html__( 'Analytics', 'recurio' ),
                'metrics'       => esc_html__( 'Metrics', 'recurio' ),
                'reports'       => esc_html__( 'Reports', 'recurio' ),
                'cohortAnalysis'=> esc_html__( 'Cohort Analysis', 'recurio' ),
                'retentionRate' => esc_html__( 'Retention Rate', 'recurio' ),
                'growthRate'    => esc_html__( 'Growth Rate', 'recurio' ),
                'forecast'      => esc_html__( 'Forecast', 'recurio' ),
            ),
            'settings' => array(
                'title'        => esc_html__( 'Settings', 'recurio' ),
                'general'      => esc_html__( 'General', 'recurio' ),
                'billing'      => esc_html__( 'Billing', 'recurio' ),
                'emails'       => esc_html__( 'Emails', 'recurio' ),
                'integrations' => esc_html__( 'Integrations', 'recurio' ),
                'advanced'     => esc_html__( 'Advanced', 'recurio' ),
                'saveSettings' => esc_html__( 'Save Settings', 'recurio' ),
                'settingsSaved'=> esc_html__( 'Settings saved successfully', 'recurio' ),
            ),
        );
    }
}
