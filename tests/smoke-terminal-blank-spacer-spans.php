<?php
/**
 * Smoke test: Homeboy terminal blank spacers stay block-native.
 *
 * Evidence: html-to-blocks-converter issue #216, Homeboy benchmark run
 * 9b245aa9-76c1-4009-b64b-b5fb9c654497.
 *
 * Empty terminal span placeholders were legacy H2BC-only behavior. Canonical
 * terminal spacing is now delegated to Blocks Engine as explicit spacers.
 *
 * Run: php tests/smoke-terminal-blank-spacer-spans.php
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
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'get_shortcode_regex' ) ) {
	function get_shortcode_regex() {
		return '(?!)';
	}
}

$fallback_events      = [];
$transformer_results = [];
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		global $fallback_events, $transformer_results;
		if ( 'html_to_blocks_unsupported_html_fallback' === $hook_name ) {
			$fallback_events[] = $args;
		}
		if ( 'html_to_blocks_transformer_result' === $hook_name ) {
			$transformer_results[] = $args[0] ?? null;
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

$flatten_blocks = static function ( array $blocks ) use ( &$flatten_blocks ): array {
	$flat = [];
	foreach ( $blocks as $block ) {
		$flat[] = $block;
		$flat  = array_merge( $flat, $flatten_blocks( $block['innerBlocks'] ?? [] ) );
	}

	return $flat;
};

$html = <<<'HTML'
<div class="terminal-body">
  <p><span class="t-prompt">$</span> <span class="t-cmd">hb rig up --env staging</span></p>
  <p class="t-output t-success">✓ Rig provisioned in 4.2s</p>
  <div class="wp-block-spacer t-blank" style="height: 16px"></div>
  <p><span class="t-prompt">$</span> <span class="t-cmd">hb bench run --baseline main</span></p>
  <p class="t-output">Running 3 benchmark suites…</p>
  <p class="t-output t-success">✓ p99 latency 18ms (-23% vs main)</p>
  <p class="t-output t-info">→ report saved: .homeboy/bench/2024-11-18.json</p>
  <div class="wp-block-spacer t-blank" style="height: 16px"></div>
  <p><span class="t-prompt">$</span> <span class="t-cmd">hb trace analyze --since 2h</span></p>
  <p class="t-output t-warn">! 3 slow spans detected in auth pipeline</p>
  <p class="t-output t-info">→ flamegraph: .homeboy/traces/auth-slow.html</p>
  <div class="wp-block-spacer t-blank" style="height: 16px"></div>
  <p><span class="t-prompt">$</span> <span class="t-cmd">hb pr ready</span></p>
  <p class="t-output t-success">✓ PR report generated with evidence bundle</p>
  <p class="t-output t-success">✓ Opened: github.com/org/repo/pull/412</p>
  <div class="wp-block-spacer t-blank" style="height: 16px"></div>
  <p><span class="t-prompt">$</span> <span class="cursor"></span></p>
</div>
HTML;

$blocks     = html_to_blocks_raw_handler( [ 'HTML' => $html ] );
$flat       = $flatten_blocks( $blocks );
$names      = array_map(
	static function ( $block ) {
		return $block['blockName'] ?? '';
	},
	$flat
);
$serialized = serialize_blocks( $blocks );
$spacers    = array_values(
	array_filter(
		$flat,
		static function ( $block ) {
			return ( $block['blockName'] ?? '' ) === 'core/spacer';
		}
	)
);
$latest_transformer_result = end( $transformer_results );
$transformer_result_array  = is_object( $latest_transformer_result ) && method_exists( $latest_transformer_result, 'toArray' )
	? $latest_transformer_result->toArray()
	: [];

$assert( count( $blocks ) === 1, 'terminal-body-single-wrapper', (string) count( $blocks ) );
$assert( ( $blocks[0]['blockName'] ?? '' ) === 'core/group', 'terminal-body-wrapper-is-group', implode( ', ', $names ) );
$assert( ! in_array( 'core/html', $names, true ), 'terminal-body-does-not-use-core-html', implode( ', ', $names ) );
$assert( count( $fallback_events ) === 0, 'terminal-body-emits-no-fallback-events', (string) count( $fallback_events ) );
$assert( 'blocks-engine/php-transformer/result/v1' === ( $transformer_result_array['schema'] ?? '' ), 'terminal-body-uses-blocks-engine-transformer', json_encode( $transformer_result_array ) );
$assert( count( $spacers ) === 4, 'terminal-blank-spacers-become-core-spacer', implode( ', ', $names ) );
foreach ( $spacers as $index => $spacer ) {
	$assert( '16px' === ( $spacer['attrs']['height'] ?? '' ), 'terminal-spacer-' . $index . '-preserves-height', json_encode( $spacer ) );
	$assert( str_contains( (string) ( $spacer['attrs']['className'] ?? '' ), 't-blank' ), 'terminal-spacer-' . $index . '-preserves-class', json_encode( $spacer ) );
}
$assert( ! str_contains( $serialized, '<!-- wp:html -->' ), 'terminal-body-serialized-output-has-no-wp-html', $serialized );

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
