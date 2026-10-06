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
		add_action( self::FONT_TASK, array( self::class, 'fetch_font' ) );
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
		if ( $settings['lazy_iframes'] || $settings['minify_html'] || $settings['optimize_fonts'] ) {
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
		if ( $settings['optimize_fonts'] && ! Environment::conflict( 'assets' ) ) {
			$html = self::google_fonts( $html, array( self::class, 'font_css' ) );
		}
		if ( $settings['lazy_iframes'] ) {
			$html = self::lazy_iframes( $html );
		}
		if ( $settings['minify_html'] ) {
			$html = self::minify( $html );
		}
		return $html;
	}

	/**
	 * Write Google Fonts stylesheets into the page instead of linking them.
	 *
	 * Each one is a separate server the browser must connect to before it
	 * can draw anything; on a live phone test two of them held the first
	 * paint back by about a second. Their few kilobytes, written into the
	 * page, cost nothing to wait for. The font files themselves still come
	 * from Google, and text shows straight away in a fallback font until they
	 * arrive. A stylesheet not fetched yet stays linked as it was.
	 *
	 * @param string   $html  Page.
	 * @param callable $fetch Stylesheet URL to its CSS, or '' when not at hand.
	 * @return string
	 */
	public static function google_fonts( string $html, callable $fetch ): string {
		if ( ! str_contains( $html, 'fonts.googleapis.com/css' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#<link\b[^>]*>#i',
			static function ( array $match ) use ( $fetch ): string {
				$tag = $match[0];
				if ( ! preg_match( '#\brel\s*=\s*["\']?stylesheet\b#i', $tag ) || preg_match( '#\bmedia\s*=\s*["\']?print#i', $tag )
					|| ! preg_match( '#\bhref\s*=\s*(["\'])((?:https?:)?//fonts\.googleapis\.com/css2?\?[^"\'<>]+)\1#i', $tag, $href ) ) {
					return $tag;
				}
				$url = html_entity_decode( $href[2], ENT_QUOTES );
				$url = str_starts_with( $url, '//' ) ? 'https:' . $url : $url;
				if ( ! str_contains( $url, 'display=' ) ) {
					$url .= '&display=swap';
				}
				$css = (string) $fetch( $url );
				if ( '' === $css ) {
					return $tag;
				}
				$media = preg_match( '#\bmedia\s*=\s*(["\'])([^"\']*)\1#i', $tag, $m ) && '' !== trim( $m[2] ) && 'all' !== strtolower( trim( $m[2] ) ) ? ' media="' . htmlspecialchars( $m[2], ENT_QUOTES ) . '"' : '';
				return '<style id="smao-font-' . substr( md5( $url ), 0, 8 ) . '"' . $media . '>' . $css . '</style>';
			},
			$html
		);
	}

	/**
	 * A Google Fonts stylesheet from the cache, fetched once in the
	 * background when it is not there yet.
	 *
	 * The fetch used to wait for the end of the request, but it was asked
	 * for while the page itself was being sent, too late to ever run: a live
	 * site never got its fonts written in. A WordPress background task does
	 * it now.
	 *
	 * @param string $url Stylesheet URL.
	 * @return string
	 */
	public static function font_css( string $url ): string {
		$cached = get_transient( self::font_key( $url ) );
		if ( is_array( $cached ) ) {
			return (string) ( $cached['css'] ?? '' );
		}
		// Fetched straight away, at most once an hour and for three seconds:
		// the page is stored by the page cache, so visitors never wait for it.
		// A background task alone never ran on a live host.
		if ( function_exists( 'wp_remote_get' ) && ! get_transient( self::font_key( $url ) . '_try' ) ) {
			set_transient( self::font_key( $url ) . '_try', 1, HOUR_IN_SECONDS );
			self::fetch_font( $url, 3, false );
			$cached = get_transient( self::font_key( $url ) );
			return is_array( $cached ) ? (string) ( $cached['css'] ?? '' ) : '';
		}
		return '';
	}

	/** Why the last Google Fonts fetch failed, shown on the Speed screen. */
	public const FONT_ERROR = 'smao_gfont_error';

	/** Background task that fetches one Google Fonts stylesheet. */
	public const FONT_TASK = 'smao_google_font';

	/**
	 * Cache key for a Google Fonts stylesheet.
	 *
	 * @param string $url Stylesheet URL.
	 * @return string
	 */
	private static function font_key( string $url ): string {
		return 'smao_gfont_' . md5( $url );
	}

	/**
	 * Fetch one Google Fonts stylesheet, then refresh stored pages so
	 * visitors get it written in.
	 *
	 * @param string $url     Stylesheet URL.
	 * @param int    $timeout Seconds to wait for Google.
	 * @param bool   $refresh Whether to refresh stored pages afterwards.
	 * @return void
	 */
	public static function fetch_font( string $url, int $timeout = 8, bool $refresh = true ): void {
		$key = self::font_key( $url );
		if ( is_array( get_transient( $key ) ) || ! preg_match( '#^https://fonts\.googleapis\.com/css2?\?#', $url ) ) {
			return;
		}
		// A current browser, so the answer lists compact WOFF2 files.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => $timeout,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
			)
		);
		$css  = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		$good = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && self::font_css_ok( $css );
		set_transient( $key, array( 'css' => $good ? trim( $css ) : '' ), $good ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );
		if ( $good ) {
			delete_option( self::FONT_ERROR );
		} else {
			update_option( self::FONT_ERROR, is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ), false );
		}
		if ( $good && $refresh && Cache::active() ) {
			Cache::flush();
		}
	}

	/**
	 * Whether fetched text is a plain font stylesheet, safe to write into a page.
	 *
	 * @param string $css Fetched text.
	 * @return bool
	 */
	public static function font_css_ok( string $css ): bool {
		return '' !== $css && strlen( $css ) < 262144 && str_contains( $css, '@font-face' )
			&& ! str_contains( $css, '<' ) && ! preg_match( '#@import|expression\(|javascript:#i', $css );
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
		$body = self::youtube( substr( $html, $start ) );
		$html = substr( $html, 0, $start ) . $body;
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
	 * Show a YouTube video's picture until someone presses play.
	 *
	 * An embedded player loads about 850 KB of YouTube's scripts the moment
	 * it nears the screen, whether or not anyone watches: on a live home
	 * page that was the largest download, ahead of every image. The frame
	 * stays exactly where it is, so the layout is untouched; it first shows
	 * the video's thumbnail and a play button, and pressing play loads the
	 * real player in its place and starts the video.
	 *
	 * @param string $html Page body.
	 * @return string
	 */
	public static function youtube( string $html ): string {
		if ( ! str_contains( $html, 'youtube' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#<iframe\b(?![^>]*\ssrcdoc\s*=)([^>]*)\ssrc\s*=\s*(["\'])(https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,20})([^"\']*))\2([^>]*)>#i',
			static function ( array $m ): string {
				$url    = html_entity_decode( $m[3], ENT_QUOTES );
				$params = (string) wp_parse_url( $url, PHP_URL_QUERY );
				$play   = strtok( $url, '?' ) . '?' . ( '' !== $params ? $params . '&' : '' ) . 'autoplay=1';
				$title  = preg_match( '#\stitle\s*=\s*(["\'])(.*?)\1#i', $m[1] . ' ' . $m[6], $t ) ? $t[2] : 'Video';
				$page   = '<style>*{margin:0;padding:0;overflow:hidden}html,body{height:100%;background:#000}a,img{position:absolute;inset:0;width:100%;height:100%}img{object-fit:cover}'
					. 'span{position:absolute;top:50%;left:50%;width:68px;height:48px;margin:-24px 0 0 -34px;border-radius:12px;background:#f00;opacity:.85}'
					. 'span:after{content:"";position:absolute;left:27px;top:14px;border-style:solid;border-width:10px 0 10px 17px;border-color:transparent transparent transparent #fff}a:hover span{opacity:1}</style>'
					. '<a href="' . htmlspecialchars( $play, ENT_QUOTES ) . '"><img src="https://i.ytimg.com/vi/' . $m[4] . '/hqdefault.jpg" alt="' . $title . '"><span></span></a>';
				return '<iframe' . $m[1] . ' src=' . $m[2] . $m[3] . $m[2] . ' srcdoc="' . htmlspecialchars( $page, ENT_QUOTES ) . '"' . $m[6] . '>';
			},
			$html
		);
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
