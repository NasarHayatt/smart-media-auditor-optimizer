<?php
/**
 * JavaScript execution control.
 *
 * Total Blocking Time is 30% of the Lighthouse performance score and is caused
 * almost entirely by JavaScript running during page load. Two levers move it:
 *
 *   defer  - the script still runs on load, but stops blocking the parser.
 *   delay  - the script does not run until the visitor interacts, so it costs
 *            nothing during load at all.
 *
 * Delaying is by far the stronger lever and is safe for anything the first
 * paint does not depend on: analytics, chat widgets, social embeds, ads.
 * A hard timeout guarantees delayed scripts still run for visitors who never
 * interact, so nothing is permanently withheld.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Defer and delay script execution.
 */
final class Scripts {

	/**
	 * Scripts that must never be touched: the page cannot render without them,
	 * or deferring them reorders behaviour in ways no site survives.
	 */
	private const NEVER = array(
		'jquery-core',
		'jquery-migrate',
		'jquery',
		'wp-polyfill',
		'wp-hooks',
		'wp-i18n',
		'utils',
		'smao-delay',
	);

	/**
	 * Third-party hosts whose scripts never affect first paint. These are the
	 * biggest and safest wins, so they are delayed by default.
	 */
	private const THIRD_PARTY = array(
		'googletagmanager.com',
		'google-analytics.com',
		'connect.facebook.net',
		'facebook.com/tr',
		'hotjar.com',
		'clarity.ms',
		'doubleclick.net',
		'googlesyndication.com',
		'googleadservices.com',
		'tawk.to',
		'crisp.chat',
		'intercom.io',
		'livechatinc.com',
		'zendesk.com',
		'drift.com',
		'hubspot.com',
		'hs-scripts.com',
		'addthis.com',
		'sharethis.com',
		'disqus.com',
		'recaptcha',
		'twitter.com/widgets',
		'platform.twitter.com',
		'instagram.com/embed',
		'tiktok.com/embed',
		'snap.licdn.com',
		'ads-twitter.com',
		'bat.bing.com',
		'pinterest.com',
		'mailchimp.com',
		'chimpstatic.com',
	);

