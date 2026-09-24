<?php
/**
 * Speed: plain-language switches, each explained by what it does for a visitor.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end speed controls.
 */
final class Screen_Speed {

	/**
	 * Render the screen.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	public static function render( array $input ): void {
		$settings = Settings::get();
		self::right_size();
		?>
		<form class="smao-settings" data-settings="speed">
			<input type="hidden" name="_flags" value="speed_enabled,dimensions,lcp_preload,lazy_correct,delivery,rightsize">

			<section class="smao-panel smao-master-panel">
				<label class="smao-switch">
					<input type="checkbox" name="speed_enabled" <?php checked( $settings['speed_enabled'] ); ?>>
					<span>
						<strong><?php esc_html_e( 'Help my pages load faster', 'smart-media-auditor-optimizer' ); ?></strong>
						<small><?php esc_html_e( 'Turn this off at any time and your pages go back to exactly how your theme sends them. No file is changed either way.', 'smart-media-auditor-optimizer' ); ?></small>
					</span>
				</label>
			</section>

			<section class="smao-panel">
				<div class="smao-panel-head">
					<h2><?php esc_html_e( 'Safe to leave on', 'smart-media-auditor-optimizer' ); ?></h2>
				</div>
				<p><?php esc_html_e( 'None of these change how your site looks. They tell the browser things it would otherwise have to work out for itself.', 'smart-media-auditor-optimizer' ); ?></p>

				<?php
				self::switch_row(
					'lcp_preload',
					$settings['lcp_preload'],
					__( 'Load the main image first', 'smart-media-auditor-optimizer' ),
					__( 'We work out which image is the big one at the top of each page and ask the browser to fetch it straight away, instead of getting to it last.', 'smart-media-auditor-optimizer' ),
					__( 'Visitors see the page fill in sooner', 'smart-media-auditor-optimizer' )
				);
				self::switch_row(
					'dimensions',
					$settings['dimensions'],
					__( 'Stop pages jumping about', 'smart-media-auditor-optimizer' ),
					__( 'When an image does not say how big it is, the browser guesses, then shoves everything down the page once the real image arrives. We fill in the missing sizes.', 'smart-media-auditor-optimizer' ),
					__( 'Nothing moves under the reader as they start reading', 'smart-media-auditor-optimizer' )
				);
				self::switch_row(
					'rightsize',
					$settings['rightsize'],
					__( 'Send images at the size they are shown', 'smart-media-auditor-optimizer' ),
					__( 'Uses the measurements above to tell the browser how big each image really is, so it picks a file that fits instead of the largest one available. No image is altered and no new file is created.', 'smart-media-auditor-optimizer' ),
					__( 'Usually the single biggest saving on an image-heavy page', 'smart-media-auditor-optimizer' )
				);
				self::switch_row(
					'lazy_correct',
					$settings['lazy_correct'],
					__( 'Load lower images only when needed', 'smart-media-auditor-optimizer' ),
					__( 'Images further down the page wait until someone scrolls near them, and the main image never waits.', 'smart-media-auditor-optimizer' ),
					__( 'Less to download before the page is usable', 'smart-media-auditor-optimizer' )
				);
				?>
			</section>

			<section class="smao-panel">
				<div class="smao-panel-head">
					<h2><?php esc_html_e( 'Worth testing first', 'smart-media-auditor-optimizer' ); ?></h2>
				</div>
				<?php
				self::switch_row(
					'delivery',
					$settings['delivery'],
					__( 'Send smaller image files where possible', 'smart-media-auditor-optimizer' ),
					__( 'Modern browsers understand newer image formats that are often much smaller. Older ones still get the original, so nobody sees a broken image. This does change the code your pages send, so have a look at a few pages afterwards.', 'smart-media-auditor-optimizer' ),
					__( 'Often the single biggest saving, once smaller copies exist', 'smart-media-auditor-optimizer' )
				);
				?>
				<?php if ( ! self::has_alternates() ) : ?>
					<p class="smao-inline-note">
						<?php esc_html_e( 'You do not have any smaller copies yet, so this will not do anything on its own.', 'smart-media-auditor-optimizer' ); ?>
						<a class="smao-link" href="<?php echo esc_url( admin_url( 'admin.php?page=smao-advanced&tab=images' ) ); ?>"><?php esc_html_e( 'Make smaller copies', 'smart-media-auditor-optimizer' ); ?></a>
					</p>
				<?php endif; ?>
			</section>

			<div class="smao-form-actions">
				<button class="smao-cta"><?php esc_html_e( 'Save', 'smart-media-auditor-optimizer' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * The measured right-sizing panel: what it found, and how to refresh it.
	 *
	 * @return void
	 */
	private static function right_size(): void {
		$coverage = Measure::coverage();
		$wasted   = $coverage['images'] ? Measure::wasted() : 0;
		$worst    = $coverage['images'] ? Measure::oversized( 8 ) : array();
		$settings = Settings::get();
		?>
		<section class="smao-panel smao-measure-panel">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Serve images at the size they are shown', 'smart-media-auditor-optimizer' ); ?></h2>
				<?php if ( $coverage['images'] ) : ?>
					<span class="smao-chip is-on"><?php esc_html_e( 'Measured', 'smart-media-auditor-optimizer' ); ?></span>
				<?php endif; ?>
			</div>

			<p><?php esc_html_e( 'WordPress tells the browser every image fills the whole window. It usually does not, so the browser downloads a much larger file than it draws. We can only know the real size by looking at your pages, so that is what this does.', 'smart-media-auditor-optimizer' ); ?></p>

			<?php if ( ! $coverage['images'] ) : ?>
				<p class="smao-muted"><?php esc_html_e( 'Your pages open in a hidden frame here in the dashboard, at phone, tablet and desktop widths. Nothing is added to the pages your visitors see, and no file is changed.', 'smart-media-auditor-optimizer' ); ?></p>
			<?php else : ?>
				<div class="smao-figures">
					<article class="smao-figure">
						<span><?php esc_html_e( 'Wasted on every page load', 'smart-media-auditor-optimizer' ); ?></span>
						<strong><?php echo esc_html( size_format( $wasted ) ); ?></strong>
					</article>
					<article class="smao-figure">
						<span><?php esc_html_e( 'Images measured', 'smart-media-auditor-optimizer' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $coverage['images'] ) ); ?></strong>
					</article>
					<article class="smao-figure">
						<span><?php esc_html_e( 'Pages checked', 'smart-media-auditor-optimizer' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $coverage['pages'] ) ); ?></strong>
					</article>
				</div>
			<?php endif; ?>

			<?php if ( $worst ) : ?>
				<div class="smao-table">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Image', 'smart-media-auditor-optimizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Sent at', 'smart-media-auditor-optimizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Shown at', 'smart-media-auditor-optimizer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Wasted', 'smart-media-auditor-optimizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $worst as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['filename'] ); ?></td>
								<td><?php echo esc_html( $row['served'] . 'px' ); ?></td>
								<td><?php echo esc_html( $row['drawn'] . 'px' ); ?></td>
								<td><?php echo esc_html( size_format( $row['wasted'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="smao-measure-actions">
				<button type="button" class="smao-cta smao-cta-quiet" id="smao-measure">
					<?php
					echo $coverage['images']
						? esc_html__( 'Measure again', 'smart-media-auditor-optimizer' )
						: esc_html__( 'Measure my pages', 'smart-media-auditor-optimizer' );
					?>
				</button>
				<span id="smao-measure-status" class="smao-muted" role="status"></span>
			</div>
			<div class="smao-track"><div class="smao-track-fill" id="smao-measure-bar"></div></div>

			<?php if ( $coverage['measured_at'] ) : ?>
				<p class="smao-reassure">
					<?php
					printf(
						/* translators: %s is a human readable time difference, for example "2 hours". */
						esc_html__( 'Last measured %s ago. Measure again after a theme or layout change.', 'smart-media-auditor-optimizer' ),
						esc_html( human_time_diff( $coverage['measured_at'] ) )
					);
					?>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render one labelled switch with its reason.
	 *
	 * @param string $name    Setting name.
	 * @param bool   $checked Whether it is on.
	 * @param string $title   Plain-language title.
	 * @param string $detail  What it actually does.
	 * @param string $benefit What the visitor gets.
	 * @return void
	 */
	private static function switch_row( string $name, bool $checked, string $title, string $detail, string $benefit ): void {
		?>
		<label class="smao-switch">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" <?php checked( $checked ); ?>>
			<span>
				<strong><?php echo esc_html( $title ); ?></strong>
				<small><?php echo esc_html( $detail ); ?></small>
				<em><?php echo esc_html( $benefit ); ?></em>
			</span>
		</label>
		<?php
	}

	/**
	 * Whether any alternate format has actually been generated.
	 *
	 * @return bool
	 */
	private static function has_alternates(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT meta_id FROM %i WHERE meta_key=%s LIMIT 1', $wpdb->postmeta, '_smao_alternates' )
		);
	}
}
