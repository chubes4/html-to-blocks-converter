<?php
/**
 * Smoke test: conversion result exposes source-to-block selector provenance.
 *
 * Run: php tests/smoke-selector-provenance.php
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! class_exists( 'WP_HTML_Processor', false ) ) {
	$wp_html_api_candidates = array_filter(
		[
			getenv( 'WP_HTML_API_PATH' ) ? getenv( 'WP_HTML_API_PATH' ) : '',
			'/wordpress/wp-includes/html-api',
			'/Users/chubes/Studio/intelligence-chubes4/wp-includes/html-api',
		]
	);
	$wp_html_api_path       = '';

	foreach ( $wp_html_api_candidates as $candidate ) {
		if ( is_file( rtrim( $candidate, '/' ) . '/class-wp-html-processor.php' ) ) {
			$wp_html_api_path = rtrim( $candidate, '/' );
			break;
		}
	}

	if ( '' === $wp_html_api_path ) {
		fwrite( STDERR, "FAIL: WP_HTML_Processor is unavailable. Set WP_HTML_API_PATH to wp-includes/html-api.\n" );
		exit( 1 );
	}

	foreach ( [
		'class-wp-html-attribute-token.php',
		'class-wp-html-span.php',
		'class-wp-html-text-replacement.php',
		'class-wp-html-decoder.php',
		'class-wp-html-doctype-info.php',
		'class-wp-html-unsupported-exception.php',
		'class-wp-html-token.php',
		'class-wp-html-tag-processor.php',
		'class-wp-html-stack-event.php',
		'class-wp-html-open-elements.php',
		'class-wp-html-active-formatting-elements.php',
		'class-wp-html-processor-state.php',
		'class-wp-html-processor.php',
	] as $file ) {
		require_once $wp_html_api_path . '/' . $file;
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry', false ) ) {
	class WP_Block_Type_Registry {
		public static function get_instance() {
			return new self();
		}

		public function is_registered( $name ) {
			return in_array( $name, [ 'core/button', 'core/buttons', 'core/group', 'core/html', 'core/image', 'core/paragraph' ], true );
		}

		public function get_registered( $name ) {
			return (object) [ 'attributes' => [] ];
		}
	}
}

foreach ( [ 'esc_attr', 'esc_html', 'esc_url' ] as $function_name ) {
	if ( ! function_exists( $function_name ) ) {
		eval( 'function ' . $function_name . '( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, "UTF-8" ); }' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'get_shortcode_regex' ) ) {
	function get_shortcode_regex() {
		return '(?!)';
	}
}

$GLOBALS['h2bc_smoke_actions'] = [];

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['h2bc_smoke_actions'][ $hook_name ][] = [ $callback, $accepted_args ];
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook_name, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['h2bc_smoke_actions'][ $hook_name ] ) ) {
			return;
		}

		$GLOBALS['h2bc_smoke_actions'][ $hook_name ] = array_values(
			array_filter(
				$GLOBALS['h2bc_smoke_actions'][ $hook_name ],
				static function ( $entry ) use ( $callback ) {
					return $entry[0] !== $callback;
				}
			)
		);
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook_name ) {
		return ! empty( $GLOBALS['h2bc_smoke_actions'][ $hook_name ] );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		foreach ( $GLOBALS['h2bc_smoke_actions'][ $hook_name ] ?? [] as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( array $blocks ): string {
		$output = '';
		foreach ( $blocks as $block ) {
			$name       = $block['blockName'] ?? '';
			$attrs      = array_diff_key( $block['attrs'] ?? [], [ 'content' => true ] );
			$attrs_json = empty( $attrs ) ? '' : ' ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES );

			if ( 'core/html' === $name ) {
				$output .= '<!-- wp:html -->' . ( $block['attrs']['content'] ?? $block['innerHTML'] ?? '' ) . '<!-- /wp:html -->';
				continue;
			}

			$output .= '<!-- wp:' . substr( $name, 5 ) . $attrs_json . ' -->';
			$output .= $block['innerContent'][0] ?? $block['innerHTML'] ?? '';
			$output .= serialize_blocks( $block['innerBlocks'] ?? [] );
			$inner_content = $block['innerContent'] ?? [];
			$output       .= end( $inner_content ) ? end( $inner_content ) : '';
			$output .= '<!-- /wp:' . substr( $name, 5 ) . '-->';
		}

		return $output;
	}
}

$repo_root = dirname( __DIR__ );
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/raw-handler.php';

$failures   = [];
$assertions = 0;

$assert = static function ( $condition, $label, $detail = '' ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$html = <<<'HTML'
<section class="feature-row">
	<div class="hours-table"><div><span>Tue</span><strong>4-10</strong></div></div>
	<figure class="photo-card"><img class="rounded-photo" src="assets/photo.webp" alt="Photo"></figure>
	<div class="contact-actions"><a class="btn btn-ghost" href="/contact">Visit us</a></div>
	<form class="signup-form" action="/subscribe"><label>Email<input type="email" name="email"></label></form>
</section>
HTML;

$result     = html_to_blocks_convert_fragment( $html );
$provenance = $result['selector_provenance'] ?? [];

$find = static function ( callable $predicate ) use ( $provenance ) {
	foreach ( $provenance as $entry ) {
		if ( $predicate( $entry ) ) {
			return $entry;
		}
	}

	return null;
};

$target_names = static function ( array $entry ): array {
	return array_map(
		static function ( array $target ): string {
			return $target['name'] ?? '';
		},
		$entry['generated_block']['targets'] ?? []
	);
};

$assert( is_array( $provenance ) && count( $provenance ) >= 5, 'result-exposes-selector-provenance', json_encode( $provenance ) );

$section = $find( static fn ( array $entry ): bool => ( $entry['source']['selector'] ?? '' ) === 'section.feature-row' && ( $entry['generated_block']['type'] ?? '' ) === 'core/group' );
$assert( null !== $section, 'group-wrapper-provenance-present', json_encode( $provenance ) );
$assert( null !== $section && in_array( 'group-wrapper', $target_names( $section ), true ), 'group-wrapper-target-hint-present', json_encode( $section ) );

$row = $find( static fn ( array $entry ): bool => ( $entry['source']['selector'] ?? '' ) === 'div.hours-table' && ( $entry['generated_block']['type'] ?? '' ) === 'core/group' );
$assert( null !== $row, 'row-like-descendant-group-provenance-present', json_encode( $provenance ) );

$image = $find( static fn ( array $entry ): bool => ( $entry['source']['selector'] ?? '' ) === 'img.rounded-photo' && ( $entry['generated_block']['type'] ?? '' ) === 'core/image' );
$assert( null !== $image, 'image-provenance-present', json_encode( $provenance ) );
$assert( null !== $image && in_array( 'image-wrapper', $target_names( $image ), true ) && in_array( 'image-img', $target_names( $image ), true ), 'image-target-hints-present', json_encode( $image ) );

$button = $find( static fn ( array $entry ): bool => ( $entry['source']['selector'] ?? '' ) === 'a.btn.btn-ghost' && ( $entry['generated_block']['type'] ?? '' ) === 'core/button' );
$assert( null !== $button, 'button-anchor-provenance-present', json_encode( $provenance ) );
$assert( null !== $button && in_array( 'button-wrapper', $target_names( $button ), true ) && in_array( 'button-link', $target_names( $button ), true ), 'button-target-hints-present', json_encode( $button ) );

$form = $find( static fn ( array $entry ): bool => ( $entry['source']['selector'] ?? '' ) === 'form.signup-form' && ( $entry['generated_block']['type'] ?? '' ) === 'core/html' );
$assert( null !== $form, 'form-fallback-provenance-present', json_encode( $provenance ) );
$assert( null !== $form && in_array( 'form-control', $target_names( $form ), true ), 'form-control-target-hint-present', json_encode( $form ) );

$assert( ! str_contains( $result['block_markup'] ?? '', 'sourceSelectorProvenance' ), 'provenance-does-not-serialize-into-block-markup', $result['block_markup'] ?? '' );
$assert( [] === html_to_blocks_collect_selector_provenance_from_blocks( html_to_blocks_raw_handler( [ 'HTML' => $html ] ) ), 'raw-handler-does-not-emit-provenance-by-default' );

echo 'Assertions: ' . $assertions . PHP_EOL;
if ( empty( $failures ) ) {
	echo 'ALL PASS' . PHP_EOL;
	exit( 0 );
}

echo 'FAILURES (' . count( $failures ) . '):' . PHP_EOL;
foreach ( $failures as $failure ) {
	echo '  - ' . $failure . PHP_EOL;
}
exit( 1 );
