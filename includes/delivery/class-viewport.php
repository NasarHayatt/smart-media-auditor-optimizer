<?php
/**
 * Front-end viewport corrections that never change how a page looks.
 *
 * Everything in this class is safe to enable by default: it adds intrinsic
 * dimensions, prioritises the largest contentful image and corrects lazy
 * loading. None of it alters layout, pixels or markup semantics.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Layout-stability and loading-priority corrections.
 */
final class Viewport {

	/**
	 * Resolved attachment IDs for content image URLs, per request.
	 *
	 * @var array<string,int>
	 */
	private static array $resolved = array();

	/**
	 * Cached largest-contentful-paint candidate for the current view.
	 *
	 * @var int|null
	 */
	private static ?int $lcp = null;

	/**
	 * Number of content images emitted so far in this request.
	 *
	 * @var int
	 */
	private static int $seen = 0;

	/**
	 * Register front-end hooks when speed corrections are enabled.
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
		add_filter( 'wp_content_img_tag', array( self::class, 'content_img' ), 20, 3 );
		if ( $settings['lcp_preload'] ) {
			add_action( 'wp_head', array( self::class, 'preload' ), 2 );
		}
	}

	/**
	 * Correct a single content image tag.
	 *
	 * Runs after core has had its turn, so it can fill gaps core leaves when an
	 * image carries no wp-image-<id> class, which is the common case for
	 * page-builder and hand-written markup.
	 *
	 * @param string $html    Image tag.
	 * @param string $context Filter context.
	 * @param int    $id      Attachment ID resolved by core, zero when unknown.
	 * @return string
	 */
	public static function content_img( string $html, string $context, int $id ): string {
		if ( ! str_contains( $html, '<img' ) ) {
			return $html;
		}
		++self::$seen;
		$settings = Settings::get();
		if ( ! $id ) {
			$id = self::resolve( $html );
		}
		if ( $settings['dimensions'] ) {
			$html = self::dimensions( $html, $id );
		}
		if ( $settings['lazy_correct'] ) {
			$html = self::loading( $html, $id );
		}
		return $html;
	}

	/**
	 * Add intrinsic width and height when the markup omits them.
	 *
	 * Missing intrinsic dimensions are the single most common cause of
	 * cumulative layout shift on image-heavy pages.
	 *
	 * @param string $html Image tag.
	 * @param int    $id   Attachment ID, zero when unknown.
	 * @return string
	 */
	private static function dimensions( string $html, int $id ): string {
		if ( ! $id || preg_match( '/\swidth\s*=/i', $html ) || preg_match( '/\sheight\s*=/i', $html ) ) {
			return $html;
		}
		$src = self::attribute( $html, 'src' );
		if ( ! $src ) {
			return $html;
		}
		$size = self::size_for( $id, $src );
		if ( ! $size ) {
			return $html;
		}
		return preg_replace(
			'/<img\s/',
			sprintf( '<img width="%d" height="%d" ', $size[0], $size[1] ),
			$html,
			1
		);
	}

	/**
	 * Give the largest contentful image priority and lazy-load the rest.
	 *
	 * @param string $html Image tag.
	 * @param int    $id   Attachment ID, zero when unknown.
	 * @return string
	 */
	private static function loading( string $html, int $id ): string {
		$lcp = self::lcp_id();
		$is_lcp = ( $id && $id === $lcp ) || ( ! $lcp && 1 === self::$seen );
		if ( $is_lcp ) {
			$html = preg_replace( '/\sloading\s*=\s*(["\'])lazy\1/i', '', $html );
			if ( ! preg_match( '/\sfetchpriority\s*=/i', $html ) ) {
				$html = preg_replace( '/<img\s/', '<img fetchpriority="high" ', $html, 1 );
			}
			if ( ! preg_match( '/\sdecoding\s*=/i', $html ) ) {
				$html = preg_replace( '/<img\s/', '<img decoding="sync" ', $html, 1 );
			}
			return $html;
		}
		if ( ! preg_match( '/\sloading\s*=/i', $html ) ) {
			$html = preg_replace( '/<img\s/', '<img loading="lazy" ', $html, 1 );
		}
		// Never let a non-LCP image claim high priority.
		return preg_replace( '/\sfetchpriority\s*=\s*(["\'])high\1/i', '', $html );
	}

