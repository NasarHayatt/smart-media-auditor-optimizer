<?php
// Use ONLY a disposable, already installed WordPress database.
$bootstrap = getenv( 'SMAO_WP_LOAD' );
if ( ! $bootstrap || ! is_file( $bootstrap ) || '1' !== getenv( 'SMAO_ALLOW_TEST_WRITES' ) ) {
	fwrite( STDERR, "Set SMAO_WP_LOAD to a disposable wp-load.php and SMAO_ALLOW_TEST_WRITES=1.\n" );
	exit( 1 );
}
require_once $bootstrap;
require_once dirname( __DIR__, 2 ) . '/smart-media-auditor-optimizer.php';
SMAO\Plugin::boot();
SMAO\Database::install();
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
