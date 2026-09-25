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
			Database::install();
			// The cache drop-in reads its settings from a file. Refresh it now,
			// not at the next admin visit, so an update takes effect at once.
			add_action(
				'init',
				static function (): void {
					if ( Cache::active() ) {
						Cache::prepare_directory();
						Cache::write_config();
						Cache::flush();
					}
				}
			);
		}
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
		/*
		 * A scan's results go stale only when something could add a reference
		 * to a media file. Removing a reference can only make a file look used
		 * when it no longer is, which is safe. Page builders and many plugins
		 * rewrite their own caches on ordinary visits; counting those as
		 * changes meant a scan on a live site never finished cleanly. Every
		 * removal is still re-verified against live data before it happens.
		 */
		add_action(
			'save_post',
			static function ( $post_id, $post ): void {
				if ( $post instanceof \WP_Post && self::relevant_post( $post ) ) {
					self::dirty();
				}
			},
			10,
			2
		);
		add_action( 'switch_theme', array( self::class, 'dirty' ) );
		foreach ( array( 'post', 'term', 'user', 'comment' ) as $type ) {
			foreach ( array( 'added', 'updated' ) as $verb ) {
				add_action(
					"{$verb}_{$type}_meta",
					static function ( $meta_id, $object_id, $key, $value ): void {
						if ( self::relevant_meta( (string) $key, $value ) ) {
							self::dirty();
						}
					},
					10,
					4
				);
			}
		}
		add_action(
			'added_option',
			static function ( $name, $value ): void {
				if ( self::relevant_option( (string) $name, $value ) ) {
					self::dirty();
				}
			},
			10,
			2
		);
		add_action(
			'updated_option',
			static function ( $name, $old, $value ): void {
				if ( self::relevant_option( (string) $name, $value ) ) {
					self::dirty();
				}
			},
			10,
			3
		);
		add_action(
			'wp_insert_comment',
			static function ( $id, $comment ): void {
				if ( is_object( $comment ) && self::references_media( (string) $comment->comment_content ) ) {
					self::dirty();
				}
			},
			10,
			2
		);
		add_filter(
			'wp_generate_attachment_metadata',
			static function ( $metadata, $id ) {
				if ( Settings::get()['auto_optimize'] && self::allowed() ) {
					self::enqueue( 'optimize', array( (int) $id ) ); }
				if ( Delivery::active() && self::allowed() ) {
					try {
						self::enqueue( 'webp', array( (int) $id ) );
					} catch ( \Throwable $e ) {
						Database::log( 'webp', (int) $id, $e->getMessage() );
					}
				}
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
		/*
		 * Deleting an image in the Media Library is the owner's decision. It
		 * used to be refused silently whenever the plugin held a recovery copy,
		 * which looked like a broken delete button. Now the deletion goes
		 * ahead and the plugin forgets the image straight away, so its counts
		 * and next step update without a new scan.
		 */
		add_action( 'delete_attachment', array( self::class, 'forget_attachment' ) );
		add_action( 'admin_init', array( self::class, 'forget_missing' ) );
		Cache::boot();
		Purge::boot();
		Warm::boot();
		Headers::boot();
		Viewport::boot();
		Rightsize::boot();
		Delivery::boot();
		Styles::watch();
		/*
		 * These inspect the main query to decide whether to optimise, so they
		 * must wait until it exists. Booting them at plugins_loaded made
		 * is_feed() and friends fire before the query was run.
		 */
		add_action( 'template_redirect', array( Scripts::class, 'boot' ), 0 );
		add_action( 'template_redirect', array( Styles::class, 'boot' ), 0 );
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
	 * Whether a value could contain a reference to a media file.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $key   Field name, used to recognise bare attachment IDs.
	 * @return bool
	 */
	public static function references_media( $value, string $key = '' ): bool {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = maybe_serialize( $value );
		}
		$text = (string) $value;
		if ( '' === $text ) {
			return false;
		}
		// A number stored under an image-like name is an attachment ID.
		if ( '' !== $key && preg_match( '/image|photo|picture|logo|icon|gallery|thumb|banner|background|(?:^|_)bg(?:_|$)|media|attachment|avatar|video|audio|poster|cover|document|pdf|download/i', $key ) && preg_match( '/\d/', $text ) ) {
			return true;
		}
		// Slashes may be JSON-escaped, which is how page builders store URLs.
		return (bool) preg_match( '~wp-image-\d|/uploads\\\\?/[^"\'\s<>()]+?\.(?:jpe?g|png|gif|webp|avif|svg|bmp|tiff?|ico|heic|mp4|m4v|mov|webm|ogv|mp3|m4a|wav|ogg|flac|pdf|docx?|xlsx?|pptx?|odt|ods|csv|zip|rtf|txt)\b~i', $text );
	}

	/**
	 * Whether saving this post could add a media reference.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function relevant_post( \WP_Post $post ): bool {
		if ( in_array( $post->post_type, array( 'revision', 'attachment', 'oembed_cache', 'customize_changeset', 'user_request', 'wp_navigation', 'nav_menu_item', 'scheduled-action' ), true ) ) {
			return false;
		}
		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return false;
		}
		return self::references_media( $post->post_content . ' ' . $post->post_excerpt );
	}

	/**
	 * Whether a metadata write could add a media reference.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value New value.
	 * @return bool
	 */
	public static function relevant_meta( string $key, $value ): bool {
		if ( str_starts_with( $key, '_smao_' ) || str_starts_with( $key, '_oembed_' ) || str_starts_with( $key, '_wp_attachment' ) || str_starts_with( $key, '_transient' ) ) {
			return false;
		}
		// Derived caches that page builders and WordPress rebuild on their own.
		$derived = array(
			'_wp_attached_file',
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_encloseme',
			'_pingme',
			'_wp_desired_post_slug',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_elementor_css',
			'_elementor_element_cache',
			'_elementor_page_assets',
			'_elementor_controls_usage',
			'_elementor_screenshot',
			'_elementor_screenshot_failed',
			'_elementor_inline_svg',
			'session_tokens',
		);
		if ( in_array( $key, $derived, true ) || preg_match( '/(?:^|_)user-settings(?:-time)?$/', $key ) ) {
			return false;
		}
		return self::references_media( $value, $key );
	}

	/**
	 * Whether an option write could add a media reference.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value New value.
	 * @return bool
	 */
	public static function relevant_option( string $name, $value ): bool {
		if ( str_starts_with( $name, 'smao_' ) || str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' ) || 'cron' === $name ) {
			return false;
		}
		if ( in_array( $name, Scanner::ignored_options(), true ) ) {
			return false;
		}
		// Options that hold bare attachment IDs under generic names.
		if ( in_array( $name, array( 'site_icon', 'site_logo' ), true ) || str_starts_with( $name, 'theme_mods_' ) || str_starts_with( $name, 'widget_' ) ) {
			return true;
		}
		return self::references_media( $value, $name );
	}

	/**
	 * Drop results for images that no longer exist in WordPress.
	 *
	 * Catches deletions that happened while the plugin was inactive or on a
	 * version without the delete hook, which otherwise stayed listed forever.
	 *
	 * @return void
	 */
	public static function forget_missing(): void {
		global $wpdb;
		if ( get_transient( 'smao_forget_missing' ) ) {
			return;
		}
		set_transient( 'smao_forget_missing', 1, MINUTE_IN_SECONDS );
		$table = Database::table( 'media' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table names.
		$gone = $wpdb->get_col( "SELECT m.attachment_id FROM $table m LEFT JOIN {$wpdb->posts} p ON p.ID = m.attachment_id WHERE p.ID IS NULL LIMIT 500" );
		foreach ( (array) $gone as $id ) {
			self::forget_attachment( (int) $id );
		}
	}

	/**
	 * Drop everything the plugin knows about an attachment being deleted.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	public static function forget_attachment( $id ): void {
		global $wpdb;
		$id = (int) $id;
		try {
			Vault::forget( $id );
		} catch ( \Throwable $e ) {
			Database::log( 'forget', $id, 'Recovery copy could not be removed: ' . $e->getMessage() );
		}
		foreach ( array( 'media', 'evidence', 'tokens', 'render', 'jobs' ) as $name ) {
			$wpdb->delete( Database::table( $name ), array( 'attachment_id' => $id ) );
		}
		Database::log( 'forget', $id, 'Image deleted in WordPress; removed from results.' );
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
		if ( ! in_array( $action, array( 'optimize', 'thumbnails', 'webp' ), true ) ) {
			throw new \RuntimeException( __( 'Unsupported background action.', 'smart-media-auditor-optimizer' ) ); }
		$table = Database::table( 'jobs' );
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
				throw new \RuntimeException( __( 'Attachment permission denied.', 'smart-media-auditor-optimizer' ) ); }
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
				throw new \RuntimeException( __( 'Requesting administrator no longer has permission.', 'smart-media-auditor-optimizer' ) ); }
			if ( 'optimize' === $job['action'] ) {
				Optimizer::optimize( (int) $job['attachment_id'] );
			} elseif ( 'webp' === $job['action'] ) {
				Optimizer::webp( (int) $job['attachment_id'] );
			} else {
				Optimizer::thumbnails( (int) $job['attachment_id'] );
			}
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
		// WebP copies are quick and independent: keep going while time allows,
		// so a whole library is done in minutes rather than one per visit.
		if ( 'webp' === $job['action'] ) {
			static $started = null;
			$started = $started ?? microtime( true );
			if ( microtime( true ) - $started < 8 ) {
				self::tick();
			}
		}
	}

}
