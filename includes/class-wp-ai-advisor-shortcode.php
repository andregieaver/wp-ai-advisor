<?php
/**
 * Front-end widget: [ai_advisor] shortcode and its assets.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the conversation container and enqueues its assets on demand.
 */
class WP_AI_Advisor_Shortcode {

	const TAG    = 'ai_advisor';
	const HANDLE = 'wp-ai-advisor';

	/**
	 * Registers the shortcode and asset hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Registers (but does not enqueue) the widget assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			self::HANDLE,
			WP_AI_ADVISOR_URL . 'assets/css/advisor.css',
			array(),
			WP_AI_ADVISOR_VERSION
		);

		wp_register_script(
			self::HANDLE,
			WP_AI_ADVISOR_URL . 'assets/js/advisor.js',
			array(),
			WP_AI_ADVISOR_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'wpAiAdvisor',
			array(
				'endpoint' => esc_url_raw( rest_url( WP_AI_Advisor_REST_Controller::NAMESPACE_V1 . '/ask' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'maxChars' => WP_AI_Advisor_REST_Controller::MAX_QUESTION,
				'strings'  => array(
					'thinking' => __( 'Thinking…', 'wp-ai-advisor' ),
					'error'    => __( 'Something went wrong. Please try again.', 'wp-ai-advisor' ),
					'you'      => __( 'You', 'wp-ai-advisor' ),
					'advisor'  => __( 'Advisor', 'wp-ai-advisor' ),
					'send'     => __( 'Send question', 'wp-ai-advisor' ),
					'close'    => __( 'Close conversation', 'wp-ai-advisor' ),
				),
			)
		);
	}

	/**
	 * Picks the question set to show, most specific first.
	 *
	 * Inline attribute, then a category set matching the page, then the general
	 * page set, then the site-wide set.
	 *
	 * @param string $inline  Pipe-separated questions from the shortcode.
	 * @param int    $post_id Post the widget is on, or 0.
	 * @return string[]
	 */
	private function suggestions_for( $inline, $post_id ) {
		if ( '' !== trim( (string) $inline ) ) {
			return array_values( array_filter( array_map( 'trim', explode( '|', (string) $inline ) ) ) );
		}

		if ( ! $post_id ) {
			return WP_AI_Advisor_Settings::suggestions();
		}

		$matched = WP_AI_Advisor_Settings::suggestions_for_terms(
			WP_AI_Advisor_Page_Context::term_ids( $post_id )
		);

		return $matched ? $matched : WP_AI_Advisor_Settings::context_suggestions();
	}

	/**
	 * Renders the widget.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		if ( ! WP_AI_Advisor_Settings::is_visible() ) {
			return '';
		}

		$settings = WP_AI_Advisor_Settings::all();

		$atts = shortcode_atts(
			array(
				'eyebrow'     => $settings['eyebrow'],
				'heading'     => $settings['heading'],
				'placeholder' => $settings['placeholder'],
				'theme'       => 'dark',
				'open'        => 'no',
				'lang'        => '',
				'context'     => 'auto',
				'layout'      => 'full',
				'suggestions' => '',
			),
			$atts,
			self::TAG
		);

		$eyebrow     = '' !== trim( (string) $atts['eyebrow'] ) ? $atts['eyebrow'] : __( 'Ask us', 'wp-ai-advisor' );
		$heading     = '' !== trim( (string) $atts['heading'] ) ? $atts['heading'] : __( 'Hi, what can we help you with?', 'wp-ai-advisor' );
		$placeholder = '' !== trim( (string) $atts['placeholder'] ) ? $atts['placeholder'] : __( 'Write here …', 'wp-ai-advisor' );
		$theme       = 'light' === $atts['theme'] ? 'light' : 'dark';
		$open        = in_array( strtolower( (string) $atts['open'] ), array( 'yes', 'true', '1' ), true );

		$language = WP_AI_Advisor_Language::normalize( $atts['lang'] );
		$language = $language ? $language : WP_AI_Advisor_Language::current();

		// On a singular view the widget adopts that page as its subject, so a
		// question about "this one" has something to resolve to.
		$post_id = 0;

		if ( 'none' !== $atts['context'] ) {
			$post_id = 'auto' === $atts['context']
				? ( is_singular() ? get_queried_object_id() : 0 )
				: absint( $atts['context'] );

			$post_id = WP_AI_Advisor_Page_Context::validate( $post_id );
		}

		$compact = 'compact' === $atts['layout'];

		$suggestions = $this->suggestions_for( $atts['suggestions'], $post_id );

		$widget_id = wp_unique_id( 'aiadv-' );

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		ob_start();
		?>
		<div
			class="aiadv aiadv--<?php echo esc_attr( $theme ); ?><?php echo $compact ? ' aiadv--compact' : ''; ?><?php echo $open ? ' is-open' : ''; ?>"
			id="<?php echo esc_attr( $widget_id ); ?>"
			lang="<?php echo esc_attr( $language ); ?>"
			data-language="<?php echo esc_attr( $language ); ?>"
			data-post-id="<?php echo (int) $post_id; ?>"
			style="--aiadv-accent: <?php echo esc_attr( $settings['accent'] ); ?>;"
		>
			<div class="aiadv__card">

				<div class="aiadv__intro">
					<div class="aiadv__intro-main">
						<p class="aiadv__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
						<h2 class="aiadv__heading"><?php echo esc_html( $heading ); ?></h2>

						<button type="button" class="aiadv__launch" aria-expanded="false" aria-controls="<?php echo esc_attr( $widget_id ); ?>-panel">
							<span class="screen-reader-text"><?php echo esc_html( $placeholder ); ?></span>
							<svg class="aiadv__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
							</svg>
						</button>

						<span class="aiadv__hint" aria-hidden="true">
							<svg viewBox="0 0 24 24" focusable="false"><path d="M12 5v14M5 12l7 7 7-7" /></svg>
						</span>
					</div>

					<ul class="aiadv__suggestions">
						<?php foreach ( $suggestions as $suggestion ) : ?>
							<li>
								<button type="button" class="aiadv__suggestion" data-question="<?php echo esc_attr( $suggestion ); ?>">
									<span><?php echo esc_html( $suggestion ); ?></span>
									<svg class="aiadv__suggestion-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
										<path d="M5 12h14M12 5l7 7-7 7" />
									</svg>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="aiadv__panel" id="<?php echo esc_attr( $widget_id ); ?>-panel"<?php echo $open ? '' : ' hidden'; ?>>
					<button type="button" class="aiadv__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close conversation', 'wp-ai-advisor' ); ?></span>
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12" /></svg>
					</button>

					<div class="aiadv__log" role="log" aria-live="polite"></div>

					<form class="aiadv__form">
						<label class="screen-reader-text" for="<?php echo esc_attr( $widget_id ); ?>-input">
							<?php echo esc_html( $placeholder ); ?>
						</label>
						<textarea
							id="<?php echo esc_attr( $widget_id ); ?>-input"
							class="aiadv__input"
							rows="1"
							required
							maxlength="<?php echo esc_attr( WP_AI_Advisor_REST_Controller::MAX_QUESTION ); ?>"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"></textarea>
						<button type="submit" class="aiadv__send">
							<span class="screen-reader-text"><?php esc_html_e( 'Send question', 'wp-ai-advisor' ); ?></span>
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
								<path d="M12 19V5M5 12l7-7 7 7" />
							</svg>
						</button>
					</form>

					<p class="aiadv__notice" role="alert" hidden></p>
				</div>

			</div>
		</div>
		<?php

		return ob_get_clean();
	}
}
