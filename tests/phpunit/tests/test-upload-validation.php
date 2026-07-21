<?php
/**
 * Covers the upload prefilter bodhi_svgs_sanitize_svg() — the gatekeeper for
 * media-library and sideload uploads. Exercised through the SIDELOAD filter
 * (wp_handle_sideload_prefilter), which the function treats as always-sanitize
 * and nonce-exempt, so these tests hit the validation + sanitization branch
 * without needing a media-form nonce.
 *
 * The headline case is the .svgz XSS regression (2.5.17 / CVE fixes): a
 * plaintext file with a .svgz extension carrying a <script> must NOT bypass
 * sanitization.
 *
 * @group upload
 * @group covenant
 * @group security
 */
class Test_Upload_Validation extends WP_UnitTestCase {

	private $tmp = array();

	public function set_up() {
		parent::set_up();
		// Default sanitize behavior: nobody bypasses.
		$opts = get_option( 'bodhi_svgs_settings', array() );
		$opts['sanitize_on_upload_roles'] = array( 'none' );
		update_option( 'bodhi_svgs_settings', $opts );
		$GLOBALS['bodhi_svgs_options'] = $opts;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		foreach ( $this->tmp as $f ) {
			if ( file_exists( $f ) ) {
				@unlink( $f );
			}
		}
		$this->tmp = array();
		parent::tear_down();
	}

	private function make_upload( $basename, $bytes ) {
		$path = wp_tempnam( $basename );
		file_put_contents( $path, $bytes );
		$this->tmp[] = $path;
		return array(
			'name'     => $basename,
			'type'     => 'image/svg+xml',
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => strlen( $bytes ),
		);
	}

	/** Route a fake upload through the sideload prefilter (nonce-exempt path). */
	private function run_prefilter( $file ) {
		return apply_filters( 'wp_handle_sideload_prefilter', $file );
	}

	public function test_valid_svg_passes_and_is_sanitized() {
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert(1)</script><rect width="24" height="24"/></svg>';
		$file = $this->make_upload( 'good.svg', $svg );

		$res = $this->run_prefilter( $file );

		$this->assertEmpty( ( $res['error'] ?? '' ), 'valid SVG should not set an error' );
		$cleaned = file_get_contents( $res['tmp_name'] );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $cleaned, 'script must be stripped on upload' );
		$this->assertStringContainsString( '<rect', $cleaned, 'safe content should survive' );
	}

	/**
	 * The .svgz XSS regression: a plaintext (NOT gzipped) file named .svgz that
	 * contains a script must be sanitized, not passed through untouched.
	 */
	public function test_plaintext_svgz_is_sanitized_not_bypassed() {
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert("svgz-poc")</script><rect width="24" height="24"/></svg>';
		$file = $this->make_upload( 'sneaky.svgz', $svg ); // plaintext, .svgz extension

		$res = $this->run_prefilter( $file );

		$this->assertEmpty( ( $res['error'] ?? '' ), 'plaintext .svgz that is valid SVG should be accepted (then sanitized)' );
		$cleaned = file_get_contents( $res['tmp_name'] );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $cleaned, '.svgz must not bypass sanitization' );
	}

	/** A genuinely gzipped .svgz with a script must also be sanitized. */
	public function test_gzipped_svgz_is_sanitized() {
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert(1)</script><rect width="24" height="24"/></svg>';
		$file = $this->make_upload( 'real.svgz', gzencode( $svg ) );

		$res = $this->run_prefilter( $file );

		$this->assertEmpty( ( $res['error'] ?? '' ) );
		$out = file_get_contents( $res['tmp_name'] );
		$decoded = ( substr( $out, 0, 3 ) === "\x1f\x8b\x08" ) ? gzdecode( $out ) : $out;
		$this->assertStringNotContainsStringIgnoringCase( '<script', $decoded );
	}

	/** A non-SVG payload with an .svg extension must be rejected. */
	public function test_non_svg_content_is_rejected() {
		$file = $this->make_upload( 'fake.svg', 'this is definitely not an svg file at all' );

		$res = $this->run_prefilter( $file );

		$this->assertNotEmpty( $res['error'] ?? '', 'non-SVG content in a .svg must set an error' );
	}

	/** A non-SVG extension is ignored entirely (returned untouched, no error). */
	public function test_non_svg_extension_passes_through_untouched() {
		$file = $this->make_upload( 'photo.png', 'PNGDATA' );

		$res = $this->run_prefilter( $file );

		$this->assertEmpty( ( $res['error'] ?? '' ) );
	}
}
