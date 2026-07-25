<?php
/**
 * Regression: the sanitizer must survive loading contexts where plugin
 * file-scope variables never reach global scope (WP-CLI includes
 * wp-settings.php from inside a method). Reported against 2.6.0 as a fatal
 * on `wp media import` — bodhi_svgs_sanitizer() rebuilds the instance when
 * the global is missing, and consumers go through it.
 *
 * @group sanitize
 * @group cli-scope
 */
class Test_Sanitizer_Scope extends WP_UnitTestCase {

	/** @var string */
	private $work;

	public function set_up() {
		parent::set_up();
		$this->work = wp_tempnam( 'svgs-scope' );
	}

	public function tear_down() {
		if ( $this->work && file_exists( $this->work ) ) {
			unlink( $this->work );
		}
		// Restore the shared instance for other tests.
		$GLOBALS['sanitizer'] = new \enshrined\svgSanitize\Sanitizer();
		parent::tear_down();
	}

	private function fixture( $name ) {
		return dirname( __DIR__, 2 ) . '/fixtures/' . $name;
	}

	public function test_sanitize_works_without_the_global() {
		unset( $GLOBALS['sanitizer'] );

		copy( $this->fixture( 'malicious/onload-attr.svg' ), $this->work );
		$result = bodhi_svgs_sanitize( $this->work );

		$this->assertNotFalse( $result, 'sanitize must not fail when the global sanitizer is missing (CLI scope)' );
		$clean = file_get_contents( $this->work );
		$this->assertStringNotContainsString( 'onload', $clean, 'the rebuilt sanitizer must still strip event handlers' );
	}

	public function test_sanitize_strips_scripts_without_the_global() {
		unset( $GLOBALS['sanitizer'] );

		copy( $this->fixture( 'malicious/foreignobject-script.svg' ), $this->work );
		bodhi_svgs_sanitize( $this->work );

		$this->assertStringNotContainsString( '<script', file_get_contents( $this->work ), 'the rebuilt sanitizer must still strip script elements' );
	}

	public function test_minify_does_not_fatal_without_the_global() {
		global $bodhi_svgs_options;
		unset( $GLOBALS['sanitizer'] );
		$bodhi_svgs_options['minify_svg'] = 'on';

		bodhi_svgs_minify();

		$this->assertInstanceOf( \enshrined\svgSanitize\Sanitizer::class, $GLOBALS['sanitizer'], 'minify must rebuild the shared instance instead of fataling' );

		unset( $bodhi_svgs_options['minify_svg'] );
	}
}
