<?php
/**
 * The main image is measured, not guessed.
 *
 * On an Elementor site the largest image on a phone was a section's CSS
 * background, invisible to the featured-image guess, so the wrong image was
 * preloaded and the real one waited for the stylesheet (4.6s of load delay).
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Viewport;

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub URL sanitising.
	 *
	 * @param string $url       URL.
	 * @param array  $protocols Allowed protocols.
	 * @return string
	 */
	function esc_url_raw( $url, $protocols = null ) {
		return preg_match( '#^https?://#i', (string) $url ) ? (string) $url : '';
	}
}
if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub absint.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

/**
 * Measured main images.
 */
final class MeasuredHeroTest extends TestCase {

	/**
	 * Phones come from 390px, desktops from 1280px.
	 *
	 * @return void
	 */
	public function test_devices_map_to_measured_widths(): void {
		$clean = Viewport::clean_heroes(
			array(
				390  => array( 'kind' => 'bg', 'url' => 'https://x.test/wp-content/uploads/h1-bg.png', 'share' => 0.48 ),
				768  => array( 'kind' => 'img', 'url' => 'https://x.test/tablet.png', 'id' => 5, 'share' => 0.9 ),
				1280 => array( 'kind' => 'img', 'url' => 'https://x.test/a.png', 'id' => '12', 'share' => 0.3 ),
			)
		);
		$this->assertSame( array( 'mobile', 'desktop' ), array_keys( $clean ) );
		$this->assertSame( 'bg', $clean['mobile']['kind'] );
		$this->assertSame( 12, $clean['desktop']['id'] );
	}

	/**
	 * Small images, bad kinds and non-web URLs are dropped.
	 *
	 * @return void
	 */
	public function test_unusable_heroes_are_dropped(): void {
		$this->assertSame( array(), Viewport::clean_heroes( array( 1280 => array( 'kind' => 'bg', 'url' => 'https://x.test/small.png', 'share' => 0.077 ) ) ) );
		$this->assertSame( array(), Viewport::clean_heroes( array( 390 => array( 'kind' => 'script', 'url' => 'https://x.test/a.png', 'share' => 0.9 ) ) ) );
		$this->assertSame( array(), Viewport::clean_heroes( array( 390 => array( 'kind' => 'bg', 'url' => 'javascript:alert(1)', 'share' => 0.9 ) ) ) );
		$this->assertSame( array(), Viewport::clean_heroes( array( 390 => 'nonsense' ) ) );
	}

	/**
	 * With a measurement, the old guess never runs.
	 *
	 * @return void
	 */
	public function test_measurement_replaces_the_guess(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/delivery/class-viewport.php' );
		$preload = (string) strstr( $code, 'public static function preload(): void' );
		$this->assertStringContainsString( 'self::preload_measured( $heroes );', substr( $preload, 0, 300 ) );
		$this->assertStringContainsString( '! self::$measured', $code, 'the first-image guess must stand down on measured pages' );
	}
}
