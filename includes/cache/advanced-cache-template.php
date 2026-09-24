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

	$root = smao_cache_root( WP_CONTENT_DIR );
	$config = $root . '/config.json';
	if ( ! is_readable( $config ) ) {
		return;
	}
	$settings = json_decode( (string) file_get_contents( $config ), true );
	if ( ! is_array( $settings ) || empty( $settings['enabled'] ) ) {
		return;
	}

	if ( '' !== smao_cache_bypass_reason( $_SERVER, $_COOKIE ) ) {
		return;
	}

	$key  = smao_cache_key( $_SERVER, ! empty( $settings['separate_mobile'] ) );
	$file = smao_cache_path( $root, $key, 'html' );
	if ( '' === $file || ! is_readable( $file ) ) {
		return;
	}

	$age = time() - (int) filemtime( $file );
	$ttl = (int) ( $settings['ttl'] ?? 0 );
	if ( $ttl > 0 && $age > $ttl ) {
		return;
	}

	// Honour a conditional request without sending the body at all.
	$modified = gmdate( 'D, d M Y H:i:s', (int) filemtime( $file ) ) . ' GMT';
	$etag     = '"' . substr( $key, 0, 32 ) . '-' . filemtime( $file ) . '"';

	$since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
	$match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
	if ( ( '' !== $match && trim( (string) $match ) === $etag ) || ( '' !== $since && strtotime( (string) $since ) >= (int) filemtime( $file ) ) ) {
		header( 'HTTP/1.1 304 Not Modified' );
		header( 'X-SMAO-Cache: HIT-304' );
		exit;
	}

	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'X-SMAO-Cache: HIT' );
	header( 'X-SMAO-Cache-Age: ' . $age );
	header( 'Last-Modified: ' . $modified );
	header( 'ETag: ' . $etag );
	header( 'Cache-Control: public, max-age=0, s-maxage=0, must-revalidate' );
	header( 'Vary: Accept-Encoding' );

	// Serve the pre-compressed copy when the browser accepts it.
	$encodings = strtolower( (string) ( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '' ) );
	$gzip      = smao_cache_path( $root, $key, 'gz' );
	if ( str_contains( $encodings, 'gzip' ) && '' !== $gzip && is_readable( $gzip ) ) {
		header( 'Content-Encoding: gzip' );
		header( 'Content-Length: ' . filesize( $gzip ) );
		readfile( $gzip );
		exit;
	}

	header( 'Content-Length: ' . filesize( $file ) );
	readfile( $file );
	exit;
} )();
