<?php
/**
 * Page cache rules.
 *
 * These decide whether a response may be shared between visitors. A mistake
 * here serves one person's private page to somebody else, so every rule is
 * pinned down, including the ones found by testing rather than by design.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;

/**
 * Cache key construction and bypass rules.
 */
final class CacheRulesTest extends TestCase {

	/**
	 * Load the shared rules, which deliberately avoid WordPress.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		if ( ! defined( 'SMAO_CACHE_BOOTSTRAP' ) ) {
			define( 'SMAO_CACHE_BOOTSTRAP', true );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/cache/cache-rules.php';
	}

	/**
	 * Build a request.
	 *
	 * @param string $uri    Request URI.
	 * @param array  $extra  Extra server values.
	 * @return array
	 */
	private function request( string $uri, array $extra = array() ): array {
		return array_merge(
			array(
				'REQUEST_METHOD' => 'GET',
				'HTTP_HOST'      => 'example.test',
				'REQUEST_URI'    => $uri,
			),
			$extra
		);
	}

	/**
	 * An ordinary anonymous page view is cacheable.
	 *
	 * @return void
	 */
	public function test_public_page_is_cacheable(): void {
		$this->assertSame( '', smao_cache_bypass_reason( $this->request( '/about/' ), array() ) );
	}

	/**
	 * A logged-in visitor must never see a shared page.
	 *
	 * @return void
	 */
	public function test_login_cookie_bypasses(): void {
		$this->assertSame(
			'cookie',
			smao_cache_bypass_reason( $this->request( '/' ), array( 'wordpress_logged_in_abc' => 'x' ) )
		);
	}

