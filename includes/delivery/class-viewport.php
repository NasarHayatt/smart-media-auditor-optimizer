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
	 * Whether this page's main image comes from a measurement, not a guess.
	 *
	 * @var bool
	 */
	private static bool $measured = false;

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
		if ( ! Rightsize::measuring() ) {
			add_action( 'template_redirect', array( self::class, 'start' ), 5 );
		}
	}

	/**
	 * Correct every image in the finished page, not only post content.
	 *
	 * WordPress only runs its image filters for post content and for images
	 * built on this request. Header logos, page-builder widgets and anything
	 * a builder serves from its own element cache never pass through them,
	 * so a 170px logo kept being sent at 982px with high priority, ahead of
	 * the page's main image. Working on the finished page covers every
	 * image, whoever printed it.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( is_feed() || is_embed() ) {
			return;
		}
		ob_start( array( self::class, 'page' ) );
	}

	/**
	 * Output buffer callback: correct each image tag in the page body.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function page( string $html ): string {
		$start = stripos( $html, '<body' );
		if ( false === $start || ! str_contains( $html, '</html>' ) ) {
			return $html;
		}
		$body = substr( $html, $start );
		// Markup inside scripts and templates is not part of the page yet.
		$masked = (string) preg_replace_callback(
			'#<(script|template|textarea|xmp)\b.*?</\1\s*>|<!--.*?-->#is',
			static function ( array $match ): string {
				return str_repeat( ' ', strlen( $match[0] ) );
			},
			$body
		);
		if ( ! preg_match_all( '#<img\b[^>]*>#i', $masked, $found, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$settings = Settings::get();
		$measured = null !== Styles::heroes();
		$out      = '';
		$last     = 0;
		foreach ( $found[0] as $match ) {
			$offset = (int) $match[1];
			$tag    = substr( $body, $offset, strlen( $match[0] ) );
			$out   .= substr( $body, $last, $offset - $last ) . self::correct( $tag, $settings, $measured );
			$last   = $offset + strlen( $tag );
		}
		return substr( $html, 0, $start ) . $out . substr( $body, $last );
	}

	/**
	 * Apply every image correction to one tag. Each step leaves an already
	 * corrected tag as it is, so content images are not changed twice.
	 *
	 * @param string $tag      Image tag.
	 * @param array  $settings Settings.
	 * @param bool   $measured Whether this page's first screen was measured.
	 * @return string
	 */
	private static function correct( string $tag, array $settings, bool $measured ): string {
		$id = self::known_id( $tag );
		if ( $settings['dimensions'] ) {
			$tag = self::dimensions( $tag, $id );
		}
		if ( $settings['rightsize'] && $id && preg_match( '/\ssrcset\s*=/i', $tag ) ) {
			$tag = Rightsize::content_img( $tag, '', $id );
		}
		// Loading order is only changed where the page was measured: a guess
		// about which image is the main one is not good enough for every image.
		if ( $settings['lazy_correct'] && $measured ) {
			$tag = self::loading( $tag, $id );
		}
		return $tag;
	}

	/**
	 * The attachment behind a tag, looking it up by address only when the
	 * answer can change something.
	 *
	 * @param string $tag Image tag.
	 * @return int
	 */
	private static function known_id( string $tag ): int {
		if ( preg_match( '/\bwp-image-(\d+)/', $tag, $match ) || preg_match( '/\sdata-smao-id\s*=\s*["\']?(\d+)/i', $tag, $match ) ) {
			return (int) $match[1];
		}
		$src     = self::attribute( $tag, 'src' );
		$uploads = wp_upload_dir( null, false );
		$base    = (string) preg_replace( '#^https?:#i', '', (string) $uploads['baseurl'] );
		if ( '' === $src || '' === $base || ! str_contains( $src, $base . '/' ) ) {
			return 0;
		}
		if ( preg_match( '/\ssrcset\s*=/i', $tag ) || ! preg_match( '/\swidth\s*=/i', $tag ) ) {
			return self::resolve( $tag );
		}
		return 0;
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
		/*
		 * Falling back to "the first image on the page" is only safe when that
		 * image could plausibly be the largest one. Tracking pixels, spacers
		 * and the placeholders used by JavaScript lazy loaders all appear first
		 * and would otherwise be given high priority.
		 */
		$is_lcp = ( $id && $id === $lcp )
			|| ( ! $lcp && ! self::$measured && 1 === self::$seen && self::plausible_lcp( $html ) );
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
		if ( self::above_fold( $html, $id ) ) {
			// Drawn in the first screen when measured: load it straight away.
			$html = (string) preg_replace( '/\sloading\s*=\s*(["\'])lazy\1/i', '', $html );
		} elseif ( ! preg_match( '/\sloading\s*=/i', $html ) ) {
			$html = preg_replace( '/<img\s/', '<img loading="lazy" ', $html, 1 );
		}
		// Never let a non-LCP image claim high priority.
		return preg_replace( '/\sfetchpriority\s*=\s*(["\'])high\1/i', '', $html );
	}

	/**
	 * Whether the measurement saw this image in the first screen.
	 *
	 * Lazy loading an image that is visible on arrival delays it until after
	 * layout, behind everything else. 2.3.4 did exactly that to the main
	 * image of a measured page and the largest paint went from 7s to 20s.
	 *
	 * @param string $html Image tag.
	 * @param int    $id   Attachment ID, zero when unknown.
	 * @return bool
	 */
	private static function above_fold( string $html, int $id ): bool {
		$heroes = Styles::heroes();
		if ( null === $heroes ) {
			return false;
		}
		$name = strtolower( wp_basename( (string) wp_parse_url( self::attribute( $html, 'src' ), PHP_URL_PATH ) ) );
		foreach ( $heroes as $hero ) {
			foreach ( (array) ( $hero['above'] ?? array() ) as $image ) {
				if ( ( $id && (int) $image['id'] === $id ) || ( '' !== $name && $image['name'] === $name ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether an image tag could credibly be the largest one on the page.
	 *
	 * @param string $html Image tag.
	 * @return bool
	 */
	private static function plausible_lcp( string $html ): bool {
		$src = self::attribute( $html, 'src' );

		// A placeholder left by a JavaScript lazy loader is not the real image.
		if ( '' === $src || str_starts_with( $src, 'data:' ) ) {
			return false;
		}
		// Hidden elements never paint.
		if ( preg_match( '/\sstyle\s*=\s*(["\'])[^"\']*display\s*:\s*none/i', $html ) ) {
			return false;
		}
		// Tracking pixels and spacers.
		$width  = (int) self::attribute( $html, 'width' );
		$height = (int) self::attribute( $html, 'height' );
		if ( ( $width > 0 && $width < 150 ) || ( $height > 0 && $height < 150 ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Emit a preload hint for the largest contentful image.
	 *
	 * @return void
	 */
	public static function preload(): void {
		$heroes = Styles::heroes();
		if ( null !== $heroes ) {
			self::preload_measured( $heroes );
			return;
		}
		self::preload_image( self::lcp_id(), '' );
	}

	/**
	 * Keep only well-formed measured main images, by device.
	 *
	 * Phones are judged at 390px and desktops at 1280px, the widths closest to
	 * the ones PageSpeed tests. An image covering under a sixth of the first
	 * screen is not what the browser reports as the largest paint, so it is
	 * not preloaded.
	 *
	 * @param array $raw Heroes keyed by measured width.
	 * @return array
	 */
	public static function clean_heroes( array $raw ): array {
		$clean = array();
		foreach ( array( 'mobile' => 390, 'desktop' => 1280 ) as $device => $width ) {
			$hero = $raw[ $width ] ?? $raw[ (string) $width ] ?? null;
			if ( ! is_array( $hero ) ) {
				continue;
			}
			$url   = esc_url_raw( (string) ( $hero['url'] ?? '' ), array( 'http', 'https' ) );
			$kind  = in_array( $hero['kind'] ?? '', array( 'img', 'bg' ), true ) ? $hero['kind'] : '';
			$share = (float) ( $hero['share'] ?? 0 );

			// Images drawn in the first screen, whatever their size.
			$above = array();
			foreach ( array_slice( (array) ( $hero['above'] ?? array() ), 0, 40 ) as $image ) {
				$name = is_array( $image ) ? strtolower( wp_basename( (string) wp_parse_url( (string) ( $image['url'] ?? '' ), PHP_URL_PATH ) ) ) : '';
				$id   = is_array( $image ) ? absint( $image['id'] ?? 0 ) : 0;
				if ( $id || '' !== $name ) {
					$above[] = array(
						'id'   => $id,
						'name' => $name,
					);
				}
			}
			$top = null;
			if ( is_array( $hero['top'] ?? null ) && absint( $hero['top']['id'] ?? 0 ) && (float) ( $hero['top']['share'] ?? 0 ) >= 0.05 ) {
				$top = array(
					'id'    => absint( $hero['top']['id'] ),
					'share' => min( 1.0, (float) $hero['top']['share'] ),
				);
			}

			$entry = array(
				'above' => $above,
				'top'   => $top,
			);
			if ( '' !== $url && '' !== $kind && $share >= 0.16 ) {
				$entry += array(
					'kind'  => $kind,
					'url'   => $url,
					'id'    => absint( $hero['id'] ?? 0 ),
					'share' => min( 1.0, $share ),
				);
			}
			if ( $above || $top || isset( $entry['kind'] ) ) {
				$clean[ $device ] = $entry;
			}
		}
		return $clean;
	}

	/**
	 * Preload the images the measurement found, one per device.
	 *
	 * @param array $heroes Clean heroes keyed mobile and desktop.
	 * @return void
	 */
	private static function preload_measured( array $heroes ): void {
		$media = array(
			'mobile'  => '(max-width: 480px)',
			'desktop' => '(min-width: 481px)',
		);
		// The largest <img> in the first screen always loads first, even when
		// the biggest visual is a CSS background: the browser may well report
		// the image as the largest paint, and it must never wait behind it.
		$tops = array();
		foreach ( $heroes as $device => $hero ) {
			$id = (int) ( $hero['top']['id'] ?? 0 );
			if ( ! $id && 'img' === ( $hero['kind'] ?? '' ) ) {
				$id = (int) ( $hero['id'] ?? 0 );
			}
			if ( $id ) {
				$tops[ $device ] = $id;
			}
		}
		if ( 2 === count( $tops ) && $tops['mobile'] === $tops['desktop'] ) {
			self::preload_image( $tops['mobile'], '' );
		} else {
			foreach ( $tops as $device => $id ) {
				self::preload_image( $id, $media[ $device ] );
			}
		}

		$backgrounds = array();
		foreach ( $heroes as $device => $hero ) {
			if ( 'bg' === ( $hero['kind'] ?? '' ) ) {
				$backgrounds[ $device ] = $hero;
			}
		}
		if ( 2 === count( $backgrounds ) && $backgrounds['mobile']['url'] === $backgrounds['desktop']['url'] ) {
			$media       = array( 'mobile' => '' );
			$backgrounds = array( 'mobile' => $backgrounds['mobile'] );
		}
		foreach ( $backgrounds as $device => $hero ) {
			// A background is fetched by the exact URL in the stylesheet.
			printf(
				'<link rel="preload" as="image" href="%s" fetchpriority="high"%s>%s',
				esc_url( $hero['url'] ),
				'' !== ( $media[ $device ] ?? '' ) ? ' media="' . esc_attr( $media[ $device ] ) . '"' : '',
				"\n"
			);
		}
	}

	/**
	 * Preload one attachment, with its responsive candidates.
	 *
	 * @param int    $id    Attachment ID.
	 * @param string $media Media query limiting the preload, or empty.
	 * @return void
	 */
	private static function preload_image( int $id, string $media ): void {
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
			'<link rel="preload" as="image" href="%s"%s%s fetchpriority="high"%s>%s',
			esc_url( $url ),
			$srcset && $sizes
				? ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $sizes ) . '"'
				: '',
			$type,
			'' !== $media ? ' media="' . esc_attr( $media ) . '"' : '',
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
		$heroes    = Styles::heroes();
		if ( null !== $heroes ) {
			self::$measured = true;
			foreach ( array( 'mobile', 'desktop' ) as $device ) {
				$hero = $heroes[ $device ] ?? array();
				$id   = (int) ( $hero['top']['id'] ?? 0 );
				if ( ! $id && 'img' === ( $hero['kind'] ?? '' ) ) {
					$id = (int) ( $hero['id'] ?? 0 );
				}
				if ( $id ) {
					self::$lcp = $id;
					break;
				}
			}
			return self::$lcp;
		}
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
		self::$measured = false;
	}
}
