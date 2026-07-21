<?php
/**
 * Standalone sanitizer regression runner.
 *
 * Exercises the plugin's REAL sanitization engine — the vendored
 * enshrined/svg-sanitize library configured with SVG Support's own
 * bodhi_svg_tags / bodhi_svg_attributes whitelists and removeRemoteReferences,
 * exactly as bodhi_svgs_sanitize() sets it up — against the fixture corpus.
 *
 * It needs no WordPress and no Docker: it shims the handful of WP functions the
 * whitelist classes call, so it runs anywhere PHP is available (locally and in
 * CI) as a fast first gate. WordPress-dependent behavior (upload prefilter,
 * roles, nonce, plaintext-.svgz validation, settings) is covered by the
 * WP-PHPUnit suite in tests/phpunit.
 *
 * Usage:  php tests/sanitizer/run.php
 * Exit:   0 all assertions passed, 1 any failure.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$PLUGIN_ROOT = dirname( __DIR__, 2 );

// --- Minimal WordPress shims (only what the whitelist classes touch) ---------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $PLUGIN_ROOT . '/' );
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action() { return true; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() { return true; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text ) { return $text; }
}

// --- Load the real sanitizer + the plugin's actual whitelists ----------------
require $PLUGIN_ROOT . '/vendor/autoload.php';
require $PLUGIN_ROOT . '/includes/svg-tags.php';
require $PLUGIN_ROOT . '/includes/svg-attributes.php';

use enshrined\svgSanitize\Sanitizer;

/**
 * Mirror of bodhi_svgs_sanitize()'s core configuration + gzip round-trip,
 * operating on a string instead of a file so it is pure and testable.
 */
function svgs_test_sanitize_string( $dirty, $minify = false ) {
	$sanitizer = new Sanitizer();
	$sanitizer->setAllowedTags( new bodhi_svg_tags() );
	$sanitizer->setAllowedAttrs( new bodhi_svg_attributes() );
	$sanitizer->removeRemoteReferences( true );
	if ( $minify ) {
		$sanitizer->minify( true );
	}

	$is_zipped = ( strncmp( $dirty, "\x1f\x8b\x08", 3 ) === 0 );
	if ( $is_zipped ) {
		$dirty = gzdecode( $dirty );
		if ( $dirty === false ) {
			return false;
		}
	}

	$clean = $sanitizer->sanitize( $dirty );
	if ( $clean === false ) {
		return false;
	}

	if ( $is_zipped ) {
		$clean = gzencode( $clean );
	}

	return $clean;
}

// --- Tiny assertion harness --------------------------------------------------
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__failures'] = array();

function check( $label, $cond ) {
	if ( $cond ) {
		$GLOBALS['__pass']++;
	} else {
		$GLOBALS['__fail']++;
		$GLOBALS['__failures'][] = $label;
		fwrite( STDOUT, "  \033[31mFAIL\033[0m  $label\n" );
	}
}

function section( $name ) {
	fwrite( STDOUT, "\n\033[1m$name\033[0m\n" );
}

function note( $msg ) {
	fwrite( STDOUT, "  \033[33mNOTE\033[0m  $msg\n" );
}

$benign_dir    = "$PLUGIN_ROOT/tests/fixtures/benign";
$malicious_dir = "$PLUGIN_ROOT/tests/fixtures/malicious";

// --- Benign: must survive, stay valid SVG, keep their signature content ------
section( 'Benign fixtures survive sanitization' );

$benign_expect = array(
	'simple-icon.svg'           => array( '<path', 'M12 2' ),
	'viewbox-no-dimensions.svg' => array( '<circle', 'viewBox' ),
	'nested-svg.svg'            => array( '<rect', '<path' ),
	'use-symbol.svg'            => array( '<use', '<symbol' ),
	'embedded-style.svg'        => array( '<style', 'fill:#9333ea' ),
	'gradient.svg'              => array( 'linearGradient', 'url(#g)' ),
	'title-desc-a11y.svg'       => array( '<title', '<desc', 'aria-labelledby' ),
	'currentcolor.svg'          => array( 'currentColor' ),
	'text-element.svg'          => array( '<text', 'SVG OK' ),
	// NOTE: animate-smil.svg deliberately omitted here — see the
	// "Documented current behavior" section below. The sanitizer strips SMIL
	// animation elements, so the shape it keeps is the <circle>, not <animate>.
	'animate-smil.svg'          => array( '<circle' ),
);

foreach ( $benign_expect as $file => $needles ) {
	$dirty = file_get_contents( "$benign_dir/$file" );
	$clean = svgs_test_sanitize_string( $dirty );
	check( "$file — sanitizes without error", $clean !== false );
	check( "$file — remains an <svg>", $clean !== false && stripos( $clean, '<svg' ) !== false );
	foreach ( $needles as $needle ) {
		check( "$file — keeps \"$needle\"", $clean !== false && strpos( $clean, $needle ) !== false );
	}
}

// --- Malicious: dangerous payload must be gone; still returns valid SVG -------
section( 'Malicious fixtures are neutralized' );

