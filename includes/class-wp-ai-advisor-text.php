<?php
/**
 * Shared HTML-to-text and link extraction.
 *
 * Used by both the crawler (remote HTML) and local content mode (rendered post
 * content), so the two produce comparable text.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless text helpers.
 */
class WP_AI_Advisor_Text {

	/**
	 * Flattens an HTML fragment or document to readable plain text.
	 *
	 * @param string $html HTML source.
	 * @return string
	 */
	public static function to_text( $html ) {
		$text = (string) $html;

		// Drop chrome and code before flattening.
		$text = preg_replace( '#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $text );
		$text = preg_replace( '#<(nav|header|footer|form)\b[^>]*>.*?</\1>#is', ' ', $text );
		$text = preg_replace( '#<!--.*?-->#s', ' ', $text );
		$text = preg_replace( '#</(p|div|li|h[1-6]|section|article|tr|br)>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		return self::tidy( $text );
	}

	/**
	 * Normalises whitespace: collapses runs, strips padding around line breaks,
	 * and caps consecutive blank lines.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function tidy( $text ) {
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', (string) $text );
		$text = preg_replace( '/\r\n?/u', "\n", $text );
		$text = preg_replace( '/[ \t]+/u', ' ', $text );
		$text = preg_replace( '/[ \t]*\n[ \t]*/u', "\n", $text );
		$text = preg_replace( '/\n{3,}/u', "\n\n", $text );

		return trim( (string) $text );
	}

	/**
	 * Reads the document title, when the HTML has one.
	 *
	 * @param string $html HTML source.
	 * @return string
	 */
	public static function title( $html ) {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', (string) $html, $match ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES, 'UTF-8' ) );
		}

		return '';
	}

	/**
	 * Collects internal links with their anchor text.
	 *
	 * @param string $html     HTML source.
	 * @param string $page_url URL the HTML came from, for resolving relative hrefs.
	 * @param string $base     Site base URL; links outside it are dropped.
	 * @return array[] Each: label, url.
	 */
	public static function links( $html, $page_url, $base ) {
		if ( ! preg_match_all( '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', (string) $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$links = array();

		foreach ( $matches as $match ) {
			$href = html_entity_decode( trim( $match[1] ), ENT_QUOTES, 'UTF-8' );

			if ( '' === $href || 0 === strpos( $href, '#' ) || preg_match( '#^(mailto:|tel:|javascript:|data:)#i', $href ) ) {
				continue;
			}

			$absolute = WP_AI_Advisor_Store::normalize_url( self::absolutize( $href, $page_url ) );

			if ( '' === $absolute || 0 !== stripos( $absolute, $base ) ) {
				continue;
			}

			$label = preg_replace( '/\s+/u', ' ', trim( html_entity_decode( wp_strip_all_tags( $match[2] ), ENT_QUOTES, 'UTF-8' ) ) );

			if ( '' === $label ) {
				continue;
			}

			$links[ $absolute ] = array(
				'label' => mb_substr( $label, 0, 80 ),
				'url'   => $absolute,
			);
		}

		return array_values( $links );
	}

	/**
	 * Top-level domains recognised in text that carries no scheme.
	 *
	 * Without a scheme, "something.word" is ambiguous: a missing space after a
	 * full stop looks exactly like a domain, and Norwegian prose is full of
	 * abbreviations. Requiring a known ending keeps "kaffe.Det" out while
	 * letting "detnorskekaffehus.net" through. Every two-letter ending is
	 * accepted as a country code.
	 *
	 * @return string[]
	 */
	public static function known_tlds() {
		/**
		 * Filters the endings treated as domains in plain text.
		 *
		 * @param string[] $tlds Lowercase endings, without the dot.
		 */
		return (array) apply_filters(
			'wp_ai_advisor_known_tlds',
			array(
				'com', 'net', 'org', 'info', 'biz', 'edu', 'gov', 'int',
				'shop', 'store', 'app', 'dev', 'ai', 'cloud', 'online',
				'site', 'tech', 'email', 'blog', 'news', 'agency', 'studio',
				'design', 'digital', 'group', 'media', 'company', 'solutions',
				'coffee', 'cafe', 'bar', 'restaurant', 'services',
			)
		);
	}

	/**
	 * File extensions that look like domains but are not.
	 *
	 * @return string[]
	 */
	private static function file_endings() {
		return array(
			'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico',
			'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
			'zip', 'rar', 'mp3', 'mp4', 'mov', 'js', 'css', 'html', 'htm',
			'php', 'json', 'xml', 'exe', 'dmg',
		);
	}

	/**
	 * Turns a URL or bare domain into an absolute https address.
	 *
	 * A domain written on its own in a note — "detnorskekaffehus.net" — is a
	 * website address as far as a reader is concerned, so it is treated as one.
	 *
	 * @param string $candidate URL, or a domain with no scheme.
	 * @return string Absolute URL, or '' when it is not an address.
	 */
	public static function normalize_href( $candidate ) {
		$candidate = trim( (string) $candidate );

		// Trailing sentence punctuation is not part of the address.
		$candidate = rtrim( $candidate, '.,;:!?)]}\'"' );

		if ( '' === $candidate ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $candidate ) ) {
			return esc_url_raw( $candidate );
		}

		// Anything else with a scheme (mailto:, javascript:, data:) is not ours.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $candidate ) ) {
			return '';
		}

		$candidate = preg_replace( '#^www\.#i', 'www.', $candidate );

		if ( ! self::looks_like_domain( $candidate ) ) {
			return '';
		}

		return esc_url_raw( 'https://' . $candidate );
	}

