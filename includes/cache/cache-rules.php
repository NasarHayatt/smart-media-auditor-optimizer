<?php
/**
 * Cache rules shared by WordPress and the advanced-cache drop-in.
 *
 * The drop-in runs before WordPress is loaded, so nothing in this file may use
 * a WordPress function, constant or global. Both sides must agree exactly on
 * the cache key and the bypass rules, otherwise a request could be stored under
 * one key and served under another.
 *
 * @package SMAO
 */

defined( 'ABSPATH' ) || defined( 'SMAO_CACHE_BOOTSTRAP' ) || exit;

if ( ! function_exists( 'smao_cache_root' ) ) {

	/**
	 * Absolute path to the cache directory.
	 *
	 * @param string $content_dir Path to wp-content.
	 * @return string
	 */
	function smao_cache_root( string $content_dir ): string {
		return rtrim( str_replace( '\\', '/', $content_dir ), '/' ) . '/cache/smao';
	}

	/**
	 * Query parameters that never change the rendered page.
	 *
	 * Stripping these means a link shared with campaign tracking still hits the
	 * same cache entry instead of generating a new one for every visitor.
	 *
	 * @return array
	 */
	function smao_cache_ignored_query(): array {
		return array(
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'utm_id',
			'gclid',
			'gbraid',
			'wbraid',
			'fbclid',
			'msclkid',
			'mc_cid',
			'mc_eid',
			'ttclid',
			'igshid',
			'_ga',
			'ref',
			'age-verified',
			'usqp',
		);
	}

	/**
	 * Cookie name fragments that mean the response is personal.
	 *
	 * @return array
	 */
	function smao_cache_private_cookies(): array {
		return array(
			'wordpress_logged_in_',
			'wp-postpass_',
			'comment_author_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'wp_woocommerce_session_',
			'edd_items_in_cart',
			'wordpress_sec_',
			'wp-resetpass-',
			'smao_nocache',
		);
	}

	/**
	 * URL path fragments that must never be cached.
	 *
	 * @return array
	 */
	function smao_cache_private_paths(): array {
		return array(
			'/wp-admin',
			'/wp-login',
			'/wp-json',
			'/xmlrpc.php',
			'/wp-cron.php',
			'/cart',
			'/checkout',
			'/my-account',
			'/account',
			'/basket',
			'/order-received',
			'/order-pay',
			'/lost-password',
			'/add-to-cart',
			'/wc-api',
			'/edd-api',
			'/logout',
			'/register',
			'/feed',
			'/sitemap',
			'.xml',
			'.txt',
		);
	}

	/**
	 * Decide whether a request may be served from, or written to, the cache.
	 *
	 * @param array $server  The $_SERVER superglobal.
	 * @param array $cookies The $_COOKIE superglobal.
	 * @return string Empty when cacheable, otherwise the reason it is not.
	 */
	function smao_cache_bypass_reason( array $server, array $cookies ): string {
		$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return 'method';
		}
		if ( ! empty( $server['HTTP_X_REQUESTED_WITH'] ) && 'xmlhttprequest' === strtolower( (string) $server['HTTP_X_REQUESTED_WITH'] ) ) {
			return 'ajax';
		}

		$uri  = (string) ( $server['REQUEST_URI'] ?? '/' );
		$path = (string) parse_url( $uri, PHP_URL_PATH );
		$path = '' === $path ? '/' : strtolower( $path );

		foreach ( smao_cache_private_paths() as $fragment ) {
			if ( str_contains( $path, $fragment ) ) {
				return 'path';
			}
		}

		foreach ( $cookies as $name => $value ) {
			foreach ( smao_cache_private_cookies() as $fragment ) {
				if ( str_starts_with( (string) $name, $fragment ) ) {
					return 'cookie';
				}
			}
		}

		// Any query parameter we do not recognise may change the output, so the
		// safe default is to serve it dynamically rather than guess.
		$query = (string) parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $parsed );
			$ignored = smao_cache_ignored_query();
			foreach ( array_keys( $parsed ) as $key ) {
				if ( ! in_array( (string) $key, $ignored, true ) ) {
					return 'query';
				}
			}
		}

		return '';
	}

	/**
	 * Whether the request looks like a mobile device.
	 *
	 * @param array $server The $_SERVER superglobal.
	 * @return bool
	 */
	function smao_cache_is_mobile( array $server ): bool {
		$agent = strtolower( (string) ( $server['HTTP_USER_AGENT'] ?? '' ) );
		if ( '' === $agent ) {
			return false;
		}
		foreach ( array( 'mobile', 'android', 'iphone', 'ipod', 'blackberry', 'windows phone', 'opera mini' ) as $needle ) {
			if ( str_contains( $agent, $needle ) ) {
				return ! str_contains( $agent, 'ipad' );
			}
		}
		return false;
	}

	/**
	 * Whether the browser says it accepts WebP images.
	 *
	 * Chrome, Edge and Firefox say so on every page request. A browser that
	 * does not is given the page with original images, from its own cache
	 * entry, so it never receives an image it cannot show.
	 *
	 * @param array $server The $_SERVER superglobal.
	 * @return bool
	 */
	function smao_cache_wants_webp( array $server ): bool {
		return str_contains( strtolower( (string) ( $server['HTTP_ACCEPT'] ?? '' ) ), 'image/webp' );
	}

	/**
	 * Build the cache key for a request.
	 *
	 * @param array $server          The $_SERVER superglobal.
	 * @param bool  $separate_mobile Whether mobile gets its own entry.
	 * @param bool  $webp            Whether pages differ by WebP support.
	 * @return string 64 character hex digest.
	 */
	function smao_cache_key( array $server, bool $separate_mobile, bool $webp = false ): string {
		$host = strtolower( (string) ( $server['HTTP_HOST'] ?? 'localhost' ) );
		$host = preg_replace( '/[^a-z0-9.\-:]/', '', $host );

		$uri  = (string) ( $server['REQUEST_URI'] ?? '/' );
		$path = (string) parse_url( $uri, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;

		$https = ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] );
		if ( ! $https && isset( $server['HTTP_X_FORWARDED_PROTO'] ) ) {
			$https = 'https' === strtolower( (string) $server['HTTP_X_FORWARDED_PROTO'] );
		}

		$device = $separate_mobile && smao_cache_is_mobile( $server ) ? 'mobile' : 'desktop';

		$variant = $webp && smao_cache_wants_webp( $server ) ? '|webp' : '';

		return hash( 'sha256', ( $https ? 'https://' : 'http://' ) . $host . $path . '|' . $device . $variant );
	}

	/**
	 * Answer the current request from the cache, if a valid copy exists.
	 *
	 * Shared by the advanced-cache drop-in and the in-plugin fallback, so both
	 * paths make the same decision and send the same headers.
	 *
	 * @param string $root   Cache root.
	 * @param array  $config Keys: separate_mobile (bool), ttl (seconds).
	 * @param string $label  Value for the X-SMAO-Cache header on a hit.
	 * @return bool True when a response was sent and the caller should exit.
	 */
	function smao_cache_serve( string $root, array $config, string $label ): bool {
		// Something already printed output, so headers cannot be set and the
		// page would arrive corrupted. Let WordPress handle it normally.
		if ( headers_sent() ) {
			return false;
		}
		if ( '' !== smao_cache_bypass_reason( $_SERVER, $_COOKIE ) ) {
			return false;
		}

		$key  = smao_cache_key( $_SERVER, ! empty( $config['separate_mobile'] ), ! empty( $config['webp'] ) );
		$file = smao_cache_path( $root, $key, 'html' );
		if ( '' === $file || ! is_readable( $file ) ) {
			return false;
		}

		$mtime = (int) filemtime( $file );
		$age   = time() - $mtime;
		$ttl   = (int) ( $config['ttl'] ?? 0 );
		if ( $ttl > 0 && $age > $ttl ) {
			return false;
		}

		$modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';
		$etag     = '"' . substr( $key, 0, 32 ) . '-' . $mtime . '"';

		// Answer a conditional request without sending the body at all.
		$match = (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' );
		$since = (string) ( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '' );
		if ( ( '' !== $match && trim( $match ) === $etag ) || ( '' !== $since && strtotime( $since ) >= $mtime ) ) {
			header( 'HTTP/1.1 304 Not Modified' );
			header( 'X-SMAO-Cache: ' . $label . '-304' );
			return true;
		}

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-SMAO-Cache: ' . $label );
		header( 'X-SMAO-Cache-Age: ' . $age );
		header( 'Last-Modified: ' . $modified );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: public, max-age=0, s-maxage=0, must-revalidate' );
		header( 'Vary: ' . ( empty( $config['webp'] ) ? 'Accept-Encoding' : 'Accept-Encoding, Accept' ) );

		$encodings = strtolower( (string) ( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '' ) );
		$gzip      = smao_cache_path( $root, $key, 'gz' );
		// Do not double-compress when the server already applies gzip itself.
		$server_compresses = (bool) ini_get( 'zlib.output_compression' );
		if ( ! $server_compresses && str_contains( $encodings, 'gzip' ) && '' !== $gzip && is_readable( $gzip ) ) {
			header( 'Content-Encoding: gzip' );
			header( 'Content-Length: ' . filesize( $gzip ) );
			if ( 'HEAD' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
				readfile( $gzip );
			}
			return true;
		}

		header( 'Content-Length: ' . filesize( $file ) );
		if ( 'HEAD' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			readfile( $file );
		}
		return true;
	}

	/**
	 * Absolute path to the cached file for a key.
	 *
	 * The key is always a hex digest produced above, so it can never contain a
	 * path separator or traversal sequence.
	 *
	 * @param string $root      Cache root.
	 * @param string $key       Cache key.
	 * @param string $extension File extension, html or gz.
	 * @return string
	 */
	function smao_cache_path( string $root, string $key, string $extension = 'html' ): string {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
			return '';
		}
		$extension = in_array( $extension, array( 'html', 'gz', 'meta' ), true ) ? $extension : 'html';
		return $root . '/' . substr( $key, 0, 2 ) . '/' . $key . '.' . $extension;
	}
}
