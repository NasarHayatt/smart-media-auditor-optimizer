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

	/**
	 * Google Fonts stylesheets are written into the page once fetched.
	 *
	 * @return void
	 */
	public function test_google_fonts_are_written_into_the_page(): void {
		$asked = array();
		$fetch = static function ( string $url ) use ( &$asked ): string {
			$asked[] = $url;
			return str_contains( $url, 'Karla' ) ? '@font-face{font-family:Karla;font-display:swap;src:url(https://fonts.gstatic.com/k.woff2) format("woff2")}' : '';
		};
		$html = '<head><link href="https://fonts.googleapis.com/css?family=Karla:500&display=swap" rel="stylesheet" media="all" type="text/css" >'
			. "<link rel='stylesheet' id='theme-fonts-css' href='//fonts.googleapis.com/css?family=Heebo%3A700&#038;subset=latin' media='all' />"
			. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Karla&display=swap" media="print" onload="this.media=\'all\'">'
			. '<link rel="preconnect" href="https://fonts.googleapis.com"></head>';
		$out  = Markup::google_fonts( $html, $fetch );

		$this->assertStringContainsString( '<style id="smao-font-', $out );
		$this->assertStringContainsString( 'font-family:Karla', $out );
		$this->assertStringNotContainsString( 'family=Karla:500', $out, 'the fetched one is no longer linked' );
		$this->assertStringContainsString( "id='theme-fonts-css'", $out, 'one not fetched yet stays linked' );
		$this->assertContains( 'https://fonts.googleapis.com/css?family=Heebo%3A700&subset=latin&display=swap', $asked, 'protocol, entities and swap fixed' );
		$this->assertStringContainsString( 'media="print" onload=', $out, 'one already loading in the background is left alone' );
		$this->assertStringContainsString( '<link rel="preconnect"', $out );
		$this->assertSame( '<p>no fonts</p>', Markup::google_fonts( '<p>no fonts</p>', $fetch ) );
	}

	/**
	 * A YouTube frame shows its picture until someone presses play.
	 *
	 * @return void
	 */
	public function test_youtube_frames_wait_for_a_click(): void {
		$html = '<html><head></head><body><iframe title="Introducing D. I. Khan New City" width="1080" height="608" src="https://www.youtube.com/embed/djMzNxNOs2E?feature=oembed" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>'
			. '<iframe src="https://player.vimeo.com/video/1"></iframe></body></html>';
		$out  = Markup::lazy_iframes( $html );

		$this->assertStringContainsString( 'src="https://www.youtube.com/embed/djMzNxNOs2E?feature=oembed"', $out, 'the real address stays' );
		$this->assertStringContainsString( ' srcdoc="', $out );
		$this->assertStringContainsString( 'i.ytimg.com/vi/djMzNxNOs2E/hqdefault.jpg', $out );
		$this->assertStringContainsString( 'embed/djMzNxNOs2E?feature=oembed&amp;amp;autoplay=1', $out, 'pressing play starts the video' );
		$this->assertStringContainsString( 'allow="autoplay; encrypted-media" allowfullscreen', $out );
		$this->assertSame( 1, substr_count( $out, 'srcdoc=' ), 'other players are left alone' );
		$this->assertSame( 2, substr_count( $out, 'loading="lazy"' ) );
		$this->assertSame( $out, Markup::lazy_iframes( $out ), 'done once only' );
	}

	/**
	 * Stylesheets on open public hosts are requested so they can be read.
	 *
	 * @return void
	 */
	public function test_open_host_stylesheets_are_readable(): void {
		$cdn  = "<link rel='stylesheet' id='jquery-ui-css-css' href='https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css?ver=6.6.9' media='all' />";
		$out  = \SMAO\Styles::readable( $cdn, 'jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css?ver=6.6.9', 'all' );
		$this->assertStringStartsWith( '<link crossorigin="anonymous" rel=', $out );
		$own  = "<link rel='stylesheet' id='a-css' href='https://example.com/a.css' media='all' />";
		$this->assertSame( $own, \SMAO\Styles::readable( $own, 'a', 'https://example.com/a.css', 'all' ) );
		$this->assertSame( $out, \SMAO\Styles::readable( $out, 'jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css', 'all' ), 'never twice' );
	}

	/**
	 * Only plain font stylesheets are ever written into a page.
	 *
	 * @return void
	 */
	public function test_fetched_font_css_is_checked(): void {
		$this->assertTrue( Markup::font_css_ok( '@font-face{font-family:A;src:url(https://fonts.gstatic.com/a.woff2)}' ) );
		$this->assertFalse( Markup::font_css_ok( '' ) );
		$this->assertFalse( Markup::font_css_ok( '<html>error</html>' ) );
		$this->assertFalse( Markup::font_css_ok( '@font-face{}</style><script>alert(1)</script>' ) );
		$this->assertFalse( Markup::font_css_ok( '@import url(x.css);@font-face{}' ) );
		$this->assertFalse( Markup::font_css_ok( 'body{color:red}' ) );
	}
}
