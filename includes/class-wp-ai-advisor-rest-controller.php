<?php
/**
 * REST endpoints for the widget and the admin knowledge-base tools.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers routes under wp-ai-advisor/v1.
 */
class WP_AI_Advisor_REST_Controller {

	const NAMESPACE_V1 = 'wp-ai-advisor/v1';
	const MAX_HISTORY  = 8;
	const MAX_QUESTION = 1000;

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
						'language' => array(
							'type'     => 'string',
							'required' => false,
							'default'  => '',
						),
					),
				),
			)
		);

		$admin_routes = array(
			'/build/start'  => 'build_start',
			'/crawl/start'  => 'crawl_start',
			'/crawl/step'   => 'crawl_step',
			'/local/start'  => 'local_start',
			'/local/step'   => 'local_step',
			'/index/step'   => 'index_step',
			'/documents'    => 'upload_document',
			'/sources/delete' => 'delete_source',
			'/sources/retry'  => 'retry_failed',
			'/sources/bulk'   => 'bulk_sources',
			'/clear'        => 'clear',
			'/test'         => 'test_connection',
		);

		foreach ( $admin_routes as $route => $callback ) {
			register_rest_route(
				self::NAMESPACE_V1,
				$route,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => $this->guarded( $callback ),
						'permission_callback' => array( $this, 'can_manage' ),
					),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Wraps an admin callback so it always answers with JSON.
	 *
	 * Third-party code on `the_content` and friends can echo markup or fatal
	 * outright; unwrapped, that reaches the browser as an HTML 500 and the
	 * progress loop dies on "Unexpected token '<'". Buffering and catching turns
	 * both into an ordinary REST error the loop can report and retry.
	 *
	 * @param string $method Method name on this class.
	 * @return callable
	 */
	private function guarded( $method ) {
		return function ( $request ) use ( $method ) {
			$depth = ob_get_level();

			ob_start();

			try {
				$result = $this->$method( $request );
			} catch ( Throwable $e ) {
				$result = new WP_Error(
					'wp_ai_advisor_step_failed',
					sprintf(
						/* translators: %s: error message from the failing code. */
						__( 'A plugin or theme failed while the advisor was reading this source: %s', 'wp-ai-advisor' ),
						$e->getMessage()
					),
					array( 'status' => 500 )
				);
			}

			$stray = '';

			while ( ob_get_level() > $depth ) {
				$stray = ob_get_clean() . $stray;
			}

			if ( '' !== trim( (string) $stray ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[wp-ai-advisor] discarded stray output: ' . mb_substr( trim( $stray ), 0, 500 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return $result;
		};
	}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------ */

	/**
	 * Administrator-only guard for the knowledge-base routes.
	 *
	 * @return true|WP_Error
	 */
	public function can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_ai_advisor_forbidden',
				__( 'You are not allowed to manage the advisor.', 'wp-ai-advisor' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Visitor guard: respects the admin-only toggle and the rate limit.
	 *
	 * @return true|WP_Error
	 */
	public function can_ask() {
		if ( ! WP_AI_Advisor_Settings::is_visible() ) {
			return new WP_Error(
				'wp_ai_advisor_hidden',
				__( 'The advisor is not available.', 'wp-ai-advisor' ),
				array( 'status' => 403 )
			);
		}

		return $this->check_rate_limit();
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

	/* ---------------------------------------------------------------------
	 * Visitor endpoint
	 * ------------------------------------------------------------------ */

	/**
	 * Answers a question from the indexed knowledge base.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ask( WP_REST_Request $request ) {
		$question = trim( (string) $request->get_param( 'question' ) );
		$history  = $this->sanitize_history( (array) $request->get_param( 'history' ) );
		$settings = WP_AI_Advisor_Settings::all();
		$strict   = (bool) $settings['strict_mode'];

		// The page the widget sits on decides the language, not the site locale:
		// on a translated site those differ, and the visitor is reading the page.
		$language = WP_AI_Advisor_Language::normalize( $request->get_param( 'language' ) );
		$language = $language ? $language : WP_AI_Advisor_Language::current();

		$this->record_request();

		$client = new WP_AI_Advisor_OpenAI_Client();

		if ( ! $client->is_configured() ) {
			return new WP_Error(
				'wp_ai_advisor_missing_key',
				__( 'The assistant is not configured yet.', 'wp-ai-advisor' ),
				array( 'status' => 500 )
			);
		}

		$vectors = $client->embed( array( $question ) );

		if ( is_wp_error( $vectors ) ) {
			$this->log_error( $vectors );

			return $vectors;
		}

		$context = empty( $vectors[0] )
			? array()
			: WP_AI_Advisor_Store::search( $vectors[0], (int) $settings['top_k'], (float) $settings['min_score'], $language );

		/**
		 * Filters the retrieved context before it reaches the model.
		 *
		 * @param array  $context  Retrieved chunks.
		 * @param string $question Visitor question.
		 * @param string $language Language the answer will be given in.
		 */
		$context = apply_filters( 'wp_ai_advisor_context', $context, $question, $language );

		// Nothing relevant indexed: refuse without spending a completion.
		if ( empty( $context ) && $strict ) {
			return rest_ensure_response( $this->refusal_payload() );
		}

		$result = $client->answer( $question, $context, $history, $language );

		if ( is_wp_error( $result ) ) {
			$this->log_error( $result );

			return $result;
		}

		if ( $strict && empty( $result['grounded'] ) ) {
			return rest_ensure_response( $this->refusal_payload() );
		}

		/**
		 * Fires after the advisor answers a question.
		 *
		 * @param string $question Visitor question.
		 * @param array  $result   Model result.
		 * @param array  $context  Retrieved chunks.
		 */
		do_action( 'wp_ai_advisor_answered', $question, $result, $context );

		return rest_ensure_response(
			array(
				'answer'    => $result['answer'],
				'links'     => $result['links'],
				'followups' => ! empty( $result['followups'] ) ? $result['followups'] : array(),
				'cta'       => $this->cta(),
				'grounded'  => (bool) $result['grounded'],
				'language'  => $language,
				'calculations' => isset( $result['calculations'] ) ? $result['calculations'] : array(),
			)
		);
	}

	/**
	 * The response used when a question falls outside the knowledge base.
	 *
	 * @return array
	 */
	private function refusal_payload() {
		return array(
			'answer'    => WP_AI_Advisor_Settings::refusal_message(),
			'links'     => array(),
			'followups' => WP_AI_Advisor_Settings::suggestions(),
			'cta'       => $this->cta(),
			'grounded'  => false,
		);
	}

	/**
	 * The configured call-to-action button, if any.
	 *
	 * @return array|null
	 */
	private function cta() {
		$label = trim( (string) WP_AI_Advisor_Settings::get( 'cta_label' ) );
		$url   = trim( (string) WP_AI_Advisor_Settings::get( 'cta_url' ) );

		if ( '' === $label || '' === $url ) {
			return null;
		}

		return array(
			'label' => $label,
			'url'   => $url,
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * Verifies the API key.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_connection() {
		$result = ( new WP_AI_Advisor_OpenAI_Client() )->test_connection();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => __( 'Connected to OpenAI.', 'wp-ai-advisor' ),
			)
		);
	}

	/**
	 * Seeds every phase the configured source mode calls for.
	 *
	 * Returns the phases the caller should then step through, in order.
	 *
	 * @return WP_REST_Response
	 */
	public function build_start() {
		$phases = array();

		if ( WP_AI_Advisor_Settings::uses( 'crawl' ) ) {
			( new WP_AI_Advisor_Crawler() )->start();

			$phases[] = 'crawl';
		}

		if ( WP_AI_Advisor_Settings::uses( 'local' ) ) {
			( new WP_AI_Advisor_Local_Content() )->start();

			$phases[] = 'local';
		}

		return rest_ensure_response(
			array(
				'phases' => $phases,
				'mode'   => WP_AI_Advisor_Settings::source_mode(),
				'stats'  => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Clears imported local content and queues the current posts.
	 *
	 * @return WP_REST_Response
	 */
	public function local_start() {
		$queued = ( new WP_AI_Advisor_Local_Content() )->start();

		return rest_ensure_response(
			array(
				'queued' => $queued,
				'stats'  => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Imports one queued local post.
	 *
	 * @return WP_REST_Response
	 */
	public function local_step() {
		$result = ( new WP_AI_Advisor_Local_Content() )->import_next();

		return rest_ensure_response(
			array(
				'done'  => null === $result,
				'item'  => $result,
				'stats' => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Clears indexed pages and seeds the crawl frontier.
	 *
	 * @return WP_REST_Response
	 */
	public function crawl_start() {
		$queued = ( new WP_AI_Advisor_Crawler() )->start();

		return rest_ensure_response(
			array(
				'queued' => $queued,
				'stats'  => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Fetches one queued page.
	 *
	 * @return WP_REST_Response
	 */
	public function crawl_step() {
		$result = ( new WP_AI_Advisor_Crawler() )->crawl_next();

		return rest_ensure_response(
			array(
				'done'   => null === $result,
				'item'   => $result,
				'stats'  => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Embeds one fetched source.
	 *
	 * @return WP_REST_Response
	 */
	public function index_step() {
		$result = ( new WP_AI_Advisor_Indexer() )->index_next();

		return rest_ensure_response(
			array(
				'done'  => null === $result,
				'item'  => $result,
				'stats' => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Accepts a document upload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_document( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error(
				'wp_ai_advisor_no_file',
				__( 'No file was uploaded.', 'wp-ai-advisor' ),
				array( 'status' => 400 )
			);
		}

		$result = ( new WP_AI_Advisor_Documents() )->handle_upload(
			$files['file'],
			'file',
			(string) $request->get_param( 'language' )
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );

			return $result;
		}

		return rest_ensure_response(
			array(
				'document' => $result,
				'stats'    => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Deletes one source and its chunks.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function delete_source( WP_REST_Request $request ) {
		WP_AI_Advisor_Store::delete_source( absint( $request->get_param( 'id' ) ) );

		return rest_ensure_response(
			array(
				'ok'    => true,
				'stats' => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Puts failed sources back in the queue so a resume picks them up.
	 *
	 * @return WP_REST_Response
	 */
	public function retry_failed() {
		$requeued = WP_AI_Advisor_Store::requeue_errors();

		return rest_ensure_response(
			array(
				'requeued' => $requeued,
				'stats'    => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Applies a bulk action to selected sources.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_sources( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids    = (array) $request->get_param( 'ids' );

		if ( 'duplicates' === $action ) {
			$ids    = WP_AI_Advisor_Store::duplicate_ids();
			$action = 'delete';
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return rest_ensure_response(
				array(
					'affected' => 0,
					'stats'    => WP_AI_Advisor_Store::stats(),
				)
			);
		}

		switch ( $action ) {
			case 'delete':
				$affected = WP_AI_Advisor_Store::delete_sources( $ids );
				break;

			case 'requeue':
				$affected = WP_AI_Advisor_Store::requeue_sources( $ids );
				break;

			default:
				return new WP_Error(
					'wp_ai_advisor_bad_action',
					__( 'Unknown bulk action.', 'wp-ai-advisor' ),
					array( 'status' => 400 )
				);
		}

		return rest_ensure_response(
			array(
				'affected' => $affected,
				'stats'    => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Empties the knowledge base.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function clear( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );

		WP_AI_Advisor_Store::clear( in_array( $type, array( 'page', 'local', 'document' ), true ) ? $type : '' );

		return rest_ensure_response(
			array(
				'ok'    => true,
				'stats' => WP_AI_Advisor_Store::stats(),
			)
		);
	}

	/**
	 * Knowledge-base counts and source list.
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		return rest_ensure_response(
			array(
				'stats'   => WP_AI_Advisor_Store::stats(),
				'sources' => WP_AI_Advisor_Store::list_sources(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Normalises client-supplied history into API message shape.
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

			$clean[] = array(
				'role'    => 'assistant' === $entry['role'] ? 'assistant' : 'user',
				'content' => mb_substr( sanitize_textarea_field( (string) $entry['content'] ), 0, 2000 ),
			);
		}

		$clean = array_slice( $clean, -self::MAX_HISTORY );

		while ( ! empty( $clean ) && 'user' !== $clean[0]['role'] ) {
			array_shift( $clean );
		}

		return $clean;
	}

	/**
	 * Rejects a client that has exceeded the hourly question budget.
	 *
	 * @return true|WP_Error
	 */
	private function check_rate_limit() {
		$limit = (int) WP_AI_Advisor_Settings::get( 'rate_limit' );

		if ( $limit < 1 || current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( (int) get_transient( $this->rate_limit_key() ) >= $limit ) {
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

		if ( $limit < 1 || current_user_can( 'manage_options' ) ) {
			return;
		}

		$key = $this->rate_limit_key();

		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Transient key identifying the caller.
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
