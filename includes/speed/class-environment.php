<?php
/**
 * Detects what this WordPress install actually is.
 *
 * Every optimisation decision is made from this, so the plugin adapts to the
 * site instead of applying one fixed configuration everywhere. Results are
 * cached for a day and recalculated whenever plugins or the theme change.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Environment fingerprint and capability detection.
 */
final class Environment {

	/**
	 * Option holding the cached fingerprint.
	 */
	private const OPTION = 'smao_environment';

	/**
	 * Page builders, by an active-plugin path fragment or a callable check.
	 */
	private const BUILDERS = array(
		'elementor'  => 'elementor/elementor.php',
		'wpbakery'   => 'js_composer/js_composer.php',
		'divi'       => 'divi-builder/divi-builder.php',
		'beaver'     => 'beaver-builder-lite-version/fl-builder.php',
		'bricks'     => 'bricks/bricks.php',
		'oxygen'     => 'oxygen/functions.php',
		'brizy'      => 'brizy/brizy.php',
		'siteorigin' => 'siteorigin-panels/siteorigin-panels.php',
		'thrive'     => 'thrive-visual-editor/thrive-visual-editor.php',
		'wp_bakery'  => 'js_composer_salient/js_composer.php',
	);

	/**
	 * Other performance plugins. Running two of these against the same markup
	 * is how sites break, so we stand down where they already act.
	 */
	private const OPTIMIZERS = array(
		'wp-rocket'          => 'wp-rocket/wp-rocket.php',
		'litespeed'          => 'litespeed-cache/litespeed-cache.php',
		'w3-total-cache'     => 'w3-total-cache/w3-total-cache.php',
		'wp-super-cache'     => 'wp-super-cache/wp-cache.php',
		'autoptimize'        => 'autoptimize/autoptimize.php',
		'perfmatters'        => 'perfmatters/perfmatters.php',
		'wp-fastest-cache'   => 'wp-fastest-cache/wpFastestCache.php',
		'sg-optimizer'       => 'sg-cachepress/sg-cachepress.php',
		'nitropack'          => 'nitropack/main.php',
		'tenweb'             => 'tenweb-speed-optimizer/tenweb-speed-optimizer.php',
		'flying-press'       => 'flying-press/flying-press.php',
		'swift-performance'  => 'swift-performance-lite/performance.php',
		'breeze'             => 'breeze/breeze.php',
		'hummingbird'        => 'hummingbird-performance/wp-hummingbird.php',
		'wp-optimize'        => 'wp-optimize/wp-optimize.php',
		'ewww'               => 'ewww-image-optimizer/ewww-image-optimizer.php',
		'shortpixel'         => 'shortpixel-image-optimiser/wp-shortpixel.php',
		'smush'              => 'wp-smushit/wp-smush.php',
		'imagify'            => 'imagify/imagify.php',
		'optimole'           => 'optimole-wp/optimole-wp.php',
	);

	/**
	 * Sliders and carousels that keep media in their own tables.
	 */
	private const PRIVATE_MEDIA = array(
		'revslider'   => 'revslider/revslider.php',
		'layerslider' => 'LayerSlider/layerslider.php',
		'masterslider' => 'masterslider/masterslider.php',
		'smartslider' => 'smart-slider-3/smart-slider-3.php',
	);

	/**
	 * Read the environment, recalculating when the site has changed.
	 *
	 * @param bool $fresh Force recalculation.
	 * @return array
	 */
	public static function get( bool $fresh = false ): array {
		$cached = get_option( self::OPTION, array() );
		if ( ! $fresh && is_array( $cached ) && ( $cached['signature'] ?? '' ) === self::signature() ) {
			return $cached;
		}
		$data = self::detect();
		update_option( self::OPTION, $data, false );
		return $data;
	}

