<?php
/**
 * Raw handler pipeline ported from Gutenberg JavaScript to PHP
 *
 * Uses WordPress HTML API (WP_HTML_Processor) for spec-compliant HTML5 parsing.
 * Converts HTML to Gutenberg blocks using registered transforms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main raw handler function - converts HTML to blocks
 *
 * @param array $args Arguments array with 'HTML' key and optional conversion context.
 * @return array Array of block arrays
 */
function html_to_blocks_raw_handler( $args ) {
	$html = $args['HTML'] ?? '';

	if ( empty( $html ) ) {
		return array();
	}

	if ( strpos( $html, '<!-- wp:' ) !== false ) {
		$blocks             = parse_blocks( $html );
		$is_single_freeform = count( $blocks ) === 1
			&& isset( $blocks[0]['blockName'] )
			&& 'core/freeform' === $blocks[0]['blockName'];
		if ( ! $is_single_freeform ) {
			return html_to_blocks_normalize_parsed_image_html_blocks( $blocks );
		}

		$freeform_html = html_to_blocks_get_parsed_block_html( $blocks[0] );
		if ( '' !== trim( $freeform_html ) ) {
			$html = $freeform_html;
		}
	}

	$pieces = html_to_blocks_shortcode_converter( $html );

	$result = array();
	foreach ( $pieces as $piece ) {
		if ( ! is_string( $piece ) ) {
			$result[] = $piece;
			continue;
		}

		if ( ! html_to_blocks_can_skip_normalise_blocks( $piece ) ) {
			$piece = html_to_blocks_normalise_blocks( $piece );
		}
		$blocks = html_to_blocks_convert( $piece, array_merge( $args, array( 'HTML' => $piece ) ) );
		$result = array_merge( $result, $blocks );
	}

	return array_filter( $result );
}

/**
 * Gets the HTML payload from a parsed block.
 *
 * @param array $block Parsed block array.
 * @return string Block HTML.
 */
function html_to_blocks_get_parsed_block_html( array $block ): string {
	if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
		return $block['innerHTML'];
	}

	if ( empty( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
		return '';
	}

	$html = '';
	foreach ( $block['innerContent'] as $content ) {
		if ( is_string( $content ) ) {
			$html .= $content;
		}
	}

	return $html;
}

/**
 * Determine whether block normalization can be skipped for an already wrapped fragment.
 *
 * Normalization only needs to repair top-level phrasing content. A fragment that is
 * already one complete block-like root can go straight to raw conversion, avoiding
 * an extra full scan of large generated HTML pages.
 *
 * @param string $html HTML fragment.
 * @return bool True when normalization can be skipped.
 */
function html_to_blocks_can_skip_normalise_blocks( string $html ): bool {
	$html = trim( $html );
	if ( '' === $html || '<' !== $html[0] || ! preg_match( '/^<\s*([a-z0-9:-]+)/i', $html, $matches ) ) {
		return false;
	}

	$tag_name = strtoupper( $matches[1] );
	if ( in_array( $tag_name, html_to_blocks_phrasing_tag_names(), true ) ) {
		return false;
	}

	return trim( (string) html_to_blocks_extract_balanced_element( $html, $tag_name ) ) === $html;
}

/**
 * Gets tag names treated as phrasing content by block normalization.
 *
 * @return string[] Uppercase tag names.
 */
function html_to_blocks_phrasing_tag_names(): array {
	return array(
		'A',
		'ABBR',
		'B',
		'BDI',
		'BDO',
		'BR',
		'CITE',
		'CODE',
		'DATA',
		'DFN',
		'EM',
		'I',
		'KBD',
		'MARK',
		'Q',
		'RP',
		'RT',
		'RUBY',
		'S',
		'SAMP',
		'SMALL',
		'SPAN',
		'STRONG',
		'SUB',
		'SUP',
		'TIME',
		'U',
		'VAR',
		'WBR',
	);
}

/**
 * Calculate elapsed wall time in milliseconds.
 *
 * @param float $started Started timestamp from microtime(true).
 * @return float Elapsed milliseconds.
 */
function html_to_blocks_elapsed_ms( float $started ): float {
	return ( microtime( true ) - $started ) * 1000;
}

/**
 * Accumulate per-transform trace metrics.
 *
 * @param array  $metrics Metrics accumulator.
 * @param string $name    Transform metric key.
 * @param string $field   Metric field.
 * @param float  $value   Value to add.
 * @return void
 */
function html_to_blocks_record_transform_metric( array &$metrics, string $name, string $field, float $value ): void {
	if ( ! isset( $metrics['transforms'][ $name ] ) ) {
		$metrics['transforms'][ $name ] = array(
			'count'      => 0,
			'execute_ms' => 0.0,
		);
	}

	$metrics['transforms'][ $name ][ $field ] = ( $metrics['transforms'][ $name ][ $field ] ?? 0 ) + $value;
}

/**
 * Converts HTML directly to blocks using registered transforms
 *
 * @param string $html HTML to convert
 * @param array  $args Raw handler arguments for transform context.
 * @return array Array of blocks
 */
function html_to_blocks_convert( $html, $args = array() ) {
	if ( empty( trim( $html ) ) ) {
		return array();
	}

	if ( html_to_blocks_is_standalone_hash_anchor_fragment( $html ) ) {
		$html = html_to_blocks_normalise_blocks( $html );
	}

	$collect_metrics = function_exists( 'has_action' ) && has_action( 'html_to_blocks_convert_metrics' );
	$collect_selector_provenance = ! empty( $args['collect_selector_provenance'] );
	$metrics         = null;
	$convert_started = 0.0;
	if ( $collect_metrics ) {
		$metrics         = array(
			'html_bytes'              => strlen( $html ),
			'token_count'             => 0,
			'top_level_element_count' => 0,
			'extract_ms'              => 0.0,
			'element_parse_ms'        => 0.0,
			'transform_match_ms'      => 0.0,
			'transform_execute_ms'    => 0.0,
			'content_measure_ms'      => 0.0,
			'total_ms'                => 0.0,
			'transforms'              => array(),
		);
		$convert_started = microtime( true );
	}

	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated diagnostic logging for WP_DEBUG.
			error_log( sprintf(
				'[HTML to Blocks] create_fragment() failed | HTML length: %d | Preview: %s',
				strlen( $html ),
				substr( $html, 0, 300 )
			) );
		}
		return array();
	}

	$original_html_length = strlen( $html );
	$blocks               = array();
	$transforms           = HTML_To_Blocks_Transform_Registry::get_raw_transforms();

	$body_depth                     = 2;
	$top_level_depth                = $body_depth + 1;
	$tag_occurrences                = array();
	$tag_positions                  = array();
	$ignored_decorative_html_length = 0;

	while ( $processor->next_token() ) {
		if ( $collect_metrics ) {
			++$metrics['token_count'];
		}
		$token_type = $processor->get_token_type();
		$depth      = $processor->get_current_depth();

		if ( '#text' === $token_type && $depth === $top_level_depth ) {
			$text = trim( $processor->get_modifiable_text() );
			if ( ! empty( $text ) ) {
				$blocks[] = HTML_To_Blocks_Block_Factory::create_block(
					'core/paragraph',
					array( 'content' => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) )
				);
			}
			continue;
		}

		if ( '#tag' !== $token_type ) {
			continue;
		}

		if ( $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name = $processor->get_tag();

		if ( ! isset( $tag_occurrences[ $tag_name ] ) ) {
			$tag_occurrences[ $tag_name ] = 0;
			$tag_positions[ $tag_name ]   = html_to_blocks_find_all_tag_positions( $html, $tag_name );
		}

		$occurrence = $tag_occurrences[ $tag_name ]++;

		if ( $depth !== $top_level_depth ) {
			continue;
		}
		if ( $collect_metrics ) {
			++$metrics['top_level_element_count'];
		}

		$phase_started = $collect_metrics ? microtime( true ) : 0.0;
		$element_html  = html_to_blocks_extract_element_at_occurrence( $html, $tag_name, $tag_positions[ $tag_name ], $occurrence );
		if ( $collect_metrics ) {
			$metrics['extract_ms'] += html_to_blocks_elapsed_ms( $phase_started );
		}

		if ( ! $element_html ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated diagnostic logging for WP_DEBUG.
				error_log( sprintf(
					'[HTML to Blocks] Element extraction failed | Tag: %s | Occurrence: %d | HTML preview: %s',
					$tag_name,
					$occurrence,
					substr( $html, 0, 300 )
				) );
			}
			continue;
		}

		$phase_started = $collect_metrics ? microtime( true ) : 0.0;
		$element       = HTML_To_Blocks_HTML_Element::from_html( $element_html );
		if ( $collect_metrics ) {
			$metrics['element_parse_ms'] += html_to_blocks_elapsed_ms( $phase_started );
		}
		if ( ! $element ) {
			$blocks[] = html_to_blocks_create_unsupported_html_fallback_block(
				$element_html,
				array(
					'reason'     => 'element_parse_failed',
					'tag_name'   => $tag_name,
					'occurrence' => $occurrence,
				)
			);
			continue;
		}

		if ( 'BR' === $element->get_tag_name() ) {
			$ignored_decorative_html_length += strlen( $element_html );
			continue;
		}

		if ( html_to_blocks_should_ignore_empty_decorative_placeholder( $element ) || html_to_blocks_should_ignore_decorative_wrapper( $element ) ) {
			$ignored_decorative_html_length += strlen( $element_html );
			continue;
		}

		$phase_started = $collect_metrics ? microtime( true ) : 0.0;
		$raw_transform = html_to_blocks_find_transform( $element, $transforms );
		if ( $collect_metrics ) {
			$metrics['transform_match_ms'] += html_to_blocks_elapsed_ms( $phase_started );
		}

		if ( ! $raw_transform ) {
			if ( $collect_metrics ) {
				html_to_blocks_record_transform_metric( $metrics, 'fallback:no_transform', 'count', 1 );
			}
			$block = html_to_blocks_create_unsupported_html_fallback_block(
				$element_html,
				array(
					'reason'     => 'no_transform',
					'tag_name'   => $element->get_tag_name(),
					'occurrence' => $occurrence,
				)
			);
			if ( $collect_selector_provenance ) {
				$block = html_to_blocks_attach_selector_provenance_to_block( $block, $element, array( 'blockName' => 'core/html', 'transform_kind' => 'fallback:no_transform' ), $occurrence );
			}
			$blocks[] = $block;
		} else {
			$transform_fn = $raw_transform['transform'] ?? null;
			$metric_name  = (string) ( $raw_transform['blockName'] ?? 'unknown' ) . ':p' . (string) ( $raw_transform['priority'] ?? 'default' );
			if ( $collect_metrics ) {
				html_to_blocks_record_transform_metric( $metrics, $metric_name, 'count', 1 );
			}

			if ( $transform_fn && is_callable( $transform_fn ) ) {
				$phase_started        = $collect_metrics ? microtime( true ) : 0.0;
				$raw_handler_fn       = 'html_to_blocks_raw_handler';
				$raw_handler_callback = function ( $nested_args ) use ( $args, $raw_handler_fn ) {
					$nested_args = is_array( $nested_args ) ? $nested_args : array();
					return call_user_func( $raw_handler_fn, array_merge( $args, $nested_args ) );
				};
				$block                = call_user_func( $transform_fn, $element, $raw_handler_callback, $args );
				if ( $collect_metrics ) {
					$elapsed                          = html_to_blocks_elapsed_ms( $phase_started );
					$metrics['transform_execute_ms'] += $elapsed;
					html_to_blocks_record_transform_metric( $metrics, $metric_name, 'execute_ms', $elapsed );
				}

				if ( $element->has_attribute( 'class' ) ) {
					$existing_class = $block['attrs']['className'] ?? '';
					$node_class     = $element->get_attribute( 'class' );
					$inner_html     = $block['innerHTML'] ?? '';
					if (
						! empty( $node_class )
						&& strpos( $existing_class, $node_class ) === false
						&& strpos( $inner_html, $node_class ) === false
					) {
						$block['attrs']['className'] = trim( $existing_class . ' ' . $node_class );
					}
				}

				if ( $collect_selector_provenance ) {
					$block = html_to_blocks_attach_selector_provenance_to_block( $block, $element, $raw_transform, $occurrence );
				}
				$blocks[] = $block;
			} else {
				$phase_started = $collect_metrics ? microtime( true ) : 0.0;
				$block_name    = $raw_transform['blockName'];
				$attributes    = HTML_To_Blocks_Attribute_Parser::get_block_attributes(
					$block_name,
					$element_html
				);
				$block         = HTML_To_Blocks_Block_Factory::create_block( $block_name, $attributes );
				if ( $collect_selector_provenance ) {
					$block = html_to_blocks_attach_selector_provenance_to_block( $block, $element, $raw_transform, $occurrence );
				}
				$blocks[]      = $block;
				if ( $collect_metrics ) {
					$elapsed                          = html_to_blocks_elapsed_ms( $phase_started );
					$metrics['transform_execute_ms'] += $elapsed;
					html_to_blocks_record_transform_metric( $metrics, $metric_name, 'execute_ms', $elapsed );
				}
			}
		}
	}

	// Check if processor bailed due to unsupported HTML
	$last_error = $processor->get_last_error();
	if ( null !== $last_error ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated diagnostic logging for WP_DEBUG.
			error_log( sprintf(
				'[HTML to Blocks] WP_HTML_Processor bailed | Error: %s | Blocks created: %d | HTML length: %d | Preview: %s',
				$last_error,
				count( $blocks ),
				$original_html_length,
				substr( $html, 0, 500 )
			) );
		}
	}

	if ( empty( $blocks ) && trim( wp_strip_all_tags( $html ) ) !== '' && trim( $html ) === trim( wp_strip_all_tags( $html ) ) ) {
		$blocks[] = HTML_To_Blocks_Block_Factory::create_block(
			'core/paragraph',
			array( 'content' => trim( $html ) )
		);
	}

	// Check for significant content loss (input had content but output is empty/minimal)
	$phase_started         = $collect_metrics ? microtime( true ) : 0.0;
	$output_content_length = html_to_blocks_measure_block_content_length( $blocks );
	if ( $collect_metrics ) {
		$metrics['content_measure_ms'] += html_to_blocks_elapsed_ms( $phase_started );
		$metrics['total_ms']            = html_to_blocks_elapsed_ms( $convert_started );
		do_action( 'html_to_blocks_convert_metrics', $metrics, $args );
	}

	$diagnostic_html_length = max( 0, $original_html_length - $ignored_decorative_html_length );

	if ( $diagnostic_html_length > 100 && $output_content_length < ( $diagnostic_html_length * 0.1 ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated diagnostic logging for WP_DEBUG.
			error_log( sprintf(
				'[HTML to Blocks] Significant content loss detected | Input: %d chars | Output: %d chars | Blocks: %d | Processor error: %s | Preview: %s',
				$diagnostic_html_length,
				$output_content_length,
				count( $blocks ),
				$last_error ?? 'none',
				substr( $html, 0, 500 )
			) );
		}
	}

	return $blocks;
}

