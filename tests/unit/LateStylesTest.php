<?php
/**
 * Stylesheets printed in the body move to the head, in order.
 *
 * Page builders print header and widget styles at the end of the page. On a
 * phone the header was drawn unstyled, then snapped into shape, moving the
 * whole page (CLS 0.97 on a live Elementor site, 0.003 after this change).
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Styles;

/**
 * Late stylesheet hoisting.
 */
final class LateStylesTest extends TestCase {

	/**
	 * A page with late styles, shaped like the one that failed.
	 *
	 * @return string
	 */
	private function page(): string {
		return '<!doctype html><html><head><title>x</title><link rel="stylesheet" id="theme-css" href="/theme.css"></head>'
			. '<body><header class="site-header">Menu</header><main>Content</main>'
			. '<style>.a{color:red}</style>'
			. '<link rel="stylesheet" id="header-css" href="/post-5155.css" media="all">'
			. '<svg><style>.icon{fill:red}</style></svg>'
			. '<noscript><link rel="stylesheet" href="/noscript.css"></noscript>'
			. '<script>var s = "<link rel=\'stylesheet\' href=\'/in-script.css\'>";</script>'
			. '<link rel=\'stylesheet\' id=\'addons-css\' href=\'/addons.css\'>'
			. '<style id="after">.b{color:blue}</style>'
			. '<footer>f</footer></body></html>';
	}

	/**
	 * Everything up to the last body stylesheet moves, keeping its order.
	 *
	 * @return void
	 */
	public function test_late_styles_move_to_the_head_in_order(): void {
		$out  = Styles::hoist( $this->page() );
		$head = substr( $out, 0, (int) strpos( $out, '</head>' ) );
		$body = substr( $out, (int) strpos( $out, '</head>' ) );

		$this->assertStringContainsString( 'post-5155.css', $head );
		$this->assertStringContainsString( 'addons.css', $head );
		$this->assertStringContainsString( '.a{color:red}', $head );

		// Original cascade order: theme, .a, header, addons, then .b.
		$order = array( 'theme.css', '.a{color:red}', 'post-5155.css', 'addons.css' );
		$last  = -1;
		foreach ( $order as $needle ) {
			$at = strpos( $head, $needle );
			$this->assertGreaterThan( $last, $at, $needle . ' moved out of order' );
			$last = $at;
		}
		$this->assertStringContainsString( '<style id="after">', $body, 'a style after the last stylesheet stays where it was' );
	}

	/**
	 * Markup that belongs where it is never moves.
	 *
	 * @return void
	 */
	public function test_protected_regions_stay_put(): void {
		$out  = Styles::hoist( $this->page() );
		$body = substr( $out, (int) strpos( $out, '</head>' ) );
		$this->assertStringContainsString( '<svg><style>.icon{fill:red}</style></svg>', $body );
		$this->assertStringContainsString( '<noscript><link rel="stylesheet" href="/noscript.css"></noscript>', $body );
		$this->assertStringContainsString( "in-script.css'>\";</script>", $body );
		$this->assertStringContainsString( '<header class="site-header">Menu</header>', $body );
	}

	/**
	 * Nothing changes when there is nothing to wait for.
	 *
	 * @return void
	 */
	public function test_pages_without_late_stylesheets_are_untouched(): void {
		$plain = '<html><head><link rel="stylesheet" href="/a.css"></head><body><p>x</p><style>.x{}</style></body></html>';
		$this->assertSame( $plain, Styles::hoist( $plain ) );
		$this->assertSame( 'not html', Styles::hoist( 'not html' ) );
		$amp = '<html amp><head></head><body><link rel="stylesheet" href="/a.css"></body></html>';
		$this->assertSame( $amp, Styles::hoist( $amp ) );
	}

	/**
	 * The page cache must store the page after every rewrite.
	 *
	 * @return void
	 */
	public function test_cache_buffer_is_outermost(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/cache/class-cache.php' );
		$this->assertStringContainsString( "add_action( 'template_redirect', array( self::class, 'start' ), -1000 );", $code );
	}

	/**
	 * Delayed scripts must not replay load events to the whole page.
	 *
	 * @return void
	 */
	public function test_runtime_does_not_replay_events_globally(): void {
		$code    = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/speed/class-scripts.php' );
		$runtime = (string) strstr( $code, 'public static function runtime' );
		$this->assertStringNotContainsString( 'dispatchEvent', $runtime );
		$this->assertStringNotContainsString( 'trigger("ready")', $runtime );
		$this->assertStringContainsString( 'if(!s.length){document.documentElement.classList.add("smao-ran");return}', $runtime, 'with nothing delayed, nothing runs, and pre-built styles are released' );
		$this->assertStringContainsString( 'classList.add("smao-ran")', substr( $runtime, (int) strpos( $runtime, 'function done()' ), 200 ), 'finishing releases the pre-built styles' );
	}
}
