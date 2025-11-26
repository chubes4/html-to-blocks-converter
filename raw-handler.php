<?php
/**
 * Raw handler pipeline ported from Gutenberg JavaScript to PHP
 *
 * This file contains the main raw_handler function that converts HTML to blocks.
 * It uses the Transform_Registry for block transforms and Block_Factory for block creation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main raw handler function - converts HTML to blocks
 *
 * @param array $args Arguments array with 'HTML' key
 * @return array Array of block arrays
 */
function html_to_blocks_raw_handler( $args ) {
    $html = $args['HTML'] ?? '';

    if ( empty( $html ) ) {
        return [];
    }

    if ( strpos( $html, '<!-- wp:' ) !== false ) {
        $blocks = parse_blocks( $html );
        $is_single_freeform = count( $blocks ) === 1
            && isset( $blocks[0]['blockName'] )
            && $blocks[0]['blockName'] === 'core/freeform';
        if ( ! $is_single_freeform ) {
            return $blocks;
        }
    }

    $pieces = html_to_blocks_shortcode_converter( $html );

    $result = [];
    foreach ( $pieces as $piece ) {
        if ( ! is_string( $piece ) ) {
            $result[] = $piece;
            continue;
        }

        $piece = html_to_blocks_normalise_blocks( $piece );
        $blocks = html_to_blocks_convert( $piece );
        $result = array_merge( $result, $blocks );
    }

    return array_filter( $result );
}

/**
 * Converts HTML directly to blocks using registered transforms
 *
 * @param string $html HTML to convert
 * @return array Array of blocks
 */
function html_to_blocks_convert( $html ) {
    if ( empty( trim( $html ) ) ) {
        return [];
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $body = $doc->getElementsByTagName( 'body' )->item( 0 );
    if ( ! $body ) {
        return [];
    }

    $blocks = [];
    $transforms = HTML_To_Blocks_Transform_Registry::get_raw_transforms();

    foreach ( $body->childNodes as $node ) {
        if ( $node->nodeType === XML_TEXT_NODE ) {
            $text = trim( $node->textContent );
            if ( ! empty( $text ) ) {
                $blocks[] = HTML_To_Blocks_Block_Factory::create_block( 'core/paragraph', [
                    'content' => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ),
                ] );
            }
            continue;
        }

        if ( $node->nodeType !== XML_ELEMENT_NODE ) {
            continue;
        }

        $raw_transform = html_to_blocks_find_transform( $node, $transforms );

        if ( ! $raw_transform ) {
            $blocks[] = HTML_To_Blocks_Block_Factory::create_block( 'core/html', [
                'content' => $doc->saveHTML( $node ),
            ] );
        } else {
            $transform_fn = $raw_transform['transform'] ?? null;

            if ( $transform_fn && is_callable( $transform_fn ) ) {
                $block = call_user_func( $transform_fn, $node, 'html_to_blocks_raw_handler' );

                if ( $node instanceof DOMElement && $node->hasAttribute( 'class' ) ) {
                    $existing_class = $block['attrs']['className'] ?? '';
                    $node_class = $node->getAttribute( 'class' );
                    if ( ! empty( $node_class ) && strpos( $existing_class, $node_class ) === false ) {
                        $block['attrs']['className'] = trim( $existing_class . ' ' . $node_class );
                    }
                }

                $blocks[] = $block;
            } else {
                $block_name = $raw_transform['blockName'];
                $attributes = HTML_To_Blocks_Attribute_Parser::get_block_attributes(
                    $block_name,
                    $doc->saveHTML( $node )
                );
                $blocks[] = HTML_To_Blocks_Block_Factory::create_block( $block_name, $attributes );
            }
        }
    }

    return $blocks;
}

/**
 * Finds a matching raw transform for a node
 *
 * @param DOMNode $node       The node to match
 * @param array   $transforms Array of transforms
 * @return array|null The transform data or null
 */
function html_to_blocks_find_transform( $node, $transforms ) {
    foreach ( $transforms as $transform ) {
        $is_match = $transform['isMatch'] ?? null;
        if ( $is_match && is_callable( $is_match ) && call_user_func( $is_match, $node ) ) {
            return $transform;
        }
    }
    return null;
}

