<?php
/**
 * Works out how large each image is actually displayed.
 *
 * WordPress tells the browser every content image is the full width of the
 * window. It usually is not, so the browser downloads a far larger file than
 * it draws. Nothing in the database knows the real display size, because it is
 * decided by CSS, so this measures the rendered page instead.
 *
 * Measurement runs inside wp-admin, in a hidden frame, against the site's own
 * pages. Nothing is added to the pages your visitors see.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Layout measurement and the savings it reveals.
 */
final class Measure {

	/**
	 * Viewport widths to measure, smallest first.
	 */
	public const VIEWPORTS = array( 390, 768, 1280, 1920 );

	/**
	 * Pixel ratio to keep sharp on high-density screens.
	 */
	private const RETINA = 2;

	/**
	 * Below this ratio the saving is not worth acting on.
	 */
	private const THRESHOLD = 1.25;

	/**
	 * Viewport used when describing today's waste, a typical desktop.
	 *
	 * WordPress claims every content image fills the window, so this is the
	 * width the browser currently believes it must cover.
	 */
	private const REFERENCE = 1280;

	/**
	 * Representative pages to measure: one of each template that exists.
	 *
	 * @return array<int,array{url:string,label:string}>
	 */
	public static function targets(): array {
		$targets = array();

		$targets[] = array(
			'url'   => home_url( '/' ),
			'label' => __( 'Front page', 'smart-media-auditor-optimizer' ),
		);

		$post = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'numberposts'    => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		if ( $post ) {
			$targets[] = array(
				'url'   => (string) get_permalink( $post[0] ),
				'label' => __( 'Most recent post', 'smart-media-auditor-optimizer' ),
			);
		}

		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => 2,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		foreach ( $pages as $page ) {
			if ( (int) get_option( 'page_on_front' ) === $page->ID ) {
				continue;
			}
			$targets[] = array(
				'url'   => (string) get_permalink( $page ),
				'label' => get_the_title( $page ),
			);
		}

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( ! $posts_page && 'posts' === get_option( 'show_on_front' ) ) {
			$posts_page = 0;
		}

		return array_slice( $targets, 0, 6 );
	}