/**
 * Convert one HTML fragment and return a stable result envelope for compilers.
 *
 * `html_to_blocks_raw_handler()` remains the low-level Gutenberg-compatible
 * block-array API. This wrapper gives artifact compilers one place to collect
 * serialized markup, fallback diagnostics, metrics, and source references
 * without wiring global listeners around every conversion call.
 *
 * @param string $html Source HTML fragment.
 * @param array  $args Conversion context passed through to the raw handler.
	 * @return array{block_markup:string,blocks:array<int|string,array<string,mixed>>,selector_provenance:array<int,array<string,mixed>>,diagnostics:array<int,array<string,mixed>>,fallbacks:array<int,array<string,mixed>>,asset_references:array<int,array<string,mixed>>,svg_artifacts:array<int,array<string,mixed>>,navigation_candidates:array<int,array<string,mixed>>,visual_repair_metadata:array<string,mixed>,metrics:array<string,mixed>,source:array<string,mixed>}
 */
function html_to_blocks_convert_fragment( string $html, array $args = array() ): array {
	$args = array_merge(
		$args,
		array(
			'HTML'                        => $html,
			'collect_selector_provenance' => true,
		)
	);

	$fallbacks = array();
	$metrics   = array();

	$fallback_listener = static function ( string $element_html, array $context, array $block ) use ( &$fallbacks ): void {
		$fallbacks[] = array(
			'type'         => 'unsupported_html_fallback',
			'element_html' => $element_html,
			'context'      => $context,
			'block_name'   => (string) ( $block['blockName'] ?? '' ),
		);
	};

	$metrics_listener = static function ( array $event_metrics ) use ( &$metrics ): void {
		$metrics = $event_metrics;
	};

	$can_listen = function_exists( 'add_action' ) && function_exists( 'remove_action' );
	if ( $can_listen ) {
		add_action( 'html_to_blocks_unsupported_html_fallback', $fallback_listener, 10, 3 );
		add_action( 'html_to_blocks_convert_metrics', $metrics_listener, 10, 1 );
	}

	try {
		$blocks = html_to_blocks_raw_handler( $args );
	} finally {
		if ( $can_listen ) {
			remove_action( 'html_to_blocks_unsupported_html_fallback', $fallback_listener, 10 );
			remove_action( 'html_to_blocks_convert_metrics', $metrics_listener, 10 );
		}
	}

	$visual_repair_metadata = html_to_blocks_collect_visual_repair_metadata( $html, $blocks, $fallbacks );

	return array(
		'block_markup'          => html_to_blocks_serialize_block_markup( $blocks ),
		'blocks'                => $blocks,
		'selector_provenance'   => html_to_blocks_collect_selector_provenance_from_blocks( $blocks ),
		'diagnostics'           => html_to_blocks_fallbacks_to_diagnostics( $fallbacks ),
		'fallbacks'             => $fallbacks,
		'asset_references'      => html_to_blocks_collect_asset_references( $html ),
		'svg_artifacts'         => html_to_blocks_collect_svg_artifacts( $blocks ),
		'navigation_candidates' => html_to_blocks_collect_navigation_candidates( $html ),
		'visual_repair_metadata' => $visual_repair_metadata,
		'metrics'               => $metrics,
		'source'                => array(
			'bytes'       => strlen( $html ),
			'text_length' => strlen( trim( wp_strip_all_tags( $html ) ) ),
			'context'     => isset( $args['context'] ) && is_scalar( $args['context'] ) ? (string) $args['context'] : '',
		),
	);
}

/**
 * Collect fragment-local visual repair metadata from converted block output.
 *
 * This metadata is intentionally materializer-neutral: it reports converted
 * block wrappers and classes that compiler layers can use when assembling
 * source CSS repair artifacts without re-inferring fragment conversion facts.
 *
 * @param string                                $html      Source HTML fragment.
 * @param array<int|string,array<string,mixed>> $blocks    Converted block arrays.
 * @param array<int,array<string,mixed>>        $fallbacks Fallback observations.
 * @return array<string,mixed> Visual repair metadata.
 */