/**
 * Converts shortcodes in HTML to blocks
 *
 * @param string $html The HTML containing shortcodes
 * @return array Array of pieces (strings or blocks)
 */
function html_to_blocks_shortcode_converter( $html ) {
    $pieces = [];
    $last_index = 0;

    preg_match_all( '/' . get_shortcode_regex() . '/', $html, $matches, PREG_OFFSET_CAPTURE );

    if ( empty( $matches[0] ) ) {
        return [ $html ];
    }

    foreach ( $matches[0] as $match ) {
        $shortcode = $match[0];
        $index = $match[1];

        if ( $index > $last_index ) {
            $pieces[] = substr( $html, $last_index, $index - $last_index );
        }

        $parsed = html_to_blocks_parse_shortcode( $shortcode );
        $pieces[] = $parsed !== null ? $parsed : $shortcode;

        $last_index = $index + strlen( $shortcode );
    }

    if ( $last_index < strlen( $html ) ) {
        $pieces[] = substr( $html, $last_index );
    }

    return $pieces;
}

/**
 * Parses a shortcode and returns a block if possible
 *
 * @param string $shortcode The shortcode string
 * @return array|null The block array or null
 */
function html_to_blocks_parse_shortcode( $shortcode ) {
    $pattern = get_shortcode_regex();
    if ( ! preg_match( "/$pattern/", $shortcode, $match ) ) {
        return null;
    }

    $tag = $match[2];
    $content = $match[5] ?? '';

    return HTML_To_Blocks_Block_Factory::create_block( 'core/shortcode', [
        'text' => $shortcode,
    ] );
}

/**
 * Normalises blocks in HTML - wraps inline content in paragraphs
 *
 * @param string $html The HTML
 * @return string The normalized HTML
 */
function html_to_blocks_normalise_blocks( $html ) {
    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $doc->loadHTML(
        '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $body = $doc->getElementsByTagName( 'body' )->item( 0 );
    if ( ! $body ) {
        return $html;
    }

    $result_doc = new DOMDocument();
    $result_body = $result_doc->createElement( 'body' );
    $result_doc->appendChild( $result_body );

    $current_paragraph = null;
    $phrasing_tags = [
        'A', 'ABBR', 'B', 'BDI', 'BDO', 'BR', 'CITE', 'CODE', 'DATA', 'DFN',
        'EM', 'I', 'KBD', 'MARK', 'Q', 'RP', 'RT', 'RUBY', 'S', 'SAMP',
        'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TIME', 'U', 'VAR', 'WBR',
    ];

    foreach ( $body->childNodes as $node ) {
        if ( $node->nodeType === XML_TEXT_NODE ) {
            $text = $node->textContent;
            if ( trim( $text ) === '' ) {
                continue;
            }

            if ( ! $current_paragraph ) {
                $current_paragraph = $result_doc->createElement( 'p' );
                $result_body->appendChild( $current_paragraph );
            }
            $current_paragraph->appendChild( $result_doc->importNode( $node, true ) );
            continue;
        }

        if ( $node->nodeType !== XML_ELEMENT_NODE ) {
            continue;
        }

        $tag = strtoupper( $node->nodeName );

        if ( $tag === 'BR' ) {
            $next = $node->nextSibling;
            if ( $next && $next->nodeType === XML_ELEMENT_NODE && strtoupper( $next->nodeName ) === 'BR' ) {
                $current_paragraph = null;
                continue;
            }
            if ( $current_paragraph && $current_paragraph->hasChildNodes() ) {
                $current_paragraph->appendChild( $result_doc->importNode( $node, true ) );
            }
            continue;
        }

        if ( in_array( $tag, $phrasing_tags, true ) ) {
            if ( ! $current_paragraph ) {
                $current_paragraph = $result_doc->createElement( 'p' );
                $result_body->appendChild( $current_paragraph );
            }
            $current_paragraph->appendChild( $result_doc->importNode( $node, true ) );
            continue;
        }

        $current_paragraph = null;
        $result_body->appendChild( $result_doc->importNode( $node, true ) );
    }

    $output = '';
    foreach ( $result_body->childNodes as $child ) {
        $output .= $result_doc->saveHTML( $child );
    }

    return $output;
}
