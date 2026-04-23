<?php
/**
 * Hooks Manager - Central hub for all Pro extension hooks
 *
 * @package Recurio
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recurio Hooks Manager Class
 *
 * Provides organized hooks and filters for Pro plugin to extend functionality.
 * This class doesn't implement features - it just defines extension points.
 *
 * @since 1.1.0
 */
class Recurio_Hooks_Manager {

	/**
	 * Singleton instance
	 *
	 * @var Recurio_Hooks_Manager|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return Recurio_Hooks_Manager
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
		// Hook manager is passive - just documents available hooks
	}

	/**
	 * Subscription lifecycle hooks
	 *
	 * These hooks are triggered at key points in subscription lifecycle.
	 * Pro plugin can hook into these to add advanced features.
	 */
	public static function subscription_hooks() {
		/**
		 * Fires before a subscription is created
		 *
		 * @since 1.1.0
		 * @param array $subscription_data Subscription data array
		 */
		do_action( 'recurio_before_subscription_created', $subscription_data = array() );

		/**
		 * Fires after a subscription is created
		 *
		 * @since 1.1.0
		 * @param int   $subscription_id   Subscription ID
		 * @param array $subscription_data Subscription data array
		 */
		do_action( 'recurio_after_subscription_created', $subscription_id = 0, $subscription_data = array() );

		/**
		 * Fires before subscription status changes
		 *
		 * @since 1.1.0
		 * @param int    $subscription_id Subscription ID
		 * @param string $old_status      Old status
		 * @param string $new_status      New status
		 */
		do_action( 'recurio_before_subscription_status_change', $subscription_id = 0, $old_status = '', $new_status = '' );

		/**
		 * Fires after subscription status changes
		 *
		 * @since 1.1.0
		 * @param int    $subscription_id Subscription ID
		 * @param string $old_status      Old status
		 * @param string $new_status      New status
		 */
		do_action( 'recurio_after_subscription_status_change', $subscription_id = 0, $old_status = '', $new_status = '' );

		/**
		 * Filter subscription data before save
		 *
		 * @since 1.1.0
		 * @param array $subscription_data Subscription data to save
		 * @param int   $subscription_id   Subscription ID (0 for new)
		 */
		$subscription_data = apply_filters( 'recurio_subscription_data_before_save', $subscription_data = array(), $subscription_id = 0 );

		/**
		 * Filter subscription query parameters
		 *
		 * @since 1.1.0
		 * @param array $query_params Query parameters
		 */
		$query_params = apply_filters( 'recurio_subscription_query_params', $query_params = array() );
	}

	/**
	 * Payment processing hooks
	 *
	 * Hooks for extending payment functionality - Pro uses these for automation.
	 */
	public static function payment_hooks() {
		/**
		 * Fires before payment is processed
		 *
		 * @since 1.1.0
		 * @param int   $subscription_id Subscription ID
		 * @param float $amount          Payment amount
		 */
		do_action( 'recurio_before_payment_processed', $subscription_id = 0, $amount = 0.0 );

		/**
		 * Fires after payment is successfully processed
		 *
		 * @since 1.1.0
		 * @param int    $subscription_id Subscription ID
		 * @param float  $amount          Payment amount
		 * @param string $transaction_id  Transaction ID
		 */
		do_action( 'recurio_after_payment_processed', $subscription_id = 0, $amount = 0.0, $transaction_id = '' );

		/**
		 * Fires when payment fails
		 *
		 * @since 1.1.0
		 * @param int    $subscription_id Subscription ID
		 * @param float  $amount          Payment amount
		 * @param string $error_message   Error message
		 */
		do_action( 'recurio_payment_failed', $subscription_id = 0, $amount = 0.0, $error_message = '' );

		/**
		 * Filter available payment methods for subscription
		 *
		 * @since 1.1.0
		 * @param array $methods         Available payment methods
		 * @param int   $subscription_id Subscription ID
		 */
		$methods = apply_filters( 'recurio_available_payment_methods', $methods = array(), $subscription_id = 0 );

		/**
		 * Filter payment processing mode (manual vs automated)
		 *
		 * @since 1.1.0
		 * @param string $mode            Processing mode ('manual' or 'automated')
		 * @param int    $subscription_id Subscription ID
		 */
		$mode = apply_filters( 'recurio_payment_processing_mode', $mode = 'manual', $subscription_id = 0 );
	}

	/**
	 * Analytics and reporting hooks
	 *
	 * Hooks for Pro to add advanced analytics features.
	 */
	public static function analytics_hooks() {
		/**
		 * Filter dashboard metrics
		 *
		 * @since 1.1.0
		 * @param array $metrics Dashboard metrics array
		 */
		$metrics = apply_filters( 'recurio_dashboard_metrics', $metrics = array() );

		/**
		 * Filter analytics data before display
		 *
		 * @since 1.1.0
		 * @param array  $analytics Analytics data
		 * @param string $type      Analytics type (revenue, churn, etc.)
		 */
		$analytics = apply_filters( 'recurio_analytics_data', $analytics = array(), $type = '' );

		/**
		 * Add custom analytics tabs
		 *
		 * @since 1.1.0
		 * @param array $tabs Analytics tabs
		 */
		$tabs = apply_filters( 'recurio_analytics_tabs', $tabs = array() );

		/**
		 * Fires after analytics are calculated
		 *
		 * @since 1.1.0
		 * @param array $analytics Calculated analytics
		 */
		do_action( 'recurio_analytics_calculated', $analytics = array() );
	}

