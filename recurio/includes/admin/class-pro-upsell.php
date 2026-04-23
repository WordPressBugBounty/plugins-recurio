<?php
/**
 * Pro Upsell Class
 *
 * Adds Pro feature indicators and upsells throughout the Free admin interface.
 *
 * @package Recurio
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Upsell class
 *
 * Shows Pro badges and upgrade notices in the Free version.
 *
 * @since 1.1.0
 */
class Recurio_Pro_Upsell {

	/**
	 * Singleton instance
	 *
	 * @var Recurio_Pro_Upsell
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return Recurio_Pro_Upsell
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
		// Only show upsells if Pro is not licensed.
		if ( recurio_is_pro_licensed() ) {
			return;
		}

		// Add footer text on Recurio pages.
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ), 1 );
	}

	/**
	 * Modify admin footer text on Recurio pages
	 *
	 * @param string $text Footer text.
	 * @return string Modified footer text
	 */
	public function admin_footer_text( $text ) {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'recurio' ) === false ) {
			return $text;
		}

		if ( recurio_is_pro_licensed() ) {
			return sprintf(
				/* translators: %s: Recurio Pro version */
				__( 'Thank you for using Recurio Pro %s | Need help? Visit our <a href="%s" target="_blank">documentation</a>', 'recurio' ),
				recurio_get_pro_version(),
				'https://help.wprecurio.com'
			);
		}

		return sprintf(
			/* translators: 1: Recurio version, 2: Pro features link */
			__( 'Recurio %1$s | <a href="%2$s">Upgrade to Pro</a> for advanced automation & analytics', 'recurio' ),
			RECURIO_VERSION,
			admin_url( 'admin.php?page=recurio#/upgrade-to-pro' )
		);
	}
	
}
