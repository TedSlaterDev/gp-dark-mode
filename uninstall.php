<?php
/**
 * GP Dark Mode — uninstall cleanup.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes the
 * single options row the plugin creates. (Single-site scope.)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ogm_gpdm_settings' );
