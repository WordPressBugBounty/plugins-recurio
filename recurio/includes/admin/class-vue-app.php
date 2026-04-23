<?php
/**
 * Vue App Loader Class
 *
 * @package    Recurio
 * @since      1.0.0
 * @version    7.4
 * @category   Class
 * @author     Your Name <your.email@example.com>
 * @license    GPL-2.0+
 * @link       https://example.com
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Recurio_Vue_App
 *
 * Handles Vue application asset loading and management
 */
class Recurio_Vue_App
{
    
    private static $instance = null;
    private $manifest_path;
    private $dist_url;
    private $dist_path;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->dist_path = RECURIO_PLUGIN_DIR . 'assets/dist/';
        $this->dist_url = RECURIO_PLUGIN_URL . 'assets/dist/';
        $this->manifest_path = $this->dist_path . 'manifest.json';
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Register scripts and styles
        add_action('admin_init', array($this, 'register_assets'));
        
        // Add type module to script tags
        add_filter('script_loader_tag', array($this, 'add_type_module'), 10, 3);
    }
    
    /**
     * Register Vue app assets
     */
    public function register_assets() {
        // Check if production build exists
        if (file_exists($this->manifest_path)) {
            $this->register_production_assets();
        } else {
            $this->register_development_assets();
        }
    }
    
    /**
     * Register production assets from manifest
     */
    private function register_production_assets() {
        $manifest = json_decode(file_get_contents($this->manifest_path), true);
        
        if (!$manifest) {
            return;
        }
        
        // Find main entry files
        foreach ($manifest as $key => $file) {
            if (strpos($key, 'main.js') !== false) {
                wp_register_script(
                    'recurio-vue-app',
                    $this->dist_url . $file['file'],
                    array(),
                    RECURIO_VERSION,
                    true
                );
                
                // Register CSS if exists
                if (isset($file['css'])) {
                    foreach ($file['css'] as $css) {
                        wp_register_style(
                            'recurio-vue-app-style',
                            $this->dist_url . $css,
                            array(),
                            RECURIO_VERSION
                        );
                    }
                }
                break;
            }
        }
    }
    
    /**
     * Register development assets
     */
    private function register_development_assets() {
        // For development, use Vite dev server
        $dev_server_url = 'http://localhost:3000';
        
        wp_register_script(
            'recurio-vue-app-vite',
            $dev_server_url . '/@vite/client',
            array(),
            RECURIO_VERSION,
            true
        );
        
        wp_register_script(
            'recurio-vue-app',
            $dev_server_url . '/src/main.js',
            array('recurio-vue-app-vite'),
            RECURIO_VERSION,
            true
        );
    }
    
    /**
     * Enqueue Vue app assets
     */
    public function enqueue_assets() {
        // Enqueue Vue app
        wp_enqueue_script('recurio-vue-app');
        wp_enqueue_style('recurio-vue-app-style');
        
        // Get current user and determine display name
        $current_user = wp_get_current_user();
        $user_display_name = !empty($current_user->first_name)
            ? $current_user->first_name
            : $current_user->display_name;

        // Localize data for Vue app
        wp_localize_script('recurio-vue-app', 'recurioData', array(
            'apiUrl' => rest_url('recurio/v1'),
            'rest_url' => rest_url('recurio/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'licenseNonce' => wp_create_nonce('recuriopro_license_r'),
            'adminUrl' => admin_url(),
            'pluginUrl' => RECURIO_PLUGIN_URL,
            'currentUser' => array(
                'id' => get_current_user_id(),
                'name' => $user_display_name,
                'fullName' => $current_user->display_name,
                'firstName' => $current_user->first_name,
                'lastName' => $current_user->last_name,
                'email' => $current_user->user_email,
                'capabilities' => $current_user->allcaps
            ),
            'settings' => get_option('recurio_settings', array()),
            'currency' => array(
                'code' => get_woocommerce_currency(),
                'symbol' => get_woocommerce_currency_symbol(),
                'position' => get_option('woocommerce_currency_pos'),
                'thousand_separator' => wc_get_price_thousand_separator(),
                'decimal_separator' => wc_get_price_decimal_separator(),
                'decimals' => wc_get_price_decimals()
            ),
            'dateFormat' => get_option('date_format'),
            'timeFormat' => get_option('time_format'),
            'timezone' => wp_timezone_string(),
            'locale' => get_locale(),
            'i18n' => $this->get_translations(),
            'pro' => array(
                'active' => recurio_is_pro_active(),
                'licensed' => recurio_is_pro_licensed(),
                'version' => recurio_get_pro_version(),
                'upgradeUrl' => admin_url('admin.php?page=recurio#/upgrade-to-pro')
            )
        ));
        
        // Add inline styles for loading state (as fallback)
        wp_add_inline_style('recurio-admin', '');
    }
    
    /**
     * Add type="module" to Vue app scripts
     */
    public function add_type_module($tag, $handle, $src) {
        if (in_array($handle, array('recurio-vue-app', 'recurio-vue-app-vite'))) {
            $tag = str_replace('<script ', '<script type="module" ', $tag);
        }
        return $tag;
    }
    
    /**
     * Get translations for Vue app
     */
    private function get_translations() {
        return array(
            'general' => array(
                'save' => esc_html__('Save', 'recurio'),
                'cancel' => esc_html__('Cancel', 'recurio'),
                'delete' => esc_html__('Delete', 'recurio'),
                'edit' => esc_html__('Edit', 'recurio'),
                'view' => esc_html__('View', 'recurio'),
                'search' => esc_html__('Search', 'recurio'),
                'filter' => esc_html__('Filter', 'recurio'),
                'export' => esc_html__('Export', 'recurio'),
                'import' => esc_html__('Import', 'recurio'),
                'loading' => esc_html__('Loading...', 'recurio'),
                'noData' => esc_html__('No data available', 'recurio'),
                'confirm' => esc_html__('Are you sure?', 'recurio'),
                'success' => esc_html__('Success!', 'recurio'),
                'error' => esc_html__('Error!', 'recurio'),
                'warning' => esc_html__('Warning!', 'recurio'),
                'info' => esc_html__('Info', 'recurio')
            ),
            'subscriptions' => array(
                'title' => esc_html__('Subscriptions', 'recurio'),
                'newSubscription' => esc_html__('New Subscription', 'recurio'),
                'editSubscription' => esc_html__('Edit Subscription', 'recurio'),
                'status' => esc_html__('Status', 'recurio'),
                'active' => esc_html__('Active', 'recurio'),
                'paused' => esc_html__('Paused', 'recurio'),
                'cancelled' => esc_html__('Cancelled', 'recurio'),
                'pending' => esc_html__('Pending', 'recurio'),
                'expired' => esc_html__('Expired', 'recurio'),
                'customer' => esc_html__('Customer', 'recurio'),
                'product' => esc_html__('Product', 'recurio'),
                'nextPayment' => esc_html__('Next Payment', 'recurio'),
                'billingPeriod' => esc_html__('Billing Period', 'recurio'),
                'amount' => esc_html__('Amount', 'recurio'),
                'actions' => esc_html__('Actions', 'recurio'),
                'pause' => esc_html__('Pause', 'recurio'),
                'resume' => esc_html__('Resume', 'recurio'),
                'cancel' => esc_html__('Cancel', 'recurio'),
                'bulkActions' => esc_html__('Bulk Actions', 'recurio')
            ),
            'dashboard' => array(
                'title' => esc_html__('Dashboard', 'recurio'),
                'overview' => esc_html__('Overview', 'recurio'),
                'activeSubscriptions' => esc_html__('Active Subscriptions', 'recurio'),
                'monthlyRecurringRevenue' => esc_html__('Monthly Recurring Revenue', 'recurio'),
                'annualRecurringRevenue' => esc_html__('Annual Recurring Revenue', 'recurio'),
                'churnRate' => esc_html__('Churn Rate', 'recurio'),
                'customerLifetimeValue' => esc_html__('Customer Lifetime Value', 'recurio'),
                'recentActivity' => esc_html__('Recent Activity', 'recurio'),
                'revenueTrend' => esc_html__('Revenue Trend', 'recurio'),
                'subscriptionGrowth' => esc_html__('Subscription Growth', 'recurio'),
                'atRiskSubscriptions' => esc_html__('At-Risk Subscriptions', 'recurio')
            ),
            'customers' => array(
                'title' => esc_html__('Customers', 'recurio'),
                'name' => esc_html__('Name', 'recurio'),
                'email' => esc_html__('Email', 'recurio'),
                'subscriptions' => esc_html__('Subscriptions', 'recurio'),
                'totalSpent' => esc_html__('Total Spent', 'recurio'),
                'joinDate' => esc_html__('Join Date', 'recurio'),
                'lastActivity' => esc_html__('Last Activity', 'recurio'),
                'segment' => esc_html__('Segment', 'recurio')
            ),
            'analytics' => array(
                'title' => esc_html__('Analytics', 'recurio'),
                'metrics' => esc_html__('Metrics', 'recurio'),
                'reports' => esc_html__('Reports', 'recurio'),
                'cohortAnalysis' => esc_html__('Cohort Analysis', 'recurio'),
                'retentionRate' => esc_html__('Retention Rate', 'recurio'),
                'growthRate' => esc_html__('Growth Rate', 'recurio'),
                'forecast' => esc_html__('Forecast', 'recurio')
            ),
            'settings' => array(
                'title' => esc_html__('Settings', 'recurio'),
                'general' => esc_html__('General', 'recurio'),
                'billing' => esc_html__('Billing', 'recurio'),
                'emails' => esc_html__('Emails', 'recurio'),
                'integrations' => esc_html__('Integrations', 'recurio'),
                'advanced' => esc_html__('Advanced', 'recurio'),
                'saveSettings' => esc_html__('Save Settings', 'recurio'),
                'settingsSaved' => esc_html__('Settings saved successfully', 'recurio')
            )
        );
    }
}
