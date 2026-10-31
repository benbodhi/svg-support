<?php
/**
 * SVG Support in attachment modal
 *
 * This file contains functions to manage and manipulate SVG attachments in WordPress.
 * It includes functionality for displaying SVGs in the attachment modal, generating metadata,
 * sanitizing SVG files, and handling specific SVG-related scenarios.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Add SVG dimensions and other details to the response for displaying in the attachment modal.
 *
 * @param array $response   The prepared attachment response data.
 * @param object $attachment The attachment object.
 * @param array $meta        The attachment meta data.
 *
 * @return array Modified response with SVG dimensions and URL.
 */
function bodhi_svgs_response_for_svg( $response, $attachment, $meta ) {

	if ( $response['mime'] == 'image/svg+xml' && empty( $response['sizes'] ) ) {

		$svg_path = get_attached_file( $attachment->ID );

		if ( ! file_exists( $svg_path ) ) {
			// If SVG is external, use the URL instead of the path
			$svg_path = $response['url'];
		}

		$dimensions = bodhi_svgs_get_dimensions( $svg_path );

		$response['sizes'] = array(
			'full' => array(
				'url' => $response['url'],
				'width' => $dimensions->width,
				'height' => $dimensions->height,
				'orientation' => $dimensions->width > $dimensions->height ? 'landscape' : 'portrait'
			)
		);

	}

	return $response;

}
add_filter( 'wp_prepare_attachment_for_js', 'bodhi_svgs_response_for_svg', 10, 3 );

/**
 * Get the dimensions of an SVG file.
 *
 * This function checks if the SVG is a local file or a remote URL, retrieves its content, and
 * parses the width and height from the SVG attributes.
 *
 * @param string $svg The file path or URL of the SVG.
 *
 * @return object An object containing the width and height of the SVG.
 */
function bodhi_svgs_get_dimensions( $svg ) {

	$svg_content = '';

	// Check if $svg is a URL or a local file path
	if ( filter_var( $svg, FILTER_VALIDATE_URL ) ) {
		// For remote SVGs, use wp_remote_get()
		$response = wp_remote_get( $svg );
		if ( is_wp_error( $response ) ) {
			return (object) array( 'width' => 0, 'height' => 0 );
		}
		$svg_content = wp_remote_retrieve_body( $response );
	} else {
		// For local files, use WP_Filesystem to read the file content
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		global $wp_filesystem;
		WP_Filesystem();
		$svg_content = $wp_filesystem->get_contents( $svg );
	}

	if ( empty( $svg_content ) ) {
		return (object) array( 'width' => 0, 'height' => 0 );
	}

	// A gzipped .svgz has to be decompressed before it can be parsed
	if ( bodhi_svgs_is_gzipped( $svg_content ) ) {
		$svg_content = gzdecode( $svg_content );
	}

	// Parse quietly: a file that can't be read just reports no dimensions
	$use_errors = libxml_use_internal_errors( true );
	$svg = $svg_content ? simplexml_load_string( $svg_content ) : false;
	libxml_clear_errors();
	libxml_use_internal_errors( $use_errors );

	if ( $svg === FALSE ) {
		$width = '0';
		$height = '0';
	} else {
		$attributes = $svg->attributes();
		$width = (string) $attributes->width;
		$height = (string) $attributes->height;
	}

	return (object) array( 'width' => $width, 'height' => $height );

}

/**
 * Generate attachment metadata for SVGs. (Thanks @surml)
 *
 * This function generates metadata for SVG attachments, including dimensions and file path.
 * It is used to fix warnings related to missing width and height metadata for SVGs.
 *
 * @param array $metadata       The attachment metadata.
 * @param int   $attachment_id  The attachment ID.
 *
 * @return array Modified metadata including SVG dimensions.
 */