	/**
	 * Register hooks when script optimisation is enabled and unclaimed.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( is_admin() || self::skip() ) {
			return;
		}
		$settings = Settings::get();

		if ( $settings['defer_js'] ) {
			add_filter( 'script_loader_tag', array( self::class, 'defer' ), 20, 3 );
		}
		if ( $settings['delay_js'] ) {
			add_filter( 'script_loader_tag', array( self::class, 'delay_tag' ), 21, 3 );
			add_action( 'wp_head', array( self::class, 'runtime' ), 1 );
			add_filter( 'wp_resource_hints', array( self::class, 'drop_preconnect' ), 20, 2 );
			ob_start( array( self::class, 'delay_inline' ) );
		}
	}

	/**
	 * Whether to stand down entirely for this request.
	 *
	 * @return bool
	 */
	private static function skip(): bool {
		if ( ! Settings::get()['speed_enabled'] ) {
			return true;
		}
		// Never optimise for logged-in editors, previews, or builder sessions:
		// they need every script to behave exactly as the author expects.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		if ( is_customize_preview() || is_preview() || is_feed() || wp_is_json_request() ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bail-out checks.
		$query = array_keys( $_GET );
		foreach ( array( 'elementor-preview', 'fl_builder', 'vc_editable', 'brizy-edit', 'ct_builder', 'tve', 'et_fb', 'customize_theme', 'smao-measure', 'smao-nooptimize' ) as $marker ) {
			if ( in_array( $marker, $query, true ) ) {
				return true;
			}
		}
		if ( Environment::conflict( 'assets' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Add defer to scripts that do not need to block parsing.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Registered handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public static function defer( string $tag, string $handle, string $src ): string {
		if ( self::excluded( $handle, $src ) || self::is_delayed( $handle, $src ) ) {
			return $tag;
		}
		if ( str_contains( $tag, ' defer' ) || str_contains( $tag, ' async' ) || str_contains( $tag, 'type="module"' ) ) {
			return $tag;
		}
		return str_replace( '<script ', '<script defer ', $tag );
	}

	/**
	 * Convert a delayed script into an inert placeholder.
	 *
	 * The browser will not fetch or execute type="smao/delayed", so the cost
	 * disappears from load entirely until the runtime revives it.
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Registered handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public static function delay_tag( string $tag, string $handle, string $src ): string {
		if ( ! self::is_delayed( $handle, $src ) ) {
			return $tag;
		}
		$tag = preg_replace( '/\stype=(["\'])[^"\']*\1/i', '', $tag );
		return str_replace( '<script ', '<script type="smao/delayed" ', $tag );
	}

	/**
	 * Rewrite inline third-party snippets in the finished page.
	 *
	 * Analytics and pixels are usually pasted inline rather than enqueued, so
	 * the tag filter never sees them.
	 *
	 * @param string $html Buffered page.
	 * @return string
	 */
	public static function delay_inline( string $html ): string {
		if ( ! $html || ! str_contains( $html, '<script' ) ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#<script(?![^>]*\stype=["\']smao/delayed)([^>]*)>(.*?)</script>#is',
			static function ( array $match ): string {
				$attributes = $match[1];
				$body       = $match[2];

				// Leave structured data and templates alone.
				if ( preg_match( '#type=["\'](application/ld\+json|text/template|text/html|application/json)#i', $attributes ) ) {
					return $match[0];
				}
				if ( ! self::inline_is_third_party( $attributes . ' ' . $body ) ) {
					return $match[0];
				}
				$attributes = preg_replace( '/\stype=(["\'])[^"\']*\1/i', '', $attributes );
				return '<script type="smao/delayed"' . $attributes . '>' . $body . '</script>';
			},
			$html
		);
	}

	/**
	 * Whether a chunk of markup belongs to a known third party.
	 *
	 * @param string $text Attributes plus body.
	 * @return bool
	 */
	private static function inline_is_third_party( string $text ): bool {
		foreach ( self::THIRD_PARTY as $needle ) {
			if ( str_contains( $text, $needle ) ) {
				return true;
			}
		}
		// Common inline analytics bootstraps with no obvious host.
		foreach ( array( 'gtag(', 'dataLayer.push', 'fbq(', '_gaq.push', 'ga(\'create', 'hj(' ) as $needle ) {
			if ( str_contains( $text, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a script should be held until interaction.
	 *
	 * @param string $handle Registered handle.
	 * @param string $src    Script URL.
	 * @return bool
	 */
	private static function is_delayed( string $handle, string $src ): bool {
		if ( self::excluded( $handle, $src ) ) {
			return false;
		}
		$settings = Settings::get();

		foreach ( self::THIRD_PARTY as $needle ) {
			if ( str_contains( $src, $needle ) ) {
				return true;
			}
		}
		foreach ( self::rules( $settings['delay_extra'] ) as $needle ) {
			if ( str_contains( $src, $needle ) || $handle === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a script will still hold up the first paint after optimisation.
	 *
	 * @param string $handle Registered handle.
	 * @param string $src    Script URL.
	 * @param array  $extra  The registration's extra data.
	 * @return bool
	 */
	public static function stays_blocking( string $handle, string $src, array $extra ): bool {
		if ( in_array( $extra['strategy'] ?? '', array( 'defer', 'async' ), true ) ) {
			return false;
		}
		if ( self::skip() ) {
			return true;
		}
		$settings = Settings::get();
		if ( $settings['delay_js'] && self::is_delayed( $handle, $src ) ) {
			return false;
		}
		if ( $settings['defer_js'] && ! self::excluded( $handle, $src ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a script is protected from any change.
	 *
	 * @param string $handle Registered handle.
	 * @param string $src    Script URL.
	 * @return bool
	 */
	private static function excluded( string $handle, string $src ): bool {
		if ( in_array( $handle, self::NEVER, true ) ) {
			return true;
		}
		foreach ( self::rules( Settings::get()['script_exclusions'] ) as $needle ) {
			if ( $handle === $needle || ( '' !== $src && str_contains( $src, $needle ) ) ) {
				return true;
			}
		}
		/**
		 * Filter whether a script is left completely alone.
		 *
		 * @param bool   $excluded Current decision.
		 * @param string $handle   Script handle.
		 * @param string $src      Script URL.
		 */
		return (bool) apply_filters( 'smao_exclude_script', false, $handle, $src );
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

	/**
	 * Do not preconnect to hosts whose scripts we are deliberately delaying.
	 *
	 * @param array  $hints Hint URLs.
	 * @param string $type  Hint type.
	 * @return array
	 */
	public static function drop_preconnect( array $hints, string $type ): array {
		if ( ! in_array( $type, array( 'preconnect', 'dns-prefetch' ), true ) ) {
			return $hints;
		}
		return array_values(
			array_filter(
				$hints,
				static function ( $hint ): bool {
					$url = is_array( $hint ) ? ( $hint['href'] ?? '' ) : (string) $hint;
					foreach ( self::THIRD_PARTY as $needle ) {
						if ( str_contains( $url, $needle ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/**
	 * The runtime that revives delayed scripts.
	 *
	 * Runs on the first real interaction, or after a timeout so that visitors
	 * who never interact still get full functionality. Scripts are executed in
	 * document order, and the usual lifecycle events are replayed so libraries
	 * that wait for DOMContentLoaded or load still initialise.
	 *
	 * @return void
	 */
	public static function runtime(): void {
		$timeout = (int) Settings::get()['delay_timeout'];
		?>
<script id="smao-delay"><?php
		// phpcs:disable
		?>
(function(){var t=<?php echo (int) ( $timeout * 1000 ); ?>,f=!1,E=["keydown","mousemove","touchstart","touchmove","wheel","scroll","pointerdown","mousedown"];
function run(){if(f)return;f=!0;E.forEach(function(e){window.removeEventListener(e,run,{passive:!0})});
var s=document.querySelectorAll('script[type="smao/delayed"]'),i=0;
function next(){if(i>=s.length){done();return}var o=s[i++],n=document.createElement("script");
for(var a=0;a<o.attributes.length;a++){var at=o.attributes[a];if("type"===at.name)continue;n.setAttribute(at.name,at.value)}
if(o.src){n.onload=n.onerror=next;n.src=o.src;o.parentNode.replaceChild(n,o)}else{n.text=o.text;o.parentNode.replaceChild(n,o);next()}}
function done(){try{document.dispatchEvent(new Event("DOMContentLoaded",{bubbles:!0}));window.dispatchEvent(new Event("DOMContentLoaded",{bubbles:!0}));window.dispatchEvent(new Event("load"));
if(window.jQuery){try{jQuery(document).trigger("ready")}catch(e){}}}catch(e){}}
next()}
E.forEach(function(e){window.addEventListener(e,run,{passive:!0})});
if(t>0){window.setTimeout(run,t)}
document.addEventListener("visibilitychange",function(){document.hidden||run()});
})();
<?php
		// phpcs:enable
		?>
</script>
		<?php
	}
}
