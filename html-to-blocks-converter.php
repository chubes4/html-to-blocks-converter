<?php
/**
 * Plugin Name: HTML to Blocks Converter
 * Plugin URI: https://github.com/chubes4/html-to-blocks-converter
 * Description: Converts raw HTML to Gutenberg blocks when inserting posts via REST API or wp_insert_post
 * Version: 0.2.3
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

/**
 * Converts raw HTML to Gutenberg blocks during post insertion
 *
 * @param array $data    An array of slashed, sanitized, and processed post data
 * @param array $postarr An array of sanitized (and slashed) but otherwise unmodified post data
 * @return array Modified post data with HTML converted to blocks
 */
function html_to_blocks_convert_on_insert( $data, $postarr ) {
    if ( empty( $data['post_content'] ) ) {
        return $data;
    }

    $content = wp_unslash( $data['post_content'] );

    if ( strpos( $content, '<!-- wp:' ) !== false ) {
        return $data;
    }

    $default_types   = array_keys( get_post_types( [ 'show_in_rest' => true, 'public' => true ] ) );
    $supported_types = apply_filters( 'html_to_blocks_supported_post_types', $default_types );
    if ( ! in_array( $data['post_type'], $supported_types, true ) ) {
        return $data;
    }

    $blocks = html_to_blocks_raw_handler( [ 'HTML' => $content ] );

    if ( ! empty( $blocks ) ) {
        $serialized             = serialize_blocks( $blocks );
        $serialized_text_length = strlen( wp_strip_all_tags( $serialized ) );
        $original_text_length   = strlen( wp_strip_all_tags( $content ) );

        if ( $original_text_length > 50 && $serialized_text_length < ( $original_text_length * 0.3 ) ) {
            error_log( sprintf(
                '[HTML to Blocks] Aborting conversion due to content loss | Original: %d chars | Converted: %d chars',
                $original_text_length,
                $serialized_text_length
            ) );
            return $data;
        }

        $data['post_content'] = wp_slash( $serialized );
    }

    return $data;
}

add_filter( 'wp_insert_post_data', 'html_to_blocks_convert_on_insert', 10, 2 );

/**
 * Converts raw HTML to blocks on read — lazy conversion for posts loaded
 * from the markdown-database-integration Loader.
 *
 * The Loader runs at db_connect() time (before plugins/block types are loaded)
 * so it can only store HTML in SQLite, not blocks. This hook converts HTML → blocks
 * on the first read after init, then persists the result so it only happens once
 * per boot cycle.
 *
 * Hooks into `the_post` which fires when a post object is set up — before
 * the REST API, editor, or frontend renders it.
 */
function html_to_blocks_convert_on_read( $post ) {
	// Only convert once per post per request.
	static $converted = [];

	if ( isset( $converted[ $post->ID ] ) ) {
		return;
	}

	$content = $post->post_content;

	if ( empty( $content ) ) {
		return;
	}

	// Already has block markup — nothing to do.
	if ( strpos( $content, '<!-- wp:' ) !== false ) {
		return;
	}

	// Check post type is supported.
	$default_types   = array_keys( get_post_types( [ 'show_in_rest' => true, 'public' => true ] ) );
	$supported_types = apply_filters( 'html_to_blocks_supported_post_types', $default_types );
	if ( ! in_array( $post->post_type, $supported_types, true ) ) {
		return;
	}

	$blocks = html_to_blocks_raw_handler( [ 'HTML' => $content ] );

	if ( ! empty( $blocks ) ) {
		$serialized             = serialize_blocks( $blocks );
		$serialized_text_length = strlen( wp_strip_all_tags( $serialized ) );
		$original_text_length   = strlen( wp_strip_all_tags( $content ) );

		// Safety check — don't persist if we'd lose content.
		if ( $original_text_length > 50 && $serialized_text_length < ( $original_text_length * 0.3 ) ) {
			return;
		}

		// Update the in-memory post object so the editor sees blocks.
		$post->post_content = $serialized;

		// Persist to SQLite so subsequent reads don't need to convert again.
		// Use wpdb directly to avoid triggering wp_insert_post_data recursion.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_content' => $serialized ],
			[ 'ID' => $post->ID ],
			[ '%s' ],
			[ '%d' ]
		);

		$converted[ $post->ID ] = true;
	}
}

add_action( 'the_post', 'html_to_blocks_convert_on_read' );