	/**
	 * Emit a preload hint for the largest contentful image.
	 *
	 * @return void
	 */
	public static function preload(): void {
		$id = self::lcp_id();
		if ( ! $id ) {
			return;
		}
		$url = wp_get_attachment_image_url( $id, 'full' );
		if ( ! $url ) {
			return;
		}
		$srcset = wp_get_attachment_image_srcset( $id, 'full' );
		$sizes  = wp_get_attachment_image_sizes( $id, 'full' );
		$type   = '';

		/*
		 * When an alternate format is being served, preload that instead of the
		 * original. Preloading the original while the picture element serves a
		 * WebP makes the browser download the same image twice.
		 */
		$alternate = Delivery::alternates_for( $id, $url );
		if ( $alternate ) {
			$srcset = $alternate['srcset'];
			$type   = ' type="' . esc_attr( $alternate['mime'] ) . '"';
			if ( ! $sizes ) {
				$sizes = '100vw';
			}
			// A browser that ignores imagesrcset falls back to href, so point
			// that at the alternate as well rather than the original format.
			$fallback = self::nearest( $alternate['srcset'], Measure::needed( $id ) );
			if ( $fallback ) {
				$url = $fallback;
			}
		}

		printf(
			'<link rel="preload" as="image" href="%s"%s%s fetchpriority="high">%s',
			esc_url( $url ),
			$srcset && $sizes
				? ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $sizes ) . '"'
				: '',
			$type,
			"\n"
		);
	}

	/**
	 * Pick the srcset candidate closest to a required width.
	 *
	 * @param string $srcset   Candidate list.
	 * @param int    $required Required width, zero when unmeasured.
	 * @return string Empty when no candidate can be read.
	 */
	private static function nearest( string $srcset, int $required ): string {
		$best      = '';
		$bestWidth = 0;
		foreach ( explode( ',', $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ) );
			if ( empty( $parts[0] ) || empty( $parts[1] ) ) {
				continue;
			}
			$width = (int) rtrim( $parts[1], 'w' );
			if ( ! $width ) {
				continue;
			}
			// Smallest candidate that still covers the requirement.
			if ( $required > 0 && $width >= $required && ( 0 === $bestWidth || $width < $bestWidth ) ) {
				$best      = $parts[0];
				$bestWidth = $width;
			}
			// With no measurement, fall back to the largest available.
			if ( 0 === $required && $width > $bestWidth ) {
				$best      = $parts[0];
				$bestWidth = $width;
			}
		}
		return $best;
	}

	/**
	 * Determine the largest contentful image candidate for the current view.
	 *
	 * Featured image first, then the first sufficiently large image in the
	 * content. An administrator override always wins.
	 *
	 * @return int Attachment ID, or zero when no candidate exists.
	 */
	public static function lcp_id(): int {
		if ( null !== self::$lcp ) {
			return self::$lcp;
		}
		self::$lcp = 0;
		$override  = (int) Settings::get()['preload_id'];
		if ( $override && is_front_page() ) {
			self::$lcp = $override;
			return self::$lcp;
		}
		if ( ! is_singular() && ! is_front_page() ) {
			return self::$lcp;
		}
		$post = get_post( is_front_page() ? (int) get_option( 'page_on_front' ) : null );
		if ( ! $post instanceof \WP_Post ) {
			return self::$lcp;
		}
		$thumbnail = (int) get_post_thumbnail_id( $post );
		if ( $thumbnail ) {
			self::$lcp = $thumbnail;
			return self::$lcp;
		}
		self::$lcp = self::first_large_image( (string) $post->post_content );
		return self::$lcp;
	}

	/**
	 * Find the first content image wide enough to plausibly be the LCP element.
	 *
	 * @param string $content Post content.
	 * @return int
	 */
	private static function first_large_image( string $content ): int {
		if ( ! preg_match_all( '/<img\s[^>]+>/i', $content, $matches ) ) {
			return 0;
		}
		foreach ( $matches[0] as $tag ) {
			$id = self::resolve( $tag );
			if ( ! $id ) {
				continue;
			}
			$meta = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && (int) ( $meta['width'] ?? 0 ) >= 480 ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Resolve the attachment behind an image tag, by class then by URL.
	 *
	 * @param string $html Image tag.
	 * @return int
	 */
	private static function resolve( string $html ): int {
		if ( preg_match( '/wp-image-(\d+)/', $html, $match ) ) {
			return (int) $match[1];
		}
		$src = self::attribute( $html, 'src' );
		if ( ! $src ) {
			return 0;
		}
		$key = md5( $src );
		if ( isset( self::$resolved[ $key ] ) ) {
			return self::$resolved[ $key ];
		}
		$cached = wp_cache_get( $key, 'smao_url_id' );
		if ( false !== $cached ) {
			self::$resolved[ $key ] = (int) $cached;
			return self::$resolved[ $key ];
		}
		// Strip any size suffix so resized variants resolve to their parent.
		$candidate = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $src );
		$id        = (int) attachment_url_to_postid( $candidate ?: $src );
		wp_cache_set( $key, $id, 'smao_url_id', HOUR_IN_SECONDS );
		self::$resolved[ $key ] = $id;
		return $id;
	}

	/**
	 * Read an attribute value from an image tag.
	 *
	 * @param string $html Image tag.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private static function attribute( string $html, string $name ): string {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i', $html, $match ) ) {
			return $match[2];
		}
		return '';
	}

	/**
	 * Resolve the intrinsic size of the variant actually referenced by a URL.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $src Image URL.
	 * @return array{0:int,1:int}|null
	 */
	private static function size_for( int $id, string $src ): ?array {
		if ( preg_match( '/-(\d+)x(\d+)\.[a-z0-9]+$/i', $src, $match ) ) {
			return array( (int) $match[1], (int) $match[2] );
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return null;
		}
		return array( (int) $meta['width'], (int) $meta['height'] );
	}

	/**
	 * Reset per-request state. Used by the test suite.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$lcp      = null;
		self::$seen     = 0;
		self::$resolved = array();
	}
}
