<?php
/**
 * Smoke test: public transform capability inventory.
 *
 * Run: php tests/smoke-transform-capabilities.php
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ );

require_once dirname( __DIR__ ) . '/includes/class-block-factory.php';
require_once dirname( __DIR__ ) . '/raw-handler.php';
require_once dirname( __DIR__ ) . '/includes/capabilities.php';

$failures   = [];
$assertions = 0;

$assert = static function ( $condition, $label, $detail = '' ) use ( &$failures, &$assertions ) {
	$assertions++;
	if ( ! $condition ) {
		$failures[] = 'FAIL [' . $label . ']' . ( '' !== $detail ? ': ' . $detail : '' );
	}
};

$capabilities = html_to_blocks_get_capabilities();
$blocks       = array_fill_keys( $capabilities['transforms']['supported_core_blocks'] ?? [], true );

$assert( isset( $capabilities['version'] ) && is_string( $capabilities['version'] ), 'capabilities-include-version' );
$assert( 'html_to_blocks_raw_handler' === ( $capabilities['raw_handler']['function'] ?? null ), 'capabilities-name-raw-handler' );
$assert( 'blocks-engine' === ( $capabilities['transforms']['provider'] ?? null ), 'capabilities-use-blocks-engine-provider' );
$assert( [] === ( $capabilities['transforms']['families'] ?? null ), 'capabilities-do-not-expose-legacy-transform-families' );
$assert( [] === ( $capabilities['transforms']['explicit_markers'] ?? null ), 'capabilities-do-not-expose-legacy-marker-transforms' );

foreach ( [ 'core/paragraph', 'core/heading', 'core/list', 'core/image', 'core/group', 'core/navigation' ] as $block_name ) {
	$assert( isset( $blocks[ $block_name ] ), 'capabilities-include-' . $block_name );
}

$assert( 'html_to_blocks_unsupported_html_fallback' === ( $capabilities['hooks']['unsupported_html_fallback'] ?? null ), 'capabilities-include-fallback-hook' );

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
