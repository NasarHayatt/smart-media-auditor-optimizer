<?php
/**
 * Scanner component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Scanner workflow and safety policy.
 */
final class Scanner {
	/**
	 * Return the fixed map of source tables and primary-key cursors.
	 *
	 * @return array
	 */
	/**
	 * WordPress options that are machinery, not content.
	 *
	 * These hold routing regexes, capability maps, schedules and salts. They
	 * cannot legitimately reference a media file, but they are full of bare
	 * integers, so scanning them produced weak "evidence" for files nothing
	 * uses and suppressed almost every unused result.
	 *
	 * Options that genuinely can hold media, notably theme_mods_* with
	 * custom_logo and header_image, are deliberately absent from this list.
	 *
	 * @return array
	 */
	public static function ignored_options(): array {
		$ignored = array(
			'rewrite_rules',
			'cron',
			'wp_user_roles',
			'user_roles',
			'active_plugins',
			'active_sitewide_plugins',
			'recently_activated',
			'uninstall_plugins',
			'nonce_salt',
			'nonce_key',
			'auth_salt',
			'auth_key',
			'secure_auth_salt',
			'secure_auth_key',
			'logged_in_salt',
			'logged_in_key',
			'sidebars_widgets',
			'can_compress_scripts',
			'db_version',
			'initial_db_version',
			'wp_force_deactivated_plugins',
			'https_detection_errors',
			'finished_updating_comment_type',
			'finished_splitting_shared_terms',
			'auto_update_core_major',
			'auto_update_core_minor',
			'auto_update_core_dev',
		);
		global $wpdb;
		$ignored[] = $wpdb->prefix . 'user_roles';

		/**
		 * Filter the options excluded from reference scanning.
		 *
		 * Only add options that can never contain a media reference. Removing
		 * entries makes scans noisier, not safer.
		 *
		 * @param array $ignored Option names to skip.
		 */
		return array_values( array_unique( (array) apply_filters( 'smao_ignored_options', $ignored ) ) );
	}

	/**
	 * Build the WHERE predicate limiting which options are scanned.
	 *
	 * @return string
	 */
	private static function option_predicate(): string {
		global $wpdb;
		$names = self::ignored_options();
		$list  = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are generated from a counted array.
		$excluded = $wpdb->prepare( "option_name NOT IN ($list)", $names );
		return "option_name NOT LIKE 'smao\\_%'"
			. " AND option_name NOT LIKE '\\_transient\\_%'"
			. " AND option_name NOT LIKE '\\_site\\_transient\\_%'"
			. ' AND ' . $excluded;
	}

	public static function sources(): array {
		global $wpdb;
		return array(
			array( $wpdb->posts, 'ID', 'ID', 'post_content', '1=1', 'posts' ),
			array( $wpdb->posts, 'ID', 'ID', 'post_excerpt', '1=1', 'excerpts' ),
			array( $wpdb->term_taxonomy, 'term_taxonomy_id', 'term_id', 'description', '1=1', 'taxonomy' ),
			array( $wpdb->links, 'link_id', 'link_id', 'link_image', '1=1', 'links' ),
			array( $wpdb->postmeta, 'meta_id', 'post_id', 'meta_value', "meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes') AND meta_key NOT LIKE '\\_smao\\_%'", 'postmeta', 'meta_key' ),
			array( $wpdb->options, 'option_id', 'option_id', 'option_value', self::option_predicate(), 'options', 'option_name' ),
			array( $wpdb->termmeta, 'meta_id', 'term_id', 'meta_value', '1=1', 'termmeta', 'meta_key' ),
			array( $wpdb->usermeta, 'umeta_id', 'user_id', 'meta_value', '1=1', 'usermeta', 'meta_key' ),
			array( $wpdb->comments, 'comment_ID', 'comment_ID', 'comment_content', '1=1', 'comments' ),
			array( $wpdb->commentmeta, 'meta_id', 'comment_id', 'meta_value', '1=1', 'commentmeta', 'meta_key' ),
		);
	}

