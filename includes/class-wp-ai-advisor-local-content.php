<?php
/**
 * Local content mode: indexes posts straight from this WordPress install.
 *
 * The crawler sees what a visitor's browser sees, which is the right source for
 * rendered navigation. This mode reads the database instead: no HTTP, no page
 * budget, and it reaches content a crawl would miss — drafts of new pages are
 * still excluded, but private post types, unlinked pages and posts behind a
 * slow front end are all indexable. On Polylang and WPML sites every
 * translation is indexed, each tagged with its own language.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues and imports local posts.
 */
class WP_AI_Advisor_Local_Content {

	const MAX_POSTS = 5000;

	/**
	 * How many times one post may be attempted before it is set aside.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Clears previously imported local sources and queues the current ones.
	 *
	 * @return int Number of posts queued.
	 */
	public function start() {
		WP_AI_Advisor_Store::clear( WP_AI_Advisor_Store::TYPE_LOCAL );

		$post_types = WP_AI_Advisor_Settings::local_post_types();

		if ( empty( $post_types ) ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_POSTS,
				'fields'                 => 'ids',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// Polylang scopes queries to the current language unless told otherwise;
				// the knowledge base wants every translation.
				'lang'                   => '',
			)
		);

		/**
		 * Filters the post IDs imported in local content mode.
		 *
		 * @param int[] $post_ids   Post IDs about to be queued.
		 * @param array $post_types Post types queried.
		 */
		$post_ids = (array) apply_filters( 'wp_ai_advisor_local_post_ids', $query->posts, $post_types );

		// In both-modes the crawl usually covers the same URLs; indexing them a
		// second time costs tokens and returns near-duplicate passages.
		$crawled = WP_AI_Advisor_Settings::get( 'skip_crawled' )
			? WP_AI_Advisor_Store::urls_of_type( WP_AI_Advisor_Store::TYPE_PAGE )
			: array();

		$queued = 0;

		foreach ( $post_ids as $post_id ) {
			$permalink = get_permalink( $post_id );

			if ( isset( $crawled[ WP_AI_Advisor_Store::normalize_url( $permalink ) ] ) ) {
				continue;
			}

			if ( WP_AI_Advisor_Store::queue_post( (int) $post_id, $permalink, get_the_title( $post_id ) ) ) {
				$queued++;
			}
		}

		return $queued;
	}

	/**
	 * Imports the next queued post.
	 *
	 * @return array|null Progress info, or null when the queue is empty.
	 */
	public function import_next() {
		$rows = WP_AI_Advisor_Store::next_by_status(
			WP_AI_Advisor_Store::STATUS_PENDING,
			1,
			WP_AI_Advisor_Store::TYPE_LOCAL
		);

		if ( empty( $rows ) ) {
			return null;
		}

		$source = $rows[0];

		if ( WP_AI_Advisor_Store::record_attempt( $source['id'] ) > self::MAX_ATTEMPTS ) {
			WP_AI_Advisor_Store::mark_error(
				$source['id'],
				__( 'Gave up after repeated failures while rendering this post. Turn off "Render with theme filters" and try again.', 'wp-ai-advisor' )
			);

			return array(
				'title'  => $source['title'],
				'status' => 'skipped',
			);
		}

		$post = get_post( (int) $source['ref'] );

		if ( ! $post || 'publish' !== $post->post_status ) {
			WP_AI_Advisor_Store::mark_error( $source['id'], __( 'The post is no longer published.', 'wp-ai-advisor' ) );

			return array(
				'title'  => $source['title'],
				'status' => 'skipped',
			);
		}

		$extracted = $this->extract( $post );

		if ( '' === $extracted['content'] ) {
			WP_AI_Advisor_Store::mark_error( $source['id'], __( 'No readable text found.', 'wp-ai-advisor' ) );

			return array(
				'title'  => $source['title'],
				'status' => 'skipped',
			);
		}

		WP_AI_Advisor_Store::save_fetched( $source['id'], $extracted );

		return array(
			'title'  => $extracted['title'],
			'url'    => $source['url'],
			'status' => 'imported',
			'links'  => count( $extracted['links'] ),
		);
	}

	/**
	 * Renders one post to indexable text plus its internal links.
	 *
	 * Content runs through `the_content` so shortcodes and blocks resolve to the
	 * text a visitor would actually read.
	 *
	 * @param WP_Post $post Post object.
	 * @return array {title, content, links, language}
	 */
	public function extract( $post ) {
		$title = get_the_title( $post );

		$html  = $this->render_content( $post );

		$parts = array( $title );

		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';

		if ( $excerpt ) {
			$parts[] = $excerpt;
		}

		$parts[] = WP_AI_Advisor_Text::to_text( $html );

		$fields = WP_AI_Advisor_Page_Context::indexable_fields( $post->ID );

		if ( $fields ) {
			$parts[] = $fields;
		}

		$terms = $this->terms( $post );

		if ( $terms ) {
			$parts[] = $terms;
		}

		return array(
			'title'    => $title ? $title : (string) get_permalink( $post ),
			'content'  => WP_AI_Advisor_Text::tidy( implode( "\n\n", array_filter( $parts ) ) ),
			'links'    => WP_AI_Advisor_Text::links( $html, get_permalink( $post ), WP_AI_Advisor_Settings::site_url() ),
			'language' => WP_AI_Advisor_Language::of_post( $post->ID ),
		);
	}

	/**
	 * Renders post content, through `the_content` when that is safe to do.
	 *
	 * That filter is a hostile place to stand outside a real front-end request.
	 * Themes, page builders and commerce plugins hook it and may echo markup
	 * directly - which corrupts the JSON response - or call template functions
	 * that do not exist in a REST context, which is a fatal. So the filter runs
	 * inside an output buffer, any stray output is discarded, and a crash falls
	 * back to rendering the stored blocks instead of taking the request with it.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Rendered HTML.
	 */
	private function render_content( $post ) {
		if ( ! WP_AI_Advisor_Settings::get( 'render_filters' ) ) {
			return $this->render_raw( $post );
		}

		$previous = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$depth    = ob_get_level();

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		ob_start();

		try {
			$html = (string) apply_filters( 'the_content', $post->post_content );
		} catch ( Throwable $e ) {
			$html = $this->render_raw( $post );
		}

		// Discard anything a filter echoed rather than returned, and unwind any
		// buffer a crashing filter left open.
		while ( ob_get_level() > $depth ) {
			ob_end_clean();
		}

		wp_reset_postdata();

		if ( null === $previous ) {
			unset( $GLOBALS['post'] );
		} else {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		return '' !== trim( $html ) ? $html : $this->render_raw( $post );
	}

	/**
	 * Renders stored content without letting other plugins near it.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function render_raw( $post ) {
		$html = $post->post_content;

		if ( function_exists( 'do_blocks' ) ) {
			$html = do_blocks( $html );
		}

		return strip_shortcodes( (string) $html );
	}

	/**
	 * Renders a post's public taxonomy terms as a single line.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function terms( $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		$lines      = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}

			$names = wp_get_post_terms( $post->ID, $taxonomy->name, array( 'fields' => 'names' ) );

			if ( is_wp_error( $names ) || empty( $names ) ) {
				continue;
			}

			$lines[] = $taxonomy->labels->name . ': ' . implode( ', ', $names );
		}

		return implode( "\n", $lines );
	}
}