function bodhi_svgs_generate_svg_attachment_metadata( $metadata, $attachment_id ) {

	$mime = get_post_mime_type( $attachment_id );

	if ( $mime == 'image/svg+xml' ) {

		$svg_path = get_attached_file( $attachment_id );
		$upload_dir = wp_upload_dir();
		// Get the path relative to /uploads/
		$relative_path = $svg_path ? str_replace($upload_dir['basedir'], '', $svg_path) : '';
		$filename = basename( $svg_path );

		$dimensions = bodhi_svgs_get_dimensions( $svg_path );

		$metadata = array(
			'width'		=> intval($dimensions->width),
			'height'	=> intval($dimensions->height),
			'file'		=> $relative_path
		);

		$height = intval($dimensions->height);
		$width = intval($dimensions->width);

		// Generate sizes array for future implementations, if needed
		$sizes = array();
		foreach ( get_intermediate_image_sizes() as $s ) {

			$sizes[$s] = array( 'width' => '', 'height' => '', 'crop' => false );

			if ( $width !== 0 && $height !== 0 ) {

				if ( isset( $_wp_additional_image_sizes[$s]['width'] ) ) {
					$width_current_size = intval( $_wp_additional_image_sizes[$s]['width'] );
				} else {
					$width_current_size = get_option( "{$s}_size_w" );
				}

				if ( $width > $height ) {
					$ratio = round($width / $height, 2);
					$new_height = round($width_current_size / $ratio);
				} else {
					$ratio = round($height / $width, 2);
					$new_height = round($width_current_size * $ratio);
				}

				$sizes[$s]['width'] = $width_current_size;
				$sizes[$s]['height'] = $new_height;

				$sizes[$s]['crop'] = false;

			} else {

				if ( isset( $_wp_additional_image_sizes[$s]['width'] ) ) {
					$sizes[$s]['width'] = intval( $_wp_additional_image_sizes[$s]['width'] );
				} else {
					$sizes[$s]['width'] = get_option( "{$s}_size_w" );
				}

				if ( isset( $_wp_additional_image_sizes[$s]['height'] ) ) {
					$sizes[$s]['height'] = intval( $_wp_additional_image_sizes[$s]['height'] );
				} else {
					$sizes[$s]['height'] = get_option( "{$s}_size_h" );
				}

				if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) ) {
					$sizes[$s]['crop'] = intval( $_wp_additional_image_sizes[$s]['crop'] );
				} else {
					$sizes[$s]['crop'] = get_option( "{$s}_crop" );
				}

			}

			$sizes[$s]['file'] = $filename;
			$sizes[$s]['mime-type'] = 'image/svg+xml';

		}

		$metadata['sizes'] = $sizes;

	}

	return $metadata;

}
add_filter( 'wp_generate_attachment_metadata', 'bodhi_svgs_generate_svg_attachment_metadata', 10, 3 );

/**
 * Sanitize SVG markup.
 *
 * Removes potentially harmful elements and attributes, optionally minifies, and
 * re-encodes the result if the input was gzipped (.svgz).
 *
 * @param string $dirty The SVG markup, plain or gzipped.
 *
 * @return string|false The sanitized SVG, or false if it couldn't be sanitized.
 */
function bodhi_svgs_sanitize_string( $dirty ) {

	if ( ! is_string( $dirty ) ) {
		return false;
	}

	$sanitizer = bodhi_svgs_sanitizer();

	$sanitizer->setAllowedTags( new bodhi_svg_tags() );
	$sanitizer->setAllowedAttrs( new bodhi_svg_attributes() );

	// Try to decode if gzipped is enabled
	if ( $is_zipped = bodhi_svgs_is_gzipped( $dirty ) ) {

		$dirty = gzdecode( $dirty );

		// Return on failure, since we can't read file
		if ( $dirty === false ) {
			return false;
		}

	}

	// Remove remote references since they are dangerous and lead to injection
	$sanitizer->removeRemoteReferences(true);

	// Enable minify in library if its enabled in admin panel
	bodhi_svgs_minify();

	$clean = $sanitizer->sanitize( $dirty );

	if ( $clean === false ) {
		return false;
	}

	// If the file was gzipped, we need to re-zip it
	if ( $is_zipped ) {
		$clean = gzencode( $clean );
	}

	return $clean;

}

