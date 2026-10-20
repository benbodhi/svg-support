<?php
/**
 * #49438 (WPScan): SVGs uploaded over XML-RPC (wp.uploadFile and
 * metaWeblog.newMediaObject) are stored with wp_upload_bits(), which fires
 * neither upload prefilter. They must be sanitized all the same, and so must
 * any other SVG stored through wp_upload_bits().
 *
 * @group upload
 * @group security
 * @group xmlrpc
 */
class Test_XMLRPC_Upload extends WP_UnitTestCase {

	/** The report's payload. */
	const PAYLOAD = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.domain)"><script>alert(1)</script><rect width="10" height="10"/></svg>';

	/** @var wp_xmlrpc_server */
	private $server;

	/** @var array */
	private $options;

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . WPINC . '/class-IXR.php';
		require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';

		// The report's setup: authors may upload SVGs, nobody bypasses sanitization.
		$this->options = $GLOBALS['bodhi_svgs_options'];
		$GLOBALS['bodhi_svgs_options']['restrict']                 = array( 'administrator', 'author' );
		$GLOBALS['bodhi_svgs_options']['sanitize_on_upload_roles'] = array( 'none' );
		$GLOBALS['bodhi_svgs_upload_bits_files']                   = array();

		self::factory()->user->create(
			array(
				'user_login' => 'svgauthor',
				'user_pass'  => 'svgauthor',
				'role'       => 'author',
			)
		);

		// What xmlrpc.php does: build the server, register its callbacks, expose it.
		$this->server            = new wp_xmlrpc_server();
		$this->server->callbacks = $this->server->methods;
		$GLOBALS['wp_xmlrpc_server'] = $this->server;
	}

	public function tear_down() {
		$GLOBALS['bodhi_svgs_options'] = $this->options;
		unset( $GLOBALS['wp_xmlrpc_server'] );
		$this->remove_added_uploads();
		parent::tear_down();
	}

	private function upload( $method, $name, $bits, $type = 'image/svg+xml' ) {
		return $this->server->call( $method, $this->args( $name, $bits, $type ) );
	}

	private function args( $name, $bits, $type = 'image/svg+xml' ) {
		return array(
			1,
			'svgauthor',
			'svgauthor',
			array(
				'name' => $name,
				'type' => $type,
				'bits' => $bits,
			),
		);
	}

	/** The stored file of a successful upload, decompressed if it is gzipped. */
	private function stored( $result ) {
		$this->assertIsArray( $result, 'upload should succeed' . ( $result instanceof IXR_Error ? ': ' . $result->message : '' ) );
		$bytes = file_get_contents( get_attached_file( $result['id'] ) );
		return bodhi_svgs_is_gzipped( $bytes ) ? gzdecode( $bytes ) : $bytes;
	}

	private function assert_clean( $svg ) {
		$this->assertStringNotContainsStringIgnoringCase( '<script', $svg, 'script must be stripped' );
		$this->assertStringNotContainsStringIgnoringCase( 'onload', $svg, 'event handler must be stripped' );
		$this->assertStringNotContainsStringIgnoringCase( 'alert(', $svg, 'no payload may remain' );
		$this->assertStringContainsString( '<rect', $svg, 'safe content should survive' );
	}

	public function test_media_upload_methods_are_wrapped() {
		$this->assertSame( 'bodhi_svgs_xmlrpc_new_media_object', $this->server->methods['wp.uploadFile'] );
		$this->assertSame( 'bodhi_svgs_xmlrpc_new_media_object', $this->server->methods['metaWeblog.newMediaObject'] );
	}

	/** The report's reproduction. */
	public function test_wp_upload_file_is_sanitized() {
		$this->assert_clean( $this->stored( $this->upload( 'wp.uploadFile', 'xss.svg', self::PAYLOAD ) ) );
	}

	public function test_metaweblog_new_media_object_is_sanitized() {
		$this->assert_clean( $this->stored( $this->upload( 'metaWeblog.newMediaObject', 'xss.svg', self::PAYLOAD ) ) );
	}

	public function test_plaintext_svgz_is_sanitized() {
		$this->assert_clean( $this->stored( $this->upload( 'wp.uploadFile', 'xss.svgz', self::PAYLOAD ) ) );
	}

	public function test_gzipped_svgz_is_sanitized_and_stays_gzipped() {
		$result = $this->upload( 'wp.uploadFile', 'xss.svgz', gzencode( self::PAYLOAD ) );

		$this->assertTrue( bodhi_svgs_is_gzipped( file_get_contents( get_attached_file( $result['id'] ) ) ), '.svgz should stay gzipped' );
		$this->assert_clean( $this->stored( $result ) );
	}

	public function test_uppercase_extension_is_sanitized() {
		$this->assert_clean( $this->stored( $this->upload( 'wp.uploadFile', 'XSS.SVG', self::PAYLOAD ) ) );
	}

	public function test_smil_link_payload_is_sanitized() {
		$svg = $this->stored( $this->upload( 'wp.uploadFile', 'evil.svg', file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/malicious/animatetransform-href-safari.svg' ) ) );

		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $svg );
		$this->assertDoesNotMatchRegularExpression( '/attributeName\s*=\s*"[^"]*href/i', $svg );
	}

	public function test_unsanitizable_svg_is_rejected() {
		$result = $this->upload( 'wp.uploadFile', 'broken.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script>' );

		$this->assertInstanceOf( IXR_Error::class, $result );
		$this->assertSame( 500, $result->code );
	}

	public function test_other_file_types_are_untouched() {
		$text   = 'notes <script>not markup here</script>';
		$result = $this->upload( 'wp.uploadFile', 'notes.txt', $text, 'text/plain' );

		$this->assertIsArray( $result );
		$this->assertSame( $text, file_get_contents( get_attached_file( $result['id'] ) ) );
	}

	/**
	 * Core's method called directly (another plugin's mapping, a custom
	 * server) skips the wrapper; the wp_upload_bits() watcher still sanitizes
	 * the file once it becomes an attachment.
	 */
	public function test_core_method_without_the_wrapper_is_still_sanitized() {
		$this->assert_clean( $this->stored( $this->server->mw_newMediaObject( $this->args( 'xss.svg', self::PAYLOAD ) ) ) );
	}

	public function test_wp_upload_bits_without_an_attachment_is_sanitized_at_end_of_request() {
		wp_set_current_user( get_user_by( 'login', 'svgauthor' )->ID );

		$upload = wp_upload_bits( 'direct.svg', null, self::PAYLOAD );
		$this->assertFalse( $upload['error'] );

		bodhi_svgs_sanitize_remaining_upload_bits();

		$this->assert_clean( file_get_contents( $upload['file'] ) );
	}

	public function test_unsanitizable_wp_upload_bits_file_is_deleted() {
		wp_set_current_user( get_user_by( 'login', 'svgauthor' )->ID );

		$upload = wp_upload_bits( 'broken.svg', null, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script>' );
		bodhi_svgs_sanitize_remaining_upload_bits();

		$this->assertFileDoesNotExist( $upload['file'] );
	}

	public function test_bypass_role_keeps_wp_upload_bits_file_as_written() {
		$GLOBALS['bodhi_svgs_options']['sanitize_on_upload_roles'] = array( 'administrator' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$upload = wp_upload_bits( 'trusted.svg', null, self::PAYLOAD );
		bodhi_svgs_sanitize_remaining_upload_bits();

		$this->assertStringContainsString( '<script', file_get_contents( $upload['file'] ), 'a bypass role stores the file as written' );
	}

	/** A vetoed SVG write must never mark the next, unrelated file for sanitizing. */
	public function test_vetoed_write_does_not_touch_other_files() {
		wp_set_current_user( get_user_by( 'login', 'svgauthor' )->ID );

		$veto = function () {
			return 'blocked';
		};
		add_filter( 'wp_upload_bits', $veto, PHP_INT_MAX );
		$vetoed = wp_upload_bits( 'vetoed.svg', null, self::PAYLOAD );
		remove_filter( 'wp_upload_bits', $veto, PHP_INT_MAX );
		$this->assertSame( 'blocked', $vetoed['error'] );

		$dir  = wp_upload_dir()['path'];
		$name = wp_unique_filename( $dir, 'photo.jpg' );
		file_put_contents( "$dir/$name", 'not an svg' );

		bodhi_svgs_sanitize_remaining_upload_bits();

		$this->assertFileExists( "$dir/$name" );
		$this->assertSame( 'not an svg', file_get_contents( "$dir/$name" ) );
		unlink( "$dir/$name" );
	}
}
