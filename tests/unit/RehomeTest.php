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
	 * Paths that try to leave wp-content are never followed.
	 *
	 * @return void
	 */
	public function test_paths_cannot_leave_wp_content(): void {
		$html = '<img src="https://old.example/wp-content/../wp-config.png">';
		$this->assertSame( $html, Viewport::rehome( $html, 'jkmarketing.org', $this->dir, 'https://jkmarketing.org/wp-content' ) );
	}
}
