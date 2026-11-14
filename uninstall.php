<?php
/**
 * Uninstall: remove the settings and all visibility-window postmeta.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wcs_settings' );

delete_post_meta_by_key( '_wcs_visible_from' );
delete_post_meta_by_key( '_wcs_visible_until' );
