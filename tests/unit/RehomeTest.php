<?php
/**
 * Images still loaded from an old site address are served from this site.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Viewport;

/**
 * Old-address rewriting.
 */
final class RehomeTest extends TestCase {

	/**
	 * A temporary wp-content folder with one uploaded image.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Create the folder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/smao-rehome-' . uniqid();
		mkdir( $this->dir . '/uploads/2023/08', 0777, true );
		file_put_contents( $this->dir . '/uploads/2023/08/header.png', 'png' );
	}

	/**
	 * Remove the folder.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unlink( $this->dir . '/uploads/2023/08/header.png' );
		rmdir( $this->dir . '/uploads/2023/08' );
		rmdir( $this->dir . '/uploads/2023' );
		rmdir( $this->dir . '/uploads' );
		rmdir( $this->dir );
	}

	/**
	 * Old addresses of files this site has become this site's addresses.
	 *
	 * @return void
	 */
	public function test_old_addresses_of_local_files_are_rewritten(): void {
		$old  = 'https://wab.hak.mybluehost.me/website_9f275d8f/wp-content/uploads/2023/08/header.png';
		$html = '<div style="background-image:url(' . $old . ')"></div>'
			. '<div data-settings="{&quot;url&quot;:&quot;https:\/\/wab.hak.mybluehost.me\/website_9f275d8f\/wp-content\/uploads\/2023\/08\/header.png&quot;}"></div>'
			. '<img src="https://old.example/wp-content/uploads/2023/08/missing.png">'
			. '<img src="https://jkmarketing.org/wp-content/uploads/2023/08/header.png">'
			. '<img src="https://cdn.example.com/images/logo.png">';
		$out  = Viewport::rehome( $html, 'jkmarketing.org', $this->dir, 'https://jkmarketing.org/wp-content' );

		$this->assertStringContainsString( 'url(https://jkmarketing.org/wp-content/uploads/2023/08/header.png)', $out );
		$this->assertStringContainsString( 'https:\/\/jkmarketing.org\/wp-content\/uploads\/2023\/08\/header.png&quot;', $out, 'JSON-escaped addresses in builder settings too' );
		$this->assertStringNotContainsString( 'mybluehost', $out );
		$this->assertStringContainsString( 'https://old.example/wp-content/uploads/2023/08/missing.png', $out, 'a file this site does not have keeps its address' );
		$this->assertStringContainsString( 'https://cdn.example.com/images/logo.png', $out, 'other images are untouched' );
	}

	/**
	 * Protocol-relative addresses, as Slider Revolution saves them.
	 *
	 * @return void
	 */
	public function test_protocol_relative_addresses_are_rewritten(): void {
		$html = '<img data-lazyload="//784.861.myftpupload.com/wp-content/uploads/2023/08/header.png">'
			. '<div data-x="{&quot;u&quot;:&quot;\/\/784.861.myftpupload.com\/wp-content\/uploads\/2023\/08\/header.png&quot;}"></div>';
		$out  = Viewport::rehome( $html, 'saifenergy.com', $this->dir, 'https://saifenergy.com/wp-content' );

		$this->assertStringContainsString( 'data-lazyload="https://saifenergy.com/wp-content/uploads/2023/08/header.png"', $out );
		$this->assertStringContainsString( 'https:\/\/saifenergy.com\/wp-content\/uploads\/2023\/08\/header.png&quot;', $out );
		$this->assertStringNotContainsString( 'myftpupload', $out );
	}

