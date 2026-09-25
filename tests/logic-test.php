<?php
/**
 * Logic tests for WP AI Advisor.
 *
 * Run with: php tests/logic-test.php
 *
 * @package WP_AI_Advisor
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Reads a compiled .mo into {msgid: msgstr}.
 *
 * @param string $path Path to the .mo file.
 * @return array
 */
function wp_ai_advisor_read_mo( $path ) {
	$data = (string) file_get_contents( $path );

	if ( strlen( $data ) < 20 || 0x950412de !== unpack( 'V', substr( $data, 0, 4 ) )[1] ) {
		return array();
	}

	$header = unpack( 'Vrev/Vcount/Vokeys/Vovals', substr( $data, 4, 16 ) );
	$out    = array();

	for ( $i = 0; $i < $header['count']; $i++ ) {
		$key   = unpack( 'Vlen/Voff', substr( $data, $header['okeys'] + $i * 8, 8 ) );
		$value = unpack( 'Vlen/Voff', substr( $data, $header['ovals'] + $i * 8, 8 ) );

		$out[ substr( $data, $key['off'], $key['len'] ) ] = substr( $data, $value['off'], $value['len'] );
	}

	return $out;
}

/**
 * Reads the msgids out of a .pot file.
 *
 * @param string $path Path to the .pot file.
 * @return string[]
 */
function wp_ai_advisor_read_pot( $path ) {
	$out = array();

	foreach ( file( $path ) as $line ) {
		if ( 0 !== strpos( $line, 'msgid "' ) ) {
			continue;
		}

		$msgid = substr( trim( $line ), 7, -1 );

		if ( '' === $msgid ) {
			continue;
		}

		$out[] = str_replace(
			array( '\\"', '\\n', '\\t', '\\\\' ),
			array( '"', "\n", "\t", '\\' ),
			$msgid
		);
	}

	return $out;
}

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want;
	$ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $label );
	if ( ! $ok ) { echo "   got:  " . var_export( $got, true ) . "\n   want: " . var_export( $want, true ) . "\n"; }
}

// --- URL normalisation -----------------------------------------------------
check( 'normalize_url strips query+fragment', WP_AI_Advisor_Store::normalize_url( 'https://a.no/kaffe/?x=1#top' ), 'https://a.no/kaffe' );
check( 'normalize_url strips trailing slash', WP_AI_Advisor_Store::normalize_url( 'https://a.no/kaffe/' ), 'https://a.no/kaffe' );

// --- Vector maths ----------------------------------------------------------
$unit = WP_AI_Advisor_Store::normalize_vector( array( 3.0, 4.0 ) );
check( 'normalize_vector returns unit length', array_map( fn( $v ) => round( $v, 4 ), $unit ), array( 0.6, 0.8 ) );
check( 'normalize_vector rejects zero vector', WP_AI_Advisor_Store::normalize_vector( array( 0, 0 ) ), array() );

// --- HTML extraction -------------------------------------------------------
$html = '<html><head><title>Kaffehuset – Meny</title></head><body>'
	. '<nav><a href="/skjult">Skjult nav</a></nav>'
	. '<script>var x = "ikke tekst";</script>'
	. '<h1>Vår meny</h1><p>Vi serverer kaffe &amp; kaker.</p>'
	. '<a href="/meny">Se menyen</a><a href="https://example.test/kontakt/?ref=1">Kontakt</a>'
	. '<a href="https://facebook.com/x">Facebook</a><a href="mailto:a@b.no">Mail</a>'
	. '</body></html>';

$crawler = new WP_AI_Advisor_Crawler();
$out = $crawler->extract( $html, 'https://example.test/meny' );

