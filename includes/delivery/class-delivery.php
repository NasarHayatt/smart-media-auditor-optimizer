<?php
/**
 * Modern-format delivery for both theme-rendered and content images.
 *
 * Opt-in, because it changes emitted markup. The original <img> is always kept
 * as the fallback, so a browser that cannot decode the alternate still renders
 * the page exactly as before.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * WebP and AVIF delivery with an unconditional original-format fallback.
 */
final class Delivery {

	/**
	 * Register delivery hooks when the administrator has opted in.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		$settings = Settings::get();
		if ( ! $settings['speed_enabled'] || ! $settings['delivery'] ) {
			return;
		}
		add_filter( 'wp_get_attachment_image', array( self::class, 'attachment_image' ), 20, 5 );
		add_filter( 'wp_content_img_tag', array( self::class, 'content_img' ), 30, 3 );
	}

	/**
	 * Wrap a theme-rendered attachment image in a picture element.
	 *
	 * @param string $html Image markup.
	 * @param int    $id   Attachment ID.
	 * @param mixed  $size Requested size.
	 * @param bool   $icon Whether a mime icon was substituted.
	 * @param array  $attr Image attributes.
	 * @return string
	 */
	public static function attachment_image( string $html, int $id, $size, bool $icon, array $attr ): string {
		if ( $icon || Media::remote( $id ) ) {
			return $html;
		}
		$image = wp_get_attachment_image_src( $id, $size );
		if ( ! $image ) {
			return $html;
		}
		$sizes = $attr['sizes'] ?? wp_calculate_image_sizes( $size, $image[0], wp_get_attachment_metadata( $id ), $id );
		return self::wrap( $html, $id, $image[0], (string) $sizes );
	}

	/**
	 * Wrap an image inside post content in a picture element.
	 *
	 * This is the path 1.x never covered, and on most sites it is where the
	 * majority of images actually live.
	 *
	 * @param string $html    Image tag.
	 * @param string $context Filter context.
	 * @param int    $id      Attachment ID, zero when unknown.
	 * @return string
	 */
	public static function content_img( string $html, string $context, int $id ): string {
		if ( ! $id || str_contains( $html, '<picture' ) || Media::remote( $id ) ) {
			return $html;
		}
		if ( ! preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $html, $match ) ) {
			return $html;
		}
		$sizes = '';
		if ( preg_match( '/\ssizes\s*=\s*(["\'])(.*?)\1/i', $html, $found ) ) {
			$sizes = $found[2];
		}
		return self::wrap( $html, $id, $match[2], $sizes );
	}

	/**
	 * The alternate-format candidates that would be served for an image.
	 *
	 * Exposed so the preload hint can point at the file the browser will
	 * actually choose. Preloading the original while the picture element
	 * serves an alternate downloads the image twice.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $src Resolved image URL.
	 * @return array{srcset:string,mime:string}|null
	 */
	public static function alternates_for( int $id, string $src ): ?array {
		$settings = Settings::get();
		if ( ! $settings['speed_enabled'] || ! $settings['delivery'] ) {
			return null;
		}
		$alternates = get_post_meta( $id, '_smao_alternates', true );
		if ( ! is_array( $alternates ) || ! $alternates ) {
			return null;
		}
		$uploads = wp_upload_dir();
		$prefix  = trailingslashit( $uploads['baseurl'] );
		if ( ! str_starts_with( $src, $prefix ) ) {
			return null;
		}
		$relative = substr( $src, strlen( $prefix ) );
		if ( empty( $alternates[ $relative ] ) ) {
			return null;
		}
		$selected = $alternates[ $relative ];
		$sources  = self::candidates( $alternates, $selected, $prefix, Measure::needed( $id ) );
		if ( ! $sources ) {
			return null;
		}
		return array(
			'srcset' => implode( ', ', $sources ),
			'mime'   => (string) $selected['mime'],
		);
	}

	/**
	 * Collect same-format, same-aspect candidates that exist on disk.
	 *
	 * @param array  $alternates All recorded alternates.
	 * @param array  $selected   The alternate matching the requested size.
	 * @param string $prefix     Uploads base URL.
	 * @return array<int,string>
	 */
	private static function candidates( array $alternates, array $selected, string $prefix, int $needed = 0 ): array {
		$ratio   = (int) $selected['width'] / max( 1, (int) $selected['height'] );
		$sources = array();
		foreach ( $alternates as $alt ) {
			if ( $alt['mime'] !== $selected['mime'] ) {
				continue;
			}
			if ( abs( (int) $alt['width'] / max( 1, (int) $alt['height'] ) - $ratio ) > 0.01 ) {
				continue;
			}
			try {
				Media::path( $alt['file'] );
			} catch ( \Throwable $e ) {
				continue;
			}
			$sources[ (int) $alt['width'] ] = esc_url( $prefix . $alt['file'] ) . ' ' . (int) $alt['width'] . 'w';
		}
		ksort( $sources );

		/*
		 * Alternates are only generated where they actually beat the original,
		 * so a set can cover the large sizes and skip the small ones. When the
		 * smallest alternate is still far wider than the image is drawn, using
		 * it would send more bytes than the correctly sized original. Decline
		 * rather than make the page slower.
		 */
		if ( $needed > 0 && $sources ) {
			// $needed already allows for a high-density screen, so an alternate
			// wider than that is oversized on every display.
			$smallest = (int) array_key_first( $sources );
			if ( $smallest > $needed ) {
				return array();
			}
		}
		return $sources;
	}

	/**
	 * Build the picture element around an existing image tag.
	 *
	 * @param string $html  Original image markup, used unchanged as the fallback.
	 * @param int    $id    Attachment ID.
	 * @param string $src   Resolved image URL.
	 * @param string $sizes Sizes attribute to reuse on the source.
	 * @return string
	 */
	private static function wrap( string $html, int $id, string $src, string $sizes ): string {
		$alternates = get_post_meta( $id, '_smao_alternates', true );
		if ( ! is_array( $alternates ) || ! $alternates ) {
			return $html;
		}
		$uploads = wp_upload_dir();
		$prefix  = trailingslashit( $uploads['baseurl'] );
		if ( ! str_starts_with( $src, $prefix ) ) {
			return $html;
		}
		$relative = substr( $src, strlen( $prefix ) );
		if ( empty( $alternates[ $relative ] ) ) {
			return $html;
		}
		$selected = $alternates[ $relative ];
		$sources  = self::candidates( $alternates, $selected, $prefix, Measure::needed( $id ) );
		if ( ! $sources ) {
			return $html;
		}
		return sprintf(
			'<picture><source type="%s" srcset="%s" sizes="%s">%s</picture>',
			esc_attr( $selected['mime'] ),
			esc_attr( implode( ', ', $sources ) ),
			esc_attr( $sizes ?: '100vw' ),
			$html
		);
	}
}
