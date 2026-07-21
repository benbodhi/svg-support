<?php
/*
Plugin Name: 	SVG Support
Plugin URI:		http://wordpress.org/plugins/svg-support/
Description: 	Upload SVG files to the Media Library and render SVG files inline for direct styling/animation of an SVG's internal elements using CSS/JS.
Version: 		2.6.0
Author URI: 	https://benbodhi.com
Text Domain: 	svg-support
Domain Path:	/languages
License: 		GPLv2 or later
License URI:	http://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.8
Requires PHP: 	7.4
Block: 			true

	Copyright 2013 and beyond | Benbodhi (email : wp@benbodhi.com)

*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Global variables and constants
 */
global $bodhi_svgs_options;
$bodhi_svgs_options = array();                                     // Defining global array
define('BODHI_SVGS_VERSION', get_file_data(__FILE__, array('Version' => 'Version'))['Version']);
define('BODHI_SVGS_PLUGIN_FILE', __FILE__);                        // define the absolute plugin file path
define('BODHI_SVGS_PLUGIN_PATH', plugin_dir_path(__FILE__));       // define the absolute plugin path for includes
define('BODHI_SVGS_PLUGIN_URL', plugin_dir_url(__FILE__));         // define the plugin url for use in enqueue
$bodhi_svgs_options = get_option('bodhi_svgs_settings', array());  // Retrieve our plugin settings

// ensure $bodhi_svgs_options is always an array (normalized in memory;
// persisted once on activation/upgrade rather than on every request)
if (!is_array($bodhi_svgs_options)) {
	$bodhi_svgs_options = [];
}

/**
 * SVG Sanitizer class
 */
// init svg sanitizer for usage
use enshrined\svgSanitize\Sanitizer;
// svg sanitizer
include( BODHI_SVGS_PLUGIN_PATH . 'vendor/autoload.php' );
// interfaces to enable custom whitelisting of svg tags and attributes
include( BODHI_SVGS_PLUGIN_PATH . 'includes/svg-tags.php' );
include( BODHI_SVGS_PLUGIN_PATH . 'includes/svg-attributes.php' );
// initialize sanitizer
$sanitizer = new Sanitizer();

/**
 * Includes - keeping it modular
 */
include( BODHI_SVGS_PLUGIN_PATH . 'admin/admin-init.php' );					// initialize admin menu & settings page
include( BODHI_SVGS_PLUGIN_PATH . 'admin/plugin-action-meta-links.php' );	// add links to the plugin on the plugins page
include( BODHI_SVGS_PLUGIN_PATH . 'functions/mime-types.php' );				// setup mime types support for SVG (with fix for WP 4.7.1 - 4.7.2)
include( BODHI_SVGS_PLUGIN_PATH . 'functions/thumbnail-display.php' );		// make SVG thumbnails display correctly in media library
include( BODHI_SVGS_PLUGIN_PATH . 'functions/attachment.php' );				// make SVG thumbnails display correctly in attachment modals and generate attachment sizes
include( BODHI_SVGS_PLUGIN_PATH . 'functions/enqueue.php' );				// enqueue js & css for inline replacement & admin
include( BODHI_SVGS_PLUGIN_PATH . 'functions/attribute-control.php' );		// auto set SVG class & remove dimensions during insertion
include( BODHI_SVGS_PLUGIN_PATH . 'functions/featured-image.php' );			// allow inline SVG for featured images
include( BODHI_SVGS_PLUGIN_PATH . 'functions/meta-cleanup.php' );			// cleanup duplicate meta entries
include( BODHI_SVGS_PLUGIN_PATH . 'src/rendering/bootstrap.php' );			// server-side inline rendering engine (v3)
include( BODHI_SVGS_PLUGIN_PATH . 'src/blocks/bootstrap.php' );				// native Inline SVG block (v3)


/**
 * Handle version updates and migrations
 * 
 * Handles version comparisons for all format types:
 * - Single digit versions (1, 2)
 * - Zero versions (0, 0.1, 0.5.26)
 * - Two-digit versions (1.0, 2.1, 2.5)
 * - Three-digit versions (1.5.17, 2.5.9)
 * - Fresh installs ('0.0.0')
 * - Legacy versions (null, empty, invalid)
 */