check( 'extract reads <title>', $out['title'], 'Kaffehuset – Meny' );
check( 'extract drops <script> contents', strpos( $out['content'], 'ikke tekst' ), false );
check( 'extract drops <nav> contents', strpos( $out['content'], 'Skjult nav' ), false );
check( 'extract decodes entities', strpos( $out['content'], 'kaffe & kaker' ) !== false, true );
$urls = array_column( $out['links'], 'url' );
check( 'extract resolves relative links', in_array( 'https://example.test/meny', $urls, true ), true );
check( 'extract normalises absolute links', in_array( 'https://example.test/kontakt', $urls, true ), true );
check( 'extract excludes external links', in_array( 'https://facebook.com/x', $urls, true ), false );
check( 'extract keeps nav links for navigation', in_array( 'https://example.test/skjult', $urls, true ), true );
check( 'extract excludes mailto links', count( $urls ), 3 );

// --- Chunking --------------------------------------------------------------
$indexer = new WP_AI_Advisor_Indexer();
$long = implode( "\n\n", array_fill( 0, 40, str_repeat( 'ord ', 30 ) ) );
$chunks = $indexer->split( $long, 'Meny' );
check( 'split produces multiple chunks', count( $chunks ) > 1, true );
check( 'split prefixes every chunk with the title', count( array_filter( $chunks, fn( $c ) => str_starts_with( $c, "Meny\n\n" ) ) ), count( $chunks ) );
$oversize = max( array_map( 'mb_strlen', $chunks ) );
check( 'chunks stay near the size limit', $oversize <= WP_AI_Advisor_Indexer::CHUNK_CHARS + mb_strlen( "Meny\n\n" ), true );
check( 'split returns nothing for empty text', $indexer->split( "   \n\n  ", 'X' ), array() );

$single = $indexer->split( str_repeat( 'a', 5000 ), '' );
check( 'oversized single paragraph is split', count( $single ) > 1, true );

// --- Shared text helpers ---------------------------------------------------
check( 'tidy strips padding around line breaks', WP_AI_Advisor_Text::tidy( "  a  \n   \n\n\n  b  " ), "a\n\nb" );
check( 'tidy normalises CRLF', WP_AI_Advisor_Text::tidy( "a\r\nb" ), "a\nb" );
check( 'to_text drops style blocks', strpos( WP_AI_Advisor_Text::to_text( '<style>.x{color:red}</style><p>hei</p>' ), 'color' ), false );
check( 'title returns empty when absent', WP_AI_Advisor_Text::title( '<p>no title</p>' ), '' );
check( 'absolutize resolves root-relative', WP_AI_Advisor_Text::absolutize( '/a', 'https://example.test/b/c' ), 'https://example.test/a' );
check( 'absolutize resolves document-relative', WP_AI_Advisor_Text::absolutize( 'd', 'https://example.test/b/c' ), 'https://example.test/b/d' );
check( 'absolutize resolves protocol-relative', WP_AI_Advisor_Text::absolutize( '//cdn.test/x', 'https://example.test/' ), 'https://cdn.test/x' );
check( 'absolutize leaves absolute untouched', WP_AI_Advisor_Text::absolutize( 'http://other.test/x', 'https://example.test/' ), 'http://other.test/x' );

// --- Source mode -----------------------------------------------------------
$modes = WP_AI_Advisor_Settings::sanitize( array( '_form' => 'settings', 'source_mode' => 'local', 'local_post_types' => array( 'page', 'nonsense' ) ) );
check( 'source_mode accepted', $modes['source_mode'], 'local' );
check( 'unknown post types dropped', $modes['local_post_types'], array( 'page' ) );

$bad = WP_AI_Advisor_Settings::sanitize( array( '_form' => 'settings', 'source_mode' => 'wat' ) );
check( 'unknown source_mode falls back', $bad['source_mode'], 'crawl' );
check( 'unchecked post types clear the list', $bad['local_post_types'], array() );