	/**
	 * A cheap fingerprint of everything that would change our decisions.
	 *
	 * @return string
	 */
	private static function signature(): string {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					(array) get_option( 'active_plugins', array() ),
					is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array(),
					get_stylesheet(),
					get_template(),
					SMAO_VERSION,
				)
			)
		);
	}

	/**
	 * Work out what is installed and what the server can do.
	 *
	 * @return array
	 */
	private static function detect(): array {
		$active = self::active_plugins();

		$builders = array();
		foreach ( self::BUILDERS as $slug => $path ) {
			if ( in_array( $path, $active, true ) ) {
				$builders[] = $slug;
			}
		}
		// Elementor and Divi can be present as themes rather than plugins.
		if ( defined( 'ELEMENTOR_VERSION' ) && ! in_array( 'elementor', $builders, true ) ) {
			$builders[] = 'elementor';
		}
		if ( function_exists( 'et_setup_theme' ) || 'Divi' === wp_get_theme()->get( 'Name' ) ) {
			$builders[] = 'divi';
		}

		$optimizers = array();
		foreach ( self::OPTIMIZERS as $slug => $path ) {
			if ( in_array( $path, $active, true ) ) {
				$optimizers[] = $slug;
			}
		}

		$private_media = array();
		foreach ( self::PRIVATE_MEDIA as $slug => $path ) {
			if ( in_array( $path, $active, true ) ) {
				$private_media[] = $slug;
			}
		}

		return array(
			'signature'     => self::signature(),
			'detected_at'   => time(),
			'theme'         => get_stylesheet(),
			'parent_theme'  => get_template(),
			'builders'      => array_values( array_unique( $builders ) ),
			'optimizers'    => $optimizers,
			'private_media' => $private_media,
			'woocommerce'   => class_exists( 'WooCommerce' ) || in_array( 'woocommerce/woocommerce.php', $active, true ),
			'multisite'     => is_multisite(),
			'server'        => self::server(),
			'plugin_count'  => count( $active ),
		);
	}

	/**
	 * Active plugins on this site, including network activations.
	 *
	 * @return array
	 */
	private static function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return $active;
	}

	/**
	 * What the hosting environment supports.
	 *
	 * @return array
	 */
	private static function server(): array {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		$apache   = str_contains( strtolower( $software ), 'apache' ) || str_contains( strtolower( $software ), 'litespeed' );

		return array(
			'software'    => $software,
			'apache'      => $apache,
			'nginx'       => str_contains( strtolower( $software ), 'nginx' ),
			'htaccess'    => $apache && self::htaccess_writable(),
			'brotli'      => function_exists( 'brotli_compress' ),
			'gzip'        => function_exists( 'gzencode' ),
			'object_cache'=> wp_using_ext_object_cache(),
			'php'         => PHP_VERSION,
		);
	}

	/**
	 * Whether the root .htaccess can be managed.
	 *
	 * @return bool
	 */
	private static function htaccess_writable(): bool {
		/*
		 * get_home_path() lives in wp-admin and does not exist on the front
		 * end. Calling it there is a fatal error, and because this runs while
		 * deciding whether to cache, it took down every front-end request on
		 * Apache and LiteSpeed hosts. Derive the path instead, and only fall
		 * back to the admin helper when it is genuinely loaded.
		 */
		$home = function_exists( 'get_home_path' ) ? get_home_path() : self::home_path();
		if ( '' === $home ) {
			return false;
		}
		$file = rtrim( $home, '/\\' ) . '/.htaccess';
		return ( file_exists( $file ) && is_writable( $file ) ) || is_writable( $home );
	}

	/**
	 * The filesystem path the site is served from, without wp-admin helpers.
	 *
	 * Mirrors the logic of get_home_path(): when WordPress lives in a
	 * subdirectory, the site root is that many levels above ABSPATH.
	 *
	 * @return string Trailing-slashed path, or an empty string.
	 */
	public static function home_path(): string {
		$home    = set_url_scheme( (string) get_option( 'home' ), 'http' );
		$siteurl = set_url_scheme( (string) get_option( 'siteurl' ), 'http' );

		if ( '' !== $home && 0 !== strcasecmp( $home, $siteurl ) ) {
			// WordPress is installed in a subdirectory of the site root.
			$offset = str_ireplace( $home, '', $siteurl );
			$offset = trim( (string) $offset, '/' );
			$path   = str_replace( '\\', '/', ABSPATH );
			if ( '' !== $offset && str_ends_with( rtrim( $path, '/' ), $offset ) ) {
				$path = substr( rtrim( $path, '/' ), 0, -strlen( $offset ) );
			}
			return trailingslashit( $path );
		}
		return trailingslashit( str_replace( '\\', '/', ABSPATH ) );
	}

	/**
	 * Whether another plugin already owns a given optimisation area.
	 *
	 * Used to stand down rather than fight, which is what actually breaks
	 * sites that stack two performance plugins.
	 *
	 * @param string $area One of: assets, images, cache.
	 * @return string Name of the owning plugin, or an empty string.
	 */
	public static function conflict( string $area ): string {
		$environment = self::get();
		$owners      = array(
			'assets' => array( 'wp-rocket', 'litespeed', 'w3-total-cache', 'autoptimize', 'perfmatters', 'nitropack', 'tenweb', 'flying-press', 'swift-performance', 'sg-optimizer', 'hummingbird', 'breeze', 'wp-fastest-cache' ),
			'images' => array( 'ewww', 'shortpixel', 'smush', 'imagify', 'optimole', 'nitropack', 'tenweb' ),
			'cache'  => array( 'wp-rocket', 'litespeed', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'nitropack', 'breeze', 'sg-optimizer', 'swift-performance' ),
		);
		foreach ( $environment['optimizers'] as $slug ) {
			if ( in_array( $slug, $owners[ $area ] ?? array(), true ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Human-readable names for detected plugins.
	 *
	 * @param string $slug Internal slug.
	 * @return string
	 */
	public static function label( string $slug ): string {
		$names = array(
			'wp-rocket'         => 'WP Rocket',
			'litespeed'         => 'LiteSpeed Cache',
			'w3-total-cache'    => 'W3 Total Cache',
			'wp-super-cache'    => 'WP Super Cache',
			'autoptimize'       => 'Autoptimize',
			'perfmatters'       => 'Perfmatters',
			'wp-fastest-cache'  => 'WP Fastest Cache',
			'sg-optimizer'      => 'SiteGround Optimizer',
			'nitropack'         => 'NitroPack',
			'tenweb'            => '10Web Booster',
			'flying-press'      => 'FlyingPress',
			'swift-performance' => 'Swift Performance',
			'breeze'            => 'Breeze',
			'hummingbird'       => 'Hummingbird',
			'wp-optimize'       => 'WP-Optimize',
			'ewww'              => 'EWWW Image Optimizer',
			'shortpixel'        => 'ShortPixel',
			'smush'             => 'Smush',
			'imagify'           => 'Imagify',
			'optimole'          => 'Optimole',
			'elementor'         => 'Elementor',
			'wpbakery'          => 'WPBakery',
			'divi'              => 'Divi',
			'beaver'            => 'Beaver Builder',
			'bricks'            => 'Bricks',
			'oxygen'            => 'Oxygen',
			'brizy'             => 'Brizy',
			'siteorigin'        => 'SiteOrigin',
			'thrive'            => 'Thrive Architect',
			'revslider'         => 'Slider Revolution',
			'layerslider'       => 'LayerSlider',
			'masterslider'      => 'MasterSlider',
			'smartslider'       => 'Smart Slider 3',
		);
		return $names[ $slug ] ?? ucfirst( str_replace( '-', ' ', $slug ) );
	}
}
