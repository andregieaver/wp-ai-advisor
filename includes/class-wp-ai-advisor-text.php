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
