<?php
/**
 * Plugin component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin workflow and safety policy.
 */
final class Plugin {
	/**
	 * Install the current site schema and schedule background work.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Database::install();
		self::schedule();
	}

	/**
	 * Clear current-site scheduled work while preserving recovery data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'smao_worker' );
		wp_clear_scheduled_hook( 'smao_scheduled_scan' );
		// Per-site lazy schedules on a network naturally become inert when disabled.
	}

	/**
	 * Require administrator and media-upload capabilities.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'upload_files' );
	}

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( get_option( 'smao_schema' ) !== SMAO_VERSION ) {
			Database::install(); }
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['smao_minute'] = array(
					'interval' => 60,
					'display'  => __( 'Every minute (media jobs)', 'smart-media-auditor-optimizer' ),
				);
				return $schedules;
			}
		);
		self::schedule();
		add_action( 'smao_worker', array( self::class, 'worker' ) );
		add_action(
			'smao_scheduled_scan',
			static function (): void {
				try {
					Database::lock(
						static function (): void {
							if ( ! in_array( Database::state()['state'], array( 'running', 'paused' ), true ) ) {
								Scanner::start(); }
						}
					); } catch ( \Throwable $e ) {
					return; // Existing job retains the lock and checkpoint.
					}
			}
		);
		foreach ( array( 'save_post', 'deleted_post', 'created_term', 'edited_term', 'delete_term', 'profile_update', 'switch_theme' ) as $hook ) {
			add_action( $hook, array( self::class, 'dirty' ) ); }
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'added_user_meta', 'updated_user_meta', 'deleted_user_meta', 'added_comment_meta', 'updated_comment_meta', 'deleted_comment_meta' ) as $hook ) {
			add_action(
				$hook,
				static function ( $meta_id, $object_id, $key ): void {
					if ( ! str_starts_with( (string) $key, '_smao_' ) && ! in_array( $key, array( '_edit_lock', '_edit_last', 'session_tokens' ), true ) && ! preg_match( '/(?:^|_)user-settings(?:-time)?$/', (string) $key ) ) {
						self::dirty();
					} },
				10,
				3
			);
		}
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action(
				$hook,
				static function ( $key ): void {
					if ( ! str_starts_with( (string) $key, 'smao_' ) && ! str_starts_with( (string) $key, '_transient_' ) && ! str_starts_with( (string) $key, '_site_transient_' ) && 'cron' !== $key ) {
						self::dirty(); }
				}
			);
		}
		foreach ( array( 'wp_insert_comment', 'edit_comment', 'delete_comment' ) as $hook ) {
			add_action( $hook, array( self::class, 'dirty' ) ); }
		add_filter(
			'wp_generate_attachment_metadata',
			static function ( $metadata, $id ) {
				if ( Settings::get()['auto_optimize'] && self::allowed() ) {
					self::enqueue( 'optimize', array( (int) $id ) ); }
				return $metadata;
			},
			20,
			2
		);
		add_filter(
			'intermediate_image_sizes_advanced',
			static function ( array $sizes ): array {
				foreach ( Settings::get()['disabled_sizes'] as $name ) {
					unset( $sizes[ $name ] ); }
				return $sizes;
			}
		);
		add_filter( 'wp_get_attachment_image', array( self::class, 'picture' ), 20, 5 );
		add_filter(
			'pre_delete_attachment',
			static function ( $delete, $post ) {
				return Vault::record( (int) $post->ID ) ? false : $delete;
			},
			10,
			2
		);
		add_action( 'wp_head', array( self::class, 'preload' ), 2 );
		add_action(
			'wp_enqueue_scripts',
			static function (): void {
				if ( self::allowed() && is_admin_bar_showing() ) {
					wp_enqueue_script( 'smao-inspector', plugins_url( 'assets/inspect-images.js', SMAO_FILE ), array( 'wp-i18n' ), SMAO_VERSION, true );
					wp_set_script_translations( 'smao-inspector', 'smart-media-auditor-optimizer' );
				}
			}
		);
		add_action(
			'admin_bar_menu',
			static function ( $bar ): void {
				if ( self::allowed() && ! is_admin() ) {
					$bar->add_node(
						array(
							'id'    => 'smao-inspect',
							'title' => __( 'Inspect image sizing', 'smart-media-auditor-optimizer' ),
							'href'  => '#smao-image-report',
						)
					); }
			},
			100
		);
		Admin::boot();
	}

	/**
	 * Ensure the site has a background worker event.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'smao_worker' ) ) {
			// Activation may precede filter registration; use a single event then boot
			// installs the recurring schedule on the next request.
			if ( isset( wp_get_schedules()['smao_minute'] ) ) {
				wp_schedule_event( time() + 60, 'smao_minute', 'smao_worker' ); } else {
				wp_schedule_single_event( time() + 60, 'smao_worker' ); }
		}
	}

	/**
	 * Invalidate previous usage conclusions after relevant site changes.
	 *
	 * @return void
	 */
	public static function dirty(): void {
		update_option( 'smao_epoch', wp_generate_uuid4(), false );
	}

