<?php
/**
 * Live scan telemetry and bounded report reads.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/** Read-only data for the interactive administrator workspace. */
final class Live {
	/**
	 * Return current totals, recent media and job counts for a scan.
	 *
	 * @return array
	 */
	public static function snapshot(): array {
		global $wpdb;
		$state    = Database::state();
		$scan     = $state['id'] ?? '';
		$media    = Database::table( 'media' );
		$evidence = Database::table( 'evidence' );
		$totals   = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS count,SUM(bytes) AS bytes,SUM(saved) AS saved,SUM(optimized) AS optimized FROM %i WHERE scan_id=%s GROUP BY status', $media, $scan ), ARRAY_A );
		Database::check( $totals );
		$summary = array(
			'discovered'  => 0,
			'bytes'       => 0,
			'saved'       => 0,
			'optimized'   => 0,
			'used'        => 0,
			'possible'    => 0,
			'unused'      => 0,
			'broken'      => 0,
			'external'    => 0,
			'download'    => 0,
			'quarantined' => 0,
		);
		foreach ( $totals as $row ) {
			$summary[ $row['status'] ] = (int) $row['count'];
			$summary['discovered']    += (int) $row['count'];
			$summary['bytes']         += (int) $row['bytes'];
			$summary['saved']         += (int) $row['saved'];
			$summary['optimized']     += (int) $row['optimized'];
		}
		$summary['referenced'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT e.attachment_id) FROM %i e INNER JOIN %i m ON m.attachment_id=e.attachment_id WHERE m.scan_id=%s', $evidence, $media, $scan ) );
		Database::check( $summary['referenced'] );
		$recent = $wpdb->get_results( $wpdb->prepare( 'SELECT attachment_id,filename,mime,status,bytes FROM %i WHERE scan_id=%s ORDER BY attachment_id DESC LIMIT 6', $media, $scan ), ARRAY_A );
		Database::check( $recent );
		foreach ( $recent as &$row ) {
			$row['thumbnail'] = self::thumbnail( $row ); }
		unset( $row );
		$counts = $wpdb->get_results( $wpdb->prepare( 'SELECT state,COUNT(*) AS count FROM %i GROUP BY state', Database::table( 'jobs' ) ), ARRAY_A );
		Database::check( $counts );
		$jobs = array_fill_keys( array( 'queued', 'running', 'complete', 'failed', 'cancelled' ), 0 );
		foreach ( $counts as $row ) {
			$jobs[ $row['state'] ] = (int) $row['count']; }
		$settings = Settings::get();
		return array(
			'summary'      => $summary,
			'recent'       => $recent,
			'queue_counts' => $jobs,
			'server_time'  => time(),
			'cleanup'      => self::cleanup(),
			'runtime'      => array_intersect_key( $settings, array_flip( array( 'batch', 'source_batch', 'time_budget', 'poll_interval', 'browser_worker' ) ) ),
		);
	}

	/**
	 * Explain all cleanup prerequisites without changing media or settings.
	 *
	 * @return array
	 */
	public static function cleanup(): array {
		$scan     = Database::state();
		$blockers = array();
		if ( 'complete' !== $scan['state'] ) {
			$blockers[] = __( 'Finish the scan first. Unused results are pending until every source has been checked.', 'smart-media-auditor-optimizer' ); }
		if ( ! empty( $scan['incomplete'] ) ) {
			$blockers[] = __( 'Scan coverage is incomplete. Resolve the reported error and restart.', 'smart-media-auditor-optimizer' ); }
		if ( isset( $scan['epoch'] ) && get_option( 'smao_epoch', '' ) !== $scan['epoch'] ) {
			$blockers[] = __( 'Site content changed during this scan. Restart the scan before removing files.', 'smart-media-auditor-optimizer' ); }
		if ( 'complete' === $scan['state'] && time() - ( $scan['finished'] ?? 0 ) > DAY_IN_SECONDS ) {
			$blockers[] = __( 'This scan is older than 24 hours. Run a fresh scan.', 'smart-media-auditor-optimizer' ); }
		if ( ! Settings::get()['coverage_reviewed'] ) {
			$blockers[] = __( 'Confirm the usage-scope review below, then run a fresh scan to identify unused candidates.', 'smart-media-auditor-optimizer' ); }
		try {
			Vault::root( false );
		} catch ( \Throwable $e ) {
			$blockers[] = __( 'Set up private recovery storage in Settings before removal: ', 'smart-media-auditor-optimizer' ) . $e->getMessage(); }
		if ( is_multisite() ) {
			$blockers[] = __( 'Multisite is report-only; removal is unavailable.', 'smart-media-auditor-optimizer' ); }
		return array(
			'ready'    => ! $blockers,
			'blockers' => $blockers,
		);
	}

	/**
	 * Return one filtered page without sending all reference details.
	 *
	 * @param array $input Validated filter input.
	 * @return array
	 */
	public static function report( array $input ): array {
		global $wpdb;
		$input = array_filter( $input, 'is_scalar' );
		if ( 'smao-review' === ( $input['page'] ?? '' ) && ! isset( $input['status'] ) ) {
			$input['status'] = 'unused'; }
		if ( in_array( $input['page'] ?? '', array( 'smao-optimizer', 'smao-review' ), true ) && ! isset( $input['mime'] ) ) {
			$input['mime'] = 'image/'; }
		list( $where, $order ) = Admin::query( $input );
		$table                 = Database::table( 'media' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin::query prepares all values and allowlists the order clause.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		Database::check( $total );
		$page = min( max( 1, (int) ( $input['paged'] ?? 1 ) ), max( 1, (int) ceil( $total / 25 ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Predicate values are already prepared and sorting is allowlisted.
		$rows = $wpdb->get_results( "SELECT attachment_id,filename,mime,status,bytes,width,height,uploaded,optimized,saved,reason FROM $table WHERE $where ORDER BY $order" . $wpdb->prepare( ' LIMIT 25 OFFSET %d', ( $page - 1 ) * 25 ), ARRAY_A );
		Database::check( $rows );
		foreach ( $rows as &$row ) {
			$row['thumbnail'] = self::thumbnail( $row ); }
		unset( $row );
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
		return array(
			'rows'    => $rows,
			'total'   => $total,
			'page'    => $page,
			'pages'   => max( 1, (int) ceil( $total / 25 ) ),
			'export'  => $export,
			'scan_id' => Database::state()['id'] ?? '',
		);
	}

	/**
	 * Fetch retained reference evidence only when a row is opened.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	public static function evidence( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source,source_id,field,strength,kind FROM %i WHERE attachment_id=%d ORDER BY strength DESC,id LIMIT 100', Database::table( 'evidence' ), $id ), ARRAY_A );
		Database::check( $rows );
		return $rows;
	}

	/**
	 * Select a safe fallback icon for unavailable media.
	 *
	 * @param array $row Media row.
	 * @return string
	 */
	private static function thumbnail( array $row ): string {
		$id  = (int) $row['attachment_id'];
		$url = in_array( $row['status'], array( 'quarantined', 'broken' ), true ) ? false : wp_get_attachment_image_url( $id, 'thumbnail' );
		return esc_url_raw( $url ? $url : wp_mime_type_icon( $id ) );
	}
}
