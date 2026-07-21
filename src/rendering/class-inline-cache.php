<?php
/**
 * Cache for server-side inlined SVG markup.
 *
 * Sanitizing + parsing an SVG on every render would be wasteful, so the final
 * inline markup is cached keyed to the file path + modification time + plugin
 * version. The mtime in the key makes entries self-invalidating: re-uploading
 * or editing a file changes its mtime, which changes the key, and the stale
 * entry simply expires. Object cache first (fast, may be non-persistent),
 * transient fallback (persistent).
 */

namespace SVGSupport\Rendering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InlineCache {

	const GROUP = 'svg_support';

	/**
	 * Build the self-invalidating cache key for a file.
	 *
	 * @param string $path   Absolute file path.
	 * @param int    $mtime  File modification time.
	 * @param string $flavor Optional variant discriminator (e.g. 'currentcolor').
	 * @return string
	 */
	public static function key( $path, $mtime, $flavor = '' ) {
		return 'svgs_inline_' . md5( $path . '|' . $mtime . '|' . BODHI_SVGS_VERSION . '|' . $flavor );
	}

	/**
	 * @param string $key Cache key from self::key().
	 * @return string|false Cached markup, or false on miss.
	 */
	public static function get( $key ) {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found && is_string( $value ) ) {
			return $value;
		}

		$value = get_transient( $key );
		return is_string( $value ) ? $value : false;
	}

	/**
	 * @param string $key    Cache key from self::key().
	 * @param string $markup Inline SVG markup to store.
	 */
	public static function set( $key, $markup ) {
		wp_cache_set( $key, $markup, self::GROUP, DAY_IN_SECONDS );
		set_transient( $key, $markup, WEEK_IN_SECONDS );
	}
}