	/**
	 * Store one page's worth of measurements.
	 *
	 * @param string $url          Measured URL.
	 * @param int    $viewport     Viewport width used.
	 * @param array  $observations List of {id, width, height}.
	 * @return int Number of rows stored.
	 * @throws \RuntimeException When the payload is unusable.
	 */
	public static function store( string $url, int $viewport, array $observations ): int {
		global $wpdb;

		$url = esc_url_raw( $url );
		if ( ! $url || ! str_starts_with( $url, home_url() ) ) {
			throw new \RuntimeException( __( 'Only pages on this site can be measured.', 'smart-media-auditor-optimizer' ) );
		}
		if ( ! in_array( $viewport, self::VIEWPORTS, true ) ) {
			throw new \RuntimeException( __( 'Unexpected viewport width.', 'smart-media-auditor-optimizer' ) );
		}

		$hash  = hash( 'sha256', $url );
		$table = Database::table( 'render' );
		$now   = time();
		$rows  = 0;

		foreach ( $observations as $observation ) {
			$id     = absint( $observation['id'] ?? 0 );
			$width  = absint( $observation['width'] ?? 0 );
			$height = absint( $observation['height'] ?? 0 );
			if ( ! $id || ! $width ) {
				continue;
			}
			Database::check(
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO `$table` (attachment_id,url_hash,viewport,rendered_width,rendered_height,observed_at)
						VALUES (%d,%s,%d,%d,%d,%d)
						ON DUPLICATE KEY UPDATE rendered_width=VALUES(rendered_width),rendered_height=VALUES(rendered_height),observed_at=VALUES(observed_at)",
						$id,
						$hash,
						$viewport,
						$width,
						$height,
						$now
					)
				)
			);
			++$rows;
		}

		$pages = Database::table( 'pages' );
		Database::check(
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `$pages` (url_hash,url,image_count,measured_at)
					VALUES (%s,%s,%d,%d)
					ON DUPLICATE KEY UPDATE image_count=GREATEST(image_count,VALUES(image_count)),measured_at=VALUES(measured_at)",
					$hash,
					$url,
					$rows,
					$now
				)
			)
		);

		return $rows;
	}

	/**
	 * The widest this image is ever drawn, per viewport, across measured pages.
	 *
	 * @param int $id Attachment ID.
	 * @return array<int,int> Viewport width to rendered width.
	 */
	public static function widths( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT viewport,MAX(rendered_width) AS w FROM %i WHERE attachment_id=%d GROUP BY viewport ORDER BY viewport ASC',
				Database::table( 'render' ),
				$id
			),
			ARRAY_A
		);
		Database::check( $rows );

		$widths = array();
		foreach ( $rows as $row ) {
			$width = (int) $row['w'];
			if ( $width > 0 ) {
				$widths[ (int) $row['viewport'] ] = $width;
			}
		}
		return $widths;
	}

	/**
	 * Build a sizes attribute describing where the image is really drawn.
	 *
	 * @param int $id Attachment ID.
	 * @return string Empty when the image has never been measured.
	 */
	public static function sizes( int $id ): string {
		$widths = self::widths( $id );
		if ( ! $widths ) {
			return '';
		}
		$parts   = array();
		$largest = 0;
		foreach ( $widths as $viewport => $width ) {
			$largest = max( $largest, $width );
			if ( $viewport === (int) max( array_keys( $widths ) ) ) {
				continue;
			}
			$parts[] = sprintf( '(max-width: %dpx) %dpx', $viewport, $width );
		}
		$parts[] = sprintf( '%dpx', $largest );
		return implode( ', ', $parts );
	}

	/**
	 * The largest pixel width worth serving, allowing for retina screens.
	 *
	 * @param int $id Attachment ID.
	 * @return int Zero when unmeasured.
	 */
	public static function needed( int $id ): int {
		$widths = self::widths( $id );
		return $widths ? (int) max( $widths ) * self::RETINA : 0;
	}

	/**
	 * How many measurements exist, and when they were taken.
	 *
	 * @return array{images:int,pages:int,measured_at:int}
	 */
	public static function coverage(): array {
		global $wpdb;
		$render = Database::table( 'render' );
		$pages  = Database::table( 'pages' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table names.
		$row = $wpdb->get_row( "SELECT COUNT(DISTINCT attachment_id) AS images, MAX(observed_at) AS at FROM `$render`", ARRAY_A );
		Database::check( $row );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table names.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$pages` WHERE measured_at>0" );
		return array(
			'images'      => (int) ( $row['images'] ?? 0 ),
			'pages'       => $count,
			'measured_at' => (int) ( $row['at'] ?? 0 ),
		);
	}

	/**
	 * Images being served larger than they are drawn, worst first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function oversized( int $limit = 20 ): array {
		global $wpdb;
		$render = Database::table( 'render' );
		$media  = Database::table( 'media' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table names; limit is an integer.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.attachment_id, MAX(r.rendered_width) AS drawn, m.filename, m.width, m.bytes
				FROM `$render` r
				INNER JOIN `$media` m ON m.attachment_id = r.attachment_id
				WHERE r.rendered_width > 0 AND m.width > 0
				GROUP BY r.attachment_id, m.filename, m.width, m.bytes
				HAVING m.width > MAX(r.rendered_width) * %f
				ORDER BY m.bytes DESC
				LIMIT %d",
				self::RETINA * self::THRESHOLD,
				$limit
			),
			ARRAY_A
		);
		Database::check( $rows );

		$out = array();
		foreach ( $rows as $row ) {
			$id     = (int) $row['attachment_id'];
			$drawn  = (int) $row['drawn'];
			$full   = (int) $row['width'];
			$bytes  = (int) $row['bytes'];
			$before = self::candidate( $id, self::REFERENCE );
			$after  = self::candidate( $id, $drawn );
			if ( ! $before || ! $after || $after >= $before ) {
				continue;
			}
			$out[] = array(
				'id'       => $id,
				'filename' => (string) $row['filename'],
				'served'   => $before,
				'drawn'    => $drawn,
				'needed'   => $after,
				'bytes'    => $bytes,
				'wasted'   => max( 0, self::weigh( $bytes, $before, $full ) - self::weigh( $bytes, $after, $full ) ),
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $b['wasted'] <=> $a['wasted'];
			}
		);
		return $out;
	}

	/**
	 * The srcset candidate a browser picks for a required CSS width.
	 *
	 * @param int $id       Attachment ID.
	 * @param int $required Required width in pixels.
	 * @return int Candidate width, or zero when none is known.
	 */
	private static function candidate( int $id, int $required ): int {
		$meta = wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) ) {
			return 0;
		}
		$widths = array( (int) $meta['width'] );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['width'] ) ) {
				$widths[] = (int) $size['width'];
			}
		}
		$widths = array_values( array_unique( array_filter( $widths ) ) );
		sort( $widths );
		foreach ( $widths as $width ) {
			if ( $width >= $required ) {
				return $width;
			}
		}
		return (int) end( $widths );
	}

	/**
	 * Estimate the bytes of a variant. File size tracks pixel area closely
	 * enough for a comparison between two variants of the same image.
	 *
	 * @param int $bytes Bytes of the full-size file.
	 * @param int $width Variant width.
	 * @param int $full  Full-size width.
	 * @return int
	 */
	private static function weigh( int $bytes, int $width, int $full ): int {
		if ( $full < 1 || $width < 1 ) {
			return 0;
		}
		$ratio = min( 1, ( $width * $width ) / ( $full * $full ) );
		return (int) round( $bytes * $ratio );
	}

	/**
	 * Total bytes currently being sent for pixels nobody sees.
	 *
	 * @return int
	 */
	public static function wasted(): int {
		$total = 0;
		foreach ( self::oversized( 500 ) as $row ) {
			$total += $row['wasted'];
		}
		return $total;
	}
}