/**
 * Sanitize SVG files.
 *
 * This function sanitizes an SVG file in place with bodhi_svgs_sanitize_string().
 *
 * @param string $file The file path of the SVG to sanitize.
 *
 * @return bool True if the file was successfully sanitized, false otherwise.
 */
function bodhi_svgs_sanitize( $file ){

	// Using WP_Filesystem to read the SVG file content
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	global $wp_filesystem;
	WP_Filesystem();
	$dirty = $wp_filesystem->get_contents( $file );

	$clean = bodhi_svgs_sanitize_string( $dirty );

	if ( $clean === false ) {
		return false;
	}

	// Use WP_Filesystem to write the sanitized content back
	if ( ! $wp_filesystem->put_contents( $file, $clean, FS_CHMOD_FILE ) ) {
		return false;
	}

	return true;

}

/**
 * Enable minification for SVG files.
 *
 * This function enables minification for SVG files if the option is set in the plugin settings.
 */
function bodhi_svgs_minify() {

	global $bodhi_svgs_options;

	if ( !empty($bodhi_svgs_options['minify_svg']) && $bodhi_svgs_options['minify_svg'] === 'on' ) {
		bodhi_svgs_sanitizer()->minify(true);
	}

}

/**
 * Check if a string is gzipped.
 *
 * @param string $contents The contents to check.
 *
 * @return bool True if the contents are gzipped, false otherwise.
 */
function bodhi_svgs_is_gzipped( $contents ) {
	if ($contents === null) {
		return false;
	}

	if ( function_exists( 'mb_strpos' ) ) {
		return 0 === mb_strpos( $contents, "\x1f" . "\x8b" . "\x08" );
	} else {
		return 0 === strpos( $contents, "\x1f" . "\x8b" . "\x08" );
	}
}

/**
 * Check whether a file name has an SVG extension (.svg or .svgz).
 *
 * @param string $file_name The file name or path.
 *
 * @return bool True for .svg and .svgz, in any letter case.
 */
function bodhi_svgs_is_svg_filename( $file_name ) {
	$file_ext = strtolower( pathinfo( (string) $file_name, PATHINFO_EXTENSION ) );
	return $file_ext === 'svg' || $file_ext === 'svgz';
}

/**
 * Check whether the current user's SVGs are stored without sanitization.
 *
 * Only a user who can upload files and holds a role chosen under "Do not
 * sanitize for these roles" bypasses the sanitizer. Anyone without
 * upload_files (e.g. a front-end upload form that accepts SVGs) is always
 * sanitized, whatever their role.
 *
 * @return bool True if the user bypasses sanitization.
 */
function bodhi_svgs_user_bypasses_sanitization() {
	global $bodhi_svgs_options;

	if ( ! current_user_can( 'upload_files' ) ) {
		return false;
	}

	$bypass_roles = isset( $bodhi_svgs_options['sanitize_on_upload_roles'] ) ? (array) $bodhi_svgs_options['sanitize_on_upload_roles'] : array();
	$user = wp_get_current_user();

	return ! empty( array_intersect( $bypass_roles, (array) $user->roles ) );
}

/**
 * Pre-filter for handling SVG uploads.
 *
 * This function checks if the uploaded file is an SVG and applies sanitization if required.
 *
 * @param array $file The uploaded file data.
 *
 * @return array The modified file data.
 */
