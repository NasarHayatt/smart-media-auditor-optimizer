<?php
/**
 * Settings component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Settings workflow and safety policy.
 */
final class Settings {
	/**
	 * Return conservative initial settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
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
			'preload_id'        => 0,
			'delivery'          => false,
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
	 * Update throughput controls without invalidating unchanged scan coverage.
	 *
	 * @param array $input Runtime options.
	 * @return array
	 */
	public static function runtime( array $input ): array {
		$out = self::get();
		foreach ( array(
			'batch'         => array( 1, 200 ),
			'source_batch'  => array( 1, 100 ),
			'time_budget'   => array( 1, 8 ),
			'poll_interval' => array( 1, 15 ),
		) as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
				$out[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) ); }
		}
		$out['browser_worker'] = ! empty( $input['browser_worker'] );
		update_option( 'smao_settings', $out, false );
		Database::log( 'runtime_settings', 0, 'Administrator updated bounded processing controls.' );
		return $out;
	}

	/**
	 * Validate and persist the supplied configuration or recovery record.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function save( array $input ): array {
		$out = self::defaults();
		foreach ( array(
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
		) as $key => $range ) {
			$out[ $key ] = max( $range[0], min( $range[1], (int) ( $input[ $key ] ?? $out[ $key ] ) ) );
		}
		foreach ( array( 'coverage_reviewed', 'resize', 'strip_exif', 'auto_optimize', 'delivery', 'browser_worker' ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}
		foreach ( array(
			'schedule'    => array( 'off', 'daily', 'weekly' ),
			'compression' => array( 'lossless', 'lossy' ),
			'alternate'   => array( 'off', 'webp', 'avif' ),
		) as $key => $allowed ) {
			$out[ $key ] = in_array( $input[ $key ] ?? '', $allowed, true ) ? $input[ $key ] : $out[ $key ];
		}
		$out['exclusions']     = sanitize_textarea_field( substr( (string) ( $input['exclusions'] ?? '' ), 0, 12000 ) );
		$out['disabled_sizes'] = array_values( array_intersect( get_intermediate_image_sizes(), array_map( 'sanitize_key', (array) ( $input['disabled_sizes'] ?? array() ) ) ) );
		update_option( 'smao_settings', $out, false );
		Plugin::dirty();
		wp_clear_scheduled_hook( 'smao_scheduled_scan' );
		if ( 'off' !== $out['schedule'] ) {
			wp_schedule_event( time() + 300, $out['schedule'], 'smao_scheduled_scan' );
		}
		Database::log( 'settings', 0, 'Administrator updated settings; prior scans invalidated.' );
		return $out;
	}
}