function html_to_blocks_collect_visual_repair_metadata( string $html, array $blocks, array $fallbacks = array() ): array {
	$metadata = array(
		'schema'     => 'html-to-blocks-converter/visual-repair-metadata/v1',
		'version'    => 1,
		'wrapper_classes' => array(),
		'mappings'   => array(
			'images'     => array(),
			'forms'      => array(),
			'navigation' => array(),
			'buttons'    => array(),
		),
		'markers'    => array(
			'fallback_blocks'    => array(),
			'decorative_sources' => array(),
		),
		'diagnostics' => array(),
		'categories' => array(
			'groups'      => array(),
			'images'      => array(),
			'forms'       => array(),
			'navigation'  => array(),
			'buttons'     => array(),
			'decorative'  => array(),
			'fallbacks'   => array(),
		),
	);

	$seen = array();
	$walk = static function ( array $items, array $path = array() ) use ( &$walk, &$metadata, &$seen ): void {
		foreach ( $items as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_path = array_merge( $path, array( (int) $index ) );
			$record     = html_to_blocks_visual_repair_record_from_block( $block, $block_path );
			foreach ( html_to_blocks_visual_repair_categories_for_record( $record ) as $category ) {
				$key = $category . ':' . (string) $record['path'] . ':' . (string) $record['block_name'] . ':' . (string) $record['class_name'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$metadata['categories'][ $category ][] = $record;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$walk( $block['innerBlocks'], $block_path );
			}
		}
	};

	$walk( $blocks );
	$metadata['wrapper_classes'] = html_to_blocks_visual_repair_wrapper_classes_from_categories( $metadata['categories'] );
	$metadata['mappings']        = html_to_blocks_visual_repair_source_mappings( $html, $metadata['categories'] );
	$metadata['markers']         = array(
		'fallback_blocks'    => html_to_blocks_visual_repair_fallback_markers( $metadata['categories']['fallbacks'], $fallbacks ),
		'decorative_sources' => html_to_blocks_visual_repair_decorative_source_markers( $html, $metadata['categories']['decorative'] ),
	);
	$metadata['diagnostics']     = html_to_blocks_visual_repair_diagnostics( $metadata );

	return $metadata;
}

/**
 * Extract wrapper class records from categorized repair records.
 *
 * @param array<string,array<int,array<string,mixed>>> $categories Metadata categories.
 * @return array<int,array<string,mixed>> Wrapper class records.
 */
function html_to_blocks_visual_repair_wrapper_classes_from_categories( array $categories ): array {
	$records = array();
	$seen    = array();

	foreach ( $categories as $items ) {
		foreach ( $items as $item ) {
			$class_name = isset( $item['class_name'] ) ? trim( (string) $item['class_name'] ) : '';
			if ( '' === $class_name ) {
				continue;
			}

			$key = (string) ( $item['path'] ?? '' ) . ':' . (string) ( $item['block_name'] ?? '' ) . ':' . $class_name;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$records[]    = array(
				'path'       => (string) ( $item['path'] ?? '' ),
				'block_name' => (string) ( $item['block_name'] ?? '' ),
				'tag_name'   => (string) ( $item['tag_name'] ?? '' ),
				'class_name' => $class_name,
				'classes'    => html_to_blocks_split_class_names( $class_name ),
			);
		}
	}

	return $records;
}

/**
 * Collect materializer-neutral source mappings for visual repair consumers.
 *
 * @param string                                      $html       Source HTML fragment.
 * @param array<string,array<int,array<string,mixed>>> $categories Metadata categories.
 * @return array<string,array<int,array<string,mixed>>> Source mappings.
 */
function html_to_blocks_visual_repair_source_mappings( string $html, array $categories ): array {
	return array(
		'images'     => html_to_blocks_visual_repair_image_mappings( $html, $categories['images'] ?? array() ),
		'forms'      => html_to_blocks_visual_repair_element_mappings( $html, 'form', $categories['forms'] ?? array() ),
		'navigation' => html_to_blocks_visual_repair_element_mappings( $html, 'nav', $categories['navigation'] ?? array() ),
		'buttons'    => html_to_blocks_visual_repair_button_mappings( $html, $categories['buttons'] ?? array() ),
	);
}

/**
 * Collect source-to-block image mappings.
 *
 * @param string $html          Source HTML fragment.
 * @param array  $image_records Converted image records.
 * @return array<int,array<string,mixed>> Image mappings.
 */
function html_to_blocks_visual_repair_image_mappings( string $html, array $image_records ): array {
	$sources = array();
	if ( preg_match_all( '/<img\b([^>]*)>/i', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $index => $match ) {
			$attrs     = html_to_blocks_parse_html_attribute_string( (string) $match[1] );
			$sources[] = array(
				'source'     => 'img[' . $index . ']',
				'url'        => (string) ( $attrs['src'] ?? '' ),
				'alt'        => (string) ( $attrs['alt'] ?? '' ),
				'class_name' => (string) ( $attrs['class'] ?? '' ),
				'attrs'      => $attrs,
			);
		}
	}

	$mappings = array();
	foreach ( $image_records as $record ) {
		$source = html_to_blocks_visual_repair_find_source_by_url( $sources, (string) ( $record['url'] ?? '' ) );
		$mappings[] = array(
			'path'        => (string) ( $record['path'] ?? '' ),
			'block_name'  => (string) ( $record['block_name'] ?? '' ),
			'source'      => (string) ( $source['source'] ?? '' ),
			'source_url'  => (string) ( $source['url'] ?? $record['url'] ?? '' ),
			'block_url'   => (string) ( $record['url'] ?? '' ),
			'alt'         => (string) ( $source['alt'] ?? '' ),
			'class_name'  => (string) ( $source['class_name'] ?? $record['class_name'] ?? '' ),
		);
	}

	return $mappings;
}

/**
 * Collect generic element source mappings for forms and nav fragments.
 *
 * @param string $html    Source HTML fragment.
 * @param string $tag     Lowercase element tag.
 * @param array  $records Converted/fallback records in this category.
 * @return array<int,array<string,mixed>> Element mappings.
 */
function html_to_blocks_visual_repair_element_mappings( string $html, string $tag, array $records ): array {
	$mappings = array();
	$pattern  = '/<' . preg_quote( $tag, '/' ) . '\b([^>]*)>(.*?)<\/' . preg_quote( $tag, '/' ) . '>/is';

	if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
		return $mappings;
	}

	foreach ( $matches as $index => $match ) {
		$attrs  = html_to_blocks_parse_html_attribute_string( (string) $match[1] );
		$record = $records[ $index ] ?? $records[0] ?? array();
		$item   = array(
			'source'      => $tag . '[' . $index . ']',
			'path'        => (string) ( $record['path'] ?? '' ),
			'block_name'  => (string) ( $record['block_name'] ?? '' ),
			'tag_name'    => $tag,
			'class_name'  => (string) ( $attrs['class'] ?? $record['class_name'] ?? '' ),
			'repair_hint' => 'preserve_or_replace_' . $tag . '_html',
		);

		if ( 'nav' === $tag ) {
			$item['label'] = (string) ( $attrs['aria-label'] ?? '' );
			$item['links'] = html_to_blocks_collect_anchor_links( (string) $match[2] );
		} elseif ( 'form' === $tag ) {
			$item['action']        = (string) ( $attrs['action'] ?? '' );
			$item['method']        = strtolower( (string) ( $attrs['method'] ?? '' ) );
			$item['control_count'] = preg_match_all( '/<(?:input|textarea|select|button)\b/i', (string) $match[2] );
		}

		$mappings[] = $item;
	}

	return $mappings;
}

/**
 * Collect button-like source mappings.
 *
 * @param string $html           Source HTML fragment.
 * @param array  $button_records Converted button records.
 * @return array<int,array<string,mixed>> Button mappings.
 */
function html_to_blocks_visual_repair_button_mappings( string $html, array $button_records ): array {
	$mappings = array();
	$sources  = array();

	if ( preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $index => $match ) {
			$attrs      = html_to_blocks_parse_html_attribute_string( (string) $match[1] );
			$class_name = (string) ( $attrs['class'] ?? '' );
			$role       = strtolower( (string) ( $attrs['role'] ?? '' ) );
			if ( '' === $class_name && 'button' !== $role ) {
				continue;
			}

			$sources[] = array(
				'source'     => 'a[' . $index . ']',
				'tag_name'   => 'a',
				'url'        => (string) ( $attrs['href'] ?? '' ),
				'label'      => trim( wp_strip_all_tags( (string) $match[2] ) ),
				'class_name' => $class_name,
			);
		}
	}

	if ( preg_match_all( '/<button\b([^>]*)>(.*?)<\/button>/is', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $index => $match ) {
			$attrs     = html_to_blocks_parse_html_attribute_string( (string) $match[1] );
			$sources[] = array(
				'source'     => 'button[' . $index . ']',
				'tag_name'   => 'button',
				'url'        => '',
				'label'      => trim( wp_strip_all_tags( (string) $match[2] ) ),
				'class_name' => (string) ( $attrs['class'] ?? '' ),
			);
		}
	}

	foreach ( $sources as $index => $source ) {
		$record     = $button_records[ $index ] ?? array();
		$mappings[] = array(
			'source'      => (string) $source['source'],
			'path'        => (string) ( $record['path'] ?? '' ),
			'block_name'  => (string) ( $record['block_name'] ?? '' ),
			'tag_name'    => (string) $source['tag_name'],
			'source_url'  => (string) $source['url'],
			'block_url'   => (string) ( $record['url'] ?? '' ),
			'label'       => (string) $source['label'],
			'class_name'  => (string) $source['class_name'],
			'repair_hint' => 'preserve_button_visual_mapping',
		);
	}

	return $mappings;
}

/**
 * Convert fallback category records into explicit fallback markers.
 *
 * @param array<int,array<string,mixed>> $fallback_records Fallback records.
 * @param array<int,array<string,mixed>> $fallbacks        Fallback observations.
 * @return array<int,array<string,mixed>> Fallback markers.
 */
function html_to_blocks_visual_repair_fallback_markers( array $fallback_records, array $fallbacks ): array {
	$markers = array();
	foreach ( $fallback_records as $index => $record ) {
		$context   = isset( $fallbacks[ $index ]['context'] ) && is_array( $fallbacks[ $index ]['context'] ) ? $fallbacks[ $index ]['context'] : array();
		$markers[] = array(
			'path'      => (string) ( $record['path'] ?? '' ),
			'block_name' => (string) ( $record['block_name'] ?? 'core/html' ),
			'tag_name'  => (string) ( $record['tag_name'] ?? $context['tag_name'] ?? '' ),
			'class_name' => (string) ( $record['class_name'] ?? '' ),
			'reason'    => (string) ( $context['reason'] ?? 'preserved_html' ),
			'is_lossless' => true,
		);
	}

	return $markers;
}

/**
 * Collect decorative source markers from ignored placeholders and decorative records.
 *
 * @param string $html               Source HTML fragment.
 * @param array  $decorative_records Converted decorative records.
 * @return array<int,array<string,mixed>> Decorative markers.
 */
