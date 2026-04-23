<?php
/**
 * Admin Dashboard Class
 *
 * @package Recurio
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Recurio_Dashboard
{
    private static $instance = null;
    private $menu_slug = 'recurio';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Remove all notices on our pages
        add_action('in_admin_header', array($this, 'remove_admin_notices'), 1000);

        // Admin notices
        add_action('admin_notices', array($this, 'display_admin_notices'));

        // Dashboard widgets
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));

        // AJAX handlers for dashboard
        add_action('wp_ajax_recurio_get_dashboard_stats', array($this, 'ajax_get_dashboard_stats'));
        add_action('wp_ajax_recurio_get_recent_activity', array($this, 'ajax_get_recent_activity'));
    }

    public function add_admin_menu()
    {
        global $submenu;

        $capability = 'manage_woocommerce';
        $slug       = $this->menu_slug;

        $hook = add_menu_page(
            esc_html__('Recurio Subscriptions', 'recurio'),
            esc_html__('Recurio', 'recurio'),
            $capability,
            $slug,
            [ $this, 'render_dashboard_page' ],
            RECURIO_PLUGIN_URL . '/assets/images/menu-icon.png',
            56
        );

        if (current_user_can($capability)) {
            $submenu[ $slug ][] = array( esc_html__('Dashboard', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/dashboard' );
            $submenu[ $slug ][] = array( esc_html__('Subscriptions', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/subscriptions' );
            $submenu[ $slug ][] = array( esc_html__('Customers', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/customers' );
            $submenu[ $slug ][] = array( esc_html__('Settings', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/settings' );
            $submenu[ $slug ][] = array( esc_html__('Reports', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/reports' );
            if ( !recurio_is_pro_active() ) {
                $submenu[ $slug ][] = array( '<span style="color: #00a32a;">⭐ ' . esc_html__( 'Upgrade to Pro', 'recurio' ) . '</span>', $capability, 'admin.php?page=' . $slug . '#/upgrade-to-pro' );
            }
        }

        // Initialize under menu hooks
        add_action('load-' . $hook, [ $this, 'init_under_menu_hooks' ]);
    }

    /**
     * Initialize under menu hooks
     */
    public function init_under_menu_hooks()
    {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook)
    {
        // Only load on our plugin pages
        if (! $this->is_recurio_admin_page($hook)) {
            return;
        }

        // Check if we're on the Vue app page
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'recurio') !== false) {

            // Load Vue app assets
            Recurio_Vue_App::get_instance()->enqueue_assets();
        }

        // Enqueue admin styles
        wp_enqueue_style(
            'recurio-admin',
            RECURIO_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            RECURIO_VERSION
        );

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

        // Enqueue admin scripts
        wp_enqueue_script(
            'recurio-admin',
            RECURIO_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            RECURIO_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script(
            'recurio-admin', 'recurioAdmin', array(
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('recurio_admin_nonce'),
                'apiUrl'       => rest_url('recurio/v1'),
                'dashboardUrl' => admin_url('admin.php?page=' . $this->menu_slug),
                'i18n'         => array(
                    'confirmCancel' => esc_html__('Are you sure you want to cancel this subscription?', 'recurio'),
                    'confirmPause'  => esc_html__('Are you sure you want to pause this subscription?', 'recurio'),
                    'confirmResume' => esc_html__('Are you sure you want to resume this subscription?', 'recurio'),
                    'success'       => esc_html__('Success', 'recurio'),
                    'error'         => esc_html__('Error', 'recurio'),
                    'loading'       => esc_html__('Loading...', 'recurio')
                )
            )
        );
    }

    /**
     * Check if current page is a UWS admin page
     */
    private function is_recurio_admin_page($hook)
    {
        // Check if it's the main dashboard page or any of its submenus
        return (strpos($hook, 'recurio') !== false ||
                $hook === 'toplevel_page_recurio' ||
                strpos($hook, 'recurio_page') !== false
            );
    }

    /**
     * Remove admin notices on our pages
     */
    public function remove_admin_notices()
    {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'recurio') !== false) {
            // Remove all admin notices
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');

            // Re-add our own notices if needed
            add_action('admin_notices', array($this, 'display_admin_notices'));
        }
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page()
    {
        $this->render_app_page();
    }

    /**
     * Render the Vue app page (unified renderer for all pages)
     */
    public function render_app_page()
    {
        ?>
        <div class="wrap">
            <div id="recurio-dashboard-app">
                <!-- Vue app will mount here -->
                <div class="recurio-loading">
                    <div class="recurio-spinner-container">
                        <svg class="recurio-spinner-svg" width="48" height="48" viewBox="0 0 48 48">
                            <circle class="recurio-spinner-track" cx="24" cy="24" r="20" fill="none" stroke="#ddd" stroke-width="3"/>
                            <circle class="recurio-spinner-fill" cx="24" cy="24" r="20" fill="none" stroke="#3498db" stroke-width="3" stroke-dasharray="126" stroke-dashoffset="32" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <p><?php echo esc_html__('Loading Dashboard...', 'recurio'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices()
    {
        // Check if WooCommerce is active
        if (! class_exists('WooCommerce')) {
            ?>
            <div class="notice notice-error">
                <p><?php echo esc_html__('Recurio requires WooCommerce to be installed and active.', 'recurio'); ?></p>
            </div>
            <?php
        }
        
        // Check for successful actions
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display, no sensitive operations
        if (isset($_GET['recurio_message'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display, no sensitive operations
            $message = sanitize_text_field(wp_unslash($_GET['recurio_message']));
            $type    = isset($_GET['recurio_type']) ? sanitize_text_field(wp_unslash($_GET['recurio_type'])) : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin notice display, no sensitive operations
            ?>
            <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Add dashboard widgets
     */
    public function add_dashboard_widgets()
    {
        wp_add_dashboard_widget(
            'recurio_dashboard_widget',
            __('Subscription Overview', 'recurio'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget()
    {
        $subscription_engine = Recurio_Subscription_Engine::get_instance();
        $stats = $subscription_engine->get_statistics();
        ?>
        <div class="recurio-dashboard-widget">
            <div class="recurio-stats-grid">
                <div class="recurio-stat">
                    <span class="recurio-stat-label"><?php echo esc_html__('Active Subscriptions', 'recurio'); ?></span>
                    <span class="recurio-stat-value"><?php echo esc_html($stats['active']); ?></span>
                </div>
                <div class="recurio-stat">
                    <span class="recurio-stat-label"><?php echo esc_html__('Monthly Recurring Revenue', 'recurio'); ?></span>
                    <span class="recurio-stat-value"><?php echo wp_kses_post(wc_price($stats['mrr'])); ?></span>
                </div>
                <div class="recurio-stat">
                    <span class="recurio-stat-label"><?php echo esc_html__('Total Subscriptions', 'recurio'); ?></span>
                    <span class="recurio-stat-value"><?php echo esc_html($stats['total']); ?></span>
                </div>
                <div class="recurio-stat">
                    <span class="recurio-stat-label"><?php echo esc_html__('Annual Recurring Revenue', 'recurio'); ?></span>
                    <span class="recurio-stat-value"><?php echo wp_kses_post(wc_price($stats['arr'])); ?></span>
                </div>
            </div>
            <p class="recurio-dashboard-link">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug)); ?>" class="button button-primary">
                    <?php echo esc_html__('View Full Dashboard', 'recurio'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX handler for dashboard stats
     */
    public function ajax_get_dashboard_stats()
    {
        check_ajax_referer('recurio_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions', 'recurio'));
        }
        
        $subscription_engine = Recurio_Subscription_Engine::get_instance();
        $stats = $subscription_engine->get_statistics();
        
        wp_send_json_success($stats);
    }

    /**
     * AJAX handler for recent activity
     */
    public function ajax_get_recent_activity()
    {
        check_ajax_referer('recurio_admin_nonce', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions', 'recurio'));
        }
        
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query needed for dashboard management
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching not appropriate for real-time dashboard data
        $recent_events = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}recurio_subscription_events
            ORDER BY created_at DESC
            LIMIT 10"
        );
        
        wp_send_json_success($recent_events);
    }
}
