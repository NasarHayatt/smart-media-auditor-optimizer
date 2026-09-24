<?php
/**
 * Browser caching and compression.
 *
 * A returning visitor should not download the same unchanged stylesheet, script
 * or image again. WordPress already appends a version to asset URLs, so those
 * files can be cached for a long time and invalidated by the version changing.
 *
 * Where the server reads .htaccess this is configured there, so static files
 * never touch PHP. Everywhere else the rules are applied from PHP for the
 * requests that do reach WordPress, and the equivalent server configuration is
 * shown in the interface to paste in.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Cache-Control, Expires and validators.
 */
final class Headers {

	/**
	 * Marker around our block in .htaccess.
	 */
	private const START = '# BEGIN Smart Media Auditor';

	/**
	 * Closing marker.
	 */
	private const END = '# END Smart Media Auditor';

	/**
	 * How long each family of assets may be cached, in seconds.
	 *
	 * @return array<string,int>
	 */
	public static function lifetimes(): array {
		return array(
			'css'   => YEAR_IN_SECONDS,
			'js'    => YEAR_IN_SECONDS,
			'fonts' => YEAR_IN_SECONDS,
			'image' => MONTH_IN_SECONDS * 6,
			'media' => MONTH_IN_SECONDS,
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'sync' ) );

