<?php
/**
 * Autoloader.
 *
 * @package BazaarBasheSync
 */

namespace BazaarBashe\Sync;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload plugin classes.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = strtolower( str_replace( '_', '-', substr( $class_name, strlen( $prefix ) ) ) );
		$relative = str_replace( '\\', '/', $relative );
		$parts    = explode( '/', $relative );
		$file     = 'class-' . array_pop( $parts ) . '.php';
		$subpath  = implode( '/', $parts );

		$paths = array();

		if ( '' !== $subpath ) {
			$paths[] = BBSYNC_PATH . $subpath . '/' . $file;
		}

		$paths[] = BBSYNC_PATH . 'includes/' . $file;
		$paths[] = BBSYNC_PATH . $file;

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
