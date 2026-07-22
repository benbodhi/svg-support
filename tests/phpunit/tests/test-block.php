<?php
/**
 * The native SVG block (svg-support/svg).
 *
 * The block is dynamic and rendered server-side. By default it outputs a plain
 * <img> (like any image); the opt-in "inline" attribute renders sanitized
 * inline SVG through the engine, which also enables the single-colour option.
 * These tests lock registration, both render modes, block supports (align,
 * className), width/customId/alt, the colour option, standalone operation
 * (no Advanced Mode), sanitization and graceful fallback.
 *
 * @group block
 */

class Test_Svg_Block extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function fixture( $rel ) {
		return dirname( __DIR__, 2 ) . '/fixtures/' . $rel;
	}

	private function make_svg_attachment( $bytes, $filename ) {
		$upload = wp_upload_bits( $filename, null, $bytes );
		$this->assertEmpty( $upload['error'] );

		$rel = str_replace( trailingslashit( wp_get_upload_dir()['basedir'] ), '', $upload['file'] );

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => $rel,
				'post_mime_type' => 'image/svg+xml',
				'post_title'     => $filename,
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', $rel );

		return array( $attachment_id, $upload['file'], $upload['url'] );
	}

	public function test_block_is_registered() {
		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'svg-support/svg' ),
			'the SVG block must be registered on init'
		);
	}

	public function test_default_renders_as_plain_image() {
		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'block-img.svg' );

		$markup = sprintf(
			'<!-- wp:svg-support/svg {"id":%d,"width":"120px","customId":"hero","alt":"Hero graphic","className":"extra-class","align":"wide"} /-->',
			$id
		);
		$html = do_blocks( $markup );

		// Default = normal image, NOT inline SVG.
		$this->assertStringContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertStringContainsString( 'wp-block-svg-support-svg', $html );
		$this->assertStringContainsString( 'alignwide', $html );
		$this->assertStringContainsString( 'extra-class', $html );
		$this->assertStringContainsString( 'style="width:120px;"', $html );
		$this->assertStringContainsString( 'id="hero"', $html );
		$this->assertStringContainsString( 'alt="Hero graphic"', $html );
	}

	public function test_inline_renders_svg_with_supports_and_attributes() {
		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'block-inline.svg' );

		$markup = sprintf(
			'<!-- wp:svg-support/svg {"id":%d,"inline":true,"width":"120px","customId":"hero-icon","alt":"Hero graphic","className":"extra-class","align":"wide"} /-->',
			$id
		);
		$html = do_blocks( $markup );

		$this->assertStringContainsString( '<svg', $html );
		$this->assertStringContainsString( 'wp-block-svg-support-svg', $html );
		$this->assertStringContainsString( 'alignwide', $html );
		$this->assertStringContainsString( 'extra-class', $html );
		$this->assertStringContainsString( 'width="120px"', $html );
		$this->assertStringContainsString( 'id="hero-icon"', $html );
		$this->assertStringContainsString( 'aria-label="Hero graphic"', $html );
		$this->assertStringContainsString( 'role="img"', $html );
	}

	public function test_inline_single_colour_follows_text_colour() {
		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'block-cc.svg' );

		$html = do_blocks( sprintf( '<!-- wp:svg-support/svg {"id":%d,"inline":true,"color":"currentColor"} /-->', $id ) );

		$this->assertStringContainsString( 'fill="currentColor"', $html );
		// No fixed container colour when following the theme text colour.
		$this->assertStringNotContainsString( 'style="color:', $html );
	}

	public function test_inline_single_colour_custom_hex() {
		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'block-hex.svg' );

		$html = do_blocks( sprintf( '<!-- wp:svg-support/svg {"id":%d,"inline":true,"color":"#ff0000"} /-->', $id ) );

		// Fills flatten to currentColor and the container carries the picked colour.
		$this->assertStringContainsString( 'fill="currentColor"', $html );
		$this->assertStringContainsString( 'color:#ff0000', $html );
	}

	public function test_inline_rejects_unsafe_colour_value() {
		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'block-badcolor.svg' );

		// Only pure hex reaches the style attribute; a CSS-injection attempt is dropped.
		$html = do_blocks( sprintf( '<!-- wp:svg-support/svg {"id":%d,"inline":true,"color":"#ff0000;background:red"} /-->', $id ) );

		$this->assertStringNotContainsString( 'background:red', $html, 'a non-hex colour value never reaches the style attribute' );
	}

	public function test_block_works_without_advanced_mode_or_server_render_mode() {
		global $bodhi_svgs_options;
		$backup = $bodhi_svgs_options;
		unset( $bodhi_svgs_options['advanced_mode'], $bodhi_svgs_options['render_mode'] );

		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/viewbox-no-dimensions.svg' ) ), 'standalone.svg' );

		$html = do_blocks( sprintf( '<!-- wp:svg-support/svg {"id":%d,"inline":true} /-->', $id ) );

		$this->assertStringContainsString( '<svg', $html, 'the block is a standalone feature, independent of Advanced Mode' );

		$bodhi_svgs_options = $backup;
	}

	public function test_inline_output_is_sanitized() {
		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'malicious/script-tag.svg' ) ), 'block-dirty.svg' );

		$html = do_blocks( sprintf( '<!-- wp:svg-support/svg {"id":%d,"inline":true} /-->', $id ) );

		$this->assertStringContainsString( '<svg', $html );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $html );
	}

	public function test_missing_id_renders_nothing() {
		$this->assertSame( '', trim( do_blocks( '<!-- wp:svg-support/svg /-->' ) ) );
	}

	public function test_inline_missing_file_falls_back_to_plain_img() {
		list( $id, $path ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'vanishing.svg' );
		$url = wp_get_attachment_url( $id );
		unlink( $path ); // simulate a lost file

		$html = do_blocks( sprintf( '<!-- wp:svg-support/svg {"id":%d,"inline":true,"url":"%s","alt":"gone"} /-->', $id, esc_url( $url ) ) );

		$this->assertStringContainsString( '<img', $html, 'a missing file must degrade to an img, never break the page' );
		$this->assertStringContainsString( 'wp-block-svg-support-svg', $html );
	}
}