function bodhi_svgs_version_updates() {
    $stored_version = get_option('bodhi_svgs_plugin_version', '0.0.0');
    
    if (!is_string($stored_version) || empty($stored_version)) {
        $stored_version = '0.0.0';
    }
    
    // Skip if already at current version
    if ($stored_version === BODHI_SVGS_VERSION) {
        return;
    }
    
    // Store the old version for comparison
    $old_version = $stored_version;

    // Update to current version
    update_option('bodhi_svgs_plugin_version', BODHI_SVGS_VERSION);

    // Persist merged setting defaults once per upgrade (they are applied
    // in memory on every load; see bodhi_svgs_apply_setting_defaults)
    bodhi_svgs_persist_settings();

    // If coming from before 2.5.14, run cleanup
    if (version_compare($old_version, '2.5.14', '<')) {
        require_once BODHI_SVGS_PLUGIN_PATH . 'functions/meta-cleanup.php';
        bodhi_svgs_cleanup_duplicate_meta();
    }
}
add_action('admin_init', 'bodhi_svgs_version_updates');

/**
 * Defaults for better security in versions >= 2.5
 *
 * Historically these defaults were written to the database on every request.
 * They are now applied in memory on load (identical values, identical legacy
 * string normalization) and persisted once — on activation and on version
 * upgrade — via bodhi_svgs_persist_settings().
 */
function bodhi_svgs_apply_setting_defaults( $options ) {

	// Enable 'sanitize_svg_front_end' by default
	if ( ! isset( $options['sanitize_svg_front_end'] ) ) {
		$options['sanitize_svg_front_end'] = 'on';
	}

	// Allow only admins to upload SVGs by default (legacy 'on' string maps to
	// the same); legacy 'none' string maps to the array sentinel
	if ( ! isset( $options['restrict'] ) || $options['restrict'] == 'on' ) {
		$options['restrict'] = array( 'administrator' );
	} elseif ( $options['restrict'] == 'none' ) {
		$options['restrict'] = array( 'none' );
	}

	// By default sanitize on upload for everyone (no bypass roles); legacy
	// 'none' string maps to the array sentinel
	if ( ! isset( $options['sanitize_on_upload_roles'] ) ) {
		$options['sanitize_on_upload_roles'] = array();
	} elseif ( $options['sanitize_on_upload_roles'] == 'none' ) {
		$options['sanitize_on_upload_roles'] = array( 'none' );
	}

	return $options;
}
$bodhi_svgs_options = bodhi_svgs_apply_setting_defaults( $bodhi_svgs_options );

/**
 * Persist the in-memory settings (defaults merged) to the database.
 * Called on activation and once per version upgrade — not per request.
 */
function bodhi_svgs_persist_settings() {
	global $bodhi_svgs_options;

	$options = $bodhi_svgs_options;
	unset( $options['sanitize_svg'] ); // legacy pre-2.5 key, never re-persisted

	update_option( 'bodhi_svgs_settings', $options );
}

/**
 * Register activation and deactivation hooks
 */
// Activation Hook
function bodhi_svgs_plugin_activation() {
    // Fresh installs (no prior settings, no prior version marker) start on the
    // server-side rendering engine. Upgraded sites keep the legacy JS swap —
    // identical behavior to before — until they opt in on the settings screen.
    if ( false === get_option( 'bodhi_svgs_settings' ) && false === get_option( 'bodhi_svgs_plugin_version' ) ) {
        global $bodhi_svgs_options;
        $bodhi_svgs_options['render_mode'] = 'server';
    }

    bodhi_svgs_persist_settings();
    bodhi_svgs_remove_old_sanitize_setting();
}
register_activation_hook(__FILE__, 'bodhi_svgs_plugin_activation');

// Deactivation Hook
function bodhi_svgs_plugin_deactivation() {
    bodhi_svgs_remove_old_sanitize_setting();
}
register_deactivation_hook(__FILE__, 'bodhi_svgs_plugin_deactivation');
