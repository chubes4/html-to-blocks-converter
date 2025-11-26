<?php
/**
 * Attribute Parser - Extracts block attributes from HTML using DOM parsing
 *
 * PHP port of Gutenberg's getBlockAttributes() from packages/blocks/src/api/parser/get-block-attributes.js
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HTML_To_Blocks_Attribute_Parser {

    /**
     * Gets block attributes from HTML based on block schema
     *
     * @param string $block_name Block name
     * @param string $html       Raw HTML
     * @param array  $overrides  Attribute overrides
     * @return array Parsed attributes
     */
    public static function get_block_attributes( $block_name, $html, $overrides = [] ) {
        $registry = WP_Block_Type_Registry::get_instance();
        $block_type = $registry->get_registered( $block_name );

        if ( ! $block_type || empty( $block_type->attributes ) ) {
            return $overrides;
        }

        $doc = self::parse_html( $html );
        if ( ! $doc ) {
            return $overrides;
        }

        $attributes = [];
        foreach ( $block_type->attributes as $key => $schema ) {
            $value = self::parse_attribute( $doc, $schema, $html );
            if ( $value !== null ) {
                $attributes[ $key ] = $value;
            }
        }

        return array_merge( $attributes, $overrides );
    }

    /**
     * Parses HTML string into DOMDocument
     *
     * @param string $html HTML string
     * @return DOMDocument|null
     */
    private static function parse_html( $html ) {
        if ( empty( $html ) ) {
            return null;
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        return $doc;
    }

    /**
     * Parses a single attribute based on schema
     *
     * @param DOMDocument $doc    DOM document
     * @param array       $schema Attribute schema
     * @param string      $html   Original HTML
     * @return mixed Parsed value or null
     */
    private static function parse_attribute( $doc, $schema, $html ) {
        $source = $schema['source'] ?? null;
        $selector = $schema['selector'] ?? null;

        switch ( $source ) {
            case 'html':
                return self::get_inner_html( $doc, $selector, $schema['multiline'] ?? null );
            case 'text':
                return self::get_text_content( $doc, $selector );
            case 'attribute':
                return self::get_dom_attribute( $doc, $selector, $schema['attribute'] ?? '' );
            case 'raw':
                return $html;
            case 'query':
                return self::query_elements( $doc, $selector, $schema['query'] ?? [] );
            case 'tag':
                return self::get_tag_name( $doc, $selector );
            default:
                return $schema['default'] ?? null;
        }
    }

    /**
     * Gets inner HTML of an element matching selector
     *
     * @param DOMDocument $doc       DOM document
     * @param string|null $selector  CSS-like selector
     * @param string|null $multiline Multiline tag type
     * @return string|null
     */
    private static function get_inner_html( $doc, $selector, $multiline = null ) {
        $node = self::query_selector( $doc, $selector );
        if ( ! $node ) {
            return null;
        }

        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= $doc->saveHTML( $child );
        }

        return trim( $html );
    }

    /**
     * Gets text content of an element matching selector
     *
     * @param DOMDocument $doc      DOM document
     * @param string|null $selector CSS-like selector
     * @return string|null
     */
    private static function get_text_content( $doc, $selector ) {
        $node = self::query_selector( $doc, $selector );
        if ( ! $node ) {
            return null;
        }

        return trim( $node->textContent );
    }

    /**
     * Gets an attribute value from an element matching selector
     *
     * @param DOMDocument $doc       DOM document
     * @param string|null $selector  CSS-like selector
     * @param string      $attribute Attribute name
     * @return string|null
     */
    private static function get_dom_attribute( $doc, $selector, $attribute ) {
        $node = self::query_selector( $doc, $selector );
        if ( ! $node || ! $node instanceof DOMElement ) {
            return null;
        }

        if ( ! $node->hasAttribute( $attribute ) ) {
            return null;
        }

        return $node->getAttribute( $attribute );
    }

    /**
     * Gets the tag name of an element matching selector
     *
     * @param DOMDocument $doc      DOM document
     * @param string|null $selector CSS-like selector
     * @return string|null
     */
    private static function get_tag_name( $doc, $selector ) {
        $node = self::query_selector( $doc, $selector );
        if ( ! $node ) {
            return null;
        }

        return strtolower( $node->nodeName );
    }

    /**
     * Queries multiple elements and extracts data based on sub-schema
     *
     * @param DOMDocument $doc      DOM document
     * @param string|null $selector CSS-like selector
     * @param array       $query    Query schema for each element
     * @return array
     */
    private static function query_elements( $doc, $selector, $query ) {
        $nodes = self::query_selector_all( $doc, $selector );
        $results = [];

        foreach ( $nodes as $node ) {
            $item = [];
            foreach ( $query as $key => $sub_schema ) {
                $temp_doc = new DOMDocument();
                $imported = $temp_doc->importNode( $node, true );
                $temp_doc->appendChild( $imported );

                $item[ $key ] = self::parse_attribute( $temp_doc, $sub_schema, $temp_doc->saveHTML() );
            }
            $results[] = $item;
        }

        return $results;
    }

    /**
     * Simple CSS selector query (supports tag, .class, #id, and combinations)
     *
     * @param DOMDocument $doc      DOM document
     * @param string|null $selector CSS-like selector
     * @return DOMNode|null
     */
    public static function query_selector( $doc, $selector ) {
        if ( empty( $selector ) ) {
            $body = $doc->getElementsByTagName( 'body' )->item( 0 );
            return $body ? $body->firstChild : null;
        }

        $xpath = new DOMXPath( $doc );
        $xpath_query = self::css_to_xpath( $selector );
        $nodes = $xpath->query( $xpath_query );

        return $nodes && $nodes->length > 0 ? $nodes->item( 0 ) : null;
    }

    /**
     * Query all elements matching selector
     *
     * @param DOMDocument $doc      DOM document
     * @param string|null $selector CSS-like selector
     * @return DOMNodeList|array
     */
    public static function query_selector_all( $doc, $selector ) {
        if ( empty( $selector ) ) {
            return [];
        }

        $xpath = new DOMXPath( $doc );
        $xpath_query = self::css_to_xpath( $selector );
        $nodes = $xpath->query( $xpath_query );

        return $nodes ?: [];
    }

    /**
     * Converts a simple CSS selector to XPath
     *
     * @param string $selector CSS selector
     * @return string XPath query
     */
    private static function css_to_xpath( $selector ) {
        $selectors = preg_split( '/\s*,\s*/', trim( $selector ) );
        $xpath_parts = [];

        foreach ( $selectors as $sel ) {
            $xpath_parts[] = self::single_css_to_xpath( $sel );
        }

        return implode( ' | ', $xpath_parts );
    }

    /**
     * Converts a single CSS selector to XPath
     *
     * @param string $selector Single CSS selector
     * @return string XPath query
     */
    private static function single_css_to_xpath( $selector ) {
        $parts = preg_split( '/\s+/', trim( $selector ) );
        $xpath = '';

        foreach ( $parts as $i => $part ) {
            $prefix = $i === 0 ? '//' : '//';

            if ( preg_match( '/^([a-z0-9]+)?(?:\.([a-z0-9_-]+))?(?:#([a-z0-9_-]+))?(?:\[([a-z-]+)(?:=["\']?([^"\'\]]+)["\']?)?\])?$/i', $part, $matches ) ) {
                $tag = ! empty( $matches[1] ) ? $matches[1] : '*';
                $class = $matches[2] ?? null;
                $id = $matches[3] ?? null;
                $attr_name = $matches[4] ?? null;
                $attr_value = $matches[5] ?? null;

                $xpath .= $prefix . $tag;

                if ( $class ) {
                    $xpath .= "[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
                }
                if ( $id ) {
                    $xpath .= "[@id='{$id}']";
                }
                if ( $attr_name ) {
                    if ( $attr_value !== null ) {
                        $xpath .= "[@{$attr_name}='{$attr_value}']";
                    } else {
                        $xpath .= "[@{$attr_name}]";
                    }
                }
            } else {
                $xpath .= $prefix . $part;
            }
        }

        return $xpath;
    }
}