		if ( is_admin() || ! self::active() ) {
			return;
		}
		add_action( 'send_headers', array( self::class, 'html_headers' ) );
	}

	/**
	 * Whether this plugin should manage caching headers.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return (bool) Settings::get()['browser_cache'] && '' === Environment::conflict( 'cache' );
	}

	/**
	 * Send correct caching headers for the HTML document itself.
	 *
	 * HTML is never cached for long: it is the thing that changes. Private
	 * pages are marked so no shared cache or proxy may keep them.
	 *
	 * @return void
	 */
	public static function html_headers(): void {
		if ( headers_sent() || is_admin() ) {
			return;
		}
		$private = is_user_logged_in() || '' !== Cache::bypass();
		if ( $private ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
			header( 'Pragma: no-cache' );
			return;
		}
		header( 'Cache-Control: public, max-age=0, must-revalidate' );
	}

	/**
	 * The .htaccess block this site should have.
	 *
	 * @return string
	 */
	public static function rules(): string {
		$life  = self::lifetimes();
		$lines = array( self::START );

		$lines[] = '<IfModule mod_expires.c>';
		$lines[] = '  ExpiresActive On';
		foreach ( array(
			'text/css'                => $life['css'],
			'application/javascript'  => $life['js'],
			'text/javascript'         => $life['js'],
			'image/jpeg'              => $life['image'],
			'image/png'               => $life['image'],
			'image/gif'               => $life['image'],
			'image/webp'              => $life['image'],
			'image/avif'              => $life['image'],
			'image/svg+xml'           => $life['image'],
			'image/x-icon'            => $life['image'],
			'font/woff2'              => $life['fonts'],
			'font/woff'               => $life['fonts'],
			'application/font-woff2'  => $life['fonts'],
			'video/mp4'               => $life['media'],
			'audio/mpeg'              => $life['media'],
		) as $mime => $seconds ) {
			$lines[] = sprintf( '  ExpiresByType %s "access plus %d seconds"', $mime, $seconds );
		}
		$lines[] = '  ExpiresByType text/html "access plus 0 seconds"';
		$lines[] = '</IfModule>';

		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = '  <FilesMatch "\.(css|js|mjs)$">';
		$lines[] = sprintf( '    Header set Cache-Control "public, max-age=%d, immutable"', $life['css'] );
		$lines[] = '  </FilesMatch>';
		$lines[] = '  <FilesMatch "\.(woff2?|ttf|otf|eot)$">';
		$lines[] = sprintf( '    Header set Cache-Control "public, max-age=%d, immutable"', $life['fonts'] );
		$lines[] = '    Header set Access-Control-Allow-Origin "*"';
		$lines[] = '  </FilesMatch>';
		$lines[] = '  <FilesMatch "\.(jpe?g|png|gif|webp|avif|svg|ico)$">';
		$lines[] = sprintf( '    Header set Cache-Control "public, max-age=%d"', $life['image'] );
		$lines[] = '  </FilesMatch>';
		$lines[] = '  <FilesMatch "\.(mp4|webm|mp3|ogg|pdf)$">';
		$lines[] = sprintf( '    Header set Cache-Control "public, max-age=%d"', $life['media'] );
		$lines[] = '  </FilesMatch>';
		$lines[] = '</IfModule>';

		$lines[] = '<IfModule mod_deflate.c>';
		$lines[] = '  AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript';
		$lines[] = '  AddOutputFilterByType DEFLATE application/javascript application/json application/xml';
		$lines[] = '  AddOutputFilterByType DEFLATE image/svg+xml application/rss+xml';
		$lines[] = '</IfModule>';

		$lines[] = '<IfModule mod_brotli.c>';
		$lines[] = '  AddOutputFilterByType BROTLI_COMPRESS text/html text/css text/javascript';
		$lines[] = '  AddOutputFilterByType BROTLI_COMPRESS application/javascript application/json image/svg+xml';
		$lines[] = '</IfModule>';

		$lines[] = self::END;
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The equivalent nginx configuration, for pasting into a server block.
	 *
	 * @return string
	 */
	public static function nginx_rules(): string {
		$life = self::lifetimes();
		return implode(
			"\n",
			array(
				'location ~* \.(css|js|mjs)$ {',
				sprintf( '    add_header Cache-Control "public, max-age=%d, immutable";', $life['css'] ),
				'}',
				'location ~* \.(woff2?|ttf|otf|eot)$ {',
				sprintf( '    add_header Cache-Control "public, max-age=%d, immutable";', $life['fonts'] ),
				'    add_header Access-Control-Allow-Origin "*";',
				'}',
				'location ~* \.(jpe?g|png|gif|webp|avif|svg|ico)$ {',
				sprintf( '    add_header Cache-Control "public, max-age=%d";', $life['image'] ),
				'}',
				'gzip on;',
				'gzip_types text/css text/javascript application/javascript application/json image/svg+xml;',
			)
		) . "\n";
	}

	/**
	 * Write or remove our .htaccess block.
	 *
	 * @return void
	 */
	public static function sync(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$environment = Environment::get();
		if ( ! $environment['server']['apache'] ) {
			return; // Nothing to write; the interface shows the nginx rules.
		}
		if ( self::active() ) {
			self::write_htaccess();
		} else {
			self::remove_htaccess();
		}
	}

	/**
	 * Insert our block into the site's .htaccess.
	 *
	 * @return bool
	 */
	public static function write_htaccess(): bool {
		$file = self::htaccess_path();
		if ( '' === $file ) {
			return false;
		}
		$existing = file_exists( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$stripped = self::strip( $existing );
		$contents = self::rules() . "\n" . ltrim( $stripped );
		if ( $existing === $contents ) {
			return true;
		}
		if ( file_exists( $file ) && ! is_writable( $file ) ) {
			return false;
		}
		return false !== file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remove our block, leaving the rest of the file untouched.
	 *
	 * @return bool
	 */
	public static function remove_htaccess(): bool {
		$file = self::htaccess_path();
		if ( '' === $file || ! file_exists( $file ) || ! is_writable( $file ) ) {
			return false;
		}
		$existing = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		if ( ! str_contains( $existing, self::START ) ) {
			return true;
		}
		return false !== file_put_contents( $file, self::strip( $existing ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remove a previous block from a file's contents.
	 *
	 * @param string $contents File contents.
	 * @return string
	 */
	public static function strip( string $contents ): string {
		$pattern = '/' . preg_quote( self::START, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '\s*/s';
		return (string) preg_replace( $pattern, '', $contents );
	}

	/**
	 * Whether our block is currently installed.
	 *
	 * @return bool
	 */
	public static function installed(): bool {
		$file = self::htaccess_path();
		if ( '' === $file || ! file_exists( $file ) ) {
			return false;
		}
		return str_contains( (string) file_get_contents( $file ), self::START ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	}

	/**
	 * Path to the site's root .htaccess.
	 *
	 * @return string
	 */
	private static function htaccess_path(): string {
		$home = function_exists( 'get_home_path' ) ? get_home_path() : Environment::home_path();
		if ( '' === $home ) {
			return '';
		}
		return untrailingslashit( wp_normalize_path( $home ) ) . '/.htaccess';
	}
}
