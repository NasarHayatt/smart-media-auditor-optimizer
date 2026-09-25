<?php
/**
 * WebP delivery for every image on the page.
 *
 * A WebP copy of each JPEG and PNG is made in the background; originals are
 * never changed. When a page is sent, every uploads address in it that has a
 * copy is switched to the copy: image tags, srcsets, slider and builder data
 * attributes, inline styles and inline JSON alike. Only browsers that say they
 * accept WebP get the switched page, and the page cache keeps a separate copy
 * for those that do not, so nobody receives an image they cannot show.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * WebP delivery.
 */
final class Delivery {

	/**
	 * Option caching the file-to-copy map.
	 */
	private const MAP = 'smao_webp_map';

	/**
	 * Whether WebP delivery is switched on and nothing else handles images.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		$settings = Settings::get();
		return (bool) $settings['speed_enabled'] && (bool) $settings['delivery'] && '' === Environment::conflict( 'images' );
	}

	/**
	 * Register delivery hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'backfill' ) );
		if ( is_admin() || ! self::active() ) {
			return;
		}
		add_action( 'template_redirect', array( self::class, 'start' ), 0 );
	}

	/**
	 * Begin rewriting this page, for browsers that accept WebP.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( ! headers_sent() ) {
			header( 'Vary: Accept', false );
		}
		if ( ! function_exists( 'smao_cache_wants_webp' ) ) {
			require_once dirname( __DIR__ ) . '/cache/cache-rules.php';
		}
		if ( ! smao_cache_wants_webp( $_SERVER ) || is_feed() || is_embed() ) {
			return;
		}
		ob_start( array( self::class, 'buffer' ) );
	}

	/**
	 * Output buffer callback.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function buffer( string $html ): string {
		if ( ! str_contains( $html, '</html>' ) ) {
			return $html;
		}
		$map = self::map();
		if ( ! $map ) {
			return $html;
		}
		$uploads = wp_upload_dir();
		return self::rewrite( $html, $map, (string) $uploads['baseurl'] );
	}

	/**
	 * Switch every uploads address that has a WebP copy to the copy.
	 *
	 * Preload hints are the exception. A preload only helps if it asks for
	 * the exact file the page will use, and an image named in an external
	 * stylesheet keeps its original address there. So a preload is switched
	 * only when the same image was switched somewhere else in the page.
	 *
	 * @param string $html    Page.
	 * @param array  $map     Uploads-relative path of original => of copy.
	 * @param string $baseurl Uploads base URL.
	 * @return string
	 */
	public static function rewrite( string $html, array $map, string $baseurl ): string {
		$host = (string) preg_replace( '#^https?:#i', '', rtrim( $baseurl, '/' ) );
		if ( '' === $host || ! $map ) {
			return $html;
		}
		$plain   = preg_quote( $host, '#' );
		$escaped = preg_quote( str_replace( '/', '\\/', $host ), '#' );
		$pattern = '#((?:https?:)?' . $plain . '/)([A-Za-z0-9_\-.%/]+?\.(?:jpe?g|png))(?=[\s"\'),?\#&<>]|$)'
			. '|((?:https?:)?' . $escaped . '\\\\/)((?:[A-Za-z0-9_\-.%]|\\\\/)+?\.(?:jpe?g|png))(?=[\s"\'),?\#&<>\\\\]|$)#i';

		$switched = array();
		$swap     = static function ( array $match, bool $only_known ) use ( $map, &$switched ): string {
			if ( '' !== ( $match[1] ?? '' ) ) {
				$relative = $match[2];
				if ( isset( $map[ $relative ] ) && ( ! $only_known || isset( $switched[ $relative ] ) ) ) {
					$switched[ $relative ] = true;
					return $match[1] . $map[ $relative ];
				}
				return $match[0];
			}
			$relative = str_replace( '\\/', '/', $match[4] );
			if ( isset( $map[ $relative ] ) && ( ! $only_known || isset( $switched[ $relative ] ) ) ) {
				$switched[ $relative ] = true;
				return $match[3] . str_replace( '/', '\\/', $map[ $relative ] );
			}
			return $match[0];
		};

		$parts = preg_split( '#(<link\b[^>]*>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}
		foreach ( $parts as $index => $part ) {
			if ( 1 === $index % 2 ) {
				continue; // Link tags: second pass.
			}
			$parts[ $index ] = (string) preg_replace_callback(
				$pattern,
				static function ( array $match ) use ( $swap ): string {
					return $swap( $match, false );
				},
				$part
			);
		}
		foreach ( $parts as $index => $part ) {
			if ( 0 === $index % 2 ) {
				continue;
			}
			$parts[ $index ] = (string) preg_replace_callback(
				$pattern,
				static function ( array $match ) use ( $swap ): string {
					return $swap( $match, true );
				},
				$part
			);
		}
		return implode( '', $parts );
	}

	/**
	 * Every original file with a WebP copy, as uploads-relative paths.
	 *
	 * @return array<string,string>
	 */
	public static function map(): array {
		$cached = get_option( self::MAP, null );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$map  = array();
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s", '_smao_alternates' ) );
		foreach ( (array) $rows as $row ) {
			$alternates = maybe_unserialize( $row );
			if ( ! is_array( $alternates ) ) {
				continue;
			}
			foreach ( $alternates as $relative => $alternate ) {
				if ( is_array( $alternate ) && ! empty( $alternate['file'] ) && 'image/webp' === ( $alternate['mime'] ?? '' ) ) {
					$map[ (string) $relative ] = (string) $alternate['file'];
				}
			}
		}
		update_option( self::MAP, $map, false );
		return $map;
	}

	/**
	 * Rebuild the map on next use, and clear pages built with the old one.
	 *
	 * @return void
	 */
	public static function flush_map(): void {
		delete_option( self::MAP );
	}

	/**
	 * Queue WebP copies for images that do not have one yet.
	 *
	 * Runs a small batch at a time from the admin, so a large library is
	 * worked through in the background without anyone pressing a button.
	 *
	 * @return void
	 */
	public static function backfill(): void {
		if ( ! self::active() || ! Plugin::allowed() || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return;
		}
		if ( get_transient( 'smao_webp_backfill' ) ) {
			return;
		}
		set_transient( 'smao_webp_backfill', 1, MINUTE_IN_SECONDS );
		$ids = self::pending( 500 );
		if ( $ids ) {
			try {
				Plugin::enqueue( 'webp', $ids );
			} catch ( \Throwable $e ) {
				Database::log( 'webp', 0, $e->getMessage() );
			}
		}
	}

	/**
	 * Images still waiting for a WebP copy.
	 *
	 * @param int $limit Most to return.
	 * @return array<int,int>
	 */
	public static function pending( int $limit ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_smao_webp_checked' WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg','image/png') AND m.meta_id IS NULL ORDER BY p.ID DESC LIMIT %d",
					$limit
				)
			)
		);
	}

	/**
	 * Progress for the interface.
	 *
	 * @return array{done:int,total:int}
	 */
	public static function progress(): array {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')" );
		$done  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'attachment'", '_smao_webp_checked' ) );
		return array(
			'done'  => min( $done, $total ),
			'total' => $total,
		);
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
		// The page rewrite switches preloads together with the image tags, so
		// a preload must name the original here or it would be switched twice
		// for browsers that accept WebP and wrongly for those that do not.
		if ( self::active() ) {
			return null;
		}
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
}
