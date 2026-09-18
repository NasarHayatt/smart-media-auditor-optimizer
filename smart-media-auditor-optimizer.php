<?php
/**
 * Plugin Name: Smart Media Auditor & Optimizer
 * Description: Conservative media usage audits, recoverable quarantine and backup-first image optimization.
 * Version: 1.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Smart Media Auditor Contributors
 * License: GPL-2.0-or-later
 * Text Domain: smart-media-auditor-optimizer
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

define( 'SMAO_FILE', __FILE__ );
define( 'SMAO_VERSION', '1.2.0' );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'SMAO\\' ) ) {
			$name = substr( $class_name, 5 );
			if ( preg_match( '/^[A-Za-z][A-Za-z0-9]*$/D', $name ) ) {
				$file = __DIR__ . '/includes/class-' . strtolower( $name ) . '.php';
				if ( is_file( $file ) ) {
					require_once $file;
				}
			}
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
