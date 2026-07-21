<?php
/**
 * The native Inline SVG block (svg-support/inline-svg).
 *
 * Dynamic block rendered through the server-side engine — these tests lock
 * that it registers, renders sanitized inline SVG with block supports applied
 * (align, className), honors width/currentColor/customId/alt, works without
 * Advanced Mode (standalone free feature), and degrades gracefully.
 *
 * @group block
 */

class Test_Inline_Svg_Block extends WP_UnitTestCase {

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
			WP_Block_Type_Registry::get_instance()->is_registered( 'svg-support/inline-svg' ),
			'the Inline SVG block must be registered on init'
		);
	}

	public function test_renders_inline_svg_with_supports_and_attributes() {
		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'block-icon.svg' );

		$markup = sprintf(
			'<!-- wp:svg-support/inline-svg {"id":%d,"width":"120px","useCurrentColor":true,"customId":"hero-icon","alt":"Hero graphic","className":"extra-class","align":"wide"} /-->',
			$id
		);

		$html = do_blocks( $markup );

		$this->assertStringContainsString( '<svg', $html );
		$this->assertStringContainsString( 'wp-block-svg-support-inline-svg', $html );
		$this->assertStringContainsString( 'alignwide', $html );
		$this->assertStringContainsString( 'extra-class', $html );
		$this->assertStringContainsString( 'width="120px"', $html );
		$this->assertStringContainsString( 'fill="currentColor"', $html );
		$this->assertStringContainsString( 'id="hero-icon"', $html );
		$this->assertStringContainsString( 'aria-label="Hero graphic"', $html );
		$this->assertStringContainsString( 'role="img"', $html );
	}

	public function test_block_works_without_advanced_mode_or_server_render_mode() {
		global $bodhi_svgs_options;
		$backup = $bodhi_svgs_options;
		unset( $bodhi_svgs_options['advanced_mode'], $bodhi_svgs_options['render_mode'] );

		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/viewbox-no-dimensions.svg' ) ), 'standalone.svg' );

		$html = do_blocks( sprintf( '<!-- wp:svg-support/inline-svg {"id":%d} /-->', $id ) );

		$this->assertStringContainsString( '<svg', $html, 'the block is a standalone feature, independent of Advanced Mode' );

		$bodhi_svgs_options = $backup;
	}

	public function test_block_output_is_sanitized() {
		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'malicious/script-tag.svg' ) ), 'block-dirty.svg' );

		$html = do_blocks( sprintf( '<!-- wp:svg-support/inline-svg {"id":%d} /-->', $id ) );

		$this->assertStringContainsString( '<svg', $html );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $html );
	}

	public function test_missing_id_renders_nothing() {
		$this->assertSame( '', trim( do_blocks( '<!-- wp:svg-support/inline-svg /-->' ) ) );
	}

	public function test_missing_file_falls_back_to_plain_img() {
		list( $id, $path ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'vanishing.svg' );
		$url = wp_get_attachment_url( $id );
		unlink( $path ); // simulate a lost file

		$html = do_blocks( sprintf( '<!-- wp:svg-support/inline-svg {"id":%d,"url":"%s","alt":"gone"} /-->', $id, esc_url( $url ) ) );

		$this->assertStringContainsString( '<img', $html, 'missing file must degrade to an img, never break the page' );
		$this->assertStringContainsString( 'wp-block-svg-support-inline-svg', $html );
	}
}
