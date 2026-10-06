<?php
/**
 * CSS delivery and font loading.
 *
 * A stylesheet in the head blocks the first paint until it has downloaded and
 * parsed. Most of a WordPress page's CSS is not needed for what appears in the
 * first screenful, so loading it asynchronously lets the page paint sooner,
 * which moves First Contentful Paint, Speed Index and Largest Contentful Paint.
 *
 * Doing that without care makes the page paint unstyled and then jump into
 * place, which is a far worse Cumulative Layout Shift than the paint time it
 * saves. So a stylesheet only loads asynchronously on a page whose above-the-fold
 * styles were captured, at phone and desktop widths, and then verified: the
 * page was laid out again with nothing but those styles and compared with the
 * real layout. If anything visible moved, that page keeps its stylesheets
 * blocking. A stylesheet that was not present when the page was verified also
 * keeps blocking, because the captured styles cannot cover it.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Stylesheet and font delivery.
 */
final class Styles {

	/**
	 * Handles that must keep blocking. Removing these causes visible damage.
	 */
	private const NEVER = array(
		'admin-bar',
		'dashicons',
		'smao-admin',
	);

	/**
	 * Above this, inlining costs more than the render-blocking it removes.
	 */
	private const MAX_CRITICAL = 153600;

	/**
	 * Public stylesheet hosts that let any page read their files.
	 *
	 * A stylesheet from another site can only be read, and so captured and
	 * loaded in the background, when it is requested that way; otherwise it
	 * must keep holding up the first paint. jQuery UI's, from code.jquery.com,
	 * held a live phone page's first paint back by about a second.
	 */
	private const OPEN_HOSTS = array( 'code.jquery.com', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'fonts.googleapis.com', 'use.fontawesome.com', 'maxcdn.bootstrapcdn.com', 'stackpath.bootstrapcdn.com', 'ajax.googleapis.com' );

	/**
	 * Option holding verified above-the-fold styles, keyed by page.
	 */
	private const OPTION = 'smao_critical_pages';

	/**
	 * Most pages the option keeps; the oldest capture is dropped first.
	 */
	private const MAX_PAGES = 60;

	/**
	 * Largest layout movement tolerated when verifying captured styles.
	 *
	 * Measured like Cumulative Layout Shift. Google calls under 0.1 good for a
	 * whole page load; the captured styles alone must stay far below that.
	 */
	public const MAX_SHIFT = 0.01;

	/**
	 * Fetches the background stylesheets once the page has loaded, the
	 * visitor acts, or four seconds after the page was read, whichever comes
	 * first, and switches them all on in one go once they have arrived, or
	 * three seconds after fetching began. Its id keeps it running when every
	 * other script waits.
	 */
	public const SWITCH_ON = '(function(){var on=0,go=0,E=["load","scroll","keydown","mousemove","touchstart","pointerdown","wheel"];function all(){if(on)return;on=1;var l=document.querySelectorAll("link[data-smao-media]");for(var i=0;i<l.length;i++){if(l[i].hasAttribute("data-smao-href"))l[i].href=l[i].getAttribute("data-smao-href");l[i].media=l[i].getAttribute("data-smao-media");l[i].removeAttribute("data-smao-media")}}function start(){if(go)return;go=1;E.forEach(function(e){window.removeEventListener(e,start,{passive:!0})});var l=document.querySelectorAll("link[data-smao-href]");for(var i=0;i<l.length;i++){l[i].href=l[i].getAttribute("data-smao-href");l[i].removeAttribute("data-smao-href")}setTimeout(all,3000)}window.smaoCss=function(){if(!go||document.readyState==="loading")return;var l=document.querySelectorAll("link[data-smao-media]");for(var i=0;i<l.length;i++){if(!l[i].hasAttribute("data-smao-l"))return}all()};E.forEach(function(e){window.addEventListener(e,start,{passive:!0})});document.addEventListener("DOMContentLoaded",function(){setTimeout(start,4000)})})()';

	/**
	 * Handles noted during a measurement pass.
	 *
	 * @var array<int,string>
	 */
	private static array $recorded = array();

