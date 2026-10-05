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
	 * Paths that try to leave wp-content are never followed.
	 *
	 * @return void
	 */
	public function test_paths_cannot_leave_wp_content(): void {
		$html = '<img src="https://old.example/wp-content/../wp-config.png">';
		$this->assertSame( $html, Viewport::rehome( $html, 'jkmarketing.org', $this->dir, 'https://jkmarketing.org/wp-content' ) );
	}
}