// --- Settings sanitisation -------------------------------------------------
$clean = WP_AI_Advisor_Settings::sanitize( array(
	'_form'       => 'settings',
	'api_key'     => '',
	'temperature' => '9',
	'top_k'       => '999',
	'min_score'   => '-1',
	'accent'      => 'not-a-colour',
	'suggestions' => "Hva koster kaffen?\n\n  Åpningstider  \nHvor er dere?",
	'strict_mode' => '1',
) );
check( 'temperature is clamped', $clean['temperature'], 2.0 );
check( 'top_k is clamped', $clean['top_k'], 20 );
check( 'min_score is clamped', $clean['min_score'], 0.0 );
check( 'bad accent falls back to default', $clean['accent'], '#ffffff' );
check( 'suggestions split and trimmed', $clean['suggestions'], array( 'Hva koster kaffen?', 'Åpningstider', 'Hvor er dere?' ) );
check( 'checkbox read when form marker present', $clean['strict_mode'], true );
check( 'admin_only defaults off when unchecked', $clean['admin_only'], false );
check( 'blank api_key keeps stored key', $clean['api_key'], '' );

// --- Calculator ------------------------------------------------------------
/**
 * Asserts an expression evaluates to a value.
 *
 * @param string $expression Expression.
 * @param float  $want       Expected result.
 * @return void
 */
function calc_is( $expression, $want ) {
	$got = WP_AI_Advisor_Calculator::evaluate( $expression );

	check(
		'calculates ' . $expression,
		is_wp_error( $got ) ? $got->get_error_code() : round( $got, 8 ),
		round( $want, 8 )
	);
}

/**
 * Asserts an expression is rejected.
 *
 * @param string $expression Expression.
 * @param string $label      What is being rejected.
 * @return void
 */
function calc_rejects( $expression, $label ) {
	check( 'rejects ' . $label, is_wp_error( WP_AI_Advisor_Calculator::evaluate( $expression ) ), true );
}

// 50 employees, 2.5 cups each per working day, 21 working days, 1.90 a cup,
// plus 890 rental: the shape of estimate this exists to get right.
calc_is( '50 * 2.5 * 21 * 1.90 + 890', 5877.5 );
calc_is( '2 + 3 * 4', 14 );
calc_is( '(2 + 3) * 4', 20 );
calc_is( '10 / 4', 2.5 );
calc_is( '-5 + 3', -2 );
calc_is( '2 ^ 3 ^ 2', 512 );
calc_is( 'round(6127.456, 2)', 6127.46 );
calc_is( 'min(10, 4, 7)', 4 );
calc_is( 'max(10, 4, 7)', 10 );
calc_is( 'abs(0 - 12)', 12 );
calc_is( 'ceil(2.1)', 3 );
calc_is( 'floor(2.9)', 2 );
calc_is( '.5 * 4', 2 );
calc_is( '100 % 30', 10 );

// The expression is written by a model reading visitor input, so everything
// that is not arithmetic has to be refused rather than evaluated.
calc_rejects( 'system("ls")', 'shell calls' );
calc_rejects( 'phpinfo()', 'php functions' );
calc_rejects( '1; DROP TABLE wp_posts', 'statement separators' );
calc_rejects( '`ls`', 'backticks' );
calc_rejects( '$x + 1', 'variables' );
calc_rejects( '1 / 0', 'division by zero' );
calc_rejects( '10 % 0', 'modulo by zero' );
calc_rejects( '2 ^ 1000', 'huge exponents' );
calc_rejects( '(1 + 2', 'unclosed brackets' );
calc_rejects( '1 +', 'dangling operators' );
calc_rejects( '', 'empty input' );
calc_rejects( '1.2.3 + 1', 'malformed numbers' );
calc_rejects( '1 234', 'space-separated numbers' );
calc_rejects( 'foo(2)', 'unknown functions' );
calc_rejects( 'round()', 'missing arguments' );
calc_rejects( 'abs(1, 2)', 'too many arguments' );
calc_rejects( str_repeat( '1+', 400 ) . '1', 'over-long expressions' );
calc_rejects( str_repeat( '(', 40 ) . '1' . str_repeat( ')', 40 ), 'deep nesting' );

// --- Model list filtering --------------------------------------------------
// The models endpoint lists everything the key can reach, including speech and
// image models that would fail on the first request.
$reflection = new ReflectionMethod( 'WP_AI_Advisor_OpenAI_Client', 'is_chat_model' );
$reflection->setAccessible( true );

