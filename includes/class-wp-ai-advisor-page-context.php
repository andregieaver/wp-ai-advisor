<?php
/**
 * Facts about the page the widget is sitting on.
 *
 * A widget on a product page has to resolve "denne kaffemaskinen" to that
 * product. Semantic search alone cannot do it: the pronoun carries no meaning
 * to match on. So the page identifies itself, its own passages are pinned into
 * the context, and its live field values are stated in the prompt.
 *
 * @package WP_AI_Advisor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the current-page block handed to the model.
 */
class WP_AI_Advisor_Page_Context {

	/**
	 * Validates a post ID arriving from the browser.
	 *
	 * The ID only chooses which already-indexed content to pin, so it cannot
	 * reach anything a visitor could not read anyway — but it still has to be a
	 * published post of a public type, or a widget could be pointed at a draft.
	 *
	 * @param mixed $post_id Candidate post ID.
	 * @return int Post ID, or 0 when unusable.
	 */
	public static function validate( $post_id ) {
		// Deliberately not absint(): that folds -10 into 10, quietly answering
		// about a different product than the one asked for.
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;

		if ( $post_id <= 0 ) {
			return 0;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return 0;
		}

		$type = get_post_type_object( $post->post_type );

		if ( ! $type || empty( $type->public ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * The price range held in the configured custom field.
	 *
	 * Read through ACF when it is active so field keys and formatting are
	 * honoured, and straight from post meta otherwise — ACF stores plain text
	 * fields under the field name, so the fallback finds the same value.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function price_range( $post_id ) {
		$field = trim( (string) WP_AI_Advisor_Settings::get( 'price_field' ) );

		if ( '' === $field ) {
			return '';
		}

		$value = '';

		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field, $post_id );
		}

		if ( '' === $value || null === $value || false === $value ) {
			$value = get_post_meta( $post_id, $field, true );
		}

		if ( is_array( $value ) ) {
			$value = implode( ' – ', array_filter( array_map( 'strval', $value ) ) );
		}

		return trim( wp_strip_all_tags( (string) $value ) );
	}

	/**
	 * Name/value facts describing a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Ordered list of {label, value}.
	 */
	public static function facts( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$facts = array(
			array(
				'label' => __( 'Title', 'wp-ai-advisor' ),
				'value' => get_the_title( $post ),
			),
			array(
				'label' => __( 'URL', 'wp-ai-advisor' ),
				'value' => (string) get_permalink( $post ),
			),
		);

		$price_range = self::price_range( $post_id );

		if ( '' !== $price_range ) {
			$facts[] = array(
				'label' => __( 'Price range stated by the shop', 'wp-ai-advisor' ),
				'value' => $price_range,
			);
		}

		foreach ( self::taxonomy_facts( $post ) as $fact ) {
			$facts[] = $fact;
		}

		/**
		 * Filters the facts stated about the page the visitor is on.
		 *
		 * Use this to expose further fields — stock, SKU, lead time — to the
		 * advisor. Values are stated to the model as authoritative.
		 *
		 * @param array   $facts   Ordered list of {label, value}.
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post    Post object.
		 */
		$facts = (array) apply_filters( 'wp_ai_advisor_page_facts', $facts, $post_id, $post );

		$clean = array();

		foreach ( $facts as $fact ) {
			if ( empty( $fact['label'] ) || ! isset( $fact['value'] ) || '' === trim( (string) $fact['value'] ) ) {
				continue;
			}

			$clean[] = array(
				'label' => trim( wp_strip_all_tags( (string) $fact['label'] ) ),
				'value' => trim( wp_strip_all_tags( (string) $fact['value'] ) ),
			);
		}

		return $clean;
	}

	/**
	 * Public taxonomy terms as facts, so "denne serien" has something to match.
	 *
	 * @param WP_Post $post Post object.
	 * @return array[]
	 */
	private static function taxonomy_facts( $post ) {
		$facts = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}

			$names = wp_get_post_terms( $post->ID, $taxonomy->name, array( 'fields' => 'names' ) );

			if ( is_wp_error( $names ) || empty( $names ) ) {
				continue;
			}

			$facts[] = array(
				'label' => $taxonomy->labels->name,
				'value' => implode( ', ', $names ),
			);
		}

		return $facts;
	}

	/**
	 * Renders the facts as the PAGE block for the prompt.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty when there is nothing to say.
	 */
	public static function render( $post_id ) {
		$facts = self::facts( $post_id );

		if ( empty( $facts ) ) {
			return '';
		}

		$lines = array();

		foreach ( $facts as $fact ) {
			$lines[] = $fact['label'] . ': ' . $fact['value'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * The indexable text for a post's own fields.
	 *
	 * Used when importing, so a price range is searchable from anywhere on the
	 * site rather than only from the product's own page.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function indexable_fields( $post_id ) {
		$price_range = self::price_range( $post_id );

		if ( '' === $price_range ) {
			return '';
		}

		return sprintf(
			/* translators: %s: the price range text entered in the custom field. */
			__( 'Price range: %s', 'wp-ai-advisor' ),
			$price_range
		);
	}
}
