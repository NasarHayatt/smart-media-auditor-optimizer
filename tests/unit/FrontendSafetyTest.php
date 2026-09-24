<?php
/**
 * Guards against calling wp-admin functions on the front end.
 *
 * Environment detection ran get_home_path(), which only exists inside
 * wp-admin. On an Apache or LiteSpeed host that produced a fatal error on
 * every front-end request, and because the result is cached only after the
 * call succeeds, it never recovered. Local testing missed it because PHP's
 * built-in server is not Apache, so the && short-circuited before reaching it.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;

/**
 * Front-end code may only use functions that exist on the front end.
 */
final class FrontendSafetyTest extends TestCase {

	/**
	 * Functions WordPress only defines inside wp-admin.
	 *
	 * @return array
	 */
	private function adminOnly(): array {
		return array(
			'get_home_path',
			'WP_Filesystem',
			'request_filesystem_credentials',
			'wp_handle_upload',
			'media_handle_upload',
			'download_url',
			'unzip_file',
			'dbDelta',
			'get_plugins',
			'install_plugin_install_status',
			'wp_get_current_user_id',
		);
	}

	/**
	 * Directories whose code runs on ordinary front-end requests.
	 *
	 * @return array
	 */
	private function frontendDirs(): array {
		return array( 'core', 'engine', 'speed', 'delivery', 'cache' );
	}

	/**
	 * No front-end file may call an admin-only function unguarded.
	 *
	 * A call is acceptable when the same file checks function_exists() for it,
	 * or requires the admin include first.
	 *
	 * @return void
	 */
	public function test_no_unguarded_admin_functions_on_the_front_end(): void {
		$root      = dirname( __DIR__, 2 ) . '/includes';
		$offenders = array();

		foreach ( $this->frontendDirs() as $dir ) {
			$path = $root . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			foreach ( glob( $path . '/*.php' ) as $file ) {
				$code = (string) file_get_contents( $file );
				foreach ( $this->adminOnly() as $function ) {
					if ( ! preg_match( '/\b' . preg_quote( $function, '/' ) . '\s*\(/', $code ) ) {
						continue;
					}
					$guarded = str_contains( $code, "function_exists( '$function' )" )
						|| str_contains( $code, "wp-admin/includes" );
					if ( ! $guarded ) {
						$offenders[] = basename( $file ) . ' calls ' . $function . '()';
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Front-end code must not call wp-admin functions unguarded:\n" . implode( "\n", $offenders )
		);
	}

	/**
	 * The replacement for get_home_path() exists and is public.
	 *
	 * @return void
	 */
	public function test_home_path_helper_is_available(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/speed/class-environment.php' );
		$this->assertStringContainsString( 'public static function home_path()', $code );
		$this->assertStringContainsString( "function_exists( 'get_home_path' )", $code );
	}

	/**
	 * Shared cache rules must be loaded before anything can call them.
	 *
	 * The header layer runs on send_headers, which fires before
	 * template_redirect. Loading the rules only in template_redirect left the
	 * functions undefined, which was a fatal error on every request.
	 *
	 * @return void
	 */
	public function test_cache_rules_are_loaded_from_boot(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/cache/class-cache.php' );

		$boot = strstr( $code, 'public static function boot' );
		$this->assertIsString( $boot );
		$this->assertStringContainsString(
			'self::rules();',
			substr( (string) $boot, 0, 500 ),
			'boot() must load the shared rules before any hook can need them'
		);

		$bypass = strstr( $code, 'public static function bypass' );
		$this->assertIsString( $bypass );
		$this->assertStringContainsString(
			'self::rules();',
			substr( (string) $bypass, 0, 300 ),
			'bypass() is reachable from send_headers and must load the rules itself'
		);
	}

	/**
	 * Server detection must not short-circuit away a risky call.
	 *
	 * The bug hid because htaccess_writable() sat behind "$apache &&", so it
	 * only ran on Apache. The helper must now be safe to call unconditionally.
	 *
	 * @return void
	 */
	public function test_htaccess_check_is_safe_regardless_of_server(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/speed/class-environment.php' );
		// The guard must sit inside the helper, not at the call site.
		$helper = strstr( $code, 'private static function htaccess_writable' );
		$this->assertIsString( $helper );
		$this->assertStringContainsString( "function_exists( 'get_home_path' )", substr( (string) $helper, 0, 800 ) );
	}
}