	/**
	 * Start a read-only scan with a fresh reference index.
	 *
	 * @return array
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function start(): array {
		global $wpdb;
		$old = Database::state();
		if ( in_array( $old['state'], array( 'running', 'paused' ), true ) ) {
			throw new \RuntimeException( __( 'Finish or cancel the existing scan first.', 'smart-media-auditor-optimizer' ) );
		}
		foreach ( array( 'tokens', 'evidence' ) as $name ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
			Database::check( $wpdb->query( 'DELETE FROM ' . Database::table( $name ) ) );
		}
		$state = array(
			'total'            => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'" ),
			'classified'       => 0,
			'source_processed' => 0,
			'source_totals'    => array(),
			'updated'          => time(),
			'activity'         => array(),
			'state'            => 'running',
			'phase'            => 'inventory',
			'cursor'           => 0,
			'source'           => 0,
			'processed'        => 0,
			'records'          => 0,
			'id'               => wp_generate_uuid4(),
			'started'          => time(),
			'epoch'            => get_option( 'smao_epoch', '' ),
			'incomplete'       => false,
			'message'          => '',
		);
		Database::check( $state['total'] );
		foreach ( self::sources() as $source ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Source identifiers and predicates are the fixed sources() map.
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$source[0]} WHERE {$source[4]}" );
			Database::check( $count );
			$state['source_totals'][] = (int) $count;
		}
		Database::save_state( $state );
		Database::log( 'scan_start', 0, 'Read-only scan started.' );
		return $state;
	}

	/**
	 * Validate and apply a scan state transition.
	 *
	 * @param string $action Action.
	 * @return array
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function control( string $action ): array {
		$state = Database::state();
		if ( 'restart' === $action ) {
			if ( in_array( $state['state'], array( 'running', 'paused' ), true ) ) {
				self::control( 'cancel' ); }
			return self::start();
		}
		if ( 'start' === $action ) {
			return self::start();
		}
		$allowed = array(
			'pause'  => array( 'running', 'paused' ),
			'resume' => array( 'paused', 'running' ),
			'cancel' => array( 'running', 'paused' ),
		);
		if ( ! isset( $allowed[ $action ] ) || ! in_array( $state['state'], $allowed[ $action ], true ) ) {
			throw new \RuntimeException( __( 'This scan transition is not available.', 'smart-media-auditor-optimizer' ) );
		}
		$state['state'] = array(
			'pause'  => 'paused',
			'resume' => 'running',
			'cancel' => 'cancelled',
		)[ $action ];
		Database::save_state( $state );
		return $state;
	}

	/**
	 * Advance one bounded scan batch or queued job.
	 *
	 * @return void
	 * @throws \Throwable When a source batch cannot be processed.
	 */
	public static function tick(): void {
		global $wpdb;
		$state = Database::state();
		if ( 'running' !== $state['state'] ) {
			return;
		}
		$limit    = Settings::get()['batch'];
		$deadline = microtime( true ) + Settings::get()['time_budget'];
		try {
			if ( 'inventory' === $state['phase'] ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date_gmt, post_mime_type FROM {$wpdb->posts} WHERE post_type='attachment' AND ID>%d ORDER BY ID LIMIT %d", $state['cursor'], $limit ) );
				Database::check( $rows );
				foreach ( $rows as $row ) {
					self::inventory( $row, $state['id'] );
					$state['cursor'] = (int) $row->ID;
					++$state['processed'];
					self::checkpoint( $state, 'indexed', (int) $row->ID, wp_basename( (string) get_post_meta( $row->ID, '_wp_attached_file', true ) ) );
					if ( self::budget( $deadline ) ) {
						break; }
				}
				if ( ! $rows ) {
					$state['phase']  = 'sources';
					$state['cursor'] = 0;
				}
			} elseif ( 'sources' === $state['phase'] ) {
				// Fill the time budget instead of doing just 20 rows per cron visit.
				for ( $page = 0; $page < 10; ++$page ) {
					if ( $page > 0 && self::budget( $deadline ) ) {
						break; }
					$sources = self::sources();
					if ( ! isset( $sources[ $state['source'] ] ) ) {
						$state['phase']  = 'classify';
						$state['cursor'] = 0;
						break;
					}
					$s     = $sources[ $state['source'] ];
					$field = $s[6] ?? "'{$s[3]}'";
					$sql   = "SELECT {$s[1]} AS cursor_id, {$s[2]} AS owner_id, $field AS field, LEFT({$s[3]},262144) AS value, LENGTH({$s[3]}) AS length FROM {$s[0]} WHERE {$s[1]}>%d AND ({$s[4]}) ORDER BY {$s[1]} LIMIT %d";
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Source identifiers are fixed and cursor/limit are prepared.
					$rows = $wpdb->get_results( $wpdb->prepare( $sql, $state['cursor'], Settings::get()['source_batch'] ) );
					Database::check( $rows );
					foreach ( $rows as $row ) {
						if ( (int) $row->length > 262144 ) {
							$state['incomplete'] = true; }
						self::match( (string) $row->value, $s[5], (int) $row->owner_id, (string) $row->field );
						$state['cursor'] = (int) $row->cursor_id;
						++$state['records'];
						$state['source_processed'] = (int) ( $state['source_processed'] ?? 0 ) + 1;
						$state['current']          = $s[5] . ' #' . $row->owner_id . ' / ' . $row->field;
						if ( 0 === $state['records'] % 10 || self::budget( $deadline ) ) {
							self::checkpoint( $state, 'checked', (int) $row->owner_id, $state['current'] );
						}
						if ( self::budget( $deadline ) ) {
							break; }
					}
					if ( ! $rows ) {
						self::activity( $state, 'source_complete', 0, $s[5] );
						$state['source_processed'] = 0;
						++$state['source'];
						$state['cursor'] = 0;
					}
				}
			} else {
				$table = Database::table( 'media' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE scan_id=%s AND attachment_id>%d ORDER BY attachment_id LIMIT %d", $state['id'], $state['cursor'], $limit ), ARRAY_A );
				Database::check( $rows );
				foreach ( $rows as $row ) {
					self::classify( $row, $state );
					$state['classified'] = (int) ( $state['classified'] ?? 0 ) + 1;
					$state['cursor']     = (int) $row['attachment_id'];
					self::checkpoint( $state, 'classified', (int) $row['attachment_id'], $row['filename'] );
					if ( self::budget( $deadline ) ) {
						break; }
				}
				if ( ! $rows ) {
					if ( get_option( 'smao_epoch', '' ) !== $state['epoch'] ) {
						$state['incomplete'] = true;
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
						Database::check( $wpdb->query( $wpdb->prepare( "UPDATE $table SET status='possible',reason=%s WHERE scan_id=%s AND status='unused'", 'Site changed during scan. Rescan during a quiet period.', $state['id'] ) ) );
					}
					$state['state']    = 'complete';
					$state['finished'] = time();
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
					Database::check( $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE scan_id<>%s", $state['id'] ) ) );
					Database::log( 'scan_complete', 0, 'Read-only scan completed.' );
				}
			}
			$state['updated'] = time();
			Database::save_state( $state );
		} catch ( \Throwable $e ) {
			$state['state']   = 'paused';
			$state['message'] = $e->getMessage();
			Database::save_state( $state );
			throw $e;
		}
	}

	/**
	 * Retain a small recent activity window without copying source values.
	 *
	 * @param array  $state Mutable checkpoint.
	 * @param string $event Event type.
	 * @param int    $id Attachment identifier.
	 * @param string $label Filename or source family.
	 * @return void
	 */
	private static function activity( array &$state, string $event, int $id, string $label ): void {
		$state['current']    = $label;
		$state['activity'][] = array(
			'event' => $event,
			'id'    => $id,
			'label' => $label,
			'time'  => time(),
		);
		$state['activity']   = array_slice( $state['activity'], -12 );
	}

	/**
	 * Persist completed work before the whole request finishes.
	 *
	 * @param array  $state Scan state.
	 * @param string $event Activity type.
	 * @param int    $id Object ID.
	 * @param string $label Visible checkpoint.
	 * @return void
	 */
	private static function checkpoint( array &$state, string $event, int $id, string $label ): void {
		self::activity( $state, $event, $id, $label );
		$state['updated'] = time();
		Database::save_state( $state );
	}

	/**
	 * Check whether the current batch has reached its time or memory budget.
	 *
	 * @param float $deadline Deadline.
	 * @return bool
	 */
	private static function budget( float $deadline ): bool {
		$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		return microtime( true ) > $deadline || ( $memory > 0 && memory_get_usage( true ) > $memory * 0.7 );
	}

	/**
	 * Inventory and index one attachment group.
	 *
	 * @param object $post Post.
	 * @param string $scan Scan.
	 * @return void
	 */
	private static function inventory( object $post, string $scan ): void {
		global $wpdb;
		$id      = (int) $post->ID;
		$details = array(
			'missing'   => array(),
			'protected' => '',
			'remote'    => false,
		);
		$bytes   = 0;
		$group   = array(
			'files'    => array(),
			'main'     => '',
			'metadata' => array(),
		);
		try {
			$group                = Media::group( $id );
			$details['remote']    = Media::remote( $id );
			$details['protected'] = Media::protected_reason( $id, $group );
			foreach ( $group['files'] as $relative ) {
				try {
					$bytes += (int) filesize( Media::path( $relative ) ); } catch ( \Throwable $e ) {
					$details['missing'][] = $relative; }
					self::index( $id, Matcher::normalize( $relative ), 'path' );
					self::index( $id, wp_basename( $relative ), 'name' );
			}
		} catch ( \Throwable $e ) {
			$details['protected'] = $e->getMessage();
		}
		self::index( $id, (string) $id, 'id' );
		$stats = get_post_meta( $id, '_smao_optimized', true );
		$stats = is_array( $stats ) ? $stats : array();
		Database::check(
			$wpdb->replace(
				Database::table( 'media' ),
				array(
					'attachment_id' => $id,
					'scan_id'       => $scan,
					'status'        => 'possible',
					'mime'          => $post->post_mime_type,
					'filename'      => wp_basename( $group['main'] ),
					'uploaded'      => $post->post_date_gmt,
					'bytes'         => $bytes,
					'width'         => (int) ( $group['metadata']['width'] ?? 0 ),
					'height'        => (int) ( $group['metadata']['height'] ?? 0 ),
					'optimized'     => empty( $stats ) ? 0 : 1,
					'saved'         => (int) ( $stats['saved'] ?? 0 ),
					'reason'        => 'Scan in progress.',
					'details'       => wp_json_encode( $details ),
				)
			)
		);
	}

	/**
	 * Persist a hashed lookup token.
	 *
	 * @param int    $id Id.
	 * @param string $token Token.
	 * @param string $kind Kind.
	 * @return void
	 */
	private static function index( int $id, string $token, string $kind ): void {
		global $wpdb;
		$table = Database::table( 'tokens' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		Database::check( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (token_hash,attachment_id,kind) VALUES (%s,%d,%s)", hash( 'sha256', $token ), $id, $kind ) ) );
	}

	/**
	 * Resolve source tokens and retain evidence locations.
	 *
	 * @param string $text Text.
	 * @param string $source Source.
	 * @param int    $owner Owner.
	 * @param string $field Field.
	 * @return void
	 */
	public static function match( string $text, string $source, int $owner, string $field ): void {
		global $wpdb;
		$tokens = Matcher::tokens( $text, $field );
		$table  = Database::table( 'tokens' );
		foreach ( array_chunk( $tokens, 100 ) as $chunk ) {
			$lookup = array();
			foreach ( $chunk as $token ) {
				$lookup[ hash( 'sha256', $token['token'] ) . ':' . $token['kind'] ] = $token; }
			$hashes = array_map( static fn( $t ) => hash( 'sha256', $t['token'] ), $chunk );
			$sql    = "SELECT attachment_id,token_hash,kind FROM $table WHERE token_hash IN (" . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ')';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
			$hits = $wpdb->get_results( $wpdb->prepare( $sql, $hashes ) );
			Database::check( $hits );
			foreach ( $hits as $hit ) {
				$token = $lookup[ $hit->token_hash . ':' . $hit->kind ] ?? null;
				if ( ! $token ) {
					continue; }
				$kind = 'reference';
				if ( str_contains( $text, 'download' ) || ( str_contains( $text, 'href' ) && ! str_starts_with( (string) get_post_mime_type( $hit->attachment_id ), 'image/' ) ) ) {
					$kind = 'download'; }
				self::evidence( (int) $hit->attachment_id, $source, $owner, $field, $token['strength'], $kind );
			}
		}
	}

	/**
	 * Retain a bounded reference location without storing source values.
	 *
	 * @param int    $id Id.
	 * @param string $source Source.
	 * @param int    $owner Owner.
	 * @param string $field Field.
	 * @param int    $strength Strength.
	 * @param string $kind Kind.
	 * @return void
	 */
	private static function evidence( int $id, string $source, int $owner, string $field, int $strength, string $kind ): void {
		global $wpdb;
		$table = Database::table( 'evidence' );
		// Separate strength caps prevent weak matches from crowding out confirmed use.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE attachment_id=%d AND strength=%d", $id, $strength ) );
		Database::check( $count );
		if ( (int) $count >= 50 ) {
			return; }
		$fingerprint = hash( 'sha256', "$source|$owner|$field|$strength|$kind" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		Database::check( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (attachment_id,source,source_id,field,strength,kind,fingerprint) VALUES (%d,%s,%d,%s,%d,%s,%s)", $id, $source, $owner, substr( $field, 0, 191 ), $strength, $kind, $fingerprint ) ) );
	}

	/**
	 * Classify an inventoried attachment using evidence and safety constraints.
	 *
	 * @param array $row Row.
	 * @param array $state State.
	 * @return void
	 */
	private static function classify( array $row, array $state ): void {
		global $wpdb;
		$id      = (int) $row['attachment_id'];
		$details = json_decode( $row['details'], true );
		$table   = Database::table( 'evidence' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$best = $wpdb->get_row( $wpdb->prepare( "SELECT strength,kind FROM $table WHERE attachment_id=%d ORDER BY strength DESC,kind ASC LIMIT 1", $id ), ARRAY_A );
		Database::check( $best );
		$status = 'possible';
		$reason = 'No reference found. Custom code, remote consumers and private tables require review.';
		if ( $best ) {
			$status = (int) $best['strength'] >= 2 ? ( 'download' === $best['kind'] ? 'download' : 'used' ) : 'possible';
			$reason = 'Indexed reference locations are available in Details. Ambiguous IDs/filenames are treated as possible use.';
		} elseif ( Settings::get()['coverage_reviewed'] && ! $state['incomplete'] && get_option( 'smao_epoch', '' ) === $state['epoch'] ) {
			$status = 'unused';
			$reason = 'No reference found in reviewed scope. Unused candidate; manual review is required.';
		}
		$recent = strtotime( $row['uploaded'] . ' UTC' ) > time() - Settings::get()['recent_days'] * DAY_IN_SECONDS;
		if ( ! $best && ( $recent || wp_get_post_parent_id( $id ) || get_option( 'wp_attachment_pages_enabled', true ) ) ) {
			$status = 'possible';
			$reason = $recent ? 'Recently uploaded media is protected from quarantine.' : 'Parent relationship or enabled attachment pages may use this file.';
		}
		if ( $details['protected'] && ! $best ) {
			$status = 'possible';
			$reason = $details['protected']; }
		if ( ! empty( $details['missing'] ) && ! $details['remote'] ) {
			$status = 'broken';
			$reason = 'One or more files in the media group are missing or unreadable.'; }
		if ( $details['remote'] ) {
			$status = 'external';
			$reason = 'Filtered/CDN or offloaded media. Local absence is not evidence of breakage.'; }
		$vault = Vault::record( $id );
		if ( $vault && 'quarantine' === $vault['operation'] ) {
			$status = 'quarantined';
			$reason = 'Recoverable quarantine; see Quarantine and Restore.'; }
		Database::check(
			$wpdb->update(
				Database::table( 'media' ),
				array(
					'status' => $status,
					'reason' => $reason,
				),
				array( 'attachment_id' => $id )
			)
		);
	}

	/**
	 * Require fresh, reviewed unused evidence before quarantine.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function assert_unused( int $id ): void {
		global $wpdb;
		$state = Database::state();
		if ( 'complete' !== $state['state'] || ! empty( $state['incomplete'] ) || ( $state['epoch'] ?? null ) !== get_option( 'smao_epoch', '' ) || time() - ( $state['finished'] ?? 0 ) > DAY_IN_SECONDS || ! Settings::get()['coverage_reviewed'] ) {
			throw new \RuntimeException( __( 'A complete, unchanged scan from the last 24 hours and scope review are required.', 'smart-media-auditor-optimizer' ) );
		}
		$table = Database::table( 'media' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status,scan_id FROM $table WHERE attachment_id=%d", $id ), ARRAY_A );
		Database::check( $row );
		if ( ! $row || 'unused' !== $row['status'] || $state['id'] !== $row['scan_id'] ) {
			throw new \RuntimeException( __( 'Only reviewed unused candidates can be quarantined.', 'smart-media-auditor-optimizer' ) );
		}
		$group = Media::assert_local( $id );
		// Synchronous recheck closes the normal gap between the scan and quarantine.
		self::assert_no_references( $id, $group );
	}

	/**
	 * Recheck current source values immediately before removing media bytes.
	 *
	 * @param int   $id Id.
	 * @param array $group Group.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function assert_no_references( int $id, array $group ): void {
		global $wpdb;
		foreach ( self::sources() as $s ) {
			$terms = array( '%' . $wpdb->esc_like( (string) $id ) . '%' );
			foreach ( $group['files'] as $file ) {
				$terms[] = '%' . $wpdb->esc_like( wp_basename( $file ) ) . '%'; }
			$where = implode( ' OR ', array_fill( 0, count( $terms ), "{$s[3]} LIKE %s" ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
			$hit = $wpdb->get_var( $wpdb->prepare( "SELECT {$s[1]} FROM {$s[0]} WHERE ({$s[4]}) AND ($where) LIMIT 1", $terms ) );
			Database::check( $hit );
			if ( $hit ) {
				throw new \RuntimeException( __( 'A current database reference may exist. Rescan and review; no files were moved.', 'smart-media-auditor-optimizer' ) ); }
		}
	}
}
