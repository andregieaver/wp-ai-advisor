<?php
/**
 * Plugin bootstrap: wires the pieces together.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers hooks for the REST API, the shortcode, and the admin screen.
 */
class WP_AI_Advisor_Plugin {

	/**
	 * REST controller.
	 *
	 * @var WP_AI_Advisor_REST_Controller
	 */
	private $rest;

	/**
	 * Shortcode handler.
	 *
	 * @var WP_AI_Advisor_Shortcode
	 */
	private $shortcode;

	/**
	 * Admin screen, only built in the admin.
	 *
	 * @var WP_AI_Advisor_Admin|null
	 */
	private $admin = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rest      = new WP_AI_Advisor_REST_Controller();
		$this->shortcode = new WP_AI_Advisor_Shortcode();

		if ( is_admin() && class_exists( 'WP_AI_Advisor_Admin' ) ) {
			$this->admin = new WP_AI_Advisor_Admin();
		}
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );

		$this->shortcode->init();

		if ( $this->admin ) {
			$this->admin->init();
		}
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'wp-ai-advisor',
			false,
			dirname( plugin_basename( WP_AI_ADVISOR_FILE ) ) . '/languages'
		);
	}

	/**
	 * Seeds default settings on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( WP_AI_Advisor_Settings::OPTION_KEY, false ) ) {
			add_option( WP_AI_Advisor_Settings::OPTION_KEY, WP_AI_Advisor_Settings::defaults() );
		}
	}

	/**
	 * Runs on deactivation. Settings are kept; deletion is handled by uninstall.php.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_expired_transients();
	}
}
