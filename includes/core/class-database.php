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
		if ( ! in_array( $name, array( 'media', 'tokens', 'evidence', 'vault', 'log', 'jobs', 'pages', 'render' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Unknown table.', 'smart-media-auditor-optimizer' ) );
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
				impact_score int NOT NULL DEFAULT 0,
				reason text NOT NULL,
				details longtext NOT NULL,
				PRIMARY KEY  (attachment_id),
				KEY scan_status (scan_id,status),
				KEY mime (mime),
				KEY uploaded (uploaded),
				KEY bytes (bytes),
				KEY impact (impact_score)",
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
				provider varchar(32) NOT NULL DEFAULT \'core\',
				fingerprint char(64) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY location (attachment_id,fingerprint),
				KEY strength (attachment_id,strength),
				KEY provider (provider)',
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
			/*
			 * Reserved for 2.1/2.2 per-page attribution. Created now, populated
			 * later, so feature releases ship without migrating live installs.
			 */
			'pages'    => 'url_hash char(64) NOT NULL,
				url varchar(255) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				image_count int unsigned NOT NULL DEFAULT 0,
				image_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				lcp_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				measured_at bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (url_hash),
				KEY object_id (object_id),
				KEY image_bytes (image_bytes)',
			/*
			 * One row per image, per measured page, per viewport width. The
			 * viewport is part of the key because an image renders at a
			 * different size on a phone than on a desktop, and the sizes
			 * attribute has to describe both.
			 */
			'render'   => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attachment_id bigint(20) unsigned NOT NULL,
				url_hash char(64) NOT NULL,
				viewport int unsigned NOT NULL DEFAULT 0,
				rendered_width int unsigned NOT NULL DEFAULT 0,
				rendered_height int unsigned NOT NULL DEFAULT 0,
				observed_at bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY observation (attachment_id,url_hash,viewport),
				KEY attachment_id (attachment_id)',
		);
		/*
		 * The render table changed shape in 2.1 and has never held data worth
		 * keeping, so recreate it rather than carry a fragile column migration.
		 */
		if ( version_compare( (string) get_option( 'smao_schema', '0' ), '2.1.0', '<' ) ) {
			$render = self::table( 'render' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table name.
			$wpdb->query( "DROP TABLE IF EXISTS `$render`" );
		}

		foreach ( $definitions as $name => $sql ) {
			dbDelta( 'CREATE TABLE ' . self::table( $name ) . " (\n$sql\n) $collate;" );
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( __( 'Could not install plugin tables.', 'smart-media-auditor-optimizer' ) );
			}
		}
		// Captures from before 2.3.2 were never verified and must not be used.
		delete_option( 'smao_critical_css' );
		/*
		 * Background stylesheet loading was on by default until 2.3.5. On an
		 * image-heavy Elementor site it lowered the PageSpeed score from 65 to
		 * 47, so it is switched off once on update and left to the owner.
		 */
		// 2.3.6 makes WebP copies and serves them automatically.
		if ( version_compare( (string) get_option( 'smao_schema', '0' ), '2.3.6', '<' ) ) {
			$stored = get_option( 'smao_settings' );
			if ( is_array( $stored ) && empty( $stored['delivery'] ) ) {
				$stored['delivery'] = true;
				update_option( 'smao_settings', $stored );
			}
		}
		if ( version_compare( (string) get_option( 'smao_schema', '0' ), '2.3.5', '<' ) ) {
			$stored = get_option( 'smao_settings' );
			if ( is_array( $stored ) && ! empty( $stored['async_css'] ) ) {
				$stored['async_css'] = false;
				update_option( 'smao_settings', $stored );
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
			throw new \RuntimeException( __( 'Database operation failed. Scan or action stopped safely.', 'smart-media-auditor-optimizer' ) );
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
			throw new \RuntimeException( __( 'Another media operation is running. Retry shortly.', 'smart-media-auditor-optimizer' ), 409 );
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
			throw new \RuntimeException( __( 'Could not save scan checkpoint.', 'smart-media-auditor-optimizer' ) );
		}
	}
}
