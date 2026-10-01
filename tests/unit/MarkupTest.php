<?php
/**
 * Minifying and lazy iframes never change what a page shows or does.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Markup;

/**
 * Markup optimisations.
 */
final class MarkupTest extends TestCase {

	/**
	 * Whitespace that matters is left alone; the rest shrinks.
	 *
	 * @return void
	 */
	public function test_minify_keeps_what_matters(): void {
		$page = "<!doctype html>\n<html>\n  <head>\n    <!-- theme comment -->\n    <!--[if IE]><p>old</p><![endif]-->\n"
			. "    <style>\n /* note */\n .a  {  color : red ;  }\n .b::before { content: \"  two  spaces /* not a comment */ \"; }\n    </style>\n"
			. "    <script>var  s = '  keep   me  ';\n// line\n</script>\n  </head>\n  <body>\n"
			. "    <p>One    two</p>\n    <pre>  a\n    b  </pre>\n    <textarea>  x\n  y </textarea>\n    <span>a</span> <span>b</span>\n  </body>\n</html>\n";
		$out  = Markup::minify( $page );

		$this->assertStringNotContainsString( 'theme comment', $out );
		$this->assertStringContainsString( '<!--[if IE]><p>old</p><![endif]-->', $out );
		$this->assertStringContainsString( ".a{color : red;}", $out );
		$this->assertStringContainsString( 'content: "  two  spaces /* not a comment */ "', $out, 'strings in CSS are untouched' );
		$this->assertStringContainsString( "var  s = '  keep   me  ';\n// line\n", $out, 'scripts are untouched' );
		$this->assertStringContainsString( "<pre>  a\n    b  </pre>", $out );
		$this->assertStringContainsString( "<textarea>  x\n  y </textarea>", $out );
		$this->assertStringContainsString( '<p>One    two</p>', $out, 'text whitespace can be drawn as written (pre-line), so it stays' );
		$this->assertStringContainsString( '<span>a</span> <span>b</span>', $out, 'the space between inline elements stays' );
		$this->assertLessThan( strlen( $page ), strlen( $out ) );
	}

	/**
	 * Visible iframes load lazily; hidden and tracking ones and those that
	 * already chose are left as they are.
	 *
	 * @return void
	 */
	public function test_lazy_iframes(): void {
		$page = '<html><head><iframe src="/head"></iframe></head><body>'
			. '<iframe src="https://www.google.com/maps/embed?pb=1" width="600" height="450"></iframe>'
			. '<iframe loading="eager" src="/eager"></iframe>'
			. '<iframe src="/pixel" width="1" height="1"></iframe>'
			. '<iframe src="/hidden" style="display:none"></iframe>'
			. '</body></html>';
		$out  = Markup::lazy_iframes( $page );
		$this->assertStringContainsString( '<iframe loading="lazy" src="https://www.google.com/maps/embed?pb=1"', $out );
		$this->assertStringContainsString( '<iframe loading="eager" src="/eager">', $out );
		$this->assertStringContainsString( '<iframe src="/pixel" width="1" height="1">', $out );
		$this->assertStringContainsString( '<iframe src="/hidden" style="display:none">', $out );
		$this->assertStringContainsString( '<head><iframe src="/head">', $out, 'only the body is changed' );
	}
}
