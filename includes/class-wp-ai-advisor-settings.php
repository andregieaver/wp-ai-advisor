<?php
/**
 * Settings storage and defaults.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's single options row.
 */
class WP_AI_Advisor_Settings {

	const OPTION_KEY = 'wp_ai_advisor_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'           => '',
			'model'             => 'claude-opus-5',
			'effort'            => 'medium',
			'max_tokens'        => 4096,
			'system_prompt'     => '',
			'context_post_type' => array( 'post', 'page' ),
			'context_limit'     => 5,
			'require_login'     => false,
			'rate_limit'        => 10,
			'greeting'          => '',
		);
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Value returned when the setting is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * The API key, preferring a wp-config.php constant over the stored option.
	 *
	 * Defining WP_AI_ADVISOR_API_KEY keeps the key out of the database entirely.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'WP_AI_ADVISOR_API_KEY' ) && WP_AI_ADVISOR_API_KEY ) {
			return (string) WP_AI_ADVISOR_API_KEY;
		}

		return (string) self::get( 'api_key', '' );
	}

	/**
	 * Whether the API key comes from a constant and so cannot be edited in the admin.
	 *
	 * @return bool
	 */
	public static function api_key_is_constant() {
		return defined( 'WP_AI_ADVISOR_API_KEY' ) && WP_AI_ADVISOR_API_KEY;
	}

	/**
	 * The system prompt sent with every request.
	 *
	 * @return string
	 */
	public static function system_prompt() {
		$prompt = trim( (string) self::get( 'system_prompt', '' ) );

		if ( '' === $prompt ) {
			$prompt = sprintf(
				/* translators: %s: site name. */
				__( 'You are a helpful advisor for the website "%s". Answer questions using the provided site content. If the content does not cover the question, say so plainly and suggest what the visitor could look at instead. Keep answers short and concrete. Never invent prices, dates, or contact details.', 'wp-ai-advisor' ),
				get_bloginfo( 'name' )
			);
		}

		/**
		 * Filters the system prompt sent to Claude.
		 *
		 * @param string $prompt The system prompt.
		 */
		return (string) apply_filters( 'wp_ai_advisor_system_prompt', $prompt );
	}

	/**
	 * Sanitizes a settings array coming from the admin form.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$existing = self::all();
		$output   = array();

		$input = is_array( $input ) ? $input : array();

		// Keep the stored key when the field is left blank or is managed by a constant.
		if ( self::api_key_is_constant() ) {
			$output['api_key'] = $existing['api_key'];
		} else {
			$submitted_key     = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
			$output['api_key'] = '' === $submitted_key ? $existing['api_key'] : $submitted_key;
		}

		$allowed_models   = array( 'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5' );
		$model            = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : '';
		$output['model']  = in_array( $model, $allowed_models, true ) ? $model : $defaults['model'];

		$allowed_effort  = array( 'low', 'medium', 'high', 'xhigh', 'max' );
		$effort          = isset( $input['effort'] ) ? sanitize_text_field( $input['effort'] ) : '';
		$output['effort'] = in_array( $effort, $allowed_effort, true ) ? $effort : $defaults['effort'];

		$max_tokens             = isset( $input['max_tokens'] ) ? absint( $input['max_tokens'] ) : $defaults['max_tokens'];
		$output['max_tokens']   = max( 256, min( 16000, $max_tokens ) );

		$output['system_prompt'] = isset( $input['system_prompt'] ) ? sanitize_textarea_field( $input['system_prompt'] ) : '';
		$output['greeting']      = isset( $input['greeting'] ) ? sanitize_text_field( $input['greeting'] ) : '';

		$post_types = isset( $input['context_post_type'] ) ? (array) $input['context_post_type'] : array();
		$post_types = array_values( array_intersect( array_map( 'sanitize_key', $post_types ), get_post_types( array( 'public' => true ) ) ) );
		$output['context_post_type'] = empty( $post_types ) ? $defaults['context_post_type'] : $post_types;

		$context_limit           = isset( $input['context_limit'] ) ? absint( $input['context_limit'] ) : $defaults['context_limit'];
		$output['context_limit'] = max( 0, min( 20, $context_limit ) );

		$output['require_login'] = ! empty( $input['require_login'] );

		$rate_limit           = isset( $input['rate_limit'] ) ? absint( $input['rate_limit'] ) : $defaults['rate_limit'];
		$output['rate_limit'] = max( 0, min( 240, $rate_limit ) );

		return $output;
	}
}
