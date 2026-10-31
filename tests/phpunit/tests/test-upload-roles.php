<?php
/**
 * Who bypasses sanitization in the upload prefilter. Only a user who can
 * upload files AND holds a role chosen under "Do not sanitize for these roles"
 * skips it. Anyone without upload_files (a front-end form that accepts SVGs)
 * is always sanitized — before 2.6.2 that branch skipped the sanitizer.
 *
 * Runs through wp_handle_upload_prefilter (a browser upload), so each upload
 * carries a media-form nonce for the current user.
 *
 * @group upload
 * @group security
 */
class Test_Upload_Roles extends WP_UnitTestCase {

	/** The hardening report's payload. */
	const PAYLOAD = '<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" onload="alert(1)">
  <script>alert(document.domain)</script>
  <text x="10" y="20">POC</text>
</svg>';

	/** @var array */
	private $options;

	/** @var array */
	private $tmp = array();

	public function set_up() {
		parent::set_up();
		$this->options = $GLOBALS['bodhi_svgs_options'];
	}

	public function tear_down() {
		foreach ( $this->tmp as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		unset( $_REQUEST['_wpnonce'] );
		$GLOBALS['bodhi_svgs_options'] = $this->options;
		parent::tear_down();
	}

	/**
	 * Upload the payload as a new user with $role, with $bypass_roles set to
	 * skip sanitization. Returns the prefilter result and the file afterwards.
	 */
	private function upload_as( $role, array $bypass_roles ) {
		$GLOBALS['bodhi_svgs_options']['sanitize_on_upload_roles'] = $bypass_roles;
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'media-form' );

		$path = wp_tempnam( 'x.svg' );
		file_put_contents( $path, self::PAYLOAD );
		$this->tmp[] = $path;

		$result = apply_filters(
			'wp_handle_upload_prefilter',
			array(
				'name'     => 'x.svg',
				'type'     => 'image/svg+xml',
				'tmp_name' => $path,
				'error'    => 0,
				'size'     => strlen( self::PAYLOAD ),
			)
		);

		return array( $result, file_get_contents( $path ) );
	}

	private function assert_sanitized( $result, $svg ) {
		$this->assertEmpty( $result['error'], 'a valid SVG should not set an error' );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $svg );
		$this->assertStringNotContainsStringIgnoringCase( 'onload', $svg );
		$this->assertStringContainsString( 'POC', $svg, 'safe content should survive' );
	}

	/** The hardening report's reproduction. */
	public function test_user_without_upload_files_is_sanitized() {
		list( $result, $svg ) = $this->upload_as( 'subscriber', array( 'none' ) );
		$this->assert_sanitized( $result, $svg );
	}

	public function test_bypass_role_without_upload_files_is_still_sanitized() {
		list( $result, $svg ) = $this->upload_as( 'subscriber', array( 'subscriber' ) );
		$this->assert_sanitized( $result, $svg );
	}

	public function test_role_that_does_not_bypass_is_sanitized() {
		list( $result, $svg ) = $this->upload_as( 'author', array( 'administrator' ) );
		$this->assert_sanitized( $result, $svg );
	}

	/** The setting itself keeps working for users who can upload. */
	public function test_bypass_role_with_upload_files_skips_sanitization() {
		list( $result, $svg ) = $this->upload_as( 'administrator', array( 'administrator' ) );

		$this->assertEmpty( $result['error'] );
		$this->assertStringContainsString( '<script', $svg, 'a bypass role stores the file as uploaded' );
	}

	public function test_missing_nonce_is_still_rejected() {
		$GLOBALS['bodhi_svgs_options']['sanitize_on_upload_roles'] = array( 'none' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$path = wp_tempnam( 'x.svg' );
		file_put_contents( $path, self::PAYLOAD );
		$this->tmp[] = $path;

		$result = apply_filters( 'wp_handle_upload_prefilter', array( 'name' => 'x.svg', 'type' => 'image/svg+xml', 'tmp_name' => $path, 'error' => 0, 'size' => 1 ) );

		$this->assertNotEmpty( $result['error'], 'the anti-CSRF nonce check still applies' );
	}
}