foreach ( array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'chatgpt-4o-latest', 'o3-mini' ) as $id ) {
	check( 'offers ' . $id, $reflection->invoke( null, $id ), true );
}

foreach (
	array(
		'text-embedding-3-small',
		'whisper-1',
		'tts-1-hd',
		'dall-e-3',
		'gpt-4o-audio-preview',
		'gpt-4o-realtime-preview',
		'gpt-4o-transcribe',
		'omni-moderation-latest',
		'gpt-image-1',
		'gpt-3.5-turbo-instruct',
	) as $id
) {
	check( 'hides ' . $id, $reflection->invoke( null, $id ), false );
}

// --- Degrading when a model refuses a feature ------------------------------
// Older chat models take a conversation but not structured outputs or tools.
// Those failures are retried plainly; everything else has to surface.
$unsupported = new ReflectionMethod( 'WP_AI_Advisor_OpenAI_Client', 'is_unsupported_feature' );
$unsupported->setAccessible( true );
$probe_client = new WP_AI_Advisor_OpenAI_Client( 'sk-test' );

/**
 * Builds an API error carrying a detail message.
 *
 * @param string $detail Message from the API.
 * @return WP_Error
 */
function api_error( $detail ) {
	return new WP_Error( 'wp_ai_advisor_api_error', 'x', array( 'status' => 502, 'detail' => $detail ) );
}

foreach (
	array(
		"Invalid parameter: 'response_format' of type 'json_schema' is not supported with this model.",
		'response_format is not supported with this model',
		"Invalid value: 'json_schema'. Supported values are: 'text' and 'json_object'.",
		'This model does not support tools',
		'function calling is not supported',
	) as $detail
) {
	check( 'retries without extras: ' . substr( $detail, 0, 40 ), $unsupported->invoke( $probe_client, api_error( $detail ) ), true );
}

foreach (
	array(
		'Incorrect API key provided',
		'You exceeded your current quota',
		'The model `gpt-9` does not exist or you do not have access to it.',
		'Rate limit reached for requests',
		'context_length_exceeded: maximum context length is 8192 tokens',
		'',
	) as $detail
) {
	check( 'surfaces: ' . ( $detail ? substr( $detail, 0, 40 ) : '(no detail)' ), $unsupported->invoke( $probe_client, api_error( $detail ) ), false );
}

check( 'surfaces an error carrying no data', $unsupported->invoke( $probe_client, new WP_Error( 'x', 'y' ) ), false );

// --- Model setting ---------------------------------------------------------
$picked = WP_AI_Advisor_Settings::sanitize( array( 'model' => 'gpt-4o' ) );
check( 'a listed model is stored', $picked['model'], 'gpt-4o' );

$picked = WP_AI_Advisor_Settings::sanitize( array( 'model' => '__custom__', 'model_custom' => ' gpt-5-preview ' ) );
check( 'a custom model is taken from its own field', $picked['model'], 'gpt-5-preview' );

$picked = WP_AI_Advisor_Settings::sanitize( array( 'model' => '__custom__', 'model_custom' => '   ' ) );
check( 'an empty custom model falls back to the default', $picked['model'], 'gpt-4o-mini' );

$picked = WP_AI_Advisor_Settings::sanitize( array( 'embedding_model' => '__custom__', 'embedding_model_custom' => 'text-embedding-3-large' ) );
check( 'the embedding model uses the same rule', $picked['embedding_model'], 'text-embedding-3-large' );

$picked = WP_AI_Advisor_Settings::sanitize( array( 'temperature' => '0.5' ) );
check( 'an untouched model keeps its value', $picked['model'], 'gpt-4o-mini' );

