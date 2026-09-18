<?php
/**
 * Interactive administrator workspace markup.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/** Accessible layout with progressive enhancement through local assets. */
final class Workspace {
	/**
	 * Put the main workflow in front of the user on every media screen.
	 *
	 * @return void
	 */
	public static function flow(): void {
		echo '<nav class="smao-workflow" aria-label="' . esc_attr__( 'Media cleanup steps', 'smart-media-auditor-optimizer' ) . '">';
		foreach ( array(
			'scanner'    => '1. Scan media',
			'review'     => '2. Review & remove unused images',
			'quarantine' => '3. Restore or permanently delete',
		) as $slug => $label ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=smao-' . $slug ) ) . '">' . esc_html( I18n::text( $label ) ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Show cleanup requirements and an explicit scope-review action.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		?>
		<section class="smao-cleanup-card">
		<h2 id="smao-cleanup-title"><?php esc_html_e( 'Remove unused images', 'smart-media-auditor-optimizer' ); ?></h2>
		<p><?php esc_html_e( 'Select reviewed unused images in the table, then choose Remove selected. Files leave your uploads folder and a private recovery copy is kept. Restore or permanently delete recovery copies from Recovery after the retention period.', 'smart-media-auditor-optimizer' ); ?></p>
		<ul id="smao-cleanup-reasons">
		<?php
		foreach ( Live::cleanup()['blockers'] as $reason ) {
			echo '<li>' . esc_html( $reason ) . '</li>'; }
		?>
		</ul>
		<form id="smao-scope"><label><input type="checkbox" name="scope_reviewed" required> <?php esc_html_e( 'I checked custom code, private tables and external uses that this scan cannot detect.', 'smart-media-auditor-optimizer' ); ?></label><p><button class="button button-primary"><?php esc_html_e( 'Confirm scope & start fresh scan', 'smart-media-auditor-optimizer' ); ?></button> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=smao-settings#smao-recovery-setup' ) ); ?>"><?php esc_html_e( 'Set up recovery storage', 'smart-media-auditor-optimizer' ); ?></a></p></form>
		<p id="smao-review-progress" role="status"></p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=smao-scanner' ) ); ?>"><?php esc_html_e( 'View scan progress and recovery controls', 'smart-media-auditor-optimizer' ); ?></a>
		</section>
		<?php
	}

	/**
	 * Configure recovery without editing a PHP configuration file.
	 *
	 * @return void
	 */
	public static function storage(): void {
		?>
		<section id="smao-recovery-setup" class="smao-cleanup-card"><h2><?php esc_html_e( 'Private recovery storage', 'smart-media-auditor-optimizer' ); ?></h2>
		<p><?php esc_html_e( 'Create an empty writable folder outside your website and public web root, then enter its absolute path below. Example for XAMPP: D:/xampp/smao-recovery (outside htdocs). Keep recovery copies until you are ready to permanently delete them.', 'smart-media-auditor-optimizer' ); ?></p>
		<?php if ( defined( 'SMAO_VAULT_DIR' ) ) : ?>
		<p><?php esc_html_e( 'This site manages recovery storage with SMAO_VAULT_DIR in wp-config.php.', 'smart-media-auditor-optimizer' ); ?></p>
		<?php else : ?>
		<form id="smao-storage"><label><?php esc_html_e( 'Existing private folder', 'smart-media-auditor-optimizer' ); ?><input type="text" name="path" required value="<?php echo esc_attr( get_option( 'smao_vault_path', '' ) ); ?>"></label><button class="button"><?php esc_html_e( 'Validate & save recovery folder', 'smart-media-auditor-optimizer' ); ?></button></form>
		<?php endif; ?></section>
		<?php
	}

	/**
	 * Render scan telemetry, live findings and expert processing controls.
	 *
	 * @return void
	 */
	public static function scan(): void {
		?>
		<section class="smao-metrics" aria-label="<?php esc_attr_e( 'Live media totals', 'smart-media-auditor-optimizer' ); ?>">
			<?php
			foreach ( array(
				'discovered' => __( 'Files discovered', 'smart-media-auditor-optimizer' ),
				'referenced' => __( 'With reference evidence', 'smart-media-auditor-optimizer' ),
				'unused'     => __( 'Unused candidates', 'smart-media-auditor-optimizer' ),
				'broken'     => __( 'Missing / broken', 'smart-media-auditor-optimizer' ),
				'bytes'      => __( 'Inventoried storage', 'smart-media-auditor-optimizer' ),
				'saved'      => __( 'Live image savings', 'smart-media-auditor-optimizer' ),
			) as $key => $label ) :
				?>
			<article class="smao-metric"><span><?php echo esc_html( $label ); ?></span><strong data-metric="<?php echo esc_attr( $key ); ?>">—</strong><small><?php esc_html_e( 'Current scan', 'smart-media-auditor-optimizer' ); ?></small></article>
			<?php endforeach; ?>
		</section>
		<div class="smao-workspace-grid">
		<section class="smao-scan-card" aria-labelledby="smao-scan-title">
			<div class="smao-card-top"><span class="smao-eyebrow"><?php esc_html_e( 'LIVE SCAN', 'smart-media-auditor-optimizer' ); ?></span><span id="smao-state" class="smao-badge">—</span></div>
			<h2 id="smao-scan-title"><?php esc_html_e( 'Know what your media is doing.', 'smart-media-auditor-optimizer' ); ?></h2>
			<p class="smao-muted"><?php esc_html_e( 'Inventory your files, trace their references, then review the results. Scanning never changes your media.', 'smart-media-auditor-optimizer' ); ?></p>
			<div class="smao-progress-heading"><strong id="smao-phase-label"><?php esc_html_e( 'Ready to scan', 'smart-media-auditor-optimizer' ); ?></strong><span id="smao-percent">0%</span></div>
			<progress id="smao-progress-bar" max="100" value="0" aria-label="<?php esc_attr_e( 'Overall scan progress', 'smart-media-auditor-optimizer' ); ?>"></progress>
			<div class="smao-progress-meta"><span id="smao-work-count">—</span><span id="smao-elapsed">—</span></div>
			<ol class="smao-steps"><li data-phase="inventory"><b>01</b><span><?php esc_html_e( 'Discover files', 'smart-media-auditor-optimizer' ); ?></span><small data-phase-count="inventory">—</small></li><li data-phase="sources"><b>02</b><span><?php esc_html_e( 'Trace references', 'smart-media-auditor-optimizer' ); ?></span><small data-phase-count="sources">—</small></li><li data-phase="classify"><b>03</b><span><?php esc_html_e( 'Classify & review', 'smart-media-auditor-optimizer' ); ?></span><small data-phase-count="classify">—</small></li></ol>
			<div class="smao-current"><span class="smao-pulse"></span><div><small><?php esc_html_e( 'LATEST CHECKPOINT', 'smart-media-auditor-optimizer' ); ?></small><strong id="smao-current-file">—</strong></div><span id="smao-freshness"></span></div>
			<p id="smao-progress" class="screen-reader-text" role="status" aria-live="polite"></p>
			<p id="smao-scan-warning" class="smao-inline-warning" hidden></p>
			<div class="smao-controls smao-scan-actions"><button type="button" class="button button-primary" data-command="start"><?php esc_html_e( 'Start media scan', 'smart-media-auditor-optimizer' ); ?></button><button type="button" class="button" data-command="pause" hidden><?php esc_html_e( 'Pause', 'smart-media-auditor-optimizer' ); ?></button><button type="button" class="button button-primary" data-command="resume" hidden><?php esc_html_e( 'Resume scan', 'smart-media-auditor-optimizer' ); ?></button><button type="button" class="button smao-danger-text" data-command="cancel" hidden><?php esc_html_e( 'Cancel scan', 'smart-media-auditor-optimizer' ); ?></button><button type="button" class="button" data-command="restart" hidden><?php esc_html_e( 'Restart fresh scan', 'smart-media-auditor-optimizer' ); ?></button><button type="button" class="button" data-command="retry"><?php esc_html_e( 'Retry processing', 'smart-media-auditor-optimizer' ); ?></button><button type="button" class="button smao-secondary" data-command="tick"><?php esc_html_e( 'Run one batch', 'smart-media-auditor-optimizer' ); ?></button></div>
			<div class="smao-connection"><span id="smao-connection"><?php esc_html_e( 'Connecting to live updates…', 'smart-media-auditor-optimizer' ); ?></span><span id="smao-worker-mode"></span></div>
		</section>
		<aside class="smao-activity-card"><div class="smao-card-top"><h2><?php esc_html_e( 'Scan activity', 'smart-media-auditor-optimizer' ); ?></h2><span class="smao-live-dot"><?php esc_html_e( 'LIVE', 'smart-media-auditor-optimizer' ); ?></span></div><p class="smao-muted"><?php esc_html_e( 'The latest files and source checkpoints.', 'smart-media-auditor-optimizer' ); ?></p><ol id="smao-activity" class="smao-activity"><li class="smao-empty"><?php esc_html_e( 'Your scan activity will appear here.', 'smart-media-auditor-optimizer' ); ?></li></ol></aside>
		</div>
		<section class="smao-discovery"><div class="smao-section-heading"><h2><?php esc_html_e( 'Recently discovered', 'smart-media-auditor-optimizer' ); ?></h2><span class="smao-muted"><?php esc_html_e( 'Updates after every batch', 'smart-media-auditor-optimizer' ); ?></span></div><div id="smao-recent" class="smao-media-grid"><p class="smao-empty"><?php esc_html_e( 'Start a scan to discover images and other media.', 'smart-media-auditor-optimizer' ); ?></p></div></section>
		<?php self::expert(); ?>
		<?php
	}

	/**
	 * Render bounded worker options without allowing safety checks to be disabled.
	 *
	 * @return void
	 */
	public static function expert(): void {
		$s = Settings::get();
		?>
		<details class="smao-expert"><summary><span><?php esc_html_e( 'Expert scan controls', 'smart-media-auditor-optimizer' ); ?></span><small><?php esc_html_e( 'Tune throughput, responsiveness and background processing', 'smart-media-auditor-optimizer' ); ?></small></summary>
		<form id="smao-runtime" class="smao-runtime"><div class="smao-runtime-fields">
		<?php
		foreach ( array(
			'batch'         => array( __( 'Attachments per batch', 'smart-media-auditor-optimizer' ), 1, 200 ),
			'source_batch'  => array( __( 'Source rows per batch', 'smart-media-auditor-optimizer' ), 1, 100 ),
			'time_budget'   => array( __( 'Time budget (seconds)', 'smart-media-auditor-optimizer' ), 1, 8 ),
			'poll_interval' => array( __( 'Refresh interval (seconds)', 'smart-media-auditor-optimizer' ), 1, 15 ),
		) as $key => $field ) :
			?>
		<label><?php echo esc_html( $field[0] ); ?><input type="number" name="<?php echo esc_attr( $key ); ?>" min="<?php echo esc_attr( $field[1] ); ?>" max="<?php echo esc_attr( $field[2] ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" required></label>
		<?php endforeach; ?></div>
		<label class="smao-switch"><input type="checkbox" name="browser_worker" <?php checked( $s['browser_worker'] ); ?>> <?php esc_html_e( 'Keep work moving while this dashboard is open (including background tabs)', 'smart-media-auditor-optimizer' ); ?></label>
		<p class="smao-muted"><?php esc_html_e( 'Short batches suit shared hosting. The browser assists WP-Cron while this tab is open; browser throttling may slow background tabs. Closing it leaves work to WP-Cron. Changes apply at the next batch. Scan coverage and deletion protections are unchanged.', 'smart-media-auditor-optimizer' ); ?></p>
		<button class="button"><?php esc_html_e( 'Apply processing options', 'smart-media-auditor-optimizer' ); ?></button> <a href="<?php echo esc_url( admin_url( 'admin.php?page=smao-settings' ) ); ?>"><?php esc_html_e( 'Image, exclusion & retention settings', 'smart-media-auditor-optimizer' ); ?></a></form></details>
		<?php
	}
}
