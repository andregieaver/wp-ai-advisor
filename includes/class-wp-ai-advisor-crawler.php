<?php
/**
 * Site crawler: fetches pages and discovers internal navigation.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Walks the configured site, storing readable text and internal links.
 */
class WP_AI_Advisor_Crawler {

	const TIMEOUT   = 20;
	const MAX_DEPTH = 4;

	/**
	 * Seeds the frontier with the site root and any sitemap entries.
	 *
	 * @return int Number of URLs queued.
	 */
	public function start() {
		WP_AI_Advisor_Store::clear( WP_AI_Advisor_Store::TYPE_PAGE );

		$base   = WP_AI_Advisor_Settings::site_url();
		$queued = WP_AI_Advisor_Store::queue_url( $base, 0 ) ? 1 : 0;

		foreach ( $this->sitemap_urls( $base ) as $url ) {
			if ( $this->is_crawlable( $url, $base ) && WP_AI_Advisor_Store::queue_url( $url, 1 ) ) {
				$queued++;
			}
		}

		return $queued;
	}

	/**
	 * Fetches the next pending page and queues the internal links it exposes.
	 *
	 * @return array|null Progress info, or null when the frontier is empty.
	 */
	public function crawl_next() {
		$rows = WP_AI_Advisor_Store::next_by_status( WP_AI_Advisor_Store::STATUS_PENDING, 1, WP_AI_Advisor_Store::TYPE_PAGE );

		if ( empty( $rows ) ) {
			return null;
		}

		$source = $rows[0];
		$url    = $source['url'];

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'user-agent'  => 'WP AI Advisor/' . WP_AI_ADVISOR_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_AI_Advisor_Store::mark_error( $source['id'], $response->get_error_message() );

			return array(
				'url'    => $url,
				'status' => 'error',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			WP_AI_Advisor_Store::mark_error( $source['id'], sprintf( 'HTTP %d', $code ) );

			return array(
				'url'    => $url,
				'status' => 'error',
			);
		}

		$type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( $type && false === strpos( $type, 'text/html' ) ) {
			WP_AI_Advisor_Store::mark_error( $source['id'], __( 'Not an HTML page.', 'wp-ai-advisor' ) );

			return array(
				'url'    => $url,
				'status' => 'skipped',
			);
		}

		$html      = wp_remote_retrieve_body( $response );
		$extracted = $this->extract( $html, $url );

		if ( '' === trim( $extracted['content'] ) ) {
			WP_AI_Advisor_Store::mark_error( $source['id'], __( 'No readable text found.', 'wp-ai-advisor' ) );
		} else {
			WP_AI_Advisor_Store::save_fetched( $source['id'], $extracted );
		}

		$this->queue_links( $extracted['links'], (int) $source['depth'] + 1 );

		return array(
			'url'    => $url,
			'title'  => $extracted['title'],
			'status' => 'fetched',
			'links'  => count( $extracted['links'] ),
		);
	}

	/**
	 * Queues newly discovered links, respecting the page budget and depth limit.
	 *
	 * @param array $links Discovered links: label, url.
	 * @param int   $depth Depth to record for these links.
	 * @return void
	 */
	private function queue_links( array $links, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}

		$base = WP_AI_Advisor_Settings::site_url();
		$max  = (int) WP_AI_Advisor_Settings::get( 'crawl_max_pages' );

		// Counted per type: local sources share this table but not this budget.
		$budget = $max - WP_AI_Advisor_Store::count_by_type( WP_AI_Advisor_Store::TYPE_PAGE );

		foreach ( $links as $link ) {
			if ( $budget <= 0 ) {
				return;
			}

			if ( ! $this->is_crawlable( $link['url'], $base ) ) {
				continue;
			}

			if ( WP_AI_Advisor_Store::queue_url( $link['url'], $depth ) ) {
				$budget--;
			}
		}
	}

	/**
	 * Pulls page URLs out of the site's sitemap, when it has one.
	 *
	 * @param string $base Site base URL.
	 * @return string[]
	 */
	private function sitemap_urls( $base ) {
		$candidates = array(
			$base . '/wp-sitemap.xml',
			$base . '/sitemap_index.xml',
			$base . '/sitemap.xml',
		);

		foreach ( $candidates as $candidate ) {
			$urls = $this->read_sitemap( $candidate, 0 );

			if ( ! empty( $urls ) ) {
				return $urls;
			}
		}

		return array();
	}

	/**
	 * Reads one sitemap, following index files one level deep.
	 *
	 * @param string $url   Sitemap URL.
	 * @param int    $depth Recursion depth.
	 * @return string[]
	 */
	private function read_sitemap( $url, $depth ) {
		if ( $depth > 1 ) {
			return array();
		}

		$response = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $body, $matches ) ) {
			return array();
		}

		$is_index = false !== stripos( $body, '<sitemapindex' );
		$urls     = array();

		foreach ( $matches[1] as $found ) {
			$found = html_entity_decode( trim( $found ), ENT_QUOTES, 'UTF-8' );

			if ( $is_index ) {
				$urls = array_merge( $urls, $this->read_sitemap( $found, $depth + 1 ) );
			} else {
				$urls[] = $found;
			}

			if ( count( $urls ) > 2000 ) {
				break;
			}
		}

		return $urls;
	}

	/**
	 * Whether a URL belongs to the crawled site and is not excluded.
	 *
	 * @param string $url  Candidate URL.
	 * @param string $base Site base URL.
	 * @return bool
	 */
	private function is_crawlable( $url, $base ) {
		$url = WP_AI_Advisor_Store::normalize_url( $url );

		if ( '' === $url || 0 !== stripos( $url, $base ) ) {
			return false;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// Skip files: the crawler reads HTML, documents are uploaded separately.
		if ( preg_match( '#\.(jpe?g|png|gif|webp|svg|avif|pdf|zip|gz|mp4|mp3|wav|docx?|xlsx?|pptx?|css|js|ico)$#i', $path ) ) {
			return false;
		}

		foreach ( $this->exclusions() as $pattern ) {
			if ( false !== stripos( $url, $pattern ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Excluded path fragments from the settings.
	 *
	 * @return string[]
	 */
	private function exclusions() {
		$raw = (string) WP_AI_Advisor_Settings::get( 'crawl_exclude' );

		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) ) );
	}

	/**
	 * Extracts title, readable text and internal links from an HTML document.
	 *
	 * @param string $html HTML source.
	 * @param string $url  Page URL, used to resolve relative links.
	 * @return array {title, content, links}
	 */
	public function extract( $html, $url ) {
		$title = WP_AI_Advisor_Text::title( $html );

		return array(
			'title'   => $title ? $title : $url,
			'content' => WP_AI_Advisor_Text::to_text( $html ),
			'links'   => WP_AI_Advisor_Text::links( $html, $url, WP_AI_Advisor_Settings::site_url() ),
		);
	}
}
