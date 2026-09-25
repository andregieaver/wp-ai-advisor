<?php
/**
 * Minimal WordPress stubs so the plugin's pure-logic classes can run under plain PHP.
 *
 * This is not a WordPress test suite. It covers the parts that are decidable
 * without a database or an HTTP client: URL handling, vector maths, HTML and
 * document text extraction, chunking, and settings sanitisation.
 *
 * Run with: php tests/logic-test.php
 *
 * @package WP_AI_Advisor
 */

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'WP_AI_ADVISOR_VERSION', 'test' );

/**
 * Stand-in for WordPress's error object.
 */
class WP_Error {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Error data.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Error data.
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/**
	 * @return string
	 */
	public function get_error_message() {
		return $this->message;
	}

	/**
	 * @return string
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * @return mixed
	 */
	public function get_error_data() {
		return $this->data;
	}

	/**
	 * @param mixed $data Error data.
	 * @return void
	 */
	public function add_data( $data ) {
		$this->data = $data;
	}
}

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Universal.Files.SeparateFunctionsFromOO
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function trailingslashit( $value ) { return untrailingslashit( $value ) . '/'; }
function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_hex_color( $value ) { return preg_match( '/^#[0-9a-f]{6}$/i', (string) $value ) ? $value : null; }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function get_locale() { return $GLOBALS['wp_ai_advisor_test_locale'] ?? 'en_US'; }
function __( $text, $domain = '' ) { return $text; }
function apply_filters( $tag, $value ) { return $value; }
function get_bloginfo( $key ) { return 'Test Site'; }
function home_url() { return 'https://example.test'; }
$GLOBALS['wp_ai_advisor_test_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['wp_ai_advisor_test_options'] )
		? $GLOBALS['wp_ai_advisor_test_options'][ $key ]
		: $default;
}
function update_option( $key, $value ) { $GLOBALS['wp_ai_advisor_test_options'][ $key ] = $value; return true; }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function current_user_can( $capability ) { return false; }
function get_post_types( $args = array(), $output = 'names' ) { return array( 'post', 'page' ); }

// A tiny stand-in post store, so page-context logic can run without WordPress.
$GLOBALS['wp_ai_advisor_test_posts'] = array();
$GLOBALS['wp_ai_advisor_test_meta']  = array();
$GLOBALS['wp_ai_advisor_test_types'] = array( 'post' => true, 'page' => true, 'product' => true, 'secret' => false );

function wp_ai_advisor_test_post( $id, $args = array() ) {
	$GLOBALS['wp_ai_advisor_test_posts'][ $id ] = (object) array_merge(
		array(
			'ID'            => $id,
			'post_status'   => 'publish',
			'post_type'     => 'product',
			'post_title'    => 'Test post ' . $id,
			'post_password' => '',
			'post_content'  => '',
		),
		$args
	);
}

function get_post( $id = null ) {
	$id = is_object( $id ) ? $id->ID : (int) $id;

	return isset( $GLOBALS['wp_ai_advisor_test_posts'][ $id ] ) ? $GLOBALS['wp_ai_advisor_test_posts'][ $id ] : null;
}
function get_post_type_object( $type ) {
	if ( ! isset( $GLOBALS['wp_ai_advisor_test_types'][ $type ] ) ) { return null; }
	return (object) array( 'public' => $GLOBALS['wp_ai_advisor_test_types'][ $type ], 'name' => $type );
}
function get_the_title( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	return $post ? $post->post_title : '';
}
function get_permalink( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	return $post ? 'https://example.test/produkt/' . $post->ID : '';
}
function get_post_meta( $id, $key = '', $single = false ) {
	return isset( $GLOBALS['wp_ai_advisor_test_meta'][ $id ][ $key ] ) ? $GLOBALS['wp_ai_advisor_test_meta'][ $id ][ $key ] : '';
}
function get_object_taxonomies( $type, $output = 'names' ) { return array(); }
function wp_get_post_terms( $id, $taxonomy, $args = array() ) { return array(); }
// phpcs:enable

$root = dirname( __DIR__ ) . '/includes/';

require_once $root . 'class-wp-ai-advisor-settings.php';
require_once $root . 'class-wp-ai-advisor-store.php';
require_once $root . 'class-wp-ai-advisor-text.php';
require_once $root . 'class-wp-ai-advisor-language.php';
require_once $root . 'class-wp-ai-advisor-calculator.php';
require_once $root . 'class-wp-ai-advisor-page-context.php';
require_once $root . 'class-wp-ai-advisor-crawler.php';
require_once $root . 'class-wp-ai-advisor-indexer.php';
require_once $root . 'class-wp-ai-advisor-documents.php';
