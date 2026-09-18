<?php
/**
 * The one media report table, shared by every screen that lists media.
 *
 * PHP owns all markup. The browser never rebuilds rows; it only swaps in the
 * fragment this class produces, which keeps server and client output identical
 * by construction and keeps the table usable with JavaScript disabled.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Filterable, paginated media report.
 */
final class Report_Table {

	/**
	 * Rows shown per page.
	 */
	public const PER_PAGE = 25;

	/**
	 * Human labels for each usage status.
	 *
	 * @return array<string,string>
	 */
	public static function statuses(): array {
		return array(
			'used'        => __( 'Used', 'smart-media-auditor-optimizer' ),
			'possible'    => __( 'Possibly used', 'smart-media-auditor-optimizer' ),
			'unused'      => __( 'Unused candidate', 'smart-media-auditor-optimizer' ),
			'broken'      => __( 'Broken', 'smart-media-auditor-optimizer' ),
			'external'    => __( 'External', 'smart-media-auditor-optimizer' ),
			'download'    => __( 'Download', 'smart-media-auditor-optimizer' ),
			'quarantined' => __( 'Quarantined', 'smart-media-auditor-optimizer' ),
		);
	}

	/**
	 * Render the filter form. A plain GET form, so filters live in the URL.
	 *
	 * @param string $screen Screen slug.
	 * @param array  $input  Current query input.
	 * @return void
	 */
	public static function filters( string $screen, array $input ): void {
		$statuses = array( '' => __( 'All statuses', 'smart-media-auditor-optimizer' ) ) + self::statuses();
		?>
		<form method="get" class="smao-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( 'smao-' . $screen ); ?>">
			<label><?php esc_html_e( 'Filename or ID', 'smart-media-auditor-optimizer' ); ?>
				<input type="search" name="search" value="<?php echo esc_attr( $input['search'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Usage', 'smart-media-auditor-optimizer' ); ?>
				<select name="status">
					<?php foreach ( $statuses as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $input['status'] ?? '' ), (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
			<label><?php esc_html_e( 'Optimization', 'smart-media-auditor-optimizer' ); ?>
				<select name="optimized">
					<?php
					foreach ( array(
						''  => __( 'All', 'smart-media-auditor-optimizer' ),
						'1' => __( 'Optimized', 'smart-media-auditor-optimizer' ),
						'0' => __( 'Not optimized', 'smart-media-auditor-optimizer' ),
					) as $value => $label ) :
						?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $input['optimized'] ?? '' ), (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
			<label><?php esc_html_e( 'Type', 'smart-media-auditor-optimizer' ); ?>
				<input type="search" name="mime" placeholder="image/" value="<?php echo esc_attr( $input['mime'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Sort by', 'smart-media-auditor-optimizer' ); ?>
				<select name="sort">
					<?php
					foreach ( array(
						'attachment_id' => __( 'ID', 'smart-media-auditor-optimizer' ),
						'filename'      => __( 'Filename', 'smart-media-auditor-optimizer' ),
						'bytes'         => __( 'Size', 'smart-media-auditor-optimizer' ),
						'uploaded'      => __( 'Upload date', 'smart-media-auditor-optimizer' ),
						'status'        => __( 'Usage', 'smart-media-auditor-optimizer' ),
						'saved'         => __( 'Savings', 'smart-media-auditor-optimizer' ),
					) as $value => $label ) :
						?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $input['sort'] ?? '' ), (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
			<label><?php esc_html_e( 'Direction', 'smart-media-auditor-optimizer' ); ?>
				<select name="direction">
					<option value="asc" <?php selected( (string) ( $input['direction'] ?? '' ), 'asc' ); ?>><?php esc_html_e( 'Ascending', 'smart-media-auditor-optimizer' ); ?></option>
					<option value="desc" <?php selected( (string) ( $input['direction'] ?? '' ), 'desc' ); ?>><?php esc_html_e( 'Descending', 'smart-media-auditor-optimizer' ); ?></option>
				</select></label>
			<button class="button"><?php esc_html_e( 'Apply filters', 'smart-media-auditor-optimizer' ); ?></button>
			<?php if ( self::filtered( $input ) ) : ?>
				<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=smao-' . $screen ) ); ?>"><?php esc_html_e( 'Reset', 'smart-media-auditor-optimizer' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Report whether the administrator has narrowed the default view.
	 *
	 * @param array $input Query input.
	 * @return bool
	 */
	private static function filtered( array $input ): bool {
		foreach ( array( 'search', 'status', 'optimized', 'mime', 'from', 'until', 'min_bytes', 'max_bytes' ) as $key ) {
			if ( '' !== (string) ( $input[ $key ] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render the refreshable fragment: rows, count and pagination.
	 *
	 * This is the single markup source. The initial page render and every
	 * subsequent refresh both call it, so the two can never drift apart.
	 *
	 * @param string $screen Screen slug.
	 * @param array  $input  Query input.
	 * @return string
	 */
	public static function fragment( string $screen, array $input ): string {
		global $wpdb;
		$input = Live::defaults( $input );
		list( $where, $order ) = Admin::query( $input );
		$table                 = Database::table( 'media' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Admin::query prepares every value; ordering is allowlisted.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		Database::check( $total );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( max( 1, absint( $input['paged'] ?? 1 ) ), $pages );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY $order LIMIT %d OFFSET %d", self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ),
			ARRAY_A
		);
		Database::check( $rows );

		$evidence = self::evidence_for( wp_list_pluck( $rows, 'attachment_id' ) );

		ob_start();
		?>
		<div class="smao-table-meta">
			<span class="smao-result-count">
				<?php
				printf(
					esc_html(
						/* translators: %s is a formatted count of matching media files. */
						_n( '%s matching file', '%s matching files', $total, 'smart-media-auditor-optimizer' )
					),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</span>
		</div>
		<div class="smao-table">
			<table class="widefat striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" class="smao-select-all" aria-label="<?php esc_attr_e( 'Select all rows on this page', 'smart-media-auditor-optimizer' ); ?>"></td>
						<th scope="col"><?php esc_html_e( 'Media', 'smart-media-auditor-optimizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'smart-media-auditor-optimizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Uploaded', 'smart-media-auditor-optimizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Usage', 'smart-media-auditor-optimizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Optimization', 'smart-media-auditor-optimizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Why', 'smart-media-auditor-optimizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr class="smao-empty-row">
						<td colspan="7">
							<?php if ( ! Database::state()['id'] ?? true ) : ?>
								<?php esc_html_e( 'No scan has run yet. Start a scan from the Audit screen to build your media inventory.', 'smart-media-auditor-optimizer' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'No media matches these filters. Try resetting them, or run a fresh scan.', 'smart-media-auditor-optimizer' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php self::row( $row, $evidence[ (int) $row['attachment_id'] ] ?? array() ); ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php self::pagination( $page, $pages, $total ); ?>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one media row.
	 *
	 * @param array $row      Media record.
	 * @param array $evidence Reference locations for this attachment.
	 * @return void
	 */
	private static function row( array $row, array $evidence ): void {
		$id       = (int) $row['attachment_id'];
		$statuses = self::statuses();
		$status   = (string) $row['status'];
		?>
		<tr>
			<th scope="row" class="check-column">
				<input class="smao-id" type="checkbox" value="<?php echo esc_attr( (string) $id ); ?>"
					aria-label="
					<?php
					/* translators: %s is a media filename. */
					echo esc_attr( sprintf( __( 'Select %s', 'smart-media-auditor-optimizer' ), $row['filename'] ) );
					?>
					">
			</th>
			<td class="smao-cell-media">
				<img loading="lazy" width="48" height="48" class="smao-thumb" src="<?php echo esc_url( Live::thumbnail( $row ) ); ?>" alt="">
				<span class="smao-filename"><?php echo esc_html( $row['filename'] ); ?></span>
				<small>#<?php echo esc_html( (string) $id ); ?> &middot; <?php echo esc_html( $row['mime'] ); ?></small>
			</td>
			<td>
				<?php echo esc_html( size_format( (int) $row['bytes'] ) ); ?>
				<small><?php echo esc_html( $row['width'] . ' &times; ' . $row['height'] ); ?></small>
			</td>
			<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row['uploaded'] ) ); ?></td>
			<td><span class="smao-badge smao-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $statuses[ $status ] ?? $status ); ?></span></td>
			<td>
				<?php echo esc_html( $row['optimized'] ? __( 'Optimized', 'smart-media-auditor-optimizer' ) : __( 'Not optimized', 'smart-media-auditor-optimizer' ) ); ?>
				<?php if ( (int) $row['saved'] > 0 ) : ?>
					<small>
						<?php
						/* translators: %s is a formatted file size saved by optimization. */
						printf( esc_html__( '%s saved', 'smart-media-auditor-optimizer' ), esc_html( size_format( (int) $row['saved'] ) ) );
						?>
					</small>
				<?php endif; ?>
			</td>
			<td>
				<details class="smao-evidence">
					<summary><?php esc_html_e( 'Evidence', 'smart-media-auditor-optimizer' ); ?></summary>
					<p><?php echo esc_html( $row['reason'] ); ?></p>
					<?php if ( ! $evidence ) : ?>
						<p class="smao-muted"><?php esc_html_e( 'No indexed reference. This scan cannot detect references from outside your site.', 'smart-media-auditor-optimizer' ); ?></p>
					<?php else : ?>
						<ul class="smao-evidence-list">
						<?php foreach ( $evidence as $location ) : ?>
							<li>
								<code><?php echo esc_html( $location['source'] . ' #' . $location['source_id'] ); ?></code>
								<?php echo esc_html( $location['field'] ); ?>
								<span class="smao-badge smao-strength-<?php echo 2 === (int) $location['strength'] ? 'strong' : 'weak'; ?>">
									<?php echo 2 === (int) $location['strength'] ? esc_html__( 'strong', 'smart-media-auditor-optimizer' ) : esc_html__( 'possible', 'smart-media-auditor-optimizer' ); ?>
								</span>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</details>
			</td>
		</tr>
		<?php
	}

	/**
	 * Load reference evidence for a whole page of rows in one query.
	 *
	 * @param array $ids Attachment IDs.
	 * @return array<int,array>
	 */
	private static function evidence_for( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table        = Database::table( 'evidence' );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are generated from a counted integer array.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT attachment_id,source,source_id,field,strength,kind FROM $table WHERE attachment_id IN ($placeholders) ORDER BY strength DESC,id", $ids ),
			ARRAY_A
		);
		Database::check( $rows );
		$grouped = array();
		foreach ( $rows as $row ) {
			$key = (int) $row['attachment_id'];
			if ( count( $grouped[ $key ] ?? array() ) >= 10 ) {
				continue;
			}
			$grouped[ $key ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Render pagination that keeps the current filters in the URL.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Total pages.
	 * @param int $total Total rows.
	 * @return void
	 */
	private static function pagination( int $page, int $pages, int $total ): void {
		if ( $pages < 2 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => __( 'Previous', 'smart-media-auditor-optimizer' ),
				'next_text' => __( 'Next', 'smart-media-auditor-optimizer' ),
			)
		);
		?>
		<nav class="smao-pagination" aria-label="<?php esc_attr_e( 'Report pages', 'smart-media-auditor-optimizer' ); ?>">
			<span class="smao-muted">
				<?php
				printf(
					/* translators: 1: current page number, 2: total number of pages. */
					esc_html__( 'Page %1$s of %2$s', 'smart-media-auditor-optimizer' ),
					esc_html( number_format_i18n( $page ) ),
					esc_html( number_format_i18n( $pages ) )
				);
				?>
			</span>
			<?php echo wp_kses_post( (string) $links ); ?>
		</nav>
		<?php
	}

	/**
	 * Render the selection and bulk action bar.
	 *
	 * @param array $actions     Action slug to label.
	 * @param bool  $destructive Whether the bar performs an irreversible action.
	 * @return void
	 */
	public static function bulk( array $actions, bool $destructive ): void {
		?>
		<div class="smao-bulk-bar" data-destructive="<?php echo $destructive ? '1' : '0'; ?>">
			<strong class="smao-selection-count"><?php esc_html_e( 'No files selected', 'smart-media-auditor-optimizer' ); ?></strong>
			<?php if ( $destructive ) : ?>
				<label class="smao-ack">
					<input type="checkbox" class="smao-reviewed">
					<?php esc_html_e( 'I reviewed these files and I have an independent backup.', 'smart-media-auditor-optimizer' ); ?>
				</label>
			<?php endif; ?>
			<span class="smao-bulk-actions">
				<?php foreach ( $actions as $action => $label ) : ?>
					<button type="button" class="button<?php echo 'quarantine' === $action || 'purge' === $action ? ' button-primary smao-danger' : ''; ?>"
						data-action="<?php echo esc_attr( $action ); ?>" disabled><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Render the CSV export link for the current filters.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	public static function export_link( array $input ): void {
		$url = add_query_arg(
			array_merge(
				array_intersect_key( $input, array_flip( array( 'status', 'mime', 'optimized', 'search', 'from', 'until', 'min_bytes', 'max_bytes' ) ) ),
				array(
					'action'   => 'smao_export',
					'_wpnonce' => wp_create_nonce( 'smao_export' ),
				)
			),
			admin_url( 'admin-post.php' )
		);
		?>
		<a id="smao-export" class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Export these rows as CSV', 'smart-media-auditor-optimizer' ); ?></a>
		<?php
	}
}