function html_to_blocks_visual_repair_decorative_source_markers( string $html, array $decorative_records ): array {
	$markers = array();
	if ( preg_match_all( '/<(div|span)\b([^>]*)>(.*?)<\/\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $index => $match ) {
			$element = HTML_To_Blocks_HTML_Element::from_html( (string) $match[0] );
			if ( ! $element || ! html_to_blocks_should_ignore_empty_decorative_placeholder( $element ) ) {
				continue;
			}

			$attrs     = html_to_blocks_parse_html_attribute_string( (string) $match[2] );
			$markers[] = array(
				'source'      => strtolower( (string) $match[1] ) . '[' . $index . ']',
				'path'        => '',
				'tag_name'    => strtolower( (string) $match[1] ),
				'class_name'  => (string) ( $attrs['class'] ?? '' ),
				'repair_hint' => 'decorative_source_ignored',
			);
		}
	}

	foreach ( $decorative_records as $record ) {
		$markers[] = array(
			'source'      => 'block',
			'path'        => (string) ( $record['path'] ?? '' ),
			'block_name'  => (string) ( $record['block_name'] ?? '' ),
			'tag_name'    => (string) ( $record['tag_name'] ?? '' ),
			'class_name'  => (string) ( $record['class_name'] ?? '' ),
			'repair_hint' => 'decorative_block_preserved',
		);
	}

	return $markers;
}

/**
 * Build diagnostics scoped to visual repair metadata.
 *
 * @param array<string,mixed> $metadata Visual repair metadata.
 * @return array<int,array<string,mixed>> Diagnostics.
 */
function html_to_blocks_visual_repair_diagnostics( array $metadata ): array {
	$diagnostics = array();

	foreach ( $metadata['markers']['fallback_blocks'] ?? array() as $marker ) {
		$diagnostics[] = array(
			'code'       => 'visual_repair.fallback_block',
			'severity'   => 'info',
			'message'    => 'A source fragment was preserved as a lossless HTML fallback for downstream repair.',
			'path'       => (string) ( $marker['path'] ?? '' ),
			'context'    => array(
				'reason'   => (string) ( $marker['reason'] ?? '' ),
				'tag_name' => (string) ( $marker['tag_name'] ?? '' ),
			),
		);
	}

	foreach ( $metadata['markers']['decorative_sources'] ?? array() as $marker ) {
		$diagnostics[] = array(
			'code'       => 'visual_repair.decorative_marker',
			'severity'   => 'info',
			'message'    => 'Decorative source or block chrome was identified during fragment conversion.',
			'path'       => (string) ( $marker['path'] ?? '' ),
			'context'    => array(
				'repair_hint' => (string) ( $marker['repair_hint'] ?? '' ),
				'class_name'  => (string) ( $marker['class_name'] ?? '' ),
			),
		);
	}

	foreach ( array( 'forms', 'navigation' ) as $category ) {
		foreach ( $metadata['mappings'][ $category ] ?? array() as $mapping ) {
			$diagnostics[] = array(
				'code'       => 'visual_repair.' . $category . '_candidate',
				'severity'   => 'info',
				'message'    => 'Source ' . $category . ' markup is available for downstream materialization or preservation.',
				'path'       => (string) ( $mapping['path'] ?? '' ),
				'context'    => array(
					'source'      => (string) ( $mapping['source'] ?? '' ),
					'repair_hint' => (string) ( $mapping['repair_hint'] ?? '' ),
				),
			);
		}
	}

	return $diagnostics;
}

/**
 * Find source metadata by URL.
 *
 * @param array<int,array<string,mixed>> $sources Source records.
 * @param string                         $url     URL to match.
 * @return array<string,mixed>|null Matching source record.
 */
function html_to_blocks_visual_repair_find_source_by_url( array $sources, string $url ): ?array {
	foreach ( $sources as $source ) {
		if ( '' !== $url && isset( $source['url'] ) && (string) $source['url'] === $url ) {
			return $source;
		}
	}

	return $sources[0] ?? null;
}

/**
 * Build one normalized visual repair record from a block.
 *
 * @param array<string,mixed> $block Block array.
 * @param array<int,int>      $path  Zero-based block tree path.
 * @return array<string,mixed> Record.
 */
function html_to_blocks_visual_repair_record_from_block( array $block, array $path ): array {
	$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	$class_name = isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) ? trim( (string) $attrs['className'] ) : '';
	$inner_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
	$tag_name   = isset( $attrs['tagName'] ) && is_scalar( $attrs['tagName'] ) ? strtolower( (string) $attrs['tagName'] ) : '';
	if ( 'core/html' === (string) ( $block['blockName'] ?? '' ) ) {
		$fallback = html_to_blocks_first_element_summary_from_html( $inner_html );
		if ( '' === $tag_name ) {
			$tag_name = $fallback['tag_name'];
		}
		if ( '' === $class_name ) {
			$class_name = $fallback['class_name'];
		}
	}

	return array(
		'path'       => implode( '.', array_map( 'strval', $path ) ),
		'block_name' => isset( $block['blockName'] ) && is_scalar( $block['blockName'] ) ? (string) $block['blockName'] : '',
		'class_name' => $class_name,
		'classes'    => html_to_blocks_split_class_names( $class_name ),
		'tag_name'   => $tag_name,
		'url'        => isset( $attrs['url'] ) && is_scalar( $attrs['url'] ) ? (string) $attrs['url'] : '',
		'has_inner_blocks' => ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ),
		'inner_html_bytes' => strlen( $inner_html ),
	);
}

/**
 * Extract first-element tag and class data from fallback HTML.
 *
 * @param string $html HTML fragment.
 * @return array{tag_name:string,class_name:string}
 */
function html_to_blocks_first_element_summary_from_html( string $html ): array {
	$summary = array(
		'tag_name'   => '',
		'class_name' => '',
	);

	if ( ! preg_match( '/<\s*([a-z0-9:-]+)\b([^>]*)>/i', $html, $match ) ) {
		return $summary;
	}

	$summary['tag_name'] = strtolower( (string) $match[1] );
	$attrs               = html_to_blocks_parse_html_attribute_string( (string) $match[2] );
	$summary['class_name'] = isset( $attrs['class'] ) ? (string) $attrs['class'] : '';

	return $summary;
}

/**
 * Determine visual repair categories for a normalized record.
 *
 * @param array<string,mixed> $record Visual repair record.
 * @return array<int,string> Categories.
 */
function html_to_blocks_visual_repair_categories_for_record( array $record ): array {
	$categories = array();
	$block_name = (string) ( $record['block_name'] ?? '' );
	$tag_name   = (string) ( $record['tag_name'] ?? '' );
	$class_name = strtolower( (string) ( $record['class_name'] ?? '' ) );

	if ( 'core/group' === $block_name ) {
		$categories[] = 'groups';
	}
	if ( 'core/image' === $block_name ) {
		$categories[] = 'images';
	}
	if ( in_array( $block_name, array( 'core/button', 'core/buttons' ), true ) || preg_match( '/(?:^|[-_\s])(btn|button|cta|action)(?:$|[-_\s])/', $class_name ) ) {
		$categories[] = 'buttons';
	}
	if ( 'nav' === $tag_name || preg_match( '/(?:^|[-_\s])(nav|navbar|navigation|menu)(?:$|[-_\s])/', $class_name ) ) {
		$categories[] = 'navigation';
	}
	if ( 'form' === $tag_name || preg_match( '/(?:^|[-_\s])(form|field|input|search|newsletter|subscribe)(?:$|[-_\s])/', $class_name ) ) {
		$categories[] = 'forms';
	}
	if ( 'core/html' === $block_name ) {
		$categories[] = 'fallbacks';
	}
	if ( html_to_blocks_visual_repair_record_is_decorative( $record ) ) {
		$categories[] = 'decorative';
	}

	return array_values( array_unique( $categories ) );
}

/**
 * Check whether a visual repair record describes decorative chrome.
 *
 * @param array<string,mixed> $record Visual repair record.
 * @return bool Whether the record is decorative.
 */
function html_to_blocks_visual_repair_record_is_decorative( array $record ): bool {
	$class_name = strtolower( (string) ( $record['class_name'] ?? '' ) );
	if ( preg_match( '/(?:^|[-_\s])(accent|bar|bg|blob|chrome|decor|decorative|dot|fill|glow|halo|icon|line|orb|shape|spark|visual)(?:$|[-_\s])/', $class_name ) ) {
		return true;
	}

	return 'core/group' === (string) ( $record['block_name'] ?? '' )
		&& empty( $record['has_inner_blocks'] )
		&& 0 === (int) ( $record['inner_html_bytes'] ?? 0 );
}

/**
 * Split a class attribute into stable tokens.
 *
 * @param string $class_name Class attribute.
 * @return array<int,string> Class tokens.
 */
function html_to_blocks_split_class_names( string $class_name ): array {
	$classes = preg_split( '/\s+/', trim( $class_name ) );
	if ( ! is_array( $classes ) ) {
		return array();
	}

	return array_values( array_unique( array_filter( $classes, static fn ( string $class ): bool => '' !== $class ) ) );
}

/**
 * Attach materializer-neutral source selector provenance to a generated block.
 *
 * @param array                       $block         Generated block array.
 * @param HTML_To_Blocks_HTML_Element $element       Source element.
 * @param array<string,mixed>         $raw_transform Matched transform definition.
 * @param int                         $occurrence    Source tag occurrence in the current fragment.
 * @return array Block with non-serialized provenance metadata.
 */
