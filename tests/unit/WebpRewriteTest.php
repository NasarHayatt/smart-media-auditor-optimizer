<?php
/**
 * Every image in the page is switched to its WebP copy.
 *
 * Swapping only plain image tags missed the images that mattered most on a
 * live site: slider slides named in data attributes (1.3 MB and 0.8 MB PNGs)
 * and backgrounds written by the page builder.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Delivery;

/**
 * WebP page rewrite.
 */
final class WebpRewriteTest extends TestCase {

	/**
	 * Uploads base URL.
	 */
	private const BASE = 'https://example.test/wp-content/uploads';

	/**
	 * Files with copies.
	 *
	 * @return array
	 */
	private function map(): array {
		return array(
			'2026/02/1.png'            => '2026/02/1.png.smao-aaaaaaaaaaaa.webp',
			'2024/08/about-300x252.png' => '2024/08/about-300x252.png.smao-bbbbbbbbbbbb.webp',
			'2020/12/h1-bg01-1.png'    => '2020/12/h1-bg01-1.png.smao-cccccccccccc.webp',
			'2023/05/photo.jpg'        => '2023/05/photo.jpg.smao-dddddddddddd.webp',
		);
	}

	/**
	 * Tags, srcsets, data attributes, inline styles and escaped JSON all switch.
	 *
	 * @return void
	 */
	public function test_every_kind_of_reference_is_switched(): void {
		$html = '<html><body>'
			. '<img src="https://example.test/wp-content/uploads/2023/05/photo.jpg" srcset="https://example.test/wp-content/uploads/2024/08/about-300x252.png 300w, https://example.test/wp-content/uploads/2023/05/photo.jpg 800w">'
			. '<img src="//example.test/wp-content/uploads/plugins/dummy.png" data-lazyload="//example.test/wp-content/uploads/2026/02/1.png">'
			. '<div style="background-image:url(\'https://example.test/wp-content/uploads/2020/12/h1-bg01-1.png\')"></div>'
			. '<div data-settings="{&quot;image&quot;:{&quot;url&quot;:&quot;https:\/\/example.test\/wp-content\/uploads\/2023\/05\/photo.jpg&quot;}}"></div>'
			. '</body></html>';
		$out = Delivery::rewrite( $html, $this->map(), self::BASE );

		$this->assertStringContainsString( 'src="https://example.test/wp-content/uploads/2023/05/photo.jpg.smao-dddddddddddd.webp"', $out );
		$this->assertStringContainsString( '2024/08/about-300x252.png.smao-bbbbbbbbbbbb.webp 300w', $out );
		$this->assertStringContainsString( 'data-lazyload="//example.test/wp-content/uploads/2026/02/1.png.smao-aaaaaaaaaaaa.webp"', $out );
		$this->assertStringContainsString( "url('https://example.test/wp-content/uploads/2020/12/h1-bg01-1.png.smao-cccccccccccc.webp')", $out );
		$this->assertStringContainsString( 'https:\/\/example.test\/wp-content\/uploads\/2023\/05\/photo.jpg.smao-dddddddddddd.webp', $out );
		// No copy, no change.
		$this->assertStringContainsString( 'uploads/plugins/dummy.png"', $out );
	}

	/**
	 * A preload for an image only named in an external stylesheet stays.
	 *
	 * The stylesheet will still ask for the original; preloading the copy
	 * would download the image twice.
	 *
	 * @return void
	 */
	public function test_preload_follows_the_page(): void {
		$html = '<html><head>'
			. '<link rel="preload" as="image" href="https://example.test/wp-content/uploads/2020/12/h1-bg01-1.png" fetchpriority="high">'
			. '<link rel="preload" as="image" href="https://example.test/wp-content/uploads/2023/05/photo.jpg" fetchpriority="high">'
			. '</head><body><img src="https://example.test/wp-content/uploads/2023/05/photo.jpg"></body></html>';
		$out = Delivery::rewrite( $html, $this->map(), self::BASE );
		$this->assertStringContainsString( 'href="https://example.test/wp-content/uploads/2020/12/h1-bg01-1.png"', $out );
		$this->assertStringContainsString( 'href="https://example.test/wp-content/uploads/2023/05/photo.jpg.smao-dddddddddddd.webp"', $out );
	}

	/**
	 * Other sites and lookalike names are never touched.
	 *
	 * @return void
	 */
	public function test_foreign_and_partial_matches_are_left_alone(): void {
		$html = '<img src="https://other.test/wp-content/uploads/2023/05/photo.jpg">'
			. '<img src="https://example.test/wp-content/uploads/2023/05/photo.jpg-large.gif">'
			. '<img src="https://example.test/wp-content/uploads/2023/05/photo.jpg.smao-dddddddddddd.webp">';
		$this->assertSame( $html, Delivery::rewrite( $html, $this->map(), self::BASE ) );
		$this->assertSame( $html, Delivery::rewrite( $html, array(), self::BASE ) );
	}

	/**
	 * Browsers without WebP get their own cached copy of the page.
	 *
	 * @return void
	 */
	public function test_cache_keeps_webp_and_original_pages_apart(): void {
		if ( ! defined( 'SMAO_CACHE_BOOTSTRAP' ) ) {
			define( 'SMAO_CACHE_BOOTSTRAP', true );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/cache/cache-rules.php';
		$chrome = array( 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/', 'HTTP_ACCEPT' => 'text/html,image/avif,image/webp,*/*' );
		$old    = array( 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/', 'HTTP_ACCEPT' => 'text/html,*/*' );
		$this->assertNotSame( smao_cache_key( $chrome, false, true ), smao_cache_key( $old, false, true ) );
		$this->assertSame( smao_cache_key( $old, false, true ), smao_cache_key( $old, false, false ) );
		$this->assertSame( smao_cache_key( $chrome, false, false ), smao_cache_key( $old, false, false ), 'without WebP delivery there is one page' );
	}
}
