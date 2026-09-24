<?php
/**
 * CSS delivery and font loading.
 *
 * A stylesheet in the head blocks the first paint until it has downloaded and
 * parsed. Most of a WordPress page's CSS is not needed for what appears in the
 * first screenful, so loading it asynchronously lets the page paint sooner,
 * which moves First Contentful Paint, Speed Index and Largest Contentful Paint.
 *
 * Doing that without care causes a flash of unstyled content, so async loading
 * only happens once critical CSS exists for the template being rendered. Until
 * then the stylesheets are left blocking, which is correct rather than fast.
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
	private const MAX_CRITICAL = 102400;

	/**
	 * Whether at least one enqueued stylesheet would be made asynchronous.
	 *
	 * @return bool
	 */
	private static function will_defer_any(): bool {
		$styles = wp_styles();
		if ( ! $styles instanceof \WP_Styles ) {
			return false;
		}
		$exclusions = self::rules( Settings::get()['style_exclusions'] );
		foreach ( (array) $styles->queue as $handle ) {
			if ( in_array( $handle, self::NEVER, true ) ) {
				continue;
			}
			$item = $styles->registered[ $handle ] ?? null;
			if ( ! $item || ! $item->src ) {
				continue; // Inline-only styles are not render-blocking links.
			}
			$skip = false;
			foreach ( $exclusions as $needle ) {
				if ( $handle === $needle || str_contains( (string) $item->src, $needle ) ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				return true;
			}
		}
		return false;
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
		if ( in_array( $handle, self::NEVER, true ) || ! self::has_critical() ) {
			return $tag;
		}
		if ( 'print' === $media || str_contains( $tag, 'onload=' ) ) {
			return $tag;
		}
		foreach ( self::rules( Settings::get()['style_exclusions'] ) as $needle ) {
			if ( $handle === $needle || str_contains( $href, $needle ) ) {
				return $tag;
			}
		}
		/**
		 * Filter whether a stylesheet keeps blocking the first paint.
		 *
		 * @param bool   $blocking Current decision.
		 * @param string $handle   Style handle.
		 * @param string $href     Stylesheet URL.
		 */
		if ( apply_filters( 'smao_keep_style_blocking', false, $handle, $href ) ) {
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
	 * Inline the critical CSS recorded for this template.
	 *
	 * @return void
	 */
	public static function critical(): void {
		$css = self::critical_css();
		if ( '' === $css ) {
			return;
		}
		/*
		 * Inlining only pays for itself when it lets a blocking stylesheet
		 * load asynchronously instead. A theme that already inlines its CSS,
		 * as block themes do, would just receive the same bytes twice.
		 */
		if ( ! self::will_defer_any() ) {
			return;
		}
		// Past a certain size this is no longer "critical" CSS, it is the whole
		// stylesheet, and inlining it costs more than it saves.
		if ( strlen( $css ) > self::MAX_CRITICAL ) {
			return;
		}
		echo '<style id="smao-critical">' . wp_strip_all_tags( $css ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, tags stripped.
	}

	/**
	 * Whether critical CSS exists for the current template.
	 *
	 * @return bool
	 */
	private static function has_critical(): bool {
		return '' !== self::critical_css();
	}

	/**
	 * Read stored critical CSS for the current template.
	 *
	 * @return string
	 */
	private static function critical_css(): string {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$stored = (array) get_option( 'smao_critical_css', array() );
		$cache  = (string) ( $stored[ self::template() ] ?? $stored['default'] ?? '' );
		return $cache;
	}

	/**
	 * A stable name for the kind of page being rendered.
	 *
	 * Critical CSS is per template, not per URL, because the above-the-fold
	 * styling of every blog post is the same.
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
	 * Store critical CSS for a template.
	 *
	 * @param string $template Template name.
	 * @param string $css      Critical CSS.
	 * @return void
	 */
	public static function store_critical( string $template, string $css ): void {
		$stored              = (array) get_option( 'smao_critical_css', array() );
		$stored[ $template ] = substr( $css, 0, 200000 );
		update_option( 'smao_critical_css', $stored, false );
	}

	/**
	 * Which templates already have critical CSS.
	 *
	 * @return array
	 */
	public static function critical_coverage(): array {
		return array_keys( (array) get_option( 'smao_critical_css', array() ) );
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