function bodhi_svgs_sanitize_svg($file) {
	$file_path = $file['tmp_name'];
	$file_name = $file['name'];

	// First quick check - if it's clearly not an SVG (.svg or .svgz), return early.
	// .svgz must NOT bypass sanitization: it is registered as image/svg+xml and
	// served inline, so a .svgz holding plaintext SVG could otherwise store XSS.
	if ( ! empty( $file_name ) && ! bodhi_svgs_is_svg_filename( $file_name ) ) {
		return $file;
	}

	// Multiple validation checks for SVG
	if ( $file_path && file_exists( $file_path ) ) {
		// 1. Check MIME type using fileinfo
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $file_path );
		// finfo is an object freed automatically since PHP 8.1, and finfo_close() is deprecated in 8.5
		if ( PHP_VERSION_ID < 80100 ) {
			finfo_close( $finfo );
		}

		// 2. Read file content to check for SVG header
		$file_content = file_get_contents( $file_path );

		// A .svgz upload arrives gzip-compressed; decompress before validating so
		// we inspect the real SVG markup, not the gzip container bytes. A plaintext
		// .svgz (the XSS PoC) is not gzipped and falls through unchanged.
		$is_gzipped_upload = bodhi_svgs_is_gzipped( $file_content );
		if ( $is_gzipped_upload ) {
			$decoded = gzdecode( $file_content );
			if ( $decoded === false ) {
				$file['error'] = __( 'File is not a valid SVG.', 'svg-support' );
				return $file;
			}
			$file_content = $decoded;
		}

		// Check for XML declaration and SVG tag
		$pattern1 = '/^[\s\n]*(?:<\?xml[^>]*>[\s\n]*)?(?:<!--.*?-->[\s\n]*)*(?:<!DOCTYPE[^>]*>[\s\n]*)?(?:<!--.*?-->[\s\n]*)*<svg[^>]*>/is';
		$pattern2 = '/^[\s\n]*(?:<!--.*?-->[\s\n]*)*<svg[^>]*>/is';

		$match1 = preg_match( $pattern1, $file_content );
		$match2 = preg_match( $pattern2, $file_content );
		$has_closing = strpos( $file_content, '</svg>' ) !== false;

		$is_svg_content = ( $match1 || $match2 ) && $has_closing;

		// If content validation fails OR (mime type isn't SVG AND isn't a plain text
		// file containing SVG AND isn't a gzipped .svgz whose decoded payload is SVG).
		// A genuinely gzipped .svgz reports application/gzip from finfo, so once the
		// decompressed payload is confirmed valid SVG we accept it as image/svg+xml.
		if ( !$is_svg_content ||
			( $real_mime !== 'image/svg+xml' &&
			  $real_mime !== 'image/svg' &&
			  !( $real_mime === 'text/plain' && $is_svg_content ) &&
			  !( $is_gzipped_upload && $is_svg_content ) ) ) {
			$file['error'] = __( 'File is not a valid SVG.', 'svg-support' );
			return $file;
		}
	}

	// Detect programmatic sideloads (e.g. media_handle_sideload()), which run
	// through wp_handle_sideload_prefilter rather than a browser upload form.
	$is_sideload = ( current_filter() === 'wp_handle_sideload_prefilter' );

	// Now we know it's an SVG, continue with security checks.
	// Sideloads are server-initiated and have no media form nonce, so the
	// anti-CSRF nonce check only applies to standard browser uploads.
	if (!$is_sideload && !defined('REST_REQUEST') && !wp_verify_nonce(
		sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), 
		'media-form'
	)) {
		$file['error'] = __('Security check failed.', 'svg-support');
		return $file;
	}

	// Always sanitize sideloaded files (they may run with no interactive user);
	// otherwise force sanitize unless the user is in a role that bypasses it.
	// Users without upload_files never bypass (see bodhi_svgs_user_bypasses_sanitization()).
	if ($is_sideload || ! bodhi_svgs_user_bypasses_sanitization()) {
		// Delegate to the shared, gzip-aware sanitizer so browser uploads, REST
		// uploads and imports all apply the same whitelist filters, remote-reference
		// removal and gzip (.svgz) handling.
		if ( ! bodhi_svgs_sanitize( $file_path ) ) {
			$file['error'] = __("Sorry, this file couldn't be sanitized for security reasons and wasn't uploaded.", 'svg-support');
			return $file;
		}
	}

	return $file;
}
// Add filter to handle upload pre-filtering for sanitization
add_filter('wp_handle_upload_prefilter', 'bodhi_svgs_sanitize_svg');
// Also sanitize SVGs added programmatically via media_handle_sideload()
add_filter('wp_handle_sideload_prefilter', 'bodhi_svgs_sanitize_svg');

