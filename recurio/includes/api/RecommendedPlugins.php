<?php
namespace Recurio\Api;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommended Plugins API
 *
 * Live wp.org lookup, install-state check, and one-click install/activate
 * for a curated list of companion plugins.
 *
 * Routes:
 *   GET  /recurio/v1/recommended-plugins
 *   POST /recurio/v1/recommended-plugins/install
 *   POST /recurio/v1/recommended-plugins/activate
 *
 * @package Recurio
 * @since   1.2.0
 */
class RecommendedPlugins {

	/** REST namespace. */
	private string $namespace = 'recurio/v1';

	// -------------------------------------------------------------------------
	// Plugin list (server-side source of truth / whitelist)
	// -------------------------------------------------------------------------

	/**
	 * Curated tabs of recommended plugins. `location` is the full plugin
	 * basename (slug/main-file.php) used by get_plugins()/is_plugin_active().
	 *
	 * @return array
	 */
	private function get_tabs(): array {
		return array(
			array(
				'key'     => 'recommended',
				'title'   => __( 'Recommended', 'recurio' ),
				'plugins' => array(
					array(
						'slug'     => 'woolentor-addons',
						'location' => 'woolentor-addons/woolentor_addons_elementor.php',
						'name'     => __( 'ShopLentor – All-in-One WooCommerce Growth & Store Enhancement Plugin', 'recurio' ),
					),
					array(
						'slug'     => 'support-genix-lite',
						'location' => 'support-genix-lite/support-genix-lite.php',
						'name'     => __( 'Support Genix – Helpdesk, AI Chatbot, Knowledge Base & Customer Support Ticketing System', 'recurio' ),
					),
					array(
						'slug'     => 'hashbar-wp-notification-bar',
						'location' => 'hashbar-wp-notification-bar/init.php',
						'name'     => __( 'Notification Bar for WordPress', 'recurio' ),
					),
					array(
						'slug'     => 'wp-plugin-manager',
						'location' => 'wp-plugin-manager/plugin-main.php',
						'name'     => __( 'WP Plugin Manager', 'recurio' ),
					),
					array(
						'slug'     => 'ht-contactform',
						'location' => 'ht-contactform/contact-form-widget-elementor.php',
						'name'     => __( 'HT Contact Form – Drag & Drop Form Builder for WordPress', 'recurio' ),
					),
					array(
						'slug'     => 'cookieray',
						'location' => 'cookieray/cookieray.php',
						'name'     => __( 'CookieRay – Cookie Banner for Cookie Consent (GDPR/CCPA Compliant)', 'recurio' ),
					),
				),
			),
			array(
				'key'     => 'woocommerce',
				'title'   => __( 'WooCommerce', 'recurio' ),
				'plugins' => array(
					array(
						'slug'     => 'woolentor-addons',
						'location' => 'woolentor-addons/woolentor_addons_elementor.php',
						'name'     => __( 'ShopLentor – All-in-One WooCommerce Growth & Store Enhancement Plugin', 'recurio' ),
					),
					array(
						'slug'     => 'whols',
						'location' => 'whols/whols.php',
						'name'     => __( 'Whols – Wholesale Prices and B2B Store Solution for WooCommerce', 'recurio' ),
					),
					array(
						'slug'     => 'swatchly',
						'location' => 'swatchly/swatchly.php',
						'name'     => __( 'Swatchly – Product Variation Swatches for WooCommerce', 'recurio' ),
					),
				),
			),
			array(
				'key'     => 'other',
				'title'   => __( 'Popular', 'recurio' ),
				'plugins' => array(
					array(
						'slug'     => 'ht-mega-for-elementor',
						'location' => 'ht-mega-for-elementor/htmega_addons_elementor.php',
						'name'     => __( 'HT Mega Addons for Elementor – Elementor Widgets & Template Builder', 'recurio' ),
					),
					array(
						'slug'     => 'kelune-crm',
						'location' => 'kelune-crm/kelune-crm.php',
						'name'     => __( 'Kelune CRM – Contact Management, Email Marketing, Newsletter & Marketing Automation', 'recurio' ),
					),
					array(
						'slug'     => 'wp-plugin-manager',
						'location' => 'wp-plugin-manager/plugin-main.php',
						'name'     => __( 'WP Plugin Manager', 'recurio' ),
					),
					array(
						'slug'     => 'ht-easy-google-analytics',
						'location' => 'ht-easy-google-analytics/ht-easy-google-analytics.php',
						'name'     => __( 'HT Easy GA4 ( Google Analytics 4 )', 'recurio' ),
					),
					array(
						'slug'     => 'cookieray',
						'location' => 'cookieray/cookieray.php',
						'name'     => __( 'CookieRay – Cookie Banner for Cookie Consent (GDPR/CCPA Compliant)', 'recurio' ),
					),
					array(
						'slug'     => 'insert-headers-and-footers-script',
						'location' => 'insert-headers-and-footers-script/init.php',
						'name'     => __( 'Insert Headers and Footers Code', 'recurio' ),
					),
					array(
						'slug'     => 'pixelavo',
						'location' => 'pixelavo/pixelavo.php',
						'name'     => __( 'Pixelavo – Server Side Tracking & Pixel + AI Ads Tools', 'recurio' ),
					),
					array(
						'slug'     => 'courseglade-lms',
						'location' => 'courseglade-lms/courseglade-lms.php',
						'name'     => __( 'CourseGlade LMS – Online Course & eLearning Platform', 'recurio' ),
					),
				),
			),
		);
	}

