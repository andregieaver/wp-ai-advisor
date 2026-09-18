<?php
/**
 * REST endpoint backing the front-end advisor widget.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles /wp-json/wp-ai-advisor/v1/ask.
 */
class WP_AI_Advisor_REST_Controller {

	const NAMESPACE_V1 = 'wp-ai-advisor/v1';
	const MAX_HISTORY  = 10;
	const MAX_QUESTION = 2000;

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/ask',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ask' ),
					'permission_callback' => array( $this, 'can_ask' ),
					'args'                => array(
						'question' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
							'validate_callback' => array( $this, 'validate_question' ),
						),
						'history'  => array(
							'type'     => 'array',
							'required' => false,
							'default'  => array(),
						),
					),
				),
			)
		);
	}

	/**
	 * Validates the question length.
	 *
	 * @param mixed $value Submitted value.
	 * @return true|WP_Error
	 */
	public function validate_question( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return new WP_Error(
				'wp_ai_advisor_empty_question',
				__( 'Please enter a question.', 'wp-ai-advisor' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $value ) > self::MAX_QUESTION ) {
			return new WP_Error(
				'wp_ai_advisor_question_too_long',
				sprintf(
					/* translators: %d: maximum number of characters. */
					__( 'Questions are limited to %d characters.', 'wp-ai-advisor' ),
					self::MAX_QUESTION
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Permission check: optional login requirement plus per-client rate limiting.
	 *
	 * @return true|WP_Error
	 */
	public function can_ask() {
		if ( WP_AI_Advisor_Settings::get( 'require_login' ) && ! is_user_logged_in() ) {
			return new WP_Error(
				'wp_ai_advisor_login_required',
				__( 'Please log in to use the advisor.', 'wp-ai-advisor' ),
				array( 'status' => 401 )
			);
		}

		return $this->check_rate_limit();
	}

	/**
	 * Handles a question.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ask( WP_REST_Request $request ) {
		$question = trim( (string) $request->get_param( 'question' ) );
		$history  = $this->sanitize_history( (array) $request->get_param( 'history' ) );

		$context_builder = new WP_AI_Advisor_Context();
		$context         = $context_builder->build( $question );
		$system          = WP_AI_Advisor_Settings::system_prompt();

		if ( '' !== $context ) {
			$system .= "\n\n" . __( 'Site content you may use to answer:', 'wp-ai-advisor' ) . "\n\n" . $context;
		} else {
			$system .= "\n\n" . __( 'No matching site content was found for this question. Say so rather than guessing.', 'wp-ai-advisor' );
		}

		$messages   = $history;
		$messages[] = array(
			'role'    => 'user',
			'content' => $question,
		);

		// Counted before the call so failed attempts cannot be looped for free.
		$this->record_request();

		$result = ( new WP_AI_Advisor_Claude_Client() )->send( $messages, $system );

		if ( is_wp_error( $result ) ) {
			$this->log_error( $result );

			return $result;
		}

		/**
		 * Fires after the advisor answers a question.
		 *
		 * @param string $question Visitor question.
		 * @param array  $result   Client result: text, usage, model.
		 */
		do_action( 'wp_ai_advisor_answered', $question, $result );

		return rest_ensure_response(
			array(
				'answer'  => $result['text'],
				'sources' => $context_builder->sources(),
			)
		);
	}

	/**
	 * Normalises client-supplied conversation history into API message shape.
	 *
	 * Only the last few turns are kept, and roles are forced to user/assistant.
	 *
	 * @param array $history Raw history from the request.
	 * @return array
	 */
	private function sanitize_history( array $history ) {
		$clean = array();

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['role'] ) || empty( $entry['content'] ) ) {
				continue;
			}

			$role = 'assistant' === $entry['role'] ? 'assistant' : 'user';

			$clean[] = array(
				'role'    => $role,
				'content' => sanitize_textarea_field( (string) $entry['content'] ),
			);
		}

		$clean = array_slice( $clean, -self::MAX_HISTORY );

		// The conversation must start with a user turn.
		while ( ! empty( $clean ) && 'user' !== $clean[0]['role'] ) {
			array_shift( $clean );
		}

		return $clean;
	}

	/**
	 * Rejects a client that has exceeded the configured hourly request budget.
	 *
	 * @return true|WP_Error
	 */
	private function check_rate_limit() {
		$limit = (int) WP_AI_Advisor_Settings::get( 'rate_limit' );

		if ( $limit < 1 ) {
			return true;
		}

		$count = (int) get_transient( $this->rate_limit_key() );

		if ( $count >= $limit ) {
			return new WP_Error(
				'wp_ai_advisor_rate_limited',
				__( 'You have reached the question limit for now. Please try again later.', 'wp-ai-advisor' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Increments the caller's request counter for the current hour.
	 *
	 * @return void
	 */
	private function record_request() {
		$limit = (int) WP_AI_Advisor_Settings::get( 'rate_limit' );

		if ( $limit < 1 ) {
			return;
		}

		$key   = $this->rate_limit_key();
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Transient key identifying the caller: user ID when logged in, hashed IP otherwise.
	 *
	 * @return string
	 */
	private function rate_limit_key() {
		if ( is_user_logged_in() ) {
			return 'wp_ai_advisor_rl_u' . get_current_user_id();
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return 'wp_ai_advisor_rl_' . md5( $ip );
	}

	/**
	 * Writes API failures to the debug log when WP_DEBUG is on.
	 *
	 * @param WP_Error $error Error to log.
	 * @return void
	 */
	private function log_error( WP_Error $error ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$data   = $error->get_error_data();
		$detail = is_array( $data ) && ! empty( $data['detail'] ) ? ' ' . $data['detail'] : '';

		error_log( '[wp-ai-advisor] ' . $error->get_error_code() . ': ' . $error->get_error_message() . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
