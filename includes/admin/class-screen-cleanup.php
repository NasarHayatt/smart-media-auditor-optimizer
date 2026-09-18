<?php
/**
 * Clean up: a guided review of images nothing links to.
 *
 * No filters, no jargon, no typed confirmation. The user looks at what was
 * found, keeps anything they recognise, and confirms once.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Guided review and recovery.
 */
final class Screen_Cleanup {

	/**
	 * Images shown per page of the review.
	 */
	private const PER_PAGE = 24;

	/**
	 * Render the screen.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	public static function render( array $input ): void {
		$step = Next_Step::get();

		if ( ! Settings::get()['coverage_reviewed'] ) {
			self::the_question();
			return;
		}
		if ( in_array( $step['tone'], array( 'neutral', 'busy', 'warning' ), true ) && 'action' !== $step['tone'] ) {
			self::not_ready( $step );
			self::recovery();
			return;
		}
		self::review( $input );
		self::recovery();
	}

	/**
	 * The one thing the plugin genuinely cannot determine on its own.
	 *
	 * @return void
	 */
	private static function the_question(): void {
		?>
		<section class="smao-ask">
			<h2><?php esc_html_e( 'One thing we cannot check for you', 'smart-media-auditor-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'We can see everything stored inside your website: pages, posts, products, menus, widgets and theme settings. If an image is used in any of those, we will find it.', 'smart-media-auditor-optimizer' ); ?></p>
			<p><?php esc_html_e( 'What we cannot see is an image used by custom code somebody wrote for you, or one that another website links to directly. Those are rare, but only you would know.', 'smart-media-auditor-optimizer' ); ?></p>
			<form id="smao-scope" class="smao-ask-form">
				<label class="smao-choice">
					<input type="checkbox" name="scope_reviewed" required>
					<span><?php esc_html_e( 'I understand, and I have thought about custom code and outside links.', 'smart-media-auditor-optimizer' ); ?></span>
				</label>
				<button class="smao-cta"><?php esc_html_e( 'Finish checking my media', 'smart-media-auditor-optimizer' ); ?></button>
			</form>
			<p class="smao-reassure"><?php esc_html_e( 'Even after this, nothing is removed until you pick the images yourself.', 'smart-media-auditor-optimizer' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Explain why there is nothing to review yet, and point at the fix.
	 *
	 * @param array $step Current step.
	 * @return void
	 */
	private static function not_ready( array $step ): void {
		?>
		<section class="smao-next smao-tone-<?php echo esc_attr( $step['tone'] ); ?>">
			<h2><?php echo esc_html( $step['title'] ); ?></h2>
			<p class="smao-next-detail"><?php echo esc_html( $step['detail'] ); ?></p>
			<div class="smao-next-actions">
				<?php if ( 'link' === $step['action'] ) : ?>
					<a class="smao-cta" href="<?php echo esc_url( $step['href'] ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
				<?php else : ?>
					<button type="button" class="smao-cta" data-command="<?php echo esc_attr( $step['command'] ); ?>"><?php echo esc_html( $step['label'] ); ?></button>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * The review grid.
	 *
	 * @param array $input Query input.
	 * @return void
	 */
	private static function review( array $input ): void {
		global $wpdb;
		$table = Database::table( 'media' );
		$scan  = Database::state();
		$page  = max( 1, absint( $input['paged'] ?? 1 ) );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id=%s AND status=%s', $table, (string) ( $scan['id'] ?? '' ), 'unused' )
		);
		Database::check( $total );

		if ( ! $total ) {
			?>
			<section class="smao-panel smao-empty-state">
				<h2><?php esc_html_e( 'Nothing to review', 'smart-media-auditor-optimizer' ); ?></h2>
				<p><?php esc_html_e( 'Every image on your site is being used somewhere. There is nothing to clear out.', 'smart-media-auditor-optimizer' ); ?></p>
				<a class="smao-link" href="<?php echo esc_url( admin_url( 'admin.php?page=smao-home' ) ); ?>"><?php esc_html_e( 'Back to the overview', 'smart-media-auditor-optimizer' ); ?></a>
			</section>
			<?php
			return;
		}

		$pages = (int) ceil( $total / self::PER_PAGE );
		$page  = min( $page, $pages );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT attachment_id,filename,bytes,width,height,uploaded FROM %i WHERE scan_id=%s AND status=%s ORDER BY bytes DESC LIMIT %d OFFSET %d',
				$table,
				(string) ( $scan['id'] ?? '' ),
				'unused',
				self::PER_PAGE,
				( $page - 1 ) * self::PER_PAGE
			),
			ARRAY_A
		);
		Database::check( $rows );

		$bytes = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT SUM(bytes) FROM %i WHERE scan_id=%s AND status=%s', $table, (string) ( $scan['id'] ?? '' ), 'unused' )
		);
		?>
		<section class="smao-review" data-scope="review">
			<header class="smao-review-head">
				<div>
					<h2>
						<?php
						printf(
							/* translators: %s is a number of images. */
							esc_html( _n( '%s image is not used anywhere', '%s images are not used anywhere', $total, 'smart-media-auditor-optimizer' ) ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
					</h2>
					<p>
						<?php
						printf(
							/* translators: %s is an amount of disk space, for example 24 MB. */
							esc_html__( 'Removing all of them frees up %s. Keep any you recognise, then remove the rest.', 'smart-media-auditor-optimizer' ),
							esc_html( size_format( (int) $bytes ) )
						);
						?>
					</p>
				</div>
				<button type="button" class="smao-link-button" id="smao-keep-all"><?php esc_html_e( 'Keep all of these', 'smart-media-auditor-optimizer' ); ?></button>
			</header>

			<ul class="smao-grid-media">
				<?php foreach ( $rows as $row ) : ?>
					<?php self::tile( $row ); ?>
				<?php endforeach; ?>
			</ul>

			<?php if ( $pages > 1 ) : ?>
				<nav class="smao-pagination" aria-label="<?php esc_attr_e( 'More images', 'smart-media-auditor-optimizer' ); ?>">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $page,
								'total'     => $pages,
								'prev_text' => __( 'Back', 'smart-media-auditor-optimizer' ),
								'next_text' => __( 'More', 'smart-media-auditor-optimizer' ),
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>

			<footer class="smao-review-bar">
				<p class="smao-selection-count" data-total="<?php echo esc_attr( (string) count( $rows ) ); ?>"></p>
				<button type="button" class="smao-cta smao-cta-danger" data-action="quarantine"><?php esc_html_e( 'Move selected to safe storage', 'smart-media-auditor-optimizer' ); ?></button>
			</footer>
			<p class="smao-reassure"><?php esc_html_e( 'These are moved out of your uploads folder, not deleted. You can put any of them back from Recently removed below.', 'smart-media-auditor-optimizer' ); ?></p>
		</section>
		<?php
	}

	/**
	 * One image in the review grid.
	 *
	 * @param array $row Media record.
	 * @return void
	 */
	private static function tile( array $row ): void {
		$id    = (int) $row['attachment_id'];
		$thumb = wp_get_attachment_image_url( $id, 'medium' );
		?>
		<li class="smao-tile">
			<label>
				<input class="smao-id" type="checkbox" value="<?php echo esc_attr( (string) $id ); ?>" checked
					aria-label="
					<?php
					/* translators: %s is an image file name. */
					echo esc_attr( sprintf( __( 'Remove %s', 'smart-media-auditor-optimizer' ), $row['filename'] ) );
					?>
					">
				<span class="smao-tile-image">
					<?php if ( $thumb ) : ?>
						<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
					<?php else : ?>
						<span class="smao-tile-missing"><?php esc_html_e( 'No preview', 'smart-media-auditor-optimizer' ); ?></span>
					<?php endif; ?>
					<span class="smao-tile-mark" aria-hidden="true"></span>
				</span>
				<span class="smao-tile-name"><?php echo esc_html( $row['filename'] ); ?></span>
				<span class="smao-tile-meta">
					<?php echo esc_html( size_format( (int) $row['bytes'] ) ); ?>
					<?php if ( (int) $row['width'] ) : ?>
						&middot; <?php echo esc_html( $row['width'] . '×' . $row['height'] ); ?>
					<?php endif; ?>
				</span>
				<span class="smao-tile-why"><?php esc_html_e( 'Nothing links to this', 'smart-media-auditor-optimizer' ); ?></span>
			</label>
			<button type="button" class="smao-tile-where" data-where="<?php echo esc_attr( (string) $id ); ?>">
				<?php esc_html_e( 'Where did you look?', 'smart-media-auditor-optimizer' ); ?>
			</button>
		</li>
		<?php
	}

	/**
	 * Recently removed files, with restore.
	 *
	 * @return void
	 */
	private static function recovery(): void {
		global $wpdb;
		$table = Database::table( 'vault' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT attachment_id,created FROM %i WHERE operation='quarantine' ORDER BY created DESC LIMIT %d", $table, 12 ),
			ARRAY_A
		);
		Database::check( $rows );
		if ( ! $rows ) {
			return;
		}
		$retention = (int) Settings::get()['retention_days'];
		?>
		<section class="smao-panel" data-scope="recovery" id="smao-recovery">
			<div class="smao-panel-head">
				<h2><?php esc_html_e( 'Recently removed', 'smart-media-auditor-optimizer' ); ?></h2>
			</div>
			<p><?php esc_html_e( 'These are safe. Put any of them back whenever you like.', 'smart-media-auditor-optimizer' ); ?></p>
			<ul class="smao-removed">
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$id       = (int) $row['attachment_id'];
					$eligible = (int) $row['created'] + $retention * DAY_IN_SECONDS;
					?>
					<li>
						<label>
							<input class="smao-id" type="checkbox" value="<?php echo esc_attr( (string) $id ); ?>"
								aria-label="
								<?php
								/* translators: %s is an image title. */
								echo esc_attr( sprintf( __( 'Select %s', 'smart-media-auditor-optimizer' ), get_the_title( $id ) ?: '#' . $id ) );
								?>
								">
							<span class="smao-removed-name"><?php echo esc_html( get_the_title( $id ) ?: '#' . $id ); ?></span>
						</label>
						<span class="smao-removed-meta">
							<?php
							printf(
								/* translators: %s is a date. */
								esc_html__( 'Removed %s', 'smart-media-auditor-optimizer' ),
								esc_html( gmdate( 'j M', (int) $row['created'] ) )
							);
							?>
							&middot;
							<?php
							printf(
								/* translators: %s is a date. */
								esc_html__( 'kept until at least %s', 'smart-media-auditor-optimizer' ),
								esc_html( gmdate( 'j M', $eligible ) )
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<footer class="smao-review-bar">
				<p class="smao-selection-count"></p>
				<button type="button" class="smao-cta smao-cta-quiet" data-action="restore"><?php esc_html_e( 'Put selected back', 'smart-media-auditor-optimizer' ); ?></button>
			</footer>
		</section>
		<?php
	}
}