/**
 * Route XML-RPC media uploads through the SVG sanitizer.
 *
 * wp.uploadFile and metaWeblog.newMediaObject store the file with
 * wp_upload_bits(), which fires neither upload prefilter, so they are wrapped
 * to sanitize the bytes before core writes them. Only core's own mapping is
 * wrapped; anything else is still covered by bodhi_svgs_watch_upload_bits().
 *
 * @param array $methods The XML-RPC methods and their callbacks.
 *
 * @return array The methods, with the media upload callbacks wrapped.
 */
function bodhi_svgs_xmlrpc_methods( $methods ) {
	foreach ( array( 'wp.uploadFile', 'metaWeblog.newMediaObject' ) as $method ) {
		if ( isset( $methods[ $method ] ) && $methods[ $method ] === 'this:mw_newMediaObject' ) {
			$methods[ $method ] = 'bodhi_svgs_xmlrpc_new_media_object';
		}
	}

	return $methods;
}
add_filter( 'xmlrpc_methods', 'bodhi_svgs_xmlrpc_methods', PHP_INT_MAX );

/**
 * Sanitize an SVG uploaded over XML-RPC, then hand it to core.
 *
 * Always sanitized, like REST and sideloaded uploads: the user is only
 * authenticated inside core's method.
 *
 * @param array $args The method arguments: blog ID, username, password, file data.
 *
 * @return array|IXR_Error The uploaded media item, or an error.
 */
function bodhi_svgs_xmlrpc_new_media_object( $args ) {
	global $wp_xmlrpc_server;

	$data = ( is_array( $args ) && isset( $args[3] ) ) ? $args[3] : null;

	if ( is_array( $data ) && isset( $data['name'], $data['bits'] ) && is_string( $data['name'] ) && is_string( $data['bits'] )
		&& bodhi_svgs_is_svg_filename( sanitize_file_name( $data['name'] ) ) ) {

		$clean = bodhi_svgs_sanitize_string( $data['bits'] );

		if ( $clean === false ) {
			return new IXR_Error( 500, __( "Sorry, this file couldn't be sanitized for security reasons and wasn't uploaded.", 'svg-support' ) );
		}

		$args[3]['bits'] = $clean;
	}

	// xmlrpc.php sets the global; code that runs its own server may not.
	$server = ( $wp_xmlrpc_server instanceof wp_xmlrpc_server ) ? $wp_xmlrpc_server : new wp_xmlrpc_server();

	return $server->mw_newMediaObject( $args );
}

/**
 * Watch for SVGs stored with wp_upload_bits().
 *
 * wp_upload_bits() fires neither upload prefilter and can't be made to write
 * different bytes, yet XML-RPC, the WordPress Importer and other plugins store
 * files with it. So note the SVG it is about to write (its final path comes
 * from the wp_unique_filename() call that follows) and sanitize the file once
 * it is on disk: when it becomes an attachment, or at the end of the request.
 *
 * @param array|string $upload_bits The file name, bits and time, or an error message.
 *
 * @return array|string Unchanged.
 */
function bodhi_svgs_watch_upload_bits( $upload_bits ) {
	global $bodhi_svgs_pending_upload_bits;

	$bodhi_svgs_pending_upload_bits = is_array( $upload_bits ) && isset( $upload_bits['name'] ) && bodhi_svgs_is_svg_filename( $upload_bits['name'] );

	return $upload_bits;
}
add_filter( 'wp_upload_bits', 'bodhi_svgs_watch_upload_bits', PHP_INT_MAX );

