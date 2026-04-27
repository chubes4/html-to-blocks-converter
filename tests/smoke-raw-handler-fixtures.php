<?php
/**
 * Smoke test: production-shape raw-handler fixture matrix.
 *
 * Run: php tests/smoke-raw-handler-fixtures.php
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ );
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return (string) $value;
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $value ) {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) {
		return strip_tags( (string) $value );
	}
}
if ( ! function_exists( 'wp_unique_id' ) ) {
	function wp_unique_id( $prefix = '' ) {
		static $id = 0;
		$id++;
		return $prefix . $id;
	}
}
if ( ! function_exists( 'get_shortcode_regex' ) ) {
	function get_shortcode_regex() {
		return '(?!)';
	}
}
if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		return [
			[
				'blockName'    => 'core/freeform',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $content,
				'innerContent' => [ $content ],
			],
		];
	}
}

$html_to_blocks_smoke_actions = [];

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		global $html_to_blocks_smoke_actions;
		$html_to_blocks_smoke_actions[] = [ $hook_name, $args ];
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry', false ) ) {
	class WP_Block_Type_Registry {
		private static $instance;
		private $blocks = [];

		public static function get_instance() {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			$string = [ 'type' => 'string' ];
			$rich   = [ 'type' => 'rich-text' ];
			foreach ( [ 'core/paragraph', 'core/heading', 'core/list-item', 'core/button', 'core/pullquote', 'core/verse', 'core/code', 'core/preformatted', 'core/separator' ] as $name ) {
				$this->blocks[ $name ] = (object) [
					'attributes' => [
						'align'              => $string,
						'className'          => $string,
						'content'            => $rich,
						'level'              => [ 'type' => 'number' ],
						'text'               => $rich,
						'url'                => $string,
						'rel'                => $string,
						'linkTarget'         => $string,
						'value'              => $rich,
						'citation'           => $rich,
						'showDownloadButton' => [ 'type' => 'boolean' ],
					],
				];
			}
			$this->blocks['core/html'] = (object) [ 'attributes' => [ 'content' => $string ] ];

			foreach ( [ 'core/list', 'core/quote', 'core/buttons', 'core/details', 'core/group', 'core/columns', 'core/column', 'core/cover', 'core/spacer' ] as $name ) {
				$this->blocks[ $name ] = (object) [
					'attributes' => [
						'ordered'   => [ 'type' => 'boolean' ],
						'summary'   => $rich,
						'className' => $string,
						'url'       => $string,
						'height'    => $string,
					],
				];
			}

			foreach ( [ 'core/video', 'core/audio', 'core/image' ] as $name ) {
				$this->blocks[ $name ] = (object) [
					'attributes' => [
						'src'       => $string,
						'url'       => $string,
						'alt'       => $string,
						'caption'   => $rich,
						'poster'    => $string,
						'preload'   => $string,
						'autoplay'  => [ 'type' => 'boolean' ],
						'controls'  => [ 'type' => 'boolean' ],
						'loop'      => [ 'type' => 'boolean' ],
						'muted'     => [ 'type' => 'boolean' ],
						'id'        => [ 'type' => 'number' ],
						'className' => $string,
					],
				];
			}

			$this->blocks['core/table'] = (object) [
				'attributes' => [
					'head'      => [ 'type' => 'array' ],
					'body'      => [ 'type' => 'array' ],
					'foot'      => [ 'type' => 'array' ],
					'className' => $string,
				],
			];
			$this->blocks['core/gallery'] = (object) [
				'attributes' => [
					'ids'     => [ 'type' => 'array' ],
					'columns' => [ 'type' => 'number' ],
				],
			];
			$this->blocks['core/media-text'] = (object) [
				'attributes' => [
					'mediaUrl'          => $string,
					'mediaAlt'          => $string,
					'mediaType'         => $string,
					'mediaPosition'     => $string,
					'mediaWidth'        => [ 'type' => 'number' ],
					'isStackedOnMobile' => [ 'type' => 'boolean' ],
				],
			];
			$this->blocks['core/file'] = (object) [
				'attributes' => [
					'href'               => $string,
					'textLinkHref'       => $string,
					'textLinkTarget'     => $string,
					'fileName'           => $rich,
					'showDownloadButton' => [ 'type' => 'boolean' ],
				],
			];
			$this->blocks['core/embed'] = (object) [
				'attributes' => [
					'url'              => $string,
					'type'             => $string,
					'providerNameSlug' => $string,
					'responsive'       => [ 'type' => 'boolean' ],
				],
			];
		}

		public function is_registered( $name ) {
			return isset( $this->blocks[ $name ] );
		}

		public function get_registered( $name ) {
			return $this->blocks[ $name ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_HTML_Processor', false ) ) {
	class WP_HTML_Processor {
		private $tokens = [];
		private $index = -1;
		private $last_error = null;

		public static function create_fragment( $html ) {
			$processor = new self();
			$processor->load_fragment( (string) $html );
			return $processor;
		}

		private function load_fragment( string $html ): void {
			$document = new DOMDocument();
			libxml_use_internal_errors( true );
			$loaded = $document->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
			libxml_clear_errors();

			if ( ! $loaded ) {
				$this->last_error = 'load_failed';
				return;
			}

			$body = $document->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $body ) {
				$this->last_error = 'missing_body';
				return;
			}

			foreach ( $body->childNodes as $child ) {
				$this->append_node_tokens( $child, 3, true );
			}
		}

		private function append_node_tokens( DOMNode $node, int $tag_depth, bool $body_child = false ): void {
			if ( $node instanceof DOMText ) {
				$this->tokens[] = [
					'type'  => '#text',
					'depth' => $body_child ? 2 : $tag_depth,
					'text'  => $node->nodeValue,
				];
				return;
			}

			if ( ! $node instanceof DOMElement ) {
				return;
			}

			$this->tokens[] = [
				'type'       => '#tag',
				'depth'      => $tag_depth,
				'tag'        => strtoupper( $node->tagName ),
				'attributes' => $this->extract_attributes( $node ),
				'closer'     => false,
			];

			foreach ( $node->childNodes as $child ) {
				$this->append_node_tokens( $child, $tag_depth + 1, false );
			}
		}

		private function extract_attributes( DOMElement $node ): array {
			$attributes = [];
			foreach ( $node->attributes as $attribute ) {
				$attributes[ strtolower( $attribute->name ) ] = $attribute->value;
			}
			return $attributes;
		}

		public function next_token() {
			$this->index++;
			return isset( $this->tokens[ $this->index ] );
		}

		public function next_tag( $query = null ) {
			while ( $this->next_token() ) {
				if ( $this->get_token_type() === '#tag' ) {
					return true;
				}
			}
			return false;
		}

		public function get_token_type() {
			return $this->tokens[ $this->index ]['type'] ?? null;
		}

		public function get_current_depth() {
			return $this->tokens[ $this->index ]['depth'] ?? 0;
		}

		public function get_modifiable_text() {
			return $this->tokens[ $this->index ]['text'] ?? '';
		}

		public function get_tag() {
			return $this->tokens[ $this->index ]['tag'] ?? null;
		}

		public function is_tag_closer() {
			return (bool) ( $this->tokens[ $this->index ]['closer'] ?? false );
		}

		public function get_attribute_names_with_prefix( $prefix ) {
			$attributes = $this->tokens[ $this->index ]['attributes'] ?? [];
			return array_keys( $attributes );
		}

		public function get_attribute( $name ) {
			$attributes = $this->tokens[ $this->index ]['attributes'] ?? [];
			return $attributes[ strtolower( $name ) ] ?? null;
		}

		public function get_last_error() {
			return $this->last_error;
		}

		public function set_bookmark( $name ) {
			return true;
		}

		public function seek( $name ) {
			return true;
		}

		public function release_bookmark( $name ) {
			return true;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-block-factory.php';
require_once dirname( __DIR__ ) . '/includes/class-attribute-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-html-element.php';
require_once dirname( __DIR__ ) . '/includes/class-transform-registry.php';
require_once dirname( __DIR__ ) . '/raw-handler.php';

$failures   = [];
$assertions = 0;

$assert = static function ( $condition, $label, $detail = '' ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( $detail !== '' ? ': ' . $detail : '' );
	}
};

$flatten_block_names = static function ( array $blocks ) use ( &$flatten_block_names ): array {
	$names = [];
	foreach ( $blocks as $block ) {
		$names[] = $block['blockName'] ?? null;
		if ( ! empty( $block['innerBlocks'] ) ) {
			$names = array_merge( $names, $flatten_block_names( $block['innerBlocks'] ) );
		}
	}
	return $names;
};

$fixtures = [
	[
		'label'          => 'heading',
		'html'           => '<h2>Fixture Heading</h2>',
		'expected_names' => [ 'core/heading' ],
		'snippets'       => [ 'Fixture Heading' ],
	],
	[
		'label'          => 'paragraph',
		'html'           => '<p>Fixture <strong>paragraph</strong>.</p>',
		'expected_names' => [ 'core/paragraph' ],
		'snippets'       => [ '<strong>paragraph</strong>' ],
	],
	[
		'label'          => 'nested-list',
		'html'           => '<ul><li>One<ul><li>Child</li></ul></li><li>Two</li></ul>',
		'expected_names' => [ 'core/list', 'core/list-item', 'core/list', 'core/list-item', 'core/list-item' ],
		'snippets'       => [ 'Child' ],
	],
	[
		'label'          => 'quote',
		'html'           => '<blockquote><p>Quote text</p><cite>Source</cite></blockquote>',
		'expected_names' => [ 'core/quote', 'core/paragraph', 'core/paragraph' ],
		'snippets'       => [ 'Source' ],
	],
	[
		'label'          => 'code',
		'html'           => '<pre><code>const answer = 42;</code></pre>',
		'expected_names' => [ 'core/code' ],
		'snippets'       => [ 'const answer = 42;' ],
	],
	[
		'label'          => 'preformatted',
		'html'           => '<pre>Plain preformatted text</pre>',
		'expected_names' => [ 'core/preformatted' ],
		'snippets'       => [ 'Plain preformatted text' ],
	],
	[
		'label'          => 'separator',
		'html'           => '<hr class="is-style-wide">',
		'expected_names' => [ 'core/separator' ],
		'snippets'       => [ 'wp-block-separator' ],
	],
	[
		'label'          => 'table',
		'html'           => '<table><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Ada</td></tr></tbody></table>',
		'expected_names' => [ 'core/table' ],
	],
	[
		'label'          => 'group',
		'html'           => '<div class="wp-block-group"><p>Grouped copy</p></div>',
		'expected_names' => [ 'core/group', 'core/paragraph' ],
		'snippets'       => [ 'Grouped copy' ],
	],
	[
		'label'          => 'columns',
		'html'           => '<div class="wp-block-columns"><div class="wp-block-column"><p>Left</p></div><div class="wp-block-column"><p>Right</p></div></div>',
		'expected_names' => [ 'core/columns', 'core/column', 'core/paragraph', 'core/column', 'core/paragraph' ],
		'snippets'       => [ 'Left', 'Right' ],
	],
	[
		'label'          => 'cover',
		'html'           => '<section class="hero cover" style="background-image: url(cover.jpg)"><p>Cover text</p></section>',
		'expected_names' => [ 'core/cover', 'core/paragraph' ],
		'snippets'       => [ 'Cover text' ],
	],
	[
		'label'          => 'spacer',
		'html'           => '<div style="height: 48px" aria-hidden="true" class="wp-block-spacer"></div>',
		'expected_names' => [ 'core/spacer' ],
	],
	[
		'label'          => 'buttons',
		'html'           => '<a class="wp-block-button__link wp-element-button" href="https://example.com">Click</a>',
		'expected_names' => [ 'core/buttons', 'core/button' ],
		'snippets'       => [ 'https://example.com', 'Click' ],
	],
	[
		'label'          => 'details',
		'html'           => '<details><summary>Question</summary><p>Answer</p></details>',
		'expected_names' => [ 'core/details', 'core/paragraph' ],
		'snippets'       => [ 'Question', 'Answer' ],
	],
	[
		'label'          => 'pullquote',
		'html'           => '<blockquote class="wp-block-pullquote"><p>Pull this</p><cite>Citation</cite></blockquote>',
		'expected_names' => [ 'core/pullquote' ],
		'snippets'       => [ 'Pull this', 'Citation' ],
	],
	[
		'label'          => 'verse',
		'html'           => '<pre class="wp-block-verse">Line one\nLine two</pre>',
		'expected_names' => [ 'core/verse' ],
		'snippets'       => [ 'Line one', 'Line two' ],
	],
	[
		'label'          => 'video',
		'html'           => '<video controls src="movie.mp4" poster="poster.jpg"></video>',
		'expected_names' => [ 'core/video' ],
		'snippets'       => [ 'movie.mp4', 'poster.jpg' ],
	],
	[
		'label'          => 'audio',
		'html'           => '<audio controls><source src="clip.mp3" type="audio/mpeg"></audio>',
		'expected_names' => [ 'core/audio' ],
		'snippets'       => [ 'clip.mp3' ],
	],
	[
		'label'          => 'gallery',
		'html'           => '<div class="gallery columns-2"><figure><img src="a.jpg" alt="A" class="wp-image-10"><figcaption>Caption A</figcaption></figure><figure><img src="b.jpg" alt="B"><figcaption>Caption B</figcaption></figure></div>',
		'expected_names' => [ 'core/gallery', 'core/image', 'core/image' ],
		'snippets'       => [ 'Caption A', 'b.jpg' ],
	],
	[
		'label'          => 'media-text',
		'html'           => '<div class="wp-block-media-text"><figure><img src="hero.jpg" alt="Hero"></figure><div class="wp-block-media-text__content"><p>Media copy</p></div></div>',
		'expected_names' => [ 'core/media-text', 'core/image', 'core/group', 'core/paragraph' ],
		'snippets'       => [ 'hero.jpg', 'Media copy' ],
	],
	[
		'label'          => 'file',
		'html'           => '<a href="https://example.com/report.pdf">Download report</a>',
		'expected_names' => [ 'core/paragraph' ],
		'snippets'       => [ 'report.pdf', 'Download report' ],
	],
	[
		'label'          => 'recognized-embed',
		'html'           => '<iframe src="https://www.youtube.com/embed/abc123"></iframe>',
		'expected_names' => [ 'core/embed' ],
		'snippets'       => [ 'youtube.com/watch?v=abc123' ],
	],
	[
		'label'          => 'unknown-iframe-provider',
		'html'           => '<iframe src="https://example.com/widget"></iframe>',
		'expected_names' => [ 'core/html' ],
		'fallback_count' => 1,
		'fallback_tag'   => 'IFRAME',
		'snippets'       => [ 'example.com/widget' ],
	],
	[
		'label'          => 'custom-element',
		'html'           => '<x-card data-kind="promo">Custom payload</x-card>',
		'expected_names' => [ 'core/html' ],
		'fallback_count' => 1,
		'fallback_tag'   => 'X-CARD',
		'snippets'       => [ 'Custom payload' ],
	],
	[
		'label'          => 'app-widget',
		'html'           => '<div data-widget="stock-ticker"><span>AAPL</span></div>',
		'expected_names' => [ 'core/html' ],
		'fallback_count' => 1,
		'fallback_tag'   => 'DIV',
		'snippets'       => [ 'stock-ticker' ],
	],
];

foreach ( $fixtures as $fixture ) {
	global $html_to_blocks_smoke_actions;
	$html_to_blocks_smoke_actions = [];

	$blocks         = html_to_blocks_raw_handler( [ 'HTML' => $fixture['html'] ] );
	$flattened      = $flatten_block_names( $blocks );
	$expected_names = $fixture['expected_names'];
	$fallback_count = $fixture['fallback_count'] ?? 0;
	$actual_actions = array_values( array_filter(
		$html_to_blocks_smoke_actions,
		static function ( $action ) {
			return ( $action[0] ?? '' ) === 'html_to_blocks_unsupported_html_fallback';
		}
	) );

	$assert(
		$flattened === $expected_names,
		$fixture['label'] . '-block-names',
		'expected ' . json_encode( $expected_names ) . ', got ' . json_encode( $flattened )
	);
	$assert(
		count( $actual_actions ) === $fallback_count,
		$fixture['label'] . '-fallback-count',
		'expected ' . $fallback_count . ', got ' . count( $actual_actions )
	);

	$combined_html = '';
	array_walk_recursive(
		$blocks,
		static function ( $value, $key ) use ( &$combined_html ) {
			if ( in_array( $key, [ 'innerHTML', 'content' ], true ) && is_string( $value ) ) {
				$combined_html .= "\n" . $value;
			}
		}
	);
	foreach ( $fixture['snippets'] ?? [] as $snippet ) {
		$assert(
			strpos( $combined_html, $snippet ) !== false,
			$fixture['label'] . '-snippet-' . $snippet,
			'combined HTML: ' . $combined_html
		);
	}

	if ( $fallback_count > 0 ) {
		$action = $actual_actions[0] ?? null;
		$assert( ( $action[1][1]['reason'] ?? '' ) === 'no_transform', $fixture['label'] . '-fallback-reason' );
		$assert( ( $action[1][1]['tag_name'] ?? '' ) === $fixture['fallback_tag'], $fixture['label'] . '-fallback-tag' );
		$assert( ( $action[1][2]['blockName'] ?? '' ) === 'core/html', $fixture['label'] . '-fallback-block-name' );
		$assert( strpos( $action[1][0] ?? '', $fixture['snippets'][0] ?? '' ) !== false, $fixture['label'] . '-fallback-html-context' );
	}
}

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
