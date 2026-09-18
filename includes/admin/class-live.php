<?php
/**
 * Read-only data for the administrator workspace.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Live scan telemetry and bounded report reads.
 */
final class Live {

	/**
	 * Return current totals, recent media and queue counts for a scan.
	 *
	 * @return array
	 */
	public static function snapshot(): array {
		global $wpdb;
		$state    = Database::state();
		$scan     = $state['id'] ?? '';
		$media    = Database::table( 'media' );
		$evidence = Database::table( 'evidence' );

		$totals = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status,COUNT(*) AS count,SUM(bytes) AS bytes,SUM(saved) AS saved,SUM(optimized) AS optimized FROM %i WHERE scan_id=%s GROUP BY status',
				$media,
				$scan
			),
			ARRAY_A
		);
		Database::check( $totals );

		$summary = array_fill_keys(
			array( 'discovered', 'bytes', 'saved', 'optimized', 'used', 'possible', 'unused', 'broken', 'external', 'download', 'quarantined' ),
			0
		);
		foreach ( $totals as $row ) {
			$summary[ $row['status'] ] = (int) $row['count'];
			$summary['discovered']    += (int) $row['count'];
			$summary['bytes']         += (int) $row['bytes'];
			$summary['saved']         += (int) $row['saved'];
			$summary['optimized']     += (int) $row['optimized'];
		}

		$summary['referenced'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT e.attachment_id) FROM %i e INNER JOIN %i m ON m.attachment_id=e.attachment_id WHERE m.scan_id=%s',
				$evidence,
				$media,
				$scan
			)
		);
		Database::check( $summary['referenced'] );

		$recent = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT attachment_id,filename,mime,status,bytes FROM %i WHERE scan_id=%s ORDER BY attachment_id DESC LIMIT 6',
				$media,
				$scan
			),
			ARRAY_A
		);
		Database::check( $recent );
		foreach ( $recent as &$row ) {
			$row['thumbnail'] = self::thumbnail( $row );
		}
		unset( $row );

		$counts = $wpdb->get_results(
			$wpdb->prepare( 'SELECT state,COUNT(*) AS count FROM %i GROUP BY state', Database::table( 'jobs' ) ),
			ARRAY_A
		);
		Database::check( $counts );
		$jobs = array_fill_keys( array( 'queued', 'running', 'complete', 'failed', 'cancelled' ), 0 );
		foreach ( $counts as $row ) {
			$jobs[ $row['state'] ] = (int) $row['count'];
		}

		$settings = Settings::get();
		return array(
			'summary'      => $summary,
			'recent'       => $recent,
			'queue_counts' => $jobs,
			'server_time'  => time(),
			'cleanup'      => self::cleanup(),
			'runtime'      => array_intersect_key( $settings, array_flip( Settings::RUNTIME ) ),
		);
	}

	/**
	 * Explain every cleanup prerequisite without changing media or settings.
	 *
	 * @return array{ready:bool,blockers:array,permanent:bool}
	 */
	public static function cleanup(): array {
		$scan      = Database::state();
		$blockers  = array();
		$permanent = false;

		if ( is_multisite() ) {
			// Nothing the administrator does on this screen can clear this.
			$permanent  = true;
			$blockers[] = __( 'Multisite is report-only. Removal is unavailable on this network.', 'smart-media-auditor-optimizer' );
		}
		if ( 'complete' !== ( $scan['state'] ?? '' ) ) {
			$blockers[] = __( 'Finish the scan first. Unused results stay provisional until every source has been checked.', 'smart-media-auditor-optimizer' );
		}
		if ( ! empty( $scan['incomplete'] ) ) {
			$blockers[] = __( 'Scan coverage is incomplete. Resolve the reported error and run a fresh scan.', 'smart-media-auditor-optimizer' );
		}
		if ( isset( $scan['epoch'] ) && get_option( 'smao_epoch', '' ) !== $scan['epoch'] ) {
			$blockers[] = __( 'Site content changed during this scan. Run a fresh scan before removing files.', 'smart-media-auditor-optimizer' );
		}
		if ( 'complete' === ( $scan['state'] ?? '' ) && time() - (int) ( $scan['finished'] ?? 0 ) > DAY_IN_SECONDS ) {
			$blockers[] = __( 'This scan is more than 24 hours old. Run a fresh scan.', 'smart-media-auditor-optimizer' );
		}
		if ( ! Settings::get()['coverage_reviewed'] ) {
			$blockers[] = __( 'Confirm the usage-scope review below, then run a fresh scan to identify unused candidates.', 'smart-media-auditor-optimizer' );
		}
		try {
			Vault::root( false );
		} catch ( \Throwable $e ) {
			/* translators: %s is the reason private recovery storage is unavailable. */
			$blockers[] = sprintf( __( 'Set up private recovery storage before removal: %s', 'smart-media-auditor-optimizer' ), $e->getMessage() );
		}

		return array(
			'ready'     => ! $blockers,
			'blockers'  => $blockers,
			'permanent' => $permanent,
		);
	}

	/**
	 * Return a cheap change signature so polling does not transfer whole reports.
	 *
	 * @param array $input Filter input.
	 * @return array{signature:string,total:int}
	 */
	public static function digest( array $input ): array {
		global $wpdb;
		$input = self::defaults( array_filter( $input, 'is_scalar' ) );
		list( $where ) = Admin::query( $input );
		$table         = Database::table( 'media' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin::query prepares every value.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS total, COALESCE(MAX(attachment_id),0) AS high, COALESCE(SUM(bytes),0) AS bytes, COALESCE(SUM(optimized),0) AS opt FROM $table WHERE $where", ARRAY_A );
		Database::check( $row );
		$scan = Database::state();
		return array(
			'signature' => hash(
				'sha256',
				implode(
					'|',
					array(
						$row['total'] ?? 0,
						$row['high'] ?? 0,
						$row['bytes'] ?? 0,
						$row['opt'] ?? 0,
						$scan['state'] ?? '',
						$scan['id'] ?? '',
					)
				)
			),
			'total'     => (int) ( $row['total'] ?? 0 ),
		);
	}

	/**
	 * Apply the per-screen filter defaults shared by markup and REST reads.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function defaults( array $input ): array {
		$screen = (string) ( $input['screen'] ?? '' );
		if ( 'cleanup' === $screen && ! isset( $input['status'] ) ) {
			$input['status'] = 'unused';
		}
		if ( in_array( $screen, array( 'optimize', 'cleanup' ), true ) && ! isset( $input['mime'] ) ) {
			$input['mime'] = 'image/';
		}
		return $input;
	}

	/**
	 * Fetch retained reference evidence only when a row is opened.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	public static function evidence( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source,source_id,field,strength,kind,provider FROM %i WHERE attachment_id=%d ORDER BY strength DESC,id LIMIT 100',
				Database::table( 'evidence' ),
				$id
			),
			ARRAY_A
		);
		Database::check( $rows );
		return $rows;
	}

	/**
	 * Select a safe fallback icon for unavailable media.
	 *
	 * @param array $row Media row.
	 * @return string
	 */
	public static function thumbnail( array $row ): string {
		$id  = (int) $row['attachment_id'];
		$url = in_array( $row['status'], array( 'quarantined', 'broken' ), true )
			? false
			: wp_get_attachment_image_url( $id, 'thumbnail' );
		return esc_url_raw( $url ? $url : (string) wp_mime_type_icon( $id ) );
	}
}
