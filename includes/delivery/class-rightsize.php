<?php
/**
 * Serve images at the size they are actually drawn.
 *
 * WordPress emits sizes="(max-width: 2560px) 100vw, 2560px" for content
 * images, which tells the browser the image fills the window. When the layout
 * actually draws it at 645px the browser still downloads a file sized for the
 * full viewport, often four times the bytes it needs.
 *
 * Once a layout has been measured, this replaces that claim with the truth, so
 * the browser picks a candidate that already exists. No new files are created
 * and no pixels change.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Correct sizes and srcset from measured layout data.
 */
final class Rightsize {

	/**
	 * Cached sizes attributes for this request.
	 *
	 * @var array<int,string>
	 */
	private static array $cache = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}

		// During a measurement run, label every image so the runner can
		// identify it even when the theme omits the wp-image-<id> class.
		if ( self::measuring() ) {
			add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'label' ), 20, 2 );
			add_filter( 'wp_content_img_tag', array( self::class, 'label_content' ), 5, 3 );
			// Tell the measurement frame which template it is looking at, so
			// critical CSS is stored against the right one.
			add_action(
				'wp_head',
				static function (): void {
					echo '<meta name="smao-template" content="' . esc_attr( Styles::template() ) . '">' . "\n";
				},
				1
			);
			return;
		}

		$settings = Settings::get();
		if ( ! $settings['speed_enabled'] || ! $settings['rightsize'] ) {
			return;
		}
		add_filter( 'wp_calculate_image_sizes', array( self::class, 'calculate_sizes' ), 20, 5 );
		add_filter( 'wp_content_img_tag', array( self::class, 'content_img' ), 25, 3 );
	}

	/**
	 * Whether this request is an administrator measurement pass.
	 *
	 * @return bool
	 */
	private static function measuring(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only marker; capability is checked too.
		return isset( $_GET['smao-measure'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * Tag a theme-rendered image with its attachment ID.
	 *
	 * @param array $attr Attributes.
	 * @param mixed $attachment Attachment post.
	 * @return array
	 */
	public static function label( array $attr, $attachment ): array {
		if ( $attachment instanceof \WP_Post ) {
			$attr['data-smao-id'] = (string) $attachment->ID;
		}
		return $attr;
	}

	/**
	 * Tag a content image with its attachment ID.
	 *
	 * @param string $html    Image tag.
	 * @param string $context Filter context.
	 * @param int    $id      Attachment ID.
	 * @return string
	 */
	public static function label_content( string $html, string $context, int $id ): string {
		if ( ! $id || str_contains( $html, 'data-smao-id' ) ) {
			return $html;
		}
		return (string) preg_replace( '/<img\s/', '<img data-smao-id="' . (int) $id . '" ', $html, 1 );
	}

	/**
	 * Replace the theme's sizes claim with the measured one.
	 *
	 * @param string $sizes Calculated sizes.
	 * @param mixed  $size  Requested size.
	 * @param string $src   Image URL.
	 * @param array  $meta  Attachment metadata.
	 * @param int    $id    Attachment ID.
	 * @return string
	 */
	public static function calculate_sizes( $sizes, $size, $src, $meta, $id ) {
		$measured = self::sizes_for( (int) $id );
		return $measured ? $measured : $sizes;
	}

	/**
	 * Correct a content image's sizes attribute, and drop pointless candidates.
	 *
	 * @param string $html    Image tag.
	 * @param string $context Filter context.
	 * @param int    $id      Attachment ID.
	 * @return string
	 */
	public static function content_img( string $html, string $context, int $id ): string {
		if ( ! $id ) {
			return $html;
		}
		$measured = self::sizes_for( $id );
		if ( ! $measured ) {
			return $html;
		}

		if ( preg_match( '/\ssizes\s*=\s*(["\'])(.*?)\1/is', $html ) ) {
			$html = (string) preg_replace(
				'/(\ssizes\s*=\s*)(["\'])(.*?)\2/is',
				'$1$2' . str_replace( '$', '\\$', $measured ) . '$2',
				$html,
				1
			);
		} else {
			$html = (string) preg_replace( '/<img\s/', '<img sizes="' . esc_attr( $measured ) . '" ', $html, 1 );
		}

		return self::prune( $html, $id );
	}

	/**
	 * Remove srcset candidates far larger than the image is ever drawn.
	 *
	 * Keeps the first candidate above what is needed, so a browser on an
	 * unusually wide screen still has something to grow into.
	 *
	 * @param string $html Image tag.
	 * @param int    $id   Attachment ID.
	 * @return string
	 */
	private static function prune( string $html, int $id ): string {
		$needed = Measure::needed( $id );
		if ( ! $needed || ! preg_match( '/\ssrcset\s*=\s*(["\'])(.*?)\1/is', $html, $match ) ) {
			return $html;
		}

		$candidates = array();
		foreach ( explode( ',', $match[2] ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate || ! preg_match( '/\s(\d+)w$/', $candidate, $width ) ) {
				return $html; // Descriptors we do not understand are left alone.
			}
			$candidates[ (int) $width[1] ] = $candidate;
		}
		if ( count( $candidates ) < 2 ) {
			return $html;
		}
		ksort( $candidates );

		$kept     = array();
		$overshot = false;
		foreach ( $candidates as $width => $candidate ) {
			if ( $width <= $needed ) {
				$kept[] = $candidate;
				continue;
			}
			if ( ! $overshot ) {
				$kept[]   = $candidate;
				$overshot = true;
			}
		}
		if ( ! $kept || count( $kept ) === count( $candidates ) ) {
			return $html;
		}

		return (string) preg_replace(
			'/(\ssrcset\s*=\s*)(["\'])(.*?)\2/is',
			'$1$2' . str_replace( '$', '\\$', implode( ', ', $kept ) ) . '$2',
			$html,
			1
		);
	}

	/**
	 * Measured sizes attribute for an attachment, cached per request.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function sizes_for( int $id ): string {
		if ( ! isset( self::$cache[ $id ] ) ) {
			self::$cache[ $id ] = Measure::sizes( $id );
		}
		return self::$cache[ $id ];
	}
}
