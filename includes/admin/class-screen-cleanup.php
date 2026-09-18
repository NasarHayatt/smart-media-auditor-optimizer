<?php
/**
 * Clean up screen: review unused candidates, quarantine them, recover them.
 *
 * Sole owner of the scope-review acknowledgement.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Review, removal and recovery in one place.
 */
final class Screen_Cleanup {

	/**
	 * Render the screen.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	public static function render( array $input ): void {
		$cleanup = Live::cleanup();
		self::prerequisites( $cleanup );
		self::candidates( $input );
		self::recovery();
	}

	/**
	 * Explain what must be true before anything can be removed.
	 *
	 * @param array $cleanup Cleanup readiness.
	 * @return void
	 */
	private static function prerequisites( array $cleanup ): void {
		?>
		<section class="smao-panel smao-prereq<?php echo $cleanup['ready'] ? ' is-ready' : ''; ?>">
			<h2 id="smao-cleanup-title">
				<?php
				echo $cleanup['ready']
					? esc_html__( 'Ready to review unused images', 'smart-media-auditor-optimizer' )
					: esc_html__( 'Before you remove anything', 'smart-media-auditor-optimizer' );
				?>
			</h2>
			<p><?php esc_html_e( 'Removing a file moves it out of your uploads folder into private recovery storage. The attachment record stays, so the file can be restored. Nothing is ever deleted automatically.', 'smart-media-auditor-optimizer' ); ?></p>

			<ul id="smao-cleanup-reasons" class="smao-blockers">
				<?php if ( ! $cleanup['blockers'] ) : ?>
					<li class="smao-ok"><?php esc_html_e( 'All prerequisites are met. Review the evidence, select files, then remove them.', 'smart-media-auditor-optimizer' ); ?></li>
				<?php else : ?>
					<?php foreach ( $cleanup['blockers'] as $reason ) : ?>
						<li><?php echo esc_html( $reason ); ?></li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>

			<?php if ( $cleanup['permanent'] ) : ?>
				<p class="smao-inline-warning"><?php esc_html_e( 'This screen is read-only on multisite. You can review findings and export them, but removal is not available.', 'smart-media-auditor-optimizer' ); ?></p>
			<?php else : ?>
				<form id="smao-scope" <?php echo $cleanup['ready'] ? 'hidden' : ''; ?>>
					<label class="smao-checkbox">
						<input type="checkbox" name="scope_reviewed" required>
						<?php esc_html_e( 'I have checked custom code, private tables and any external use this scan cannot see.', 'smart-media-auditor-optimizer' ); ?>
					</label>
					<p>
						<button class="button button-primary"><?php esc_html_e( 'Confirm scope and start a fresh scan', 'smart-media-auditor-optimizer' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=smao-settings&tab=recovery' ) ); ?>"><?php esc_html_e( 'Set up recovery storage', 'smart-media-auditor-optimizer' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
			<p id="smao-review-progress" class="smao-muted" role="status"></p>
		</section>
		<?php
	}

	/**
	 * Render the unused-candidate table and its removal action.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	private static function candidates( array $input ): void {
		?>
		<section class="smao-panel smao-selectable" data-scope="candidates">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Unused candidates', 'smart-media-auditor-optimizer' ); ?></h2>
				<?php Report_Table::export_link( Live::defaults( $input + array( 'screen' => 'cleanup' ) ) ); ?>
			</div>
			<?php Report_Table::filters( 'cleanup', Live::defaults( $input + array( 'screen' => 'cleanup' ) ) ); ?>
			<div id="smao-report" data-screen="cleanup">
				<?php echo Report_Table::fragment( 'cleanup', $input + array( 'screen' => 'cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes at source. ?>
			</div>
			<?php
			Report_Table::bulk(
				array( 'quarantine' => __( 'Remove selected, keeping a recovery copy', 'smart-media-auditor-optimizer' ) ),
				true
			);
			?>
		</section>
		<?php
	}

	/**
	 * Render the recovery journal with restore and purge actions.
	 *
	 * @return void
	 */
	private static function recovery(): void {
		global $wpdb;
		$table = Database::table( 'vault' );
		$page  = max( 1, absint( $_GET['vault_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		/*
		 * Only quarantine records belong here. The vault also holds optimization
		 * backups, which are restore points for compression, not files anyone
		 * removed. Listing those made this table claim files had been deleted
		 * that never were, and offered a purge that could only ever fail.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table name.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE operation='quarantine'" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT attachment_id,operation,state,created FROM $table WHERE operation='quarantine' ORDER BY created DESC LIMIT 25 OFFSET %d", ( $page - 1 ) * 25 ),
			ARRAY_A
		);
		Database::check( $rows );
		$retention = (int) Settings::get()['retention_days'];
		$eligible  = static function ( array $row ) use ( $retention ): bool {
			return time() - (int) $row['created'] >= $retention * DAY_IN_SECONDS;
		};
		?>
		<section class="smao-panel smao-selectable" data-scope="recovery" id="smao-recovery">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Recovery', 'smart-media-auditor-optimizer' ); ?></h2>
				<span class="smao-muted">
					<?php
					printf(
						esc_html(
							/* translators: %s is a number of days. */
							_n( 'Retained at least %s day', 'Retained at least %s days', $retention, 'smart-media-auditor-optimizer' )
						),
						esc_html( number_format_i18n( $retention ) )
					);
					?>
				</span>
			</div>
			<p><?php esc_html_e( 'Files you removed are kept here. Restore puts a file back where it came from. Purge permanently deletes the recovery copy and cannot be undone.', 'smart-media-auditor-optimizer' ); ?></p>
			<div class="smao-table">
				<table class="widefat striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" class="smao-select-all" aria-label="<?php esc_attr_e( 'Select all recovery records on this page', 'smart-media-auditor-optimizer' ); ?>"></td>
							<th scope="col"><?php esc_html_e( 'File', 'smart-media-auditor-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Removed', 'smart-media-auditor-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Can be restored', 'smart-media-auditor-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Can be deleted permanently', 'smart-media-auditor-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Nothing has been removed. When you remove files, their recovery records appear here.', 'smart-media-auditor-optimizer' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $id = (int) $row['attachment_id']; ?>
						<tr>
							<th scope="row" class="check-column">
								<input class="smao-id smao-vault-id" type="checkbox" value="<?php echo esc_attr( (string) $id ); ?>"
									aria-label="
									<?php
									/* translators: %d is an attachment ID. */
									echo esc_attr( sprintf( __( 'Select recovery record for attachment %d', 'smart-media-auditor-optimizer' ), $id ) );
									?>
									">
							</th>
							<td><?php echo esc_html( get_the_title( $id ) ?: '#' . $id ); ?><small>#<?php echo esc_html( (string) $id ); ?></small></td>
							<td><?php echo esc_html( gmdate( 'j M Y', (int) $row['created'] ) ); ?></td>
							<td><span class="smao-badge smao-status-used"><?php esc_html_e( 'Yes, any time', 'smart-media-auditor-optimizer' ); ?></span></td>
							<td>
								<?php if ( $eligible( $row ) ) : ?>
									<span class="smao-badge smao-status-unused"><?php esc_html_e( 'Yes', 'smart-media-auditor-optimizer' ); ?></span>
								<?php else : ?>
									<span class="smao-badge">
										<?php
										printf(
											/* translators: %s is a date. */
											esc_html__( 'Not until %s', 'smart-media-auditor-optimizer' ),
											esc_html( gmdate( 'j M Y', (int) $row['created'] + $retention * DAY_IN_SECONDS ) )
										);
										?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $total > 25 ) : ?>
				<nav class="smao-pagination" aria-label="<?php esc_attr_e( 'Recovery pages', 'smart-media-auditor-optimizer' ); ?>">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => add_query_arg( 'vault_page', '%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => (int) ceil( $total / 25 ),
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>
			<?php
			Report_Table::bulk(
				array(
					'restore' => __( 'Restore selected', 'smart-media-auditor-optimizer' ),
					'purge'   => __( 'Permanently delete recovery copies', 'smart-media-auditor-optimizer' ),
				),
				true
			);
			?>
		</section>
		<?php
	}
}
