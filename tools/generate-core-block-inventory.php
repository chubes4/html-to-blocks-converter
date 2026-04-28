<?php
/**
 * Generate a normalized inventory of WordPress core block metadata.
 *
 * Usage:
 *   php tools/generate-core-block-inventory.php /path/to/wp-includes/blocks > docs/core-block-inventory.json
 */

// phpcs:disable

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$blocks_dir = $argv[1] ?? '';
if ( $blocks_dir === '' || ! is_dir( $blocks_dir ) ) {
	fwrite( STDERR, "Usage: php tools/generate-core-block-inventory.php /path/to/wp-includes/blocks\n" );
	exit( 1 );
}

$blocks_dir = rtrim( $blocks_dir, DIRECTORY_SEPARATOR );
$files      = glob( $blocks_dir . '/*/block.json' );
if ( ! is_array( $files ) || empty( $files ) ) {
	fwrite( STDERR, "No block.json files found under {$blocks_dir}\n" );
	exit( 1 );
}

$blocks = [];
foreach ( $files as $file ) {
	$raw  = file_get_contents( $file );
	$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $data ) || empty( $data['name'] ) ) {
		fwrite( STDERR, "Invalid block metadata: {$file}\n" );
		exit( 1 );
	}

	$attributes = [];
	foreach ( (array) ( $data['attributes'] ?? [] ) as $attribute_name => $schema ) {
		$attributes[ $attribute_name ] = [
			'type'    => $schema['type'] ?? null,
			'default' => array_key_exists( 'default', $schema ) ? $schema['default'] : null,
		];
	}
	ksort( $attributes );

	$blocks[ $data['name'] ] = [
		'name'            => $data['name'],
		'title'           => $data['title'] ?? null,
		'category'        => $data['category'] ?? null,
		'attributes'      => $attributes,
		'supports'        => $data['supports'] ?? new stdClass(),
		'allowedBlocks'   => $data['allowedBlocks'] ?? [],
		'parent'          => $data['parent'] ?? [],
		'ancestor'        => $data['ancestor'] ?? [],
		'usesContext'     => $data['usesContext'] ?? [],
		'providesContext' => $data['providesContext'] ?? new stdClass(),
		'selectors'       => $data['selectors'] ?? new stdClass(),
	];
}

ksort( $blocks );

echo json_encode(
	[
		'generated_from' => 'wp-includes/blocks/*/block.json',
		'block_count'    => count( $blocks ),
		'blocks'         => array_values( $blocks ),
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
