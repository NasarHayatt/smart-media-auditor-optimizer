<?php
/**
 * Database component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Database workflow and safety policy.
 */
final class Database {
	/**
	 * Resolve a whitelisted site-local table name.
	 *
	 * @param string $name Name.
	 * @return string
	 * @throws \InvalidArgumentException When a table name is outside the fixed allowlist.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'media', 'tokens', 'evidence', 'vault', 'log', 'jobs' ), true ) ) {
			throw new \InvalidArgumentException( I18n::text( 'Unknown table.' ) );
		}
		return $wpdb->prefix . 'smao_' . $name;
	}

	/**
	 * Create or upgrade the per-site plugin schema.
	 *
	 * @return void
	 * @throws \RuntimeException When database state cannot be safely updated.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate     = $wpdb->get_charset_collate();
		$definitions = array(
			'media'    => "attachment_id bigint(20) unsigned NOT NULL,
				scan_id varchar(36) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'possible',
				mime varchar(100) NOT NULL DEFAULT '',
				filename varchar(255) NOT NULL DEFAULT '',
				uploaded datetime NOT NULL,
				bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				width int unsigned NOT NULL DEFAULT 0,
				height int unsigned NOT NULL DEFAULT 0,
				optimized tinyint NOT NULL DEFAULT 0,
				saved bigint(20) NOT NULL DEFAULT 0,
				reason text NOT NULL,
				details longtext NOT NULL,
				PRIMARY KEY  (attachment_id),
				KEY scan_status (scan_id,status),
				KEY mime (mime),
				KEY uploaded (uploaded),
				KEY bytes (bytes)",
			'tokens'   => 'token_hash char(64) NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL,
				kind varchar(8) NOT NULL,
				PRIMARY KEY  (token_hash,attachment_id,kind),
				KEY attachment_id (attachment_id)',
			'evidence' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				source varchar(30) NOT NULL,
				source_id bigint(20) unsigned NOT NULL,
				field varchar(191) NOT NULL,
				strength tinyint NOT NULL,
				kind varchar(20) NOT NULL,
				fingerprint char(64) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY location (attachment_id,fingerprint),
				KEY strength (attachment_id,strength)',
			'vault'    => 'attachment_id bigint(20) unsigned NOT NULL,
				operation varchar(20) NOT NULL,
				state varchar(20) NOT NULL,
				created bigint(20) unsigned NOT NULL,
				manifest longtext NOT NULL,
				PRIMARY KEY  (attachment_id)',
			'log'      => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(40) NOT NULL,
				message text NOT NULL,
				PRIMARY KEY  (id),
				KEY created (created)',
			'jobs'     => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				action varchar(20) NOT NULL,
				state varchar(20) NOT NULL DEFAULT 'queued',
				created datetime NOT NULL,
				message text NOT NULL,
				PRIMARY KEY  (id),
				KEY state (state,id),
				KEY created (created)",
		);
		foreach ( $definitions as $name => $sql ) {
			dbDelta( 'CREATE TABLE ' . self::table( $name ) . " (\n$sql\n) $collate;" );
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( I18n::text( 'Could not install plugin tables.' ) );
			}
		}
		update_option( 'smao_schema', SMAO_VERSION, false );
	}

	/**
	 * Reject failed database operations before continuing a workflow.
	 *
	 * @param mixed $result Result.
	 * @return mixed
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function check( mixed $result ): mixed {
		global $wpdb;
		if ( false === $result || $wpdb->last_error ) {
			throw new \RuntimeException( I18n::text( 'Database operation failed. Scan or action stopped safely.' ) );
		}
		return $result;
	}

	/**
	 * Serialize an operation with a connection-scoped database lock.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function lock( callable $callback ): mixed {
		global $wpdb;
		$key = 'smao_' . substr( hash( 'sha256', DB_NAME . $wpdb->prefix ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
			throw new \RuntimeException( I18n::text( 'Another media operation is running. Retry shortly.' ), 409 );
		}
		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/**
	 * Append a user-attributed event to the activity log.
	 *
	 * @param string $action Action.
	 * @param int    $id Id.
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( string $action, int $id, string $message ): void {
		global $wpdb;
		self::check(
			$wpdb->insert(
				self::table( 'log' ),
				array(
					'created'       => gmdate( 'Y-m-d H:i:s' ),
					'user_id'       => get_current_user_id(),
					'attachment_id' => $id,
					'action'        => $action,
					'message'       => $message,
				)
			)
		);
	}

	/**
	 * Read the persistent scan checkpoint.
	 *
	 * @return array
	 */
	public static function state(): array {
		return (array) get_option( 'smao_scan', array( 'state' => 'idle' ) );
	}

	/**
	 * Persist and verify a scan checkpoint.
	 *
	 * @param array $state State.
	 * @return void
	 * @throws \RuntimeException When database state cannot be safely updated.
	 */
	public static function save_state( array $state ): void {
		update_option( 'smao_scan', $state, false );
		if ( get_option( 'smao_scan' ) !== $state ) {
			throw new \RuntimeException( I18n::text( 'Could not save scan checkpoint.' ) );
		}
	}
}
