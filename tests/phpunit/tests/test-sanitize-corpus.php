<?php
/**
 * Exercises the real file-based sanitizer bodhi_svgs_sanitize() (WP_Filesystem
 * read/write, global $sanitizer, gzip round-trip) against the fixture corpus.
 * Complements the standalone runner in tests/sanitizer/run.php, which tests the
 * same engine without WordPress — this one proves the WP integration path.
 *
 * @group sanitize
 * @group covenant
 */
class Test_Sanitize_Corpus extends WP_UnitTestCase {

	/** @var string */
	private $work;

	public function set_up() {
		parent::set_up();
		$this->work = wp_tempnam( 'svgs-corpus' );
	}

	public function tear_down() {
		if ( $this->work && file_exists( $this->work ) ) {
			@unlink( $this->work );
		}
		parent::tear_down();
	}

	private function fixtures( $dir ) {
		return glob( dirname( __DIR__, 2 ) . "/fixtures/$dir/*.svg" );
	}

	/** Copy a fixture to a writable temp file and sanitize it in place. */
	private function sanitize_fixture( $path ) {
		copy( $path, $this->work );
		$ok = bodhi_svgs_sanitize( $this->work );
		return array( $ok, file_get_contents( $this->work ) );
	}

	public function test_function_exists() {
		$this->assertTrue( function_exists( 'bodhi_svgs_sanitize' ) );
	}

	public function test_benign_fixtures_survive() {
		foreach ( $this->fixtures( 'benign' ) as $path ) {
			$name = basename( $path );
			list( $ok, $clean ) = $this->sanitize_fixture( $path );
			$this->assertTrue( $ok, "$name should sanitize without error" );
			$this->assertStringContainsStringIgnoringCase( '<svg', $clean, "$name should remain an SVG" );
		}
	}

	public function test_malicious_fixtures_are_neutralized() {
		$forbidden_global = array( '<script', 'javascript:', 'onload=', 'onclick=', 'onbegin=', 'onmouseover=' );
		foreach ( $this->fixtures( 'malicious' ) as $path ) {
			$name = basename( $path );
			list( $ok, $clean ) = $this->sanitize_fixture( $path );
			// Sanitizer may neutralize (return true + cleaned) or reject (false).
			if ( $ok === false ) {
				continue;
			}
			$hay = strtolower( $clean );
			foreach ( $forbidden_global as $bad ) {
				$this->assertStringNotContainsString( strtolower( $bad ), $hay, "$name must not retain $bad" );
			}
			// evil.example.com must be gone for the remote <use> case; the remote
			// <image> case is a documented current limitation covered explicitly
			// (and separately) so we don't assert its absence here.
			$this->assertLessThan( 65536, strlen( $clean ), "$name output should stay bounded" );
		}
	}

	/**
	 * Gzipped .svgz round-trip: a gzip-compressed SVG carrying a <script> must
	 * come back sanitized AND still gzip-framed (so it round-trips on disk).
	 */
	public function test_svgz_gzip_roundtrip() {
		$dirty = file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/malicious/script-tag.svg' );
		file_put_contents( $this->work, gzencode( $dirty ) );

		$ok = bodhi_svgs_sanitize( $this->work );
		$this->assertTrue( $ok, 'gzipped .svgz should sanitize without error' );

		$out = file_get_contents( $this->work );
		$this->assertSame( "\x1f\x8b\x08", substr( $out, 0, 3 ), 'output should remain gzip-framed' );

		$decoded = gzdecode( $out );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $decoded, 'decoded payload must be clean' );
		$this->assertStringContainsString( '<rect', $decoded, 'decoded payload should keep the safe shape' );
	}

	/**
	 * Documented current behavior — remote <image href> passes through. Locked
	 * so a dependency bump that changes it is caught here.
	 */
	public function test_remote_image_href_current_behavior() {
		list( $ok, $clean ) = $this->sanitize_fixture( dirname( __DIR__, 2 ) . '/fixtures/malicious/external-image-href.svg' );
		$this->assertTrue( $ok );
		$this->assertStringContainsString( 'evil.example.com', $clean, 'remote <image href> currently survives (known limitation)' );
	}

	public function test_remote_use_href_is_stripped() {
		list( $ok, $clean ) = $this->sanitize_fixture( dirname( __DIR__, 2 ) . '/fixtures/malicious/external-use-href.svg' );
		$this->assertTrue( $ok );
		$this->assertStringNotContainsString( 'evil.example.com', $clean, 'remote <use href> should be stripped' );
	}
}