// --- Language detection ----------------------------------------------------
check( 'normalize drops region', WP_AI_Advisor_Language::normalize( 'nb_NO' ), 'nb' );
check( 'normalize handles hyphenated tags', WP_AI_Advisor_Language::normalize( 'pt-BR' ), 'pt' );
check( 'normalize lowercases', WP_AI_Advisor_Language::normalize( 'NB' ), 'nb' );
check( 'normalize rejects junk', WP_AI_Advisor_Language::normalize( '12345' ), '' );
check( 'normalize handles empty input', WP_AI_Advisor_Language::normalize( '' ), '' );
check( 'of_html reads the lang attribute', WP_AI_Advisor_Language::of_html( '<html lang="nb-NO"><body>x</body></html>' ), 'nb' );
check( 'of_html copes with extra attributes', WP_AI_Advisor_Language::of_html( '<html dir="ltr" lang="nn" class="x">' ), 'nn' );
check( 'of_html returns empty when absent', WP_AI_Advisor_Language::of_html( '<html><body>x</body></html>' ), '' );
check( 'name resolves known codes', WP_AI_Advisor_Language::name( 'nb_NO' ), 'Norwegian Bokmål' );
check( 'name passes unknown codes through', WP_AI_Advisor_Language::name( 'xyz' ), 'xyz' );

$GLOBALS['wp_ai_advisor_test_locale'] = 'nb_NO';
check( 'site language comes from the locale', WP_AI_Advisor_Language::site(), 'nb' );

$lang = WP_AI_Advisor_Settings::sanitize( array( '_form' => 'settings', 'reply_language' => 'nn_NO' ) );
check( 'reply_language normalised to a code', $lang['reply_language'], 'nn' );
check( 'reply_language accepts auto', WP_AI_Advisor_Settings::sanitize( array( 'reply_language' => 'auto' ) )['reply_language'], 'auto' );
check( 'reply_language accepts page', WP_AI_Advisor_Settings::sanitize( array( 'reply_language' => 'page' ) )['reply_language'], 'page' );
check( 'reply_language rejects junk', WP_AI_Advisor_Settings::sanitize( array( 'reply_language' => '!!' ) )['reply_language'], 'auto' );
$GLOBALS['wp_ai_advisor_test_locale'] = 'en_US';

// --- Page context ----------------------------------------------------------
wp_ai_advisor_test_post( 10, array( 'post_title' => 'JURA WE6' ) );
wp_ai_advisor_test_post( 11, array( 'post_status' => 'draft' ) );
wp_ai_advisor_test_post( 12, array( 'post_status' => 'private' ) );
wp_ai_advisor_test_post( 13, array( 'post_password' => 'hemmelig' ) );
wp_ai_advisor_test_post( 14, array( 'post_type' => 'secret' ) );

check( 'published public post is accepted', WP_AI_Advisor_Page_Context::validate( 10 ), 10 );
check( 'draft is rejected', WP_AI_Advisor_Page_Context::validate( 11 ), 0 );
check( 'private post is rejected', WP_AI_Advisor_Page_Context::validate( 12 ), 0 );
check( 'password-protected post is rejected', WP_AI_Advisor_Page_Context::validate( 13 ), 0 );
check( 'non-public post type is rejected', WP_AI_Advisor_Page_Context::validate( 14 ), 0 );
check( 'unknown post is rejected', WP_AI_Advisor_Page_Context::validate( 9999 ), 0 );
check( 'zero is rejected', WP_AI_Advisor_Page_Context::validate( 0 ), 0 );
check( 'negative id is rejected', WP_AI_Advisor_Page_Context::validate( -10 ), 0 );
check( 'non-numeric id is rejected', WP_AI_Advisor_Page_Context::validate( 'abc' ), 0 );

$GLOBALS['wp_ai_advisor_test_meta'][10] = array( 'hvor_mye_koster_det' => ' 12 900 – 18 400 kr ' );

check(
	'price range is read from the configured field',
	WP_AI_Advisor_Page_Context::price_range( 10 ),
	'12 900 – 18 400 kr'
);
check( 'missing price range is empty', WP_AI_Advisor_Page_Context::price_range( 11 ), '' );

