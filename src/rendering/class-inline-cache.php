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
	 * Build the cache key for a file. Self-invalidating on file modification
	 * time, plugin version, and an explicit per-file generation token so a
	 * deliberate edit (e.g. a Pro recolor) can force fresh markup even when it
	 * lands within the same second as the previous render (mtime unchanged).
	 *
	 * @param string $path   Absolute file path.
	 * @param int    $mtime  File modification time.
	 * @param string $flavor Optional variant discriminator (e.g. 'currentcolor').
	 * @return string
	 */
	public static function key( $path, $mtime, $flavor = '' ) {
		$gen = (int) get_transient( self::gen_key( $path ) );
		return 'svgs_inline_' . md5( $path . '|' . $mtime . '|' . $gen . '|' . BODHI_SVGS_VERSION . '|' . $flavor );
	}

	/**
	 * Explicitly invalidate all cached markup for a file. Call after editing
	 * an SVG in place so the next render recomputes regardless of mtime.
	 *
	 * @param string $path Absolute file path.
	 */
	public static function invalidate( $path ) {
		$gen = (int) get_transient( self::gen_key( $path ) );
		set_transient( self::gen_key( $path ), $gen + 1, YEAR_IN_SECONDS );
	}

	private static function gen_key( $path ) {
		return 'svgs_inline_gen_' . md5( $path );
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
