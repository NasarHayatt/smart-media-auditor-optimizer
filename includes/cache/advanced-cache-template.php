<?php
/**
 * Smart Media Auditor page cache drop-in.
 *
 * Generated automatically. Do not edit: the plugin overwrites this file, and
 * deleting it simply disables the fastest path rather than breaking the site.
 *
 * This runs from wp-settings.php before plugins, the theme or the database are
 * loaded, so a cache hit costs one file read instead of a full WordPress boot.
 * That is what moves Time to First Byte.
 *
 * @package SMAO
 */

defined( 'ABSPATH' ) || exit;

define( 'SMAO_CACHE_BOOTSTRAP', true );

( static function (): void {
	// Only ever answer a real web request. WP-CLI, cron and any other
	// command-line entry point must load WordPress normally.
	if ( 'cli' === PHP_SAPI || 'phpdbg' === PHP_SAPI || defined( 'WP_CLI' ) ) {
		return;
	}
	if ( defined( 'DOING_CRON' ) || defined( 'WP_INSTALLING' ) || defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( empty( $_SERVER['REQUEST_METHOD'] ) || empty( $_SERVER['HTTP_HOST'] ) ) {
		return;
	}

	$rules = '%%RULES%%';
	if ( ! is_readable( $rules ) ) {
		return;
	}
	require_once $rules;
	if ( ! function_exists( 'smao_cache_serve' ) ) {
		return;
	}

	$root   = smao_cache_root( WP_CONTENT_DIR );
	$config = $root . '/config.json';
	if ( ! is_readable( $config ) ) {
		return;
	}
	$settings = json_decode( (string) file_get_contents( $config ), true );
	if ( ! is_array( $settings ) || empty( $settings['enabled'] ) ) {
		return;
	}

	if ( smao_cache_serve( $root, $settings, 'HIT' ) ) {
		exit;
	}
} )();
