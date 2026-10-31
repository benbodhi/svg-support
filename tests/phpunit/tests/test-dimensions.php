<?php
/**
 * SVG dimensions are read from wherever the file lives. Offloaded media
 * plugins such as S3 Uploads keep uploads behind a stream wrapper (s3://...),
 * which used to be mistaken for a remote URL and fetched over HTTP, failing
 * with 0×0. Only http(s) URLs are downloaded, through the
 * bodhi_svgs_remote_svg_url filter.
 *
 * @group dimensions
 */
class Test_Dimensions extends WP_UnitTestCase {

	const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="80" viewBox="0 0 120 80"><rect width="120" height="80"/></svg>';

	/** @var string Folder the test stream wrapper serves. */
	private $root;

	/** @var string[] URLs requested over HTTP during a test. */
	private $requests = array();

	public function set_up() {
		parent::set_up();

		$this->root = get_temp_dir() . 'svgs-dimensions-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->root . '/bucket/uploads/2026/10' );
		file_put_contents( $this->root . '/bucket/uploads/2026/10/logo.svg', self::SVG );

		Bodhi_Test_Bucket_Wrapper::$root  = $this->root;
		Bodhi_Test_Bucket_Wrapper::$opens = array();
		stream_wrapper_register( 'svgsbucket', 'Bodhi_Test_Bucket_Wrapper' );

		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		stream_wrapper_unregister( 'svgsbucket' );
		if ( ! in_array( 'phar', stream_get_wrappers(), true ) && extension_loaded( 'phar' ) ) {
			stream_wrapper_restore( 'phar' );
		}
		$this->delete_folder( $this->root );
		parent::tear_down();
	}

