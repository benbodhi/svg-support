<?php
/**
 * Server-side inlining engine (SVGSupport\Rendering\Inliner).
 *
 * Locks the v3 rendering contract: targeted <img> tags become sanitized inline
 * <svg> markup at render time, preserving width, height, alt, style, aria-
 * and data- attributes (which the legacy JS swap drops), hardening remote
 * <image> refs, and never activating outside server render mode.
 *
 * @group engine
 */

use SVGSupport\Rendering\Inliner;
use SVGSupport\Rendering\InlineCache;

class Test_Inliner extends WP_UnitTestCase {

	private $opts_backup;

	public function set_up() {
		parent::set_up();

		global $bodhi_svgs_options;
		$this->opts_backup = $bodhi_svgs_options;

		// Admin so the svg mime is allowed for test uploads.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		global $bodhi_svgs_options;
		$bodhi_svgs_options = $this->opts_backup;
		update_option( 'bodhi_svgs_settings', $this->opts_backup );
		parent::tear_down();
	}

	private function server_mode_on() {
		global $bodhi_svgs_options;
		$bodhi_svgs_options['advanced_mode'] = 'on';
		$bodhi_svgs_options['render_mode']   = 'server';
		update_option( 'bodhi_svgs_settings', $bodhi_svgs_options );
	}

	private function fixture( $rel ) {
		return dirname( __DIR__, 2 ) . '/fixtures/' . $rel;
	}

	/**
	 * Create a real attachment from fixture bytes (raw write — deliberately
	 * bypasses upload sanitization, simulating pre-existing/legacy files).
	 *
	 * @return array [attachment_id, path, url]
	 */
	private function make_svg_attachment( $bytes, $filename ) {
		$upload = wp_upload_bits( $filename, null, $bytes );
		$this->assertEmpty( $upload['error'], 'test upload should succeed: ' . ( $upload['error'] ?? '' ) );

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => str_replace( trailingslashit( wp_get_upload_dir()['basedir'] ), '', $upload['file'] ),
				'post_mime_type' => 'image/svg+xml',
				'post_title'     => $filename,
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', str_replace( trailingslashit( wp_get_upload_dir()['basedir'] ), '', $upload['file'] ) );

		return array( $attachment_id, $upload['file'], $upload['url'] );
	}

	/* ------------------------------------------------------------ gating */

	public function test_inactive_outside_server_mode() {
		global $bodhi_svgs_options;
		$bodhi_svgs_options['advanced_mode'] = 'on';
		unset( $bodhi_svgs_options['render_mode'] ); // upgraded site

		list( , , $url ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'gate.svg' );
		$img = '<img class="style-svg" src="' . esc_url( $url ) . '" />';

		$this->assertSame( $img, Inliner::filter_content_img( $img, 'the_content', 0 ), 'legacy sites must be untouched' );
	}

	public function test_inactive_without_advanced_mode() {
		global $bodhi_svgs_options;
		unset( $bodhi_svgs_options['advanced_mode'] );
		$bodhi_svgs_options['render_mode'] = 'server';

		list( , , $url ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'gate2.svg' );
		$img = '<img class="style-svg" src="' . esc_url( $url ) . '" />';

		$this->assertSame( $img, Inliner::filter_content_img( $img, 'the_content', 0 ) );
	}

	public function test_non_target_img_untouched() {
		$this->server_mode_on();

		list( , , $url ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'plain.svg' );
		$img = '<img src="' . esc_url( $url ) . '" />';

		$this->assertSame( $img, Inliner::filter_content_img( $img, 'the_content', 0 ), 'no target class and no force = untouched' );
	}

	/* --------------------------------------------------------- replacing */

	public function test_targeted_img_becomes_inline_svg_with_attributes_preserved() {
		$this->server_mode_on();

		list( $id, , $url ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'icon.svg' );

		$img = '<img class="style-svg fancy wp-image-' . $id . '" src="' . esc_url( $url ) . '" width="48" height="48" alt="My icon" style="opacity:.5" data-x="1" aria-describedby="cap" />';
		$out = Inliner::filter_content_img( $img, 'the_content', $id );

		$this->assertStringStartsWith( '<svg', $out, 'img should be replaced by inline svg' );
		$this->assertStringNotContainsString( '<img', $out );

		// The attributes the legacy JS swap dropped are preserved here.
		$this->assertStringContainsString( 'width="48"', $out );
		$this->assertStringContainsString( 'height="48"', $out );
		$this->assertStringContainsString( 'aria-label="My icon"', $out );
		$this->assertStringContainsString( 'role="img"', $out );
		$this->assertStringContainsString( 'data-x="1"', $out );
		$this->assertStringContainsString( 'aria-describedby="cap"', $out );
		$this->assertStringContainsString( 'opacity:.5', $out );

		// Class contract: img classes + replaced-svg markers.
		$this->assertStringContainsString( 'style-svg', $out );
		$this->assertStringContainsString( 'fancy', $out );
		$this->assertStringContainsString( 'replaced-svg', $out );
		$this->assertMatchesRegularExpression( '/svg-replaced-\d+/', $out );

		// Original vector content survived.
		$this->assertStringContainsString( '<path', $out );
	}

