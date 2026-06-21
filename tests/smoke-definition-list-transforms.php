<?php
/**
 * Smoke test: definition lists follow Blocks Engine canonical output.
 *
 * Run: php tests/smoke-definition-list-transforms.php
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

if ( ! class_exists( 'WP_Block_Type_Registry', false ) ) {
	class WP_Block_Type_Registry {
		public static function get_instance() {
			return new self();
		}

		public function is_registered( $name ) {
			return in_array(
				$name,
				[
					'core/heading',
					'core/html',
					'core/group',
					'core/list',
					'core/list-item',
					'core/paragraph',
				],
				true
			);
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

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
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

$flatten_block_names = static function ( array $blocks ) use ( &$flatten_block_names ): array {
	$names = [];
	foreach ( $blocks as $block ) {
		$names[] = $block['blockName'] ?? '';
		$names   = array_merge( $names, $flatten_block_names( $block['innerBlocks'] ?? [] ) );
	}
	return $names;
};

$assert_facade_matches_transformer = static function ( string $html, string $label ) use ( $assert ): array {
	$facade_args        = [ 'HTML' => $html ];
	$facade_blocks      = html_to_blocks_raw_handler( $facade_args );
	$transformer_result = html_to_blocks_transformer()->transform( $html, $facade_args )->toArray();
	$transformer_blocks = $transformer_result['blocks'] ?? [];
	$coverage_blocks    = $transformer_result['coverage'][0]['supported_blocks'] ?? [];

	$assert( $facade_blocks === $transformer_blocks, $label . '-facade-matches-blocks-engine', json_encode( [ 'facade' => $facade_blocks, 'transformer' => $transformer_blocks ] ) );
	$assert( in_array( 'core/list', $coverage_blocks, true ), $label . '-blocks-engine-coverage-includes-list', json_encode( $coverage_blocks ) );
	$assert( in_array( 'core/list-item', $coverage_blocks, true ), $label . '-blocks-engine-coverage-includes-list-item', json_encode( $coverage_blocks ) );
	$assert( [] === ( $transformer_result['fallbacks'] ?? [] ), $label . '-blocks-engine-reports-no-fallbacks', json_encode( $transformer_result['fallbacks'] ?? [] ) );

	return $facade_blocks;
};

$direct_blocks = $assert_facade_matches_transformer( '<dl><dt>Origin</dt><dd>Charleston</dd></dl>', 'direct-definition-list' );
$direct_names  = $flatten_block_names( $direct_blocks );
$assert( ( $direct_blocks[0]['blockName'] ?? '' ) === 'core/list', 'direct-definition-list-becomes-list' );
$assert( ( $direct_blocks[0]['innerBlocks'][0]['attrs']['content'] ?? '' ) === '<strong>Origin</strong> Charleston', 'direct-definition-list-content' );
$assert( ( $direct_blocks[0]['innerBlocks'][0]['blockName'] ?? '' ) === 'core/list-item', 'direct-definition-list-keeps-list-item' );
$assert( ! in_array( 'core/group', $direct_names, true ), 'direct-definition-list-has-no-group-wrapper' );
$assert( ! in_array( 'core/html', $direct_names, true ), 'direct-definition-list-has-no-html-fallback' );

$wrapper_stat_blocks = $assert_facade_matches_transformer( '<dl class="hero-stats" aria-label="Store highlights"><div><dt>5</dt><dd>workflow categories</dd></div><div><dt>18+</dt><dd>bench-ready tools</dd></div><div><dt>0</dt><dd>guesswork mornings</dd></div></dl>', 'wrapped-stat-definition-list' );
$assert( $wrapper_stat_blocks === array(), 'wrapped-stat-definition-list-follows-blocks-engine-empty-output' );

$complex_blocks = $assert_facade_matches_transformer( '<dl><div><dt>Term</dt><dd>Description</dd><p>Extra</p></div></dl>', 'complex-definition-list' );
$assert( $complex_blocks === array(), 'complex-definition-list-follows-blocks-engine-empty-output' );

if ( $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "PASS: {$assertions} definition list assertions\n" );
