<?php
/**
 * Audit screen: run the scan and read the inventory it produces.
 *
 * Sole owner of the throughput controls. No other screen may write them.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Scan control and media inventory.
 */
final class Screen_Audit {

	/**
	 * Render the screen.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	public static function render( array $input ): void {
		self::metrics();
		self::scan_card();
		self::expert();
		?>
		<section class="smao-panel">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Media inventory', 'smart-media-auditor-optimizer' ); ?></h2>
				<?php Report_Table::export_link( $input ); ?>
			</div>
			<?php Report_Table::filters( 'audit', $input, 'scan' ); ?>
			<div id="smao-report" data-screen="audit">
				<?php echo Report_Table::fragment( 'audit', $input ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragment escapes every value at source. ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the live totals for the current scan.
	 *
	 * @return void
	 */
	private static function metrics(): void {
		$metrics = array(
			'discovered' => __( 'Files found', 'smart-media-auditor-optimizer' ),
			'referenced' => __( 'With references', 'smart-media-auditor-optimizer' ),
			'unused'     => __( 'Unused candidates', 'smart-media-auditor-optimizer' ),
			'broken'     => __( 'Missing or broken', 'smart-media-auditor-optimizer' ),
			'bytes'      => __( 'Inventoried storage', 'smart-media-auditor-optimizer' ),
			'saved'      => __( 'Saved so far', 'smart-media-auditor-optimizer' ),
		);
		?>
		<section class="smao-metrics" aria-label="<?php esc_attr_e( 'Live scan totals', 'smart-media-auditor-optimizer' ); ?>">
			<?php foreach ( $metrics as $key => $label ) : ?>
				<article class="smao-metric">
					<span><?php echo esc_html( $label ); ?></span>
					<strong data-metric="<?php echo esc_attr( $key ); ?>">&mdash;</strong>
				</article>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Render scan progress and the scan control buttons.
	 *
	 * @return void
	 */
	private static function scan_card(): void {
		?>
		<div class="smao-grid">
			<section class="smao-panel smao-scan-card" aria-labelledby="smao-scan-title">
				<div class="smao-panel-head">
					<h2 id="smao-scan-title"><?php esc_html_e( 'Scan your media', 'smart-media-auditor-optimizer' ); ?></h2>
					<span id="smao-state" class="smao-badge">&mdash;</span>
				</div>
				<p class="smao-muted"><?php esc_html_e( 'Scanning reads your site and never changes a file. It builds an inventory, traces where each file is referenced, then classifies what it found.', 'smart-media-auditor-optimizer' ); ?></p>

				<div class="smao-progress-head">
					<strong id="smao-phase-label"><?php esc_html_e( 'Ready to scan', 'smart-media-auditor-optimizer' ); ?></strong>
					<span id="smao-percent">&mdash;</span>
				</div>
				<progress id="smao-progress-bar" max="100" value="0" aria-label="<?php esc_attr_e( 'Overall scan progress', 'smart-media-auditor-optimizer' ); ?>"></progress>
				<div class="smao-progress-meta">
					<span id="smao-work-count">&mdash;</span>
					<span id="smao-elapsed"></span>
				</div>

				<ol class="smao-steps">
					<?php
					foreach ( array(
						'inventory' => __( 'Find files', 'smart-media-auditor-optimizer' ),
						'sources'   => __( 'Trace references', 'smart-media-auditor-optimizer' ),
						'classify'  => __( 'Classify', 'smart-media-auditor-optimizer' ),
					) as $phase => $label ) :
						?>
						<li data-phase="<?php echo esc_attr( $phase ); ?>">
							<span><?php echo esc_html( $label ); ?></span>
							<small data-phase-count="<?php echo esc_attr( $phase ); ?>">&mdash;</small>
						</li>
					<?php endforeach; ?>
				</ol>

				<div class="smao-current">
					<div>
						<small><?php esc_html_e( 'Latest checkpoint', 'smart-media-auditor-optimizer' ); ?></small>
						<strong id="smao-current-file">&mdash;</strong>
					</div>
					<span id="smao-freshness" class="smao-muted"></span>
				</div>

				<p id="smao-progress" class="screen-reader-text" role="status" aria-live="polite"></p>
				<p id="smao-scan-warning" class="smao-inline-warning" role="status" hidden></p>

				<div class="smao-controls smao-scan-actions">
					<button type="button" class="button button-primary" data-command="start" hidden><?php esc_html_e( 'Start scan', 'smart-media-auditor-optimizer' ); ?></button>
					<button type="button" class="button" data-command="pause" hidden><?php esc_html_e( 'Pause', 'smart-media-auditor-optimizer' ); ?></button>
					<button type="button" class="button button-primary" data-command="resume" hidden><?php esc_html_e( 'Resume', 'smart-media-auditor-optimizer' ); ?></button>
					<button type="button" class="button" data-command="cancel" hidden><?php esc_html_e( 'Cancel', 'smart-media-auditor-optimizer' ); ?></button>
					<button type="button" class="button" data-command="restart" hidden><?php esc_html_e( 'Start a fresh scan', 'smart-media-auditor-optimizer' ); ?></button>
					<button type="button" class="button" data-command="retry" hidden><?php esc_html_e( 'Retry processing', 'smart-media-auditor-optimizer' ); ?></button>
				</div>
				<div class="smao-connection">
					<span id="smao-connection"><?php esc_html_e( 'Connecting…', 'smart-media-auditor-optimizer' ); ?></span>
					<span id="smao-worker-mode" class="smao-muted"></span>
				</div>
			</section>

			<aside class="smao-panel smao-activity-card">
				<div class="smao-panel-head">
					<h2><?php esc_html_e( 'Activity', 'smart-media-auditor-optimizer' ); ?></h2>
				</div>
				<ol id="smao-activity" class="smao-activity">
					<li class="smao-empty"><?php esc_html_e( 'Scan activity appears here.', 'smart-media-auditor-optimizer' ); ?></li>
				</ol>
			</aside>
		</div>
		<?php
	}

	/**
	 * Render the throughput controls. This screen owns these values.
	 *
	 * @return void
	 */
	private static function expert(): void {
		$settings = Settings::get();
		?>
		<details class="smao-panel smao-expert">
			<summary>
				<span><?php esc_html_e( 'Processing options', 'smart-media-auditor-optimizer' ); ?></span>
				<small><?php esc_html_e( 'Throughput and responsiveness. Changing these never affects what a scan finds.', 'smart-media-auditor-optimizer' ); ?></small>
			</summary>
			<form id="smao-runtime" class="smao-runtime">
				<div class="smao-field-grid">
					<?php
					foreach ( array(
						'batch'         => __( 'Files per batch', 'smart-media-auditor-optimizer' ),
						'source_batch'  => __( 'Source rows per batch', 'smart-media-auditor-optimizer' ),
						'time_budget'   => __( 'Time budget (seconds)', 'smart-media-auditor-optimizer' ),
						'poll_interval' => __( 'Refresh interval (seconds)', 'smart-media-auditor-optimizer' ),
					) as $key => $label ) :
						list( $min, $max ) = Settings::bounds( $key );
						?>
						<label>
							<?php echo esc_html( $label ); ?>
							<input type="number" name="<?php echo esc_attr( $key ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="1" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" required>
							<small><?php echo esc_html( sprintf( '%d - %d', $min, $max ) ); ?></small>
						</label>
					<?php endforeach; ?>
				</div>
				<label class="smao-checkbox">
					<input type="checkbox" name="browser_worker" <?php checked( $settings['browser_worker'] ); ?>>
					<?php esc_html_e( 'Keep work moving while this page is open', 'smart-media-auditor-optimizer' ); ?>
				</label>
				<p class="smao-muted"><?php esc_html_e( 'Smaller batches suit shared hosting. When this page is closed, WP-Cron continues the work.', 'smart-media-auditor-optimizer' ); ?></p>
				<button class="button"><?php esc_html_e( 'Apply processing options', 'smart-media-auditor-optimizer' ); ?></button>
			</form>
		</details>
		<?php
	}
}
