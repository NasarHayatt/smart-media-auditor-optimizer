<?php
/**
 * Stylesheets may only load asynchronously on a verified page.
 *
 * 2.3.1 made every stylesheet asynchronous whenever any critical CSS was
 * stored, while the inlining step separately refused captures over its size
 * limit. On an Elementor site the capture was over the limit, so the page
 * painted with no styles at all and Cumulative Layout Shift reached 1.6.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Styles;

/**
 * One decision gates both inlining and asynchronous loading.
 */
final class CriticalCssGateTest extends TestCase {

	/**
	 * A capture that passed every check.
	 *
	 * @return array
	 */
	private function ready(): array {
		return array(
			'status'  => 'ready',
			'css'     => str_repeat( 'a', 500 ),
			'handles' => array( 'theme-style' ),
		);
	}

	/**
	 * Only a small, verified, non-empty capture is usable.
	 *
	 * @return void
	 */
	public function test_only_verified_captures_are_usable(): void {
		$this->assertTrue( Styles::usable( $this->ready() ) );

		$shifted           = $this->ready();
		$shifted['status'] = 'shifted';
		$this->assertFalse( Styles::usable( $shifted ), 'a capture that moved the layout must never be used' );

		$huge        = $this->ready();
		$huge['css'] = str_repeat( 'a', 153601 );
		$this->assertFalse( Styles::usable( $huge ), 'a capture too large to inline must not switch stylesheets to async' );

		$empty        = $this->ready();
		$empty['css'] = '';
		$this->assertFalse( Styles::usable( $empty ) );

		$uncovered            = $this->ready();
		$uncovered['handles'] = array();
		$this->assertFalse( Styles::usable( $uncovered ) );

		$this->assertFalse( Styles::usable( null ) );
		$this->assertFalse( Styles::usable( 'css' ) );
	}

	/**
	 * The verdict refuses anything that moved or is too big.
	 *
	 * @return void
	 */
	public function test_verdict(): void {
		$this->assertSame( 'ready', Styles::verdict( 40000, 12, 0.0 ) );
		$this->assertSame( 'ready', Styles::verdict( 40000, 12, Styles::MAX_SHIFT ) );
		$this->assertSame( 'shifted', Styles::verdict( 40000, 12, Styles::MAX_SHIFT + 0.0001 ) );
		$this->assertSame( 'shifted', Styles::verdict( 40000, 12, 0.69 ) );
		$this->assertSame( 'shifted', Styles::verdict( 40000, 12, -1.0 ), 'a verification that did not run is a failure' );
		$this->assertSame( 'too_large', Styles::verdict( 200000, 12, 0.0 ) );
		$this->assertSame( 'empty', Styles::verdict( 10, 12, 0.0 ) );
		$this->assertSame( 'empty', Styles::verdict( 40000, 0, 0.0 ) );
	}

	/**
	 * Captures are per page, and trailing slashes do not split them.
	 *
	 * @return void
	 */
	public function test_keys_are_per_path(): void {
		$this->assertSame( Styles::key( '/about/' ), Styles::key( '/about' ) );
		$this->assertSame( Styles::key( '/' ), Styles::key( '' ) );
		$this->assertNotSame( Styles::key( '/about/' ), Styles::key( '/contact/' ) );
		$this->assertNotSame( Styles::key( '/' ), Styles::key( '/about/' ) );
	}

	/**
	 * Inlining and async loading must read the same answer.
	 *
	 * @return void
	 */
	public function test_async_and_inline_share_one_gate(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/speed/class-styles.php' );

		$async = strstr( $code, 'public static function async(' );
		$this->assertIsString( $async );
		$this->assertStringContainsString( 'self::entry()', substr( (string) $async, 0, 400 ) );
		$this->assertStringContainsString( "in_array( \$handle, \$entry['handles'], true )", substr( (string) $async, 0, 400 ), 'a stylesheet not present when the page was verified must keep blocking' );

		$critical = strstr( $code, 'public static function critical(' );
		$this->assertIsString( $critical );
		$this->assertStringContainsString( 'self::entry()', substr( (string) $critical, 0, 300 ) );

		$entry = strstr( $code, 'private static function entry(' );
		$this->assertIsString( $entry );
		$this->assertStringContainsString( 'self::usable(', substr( (string) $entry, 0, 700 ) );
	}

	/**
	 * Background stylesheets only when they, not scripts, hold up the paint.
	 *
	 * With a larger blocking script in the head, async stylesheets made the
	 * first paint 0.5s later on a test page, because images then competed
	 * with the script that was actually holding it up.
	 *
	 * @return void
	 */
	public function test_async_only_when_stylesheets_outweigh_blocking_scripts(): void {
		$code  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/speed/class-styles.php' );
		$entry = strstr( $code, 'private static function entry(' );
		$this->assertStringContainsString( 'self::worthwhile( $entry )', substr( (string) $entry, 0, 700 ) );
		$rule = strstr( $code, 'private static function worthwhile(' );
		$this->assertIsString( $rule );
		$this->assertStringContainsString( 'Scripts::stays_blocking(', (string) $rule );
		$this->assertStringContainsString( 'return $css > $js;', (string) $rule );

		$scripts = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/speed/class-scripts.php' );
		$this->assertStringContainsString( 'public static function stays_blocking(', $scripts );
	}

	/**
	 * Unverified captures from before 2.3.2 are discarded on upgrade.
	 *
	 * @return void
	 */
	public function test_old_unverified_captures_are_discarded(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/core/class-database.php' );
		$this->assertStringContainsString( "delete_option( 'smao_critical_css' )", $code );
	}
}
