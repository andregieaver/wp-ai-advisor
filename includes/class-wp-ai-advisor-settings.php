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
			// API.
			'api_key'          => '',
			'model'            => 'gpt-4o-mini',
			'embedding_model'  => 'text-embedding-3-small',
			'temperature'      => 0.2,
			'max_tokens'       => 800,

			// Knowledge base.
			'source_mode'      => 'crawl',
			'local_post_types' => array( 'post', 'page' ),
			'site_url'         => '',
			'crawl_max_pages'  => 100,
			'crawl_exclude'    => "/wp-admin/\n/cart/\n/checkout/\n/my-account/",
			'top_k'            => 6,
			'min_score'        => 0.20,

			// Grounding.
			'strict_mode'      => true,
			'refusal_message'  => '',
			'system_prompt'    => '',

			// Access.
			'admin_only'       => false,
			'rate_limit'       => 20,

			// Appearance.
			'eyebrow'          => '',
			'heading'          => '',
			'placeholder'      => '',
			'suggestions'      => array(),
			'cta_label'        => '',
			'cta_url'          => '',
			'accent'           => '#ffffff',
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
	 * Updates a subset of settings.
	 *
	 * @param array $values Values to merge in.
	 * @return void
	 */
	public static function update( array $values ) {
		update_option( self::OPTION_KEY, array_merge( self::all(), $values ) );
	}

	/**
	 * The OpenAI API key, preferring a wp-config.php constant over the stored option.
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
	 * Base URL the crawler starts from.
	 *
	 * @return string
	 */
	public static function site_url() {
		$url = trim( (string) self::get( 'site_url', '' ) );

		return $url ? untrailingslashit( $url ) : untrailingslashit( home_url() );
	}

	/**
	 * How the knowledge base is built: 'crawl', 'local' or 'both'.
	 *
	 * @return string
	 */
	public static function source_mode() {
		$mode = (string) self::get( 'source_mode', 'crawl' );

		return in_array( $mode, array( 'crawl', 'local', 'both' ), true ) ? $mode : 'crawl';
	}

	/**
	 * Whether a build phase runs under the current mode.
	 *
	 * @param string $phase 'crawl' or 'local'.
	 * @return bool
	 */
	public static function uses( $phase ) {
		$mode = self::source_mode();

		return 'both' === $mode || $mode === $phase;
	}

	/**
	 * Post types indexed in local content mode.
	 *
	 * @return string[]
	 */
	public static function local_post_types() {
		$types = (array) self::get( 'local_post_types', array() );
		$types = array_values( array_intersect( $types, get_post_types( array( 'public' => true ) ) ) );

		return $types;
	}

	/**
	 * Suggested questions shown on the collapsed card.
	 *
	 * @return string[]
	 */
	public static function suggestions() {
		$suggestions = (array) self::get( 'suggestions', array() );
		$suggestions = array_values( array_filter( array_map( 'trim', $suggestions ) ) );

		if ( empty( $suggestions ) ) {
			$suggestions = array(
				__( 'What do you offer?', 'wp-ai-advisor' ),
				__( 'Where can I find you?', 'wp-ai-advisor' ),
				__( 'What are your opening hours?', 'wp-ai-advisor' ),
			);
		}

		return array_slice( $suggestions, 0, 6 );
	}

	/**
	 * Message returned when a question falls outside the knowledge base.
	 *
	 * @return string
	 */
	public static function refusal_message() {
		$message = trim( (string) self::get( 'refusal_message', '' ) );

		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %s: site name. */
				__( 'I can only answer questions about %s, and I could not find anything on that. Try asking about something else on the site.', 'wp-ai-advisor' ),
				get_bloginfo( 'name' )
			);
		}

		return $message;
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
				/* translators: 1: site name, 2: site URL. */
				__( 'You are the assistant for %1$s (%2$s). Answer visitor questions using only the supplied site excerpts. Be brief, concrete and friendly, and answer in the language the visitor writes in.', 'wp-ai-advisor' ),
				get_bloginfo( 'name' ),
				self::site_url()
			);
		}

		/**
		 * Filters the system prompt sent to the model.
		 *
		 * @param string $prompt The system prompt.
		 */
		return (string) apply_filters( 'wp_ai_advisor_system_prompt', $prompt );
	}

	/**
	 * Whether the widget should render for the current visitor.
	 *
	 * @return bool
	 */
	public static function is_visible() {
		$visible = ! self::get( 'admin_only' ) || current_user_can( 'manage_options' );

		/**
		 * Filters whether the advisor widget renders.
		 *
		 * @param bool $visible Whether to render.
		 */
		return (bool) apply_filters( 'wp_ai_advisor_is_visible', $visible );
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
		$output   = $existing;

		$input = is_array( $input ) ? $input : array();

		// The key field is write-only: blank means "keep what is stored".
		if ( ! self::api_key_is_constant() && isset( $input['api_key'] ) ) {
			$submitted = trim( sanitize_text_field( $input['api_key'] ) );

			if ( '' !== $submitted ) {
				$output['api_key'] = $submitted;
			}
		}

		if ( isset( $input['model'] ) ) {
			$model            = sanitize_text_field( $input['model'] );
			$output['model']  = '' !== $model ? $model : $defaults['model'];
		}

		if ( isset( $input['embedding_model'] ) ) {
			$embedding                 = sanitize_text_field( $input['embedding_model'] );
			$output['embedding_model'] = '' !== $embedding ? $embedding : $defaults['embedding_model'];
		}

		if ( isset( $input['temperature'] ) ) {
			$output['temperature'] = (float) max( 0, min( 2, (float) $input['temperature'] ) );
		}

		if ( isset( $input['max_tokens'] ) ) {
			$output['max_tokens'] = max( 128, min( 4000, absint( $input['max_tokens'] ) ) );
		}

		if ( isset( $input['source_mode'] ) ) {
			$mode                  = sanitize_key( $input['source_mode'] );
			$output['source_mode'] = in_array( $mode, array( 'crawl', 'local', 'both' ), true ) ? $mode : $defaults['source_mode'];
		}

		if ( isset( $input['_form'] ) ) {
			$types = isset( $input['local_post_types'] ) ? (array) $input['local_post_types'] : array();
			$types = array_values( array_intersect( array_map( 'sanitize_key', $types ), get_post_types( array( 'public' => true ) ) ) );

			$output['local_post_types'] = $types;
		}

		if ( isset( $input['site_url'] ) ) {
			$url                = esc_url_raw( trim( $input['site_url'] ) );
			$output['site_url'] = $url ? untrailingslashit( $url ) : '';
		}

		if ( isset( $input['crawl_max_pages'] ) ) {
			$output['crawl_max_pages'] = max( 1, min( 2000, absint( $input['crawl_max_pages'] ) ) );
		}

		if ( isset( $input['crawl_exclude'] ) ) {
			$output['crawl_exclude'] = sanitize_textarea_field( $input['crawl_exclude'] );
		}

		if ( isset( $input['top_k'] ) ) {
			$output['top_k'] = max( 1, min( 20, absint( $input['top_k'] ) ) );
		}

		if ( isset( $input['min_score'] ) ) {
			$output['min_score'] = (float) max( 0, min( 1, (float) $input['min_score'] ) );
		}

		// Checkboxes post nothing when unchecked, so they are only read when the
		// form that owns them was actually submitted.
		if ( isset( $input['_form'] ) ) {
			$output['strict_mode'] = ! empty( $input['strict_mode'] );
			$output['admin_only']  = ! empty( $input['admin_only'] );
		}

		if ( isset( $input['refusal_message'] ) ) {
			$output['refusal_message'] = sanitize_textarea_field( $input['refusal_message'] );
		}

		if ( isset( $input['system_prompt'] ) ) {
			$output['system_prompt'] = sanitize_textarea_field( $input['system_prompt'] );
		}

		if ( isset( $input['rate_limit'] ) ) {
			$output['rate_limit'] = max( 0, min( 500, absint( $input['rate_limit'] ) ) );
		}

		foreach ( array( 'eyebrow', 'heading', 'placeholder', 'cta_label' ) as $text_field ) {
			if ( isset( $input[ $text_field ] ) ) {
				$output[ $text_field ] = sanitize_text_field( $input[ $text_field ] );
			}
		}

		if ( isset( $input['cta_url'] ) ) {
			$output['cta_url'] = esc_url_raw( trim( $input['cta_url'] ) );
		}

		if ( isset( $input['accent'] ) ) {
			$accent           = sanitize_hex_color( $input['accent'] );
			$output['accent'] = $accent ? $accent : $defaults['accent'];
		}

		if ( isset( $input['suggestions'] ) ) {
			$suggestions = is_array( $input['suggestions'] ) ? $input['suggestions'] : explode( "\n", (string) $input['suggestions'] );
			$suggestions = array_map( 'sanitize_text_field', $suggestions );
			$suggestions = array_values( array_filter( array_map( 'trim', $suggestions ) ) );

			$output['suggestions'] = array_slice( $suggestions, 0, 6 );
		}

		return $output;
	}
}
