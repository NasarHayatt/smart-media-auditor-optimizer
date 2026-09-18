<?php
/**
 * Optimize screen: compress images and see which ones are oversized.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Image queue and dimension guidance.
 */
final class Screen_Optimize {

	/**
	 * Render the screen.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	public static function render( array $input ): void {
		self::queue();
		self::oversized();
		?>
		<section class="smao-panel smao-selectable" data-scope="optimize">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Images', 'smart-media-auditor-optimizer' ); ?></h2>
				<?php Report_Table::export_link( Live::defaults( $input + array( 'screen' => 'optimize' ) ) ); ?>
			</div>
			<?php Report_Table::filters( 'optimize', Live::defaults( $input + array( 'screen' => 'optimize' ) ), 'optimize' ); ?>
			<div id="smao-report" data-screen="optimize">
				<?php echo Report_Table::fragment( 'optimize', $input + array( 'screen' => 'optimize' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes at source. ?>
			</div>
			<?php
			Report_Table::bulk(
				array(
					'optimize'   => __( 'Optimize selected', 'smart-media-auditor-optimizer' ),
					'thumbnails' => __( 'Generate missing sizes', 'smart-media-auditor-optimizer' ),
					'restore'    => __( 'Restore originals', 'smart-media-auditor-optimizer' ),
				),
				false
			);
			?>
		</section>
		<?php
	}

	/**
	 * Render the optimization queue and its controls.
	 *
	 * @return void
	 */
	private static function queue(): void {
		$settings = Settings::get();
		$capable  = Optimizer::capabilities();
		?>
		<section class="smao-panel">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Optimization queue', 'smart-media-auditor-optimizer' ); ?></h2>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=smao-advanced&tab=images' ) ); ?>"><?php esc_html_e( 'Image settings', 'smart-media-auditor-optimizer' ); ?></a>
			</div>

			<?php if ( 'lossless' === $settings['compression'] && empty( $capable['imagick'] ) ) : ?>
				<p class="smao-inline-warning">
					<?php esc_html_e( 'Lossless compression needs ImageMagick, which this server does not have. Optimization will not run until you either install ImageMagick or choose lossy compression in Image settings.', 'smart-media-auditor-optimizer' ); ?>
				</p>
			<?php endif; ?>

			<p class="smao-muted"><?php esc_html_e( 'Originals are copied to recovery storage before anything is recompressed, so you can always restore them. Reported savings exclude those backups and any alternate formats.', 'smart-media-auditor-optimizer' ); ?></p>

			<div id="smao-queue-counts" class="smao-queue-counts"></div>
			<div class="smao-controls">
				<button type="button" class="button" data-command="pause_jobs"><?php esc_html_e( 'Pause queue', 'smart-media-auditor-optimizer' ); ?></button>
				<button type="button" class="button" data-command="resume_jobs"><?php esc_html_e( 'Resume queue', 'smart-media-auditor-optimizer' ); ?></button>
				<button type="button" class="button" data-command="cancel_jobs"><?php esc_html_e( 'Cancel queued jobs', 'smart-media-auditor-optimizer' ); ?></button>
				<button type="button" class="button" data-command="tick"><?php esc_html_e( 'Process next job', 'smart-media-auditor-optimizer' ); ?></button>
			</div>
			<div id="smao-jobs" class="smao-jobs"></div>
		</section>
		<?php
	}

	/**
	 * Report source images larger than the configured maximum.
	 *
	 * @return void
	 */
	private static function oversized(): void {
		global $wpdb;
		$settings = Settings::get();
		$table    = Database::table( 'media' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id,filename,width,height,bytes FROM $table WHERE width>%d OR height>%d ORDER BY bytes DESC LIMIT 25",
				$settings['max_width'],
				$settings['max_height']
			),
			ARRAY_A
		);
		Database::check( $rows );
		?>
		<section class="smao-panel">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Oversized images', 'smart-media-auditor-optimizer' ); ?></h2>
				<span class="smao-muted">
					<?php
					printf(
						/* translators: 1: maximum width in pixels, 2: maximum height in pixels. */
						esc_html__( 'Larger than %1$d × %2$d', 'smart-media-auditor-optimizer' ),
						(int) $settings['max_width'],
						(int) $settings['max_height']
					);
					?>
				</span>
			</div>
			<?php if ( ! $rows ) : ?>
				<p class="smao-muted"><?php esc_html_e( 'No source image exceeds your maximum dimensions. If you have not scanned yet, run a scan from the Audit screen first.', 'smart-media-auditor-optimizer' ); ?></p>
			<?php else : ?>
				<p class="smao-muted"><?php esc_html_e( 'These are source dimensions, not how large the image appears on screen. Resizing changes pixels, so it stays opt-in.', 'smart-media-auditor-optimizer' ); ?></p>
				<div class="smao-table">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'File', 'smart-media-auditor-optimizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Dimensions', 'smart-media-auditor-optimizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Size', 'smart-media-auditor-optimizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['filename'] ); ?><small>#<?php echo esc_html( $row['attachment_id'] ); ?></small></td>
								<td><?php echo esc_html( $row['width'] . ' × ' . $row['height'] ); ?></td>
								<td><?php echo esc_html( size_format( (int) $row['bytes'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}
}
