<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package WP_AI_Advisor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_ai_advisor_settings' );

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'wp_ai_advisor_settings' );
		restore_current_blog();
	}
}