// For each fixture, the case-insensitive substrings that MUST NOT survive.
$malicious_forbid = array(
	'script-tag.svg'                 => array( '<script', "alert('xss-script-tag')" ),
	'onload-attr.svg'                => array( 'onload' ),
	'onclick-attr.svg'               => array( 'onclick' ),
	'anchor-javascript-href.svg'     => array( 'javascript:' ),
	'xlink-javascript-href.svg'      => array( 'javascript:' ),
	'mixedcase-xlink-href.svg'       => array( 'javascript:' ),
	'foreignobject-script.svg'       => array( '<script', 'foreignobject' ),
	// external-image-href.svg is intentionally NOT here — remote <image href>
	// currently survives (see "Documented current behavior" below).
	'external-use-href.svg'          => array( 'evil.example.com' ),
	'xxe-entity.svg'                 => array( 'file:///', '<!entity', 'system' ),
	'billion-laughs.svg'             => array( '<!entity' ),
	'animate-values-javascript.svg'  => array( 'javascript:' ),
	'handler-element.svg'            => array( '<handler' ),
	'set-onbegin.svg'                => array( 'onbegin' ),
);

foreach ( $malicious_forbid as $file => $forbidden ) {
	$dirty = file_get_contents( "$malicious_dir/$file" );
	$clean = svgs_test_sanitize_string( $dirty );
	// The sanitizer should neutralize, not reject outright — a returned string
	// that still parses as SVG is the expected shape (false is also acceptable
	// for the entity-expansion cases as long as nothing dangerous leaks).
	if ( $clean === false ) {
		check( "$file — rejected outright (acceptable)", true );
		continue;
	}
	$hay = strtolower( $clean );
	foreach ( $forbidden as $bad ) {
		check( "$file — strips \"$bad\"", strpos( $hay, strtolower( $bad ) ) === false );
	}
	// Bound output so an entity expansion can't quietly balloon.
	check( "$file — output stays bounded (<64KB)", strlen( $clean ) < 65536 );
}

// --- Documented current behavior (lock it so future drift is caught) ---------
// These assertions encode what the sanitizer does TODAY, including two known
// limitations. They are green on purpose: if a dependency bump changes either
// behavior, these flip to red and we find out on the next run rather than in
// production. Both are flagged for the v3.0 server-side engine.
section( 'Documented current behavior (baseline lock)' );

// 1. SMIL animation elements are not in the sanitizer whitelist, so they are
//    removed on upload. Locking this so a dependency bump that changes it is
//    caught here rather than in production.
$smil = svgs_test_sanitize_string( file_get_contents( "$benign_dir/animate-smil.svg" ) );
check( 'SMIL <animate> is stripped by the sanitizer', $smil !== false && stripos( $smil, '<animate' ) === false );
check( 'SMIL <set> is stripped by the sanitizer', $smil !== false && stripos( $smil, '<set' ) === false );
check( 'shape around the SMIL survives (<circle>)', $smil !== false && stripos( $smil, '<circle' ) !== false );
note( 'SMIL animation elements are removed on upload by the sanitizer whitelist.' );

// 2. Remote <image href> passes through (enshrined strips remote <use> but not
//    remote <image>). Privacy/tracking vector when rendered inline. Longstanding
//    (not a 2.6.0 regression); v3.0 server-side engine should strip/block it.
$img = svgs_test_sanitize_string( file_get_contents( "$malicious_dir/external-image-href.svg" ) );
check( 'remote <image href> currently survives (known limitation)', $img !== false && stripos( $img, 'evil.example.com' ) !== false );
check( 'remote <use href> IS stripped (for contrast)', stripos( (string) svgs_test_sanitize_string( file_get_contents( "$malicious_dir/external-use-href.svg" ) ), 'evil.example.com' ) === false );
note( 'Remote <image href> is not stripped by enshrined — address in the v3.0 server-side engine.' );

// --- .svgz gzip round-trip: decode, clean, re-encode -------------------------
section( 'Gzipped .svgz round-trip' );

$plain_dirty = file_get_contents( "$malicious_dir/script-tag.svg" );
$gz_dirty    = gzencode( $plain_dirty );
check( 'input is gzip-framed', strncmp( $gz_dirty, "\x1f\x8b\x08", 3 ) === 0 );

$gz_clean = svgs_test_sanitize_string( $gz_dirty );
check( '.svgz — sanitizes without error', $gz_clean !== false );
check( '.svgz — output is still gzip-framed', $gz_clean !== false && strncmp( $gz_clean, "\x1f\x8b\x08", 3 ) === 0 );
$decoded = $gz_clean !== false ? gzdecode( $gz_clean ) : '';
check( '.svgz — decoded payload is clean', strpos( strtolower( (string) $decoded ), '<script' ) === false );
check( '.svgz — decoded payload keeps the safe <path>', strpos( (string) $decoded, '<path' ) !== false );

// --- Public filter extension points still work -------------------------------
section( 'Public API: svg_allowed_tags / svg_allowed_attributes exist' );
check( 'bodhi_svg_tags::getTags returns a non-empty array', is_array( bodhi_svg_tags::getTags() ) && count( bodhi_svg_tags::getTags() ) > 0 );
check( 'bodhi_svg_attributes::getAttributes returns a non-empty array', is_array( bodhi_svg_attributes::getAttributes() ) && count( bodhi_svg_attributes::getAttributes() ) > 0 );

// --- Summary -----------------------------------------------------------------
$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
fwrite( STDOUT, "\n" );
if ( $fail === 0 ) {
	fwrite( STDOUT, "\033[32m✓ ALL PASSED\033[0m — $pass assertions\n" );
	exit( 0 );
}
fwrite( STDOUT, "\033[31m✗ $fail FAILED\033[0m / " . ( $pass + $fail ) . " assertions\n" );
exit( 1 );
