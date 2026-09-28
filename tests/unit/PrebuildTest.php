<?php
/**
 * Pre-built layout: labelling and the decision to hold scripts.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Prebuild;

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
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

/**
 * Pre-build behaviour.
 */
final class PrebuildTest extends TestCase {

	/**
	 * The finished and the held page must get identical labels, whatever
	 * their scripts look like.
	 *
	 * @return void
	 */
	public function test_labels_do_not_depend_on_scripts(): void {
		$finished = '<html><head><title>t</title></head><body><div class="a"><img src="x.png"><script>var x = "<div>";</script><p>Hi</p></div><svg><g><rect/></g></svg><section>s</section></body></html>';
		$held     = str_replace( '<script>var x = "<div>";</script>', '<script type="smao/delayed" src="/big.js"></script><script type="smao/delayed">var y = "<span><b>";</script>', $finished );
		preg_match_all( '/<(\w+)[^>]*data-smao-n="(\d+)"/', Prebuild::stamp( $finished ), $a );
		preg_match_all( '/<(\w+)[^>]*data-smao-n="(\d+)"/', Prebuild::stamp( $held ), $b );
		$this->assertSame( array_combine( $a[2], $a[1] ), array_combine( $b[2], $b[1] ) );
		$this->assertSame( array( 'body', 'div', 'img', 'p', 'section' ), $a[1], 'scripts, SVG internals and head tags are not labelled' );
	}

	/**
	 * Only a page that matches on every measure may hold its scripts.
	 *
	 * @return void
	 */
	public function test_verdict(): void {
		$this->assertSame( 'ready', Prebuild::verdict( 15000, 0.0, 0.0, 0.0, 0.0 ) );
		$this->assertSame( 'moved', Prebuild::verdict( 15000, 0.03, 0.0, 0.0, 0.0 ) );
		$this->assertSame( 'moved', Prebuild::verdict( 15000, 0.0, 0.02, 0.0, 0.0 ), 'page length changed' );
		$this->assertSame( 'moved', Prebuild::verdict( 15000, 0.0, 0.0, 0.05, 0.0 ), 'content missing while held' );
		$this->assertSame( 'moved', Prebuild::verdict( 15000, 0.0, 0.0, 0.0, 0.3 ), 'pieces off target' );
		$this->assertSame( 'too_large', Prebuild::verdict( 500000, 0.0, 0.0, 0.0, 0.0 ) );
		$this->assertSame( 'ready', Prebuild::verdict( 3000, 0.0, 0.0, 0.0, 0.0, 65544 ), 'judged as sent, compressed' );
		$this->assertSame( 'too_large', Prebuild::verdict( 3000, 0.0, 0.0, 0.0, 0.0, 600000 ), 'too much to read, however well it compresses' );
		$this->assertSame( 'unchecked', Prebuild::verdict( 15000, -1.0, 0.0, 0.0, 0.0 ) );
	}

	/**
	 * Failed checks never store styles; tags cannot be smuggled in.
	 *
	 * @return void
	 */
	public function test_clean(): void {
		$bad = Prebuild::clean( array( 'css' => 'a{}', 'shift' => 0.5, 'height' => 0, 'missing' => 0, 'off' => 0 ) );
		$this->assertSame( 'moved', $bad['status'] );
		$this->assertSame( '', $bad['css'] );
		$good = Prebuild::clean( array( 'css' => '</style><script>x</script>a{color:red}', 'shift' => 0, 'height' => 0, 'missing' => 0, 'off' => 0 ) );
		$this->assertSame( 'ready', $good['status'] );
		$this->assertStringNotContainsString( '<', $good['css'] );
		$this->assertSame( 'unchecked', Prebuild::clean( array( 'css' => 'a{}' ) )['status'] );
	}

	/**
	 * Holding every script requires a verified page.
	 *
	 * @return void
	 */
	public function test_holding_requires_a_verified_page(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/speed/class-scripts.php' );
		$rule = (string) strstr( $code, 'public static function holding_all' );
		$this->assertStringContainsString( 'Prebuild::allows_holding()', substr( $rule, 0, 400 ) );
	}

	/**
	 * Limits in the browser and on the server are the same numbers.
	 *
	 * @return void
	 */
	public function test_limits_match_the_browser(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/prebuild.js' );
		$this->assertStringContainsString(
			sprintf( 'var LIMITS = { shift: %s, height: %s, missing: %s, off: %s };', Prebuild::MAX_SHIFT, Prebuild::MAX_HEIGHT, Prebuild::MAX_MISSING, Prebuild::MAX_OFF ),
			$js
		);
	}

	/**
	 * The failing width and measure are kept and named in plain words.
	 *
	 * @return void
	 */
	public function test_reason_names_the_width_and_the_measure(): void {
		$stored = Prebuild::clean(
			array(
				'css'     => '#a{height:1px}',
				'shift'   => 0,
				'height'  => 0.04,
				'missing' => 0.002,
				'off'     => 0,
				'width'   => 768,
				'widths'  => array( array( 'width' => 768, 'shift' => 0, 'height' => 0.04, 'missing' => null, 'off' => 'x' ), 'junk' ),
			)
		);
		$this->assertSame( 'moved', $stored['status'] );
		$this->assertSame( 768, $stored['width'] );
		$this->assertSame( array( array( 'width' => 768, 'shift' => 0.0, 'height' => 0.04, 'missing' => null, 'off' => null ) ), $stored['widths'] );
		$this->assertSame( 'Scripts run as normal: on a 768px wide screen the page length would change by 4%.', Prebuild::reason( $stored ) );
		$this->assertStringStartsWith( 'Looks the same', Prebuild::reason( array( 'status' => 'ready' ) ) );
		$this->assertStringContainsString( 'Measure again', Prebuild::reason( array( 'status' => 'unchecked' ) ) );
	}

	/**
	 * Repetitive rules are measured as they travel.
	 *
	 * @return void
	 */
	public function test_sent_size_is_compressed(): void {
		$css = str_repeat( "html:not(.smao-ran) #menu-main-menu > li.menu-item:nth-of-type(2) > a{position:absolute!important;left:80px!important}
", 400 );
		$this->assertLessThan( strlen( $css ) / 10, Prebuild::sent_size( $css ) );
		$this->assertSame( 0, Prebuild::sent_size( '' ) );
		$stored = Prebuild::clean( array( 'css' => $css, 'shift' => 0, 'height' => 0, 'missing' => 0, 'off' => 0 ) );
		$this->assertSame( 'ready', $stored['status'] );
		$this->assertSame( Prebuild::sent_size( $css ), $stored['sent'] );
	}

	/**
	 * Measuring frames have no scrollbar, and visitors' scrollbars are
	 * taken off full-width areas by a script that is never held back.
	 *
	 * @return void
	 */
	public function test_scrollbars_do_not_change_the_result(): void {
		$root = dirname( __DIR__, 2 );
		$this->assertStringContainsString( 'html{scrollbar-width:none}', (string) file_get_contents( $root . '/includes/delivery/class-rightsize.php' ) );
		$this->assertStringContainsString( 'setProperty("--smao-sb"', Prebuild::SCROLLBAR );
		$this->assertStringContainsString( "str_contains( \$attributes, 'smao-prebuild' )", (string) file_get_contents( $root . '/includes/speed/class-scripts.php' ) );
		$this->assertStringContainsString( 'calc(100vw - var(--smao-sb,0px))', (string) file_get_contents( $root . '/assets/prebuild.js' ) );
	}
}
