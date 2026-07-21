<?php
/**
 * Rendering engine bootstrap: loads the engine classes, exposes the render
 * mode helper to the (procedural) legacy code, and registers the render-time
 * hooks. The hooks are registered unconditionally and gate themselves — they
 * are no-ops unless Advanced Mode is on AND the site is in server render mode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-inline-cache.php';
require_once __DIR__ . '/class-inliner.php';

/**
 * Which rendering engine handles inline SVG replacement.
 *
 * 'server' — render-time PHP inlining (default for fresh 3.0+ installs).
 * 'legacy' — the original JS class-swap (default for upgraded sites; absent
 *            key means an upgraded site that hasn't opted in).
 *
 * @return string 'server' or 'legacy'.
 */
function bodhi_svgs_render_mode() {
	global $bodhi_svgs_options;

	return ( isset( $bodhi_svgs_options['render_mode'] ) && 'server' === $bodhi_svgs_options['render_mode'] )
		? 'server'
		: 'legacy';
}

add_filter( 'wp_content_img_tag', array( 'SVGSupport\\Rendering\\Inliner', 'filter_content_img' ), 20, 3 );
add_filter( 'post_thumbnail_html', array( 'SVGSupport\\Rendering\\Inliner', 'filter_thumbnail' ), 20, 3 );
