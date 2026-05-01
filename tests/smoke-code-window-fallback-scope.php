<?php
/**
 * Smoke test: complex code-window fallback stays scoped inside normal page sections.
 *
 * Run: php tests/smoke-code-window-fallback-scope.php
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
					'core/button',
					'core/buttons',
					'core/group',
					'core/heading',
					'core/html',
					'core/paragraph',
					'core/preformatted',
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
		return strip_tags( $text );
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

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( array $blocks ): string {
		$output = '';
		foreach ( $blocks as $block ) {
			$name       = $block['blockName'] ?? '';
			$attrs      = array_diff_key( $block['attrs'] ?? [], [ 'content' => true ] );
			$attrs_json = empty( $attrs ) ? '' : ' ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES );

			if ( $name === 'core/html' ) {
				$output .= '<!-- wp:html -->' . ( $block['attrs']['content'] ?? $block['innerHTML'] ?? '' ) . '<!-- /wp:html -->';
				continue;
			}

			$output .= '<!-- wp:' . substr( $name, 5 ) . $attrs_json . ' -->';
			$output .= $block['innerHTML'] ?? '';
			$output .= serialize_blocks( $block['innerBlocks'] ?? [] );
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
		$failures[] = 'FAIL [' . $label . ']' . ( $detail !== '' ? ': ' . $detail : '' );
	}
};

$collect_blocks = static function ( array $blocks, string $name ) use ( &$collect_blocks ): array {
	$matches = [];
	foreach ( $blocks as $block ) {
		if ( ( $block['blockName'] ?? '' ) === $name ) {
			$matches[] = $block;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$matches = array_merge( $matches, $collect_blocks( $block['innerBlocks'], $name ) );
		}
	}

	return $matches;
};

$html = <<<'HTML'
<div class="sc-page">
  <section class="sc-hero">
    <div class="sc-container">
      <div class="sc-hero-inner">
        <div class="sc-hero-text">
          <div class="sc-eyebrow">WordPress Studio &mdash; Now in Beta</div>
          <h1>Vibe code your site.<br><em>Get real blocks.</em></h1>
          <p class="sc-hero-lead">Write pure, familiar HTML. Studio Code converts it into clean WordPress blocks.</p>
          <div class="sc-cta-group">
			<a href="#get-started" class="sc-btn sc-btn-primary">Start Building Free &rarr;</a>
			<a href="#how-it-works" class="sc-btn sc-btn-secondary">See How It Works</a>
          </div>
        </div>
        <div class="sc-hero-visual">
          <div class="sc-code-window">
            <div class="sc-code-bar">
              <div class="sc-code-dot"></div>
              <div class="sc-code-dot"></div>
              <div class="sc-code-dot"></div>
              <span class="sc-code-filename">index.html &rarr; WordPress blocks</span>
            </div>
            <div class="sc-code-body">
              <div><span class="sc-cm">&lt;!-- You write --&gt;</span></div>
              <div>&nbsp;</div>
              <div><span class="sc-tag">&lt;section</span> <span class="sc-attr">class</span>=<span class="sc-val">"hero"</span><span class="sc-tag">&gt;</span></div>
              <div>&nbsp;&nbsp;<span class="sc-tag">&lt;h1&gt;</span><span class="sc-txt">Build Something Beautiful</span><span class="sc-tag">&lt;/h1&gt;</span></div>
              <div>&nbsp;&nbsp;<span class="sc-tag">&lt;a</span> <span class="sc-attr">class</span>=<span class="sc-val">"btn"</span><span class="sc-tag">&gt;</span><span class="sc-txt">Get Started</span><span class="sc-tag">&lt;/a&gt;</span></div>
            </div>
            <div class="sc-arrow-row">
              <span>Studio Code</span>
              &darr; converts automatically
              <span>WordPress Blocks</span>
            </div>
            <div class="sc-code-body">
              <div><span class="sc-cm">&lt;!-- WordPress stores --&gt;</span></div>
              <div><span class="sc-tag">&lt;!--</span> <span class="sc-attr">wp:group</span> <span class="sc-val">{"layout":{"type":"constrained"}}</span> <span class="sc-tag">--&gt;</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
  <section class="sc-workflow" id="how-it-works">
    <div class="sc-container">
      <p class="sc-section-label">How It Works</p>
      <h2 class="sc-section-title">HTML in. WordPress blocks out.</h2>
      <p class="sc-section-body">Normal page sections should remain editable native blocks.</p>
    </div>
  </section>
</div>
HTML;

$blocks     = html_to_blocks_raw_handler( [ 'HTML' => $html ] );
$serialized = serialize_blocks( $blocks );
$groups     = $collect_blocks( $blocks, 'core/group' );
$fallbacks  = $collect_blocks( $blocks, 'core/html' );
$buttons    = $collect_blocks( $blocks, 'core/button' );
$preformatted = $collect_blocks( $blocks, 'core/preformatted' );

$assert( count( $blocks ) === 1 && ( $blocks[0]['blockName'] ?? '' ) === 'core/group', 'root-page-becomes-group', $serialized );
$assert( count( $groups ) >= 6, 'normal-wrappers-become-groups', $serialized );
$assert( str_contains( $serialized, 'sc-page' ), 'page-class-survives', $serialized );
$assert( str_contains( $serialized, 'HTML in. WordPress blocks out.' ), 'normal-heading-survives', $serialized );
$assert( str_contains( $serialized, 'Normal page sections should remain editable native blocks.' ), 'normal-copy-survives', $serialized );
$assert( str_contains( $serialized, '<!-- wp:heading' ), 'normal-heading-is-native', $serialized );
$assert( str_contains( $serialized, '<!-- wp:paragraph' ), 'normal-copy-is-native', $serialized );
$assert( count( $buttons ) === 2, 'cta-links-become-native-buttons', $serialized );
$assert( str_contains( $serialized, '#get-started' ) && str_contains( $serialized, 'Start Building Free' ), 'cta-primary-preserves-href-and-text', $serialized );
$assert( str_contains( $serialized, 'sc-btn-primary' ) && str_contains( $serialized, 'sc-btn-secondary' ), 'cta-classes-survive', $serialized );
$assert( count( $fallbacks ) === 0, 'code-window-does-not-fallback-to-html', $serialized );
$assert( count( $preformatted ) === 2, 'code-bodies-become-preformatted', $serialized );
$assert( str_contains( $serialized, 'sc-code-window' ), 'code-window-class-survives', $serialized );
$assert( str_contains( $serialized, 'sc-code-bar' ), 'code-bar-class-survives', $serialized );
$assert( str_contains( $serialized, 'sc-arrow-row' ), 'arrow-row-class-survives', $serialized );
$assert( str_contains( $serialized, 'index.html &rarr; WordPress blocks' ), 'filename-survives', $serialized );
$assert( str_contains( $serialized, 'Studio Code' ) && str_contains( $serialized, 'WordPress Blocks' ), 'arrow-row-text-survives', $serialized );
$assert( str_contains( $serialized, '&lt;!-- WordPress stores --&gt;' ), 'escaped-code-survives', $serialized );
$assert( str_contains( $serialized, 'sc-tag' ) && str_contains( $serialized, 'sc-attr' ), 'syntax-span-classes-survive', $serialized );

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
