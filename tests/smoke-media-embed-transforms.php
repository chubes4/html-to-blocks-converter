<?php
/**
 * Smoke test: media/embed facade output follows Blocks Engine canonical output.
 *
 * Run: php tests/smoke-media-embed-transforms.php
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

foreach ( [ 'esc_attr', 'esc_html', 'esc_url' ] as $function_name ) {
	if ( ! function_exists( $function_name ) ) {
		eval( 'function ' . $function_name . '( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, "UTF-8" ); }' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
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

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {}
}

$repo_root = dirname( __DIR__ );
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
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

$assert_facade_matches_transformer = static function ( string $html, string $label, array $args = [] ) use ( $assert ): array {
	$facade_args        = array_merge( $args, [ 'HTML' => $html ] );
	$facade_blocks      = html_to_blocks_raw_handler( $facade_args );
	$transformer_result = html_to_blocks_transformer()->transform( $html, $facade_args )->toArray();
	$transformer_blocks = $transformer_result['blocks'] ?? [];

	$assert( $facade_blocks === $transformer_blocks, $label . '-facade-matches-blocks-engine', json_encode( [ 'facade' => $facade_blocks, 'transformer' => $transformer_blocks ] ) );

	return [ $facade_blocks, $transformer_result ];
};

[ $media_blocks, $media_result ] = $assert_facade_matches_transformer(
	'<figure class="wp-block-image"><img src="product.jpg" alt="Product" width="640" height="480"><figcaption>Product shot</figcaption></figure><p><a href="https://example.com/report.pdf">Download report</a></p><iframe src="https://www.youtube.com/embed/abc123"></iframe><iframe src="https://example.com/widget"></iframe>',
	'media-embed-fragment',
	[
		'context' => [
			'asset_metadata' => [
				'product.jpg' => [
					'id'  => 301,
					'url' => 'https://example.test/uploads/product.jpg',
				],
			],
		],
	]
);

$media_flat   = $flatten_blocks( $media_blocks );
$media_names  = array_map(
	static function ( $block ) {
		return $block['blockName'] ?? '';
	},
	$media_flat
);
$image        = null;
$embeds       = [];
foreach ( $media_flat as $block ) {
	if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
		$image = $block;
	}
	if ( 'core/embed' === ( $block['blockName'] ?? '' ) ) {
		$embeds[] = $block;
	}
}

$coverage_blocks = $media_result['coverage'][0]['supported_blocks'] ?? [];
$assert( in_array( 'core/image', $coverage_blocks, true ), 'media-coverage-includes-image', json_encode( $coverage_blocks ) );
$assert( in_array( 'core/embed', $coverage_blocks, true ), 'media-coverage-includes-embed', json_encode( $coverage_blocks ) );
$assert( in_array( 'core/image', $media_names, true ), 'media-fragment-emits-image', implode( ', ', $media_names ) );
$assert( in_array( 'core/paragraph', $media_names, true ), 'file-link-remains-paragraph', implode( ', ', $media_names ) );
$assert( in_array( 'core/embed', $media_names, true ), 'media-fragment-emits-embed', implode( ', ', $media_names ) );
$assert( [] === ( $media_result['fallbacks'] ?? [] ), 'media-fragment-has-no-blocks-engine-fallbacks', json_encode( $media_result['fallbacks'] ?? [] ) );
$assert( 301 === ( $image['attrs']['id'] ?? null ), 'image-resolved-id', json_encode( $image ) );
$assert( 'https://example.test/uploads/product.jpg' === ( $image['attrs']['url'] ?? '' ), 'image-resolved-url', json_encode( $image ) );
$assert( 'Product' === ( $image['attrs']['alt'] ?? '' ), 'image-preserves-source-alt', json_encode( $image ) );
$assert( count( $embeds ) === 2, 'iframe-count-follows-blocks-engine', json_encode( $embeds ) );
$assert( ( $embeds[0]['attrs']['providerNameSlug'] ?? '' ) === 'youtube', 'youtube-provider', json_encode( $embeds[0] ?? null ) );
$assert( ( $embeds[0]['attrs']['url'] ?? '' ) === 'https://www.youtube.com/watch?v=abc123', 'youtube-url-normalised', json_encode( $embeds[0] ?? null ) );
$assert( ( $embeds[1]['attrs']['url'] ?? '' ) === 'https://example.com/widget', 'unknown-iframe-preserved-as-embed-url', json_encode( $embeds[1] ?? null ) );

[ $cta_blocks, $cta_result ] = $assert_facade_matches_transformer(
	'<p><a href="https://example.com/signup">Sign up</a></p><iframe src="https://example.com/widget"></iframe>',
	'fallback-boundary-fragment'
);
$cta_names = array_map(
	static function ( $block ) {
		return $block['blockName'] ?? '';
	},
	$flatten_blocks( $cta_blocks )
);
$assert( ! in_array( 'core/file', $cta_names, true ), 'normal-cta-link-not-file', implode( ', ', $cta_names ) );
$assert( in_array( 'core/embed', $cta_names, true ), 'unknown-iframe-remains-canonical-embed', implode( ', ', $cta_names ) );
$assert( [] === ( $cta_result['fallbacks'] ?? [] ), 'unknown-iframe-has-no-fallback-under-blocks-engine', json_encode( $cta_result['fallbacks'] ?? [] ) );

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
