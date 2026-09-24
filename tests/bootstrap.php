<?php
/**
 * Unit-test bootstrap.
 *
 * Loads engine classes in isolation with the small slice of WordPress they
 * touch stubbed out. No database and no WordPress install are required.
 *
 * @package SMAO
 */

define( 'ABSPATH', __DIR__ . '/fake-wordpress/' );

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub translation.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Stub path normalisation.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Stub uploads directory.
	 *
	 * @return array
	 */
	function wp_upload_dir() {
		return array( 'basedir' => $GLOBALS['smao_test_uploads'] );
	}
}

spl_autoload_register(
	static function ( $class ) {
		if ( ! str_starts_with( $class, 'SMAO\\' ) ) {
			return;
		}
		$file = 'class-' . str_replace( '_', '-', strtolower( substr( $class, 5 ) ) ) . '.php';
		foreach ( array( 'core', 'engine', 'admin', 'delivery', 'speed' ) as $module ) {
			$path = dirname( __DIR__ ) . '/includes/' . $module . '/' . $file;
			if ( is_file( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub filter application.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

// Minimal $wpdb stand-in for scope rules that only need the table prefix.
if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class() {
		public $prefix = 'wp_';
	};
}
