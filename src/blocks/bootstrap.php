<?php
/**
 * Block registration: the native SVG block.
 *
 * Dynamic block — save() is null and the server renders it. By default the SVG
 * is output as a normal <img> (exactly like any other image). Turning on the
 * block's "Render inline" option embeds the SVG through the same engine
 * (SVGSupport\Rendering\Inliner) the front-end replacement uses, so inline
 * output is sanitized, cached and attribute-complete — and can be styled and
 * recoloured with CSS. Registered regardless of Advanced Mode: a free feature.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render callback for svg-support/svg.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function bodhi_svgs_render_svg_block( $attributes ) {
	$id = isset( $attributes['id'] ) ? (int) $attributes['id'] : 0;
	if ( ! $id ) {
		return '';
	}

	$classes = array( 'wp-block-svg-support-svg' );
	if ( ! empty( $attributes['align'] ) ) {
		$classes[] = 'align' . $attributes['align'];
	}
	if ( ! empty( $attributes['className'] ) ) {
		$classes[] = $attributes['className'];
	}

	// Default: render as a normal image. Inline is an opt-in (it's what enables
	// CSS styling + the single-colour option).
	if ( empty( $attributes['inline'] ) ) {
		return bodhi_svgs_render_svg_block_image( $id, $attributes, $classes );
	}

	$attrs = array( 'class' => implode( ' ', $classes ) );

	foreach ( array( 'width', 'height' ) as $dim ) {
		if ( ! empty( $attributes[ $dim ] ) ) {
			$attrs[ $dim ] = $attributes[ $dim ];
		}
	}
	if ( ! empty( $attributes['customId'] ) ) {
		$attrs['id'] = $attributes['customId'];
	}
	if ( isset( $attributes['alt'] ) ) {
		$attrs['alt'] = $attributes['alt'];
	}

	// Single-colour option: flatten the SVG's fills to currentColor, and for a
	// specific picked colour also set the container's CSS color so it renders
	// in that colour. '' keeps the SVG's own colours; 'currentColor' follows
	// the theme's text colour. (Per-colour / per-element editing is premium.)
	$color = isset( $attributes['color'] ) ? trim( (string) $attributes['color'] ) : '';
	if ( '' !== $color ) {
		$attrs['currentcolor'] = true;
		if ( 0 !== strcasecmp( $color, 'currentColor' ) ) {
			$hex = bodhi_svgs_sanitize_css_hex( $color );
			if ( '' !== $hex ) {
				$attrs['style'] = 'color:' . $hex;
			}
		}
	}

	$svg = \SVGSupport\Rendering\Inliner::render_attachment( $id, $attrs );
	if ( '' !== $svg ) {
		return $svg;
	}

	// Graceful fallback: if the file is missing/unreadable, degrade to a plain
	// image so the page never breaks.
	return bodhi_svgs_render_svg_block_image( $id, $attributes, $classes );
}

/**
 * Render the block as a plain <img> (the default, non-inline output). Width and
 * height go through inline CSS so any CSS length works (120px, 10rem, 100%).
 *
 * @param int   $id         Attachment ID.
 * @param array $attributes Block attributes.
 * @param array $classes    Resolved block classes.
 * @return string
 */
function bodhi_svgs_render_svg_block_image( $id, $attributes, $classes ) {
	$url = ! empty( $attributes['url'] ) ? $attributes['url'] : wp_get_attachment_url( $id );
	if ( ! $url ) {
		return '';
	}

	$style = '';
	foreach ( array( 'width', 'height' ) as $dim ) {
		if ( ! empty( $attributes[ $dim ] ) ) {
			$style .= $dim . ':' . $attributes[ $dim ] . ';';
		}
	}

	$id_attr    = ! empty( $attributes['customId'] ) ? ' id="' . esc_attr( $attributes['customId'] ) . '"' : '';
	$style_attr = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';

	return sprintf(
		'<img class="%s" src="%s" alt="%s"%s%s />',
		esc_attr( implode( ' ', $classes ) ),
		esc_url( $url ),
		esc_attr( isset( $attributes['alt'] ) ? $attributes['alt'] : '' ),
		$id_attr,
		$style_attr
	);
}

/**
 * Validate a CSS hex colour (#rgb, #rgba, #rrggbb, #rrggbbaa). Returns '' if
 * the value isn't a plain hex colour, so nothing arbitrary reaches the style
 * attribute.
 *
 * @param string $color
 * @return string
 */
function bodhi_svgs_sanitize_css_hex( $color ) {
	$color = trim( (string) $color );
	if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color ) ) {
		return $color;
	}
	return '';
}

/**
 * Register the block and its (build-free) editor script.
 */
function bodhi_svgs_register_blocks() {
	wp_register_script(
		'svg-support-block',
		BODHI_SVGS_PLUGIN_URL . 'blocks/svg/index.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		BODHI_SVGS_VERSION,
		true
	);

	register_block_type(
		BODHI_SVGS_PLUGIN_PATH . 'blocks/svg',
		array(
			'render_callback' => 'bodhi_svgs_render_svg_block',
		)
	);
}
add_action( 'init', 'bodhi_svgs_register_blocks' );