	/**
	 * Customer portal hooks
	 *
	 * Hooks for Pro to enhance customer portal.
	 */
	public static function portal_hooks() {
		/**
		 * Filter portal template path
		 *
		 * @since 1.1.0
		 * @param string $template Template path
		 * @param string $view     View name
		 */
		$template = apply_filters( 'recurio_portal_template', $template = '', $view = '' );

		/**
		 * Fires before portal content is rendered
		 *
		 * @since 1.1.0
		 * @param string $view View being rendered
		 */
		do_action( 'recurio_before_portal_content', $view = '' );

		/**
		 * Fires after portal content is rendered
		 *
		 * @since 1.1.0
		 * @param string $view View being rendered
		 */
		do_action( 'recurio_after_portal_content', $view = '' );

		/**
		 * Add custom portal actions
		 *
		 * @since 1.1.0
		 * @param array $actions Available actions
		 * @param int   $subscription_id Subscription ID
		 */
		$actions = apply_filters( 'recurio_portal_actions', $actions = array(), $subscription_id = 0 );
	}

	/**
	 * Email notification hooks
	 *
	 * Hooks for Pro to add custom email templates and campaigns.
	 */
	public static function email_hooks() {
		/**
		 * Filter email template content
		 *
		 * @since 1.1.0
		 * @param string $content      Email content
		 * @param string $template     Template name
		 * @param array  $template_vars Template variables
		 */
		$content = apply_filters( 'recurio_email_template_content', $content = '', $template = '', $template_vars = array() );

		/**
		 * Filter email subject
		 *
		 * @since 1.1.0
		 * @param string $subject      Email subject
		 * @param string $template     Template name
		 * @param array  $template_vars Template variables
		 */
		$subject = apply_filters( 'recurio_email_subject', $subject = '', $template = '', $template_vars = array() );

		/**
		 * Fires before email is sent
		 *
		 * @since 1.1.0
		 * @param string $to           Recipient email
		 * @param string $subject      Email subject
		 * @param string $message      Email message
		 */
		do_action( 'recurio_before_email_sent', $to = '', $subject = '', $message = '' );
	}

	/**
	 * Settings and configuration hooks
	 *
	 * Hooks for Pro to add advanced settings.
	 */
	public static function settings_hooks() {
		/**
		 * Add custom settings sections
		 *
		 * @since 1.1.0
		 * @param array $sections Settings sections
		 */
		$sections = apply_filters( 'recurio_settings_sections', $sections = array() );

		/**
		 * Add custom settings fields
		 *
		 * @since 1.1.0
		 * @param array  $fields  Settings fields
		 * @param string $section Section name
		 */
		$fields = apply_filters( 'recurio_settings_fields', $fields = array(), $section = '' );

		/**
		 * Filter settings before save
		 *
		 * @since 1.1.0
		 * @param array $settings Settings to save
		 */
		$settings = apply_filters( 'recurio_settings_before_save', $settings = array() );

		/**
		 * Fires after settings are saved
		 *
		 * @since 1.1.0
		 * @param array $settings Saved settings
		 */
		do_action( 'recurio_settings_saved', $settings = array() );
	}

	/**
	 * REST API hooks
	 *
	 * Hooks for Pro to extend API endpoints.
	 */
	public static function api_hooks() {
		/**
		 * Register custom REST API routes
		 *
		 * @since 1.1.0
		 */
		do_action( 'recurio_register_rest_routes' );

		/**
		 * Filter REST API response
		 *
		 * @since 1.1.0
		 * @param array  $response REST response data
		 * @param string $endpoint Endpoint name
		 */
		$response = apply_filters( 'recurio_rest_api_response', $response = array(), $endpoint = '' );

		/**
		 * Filter REST API permissions
		 *
		 * @since 1.1.0
		 * @param bool   $allowed  Whether access is allowed
		 * @param string $endpoint Endpoint name
		 */
		$allowed = apply_filters( 'recurio_rest_api_permissions', $allowed = false, $endpoint = '' );
	}

	/**
	 * Admin UI hooks
	 *
	 * Hooks for Pro to add admin features.
	 */
	public static function admin_hooks() {
		/**
		 * Add custom admin menu items
		 *
		 * @since 1.1.0
		 * @param array $menu_items Admin menu items
		 */
		$menu_items = apply_filters( 'recurio_admin_menu_items', $menu_items = array() );

		/**
		 * Add custom dashboard widgets
		 *
		 * @since 1.1.0
		 * @param array $widgets Dashboard widgets
		 */
		$widgets = apply_filters( 'recurio_dashboard_widgets', $widgets = array() );

		/**
		 * Filter admin notices
		 *
		 * @since 1.1.0
		 * @param array $notices Admin notices
		 */
		$notices = apply_filters( 'recurio_admin_notices', $notices = array() );
	}
}
