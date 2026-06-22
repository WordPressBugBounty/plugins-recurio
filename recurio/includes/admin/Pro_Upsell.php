<?php
namespace Recurio\Admin;
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
class Pro_Upsell {

	/**
	 * Constructor
	 */
	public function init() {
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
				/* translators: 1: Recurio Pro version number, 2: documentation URL */
				__( 'Thank you for using Recurio Pro %1$s | Need help? Visit our <a href="%2$s" target="_blank">documentation</a>', 'recurio' ),
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