$rendered = WP_AI_Advisor_Page_Context::render( 10 );
check( 'page block names the post', false !== strpos( $rendered, 'JURA WE6' ), true );
check( 'page block carries the permalink', false !== strpos( $rendered, 'https://example.test/produkt/10' ), true );
check( 'page block states the price range', false !== strpos( $rendered, '12 900 – 18 400 kr' ), true );
check( 'price range is offered for indexing', false !== strpos( WP_AI_Advisor_Page_Context::indexable_fields( 10 ), '12 900' ), true );
check( 'nothing is indexed without a price range', WP_AI_Advisor_Page_Context::indexable_fields( 11 ), '' );
check( 'unknown post renders nothing', WP_AI_Advisor_Page_Context::render( 9999 ), '' );

// An HTML-bearing field value must not reach the prompt as markup.
$GLOBALS['wp_ai_advisor_test_meta'][10] = array( 'hvor_mye_koster_det' => '<b>999</b> kr' );
check( 'markup is stripped from field values', WP_AI_Advisor_Page_Context::price_range( 10 ), '999 kr' );

// The field name is a meta key, so case must survive sanitisation.
check(
	'field name keeps its case',
	WP_AI_Advisor_Settings::sanitize( array( 'price_field' => 'hvor_mye_koster_det' ) )['price_field'],
	'hvor_mye_koster_det'
);
check(
	'mixed-case field name is preserved',
	WP_AI_Advisor_Settings::sanitize( array( 'price_field' => 'priceRange' ) )['price_field'],
	'priceRange'
);
check(
	'unsafe characters are stripped from the field name',
	WP_AI_Advisor_Settings::sanitize( array( 'price_field' => "hvor mye'; DROP--" ) )['price_field'],
	'hvormyeDROP--'
);
$GLOBALS['wp_ai_advisor_test_meta'][10] = array( 'hvor_mye_koster_det' => '12 900 – 18 400 kr' );

// --- Which post types the widget adopts ------------------------------------
// An ordinary Page is singular too, so "is this a singular view" is not the
// same question as "is this the kind of thing the advisor is about".
$GLOBALS['wp_ai_advisor_test_post_types'] = array( 'post', 'page', 'product', 'attachment' );
$GLOBALS['wp_ai_advisor_test_options']    = array();

check(
	'by default only non-content types are subjects',
	WP_AI_Advisor_Settings::context_post_types(),
	array( 'product' )
);

wp_ai_advisor_test_post( 50, array( 'post_type' => 'product' ) );
wp_ai_advisor_test_post( 51, array( 'post_type' => 'page' ) );
wp_ai_advisor_test_post( 52, array( 'post_type' => 'post' ) );

check( 'a product is a subject', WP_AI_Advisor_Settings::is_subject_type( 50 ), true );
check( 'an ordinary page is not', WP_AI_Advisor_Settings::is_subject_type( 51 ), false );
check( 'a blog post is not', WP_AI_Advisor_Settings::is_subject_type( 52 ), false );

// An explicit choice is honoured, including an empty one.
$GLOBALS['wp_ai_advisor_test_options']['wp_ai_advisor_settings'] = array( 'context_post_types' => array( 'page' ) );
check( 'a configured list is used', WP_AI_Advisor_Settings::context_post_types(), array( 'page' ) );
check( 'pages become subjects when chosen', WP_AI_Advisor_Settings::is_subject_type( 51 ), true );
check( 'products stop being subjects when not chosen', WP_AI_Advisor_Settings::is_subject_type( 50 ), false );

$GLOBALS['wp_ai_advisor_test_options']['wp_ai_advisor_settings'] = array( 'context_post_types' => array() );
check( 'an empty list means nothing is adopted', WP_AI_Advisor_Settings::is_subject_type( 50 ), false );

$saved = WP_AI_Advisor_Settings::sanitize( array( '_form' => 'settings', 'context_post_types' => array( 'product', 'nonsense' ) ) );
check( 'unknown post types are dropped on save', $saved['context_post_types'], array( 'product' ) );

