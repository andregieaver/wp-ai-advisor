<?php
/**
 * Plugin Name:       WP AI Advisor
 * Plugin URI:        https://github.com/andregieaver/wp-ai-advisor
 * Description:       Adds an AI advisor powered by Claude that answers visitor questions using your site's content as context.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Andre Gieaver
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-advisor
 * Domain Path:       /languages
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_AI_ADVISOR_VERSION', '0.1.0' );
define( 'WP_AI_ADVISOR_FILE', __FILE__ );
define( 'WP_AI_ADVISOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_ADVISOR_URL', plugin_dir_url( __FILE__ ) );

require_once WP_AI_ADVISOR_PATH . 'includes/class-wp-ai-advisor-settings.php';
require_once WP_AI_ADVISOR_PATH . 'includes/class-wp-ai-advisor-claude-client.php';
require_once WP_AI_ADVISOR_PATH . 'includes/class-wp-ai-advisor-context.php';
require_once WP_AI_ADVISOR_PATH . 'includes/class-wp-ai-advisor-rest-controller.php';
require_once WP_AI_ADVISOR_PATH . 'includes/class-wp-ai-advisor-shortcode.php';
require_once WP_AI_ADVISOR_PATH . 'includes/class-wp-ai-advisor-plugin.php';

if ( is_admin() ) {
	require_once WP_AI_ADVISOR_PATH . 'admin/class-wp-ai-advisor-admin.php';
}

/**
 * Main plugin instance.
 *
 * @return WP_AI_Advisor_Plugin
 */
function wp_ai_advisor() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new WP_AI_Advisor_Plugin();
	}

	return $plugin;
}

wp_ai_advisor()->init();

register_activation_hook( __FILE__, array( 'WP_AI_Advisor_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_AI_Advisor_Plugin', 'deactivate' ) );