	/**
	 * Flat, deduped list of every entry across all tabs — the server-side
	 * whitelist used to validate install/activate requests.
	 *
	 * @return array Associative array keyed by slug.
	 */
	private function get_whitelist(): array {
		$whitelist = array();

		foreach ( $this->get_tabs() as $tab ) {
			foreach ( $tab['plugins'] as $plugin ) {
				$whitelist[ $plugin['slug'] ] = $plugin;
			}
		}

		return $whitelist;
	}

	// -------------------------------------------------------------------------
	// Routes
	// -------------------------------------------------------------------------

	public function register_routes(): void {

		register_rest_route(
			$this->namespace,
			'/recommended-plugins',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_recommended_plugins' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/recommended-plugins/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_recommended_plugin' ),
				'permission_callback' => array( $this, 'check_install_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/recommended-plugins/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'activate_recommended_plugin' ),
				'permission_callback' => array( $this, 'check_install_permission' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * Look up live wp.org plugin data by slug (not by author account), cached
	 * for a week. Slugs that plugins_api() can't resolve are simply absent.
	 *
	 * @param array $slugs Unique plugin slugs to look up.
	 * @return array Associative array keyed by slug.
	 */
	private function get_plugins_info( array $slugs ): array {
		if ( empty( $slugs ) ) {
			return array();
		}

		$slugs = array_values( array_unique( $slugs ) );
		sort( $slugs );

		$transient_key = 'recurio_rp_info_v2_' . md5( implode( ',', $slugs ) );
		$plugins_info  = get_transient( $transient_key );

		if ( false === $plugins_info ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$plugins_info = array();

			foreach ( $slugs as $slug ) {
				$plugin_info = plugins_api(
					'plugin_information',
					array(
						'slug'   => $slug,
						'fields' => array(
							'short_description' => true,
							'sections'           => false,
							'icons'              => true,
							'active_installs'    => true,
							'author'             => false,
							'versions'           => false,
							'ratings'            => false,
							'reviews'            => false,
							'banners'            => false,
							'compatibility'      => false,
							'homepage'           => false,
							'donate_link'        => false,
							'tags'               => false,
						),
					)
				);

				if ( is_wp_error( $plugin_info ) ) {
					continue;
				}

				$plugins_info[ $slug ] = array(
					'name'            => html_entity_decode( $plugin_info->name, ENT_QUOTES, 'UTF-8' ),
					'icons'           => (array) $plugin_info->icons,
					'description'     => html_entity_decode( wp_strip_all_tags( $plugin_info->short_description ), ENT_QUOTES, 'UTF-8' ),
					'active_installs' => $plugin_info->active_installs,
				);
			}

			set_transient( $transient_key, $plugins_info, WEEK_IN_SECONDS );
		}

		return $plugins_info;
	}

	public function get_recommended_plugins(): WP_REST_Response {
		$tabs      = $this->get_tabs();
		$whitelist = $this->get_whitelist();
		$live_info = $this->get_plugins_info( array_keys( $whitelist ) );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();

		$response_tabs = array();

		foreach ( $tabs as $tab ) {
			$items = array();

			foreach ( $tab['plugins'] as $plugin ) {
				$info = $live_info[ $plugin['slug'] ] ?? array();

				$status = 'not_installed';
				if ( isset( $installed_plugins[ $plugin['location'] ] ) ) {
					$status = is_plugin_active( $plugin['location'] ) ? 'active' : 'inactive';
				}

				$items[] = array(
					'slug'            => $plugin['slug'],
					'location'        => $plugin['location'],
					'name'            => ! empty( $info['name'] ) ? $info['name'] : $plugin['name'],
					'description'     => $info['description'] ?? '',
					'icon'            => $info['icons']['1x'] ?? ( $info['icons']['default'] ?? '' ),
					'active_installs' => $info['active_installs'] ?? null,
					'status'          => $status,
				);
			}

			$response_tabs[] = array(
				'key'     => $tab['key'],
				'title'   => $tab['title'],
				'plugins' => $items,
			);
		}

		return new WP_REST_Response( array( 'tabs' => $response_tabs ), 200 );
	}

	public function install_recommended_plugin( WP_REST_Request $request ) {
		$slug      = sanitize_text_field( (string) $request->get_param( 'slug' ) );
		$whitelist = $this->get_whitelist();

		if ( empty( $slug ) || ! isset( $whitelist[ $slug ] ) ) {
			return new WP_Error( 'recurio_invalid_plugin', __( 'This plugin is not in the recommended list.', 'recurio' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );

		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'recurio_plugin_not_found', __( 'Plugin not found on WordPress.org.', 'recurio' ), array( 'status' => 404 ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'recurio_install_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		if ( is_wp_error( $skin->result ) ) {
			return new WP_Error( 'recurio_install_failed', $skin->result->get_error_message(), array( 'status' => 500 ) );
		}

		if ( $skin->get_errors()->has_errors() ) {
			return new WP_Error( 'recurio_install_failed', $skin->get_error_messages(), array( 'status' => 500 ) );
		}

		$plugin_location = $upgrader->plugin_info();

		if ( ! $plugin_location ) {
			return new WP_Error( 'recurio_install_failed', __( 'Could not determine installed plugin file.', 'recurio' ), array( 'status' => 500 ) );
		}

		$activate_result = activate_plugin( $plugin_location );

		if ( is_wp_error( $activate_result ) ) {
			return new WP_Error( 'recurio_activate_failed', $activate_result->get_error_message(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'location' => $plugin_location,
				'status'   => 'active',
			),
			200
		);
	}

	public function activate_recommended_plugin( WP_REST_Request $request ) {
		$location  = sanitize_text_field( (string) $request->get_param( 'location' ) );
		$whitelist = $this->get_whitelist();

		$is_whitelisted = false;
		foreach ( $whitelist as $plugin ) {
			if ( $plugin['location'] === $location ) {
				$is_whitelisted = true;
				break;
			}
		}

		if ( empty( $location ) || ! $is_whitelisted ) {
			return new WP_Error( 'recurio_invalid_plugin', __( 'This plugin is not in the recommended list.', 'recurio' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $location ) ) {
			return new WP_Error( 'recurio_plugin_not_found', __( 'Plugin is not installed.', 'recurio' ), array( 'status' => 404 ) );
		}

		$result = activate_plugin( $location );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'recurio_activate_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'status' => 'active' ), 200 );
	}

	// -------------------------------------------------------------------------
	// Permission
	// -------------------------------------------------------------------------

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function check_install_permission(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
	}
}