$cleared = WP_AI_Advisor_Settings::sanitize( array( '_form' => 'settings' ) );
check( 'unticking everything saves an empty list, not the default', $cleared['context_post_types'], array() );

$GLOBALS['wp_ai_advisor_test_options']    = array();
$GLOBALS['wp_ai_advisor_test_post_types'] = array( 'post', 'page' );

// --- Question sets ---------------------------------------------------------
$GLOBALS['wp_ai_advisor_test_options']['wp_ai_advisor_settings'] = array(
	'suggestion_sets' => array(
		array( 'label' => 'Espresso', 'terms' => array( 30 ), 'questions' => array( 'Hvor stor er tanken?' ) ),
		array( 'label' => 'Kaffemaskiner', 'terms' => array( 20 ), 'questions' => array( 'Hva koster denne?', 'Hvem passer den for?' ) ),
		array( 'label' => 'Tom', 'terms' => array( 40 ), 'questions' => array() ),
		array( 'label' => 'Uten kategori', 'terms' => array(), 'questions' => array( 'Skal aldri treffe' ) ),
	),
);

check( 'sets without questions are dropped', count( WP_AI_Advisor_Settings::suggestion_sets() ), 3 );
check(
	'a matching set is used',
	WP_AI_Advisor_Settings::suggestions_for_terms( array( 20 ) ),
	array( 'Hva koster denne?', 'Hvem passer den for?' )
);
check(
	'the first matching set wins',
	WP_AI_Advisor_Settings::suggestions_for_terms( array( 20, 30 ) ),
	array( 'Hvor stor er tanken?' )
);
check( 'no match returns nothing', WP_AI_Advisor_Settings::suggestions_for_terms( array( 99 ) ), array() );
check( 'no terms returns nothing', WP_AI_Advisor_Settings::suggestions_for_terms( array() ), array() );
check( 'a set with no categories never matches', WP_AI_Advisor_Settings::suggestions_for_terms( array( 0 ) ), array() );

// A product filed only under a child category still matches a parent set.
$GLOBALS['wp_ai_advisor_test_taxonomies'] = true;
$GLOBALS['wp_ai_advisor_test_terms'][10]  = array( 21 );
$GLOBALS['wp_ai_advisor_test_ancestors'][21] = array( 20 );

check( 'term ids include ancestors', WP_AI_Advisor_Page_Context::term_ids( 10 ), array( 21, 20 ) );
check(
	'a child category inherits its parent set',
	WP_AI_Advisor_Settings::suggestions_for_terms( WP_AI_Advisor_Page_Context::term_ids( 10 ) ),
	array( 'Hva koster denne?', 'Hvem passer den for?' )
);
$GLOBALS['wp_ai_advisor_test_taxonomies'] = false;

// Rows arriving from the admin form.
$saved = WP_AI_Advisor_Settings::sanitize(
	array(
		'suggestion_sets' => array(
			array( 'label' => ' Kaffe ', 'terms' => array( '20', 'x', '20', '-3' ), 'questions' => "  A  \n\n B \n" ),
			array( 'label' => 'Blank', 'terms' => array( '21' ), 'questions' => "   \n  " ),
		),
	)
);

check( 'blank rows are not saved', count( $saved['suggestion_sets'] ), 1 );
check( 'term ids are deduplicated, and junk dropped rather than folded', $saved['suggestion_sets'][0]['terms'], array( 20 ) );
check( 'questions are split and trimmed', $saved['suggestion_sets'][0]['questions'], array( 'A', 'B' ) );
check( 'labels are trimmed', $saved['suggestion_sets'][0]['label'], 'Kaffe' );

$GLOBALS['wp_ai_advisor_test_options'] = array();

