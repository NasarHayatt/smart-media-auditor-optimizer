<?php
/**
 * Settings screen with tabs, including the activity log and system status.
 *
 * Throughput lives on Audit and scope review lives on Clean up, so neither
 * appears here. Each tab posts only its own fields, and the save merges.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration, diagnostics and history.
 */
final class Screen_Settings {

	/**
	 * Tab slugs and labels.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'speed'    => __( 'Speed', 'smart-media-auditor-optimizer' ),
			'images'   => __( 'Images', 'smart-media-auditor-optimizer' ),
			'scanning' => __( 'Scanning', 'smart-media-auditor-optimizer' ),
			'recovery' => __( 'Recovery storage', 'smart-media-auditor-optimizer' ),
			'log'      => __( 'Activity log', 'smart-media-auditor-optimizer' ),
			'system'   => __( 'System status', 'smart-media-auditor-optimizer' ),
		);
	}

	/**
	 * Render the screen.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	public static function render( array $input ): void {
		$tabs = self::tabs();
		$tab  = isset( $input['tab'] ) && isset( $tabs[ $input['tab'] ] ) ? (string) $input['tab'] : 'speed';
		?>
		<nav class="smao-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'smart-media-auditor-optimizer' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=smao-settings&tab=' . $slug ) ); ?>"
					<?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		switch ( $tab ) {
			case 'images':
				self::images();
				break;
			case 'scanning':
				self::scanning();
				break;
			case 'recovery':
				self::recovery();
				break;
			case 'log':
				self::log();
				break;
			case 'system':
				self::system();
				break;
			default:
				self::speed();
		}
	}

	/**
	 * Render the speed tab.
	 *
	 * @return void
	 */
	private static function speed(): void {
		$settings = Settings::get();
		?>
		<section class="smao-panel">
			<h2><?php esc_html_e( 'Speed', 'smart-media-auditor-optimizer' ); ?></h2>
			<form class="smao-settings" data-settings="speed">
				<input type="hidden" name="_flags" value="speed_enabled,dimensions,lcp_preload,lazy_correct,delivery">

				<label class="smao-checkbox smao-master">
					<input type="checkbox" name="speed_enabled" <?php checked( $settings['speed_enabled'] ); ?>>
					<span>
						<strong><?php esc_html_e( 'Speed corrections', 'smart-media-auditor-optimizer' ); ?></strong>
						<small><?php esc_html_e( 'The master switch. Turning this off disables everything below immediately, without changing any file.', 'smart-media-auditor-optimizer' ); ?></small>
					</span>
				</label>

				<fieldset>
					<legend><?php esc_html_e( 'Safe by default', 'smart-media-auditor-optimizer' ); ?></legend>
					<p class="smao-muted"><?php esc_html_e( 'These cannot change how your pages look. They only tell the browser what it already needs to know sooner.', 'smart-media-auditor-optimizer' ); ?></p>
					<label class="smao-checkbox">
						<input type="checkbox" name="dimensions" <?php checked( $settings['dimensions'] ); ?>>
						<span><?php esc_html_e( 'Add missing width and height to images', 'smart-media-auditor-optimizer' ); ?>
						<small><?php esc_html_e( 'Stops the page jumping around as images load. Most useful on page-builder and hand-written content.', 'smart-media-auditor-optimizer' ); ?></small></span>
					</label>
					<label class="smao-checkbox">
						<input type="checkbox" name="lcp_preload" <?php checked( $settings['lcp_preload'] ); ?>>
						<span><?php esc_html_e( 'Preload the main image on each page', 'smart-media-auditor-optimizer' ); ?>
						<small><?php esc_html_e( 'Detects the largest image automatically and asks the browser to fetch it first.', 'smart-media-auditor-optimizer' ); ?></small></span>
					</label>
					<label class="smao-checkbox">
						<input type="checkbox" name="lazy_correct" <?php checked( $settings['lazy_correct'] ); ?>>
						<span><?php esc_html_e( 'Correct lazy loading', 'smart-media-auditor-optimizer' ); ?>
						<small><?php esc_html_e( 'Never lazy-loads the main image, and lazy-loads the ones further down that should be.', 'smart-media-auditor-optimizer' ); ?></small></span>
					</label>
				</fieldset>

				<fieldset>
					<legend><?php esc_html_e( 'Changes your markup', 'smart-media-auditor-optimizer' ); ?></legend>
					<label class="smao-checkbox">
						<input type="checkbox" name="delivery" <?php checked( $settings['delivery'] ); ?>>
						<span><?php esc_html_e( 'Serve WebP or AVIF when available', 'smart-media-auditor-optimizer' ); ?>
						<small><?php esc_html_e( 'Wraps images in a picture element, keeping the original as a fallback. Requires alternate formats to have been generated on the Optimize screen. Test your theme after enabling.', 'smart-media-auditor-optimizer' ); ?></small></span>
					</label>
				</fieldset>

				<label>
					<?php esc_html_e( 'Front page image to preload (0 detects automatically)', 'smart-media-auditor-optimizer' ); ?>
					<input type="number" name="preload_id" min="0" step="1" value="<?php echo esc_attr( (string) $settings['preload_id'] ); ?>">
				</label>

				<button class="button button-primary"><?php esc_html_e( 'Save speed settings', 'smart-media-auditor-optimizer' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Render the images tab.
	 *
	 * @return void
	 */
	private static function images(): void {
		$settings = Settings::get();
		?>
		<section class="smao-panel">
			<h2><?php esc_html_e( 'Images', 'smart-media-auditor-optimizer' ); ?></h2>
			<form class="smao-settings" data-settings="images">
				<input type="hidden" name="_flags" value="resize,strip_exif,auto_optimize">
				<label>
					<?php esc_html_e( 'Compression', 'smart-media-auditor-optimizer' ); ?>
					<select name="compression">
						<option value="lossless" <?php selected( $settings['compression'], 'lossless' ); ?>><?php esc_html_e( 'Lossless (pixel verified, needs ImageMagick)', 'smart-media-auditor-optimizer' ); ?></option>
						<option value="lossy" <?php selected( $settings['compression'], 'lossy' ); ?>><?php esc_html_e( 'Lossy (smaller files, review the results)', 'smart-media-auditor-optimizer' ); ?></option>
					</select>
				</label>
				<?php self::number( 'quality', __( 'Lossy quality', 'smart-media-auditor-optimizer' ), $settings ); ?>
				<label>
					<?php esc_html_e( 'Generate alternate format', 'smart-media-auditor-optimizer' ); ?>
					<select name="alternate">
						<option value="off" <?php selected( $settings['alternate'], 'off' ); ?>><?php esc_html_e( 'Off', 'smart-media-auditor-optimizer' ); ?></option>
						<option value="webp" <?php selected( $settings['alternate'], 'webp' ); ?>><?php esc_html_e( 'WebP', 'smart-media-auditor-optimizer' ); ?></option>
						<option value="avif" <?php selected( $settings['alternate'], 'avif' ); ?>><?php esc_html_e( 'AVIF (server dependent)', 'smart-media-auditor-optimizer' ); ?></option>
					</select>
					<small><?php esc_html_e( 'Generating alternates does nothing on its own. Turn on WebP or AVIF delivery under Speed to actually serve them.', 'smart-media-auditor-optimizer' ); ?></small>
				</label>
				<?php self::number( 'max_width', __( 'Maximum width', 'smart-media-auditor-optimizer' ), $settings ); ?>
				<?php self::number( 'max_height', __( 'Maximum height', 'smart-media-auditor-optimizer' ), $settings ); ?>
				<label class="smao-checkbox">
					<input type="checkbox" name="resize" <?php checked( $settings['resize'] ); ?>>
					<span><?php esc_html_e( 'Resize oversized originals', 'smart-media-auditor-optimizer' ); ?>
					<small><?php esc_html_e( 'Lossy mode only. This changes the stored pixels.', 'smart-media-auditor-optimizer' ); ?></small></span>
				</label>
				<label class="smao-checkbox">
					<input type="checkbox" name="strip_exif" <?php checked( $settings['strip_exif'] ); ?>>
					<span><?php esc_html_e( 'Remove EXIF metadata', 'smart-media-auditor-optimizer' ); ?>
					<small><?php esc_html_e( 'Colour profiles are kept where the server supports it.', 'smart-media-auditor-optimizer' ); ?></small></span>
				</label>
				<label class="smao-checkbox">
					<input type="checkbox" name="auto_optimize" <?php checked( $settings['auto_optimize'] ); ?>>
					<span><?php esc_html_e( 'Optimize new uploads automatically', 'smart-media-auditor-optimizer' ); ?></span>
				</label>
				<fieldset>
					<legend><?php esc_html_e( 'Stop generating these image sizes. Existing files are kept.', 'smart-media-auditor-optimizer' ); ?></legend>
					<?php foreach ( get_intermediate_image_sizes() as $size ) : ?>
						<label class="smao-checkbox">
							<input type="checkbox" name="disabled_sizes[]" value="<?php echo esc_attr( $size ); ?>" <?php checked( in_array( $size, $settings['disabled_sizes'], true ) ); ?>>
							<span><?php echo esc_html( $size ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<button class="button button-primary"><?php esc_html_e( 'Save image settings', 'smart-media-auditor-optimizer' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Render the scanning tab.
	 *
	 * @return void
	 */
	private static function scanning(): void {
		$settings = Settings::get();
		?>
		<section class="smao-panel">
			<h2><?php esc_html_e( 'Scanning', 'smart-media-auditor-optimizer' ); ?></h2>
			<p class="smao-inline-warning"><?php esc_html_e( 'Changing anything on this tab affects what a scan would find, so it invalidates your current scan results. You will be told when that happens.', 'smart-media-auditor-optimizer' ); ?></p>
			<form class="smao-settings" data-settings="scanning">
				<?php self::number( 'recent_days', __( 'Protect uploads newer than (days)', 'smart-media-auditor-optimizer' ), $settings ); ?>
				<?php self::number( 'retention_days', __( 'Keep recovery copies for at least (days)', 'smart-media-auditor-optimizer' ), $settings ); ?>
				<label>
					<?php esc_html_e( 'Scheduled scans', 'smart-media-auditor-optimizer' ); ?>
					<select name="schedule">
						<option value="off" <?php selected( $settings['schedule'], 'off' ); ?>><?php esc_html_e( 'Off', 'smart-media-auditor-optimizer' ); ?></option>
						<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'smart-media-auditor-optimizer' ); ?></option>
						<option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'smart-media-auditor-optimizer' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Never touch these files', 'smart-media-auditor-optimizer' ); ?>
					<textarea rows="6" name="exclusions" spellcheck="false"><?php echo esc_textarea( $settings['exclusions'] ); ?></textarea>
					<small><?php esc_html_e( 'One rule per line: id:123, name:logo.png, path:2026/protected/, type:application/pdf', 'smart-media-auditor-optimizer' ); ?></small>
				</label>
				<button class="button button-primary"><?php esc_html_e( 'Save scanning settings', 'smart-media-auditor-optimizer' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Render the recovery storage tab.
	 *
	 * @return void
	 */
	private static function recovery(): void {
		?>
		<section class="smao-panel">
			<h2><?php esc_html_e( 'Private recovery storage', 'smart-media-auditor-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'Removed originals are moved here instead of being deleted. It must be a folder that already exists, is writable, and sits outside everything your web server serves publicly.', 'smart-media-auditor-optimizer' ); ?></p>
			<?php
			try {
				$root = Vault::root( false );
				?>
				<p class="smao-ok">
					<?php
					/* translators: %s is a filesystem path. */
					printf( esc_html__( 'Configured: %s', 'smart-media-auditor-optimizer' ), '<code>' . esc_html( $root ) . '</code>' );
					?>
				</p>
				<?php
			} catch ( \Throwable $e ) {
				echo '<p class="smao-inline-warning">' . esc_html( $e->getMessage() ) . '</p>';
			}
			?>
			<?php if ( defined( 'SMAO_VAULT_DIR' ) ) : ?>
				<p class="smao-muted"><?php esc_html_e( 'This site sets the recovery folder with SMAO_VAULT_DIR in wp-config.php, so it cannot be changed here.', 'smart-media-auditor-optimizer' ); ?></p>
			<?php else : ?>
				<form id="smao-storage" class="smao-settings">
					<label>
						<?php esc_html_e( 'Absolute path to an existing private folder', 'smart-media-auditor-optimizer' ); ?>
						<input type="text" name="path" required spellcheck="false" value="<?php echo esc_attr( (string) get_option( 'smao_vault_path', '' ) ); ?>" placeholder="<?php echo esc_attr( self::example_path() ); ?>">
						<small><?php esc_html_e( 'Do not use your uploads folder, the plugin folder, or anything inside your web root.', 'smart-media-auditor-optimizer' ); ?></small>
					</label>
					<button class="button button-primary"><?php esc_html_e( 'Check and save folder', 'smart-media-auditor-optimizer' ); ?></button>
				</form>
			<?php endif; ?>
			<p class="smao-muted"><?php esc_html_e( 'Back this folder up together with your database, so recovery records and their files stay in step.', 'smart-media-auditor-optimizer' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Suggest a platform-appropriate example path.
	 *
	 * @return string
	 */
	private static function example_path(): string {
		return 0 === stripos( PHP_OS_FAMILY, 'win' ) ? 'D:/private/smao-recovery' : '/srv/private/smao-recovery';
	}

	/**
	 * Render the activity log tab.
	 *
	 * @return void
	 */
	private static function log(): void {
		global $wpdb;
		$table = Database::table( 'log' );
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table name.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed internal table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT 25 OFFSET %d", ( $page - 1 ) * 25 ),
			ARRAY_A
		);
		Database::check( $rows );
		?>
		<section class="smao-panel">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Activity log', 'smart-media-auditor-optimizer' ); ?></h2>
				<button type="button" class="button" id="smao-cleanup-records"><?php esc_html_e( 'Clear old records', 'smart-media-auditor-optimizer' ); ?></button>
			</div>
			<div class="smao-table">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When (UTC)', 'smart-media-auditor-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'smart-media-auditor-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'File', 'smart-media-auditor-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'smart-media-auditor-optimizer' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Detail', 'smart-media-auditor-optimizer' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Nothing has happened yet. Scans and file actions are recorded here.', 'smart-media-auditor-optimizer' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php $user = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null; ?>
						<tr>
							<td><?php echo esc_html( $row['created'] ); ?></td>
							<td><?php echo esc_html( $user ? $user->display_name : __( 'System', 'smart-media-auditor-optimizer' ) ); ?></td>
							<td><?php echo (int) $row['attachment_id'] ? esc_html( '#' . $row['attachment_id'] ) : '<span class="smao-muted">&mdash;</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( str_replace( '_', ' ', $row['action'] ) ); ?></td>
							<td><?php echo esc_html( $row['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $total > 25 ) : ?>
				<nav class="smao-pagination" aria-label="<?php esc_attr_e( 'Log pages', 'smart-media-auditor-optimizer' ); ?>">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => (int) ceil( $total / 25 ),
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render the system status tab.
	 *
	 * @return void
	 */
	private static function system(): void {
		global $wp_version;
		$capabilities = Optimizer::capabilities();
		$items        = array(
			__( 'WordPress', 'smart-media-auditor-optimizer' )      => $wp_version,
			__( 'PHP', 'smart-media-auditor-optimizer' )            => PHP_VERSION,
			__( 'Memory limit', 'smart-media-auditor-optimizer' )   => ini_get( 'memory_limit' ),
			__( 'Multisite', 'smart-media-auditor-optimizer' )      => is_multisite(),
			__( 'WP-Cron disabled', 'smart-media-auditor-optimizer' ) => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			__( 'GD', 'smart-media-auditor-optimizer' )             => $capabilities['gd'],
			__( 'ImageMagick', 'smart-media-auditor-optimizer' )    => $capabilities['imagick'],
			__( 'JPEG support', 'smart-media-auditor-optimizer' )   => $capabilities['jpeg'],
			__( 'PNG support', 'smart-media-auditor-optimizer' )    => $capabilities['png'],
			__( 'WebP support', 'smart-media-auditor-optimizer' )   => $capabilities['webp'],
			__( 'AVIF support', 'smart-media-auditor-optimizer' )   => $capabilities['avif'],
		);
		try {
			Vault::root( false );
			$items[ __( 'Recovery storage', 'smart-media-auditor-optimizer' ) ] = __( 'Configured', 'smart-media-auditor-optimizer' );
		} catch ( \Throwable $e ) {
			$items[ __( 'Recovery storage', 'smart-media-auditor-optimizer' ) ] = $e->getMessage();
		}
		?>
		<section class="smao-panel">
			<h2><?php esc_html_e( 'System status', 'smart-media-auditor-optimizer' ); ?></h2>
			<dl class="smao-system">
				<?php foreach ( $items as $key => $value ) : ?>
					<dt><?php echo esc_html( $key ); ?></dt>
					<dd>
						<?php
						if ( is_bool( $value ) ) {
							echo $value
								? '<span class="smao-badge smao-status-used">' . esc_html__( 'Yes', 'smart-media-auditor-optimizer' ) . '</span>'
								: '<span class="smao-badge">' . esc_html__( 'No', 'smart-media-auditor-optimizer' ) . '</span>';
						} else {
							echo esc_html( (string) $value );
						}
						?>
					</dd>
				<?php endforeach; ?>
			</dl>
			<p class="smao-muted"><?php esc_html_e( 'This plugin sends nothing anywhere. There is no telemetry, no remote scanning and no external optimization service.', 'smart-media-auditor-optimizer' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Render a bounded number field that advertises its own limits.
	 *
	 * @param string $key      Setting name.
	 * @param string $label    Field label.
	 * @param array  $settings Current settings.
	 * @return void
	 */
	private static function number( string $key, string $label, array $settings ): void {
		list( $min, $max ) = Settings::bounds( $key );
		?>
		<label>
			<?php echo esc_html( $label ); ?>
			<input type="number" name="<?php echo esc_attr( $key ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="1" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" required>
			<small><?php echo esc_html( sprintf( '%s – %s', number_format_i18n( $min ), number_format_i18n( $max ) ) ); ?></small>
		</label>
		<?php
	}
}
