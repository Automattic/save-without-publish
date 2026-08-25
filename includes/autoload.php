<?php
/**
 * Class autoloader.
 *
 * Maps `SaveWithoutPublish\Some_Class` to `includes/class-some-class.php`, the
 * file-naming convention WordPress coding standards expect.
 *
 * @package SaveWithoutPublish
 */

declare( strict_types = 1 );

namespace SaveWithoutPublish;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		$path     = __DIR__ . '/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
