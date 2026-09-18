<?php
/**
 * Vault component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Vault workflow and safety policy.
 */
final class Vault {
	/**
	 * Validate private backup storage outside the public document root.
	 *
	 * @param bool        $create Whether to create the private site directory.
	 * @param string|null $candidate Optional candidate path for setup validation.
	 * @return string
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function root( bool $create = true, ?string $candidate = null ): string {
		$path = $candidate ?? ( defined( 'SMAO_VAULT_DIR' ) ? SMAO_VAULT_DIR : get_option( 'smao_vault_path', '' ) );
		if ( ! is_string( $path ) || '' === $path ) {
			throw new \RuntimeException( __( 'Choose a private recovery folder in Settings.', 'smart-media-auditor-optimizer' ) ); }
		$root          = realpath( $path );
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '';
		$document      = $document_root ? realpath( $document_root ) : false;
		if ( ! preg_match( '~^(?:/|[a-zA-Z]:[/\\\\])~', $path ) || Media::within( (string) $root, wp_upload_dir()['basedir'] ) ) {
			throw new \RuntimeException( I18n::text( 'Vault must be an absolute private path outside uploads.' ) );
		}
		if ( ! $root || ! is_dir( $root ) || ! is_writable( $root ) || Media::within( $root, ABSPATH ) || ( $document && Media::within( $root, $document ) ) ) {
			throw new \RuntimeException( I18n::text( 'Private vault is unavailable or inside a public web root.' ) );
		}
		// Reject symlinks before resolving, including ancestor symlinks.
		$walk = rtrim( $path, '/\\' );
		while ( dirname( $walk ) !== $walk ) {
			if ( is_link( $walk ) ) {
				throw new \RuntimeException( I18n::text( 'Vault symlinks are not supported.' ) ); }
			$walk = dirname( $walk );
		}
		$site = $root . '/site-' . get_current_blog_id();
		if ( is_link( $site ) || ( $create && ! is_dir( $site ) && ! mkdir( $site, 0700 ) ) ) {
			throw new \RuntimeException( I18n::text( 'Cannot create private site vault.' ) );
		}
		return $site;
	}

	/**
	 * Save an administrator-selected existing private recovery directory.
	 *
	 * @param string $path Existing absolute private path.
	 * @return void
	 * @throws \RuntimeException When the directory is unsafe or configuration overrides it.
	 */
	public static function configure( string $path ): void {
		if ( defined( 'SMAO_VAULT_DIR' ) ) {
			throw new \RuntimeException( __( 'Recovery storage is managed by SMAO_VAULT_DIR in wp-config.php.', 'smart-media-auditor-optimizer' ) ); }
		global $wpdb;
		$path     = trim( $path );
		$previous = get_option( 'smao_vault_path', '' );
		if ( $previous && realpath( $previous ) !== realpath( $path ) ) {
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::table( 'vault' ) ) );
			Database::check( $count );
			if ( $count ) {
				throw new \RuntimeException( __( 'Existing recovery journals depend on the current folder. Keep that folder configured.', 'smart-media-auditor-optimizer' ) ); }
		}
		self::root( false, $path );
		update_option( 'smao_vault_path', $path, false );
		Database::log( 'recovery_setup', 0, 'Administrator configured private recovery storage.' );
	}

	/**
	 * Read and decode an attachment recovery journal.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function record( int $id ): ?array {
		global $wpdb;
		$table = Database::table( 'vault' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers/predicates come from fixed internal maps; all request values use prepared placeholders.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE attachment_id=%d", $id ), ARRAY_A );
		Database::check( $row );
		if ( ! $row ) {
			return null; }
		$row['manifest'] = json_decode( $row['manifest'], true, 512, JSON_THROW_ON_ERROR );
		return $row;
	}

	/**
	 * Validate and persist the supplied configuration or recovery record.
	 *
	 * @param array $record Record.
	 * @return void
	 */
	public static function save( array $record ): void {
		global $wpdb;
		$copy             = $record;
		$copy['manifest'] = wp_json_encode( $record['manifest'] );
		Database::check( $wpdb->replace( Database::table( 'vault' ), $copy ) );
	}

	/**
	 * Resolve a validated private backup filename.
	 *
	 * @param array  $record Record.
	 * @param string $name Name.
	 * @return string
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function backup_path( array $record, string $name ): string {
		$dir = $record['manifest']['directory'];
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $dir ) || ! preg_match( '/^[a-f0-9]{64}\.bin$/D', $name ) ) {
			throw new \RuntimeException( I18n::text( 'Invalid backup manifest.' ) );
		}
		$folder = self::root() . '/' . $dir;
		$path   = $folder . '/' . $name;
		if ( is_link( $folder ) || is_link( $path ) ) {
			throw new \RuntimeException( I18n::text( 'Backup symlink rejected.' ) ); }
		return $path;
	}

	/**
	 * Journal and verify recovery copies before changing live files.
	 *
	 * @param int    $id Id.
	 * @param string $operation Operation.
	 * @param array  $group Group.
	 * @return array
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function prepare( int $id, string $operation, array $group ): array {
		if ( self::record( $id ) ) {
			throw new \RuntimeException( I18n::text( 'A recovery record already exists. Restore it before another operation.' ) ); }
		$directory = bin2hex( random_bytes( 16 ) );
		$folder    = self::root() . '/' . $directory;
		if ( ! mkdir( $folder, 0700 ) ) {
			throw new \RuntimeException( I18n::text( 'Cannot create backup directory.' ) ); }
		$record = array(
			'attachment_id' => $id,
			'operation'     => $operation,
			'state'         => 'preparing',
			'created'       => time(),
			'manifest'      => array(
				'uploads_root' => realpath( wp_upload_dir()['basedir'] ),
				'directory'    => $directory,
				'metadata'     => $group['metadata'],
				'alternates'   => get_post_meta( $id, '_smao_alternates', true ),
				'optimized'    => get_post_meta( $id, '_smao_optimized', true ),
				'files'        => array(),
				'generated'    => array(),
			),
		);
		self::save( $record );
		foreach ( $group['files'] as $relative ) {
			$path = Media::path( $relative );
			$hash = hash_file( 'sha256', $path );
			if ( false === $hash ) {
				throw new \RuntimeException( I18n::text( 'Cannot hash original.' ) ); }
			$name = hash( 'sha256', $relative ) . '.bin';
			// Persist intent first. A killed copy never authorizes removal of the original.
			$record['manifest']['files'][ $relative ] = array(
				'backup' => $name,
				'before' => $hash,
				'after'  => $hash,
				'ready'  => false,
			);
			self::save( $record );
			$backup = self::backup_path( $record, $name );
			if ( ! copy( $path, $backup ) || hash_file( 'sha256', $backup ) !== $hash || hash_file( 'sha256', $path ) !== $hash ) {
				throw new \RuntimeException( I18n::text( 'Backup verification failed; originals retained.' ) );
			}
			chmod( $backup, 0600 );
			$record['manifest']['files'][ $relative ]['ready'] = true;
			self::save( $record );
		}
		$record['state'] = 'prepared';
		self::save( $record );
		Database::log( 'backup', $id, 'Verified private backup created before file action.' );
		return $record;
	}

	/**
	 * Move a reviewed local media group into recoverable storage.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function quarantine( int $id ): void {
		Scanner::assert_unused( $id );
		$group  = Media::assert_local( $id );
		$record = self::prepare( $id, 'quarantine', $group );
		// Check site references once again after copying, before making files unavailable.
		Scanner::assert_unused( $id );
		$record['state'] = 'moving';
		self::save( $record );
		foreach ( $record['manifest']['files'] as $relative => $entry ) {
			$path = Media::path( $relative );
			if ( hash_file( 'sha256', $path ) !== $entry['before'] || ! unlink( $path ) ) {
				throw new \RuntimeException( I18n::text( 'File changed or could not be moved. Recovery journal retained; use Restore.' ) );
			}
		}
		$record['state'] = 'quarantined';
		self::save( $record );
		global $wpdb;
		Database::check(
			$wpdb->update(
				Database::table( 'media' ),
				array(
					'bytes'  => 0,
					'status' => 'quarantined',
					'reason' => 'Recoverable quarantine.',
				),
				array( 'attachment_id' => $id )
			)
		);
		Database::log( 'quarantine', $id, 'Media group moved to private recovery storage.' );
	}

	/**
	 * Restore verified originals without overwriting unrelated changes.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function restore( int $id ): void {
		$record = self::record( $id );
		if ( ! $record ) {
			throw new \RuntimeException( I18n::text( 'No recovery record exists.' ) ); }
		if ( ( $record['manifest']['uploads_root'] ?? null ) !== realpath( wp_upload_dir()['basedir'] ) ) {
			throw new \RuntimeException( I18n::text( 'Uploads location changed since backup. Restore requires administrator recovery of the original location.' ) );
		}
		if ( 'purging' === $record['state'] ) {
			throw new \RuntimeException( I18n::text( 'Permanent purge has started; restore from your independent backup.' ) ); }
		if ( 'restored' === $record['state'] ) {
			self::discard( $record );
			return; }
		// Validate the complete transaction before changing any file.
		foreach ( $record['manifest']['files'] as $relative => $entry ) {
			$path   = Media::path( $relative, false );
			$backup = self::backup_path( $record, $entry['backup'] );
			if ( ! $entry['ready'] ) {
				if ( ! is_file( $path ) || hash_file( 'sha256', $path ) !== $entry['before'] ) {
					throw new \RuntimeException( I18n::text( 'Incomplete backup and original changed. Manual recovery required.' ) ); }
				continue;
			}
			if ( ! is_file( $backup ) || hash_file( 'sha256', $backup ) !== $entry['before'] ) {
				throw new \RuntimeException( I18n::text( 'Backup is missing or damaged. Restore stopped.' ) ); }
			if ( file_exists( $path ) && ! in_array( hash_file( 'sha256', $path ), array( $entry['before'], $entry['after'] ), true ) ) {
				throw new \RuntimeException( I18n::text( 'An original path has newer content; refusing to overwrite it.' ) ); }
		}
		foreach ( $record['manifest']['generated'] as $relative => $hash ) {
			$path = Media::path( $relative, false );
			if ( file_exists( $path ) && ( ! $hash || hash_file( 'sha256', $path ) !== $hash ) ) {
				throw new \RuntimeException( I18n::text( 'Generated file has changed; manual review required.' ) ); }
		}
		$record['state'] = 'restoring';
		self::save( $record );
		foreach ( $record['manifest']['files'] as $relative => $entry ) {
			$path = Media::path( $relative, false );
			if ( ! $entry['ready'] || ( is_file( $path ) && hash_file( 'sha256', $path ) === $entry['before'] ) ) {
				continue; }
			$temp = $path . '.smao-restore-' . bin2hex( random_bytes( 8 ) );
			if ( ! copy( self::backup_path( $record, $entry['backup'] ), $temp ) || hash_file( 'sha256', $temp ) !== $entry['before'] ) {
				if ( is_file( $temp ) ) {
					unlink( $temp ); }
				throw new \RuntimeException( I18n::text( 'Restore copy failed; backup retained.' ) );
			}
			if ( ! rename( $temp, $path ) ) {
				unlink( $temp );
				throw new \RuntimeException( I18n::text( 'Atomic restore failed; backup retained.' ) ); }
		}
		foreach ( $record['manifest']['generated'] as $relative => $hash ) {
			$path = Media::path( $relative, false );
			if ( is_file( $path ) && ! unlink( $path ) ) {
				throw new \RuntimeException( I18n::text( 'Could not remove a generated derivative.' ) ); }
		}
		wp_update_attachment_metadata( $id, $record['manifest']['metadata'] );
		if ( $record['manifest']['metadata'] && get_post_meta( $id, '_wp_attachment_metadata', true ) !== $record['manifest']['metadata'] ) {
			throw new \RuntimeException( __( 'Original files restored, but metadata could not be verified. Recovery record retained.', 'smart-media-auditor-optimizer' ) );
		}
		update_post_meta( $id, '_smao_alternates', $record['manifest']['alternates'] );
		if ( $record['manifest']['optimized'] ) {
			update_post_meta( $id, '_smao_optimized', $record['manifest']['optimized'] ); } else {
			delete_post_meta( $id, '_smao_optimized' ); }
			$record['state'] = 'restored';
			self::save( $record );
			global $wpdb;
			$bytes = 0;
			foreach ( array_keys( $record['manifest']['files'] ) as $relative ) {
				$bytes += filesize( Media::path( $relative ) ); }
			Database::check(
				$wpdb->update(
					Database::table( 'media' ),
					array(
						'status'    => 'possible',
						'bytes'     => $bytes,
						'optimized' => empty( $record['manifest']['optimized'] ) ? 0 : 1,
						'saved'     => (int) ( $record['manifest']['optimized']['saved'] ?? 0 ),
						'reason'    => 'Originals restored. Run a fresh scan.',
					),
					array( 'attachment_id' => $id )
				)
			);
			self::discard( $record );
			Plugin::dirty();
			Database::log( 'restore', $id, 'Original media group and metadata restored.' );
	}

	/**
	 * Remove only the files named by a completed recovery journal.
	 *
	 * @param array $record Record.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	private static function discard( array $record ): void {
		foreach ( $record['manifest']['files'] as $entry ) {
			$path = self::backup_path( $record, $entry['backup'] );
			if ( is_file( $path ) && ! unlink( $path ) ) {
				throw new \RuntimeException( I18n::text( 'Could not remove backup; recovery record retained.' ) ); }
		}
		$folder = self::root() . '/' . $record['manifest']['directory'];
		if ( is_dir( $folder ) && ! rmdir( $folder ) ) {
			throw new \RuntimeException( I18n::text( 'Unexpected files in backup folder; record retained.' ) ); }
		global $wpdb;
		Database::check( $wpdb->delete( Database::table( 'vault' ), array( 'attachment_id' => $record['attachment_id'] ) ) );
	}

	/**
	 * Permanently remove explicitly approved quarantine backups after retention.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function purge( int $id ): void {
		$record = self::record( $id );
		if ( ! $record || 'quarantine' !== $record['operation'] || ! in_array( $record['state'], array( 'quarantined', 'purging' ), true ) || time() - (int) $record['created'] < Settings::get()['retention_days'] * DAY_IN_SECONDS ) {
			throw new \RuntimeException( I18n::text( 'Only retained quarantine groups can be permanently deleted after the retention period.' ) );
		}
		if ( 'purging' !== $record['state'] ) {
			if ( is_multisite() || Media::remote( $id ) || Media::protected_reason( $id, Media::group( $id ) ) ) {
				throw new \RuntimeException( I18n::text( 'Attachment is now protected.' ) ); }
			Scanner::assert_no_references( $id, Media::group( $id ) );
			foreach ( $record['manifest']['files'] as $relative => $entry ) {
				if ( file_exists( Media::path( $relative, false ) ) ) {
					throw new \RuntimeException( I18n::text( 'A live file exists at an original path; purge refused.' ) ); }
			}
			// Purge only plugin-owned backups. Never invoke attachment deletion hooks that
			// can remove newly generated or shared files. Keep the attachment as a tombstone.
			$record['state'] = 'purging';
			self::save( $record );
		}
		self::discard( $record );
		update_post_meta( $id, '_smao_purged', gmdate( 'c' ) );
		Plugin::dirty();
		Database::log( 'purge', $id, 'Administrator permanently purged quarantine backups. Attachment record retained for audit.' );
	}
}
