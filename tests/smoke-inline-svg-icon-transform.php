<?php
/**
 * Smoke test: small static inline SVG icons preserve markup without unsupported fallback diagnostics.
 *
 * Run: php tests/smoke-inline-svg-icon-transform.php
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! class_exists( 'WP_HTML_Processor', false ) ) {
	$wp_html_api_candidates = array_filter(
		[
			getenv( 'WP_HTML_API_PATH' ) ?: '',
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

	if ( $wp_html_api_path === '' ) {
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
			return in_array( $name, [ 'core/group', 'core/html', 'core/paragraph' ], true );
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
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'get_shortcode_regex' ) ) {
	function get_shortcode_regex() {
		return '(?!)';
	}
}

$fallback_events = [];

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		global $fallback_events;
		if ( $hook_name === 'html_to_blocks_unsupported_html_fallback' ) {
			$fallback_events[] = $args;
		}
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( array $blocks ): string {
		$output = '';
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			if ( $name === 'core/html' ) {
				$output .= '<!-- wp:html -->' . ( $block['attrs']['content'] ?? $block['innerHTML'] ?? '' ) . '<!-- /wp:html -->';
				continue;
			}

			$output .= '<!-- wp:' . substr( $name, 5 ) . ' -->';
			$output .= $block['innerContent'][0] ?? $block['innerHTML'] ?? '';
			$output .= serialize_blocks( $block['innerBlocks'] ?? [] );
			$inner_content = $block['innerContent'] ?? [];
			$output       .= end( $inner_content ) ?: '';
			$output .= '<!-- /wp:' . substr( $name, 5 ) . '-->';
		}

		return $output;
	}
}

$repo_root = dirname( __DIR__ );
require_once $repo_root . '/includes/class-block-factory.php';
require_once $repo_root . '/includes/class-attribute-parser.php';
require_once $repo_root . '/includes/class-html-element.php';
require_once $repo_root . '/includes/class-transform-registry.php';
require_once $repo_root . '/raw-handler.php';

$failures   = [];
$assertions = 0;

$assert = static function ( $condition, $label, $detail = '' ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( $detail !== '' ? ': ' . $detail : '' );
	}
};

$html = <<<HTML
<div class="icon-row">
  <svg viewbox="0 0 24 24"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><rect x="10" y="10" width="4" height="4"></rect></svg>
  <span>Two icons</span>
</div>
HTML;

$blocks     = html_to_blocks_raw_handler( [ 'HTML' => $html ] );
$serialized = serialize_blocks( $blocks );

$assert( substr_count( $serialized, '<!-- wp:html -->' ) === 2, 'icons-preserve-svg-markup-in-html-blocks', $serialized );
$assert( str_contains( $serialized, '<polyline points="16 18 22 12 16 6">' ), 'polyline-icon-survives', $serialized );
$assert( str_contains( $serialized, '<circle cx="12" cy="12" r="10">' ), 'circle-icon-survives', $serialized );
$assert( str_contains( $serialized, '<!-- wp:paragraph' ), 'nearby-text-still-converts', $serialized );
$assert( count( $fallback_events ) === 0, 'small-icons-emit-no-unsupported-fallback-events', (string) count( $fallback_events ) );

$unsafe_svg = '<svg viewBox="0 0 24 24"><foreignObject><iframe src="https://example.com/widget"></iframe></foreignObject></svg>';
html_to_blocks_raw_handler( [ 'HTML' => $unsafe_svg ] );

$assert( count( $fallback_events ) === 1, 'unsafe-svg-still-uses-unsupported-fallback', (string) count( $fallback_events ) );
$assert( ( $fallback_events[0][1]['tag_name'] ?? '' ) === 'SVG', 'unsafe-svg-fallback-tag-name' );

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
