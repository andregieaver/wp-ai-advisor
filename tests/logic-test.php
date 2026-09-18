<?php
/**
 * Logic tests for WP AI Advisor.
 *
 * Run with: php tests/logic-test.php
 *
 * @package WP_AI_Advisor
 */

require_once __DIR__ . '/bootstrap.php';

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