	/**
	 * Remove a bounded batch of old, disposable plugin-owned records.
	 *
	 * @return int Number of removed records.
	 */
	public static function cleanup(): int {
		global $wpdb;
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
		$removed = 0;
		foreach ( array( 'log', 'jobs' ) as $name ) {
			$table     = Database::table( $name );
			$predicate = 'jobs' === $name ? " AND state IN ('complete','failed','cancelled')" : '';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Predicate is a fixed internal literal; table uses an identifier placeholder.
			$removed += (int) Database::check( $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE created < %s $predicate LIMIT 500", $table, $cutoff ) ) );
		}
		Database::log( 'cleanup', 0, 'Administrator removed a bounded batch of logs and finished jobs older than 90 days.' );
		return $removed;
	}

	/**
	 * Queue explicitly authorized image operations for bounded processing.
	 *
	 * @param string $action Action.
	 * @param array  $ids Ids.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function enqueue( string $action, array $ids ): void {
		global $wpdb;
		if ( ! in_array( $action, array( 'optimize', 'thumbnails' ), true ) ) {
			throw new \RuntimeException( I18n::text( 'Unsupported background action.' ) ); }
		$table = Database::table( 'jobs' );
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
				throw new \RuntimeException( I18n::text( 'Attachment permission denied.' ) ); }
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE attachment_id=%d AND action=%s AND state IN ('queued','running') LIMIT 1", $id, $action ) );
			Database::check( $existing );
			if ( $existing ) {
				continue; }
			Database::check(
				$wpdb->insert(
					$table,
					array(
						'attachment_id' => $id,
						'user_id'       => get_current_user_id(),
						'action'        => $action,
						'state'         => 'queued',
						'created'       => gmdate( 'Y-m-d H:i:s' ),
						'message'       => '',
					)
				)
			);
		}
	}

	/**
	 * Run a background batch under the site operation lock.
	 *
	 * @return void
	 */
	public static function worker(): void {
		try {
			Database::lock(
				static function (): void {
					self::tick();
				}
			); } catch ( \Throwable $e ) {
			return; // Scanner checkpoints or job journals retain error state.
			}
	}

	/**
	 * Advance one bounded scan batch or queued job.
	 *
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function tick(): void {
		global $wpdb;
		if ( 'running' === Database::state()['state'] ) {
			Scanner::tick();
			return; }
		if ( get_option( 'smao_jobs_paused', false ) ) {
			return; }
		$table = Database::table( 'jobs' );
		// Connection lock proves no earlier worker is still alive. Never blindly replay
		// an interrupted mutation; its backup is available through recovery.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		Database::check( $wpdb->query( "UPDATE $table SET state='failed',message='Worker interrupted. Inspect recovery records before retrying.' WHERE state='running'" ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$job = $wpdb->get_row( "SELECT * FROM $table WHERE state='queued' ORDER BY id LIMIT 1", ARRAY_A );
		Database::check( $job );
		if ( ! $job ) {
			return; }
		$previous = get_current_user_id();
		wp_set_current_user( (int) $job['user_id'] );
		Database::check( $wpdb->update( $table, array( 'state' => 'running' ), array( 'id' => $job['id'] ) ) );
		try {
			if ( ! self::allowed() || ! current_user_can( 'edit_post', (int) $job['attachment_id'] ) ) {
				throw new \RuntimeException( I18n::text( 'Requesting administrator no longer has permission.' ) ); }
			if ( 'optimize' === $job['action'] ) {
				Optimizer::optimize( (int) $job['attachment_id'] ); } else {
				Optimizer::thumbnails( (int) $job['attachment_id'] ); }
				Database::check(
					$wpdb->update(
						$table,
						array(
							'state'   => 'complete',
							'message' => 'Completed.',
						),
						array( 'id' => $job['id'] )
					)
				);
		} catch ( \Throwable $e ) {
			Database::check(
				$wpdb->update(
					$table,
					array(
						'state'   => 'failed',
						'message' => $e->getMessage(),
					),
					array( 'id' => $job['id'] )
				)
			);
			Database::log( 'job_failed', (int) $job['attachment_id'], $e->getMessage() );
		} finally {
			wp_set_current_user( $previous ); }
	}

	/**
	 * Add a compatible modern-format source while retaining the core image fallback.
	 *
	 * @param string $html Html.
	 * @param int    $id Id.
	 * @param mixed  $size Size.
	 * @param bool   $icon Icon.
	 * @param array  $attr Attr.
	 * @return string
	 */
	public static function picture( string $html, int $id, $size, bool $icon, array $attr ): string {
		if ( is_admin() || ! Settings::get()['delivery'] || $icon || Media::remote( $id ) ) {
			return $html; }
		$alternates = get_post_meta( $id, '_smao_alternates', true );
		if ( ! is_array( $alternates ) || ! $alternates ) {
			return $html; }
		$image = wp_get_attachment_image_src( $id, $size );
		if ( ! $image ) {
			return $html; }
		$uploads = wp_upload_dir();
		$prefix  = trailingslashit( $uploads['baseurl'] );
		if ( ! str_starts_with( $image[0], $prefix ) ) {
			return $html; }
		$relative = substr( $image[0], strlen( $prefix ) );
		if ( empty( $alternates[ $relative ] ) ) {
			return $html; }
		$selected = $alternates[ $relative ];
		$sources  = array();
		foreach ( $alternates as $alt ) {
			if ( $alt['mime'] !== $selected['mime'] || abs( $alt['width'] / max( 1, $alt['height'] ) - $selected['width'] / max( 1, $selected['height'] ) ) > 0.01 ) {
				continue; }
			try {
				Media::path( $alt['file'] );
			} catch ( \Throwable $e ) {
				continue; }
			$sources[ (int) $alt['width'] ] = esc_url( $prefix . $alt['file'] ) . ' ' . (int) $alt['width'] . 'w';
		}
		if ( ! $sources ) {
			return $html; }
		ksort( $sources );
		$sizes = wp_calculate_image_sizes( $size, $image[0], wp_get_attachment_metadata( $id ), $id );
		return '<picture><source type="' . esc_attr( $selected['mime'] ) . '" srcset="' . esc_attr( implode( ', ', $sources ) ) . '" sizes="' . esc_attr( $attr['sizes'] ?? ( $sizes ? $sizes : '100vw' ) ) . '">' . $html . '</picture>';
	}

	/**
	 * Preload the explicitly selected front-page image when compatible with delivery settings.
	 *
	 * @return void
	 */
	public static function preload(): void {
		$id = Settings::get()['preload_id'];
		if ( ! $id || ! is_front_page() || Settings::get()['delivery'] ) {
			return; }
		$url = wp_get_attachment_image_url( $id, 'full' );
		if ( ! $url ) {
			return; }
		$srcset = wp_get_attachment_image_srcset( $id, 'full' );
		$sizes  = wp_get_attachment_image_sizes( $id, 'full' );
		echo '<link rel="preload" as="image" href="' . esc_url( $url ) . '"';
		if ( $srcset && $sizes ) {
			echo ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $sizes ) . '"'; }
		echo ' fetchpriority="high">' . "\n";
	}
}
