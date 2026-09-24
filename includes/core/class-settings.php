<?php
/**
 * Settings storage, validation and ownership rules.
 *
 * Each value has exactly one owner screen. Throughput values belong to Audit,
 * scope review belongs to Clean up, everything else belongs to Settings. Saving
 * merges over current values rather than defaults, so a partial save from one
 * tab can never reset a value owned by another.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Validated plugin configuration.
 */
final class Settings {

	/**
	 * Integer settings and their inclusive bounds.
	 */
	private const BOUNDS = array(
		'batch'          => array( 1, 200 ),
		'source_batch'   => array( 1, 100 ),
		'time_budget'    => array( 1, 8 ),
		'poll_interval'  => array( 1, 15 ),
		'recent_days'    => array( 7, 3650 ),
		'retention_days' => array( 7, 3650 ),
		'quality'        => array( 50, 100 ),
		'max_width'      => array( 320, 12000 ),
		'max_height'     => array( 320, 12000 ),
		'preload_id'     => array( 0, PHP_INT_MAX ),
		'delay_timeout'  => array( 0, 30 ),
		'cache_ttl'      => array( 1, 720 ),
	);

	/**
	 * Boolean settings.
	 */
	private const FLAGS = array(
		'speed_enabled',
		'dimensions',
		'lcp_preload',
		'lazy_correct',
		'delivery',
		'rightsize',
		'defer_js',
		'delay_js',
		'async_css',
		'optimize_fonts',
		'page_cache',
		'browser_cache',
		'cache_gzip',
		'cache_warm',
		'separate_mobile',
		'coverage_reviewed',
		'resize',
		'strip_exif',
		'auto_optimize',
		'browser_worker',
	);

	/**
	 * Enumerated settings and their permitted values.
	 */
	private const CHOICES = array(
		'schedule'    => array( 'off', 'daily', 'weekly' ),
		'compression' => array( 'lossless', 'lossy' ),
		'alternate'   => array( 'off', 'webp', 'avif' ),
	);

	/**
	 * Settings whose change invalidates completed scan coverage.
	 */
	private const COVERAGE = array( 'exclusions', 'recent_days', 'coverage_reviewed' );

	/**
	 * Throughput settings owned by the Audit screen.
	 */
	public const RUNTIME = array( 'batch', 'source_batch', 'time_budget', 'poll_interval', 'browser_worker' );

	/**
	 * Return conservative initial settings.
	 *
	 * Speed corrections that cannot alter appearance are on. Anything that
	 * changes markup or rewrites files is off until explicitly enabled.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'speed_enabled'     => true,
			'dimensions'        => true,
			'lcp_preload'       => true,
			'lazy_correct'      => true,
			'delivery'          => false,
			'rightsize'         => true,
			'defer_js'          => true,
			'delay_js'          => true,
			'delay_timeout'     => 6,
			'delay_extra'       => '',
			'script_exclusions' => '',
			'style_exclusions'  => '',
			'async_css'         => true,
			'optimize_fonts'    => true,
			'page_cache'        => true,
			'browser_cache'     => true,
			'cache_gzip'        => true,
			'cache_warm'        => true,
			'separate_mobile'   => false,
			'cache_ttl'         => 24,
			'cache_exclusions'  => '',
			'preload_id'        => 0,
			'batch'             => 50,
			'source_batch'      => 100,
			'time_budget'       => 3,
			'poll_interval'     => 2,
			'browser_worker'    => true,
			'recent_days'       => 30,
			'retention_days'    => 30,
			'coverage_reviewed' => false,
			'schedule'          => 'off',
			'exclusions'        => '',
			'compression'       => 'lossless',
			'quality'           => 85,
			'max_width'         => 2560,
			'max_height'        => 2560,
			'resize'            => false,
			'strip_exif'        => false,
			'alternate'         => 'off',
			'auto_optimize'     => false,
			'disabled_sizes'    => array(),
		);
	}

	/**
	 * Read settings with defaults for missing keys.
	 *
	 * @return array
	 */
	public static function get(): array {
		return array_merge( self::defaults(), (array) get_option( 'smao_settings', array() ) );
	}

