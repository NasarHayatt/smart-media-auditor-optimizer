<?php
/**
 * Plugin Name: Smart Media Auditor & Optimizer
 * Description: Find out where every image is actually used, remove what is not, and make the pages that remain load faster.
 * Version: 2.7.0
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

if ( ! defined( 'SMAO_FILE' ) ) {
	define( 'SMAO_FILE', __FILE__ );
}
if ( ! defined( 'SMAO_VERSION' ) ) {
	define( 'SMAO_VERSION', '2.7.0' );
}

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
