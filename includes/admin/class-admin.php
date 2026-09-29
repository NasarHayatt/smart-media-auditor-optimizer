<?php
/**
 * Admin router, REST endpoints and CSV export.
 *
 * Screens own their markup; this class only decides which one runs and serves
 * the endpoints they depend on.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Administrator entry point.
 */
final class Admin {

	/**
	 * Screen slug to menu label.
	 *
	 * @return array<string,string>
	 */
	public static function screens(): array {
		return array(
			'home'     => __( 'Overview', 'smart-media-auditor-optimizer' ),
			'cleanup'  => __( 'Clean up', 'smart-media-auditor-optimizer' ),
			'speed'    => __( 'Speed', 'smart-media-auditor-optimizer' ),
			'advanced' => __( 'Advanced', 'smart-media-auditor-optimizer' ),
		);
	}

	/**
	 * Register WordPress hooks for this component.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_smao_export', array( self::class, 'export' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 100 );
		add_action( 'admin_notices', array( self::class, 'remeasure_notice' ) );
	}

	/**
	 * Say so when a site change cleared the page measurements that holding
	 * scripts and background stylesheets depend on, anywhere in the admin.
	 *
	 * @return void
	 */
	public static function remeasure_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && str_contains( (string) $screen->id, 'smao-speed' ) ) {
			return; // The Speed screen measures again by itself.
		}
		$forgot   = get_option( Styles::FORGOT );
		$settings = Settings::get();
		if ( ! is_array( $forgot ) || Styles::pages() || ! ( $settings['delay_all'] || $settings['async_css'] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: reason. */
					__( 'Media Auditor: your pages load without the full speed-up because %s, which cleared the page measurements.', 'smart-media-auditor-optimizer' ),
					(string) $forgot['reason']
				)
			),
			esc_url( admin_url( 'admin.php?page=smao-speed' ) ),
			esc_html__( 'Open Speed to measure again (a few minutes)', 'smart-media-auditor-optimizer' )
		);
	}

	/**
	 * Register the single menu and its four screens.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_menu_page(
			__( 'Smart Media Auditor', 'smart-media-auditor-optimizer' ),
			__( 'Media Auditor', 'smart-media-auditor-optimizer' ),
			'manage_options',
			'smao-home',
			array( self::class, 'render' ),
			'dashicons-format-gallery',
			81
		);
		foreach ( self::screens() as $slug => $label ) {
			add_submenu_page( 'smao-home', $label, $label, 'manage_options', 'smao-' . $slug, array( self::class, 'render' ) );
		}
	}

	/**
	 * Surface the delivery kill switch in the toolbar when speed is active.
	 *
	 * @param \WP_Admin_Bar $bar Toolbar.
	 * @return void
	 */
	public static function admin_bar( $bar ): void {
		if ( ! Plugin::allowed() || ! Settings::get()['speed_enabled'] ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'smao-speed',
				'title' => __( 'Speed: on', 'smart-media-auditor-optimizer' ),
				'href'  => admin_url( 'admin.php?page=smao-speed' ),
				'meta'  => array( 'title' => __( 'Media Auditor speed corrections are active', 'smart-media-auditor-optimizer' ) ),
			)
		);
	}

	/**
	 * Load admin assets only on this plugin's screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'smao-' ) ) {
			return;
		}
		wp_enqueue_style( 'smao-admin', plugins_url( 'assets/admin.css', SMAO_FILE ), array(), SMAO_VERSION );
		wp_register_script( 'smao-model', plugins_url( 'assets/model.js', SMAO_FILE ), array(), SMAO_VERSION, true );
		wp_enqueue_script( 'smao-admin', plugins_url( 'assets/admin.js', SMAO_FILE ), array( 'smao-model', 'wp-i18n' ), SMAO_VERSION, true );
		wp_set_script_translations( 'smao-admin', 'smart-media-auditor-optimizer' );
		if ( str_contains( $hook, 'smao-speed' ) ) {
			$current   = Settings::get();
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$home_pre  = Styles::pages()[ Styles::key( $home_path ) ]['prebuild'] ?? null;
			wp_enqueue_script( 'smao-psi', plugins_url( 'assets/psi.js', SMAO_FILE ), array( 'smao-admin', 'wp-i18n' ), SMAO_VERSION, true );
			wp_set_script_translations( 'smao-psi', 'smart-media-auditor-optimizer' );
			wp_localize_script(
				'smao-psi',
				'smaoPsi',
				array(
					'home'       => home_url( '/' ),
					'current'    => array_intersect_key( $current, array_flip( Settings::TESTABLE ) ),
					'candidates' => array(
						array(
							'flag'   => 'delay_all',
							'label'  => __( 'Hold back all scripts until the page has appeared', 'smart-media-auditor-optimizer' ),
							// Why switching it on changes nothing, when the home page did not pass.
							'reason' => is_array( $home_pre ) && 'ready' !== ( $home_pre['status'] ?? '' )
								? __( 'Not used: the home page did not pass the look-the-same check (see "Worth testing first")', 'smart-media-auditor-optimizer' )
								: ( ! is_array( $home_pre ) ? __( 'Not used: measure your pages first', 'smart-media-auditor-optimizer' ) : '' ),
						),
						array(
							'flag'  => 'defer_js',
							'label' => __( 'Stop other scripts blocking the page', 'smart-media-auditor-optimizer' ),
						),
						array(
							'flag'  => 'async_css',
							'label' => __( 'Stop stylesheets blocking the first paint', 'smart-media-auditor-optimizer' ),
						),
					),
				)
			);
			wp_register_script( 'smao-critical', plugins_url( 'assets/critical.js', SMAO_FILE ), array(), SMAO_VERSION, true );
			wp_register_script( 'smao-prebuild', plugins_url( 'assets/prebuild.js', SMAO_FILE ), array( 'smao-critical' ), SMAO_VERSION, true );
			wp_enqueue_script( 'smao-measure', plugins_url( 'assets/measure.js', SMAO_FILE ), array( 'smao-admin', 'smao-critical', 'smao-prebuild', 'wp-i18n' ), SMAO_VERSION, true );
			wp_set_script_translations( 'smao-measure', 'smart-media-auditor-optimizer' );
			wp_localize_script(
				'smao-measure',
				'smaoMeasure',
				array(
					'targets'   => Measure::targets(),
					'viewports' => Measure::VIEWPORTS,
				)
			);
		}
		wp_localize_script(
			'smao-admin',
			'smaoConfig',
			array(
				'root'  => esc_url_raw( rest_url( 'smao/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Require administrator capabilities and a valid REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public static function permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! Plugin::allowed() || ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new \WP_Error(
				'smao_forbidden',
				__( 'Administrator capability and a valid REST nonce are required.', 'smart-media-auditor-optimizer' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Register nonce-protected administrator REST routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$routes = array(
			'status'   => 'GET',
			'digest'   => 'GET',
			'fragment' => 'GET',
			'evidence' => 'GET',
			'control'  => 'POST',
			'action'   => 'POST',
			'settings' => 'POST',
			'runtime'  => 'POST',
			'storage'  => 'POST',
			'measure'  => 'POST',
			'critical' => 'POST',
			'psi'      => 'POST',
		);
		foreach ( $routes as $route => $method ) {
			register_rest_route(
				'smao/v1',
				'/' . $route,
				array(
					'methods'             => $method,
					'permission_callback' => array( self::class, 'permission' ),
					'callback'            => static function ( \WP_REST_Request $request ) use ( $route ) {
						return self::dispatch( $route, $request );
					},
				)
			);
		}
	}

	/**
	 * Execute one REST route with uniform error handling.
	 *
	 * @param string           $route   Route name.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function dispatch( string $route, \WP_REST_Request $request ) {
		try {
			// Reads need no lock.
			if ( 'status' === $route ) {
				return rest_ensure_response( self::status() );
			}
			if ( 'digest' === $route ) {
				return rest_ensure_response( Live::digest( (array) $request->get_query_params() ) );
			}
			if ( 'fragment' === $route ) {
				$params = (array) $request->get_query_params();
				$screen = self::screen_of( (string) ( $params['screen'] ?? 'advanced' ) );
				return rest_ensure_response(
					array(
						'html'   => Report_Table::fragment( $screen, $params ),
						'digest' => Live::digest( $params ),
					)
				);
			}
			if ( 'evidence' === $route ) {
				return rest_ensure_response( Live::evidence( absint( $request->get_param( 'id' ) ) ) );
			}

			if ( 'psi' === $route ) {
				// The last Google check, for display after a reload.
				$rows = array();
				foreach ( array_slice( (array) ( $request->get_json_params()['results'] ?? array() ), 0, 12 ) as $row ) {
					$rows[] = array(
						'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
						'score' => isset( $row['score'] ) ? (int) $row['score'] : null,
						'lcp'   => isset( $row['lcp'] ) ? (int) $row['lcp'] : null,
						'best'  => ! empty( $row['best'] ),
					);
				}
				update_option( 'smao_psi_last', array( 'time' => time(), 'rows' => $rows ), false );
				return rest_ensure_response( array( 'saved' => count( $rows ) ) );
			}
			/*
			 * Measurement writes only its own records, never media files, so it
			 * does not wait for the media lock. Holding it back behind a WebP
			 * job made "Measure again" fail with "another operation is running".
			 */
			/*
			 * Settings and the page cache never touch media files, so they do not
			 * wait for the media lock either. Waiting made "Save" and "Clear
			 * everything" fail silently while WebP copies were being made.
			 */
			if ( 'settings' === $route ) {
				$payload          = (array) $request->get_json_params();
				$result           = Settings::save( $payload );
				$result['status'] = self::status();
				return rest_ensure_response( $result );
			}
			if ( 'control' === $route && in_array( sanitize_key( (string) $request->get_param( 'command' ) ), array( 'cache_flush', 'cache_forget', 'cache_warm' ), true ) ) {
				return rest_ensure_response( self::control( $request ) );
			}
			if ( 'critical' === $route ) {
				$payload = (array) $request->get_json_params();
				return rest_ensure_response( Styles::store_page(
					(string) ( $payload['url'] ?? '' ),
					(string) ( $payload['css'] ?? '' ),
					(array) ( $payload['handles'] ?? array() ),
					isset( $payload['shift'] ) && is_numeric( $payload['shift'] ) ? (float) $payload['shift'] : -1.0,
					(array) ( $payload['heroes'] ?? array() ),
					is_array( $payload['prebuild'] ?? null ) ? $payload['prebuild'] : array()
				) );
			}
			if ( 'measure' === $route ) {
				$payload = (array) $request->get_json_params();
				return rest_ensure_response( array(
					'stored' => Measure::store(
						(string) ( $payload['url'] ?? '' ),
						(int) ( $payload['viewport'] ?? 0 ),
						(array) ( $payload['observations'] ?? array() )
					),
				) );
			}
			return rest_ensure_response(
				Database::lock(
					static function () use ( $request, $route ) {
						if ( 'storage' === $route ) {
							Vault::configure( (string) $request->get_param( 'path' ) );
							return self::status();
						}
						if ( 'runtime' === $route ) {
							Settings::runtime( (array) $request->get_json_params() );
							return self::status();
						}
						if ( 'settings' === $route ) {
							$payload  = (array) $request->get_json_params();
							$result   = Settings::save( $payload );
							$result['status'] = self::status();
							return $result;
						}
						if ( 'control' === $route ) {
							return self::control( $request );
						}
						return self::action( $request );
					}
				)
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				409 === $e->getCode() ? 'smao_busy' : 'smao_stopped',
				$e->getMessage(),
				array( 'status' => 409 )
			);
		}
	}

	/**
	 * Map an untrusted screen name onto a known screen.
	 *
	 * @param string $screen Requested screen.
	 * @return string
	 */
	private static function screen_of( string $screen ): string {
		$screen = sanitize_key( $screen );
		return isset( self::screens()[ $screen ] ) ? $screen : 'advanced';
	}

	/**
	 * Run a scan or queue control command.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 * @throws \RuntimeException When a command is refused.
	 */
	private static function control( \WP_REST_Request $request ): array {
		global $wpdb;
		$command = sanitize_key( (string) $request->get_param( 'command' ) );

		if ( 'review_scan' === $command ) {
			if ( true !== $request->get_param( 'scope_reviewed' ) ) {
				throw new \RuntimeException( __( 'Confirm the scope review before starting.', 'smart-media-auditor-optimizer' ) );
			}
			$settings                      = Settings::get();
			$settings['coverage_reviewed'] = true;
			update_option( 'smao_settings', $settings, false );
			Database::log( 'scope_reviewed', 0, __( 'Administrator confirmed the usage-scope review.', 'smart-media-auditor-optimizer' ) );
			Scanner::control( 'restart' );
			return self::status();
		}

		if ( 'cleanup_records' === $command ) {
			if ( 'CLEAR' !== $request->get_param( 'confirmation' ) ) {
				throw new \RuntimeException( __( 'Type CLEAR to confirm removing old plugin records.', 'smart-media-auditor-optimizer' ) );
			}
			return array( 'removed' => Plugin::cleanup() );
		}

		if ( 'cache_flush' === $command ) {
			$removed = Cache::flush();
			Warm::queue_important();
			return array(
				'message' => sprintf(
					/* translators: %s is a number of cached files. */
					_n( 'Cleared %s cached file. The most important pages are being rebuilt.', 'Cleared %s cached files. The most important pages are being rebuilt.', $removed, 'smart-media-auditor-optimizer' ),
					number_format_i18n( $removed )
				),
				'status'  => self::status(),
			);
		}
		if ( 'cache_forget' === $command ) {
			$url = esc_url_raw( (string) $request->get_param( 'url' ) );
			if ( '' === $url || ! str_starts_with( $url, home_url() ) ) {
				throw new \RuntimeException( __( 'Enter a full address on this site.', 'smart-media-auditor-optimizer' ) );
			}
			$done = Cache::forget( $url );
			Warm::queue( array( $url ) );
			return array(
				'message' => $done
					? __( 'Cleared. That page will be rebuilt on the next visit.', 'smart-media-auditor-optimizer' )
					: __( 'That page was not in the cache, so there was nothing to clear.', 'smart-media-auditor-optimizer' ),
				'status'  => self::status(),
			);
		}
		if ( 'cache_warm' === $command ) {
			$queued = Warm::queue_important();
			Warm::run();
			return array(
				'message' => sprintf(
					/* translators: %s is a number of pages. */
					_n( '%s page queued for rebuilding.', '%s pages queued for rebuilding.', $queued, 'smart-media-auditor-optimizer' ),
					number_format_i18n( $queued )
				),
				'status'  => self::status(),
			);
		}
		if ( 'tick' === $command ) {
			Plugin::tick();
			return self::status();
		}

		if ( in_array( $command, array( 'pause_jobs', 'resume_jobs', 'cancel_jobs' ), true ) ) {
			if ( 'cancel_jobs' === $command ) {
				Database::check( $wpdb->update( Database::table( 'jobs' ), array( 'state' => 'cancelled' ), array( 'state' => 'queued' ) ) );
			} else {
				update_option( 'smao_jobs_paused', 'pause_jobs' === $command, false );
			}
			return self::status();
		}

		Scanner::control( $command );
		return self::status();
	}

	/**
	 * Validate confirmation and execute a selected-attachment action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 * @throws \RuntimeException When validation or permission fails.
	 */
	private static function action( \WP_REST_Request $request ): array {
		$action      = sanitize_key( (string) $request->get_param( 'action' ) );
		$destructive = array( 'quarantine', 'purge' );
		$allowed     = array( 'quarantine', 'restore', 'purge', 'optimize', 'thumbnails' );
		if ( ! in_array( $action, $allowed, true ) ) {
			throw new \RuntimeException( __( 'Unsupported action.', 'smart-media-auditor-optimizer' ) );
		}

		$raw = $request->get_param( 'ids' );
		if ( ! is_array( $raw ) || ! $raw || count( $raw ) > 50 ) {
			throw new \RuntimeException( __( 'Select between 1 and 50 files.', 'smart-media-auditor-optimizer' ) );
		}
		foreach ( $raw as $candidate ) {
			if ( ( ! is_int( $candidate ) && ! is_string( $candidate ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $candidate ) ) {
				throw new \RuntimeException( __( 'File IDs must be positive integers.', 'smart-media-auditor-optimizer' ) );
			}
		}
		$ids = array_values( array_unique( array_map( 'absint', $raw ) ) );

		// Only a genuinely irreversible action demands a typed confirmation.
		// Quarantine moves files somewhere safe and can be undone, so it does not.
		if ( 'purge' === $action && 'PURGE' !== $request->get_param( 'confirmation' ) ) {
			throw new \RuntimeException( __( 'Type the confirmation word to continue.', 'smart-media-auditor-optimizer' ) );
		}

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				throw new \RuntimeException( __( 'You do not have permission to change one of the selected files.', 'smart-media-auditor-optimizer' ) );
			}
			if ( in_array( $action, $destructive, true ) && ! current_user_can( 'delete_post', $id ) ) {
				throw new \RuntimeException( __( 'You do not have permission to remove one of the selected files.', 'smart-media-auditor-optimizer' ) );
			}
		}

		if ( in_array( $action, array( 'optimize', 'thumbnails' ), true ) ) {
			Plugin::enqueue( $action, $ids );
			return array(
				'message' => __( 'Queued. Progress appears in the optimization queue above.', 'smart-media-auditor-optimizer' ),
				'status'  => self::status(),
			);
		}

		$results = array();
		foreach ( $ids as $id ) {
			try {
				if ( 'quarantine' === $action ) {
					Vault::quarantine( $id );
				} elseif ( 'restore' === $action ) {
					Vault::restore( $id );
				} else {
					Vault::purge( $id );
				}
				$results[] = array(
					'id'      => $id,
					'ok'      => true,
					'message' => __( 'Done.', 'smart-media-auditor-optimizer' ),
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
		return array(
			'results' => $results,
			'status'  => self::status(),
		);
	}

	/**
	 * Read compact scan and queue progress for polling.
	 *
	 * @return array
	 */
	public static function status(): array {
		global $wpdb;
		$table = Database::table( 'jobs' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table name.
		$jobs = $wpdb->get_results( "SELECT id,attachment_id,action,state,message FROM $table ORDER BY id DESC LIMIT 20", ARRAY_A );
		Database::check( $jobs );
		$state          = Database::state();
		$state['stale'] = isset( $state['epoch'] ) && get_option( 'smao_epoch', '' ) !== $state['epoch'];
		return array(
			'scan'        => $state,
			'jobs'        => $jobs,
			'jobs_paused' => (bool) get_option( 'smao_jobs_paused', false ),
			'live'        => Live::snapshot(),
			'next'        => md5( (string) wp_json_encode( Next_Step::get() ) ),
		);
	}

	/**
	 * Build a prepared report predicate with allowlisted ordering.
	 *
	 * @param array $input Filter input.
	 * @param int   $after Cursor for streaming exports.
	 * @return array{0:string,1:string}
	 */
	public static function query( array $input, int $after = -1 ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		$scan  = Database::state();
		if ( isset( $scan['id'] ) ) {
			$where[] = 'scan_id=%s';
			$args[]  = $scan['id'];
		}
		$status = sanitize_key( (string) ( $input['status'] ?? '' ) );
		if ( array_key_exists( $status, Report_Table::statuses() ) ) {
			$where[] = 'status=%s';
			$args[]  = $status;
		}
		if ( isset( $input['optimized'] ) && in_array( (string) $input['optimized'], array( '0', '1' ), true ) ) {
			$where[] = 'optimized=%d';
			$args[]  = (int) $input['optimized'];
		}
		if ( ! empty( $input['mime'] ) ) {
			$where[] = 'mime LIKE %s';
			$args[]  = $wpdb->esc_like( sanitize_text_field( (string) $input['mime'] ) ) . '%';
		}
		if ( ! empty( $input['search'] ) ) {
			$where[] = '(filename LIKE %s OR attachment_id=%d)';
			$args[]  = '%' . $wpdb->esc_like( sanitize_text_field( (string) $input['search'] ) ) . '%';
			$args[]  = absint( $input['search'] );
		}
		foreach ( array(
			'from'  => '>=',
			'until' => '<=',
		) as $key => $operator ) {
			if ( ! empty( $input[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', (string) $input[ $key ] ) ) {
				$where[] = "uploaded $operator %s";
				$args[]  = $input[ $key ] . ( 'from' === $key ? ' 00:00:00' : ' 23:59:59' );
			}
		}
		foreach ( array(
			'min_bytes' => '>=',
			'max_bytes' => '<=',
		) as $key => $operator ) {
			if ( isset( $input[ $key ] ) && '' !== (string) $input[ $key ] ) {
				$where[] = "bytes $operator %d";
				$args[]  = absint( $input[ $key ] );
			}
		}
		if ( $after >= 0 ) {
			$where[] = 'attachment_id>%d';
			$args[]  = $after;
		}
		$sort      = in_array( $input['sort'] ?? '', array( 'attachment_id', 'filename', 'bytes', 'uploaded', 'status', 'saved' ), true )
			? $input['sort']
			: 'attachment_id';
		$direction = 'desc' === ( $input['direction'] ?? '' ) ? 'DESC' : 'ASC';
		$sql       = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Predicates come from fixed internal maps; values use placeholders.
		$sql = $args ? $wpdb->prepare( $sql, $args ) : $sql;
		return array( $sql, $after >= 0 ? 'attachment_id ASC' : "$sort $direction, attachment_id ASC" );
	}

	/**
	 * Render the requested screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! Plugin::allowed() ) {
			wp_die( esc_html__( 'You do not have permission to manage media audits.', 'smart-media-auditor-optimizer' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing and filters.
		$input   = wp_unslash( $_GET );
		$screens = self::screens();
		$slug    = str_replace( 'smao-', '', sanitize_key( (string) ( $input['page'] ?? 'smao-home' ) ) );

		if ( ! isset( $screens[ $slug ] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=smao-home&unknown=1' ) );
			exit;
		}
		$input['screen'] = $slug;
		?>
		<div class="wrap smao" data-screen="<?php echo esc_attr( $slug ); ?>">
			<h1 class="smao-title">
				<?php echo esc_html( $screens[ $slug ] ); ?>
				<span class="smao-version">v<?php echo esc_html( SMAO_VERSION ); ?></span>
			</h1>

			<?php self::steps( $slug ); ?>

			<div id="smao-notice" role="status" aria-live="polite" tabindex="-1"></div>

			<?php if ( ! empty( $input['unknown'] ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'That page has moved. Here is the overview.', 'smart-media-auditor-optimizer' ); ?></p></div>
			<?php endif; ?>

			<?php
			try {
				switch ( $slug ) {
					case 'cleanup':
						Screen_Cleanup::render( $input );
						break;
					case 'speed':
						Screen_Speed::render( $input );
						break;
					case 'advanced':
						Screen_Advanced::render( $input );
						break;
					default:
						Screen_Home::render( $input );
				}
			} catch ( \Throwable $e ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the workflow position indicator, present on every screen.
	 *
	 * @param string $slug Current screen.
	 * @return void
	 */
	private static function steps( string $slug ): void {
		$steps = array(
			'home'    => __( 'Overview', 'smart-media-auditor-optimizer' ),
			'cleanup' => __( 'Clean up', 'smart-media-auditor-optimizer' ),
			'speed'   => __( 'Speed', 'smart-media-auditor-optimizer' ),
		);
		$index = array_search( $slug, array_keys( $steps ), true );
		?>
		<ol class="smao-workflow" aria-label="<?php esc_attr_e( 'Where you are', 'smart-media-auditor-optimizer' ); ?>">
			<?php $position = 0; ?>
			<?php foreach ( $steps as $step => $label ) : ?>
				<?php
				$state = 'todo';
				if ( $step === $slug ) {
					$state = 'current';
				} elseif ( false !== $index && $position < $index ) {
					$state = 'done';
				}
				++$position;
				?>
				<li class="is-<?php echo esc_attr( $state ); ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=smao-' . $step ) ); ?>"
						<?php echo $step === $slug ? 'aria-current="step"' : ''; ?>>
						<span class="smao-step-number"><?php echo esc_html( number_format_i18n( $position ) ); ?></span>
						<?php echo esc_html( $label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Stream filtered, formula-safe CSV rows in primary-key order.
	 *
	 * @return void
	 */
	public static function export(): void {
		if ( ! Plugin::allowed() ) {
			wp_die( esc_html__( 'Forbidden.', 'smart-media-auditor-optimizer' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'smao_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="smart-media-audit.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$output  = fopen( 'php://output', 'w' );
		$columns = array( 'attachment_id', 'filename', 'mime', 'uploaded', 'bytes', 'width', 'height', 'status', 'optimized', 'saved', 'reason', 'locations' );
		fputcsv( $output, $columns, ',', '"', '' );

		global $wpdb;
		$table = Database::table( 'media' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$input  = wp_unslash( $_GET );
		$cursor = 0;
		do {
			list( $where, $order ) = self::query( $input, $cursor );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- query() prepares every value.
			$rows = $wpdb->get_results( "SELECT * FROM $table WHERE $where ORDER BY $order LIMIT 100", ARRAY_A );
			Database::check( $rows );
			foreach ( $rows as $row ) {
				$evidence = Database::table( 'evidence' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table name.
				$row['locations'] = wp_json_encode(
					$wpdb->get_results(
						$wpdb->prepare( "SELECT source,source_id,field,strength,kind FROM $evidence WHERE attachment_id=%d LIMIT 100", $row['attachment_id'] ),
						ARRAY_A
					)
				);
				$line = array();
				foreach ( $columns as $column ) {
					$line[] = Matcher::csv( $row[ $column ] );
				}
				fputcsv( $output, $line, ',', '"', '' );
				$cursor = (int) $row['attachment_id'];
			}
			$batch = count( $rows );
		} while ( 100 === $batch && ! connection_aborted() );

		fclose( $output );
		exit;
	}
}
