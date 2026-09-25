<?php
/**
 * Page cache.
 *
 * Stores the finished HTML of public pages so a repeat request can be answered
 * from a file. The fast path lives in the advanced-cache drop-in, which serves
 * a hit before WordPress boots; this class decides what may be stored, writes
 * it, and keeps the drop-in and its configuration in step.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Full page caching.
 */
final class Cache {

	/**
	 * Marker written into every cached page.
	 */
	private const SIGNATURE = '<!-- cached by Smart Media Auditor -->';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		// Load once, unconditionally. Several callers reach the shared rules
		// before template_redirect, notably the header layer on send_headers,
		// and a missing function there is a fatal error on every request.
		self::rules();

		add_action( 'admin_init', array( self::class, 'sync' ) );

		if ( is_admin() || ! self::active() ) {
			return;
		}

		self::serve_fallback();

		/*
		 * Start first so this buffer is the outermost one. Buffers started
		 * later, such as the script and stylesheet rewrites, then finish
		 * before this one stores the page, and the cached copy carries their
		 * changes. Started second, the cache stored the page unrewritten.
		 */
		add_action( 'template_redirect', array( self::class, 'start' ), -1000 );
	}

	/**
	 * Serve a stored page from inside the plugin when the drop-in is not active.
	 *
	 * The drop-in is the fastest path, but it needs WP_CACHE in wp-config.php,
	 * which many hosts do not let a plugin write. Without this fallback such a
	 * site stored pages and never served them. Answering here, at
	 * plugins_loaded, still skips the theme, the page builder and the query,
	 * which is where most of the time goes.
	 *
	 * @return void
	 */
	private static function serve_fallback(): void {
		if ( defined( 'SMAO_CACHE_BOOTSTRAP' ) ) {
			return; // The drop-in already looked and found nothing valid.
		}
		if ( 'cli' === PHP_SAPI || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) ) {
			return;
		}
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || empty( $_SERVER['HTTP_HOST'] ) ) {
			return;
		}
		$settings = Settings::get();
		$config   = array(
			'separate_mobile' => (bool) $settings['separate_mobile'],
			'ttl'             => (int) $settings['cache_ttl'] * HOUR_IN_SECONDS,
			'webp'            => Delivery::active(),
		);
		if ( smao_cache_serve( self::root(), $config, 'HIT-PHP' ) ) {
			exit;
		}
	}

	/**
	 * Explain which serving path is active and, if it is not the fastest, why.
	 *
	 * Modes: off, unwritable, php (served by the plugin), dropin (fastest).
	 *
	 * @return array{mode:string,problems:array<int,string>,fix:string}
	 */
	public static function diagnose(): array {
		if ( ! self::active() ) {
			return array(
				'mode'     => 'off',
				'problems' => array(),
				'fix'      => '',
			);
		}

		$problems = array();
		$fix      = '';
		$dropin   = WP_CONTENT_DIR . '/advanced-cache.php';

		if ( file_exists( $dropin ) && ! self::owns_dropin( $dropin ) ) {
			$problems[] = __( 'Another caching system already owns wp-content/advanced-cache.php, probably your host. Pages are served from the plugin instead, which is slower but still much faster than no cache.', 'smart-media-auditor-optimizer' );
		} elseif ( ! file_exists( $dropin ) ) {
			$problems[] = __( 'The fast-path file wp-content/advanced-cache.php could not be created. Check that wp-content is writable.', 'smart-media-auditor-optimizer' );
		}
		if ( ! self::wp_cache_defined() ) {
			$problems[] = __( 'WP_CACHE is not switched on in wp-config.php, so WordPress never loads the fast path.', 'smart-media-auditor-optimizer' );
			$fix        = "define( 'WP_CACHE', true );";
		}
		$root     = self::root();
		$storable = is_dir( $root ) ? wp_is_writable( $root ) : wp_is_writable( WP_CONTENT_DIR );
		if ( ! $storable ) {
			return array(
				'mode'     => 'unwritable',
				'problems' => array( __( 'The cache folder wp-content/cache/smao is not writable, so no page can be stored. Ask your host to make wp-content writable by WordPress.', 'smart-media-auditor-optimizer' ) ),
				'fix'      => '',
			);
		}

		return array(
			'mode'     => $problems ? 'php' : 'dropin',
			'problems' => $problems,
			'fix'      => $fix,
		);
	}

	/**
	 * Whether this plugin should be doing page caching at all.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return (bool) Settings::get()['page_cache'] && '' === Environment::conflict( 'cache' );
	}

	/**
	 * Absolute path to the cache directory.
	 *
	 * @return string
	 */
	public static function root(): string {
		self::rules();
		return smao_cache_root( WP_CONTENT_DIR );
	}

	/**
	 * Load the rules shared with the drop-in.
	 *
	 * @return void
	 */
	private static function rules(): void {
		if ( ! function_exists( 'smao_cache_bypass_reason' ) ) {
			require_once __DIR__ . '/cache-rules.php';
		}
	}

	/**
	 * Begin buffering a page that is allowed to be cached.
	 *
	 * @return void
	 */
	public static function start(): void {
		self::rules();

		$reason = self::bypass();
		if ( '' !== $reason ) {
			if ( ! headers_sent() ) {
				header( 'X-SMAO-Cache: BYPASS-' . strtoupper( $reason ) );
			}
			return;
		}
		if ( ! headers_sent() ) {
			header( 'X-SMAO-Cache: MISS' );
		}
		ob_start( array( self::class, 'store' ) );
	}

	/**
	 * Reasons this particular response must not be cached.
	 *
	 * The shared rules cover everything visible from the request alone. These
	 * are the checks that need WordPress to have decided what the page is.
	 *
	 * @return string
	 */
	public static function bypass(): string {
		self::rules();
		$shared = smao_cache_bypass_reason( $_SERVER, $_COOKIE ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request inspection.
		if ( '' !== $shared ) {
			return $shared;
		}
		if ( is_user_logged_in() ) {
			return 'loggedin';
		}
		if ( is_admin() || is_feed() || is_preview() || is_search() || is_404() || is_trackback() || is_customize_preview() ) {
			return 'dynamic';
		}
		if ( post_password_required() ) {
			return 'password';
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return 'donotcache';
		}
		if ( function_exists( 'is_woocommerce' ) ) {
			if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
				return 'woocommerce';
			}
			// A session with items in it means the markup is personal.
			if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
				return 'cart';
			}
		}
		foreach ( self::exclusions() as $pattern ) {
			if ( self::matches( $pattern ) ) {
				return 'excluded';
			}
		}
		/**
		 * Filter the reason a page is not cached.
		 *
		 * @param string $reason Empty when cacheable.
		 */
		return (string) apply_filters( 'smao_cache_bypass', '' );
	}

	/**
	 * Whether the current URL matches an exclusion pattern.
	 *
	 * @param string $pattern Pattern, with an optional trailing asterisk.
	 * @return bool
	 */
	private static function matches( string $pattern ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		if ( str_ends_with( $pattern, '*' ) ) {
			return str_starts_with( $path, rtrim( $pattern, '*' ) );
		}
		return $path === $pattern || trailingslashit( $path ) === trailingslashit( $pattern );
	}

	/**
	 * Configured URL exclusions.
	 *
	 * @return array
	 */
	public static function exclusions(): array {
		$rules = array();
		foreach ( preg_split( '/[\r\n]+/', (string) Settings::get()['cache_exclusions'] ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$rules[] = $line;
			}
		}
		return $rules;
	}

	/**
	 * Write the finished page to disk.
	 *
	 * @param string $html Buffered output.
	 * @return string
	 */
	public static function store( string $html ): string {
		self::rules();
		// Something later in the request may have decided this is private.
		if ( strlen( $html ) < 255 || '' !== self::bypass() || http_response_code() !== 200 ) {
			return $html;
		}
		if ( ! str_contains( $html, '</html>' ) ) {
			return $html; // Partial or non-HTML output.
		}
		// A nonce means the markup is tied to a session, so it cannot be shared.
		if ( preg_match( '/name=["\']_wpnonce["\']/', $html ) || str_contains( $html, 'wp-admin/admin-ajax.php?action=' ) && str_contains( $html, '_wpnonce=' ) ) {
			return $html;
		}

		$key  = smao_cache_key( $_SERVER, (bool) Settings::get()['separate_mobile'], Delivery::active() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$file = smao_cache_path( self::root(), $key, 'html' );
		if ( '' === $file ) {
			return $html;
		}

		$payload = $html . "\n" . self::SIGNATURE . "\n";
		self::write( $file, $payload );

		if ( Settings::get()['cache_gzip'] && function_exists( 'gzencode' ) ) {
			$gz = gzencode( $payload, 6 );
			if ( false !== $gz ) {
				self::write( smao_cache_path( self::root(), $key, 'gz' ), $gz );
			}
		}

		self::write(
			smao_cache_path( self::root(), $key, 'meta' ),
			(string) wp_json_encode(
				array(
					'url'     => home_url( add_query_arg( array() ) ),
					'created' => time(),
					'bytes'   => strlen( $payload ),
				)
			)
		);

		return $html;
	}

	/**
	 * Write a file atomically, creating its directory.
	 *
	 * @param string $file     Destination.
	 * @param string $contents Contents.
	 * @return bool
	 */
	private static function write( string $file, string $contents ): bool {
		if ( '' === $file ) {
			return false;
		}
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$temp = $dir . '/.' . wp_generate_password( 12, false ) . '.tmp';
		if ( false === file_put_contents( $temp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}
		if ( ! @rename( $temp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		@chmod( $file, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return true;
	}

	/**
	 * Keep the drop-in, its configuration and the cache directory in step.
	 *
	 * @return void
	 */
	public static function sync(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::active() ) {
			self::prepare_directory();
			self::write_config();
			self::install_dropin();
		} else {
			self::remove_dropin();
		}
	}

	/**
	 * Create the cache directory and stop it being browsed or executed.
	 *
	 * @return void
	 */
	public static function prepare_directory(): void {
		$root = self::root();
		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}
		if ( ! file_exists( $root . '/index.php' ) ) {
			self::write( $root . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			self::write( $root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
	}

	/**
	 * Publish the settings the drop-in needs.
	 *
	 * @return void
	 */
	public static function write_config(): void {
		$settings = Settings::get();
		self::write(
			self::root() . '/config.json',
			(string) wp_json_encode(
				array(
					'enabled'         => self::active(),
					'ttl'             => (int) $settings['cache_ttl'] * HOUR_IN_SECONDS,
					'separate_mobile' => (bool) $settings['separate_mobile'],
					'webp'            => Delivery::active(),
					'version'         => SMAO_VERSION,
				)
			)
		);
	}

	/**
	 * Install the advanced-cache drop-in.
	 *
	 * @return bool
	 */
	public static function install_dropin(): bool {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( file_exists( $target ) && ! self::owns_dropin( $target ) ) {
			return false; // Another plugin owns it; leave it alone.
		}
		$template = file_get_contents( __DIR__ . '/advanced-cache-template.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		if ( false === $template ) {
			return false;
		}
		$contents = str_replace( '%%RULES%%', str_replace( '\\', '/', __DIR__ ) . '/cache-rules.php', $template );
		if ( file_exists( $target ) && (string) file_get_contents( $target ) === $contents ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			return true; // Already current; no write on every admin page load.
		}
		return self::write( $target, $contents );
	}

	/**
	 * Remove our drop-in, leaving another plugin's alone.
	 *
	 * @return void
	 */
	public static function remove_dropin(): void {
		$target = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( file_exists( $target ) && self::owns_dropin( $target ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$config = self::root() . '/config.json';
		if ( file_exists( $config ) ) {
			@unlink( $config ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Whether a drop-in file is ours.
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	public static function owns_dropin( string $file ): bool {
		$head = (string) file_get_contents( $file, false, null, 0, 600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		return str_contains( $head, 'Smart Media Auditor page cache drop-in' );
	}

	/**
	 * Whether WP_CACHE is switched on in wp-config.php.
	 *
	 * @return bool
	 */
	public static function wp_cache_defined(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * Try to add the WP_CACHE constant to wp-config.php.
	 *
	 * @return bool True when it is now present.
	 */
	public static function enable_wp_cache(): bool {
		if ( self::wp_cache_defined() ) {
			return true;
		}
		$config = self::config_file();
		if ( '' === $config || ! is_writable( $config ) ) {
			return false;
		}
		$contents = (string) file_get_contents( $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		if ( preg_match( '/define\s*\(\s*([\'"])WP_CACHE\1/', $contents ) ) {
			$contents = (string) preg_replace( '/define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*(false|0|\'\')\s*\)\s*;/i', "define( 'WP_CACHE', true );", $contents );
		} else {
			$contents = (string) preg_replace(
				'/(<\?php\s*)/',
				"$1\ndefine( 'WP_CACHE', true ); // Added by Smart Media Auditor.\n",
				$contents,
				1
			);
		}
		return false !== file_put_contents( $config, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Locate wp-config.php.
	 *
	 * @return string
	 */
	private static function config_file(): string {
		$candidates = array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' );
		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Count and total size of the cache.
	 *
	 * @return array{pages:int,bytes:int,updated:int}
	 */
	public static function stats(): array {
		$root = self::root();
		$out  = array(
			'pages'   => 0,
			'bytes'   => 0,
			'updated' => 0,
		);
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$out['bytes'] += $file->getSize();
			if ( 'html' === $file->getExtension() ) {
				++$out['pages'];
				$out['updated'] = max( $out['updated'], $file->getMTime() );
			}
		}
		return $out;
	}

	/**
	 * Delete everything in the cache.
	 *
	 * @return int Files removed.
	 */
	public static function flush(): int {
		$root = self::root();
		if ( ! is_dir( $root ) ) {
			return 0;
		}
		$removed  = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$name = $file->getFilename();
			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			if ( in_array( $name, array( 'index.php', '.htaccess', 'config.json' ), true ) ) {
				continue;
			}
			if ( @unlink( $file->getPathname() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				++$removed;
			}
		}
		update_option( 'smao_cache_purged', time(), false );
		return $removed;
	}

	/**
	 * Remove the cached copy of one URL.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function forget( string $url ): bool {
		self::rules();
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return false;
		}
		/*
		 * The stored key is built from HTTP_HOST, which includes the port when
		 * one is present. wp_parse_url() splits the port out, so it has to be
		 * put back or the key will not match and nothing is ever purged.
		 */
		$host = (string) $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$host .= ':' . (int) $parts['port'];
		}

		$removed = false;
		foreach ( array( true, false ) as $mobile ) {
			// Both the WebP and the original-image copy of the page.
			foreach ( array( 'image/webp,*/*', '*/*' ) as $accept ) {
				$server = array(
					'HTTP_HOST'       => $host,
					'REQUEST_URI'     => ( $parts['path'] ?? '/' ),
					'HTTPS'           => ( 'https' === ( $parts['scheme'] ?? 'http' ) ) ? 'on' : 'off',
					'HTTP_USER_AGENT' => $mobile ? 'Mobile' : 'Desktop',
					'HTTP_ACCEPT'     => $accept,
				);
				$key = smao_cache_key( $server, (bool) Settings::get()['separate_mobile'], true );
				foreach ( array( 'html', 'gz', 'meta' ) as $extension ) {
					$file = smao_cache_path( self::root(), $key, $extension );
					if ( '' !== $file && file_exists( $file ) && @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$removed = true;
					}
				}
			}
			if ( ! Settings::get()['separate_mobile'] ) {
				break;
			}
		}
		return $removed;
	}
}
