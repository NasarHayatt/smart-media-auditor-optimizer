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
		?>
		<form class="smao-settings" data-settings="speed">
			<input type="hidden" name="_flags" value="speed_enabled,dimensions,lcp_preload,lazy_correct,delivery">

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
