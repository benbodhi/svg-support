<?php
/**
 * Server-side SVG inlining engine.
 *
 * Replaces targeted <img> tags with sanitized inline <svg> markup at render
 * time in PHP — no frontend JavaScript, no flash of the un-swapped image, no
 * layout shift. Unlike the legacy JS swap, it preserves the original image's
 * width, height, alt, style, aria- and data- attributes, and it hardens
 * output by stripping remote <image> references the sanitizer lets through.
 *
 * Activation: the content/thumbnail hooks only act when Advanced Mode is on
 * AND the site is in server render mode ('render_mode' === 'server' — the
 * default for fresh installs, opt-in for upgraded sites). The SVG block calls
 * render_attachment() directly and works regardless of mode.
 */

namespace SVGSupport\Rendering;

use enshrined\svgSanitize\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Inliner {

	/**
	 * Per-request counter for generated svg-replaced-N ids/classes,
	 * mirroring the legacy JS swap's numbering contract.
	 *
	 * @var int
	 */
	private static $counter = 0;

	/* ---------------------------------------------------------------- mode */

	/**
	 * Whether the automatic IMG→SVG replacement hooks should act.
	 */
	public static function is_server_mode() {
		return function_exists( 'bodhi_svgs_advanced_mode' )
			&& bodhi_svgs_advanced_mode()
			&& function_exists( 'bodhi_svgs_render_mode' )
			&& 'server' === bodhi_svgs_render_mode();
	}

	/**
	 * The class users add to images they want inlined.
	 */
	public static function target_class() {
		global $bodhi_svgs_options;
		return ! empty( $bodhi_svgs_options['css_target'] )
			? (string) $bodhi_svgs_options['css_target']
			: 'style-svg';
	}

	/* --------------------------------------------------------------- hooks */

	/**
	 * wp_content_img_tag filter: every <img> in content/excerpt/widget markup.
	 *
	 * @param string $img_html      The full <img> tag.
	 * @param string $context       Filter context (e.g. 'the_content').
	 * @param int    $attachment_id Attachment ID when WP could detect one, else 0.
	 * @return string
	 */
	public static function filter_content_img( $img_html, $context = '', $attachment_id = 0 ) {
		if ( ! self::is_server_mode() ) {
			return $img_html;
		}

		global $bodhi_svgs_options;
		$force = ! empty( $bodhi_svgs_options['force_inline_svg'] );

		if ( ! $force && ! self::has_class( $img_html, self::target_class() ) ) {
			return $img_html;
		}

		$svg = self::render_img_tag( $img_html, (int) $attachment_id );

		return '' !== $svg ? $svg : $img_html;
	}

	/**
	 * post_thumbnail_html filter: featured images with the per-post inline
	 * meta enabled (same condition the legacy class-adder uses).
	 *
	 * @param string $html    Thumbnail markup (usually one <img>).
	 * @param int    $post_id Post ID.
	 * @param int    $thumb_id Attachment ID of the thumbnail.
	 * @return string
	 */
	public static function filter_thumbnail( $html, $post_id = 0, $thumb_id = 0 ) {
		if ( ! self::is_server_mode() || ! is_singular() || '' === $html ) {
			return $html;
		}

		$meta = get_post_meta( $post_id, 'inline_featured_image' );
		if ( ! is_array( $meta ) || ! in_array( 1, $meta ) ) {
			return $html;
		}

		if ( ! preg_match( '/<img\b[^>]*>/i', $html, $m ) ) {
			return $html;
		}

		$svg = self::render_img_tag( $m[0], (int) $thumb_id );

		return '' !== $svg ? str_replace( $m[0], $svg, $html ) : $html;
	}

	/* ------------------------------------------------------------- render */

	/**
	 * Convert one <img> tag to inline SVG, carrying its attributes over.
	 *
	 * @param string $img_html      The full <img> tag.
	 * @param int    $attachment_id Attachment ID if known (0 = resolve from markup).
	 * @return string Inline <svg> markup, or '' when the image can't/shouldn't inline.
	 */
	public static function render_img_tag( $img_html, $attachment_id = 0 ) {
		$attrs = self::parse_img_attributes( $img_html );
		if ( null === $attrs ) {
			return '';
		}

		if ( ! $attachment_id && isset( $attrs['class'] ) && preg_match( '/wp-image-(\d+)/', $attrs['class'], $m ) ) {
			$attachment_id = (int) $m[1];
		}

		$path = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ( ! $path || ! file_exists( $path ) ) && ! empty( $attrs['src'] ) ) {
			$path = self::path_from_upload_url( $attrs['src'] );
		}

		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
			return '';
		}

		$svg_markup = self::get_inline_svg( $path );
		if ( '' === $svg_markup ) {
			return '';
		}

		$html = self::merge_attributes( $svg_markup, $attrs );
		if ( '' === $html ) {
			return '';
		}

		/**
		 * Filter the final inline SVG markup produced by the server-side engine.
		 *
		 * @param string $html          Inline <svg> markup about to be output.
		 * @param int    $attachment_id Attachment ID (0 when resolved by path).
		 * @param string $img_html      The original <img> tag that was replaced.
		 */
		return apply_filters( 'bodhi_svgs_inline_svg', $html, $attachment_id, $img_html );
	}

	/**
	 * Render an attachment directly as inline SVG (used by the SVG block).
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $attrs         img-style attributes to merge (class, width,
	 *                             height, id, style, alt, aria-*, data-*), plus
	 *                             'currentcolor' => true to map fills to currentColor.
	 * @return string
	 */
	public static function render_attachment( $attachment_id, $attrs = array() ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
			return '';
		}

		$currentcolor = ! empty( $attrs['currentcolor'] );
		unset( $attrs['currentcolor'] );

		$svg_markup = self::get_inline_svg( $path, $currentcolor ? 'currentcolor' : '' );
		if ( '' === $svg_markup ) {
			return '';
		}

		$html = self::merge_attributes( $svg_markup, $attrs );

		return apply_filters( 'bodhi_svgs_inline_svg', $html, $attachment_id, '' );
	}

	/* ------------------------------------------------- sanitize + cache -- */

	/**
	 * Sanitized, hardened inline markup for an SVG file (cached).
	 *
	 * @param string $path   Absolute path to the .svg/.svgz file.
	 * @param string $flavor '' or 'currentcolor'.
	 * @return string Root <svg>…</svg> markup, or '' on failure.
	 */
	public static function get_inline_svg( $path, $flavor = '' ) {
		$mtime = (int) @filemtime( $path );
		$key   = InlineCache::key( $path, $mtime, $flavor );

		$cached = InlineCache::get( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$raw = @file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return '';
		}

		// .svgz arrives gzip-compressed on disk.
		if ( 0 === strncmp( $raw, "\x1f\x8b\x08", 3 ) ) {
			$raw = gzdecode( $raw );
			if ( false === $raw ) {
				return '';
			}
		}

		$clean = self::sanitize_svg_string( $raw );
		if ( '' === $clean ) {
			return '';
		}

		$svg = self::harden_and_extract_root( $clean, 'currentcolor' === $flavor );
		if ( '' === $svg ) {
			return '';
		}

		InlineCache::set( $key, $svg );

		return $svg;
	}

	/**
	 * Run the plugin's standard sanitizer configuration over a string.
	 * Mirrors bodhi_svgs_sanitize() (same whitelists, same remote-reference
	 * removal) but operates on a string instead of rewriting a file.
	 *
	 * @param string $dirty Raw SVG markup.
	 * @return string Sanitized markup, or '' on failure.
	 */
	public static function sanitize_svg_string( $dirty ) {
		$sanitizer = new Sanitizer();
		$sanitizer->setAllowedTags( new \bodhi_svg_tags() );
		$sanitizer->setAllowedAttrs( new \bodhi_svg_attributes() );
		$sanitizer->removeRemoteReferences( true );

		$clean = $sanitizer->sanitize( $dirty );

		return ( false === $clean || null === $clean ) ? '' : $clean;
	}

	/**
	 * Post-sanitizer hardening + root extraction:
	 *  - remove <image> elements with remote href/xlink:href (the sanitizer's
	 *    removeRemoteReferences strips remote <use> but lets remote <image>
	 *    through — a privacy/tracking vector when rendered inline)
	 *  - optionally map fill attributes to currentColor
	 *  - return only the <svg> root (no XML declaration)
	 *
	 * @param string $clean        Sanitizer output (XML document string).
	 * @param bool   $currentcolor Map non-none fill attributes to currentColor.
	 * @return string
	 */
	private static function harden_and_extract_root( $clean, $currentcolor = false ) {
		$doc              = new \DOMDocument();
		$prior            = libxml_use_internal_errors( true );
		$loaded           = $doc->loadXML( $clean, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );

		if ( ! $loaded || ! $doc->documentElement || 'svg' !== strtolower( $doc->documentElement->localName ) ) {
			return '';
		}

		// Remove <image> elements referencing remote resources.
		$remove = array();
		foreach ( $doc->getElementsByTagName( 'image' ) as $image ) {
			foreach ( array( 'href', 'xlink:href' ) as $attr_name ) {
				$href = $image->getAttribute( $attr_name );
				if ( '' === $href && false !== strpos( $attr_name, ':' ) ) {
					$href = $image->getAttributeNS( 'http://www.w3.org/1999/xlink', 'href' );
				}
				if ( $href && preg_match( '#^(?:https?:)?//#i', $href ) ) {
					$remove[] = $image;
					break;
				}
			}
		}
		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		if ( $currentcolor ) {
			self::map_fills_to_currentcolor( $doc->documentElement );
		}

		$svg = $doc->saveXML( $doc->documentElement );

		return is_string( $svg ) ? $svg : '';
	}

	/**
	 * Recursively map fill presentation attributes to currentColor,
	 * leaving fill="none" untouched.
	 */
	private static function map_fills_to_currentcolor( \DOMElement $el ) {
		$fill = $el->getAttribute( 'fill' );
		if ( '' !== $fill && 'none' !== strtolower( $fill ) ) {
			$el->setAttribute( 'fill', 'currentColor' );
		}

		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				self::map_fills_to_currentcolor( $child );
			}
		}
	}

	/* --------------------------------------------------- attribute merge -- */

	/**
	 * Merge the original img attributes onto the SVG root.
	 *
	 * Contract (documented improvement over the legacy JS swap, which kept only
	 * id and class): class union + replaced-svg markers; width, height, style
	 * and id carried over; alt mapped to accessible naming; aria-, data- and
	 * role attributes kept.
	 *
	 * @param string $svg_markup Root <svg> markup.
	 * @param array  $attrs      Attributes parsed off the original <img>.
	 * @return string
	 */
	public static function merge_attributes( $svg_markup, $attrs ) {
		$doc    = new \DOMDocument();
		$prior  = libxml_use_internal_errors( true );
		$loaded = $doc->loadXML( $svg_markup, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );

		if ( ! $loaded || ! $doc->documentElement ) {
			return '';
		}

		$svg = $doc->documentElement;
		self::$counter++;
		$n = self::$counter;

		// Classes: union of the img's classes, the svg's own root classes and
		// the replaced-svg markers the legacy swap established.
		$img_classes = isset( $attrs['class'] ) ? preg_split( '/\s+/', trim( $attrs['class'] ) ) : array();
		$svg_classes = preg_split( '/\s+/', trim( $svg->getAttribute( 'class' ) ) );
		$classes     = array_filter( array_unique( array_merge(
			(array) $img_classes,
			(array) $svg_classes,
			array( 'replaced-svg', 'svg-replaced-' . $n )
		) ) );
		$svg->setAttribute( 'class', implode( ' ', $classes ) );

		// id: img id wins; otherwise keep the svg's own; otherwise generate one
		// (matches the legacy numbering contract).
		if ( ! empty( $attrs['id'] ) ) {
			$svg->setAttribute( 'id', $attrs['id'] );
		} elseif ( '' === $svg->getAttribute( 'id' ) ) {
			$svg->setAttribute( 'id', 'svg-replaced-' . $n );
		}

		// Explicit dimensions on the img override the file's own.
		foreach ( array( 'width', 'height' ) as $dim ) {
			if ( ! empty( $attrs[ $dim ] ) ) {
				$svg->setAttribute( $dim, $attrs[ $dim ] );
			}
		}

		// Inline style: append the img's to any existing svg style.
		if ( ! empty( $attrs['style'] ) ) {
			$existing = rtrim( $svg->getAttribute( 'style' ), '; ' );
			$svg->setAttribute( 'style', ( '' !== $existing ? $existing . ';' : '' ) . $attrs['style'] );
		}

		// Accessibility: map alt to an accessible name unless the file already
		// provides one. Empty alt (decorative) maps to aria-hidden.
		$has_label = $svg->hasAttribute( 'aria-label' ) || $svg->hasAttribute( 'aria-labelledby' );
		if ( isset( $attrs['alt'] ) && ! $has_label ) {
			if ( '' !== trim( $attrs['alt'] ) ) {
				$svg->setAttribute( 'role', 'img' );
				$svg->setAttribute( 'aria-label', $attrs['alt'] );
			} elseif ( ! $svg->hasAttribute( 'aria-hidden' ) ) {
				$svg->setAttribute( 'aria-hidden', 'true' );
			}
		}

		// Carry over role (unless set above), aria-* and data-* verbatim.
		foreach ( $attrs as $name => $value ) {
			if ( 'role' === $name && ! $svg->hasAttribute( 'role' ) ) {
				$svg->setAttribute( 'role', $value );
			} elseif ( 0 === strpos( $name, 'aria-' ) && ! $svg->hasAttribute( $name ) ) {
				$svg->setAttribute( $name, $value );
			} elseif ( 0 === strpos( $name, 'data-' ) ) {
				$svg->setAttribute( $name, $value );
			}
		}

		$html = $doc->saveXML( $svg );

		return is_string( $html ) ? $html : '';
	}

	/* -------------------------------------------------------------- utils */

	/**
	 * Parse an <img> tag's attributes into a name => value array.
	 *
	 * @param string $img_html The <img> tag markup.
	 * @return array|null Attributes, or null when no img tag could be parsed.
	 */
	public static function parse_img_attributes( $img_html ) {
		$doc    = new \DOMDocument();
		$prior  = libxml_use_internal_errors( true );
		$loaded = $doc->loadHTML(
			'<?xml encoding="utf-8"?><html><body>' . $img_html . '</body></html>',
			LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );

		if ( ! $loaded ) {
			return null;
		}

		$img = $doc->getElementsByTagName( 'img' )->item( 0 );
		if ( ! $img ) {
			return null;
		}

		$attrs = array();
		foreach ( $img->attributes as $attr ) {
			$attrs[ strtolower( $attr->name ) ] = $attr->value;
		}

		return $attrs;
	}

	/**
	 * Whether an <img> tag carries a class (word-boundary match).
	 */
	public static function has_class( $img_html, $class ) {
		$attrs = self::parse_img_attributes( $img_html );
		if ( null === $attrs || empty( $attrs['class'] ) ) {
			return false;
		}

		return in_array( $class, preg_split( '/\s+/', trim( $attrs['class'] ) ), true );
	}

	/**
	 * Map an uploads URL to its file path, refusing anything that escapes the
	 * uploads directory.
	 *
	 * @param string $url Image src URL.
	 * @return string Absolute path, or '' when not resolvable/allowed.
	 */
	public static function path_from_upload_url( $url ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		// Normalize protocol-relative and scheme differences before comparing.
		$normalized_url  = preg_replace( '#^https?:#i', '', $url );
		$normalized_base = preg_replace( '#^https?:#i', '', $uploads['baseurl'] );

		if ( 0 !== strpos( $normalized_url, $normalized_base ) ) {
			return '';
		}

		$relative = substr( $normalized_url, strlen( $normalized_base ) );
		$relative = strtok( $relative, '?#' ); // drop query string / fragment
		$path     = $uploads['basedir'] . $relative;

		$real_base = realpath( $uploads['basedir'] );
		$real_path = realpath( $path );

		if ( ! $real_base || ! $real_path || 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			return '';
		}

		return $real_path;
	}
}
