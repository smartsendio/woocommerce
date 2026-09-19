<?php
/**
 * Load plugin classes directly from the shipped source files.
 *
 * @package Smart_Send
 */

namespace Smart_Send;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'Smart_Send\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'Smart_Send\\' ) );
		// Accept class-name segments only: never resolve arbitrary paths.
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*(?:\\\\[A-Za-z][A-Za-z0-9_]*)*$/D', $relative ) ) {
			return;
		}

		$roots = [
			'Admin\\'    => dirname( __DIR__ ) . '/admin/',
			'Frontend\\' => dirname( __DIR__ ) . '/public/',
		];
		$base  = __DIR__ . '/';
		foreach ( $roots as $prefix => $directory ) {
			if ( 0 === strpos( $relative, $prefix ) ) {
				$base     = $directory;
				$relative = substr( $relative, strlen( $prefix ) );
				break;
			}
		}

		$segments = explode( '\\', strtolower( str_replace( '_', '-', $relative ) ) );
		$filename = 'class-' . array_pop( $segments ) . '.php';
		$path     = $base . ( $segments ? implode( '/', $segments ) . '/' : '' ) . $filename;
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);
