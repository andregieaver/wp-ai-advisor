<?php
/**
 * Settings screen under Settings → AI Advisor.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the options page and its fields.
 */
class WP_AI_Advisor_Admin {

	const PAGE_SLUG  = 'wp-ai-advisor';
	const GROUP      = 'wp_ai_advisor';
	const CAPABILITY = 'manage_options';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_AI_ADVISOR_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the options page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_options_page(
			__( 'AI Advisor', 'wp-ai-advisor' ),
			__( 'AI Advisor', 'wp-ai-advisor' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link to the plugin list row.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wp-ai-advisor' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Registers the settings, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			WP_AI_Advisor_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WP_AI_Advisor_Settings', 'sanitize' ),
				'default'           => WP_AI_Advisor_Settings::defaults(),
			)
		);

		add_settings_section(
			'wp_ai_advisor_api',
			__( 'API', 'wp-ai-advisor' ),
			array( $this, 'render_api_section' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'wp_ai_advisor_behaviour',
			__( 'Behaviour', 'wp-ai-advisor' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_section(
			'wp_ai_advisor_access',
			__( 'Access', 'wp-ai-advisor' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$fields = array(
			array( 'api_key', __( 'Anthropic API key', 'wp-ai-advisor' ), 'render_api_key', 'wp_ai_advisor_api' ),
			array( 'model', __( 'Model', 'wp-ai-advisor' ), 'render_model', 'wp_ai_advisor_api' ),
			array( 'effort', __( 'Effort', 'wp-ai-advisor' ), 'render_effort', 'wp_ai_advisor_api' ),
			array( 'max_tokens', __( 'Max response tokens', 'wp-ai-advisor' ), 'render_max_tokens', 'wp_ai_advisor_api' ),
			array( 'system_prompt', __( 'System prompt', 'wp-ai-advisor' ), 'render_system_prompt', 'wp_ai_advisor_behaviour' ),
			array( 'greeting', __( 'Greeting', 'wp-ai-advisor' ), 'render_greeting', 'wp_ai_advisor_behaviour' ),
			array( 'context_post_type', __( 'Content to search', 'wp-ai-advisor' ), 'render_post_types', 'wp_ai_advisor_behaviour' ),
			array( 'context_limit', __( 'Context items', 'wp-ai-advisor' ), 'render_context_limit', 'wp_ai_advisor_behaviour' ),
			array( 'require_login', __( 'Require login', 'wp-ai-advisor' ), 'render_require_login', 'wp_ai_advisor_access' ),
			array( 'rate_limit', __( 'Questions per hour', 'wp-ai-advisor' ), 'render_rate_limit', 'wp_ai_advisor_access' ),
		);

		foreach ( $fields as $field ) {
			list( $id, $label, $callback, $section ) = $field;

			add_settings_field(
				'wp_ai_advisor_' . $id,
				$label,
				array( $this, $callback ),
				self::PAGE_SLUG,
				$section,
				array( 'label_for' => 'wp_ai_advisor_' . $id )
			);
		}
	}

	/**
	 * Intro copy for the API section.
	 *
	 * @return void
	 */
	public function render_api_section() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Create a key in the Anthropic Console. For production sites, define WP_AI_ADVISOR_API_KEY in wp-config.php instead of storing the key in the database.', 'wp-ai-advisor' )
		);
	}

	/**
	 * Renders the options page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: shortcode example. */
					esc_html__( 'Place the advisor on any page with %s.', 'wp-ai-advisor' ),
					'<code>[ai_advisor]</code>'
				);
				?>
			</p>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Field name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function name( $key ) {
		return WP_AI_Advisor_Settings::OPTION_KEY . '[' . $key . ']';
	}

	/**
	 * API key field.
	 *
	 * @return void
	 */
	public function render_api_key() {
		if ( WP_AI_Advisor_Settings::api_key_is_constant() ) {
			printf(
				'<p><code>WP_AI_ADVISOR_API_KEY</code> %s</p>',
				esc_html__( 'is defined in wp-config.php and takes precedence over this field.', 'wp-ai-advisor' )
			);

			return;
		}

		$has_key = '' !== WP_AI_Advisor_Settings::get( 'api_key', '' );

		printf(
			'<input type="password" id="wp_ai_advisor_api_key" name="%1$s" value="" class="regular-text" autocomplete="off" placeholder="%2$s" />',
			esc_attr( $this->name( 'api_key' ) ),
			esc_attr( $has_key ? __( 'A key is saved. Enter a new key to replace it.', 'wp-ai-advisor' ) : 'sk-ant-…' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Leave blank to keep the saved key.', 'wp-ai-advisor' )
		);
	}

	/**
	 * Model field.
	 *
	 * @return void
	 */
	public function render_model() {
		$current = WP_AI_Advisor_Settings::get( 'model' );
		$models  = array(
			'claude-opus-5'    => __( 'Claude Opus 5 — most capable', 'wp-ai-advisor' ),
			'claude-sonnet-5'  => __( 'Claude Sonnet 5 — balanced', 'wp-ai-advisor' ),
			'claude-haiku-4-5' => __( 'Claude Haiku 4.5 — fastest and cheapest', 'wp-ai-advisor' ),
		);

		echo '<select id="wp_ai_advisor_model" name="' . esc_attr( $this->name( 'model' ) ) . '">';

		foreach ( $models as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Effort field.
	 *
	 * @return void
	 */
	public function render_effort() {
		$current = WP_AI_Advisor_Settings::get( 'effort' );
		$levels  = array( 'low', 'medium', 'high', 'xhigh', 'max' );

		echo '<select id="wp_ai_advisor_effort" name="' . esc_attr( $this->name( 'effort' ) ) . '">';

		foreach ( $levels as $level ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $level ),
				selected( $current, $level, false )
			);
		}

		echo '</select>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'How much reasoning each answer gets. Higher settings cost more tokens and take longer.', 'wp-ai-advisor' )
		);
	}

	/**
	 * Max tokens field.
	 *
	 * @return void
	 */
	public function render_max_tokens() {
		printf(
			'<input type="number" id="wp_ai_advisor_max_tokens" name="%1$s" value="%2$d" min="256" max="16000" step="256" class="small-text" />',
			esc_attr( $this->name( 'max_tokens' ) ),
			(int) WP_AI_Advisor_Settings::get( 'max_tokens' )
		);
	}

	/**
	 * System prompt field.
	 *
	 * @return void
	 */
	public function render_system_prompt() {
		printf(
			'<textarea id="wp_ai_advisor_system_prompt" name="%1$s" rows="6" class="large-text code">%2$s</textarea>',
			esc_attr( $this->name( 'system_prompt' ) ),
			esc_textarea( WP_AI_Advisor_Settings::get( 'system_prompt' ) )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Leave blank to use the built-in prompt. Matched site content is appended automatically.', 'wp-ai-advisor' )
		);
	}

	/**
	 * Greeting field.
	 *
	 * @return void
	 */
	public function render_greeting() {
		printf(
			'<input type="text" id="wp_ai_advisor_greeting" name="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( $this->name( 'greeting' ) ),
			esc_attr( WP_AI_Advisor_Settings::get( 'greeting' ) )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Shown above the input before the first question.', 'wp-ai-advisor' )
		);
	}

	/**
	 * Post type checkboxes.
	 *
	 * @return void
	 */
	public function render_post_types() {
		$selected   = (array) WP_AI_Advisor_Settings::get( 'context_post_type' );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		echo '<fieldset>';

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}

			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s</label><br />',
				esc_attr( $this->name( 'context_post_type' ) ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $selected, true ), true, false ),
				esc_html( $post_type->labels->name )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Context limit field.
	 *
	 * @return void
	 */
	public function render_context_limit() {
		printf(
			'<input type="number" id="wp_ai_advisor_context_limit" name="%1$s" value="%2$d" min="0" max="20" class="small-text" />',
			esc_attr( $this->name( 'context_limit' ) ),
			(int) WP_AI_Advisor_Settings::get( 'context_limit' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'How many matching posts are passed to the model. 0 disables site context.', 'wp-ai-advisor' )
		);
	}

	/**
	 * Require-login checkbox.
	 *
	 * @return void
	 */
	public function render_require_login() {
		printf(
			'<label><input type="checkbox" id="wp_ai_advisor_require_login" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $this->name( 'require_login' ) ),
			checked( (bool) WP_AI_Advisor_Settings::get( 'require_login' ), true, false ),
			esc_html__( 'Only logged-in users may ask questions.', 'wp-ai-advisor' )
		);
	}

	/**
	 * Rate limit field.
	 *
	 * @return void
	 */
	public function render_rate_limit() {
		printf(
			'<input type="number" id="wp_ai_advisor_rate_limit" name="%1$s" value="%2$d" min="0" max="240" class="small-text" />',
			esc_attr( $this->name( 'rate_limit' ) ),
			(int) WP_AI_Advisor_Settings::get( 'rate_limit' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Per visitor, per hour. 0 disables rate limiting.', 'wp-ai-advisor' )
		);
	}
}
