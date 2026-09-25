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
	const MAX_TOOL_ROUNDS = 4;
	const MODELS_CACHE    = 'wp_ai_advisor_models';
	const MODELS_TTL      = 12 * HOUR_IN_SECONDS;
	const LAST_ERROR      = 'wp_ai_advisor_last_error';

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
	 * @param string $language Language of the page the widget is on.
	 * @param string $page     Facts about the page the widget is on.
	 * @return array|WP_Error {
	 *     @type string $answer    Reply text.
	 *     @type bool   $grounded  Whether the answer came from the supplied context.
	 *     @type array  $followups Suggested follow-up questions.
	 *     @type array  $links     Navigation links: label, url.
	 * }
	 */
	public function answer( $question, array $context, array $history = array(), $language = '', $page = '' ) {
		$settings = WP_AI_Advisor_Settings::all();

		$system = WP_AI_Advisor_Settings::system_prompt()
			. "\n\n" . WP_AI_Advisor_Language::reply_instruction( $language )
			. "\n\n" . $this->grounding_rules( (bool) $settings['strict_mode'] );

		if ( '' !== $page ) {
			$system .= "\n\n" . $this->page_rules();
		}

		$system .= "\n\n" . $this->format_rules();

		if ( ! empty( $settings['enable_calculator'] ) ) {
			$system .= "\n\n" . $this->estimate_rules();
		}

		$messages = array( array( 'role' => 'system', 'content' => $system ) );

		foreach ( $history as $turn ) {
			$messages[] = $turn;
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $this->render_user_turn( $question, $context, $page ),
		);

		$calculations = array();

		// Not every chat model accepts structured outputs or tools. When one
		// refuses, the request is retried without them rather than failing:
		// a plain answer beats no answer.
		$plain = false;

		// The model may ask for arithmetic before it can answer, so the request
		// is a short loop rather than a single call.
		for ( $round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++ ) {
			$body = array(
				'model'       => $settings['model'],
				'messages'    => $messages,
				'temperature' => (float) $settings['temperature'],
				'max_tokens'  => (int) $settings['max_tokens'],
			);

			if ( ! $plain ) {
				$body['response_format'] = array(
					'type'        => 'json_schema',
					'json_schema' => array(
						'name'   => 'advisor_answer',
						'strict' => true,
						'schema' => $this->answer_schema(),
					),
				);
			}

			// On the last round the tools are withheld, so the model has no
			// choice but to produce the answer instead of asking for more sums.
			if ( ! $plain && ! empty( $settings['enable_calculator'] ) && $round < self::MAX_TOOL_ROUNDS ) {
				$body['tools'] = array( $this->calculator_tool() );
			}

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
				if ( ! $plain && $this->is_unsupported_feature( $parsed ) ) {
					// Retry this same round without the features it refused.
					$plain = true;
					$round--;

					continue;
				}

				return $parsed;
			}

			$message = isset( $parsed['choices'][0]['message'] ) ? $parsed['choices'][0]['message'] : array();

			if ( ! empty( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
				$messages[] = $message;

				foreach ( $message['tool_calls'] as $call ) {
					$outcome = $this->run_calculator( $call );

					$calculations[] = $outcome['record'];

					$messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => isset( $call['id'] ) ? $call['id'] : '',
						'content'      => wp_json_encode( $outcome['result'] ),
					);
				}

				continue;
			}

			return $this->finish( $message, $context, $calculations, $parsed );
		}

		return new WP_Error(
			'wp_ai_advisor_tool_loop',
			__( 'The assistant kept asking for calculations without answering. Please try again.', 'wp-ai-advisor' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Turns the model's final message into the answer payload.
	 *
	 * @param array $message      Assistant message.
	 * @param array $context      Retrieved context chunks.
	 * @param array $calculations Calculations performed this turn.
	 * @param array $parsed       Full decoded response.
	 * @return array|WP_Error
	 */
	private function finish( array $message, array $context, array $calculations, array $parsed ) {
		$content = isset( $message['content'] ) ? (string) $message['content'] : '';
		$decoded = json_decode( $content, true );

		// Structured output should always parse, but a plain-text reply is worth
		// showing rather than throwing away.
		if ( ! is_array( $decoded ) || ! isset( $decoded['answer'] ) ) {
			if ( '' === trim( $content ) ) {
				return new WP_Error(
					'wp_ai_advisor_bad_answer',
					__( 'The assistant returned an unreadable answer. Please try again.', 'wp-ai-advisor' ),
					array( 'status' => 502 )
				);
			}

			$decoded = array(
				'answer'   => $content,
				'grounded' => ! empty( $context ),
			);
		}

		return array(
			'answer'       => (string) $decoded['answer'],
			'grounded'     => ! empty( $decoded['grounded'] ),
			'followups'    => $this->clean_list( isset( $decoded['followups'] ) ? $decoded['followups'] : array() ),
			'links'        => $this->clean_links( isset( $decoded['links'] ) ? $decoded['links'] : array(), $context ),
			'calculations' => $calculations,
			'usage'        => isset( $parsed['usage'] ) ? $parsed['usage'] : array(),
		);
	}

	/**
	 * The calculator tool definition.
	 *
	 * @return array
	 */
	private function calculator_tool() {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => 'calculate',
				'description' => 'Evaluate an arithmetic expression exactly. Use this for every sum, product or total in an answer — never work the arithmetic out yourself.',
				'strict'      => true,
				'parameters'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'expression', 'label' ),
					'properties'           => array(
						'expression' => array(
							'type'        => 'string',
							'description' => 'Arithmetic only: digits, + - * / % ^ ( ) and round, min, max, abs, ceil, floor. Use a dot for decimals and no thousand separators. Example: 50 * 2.5 * 21 * 1.90 + 890',
						),
						'label'      => array(
							'type'        => 'string',
							'description' => 'What this step works out, in the language of the answer.',
						),
					),
				),
			),
		);
	}

	/**
	 * Runs one calculator tool call.
	 *
	 * @param array $call Tool call from the model.
	 * @return array {result, record}
	 */
	private function run_calculator( array $call ) {
		$arguments  = isset( $call['function']['arguments'] ) ? json_decode( $call['function']['arguments'], true ) : array();
		$expression = is_array( $arguments ) && isset( $arguments['expression'] ) ? (string) $arguments['expression'] : '';
		$label      = is_array( $arguments ) && isset( $arguments['label'] ) ? (string) $arguments['label'] : '';

		$value = WP_AI_Advisor_Calculator::evaluate( $expression );

		if ( is_wp_error( $value ) ) {
			return array(
				'result' => array(
					'ok'    => false,
					'error' => $value->get_error_message(),
				),
				'record' => array(
					'label'      => $label,
					'expression' => $expression,
					'error'      => $value->get_error_message(),
				),
			);
		}

		return array(
			'result' => array(
				'ok'    => true,
				'value' => $value,
			),
			'record' => array(
				'label'      => $label,
				'expression' => $expression,
				'value'      => $value,
			),
		);
	}

	/**
	 * Rules that let the advisor produce an estimate without inventing figures.
	 *
	 * @return string
	 */
	private function estimate_rules() {
		$rules = array(
			__( 'When the visitor asks what something would cost, or how much of something they would need, work out an estimate instead of refusing.', 'wp-ai-advisor' ),
			__( 'Every price, rate and fee must come from the excerpts. Never invent one, and never adjust one.', 'wp-ai-advisor' ),
			__( 'For anything the excerpts cannot tell you — how much people consume, how many working days a month has — use the ASSUMPTIONS below.', 'wp-ai-advisor' ),
			__( 'Do every calculation with the calculate tool. Never work arithmetic out yourself, even when it looks easy.', 'wp-ai-advisor' ),
			__( 'Write the estimate in this order: the assumptions you used, then what the numbers work out to, then the rounded total. Say which figures came from the site and which are assumptions.', 'wp-ai-advisor' ),
			__( 'Call it an estimate, and invite the visitor to ask for an exact quote.', 'wp-ai-advisor' ),
			__( 'If a price you need is not in the excerpts, say exactly which figure is missing and offer to put them in touch, rather than guessing at it.', 'wp-ai-advisor' ),
			__( 'An estimate built from excerpt prices plus the assumptions below is grounded: set "grounded" to true.', 'wp-ai-advisor' ),
		);

		$assumptions = trim( (string) WP_AI_Advisor_Settings::assumptions() );

		return "ESTIMATE RULES:\n- " . implode( "\n- ", $rules )
			. "\n\nASSUMPTIONS:\n" . $assumptions;
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
	 * The chat and embedding models this key can use.
	 *
	 * Fetched from the API rather than hard-coded: a baked-in list goes stale the
	 * moment a model ships or is retired, and then silently offers something that
	 * no longer works. Cached, because the settings screen should not make an
	 * HTTP request on every load.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array|WP_Error {chat, embedding, cached}
	 */
	public function list_models( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::MODELS_CACHE );

			if ( is_array( $cached ) ) {
				$cached['cached'] = true;

				return $cached;
			}
		}

		$parsed = $this->request( '/models', null );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$chat      = array();
		$embedding = array();

		foreach ( isset( $parsed['data'] ) ? (array) $parsed['data'] : array() as $model ) {
			$id = isset( $model['id'] ) ? (string) $model['id'] : '';

			if ( '' === $id ) {
				continue;
			}

			if ( false !== strpos( $id, 'embedding' ) ) {
				$embedding[] = $id;
				continue;
			}

			if ( self::is_chat_model( $id ) ) {
				$chat[] = $id;
			}
		}

		sort( $chat );
		sort( $embedding );

		$models = array(
			'chat'      => $chat,
			'embedding' => $embedding,
			'cached'    => false,
		);

		set_transient( self::MODELS_CACHE, $models, self::MODELS_TTL );

		return $models;
	}

	/**
	 * Whether a model id looks like one that can hold a conversation.
	 *
	 * The models endpoint lists everything the key can reach, including speech,
	 * image and moderation models that would fail on the first request.
	 *
	 * @param string $id Model id.
	 * @return bool
	 */
	private static function is_chat_model( $id ) {
		if ( ! preg_match( '/^(gpt-|chatgpt-|o[0-9])/i', $id ) ) {
			return false;
		}

		$excluded = array(
			'audio', 'realtime', 'transcribe', 'tts', 'whisper', 'image',
			'dall-e', 'moderation', 'search', 'instruct', 'vision-preview',
		);

		foreach ( $excluded as $needle ) {
			if ( false !== stripos( $id, $needle ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Clears the cached model list.
	 *
	 * @return void
	 */
	public static function forget_models() {
		delete_transient( self::MODELS_CACHE );
	}

	/**
	 * Verifies the key by listing models.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		// Listing models only proves the key works. The question that matters is
		// whether the chosen model answers the kind of request this plugin
		// actually sends, which is where a model choice goes wrong.
		$result = $this->answer(
			__( 'Reply with the single word OK.', 'wp-ai-advisor' ),
			array(
				array(
					'title'    => __( 'Connection test', 'wp-ai-advisor' ),
					'url'      => home_url(),
					'language' => WP_AI_Advisor_Language::site(),
					'content'  => __( 'This is a connection test. Reply with the single word OK.', 'wp-ai-advisor' ),
				),
			)
		);

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

			$this->remember_error( $path, $status, $detail );

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
			__( 'In "links", return up to three URLs taken verbatim from the excerpt metadata that the visitor should read next. Never write a URL that does not appear there. Write each label in the same language as your answer.', 'wp-ai-advisor' ),
			__( 'In "followups", suggest up to three short questions the visitor could ask next, answerable from this site. Write them in the same language as your answer.', 'wp-ai-advisor' ),
			__( 'Excerpts may be in a different language from the question. Use them anyway and translate what you need; never tell the visitor the information was in another language.', 'wp-ai-advisor' ),
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
	private function render_user_turn( $question, array $context, $page = '' ) {
		$prefix = '' !== $page ? "CURRENT PAGE:\n" . $page . "\n\n" : '';

		if ( empty( $context ) ) {
			return $prefix . "SITE CONTENT:\n(none found)\n\nQUESTION:\n" . $question;
		}

		$blocks = array();

		foreach ( $context as $chunk ) {
			$blocks[] = sprintf(
				"[%s]%s\nURL: %s\nLANGUAGE: %s\n%s",
				isset( $chunk['title'] ) ? $chunk['title'] : '',
				! empty( $chunk['pinned'] ) ? ' (the page the visitor is on)' : '',
				isset( $chunk['url'] ) ? $chunk['url'] : '',
				! empty( $chunk['language'] ) ? $chunk['language'] : 'unknown',
				isset( $chunk['content'] ) ? $chunk['content'] : ''
			);
		}

		return $prefix . "SITE CONTENT:\n" . implode( "\n\n---\n\n", $blocks ) . "\n\nQUESTION:\n" . $question;
	}

	/**
	 * How the answer text itself should read.
	 *
	 * @return string
	 */
	private function format_rules() {
		$rules = array(
			__( 'Write for someone standing in front of a screen: short paragraphs, no preamble, no sign-off.', 'wp-ai-advisor' ),
			__( 'Markdown is rendered, so use a bullet list when listing things, and bold sparingly for names or figures. Never use headings or tables.', 'wp-ai-advisor' ),
		);

		return "STYLE:\n- " . implode( "\n- ", $rules );
	}

	/**
	 * Rules for a widget sitting on a specific page.
	 *
	 * @return string
	 */
	private function page_rules() {
		$rules = array(
			__( 'The visitor is reading the page described under CURRENT PAGE. Words like "this", "it", "denne" or "dette" mean that page unless they clearly mean something else.', 'wp-ai-advisor' ),
			__( 'Answer about that page by default. Bring in other excerpts only to compare with it or when the question is plainly about something else.', 'wp-ai-advisor' ),
			__( 'The CURRENT PAGE facts are current and authoritative: they are read live from the site, so prefer them over any figure in an excerpt that disagrees.', 'wp-ai-advisor' ),
			__( 'Where a price range is given, quote it as a range in the words the shop used. Do not present one end of it as the price, and do not convert or recalculate it.', 'wp-ai-advisor' ),
			__( 'A question answered from the CURRENT PAGE facts is grounded: set "grounded" to true.', 'wp-ai-advisor' ),
		);

		return "CURRENT PAGE RULES:\n- " . implode( "\n- ", $rules );
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
			case 400:
			case 404:
			case 422:
				return __( 'OpenAI rejected the request. This usually means the chosen model does not accept how the assistant is configured.', 'wp-ai-advisor' );
			case 401:
			case 403:
				return __( 'OpenAI rejected the configured API key.', 'wp-ai-advisor' );
			case 429:
				return __( 'The assistant is busy right now. Please try again shortly.', 'wp-ai-advisor' );
			default:
				return __( 'The assistant is temporarily unavailable. Please try again.', 'wp-ai-advisor' );
		}
	}

	/**
	 * Remembers the last failure so the settings screen can show it.
	 *
	 * Without this the reason lives only in a browser console at the moment it
	 * happens, which is no use to whoever has to fix it afterwards.
	 *
	 * @param string $path   Endpoint that failed.
	 * @param int    $status HTTP status.
	 * @param string $detail Message from the API.
	 * @return void
	 */
	private function remember_error( $path, $status, $detail ) {
		set_transient(
			self::LAST_ERROR,
			array(
				'path'   => $path,
				'status' => (int) $status,
				'detail' => mb_substr( (string) $detail, 0, 500 ),
				'model'  => (string) WP_AI_Advisor_Settings::get( 'model' ),
				'when'   => current_time( 'mysql' ),
			),
			WEEK_IN_SECONDS
		);
	}

	/**
	 * The last recorded API failure, if any.
	 *
	 * @return array|null
	 */
	public static function last_error() {
		$error = get_transient( self::LAST_ERROR );

		return is_array( $error ) ? $error : null;
	}

	/**
	 * Clears the recorded failure.
	 *
	 * @return void
	 */
	public static function forget_error() {
		delete_transient( self::LAST_ERROR );
	}

	/**
	 * Whether a failure is the API refusing a feature the request asked for.
	 *
	 * Older chat models accept a conversation but not structured outputs or
	 * tools, and answer a request carrying either with a 400.
	 *
	 * @param WP_Error $error Error from a request.
	 * @return bool
	 */
	private function is_unsupported_feature( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) || empty( $data['detail'] ) ) {
			return false;
		}

		return (bool) preg_match(
			'/response_format|json_schema|schema|tool|function calling/i',
			(string) $data['detail']
		);
	}
}
