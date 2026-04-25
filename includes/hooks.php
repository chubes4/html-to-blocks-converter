<?php
/**
 * Standalone plugin hook integration.
 *
 * This file is loaded by `html-to-blocks-converter.php` only. Composer
 * consumers load `library.php`, which exposes the conversion API without
 * registering these automatic write/read hooks.
 *
 * @package HTML_To_Blocks_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	$default_types   = array_keys( get_post_types( array( 'show_in_rest' => true, 'public' => true ) ) );
	$supported_types = apply_filters( 'html_to_blocks_supported_post_types', $default_types );

	foreach ( $supported_types as $post_type ) {
		add_filter( "rest_prepare_{$post_type}", 'html_to_blocks_convert_rest_response', 10, 3 );
	}
}

// Priority 20: run after all plugins have registered their custom post types
// at the default init priority (10). Without this, CPTs registered by other
// plugins (e.g. Intelligence's wiki post type) won't exist yet when
// get_post_types() is called, and their REST response filter won't be added.
add_action( 'init', 'html_to_blocks_register_rest_filters', 20 );

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
	$default_types   = array_keys( get_post_types( array( 'show_in_rest' => true, 'public' => true ) ) );
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
	$blocks = html_to_blocks_raw_handler( array( 'HTML' => $html ) );

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