	public function test_force_inline_replaces_untargeted_svg_imgs() {
		$this->server_mode_on();
		global $bodhi_svgs_options;
		$bodhi_svgs_options['force_inline_svg'] = 'on';

		list( $id, , $url ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/viewbox-no-dimensions.svg' ) ), 'forced.svg' );
		$img = '<img src="' . esc_url( $url ) . '" alt="" />';

		$out = Inliner::filter_content_img( $img, 'the_content', $id );

		$this->assertStringStartsWith( '<svg', $out );
		$this->assertStringContainsString( 'aria-hidden="true"', $out, 'empty alt (decorative) maps to aria-hidden' );

		unset( $bodhi_svgs_options['force_inline_svg'] );
	}

	public function test_non_svg_img_left_alone_even_in_force_mode() {
		$this->server_mode_on();
		global $bodhi_svgs_options;
		$bodhi_svgs_options['force_inline_svg'] = 'on';

		$img = '<img src="' . esc_url( content_url( 'photo.jpg' ) ) . '" />';
		$this->assertSame( $img, Inliner::filter_content_img( $img, 'the_content', 0 ) );

		unset( $bodhi_svgs_options['force_inline_svg'] );
	}

	/* ------------------------------------------------- sanitize + harden */

	public function test_dirty_file_on_disk_is_sanitized_at_render_time() {
		$this->server_mode_on();

		// Raw write bypassed upload sanitization — the engine must still
		// deliver clean markup (sanitize-at-render safety net).
		list( $id, , $url ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'malicious/script-tag.svg' ) ), 'dirty.svg' );
		$img = '<img class="style-svg" src="' . esc_url( $url ) . '" />';

		$out = Inliner::filter_content_img( $img, 'the_content', $id );

		$this->assertStringStartsWith( '<svg', $out );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $out );
		$this->assertStringContainsString( '<path', $out );
	}

	public function test_remote_image_href_is_stripped_by_engine() {
		$this->server_mode_on();

		// The sanitizer lets remote <image href> through (documented limitation)
		// — the engine's hardening pass must remove it.
		list( $id, , $url ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'malicious/external-image-href.svg' ) ), 'remote.svg' );
		$img = '<img class="style-svg" src="' . esc_url( $url ) . '" />';

		$out = Inliner::filter_content_img( $img, 'the_content', $id );

		$this->assertStringStartsWith( '<svg', $out );
		$this->assertStringNotContainsString( 'evil.example.com', $out, 'engine must strip remote <image> references' );
	}

	public function test_svgz_attachment_inlines() {
		$this->server_mode_on();

		$bytes = gzencode( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ) );
		list( $id, , $url ) = $this->make_svg_attachment( $bytes, 'zipped.svgz' );
		$img = '<img class="style-svg" src="' . esc_url( $url ) . '" />';

		$out = Inliner::filter_content_img( $img, 'the_content', $id );

		$this->assertStringStartsWith( '<svg', $out );
		$this->assertStringContainsString( '<path', $out );
	}

	/* ------------------------------------------------------------- cache */

	public function test_inline_markup_is_cached_with_self_invalidating_key() {
		$this->server_mode_on();

		list( , $path, ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/gradient.svg' ) ), 'cached.svg' );

		$first = Inliner::get_inline_svg( $path );
		$this->assertNotSame( '', $first );

		$key = InlineCache::key( $path, (int) filemtime( $path ) );
		$this->assertSame( $first, get_transient( $key ), 'inline markup should be in the transient cache' );

		$second = Inliner::get_inline_svg( $path );
		$this->assertSame( $first, $second );
	}

	/* ------------------------------------------------------- currentColor */

	public function test_currentcolor_flavor_maps_fills() {
		$this->server_mode_on();

		list( $id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'cc.svg' );

		$out = Inliner::render_attachment( $id, array( 'currentcolor' => true, 'class' => 'my-cc' ) );

		$this->assertStringContainsString( 'fill="currentColor"', $out );
		$this->assertStringNotContainsString( '#e11d48', $out );
		$this->assertStringContainsString( 'my-cc', $out );
	}

	/* ---------------------------------------------------- featured image */

	public function test_featured_image_inlines_when_meta_enabled() {
		$this->server_mode_on();

		list( $thumb_id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'featured.svg' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		set_post_thumbnail( $post_id, $thumb_id );
		update_post_meta( $post_id, 'inline_featured_image', 1 );

		$this->go_to( get_permalink( $post_id ) );

		$html = get_the_post_thumbnail( $post_id );

		$this->assertStringContainsString( '<svg', $html, 'featured svg should inline when the per-post meta is on' );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_featured_image_untouched_without_meta() {
		$this->server_mode_on();

		list( $thumb_id ) = $this->make_svg_attachment( file_get_contents( $this->fixture( 'benign/simple-icon.svg' ) ), 'featured2.svg' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		set_post_thumbnail( $post_id, $thumb_id );

		$this->go_to( get_permalink( $post_id ) );

		$html = get_the_post_thumbnail( $post_id );

		$this->assertStringContainsString( '<img', $html, 'without the meta the featured image stays an img' );
	}

	/* -------------------------------------------------------------- utils */

	public function test_path_from_upload_url_refuses_escapes_and_foreign_urls() {
		$this->assertSame( '', Inliner::path_from_upload_url( 'https://elsewhere.example.com/x.svg' ) );

		$uploads = wp_get_upload_dir();
		$this->assertSame( '', Inliner::path_from_upload_url( $uploads['baseurl'] . '/../../wp-config.php' ) );
	}
}
