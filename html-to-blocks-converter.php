<?php
/**
 * Plugin Name: HTML to Blocks Converter
 * Plugin URI: https://github.com/chubes4/html-to-blocks-converter
 * Description: Converts raw HTML to Gutenberg blocks — on write (wp_insert_post) and on read (REST API for the editor)
 * Version: 0.3.0
 * Author: Chris Huber
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: html-to-blocks-converter
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HTML_TO_BLOCKS_CONVERTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'HTML_TO_BLOCKS_CONVERTER_MIN_WP', '6.4' );

if ( version_compare( get_bloginfo( 'version' ), HTML_TO_BLOCKS_CONVERTER_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: minimum WordPress version */
				esc_html__( 'HTML to Blocks Converter requires WordPress %s or higher.', 'html-to-blocks-converter' ),
				HTML_TO_BLOCKS_CONVERTER_MIN_WP
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once HTML_TO_BLOCKS_CONVERTER_PATH . 'includes/class-html-element.php';
require_once HTML_TO_BLOCKS_CONVERTER_PATH . 'includes/class-block-factory.php';
require_once HTML_TO_BLOCKS_CONVERTER_PATH . 'includes/class-attribute-parser.php';
require_once HTML_TO_BLOCKS_CONVERTER_PATH . 'includes/class-transform-registry.php';
require_once HTML_TO_BLOCKS_CONVERTER_PATH . 'raw-handler.php';

// ---------------------------------------------------------------------------
// Write path: convert HTML → blocks when a post is inserted/updated.
// ---------------------------------------------------------------------------

/**
 * Converts raw HTML to Gutenberg blocks during post insertion.
 *
 * @param array $data    An array of slashed, sanitized, and processed post data.
 * @param array $postarr An array of sanitized (and slashed) but otherwise unmodified post data.
 * @return array Modified post data with HTML converted to blocks.
 */
function html_to_blocks_convert_on_insert( $data, $postarr ) {
    if ( empty( $data['post_content'] ) ) {
        return $data;
    }

    $content = wp_unslash( $data['post_content'] );

    if ( strpos( $content, '<!-- wp:' ) !== false ) {
        return $data;
    }

    if ( ! html_to_blocks_is_supported_type( $data['post_type'] ) ) {
        return $data;
    }

    $serialized = html_to_blocks_convert_content( $content );
    if ( $serialized !== null ) {
        $data['post_content'] = wp_slash( $serialized );
    }

    return $data;
}

add_filter( 'wp_insert_post_data', 'html_to_blocks_convert_on_insert', 10, 2 );

// ---------------------------------------------------------------------------
// Read path: convert HTML → blocks in REST API responses for the editor.
// ---------------------------------------------------------------------------

/**
 * Register REST API response filters for all supported post types.
 *
 * The block editor fetches posts via the REST API and reads content.raw.
 * If content.raw is HTML (no block delimiters), we convert it to blocks
 * so the editor works natively.
 *
 * Runs at priority 10 — after any upstream filters (e.g. markdown → HTML
 * at priority 5) have already converted to HTML.
 */
function html_to_blocks_register_rest_filters() {
	$default_types   = array_keys( get_post_types( [ 'show_in_rest' => true, 'public' => true ] ) );
	$supported_types = apply_filters( 'html_to_blocks_supported_post_types', $default_types );

	foreach ( $supported_types as $post_type ) {
		add_filter( "rest_prepare_{$post_type}", 'html_to_blocks_convert_rest_response', 10, 3 );
	}
}

add_action( 'init', 'html_to_blocks_register_rest_filters' );

/**
 * Convert HTML to blocks in REST API responses.
 *
 * Only converts content.raw when the request has edit context (i.e. the
 * block editor is loading the post). Frontend REST requests are untouched.
 *
 * @param WP_REST_Response $response The response object.
 * @param WP_Post          $post     The post object.
 * @param WP_REST_Request  $request  The request object.
 * @return WP_REST_Response Modified response.
 */
function html_to_blocks_convert_rest_response( $response, $post, $request ) {
	// Only convert for edit context (block editor).
	if ( $request->get_param( 'context' ) !== 'edit' ) {
		return $response;
	}

	$data = $response->get_data();

	if ( empty( $data['content']['raw'] ) ) {
		return $response;
	}

	$raw = $data['content']['raw'];

	// Already block markup — nothing to do.
	if ( strpos( $raw, '<!-- wp:' ) !== false ) {
		return $response;
	}

	$serialized = html_to_blocks_convert_content( $raw );
	if ( $serialized !== null ) {
		$data['content']['raw'] = $serialized;
		$response->set_data( $data );
	}

	return $response;
}

// ---------------------------------------------------------------------------
// Shared helpers.
// ---------------------------------------------------------------------------

/**
 * Check if a post type is supported for conversion.
 *
 * @param string $post_type The post type slug.
 * @return bool
 */
function html_to_blocks_is_supported_type( string $post_type ): bool {
	$default_types   = array_keys( get_post_types( [ 'show_in_rest' => true, 'public' => true ] ) );
	$supported_types = apply_filters( 'html_to_blocks_supported_post_types', $default_types );
	return in_array( $post_type, $supported_types, true );
}

/**
 * Convert HTML content to serialized block markup.
 *
 * Returns null if conversion fails or would lose significant content.
 *
 * @param string $html The HTML content.
 * @return string|null Serialized block markup, or null on failure.
 */
function html_to_blocks_convert_content( string $html ): ?string {
	$blocks = html_to_blocks_raw_handler( [ 'HTML' => $html ] );

	if ( empty( $blocks ) ) {
		return null;
	}

	$serialized             = serialize_blocks( $blocks );
	$serialized_text_length = strlen( wp_strip_all_tags( $serialized ) );
	$original_text_length   = strlen( wp_strip_all_tags( $html ) );

	// Safety: abort if we'd lose significant content.
	if ( $original_text_length > 50 && $serialized_text_length < ( $original_text_length * 0.3 ) ) {
		error_log( sprintf(
			'[HTML to Blocks] Aborting conversion due to content loss | Original: %d chars | Converted: %d chars',
			$original_text_length,
			$serialized_text_length
		) );
		return null;
	}

	return $serialized;
}
