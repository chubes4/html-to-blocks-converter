<?php
/**
 * Smoke test: public conversion result API exposes compiler-friendly metadata.
 *
 * Run: php tests/smoke-conversion-result-api.php
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
			return in_array( $name, [ 'core/button', 'core/buttons', 'core/group', 'core/html', 'core/image', 'core/list', 'core/list-item', 'core/paragraph' ], true );
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
			$output .= '<!-- /wp:' . substr( $name, 5 ) . ' -->';
		}

		return $output;
	}
}

$repo_root = dirname( __DIR__ );
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-svg-icon-classifier.php';
require_once $repo_root . '/includes/class-transform-registry.php';
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
<svg class="icon icon-star" viewBox="0 0 24 24" width="24" height="24" aria-label="Star"><path d="M12 2l3 7h7l-5 5 2 8-7-4-7 4 2-8-5-5h7z"/></svg>
<nav class="primary site-nav" aria-label="Primary"><a href="/">Home</a><a href="/menu/">Menu</a></nav>
<main class="content-shell"><p>Hello <strong>world</strong>.</p><img class="hero-image" src="assets/hero.webp" srcset="assets/hero.webp 1x, assets/hero@2x.webp 2x" alt="Hero"><a class="btn hero-cta" href="/book/">Book</a><form class="newsletter-form" action="/subscribe"><input type="email" name="email"><button type="submit">Join</button></form><div class="glow-orb decorative-layer"></div></main>
<custom-card data-state="unknown"><span>Opaque</span></custom-card>
<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z"/></svg>
HTML;

$result = html_to_blocks_convert_fragment(
	$html,
	[
		'context' => 'theme_part',
	]
);

$assert( is_array( $result['blocks'] ?? null ), 'result-exposes-blocks' );
$assert( is_string( $result['block_markup'] ?? null ) && str_contains( $result['block_markup'], '<!-- wp:' ), 'result-exposes-serialized-block-markup', $result['block_markup'] ?? '' );
$assert( is_array( $result['diagnostics'] ?? null ), 'result-exposes-diagnostics' );
$assert( is_array( $result['fallbacks'] ?? null ) && count( $result['fallbacks'] ) >= 1, 'result-captures-fallback-events' );
$assert( count( $result['diagnostics'] ) === count( $result['fallbacks'] ), 'fallbacks-normalize-to-diagnostics' );
$assert( is_array( $result['svg_artifacts'] ?? null ), 'result-exposes-svg-artifacts' );
$assert( is_array( $result['metrics'] ?? null ) && isset( $result['metrics']['total_ms'] ), 'result-captures-metrics' );
$assert( is_array( $result['source'] ?? null ) && ( $result['source']['bytes'] ?? 0 ) === strlen( $html ), 'result-exposes-source-summary' );
$assert( 'theme_part' === ( $result['source']['context'] ?? '' ), 'result-preserves-context' );
$assert( is_array( $result['transformer_result'] ?? null ), 'result-exposes-transformer-result' );
$assert( 'blocks-engine/php-transformer/result/v1' === ( $result['transformer_result']['schema'] ?? '' ), 'transformer-result-exposes-schema', json_encode( $result['transformer_result'] ?? null ) );
$assert( is_array( $result['transformer_result']['blocks'] ?? null ), 'transformer-result-exposes-blocks' );
$assert( is_array( $result['transformer_result']['fallbacks'] ?? null ) && count( $result['transformer_result']['fallbacks'] ) >= 1, 'transformer-result-exposes-fallbacks', json_encode( $result['transformer_result']['fallbacks'] ?? null ) );
$assert( isset( $result['transformer_result']['metrics']['transform_duration_ms'] ), 'transformer-result-exposes-metrics', json_encode( $result['transformer_result']['metrics'] ?? null ) );

$diagnostic_codes = array_map(
	static function ( $diagnostic ) {
		return $diagnostic['code'] ?? '';
	},
	$result['diagnostics']
);
$assert( in_array( 'unsafe_inline_svg', $diagnostic_codes, true ), 'result-diagnoses-unsafe-inline-svg', json_encode( $result['diagnostics'] ) );
$asset_urls = array_map(
	static function ( $reference ) {
		return $reference['url'] ?? '';
	},
	$result['asset_references']
);
$assert( in_array( 'assets/hero.webp', $asset_urls, true ), 'result-captures-img-src-asset-reference', json_encode( $result['asset_references'] ) );
$assert( in_array( 'assets/hero@2x.webp', $asset_urls, true ), 'result-captures-srcset-asset-reference', json_encode( $result['asset_references'] ) );

$assert( 1 === count( $result['navigation_candidates'] ), 'result-captures-navigation-candidate', json_encode( $result['navigation_candidates'] ) );
$assert( 'Primary' === ( $result['navigation_candidates'][0]['label'] ?? '' ), 'navigation-candidate-preserves-label' );
$assert( 2 === count( $result['navigation_candidates'][0]['links'] ?? [] ), 'navigation-candidate-preserves-links' );

$repair = $result['visual_repair_metadata'] ?? [];
$assert( 'html-to-blocks-converter/visual-repair-metadata/v1' === ( $repair['schema'] ?? '' ), 'result-exposes-visual-repair-schema', json_encode( $repair ) );
$assert( 1 === ( $repair['version'] ?? 0 ), 'repair-metadata-exposes-version', json_encode( $repair ) );
$assert( is_array( $repair['wrapper_classes'] ?? null ) && ! empty( $repair['wrapper_classes'] ), 'repair-metadata-captures-wrapper-classes', json_encode( $repair ) );
$assert( is_array( $repair['mappings']['images'] ?? null ) && ! empty( $repair['mappings']['images'] ), 'repair-metadata-captures-image-mappings', json_encode( $repair ) );
$assert( is_array( $repair['mappings']['forms'] ?? null ) && ! empty( $repair['mappings']['forms'] ), 'repair-metadata-captures-form-mappings', json_encode( $repair ) );
$assert( is_array( $repair['mappings']['navigation'] ?? null ) && ! empty( $repair['mappings']['navigation'] ), 'repair-metadata-captures-navigation-mappings', json_encode( $repair ) );
$assert( is_array( $repair['mappings']['buttons'] ?? null ) && ! empty( $repair['mappings']['buttons'] ), 'repair-metadata-captures-button-mappings', json_encode( $repair ) );
$assert( is_array( $repair['markers']['fallback_blocks'] ?? null ) && ! empty( $repair['markers']['fallback_blocks'] ), 'repair-metadata-captures-fallback-markers', json_encode( $repair ) );
$assert( is_array( $repair['markers']['decorative_sources'] ?? null ) && ! empty( $repair['markers']['decorative_sources'] ), 'repair-metadata-captures-decorative-markers', json_encode( $repair ) );
$assert( is_array( $repair['diagnostics'] ?? null ) && ! empty( $repair['diagnostics'] ), 'repair-metadata-captures-diagnostics', json_encode( $repair ) );
$assert( ! empty( $repair['categories']['groups'] ?? [] ), 'repair-metadata-captures-group-records', json_encode( $repair ) );
$assert( ! empty( $repair['categories']['images'] ?? [] ), 'repair-metadata-captures-image-records', json_encode( $repair ) );
$assert( ! empty( $repair['categories']['forms'] ?? [] ), 'repair-metadata-captures-form-records', json_encode( $repair ) );
$assert( ! empty( $repair['categories']['navigation'] ?? [] ), 'repair-metadata-captures-navigation-records', json_encode( $repair ) );
$assert( ! empty( $repair['categories']['buttons'] ?? [] ), 'repair-metadata-captures-button-records', json_encode( $repair ) );
$assert( ! empty( $repair['categories']['decorative'] ?? [] ), 'repair-metadata-captures-decorative-records', json_encode( $repair ) );

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
