<?php
/**
 * HTML Element Adapter - Provides DOM-like interface over WP_HTML_Processor
 *
 * Wraps WordPress HTML API to provide familiar DOM traversal methods
 * for transform callbacks and HTML parsing operations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTML_To_Blocks_HTML_Element {

	private string $tag_name;
	private array $attributes;
	private string $outer_html;
	private string $inner_html;

	public function __construct( string $tag_name, array $attributes, string $outer_html, string $inner_html ) {
		$this->tag_name   = $tag_name;
		$this->attributes = $attributes;
		$this->outer_html = $outer_html;
		$this->inner_html = $inner_html;
	}

	/**
	 * Creates an HTML_Element from raw HTML string representing a single element
	 *
	 * @param string $html HTML string containing a single root element
	 * @return self|null Element instance or null if parsing fails
	 */
	public static function from_html( string $html ): ?self {
		$html = trim( $html );
		if ( empty( $html ) ) {
			return null;
		}

		$processor = WP_HTML_Processor::create_fragment( $html );
		if ( ! $processor ) {
			return null;
		}

		if ( ! $processor->next_token() ) {
			return null;
		}

		$tag_name = $processor->get_tag();
		if ( ! $tag_name ) {
			return null;
		}

		$attributes      = self::extract_attributes( $processor );
		$inner_html      = self::extract_inner_html( $html, $tag_name );

		return new self( $tag_name, $attributes, $html, $inner_html );
	}

	/**
	 * Extracts all attributes from the current processor position
	 *
	 * @param WP_HTML_Processor $processor HTML processor at an element
	 * @return array Associative array of attribute name => value
	 */
	private static function extract_attributes( WP_HTML_Processor $processor ): array {
		$attributes = [];
		$names      = $processor->get_attribute_names_with_prefix( '' );

		if ( $names ) {
			foreach ( $names as $name ) {
				$attributes[ $name ] = $processor->get_attribute( $name );
			}
		}

		return $attributes;
	}

	/**
	 * Extracts inner HTML from an element string
	 *
	 * @param string $html     Full element HTML
	 * @param string $tag_name Tag name to find closing tag
	 * @return string Inner HTML content
	 */
	private static function extract_inner_html( string $html, string $tag_name ): string {
		$tag_lower = strtolower( $tag_name );

		$void_elements = [
			'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
			'link', 'meta', 'param', 'source', 'track', 'wbr',
		];

		if ( in_array( $tag_lower, $void_elements, true ) ) {
			return '';
		}

		$pattern = '/^<' . preg_quote( $tag_name, '/' ) . '(?:\s[^>]*)?>(.*)$/is';
		if ( ! preg_match( $pattern, $html, $matches ) ) {
			return '';
		}

		$content = $matches[1];

		$close_pattern = '/<\/' . preg_quote( $tag_name, '/' ) . '>\s*$/i';
		$content       = preg_replace( $close_pattern, '', $content );

		return trim( $content );
	}

	/**
	 * Gets the tag name (uppercase)
	 *
	 * @return string Tag name
	 */
	public function get_tag_name(): string {
		return strtoupper( $this->tag_name );
	}

	/**
	 * Alias for get_tag_name() to match DOMNode interface
	 *
	 * @return string Tag name
	 */
	public function get_node_name(): string {
		return $this->get_tag_name();
	}

	/**
	 * Gets an attribute value
	 *
	 * @param string $name Attribute name
	 * @return string|null Attribute value or null if not present
	 */
	public function get_attribute( string $name ): ?string {
		$value = $this->attributes[ strtolower( $name ) ] ?? null;
		if ( $value === true ) {
			return '';
		}
		return $value;
	}

	/**
	 * Checks if an attribute exists
	 *
	 * @param string $name Attribute name
	 * @return bool True if attribute exists
	 */
	public function has_attribute( string $name ): bool {
		return isset( $this->attributes[ strtolower( $name ) ] );
	}

	/**
	 * Gets all attributes
	 *
	 * @return array Associative array of attributes
	 */
	public function get_attributes(): array {
		return $this->attributes;
	}

	/**
	 * Gets the inner HTML content
	 *
	 * @return string Inner HTML
	 */
	public function get_inner_html(): string {
		return $this->inner_html;
	}

	/**
	 * Gets the outer HTML (full element including tags)
	 *
	 * @return string Outer HTML
	 */
	public function get_outer_html(): string {
		return $this->outer_html;
	}

	/**
	 * Gets the text content (strips all HTML tags)
	 *
	 * @return string Text content
	 */
	public function get_text_content(): string {
		return trim( wp_strip_all_tags( $this->inner_html ) );
	}

	/**
	 * Queries for a descendant element matching a simple selector
	 *
	 * @param string $selector Simple CSS selector (tag, .class, #id)
	 * @return self|null Matching element or null
	 */
	public function query_selector( string $selector ): ?self {
		$results = $this->query_selector_all( $selector );
		return $results[0] ?? null;
	}

	/**
	 * Queries for all descendant elements matching a simple selector
	 *
	 * @param string $selector Simple CSS selector (tag, .class, #id)
	 * @return array Array of matching elements
	 */
	public function query_selector_all( string $selector ): array {
		$processor = WP_HTML_Processor::create_fragment( $this->inner_html );
		if ( ! $processor ) {
			return [];
		}

		$selector = trim( $selector );
		$results  = [];

		$tag_match   = null;
		$class_match = null;
		$id_match    = null;

		if ( preg_match( '/^([a-z0-9]+)?(?:\.([a-z0-9_-]+))?(?:#([a-z0-9_-]+))?$/i', $selector, $matches ) ) {
			$tag_match   = ! empty( $matches[1] ) ? strtoupper( $matches[1] ) : null;
			$class_match = $matches[2] ?? null;
			$id_match    = $matches[3] ?? null;
		}

		while ( $processor->next_tag() ) {
			if ( $processor->is_tag_closer() ) {
				continue;
			}

			$tag = $processor->get_tag();

			if ( $tag_match && strtoupper( $tag ) !== $tag_match ) {
				continue;
			}

			if ( $class_match ) {
				$class_attr = $processor->get_attribute( 'class' );
				if ( ! $class_attr || ! preg_match( '/(?:^|\s)' . preg_quote( $class_match, '/' ) . '(?:$|\s)/', $class_attr ) ) {
					continue;
				}
			}

			if ( $id_match ) {
				$id_attr = $processor->get_attribute( 'id' );
				if ( $id_attr !== $id_match ) {
					continue;
				}
			}

			$element_html = self::extract_element_html_at_position( $this->inner_html, $processor );
			if ( $element_html ) {
				$element = self::from_html( $element_html );
				if ( $element ) {
					$results[] = $element;
				}
			}
		}

		return $results;
	}

	/**
	 * Extracts the full HTML of an element at the processor's current position
	 *
	 * @param string            $html      Source HTML
	 * @param WP_HTML_Processor $processor Processor at target element
	 * @return string|null Element HTML or null
	 */
	private static function extract_element_html_at_position( string $html, WP_HTML_Processor $processor ): ?string {
		$tag_name = $processor->get_tag();
		if ( ! $tag_name ) {
			return null;
		}

		$bookmark_name = 'element_start_' . wp_unique_id();
		if ( ! $processor->set_bookmark( $bookmark_name ) ) {
			return null;
		}

		$void_elements = [
			'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT',
			'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR',
		];

		if ( in_array( strtoupper( $tag_name ), $void_elements, true ) ) {
			$processor->release_bookmark( $bookmark_name );
			$pattern = '/<' . preg_quote( $tag_name, '/' ) . '(?:\s[^>]*)?\/?>/i';
			if ( preg_match( $pattern, $html, $matches ) ) {
				return $matches[0];
			}
			return null;
		}

		$start_depth = $processor->get_current_depth();
		$found_close = false;

		while ( $processor->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
			if ( $processor->get_current_depth() < $start_depth ) {
				$found_close = true;
				break;
			}
			if ( $processor->is_tag_closer() && 
				 strtoupper( $processor->get_tag() ) === strtoupper( $tag_name ) && 
				 $processor->get_current_depth() === $start_depth - 1 ) {
				$found_close = true;
				break;
			}
		}

		$processor->seek( $bookmark_name );
		$processor->release_bookmark( $bookmark_name );

		if ( ! $found_close ) {
			$pattern = '/<' . preg_quote( $tag_name, '/' ) . '(?:\s[^>]*)?>.*?<\/' . preg_quote( $tag_name, '/' ) . '>/is';
			if ( preg_match( $pattern, $html, $matches ) ) {
				return $matches[0];
			}
		}

		$pattern = '/<' . preg_quote( $tag_name, '/' ) . '(?:\s[^>]*)?>.*?<\/' . preg_quote( $tag_name, '/' ) . '>/is';
		if ( preg_match( $pattern, $html, $matches ) ) {
			return $matches[0];
		}

		return null;
	}

	/**
	 * Gets child elements (direct descendants only)
	 *
	 * @return array Array of child elements
	 */
	public function get_child_elements(): array {
		$processor = WP_HTML_Processor::create_fragment( $this->inner_html );
		if ( ! $processor ) {
			return [];
		}

		$children    = [];
		$body_depth  = 2;
		$target_depth = $body_depth + 1;

		while ( $processor->next_tag() ) {
			if ( $processor->is_tag_closer() ) {
				continue;
			}

			$depth = $processor->get_current_depth();
			if ( $depth !== $target_depth ) {
				continue;
			}

			$tag_name     = $processor->get_tag();
			$element_html = self::extract_element_html_from_fragment( $this->inner_html, $processor, $tag_name );

			if ( $element_html ) {
				$element = self::from_html( $element_html );
				if ( $element ) {
					$children[] = $element;
				}
			}
		}

		return $children;
	}

	/**
	 * Extracts element HTML from a fragment at current processor position
	 *
	 * @param string            $fragment_html Source fragment HTML
	 * @param WP_HTML_Processor $processor     Processor at target element
	 * @param string            $tag_name      Tag name of the element
	 * @return string|null Element HTML or null
	 */
	private static function extract_element_html_from_fragment( string $fragment_html, WP_HTML_Processor $processor, string $tag_name ): ?string {
		$void_elements = [
			'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT',
			'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR',
		];

		$tag_upper = strtoupper( $tag_name );

		if ( in_array( $tag_upper, $void_elements, true ) ) {
			$attributes = self::extract_attributes( $processor );
			$attr_string = '';
			foreach ( $attributes as $name => $value ) {
				if ( $value === true || $value === '' ) {
					$attr_string .= ' ' . $name;
				} else {
					$attr_string .= ' ' . $name . '="' . esc_attr( $value ) . '"';
				}
			}
			return '<' . strtolower( $tag_name ) . $attr_string . '>';
		}

		$pattern = '/<' . preg_quote( $tag_name, '/' ) . '(?:\s[^>]*)?>.*?<\/' . preg_quote( $tag_name, '/' ) . '>/is';
		if ( preg_match( $pattern, $fragment_html, $matches ) ) {
			return $matches[0];
		}

		return null;
	}
}