	/**
	 * Whether at least one enqueued stylesheet would be made asynchronous.
	 *
	 * @param array $entry Verified capture for this page.
	 * @return bool
	 */
	private static function will_defer_any( array $entry ): bool {
		$styles = wp_styles();
		if ( ! $styles instanceof \WP_Styles ) {
			return false;
		}
		foreach ( (array) $styles->queue as $handle ) {
			$item = $styles->registered[ $handle ] ?? null;
			if ( ! $item || ! $item->src ) {
				continue; // Inline-only styles are not render-blocking links.
			}
			if ( in_array( $handle, $entry['handles'], true ) && self::deferrable( (string) $handle, (string) $item->src ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a stylesheet is ever a candidate for asynchronous loading.
	 *
	 * @param string $handle Style handle.
	 * @param string $href   Stylesheet URL.
	 * @return bool
	 */
	public static function deferrable( string $handle, string $href ): bool {
		if ( in_array( $handle, self::NEVER, true ) ) {
			return false;
		}
		foreach ( self::rules( Settings::get()['style_exclusions'] ) as $needle ) {
			if ( $handle === $needle || str_contains( $href, $needle ) ) {
				return false;
			}
		}
		/**
		 * Filter whether a stylesheet keeps blocking the first paint.
		 *
		 * @param bool   $blocking Current decision.
		 * @param string $handle   Style handle.
		 * @param string $href     Stylesheet URL.
		 */
		return ! apply_filters( 'smao_keep_style_blocking', false, $handle, $href );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'style_loader_tag', array( self::class, 'readable' ), 5, 4 );
		if ( Rightsize::measuring() ) {
			// The measurement frame must see the page exactly as it is today,
			// and learn which stylesheets could be loaded in the background.
			add_filter( 'style_loader_tag', array( self::class, 'record' ), 99, 4 );
			add_action( 'wp_footer', array( self::class, 'print_recorded' ), 999 );
			return;
		}

		$settings = Settings::get();
		if ( ! $settings['speed_enabled'] ) {
			return;
		}

		if ( $settings['dimensions'] && ! Environment::conflict( 'assets' ) ) {
			ob_start( array( self::class, 'hoist' ) );
		}

		if ( $settings['optimize_fonts'] ) {
			add_filter( 'style_loader_tag', array( self::class, 'font_display' ), 20, 4 );
			add_action( 'wp_head', array( self::class, 'preconnect_fonts' ), 2 );
		}

		if ( $settings['async_css'] && ! Environment::conflict( 'assets' ) ) {
			add_filter( 'style_loader_tag', array( self::class, 'async' ), 22, 4 );
			// Late enough that the style queue exists, early enough to precede
			// wp_print_styles, so we can tell whether inlining is worth it.
			add_action( 'wp_head', array( self::class, 'critical' ), 7 );
		}
	}

	/**
	 * Move stylesheets printed in the body up into the head.
	 *
	 * Page builders print the styles for headers, footers and widgets after
	 * the page has started, often at the very end. On a slow connection the
	 * browser draws the header long before it reaches them, so the header
	 * appears unstyled and then snaps into shape, moving everything below.
	 *
	 * Every stylesheet and style block from the first one in the body up to
	 * the last stylesheet link moves, in its original order, to the end of the
	 * head. They still come after everything that was in the head and before
	 * everything left in the body, so the cascade, and the finished look, are
	 * exactly the same. Only the moment the styles apply changes.
	 *
	 * @param string $html Finished page.
	 * @return string
	 */
	public static function hoist( string $html ): string {
		$head_end = stripos( $html, '</head>' );
		if ( false === $head_end || preg_match( '/<html[^>]*\s(?:amp|\x{26A1})[\s>=]/iu', substr( $html, 0, 2000 ) ) ) {
			return $html;
		}
		$body = substr( $html, $head_end );

		// Blank out regions whose contents must never move, keeping offsets.
		$masked = (string) preg_replace_callback(
			'#<(svg|noscript|template|script|textarea|iframe|xmp)\b.*?</\1\s*>|<!--.*?-->#is',
			static function ( array $match ): string {
				return str_repeat( ' ', strlen( $match[0] ) );
			},
			$body
		);
		if ( ! preg_match_all( '#<link\b[^>]*\brel\s*=\s*["\']?stylesheet\b[^>]*>|<style\b[^>]*>.*?</style\s*>#is', $masked, $found, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$last = -1;
		foreach ( $found[0] as $index => $match ) {
			if ( 0 === stripos( $match[0], '<link' ) ) {
				$last = $index;
			}
		}
		if ( $last < 0 ) {
			return $html; // Only style blocks: nothing waits on the network.
		}

		$moved = array();
		for ( $index = $last; $index >= 0; $index-- ) {
			list( $text, $offset ) = $found[0][ $index ];
			$moved[] = substr( $body, $offset, strlen( $text ) );
			$body    = substr_replace( $body, '', $offset, strlen( $text ) );
		}
		$moved = array_reverse( $moved );

		return substr( $html, 0, $head_end ) . implode( "\n", $moved ) . "\n" . $body;
	}

	/**
	 * Ask Google Fonts to show text immediately rather than hiding it.
	 *
	 * A webfont that blocks text rendering costs First Contentful Paint and is
	 * one of the most common reasons a page paints late.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Handle.
	 * @param string $href   URL.
	 * @param string $media  Media attribute.
	 * @return string
	 */
	public static function font_display( string $tag, string $handle, string $href, string $media ): string {
		if ( ! str_contains( $href, 'fonts.googleapis.com' ) || str_contains( $href, 'display=swap' ) ) {
			return $tag;
		}
		// Builders often ask for display=auto or block, which hides the text
		// until the font arrives; swap shows it straight away in a fallback.
		$updated = str_contains( $href, 'display=' )
			? (string) preg_replace( '/([?&])display=[a-z]*/', '$1display=swap', $href )
			: add_query_arg( 'display', 'swap', $href );
		return str_replace( array( esc_url( $href ), $href ), esc_url( $updated ), $tag );
	}

	/**
	 * Open the connection to font hosts early.
	 *
	 * @return void
	 */
	public static function preconnect_fonts(): void {
		echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	}

	/**
	 * Load a stylesheet without blocking the first paint.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Handle.
	 * @param string $href   URL.
	 * @param string $media  Media attribute.
	 * @return string
	 */
	public static function async( string $tag, string $handle, string $href, string $media ): string {
		$entry = self::entry();
		if ( null === $entry || ! in_array( $handle, $entry['handles'], true ) ) {
			return $tag; // Not verified for this page: keep it blocking.
		}
		if ( 'print' === $media || str_contains( $tag, 'onload=' ) ) {
			return $tag;
		}
		if ( ! self::deferrable( $handle, $href ) ) {
			return $tag;
		}

		// Each stylesheet downloads in the background and is switched on with
		// all the others at once. Switched on one by one as each arrived, a
		// page of 26 stylesheets was styled and laid out again 26 times, and
		// on a slow phone those passes were most of the page's blocking time.
		$onload = 'this.onload=null;this.setAttribute(\'data-smao-l\',\'\');window.smaoCss&&smaoCss()';
		$async  = str_replace(
			"media='" . $media . "'",
			"media='print' data-smao-media='" . $media . "' onload=\"" . $onload . "\"",
			$tag
		);
		if ( $async === $tag ) {
			$async = str_replace( '<link ', '<link media="print" data-smao-media="all" onload="' . $onload . '" ', $tag );
		}
		// Not even fetched until the page has loaded or the visitor acts. The
		// styles written into the page draw the first screen on their own
		// (checked when the page was measured), and a download started
		// earlier competes with the main image on a slow phone.
		$async = (string) preg_replace( '/\shref=(["\'])/', ' data-smao-href=$1', $async, 1 );
		return $async . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * Request a stylesheet from an open public host so it can be read.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Handle.
	 * @param string $href   URL.
	 * @param string $media  Media attribute.
	 * @return string
	 */
	public static function readable( string $tag, string $handle, string $href, string $media ): string {
		$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		if ( ! in_array( $host, self::OPEN_HOSTS, true ) || false !== stripos( $tag, 'crossorigin' ) ) {
			return $tag;
		}
		return (string) preg_replace( '/<link\b/i', '<link crossorigin="anonymous"', $tag, 1 );
	}

	/**
	 * During measurement, note each stylesheet that could load asynchronously.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Handle.
	 * @param string $href   URL.
	 * @param string $media  Media attribute.
	 * @return string Unchanged tag.
	 */
	public static function record( string $tag, string $handle, string $href, string $media ): string {
		if ( 'print' !== $media && self::deferrable( $handle, $href ) ) {
			self::$recorded[] = $handle;
		}
		return $tag;
	}

	/**
	 * Hand the measurement frame the list of candidate stylesheets.
	 *
	 * @return void
	 */
	public static function print_recorded(): void {
		echo '<script type="application/json" id="smao-styles">' . wp_json_encode( array_values( array_unique( self::$recorded ) ) ) . '</script>' . "\n";
	}

	/**
	 * Inline the verified above-the-fold styles for this page.
	 *
	 * @return void
	 */
	public static function critical(): void {
		$entry = self::entry();
		if ( null === $entry ) {
			return;
		}
		/*
		 * Inlining only pays for itself when it lets a blocking stylesheet
		 * load asynchronously instead. A theme that already inlines its CSS,
		 * as block themes do, would just receive the same bytes twice.
		 */
		if ( ! self::will_defer_any( $entry ) ) {
			return;
		}
		echo '<style id="smao-critical">' . wp_strip_all_tags( $entry['css'] ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, tags stripped.
		echo '<script id="smao-prebuild-css">' . self::SWITCH_ON . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed script.
	}

	/**
	 * The verified capture for the page being rendered, if there is one.
	 *
	 * Inlining and loading asynchronously both read this one answer. They
	 * used to be separate checks, and a capture too large to inline still
	 * switched every stylesheet to asynchronous, so the page painted with no
	 * styles at all.
	 *
	 * Only where the page's scripts wait too. A theme script that sizes
	 * sections as the page loads measured them half-styled, and when the full
	 * styles arrived the page jumped (layout shift 1.0 on a live site, against
	 * 0.17 with nothing changed). Held scripts start once every stylesheet is on.
	 *
	 * @return array|null
	 */
	private static function entry(): ?array {
		static $cache = false;
		if ( false !== $cache ) {
			return $cache;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only hashed.
		$path  = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$entry = self::pages()[ self::key( $path ) ] ?? null;
		$cache = self::usable( $entry ) && self::worthwhile( $entry ) && Scripts::holding_all() ? $entry : null;
		return $cache;
	}

	/**
	 * Whether loading this page's stylesheets in the background helps at all.
	 *
	 * The first paint waits for everything blocking in the head. When scripts
	 * that stay blocking outweigh the stylesheets, they set the pace, and
	 * taking the stylesheets out of the way only frees bandwidth for images to
	 * compete with those scripts. Measured on a test page, that made the first
	 * paint half a second later, so such a page keeps its stylesheets as they
	 * are.
	 *
	 * @param array $entry Verified capture.
	 * @return bool
	 */
	private static function worthwhile( array $entry ): bool {
		$styles  = wp_styles();
		$scripts = wp_scripts();
		if ( ! $styles instanceof \WP_Styles || ! $scripts instanceof \WP_Scripts ) {
			return false;
		}

		$css = 0;
		foreach ( $entry['handles'] as $handle ) {
			$item = $styles->registered[ $handle ] ?? null;
			if ( $item && $item->src ) {
				$css += self::bytes( (string) $item->src, 0 );
			}
		}

		$js = 0;
		foreach ( self::head_scripts( $scripts ) as $handle ) {
			$item = $scripts->registered[ $handle ];
			if ( $item->src && Scripts::stays_blocking( $handle, (string) $item->src, (array) $item->extra ) ) {
				// A script we cannot weigh is assumed to be a typical library.
				$js += self::bytes( (string) $item->src, 50000 );
			}
		}

		return $css > $js;
	}

	/**
	 * Head scripts that will be printed, dependencies included.
	 *
	 * Walks the registrations directly instead of calling all_deps(), which
	 * would change what WordPress itself goes on to print.
	 *
	 * @param \WP_Scripts $scripts Script registry.
	 * @return array<int,string>
	 */
	private static function head_scripts( \WP_Scripts $scripts ): array {
		$seen  = array();
		$stack = array_values( (array) $scripts->queue );
		while ( $stack ) {
			$handle = (string) array_pop( $stack );
			if ( isset( $seen[ $handle ] ) || ! isset( $scripts->registered[ $handle ] ) ) {
				continue;
			}
			$seen[ $handle ] = true;
			foreach ( (array) $scripts->registered[ $handle ]->deps as $dependency ) {
				$stack[] = (string) $dependency;
			}
		}
		$head = array();
		foreach ( array_keys( $seen ) as $handle ) {
			if ( 0 === (int) ( $scripts->registered[ $handle ]->extra['group'] ?? 0 ) ) {
				$head[] = $handle;
			}
		}
		return $head;
	}

	/**
	 * Size of a local asset, from its URL.
	 *
	 * @param string $url      Asset URL.
	 * @param int    $fallback Size to assume when the file is not local.
	 * @return int
	 */
	private static function bytes( string $url, int $fallback ): int {
		$url = (string) strtok( $url, '?#' );
		if ( str_starts_with( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$url = site_url( $url );
		}
		$map = array(
			content_url()         => WP_CONTENT_DIR,
			includes_url()        => ABSPATH . WPINC . '/',
			site_url( '/' )       => ABSPATH,
		);
		foreach ( $map as $prefix => $dir ) {
			$prefix = set_url_scheme( $prefix, 'http' );
			$plain  = set_url_scheme( $url, 'http' );
			if ( str_starts_with( $plain, $prefix ) ) {
				$path = wp_normalize_path( rtrim( $dir, '/\\' ) . '/' . ltrim( substr( $plain, strlen( $prefix ) ), '/' ) );
				if ( str_contains( $path, '..' ) ) {
					return $fallback;
				}
				$size = is_readable( $path ) ? filesize( $path ) : false;
				return false === $size ? $fallback : (int) $size;
			}
		}
		return $fallback;
	}

	/**
	 * Whether a stored capture may be acted on.
	 *
	 * @param mixed $entry Stored capture.
	 * @return bool
	 */
	public static function usable( $entry ): bool {
		return is_array( $entry )
			&& 'ready' === ( $entry['status'] ?? '' )
			&& is_string( $entry['css'] ?? null )
			&& '' !== $entry['css']
			&& strlen( $entry['css'] ) <= self::MAX_CRITICAL
			&& ! empty( $entry['handles'] )
			&& is_array( $entry['handles'] );
	}

	/**
	 * Decide what a capture is allowed to do.
	 *
	 * @param int   $bytes   Size of the captured styles.
	 * @param int   $handles Number of stylesheets it covers.
	 * @param float $shift   Worst layout movement seen while verifying.
	 * @return string ready, empty, too_large or shifted.
	 */
	public static function verdict( int $bytes, int $handles, float $shift ): string {
		if ( $bytes < 50 || 0 === $handles ) {
			return 'empty';
		}
		if ( $bytes > self::MAX_CRITICAL ) {
			return 'too_large';
		}
		if ( $shift < 0 || $shift > self::MAX_SHIFT ) {
			return 'shifted';
		}
		return 'ready';
	}

	/**
	 * Storage key for a URL path.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	public static function key( string $path ): string {
		return md5( '/' . trim( rawurldecode( $path ), '/' ) );
	}

	/**
	 * The measured main images for the page being rendered.
	 *
	 * @return array|null Keyed mobile and desktop, or null when unmeasured.
	 */
	public static function heroes(): ?array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only hashed.
		$path  = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$entry = self::pages()[ self::key( $path ) ] ?? null;
		return is_array( $entry ) && ! empty( $entry['heroes'] ) ? (array) $entry['heroes'] : null;
	}

	/**
	 * Change the stored pre-build status of one page.
	 *
	 * @param string $key    Page key.
	 * @param string $status New status.
	 * @param array  $detail What was seen, for the explanation.
	 * @return string The page URL when a verified pre-build was changed, else empty.
	 */
	public static function mark_prebuild( string $key, string $status, array $detail = array() ): string {
		$pages = self::pages();
		if ( 'ready' !== ( $pages[ $key ]['prebuild']['status'] ?? '' ) ) {
			return '';
		}
		$pages[ $key ]['prebuild']['status']   = $status;
		$pages[ $key ]['prebuild']['css']      = '';
		$pages[ $key ]['prebuild']['reported']        = time();
		$pages[ $key ]['prebuild']['reported_detail'] = $detail;
		update_option( self::OPTION, $pages, false );
		return (string) ( $pages[ $key ]['url'] ?? '' );
	}

	/**
	 * A visitor at one checked width saw the page move when its scripts
	 * started: stop holding them at that width only, or, when no checked
	 * width is left, for the whole page.
	 *
	 * @param string $key    Page key.
	 * @param array  $detail Width, change in length and movement seen.
	 * @return string The page URL when something changed, else empty.
	 */
	public static function block_width( string $key, array $detail ): string {
		$pages = self::pages();
		$entry = $pages[ $key ]['prebuild'] ?? null;
		if ( ! is_array( $entry ) || 'ready' !== ( $entry['status'] ?? '' ) ) {
			return '';
		}
		$seen    = absint( $detail['width'] ?? 0 );
		$allowed = Prebuild::allowed_widths( $entry );
		$match   = 0;
		foreach ( $allowed as $width ) {
			if ( abs( $width - $seen ) <= Prebuild::WIDTH_MATCH ) {
				$match = $width;
				break;
			}
		}
		if ( ! $match ) {
			return ''; // That screen was not holding its scripts anyway.
		}
		$entry['blocked']                    = array_values( array_unique( array_merge( array_map( 'absint', (array) ( $entry['blocked'] ?? array() ) ), array( $match ) ) ) );
		$entry['blocked_detail'][ $match ]   = $detail + array( 'at' => time() );
		$entry['reported']                   = time();
		$entry['reported_detail']            = $detail;
		if ( ! Prebuild::allowed_widths( $entry ) ) {
			$entry['status'] = 'visitor_moved';
			$entry['css']    = '';
		}
		$pages[ $key ]['prebuild'] = $entry;
		update_option( self::OPTION, $pages, false );
		return (string) ( $pages[ $key ]['url'] ?? '' );
	}

	/**
	 * Every stored capture.
	 *
	 * @return array<string,array>
	 */
	public static function pages(): array {
		$pages = get_option( self::OPTION, array() );
		return is_array( $pages ) ? $pages : array();
	}

	/**
	 * Store the outcome of capturing and verifying one page.
	 *
	 * @param string $url     Page URL.
	 * @param string $css     Captured above-the-fold styles.
	 * @param array  $handles Stylesheets the capture covers.
	 * @param float  $shift   Worst layout movement seen while verifying.
	 * @param array  $heroes  Largest image in the first screen, per width.
	 * @param array  $prebuild Pre-built layout for when scripts wait, and its check.
	 * @return array{status:string,bytes:int}
	 * @throws \RuntimeException When the URL is not on this site.
	 */
	public static function store_page( string $url, string $css, array $handles, float $shift, array $heroes = array(), array $prebuild = array() ): array {
		$url = esc_url_raw( $url );
		if ( ! $url || ! str_starts_with( $url, home_url() ) ) {
			throw new \RuntimeException( __( 'Only pages on this site can be measured.', 'smart-media-auditor-optimizer' ) );
		}
		$clean = array();
		foreach ( $handles as $handle ) {
			$handle = (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $handle );
			if ( '' !== $handle ) {
				$clean[] = $handle;
			}
		}
		$css    = wp_strip_all_tags( $css );
		$status = self::verdict( strlen( $css ), count( $clean ), $shift );

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$key  = self::key( $path );
		$post = url_to_postid( $url );
		if ( ! $post && '/' === '/' . trim( $path, '/' ) ) {
			$post = (int) get_option( 'page_on_front' );
		}

		$pages = self::pages();
		unset( $pages[ $key ] );
		$pages[ $key ] = array(
			'url'     => $url,
			'post'    => (int) $post,
			'status'  => $status,
			'shift'   => round( max( 0, $shift ), 4 ),
			'bytes'   => strlen( $css ),
			'css'     => 'ready' === $status ? $css : '',
			'handles' => 'ready' === $status ? array_values( array_unique( $clean ) ) : array(),
			'heroes'   => Viewport::clean_heroes( $heroes ),
			'prebuild' => $prebuild ? Prebuild::clean( $prebuild ) : null,
			'at'      => time(),
		);
		if ( count( $pages ) > self::MAX_PAGES ) {
			$pages = array_slice( $pages, -self::MAX_PAGES, null, true );
		}
		update_option( self::OPTION, $pages, false );
		delete_option( self::FORGOT );
		delete_option( self::RECHECK );
		// The stored copy of this page was built before the capture existed.
		Purge::urls( array( $url ) );

		return array(
			'status' => $status,
			'bytes'  => strlen( $css ),
		);
	}

	/**
	 * Post types that hold layout shared by many pages: builder templates,
	 * block templates and reusable parts. Saving one changes pages it is on.
	 */
	private const SHARED_TYPES = array( 'elementor_library', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block', 'et_pb_layout', 'et_template', 'et_header_layout', 'et_footer_layout', 'et_body_layout', 'fl-builder-template', 'ct_template', 'bricks_template', 'elementskit_template', 'ae_global_templates', 'jet-theme-core', 'oceanwp_library', 'astra-advanced-hook', 'vc_grid_item', 'templatera', 'kadence_element', 'gp_elements', 'blocksy_ct', 'header', 'footer' );

	/**
	 * Plugins whose update can change how every page is laid out.
	 */
	private const LAYOUT_PLUGINS = array( 'elementor', 'elementor-pro', 'js_composer', 'divi-builder', 'beaver-builder-lite-version', 'bb-plugin', 'oxygen', 'bricks', 'brizy', 'thrive-visual-editor', 'revslider', 'LayerSlider', 'essential-addons-for-elementor-lite', 'header-footer-elementor', 'ultimate-elementor', 'jet-elements', 'kadence-blocks', 'spectra', 'ultimate-addons-for-gutenberg', 'generateblocks', 'stackable-ultimate-gutenberg-blocks', 'woocommerce' );

	/**
	 * Plain words for a site-wide change, looked up only when it happens.
	 *
	 * @param string $hook Action that fired.
	 * @return string
	 */
	private static function reason_for( string $hook ): string {
		switch ( $hook ) {
			case 'switch_theme':
				return __( 'the theme was changed', 'smart-media-auditor-optimizer' );
			case 'customize_save_after':
				return __( 'the Customizer was saved', 'smart-media-auditor-optimizer' );
			case 'activated_plugin':
				return __( 'a plugin was activated', 'smart-media-auditor-optimizer' );
			case 'deactivated_plugin':
				return __( 'a plugin was deactivated', 'smart-media-auditor-optimizer' );
			case 'wp_update_nav_menu':
				return __( 'a menu was changed', 'smart-media-auditor-optimizer' );
			default:
				return __( 'Elementor regenerated its styles', 'smart-media-auditor-optimizer' );
		}
	}

	/**
	 * Why every capture was last dropped, for the Speed screen.
	 */
	public const FORGOT = 'smao_styles_forgot';

	/**
	 * Set by an update when a measured page is not fully sped up, so the
	 * Speed screen measures again with the new checks. A page whose layout
	 * check failed on phones otherwise kept failing after the fix shipped.
	 */
	public const RECHECK = 'smao_styles_recheck';

	/**
	 * Whether any measured page fell short of the full speed-up.
	 *
	 * @return bool
	 */
	public static function needs_recheck(): bool {
		foreach ( self::pages() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( 'too_large' === ( $entry['status'] ?? '' ) || 'shifted' === ( $entry['status'] ?? '' ) ) {
				return true;
			}
			$prebuild = $entry['prebuild'] ?? null;
			if ( is_array( $prebuild ) && ( 'ready' !== ( $prebuild['status'] ?? '' ) || ! empty( $prebuild['blocked'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drop captures that may no longer describe their page.
	 *
	 * @param int    $post_id Post whose captures to drop, or 0 for all of them.
	 * @param string $reason  Plain words for why, when all are dropped.
	 * @return void
	 */
	public static function forget( int $post_id = 0, string $reason = '' ): void {
		$pages = self::pages();
		if ( ! $pages ) {
			return;
		}
		if ( 0 === $post_id ) {
			delete_option( self::OPTION );
			update_option(
				self::FORGOT,
				array(
					'reason' => '' !== $reason ? $reason : __( 'the site changed', 'smart-media-auditor-optimizer' ),
					'at'     => time(),
				),
				false
			);
			// Stored pages still carry the old inline styles; rebuild them.
			Purge::everything();
			return;
		}
		$kept = array_filter(
			$pages,
			static function ( $entry ) use ( $post_id ): bool {
				return (int) ( $entry['post'] ?? 0 ) !== $post_id;
			}
		);
		if ( count( $kept ) !== count( $pages ) ) {
			update_option( self::OPTION, $kept, false );
		}
	}

	/**
	 * Forget captures whenever the layout of a page may have changed.
	 *
	 * Editing a page changes only that page. A theme, a menu, widgets, the
	 * customizer, a shared template or a page builder's update can change
	 * every page, so all captures go, and the Speed screen measures again the
	 * next time it is opened. Anything else is left alone: a site where every
	 * donation, background save or plugin update dropped the measurements
	 * quietly lost its speed-up again and again.
	 *
	 * @return void
	 */
	public static function watch(): void {
		add_action(
			'save_post',
			static function ( $post_id, $post ): void {
				if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
					return;
				}
				if ( in_array( $post->post_type, array( 'oembed_cache', 'customize_changeset', 'user_request', 'revision', 'nav_menu_item' ), true ) ) {
					return;
				}
				$type = get_post_type_object( $post->post_type );
				if ( $type && $type->public ) {
					self::forget( (int) $post_id );
					return;
				}
				/**
				 * Filter the post types whose changes affect many pages.
				 *
				 * @param array<int,string> $types Post types.
				 */
				$shared = (array) apply_filters( 'smao_shared_layout_types', self::SHARED_TYPES );
				if ( in_array( $post->post_type, $shared, true ) ) {
					/* translators: %s: title of a template or other shared part. */
					self::forget( 0, sprintf( __( '"%s", which several pages share, was edited', 'smart-media-auditor-optimizer' ), get_the_title( $post ) ) );
				}
				// Donations, orders, form entries and other private records do
				// not change how a page looks.
			},
			10,
			2
		);
		foreach ( array( 'switch_theme', 'customize_save_after', 'activated_plugin', 'deactivated_plugin', 'wp_update_nav_menu', 'elementor/core/files/clear_cache' ) as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook ): void {
					self::forget( 0, self::reason_for( $hook ) );
				}
			);
		}
		add_action(
			'upgrader_process_complete',
			static function ( $upgrader, $data = array() ): void {
				$data = is_array( $data ) ? $data : array();
				if ( 'theme' === ( $data['type'] ?? '' ) ) {
					self::forget( 0, __( 'the theme was updated', 'smart-media-auditor-optimizer' ) );
					return;
				}
				if ( 'plugin' !== ( $data['type'] ?? '' ) ) {
					return;
				}
				$slugs = array();
				foreach ( (array) ( $data['plugins'] ?? array() ) as $file ) {
					$slugs[] = dirname( (string) $file );
				}
				if ( isset( $data['plugin'] ) ) {
					$slugs[] = dirname( (string) $data['plugin'] );
				}
				$layout = array_intersect( $slugs, (array) apply_filters( 'smao_layout_plugins', self::LAYOUT_PLUGINS ) );
				if ( $layout ) {
					/* translators: %s: plugin folder name. */
					self::forget( 0, sprintf( __( '%s was updated', 'smart-media-auditor-optimizer' ), reset( $layout ) ) );
				}
			},
			10,
			2
		);
		add_action(
			'updated_option',
			static function ( $option ): void {
				if ( 'sidebars_widgets' === $option ) {
					self::forget( 0, __( 'widgets were changed', 'smart-media-auditor-optimizer' ) );
				}
			}
		);
	}

	/**
	 * Summary of captured pages for the interface.
	 *
	 * @return array{ready:int,total:int,pages:array<int,array>}
	 */
	public static function coverage(): array {
		$pages = array_values( self::pages() );
		$ready = 0;
		foreach ( $pages as $entry ) {
			if ( self::usable( $entry ) ) {
				++$ready;
			}
		}
		return array(
			'ready' => $ready,
			'total' => count( $pages ),
			'pages' => $pages,
		);
	}

	/**
	 * A stable name for the kind of page being rendered.
	 *
	 * Used to label measurement passes.
	 *
	 * @return string
	 */
	public static function template(): string {
		if ( is_front_page() ) {
			return 'front';
		}
		if ( is_home() ) {
			return 'blog';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'shop';
		}
		if ( is_singular( 'post' ) ) {
			return 'post';
		}
		if ( is_page() ) {
			return 'page';
		}
		if ( is_archive() || is_search() ) {
			return 'archive';
		}
		return 'default';
	}

	/**
	 * Split a newline-separated rule list.
	 *
	 * @param string $value Raw setting.
	 * @return array
	 */
	private static function rules( string $value ): array {
		$rules = array();
		foreach ( preg_split( '/[\r\n]+/', $value ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$rules[] = $line;
			}
		}
		return $rules;
	}
}