// --- Settings migration ----------------------------------------------------
// A default only applies to a site that has never saved, so a renamed default
// has to be migrated or it silently stops matching anything.
$GLOBALS['wp_ai_advisor_test_options'] = array(
	'wp_ai_advisor_settings' => array( 'price_field' => 'price_range' ),
);
WP_AI_Advisor_Settings::maybe_migrate();
check(
	'superseded default is migrated',
	$GLOBALS['wp_ai_advisor_test_options']['wp_ai_advisor_settings']['price_field'],
	'hvor_mye_koster_det'
);
check(
	'migration records its version',
	(int) $GLOBALS['wp_ai_advisor_test_options']['wp_ai_advisor_settings_version'],
	1
);

$GLOBALS['wp_ai_advisor_test_options'] = array(
	'wp_ai_advisor_settings' => array( 'price_field' => 'min_egen_pris' ),
);
WP_AI_Advisor_Settings::maybe_migrate();
check(
	'a deliberate field name is left alone',
	$GLOBALS['wp_ai_advisor_test_options']['wp_ai_advisor_settings']['price_field'],
	'min_egen_pris'
);

$GLOBALS['wp_ai_advisor_test_options'] = array(
	'wp_ai_advisor_settings'         => array( 'price_field' => 'price_range' ),
	'wp_ai_advisor_settings_version' => 1,
);
WP_AI_Advisor_Settings::maybe_migrate();
check(
	'migration does not run a second time',
	$GLOBALS['wp_ai_advisor_test_options']['wp_ai_advisor_settings']['price_field'],
	'price_range'
);

$GLOBALS['wp_ai_advisor_test_options'] = array();

// --- Translation coverage --------------------------------------------------
$pot = dirname( __DIR__ ) . '/languages/wp-ai-advisor.pot';
$mo  = dirname( __DIR__ ) . '/languages/wp-ai-advisor-nb_NO.mo';

check( 'POT file exists', file_exists( $pot ), true );
check( 'nb_NO catalogue exists', file_exists( $mo ), true );

if ( file_exists( $pot ) && file_exists( $mo ) ) {
	$translations = wp_ai_advisor_read_mo( $mo );
	$msgids       = wp_ai_advisor_read_pot( $pot );

	check( 'POT has strings', count( $msgids ) > 100, true );

	$untranslated = array();

	foreach ( $msgids as $msgid ) {
		if ( empty( $translations[ $msgid ] ) ) {
			$untranslated[] = $msgid;
		}
	}

	check(
		'every POT string has a Norwegian translation',
		$untranslated,
		array(),
	);

	// A translation that loses a printf placeholder throws a fatal at runtime.
	$broken = array();

	foreach ( $msgids as $msgid ) {
		if ( empty( $translations[ $msgid ] ) ) {
			continue;
		}

		preg_match_all( '/%(?:\\d+\\$)?[sd]/', $msgid, $want );
		preg_match_all( '/%(?:\\d+\\$)?[sd]/', $translations[ $msgid ], $got );

		sort( $want[0] );
		sort( $got[0] );

		if ( $want[0] !== $got[0] ) {
			$broken[] = $msgid;
		}
	}

	check( 'placeholders survive translation', $broken, array() );
}

// --- Document text extraction ---------------------------------------------
$docs = new WP_AI_Advisor_Documents();
$tmp = sys_get_temp_dir() . '/aiadv-test.html';
file_put_contents( $tmp, '<p>Åpent 08&ndash;18</p><script>nope</script><p>Velkommen</p>' );
$text = $docs->extract_text( $tmp, 'html' );
check( 'html document strips scripts', strpos( $text, 'nope' ), false );
check( 'html document keeps prose', strpos( $text, 'Velkommen' ) !== false, true );

$tmp2 = sys_get_temp_dir() . '/aiadv-test.txt';
file_put_contents( $tmp2, "  Linje  1  \n\n\n\n Linje 2 " );
check( 'txt whitespace collapsed', $docs->extract_text( $tmp2, 'txt' ), "Linje 1\n\nLinje 2" );

file_put_contents( $tmp2, '   ' );
check( 'empty document returns WP_Error-ish', is_object( $docs->extract_text( $tmp2, 'txt' ) ), true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
