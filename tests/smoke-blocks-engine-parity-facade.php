<?php
/**
 * Smoke test: H2BC facade delegates post-parity behavior to Blocks Engine.
 *
 * Run: php tests/smoke-blocks-engine-parity-facade.php
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

	$core_root = dirname( $wp_html_api_path );
	if ( is_file( $core_root . '/class-wp-token-map.php' ) ) {
		require_once $core_root . '/class-wp-token-map.php';
	}
	if ( is_file( $wp_html_api_path . '/html5-named-character-references.php' ) ) {
		require_once $wp_html_api_path . '/html5-named-character-references.php';
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

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'get_shortcode_regex' ) ) {
	function get_shortcode_regex() {
		return '(?!)';
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
			$output .= '<!-- /wp:' . substr( $name, 5 ) . ' -->';
		}

		return $output;
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

$repo_root = dirname( __DIR__ );
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

$flatten_blocks = static function ( array $blocks ) use ( &$flatten_blocks ): array {
	$flat = [];
	foreach ( $blocks as $block ) {
		$flat[] = $block;
		$flat  = array_merge( $flat, $flatten_blocks( $block['innerBlocks'] ?? [] ) );
	}

	return $flat;
};

$result = html_to_blocks_convert_fragment(
	'<section class="landing-media"><img src="assets/hero.jpg" alt="Original hero"><div class="wp-block-spacer landing-gap" style="height:48px"></div></section>',
	[
		'context' => [
			'asset_metadata' => [
				'assets/hero.jpg' => [
					'id'  => 412,
					'url' => 'https://example.test/wp-content/uploads/hero.jpg',
				],
			],
		],
	]
);

$flat       = $flatten_blocks( $result['blocks'] ?? [] );
$names      = array_map(
	static function ( $block ) {
		return $block['blockName'] ?? '';
	},
	$flat
);
$image      = null;
$spacer     = null;
foreach ( $flat as $block ) {
	if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
		$image = $block;
	}
	if ( 'core/spacer' === ( $block['blockName'] ?? '' ) ) {
		$spacer = $block;
	}
}

$transformer_blocks = $result['transformer_result']['blocks'] ?? [];
$coverage_blocks    = $result['transformer_result']['coverage'][0]['supported_blocks'] ?? [];

$assert( is_array( $result['transformer_result'] ?? null ), 'facade-exposes-transformer-result' );
$assert( 'blocks-engine/php-transformer/result/v1' === ( $result['transformer_result']['schema'] ?? '' ), 'facade-uses-blocks-engine-result-schema', json_encode( $result['transformer_result'] ?? null ) );
$assert( in_array( 'core/image', $coverage_blocks, true ), 'blocks-engine-coverage-includes-image', json_encode( $coverage_blocks ) );
$assert( in_array( 'core/spacer', $coverage_blocks, true ), 'blocks-engine-coverage-includes-spacer', json_encode( $coverage_blocks ) );
$assert( in_array( 'core/image', $names, true ), 'facade-emits-image-block', implode( ', ', $names ) );
$assert( in_array( 'core/spacer', $names, true ), 'facade-emits-spacer-block', implode( ', ', $names ) );
$assert( ! in_array( 'core/html', $names, true ), 'parity-input-does-not-use-fallback-html', implode( ', ', $names ) );
$assert( 412 === ( $image['attrs']['id'] ?? null ), 'facade-preserves-blocks-engine-asset-id', json_encode( $image ) );
$assert( 'https://example.test/wp-content/uploads/hero.jpg' === ( $image['attrs']['url'] ?? '' ), 'facade-preserves-blocks-engine-asset-url', json_encode( $image ) );
$assert( 'Original hero' === ( $image['attrs']['alt'] ?? '' ), 'facade-preserves-source-alt-text', json_encode( $image ) );
$assert( '48px' === ( $spacer['attrs']['height'] ?? '' ), 'facade-preserves-blocks-engine-spacer-height', json_encode( $spacer ) );
$assert( count( $transformer_blocks ) === count( $result['blocks'] ?? [] ), 'facade-blocks-match-transformer-block-count', json_encode( $transformer_blocks ) );
$assert( [] === ( $result['fallbacks'] ?? [] ), 'facade-reports-no-fallbacks-for-parity-input', json_encode( $result['fallbacks'] ?? [] ) );

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
