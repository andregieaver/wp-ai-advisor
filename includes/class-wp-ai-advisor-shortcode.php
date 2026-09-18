<?php
/**
 * Front-end widget: [ai_advisor] shortcode and its assets.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the advisor form and enqueues its assets on demand.
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
				'strings'  => array(
					'thinking' => __( 'Thinking…', 'wp-ai-advisor' ),
					'error'    => __( 'Something went wrong. Please try again.', 'wp-ai-advisor' ),
					'sources'  => __( 'Sources', 'wp-ai-advisor' ),
					'you'      => __( 'You', 'wp-ai-advisor' ),
					'advisor'  => __( 'Advisor', 'wp-ai-advisor' ),
				),
			)
		);
	}

	/**
	 * Renders the widget.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'       => __( 'Ask the advisor', 'wp-ai-advisor' ),
				'placeholder' => __( 'What would you like to know?', 'wp-ai-advisor' ),
				'button'      => __( 'Ask', 'wp-ai-advisor' ),
			),
			$atts,
			self::TAG
		);

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		$greeting  = trim( (string) WP_AI_Advisor_Settings::get( 'greeting', '' ) );
		$widget_id = wp_unique_id( 'wp-ai-advisor-' );

		ob_start();
		?>
		<div class="wp-ai-advisor" id="<?php echo esc_attr( $widget_id ); ?>">
			<?php if ( $atts['title'] ) : ?>
				<h2 class="wp-ai-advisor__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>

			<div class="wp-ai-advisor__log" role="log" aria-live="polite">
				<?php if ( '' !== $greeting ) : ?>
					<div class="wp-ai-advisor__message wp-ai-advisor__message--assistant">
						<p><?php echo esc_html( $greeting ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<form class="wp-ai-advisor__form">
				<label class="screen-reader-text" for="<?php echo esc_attr( $widget_id ); ?>-input">
					<?php echo esc_html( $atts['placeholder'] ); ?>
				</label>
				<textarea
					id="<?php echo esc_attr( $widget_id ); ?>-input"
					class="wp-ai-advisor__input"
					rows="2"
					required
					maxlength="<?php echo esc_attr( WP_AI_Advisor_REST_Controller::MAX_QUESTION ); ?>"
					placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"></textarea>
				<button type="submit" class="wp-ai-advisor__submit">
					<?php echo esc_html( $atts['button'] ); ?>
				</button>
			</form>

			<p class="wp-ai-advisor__notice" role="alert" hidden></p>
		</div>
		<?php

		return ob_get_clean();
	}
}
