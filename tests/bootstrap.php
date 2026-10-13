<?php
/**
 * PHPUnit bootstrap.
 *
 * Containment and fail-safe behaviour are properties of WordPress core, not of
 * this plugin's code, so the suite runs against a real WordPress rather than
 * mocks (see the plan's Verification Contract).
 *
 * @package StagedEdits
 */

declare( strict_types = 1 );

$staged_edits_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $staged_edits_autoload ) ) {
	echo "Run `composer install` before the test suite.\n";
	exit( 1 );
}

require_once $staged_edits_autoload;

$staged_edits_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $staged_edits_tests_dir ) {
	$staged_edits_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $staged_edits_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/staged-edits.php';
	}
);

require $staged_edits_tests_dir . '/includes/bootstrap.php';