	/**
	 * Whether a schemeless string reads as a domain rather than as prose.
	 *
	 * @param string $candidate Text with no scheme.
	 * @return bool
	 */
	private static function looks_like_domain( $candidate ) {
		$host = strtok( $candidate, '/' );

		if ( ! $host || ! preg_match( '#^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$#i', $host ) ) {
			return false;
		}

		$labels = explode( '.', $host );
		$tld    = strtolower( array_pop( $labels ) );
		$first  = $labels[0];

		// "f.eks" and "bl.a" are abbreviations, not hosts.
		if ( mb_strlen( $first ) < 2 ) {
			return false;
		}

		if ( in_array( $tld, self::file_endings(), true ) ) {
			return false;
		}

		return 2 === strlen( $tld ) || in_array( $tld, self::known_tlds(), true );
	}

	/**
	 * Finds the web addresses written in a piece of text.
	 *
	 * @param string $text Plain text.
	 * @return array[] Each: label, url.
	 */
	public static function find_links( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return array();
		}

		// Blank out e-mail addresses so their domain is not read as a website.
		$masked = preg_replace( '#[^\s<>()\[\]]+@[^\s<>()\[\]]+#u', ' ', $text );

		$pattern = '#(?:https?://[^\s<>"\'\)\]]+|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}(?:/[^\s<>"\'\)\]]*)?)#i';

		if ( ! preg_match_all( $pattern, $masked, $matches ) ) {
			return array();
		}

		$links = array();

		foreach ( $matches[0] as $match ) {
			$url = self::normalize_href( $match );

			if ( '' === $url ) {
				continue;
			}

			$links[ untrailingslashit( $url ) ] = array(
				'label' => self::link_label( $url ),
				'url'   => untrailingslashit( $url ),
			);
		}

		return array_values( $links );
	}

	/**
	 * A readable label for a bare address.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	private static function link_label( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = preg_replace( '#^www\.#i', '', $host );

		return $host ? $host : $url;
	}

	/**
	 * Resolves a possibly relative href against the page URL.
	 *
	 * @param string $href Raw href.
	 * @param string $url  Page URL.
	 * @return string
	 */
	public static function absolutize( $href, $url ) {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';

		if ( ! $host ) {
			return '';
		}

		$origin = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( 0 === strpos( $href, '//' ) ) {
			return $scheme . ':' . $href;
		}

		if ( 0 === strpos( $href, '/' ) ) {
			return $origin . $href;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$path = preg_replace( '#/[^/]*$#', '/', $path );

		return $origin . $path . $href;
	}
}
