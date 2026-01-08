<?php
/**
 * Plugin Name: HTML to Blocks Converter
 * Plugin URI: https://github.com/chubes4/html-to-blocks-converter
 * Description: Converts raw HTML to Gutenberg blocks when inserting posts via REST API or wp_insert_post
 * Version: 0.2.2
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

    if ( strpos( trim( $content ), '<!-- wp:' ) === 0 ) {
        return $data;
    }

    $default_types   = array_keys( get_post_types( [ 'show_in_rest' => true, 'public' => true ] ) );
    $supported_types = apply_filters( 'html_to_blocks_supported_post_types', $default_types );
    if ( ! in_array( $data['post_type'], $supported_types, true ) ) {
        return $data;
    }

    $original_length = strlen( $content );
    $blocks = html_to_blocks_raw_handler( [ 'HTML' => $content ] );

    if ( ! empty( $blocks ) ) {
        $serialized = serialize_blocks( $blocks );
        $serialized_length = strlen( $serialized );
        
        // Log if output is significantly smaller than input (potential content loss)
        if ( $original_length > 200 && $serialized_length < ( $original_length * 0.3 ) ) {
            error_log( sprintf(
                '[HTML to Blocks] Potential content loss on insert | Post type: %s | Title: %s | Input: %d chars | Output: %d chars | Blocks: %d | Input preview: %s',
                $data['post_type'] ?? 'unknown',
                substr( $data['post_title'] ?? 'untitled', 0, 50 ),
                $original_length,
                $serialized_length,
                count( $blocks ),
                substr( $content, 0, 500 )
            ) );
        }
        
        $data['post_content'] = wp_slash( $serialized );
    }

    return $data;
}

add_filter( 'wp_insert_post_data', 'html_to_blocks_convert_on_insert', 10, 2 );