/**
 * Record where wp_upload_bits() is writing an SVG.
 *
 * @param string $filename The unique file name.
 * @param string $ext      The file extension.
 * @param string $dir      The directory the file is written to.
 *
 * @return string Unchanged.
 */
function bodhi_svgs_note_upload_bits_file( $filename, $ext, $dir ) {
	global $bodhi_svgs_pending_upload_bits, $bodhi_svgs_upload_bits_files;

	if ( ! empty( $bodhi_svgs_pending_upload_bits ) ) {
		$bodhi_svgs_pending_upload_bits = false;

		if ( bodhi_svgs_is_svg_filename( $filename ) ) {
			$bodhi_svgs_upload_bits_files[] = wp_normalize_path( $dir . '/' . $filename );
		}
	}

	return $filename;
}
add_filter( 'wp_unique_filename', 'bodhi_svgs_note_upload_bits_file', PHP_INT_MAX, 3 );

/**
 * Sanitize an SVG that wp_upload_bits() wrote, unless the user bypasses
 * sanitization. A file that can't be sanitized is deleted, so it is never
 * served as written.
 *
 * @param string $file The file path.
 */
function bodhi_svgs_sanitize_upload_bits_file( $file ) {
	if ( ! bodhi_svgs_is_svg_filename( $file ) || ! file_exists( $file ) || filesize( $file ) === 0 || bodhi_svgs_user_bypasses_sanitization() ) {
		return;
	}

	if ( ! bodhi_svgs_sanitize( $file ) ) {
		wp_delete_file( $file );
	}
}

/**
 * Sanitize a watched SVG as soon as it becomes an attachment, before its
 * metadata is generated (XML-RPC, the WordPress Importer).
 *
 * @param int $attachment_id The new attachment ID.
 */
function bodhi_svgs_sanitize_upload_bits_attachment( $attachment_id ) {
	global $bodhi_svgs_upload_bits_files;

	if ( empty( $bodhi_svgs_upload_bits_files ) ) {
		return;
	}

	$file = wp_normalize_path( (string) get_attached_file( $attachment_id ) );
	$key  = array_search( $file, $bodhi_svgs_upload_bits_files, true );

	if ( $key === false ) {
		return;
	}

	unset( $bodhi_svgs_upload_bits_files[ $key ] );
	bodhi_svgs_sanitize_upload_bits_file( $file );
}
add_action( 'add_attachment', 'bodhi_svgs_sanitize_upload_bits_attachment' );

/**
 * Sanitize any watched SVG that didn't become an attachment during the request.
 */
function bodhi_svgs_sanitize_remaining_upload_bits() {
	global $bodhi_svgs_upload_bits_files;

	foreach ( (array) $bodhi_svgs_upload_bits_files as $file ) {
		bodhi_svgs_sanitize_upload_bits_file( $file );
	}

	$bodhi_svgs_upload_bits_files = array();
}
add_action( 'shutdown', 'bodhi_svgs_sanitize_remaining_upload_bits' );

/**
 * Fix for image widget PHP warnings related to metadata.
 *
 * @param array $data The attachment metadata.
 *
 * @return array|false The modified metadata or false if invalid.
 */
function bodhi_svgs_get_attachment_metadata( $data ) {

	$res = $data;

	if ( !isset( $data['width'] ) || !isset( $data['height'] ) ) {
		$res = false;
	}

	return $res;

}
// add_filter( 'wp_get_attachment_metadata' , 'bodhi_svgs_get_attachment_metadata' );
// Commented this out 20200307 because it was stripping metadata from other attachments as well. Need to make this target only SVG attachments.

/**
 * Remove srcset attribute for SVG images.
 *
 * @param array $sources The sources array for the image.
 *
 * @return array The modified sources array.
 */