	/**
	 * An image CDN copy of this site keeps its resizing; one of an old
	 * address is pointed at this site through the same CDN.
	 *
	 * @return void
	 */
	public function test_image_cdn_addresses(): void {
		$self = 'https://i0.wp.com/saifenergy.com/wp-content/uploads/2023/08/header.png?fit=32%2C32&#038;ssl=1';
		$html = '<img src="' . $self . '">'
			. '<img src="https://i0.wp.com/wab.hak.mybluehost.me/website_d48ba495/wp-content/uploads/2023/08/header.png?w=847&#038;ssl=1">'
			. '<div data-x="{&quot;u&quot;:&quot;https:\/\/i1.wp.com\/wab.hak.mybluehost.me\/website_d48ba495\/wp-content\/uploads\/2023\/08\/header.png?w=847&quot;}"></div>';
		$out  = Viewport::rehome( $html, 'saifenergy.com', $this->dir, 'https://saifenergy.com/wp-content' );

		$this->assertStringContainsString( $self, $out, 'this site through its CDN is left alone' );
		$this->assertStringContainsString( 'https://i0.wp.com/saifenergy.com/wp-content/uploads/2023/08/header.png?w=847&#038;ssl=1', $out );
		$this->assertStringContainsString( 'https:\/\/i1.wp.com\/saifenergy.com\/wp-content\/uploads\/2023\/08\/header.png?w=847&quot;', $out );
		$this->assertStringNotContainsString( 'mybluehost', $out );
	}

	/**
	 * Image preloads for files the page does not show are dropped; every
	 * other preload stays.
	 *
	 * @return void
	 */
	public function test_preloads_of_images_the_page_does_not_show_are_dropped(): void {
		$stray  = '<link rel="preload" as="image" href="https://saifenergy.com/wp-content/uploads/2023/12/Leading-home.webp" type="image/webp">';
		$used   = '<link rel="preload" as="image" href="https://saifenergy.com/wp-content/uploads/2023/11/logo.png">';
		$bg     = '<link rel="preload" as="image" href="https://saifenergy.com/wp-content/uploads/2023/11/hero-bg.jpg">';
		$ours   = '<link rel="preload" as="image" data-smao="1" href="https://saifenergy.com/wp-content/uploads/2022/01/elsewhere.webp" fetchpriority="high">';
		$font   = '<link rel="preload" as="font" href="https://saifenergy.com/wp-content/fonts/x.woff2" crossorigin>';
		$srcset = '<link rel="preload" as="image" href="https://saifenergy.com/wp-content/uploads/2021/01/r.webp" imagesrcset="https://saifenergy.com/wp-content/uploads/2021/01/r-768.webp 768w">';
		$html   = '<html><head>' . $stray . $used . $bg . $ours . $font . $srcset . '</head><body>'
			. '<img src="https://i0.wp.com/saifenergy.com/wp-content/uploads/2025/04/Leading-home.webp?fit=537%2C539&amp;ssl=1">'
			. '<img src="https://i0.wp.com/saifenergy.com/wp-content/uploads/2023/11/logo.png?ssl=1"></body></html>';
		$heroes = array(
			'mobile'  => array( 'above' => array( array( 'id' => 0, 'name' => 'leading-home.webp' ) ) ),
			'desktop' => array(
				'kind' => 'bg',
				'url'  => 'https://saifenergy.com/wp-content/uploads/2023/11/hero-bg.jpg',
			),
		);
		$out    = Viewport::unused_preloads( $html, $heroes );

		$this->assertStringNotContainsString( '2023/12/Leading-home.webp', $out, 'an older copy from another folder is never shown' );
		foreach ( array( $used, $bg, $ours, $font, $srcset ) as $kept ) {
			$this->assertStringContainsString( $kept, $out );
		}
		$this->assertSame( '<p>no head</p>', Viewport::unused_preloads( '<p>no head</p>', $heroes ) );
	}

	/**
	 * Paths that try to leave wp-content are never followed.
	 *
	 * @return void
	 */
	public function test_paths_cannot_leave_wp_content(): void {
		$html = '<img src="https://old.example/wp-content/../wp-config.png">';
		$this->assertSame( $html, Viewport::rehome( $html, 'jkmarketing.org', $this->dir, 'https://jkmarketing.org/wp-content' ) );
	}
}
