<?php
/**
 * Builds the site-content context handed to Claude with each question.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds published content relevant to a question and renders it as plain text.
 */
class WP_AI_Advisor_Context {

	const EXCERPT_WORDS = 220;

	/**
	 * Posts matched by the most recent build() call.
	 *
	 * @var WP_Post[]
	 */
	private $matched = array();

	/**
	 * Builds a context block for a question.
	 *
	 * @param string $question Visitor question.
	 * @return string Empty string when nothing relevant is found.
	 */
	public function build( $question ) {
		$settings = WP_AI_Advisor_Settings::all();
		$limit    = (int) $settings['context_limit'];

		$this->matched = array();

		if ( $limit < 1 ) {
			return '';
		}

		$posts = $this->find_posts( $question, $settings['context_post_type'], $limit );

		/**
		 * Filters the posts used as context for a question.
		 *
		 * @param WP_Post[] $posts    Matched posts.
		 * @param string    $question Visitor question.
		 */
		$posts = apply_filters( 'wp_ai_advisor_context_posts', $posts, $question );

		if ( empty( $posts ) ) {
			return '';
		}

		$this->matched = $posts;

		$sections = array();

		foreach ( $posts as $post ) {
			$sections[] = sprintf(
				"## %s\nURL: %s\n\n%s",
				$post->post_title,
				get_permalink( $post ),
				$this->plain_excerpt( $post )
			);
		}

		return implode( "\n\n---\n\n", $sections );
	}

	/**
	 * Title and permalink for each post used by the last build() call.
	 *
	 * @return array[]
	 */
	public function sources() {
		$sources = array();

		foreach ( $this->matched as $post ) {
			$sources[] = array(
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
			);
		}

		return $sources;
	}

	/**
	 * Runs a keyword search over the configured post types.
	 *
	 * @param string   $question   Visitor question.
	 * @param string[] $post_types Post types to search.
	 * @param int      $limit      Maximum posts to return.
	 * @return WP_Post[]
	 */
	private function find_posts( $question, $post_types, $limit ) {
		$query = new WP_Query(
			array(
				's'                      => $question,
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'has_password'           => false,
			)
		);

		return $query->posts;
	}

	/**
	 * Renders a post body as trimmed plain text.
	 *
	 * Shortcodes and blocks are stripped so the model sees prose, not markup.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function plain_excerpt( $post ) {
		$content = $post->post_content;
		$content = excerpt_remove_blocks( $content );
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/u', ' ', $content );

		return wp_trim_words( trim( (string) $content ), self::EXCERPT_WORDS, '…' );
	}
}
