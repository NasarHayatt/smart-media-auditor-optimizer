<?php
/**
 * Admin component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Admin workflow and safety policy.
 */
final class Admin {
	private const PAGES = array(
		'dashboard'   => 'Dashboard',
		'scanner'     => 'Scan media',
		'review'      => 'Remove unused images',
		'optimizer'   => 'Optimize images',
		'performance' => 'Performance Recommendations',
		'quarantine'  => 'Recovery & permanent deletion',
		'log'         => 'Activity Log',
		'settings'    => 'Settings',
		'system'      => 'System Status',
	);

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_menu_page( __( 'Smart Media Auditor', 'smart-media-auditor-optimizer' ), __( 'Smart Media Auditor', 'smart-media-auditor-optimizer' ), 'manage_options', 'smao-dashboard', array( self::class, 'render' ), 'dashicons-format-gallery' );
				foreach ( self::PAGES as $slug => $title ) {
					add_submenu_page( 'smao-dashboard', I18n::text( $title ), I18n::text( $title ), 'manage_options', 'smao-' . $slug, array( self::class, 'render' ) );
				}
			}
		);
		add_action(
			'admin_enqueue_scripts',
			static function ( string $hook ): void {
				if ( ! str_contains( $hook, 'smao-' ) ) {
					return; }
				wp_enqueue_style( 'smao-admin', plugins_url( 'assets/admin.css', SMAO_FILE ), array(), SMAO_VERSION );
				wp_enqueue_script( 'smao-model', plugins_url( 'assets/model.js', SMAO_FILE ), array(), SMAO_VERSION, true );
				wp_enqueue_script( 'smao-admin', plugins_url( 'assets/admin.js', SMAO_FILE ), array( 'smao-model', 'wp-i18n' ), SMAO_VERSION, true );
				wp_set_script_translations( 'smao-admin', 'smart-media-auditor-optimizer' );
				wp_localize_script(
					'smao-admin',
					'smaoConfig',
					array(
						'root'  => esc_url_raw( rest_url( 'smao/v1/' ) ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
					)
				);
			}
		);
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_smao_export', array( self::class, 'export' ) );
	}

	/**
	 * Require administrator capabilities and a valid REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public static function permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! Plugin::allowed() || ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new \WP_Error( 'smao_forbidden', __( 'Administrator capability and a valid REST nonce are required.', 'smart-media-auditor-optimizer' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Register nonce-protected administrator REST routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		foreach ( array(
			'status'   => 'GET',
			'report'   => 'GET',
			'evidence' => 'GET',
			'runtime'  => 'POST',
			'storage'  => 'POST',
			'control'  => 'POST',
			'action'   => 'POST',
			'settings' => 'POST',
		) as $route => $method ) {
			register_rest_route(
				'smao/v1',
				'/' . $route,
				array(
					'methods'             => $method,
					'permission_callback' => array( self::class, 'permission' ),
					'callback'            => static function ( \WP_REST_Request $request ) use ( $route ) {
						try {
							if ( 'report' === $route ) {
								return rest_ensure_response( Live::report( $request->get_query_params() ) ); }
							if ( 'evidence' === $route ) {
								return rest_ensure_response( Live::evidence( absint( $request->get_param( 'id' ) ) ) ); }
							if ( 'status' === $route ) {
								return rest_ensure_response( self::status() ); }
							return rest_ensure_response(
								Database::lock(
									static function () use ( $request, $route ) {
										if ( 'storage' === $route ) {
											Vault::configure( (string) $request->get_param( 'path' ) );
											return self::status(); }
										if ( 'runtime' === $route ) {
											Settings::runtime( (array) $request->get_json_params() );
											return self::status(); }
										if ( 'settings' === $route ) {
											return Settings::save( (array) $request->get_json_params() ); }
										if ( 'control' === $route ) {
											$command = sanitize_key( (string) $request->get_param( 'command' ) );
											if ( 'review_scan' === $command ) {
												if ( true !== $request->get_param( 'scope_reviewed' ) ) {
													throw new \RuntimeException( __( 'Confirm the scope review before starting.', 'smart-media-auditor-optimizer' ) ); }
												$settings = Settings::get();
												$settings['coverage_reviewed'] = true;
												update_option( 'smao_settings', $settings, false );
												Scanner::control( 'restart' );
												return self::status();
											}
											if ( 'cleanup_records' === $command ) {
												if ( 'CLEANUP' !== $request->get_param( 'confirmation' ) ) {
													throw new \RuntimeException( __( 'Type CLEANUP to confirm removal of old plugin records.', 'smart-media-auditor-optimizer' ) ); }
												return array( 'removed' => Plugin::cleanup() );
											}
											if ( 'tick' === $command ) {
												Plugin::tick(); } elseif ( in_array( $command, array( 'pause_jobs', 'resume_jobs', 'cancel_jobs' ), true ) ) {
																global $wpdb;
												if ( 'cancel_jobs' === $command ) {
													Database::check( $wpdb->update( Database::table( 'jobs' ), array( 'state' => 'cancelled' ), array( 'state' => 'queued' ) ) ); } else {
													update_option( 'smao_jobs_paused', 'pause_jobs' === $command, false ); }
												} else {
													Scanner::control( $command ); }
												return self::status();
										}
										return self::action( $request );
									}
								)
							);
						} catch ( \Throwable $e ) {
							return new \WP_Error( 409 === $e->getCode() ? 'smao_busy' : 'smao_stopped', $e->getMessage(), array( 'status' => 409 ) ); }
					},
				)
			);
		}
	}

	/**
	 * Validate manual confirmation and execute selected attachment actions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	private static function action( \WP_REST_Request $request ): array {
		$action  = sanitize_key( (string) $request->get_param( 'action' ) );
		$allowed = array( 'quarantine', 'restore', 'purge', 'optimize', 'thumbnails' );
		if ( ! in_array( $action, $allowed, true ) ) {
			throw new \RuntimeException( I18n::text( 'Unsupported action.' ) ); }
		$raw = $request->get_param( 'ids' );
		if ( ! is_array( $raw ) || ! $raw || count( $raw ) > 50 ) {
			throw new \RuntimeException( I18n::text( 'Select between 1 and 50 attachments.' ) ); }
		foreach ( $raw as $candidate ) {
			if ( ( ! is_int( $candidate ) && ! is_string( $candidate ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $candidate ) ) {
				throw new \RuntimeException( __( 'Attachment IDs must be positive integers.', 'smart-media-auditor-optimizer' ) );
			}
		}
		$ids = array_values( array_unique( array_map( 'absint', $raw ) ) );
		if ( strtoupper( $action ) !== $request->get_param( 'confirmation' ) ) {
			throw new \RuntimeException( I18n::text( 'Explicit action confirmation is required.' ) ); }
		foreach ( $ids as $id ) {
			if ( ! $id || ! current_user_can( 'edit_post', $id ) || ( in_array( $action, array( 'quarantine', 'purge' ), true ) && ! current_user_can( 'delete_post', $id ) ) ) {
				throw new \RuntimeException( I18n::text( 'Attachment permission denied.' ) ); }
		}
		if ( in_array( $action, array( 'optimize', 'thumbnails' ), true ) ) {
			Plugin::enqueue( $action, $ids );
			return array( 'message' => 'Jobs queued. Progress appears on the optimizer screen.' ); }
		$results = array();
		foreach ( $ids as $id ) {
			try {
				if ( 'quarantine' === $action ) {
					Vault::quarantine( $id ); } elseif ( 'restore' === $action ) {
					Vault::restore( $id ); } else {
						Vault::purge( $id ); }
					$results[] = array(
						'id'      => $id,
						'ok'      => true,
						'message' => 'Completed.',
					);
			} catch ( \Throwable $e ) {
				$results[] = array(
					'id'      => $id,
					'ok'      => false,
					'message' => $e->getMessage(),
				);
				Database::log( $action . '_blocked', $id, $e->getMessage() );
			}
		}
		return array( 'results' => $results );
	}

	/**
	 * Read compact scan and queue progress for administrator polling.
	 *
	 * @return array
	 */
	public static function status(): array {
		global $wpdb;
		$table = Database::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$jobs = $wpdb->get_results( "SELECT id,attachment_id,action,state,message FROM $table ORDER BY id DESC LIMIT 20", ARRAY_A );
		Database::check( $jobs );
		$state          = Database::state();
		$state['stale'] = isset( $state['epoch'] ) && get_option( 'smao_epoch', '' ) !== $state['epoch'];
		return array(
			'scan'        => $state,
			'jobs'        => $jobs,
			'jobs_paused' => (bool) get_option( 'smao_jobs_paused', false ),
			'live'        => Live::snapshot(),
		);
	}

	/**
	 * Build a prepared report predicate with whitelisted ordering.
	 *
	 * @param array $input Input.
	 * @param int   $after After.
	 * @return array
	 */
	public static function query( array $input, int $after = -1 ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		$scan  = Database::state();
		if ( isset( $scan['id'] ) ) {
			$where[] = 'scan_id=%s';
			$args[]  = $scan['id']; }
		$status = sanitize_key( (string) ( $input['status'] ?? '' ) );
		if ( in_array( $status, array( 'used', 'possible', 'unused', 'broken', 'external', 'download', 'quarantined' ), true ) ) {
			$where[] = 'status=%s';
			$args[]  = $status; }
		if ( isset( $input['optimized'] ) && in_array( (string) $input['optimized'], array( '0', '1' ), true ) ) {
			$where[] = 'optimized=%d';
			$args[]  = (int) $input['optimized']; }
		if ( ! empty( $input['mime'] ) ) {
			$where[] = 'mime LIKE %s';
			$args[]  = $wpdb->esc_like( sanitize_text_field( $input['mime'] ) ) . '%'; }
		if ( ! empty( $input['search'] ) ) {
			$where[] = '(filename LIKE %s OR attachment_id=%d)';
			$args[]  = '%' . $wpdb->esc_like( sanitize_text_field( $input['search'] ) ) . '%';
			$args[]  = absint( $input['search'] ); }
		foreach ( array(
			'from'  => '>=',
			'until' => '<=',
		) as $key => $operator ) {
			if ( ! empty( $input[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $input[ $key ] ) ) {
				$where[] = "uploaded $operator %s";
				$args[]  = $input[ $key ] . ( 'from' === $key ? ' 00:00:00' : ' 23:59:59' ); }
		}
		foreach ( array(
			'min_bytes' => '>=',
			'max_bytes' => '<=',
		) as $key => $operator ) {
			if ( isset( $input[ $key ] ) && '' !== (string) $input[ $key ] ) {
				$where[] = "bytes $operator %d";
				$args[]  = absint( $input[ $key ] ); }
		}
		if ( $after >= 0 ) {
			$where[] = 'attachment_id>%d';
			$args[]  = $after; }
		$sort      = in_array( $input['sort'] ?? '', array( 'attachment_id', 'filename', 'bytes', 'uploaded', 'status', 'saved' ), true ) ? $input['sort'] : 'attachment_id';
		$direction = 'desc' === ( $input['direction'] ?? '' ) ? 'DESC' : 'ASC';
		$sql       = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$sql = $args ? $wpdb->prepare( $sql, $args ) : $sql;
		return array( $sql, $after >= 0 ? 'attachment_id ASC' : "$sort $direction, attachment_id ASC" );
	}

	/**
	 * Render the selected administrator screen with escaped content.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! Plugin::allowed() ) {
			wp_die( esc_html__( 'Administrator access required.', 'smart-media-auditor-optimizer' ) ); }
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? 'smao-dashboard' ) );
		$slug = str_replace( 'smao-', '', $page );
		if ( ! isset( self::PAGES[ $slug ] ) ) {
			return; }
		echo '<div class="wrap smao" data-screen="' . esc_attr( $slug ) . '"><header class="smao-app-header"><div class="smao-brand-mark" aria-hidden="true">M</div><div><span class="smao-eyebrow">' . esc_html__( 'MEDIA INTELLIGENCE', 'smart-media-auditor-optimizer' ) . '</span><h1>' . esc_html__( 'Smart Media Auditor', 'smart-media-auditor-optimizer' ) . '</h1></div><span class="smao-version">v' . esc_html( SMAO_VERSION ) . ' · PHP ' . esc_html( PHP_VERSION ) . '</span></header><nav class="smao-app-nav" aria-label="' . esc_attr__( 'Media workspace', 'smart-media-auditor-optimizer' ) . '">';
		foreach ( self::PAGES as $key => $title ) {
			echo '<a ' . ( $key === $slug ? 'aria-current="page"' : '' ) . ' href="' . esc_url( admin_url( 'admin.php?page=smao-' . $key ) ) . '">' . esc_html( I18n::text( $title ) ) . '</a>'; }
		echo '</nav><div class="smao-page-heading"><div><p class="smao-eyebrow">' . esc_html__( 'YOUR MEDIA WORKSPACE', 'smart-media-auditor-optimizer' ) . '</p><h2>' . esc_html( I18n::text( self::PAGES[ $slug ] ) ) . '</h2></div><span class="smao-safe-tag">' . esc_html__( 'Read-only scans · Recovery-first actions', 'smart-media-auditor-optimizer' ) . '</span></div>';
		echo '<div id="smao-notice" role="status" aria-live="polite"></div>';
		echo '<details class="smao-safety"><summary>' . esc_html__( 'Your originals stay protected. Review before making changes.', 'smart-media-auditor-optimizer' ) . '</summary><p>' . esc_html__( 'Scans do not change files. Unused means a candidate within reviewed scope. Back up the database and uploads before file actions. Protected and offloaded files cannot be quarantined.', 'smart-media-auditor-optimizer' ) . '</p></details>';

		try {
			if ( 'settings' === $slug ) {
				self::settings(); } elseif ( 'system' === $slug ) {
				self::system(); } elseif ( 'log' === $slug ) {
					self::log(); } elseif ( 'quarantine' === $slug ) {
					self::quarantine(); } elseif ( 'performance' === $slug ) {
							self::performance(); } else {
								Workspace::flow();
						if ( 'review' === $slug ) {
							Workspace::cleanup();
						} else {
							self::progress(); }
						if ( 'dashboard' === $slug ) {
							self::dashboard(); } else {
							if ( 'optimizer' === $slug ) {
								echo '<p>' . esc_html__( 'Originals are backed up before compression. Lossless mode requires ImageMagick and rejects changed pixels; lossless JPEG recompression is unavailable. Alternate formats keep the existing URL as a fallback. Savings exclude backups and derivatives.', 'smart-media-auditor-optimizer' ) . '</p>';
								self::job_controls();
							}
							self::listing( $slug );
							}
							}
		} catch ( \Throwable $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>'; }
		echo '</div>';
	}

	/**
	 * Render a translated scan-control button.
	 *
	 * @param string $command Command.
	 * @param string $label Label.
	 * @return void
	 */
	private static function button( string $command, string $label ): void {
		echo '<button type="button" class="button" data-command="' . esc_attr( $command ) . '">' . esc_html( I18n::text( $label ) ) . '</button> ';
	}

	/**
	 * Render read-only scan progress and explicit controls.
	 *
	 * @return void
	 */
	private static function progress(): void {
		Workspace::scan();
	}

	/**
	 * Render image-queue controls and progress.
	 *
	 * @return void
	 */
	private static function job_controls(): void {
		echo '<section class="smao-queue-card"><div class="smao-section-heading"><div><span class="smao-eyebrow">' . esc_html__( 'IMAGE PROCESSING', 'smart-media-auditor-optimizer' ) . '</span><h2>' . esc_html__( 'Optimization queue', 'smart-media-auditor-optimizer' ) . '</h2></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=smao-settings' ) ) . '">' . esc_html__( 'Compression settings', 'smart-media-auditor-optimizer' ) . '</a></div><div id="smao-queue-counts" class="smao-queue-counts"></div><div class="smao-controls">';
		foreach ( array(
			'pause_jobs'  => 'Pause image queue',
			'resume_jobs' => 'Resume image queue',
			'cancel_jobs' => 'Cancel queued image jobs',
			'tick'        => 'Process next job now',
		) as $command => $label ) {
			self::button( $command, $label ); }
		echo '</div><div id="smao-jobs" class="smao-jobs"></div></section>';
	}

	/**
	 * Render cached media counts and clearly scoped storage figures.
	 *
	 * @return void
	 */
	private static function dashboard(): void {
		echo '<p class="smao-muted">' . esc_html__( 'Live totals describe this scan. Reference counts include ambiguous matches. Unused candidates are final only after all scan phases complete. Savings exclude recovery copies and alternate files.', 'smart-media-auditor-optimizer' ) . '</p>';
		self::listing( 'scanner' );
	}

	/**
	 * Render a filtered and paginated attachment report.
	 *
	 * @param string $slug Slug.
	 * @return void
	 */
	private static function listing( string $slug ): void {
		global $wpdb;
		$input = wp_unslash( $_GET );
		if ( 'review' === $slug && ! isset( $input['status'] ) ) {
			$input['status'] = 'unused'; }
		if ( in_array( $slug, array( 'optimizer', 'review' ), true ) && ! isset( $input['mime'] ) ) {
			$input['mime'] = 'image/'; }
		echo '<section class="smao-results"><div class="smao-section-heading"><h2>' . esc_html__( 'Media explorer', 'smart-media-auditor-optimizer' ) . '</h2><span id="smao-result-count" class="smao-muted"></span></div><form method="get" class="smao-filters"><input type="hidden" name="page" value="' . esc_attr( 'smao-' . $slug ) . '">';
		foreach ( array(
			'search'    => 'Filename or ID',
			'mime'      => 'MIME type prefix',
			'from'      => 'Uploaded from',
			'until'     => 'Uploaded until',
			'min_bytes' => 'Minimum bytes',
			'max_bytes' => 'Maximum bytes',
		) as $key => $label ) {
			$type = in_array( $key, array( 'from', 'until' ), true ) ? 'date' : ( str_contains( $key, 'bytes' ) ? 'number' : 'search' );
			echo '<label>' . esc_html( I18n::text( $label ) ) . '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $input[ $key ] ?? '' ) . '"></label>';
		}
		self::select(
			'status',
			'Usage status',
			array(
				''            => 'All statuses',
				'used'        => 'Used',
				'possible'    => 'Possibly used',
				'unused'      => 'Unused candidates',
				'broken'      => 'Broken',
				'external'    => 'External / offloaded',
				'download'    => 'Download',
				'quarantined' => 'Quarantined',
			),
			$input['status'] ?? ''
		);
		self::select(
			'optimized',
			'Optimization',
			array(
				''  => 'All',
				'1' => 'Optimized',
				'0' => 'Unoptimized',
			),
			$input['optimized'] ?? ''
		);
		self::select(
			'sort',
			'Sort by',
			array(
				'attachment_id' => 'ID',
				'filename'      => 'Filename',
				'bytes'         => 'Bytes',
				'uploaded'      => 'Upload date',
				'status'        => 'Status',
				'saved'         => 'Savings',
			),
			$input['sort'] ?? ''
		);
		self::select(
			'direction',
			'Direction',
			array(
				'asc'  => 'Ascending',
				'desc' => 'Descending',
			),
			$input['direction'] ?? ''
		);
		echo '<button class="button">' . esc_html__( 'Filter', 'smart-media-auditor-optimizer' ) . '</button></form>';
		$export = add_query_arg(
			array_merge(
				array_intersect_key( $input, array_flip( array( 'status', 'mime', 'optimized', 'search', 'from', 'until', 'min_bytes', 'max_bytes' ) ) ),
				array(
					'action'   => 'smao_export',
					'_wpnonce' => wp_create_nonce( 'smao_export' ),
				)
			),
			admin_url( 'admin-post.php' )
		);
		echo '<p><a id="smao-export" class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export matching rows as CSV', 'smart-media-auditor-optimizer' ) . '</a></p>';
		list( $where, $order ) = self::query( $input );
		$table                 = Database::table( 'media' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		$page  = max( 1, absint( $input['paged'] ?? 1 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY $order LIMIT 25 OFFSET %d", ( $page - 1 ) * 25 ), ARRAY_A );
		Database::check( $rows );
		echo '<div class="smao-table"><table class="widefat striped"><thead><tr><th><input type="checkbox" id="smao-select-all" aria-label="' . esc_attr__( 'Select all on this page', 'smart-media-auditor-optimizer' ) . '"></th>';
		foreach ( array( 'Media', 'Size / dimensions', 'Uploaded', 'Usage', 'Optimization', 'Evidence and reason' ) as $label ) {
			echo '<th scope="col">' . esc_html( I18n::text( $label ) ) . '</th>'; }
		echo '</tr></thead><tbody id="smao-report-body">';
		foreach ( $rows as $row ) {
			$id = (int) $row['attachment_id'];
			// translators: %d is the attachment ID selected in the media report.
			echo '<tr><td><input class="smao-id" type="checkbox" value="' . esc_attr( $id ) . '" aria-label="' . esc_attr( sprintf( __( 'Select attachment %d', 'smart-media-auditor-optimizer' ), $id ) ) . '"></td><td>';
			$url = wp_mime_type_icon( $id );
			if ( 'quarantined' !== $row['status'] ) {
				$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
				$url   = $thumb ? $thumb : $url; }
			echo '<img loading="lazy" width="48" height="48" class="smao-thumb" src="' . esc_url( $url ) . '" alt=""><strong>' . esc_html( $row['filename'] ) . '</strong><br>#' . esc_html( $id ) . ' · ' . esc_html( $row['mime'] ) . '</td><td>' . esc_html( size_format( $row['bytes'] ) ) . '<br>' . esc_html( $row['width'] . ' × ' . $row['height'] ) . '</td><td>' . esc_html( $row['uploaded'] ) . ' UTC</td><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( $row['optimized'] ? __( 'Optimized', 'smart-media-auditor-optimizer' ) : __( 'Unoptimized', 'smart-media-auditor-optimizer' ) ) . '<br>' . esc_html( size_format( $row['saved'] ) ) . ' ' . esc_html__( 'saved', 'smart-media-auditor-optimizer' ) . '</td><td><details><summary>' . esc_html__( 'Review evidence', 'smart-media-auditor-optimizer' ) . '</summary><p>' . esc_html( $row['reason'] ) . '</p>';
			$evidence = Database::table( 'evidence' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
			$locations = $wpdb->get_results( $wpdb->prepare( "SELECT source,source_id,field,strength,kind FROM $evidence WHERE attachment_id=%d ORDER BY strength DESC,id LIMIT 100", $id ), ARRAY_A );
			foreach ( $locations as $location ) {
				echo '<p>' . esc_html( $location['source'] . ' #' . $location['source_id'] . ' / ' . $location['field'] . ' — ' . ( 2 === (int) $location['strength'] ? 'strong' : 'possible' ) . ' / ' . $location['kind'] ) . '</p>'; }
			if ( ! $locations ) {
				echo '<p>' . esc_html__( 'No indexed reference locations. External inbound references cannot be established by this scan.', 'smart-media-auditor-optimizer' ) . '</p>'; }
			echo '</details></td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No matching inventory. Run a scan or adjust filters.', 'smart-media-auditor-optimizer' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		echo '<div id="smao-pagination">';
		self::pagination( $page, $total );
		echo '</div>';
		self::bulk(
			'optimizer' === $slug ? array(
				'optimize'   => 'Optimize selected',
				'thumbnails' => 'Generate missing thumbnails',
				'restore'    => 'Restore originals',
			) : array( 'quarantine' => 'Remove selected (keep recovery copy)' )
		);
		echo '</section>';
	}

	/**
	 * Render an accessible translated select control.
	 *
	 * @param string $name Name.
	 * @param string $label Label.
	 * @param array  $options Options.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private static function select( string $name, string $label, array $options, mixed $value ): void {
		echo '<label>' . esc_html( I18n::text( $label ) ) . '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( I18n::text( $text ) ) . '</option>'; }
		echo '</select></label>';
	}

	/**
	 * Render bounded report-page navigation.
	 *
	 * @param int $page Page.
	 * @param int $total Total.
	 * @return void
	 */
	private static function pagination( int $page, int $total ): void {
		// translators: %d is the number of matching report records.
		echo '<p>' . esc_html( sprintf( __( '%d matching records', 'smart-media-auditor-optimizer' ), $total ) ) . '</p><nav aria-label="' . esc_attr__( 'Report pages', 'smart-media-auditor-optimizer' ) . '">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $page,
					'total'   => max( 1, (int) ceil( $total / 25 ) ),
				)
			)
		);
		echo '</nav>';
	}

	/**
	 * Render manual review acknowledgement and action controls.
	 *
	 * @param array $actions Actions.
	 * @return void
	 */
	private static function bulk( array $actions ): void {
		echo '<div class="smao-controls smao-bulk-bar"><strong id="smao-selection-count">' . esc_html__( 'No files selected', 'smart-media-auditor-optimizer' ) . '</strong><label><input type="checkbox" id="smao-reviewed"> ' . esc_html__( 'I reviewed these IDs, their usage and recovery requirements and have an independent backup.', 'smart-media-auditor-optimizer' ) . '</label><br>';
		foreach ( $actions as $action => $label ) {
			echo '<button type="button" class="button" data-action="' . esc_attr( $action ) . '">' . esc_html( I18n::text( $label ) ) . '</button> '; }
		echo '</div>';
	}

	/**
	 * Move a reviewed local media group into recoverable storage.
	 *
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	private static function quarantine(): void {
		global $wpdb;
		$table = Database::table( 'vault' );
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id,operation,state,created FROM $table ORDER BY created DESC LIMIT 25 OFFSET %d", ( $page - 1 ) * 25 ), ARRAY_A );
		Database::check( $rows );
		echo '<p>' . esc_html__( 'Pending or interrupted actions retain recovery journals. Restore originals before retrying optimization. Permanent purge removes only private quarantine backups after retention; it keeps the attachment database record for audit. Nothing is purged automatically.', 'smart-media-auditor-optimizer' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Select', 'smart-media-auditor-optimizer' ) . '</th><th>ID</th><th>' . esc_html__( 'Operation', 'smart-media-auditor-optimizer' ) . '</th><th>' . esc_html__( 'State', 'smart-media-auditor-optimizer' ) . '</th><th>' . esc_html__( 'Created (UTC)', 'smart-media-auditor-optimizer' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			// translators: %d is the attachment ID selected in the media report.
			echo '<tr><td><input class="smao-id" type="checkbox" value="' . esc_attr( $row['attachment_id'] ) . '" aria-label="' . esc_attr( 'Select ' . $row['attachment_id'] ) . '"></td><td>' . esc_html( $row['attachment_id'] ) . '</td><td>' . esc_html( $row['operation'] ) . '</td><td>' . esc_html( $row['state'] ) . '</td><td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $row['created'] ) ) . '</td></tr>'; }
		echo '</tbody></table>';
		self::pagination( $page, $total );
		self::bulk(
			array(
				'restore' => 'Restore selected',
				'purge'   => 'Permanently purge retained backups',
			)
		);
	}

	/**
	 * Append a user-attributed event to the activity log.
	 *
	 * @return void
	 */
	private static function log(): void {
		global $wpdb;
		$table = Database::table( 'log' );
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT 25 OFFSET %d", ( $page - 1 ) * 25 ), ARRAY_A );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'UTC date', 'User ID', 'Attachment', 'Action', 'Message' ) as $label ) {
			echo '<th>' . esc_html( I18n::text( $label ) ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( 'created', 'user_id', 'attachment_id', 'action', 'message' ) as $key ) {
				echo '<td>' . esc_html( $row[ $key ] ) . '</td>';
			} echo '</tr>'; }
		echo '</tbody></table>';
		self::pagination( $page, $total );
	}

	/**
	 * Render the opt-in settings form.
	 *
	 * @return void
	 */
	private static function settings(): void {
		Workspace::storage();
		$s = Settings::get();
		echo '<p class="smao-muted">' . esc_html__( 'Fine-tune image quality, delivery and protection. Processing controls are available directly on the scan dashboard.', 'smart-media-auditor-optimizer' ) . '</p><form id="smao-settings" class="smao-settings">';
		foreach ( array( 'source_batch', 'time_budget', 'poll_interval', 'browser_worker' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (int) $s[ $key ] ) . '">'; }
		foreach ( array(
			'batch'          => 'Batch size (1–200)',
			'recent_days'    => 'Protect uploads younger than days (minimum 7)',
			'retention_days' => 'Minimum quarantine retention in days (minimum 7)',
			'quality'        => 'Lossy quality (50–100)',
			'max_width'      => 'Maximum image width',
			'max_height'     => 'Maximum image height',
			'preload_id'     => 'Front-page primary image ID to preload (0 disables)',
		) as $key => $label ) {
			echo '<label>' . esc_html( I18n::text( $label ) ) . '<input type="number" name="' . esc_attr( $key ) . '" value="' . esc_attr( $s[ $key ] ) . '"></label>';
		}
		self::select(
			'schedule',
			'Scheduled read-only scans',
			array(
				'off'    => 'Off',
				'daily'  => 'Daily',
				'weekly' => 'Weekly',
			),
			$s['schedule']
		);
		self::select(
			'compression',
			'Compression',
			array(
				'lossless' => 'Lossless (pixel verified; no JPEG)',
				'lossy'    => 'Lossy (manual visual review required)',
			),
			$s['compression']
		);
		self::select(
			'alternate',
			'Generate alternate format',
			array(
				'off'  => 'Off',
				'webp' => 'WebP',
				'avif' => 'AVIF (server dependent)',
			),
			$s['alternate']
		);
		foreach ( array(
			'coverage_reviewed' => 'I reviewed custom code, private tables and external consumers; allow unmatched files to become unused candidates.',
			'resize'            => 'Resize oversized full-size images (lossy mode only; may affect cropping/layout)',
			'strip_exif'        => 'Remove EXIF metadata (ICC color profile retained where supported)',
			'auto_optimize'     => 'Queue optimization for new images uploaded by administrators',
			'delivery'          => 'Deliver alternate formats using picture sources and the original img fallback',
		) as $key => $label ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $s[ $key ], true, false ) . '> ' . esc_html( I18n::text( $label ) ) . '</label>';
		}
		echo '<label>' . esc_html__( 'Exclusions: one rule per line: id:123, type:application/pdf, path:2026/protected/, name:logo.png', 'smart-media-auditor-optimizer' ) . '<textarea rows="6" name="exclusions">' . esc_textarea( $s['exclusions'] ) . '</textarea></label><fieldset><legend>' . esc_html__( 'Disable future generation of selected image sizes. Existing sizes are retained.', 'smart-media-auditor-optimizer' ) . '</legend>';
		foreach ( get_intermediate_image_sizes() as $size ) {
			echo '<label><input type="checkbox" name="disabled_sizes[]" value="' . esc_attr( $size ) . '" ' . checked( in_array( $size, $s['disabled_sizes'], true ), true, false ) . '> ' . esc_html( $size ) . '</label>'; }
		echo '</fieldset><button class="button button-primary">' . esc_html__( 'Save settings', 'smart-media-auditor-optimizer' ) . '</button></form>';
		echo '<h3>' . esc_html__( 'Plugin record maintenance', 'smart-media-auditor-optimizer' ) . '</h3><p>' . esc_html__( 'Remove up to 500 old logs and 500 finished jobs older than 90 days per click. Media, core metadata and recovery records are preserved.', 'smart-media-auditor-optimizer' ) . '</p><button type="button" class="button" id="smao-cleanup">' . esc_html__( 'Clean old plugin records', 'smart-media-auditor-optimizer' ) . '</button>';
	}

	/**
	 * Render server capabilities and recovery-storage status.
	 *
	 * @return void
	 */
	private static function system(): void {
		global $wp_version;
		$items = array(
			'WordPress'        => $wp_version,
			'PHP'              => PHP_VERSION,
			'Multisite'        => is_multisite(),
			'Memory limit'     => ini_get( 'memory_limit' ),
			'WP-Cron disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
		$items = array_merge( $items, Optimizer::capabilities() );
		try {
			Vault::root();
			$items['Private vault'] = 'Configured';
		} catch ( \Throwable $e ) {
			$items['Private vault'] = $e->getMessage(); }
		echo '<dl class="smao-system">';
		foreach ( $items as $key => $value ) {
			echo '<dt>' . esc_html( $key ) . '</dt><dd>' . esc_html( is_bool( $value ) ? ( $value ? 'Yes' : 'No' ) : $value ) . '</dd>'; }
		echo '</dl><p>' . esc_html__( 'No telemetry, remote scans or external optimization services are used. Offloaded media is report-only. Configure a real server cron if site traffic is too low for WP-Cron.', 'smart-media-auditor-optimizer' ) . '</p>';
	}

	/**
	 * Report oversized source dimensions and safe performance guidance.
	 *
	 * @return void
	 */
	private static function performance(): void {
		global $wpdb;
		$s     = Settings::get();
		$table = Database::table( 'media' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id,filename,width,height,bytes FROM $table WHERE width>%d OR height>%d ORDER BY bytes DESC LIMIT 50", $s['max_width'], $s['max_height'] ), ARRAY_A );
		echo '<ul><li>' . esc_html__( 'WordPress native lazy loading, dimensions, srcset and sizes are preserved. The plugin does not force lazy loading on the likely primary image.', 'smart-media-auditor-optimizer' ) . '</li><li>' . esc_html__( 'Enable alternate format delivery only after testing theme output. It applies to wp_get_attachment_image; stored HTML and CSS are unchanged.', 'smart-media-auditor-optimizer' ) . '</li><li>' . esc_html__( 'Actual displayed dimensions require a browser inspection at representative viewport sizes. This report identifies source dimensions only; it does not pretend to measure CSS layout.', 'smart-media-auditor-optimizer' ) . '</li><li>' . esc_html__( 'Use the optimizer to generate missing thumbnails without deleting existing sizes. Disable only sizes that your theme and plugins do not need.', 'smart-media-auditor-optimizer' ) . '</li></ul><h3>' . esc_html__( 'Largest oversized sources (up to 50)', 'smart-media-auditor-optimizer' ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>#' . esc_html( $row['attachment_id'] ) . '</td><td>' . esc_html( $row['filename'] ) . '</td><td>' . esc_html( $row['width'] . ' × ' . $row['height'] ) . '</td><td>' . esc_html( size_format( $row['bytes'] ) ) . '</td></tr>'; }
		echo '</tbody></table>';
	}

	/**
	 * Stream filtered, formula-safe CSV rows in primary-key order.
	 *
	 * @return void
	 */
	public static function export(): void {
		if ( ! Plugin::allowed() ) {
			wp_die( esc_html__( 'Forbidden.', 'smart-media-auditor-optimizer' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'smao_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="smart-media-audit.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$output  = fopen( 'php://output', 'w' );
		$columns = array( 'attachment_id', 'filename', 'mime', 'uploaded', 'bytes', 'width', 'height', 'status', 'optimized', 'saved', 'reason', 'locations' );
		fputcsv( $output, $columns, ',', '"', '' );
		global $wpdb;
		$table  = Database::table( 'media' );
		$cursor = 0;
		do {
			list( $where, $order ) = self::query( wp_unslash( $_GET ), $cursor );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
			$rows = $wpdb->get_results( "SELECT * FROM $table WHERE $where ORDER BY $order LIMIT 100", ARRAY_A );
			Database::check( $rows );
			foreach ( $rows as $row ) {
				$evidence = Database::table( 'evidence' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
				$row['locations'] = wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT source,source_id,field,strength,kind FROM $evidence WHERE attachment_id=%d LIMIT 100", $row['attachment_id'] ), ARRAY_A ) );
				$line             = array();
				foreach ( $columns as $column ) {
					$line[] = Matcher::csv( $row[ $column ] ); }
				fputcsv( $output, $line, ',', '"', '' );
				$cursor = (int) $row['attachment_id'];
			}
			$batch_count = count( $rows );
		} while ( 100 === $batch_count && ! connection_aborted() );
		fclose( $output );
		exit;
	}
}