function html_to_blocks_attach_selector_provenance_to_block( array $block, $element, array $raw_transform, int $occurrence ): array {
	$provenance_element = $element;
	if ( 'core/image' === ( $block['blockName'] ?? '' ) && 'IMG' !== $element->get_tag_name() ) {
		$image = $element->query_selector( 'img' );
		if ( $image ) {
			$provenance_element = $image;
		}
	}

	$block['sourceSelectorProvenance'] = html_to_blocks_build_selector_provenance_entry( $provenance_element, $block, $raw_transform, $occurrence );

	if ( 'core/buttons' === ( $block['blockName'] ?? '' ) && ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
		$anchors = html_to_blocks_direct_source_elements_from_html( $element->get_inner_html(), 'a' );
		foreach ( $block['innerBlocks'] as $index => $inner_block ) {
			if ( 'core/button' !== ( $inner_block['blockName'] ?? '' ) || empty( $anchors[ $index ] ) ) {
				continue;
			}

			$block['innerBlocks'][ $index ]['sourceSelectorProvenance'] = html_to_blocks_build_selector_provenance_entry(
				$anchors[ $index ],
				$inner_block,
				array(
					'blockName'       => 'core/button',
					'transform_kind'  => 'button-anchor',
					'parentBlockName' => 'core/buttons',
				),
				$index
			);
		}
	}

	if ( 'core/gallery' === ( $block['blockName'] ?? '' ) && ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
		$images = $element->query_selector_all( 'img' );
		foreach ( $block['innerBlocks'] as $index => $inner_block ) {
			if ( 'core/image' !== ( $inner_block['blockName'] ?? '' ) || empty( $images[ $index ] ) ) {
				continue;
			}

			$block['innerBlocks'][ $index ]['sourceSelectorProvenance'] = html_to_blocks_build_selector_provenance_entry(
				$images[ $index ],
				$inner_block,
				array(
					'blockName'       => 'core/image',
					'transform_kind'  => 'gallery-image',
					'parentBlockName' => 'core/gallery',
				),
				$index
			);
		}
	}

	return $block;
}

/**
 * Build one source-to-block selector provenance record.
 *
 * @param HTML_To_Blocks_HTML_Element $element       Source element.
 * @param array<string,mixed>         $block         Generated block array.
 * @param array<string,mixed>         $raw_transform Matched transform definition.
 * @param int                         $occurrence    Source tag occurrence in the current fragment.
 * @return array<string,mixed> Provenance entry.
 */
function html_to_blocks_build_selector_provenance_entry( $element, array $block, array $raw_transform, int $occurrence ): array {
	$tag        = strtolower( $element->get_tag_name() );
	$id         = trim( (string) ( $element->get_attribute( 'id' ) ?? '' ) );
	$class_name = trim( (string) ( $element->get_attribute( 'class' ) ?? '' ) );
	$classes    = '' === $class_name ? array() : preg_split( '/\s+/', $class_name );
	$classes    = is_array( $classes ) ? array_values( array_filter( $classes, 'strlen' ) ) : array();

	$entry = array(
		'source'          => array(
			'tag'                  => $tag,
			'id'                   => $id,
			'classes'              => $classes,
			'class_name'           => $class_name,
			'selector'             => html_to_blocks_source_element_selector( $tag, $id, $classes ),
			'stable_selector_path' => html_to_blocks_source_element_selector_path( $tag, $id, $classes, $occurrence ),
			'occurrence'           => $occurrence,
		),
		'transform'       => array(
			'kind'       => isset( $raw_transform['transform_kind'] ) && is_scalar( $raw_transform['transform_kind'] ) ? (string) $raw_transform['transform_kind'] : 'raw_transform',
			'block_type' => (string) ( $raw_transform['blockName'] ?? ( $block['blockName'] ?? '' ) ),
		),
		'generated_block' => array(
			'type'    => (string) ( $block['blockName'] ?? '' ),
			'targets' => html_to_blocks_generated_selector_targets( $block ),
		),
	);

	if ( isset( $raw_transform['parentBlockName'] ) && is_scalar( $raw_transform['parentBlockName'] ) ) {
		$entry['generated_block']['parent_type'] = (string) $raw_transform['parentBlockName'];
	}

	return $entry;
}

/**
 * Build a compact source selector from element identity.
 *
 * @param string       $tag     Source tag.
 * @param string       $id      Source id.
 * @param array<int,string> $classes Source classes.
 * @return string Selector.
 */
function html_to_blocks_source_element_selector( string $tag, string $id, array $classes ): string {
	if ( '' !== $id && preg_match( '/^[A-Za-z_][-A-Za-z0-9_:.]*$/', $id ) ) {
		return '#' . $id;
	}

	$selector = $tag;
	foreach ( $classes as $class ) {
		if ( preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $class ) ) {
			$selector .= '.' . $class;
		}
	}

	return $selector;
}

/**
 * Build a stable selector path fallback for one source element.
 *
 * @param string            $tag        Source tag.
 * @param string            $id         Source id.
 * @param array<int,string> $classes    Source classes.
 * @param int               $occurrence Source tag occurrence.
 * @return string Stable selector path.
 */
function html_to_blocks_source_element_selector_path( string $tag, string $id, array $classes, int $occurrence ): string {
	$selector = html_to_blocks_source_element_selector( $tag, $id, $classes );
	if ( '' !== $id ) {
		return $selector;
	}

	return $selector . ':nth-of-type(' . ( $occurrence + 1 ) . ')';
}

/**
 * Return generated rendered target hints for known core block DOM shapes.
 *
 * @param array<string,mixed> $block Generated block.
 * @return array<int,array{name:string,selector:string}> Generated target hints.
 */
function html_to_blocks_generated_selector_targets( array $block ): array {
	$block_name = (string) ( $block['blockName'] ?? '' );
	switch ( $block_name ) {
		case 'core/group':
			return array( array( 'name' => 'group-wrapper', 'selector' => '.wp-block-group' ) );
		case 'core/image':
			return array(
				array( 'name' => 'image-wrapper', 'selector' => '.wp-block-image' ),
				array( 'name' => 'image-img', 'selector' => '.wp-block-image img' ),
			);
		case 'core/buttons':
			return array( array( 'name' => 'buttons-wrapper', 'selector' => '.wp-block-buttons' ) );
		case 'core/button':
			return array(
				array( 'name' => 'button-wrapper', 'selector' => '.wp-block-button' ),
				array( 'name' => 'button-link', 'selector' => '.wp-block-button__link' ),
			);
		case 'core/html':
			$content = (string) ( $block['attrs']['content'] ?? $block['innerHTML'] ?? '' );
			if ( preg_match( '/<\s*(?:form|label|input|select|textarea|button)\b/i', $content ) ) {
				return array(
					array( 'name' => 'html-fallback', 'selector' => '.wp-block-html' ),
					array( 'name' => 'form-field', 'selector' => 'label' ),
					array( 'name' => 'form-control', 'selector' => 'input, select, textarea, button' ),
				);
			}
			return array( array( 'name' => 'html-fallback', 'selector' => '.wp-block-html' ) );
	}

	return array();
}

/**
 * Collect direct child source elements from an HTML fragment.
 *
 * @param string $html Source HTML.
 * @param string $tag  Lowercase tag to collect.
 * @return array<int,HTML_To_Blocks_HTML_Element> Elements.
 */
function html_to_blocks_direct_source_elements_from_html( string $html, string $tag ): array {
	$wrapper = HTML_To_Blocks_HTML_Element::from_html( '<div>' . $html . '</div>' );
	if ( ! $wrapper ) {
		return array();
	}

	return array_values(
		array_filter(
			$wrapper->get_child_elements(),
			static function ( $element ) use ( $tag ): bool {
				return strtolower( $element->get_tag_name() ) === strtolower( $tag );
			}
		)
	);
}

/**
 * Collect normalized selector provenance from a block tree.
 *
 * @param array<int|string,array<string,mixed>> $blocks Block tree.
 * @return array<int,array<string,mixed>> Provenance entries.
 */
function html_to_blocks_collect_selector_provenance_from_blocks( array $blocks ): array {
	$provenance = array();
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		if ( isset( $block['sourceSelectorProvenance'] ) && is_array( $block['sourceSelectorProvenance'] ) ) {
			$provenance[] = $block['sourceSelectorProvenance'];
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$provenance = array_merge( $provenance, html_to_blocks_collect_selector_provenance_from_blocks( $block['innerBlocks'] ) );
		}
	}

	return $provenance;
}

/**
 * Serialize block arrays for the result API.
 *
 * @param array<int|string,array<string,mixed>> $blocks Block arrays.
 * @return string Serialized block markup.
 */
function html_to_blocks_serialize_block_markup( array $blocks ): string {
	if ( function_exists( 'serialize_blocks' ) ) {
		return serialize_blocks( $blocks );
	}

	return '';
}

/**
 * Normalize fallback observations into compiler-friendly diagnostics.
 *
 * @param array<int,array<string,mixed>> $fallbacks Fallback events.
 * @return array<int,array<string,mixed>> Diagnostics.
 */
function html_to_blocks_fallbacks_to_diagnostics( array $fallbacks ): array {
	$diagnostics = array();
	foreach ( $fallbacks as $fallback ) {
		$context       = isset( $fallback['context'] ) && is_array( $fallback['context'] ) ? $fallback['context'] : array();
		$element_html  = isset( $fallback['element_html'] ) && is_string( $fallback['element_html'] ) ? $fallback['element_html'] : '';
		$is_svg        = ( $context['tag_name'] ?? '' ) === 'SVG' || preg_match( '/^\s*<svg\b/i', $element_html ) === 1;
		$safety_reason = '';
		if ( $is_svg && class_exists( 'HTML_To_Blocks_SVG_Icon_Classifier', false ) ) {
			$classification = HTML_To_Blocks_SVG_Icon_Classifier::classify( $element_html );
			$safety_reason  = isset( $classification['reason'] ) && is_scalar( $classification['reason'] ) ? (string) $classification['reason'] : '';
			if ( '' !== $safety_reason ) {
				$context['safety_reason'] = $safety_reason;
			}
		}

		$diagnostics[] = array(
			'code'     => $is_svg ? 'unsafe_inline_svg' : 'unsupported_html_fallback',
			'severity' => 'warning',
			'message'  => $is_svg ? 'Inline SVG was preserved as core/html because it did not pass conservative safe SVG validation.' : 'Source HTML fragment was preserved as core/html because no safe native block transform matched.',
			'context'  => $context,
		);
	}

	return $diagnostics;
}

/**
 * Collect safe inline SVG placeholders as materializer-neutral artifacts.
 *
 * @param array<int|string,array<string,mixed>> $blocks Block arrays.
 * @param string                               $path   Current block tree path.
 * @return array<int,array<string,mixed>> SVG artifacts.
 */
