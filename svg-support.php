<?php
/*
Plugin Name: 	SVG Support
Plugin URI:		http://wordpress.org/plugins/svg-support/
Description: 	Upload SVG files to the Media Library and render SVG files inline for direct styling/animation of an SVG's internal elements using CSS/JS.
Version: 		2.5.9
Author URI: 	https://benbodhi.com
Text Domain: 	svg-support
Domain Path:	/languages
License: 		GPLv2 or later
License URI:	http://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.8
Requires PHP: 	7.0
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

// ensure $bodhi_svgs_options is always an array
if (!is_array($bodhi_svgs_options)) {
	$bodhi_svgs_options = [];
	update_option('bodhi_svgs_settings', $bodhi_svgs_options);
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
include( BODHI_SVGS_PLUGIN_PATH . 'functions/localization.php' );			// setup localization & languages
include( BODHI_SVGS_PLUGIN_PATH . 'functions/attribute-control.php' );		// auto set SVG class & remove dimensions during insertion
include( BODHI_SVGS_PLUGIN_PATH . 'functions/featured-image.php' );			// allow inline SVG for featured images

// Include WP All Import integration only if WP All Import is active
// if ( defined( 'PMXI_VERSION' ) ) {
// 	include( BODHI_SVGS_PLUGIN_PATH . 'integrations/wp-all-import.php' );
// }

/**
 * Version based conditional / Check for stored plugin version
 */
$svgs_plugin_version_stored = get_option('bodhi_svgs_plugin_version');

// If updating from an older version
if ( $svgs_plugin_version_stored !== BODHI_SVGS_VERSION ) {
    // Run cleanup if updating from version before meta fix
    if ( version_compare( $svgs_plugin_version_stored, '2.5.9', '<' ) ) {
        bodhi_svgs_cleanup_duplicate_meta();
    }
    
    // Update stored version number
    update_option('bodhi_svgs_plugin_version', BODHI_SVGS_VERSION);
}

/**
 * Defaults for better security in versions >= 2.5
 */
// Enable 'sanitize_svg_front_end' by default
if ( !isset($bodhi_svgs_options['sanitize_svg_front_end']) ) {
	$bodhi_svgs_options['sanitize_svg_front_end'] = 'on';
	update_option( 'bodhi_svgs_settings', $bodhi_svgs_options );
}

// Allow only admins to upload SVGs by default
if ( !isset($bodhi_svgs_options['restrict']) || $bodhi_svgs_options['restrict'] == "on" ) {
	$bodhi_svgs_options['restrict'] = array('administrator');
	update_option( 'bodhi_svgs_settings', $bodhi_svgs_options );
}
elseif (isset($bodhi_svgs_options['restrict']) && $bodhi_svgs_options['restrict'] == "none" ) {
	$bodhi_svgs_options['restrict'] = array("none");
	update_option( 'bodhi_svgs_settings', $bodhi_svgs_options );
}

// By default sanitize on upload for everyone except administrator and editor roles
if ( !isset($bodhi_svgs_options['sanitize_on_upload_roles']) ) {
	$bodhi_svgs_options['sanitize_on_upload_roles'] = array('administrator', 'editor');
	update_option( 'bodhi_svgs_settings', $bodhi_svgs_options );
}
elseif ( isset($bodhi_svgs_options['sanitize_on_upload_roles']) && $bodhi_svgs_options['sanitize_on_upload_roles'] == "none") {
	$bodhi_svgs_options['sanitize_on_upload_roles'] = array("none");
	update_option( 'bodhi_svgs_settings', $bodhi_svgs_options );
}

/**
 * Register activation and deactivation hooks
 */
// Activation Hook
function bodhi_svgs_plugin_activation() {
    bodhi_svgs_remove_old_sanitize_setting();
}
register_activation_hook(__FILE__, 'bodhi_svgs_plugin_activation');

// Deactivation Hook
function bodhi_svgs_plugin_deactivation() {
    bodhi_svgs_remove_old_sanitize_setting();
}
register_deactivation_hook(__FILE__, 'bodhi_svgs_plugin_deactivation');
