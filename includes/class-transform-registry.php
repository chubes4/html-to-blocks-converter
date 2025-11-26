<?php
/**
 * Transform Registry - PHP raw transforms mirroring Gutenberg JS transforms
 *
 * PHP port of transforms from packages/block-library/src transforms.js files
 * Only type raw transforms for server-side HTML-to-blocks conversion
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HTML_To_Blocks_Transform_Registry {

    private static $transforms = null;

    /**
     * Gets all raw transforms for core blocks
     * Sorted by priority (lower = higher priority)
     *
     * @return array Array of transform definitions
     */
    public static function get_raw_transforms() {
        if ( self::$transforms !== null ) {
            return self::$transforms;
        }

        self::$transforms = array_merge(
            self::get_heading_transforms(),
            self::get_list_transforms(),
            self::get_image_transforms(),
            self::get_quote_transforms(),
            self::get_code_transforms(),
            self::get_preformatted_transforms(),
            self::get_separator_transforms(),
            self::get_table_transforms(),
            self::get_paragraph_transforms()
        );

        usort( self::$transforms, function( $a, $b ) {
            return ( $a['priority'] ?? 10 ) - ( $b['priority'] ?? 10 );
        } );

        return self::$transforms;
    }

    /**
     * core/heading transforms - h1-h6 elements
     */
    private static function get_heading_transforms() {
        return [
            [
                'blockName' => 'core/heading',
                'priority'  => 10,
                'selector'  => 'h1,h2,h3,h4,h5,h6',
                'isMatch'   => function( $node ) {
                    return preg_match( '/^H[1-6]$/i', $node->nodeName );
                },
                'transform' => function( $node ) {
                    $level = (int) substr( $node->nodeName, 1 );
                    $content = HTML_To_Blocks_Block_Factory::get_inner_html( $node );
                    $attributes = [ 'level' => $level, 'content' => $content ];

                    if ( $node instanceof DOMElement && $node->hasAttribute( 'id' ) ) {
                        $attributes['anchor'] = $node->getAttribute( 'id' );
                    }

                    if ( $node instanceof DOMElement && $node->hasAttribute( 'style' ) ) {
                        $style = $node->getAttribute( 'style' );
                        if ( preg_match( '/text-align:\s*(left|center|right)/i', $style, $matches ) ) {
                            $attributes['textAlign'] = strtolower( $matches[1] );
                        }
                    }

                    return HTML_To_Blocks_Block_Factory::create_block( 'core/heading', $attributes );
                },
            ],
        ];
    }

    /**
     * core/list transforms - ol and ul elements
     */
    private static function get_list_transforms() {
        return [
            [
                'blockName' => 'core/list',
                'priority'  => 10,
                'selector'  => 'ol,ul',
                'isMatch'   => function( $node ) {
                    return in_array( strtoupper( $node->nodeName ), [ 'OL', 'UL' ], true );
                },
                'transform' => function( $node ) {
                    return self::create_list_block_from_element( $node );
                },
            ],
        ];
    }

    /**
     * Creates a list block from a DOM element (recursive for nested lists)
     *
     * @param DOMElement $list_element The ol/ul element
     * @return array Block array
     */
    private static function create_list_block_from_element( $list_element ) {
        $ordered = strtoupper( $list_element->nodeName ) === 'OL';

        $list_attributes = [
            'ordered' => $ordered,
        ];

        if ( $list_element instanceof DOMElement ) {
            if ( $list_element->hasAttribute( 'id' ) && $list_element->getAttribute( 'id' ) !== '' ) {
                $list_attributes['anchor'] = $list_element->getAttribute( 'id' );
            }
            if ( $list_element->hasAttribute( 'start' ) ) {
                $list_attributes['start'] = (int) $list_element->getAttribute( 'start' );
            }
            if ( $list_element->hasAttribute( 'reversed' ) ) {
                $list_attributes['reversed'] = true;
            }
            if ( $list_element->hasAttribute( 'type' ) ) {
                $type = $list_element->getAttribute( 'type' );
                $type_map = [
                    'A' => 'upper-alpha',
                    'a' => 'lower-alpha',
                    'I' => 'upper-roman',
                    'i' => 'lower-roman',
                ];
                if ( isset( $type_map[ $type ] ) ) {
                    $list_attributes['type'] = $type_map[ $type ];
                }
            }
        }

        $inner_blocks = [];
        foreach ( $list_element->childNodes as $child ) {
            if ( $child->nodeType !== XML_ELEMENT_NODE || strtoupper( $child->nodeName ) !== 'LI' ) {
                continue;
            }

            $list_item_block = self::create_list_item_block( $child );
            if ( $list_item_block ) {
                $inner_blocks[] = $list_item_block;
            }
        }

        return HTML_To_Blocks_Block_Factory::create_block( 'core/list', $list_attributes, $inner_blocks );
    }

    /**
     * Creates a list-item block from an li element
     *
     * @param DOMElement $li_element The li element
     * @return array Block array
     */
    private static function create_list_item_block( $li_element ) {
        $content_parts = [];
        $nested_list = null;

        foreach ( $li_element->childNodes as $child ) {
            if ( $child->nodeType === XML_ELEMENT_NODE ) {
                $tag = strtoupper( $child->nodeName );
                if ( $tag === 'OL' || $tag === 'UL' ) {
                    $nested_list = $child;
                    continue;
                }
            }

            if ( $child->nodeType === XML_TEXT_NODE ) {
                $text = $child->textContent;
                if ( trim( $text ) !== '' ) {
                    $content_parts[] = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
                }
            } else {
                $content_parts[] = $li_element->ownerDocument->saveHTML( $child );
            }
        }

        $content = trim( implode( '', $content_parts ) );
        $inner_blocks = [];

        if ( $nested_list ) {
            $inner_blocks[] = self::create_list_block_from_element( $nested_list );
        }

        return HTML_To_Blocks_Block_Factory::create_block(
            'core/list-item',
            [ 'content' => $content ],
            $inner_blocks
        );
    }

    /**
     * core/image transforms - figure with img
     */
    private static function get_image_transforms() {
        return [
            [
                'blockName' => 'core/image',
                'priority'  => 10,
                'isMatch'   => function( $node ) {
                    if ( strtoupper( $node->nodeName ) !== 'FIGURE' ) {
                        return false;
                    }
                    $imgs = $node->getElementsByTagName( 'img' );
                    return $imgs->length > 0;
                },
                'transform' => function( $node ) {
                    $img = $node->getElementsByTagName( 'img' )->item( 0 );
                    $figcaption = $node->getElementsByTagName( 'figcaption' )->item( 0 );

                    $attributes = [
                        'url' => $img->getAttribute( 'src' ),
                    ];

                    if ( $img->hasAttribute( 'alt' ) ) {
                        $attributes['alt'] = $img->getAttribute( 'alt' );
                    }

                    if ( $img->hasAttribute( 'title' ) ) {
                        $attributes['title'] = $img->getAttribute( 'title' );
                    }

                    if ( $figcaption ) {
                        $attributes['caption'] = HTML_To_Blocks_Block_Factory::get_inner_html( $figcaption );
                    }

                    $class_name = '';
                    if ( $node instanceof DOMElement && $node->hasAttribute( 'class' ) ) {
                        $class_name .= $node->getAttribute( 'class' ) . ' ';
                    }
                    if ( $img->hasAttribute( 'class' ) ) {
                        $class_name .= $img->getAttribute( 'class' );
                    }
                    $class_name = trim( $class_name );

                    if ( preg_match( '/(?:^|\s)align(left|center|right)(?:$|\s)/', $class_name, $matches ) ) {
                        $attributes['align'] = $matches[1];
                    }

                    if ( preg_match( '/(?:^|\s)wp-image-(\d+)(?:$|\s)/', $class_name, $matches ) ) {
                        $attributes['id'] = (int) $matches[1];
                    }

                    if ( $node instanceof DOMElement && $node->hasAttribute( 'id' ) && $node->getAttribute( 'id' ) !== '' ) {
                        $attributes['anchor'] = $node->getAttribute( 'id' );
                    }

                    $anchor_element = $node->getElementsByTagName( 'a' )->item( 0 );
                    if ( $anchor_element && $anchor_element->hasAttribute( 'href' ) ) {
                        $attributes['href'] = $anchor_element->getAttribute( 'href' );
                        $attributes['linkDestination'] = 'custom';

                        if ( $anchor_element->hasAttribute( 'rel' ) ) {
                            $attributes['rel'] = $anchor_element->getAttribute( 'rel' );
                        }
                        if ( $anchor_element->hasAttribute( 'class' ) ) {
                            $attributes['linkClass'] = $anchor_element->getAttribute( 'class' );
                        }
                    }

                    return HTML_To_Blocks_Block_Factory::create_block( 'core/image', $attributes );
                },
            ],
            [
                'blockName' => 'core/image',
                'priority'  => 15,
                'isMatch'   => function( $node ) {
                    return strtoupper( $node->nodeName ) === 'IMG';
                },
                'transform' => function( $node ) {
                    $attributes = [
                        'url' => $node->getAttribute( 'src' ),
                    ];

                    if ( $node->hasAttribute( 'alt' ) ) {
                        $attributes['alt'] = $node->getAttribute( 'alt' );
                    }

                    if ( $node->hasAttribute( 'title' ) ) {
                        $attributes['title'] = $node->getAttribute( 'title' );
                    }

                    $class_name = $node->hasAttribute( 'class' ) ? $node->getAttribute( 'class' ) : '';

                    if ( preg_match( '/(?:^|\s)align(left|center|right)(?:$|\s)/', $class_name, $matches ) ) {
                        $attributes['align'] = $matches[1];
                    }

                    if ( preg_match( '/(?:^|\s)wp-image-(\d+)(?:$|\s)/', $class_name, $matches ) ) {
                        $attributes['id'] = (int) $matches[1];
                    }

                    return HTML_To_Blocks_Block_Factory::create_block( 'core/image', $attributes );
                },
            ],
        ];
    }

    /**
     * core/quote transforms - blockquote elements
     */
    private static function get_quote_transforms() {
        return [
            [
                'blockName' => 'core/quote',
                'priority'  => 10,
                'selector'  => 'blockquote',
                'isMatch'   => function( $node ) {
                    return strtoupper( $node->nodeName ) === 'BLOCKQUOTE';
                },
                'transform' => function( $node, $handler ) {
                    $inner_html = HTML_To_Blocks_Block_Factory::get_inner_html( $node );
                    $inner_blocks = $handler( [ 'HTML' => $inner_html ] );

                    $attributes = [];
                    if ( $node instanceof DOMElement && $node->hasAttribute( 'id' ) && $node->getAttribute( 'id' ) !== '' ) {
                        $attributes['anchor'] = $node->getAttribute( 'id' );
                    }

                    return HTML_To_Blocks_Block_Factory::create_block( 'core/quote', $attributes, $inner_blocks );
                },
            ],
        ];
    }

    /**
     * core/code transforms - pre > code elements
     */
    private static function get_code_transforms() {
        return [
            [
                'blockName' => 'core/code',
                'priority'  => 10,
                'isMatch'   => function( $node ) {
                    if ( strtoupper( $node->nodeName ) !== 'PRE' ) {
                        return false;
                    }
                    $children = [];
                    foreach ( $node->childNodes as $child ) {
                        if ( $child->nodeType === XML_ELEMENT_NODE ) {
                            $children[] = $child;
                        }
                    }
                    return count( $children ) === 1
                        && strtoupper( $children[0]->nodeName ) === 'CODE';
                },
                'transform' => function( $node ) {
                    $code = $node->getElementsByTagName( 'code' )->item( 0 );
                    $content = $code ? $code->textContent : $node->textContent;

                    return HTML_To_Blocks_Block_Factory::create_block( 'core/code', [
                        'content' => $content,
                    ] );
                },
            ],
        ];
    }

    /**
     * core/preformatted transforms - pre elements (not containing code)
     */
    private static function get_preformatted_transforms() {
        return [
            [
                'blockName' => 'core/preformatted',
                'priority'  => 11,
                'isMatch'   => function( $node ) {
                    if ( strtoupper( $node->nodeName ) !== 'PRE' ) {
                        return false;
                    }
                    $children = [];
                    foreach ( $node->childNodes as $child ) {
                        if ( $child->nodeType === XML_ELEMENT_NODE ) {
                            $children[] = $child;
                        }
                    }
                    $is_code = count( $children ) === 1
                        && strtoupper( $children[0]->nodeName ) === 'CODE';
                    return ! $is_code;
                },
                'transform' => function( $node ) {
                    $content = HTML_To_Blocks_Block_Factory::get_inner_html( $node );

                    $attributes = [ 'content' => $content ];
                    if ( $node instanceof DOMElement && $node->hasAttribute( 'id' ) && $node->getAttribute( 'id' ) !== '' ) {
                        $attributes['anchor'] = $node->getAttribute( 'id' );
                    }

                    return HTML_To_Blocks_Block_Factory::create_block( 'core/preformatted', $attributes );
                },
            ],
        ];
    }

    /**
     * core/separator transforms - hr elements
     */
    private static function get_separator_transforms() {
        return [
            [
                'blockName' => 'core/separator',
                'priority'  => 10,
                'selector'  => 'hr',
                'isMatch'   => function( $node ) {
                    return strtoupper( $node->nodeName ) === 'HR';
                },
                'transform' => function( $node ) {
                    $attributes = [];

                    if ( $node instanceof DOMElement && $node->hasAttribute( 'class' ) ) {
                        $class = $node->getAttribute( 'class' );
                        if ( strpos( $class, 'is-style-wide' ) !== false ) {
                            $attributes['className'] = 'is-style-wide';
                        } elseif ( strpos( $class, 'is-style-dots' ) !== false ) {
                            $attributes['className'] = 'is-style-dots';
                        }
                    }

                    return HTML_To_Blocks_Block_Factory::create_block( 'core/separator', $attributes );
                },
            ],
        ];
    }

    /**
     * core/table transforms - table elements
     */
    private static function get_table_transforms() {
        return [
            [
                'blockName' => 'core/table',
                'priority'  => 10,
                'selector'  => 'table',
                'isMatch'   => function( $node ) {
                    return strtoupper( $node->nodeName ) === 'TABLE';
                },
                'transform' => function( $node ) {
                    return self::create_table_block_from_element( $node );
                },
            ],
        ];
    }

    /**
     * Creates a table block from a DOM element
     *
     * @param DOMElement $table_element The table element
     * @return array Block array
     */
    private static function create_table_block_from_element( $table_element ) {
        $head = [];
        $body = [];
        $foot = [];

        $thead = $table_element->getElementsByTagName( 'thead' )->item( 0 );
        $tbody = $table_element->getElementsByTagName( 'tbody' )->item( 0 );
        $tfoot = $table_element->getElementsByTagName( 'tfoot' )->item( 0 );

        if ( $thead ) {
            $head = self::extract_table_rows( $thead );
        }

        if ( $tbody ) {
            $body = self::extract_table_rows( $tbody );
        } else {
            $body = self::extract_table_rows( $table_element, true );
        }

        if ( $tfoot ) {
            $foot = self::extract_table_rows( $tfoot );
        }

        $attributes = [
            'head' => $head,
            'body' => $body,
            'foot' => $foot,
        ];

        if ( $table_element instanceof DOMElement && $table_element->hasAttribute( 'id' ) && $table_element->getAttribute( 'id' ) !== '' ) {
            $attributes['anchor'] = $table_element->getAttribute( 'id' );
        }

        $caption = $table_element->getElementsByTagName( 'caption' )->item( 0 );
        if ( $caption ) {
            $attributes['caption'] = HTML_To_Blocks_Block_Factory::get_inner_html( $caption );
        }

        return HTML_To_Blocks_Block_Factory::create_block( 'core/table', $attributes );
    }

    /**
     * Extracts rows from a table section
     *
     * @param DOMElement $section       The thead/tbody/tfoot element
     * @param bool       $exclude_thead Skip thead rows when processing table directly
     * @return array Array of row data
     */
    private static function extract_table_rows( $section, $exclude_thead = false ) {
        $rows = [];
        $tr_elements = $section->getElementsByTagName( 'tr' );

        foreach ( $tr_elements as $tr ) {
            if ( $exclude_thead && $tr->parentNode->nodeName === 'thead' ) {
                continue;
            }

            $cells = [];
            foreach ( $tr->childNodes as $cell ) {
                if ( $cell->nodeType !== XML_ELEMENT_NODE ) {
                    continue;
                }
                $tag = strtoupper( $cell->nodeName );
                if ( $tag !== 'TD' && $tag !== 'TH' ) {
                    continue;
                }

                $cell_data = [
                    'content' => HTML_To_Blocks_Block_Factory::get_inner_html( $cell ),
                    'tag'     => strtolower( $tag ),
                ];

                if ( $cell->hasAttribute( 'colspan' ) ) {
                    $cell_data['colspan'] = (int) $cell->getAttribute( 'colspan' );
                }
                if ( $cell->hasAttribute( 'rowspan' ) ) {
                    $cell_data['rowspan'] = (int) $cell->getAttribute( 'rowspan' );
                }

                $cells[] = $cell_data;
            }

            if ( ! empty( $cells ) ) {
                $rows[] = [ 'cells' => $cells ];
            }
        }

        return $rows;
    }

    /**
     * core/paragraph transforms - p elements (lowest priority, fallback)
     */
    private static function get_paragraph_transforms() {
        return [
            [
                'blockName' => 'core/paragraph',
                'priority'  => 20,
                'selector'  => 'p',
                'isMatch'   => function( $node ) {
                    return strtoupper( $node->nodeName ) === 'P';
                },
                'transform' => function( $node ) {
                    $content = HTML_To_Blocks_Block_Factory::get_inner_html( $node );
                    $attributes = [ 'content' => $content ];

                    if ( $node instanceof DOMElement && $node->hasAttribute( 'id' ) ) {
                        $attributes['anchor'] = $node->getAttribute( 'id' );
                    }

                    if ( $node instanceof DOMElement && $node->hasAttribute( 'style' ) ) {
                        $style = $node->getAttribute( 'style' );
                        if ( preg_match( '/text-align:\s*(left|center|right)/i', $style, $matches ) ) {
                            $attributes['style'] = [
                                'typography' => [
                                    'textAlign' => strtolower( $matches[1] ),
                                ],
                            ];
                        }
                    }

                    return HTML_To_Blocks_Block_Factory::create_block( 'core/paragraph', $attributes );
                },
            ],
        ];
    }
}
