<?php
/**
 * Public capability inventory for html-to-blocks-converter consumers.
 *
 * @package HTML_To_Blocks_Converter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'html_to_blocks_get_capabilities' ) ) {
	/**
	 * Gets h2bc public capabilities without requiring consumers to parse source.
	 *
	 * @return array<string,mixed> Capability inventory.
	 */
	function html_to_blocks_get_capabilities(): array {
		$supported_core_blocks = array();
		if ( function_exists( 'html_to_blocks_transformer' ) ) {
			$transformer = html_to_blocks_transformer();
			if ( $transformer ) {
				$result = $transformer->transform( '<p>Capability probe</p>' );
				foreach ( (array) ( $result->coverage[0]['supported_blocks'] ?? array() ) as $block_name ) {
					if ( is_string( $block_name ) && strpos( $block_name, 'core/' ) === 0 ) {
						$supported_core_blocks[] = $block_name;
					}
				}
			}
		}

		return array(
			'version'            => defined( 'HTML_TO_BLOCKS_CONVERTER_VERSION' ) ? HTML_TO_BLOCKS_CONVERTER_VERSION : '0.7.2',
			'raw_handler'        => array(
				'function'  => 'html_to_blocks_raw_handler',
				'available' => function_exists( 'html_to_blocks_raw_handler' ),
			),
			'transforms'         => array(
				'provider'              => 'blocks-engine',
				'families'              => array(),
				'supported_core_blocks' => array_values( array_unique( $supported_core_blocks ) ),
				'explicit_markers'      => array(),
			),
			'hooks'              => array(
				'unsupported_html_fallback' => 'html_to_blocks_unsupported_html_fallback',
				'convert_metrics'           => 'html_to_blocks_convert_metrics',
			),
			'fallback_blocks'    => array( 'core/html' ),
			'boundary_contracts' => array(
				'raw_fragment_to_block_array'  => true,
				'explicit_site_editor_markers' => true,
				'context_required_blocks'      => array(
					'core/navigation',
					'core/site-title',
					'core/post-title',
					'core/query',
					'woocommerce/*',
				),
			),
		);
	}
}
