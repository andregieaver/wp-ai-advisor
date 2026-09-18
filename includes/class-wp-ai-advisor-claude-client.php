<?php
/**
 * Claude Messages API client built on the WordPress HTTP API.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests to POST /v1/messages and normalises the response.
 */
class WP_AI_Advisor_Claude_Client {

	const ENDPOINT        = 'https://api.anthropic.com/v1/messages';
	const API_VERSION     = '2023-06-01';
	const FALLBACK_BETA   = 'server-side-fallback-2026-07-01';
	const REQUEST_TIMEOUT = 60;

	/**
	 * API key used for requests.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Anthropic API key. Falls back to the stored setting.
	 */
	public function __construct( $api_key = '' ) {
		$this->api_key = $api_key ? $api_key : WP_AI_Advisor_Settings::api_key();
	}

	/**
	 * Whether the client has credentials to talk to the API.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->api_key );
	}

	/**
	 * Sends a conversation to Claude and returns the reply text.
	 *
	 * @param array  $messages Messages array, each {role, content}.
	 * @param string $system   System prompt.
	 * @param array  $args     Optional overrides: model, max_tokens, effort.
	 * @return array|WP_Error {
	 *     @type string $text  Assistant reply text.
	 *     @type array  $usage Token usage reported by the API.
	 *     @type string $model Model that served the request.
	 * }
	 */
	public function send( array $messages, $system, array $args = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_ai_advisor_missing_key',
				__( 'The AI advisor is not configured yet. Add an Anthropic API key in Settings.', 'wp-ai-advisor' ),
				array( 'status' => 500 )
			);
		}

		$settings = WP_AI_Advisor_Settings::all();

		$body = array(
			'model'      => isset( $args['model'] ) ? $args['model'] : $settings['model'],
			'max_tokens' => isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : (int) $settings['max_tokens'],
			'system'     => $system,
			'messages'   => $messages,
			// Adaptive thinking: Claude decides how much to reason per request.
			'thinking'   => array( 'type' => 'adaptive' ),
			// Effort trades answer depth against token spend.
			'output_config' => array(
				'effort' => isset( $args['effort'] ) ? $args['effort'] : $settings['effort'],
			),
			// Route refusals to a fallback model instead of returning nothing.
			'fallbacks'  => 'default',
		);

		/**
		 * Filters the request body before it is sent to the Messages API.
		 *
		 * @param array $body     Request body.
		 * @param array $messages Conversation messages.
		 */
		$body = apply_filters( 'wp_ai_advisor_request_body', $body, $messages );

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
					'anthropic-beta'    => self::FALLBACK_BETA,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_ai_advisor_http_error',
				__( 'Could not reach the AI service. Please try again.', 'wp-ai-advisor' ),
				array(
					'status' => 502,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'wp_ai_advisor_bad_response',
				__( 'The AI service returned an unreadable response.', 'wp-ai-advisor' ),
				array( 'status' => 502 )
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			$detail = isset( $parsed['error']['message'] ) ? $parsed['error']['message'] : '';

			return new WP_Error(
				'wp_ai_advisor_api_error',
				$this->message_for_status( $status ),
				array(
					'status' => 429 === $status ? 429 : 502,
					'detail' => $detail,
				)
			);
		}

		// Safety classifiers can decline a request with HTTP 200.
		if ( isset( $parsed['stop_reason'] ) && 'refusal' === $parsed['stop_reason'] ) {
			return new WP_Error(
				'wp_ai_advisor_refusal',
				__( 'The assistant could not answer that question. Try rephrasing it.', 'wp-ai-advisor' ),
				array(
					'status' => 422,
					'detail' => isset( $parsed['stop_details']['category'] ) ? $parsed['stop_details']['category'] : '',
				)
			);
		}

		$text = $this->extract_text( $parsed );

		if ( '' === $text ) {
			return new WP_Error(
				'wp_ai_advisor_empty_reply',
				__( 'The assistant returned an empty answer. Try asking again.', 'wp-ai-advisor' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'text'  => $text,
			'usage' => isset( $parsed['usage'] ) ? $parsed['usage'] : array(),
			'model' => isset( $parsed['model'] ) ? $parsed['model'] : '',
		);
	}

	/**
	 * Concatenates the text blocks of a response, skipping thinking and tool blocks.
	 *
	 * @param array $parsed Decoded response body.
	 * @return string
	 */
	private function extract_text( array $parsed ) {
		if ( empty( $parsed['content'] ) || ! is_array( $parsed['content'] ) ) {
			return '';
		}

		$parts = array();

		foreach ( $parsed['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$parts[] = $block['text'];
			}
		}

		return trim( implode( "\n\n", $parts ) );
	}

	/**
	 * Visitor-facing message for an HTTP failure.
	 *
	 * @param int $status HTTP status code.
	 * @return string
	 */
	private function message_for_status( $status ) {
		switch ( $status ) {
			case 401:
			case 403:
				return __( 'The AI service rejected the configured API key.', 'wp-ai-advisor' );
			case 429:
				return __( 'The AI service is rate limiting requests. Please try again shortly.', 'wp-ai-advisor' );
			default:
				return __( 'The AI service is temporarily unavailable. Please try again.', 'wp-ai-advisor' );
		}
	}
}
