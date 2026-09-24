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
		if ( ! str_contains( $href, 'fonts.googleapis.com' ) || str_contains( $href, 'display=' ) ) {
			return $tag;
		}
		$updated = add_query_arg( 'display', 'swap', $href );
		return str_replace( esc_url( $href ), esc_url( $updated ), $tag );
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

		$async = str_replace(
			"media='" . $media . "'",
			"media='print' onload=\"this.media='" . $media . "';this.onload=null\"",
			$tag
		);
		if ( $async === $tag ) {
			$async = str_replace( '<link ', '<link media="print" onload="this.media=\'all\';this.onload=null" ', $tag );
		}
		return $async . '<noscript>' . $tag . '</noscript>';
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
	}

	/**
	 * The verified capture for the page being rendered, if there is one.
	 *
	 * Inlining and loading asynchronously both read this one answer. They
	 * used to be separate checks, and a capture too large to inline still
	 * switched every stylesheet to asynchronous, so the page painted with no
	 * styles at all.
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
		$cache = self::usable( $entry ) && self::worthwhile( $entry ) ? $entry : null;
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
	 * @return array{status:string,bytes:int}
	 * @throws \RuntimeException When the URL is not on this site.
	 */
	public static function store_page( string $url, string $css, array $handles, float $shift ): array {
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
			'at'      => time(),
		);
		if ( count( $pages ) > self::MAX_PAGES ) {
			$pages = array_slice( $pages, -self::MAX_PAGES, null, true );
		}
		update_option( self::OPTION, $pages, false );
		// The stored copy of this page was built before the capture existed.
		Purge::urls( array( $url ) );

		return array(
			'status' => $status,
			'bytes'  => strlen( $css ),
		);
	}

	/**
	 * Drop captures that may no longer describe their page.
	 *
	 * @param int $post_id Post whose captures to drop, or 0 for all of them.
	 * @return void
	 */
	public static function forget( int $post_id = 0 ): void {
		$pages = self::pages();
		if ( ! $pages ) {
			return;
		}
		if ( 0 === $post_id ) {
			delete_option( self::OPTION );
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
	 * Forget captures whenever what is at the top of a page may have changed.
	 *
	 * Editing a page changes only that page. A theme, a plugin, a menu, a
	 * widget, a shared template or a builder's global styles can change every
	 * page, so all captures go. A page without a capture simply keeps its
	 * stylesheets blocking until it is measured again.
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
				self::forget(); // Templates, headers, footers, kits: shared by many pages.
			},
			10,
			2
		);
		foreach ( array( 'switch_theme', 'customize_save_after', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'wp_update_nav_menu', 'elementor/core/files/clear_cache' ) as $hook ) {
			add_action(
				$hook,
				static function (): void {
					self::forget();
				}
			);
		}
		add_action(
			'updated_option',
			static function ( $option ): void {
				if ( 'sidebars_widgets' === $option || str_starts_with( (string) $option, 'theme_mods_' ) ) {
					self::forget();
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
