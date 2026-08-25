<?php
/**
 * PHPUnit bootstrap.
 *
 * @package ArrayPress\InlineSync
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// wp-composer-assets works out an asset's public URL by comparing its path
// against these, so they have to exist before it is asked for one.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', dirname( __DIR__, 3 ) );
}

if ( ! defined( 'WP_CONTENT_URL' ) ) {
	define( 'WP_CONTENT_URL', 'https://example.test/wp-content' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

require_once __DIR__ . '/stubs.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/*
 * And src/Functions.php again: it is a Composer `files` entry, so it already
 * ran when PHPUnit loaded the autoloader -- before ABSPATH was defined, so it
 * returned without declaring anything. `require`, not `require_once`.
 */
require dirname( __DIR__ ) . '/src/Functions.php';
