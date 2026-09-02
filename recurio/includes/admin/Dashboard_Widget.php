<?php
namespace Recurio\Admin;

/**
 * Dashboard widget Class
 *
 * @package Recurio
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dashboard_Widget{

    public function init(){
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widgets'));
    }

    /**
     * Add dashboard widgets
     */
    public function add_dashboard_widgets(){
        wp_add_dashboard_widget(
            'recurio_dashboard_widget',
            esc_html__( 'Subscription Overview', 'recurio' ),
            [ $this, 'render_dashboard_widget' ]
        );
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget(){
        $subscription_engine = \Recurio_Subscription_Engine::get_instance();
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
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . Menu::MENU_PAGE_SLUG)); ?>" class="button button-primary">
                    <?php echo esc_html__('View Full Dashboard', 'recurio'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}
