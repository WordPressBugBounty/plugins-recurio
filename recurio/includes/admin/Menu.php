<?php
namespace Recurio\Admin;

class Menu {

    public $menupage = [];

    /**
     * Parent Menu Page Slug
     */
    const MENU_PAGE_SLUG = 'recurio';

    /**
     * Menu capability
     */
    const MENU_CAPABILITY = 'manage_woocommerce';

    /**
     * [init]
     */
    public function init() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
    }

    /**
     * Register Menu
     *
     * @return void
     */
    public function admin_menu(){
        global $submenu;

        $capability = self::MENU_CAPABILITY;
        $slug       = self::MENU_PAGE_SLUG;

        $hook = add_menu_page(
            esc_html__('Recurio Subscriptions', 'recurio'),
            esc_html__('Recurio', 'recurio'),
            $capability,
            $slug,
            [ $this, 'plugin_page' ],
            RECURIO_PLUGIN_URL . 'assets/images/menu-icon.png',
            56
        );

        if (current_user_can($capability)) {
            $submenu[ $slug ][] = array( esc_html__('Dashboard', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/dashboard' );
            $submenu[ $slug ][] = array( esc_html__('Subscriptions', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/subscriptions' );
            $submenu[ $slug ][] = array( esc_html__('Customers', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/customers' );
            $submenu[ $slug ][] = array( esc_html__('Plans', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/plans' );
            $submenu[ $slug ][] = array( esc_html__('Settings', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/settings' );
            $submenu[ $slug ][] = array( esc_html__('Reports', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/reports' );
            $submenu[ $slug ][] = array( esc_html__('Recommended Plugins', 'recurio'), $capability, 'admin.php?page=' . $slug . '#/recommended-plugins' );
            if ( !recurio_is_pro_active() ) {
                $submenu[ $slug ][] = array( '<span style="color: #00a32a;">⭐ ' . esc_html__( 'Upgrade to Pro', 'recurio' ) . '</span>', $capability, 'admin.php?page=' . $slug . '#/upgrade-to-pro' );
            }
        }

        // Initialize under menu hooks
        add_action('load-' . $hook, [ $this, 'init_hooks' ]);
    }

    /**
     * Initialize our hooks for the admin page
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook){

        // Media library for settings (e.g. email header image).
        wp_enqueue_media();

        // Classic editor (TinyMCE / Quicktags) for email HTML — same script stack as wp_editor().
        wp_enqueue_editor();

        // Load assets
        \Recurio\Assets::get_instance()->enqueue_admin_assets();
    }

    /**
     * Render dashboard page
     */
    public function plugin_page(){
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

}
