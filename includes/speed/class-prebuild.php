<?php
/**
 * Pre-built layout for pages whose scripts wait.
 *
 * Holding every script until the visitor interacts gives the fastest first
 * paint, but anything a script builds, such as a carousel or a slider, is not
 * built yet, so on most themes the page looks broken until then.
 *
 * The page measurement fixes that for each page, on any theme. It loads the
 * page twice at each width: finished, and with scripts held. Every element is
 * labelled so the two can be matched exactly, whatever the scripts moved. It
 * then writes styles that make the held page look like the finished one:
 * areas keep their finished height, rows of cards sit side by side at their
 * finished width, and images a script would have placed are shown where they
 * will be. Finally it lays the held page out again with those styles and
 * compares it with the finished page. Only a page that matches may hold its
 * scripts; every other page keeps them running normally. When the scripts do
 * run, the styles switch themselves off.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Layout pre-building and its verification.
 */
final class Prebuild {

	/**
	 * Largest layout movement tolerated between held and finished pages.
	 */
	public const MAX_SHIFT = 0.02;

	/**
	 * Largest difference in page height tolerated, as a share.
	 */
	public const MAX_HEIGHT = 0.01;

	/**
	 * Largest share of the finished page's content that may be missing.
	 */
	public const MAX_MISSING = 0.01;

	/**
	 * Largest share of rebuilt pieces allowed to land away from their place.
	 */
	public const MAX_OFF = 0.05;

	/**
	 * Largest amount of generated styles accepted for one page, as sent:
	 * compressed, which is how every host and CDN delivers a page. The rules
	 * repeat the same selectors and declarations, so they compress about
	 * twenty times; judging them uncompressed turned away a home page whose
	 * 65 KB of styles cost visitors under 3 KB.
	 */
	public const MAX_CSS = 30720;

	/**
	 * Measures the scrollbar a desktop browser takes from the page width.
	 */
	public const SCROLLBAR = '(function(){var d=document.documentElement,p=document.createElement("div");p.style.cssText="position:absolute;top:-999px;left:0;width:100px;height:100px;overflow:scroll";d.appendChild(p);d.style.setProperty("--smao-sb",(p.offsetWidth-p.clientWidth)+"px");d.removeChild(p)})()';

