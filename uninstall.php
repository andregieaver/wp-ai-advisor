<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package WP_AI_Advisor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-ai-advisor-store.php';

/**
 * Removes this site's options and knowledge-base tables.
 *
 * @return void
 */
function wp_ai_advisor_uninstall_site() {
	delete_option( 'wp_ai_advisor_settings' );
	delete_option( 'wp_ai_advisor_settings_version' );
	delete_option( WP_AI_Advisor_Store::DB_VERSION_KEY );

	WP_AI_Advisor_Store::drop();
}

wp_ai_advisor_uninstall_site();

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		wp_ai_advisor_uninstall_site();
		restore_current_blog();
	}
}
