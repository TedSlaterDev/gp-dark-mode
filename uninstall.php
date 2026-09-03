<?php
/**
 * GP Dark Mode — uninstall cleanup.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes
 * every options row the plugin creates (settings incl. the API key, the
 * generated palette, and the AI proposal/backup). On multisite, each site's
 * rows are removed — per-site admins may have saved settings on subsites.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function ogm_gpdm_uninstall_delete_options() {
	delete_option( 'ogm_gpdm_settings' );
	delete_option( 'ogm_gpdm_generated_css' );
	delete_option( 'ogm_gpdm_ai_proposal' );
	delete_option( 'ogm_gpdm_ai_backup' );
	delete_option( 'ogm_gpdm_ai_models' );
	delete_option( 'ogm_gpdm_ai_status' );
	delete_option( 'ogm_gpdm_ai_instructions' );
	delete_option( 'ogm_gpdm_ai_css' );
}

if ( is_multisite() ) {
	$ogm_gpdm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $ogm_gpdm_site_ids as $ogm_gpdm_site_id ) {
		switch_to_blog( $ogm_gpdm_site_id );
		ogm_gpdm_uninstall_delete_options();
		restore_current_blog();
	}
} else {
	ogm_gpdm_uninstall_delete_options();
}
