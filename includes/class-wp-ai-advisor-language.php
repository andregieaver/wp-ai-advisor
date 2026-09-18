<?php
/**
 * Language detection and naming.
 *
 * Covers three separate questions that are easy to conflate: what language the
 * site runs in, what language the page holding the widget is in, and what
 * language a given piece of indexed content is in.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless language helpers.
 */
class WP_AI_Advisor_Language {

	/**
	 * Language names, keyed by the short code this plugin stores.
	 *
	 * Translated, because these appear both in the admin and inside the prompt:
	 * a Norwegian site should say "Svar alltid på norsk bokmål", not mix
	 * languages mid-sentence. The model understands far more than this list, so
	 * an unknown code is passed through as-is.
	 *
	 * @return array
	 */
	public static function names() {
		return array(
			'nb' => __( 'Norwegian Bokmål', 'wp-ai-advisor' ),
			'nn' => __( 'Norwegian Nynorsk', 'wp-ai-advisor' ),
			'no' => __( 'Norwegian', 'wp-ai-advisor' ),
			'da' => __( 'Danish', 'wp-ai-advisor' ),
			'sv' => __( 'Swedish', 'wp-ai-advisor' ),
			'fi' => __( 'Finnish', 'wp-ai-advisor' ),
			'is' => __( 'Icelandic', 'wp-ai-advisor' ),
			'en' => __( 'English', 'wp-ai-advisor' ),
			'de' => __( 'German', 'wp-ai-advisor' ),
			'nl' => __( 'Dutch', 'wp-ai-advisor' ),
			'fr' => __( 'French', 'wp-ai-advisor' ),
			'es' => __( 'Spanish', 'wp-ai-advisor' ),
			'it' => __( 'Italian', 'wp-ai-advisor' ),
			'pt' => __( 'Portuguese', 'wp-ai-advisor' ),
			'pl' => __( 'Polish', 'wp-ai-advisor' ),
			'et' => __( 'Estonian', 'wp-ai-advisor' ),
			'lv' => __( 'Latvian', 'wp-ai-advisor' ),
			'lt' => __( 'Lithuanian', 'wp-ai-advisor' ),
			'ru' => __( 'Russian', 'wp-ai-advisor' ),
			'uk' => __( 'Ukrainian', 'wp-ai-advisor' ),
			'ar' => __( 'Arabic', 'wp-ai-advisor' ),
			'he' => __( 'Hebrew', 'wp-ai-advisor' ),
			'tr' => __( 'Turkish', 'wp-ai-advisor' ),
			'zh' => __( 'Chinese', 'wp-ai-advisor' ),
			'ja' => __( 'Japanese', 'wp-ai-advisor' ),
			'ko' => __( 'Korean', 'wp-ai-advisor' ),
		);
	}

	/**
	 * Reduces a locale to the short code used throughout the plugin.
	 *
	 * `nb_NO` becomes `nb`; `pt-BR` becomes `pt`. Region is deliberately dropped:
	 * it rarely changes which passages are relevant, and keeping it would split
	 * the index needlessly.
	 *
	 * @param string $locale Locale or language tag.
	 * @return string Lowercase two- or three-letter code, or ''.
	 */
	public static function normalize( $locale ) {
		$locale = strtolower( trim( (string) $locale ) );

		if ( '' === $locale ) {
			return '';
		}

		$code = preg_split( '/[_\-.]/', $locale );
		$code = isset( $code[0] ) ? $code[0] : '';

		return preg_match( '/^[a-z]{2,3}$/', $code ) ? $code : '';
	}

	/**
	 * The language WordPress itself runs in.
	 *
	 * @return string
	 */
	public static function site() {
		return self::normalize( get_locale() );
	}

	/**
	 * The language of the page currently being served.
	 *
	 * Honours Polylang and WPML when present, since on those sites the site
	 * locale says nothing about which translation the visitor is reading.
	 *
	 * @return string
	 */
	public static function current() {
		if ( function_exists( 'pll_current_language' ) ) {
			$code = self::normalize( pll_current_language( 'slug' ) );

			if ( $code ) {
				return $code;
			}
		}

		if ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE ) {
			$code = self::normalize( ICL_LANGUAGE_CODE );

			if ( $code ) {
				return $code;
			}
		}

		return self::site();
	}

	/**
	 * The language a post is written in.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function of_post( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$code = self::normalize( pll_get_post_language( $post_id, 'slug' ) );

			if ( $code ) {
				return $code;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );

			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				$code = self::normalize( $details['language_code'] );

				if ( $code ) {
					return $code;
				}
			}
		}

		return self::site();
	}

	/**
	 * The language declared by an HTML document.
	 *
	 * @param string $html HTML source.
	 * @return string
	 */
	public static function of_html( $html ) {
		if ( preg_match( '#<html[^>]*\blang=["\']([^"\']+)["\']#i', (string) $html, $match ) ) {
			return self::normalize( $match[1] );
		}

		return '';
	}

	/**
	 * A human-readable name for a language code.
	 *
	 * @param string $code Short language code.
	 * @return string
	 */
	public static function name( $code ) {
		$code  = self::normalize( $code );
		$names = self::names();

		return isset( $names[ $code ] ) ? $names[ $code ] : $code;
	}

	/**
	 * The instruction appended to the system prompt telling the model which
	 * language to answer in.
	 *
	 * @param string $page_language Language of the page holding the widget.
	 * @return string
	 */
	public static function reply_instruction( $page_language = '' ) {
		$setting = (string) WP_AI_Advisor_Settings::get( 'reply_language', 'auto' );

		if ( 'page' === $setting ) {
			$code = self::normalize( $page_language );
			$code = $code ? $code : self::site();

			return sprintf(
				/* translators: %s: language name, e.g. "Norwegian Bokmål". */
				__( 'Always answer in %s, whatever language the question is written in.', 'wp-ai-advisor' ),
				self::name( $code )
			);
		}

		if ( 'auto' !== $setting ) {
			return sprintf(
				/* translators: %s: language name, e.g. "Norwegian Bokmål". */
				__( 'Always answer in %s, whatever language the question is written in.', 'wp-ai-advisor' ),
				self::name( $setting )
			);
		}

		$fallback = self::normalize( $page_language );
		$fallback = $fallback ? $fallback : self::site();

		return sprintf(
			/* translators: %s: language name, e.g. "Norwegian Bokmål". */
			__( 'Answer in the same language the visitor wrote their question in. If that is unclear, answer in %s. Translate the wording of any site excerpt you quote into that language rather than switching language mid-answer.', 'wp-ai-advisor' ),
			self::name( $fallback )
		);
	}

	/**
	 * The language codes present in the knowledge base, for the admin screen.
	 *
	 * @return string[]
	 */
	public static function indexed() {
		return WP_AI_Advisor_Store::languages();
	}
}
