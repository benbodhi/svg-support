<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite provided by wp-env
 * (WP_TESTS_DIR is set inside the wp-env containers) and the plugin.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

require_once __DIR__ . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', __DIR__ . '/vendor/yoast/phpunit-polyfills' );
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$_tests_dir}. Run inside wp-env (npm run test:php) or set WP_TESTS_DIR." . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		// svg-support.php assigns $sanitizer at file top level. Loaded normally
		// by WordPress that is global scope; required inside this closure it
		// would be closure-local, so declare the globals first to bind the
		// assignments to the true globals the plugin's functions read.
		global $sanitizer, $bodhi_svgs_options;
		require dirname( __DIR__, 2 ) . '/svg-support.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
