<?php
use PHPUnit\Framework\TestCase;
use SMAO\Media;

final class MediaTest extends TestCase {
	/** @dataProvider unsafe_paths */
	public function test_traversal_and_stream_paths_are_rejected( string $path ): void {
		$this->expectException( RuntimeException::class );
		Media::relative( $path );
	}
	public static function unsafe_paths(): array { return array( array( '../photo.jpg' ), array( '/etc/passwd' ), array( '2026/../../photo.jpg' ), array( 'php://filter' ), array( 'C:\\private\\a.jpg' ), array( "x\0.jpg" ), array( '' ), array( '2026/./x.jpg' ) ); }
	public function test_sibling_prefix_is_not_containment(): void {
		$this->assertTrue( Media::within( '/srv/uploads/2026/a.jpg', '/srv/uploads' ) );
		$this->assertFalse( Media::within( '/srv/uploads-other/a.jpg', '/srv/uploads' ) );
	}
	public function test_missing_parent_is_not_authorized_for_restore(): void {
		$GLOBALS['smao_test_uploads'] = sys_get_temp_dir();
		$this->expectException( RuntimeException::class );
		Media::path( 'smao-nonexistent-' . bin2hex( random_bytes( 10 ) ) . '/a.jpg', false );
	}
}
