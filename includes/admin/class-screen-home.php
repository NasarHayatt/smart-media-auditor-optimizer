<?php
/**
 * Home: what to do next, and what it is worth.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * The landing screen.
 */
final class Screen_Home {

	/**
	 * Render the screen.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	public static function render( array $input ): void {
		$step    = Next_Step::get();
		$summary = Next_Step::summary();
		self::next_step( $step );
		self::figures( $step, $summary );
		self::speed_card();
	}

	/**
	 * The single recommended action.
	 *
	 * @param array $step Current step.
	 * @return void
	 */
	private static function next_step( array $step ): void {
		list( $icon, $message ) = Next_Step::reassurance( $step );
		?>
		<section class="smao-next smao-tone-<?php echo esc_attr( $step['tone'] ); ?>" id="smao-next">
			<p class="smao-next-label"><?php esc_html_e( 'Next step', 'smart-media-auditor-optimizer' ); ?></p>
			<h2 id="smao-next-title"><?php echo esc_html( $step['title'] ); ?></h2>
			<p class="smao-next-detail" id="smao-next-detail"><?php echo esc_html( $step['detail'] ); ?></p>

			<div class="smao-next-actions">
				<?php if ( 'link' === $step['action'] ) : ?>
					<a class="smao-cta" href="<?php echo esc_url( $step['href'] ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
				<?php else : ?>
					<button type="button" class="smao-cta" data-command="<?php echo esc_attr( $step['command'] ); ?>"><?php echo esc_html( $step['label'] ); ?></button>
				<?php endif; ?>
				<?php if ( $step['note'] ) : ?>
					<span class="smao-next-note"><?php echo esc_html( $step['note'] ); ?></span>
				<?php endif; ?>
			</div>

			<div class="smao-next-progress" id="smao-next-progress" <?php echo $step['progress'] ? '' : 'hidden'; ?>>
				<div class="smao-track"><div class="smao-track-fill" id="smao-track-fill"></div></div>
				<div class="smao-track-meta">
					<span id="smao-phase-label"><?php esc_html_e( 'Getting started', 'smart-media-auditor-optimizer' ); ?></span>
					<span id="smao-percent"></span>
				</div>
				<p id="smao-progress" class="screen-reader-text" role="status" aria-live="polite"></p>
			</div>

			<p class="smao-reassure"><?php echo esc_html( self::icon( $icon ) . ' ' . $message ); ?></p>
		</section>
		<?php
	}

	/**
	 * Headline figures, expressed as outcomes rather than scan internals.
	 *
	 * @param array $step    Current step.
	 * @param array $summary Scan summary.
	 * @return void
	 */
	private static function figures( array $step, array $summary ): void {
		$scanned = 'neutral' !== $step['tone'] || $summary['total'] > 0;
		$figures = $scanned
			? array(
				array(
					__( 'Space you can free up', 'smart-media-auditor-optimizer' ),
					$summary['unused'] > 0 ? size_format( $summary['reclaimable'] ) : size_format( 0 ),
					'reclaimable',
				),
				array( __( 'Images in use', 'smart-media-auditor-optimizer' ), number_format_i18n( $summary['used'] ), 'used' ),
				array( __( 'Not used anywhere', 'smart-media-auditor-optimizer' ), number_format_i18n( $summary['unused'] ), 'unused' ),
				array( __( 'Not sure about', 'smart-media-auditor-optimizer' ), number_format_i18n( $summary['unsure'] ), 'unsure' ),
				array( __( 'Already removed', 'smart-media-auditor-optimizer' ), number_format_i18n( $summary['removed'] ), 'removed' ),
			)
			: array(
				array( __( 'Images on your site', 'smart-media-auditor-optimizer' ), number_format_i18n( self::attachment_count() ), '' ),
				array( __( 'Last checked', 'smart-media-auditor-optimizer' ), __( 'Never', 'smart-media-auditor-optimizer' ), '' ),
			);
		?>
		<section class="smao-figures" aria-label="<?php esc_attr_e( 'Summary of your media', 'smart-media-auditor-optimizer' ); ?>">
			<?php foreach ( $figures as $figure ) : ?>
				<article class="smao-figure">
					<span><?php echo esc_html( $figure[0] ); ?></span>
					<strong <?php echo $figure[2] ? 'data-figure="' . esc_attr( $figure[2] ) . '"' : ''; ?>><?php echo esc_html( $figure[1] ); ?></strong>
				</article>
			<?php endforeach; ?>
		</section>
		<?php if ( $summary['unsure'] > 0 ) : ?>
			<p class="smao-hint">
				<?php esc_html_e( 'Not sure about means we found something that might be a reference, so we left those alone. They are never offered for removal.', 'smart-media-auditor-optimizer' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * A short, plain summary of what is making pages faster.
	 *
	 * @return void
	 */
	private static function speed_card(): void {
		$settings = Settings::get();
		$on       = (bool) $settings['speed_enabled'];
		$active   = array();
		if ( $on && $settings['lcp_preload'] ) {
			$active[] = __( 'main image loads first', 'smart-media-auditor-optimizer' );
		}
		if ( $on && $settings['dimensions'] ) {
			$active[] = __( 'pages stop jumping about', 'smart-media-auditor-optimizer' );
		}
		if ( $on && $settings['lazy_correct'] ) {
			$active[] = __( 'images below the fold wait their turn', 'smart-media-auditor-optimizer' );
		}
		if ( $on && $settings['delivery'] ) {
			$active[] = __( 'smaller image formats where supported', 'smart-media-auditor-optimizer' );
		}
		?>
		<section class="smao-panel smao-speed-card">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Speed', 'smart-media-auditor-optimizer' ); ?></h2>
				<span class="smao-chip <?php echo $on ? 'is-on' : ''; ?>">
					<?php echo $on ? esc_html__( 'On', 'smart-media-auditor-optimizer' ) : esc_html__( 'Off', 'smart-media-auditor-optimizer' ); ?>
				</span>
			</div>
			<?php if ( $active ) : ?>
				<p><?php esc_html_e( 'Right now, on every page:', 'smart-media-auditor-optimizer' ); ?></p>
				<ul class="smao-ticks">
					<?php foreach ( $active as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'Speed help is switched off, so your pages load exactly as your theme sends them.', 'smart-media-auditor-optimizer' ); ?></p>
			<?php endif; ?>
			<p><a class="smao-link" href="<?php echo esc_url( admin_url( 'admin.php?page=smao-speed' ) ); ?>"><?php esc_html_e( 'Change what this does', 'smart-media-auditor-optimizer' ); ?></a></p>
		</section>
		<?php
	}

	/**
	 * Count attachments without needing a scan.
	 *
	 * @return int
	 */
	private static function attachment_count(): int {
		$counts = (array) wp_count_attachments();
		$total  = 0;
		foreach ( $counts as $mime => $count ) {
			if ( str_starts_with( (string) $mime, 'image/' ) ) {
				$total += (int) $count;
			}
		}
		return $total;
	}

	/**
	 * A small text mark, avoiding an icon font dependency.
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	private static function icon( string $name ): string {
		$marks = array(
			'yes'    => '✓',
			'undo'   => '↺',
			'shield' => '•',
		);
		return $marks[ $name ] ?? '•';
	}
}
