<?php
/**
 * Serving a stored page.
 *
 * The drop-in and the in-plugin fallback both call smao_cache_serve(), so a
 * host that will not let the plugin switch on WP_CACHE still gets cache hits.
 * Before 2.3.2 such a host stored pages and never served one.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;

/**
 * Shared serving path.
 *
 * Each test sends headers, so each runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CacheServeTest extends TestCase {

	/**
	 * Temporary cache root.
	 *
	 * @var string
	 */
	private string $root = '';

	/**
	 * Saved superglobals.
	 *
	 * @var array
	 */
	private array $saved = array();

	/**
	 * Load the rules and create an empty cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		if ( ! defined( 'SMAO_CACHE_BOOTSTRAP' ) ) {
			define( 'SMAO_CACHE_BOOTSTRAP', true );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/cache/cache-rules.php';
		$this->root  = sys_get_temp_dir() . '/smao-serve-' . bin2hex( random_bytes( 4 ) );
		$this->saved = array( $_SERVER, $_COOKIE );
		$_COOKIE     = array();
		$_SERVER     = array(
			'REQUEST_METHOD' => 'GET',
			'HTTP_HOST'      => 'example.test',
			'REQUEST_URI'    => '/about/',
			'HTTPS'          => 'on',
		);
	}

	/**
	 * Remove the temporary cache.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		list( $_SERVER, $_COOKIE ) = $this->saved;
		foreach ( (array) glob( $this->root . '/*/*' ) as $file ) {
			@unlink( (string) $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( (array) glob( $this->root . '/*' ) as $dir ) {
			@rmdir( (string) $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@rmdir( $this->root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Store a page for the current request.
	 *
	 * @param string $html Body.
	 * @return string Stored file.
	 */
	private function store( string $html ): string {
		$file = smao_cache_path( $this->root, smao_cache_key( $_SERVER, false ), 'html' );
		@mkdir( dirname( $file ), 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		file_put_contents( $file, $html );
		return $file;
	}

	/**
	 * Serve and capture the output.
	 *
	 * @param array $config Serve configuration.
	 * @return array{0:bool,1:string}
	 */
	private function serve( array $config = array( 'ttl' => 3600 ) ): array {
		ob_start();
		$served = smao_cache_serve( $this->root, $config, 'HIT-PHP' );
		return array( $served, (string) ob_get_clean() );
	}

	/**
	 * A stored page is served whole.
	 *
	 * @return void
	 */
	public function test_hit_serves_the_stored_page(): void {
		$this->store( '<html><body>cached</body></html>' );
		list( $served, $body ) = $this->serve();
		$this->assertTrue( $served );
		$this->assertSame( '<html><body>cached</body></html>', $body );
	}

	/**
	 * Nothing stored means WordPress renders the page.
	 *
	 * @return void
	 */
	public function test_miss_falls_through(): void {
		list( $served, $body ) = $this->serve();
		$this->assertFalse( $served );
		$this->assertSame( '', $body );
	}

	/**
	 * A logged-in visitor never receives the shared copy.
	 *
	 * @return void
	 */
	public function test_private_cookie_is_never_served_a_shared_page(): void {
		$this->store( '<html>anonymous</html>' );
		$_COOKIE = array( 'wordpress_logged_in_abc' => 'admin|123' );
		list( $served, $body ) = $this->serve();
		$this->assertFalse( $served );
		$this->assertSame( '', $body );
	}

	/**
	 * Form submissions always reach WordPress.
	 *
	 * @return void
	 */
	public function test_post_is_never_served_from_cache(): void {
		$this->store( '<html>anonymous</html>' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		list( $served ) = $this->serve();
		$this->assertFalse( $served );
	}

	/**
	 * Expired copies are not served.
	 *
	 * @return void
	 */
	public function test_expired_copy_is_ignored(): void {
		$file = $this->store( '<html>old</html>' );
		touch( $file, time() - 7200 );
		clearstatcache();
		list( $served ) = $this->serve( array( 'ttl' => 3600 ) );
		$this->assertFalse( $served );
	}

	/**
	 * A HEAD request gets headers only.
	 *
	 * @return void
	 */
	public function test_head_sends_no_body(): void {
		$this->store( '<html>cached</html>' );
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		list( $served, $body ) = $this->serve();
		$this->assertTrue( $served );
		$this->assertSame( '', $body );
	}

	/**
	 * A browser holding the current copy gets 304 and no body.
	 *
	 * @return void
	 */
	public function test_conditional_request_gets_not_modified(): void {
		$file                             = $this->store( '<html>cached</html>' );
		$_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate( 'D, d M Y H:i:s', (int) filemtime( $file ) + 5 ) . ' GMT';
		list( $served, $body ) = $this->serve();
		$this->assertTrue( $served );
		$this->assertSame( '', $body );
	}

	/**
	 * The plugin serves from boot when the drop-in did not.
	 *
	 * @return void
	 */
	public function test_fallback_is_wired_into_boot(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/cache/class-cache.php' );
		$boot = strstr( $code, 'public static function boot' );
		$this->assertIsString( $boot );
		$this->assertStringContainsString( 'self::serve_fallback();', substr( (string) $boot, 0, 700 ) );
		$this->assertStringContainsString( "smao_cache_serve( self::root(), \$config, 'HIT-PHP' )", $code );

		$dropin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/cache/advanced-cache-template.php' );
		$this->assertStringContainsString( "smao_cache_serve( \$root, \$settings, 'HIT' )", $dropin );
	}
}
