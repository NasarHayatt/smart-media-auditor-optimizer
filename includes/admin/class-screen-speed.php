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
			<?php self::cache_panel(); ?>

			<input type="hidden" name="_flags" value="speed_enabled,dimensions,lcp_preload,lazy_correct,delivery,rightsize,defer_js,delay_js,async_css,optimize_fonts,page_cache,browser_cache,cache_gzip,cache_warm,separate_mobile">

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
					__( 'When an image does not say how big it is, the browser guesses, then shoves everything down the page once the real image arrives. We fill in the missing sizes, and move styles your page builder prints late up to the top, so headers and menus are styled from the first moment.', 'smart-media-auditor-optimizer' ),
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
					<h2><?php esc_html_e( 'JavaScript', 'smart-media-auditor-optimizer' ); ?></h2>
					<?php $owner = Environment::conflict( 'assets' ); ?>
					<?php if ( $owner ) : ?>
						<span class="smao-chip"><?php echo esc_html( Environment::label( $owner ) ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $owner ) : ?>
					<p class="smao-inline-note">
						<?php
						printf(
							/* translators: %s is the name of another optimisation plugin. */
							esc_html__( '%s is already handling scripts on this site, so these are switched off to avoid two plugins rewriting the same page. Turn that plugin off if you would rather this one did the work.', 'smart-media-auditor-optimizer' ),
							esc_html( Environment::label( $owner ) )
						);
						?>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Scripts are the single largest cause of a slow score. Most of them are not needed for the page to appear.', 'smart-media-auditor-optimizer' ); ?></p>
				<?php endif; ?>
				<?php
				self::switch_row(
					'delay_js',
					$settings['delay_js'],
					__( 'Hold back third-party scripts until someone interacts', 'smart-media-auditor-optimizer' ),
					__( 'Analytics, chat widgets, pixels and social embeds do nothing for the first view of a page, so they wait until a visitor scrolls, taps or moves the mouse. If nobody interacts they run anyway after a few seconds, so nothing is lost.', 'smart-media-auditor-optimizer' ),
					__( 'Usually the single biggest improvement to a performance score', 'smart-media-auditor-optimizer' )
				);
				self::switch_row(
					'defer_js',
					$settings['defer_js'],
					__( 'Stop other scripts blocking the page', 'smart-media-auditor-optimizer' ),
					__( 'Remaining scripts still run, but they no longer hold up the text and images while the browser fetches them. jQuery and anything that depends on it are left alone.', 'smart-media-auditor-optimizer' ),
					__( 'The page becomes readable sooner', 'smart-media-auditor-optimizer' )
				);
				?>
				<details class="smao-filters-more">
					<summary><?php esc_html_e( 'Exclusions', 'smart-media-auditor-optimizer' ); ?></summary>
					<label>
						<?php esc_html_e( 'Never touch these scripts', 'smart-media-auditor-optimizer' ); ?>
						<textarea rows="4" name="script_exclusions" spellcheck="false"><?php echo esc_textarea( $settings['script_exclusions'] ); ?></textarea>
						<small><?php esc_html_e( 'One per line. A script handle, or any part of its URL. Add something here if a feature stops working.', 'smart-media-auditor-optimizer' ); ?></small>
					</label>
					<label>
						<?php esc_html_e( 'Also hold these back', 'smart-media-auditor-optimizer' ); ?>
						<textarea rows="3" name="delay_extra" spellcheck="false"><?php echo esc_textarea( $settings['delay_extra'] ); ?></textarea>
						<small><?php esc_html_e( 'One per line. Anything here waits for interaction as well.', 'smart-media-auditor-optimizer' ); ?></small>
					</label>
					<?php self::number_row( 'delay_timeout', __( 'Run held-back scripts anyway after (seconds)', 'smart-media-auditor-optimizer' ), $settings ); ?>
				</details>
			</section>

			<section class="smao-panel">
				<div class="smao-panel-head">
					<h2><?php esc_html_e( 'CSS and fonts', 'smart-media-auditor-optimizer' ); ?></h2>
				</div>
				<?php
				self::switch_row(
					'optimize_fonts',
					$settings['optimize_fonts'],
					__( 'Show text while webfonts load', 'smart-media-auditor-optimizer' ),
					__( 'Stops the browser hiding your text until a font arrives, and opens the connection to the font host early.', 'smart-media-auditor-optimizer' ),
					__( 'Text appears immediately instead of after the font', 'smart-media-auditor-optimizer' )
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
				<?php
				self::switch_row(
					'async_css',
					$settings['async_css'],
					__( 'Stop stylesheets blocking the first paint', 'smart-media-auditor-optimizer' ),
					__( 'Inlines the styles for the top of each measured page and loads the rest in the background. Pages are only changed if their layout stays exactly the same. On image-heavy pages it can make the PageSpeed score worse, because images then start downloading sooner and compete with everything else, so run PageSpeed before and after switching it on and keep whichever scores higher.', 'smart-media-auditor-optimizer' ),
					__( 'The page paints before the full stylesheet arrives', 'smart-media-auditor-optimizer' )
				);
				?>
				<?php $coverage = Styles::coverage(); ?>
				<p class="smao-muted">
					<?php if ( ! $coverage['total'] ) : ?>
						<?php esc_html_e( 'No pages measured yet, so every stylesheet still loads normally. Run the measurement above.', 'smart-media-auditor-optimizer' ); ?>
					<?php else : ?>
						<?php
						printf(
							/* translators: 1: pages that passed the check, 2: pages measured. */
							esc_html__( 'Stylesheets load in the background on %1$d of %2$d measured pages.', 'smart-media-auditor-optimizer' ),
							(int) $coverage['ready'],
							(int) $coverage['total']
						);
						?>
					<?php endif; ?>
				</p>
				<?php if ( $coverage['total'] > $coverage['ready'] ) : ?>
					<ul class="smao-muted">
						<?php
						foreach ( $coverage['pages'] as $entry ) {
							if ( Styles::usable( $entry ) ) {
								continue;
							}
							$reasons = array(
								'shifted'   => __( 'kept as it is, because the layout moved when tested', 'smart-media-auditor-optimizer' ),
								'too_large' => __( 'kept as it is, because the top of the page needs too much styling to inline', 'smart-media-auditor-optimizer' ),
								'empty'     => __( 'kept as it is, because its stylesheets could not be read', 'smart-media-auditor-optimizer' ),
							);
							printf(
								'<li>%1$s: %2$s</li>',
								esc_html( (string) wp_parse_url( (string) $entry['url'], PHP_URL_PATH ) ),
								esc_html( $reasons[ $entry['status'] ] ?? $reasons['empty'] )
							);
						}
						?>
					</ul>
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
	 * Render a bounded number field.
	 *
	 * @param string $key      Setting name.
	 * @param string $label    Field label.
	 * @param array  $settings Current settings.
	 * @return void
	 */
	private static function number_row( string $key, string $label, array $settings ): void {
		list( $min, $max ) = Settings::bounds( $key );
		?>
		<label>
			<?php echo esc_html( $label ); ?>
			<input type="number" name="<?php echo esc_attr( $key ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="1" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>">
			<small><?php esc_html_e( 'Zero waits for interaction only.', 'smart-media-auditor-optimizer' ); ?></small>
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

	/**
	 * The caching panel: what is stored, and the controls for it.
	 *
	 * @return void
	 */
	private static function cache_panel(): void {
		$settings    = Settings::get();
		$owner       = Environment::conflict( 'cache' );
		$environment = Environment::get();
		$stats       = Cache::active() ? Cache::stats() : array(
			'pages'   => 0,
			'bytes'   => 0,
			'updated' => 0,
		);
		$purged = (int) get_option( 'smao_cache_purged', 0 );
		?>
		<section class="smao-panel">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Page cache', 'smart-media-auditor-optimizer' ); ?></h2>
				<span class="smao-chip <?php echo Cache::active() ? 'is-on' : ''; ?>">
					<?php
					echo Cache::active()
						? esc_html__( 'On', 'smart-media-auditor-optimizer' )
						: esc_html__( 'Off', 'smart-media-auditor-optimizer' );
					?>
				</span>
			</div>

			<?php if ( $owner ) : ?>
				<p class="smao-inline-note">
					<?php
					printf(
						/* translators: %s is the name of another caching plugin. */
						esc_html__( '%s is already caching this site, so this is switched off. Two page caches on one site cause stale and mismatched pages.', 'smart-media-auditor-optimizer' ),
						esc_html( Environment::label( $owner ) )
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Saves the finished page so the next visitor gets a file instead of waiting for WordPress to build it again. Logged-in visitors, carts, checkouts and account pages are never cached.', 'smart-media-auditor-optimizer' ); ?></p>

				<?php $diagnosis = Cache::diagnose(); ?>
				<?php if ( 'dropin' === $diagnosis['mode'] ) : ?>
					<p class="smao-muted"><?php esc_html_e( 'Serving mode: fastest. Stored pages are sent before WordPress starts.', 'smart-media-auditor-optimizer' ); ?></p>
				<?php elseif ( 'php' === $diagnosis['mode'] ) : ?>
					<div class="smao-inline-note">
						<p><?php esc_html_e( 'Serving mode: standard. Stored pages are sent as soon as the plugin loads, skipping your theme and page builder. One more step makes it faster still:', 'smart-media-auditor-optimizer' ); ?></p>
						<ul>
							<?php foreach ( $diagnosis['problems'] as $problem ) : ?>
								<li><?php echo esc_html( $problem ); ?></li>
							<?php endforeach; ?>
						</ul>
						<?php if ( '' !== $diagnosis['fix'] ) : ?>
							<p>
								<?php esc_html_e( 'Add this line to wp-config.php, above the line that says "stop editing":', 'smart-media-auditor-optimizer' ); ?>
								<code><?php echo esc_html( $diagnosis['fix'] ); ?></code>
							</p>
						<?php endif; ?>
					</div>
				<?php elseif ( 'unwritable' === $diagnosis['mode'] ) : ?>
					<p class="smao-inline-note">
						<?php foreach ( $diagnosis['problems'] as $problem ) : ?>
							<?php echo esc_html( $problem ); ?>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>

				<div class="smao-figures">
					<article class="smao-figure">
						<span><?php esc_html_e( 'Pages stored', 'smart-media-auditor-optimizer' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $stats['pages'] ) ); ?></strong>
					</article>
					<article class="smao-figure">
						<span><?php esc_html_e( 'Space used', 'smart-media-auditor-optimizer' ); ?></span>
						<strong><?php echo esc_html( size_format( $stats['bytes'] ) ); ?></strong>
					</article>
					<article class="smao-figure">
						<span><?php esc_html_e( 'Waiting to rebuild', 'smart-media-auditor-optimizer' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( Warm::pending() ) ); ?></strong>
					</article>
					<article class="smao-figure">
						<span><?php esc_html_e( 'Last cleared', 'smart-media-auditor-optimizer' ); ?></span>
						<strong style="font-size:15px">
							<?php
							echo $purged
								/* translators: %s is a human readable time difference. */
								? esc_html( sprintf( __( '%s ago', 'smart-media-auditor-optimizer' ), human_time_diff( $purged ) ) )
								: esc_html__( 'Never', 'smart-media-auditor-optimizer' );
							?>
						</strong>
					</article>
				</div>

				<div class="smao-controls">
					<button type="button" class="smao-cta smao-cta-quiet" data-command="cache_flush"><?php esc_html_e( 'Clear everything', 'smart-media-auditor-optimizer' ); ?></button>
					<button type="button" class="smao-cta smao-cta-quiet" data-command="cache_warm"><?php esc_html_e( 'Rebuild main pages', 'smart-media-auditor-optimizer' ); ?></button>
				</div>

				<details class="smao-filters-more">
					<summary><?php esc_html_e( 'More cache options', 'smart-media-auditor-optimizer' ); ?></summary>
					<label>
						<?php esc_html_e( 'Clear one address', 'smart-media-auditor-optimizer' ); ?>
						<input type="url" id="smao-forget-url" placeholder="<?php echo esc_attr( home_url( '/example-page/' ) ); ?>">
					</label>
					<p><button type="button" class="button" id="smao-forget"><?php esc_html_e( 'Clear that address', 'smart-media-auditor-optimizer' ); ?></button></p>
					<label>
						<?php esc_html_e( 'Never cache these addresses', 'smart-media-auditor-optimizer' ); ?>
						<textarea rows="4" name="cache_exclusions" spellcheck="false"><?php echo esc_textarea( $settings['cache_exclusions'] ); ?></textarea>
						<small><?php esc_html_e( 'One path per line, for example /landing-page/. End with * to match everything beneath it.', 'smart-media-auditor-optimizer' ); ?></small>
					</label>
					<?php self::number_row_hours( 'cache_ttl', __( 'Rebuild pages older than (hours)', 'smart-media-auditor-optimizer' ), $settings ); ?>
					<label class="smao-checkbox">
						<input type="checkbox" name="separate_mobile" <?php checked( $settings['separate_mobile'] ); ?>>
						<span><?php esc_html_e( 'Keep a separate copy for phones', 'smart-media-auditor-optimizer' ); ?>
						<small><?php esc_html_e( 'Only needed if your theme sends different HTML to phones. Most responsive themes do not.', 'smart-media-auditor-optimizer' ); ?></small></span>
					</label>
					<label class="smao-checkbox">
						<input type="checkbox" name="cache_warm" <?php checked( $settings['cache_warm'] ); ?>>
						<span><?php esc_html_e( 'Rebuild pages in the background after a change', 'smart-media-auditor-optimizer' ); ?></span>
					</label>
					<label class="smao-checkbox">
						<input type="checkbox" name="cache_gzip" <?php checked( $settings['cache_gzip'] ); ?>>
						<span><?php esc_html_e( 'Store a compressed copy as well', 'smart-media-auditor-optimizer' ); ?></span>
					</label>
				</details>
			<?php endif; ?>

			<?php self::switch_row( 'page_cache', $settings['page_cache'], __( 'Cache my pages', 'smart-media-auditor-optimizer' ), __( 'Serves a stored copy of each public page instead of rebuilding it for every visitor.', 'smart-media-auditor-optimizer' ), __( 'The single biggest reduction in server response time', 'smart-media-auditor-optimizer' ) ); ?>
		</section>

		<section class="smao-panel">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Browser caching', 'smart-media-auditor-optimizer' ); ?></h2>
			</div>
			<p><?php esc_html_e( 'Tells browsers to keep images, stylesheets, scripts and fonts rather than downloading them again on every visit. Addresses change when a file changes, so visitors always get the current version.', 'smart-media-auditor-optimizer' ); ?></p>
			<?php self::switch_row( 'browser_cache', $settings['browser_cache'], __( 'Let browsers keep static files', 'smart-media-auditor-optimizer' ), __( 'Applies the right expiry to images, CSS, JavaScript and fonts.', 'smart-media-auditor-optimizer' ), __( 'Returning visitors download almost nothing', 'smart-media-auditor-optimizer' ) ); ?>

			<?php if ( $environment['server']['apache'] ) : ?>
				<p class="smao-muted">
					<?php
					echo Headers::installed()
						? esc_html__( 'Rules are installed in your .htaccess file.', 'smart-media-auditor-optimizer' )
						: esc_html__( 'Rules will be added to your .htaccess file when this is saved.', 'smart-media-auditor-optimizer' );
					?>
				</p>
			<?php else : ?>
				<details class="smao-filters-more">
					<summary><?php esc_html_e( 'Your server needs these rules added by hand', 'smart-media-auditor-optimizer' ); ?></summary>
					<p class="smao-muted"><?php esc_html_e( 'This server does not read .htaccess, so paste the following into your server configuration. Everything else works without it.', 'smart-media-auditor-optimizer' ); ?></p>
					<textarea rows="12" readonly spellcheck="false"><?php echo esc_textarea( Headers::nginx_rules() ); ?></textarea>
				</details>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render a bounded number field measured in hours.
	 *
	 * @param string $key      Setting name.
	 * @param string $label    Field label.
	 * @param array  $settings Current settings.
	 * @return void
	 */
	private static function number_row_hours( string $key, string $label, array $settings ): void {
		list( $min, $max ) = Settings::bounds( $key );
		?>
		<label>
			<?php echo esc_html( $label ); ?>
			<input type="number" name="<?php echo esc_attr( $key ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="1" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>">
		</label>
		<?php
	}
}
