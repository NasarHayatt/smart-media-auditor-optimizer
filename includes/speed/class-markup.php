<?php
/**
 * Lighter pages: no emoji script, lazy iframes, minified markup.
 *
 * Each change leaves the page looking and working the same: WordPress's
 * emoji script only replaced emoji that every current browser draws itself,
 * a lazy iframe still loads as soon as it nears the screen, and minifying
 * removes only comments, and spare spaces inside style blocks.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Markup-level optimisations.
 */
final class Markup {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( is_admin() || Rightsize::measuring() ) {
			return;
		}
		$settings = Settings::get();
		if ( ! $settings['speed_enabled'] ) {
			return;
		}
		if ( $settings['disable_emoji'] ) {
			self::no_emoji();
		}
		if ( $settings['lazy_iframes'] || $settings['minify_html'] ) {
			// After every other rewrite and before the page cache stores the
			// page, so the stored copy is the finished, smaller one.
			add_action( 'template_redirect', array( self::class, 'start' ), -900 );
		}
	}

	/**
	 * Stop WordPress printing its emoji detection script and styles.
	 *
	 * @return void
	 */
	private static function no_emoji(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter(
			'wp_resource_hints',
			static function ( array $urls, string $type ): array {
				if ( 'dns-prefetch' !== $type ) {
					return $urls;
				}
				return array_values(
					array_filter(
						$urls,
						static function ( $url ): bool {
							return ! str_contains( is_array( $url ) ? (string) ( $url['href'] ?? '' ) : (string) $url, 's.w.org/images/core/emoji' );
						}
					)
				);
			},
			10,
			2
		);
	}

	/**
	 * Begin buffering the page.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( is_feed() || is_embed() || is_customize_preview() ) {
			return;
		}
		ob_start( array( self::class, 'page' ) );
	}

	/**
	 * Output buffer callback.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function page( string $html ): string {
		if ( ! str_contains( $html, '</html>' ) ) {
			return $html;
		}
		$settings = Settings::get();
		if ( $settings['lazy_iframes'] ) {
			$html = self::lazy_iframes( $html );
		}
		if ( $settings['minify_html'] ) {
			$html = self::minify( $html );
		}
		return $html;
	}

	/**
	 * Let iframes load as they near the screen instead of with the page.
	 *
	 * Maps, videos and other embeds are the heaviest things on many pages.
	 * The browser still loads one straight away when it is on screen.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function lazy_iframes( string $html ): string {
		$start = stripos( $html, '<body' );
		if ( false === $start ) {
			return $html;
		}
		$body = (string) preg_replace_callback(
			'#<iframe\b(?![^>]*\sloading\s*=)([^>]*)>#i',
			static function ( array $match ): string {
				// Hidden and tracking frames load nothing a visitor sees anyway.
				if ( preg_match( '#\s(width|height)\s*=\s*["\']?[01]["\'\s>]#i', $match[1] ) || preg_match( '#display\s*:\s*none#i', $match[1] ) ) {
					return $match[0];
				}
				return '<iframe loading="lazy"' . $match[1] . '>';
			},
			substr( $html, $start )
		);
		return substr( $html, 0, $start ) . $body;
	}

	/**
	 * Remove what never changes how a page is drawn: HTML comments, and the
	 * comments and spare spaces in its style blocks.
	 *
	 * Whitespace in the page's text is left exactly as it is. A theme can draw
	 * text with its line breaks and spaces kept (white-space: pre-line), and
	 * collapsing them changed where a live page's text wrapped. Scripts,
	 * conditional comments, pre and textarea are never touched.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function minify( string $html ): string {
		$kept  = array();
		$keep  = static function ( string $text ) use ( &$kept ): string {
			$kept[] = $text;
			return "\x1A" . ( count( $kept ) - 1 ) . "\x1A";
		};
		// Protect what must not change.
		$html = (string) preg_replace_callback(
			'#<(pre|textarea|script|xmp)\b[^>]*>.*?</\1\s*>|<!--\[if.*?<!\[endif\]-->#is',
			static function ( array $match ) use ( $keep ): string {
				return $keep( $match[0] );
			},
			$html
		);
		$html = (string) preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
			static function ( array $match ) use ( $keep ): string {
				return $keep( $match[1] . self::minify_css( $match[2] ) . $match[3] );
			},
			$html
		);
		// Comments, except markers other tools rely on.
		$html = (string) preg_replace( '#<!--(?!\s*(?:/?noindex|google_ad|esi))(?!\[if).*?-->#s', '', $html );
		return (string) preg_replace_callback(
			"#\x1A(\d+)\x1A#",
			static function ( array $match ) use ( &$kept ): string {
				return $kept[ (int) $match[1] ];
			},
			trim( $html )
		);
	}

	/**
	 * Minify a block of CSS without changing what it means.
	 *
	 * @param string $css Styles.
	 * @return string
	 */
	public static function minify_css( string $css ): string {
		if ( str_contains( $css, '"' ) || str_contains( $css, "'" ) ) {
			// Strings may contain anything, including comment markers; only
			// collapse whitespace outside them.
			$parts = preg_split( '#("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')#s', $css, -1, PREG_SPLIT_DELIM_CAPTURE );
			$out   = '';
			foreach ( (array) $parts as $index => $part ) {
				$out .= ( $index % 2 ) ? $part : self::squeeze( $part );
			}
			return trim( $out );
		}
		return trim( self::squeeze( $css ) );
	}

	/**
	 * Squeeze CSS that contains no strings.
	 *
	 * @param string $css Styles.
	 * @return string
	 */
	private static function squeeze( string $css ): string {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = (string) preg_replace( '#\s+#', ' ', $css );
		return (string) preg_replace( '#\s*([{};])\s*#', '$1', $css );
	}
}
