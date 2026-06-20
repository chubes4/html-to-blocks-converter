<?php
/**
 * Safe inline SVG icon helper functions.
 *
 * @package HTML_To_Blocks_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'html_to_blocks_classify_inline_svg_icon' ) ) {
	/**
	 * Classifies and sanitizes an inline SVG icon for downstream materialization.
	 *
	 * @param string $svg Source SVG fragment.
	 * @return array Classification result with is_safe, svg, metadata, and reason keys.
	 */
	function html_to_blocks_classify_inline_svg_icon( string $svg ): array {
		return HTML_To_Blocks_SVG_Icon_Classifier::classify( $svg );
	}
}

if ( ! function_exists( 'html_to_blocks_svg_icon_block_from_transformer_fallback' ) ) {
	/**
	 * Converts a safe upstream inline SVG fallback into H2BC's compatibility block.
	 *
	 * @param array<string,mixed> $fallback Upstream transformer fallback metadata.
	 * @return array<string,mixed>|null SVG placeholder block when safe.
	 */
	function html_to_blocks_svg_icon_block_from_transformer_fallback( array $fallback ): ?array {
		if ( 'unsafe_inline_svg' === (string) ( $fallback['reason'] ?? '' ) || 'html_unsafe_inline_svg' === (string) ( $fallback['diagnostic_code'] ?? '' ) ) {
			return null;
		}

		if ( 'svg' !== strtolower( (string) ( $fallback['tag'] ?? '' ) ) || empty( $fallback['html'] ) || ! class_exists( 'HTML_To_Blocks_SVG_Icon_Classifier', false ) ) {
			return null;
		}

		$classification = HTML_To_Blocks_SVG_Icon_Classifier::classify( (string) $fallback['html'] );
		if ( empty( $classification['is_safe'] ) || empty( $classification['svg'] ) ) {
			return null;
		}

		$svg      = (string) $classification['svg'];
		$metadata = isset( $classification['metadata'] ) && is_array( $classification['metadata'] ) ? $classification['metadata'] : array();

		if ( function_exists( 'do_action' ) ) {
			do_action( 'html_to_blocks_safe_inline_svg_icon', $svg, $metadata, $classification );
		}

		return array(
			'blockName'    => 'html-to-blocks/svg-icon',
			'attrs'        => array(
				'svg'      => $svg,
				'metadata' => $metadata,
			),
			'innerBlocks'  => array(),
			'innerHTML'    => $svg,
			'innerContent' => array( $svg ),
		);
	}
}