function bodhi_svgs_disable_srcset( $sources ) {

	$first_element = reset($sources);
	if ( isset($first_element) && !empty($first_element['url']) ) {

		$ext = pathinfo(reset($sources)['url'], PATHINFO_EXTENSION);

		if ( $ext == 'svg' ) {

			// return empty array
			$sources = array();

			return $sources;

		} else {

			return $sources;

		}

	} else {

		return $sources;

	}

}
add_filter( 'wp_calculate_image_srcset', 'bodhi_svgs_disable_srcset' );

/**
 * Fix for division by zero error for SVGs. (Proposed by @starsis)
 *
 * This function ensures that SVGs do not cause division by zero errors by providing default
 * dimensions if they are missing.
 *
 * @param array  $image         The image data.
 * @param int    $attachment_id The attachment ID.
 * @param string $size          The requested image size.
 * @param bool   $icon          Whether the image is an icon.
 *
 * @return array The modified image data.
 */
function bodhi_svgs_dimension_fallback( $image, $attachment_id, $size, $icon ) {

	// only manipulate for svgs
	if ( get_post_mime_type($attachment_id) == 'image/svg+xml' ) {

		if ( isset($image[1]) && $image[1] === 0 ) {
			$image[1] = 1;
		}
		if ( isset($image[2]) && $image[2] === 0 ) {
			$image[2] = 1;
		}

	}

	return $image;

}
add_filter( 'wp_get_attachment_image_src', 'bodhi_svgs_dimension_fallback', 10, 4 );

/**
 * Pre-process SVG files uploaded via REST API
 *
 * @param array $file File data before processing
 * @param array $request The full request payload
 * @return array|WP_Error Modified file data or error
 */
function bodhi_svgs_rest_pre_upload($file, $request) {
    if ($file['type'] === 'image/svg+xml') {
        // Randomize filename for REST API uploads
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $random_name = wp_generate_password(12, false) . '.' . $ext;
        $file['name'] = sanitize_file_name($random_name);

        // Force sanitization
        if (!bodhi_svgs_sanitize($file['tmp_name'])) {
            return new WP_Error(
                'svg_sanitization_failed',
                __('SVG sanitization failed for security reasons.', 'svg-support'),
                array('status' => 400)
            );
        }
    }
    return $file;
}
add_filter('rest_pre_upload_file', 'bodhi_svgs_rest_pre_upload', 10, 2);

function bodhi_svgs_handle_upload_check($fileinfo) {
    if ($fileinfo['type'] === 'image/svg+xml') {
        global $bodhi_last_upload_info;
        $bodhi_last_upload_info = $fileinfo;
    }
    return $fileinfo;
}
add_filter('wp_handle_upload', 'bodhi_svgs_handle_upload_check');

function bodhi_svgs_rest_insert_attachment($prepared_attachment, $request) {
    if ($request->get_header('content-type') !== 'image/svg+xml') {
        return $prepared_attachment;
    }
    
    global $bodhi_svgs_options;
    $user = wp_get_current_user();
    $current_user_roles = (array) $user->roles;
    $sanitize_on_upload_roles_array = (array) $bodhi_svgs_options['sanitize_on_upload_roles'];
    
    $should_sanitize = empty(array_intersect($sanitize_on_upload_roles_array, $current_user_roles));
    
    if ($should_sanitize) {
        $file_path = get_attached_file($prepared_attachment->ID);
        if (!$file_path) {
            global $bodhi_last_upload_info;
            if (isset($bodhi_last_upload_info['file'])) {
                $file_path = $bodhi_last_upload_info['file'];
            }
        }
        
        if ($file_path && file_exists($file_path)) {
            // Use the shared, gzip-aware sanitizer for consistency with the upload
            // and REST pre-upload paths (best-effort; non-fatal here).
            bodhi_svgs_sanitize($file_path);
        }
    }
    
    return $prepared_attachment;
}
add_filter('rest_insert_attachment', 'bodhi_svgs_rest_insert_attachment', 10, 2);