function html_to_blocks_collect_svg_artifacts( array $blocks, string $path = '' ): array {
	$artifacts = array();

	foreach ( $blocks as $index => $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$block_path = '' === $path ? (string) $index : $path . '.' . (string) $index;
		if ( ( $block['blockName'] ?? '' ) === 'html-to-blocks/svg-icon' ) {
			$svg      = isset( $block['attrs']['svg'] ) && is_string( $block['attrs']['svg'] ) ? $block['attrs']['svg'] : '';
			$metadata = isset( $block['attrs']['metadata'] ) && is_array( $block['attrs']['metadata'] ) ? $block['attrs']['metadata'] : array();
			if ( '' !== $svg ) {
				$artifacts[] = array(
					'type'         => 'safe_inline_svg',
					'kind'         => isset( $metadata['kind'] ) && is_scalar( $metadata['kind'] ) ? (string) $metadata['kind'] : 'inline-svg',
					'content_hash' => sha1( $svg ),
					'svg'          => $svg,
					'metadata'     => $metadata,
					'block_path'   => $block_path,
				);
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$artifacts = array_merge( $artifacts, html_to_blocks_collect_svg_artifacts( $block['innerBlocks'], $block_path ) );
		}
	}

	return $artifacts;
}

/**
 * Collect obvious source asset references for downstream materializers.
 *
 * @param string $html Source HTML.
 * @return array<int,array<string,mixed>> Asset references.
 */
function html_to_blocks_collect_asset_references( string $html ): array {
	$references = array();
	$seen       = array();

	foreach ( array( 'src', 'href', 'poster' ) as $attribute ) {
		if ( ! preg_match_all( '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $matches, PREG_SET_ORDER ) ) {
			continue;
		}

		foreach ( $matches as $match ) {
			$url = html_entity_decode( (string) ( $match[2] ?? $match[3] ?? $match[4] ?? '' ), ENT_QUOTES, 'UTF-8' );
			if ( ! html_to_blocks_is_asset_reference_url( $url ) ) {
				continue;
			}

			$key = $attribute . ':' . $url;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ]  = true;
			$references[] = array(
				'attribute' => $attribute,
				'url'       => $url,
			);
		}
	}

	if ( preg_match_all( '/\bsrcset\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$srcset = html_entity_decode( (string) ( $match[2] ?? $match[3] ?? '' ), ENT_QUOTES, 'UTF-8' );
			foreach ( explode( ',', $srcset ) as $candidate ) {
				$parts = preg_split( '/\s+/', trim( $candidate ) );
				$url   = (string) ( $parts[0] ?? '' );
				if ( ! html_to_blocks_is_asset_reference_url( $url ) ) {
					continue;
				}

				$key = 'srcset:' . $url;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ]  = true;
				$references[] = array(
					'attribute' => 'srcset',
					'url'       => $url,
				);
			}
		}
	}

	return $references;
}

/**
 * Check whether a URL-like value should be reported as an asset reference.
 *
 * @param string $url URL or path.
 * @return bool True when this value points at a materializable asset.
 */
function html_to_blocks_is_asset_reference_url( string $url ): bool {
	$url = trim( $url );
	if ( '' === $url || '#' === $url[0] || preg_match( '/^(?:mailto|tel|javascript):/i', $url ) ) {
		return false;
	}

	$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? $url );
	return preg_match( '/\.(?:avif|gif|jpe?g|png|svg|webp|css|js|json|mp4|webm|mp3|wav|woff2?|ttf|otf|eot|pdf)(?:$|[?#])/i', $path ) === 1;
}

/**
 * Collect simple navigation candidates for downstream entity materializers.
 *
 * @param string $html Source HTML.
 * @return array<int,array<string,mixed>> Navigation candidates.
 */
function html_to_blocks_collect_navigation_candidates( string $html ): array {
	$candidates = array();
	if ( ! preg_match_all( '/<nav\b([^>]*)>(.*?)<\/nav>/is', $html, $matches, PREG_SET_ORDER ) ) {
		return $candidates;
	}

	foreach ( $matches as $index => $match ) {
		$attrs = html_to_blocks_parse_html_attribute_string( (string) $match[1] );
		$links = html_to_blocks_collect_anchor_links( (string) $match[2] );
		if ( empty( $links ) ) {
			continue;
		}

		$candidates[] = array(
			'source'     => 'nav[' . $index . ']',
			'class_name' => isset( $attrs['class'] ) ? (string) $attrs['class'] : '',
			'label'      => isset( $attrs['aria-label'] ) ? (string) $attrs['aria-label'] : '',
			'links'      => $links,
		);
	}

	return $candidates;
}

/**
 * Collect anchors from an HTML fragment.
 *
 * @param string $html Source HTML.
 * @return array<int,array{url:string,label:string,class_name:string}>
 */
function html_to_blocks_collect_anchor_links( string $html ): array {
	$links = array();
	if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
		return $links;
	}

	foreach ( $matches as $match ) {
		$attrs = html_to_blocks_parse_html_attribute_string( (string) $match[1] );
		$url   = trim( (string) ( $attrs['href'] ?? '' ) );
		if ( '' === $url ) {
			continue;
		}

		$links[] = array(
			'url'        => html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ),
			'label'      => trim( wp_strip_all_tags( (string) $match[2] ) ),
			'class_name' => isset( $attrs['class'] ) ? (string) $attrs['class'] : '',
		);
	}

	return $links;
}

/**
 * Parse a small HTML attribute string into a lowercase map.
 *
 * @param string $attribute_string Raw attributes.
 * @return array<string,string>
 */
function html_to_blocks_parse_html_attribute_string( string $attribute_string ): array {
	$attributes = array();
	if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $attribute_string, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$value = '';
			if ( isset( $match[3] ) && '' !== $match[3] ) {
				$value = $match[3];
			} elseif ( isset( $match[4] ) && '' !== $match[4] ) {
				$value = $match[4];
			} elseif ( isset( $match[5] ) ) {
				$value = $match[5];
			}

			$attributes[ strtolower( $match[1] ) ] = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}
	}

	return $attributes;
}

/**
 * Checks whether an empty div/span is a safe visual-only icon placeholder.
 *
 * @param HTML_To_Blocks_HTML_Element $element The source element.
 * @return bool True when the placeholder should be ignored.
 */
function html_to_blocks_should_ignore_empty_decorative_placeholder( $element ): bool {
	if ( ! in_array( $element->get_tag_name(), array( 'DIV', 'SPAN' ), true ) ) {
		return false;
	}

	if ( trim( wp_strip_all_tags( $element->get_inner_html() ) ) !== '' || array() !== $element->get_child_elements() ) {
		return false;
	}

	$attributes = $element->get_attributes();
	if ( 'DIV' === $element->get_tag_name() && array() === $attributes ) {
		return true;
	}

	$class_name = isset( $attributes['class'] ) ? (string) $attributes['class'] : '';
	$style      = isset( $attributes['style'] ) ? (string) $attributes['style'] : '';
	$role       = isset( $attributes['role'] ) ? strtolower( trim( (string) $attributes['role'] ) ) : '';

	$decorative_class_pattern = '/(?:^|[-_\s])(icon|ico|glyph|symbol|accent|bar|divider|separator|sep|rule|line|blank|orb|blob|dot|glow)(?:$|[-_\s]|\d)/i';
	if ( preg_match( $decorative_class_pattern, $class_name ) !== 1 ) {
		return false;
	}

	foreach ( $attributes as $name => $value ) {
		$name = strtolower( (string) $name );
		if ( preg_match( '/^on/i', $name ) ) {
			return false;
		}

		if ( ! in_array( $name, array( 'class', 'style', 'id', 'aria-hidden', 'role' ), true ) ) {
			return false;
		}
	}

	if ( '' !== $role && ! in_array( $role, array( 'none', 'presentation' ), true ) ) {
		return false;
	}

	if ( preg_match( '/url\s*\(/i', $style ) ) {
		return false;
	}

	if ( preg_match( '/(?:^|\s)code[-_]?dot(?:$|\s)/i', $class_name ) === 1 ) {
		return true;
	}

	if ( preg_match( '/(?:^|[-_\s])(?:accent|sep)(?:$|[-_\s]|\d)/i', $class_name ) === 1 ) {
		return true;
	}

	return preg_match( '/(?:^|;)\s*position\s*:\s*(?:absolute|fixed)\b/i', $style ) === 1
		|| preg_match( '/(?:^|;)\s*opacity\s*:\s*0(?:\.0+)?\b/i', $style ) === 1
		|| preg_match( '/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden|pointer-events\s*:\s*none)\b/i', $style ) === 1
		|| strtolower( (string) ( $attributes['aria-hidden'] ?? '' ) ) === 'true';
}

/**
 * Checks whether a block wrapper contains only ignorable decorative children.
 *
 * @param HTML_To_Blocks_HTML_Element $element The source element.
 * @return bool True when the wrapper should be ignored with its children.
 */
