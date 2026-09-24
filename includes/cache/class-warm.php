<?php
/**
 * Cache warming.
 *
 * After a purge the affected pages are slow again until somebody visits them.
 * This rebuilds them in the background, a few at a time, so the first real
 * visitor gets a cache hit without the site being hammered to achieve it.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Background regeneration of purged pages.
 */
final class Warm {

	/**
	 * Option holding the pending queue.
	 */
	private const QUEUE = 'smao_warm_queue';

	/**
	 * Most URLs to hold at once, so a large site cannot fill the options table.
	 */
	private const MAX_QUEUE = 300;

	/**
	 * URLs fetched per pass, keeping the load gentle.
	 */
	private const PER_PASS = 5;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'smao_warm', array( self::class, 'run' ) );
		add_action( 'shutdown', array( self::class, 'spawn' ), 99 );
	}

	/**
	 * Add URLs to the queue, ignoring duplicates.
	 *
	 * @param array $urls URLs.
	 * @return int Queue length.
	 */
	public static function queue( array $urls ): int {
		if ( ! Cache::active() || ! Settings::get()['cache_warm'] ) {
			return 0;
		}
		$queue = (array) get_option( self::QUEUE, array() );
		foreach ( $urls as $url ) {
			$url = esc_url_raw( (string) $url );
			if ( '' === $url || ! str_starts_with( $url, home_url() ) ) {
				continue;
			}
			$queue[ md5( $url ) ] = $url;
		}
		if ( count( $queue ) > self::MAX_QUEUE ) {
			$queue = array_slice( $queue, -self::MAX_QUEUE, null, true );
		}
		update_option( self::QUEUE, $queue, false );
		return count( $queue );
	}

	/**
	 * Schedule a pass if work is waiting.
	 *
	 * Runs on shutdown so the editor's own request is never held up by it.
	 *
	 * @return void
	 */
	public static function spawn(): void {
		if ( ! Cache::active() || ! self::pending() ) {
			return;
		}
		if ( ! wp_next_scheduled( 'smao_warm' ) ) {
			wp_schedule_single_event( time() + 10, 'smao_warm' );
		}
		// WP-Cron only fires when somebody visits, and a freshly purged site
		// may have no visitors for a while, so nudge it ourselves.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			self::loopback();
		}
	}

	/**
	 * Fetch the next few queued URLs.
	 *
	 * @return int Number warmed.
	 */
	public static function run(): int {
		if ( ! Cache::active() ) {
			return 0;
		}
		$lock = get_transient( 'smao_warm_lock' );
		if ( $lock ) {
			return 0; // A pass is already running.
		}
		set_transient( 'smao_warm_lock', 1, 120 );

		$queue = (array) get_option( self::QUEUE, array() );
		$batch = array_slice( $queue, 0, self::PER_PASS, true );
		$done  = 0;

		foreach ( $batch as $hash => $url ) {
			unset( $queue[ $hash ] );
			/*
			 * Fire and forget. The page is generated and cached by the request
			 * itself, so there is nothing to read back, and waiting would tie
			 * up a worker per URL. On a single-worker server, waiting deadlocks
			 * outright because the request cannot be served until we return.
			 */
			wp_remote_get(
				$url,
				array(
					'timeout'    => 0.5,
					'blocking'   => false,
					'sslverify'  => false,
					'user-agent' => 'SMAO-Warmer/' . SMAO_VERSION,
					'headers'    => array( 'X-SMAO-Warm' => '1' ),
				)
			);
			++$done;
		}

		update_option( self::QUEUE, $queue, false );
		update_option( 'smao_warm_last', time(), false );
		delete_transient( 'smao_warm_lock' );

		if ( $queue && ! wp_next_scheduled( 'smao_warm' ) ) {
			wp_schedule_single_event( time() + 20, 'smao_warm' );
		}
		return $done;
	}

	/**
	 * Trigger WP-Cron over a non-blocking loopback request.
	 *
	 * @return void
	 */
	private static function loopback(): void {
		wp_remote_post(
			site_url( 'wp-cron.php?doing_wp_cron' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
			)
		);
	}

	/**
	 * How many URLs are waiting.
	 *
	 * @return int
	 */
	public static function pending(): int {
		return count( (array) get_option( self::QUEUE, array() ) );
	}

	/**
	 * When the last pass ran.
	 *
	 * @return int
	 */
	public static function last_run(): int {
		return (int) get_option( 'smao_warm_last', 0 );
	}

	/**
	 * Queue the site's most important pages.
	 *
	 * @return int
	 */
	public static function queue_important(): int {
		$urls = array( home_url( '/' ) );

		$blog = (int) get_option( 'page_for_posts' );
		if ( $blog ) {
			$urls[] = (string) get_permalink( $blog );
		}
		foreach ( get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'numberposts'    => 15,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		) as $id ) {
			$urls[] = (string) get_permalink( (int) $id );
		}
		foreach ( get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'numberposts' => 15,
				'fields'      => 'ids',
			)
		) as $id ) {
			$urls[] = (string) get_permalink( (int) $id );
		}
		return self::queue( $urls );
	}

	/**
	 * Empty the queue.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::QUEUE );
		delete_transient( 'smao_warm_lock' );
	}
}
