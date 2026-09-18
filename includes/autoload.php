<?php
/**
 * Class autoloading for the 2.x module layout.
 *
 * Kept in its own file so the legacy compatibility shims can install it too.
 * If a stale 1.x bootstrap is still executing, its own autoloader cannot
 * resolve names containing underscores, such as SMAO\Report_Table, and it
 * looks in the old flat directory. Registering this loader from a shim repairs
 * both problems without the site ever raising a fatal.
 *
 * @package SMAO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'smao_register_autoloader' ) ) {

	/**
	 * Register the module autoloader exactly once.
	 *
	 * @return void
	 */
	function smao_register_autoloader(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		spl_autoload_register(
			static function ( string $class_name ): void {
				if ( ! str_starts_with( $class_name, 'SMAO\\' ) ) {
					return;
				}
				$name = substr( $class_name, 5 );
				if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/D', $name ) ) {
					return;
				}
				$file = 'class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
				foreach ( array( 'core', 'engine', 'admin', 'delivery' ) as $module ) {
					$path = __DIR__ . '/' . $module . '/' . $file;
					if ( is_file( $path ) ) {
						require_once $path;
						return;
					}
				}
			}
		);
	}
}

smao_register_autoloader();