	/**
	 * Commerce and commenting cookies mean the markup is personal.
	 *
	 * @return void
	 */
	public function test_personal_cookies_bypass(): void {
		foreach ( array( 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_1', 'comment_author_x', 'wp-postpass_x' ) as $cookie ) {
			$this->assertSame(
				'cookie',
				smao_cache_bypass_reason( $this->request( '/' ), array( $cookie => '1' ) ),
				"$cookie must prevent caching"
			);
		}
	}

	/**
	 * Private and dynamic paths are never cached.
	 *
	 * @return void
	 */
	public function test_private_paths_bypass(): void {
		foreach ( array( '/cart/', '/checkout/', '/my-account/', '/wp-admin/', '/wp-login.php', '/wp-json/wp/v2/posts', '/feed/', '/order-received/12' ) as $path ) {
			$this->assertSame( 'path', smao_cache_bypass_reason( $this->request( $path ), array() ), "$path must not be cached" );
		}
	}

	/**
	 * Anything other than a read is never served from cache.
	 *
	 * @return void
	 */
	public function test_non_get_bypasses(): void {
		foreach ( array( 'POST', 'PUT', 'DELETE', 'PATCH' ) as $method ) {
			$this->assertSame(
				'method',
				smao_cache_bypass_reason( $this->request( '/', array( 'REQUEST_METHOD' => $method ) ), array() )
			);
		}
		$this->assertSame( '', smao_cache_bypass_reason( $this->request( '/', array( 'REQUEST_METHOD' => 'HEAD' ) ), array() ) );
	}

	/**
	 * An unrecognised query parameter may change the output.
	 *
	 * @return void
	 */
	public function test_unknown_query_bypasses(): void {
		$this->assertSame( 'query', smao_cache_bypass_reason( $this->request( '/?s=shoes' ), array() ) );
		$this->assertSame( 'query', smao_cache_bypass_reason( $this->request( '/?preview=true' ), array() ) );
		$this->assertSame( 'query', smao_cache_bypass_reason( $this->request( '/?add-to-cart=9' ), array() ) );
	}

	/**
	 * Campaign tracking does not change the page, so it still hits the cache.
	 *
	 * @return void
	 */
	public function test_tracking_query_still_cacheable(): void {
		$this->assertSame( '', smao_cache_bypass_reason( $this->request( '/?utm_source=news&utm_medium=email' ), array() ) );
		$this->assertSame( '', smao_cache_bypass_reason( $this->request( '/?fbclid=abc' ), array() ) );
		$this->assertSame( '', smao_cache_bypass_reason( $this->request( '/?gclid=abc' ), array() ) );
	}

	/**
	 * A tracking parameter shares the entry with the clean URL.
	 *
	 * @return void
	 */
	public function test_tracking_shares_one_entry(): void {
		$this->assertSame(
			smao_cache_key( $this->request( '/' ), false ),
			smao_cache_key( $this->request( '/?utm_source=x' ), false )
		);
	}

	/**
	 * Different sites, paths and schemes never share an entry.
	 *
	 * @return void
	 */
	public function test_keys_are_distinct(): void {
		$a = smao_cache_key( $this->request( '/' ), false );
		$b = smao_cache_key( $this->request( '/about/' ), false );
		$c = smao_cache_key( $this->request( '/', array( 'HTTP_HOST' => 'other.test' ) ), false );
		$d = smao_cache_key( $this->request( '/', array( 'HTTPS' => 'on' ) ), false );

		$this->assertNotSame( $a, $b, 'different paths' );
		$this->assertNotSame( $a, $c, 'a forged Host must not reach another site entry' );
		$this->assertNotSame( $a, $d, 'http and https are different entries' );
	}

	/**
	 * The port is part of the host, and therefore part of the key.
	 *
	 * Purging built the key from a parsed URL, which drops the port, so the key
	 * never matched and nothing was ever cleared.
	 *
	 * @return void
	 */
	public function test_port_changes_the_key(): void {
		$this->assertNotSame(
			smao_cache_key( $this->request( '/', array( 'HTTP_HOST' => 'localhost' ) ), false ),
			smao_cache_key( $this->request( '/', array( 'HTTP_HOST' => 'localhost:8080' ) ), false )
		);
	}

	/**
	 * Mobile only gets its own entry when that is asked for.
	 *
	 * @return void
	 */
	public function test_mobile_separation_is_optional(): void {
		$phone   = $this->request( '/', array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile/15E148' ) );
		$desktop = $this->request( '/', array( 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' ) );

		$this->assertSame( smao_cache_key( $phone, false ), smao_cache_key( $desktop, false ) );
		$this->assertNotSame( smao_cache_key( $phone, true ), smao_cache_key( $desktop, true ) );
		$this->assertTrue( smao_cache_is_mobile( $phone ) );
		$this->assertFalse( smao_cache_is_mobile( $desktop ) );
	}

	/**
	 * A key is always a hex digest, so a path can never escape the cache root.
	 *
	 * @return void
	 */
	public function test_paths_cannot_traverse(): void {
		foreach ( array( '../../wp-config', 'abc/../../etc/passwd', '..%2fwp-config', '', 'Z' . str_repeat( 'a', 63 ), str_repeat( 'a', 63 ) ) as $key ) {
			$this->assertSame( '', smao_cache_path( '/cache', $key ), "unsafe key must be refused: $key" );
		}
		$valid = str_repeat( 'a', 64 );
		$this->assertSame( '/cache/aa/' . $valid . '.html', smao_cache_path( '/cache', $valid ) );
	}

	/**
	 * An unexpected extension falls back to html rather than writing anywhere.
	 *
	 * @return void
	 */
	public function test_extension_is_constrained(): void {
		$valid = str_repeat( 'b', 64 );
		$this->assertStringEndsWith( '.html', smao_cache_path( '/cache', $valid, '../../evil' ) );
		$this->assertStringEndsWith( '.gz', smao_cache_path( '/cache', $valid, 'gz' ) );
		$this->assertStringEndsWith( '.meta', smao_cache_path( '/cache', $valid, 'meta' ) );
	}

	/**
	 * An AJAX request is dynamic by definition.
	 *
	 * @return void
	 */
	public function test_ajax_bypasses(): void {
		$this->assertSame(
			'ajax',
			smao_cache_bypass_reason( $this->request( '/', array( 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest' ) ), array() )
		);
	}

	/**
	 * The cache root always sits inside wp-content.
	 *
	 * @return void
	 */
	public function test_root_is_inside_content(): void {
		$this->assertSame( '/var/www/wp-content/cache/smao', smao_cache_root( '/var/www/wp-content' ) );
		$this->assertSame( '/var/www/wp-content/cache/smao', smao_cache_root( '/var/www/wp-content/' ) );
	}
}