function html_to_blocks_should_ignore_decorative_wrapper( $element ): bool {
	if ( ! in_array( $element->get_tag_name(), array( 'P', 'DIV', 'SPAN' ), true ) ) {
		return false;
	}

	if ( trim( wp_strip_all_tags( $element->get_inner_html() ) ) !== '' ) {
		return false;
	}

	$children = $element->get_child_elements();
	if ( empty( $children ) ) {
		return false;
	}

	foreach ( $children as $child ) {
		if ( ! html_to_blocks_should_ignore_empty_decorative_placeholder( $child ) && ! html_to_blocks_should_ignore_decorative_wrapper( $child ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Checks whether a span contains block-level markup that cannot live in a paragraph.
 *
 * @param HTML_To_Blocks_HTML_Element $element The source element.
 * @return bool True when the span should be promoted to a block wrapper.
 */
function html_to_blocks_is_blocky_span( $element ): bool {
	if ( 'SPAN' !== $element->get_tag_name() ) {
		return false;
	}

	return preg_match( '/<(?:address|article|aside|blockquote|details|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|main|nav|ol|p|pre|section|table|ul)\b/i', $element->get_inner_html() ) === 1;
}

/**
 * Promotes an invalid span wrapper to a div while preserving safe attributes.
 *
 * @param HTML_To_Blocks_HTML_Element $element The span element.
 * @return string A valid block-level wrapper with the original contents.
 */
function html_to_blocks_promote_span_to_div_markup( $element ): string {
	$attributes = '';
	foreach ( $element->get_attributes() as $name => $value ) {
		$name = strtolower( (string) $name );
		if ( preg_match( '/^[a-z][a-z0-9:-]*$/', $name ) !== 1 ) {
			continue;
		}

		if ( true === $value ) {
			$attributes .= ' ' . $name;
			continue;
		}

		$attributes .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
	}

	return '<div' . $attributes . '>' . $element->get_inner_html() . '</div>';
}

/**
 * Measures converted block content, including nested layout descendants.
 *
 * @param array $blocks Converted block arrays.
 * @return int Approximate HTML content length.
 */
function html_to_blocks_measure_block_content_length( array $blocks ): int {
	$length = 0;

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$length += strlen( (string) ( $block['innerHTML'] ?? '' ) );

		if ( isset( $block['attrs']['content'] ) && is_string( $block['attrs']['content'] ) ) {
			$length += strlen( $block['attrs']['content'] );
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$length += html_to_blocks_measure_block_content_length( $block['innerBlocks'] );
		}
	}

	return $length;
}

/**
 * Creates the core/html fallback block and emits an observability hook.
 *
 * @param string $element_html Unsupported HTML fragment.
 * @param array  $context      Fallback context such as reason, tag_name, and occurrence.
 * @return array Block array.
 */
function html_to_blocks_create_unsupported_html_fallback_block( string $element_html, array $context = array() ): array {
	$block = HTML_To_Blocks_Block_Factory::create_block(
		'core/html',
		array( 'content' => $element_html )
	);

	if ( function_exists( 'do_action' ) ) {
		/**
		 * Fires when h2bc falls back to core/html because no supported transform exists.
		 *
		 * @param string $element_html Unsupported HTML fragment.
		 * @param array  $context      Context including reason, tag_name, and occurrence when available.
		 * @param array  $block        The generated core/html fallback block.
		 */
		do_action( 'html_to_blocks_unsupported_html_fallback', $element_html, $context, $block );
	}

	return $block;
}

/**
 * Recursively converts parsed core/html image fragments back to native image blocks.
 *
 * Some upstream callers pass already-serialized block markup through h2bc. In that
 * path parse_blocks() would otherwise preserve harmless image-only core/html
 * fragments instead of applying the raw image transforms.
 *
 * @param array<int|string,array<string,mixed>> $blocks Parsed blocks.
 * @return array<int|string,array<string,mixed>> Normalized blocks.
 */
function html_to_blocks_normalize_parsed_image_html_blocks( array $blocks ): array {
	$normalized = array();

	foreach ( $blocks as $block ) {
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = html_to_blocks_normalize_parsed_image_html_blocks( $block['innerBlocks'] );
		}

		if ( ( $block['blockName'] ?? null ) !== 'core/html' ) {
			$normalized[] = $block;
			continue;
		}

		$html = '';
		if ( isset( $block['attrs']['content'] ) && is_string( $block['attrs']['content'] ) ) {
			$html = $block['attrs']['content'];
		} elseif ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html = $block['innerHTML'];
		}

		$convertible_html          = $html;
		$is_decorative_inline_span = false;
		$is_image_only_fragment    = false;
		$is_form_container         = false;
		if ( html_to_blocks_is_decorative_inline_span_fragment( $html ) ) {
			$is_decorative_inline_span = true;
			$convertible_html          = html_to_blocks_normalise_blocks( $html );
		} elseif ( html_to_blocks_is_image_only_html_fragment( $html ) ) {
			$is_image_only_fragment = true;
		} elseif ( html_to_blocks_is_form_containing_container_fragment( $html ) ) {
			$is_form_container = true;
		} else {
			$normalized[] = $block;
			continue;
		}

		$converted = html_to_blocks_convert( $convertible_html );
		if ( empty( $converted ) ) {
			$normalized[] = $block;
			continue;
		}

		if ( ( $is_decorative_inline_span || $is_image_only_fragment ) && html_to_blocks_contains_block_name( $converted, 'core/html' ) ) {
			$normalized[] = $block;
			continue;
		}

		if ( $is_form_container && html_to_blocks_is_single_html_fallback_for_fragment( $converted, $html ) ) {
			$normalized[] = $block;
			continue;
		}

		$normalized = array_merge( $normalized, $converted );
	}

	return $normalized;
}

/**
 * Checks whether a raw HTML fallback wraps a larger static container with form controls.
 *
 * @param string $html HTML fragment.
 * @return bool True when reconversion may shrink fallback to a form/control island.
 */
function html_to_blocks_is_form_containing_container_fragment( string $html ): bool {
	$element = HTML_To_Blocks_HTML_Element::from_html( $html );
	if ( ! $element ) {
		return false;
	}

	if ( ! in_array( $element->get_tag_name(), array( 'SECTION', 'DIV', 'ARTICLE', 'ASIDE', 'HEADER', 'FOOTER', 'MAIN', 'NAV' ), true ) ) {
		return false;
	}

	foreach ( array( 'form', 'input', 'textarea', 'select', 'button' ) as $selector ) {
		if ( $element->query_selector( $selector ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Checks whether conversion still produced the original opaque core/html fragment.
 *
 * @param array  $blocks Blocks produced by reconversion.
 * @param string $html   Original HTML fragment.
 * @return bool True when fallback scope did not shrink.
 */
function html_to_blocks_is_single_html_fallback_for_fragment( array $blocks, string $html ): bool {
	if ( count( $blocks ) !== 1 || ( $blocks[0]['blockName'] ?? null ) !== 'core/html' ) {
		return false;
	}

	$fallback_html = $blocks[0]['attrs']['content'] ?? $blocks[0]['innerHTML'] ?? '';
	return is_string( $fallback_html ) && trim( $fallback_html ) === trim( $html );
}

/**
 * Checks whether an HTML fragment is one safe, empty decorative inline span.
 *
 * @param string $html HTML fragment.
 * @return bool True when the fragment can be materialized as editable inline content.
 */
function html_to_blocks_is_decorative_inline_span_fragment( string $html ): bool {
	$element = HTML_To_Blocks_HTML_Element::from_html( $html );
	if ( ! $element || $element->get_tag_name() !== 'SPAN' ) {
		return false;
	}

	if ( trim( wp_strip_all_tags( $element->get_inner_html() ) ) !== '' || array() !== $element->get_child_elements() ) {
		return false;
	}

	$attributes = $element->get_attributes();
	$class_name = isset( $attributes['class'] ) ? (string) $attributes['class'] : '';
	$style      = isset( $attributes['style'] ) ? (string) $attributes['style'] : '';
	$role       = isset( $attributes['role'] ) ? strtolower( trim( (string) $attributes['role'] ) ) : '';

	foreach ( $attributes as $name => $value ) {
		$name = strtolower( (string) $name );
		if ( preg_match( '/^on/i', $name ) ) {
			return false;
		}

		if ( ! in_array( $name, array( 'class', 'style', 'id', 'aria-hidden', 'role' ), true ) ) {
			return false;
		}
	}

	if ( '' !== $role && ! in_array( $role, array( 'none', 'presentation' ), true ) ) {
		return false;
	}

	if ( preg_match( '/(?:url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:)/i', $style ) ) {
		return false;
	}

	$decorative_class_pattern = '/(?:^|[-_\s])(icon|ico|glyph|symbol|accent|bar|divider|separator|sep|rule|line|blank|orb|blob|dot|glow)(?:$|[-_\s]|\d)/i';
	if ( preg_match( $decorative_class_pattern, $class_name ) === 1 ) {
		return true;
	}

	return '' !== $style
		&& preg_match( '/(?:^|;)\s*display\s*:\s*inline-block\b/i', $style ) === 1
		&& preg_match( '/(?:^|;)\s*width\s*:\s*[^;]+/i', $style ) === 1
		&& preg_match( '/(?:^|;)\s*height\s*:\s*[^;]+/i', $style ) === 1
		&& preg_match( '/(?:^|;)\s*(?:background|background-color)\s*:\s*[^;]+/i', $style ) === 1;
}

/**
 * Checks whether an HTML fragment is only an image, optionally inside one wrapper.
 *
 * @param string $html HTML fragment.
 * @return bool True when the fragment can safely be re-run through image transforms.
 */
function html_to_blocks_is_image_only_html_fragment( string $html ): bool {
	$element = HTML_To_Blocks_HTML_Element::from_html( $html );
	if ( ! $element ) {
		return false;
	}

	if ( $element->get_tag_name() === 'IMG' ) {
		$src = $element->get_attribute( 'src' );
		return is_string( $src ) && '' !== $src;
	}

	if ( ! in_array( $element->get_tag_name(), array( 'DIV', 'SPAN', 'FIGURE' ), true ) ) {
		return false;
	}

	$images = $element->query_selector_all( 'img' );
	$src    = count( $images ) === 1 ? $images[0]->get_attribute( 'src' ) : null;
	if ( count( $images ) !== 1 || ! is_string( $src ) || '' === $src ) {
		return false;
	}

	$remaining = str_replace( $images[0]->get_outer_html(), '', $element->get_inner_html() );
	return trim( wp_strip_all_tags( $remaining ) ) === '';
}

/**
 * Checks whether a block tree contains a block name.
 *
 * @param array<int|string,array<string,mixed>> $blocks Blocks to inspect.
 * @param string                                $name   Block name.
 * @return bool True when the block tree contains the name.
 */
function html_to_blocks_contains_block_name( array $blocks, string $name ): bool {
	foreach ( $blocks as $block ) {
		if ( ( $block['blockName'] ?? null ) === $name ) {
			return true;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && html_to_blocks_contains_block_name( $block['innerBlocks'], $name ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Finds all positions of a tag's opening tags in HTML
 *
 * @param string $html     Source HTML
 * @param string $tag_name Tag name to find
 * @return array Array of start positions
 */
function html_to_blocks_find_all_tag_positions( $html, $tag_name ) {
	$positions = array();
	$pattern   = '/<' . preg_quote( $tag_name, '/' ) . '(?:\s[^>]*)?>/i';

	if ( preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[0] as $match ) {
			$positions[] = $match[1];
		}
	}

	return $positions;
}

/**
 * Extracts element HTML at a specific occurrence
 *
 * @param string $html       Source HTML
 * @param string $tag_name   Tag name
 * @param array  $positions  Array of tag start positions
 * @param int    $occurrence Which occurrence (0-based)
 * @return string|null Element HTML or null
 */
function html_to_blocks_extract_element_at_occurrence( $html, $tag_name, $positions, $occurrence ) {
	if ( ! isset( $positions[ $occurrence ] ) ) {
		return null;
	}

	$start_pos       = $positions[ $occurrence ];
	$html_from_start = substr( $html, $start_pos );

	$void_elements = array(
		'AREA',
		'BASE',
		'BR',
		'COL',
		'EMBED',
		'HR',
		'IMG',
		'INPUT',
		'LINK',
		'META',
		'PARAM',
		'SOURCE',
		'TRACK',
		'WBR',
	);

	if ( in_array( strtoupper( $tag_name ), $void_elements, true ) ) {
		$pattern = '/<' . preg_quote( $tag_name, '/' ) . '(?:\s[^>]*)?\/?>/i';
		if ( preg_match( $pattern, $html_from_start, $matches ) ) {
			return $matches[0];
		}
		return null;
	}

	return html_to_blocks_extract_balanced_element( $html_from_start, $tag_name );
}

/**
 * Extracts a balanced element including nested elements of the same type
 *
 * @param string $html     HTML starting with the opening tag
 * @param string $tag_name Tag name to balance
 * @return string|null Balanced element HTML or null
 */
function html_to_blocks_extract_balanced_element( $html, $tag_name ) {
	$depth         = 0;
	$tag_pattern   = '/<\/?' . preg_quote( $tag_name, '/' ) . '(?:\s[^>]*)?>/i';
	$matched_count = preg_match_all( $tag_pattern, $html, $matches, PREG_OFFSET_CAPTURE );
	if ( false === $matched_count || 0 === $matched_count ) {
		return null;
	}

	foreach ( $matches[0] as $match ) {
		$tag_markup = $match[0];
		$offset     = $match[1];

		if ( 0 === strpos( $tag_markup, '</' ) ) {
			--$depth;
			if ( 0 === $depth ) {
				return substr( $html, 0, $offset + strlen( $tag_markup ) );
			}
			continue;
		}

		++$depth;
	}

	return null;
}

/**
 * Finds a matching raw transform for an element
 *
 * @param HTML_To_Blocks_HTML_Element $element    The element to match
 * @param array                       $transforms Array of transforms
 * @return array|null The transform data or null
 */
function html_to_blocks_find_transform( $element, $transforms ) {
	foreach ( $transforms as $transform ) {
		$is_match = $transform['isMatch'] ?? null;
		if ( $is_match && is_callable( $is_match ) && call_user_func( $is_match, $element ) ) {
			return $transform;
		}
	}
	return null;
}

/**
 * Converts shortcodes in HTML to blocks
 *
 * @param string $html The HTML containing shortcodes
 * @return array Array of pieces (strings or blocks)
 */
function html_to_blocks_shortcode_converter( $html ) {
	$pieces     = array();
	$last_index = 0;

	preg_match_all( '/' . get_shortcode_regex() . '/', $html, $matches, PREG_OFFSET_CAPTURE );

	if ( empty( $matches[0] ) ) {
		return array( $html );
	}

	foreach ( $matches[0] as $match ) {
		$shortcode = $match[0];
		$index     = $match[1];

		if ( $index > $last_index ) {
			$pieces[] = substr( $html, $last_index, $index - $last_index );
		}

		$parsed   = html_to_blocks_parse_shortcode( $shortcode );
		$pieces[] = null !== $parsed ? $parsed : $shortcode;

		$last_index = $index + strlen( $shortcode );
	}

	if ( $last_index < strlen( $html ) ) {
		$pieces[] = substr( $html, $last_index );
	}

	return $pieces;
}

/**
 * Parses a shortcode and returns a block if possible
 *
 * @param string $shortcode The shortcode string
 * @return array|null The block array or null
 */
function html_to_blocks_parse_shortcode( $shortcode ) {
	$pattern = get_shortcode_regex();
	if ( ! preg_match( "/$pattern/", $shortcode, $match ) ) {
		return null;
	}

	return HTML_To_Blocks_Block_Factory::create_block(
		'core/shortcode',
		array( 'text' => $shortcode )
	);
}

/**
 * Checks whether an HTML fragment is one same-page hash anchor.
 *
 * @param string $html HTML fragment.
 * @return bool True when the fragment is a standalone hash anchor.
 */
function html_to_blocks_is_standalone_hash_anchor_fragment( string $html ): bool {
	$element = HTML_To_Blocks_HTML_Element::from_html( $html );
	if ( ! $element || 'A' !== $element->get_tag_name() ) {
		return false;
	}

	$href = trim( (string) $element->get_attribute( 'href' ) );
	if ( '' === $href || '#' !== $href[0] ) {
		return false;
	}

	return trim( $element->get_outer_html() ) === trim( $html );
}

/**
 * Normalises blocks in HTML - wraps inline content in paragraphs
 *
 * @param string $html The HTML
 * @return string The normalized HTML
 */
function html_to_blocks_normalise_blocks( $html ) {
	$processor = WP_HTML_Processor::create_fragment( $html );
	if ( ! $processor ) {
		return $html;
	}

	$phrasing_tags = html_to_blocks_phrasing_tag_names();

	$body_depth       = 2;
	$top_level_depth  = $body_depth + 1;
	$output           = '';
	$paragraph_buffer = '';
	$in_paragraph     = false;
	$last_was_br      = false;
	$tag_occurrences  = array();
	$tag_positions    = array();

	while ( $processor->next_token() ) {
		$token_type = $processor->get_token_type();
		$depth      = $processor->get_current_depth();

		if ( '#text' === $token_type && $depth === $top_level_depth ) {
			$text = $processor->get_modifiable_text();
			if ( trim( $text ) === '' ) {
				if ( $in_paragraph && '' !== $paragraph_buffer ) {
					$paragraph_buffer .= htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
				}
				continue;
			}

			if ( ! $in_paragraph ) {
				$in_paragraph = true;
			}
			$paragraph_buffer .= htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			$last_was_br       = false;
			continue;
		}

		if ( '#tag' !== $token_type ) {
			continue;
		}

		if ( $depth < $top_level_depth && ! $processor->is_tag_closer() ) {
			continue;
		}

		$tag_name   = $processor->get_tag();
		$tag_upper  = strtoupper( $tag_name );
		$is_closer  = $processor->is_tag_closer();
		$occurrence = null;

		if ( ! $is_closer ) {
			if ( ! isset( $tag_occurrences[ $tag_name ] ) ) {
				$tag_occurrences[ $tag_name ] = 0;
				$tag_positions[ $tag_name ]   = html_to_blocks_find_all_tag_positions( $html, $tag_name );
			}

			$occurrence = $tag_occurrences[ $tag_name ]++;
		}

		if ( $depth !== $top_level_depth && $depth !== $body_depth ) {
			continue;
		}

		if ( 'BR' === $tag_upper && ! $is_closer ) {
			if ( $last_was_br ) {
				if ( ! empty( trim( $paragraph_buffer ) ) ) {
					$output .= '<p>' . trim( $paragraph_buffer ) . '</p>';
				}
				$paragraph_buffer = '';
				$in_paragraph     = false;
				$last_was_br      = false;
			} else {
				if ( $in_paragraph && ! empty( $paragraph_buffer ) ) {
					$paragraph_buffer .= '<br>';
				}
				$last_was_br = true;
			}
			continue;
		}

		$last_was_br = false;

		if ( in_array( $tag_upper, $phrasing_tags, true ) && ! $is_closer ) {
			$element_html = html_to_blocks_extract_element_at_occurrence( $html, $tag_name, $tag_positions[ $tag_name ], $occurrence );

			if ( $element_html ) {
				$element = HTML_To_Blocks_HTML_Element::from_html( $element_html );
				if ( $element && html_to_blocks_is_blocky_span( $element ) ) {
					if ( $in_paragraph && ! empty( trim( $paragraph_buffer ) ) ) {
						$output .= '<p>' . trim( $paragraph_buffer ) . '</p>';
					}
					$paragraph_buffer = '';
					$in_paragraph     = false;
					$output          .= html_to_blocks_promote_span_to_div_markup( $element );
					continue;
				}

				if ( ! $in_paragraph ) {
					$in_paragraph = true;
				}

				$paragraph_buffer .= html_to_blocks_normalise_phrasing_fragment( $element_html );
			}
			continue;
		}

		if ( ! $is_closer && ! in_array( $tag_upper, $phrasing_tags, true ) ) {
			if ( $in_paragraph && ! empty( trim( $paragraph_buffer ) ) ) {
				$output .= '<p>' . trim( $paragraph_buffer ) . '</p>';
			}
			$paragraph_buffer = '';
			$in_paragraph     = false;

			$element_html = html_to_blocks_extract_element_at_occurrence( $html, $tag_name, $tag_positions[ $tag_name ], $occurrence );

			if ( $element_html ) {
				$output .= $element_html;
			}
		}
	}

	if ( $in_paragraph && ! empty( trim( $paragraph_buffer ) ) ) {
		$output .= '<p>' . trim( $paragraph_buffer ) . '</p>';
	}

	return ! empty( $output ) ? $output : $html;
}

/**
 * Normalize standalone phrasing fragments before wrapping them in paragraphs.
 *
 * @param string $html HTML fragment.
 * @return string Normalized fragment.
 */
function html_to_blocks_normalise_phrasing_fragment( string $html ): string {
	if (
		preg_match( '/^\s*<\s*a\b[^>]*\sclass=("|\')([^"\']*)\1/i', $html, $matches )
		&& preg_match( '/(^|[-_\s])(brand|logo)([-_\s]|$)/i', $matches[2] )
	) {
		$html = preg_replace( '/<\s*div\b([^>]*)>/i', '<span$1>', $html ) ?? $html;
		$html = preg_replace( '/<\s*\/\s*div\s*>/i', '</span>', $html ) ?? $html;
	}

	return $html;
}
