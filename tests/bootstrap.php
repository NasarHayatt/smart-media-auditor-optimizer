<?php
define( 'ABSPATH', __DIR__ . '/fake-wordpress/' );
function __( $text, $domain = '' ) { return $text; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['smao_test_uploads'] ); }
spl_autoload_register( static function ( $class ) {
	if ( str_starts_with( $class, 'SMAO\\' ) ) {
		require_once dirname( __DIR__ ) . '/includes/class-' . strtolower( substr( $class, 5 ) ) . '.php';
	}
} );
