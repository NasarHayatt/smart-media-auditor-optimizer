<?php
use PHPUnit\Framework\TestCase;
use SMAO\Matcher;

final class MatcherTest extends TestCase {
	public function test_large_non_path_values_do_not_produce_references(): void {
		$this->assertSame( array(), Matcher::tokens( str_repeat( 'x', 262144 ) ) );
	}
	public function test_css_paths_are_detected_with_server_jit_enabled(): void {
		$previous = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', '1' );
		try {
			$tokens = Matcher::tokens( '{"id":42,"style":"background-image:url(/wp-content/uploads/2025/photo.webp)"}' );
			$this->assertSame( 2, $tokens['path:2025/photo.webp']['strength'] );
			$this->assertSame( '1', ini_get( 'pcre.jit' ) );
		} finally { ini_set( 'pcre.jit', $previous ); }
	}
	public function test_recognizes_escaped_cdn_thumbnail_and_query_string(): void {
		$tokens = Matcher::tokens( '{"url":"https:\\/\\/cdn.example.test\\/wp-content\\/uploads\\/2025\\/04\\/photo-300x200.jpg?width=300"}' );
		$this->assertSame( 2, $tokens['path:2025/04/photo-300x200.jpg']['strength'] );
		$this->assertSame( 1, $tokens['name:photo-300x200.jpg']['strength'] );
	}
	public function test_numeric_custom_fields_remain_ambiguous(): void {
		$tokens = Matcher::tokens( 'a:1:{s:5:"price";i:123;}', 'price' );
		$this->assertSame( 1, $tokens['id:123']['strength'] );
		$this->assertSame( 2, Matcher::tokens( '123', '_thumbnail_id' )['id:123']['strength'] );
	}
	public function test_core_markup_and_galleries_produce_strong_evidence(): void {
		$tokens = Matcher::tokens( '<img class="wp-image-456">[gallery ids="7,8,9"]' );
		foreach ( array( 456, 7, 8, 9 ) as $id ) { $this->assertSame( 2, $tokens[ 'id:' . $id ]['strength'] ); }
	}
	public function test_builder_json_is_conservative_and_css_is_detected(): void {
		$tokens = Matcher::tokens( '{"id":42,"style":"background-image:url(/wp-content/uploads/2025/photo.webp)"}' );
		$this->assertSame( 1, $tokens['id:42']['strength'] );
		$this->assertSame( 2, $tokens['path:2025/photo.webp']['strength'] );
	}
	public function test_encoded_relative_url_and_srcset(): void {
		$tokens = Matcher::tokens( '<img srcset="/2025/a%2Bb.jpg 300w, /2025/a%2Bb-600x400.jpg 600w">' );
		$this->assertArrayHasKey( 'path:2025/a+b.jpg', $tokens );
		$this->assertArrayHasKey( 'path:2025/a+b-600x400.jpg', $tokens );
	}
	/** @dataProvider formulas */
	public function test_csv_formula_injection_is_neutralized( string $value ): void {
		$this->assertSame( "'" . $value, Matcher::csv( $value ) );
	}
	public static function formulas(): array { return array( array( '=SUM(A1)' ), array( '+cmd' ), array( '-10' ), array( '@SUM(A1)' ), array( "\t=HYPERLINK(1)" ), array( "\r +1" ) ); }
	public function test_normal_csv_text_is_preserved(): void { $this->assertSame( 'photo.jpg', Matcher::csv( 'photo.jpg' ) ); }
}
