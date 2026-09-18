<?php
/**
 * Plugin Name: Smart Media Auditor & Optimizer
 * Description: Find out where every image is actually used, remove what is not, and make the pages that remain load faster.
 * Version: 2.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Smart Media Auditor Contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-media-auditor-optimizer
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

define( 'SMAO_FILE', __FILE__ );
define( 'SMAO_VERSION', '2.0.0' );

/**
 * Map SMAO\Some_Class to includes/<module>/class-some-class.php.
 */
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
			$path = __DIR__ . '/includes/' . $module . '/' . $file;
			if ( is_file( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
