<?php
/**
 * Block registration: the native Inline SVG block.
 *
 * Dynamic block — save() is null and the server renders through the same
 * engine (SVGSupport\Rendering\Inliner) the front-end replacement uses, so
 * block output is sanitized, cached and attribute-complete. Registered
 * regardless of Advanced Mode: the block is a standalone free feature.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render callback for svg-support/inline-svg.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function bodhi_svgs_render_inline_svg_block( $attributes ) {
	$id = isset( $attributes['id'] ) ? (int) $attributes['id'] : 0;
	if ( ! $id ) {
		return '';
	}

	$classes = array( 'wp-block-svg-support-inline-svg' );
	if ( ! empty( $attributes['align'] ) ) {
		$classes[] = 'align' . $attributes['align'];
	}
	if ( ! empty( $attributes['className'] ) ) {
		$classes[] = $attributes['className'];
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

	if ( ! empty( $attributes['useCurrentColor'] ) ) {
		$attrs['currentcolor'] = true;
	}

	$svg = \SVGSupport\Rendering\Inliner::render_attachment( $id, $attrs );

	if ( '' !== $svg ) {
		return $svg;
	}

	// Graceful fallback: if the file is missing/unreadable, degrade to a plain
	// img so the page never breaks.
	$url = ! empty( $attributes['url'] ) ? $attributes['url'] : wp_get_attachment_url( $id );
	if ( ! $url ) {
		return '';
	}

	return sprintf(
		'<img class="%s" src="%s" alt="%s" />',
		esc_attr( implode( ' ', $classes ) ),
		esc_url( $url ),
		esc_attr( isset( $attributes['alt'] ) ? $attributes['alt'] : '' )
	);
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
			'render_callback' => 'bodhi_svgs_render_inline_svg_block',
		)
	);
}
add_action( 'init', 'bodhi_svgs_register_blocks' );
