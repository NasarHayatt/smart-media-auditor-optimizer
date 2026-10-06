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
	 * Largest difference, in pixels, between a visitor's page width and a
	 * checked width for the visitor to get the pre-built page.
	 */
	public const WIDTH_MATCH = 4;

	/**
	 * Before the first paint: measure the scrollbar a desktop browser takes
	 * from the page width, and open the page, so its scripts run straight
	 * away, when the window is not as wide as one the check looked at. The
	 * window, not the page inside it: the rules are chosen by window width,
	 * and a scrollbar made Google's desktop test miss the checked 1350px.
	 *
	 * A pre-built layout is only known to match at the widths it was checked
	 * at. Sliders scale with the screen and text wraps differently a few
	 * pixels narrower, so on a 390px phone a layout checked at 412px moved
	 * when the scripts started. Those visitors now get the page exactly as it
	 * loads without this feature, and nobody sees it move.
	 *
	 * A phone reports the browser's default 980px until the page's viewport
	 * tag has been read, and themes often print it after this script: every
	 * phone visit of a live site was treated as unchecked and ran its scripts
	 * straight away. So on a touch screen, before that tag, the screen's own
	 * width is used. Only there: Google's desktop test reports an 800px
	 * screen for its 1350px window, and taking that as the width stopped
	 * every desktop test from holding.
	 *
	 * @param array<int,int> $widths Widths the page passed at.
	 * @return string
	 */
	public static function gate( array $widths ): string {
		$list = implode( ',', array_map( 'intval', $widths ) );
		return '(function(){var d=document.documentElement,p=document.createElement("div");p.style.cssText="position:absolute;top:-999px;left:0;width:100px;height:100px;overflow:scroll";d.appendChild(p);var s=p.offsetWidth-p.clientWidth;d.removeChild(p);d.style.setProperty("--smao-sb",s+"px");var w=window.innerWidth;if(navigator.maxTouchPoints>0&&screen.width&&w>screen.width&&!document.querySelector("meta[name=viewport]"))w=screen.width;if(![' . $list . '].some(function(x){return Math.abs(w-x)<=' . self::WIDTH_MATCH . '}))d.classList.add("smao-open")})()';
	}

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
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
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
	 * Show each picture of the pre-built layout from the smallest copy
	 * WordPress keeps that still fills its box.
	 *
	 * A slider's photo was shown from its 2560px original, 393 KB, in a box
	 * 412px wide on phones. It was the page's largest paint and took seconds
	 * on a slow connection. Only copies with the original's shape qualify, so
	 * the crop stays the same, and they must be twice the box for sharp
	 * screens.
	 *
	 * @param string   $css    Pre-built styles.
	 * @param callable $copies Image URL to its copies, each [url, width, height], original included.
	 * @return string
	 */
	public static function fit_pictures( string $css, callable $copies ): string {
		if ( ! str_contains( $css, 'content:url(' ) ) {
			return $css;
		}
		return (string) preg_replace_callback(
			'/\{([^{}]*content:url\("([^"]+)"\)[^{}]*)\}/',
			static function ( array $m ) use ( $copies ): string {
				$block = $m[1];
				$url   = $m[2];
				if ( ! preg_match( '/(?:^|;)width:([\d.]+)px/', $block, $w ) || ! preg_match( '/(?:^|;)height:([\d.]+)px/', $block, $h ) ) {
					return $m[0];
				}
				$list = array_values(
					array_filter(
						(array) $copies( $url ),
						static function ( $copy ): bool {
							return is_array( $copy ) && 3 === count( $copy ) && (int) $copy[1] > 0 && (int) $copy[2] > 0;
						}
					)
				);
				if ( count( $list ) < 2 ) {
					return $m[0];
				}
				usort(
					$list,
					static function ( array $a, array $b ): int {
						return (int) $a[1] <=> (int) $b[1];
					}
				);
				$largest = $list[ count( $list ) - 1 ];
				$ratio   = $largest[1] / $largest[2];
				// Covering a box can need more width than the box itself.
				$wide = str_contains( $block, 'object-fit:cover' ) ? max( (float) $w[1], (float) $h[1] * $ratio ) : (float) $w[1];
				foreach ( $list as $copy ) {
					if ( abs( $copy[1] / $copy[2] - $ratio ) > 0.02 * $ratio || $copy[1] < 2 * $wide ) {
						continue;
					}
					return '{' . str_replace( $url, (string) $copy[0], $block ) . '}';
				}
				return $m[0];
			},
			$css
		);
	}

	/**
	 * The largest picture the pre-built layout shows in the first screen, per
	 * screen size, to fetch as soon as the page starts arriving.
	 *
	 * Written into the layout's styles, a slider's photo was only found once
	 * the browser had read the whole page: on a live phone test it waited
	 * 0.7 to 0.9s before starting, behind the page's other images, and was
	 * the page's largest paint.
	 *
	 * @param string $css     Pre-built styles, grouped in @media blocks.
	 * @param array  $allowed Widths the page holds its scripts at.
	 * @return array<int,array{url:string,media:string}>
	 */
	public static function pictures( string $css, array $allowed ): array {
		$ranges = array(
			412  => '(max-width: 600px)',
			768  => '(min-width: 601px) and (max-width: 1024px)',
			1350 => '(min-width: 1025px) and (max-width: 1600px)',
			1920 => '(min-width: 1601px)',
		);
		$open   = array();
		foreach ( $allowed as $width ) {
			foreach ( $ranges as $measured => $query ) {
				if ( abs( (int) $width - $measured ) <= self::WIDTH_MATCH ) {
					$open[] = $query;
				}
			}
		}
		$found = array();
		if ( ! $open || ! preg_match_all( '/@media ([^{]+)\{(.*?)\n\}/s', $css, $blocks, PREG_SET_ORDER ) ) {
			return $found;
		}
		foreach ( $blocks as $block ) {
			$media = array_values( array_intersect( array_map( 'trim', explode( ',', $block[1] ) ), $open ) );
			if ( ! $media || ! preg_match_all( '/\{([^{}]*content:url\("([^"]+)"\)[^{}]*)\}/', $block[2], $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			$best = null;
			$area = 0.0;
			foreach ( $rules as $rule ) {
				if ( preg_match( '/(?:^|;)width:([\d.]+)px/', $rule[1], $w ) && preg_match( '/(?:^|;)height:([\d.]+)px/', $rule[1], $h ) && (float) $w[1] * (float) $h[1] > $area ) {
					$area = (float) $w[1] * (float) $h[1];
					$best = $rule[2];
				}
			}
			if ( null !== $best && $area >= 40000 ) {
				$found[] = array(
					'url'   => $best,
					'media' => implode( ', ', $media ),
				);
			}
		}
		return $found;
	}

	/**
	 * Copies WordPress keeps of an uploaded image, the original included.
	 *
	 * @param string $url Image URL.
	 * @return array<int,array{0:string,1:int,2:int}>
	 */
	public static function copies( string $url ): array {
		if ( ! function_exists( 'attachment_url_to_postid' ) ) {
			return array();
		}
		$id   = (int) attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url ) );
		$meta = $id ? wp_get_attachment_metadata( $id ) : null;
		$full = $id ? wp_get_attachment_url( $id ) : '';
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) || ! $full ) {
			return array();
		}
		$dir  = trailingslashit( dirname( $full ) );
		$list = array( array( $full, (int) $meta['width'], (int) $meta['height'] ) );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$list[] = array( $dir . $size['file'], (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ) );
			}
		}
		return $list;
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
		$css    = self::fit_pictures( wp_strip_all_tags( (string) ( $data['css'] ?? '' ) ), array( self::class, 'copies' ) );
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
		// A page that fails at some widths still holds its scripts at the
		// widths that passed; the others load normally. A slider can differ at
		// one width only, and the whole page used to lose its speed-up for it.
		$blocked = array();
		if ( 'moved' === $status && $widths ) {
			foreach ( $widths as $row ) {
				$values = array( $row['shift'], $row['height'], $row['missing'], $row['off'] );
				if ( in_array( null, $values, true ) || 'ready' !== self::verdict( $sent, (float) $row['shift'], (float) $row['height'], (float) $row['missing'], (float) $row['off'], strlen( $css ) ) ) {
					$blocked[] = $row['width'];
				}
			}
			if ( count( $blocked ) < count( $widths ) ) {
				$status = 'ready';
			}
		}
		return array(
			'status'  => $status,
			'blocked' => 'ready' === $status ? $blocked : array(),
			'checked_blocked' => 'ready' === $status ? $blocked : array(),
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
	 * Register the route a visitor's browser reports a moving page to.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			'smao/v1',
			'/prebuild-report',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // Visitors report; the token below limits it to real pages.
				'callback'            => array( self::class, 'report' ),
			)
		);
	}

	/**
	 * A token that ties a report to a page this site printed.
	 *
	 * @param string $key Page key.
	 * @return string
	 */
	public static function token( string $key ): string {
		return substr( hash_hmac( 'sha256', 'prebuild|' . $key, wp_salt( 'auth' ) ), 0, 20 );
	}

	/**
	 * A visitor's page changed when its scripts started: stop holding them.
	 *
	 * The measurement is checked before any page holds its scripts, but a
	 * page can still do something only a real visit shows, such as loading
	 * more content as it is scrolled. The visitor's browser compares the page
	 * before and after its scripts run and reports a real change here. That
	 * page then runs its scripts normally, and its stored copy is rebuilt, so
	 * no visitor keeps getting a page that jumps. The worst a false report can
	 * do is switch one page back to how it loads without this feature.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function report( \WP_REST_Request $request ): \WP_REST_Response {
		$key   = strtolower( (string) $request->get_param( 'k' ) );
		$token = (string) $request->get_param( 't' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) || ! hash_equals( self::token( $key ), $token ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$detail = array(
			'width'  => min( 10000, absint( $request->get_param( 'w' ) ) ),
			'growth' => min( 100.0, max( 0.0, (float) $request->get_param( 'g' ) ) ),
			'shift'  => min( 100.0, max( 0.0, (float) $request->get_param( 'c' ) ) ),
		);
		// Only the screen width the visitor had stops holding its scripts; the
		// page stays fast on every other checked width. A layout can differ
		// at one width only, and switching the whole page off for one visitor
		// also took the speed-up away from everyone else.
		$url    = Styles::block_width( $key, $detail );
		if ( '' !== $url ) {
			Cache::forget( $url );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Checked widths at which the page still holds its scripts.
	 *
	 * @param array $prebuild Stored outcome.
	 * @return array<int,int>
	 */
	public static function allowed_widths( array $prebuild ): array {
		$measured = array_values( array_filter( array_map( 'absint', array_column( (array) ( $prebuild['widths'] ?? array() ), 'width' ) ) ) );
		if ( ! $measured ) {
			$measured = array( 412, 768, 1350, 1920 );
		}
		$blocked = array_map( 'absint', (array) ( $prebuild['blocked'] ?? array() ) );
		return array_values( array_diff( $measured, $blocked ) );
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
			$blocked = array_map( 'absint', (array) ( $prebuild['blocked'] ?? array() ) );
			$checked = array_map( 'absint', (array) ( $prebuild['checked_blocked'] ?? array() ) );
			$visitor = array_values( array_diff( $blocked, $checked ) );
			$list    = static function ( array $widths ): string {
				return implode( ', ', array_map( static fn( int $w ): string => $w . 'px', $widths ) );
			};
			$parts = array();
			if ( $checked ) {
				/* translators: %s: list of screen widths. */
				$parts[] = sprintf( __( 'on %s wide screens the check saw it move when scripts start', 'smart-media-auditor-optimizer' ), $list( $checked ) );
			}
			if ( $visitor ) {
				/* translators: %s: list of screen widths. */
				$parts[] = sprintf( __( 'on %s wide screens a visitor saw it move', 'smart-media-auditor-optimizer' ), $list( $visitor ) );
			}
			if ( $parts ) {
				return sprintf(
					/* translators: %s: where and why some screens load normally. */
					__( 'Its scripts wait, except where %s; those screens load normally.', 'smart-media-auditor-optimizer' ),
					implode( '; ', $parts )
				);
			}
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
		if ( 'visitor_moved' === $status ) {
			$seen = (array) ( $prebuild['reported_detail'] ?? array() );
			if ( ! empty( $seen['width'] ) ) {
				return sprintf(
					/* translators: 1: screen width in pixels, 2: change in page length in percent, 3: layout shift score. */
					__( 'Scripts run as normal: when a visitor on a %1$dpx wide screen started the scripts, the page length changed by %2$s%% and parts moved (%3$s), so it was switched back automatically. Measure again after changing this page.', 'smart-media-auditor-optimizer' ),
					(int) $seen['width'],
					number_format_i18n( (float) $seen['growth'] * 100, 1 ),
					number_format_i18n( (float) $seen['shift'], 3 )
				);
			}
			return __( 'Scripts run as normal: on a real visit the page changed when its scripts started, so it was switched back automatically. Measure again after changing this page.', 'smart-media-auditor-optimizer' );
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only hashed.
		$key   = Styles::key( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) );
		$check = rest_url( 'smao/v1/prebuild-report' ) . '|' . $key . '|' . self::token( $key );
		foreach ( self::pictures( $entry['css'], self::allowed_widths( $entry ) ) as $picture ) {
			printf( '<link rel="preload" as="image" href="%s" fetchpriority="high" media="%s">' . "\n", esc_url( $picture['url'] ), esc_attr( $picture['media'] ) );
		}
		echo '<style id="smao-prebuild" data-smao-check="' . esc_attr( $check ) . '">' . wp_strip_all_tags( $entry['css'] ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, tags stripped.
		echo '<script id="smao-prebuild-gate">' . self::gate( self::allowed_widths( $entry ) ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from integers.
	}
}