	/**
	 * Update throughput controls without invalidating scan coverage.
	 *
	 * @param array $input Runtime options.
	 * @return array
	 */
	public static function runtime( array $input ): array {
		$out = self::get();
		foreach ( self::RUNTIME as $key ) {
			if ( 'browser_worker' === $key ) {
				$out[ $key ] = ! empty( $input[ $key ] );
				continue;
			}
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
				$out[ $key ] = self::clamp( $key, $input[ $key ] );
			}
		}
		update_option( 'smao_settings', $out, false );
		Database::log( 'runtime_settings', 0, __( 'Administrator updated processing controls.', 'smart-media-auditor-optimizer' ) );
		return $out;
	}

	/**
	 * Validate and persist a partial settings update.
	 *
	 * Only keys present in the payload are touched, and only a change that
	 * genuinely affects what a scan would find invalidates existing coverage.
	 *
	 * @param array $input Submitted values.
	 * @return array{settings:array,adjusted:array,invalidated:bool}
	 */
	public static function save( array $input ): array {
		$current  = self::get();
		$out      = $current;
		$adjusted = array();

		foreach ( self::BOUNDS as $key => $range ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$requested   = (int) $input[ $key ];
			$out[ $key ] = self::clamp( $key, $requested );
			if ( $out[ $key ] !== $requested ) {
				$adjusted[ $key ] = array(
					'requested' => $requested,
					'stored'    => $out[ $key ],
					'min'       => $range[0],
					'max'       => $range[1],
				);
			}
		}

		// Checkboxes only report when checked, so a group must declare itself.
		$present = (array) ( $input['_flags'] ?? array() );
		foreach ( self::FLAGS as $key ) {
			if ( in_array( $key, $present, true ) ) {
				$out[ $key ] = ! empty( $input[ $key ] );
			}
		}

		foreach ( self::CHOICES as $key => $allowed ) {
			if ( array_key_exists( $key, $input ) && in_array( $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}

		foreach ( array( 'delay_extra', 'script_exclusions', 'style_exclusions', 'cache_exclusions' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = sanitize_textarea_field( substr( (string) $input[ $key ], 0, 8000 ) );
			}
		}
		if ( array_key_exists( 'exclusions', $input ) ) {
			$out['exclusions'] = sanitize_textarea_field( substr( (string) $input['exclusions'], 0, 12000 ) );
		}
		if ( array_key_exists( 'disabled_sizes', $input ) ) {
			$out['disabled_sizes'] = array_values(
				array_intersect(
					get_intermediate_image_sizes(),
					array_map( 'sanitize_key', (array) $input['disabled_sizes'] )
				)
			);
		}

		$invalidated = false;
		foreach ( self::COVERAGE as $key ) {
			if ( $out[ $key ] !== $current[ $key ] ) {
				$invalidated = true;
				break;
			}
		}

		update_option( 'smao_settings', $out, false );

		if ( $out['schedule'] !== $current['schedule'] ) {
			wp_clear_scheduled_hook( 'smao_scheduled_scan' );
			if ( 'off' !== $out['schedule'] ) {
				wp_schedule_event( time() + 300, $out['schedule'], 'smao_scheduled_scan' );
			}
		}
		if ( $invalidated ) {
			Plugin::dirty();
			Database::log( 'settings', 0, __( 'Administrator changed scan scope; prior scan coverage invalidated.', 'smart-media-auditor-optimizer' ) );
		} else {
			Database::log( 'settings', 0, __( 'Administrator updated settings.', 'smart-media-auditor-optimizer' ) );
		}

		return array(
			'settings'    => $out,
			'adjusted'    => $adjusted,
			'invalidated' => $invalidated,
		);
	}

	/**
	 * Constrain an integer setting to its permitted range.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Requested value.
	 * @return int
	 */
	private static function clamp( string $key, mixed $value ): int {
		list( $min, $max ) = self::BOUNDS[ $key ];
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Expose the bounds for a setting so forms can advertise them.
	 *
	 * @param string $key Setting name.
	 * @return array{0:int,1:int}
	 */
	public static function bounds( string $key ): array {
		return self::BOUNDS[ $key ] ?? array( 0, PHP_INT_MAX );
	}
}
