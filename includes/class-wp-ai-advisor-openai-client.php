<?php
/**
 * OpenAI API client built on the WordPress HTTP API.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the chat completions and embeddings endpoints.
 */
class WP_AI_Advisor_OpenAI_Client {

	const API_BASE        = 'https://api.openai.com/v1';
	const REQUEST_TIMEOUT = 60;
	const EMBED_BATCH     = 64;

	/**
	 * API key used for requests.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key OpenAI API key. Falls back to the stored setting.
	 */
	public function __construct( $api_key = '' ) {
		$this->api_key = $api_key ? $api_key : WP_AI_Advisor_Settings::api_key();
	}

	/**
	 * Whether the client has credentials.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->api_key );
	}

	/**
	 * Answers a question from retrieved site context.
	 *
	 * The model is asked for structured JSON so the widget can render follow-up
	 * chips and navigation links alongside the answer.
	 *
	 * @param string $question Visitor question.
	 * @param array  $context  Retrieved chunks: title, url, content.
	 * @param array  $history  Prior turns: role, content.
	 * @return array|WP_Error {
	 *     @type string $answer    Reply text.
	 *     @type bool   $grounded  Whether the answer came from the supplied context.
	 *     @type array  $followups Suggested follow-up questions.
	 *     @type array  $links     Navigation links: label, url.
	 * }
	 */
	public function answer( $question, array $context, array $history = array() ) {
		$settings = WP_AI_Advisor_Settings::all();

		$system = WP_AI_Advisor_Settings::system_prompt() . "\n\n" . $this->grounding_rules( (bool) $settings['strict_mode'] );

		$messages = array( array( 'role' => 'system', 'content' => $system ) );

		foreach ( $history as $turn ) {
			$messages[] = $turn;
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $this->render_user_turn( $question, $context ),
		);

		$body = array(
			'model'           => $settings['model'],
			'messages'        => $messages,
			'temperature'     => (float) $settings['temperature'],
			'max_tokens'      => (int) $settings['max_tokens'],
			'response_format' => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'advisor_answer',
					'strict' => true,
					'schema' => $this->answer_schema(),
				),
			),
		);

		/**
		 * Filters the chat completion request body.
		 *
		 * @param array  $body     Request body.
		 * @param string $question Visitor question.
		 * @param array  $context  Retrieved context chunks.
		 */
		$body = apply_filters( 'wp_ai_advisor_request_body', $body, $question, $context );

		$parsed = $this->request( '/chat/completions', $body );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$content = isset( $parsed['choices'][0]['message']['content'] ) ? $parsed['choices'][0]['message']['content'] : '';
		$decoded = json_decode( $content, true );

		if ( ! is_array( $decoded ) || empty( $decoded['answer'] ) ) {
			return new WP_Error(
				'wp_ai_advisor_bad_answer',
				__( 'The assistant returned an unreadable answer. Please try again.', 'wp-ai-advisor' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'answer'    => (string) $decoded['answer'],
			'grounded'  => ! empty( $decoded['grounded'] ),
			'followups' => $this->clean_list( isset( $decoded['followups'] ) ? $decoded['followups'] : array() ),
			'links'     => $this->clean_links( isset( $decoded['links'] ) ? $decoded['links'] : array(), $context ),
			'usage'     => isset( $parsed['usage'] ) ? $parsed['usage'] : array(),
		);
	}

	/**
	 * Creates embeddings for a batch of strings.
	 *
	 * @param string[] $inputs Texts to embed.
	 * @return array|WP_Error List of float vectors, in input order.
	 */
	public function embed( array $inputs ) {
		$inputs = array_values( array_filter( array_map( 'strval', $inputs ), 'strlen' ) );

		if ( empty( $inputs ) ) {
			return array();
		}

		$vectors = array();

		foreach ( array_chunk( $inputs, self::EMBED_BATCH ) as $batch ) {
			$parsed = $this->request(
				'/embeddings',
				array(
					'model' => WP_AI_Advisor_Settings::get( 'embedding_model' ),
					'input' => $batch,
				)
			);

			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			if ( empty( $parsed['data'] ) || ! is_array( $parsed['data'] ) ) {
				return new WP_Error(
					'wp_ai_advisor_bad_embeddings',
					__( 'The embedding service returned no vectors.', 'wp-ai-advisor' ),
					array( 'status' => 502 )
				);
			}

			// The API may return items out of order; index is authoritative.
			$ordered = array();

			foreach ( $parsed['data'] as $item ) {
				$index             = isset( $item['index'] ) ? (int) $item['index'] : count( $ordered );
				$ordered[ $index ] = isset( $item['embedding'] ) ? $item['embedding'] : array();
			}

			ksort( $ordered );

			$vectors = array_merge( $vectors, array_values( $ordered ) );
		}

		return $vectors;
	}

	/**
	 * Verifies the key by listing models.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$result = $this->request( '/models', null );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Performs a request against the API.
	 *
	 * @param string     $path Endpoint path, with leading slash.
	 * @param array|null $body Request body, or null for a GET.
	 * @return array|WP_Error Decoded response body.
	 */
	private function request( $path, $body ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'wp_ai_advisor_missing_key',
				__( 'No OpenAI API key is configured.', 'wp-ai-advisor' ),
				array( 'status' => 500 )
			);
		}

		$args = array(
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null === $body ) {
			$args['method'] = 'GET';
		} else {
			$args['method'] = 'POST';
			$args['body']   = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_ai_advisor_http_error',
				__( 'Could not reach OpenAI. Please try again.', 'wp-ai-advisor' ),
				array(
					'status' => 502,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

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

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'wp_ai_advisor_bad_response',
				__( 'OpenAI returned an unreadable response.', 'wp-ai-advisor' ),
				array( 'status' => 502 )
			);
		}

		return $parsed;
	}

	/**
	 * Grounding instructions appended to the system prompt.
	 *
	 * @param bool $strict Whether off-topic questions must be refused.
	 * @return string
	 */
	private function grounding_rules( $strict ) {
		$rules = array(
			__( 'Use only the SITE CONTENT excerpts below. They are the only source you may draw facts from.', 'wp-ai-advisor' ),
			__( 'Never invent prices, opening hours, addresses, phone numbers, availability or product details. If an excerpt does not state it, you do not know it.', 'wp-ai-advisor' ),
			__( 'Set "grounded" to true only when the excerpts actually support your answer. Set it to false when they do not.', 'wp-ai-advisor' ),
			__( 'In "links", return up to three URLs taken verbatim from the excerpt metadata that the visitor should read next. Never write a URL that does not appear there.', 'wp-ai-advisor' ),
			__( 'In "followups", suggest up to three short questions the visitor could ask next, answerable from this site.', 'wp-ai-advisor' ),
		);

		if ( $strict ) {
			$rules[] = __( 'If the question is not about this website, or the excerpts do not cover it, set "grounded" to false and say briefly that you can only help with questions about this site. Do not answer from general knowledge.', 'wp-ai-advisor' );
		}

		return "RULES:\n- " . implode( "\n- ", $rules );
	}

	/**
	 * Renders the question plus its retrieved context as a single user turn.
	 *
	 * @param string $question Visitor question.
	 * @param array  $context  Retrieved chunks.
	 * @return string
	 */
	private function render_user_turn( $question, array $context ) {
		if ( empty( $context ) ) {
			return "SITE CONTENT:\n(none found)\n\nQUESTION:\n" . $question;
		}

		$blocks = array();

		foreach ( $context as $chunk ) {
			$blocks[] = sprintf(
				"[%s]\nURL: %s\n%s",
				isset( $chunk['title'] ) ? $chunk['title'] : '',
				isset( $chunk['url'] ) ? $chunk['url'] : '',
				isset( $chunk['content'] ) ? $chunk['content'] : ''
			);
		}

		return "SITE CONTENT:\n" . implode( "\n\n---\n\n", $blocks ) . "\n\nQUESTION:\n" . $question;
	}

	/**
	 * JSON schema for the structured answer.
	 *
	 * @return array
	 */
	private function answer_schema() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'answer', 'grounded', 'followups', 'links' ),
			'properties'           => array(
				'answer'    => array(
					'type'        => 'string',
					'description' => 'The reply shown to the visitor.',
				),
				'grounded'  => array(
					'type'        => 'boolean',
					'description' => 'True when the supplied excerpts support the answer.',
				),
				'followups' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'links'     => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'label', 'url' ),
						'properties'           => array(
							'label' => array( 'type' => 'string' ),
							'url'   => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Trims and caps a list of suggestion strings.
	 *
	 * @param mixed $list Raw value from the model.
	 * @return string[]
	 */
	private function clean_list( $list ) {
		if ( ! is_array( $list ) ) {
			return array();
		}

		$clean = array();

		foreach ( $list as $item ) {
			$item = trim( wp_strip_all_tags( (string) $item ) );

			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array_slice( array_unique( $clean ), 0, 3 );
	}

	/**
	 * Keeps only links whose URL actually appears in the retrieved context.
	 *
	 * The model is told not to invent URLs; this enforces it rather than trusting it.
	 *
	 * @param mixed $links   Raw value from the model.
	 * @param array $context Retrieved chunks.
	 * @return array
	 */
	private function clean_links( $links, array $context ) {
		if ( ! is_array( $links ) ) {
			return array();
		}

		$allowed = array();

		foreach ( $context as $chunk ) {
			if ( ! empty( $chunk['url'] ) ) {
				$allowed[ untrailingslashit( $chunk['url'] ) ] = true;
			}

			if ( ! empty( $chunk['links'] ) && is_array( $chunk['links'] ) ) {
				foreach ( $chunk['links'] as $link ) {
					if ( ! empty( $link['url'] ) ) {
						$allowed[ untrailingslashit( $link['url'] ) ] = true;
					}
				}
			}
		}

		$clean = array();

		foreach ( $links as $link ) {
			if ( ! is_array( $link ) || empty( $link['url'] ) || empty( $link['label'] ) ) {
				continue;
			}

			$url = untrailingslashit( esc_url_raw( (string) $link['url'] ) );

			if ( ! $url || ! isset( $allowed[ $url ] ) ) {
				continue;
			}

			$clean[ $url ] = array(
				'label' => trim( wp_strip_all_tags( (string) $link['label'] ) ),
				'url'   => $url,
			);
		}

		return array_slice( array_values( $clean ), 0, 3 );
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
				return __( 'OpenAI rejected the configured API key.', 'wp-ai-advisor' );
			case 429:
				return __( 'The assistant is busy right now. Please try again shortly.', 'wp-ai-advisor' );
			default:
				return __( 'The assistant is temporarily unavailable. Please try again.', 'wp-ai-advisor' );
		}
	}
}