	public function fake_http( $preempt, $args, $url ) {
		$this->requests[] = $url;
		// As WP_Http does, once past this filter
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'http_request_failed', 'A valid URL was not provided.' );
		}
		return array(
			'headers'  => array(),
			'body'     => self::SVG,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function delete_folder( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/** Point the uploads folder at the bucket, as S3 Uploads does. */
	public function bucket_upload_dir( $uploads ) {
		$uploads['basedir'] = 'svgsbucket://bucket/uploads';
		$uploads['path']    = $uploads['basedir'] . $uploads['subdir'];
		$uploads['baseurl'] = 'https://bucket.example.com/uploads';
		$uploads['url']     = $uploads['baseurl'] . $uploads['subdir'];
		return $uploads;
	}

	private function create_bucket_attachment() {
		add_filter( 'upload_dir', array( $this, 'bucket_upload_dir' ) );
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/logo.svg',
				'post_mime_type' => 'image/svg+xml',
			)
		);
		update_post_meta( $id, '_wp_attached_file', '2026/10/logo.svg' );
		return $id;
	}

	public function test_local_file() {
		$dimensions = bodhi_svgs_get_dimensions( $this->root . '/bucket/uploads/2026/10/logo.svg' );

		$this->assertSame( '120', $dimensions->width );
		$this->assertSame( '80', $dimensions->height );
		$this->assertSame( array(), $this->requests, 'a local file must not be fetched over HTTP' );
	}

	public function test_stream_wrapper_path_is_read_as_a_file() {
		$dimensions = bodhi_svgs_get_dimensions( 'svgsbucket://bucket/uploads/2026/10/logo.svg' );

		$this->assertSame( '120', $dimensions->width );
		$this->assertSame( '80', $dimensions->height );
		$this->assertSame( array(), $this->requests, 'a stream wrapper path must not be fetched over HTTP' );
	}

	public function test_offloaded_attachment_metadata() {
		$id = $this->create_bucket_attachment();

		$this->assertSame( 'svgsbucket://bucket/uploads/2026/10/logo.svg', get_attached_file( $id ) );

		$metadata = bodhi_svgs_generate_svg_attachment_metadata( array(), $id );

		$this->assertSame( 120, $metadata['width'] );
		$this->assertSame( 80, $metadata['height'] );
		$this->assertSame( '/2026/10/logo.svg', $metadata['file'] );
		$this->assertSame( array(), $this->requests, 'an offloaded file must be read, not fetched over HTTP' );
	}

	public function test_offloaded_attachment_in_the_media_modal() {
		$id = $this->create_bucket_attachment();

		$response = wp_prepare_attachment_for_js( $id );

		$this->assertSame( '120', $response['sizes']['full']['width'] );
		$this->assertSame( '80', $response['sizes']['full']['height'] );
		$this->assertSame( 'landscape', $response['sizes']['full']['orientation'] );
		$this->assertSame( array(), $this->requests, 'an offloaded file must be read, not fetched over HTTP' );
	}

	public function test_remote_url_is_downloaded() {
		$dimensions = bodhi_svgs_get_dimensions( 'https://cdn.example.com/logo.svg' );

		$this->assertSame( array( 'https://cdn.example.com/logo.svg' ), $this->requests );
		$this->assertSame( '120', $dimensions->width );
		$this->assertSame( '80', $dimensions->height );
	}

	public function test_remote_url_filter() {
		$seen = null;
		add_filter(
			'bodhi_svgs_remote_svg_url',
			function ( $url ) use ( &$seen ) {
				$seen = $url;
				return $url . '?signature=abc';
			}
		);

		$dimensions = bodhi_svgs_get_dimensions( 'https://cdn.example.com/logo.svg' );

		$this->assertSame( 'https://cdn.example.com/logo.svg', $seen );
		$this->assertSame( array( 'https://cdn.example.com/logo.svg?signature=abc' ), $this->requests );
		$this->assertSame( '120', $dimensions->width );
	}

	public function test_remote_url_filter_is_not_applied_to_files() {
		$called = false;
		add_filter(
			'bodhi_svgs_remote_svg_url',
			function ( $url ) use ( &$called ) {
				$called = true;
				return $url;
			}
		);

		bodhi_svgs_get_dimensions( 'svgsbucket://bucket/uploads/2026/10/logo.svg' );

		$this->assertFalse( $called );
	}

	public function test_phar_paths_are_never_opened() {
		// Stand in for the phar wrapper to see whether anything touches it
		if ( in_array( 'phar', stream_get_wrappers(), true ) ) {
			stream_wrapper_unregister( 'phar' );
		}
		stream_wrapper_register( 'phar', 'Bodhi_Test_Bucket_Wrapper' );

		$dimensions = bodhi_svgs_get_dimensions( 'phar://bucket/uploads/2026/10/logo.svg' );
		$nested     = bodhi_svgs_get_dimensions( 'compress.zlib://phar://bucket/uploads/2026/10/logo.svg' );

		stream_wrapper_unregister( 'phar' );

		$this->assertSame( 0, $dimensions->width );
		$this->assertSame( 0, $dimensions->height );
		$this->assertSame( 0, $nested->width );
		$this->assertSame( 0, $nested->height );
		$this->assertSame( array(), Bodhi_Test_Bucket_Wrapper::$opens, 'a phar path must never be opened' );
		$this->assertSame( array(), $this->requests );
	}
}

/**
 * Read-only stream wrapper serving a local folder, standing in for an
 * offloaded media bucket.
 */
class Bodhi_Test_Bucket_Wrapper {

	/** @var string */
	public static $root;

	/** @var string[] Every path opened or checked. */
	public static $opens = array();

	/** @var resource */
	public $context;

	/** @var resource */
	private $handle;

	private static function path( $url ) {
		self::$opens[] = $url;
		return self::$root . '/' . substr( $url, strpos( $url, '://' ) + 3 );
	}

	public function stream_open( $url, $mode ) {
		$this->handle = fopen( self::path( $url ), $mode );
		return (bool) $this->handle;
	}

	public function stream_read( $count ) {
		return fread( $this->handle, $count );
	}

	public function stream_eof() {
		return feof( $this->handle );
	}

	public function stream_stat() {
		return fstat( $this->handle );
	}

	public function stream_close() {
		fclose( $this->handle );
	}

	public function url_stat( $url, $flags ) {
		$path = self::path( $url );
		return file_exists( $path ) ? stat( $path ) : false;
	}
}