	/**
	 * Largest amount of generated styles accepted uncompressed, which bounds
	 * the time a phone spends reading them.
	 */
	public const MAX_RAW = 524288;

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
			// Label every element so the finished and the held page can be
			// matched element by element.
			add_action(
				'template_redirect',
				static function (): void {
					ob_start( array( self::class, 'stamp' ) );
				},
				-2000
			);
			return;
		}
		add_action( 'wp_head', array( self::class, 'print_styles' ), 1 );
	}

	/**
	 * Whether this is a measurement pass that must show the held page.
	 *
	 * @return bool
	 */
	public static function held(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only marker; the measuring check requires an administrator.
		return isset( $_GET['smao-held'] ) && Rightsize::measuring();
	}

	/**
	 * Label every element with its position in the served page.
	 *
	 * Script, style, template and comment contents are left alone, and so is
	 * anything inside an SVG, so the numbering depends only on the page's
	 * elements and is identical whether scripts are held or not.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function stamp( string $html ): string {
		if ( ! str_contains( $html, '</html>' ) ) {
			return $html;
		}
		$masked = (string) preg_replace_callback(
			'#<(script|style|template|textarea|svg|noscript|xmp|iframe)\b.*?</\1\s*>|<!--.*?-->#is',
			static function ( array $match ): string {
				return str_repeat( ' ', strlen( $match[0] ) );
			},
			$html
		);
		$skip = array( 'html', 'head', 'meta', 'link', 'title', 'base', 'script', 'style', 'noscript', 'template', 'br', 'wbr', 'source', 'track', 'param', 'col', 'area' );
		if ( ! preg_match_all( '#<([a-z][a-z0-9-]*)(?=[\s>/])#i', $masked, $tags, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$out    = '';
		$last   = 0;
		$number = 0;
		foreach ( $tags[1] as $tag ) {
			list( $name, $offset ) = $tag;
			if ( in_array( strtolower( $name ), $skip, true ) ) {
				continue;
			}
			++$number;
			$insert = $offset + strlen( $name );
			$out   .= substr( $html, $last, $insert - $last ) . ' data-smao-n="' . $number . '"';
			$last   = $insert;
		}
		return $out . substr( $html, $last );
	}

	/**
	 * The outcome of pre-building one page, ready to store.
	 *
	 * @param array $data Keys: css, shift, height, missing, off (shares).
	 * @return array
	 */
	public static function clean( array $data ): array {
		$number = static function ( string $key ) use ( $data ): float {
			return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? (float) $data[ $key ] : -1.0;
		};
		$css    = wp_strip_all_tags( (string) ( $data['css'] ?? '' ) );
		$sent   = self::sent_size( $css );
		$status = self::verdict( $sent, $number( 'shift' ), $number( 'height' ), $number( 'missing' ), $number( 'off' ), strlen( $css ) );
		$widths = array();
		foreach ( array_slice( (array) ( $data['widths'] ?? array() ), 0, 8 ) as $row ) {
			if ( ! is_array( $row ) || ! absint( $row['width'] ?? 0 ) ) {
				continue;
			}
			$clean = array( 'width' => absint( $row['width'] ) );
			foreach ( array( 'shift', 'height', 'missing', 'off' ) as $key ) {
				$clean[ $key ] = isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ? round( (float) $row[ $key ], 4 ) : null;
			}
			$widths[] = $clean;
		}
		return array(
			'status'  => $status,
			'css'     => 'ready' === $status ? $css : '',
			'shift'   => round( max( 0, $number( 'shift' ) ), 4 ),
			'height'  => round( max( 0, $number( 'height' ) ), 4 ),
			'missing' => round( max( 0, $number( 'missing' ) ), 4 ),
			'off'     => round( max( 0, $number( 'off' ) ), 4 ),
			'bytes'   => strlen( $css ),
			'sent'    => $sent,
			'width'   => absint( $data['width'] ?? 0 ),
			'widths'  => $widths,
		);
	}

	/**
	 * Size of styles as a visitor downloads them, compressed.
	 *
	 * @param string $css Styles.
	 * @return int
	 */
	public static function sent_size( string $css ): int {
		if ( '' === $css ) {
			return 0;
		}
		if ( function_exists( 'gzencode' ) ) {
			$packed = gzencode( $css, 6 );
			if ( false !== $packed ) {
				return strlen( $packed );
			}
		}
		return (int) ceil( strlen( $css ) / 8 ); // Typical ratio for these rules.
	}

	/**
	 * Plain words for why a page does or does not hold its scripts.
	 *
	 * @param array $prebuild Stored outcome.
	 * @return string
	 */
	public static function reason( array $prebuild ): string {
		$status = (string) ( $prebuild['status'] ?? '' );
		if ( 'ready' === $status ) {
			return __( 'Looks the same with scripts waiting, so its scripts wait.', 'smart-media-auditor-optimizer' );
		}
		if ( 'too_large' === $status ) {
			if ( empty( $prebuild['sent'] ) ) {
				return __( 'Scripts run as normal: rebuilding this layout would need too many extra styles. Measure again.', 'smart-media-auditor-optimizer' );
			}
			return sprintf(
				/* translators: %s: size, such as 40 KB. */
				__( 'Scripts run as normal: rebuilding this layout would add %s to the page.', 'smart-media-auditor-optimizer' ),
				size_format( (int) ( (int) ( $prebuild['bytes'] ?? 0 ) > self::MAX_RAW ? $prebuild['bytes'] : $prebuild['sent'] ) )
			);
		}
		if ( 'unchecked' === $status ) {
			return __( 'Scripts run as normal: the page did not finish loading while it was checked. Measure again.', 'smart-media-auditor-optimizer' );
		}
		// The measure furthest over its limit is the one worth naming.
		$limits = array(
			'shift'   => self::MAX_SHIFT,
			'height'  => self::MAX_HEIGHT,
			'missing' => self::MAX_MISSING,
			'off'     => self::MAX_OFF,
		);
		$worst = '';
		$over  = 0.0;
		foreach ( $limits as $key => $limit ) {
			$ratio = (float) ( $prebuild[ $key ] ?? 0 ) / $limit;
			if ( $ratio > $over ) {
				$over  = $ratio;
				$worst = $key;
			}
		}
		$percent = round( (float) ( $prebuild[ $worst ] ?? 0 ) * 100, 1 );
		$words   = array(
			/* translators: %s: percentage. */
			'shift'   => __( 'parts of the page would move when scripts start (%s%%)', 'smart-media-auditor-optimizer' ),
			/* translators: %s: percentage. */
			'height'  => __( 'the page length would change by %s%%', 'smart-media-auditor-optimizer' ),
			/* translators: %s: percentage. */
			'missing' => __( '%s%% of the content would be missing until scripts run', 'smart-media-auditor-optimizer' ),
			/* translators: %s: percentage. */
			'off'     => __( '%s%% of the rebuilt pieces would be out of place', 'smart-media-auditor-optimizer' ),
		);
		$what = isset( $words[ $worst ] ) ? sprintf( $words[ $worst ], $percent ) : __( 'it would look different', 'smart-media-auditor-optimizer' );
		$at   = absint( $prebuild['width'] ?? 0 );
		return $at
			/* translators: 1: screen width in pixels, 2: what went wrong. */
			? sprintf( __( 'Scripts run as normal: on a %1$dpx wide screen %2$s.', 'smart-media-auditor-optimizer' ), $at, $what )
			/* translators: %s: what went wrong. */
			: sprintf( __( 'Scripts run as normal: %s.', 'smart-media-auditor-optimizer' ), $what );
	}

	/**
	 * Decide whether a page may hold its scripts.
	 *
	 * @param int   $bytes   Size of the generated styles as sent, compressed.
	 * @param float $shift   Layout movement between held and finished page.
	 * @param float $height  Difference in page height, as a share.
	 * @param float $missing Share of visible content missing when held.
	 * @param float $off     Share of rebuilt pieces not where they belong.
	 * @param int   $raw     Size of the generated styles uncompressed.
	 * @return string ready, moved, too_large or unchecked.
	 */
	public static function verdict( int $bytes, float $shift, float $height, float $missing = 0.0, float $off = 0.0, int $raw = 0 ): string {
		if ( $shift < 0 || $height < 0 || $missing < 0 || $off < 0 ) {
			return 'unchecked';
		}
		if ( $bytes > self::MAX_CSS || $raw > self::MAX_RAW ) {
			return 'too_large';
		}
		if ( $shift > self::MAX_SHIFT || $height > self::MAX_HEIGHT || $missing > self::MAX_MISSING || $off > self::MAX_OFF ) {
			return 'moved';
		}
		return 'ready';
	}

	/**
	 * How many measured pages may hold their scripts.
	 *
	 * @return array{ready:int,total:int}
	 */
	public static function coverage(): array {
		$ready = 0;
		$total = 0;
		foreach ( Styles::pages() as $entry ) {
			if ( ! is_array( $entry['prebuild'] ?? null ) ) {
				continue;
			}
			++$total;
			if ( 'ready' === ( $entry['prebuild']['status'] ?? '' ) ) {
				++$ready;
			}
		}
		return array(
			'ready' => $ready,
			'total' => $total,
		);
	}

	/**
	 * The verified pre-build for the page being rendered, if any.
	 *
	 * @return array|null
	 */
	public static function entry(): ?array {
		static $cache = false;
		if ( false !== $cache ) {
			return $cache;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only hashed.
		$path  = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$entry = Styles::pages()[ Styles::key( $path ) ]['prebuild'] ?? null;
		$cache = is_array( $entry ) && 'ready' === ( $entry['status'] ?? '' ) ? $entry : null;
		return $cache;
	}

	/**
	 * Whether this page may hold every script.
	 *
	 * @return bool
	 */
	public static function allows_holding(): bool {
		if ( self::held() ) {
			return true; // The measurement pass that looks at the held page.
		}
		return null !== self::entry();
	}

	/**
	 * Print the pre-built layout while scripts are held.
	 *
	 * @return void
	 */
	public static function print_styles(): void {
		if ( ! Scripts::holding_all() ) {
			return;
		}
		$entry = self::entry();
		if ( null === $entry || '' === $entry['css'] ) {
			return;
		}
		if ( str_contains( $entry['css'], '--smao-sb' ) ) {
			// Full-width areas subtract the scrollbar a desktop browser adds.
			// Measured here, before the first paint; zero on phones.
			echo '<script id="smao-prebuild-scrollbar">' . self::SCROLLBAR . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed script.
		}
		echo '<style id="smao-prebuild">' . wp_strip_all_tags( $entry['css'] ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, tags stripped.
	}
}
