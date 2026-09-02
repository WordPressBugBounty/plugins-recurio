<?php
namespace Recurio\Admin;

/**
 * HasThemes Stories dashboard widget
 *
 * Shared "HasThemes Stories" widget (news feed + promo banner) shown on
 * wp-admin/index.php. Widget ID is the shared literal 'hasthemes-dashboard-stories'
 * used across HasThemes plugins so only one instance ever registers on a site.
 *
 * @package Recurio
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HasThemes_Stories_Widget {

	const REMOTE_BASE_URL = 'https://feed.hasthemes.com/notices/news-feed';
	const ENDPOINT_FILE   = 'news-data.json';
	const TRANSIENT_KEY   = 'recurio_news_feed_data';

	public function init() {
		// Priority chain: defer to WooLentor and HT Mega if either is active —
		// they register the same shared widget ID, only one instance may win.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( 'woolentor-addons/woolentor_addons_elementor.php' ) ||
			is_plugin_active( 'ht-mega-for-elementor/htmega_addons_elementor.php' ) ) {
			return;
		}

		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ), 9999 );
	}

	/**
	 * Register the shared HasThemes Stories widget, moved to the top of the
	 * left column. No-ops if another active HasThemes plugin already registered it.
	 */
	public function add_dashboard_widget() {
		global $wp_meta_boxes;

		if ( isset( $wp_meta_boxes['dashboard']['normal']['core']['hasthemes-dashboard-stories'] ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'hasthemes-dashboard-stories',
			esc_html__( 'HasThemes Stories', 'recurio' ),
			array( $this, 'render_widget' )
		);

		$widgets = $wp_meta_boxes['dashboard']['normal']['core'];

		$wp_meta_boxes['dashboard']['normal']['core'] = array_merge(
			array( 'hasthemes-dashboard-stories' => $widgets['hasthemes-dashboard-stories'] ),
			$widgets
		);
	}

	/**
	 * Fetch the shared news-feed data, cached via transient.
	 *
	 * @return array
	 */
	private function get_remote_data(): array {
		$cache_key = self::TRANSIENT_KEY;
		$info_data = get_transient( $cache_key );

		if ( false === $info_data ) {
			$response = wp_remote_get(
				sprintf( '%s/%s', self::REMOTE_BASE_URL, self::ENDPOINT_FILE ),
				array( 'timeout' => 8 )
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				set_transient( $cache_key, array(), HOUR_IN_SECONDS );
				return array();
			}

			$info_data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $info_data ) || ! is_array( $info_data ) ) {
				set_transient( $cache_key, array(), HOUR_IN_SECONDS );
				return array();
			}

			set_transient( $cache_key, $info_data, 12 * HOUR_IN_SECONDS );
		}

		return empty( $info_data ) ? array() : $info_data;
	}

	public function render_widget() {
		$info_data = $this->get_remote_data();
		$banner    = ! empty( $info_data['banner'] ) ? $info_data['banner'] : array();
		?>
		<style>
			.hastheme-dashboard-widget-header img { width: 100%; }
			.hastheme-dashboard-widget-newsfeed ul li { margin: 10px 0; }
			.hastheme-dashboard-widget-newsfeed ul li .hastheme-dashboard-widget-newsfeed-item-title a {
				font-size: 14px;
				margin-bottom: 3px;
				display: inline-block;
			}
			.hastheme-dashboard-widget-newsfeed-item-description { margin: 0 0 1.2em; }
			.hastheme-dashboard-widget-footer { border-top: 1px solid #eee; margin: 0 -12px; padding: 12px 6px 0 12px; }
			.hastheme-dashboard-widget-footer ul { display: flex; list-style: none; margin: 0; padding: 0; }
			.hastheme-dashboard-widget-footer ul li { padding: 0 10px; margin: 0; border-left: 1px solid #ddd; }
			.hastheme-dashboard-widget-footer ul li:first-child { padding-left: 0; border: none; }
			.hastheme-dashboard-widget-footer ul li a { text-decoration: none; }
		</style>
		<div class="hastheme-dashboard-widget-area">
			<div class="hastheme-dashboard-widget-header">
				<?php if ( ! empty( $banner['status'] ) && ! empty( $banner['image'] ) ) : ?>
					<a href="<?php echo esc_url( $banner['link'] ?? '' ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( $banner['image'] ); ?>" alt="<?php echo esc_attr( $banner['alt'] ?? '' ); ?>" />
					</a>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $info_data['feed'] ) ) : ?>
				<div class="hastheme-dashboard-widget-newsfeed">
					<ul>
						<?php
						foreach ( $info_data['feed'] as $feed ) :
							if ( empty( $feed['status'] ) ) {
								continue;
							}
							?>
							<li class="hastheme-dashboard-widget-newsfeed-item">
								<div class="hastheme-dashboard-widget-newsfeed-item-title">
									<a target="_blank" href="<?php echo esc_url( $feed['url'] ); ?>"><?php echo esc_html( $feed['title'] ); ?></a>
								</div>
								<div class="hastheme-dashboard-widget-newsfeed-item-description">
									<?php echo wp_kses_post( $feed['description'] ); ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div class="hastheme-dashboard-widget-footer">
				<ul>
					<li>
						<a href="https://hasthemes.com/blog/" target="_blank" rel="noopener">
							<?php esc_html_e( 'Blog', 'recurio' ); ?>
							<span aria-hidden="true" class="dashicons dashicons-external"></span>
						</a>
					</li>
					<li>
						<a href="https://help.wprecurio.com/docs/" target="_blank" rel="noopener">
							<?php esc_html_e( 'Documentation', 'recurio' ); ?>
							<span aria-hidden="true" class="dashicons dashicons-external"></span>
						</a>
					</li>
					<li>
						<a href="https://wprecurio.com/contact-us/" target="_blank" rel="noopener">
							<?php esc_html_e( 'Support', 'recurio' ); ?>
							<span aria-hidden="true" class="dashicons dashicons-external"></span>
						</a>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}
}
