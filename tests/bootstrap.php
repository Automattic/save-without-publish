<?php
/**
 * PHPUnit bootstrap.
 *
 * Containment and fail-safe behaviour are properties of WordPress core, not of
 * this plugin's code, so the suite runs against a real WordPress rather than
 * mocks (see the plan's Verification Contract).
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

$swpub_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $swpub_autoload ) ) {
	echo "Run `composer install` before the test suite.\n";
	exit( 1 );
}

require_once $swpub_autoload;

$swpub_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $swpub_tests_dir ) {
	$swpub_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $swpub_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/save-without-publish.php';
	}
);

require $swpub_tests_dir . '/includes/bootstrap.php';
