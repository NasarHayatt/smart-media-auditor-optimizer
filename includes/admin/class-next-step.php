<?php
/**
 * Works out the one thing the user should do next.
 *
 * Every screen asks this rather than showing raw scan state, so the interface
 * never leaves someone to infer what a phase name or a blocker list means.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * The single recommended action for the current state of the site.
 */
final class Next_Step {

	/**
	 * Describe the current state and the action that follows from it.
	 *
	 * @return array{tone:string,status:string,title:string,detail:string,action:string,
	 *               label:string,note:string,href:string,command:string,progress:bool}
	 */
	public static function get(): array {
		$scan     = Database::state();
		$state    = (string) ( $scan['state'] ?? 'idle' );
		$settings = Settings::get();
		$summary  = self::summary();

		if ( 'idle' === $state || '' === $state || empty( $scan['id'] ) ) {
			return self::step(
				'neutral',
				__( 'Not checked yet', 'smart-media-auditor-optimizer' ),
				__( 'Find out what your images are costing you', 'smart-media-auditor-optimizer' ),
				__( 'This looks at every image on your site and works out which ones nothing links to. It only reads. Nothing is changed or deleted.', 'smart-media-auditor-optimizer' ),
				'command',
				__( 'Check my media', 'smart-media-auditor-optimizer' ),
				__( 'Usually takes a minute or two', 'smart-media-auditor-optimizer' ),
				'',
				'start'
			);
		}

		if ( 'running' === $state ) {
			$step = self::step(
				'busy',
				__( 'Checking', 'smart-media-auditor-optimizer' ),
				__( 'Checking your images', 'smart-media-auditor-optimizer' ),
				__( 'You can leave this page. It carries on in the background, and you can come back whenever you like.', 'smart-media-auditor-optimizer' ),
				'command',
				__( 'Pause', 'smart-media-auditor-optimizer' ),
				'',
				'',
				'pause'
			);
			$step['progress'] = true;
			return $step;
		}

		if ( 'paused' === $state ) {
			return self::step(
				'busy',
				__( 'Paused', 'smart-media-auditor-optimizer' ),
				__( 'Checking is paused', 'smart-media-auditor-optimizer' ),
				__( 'Nothing has been changed. Pick up where you left off whenever you are ready.', 'smart-media-auditor-optimizer' ),
				'command',
				__( 'Carry on checking', 'smart-media-auditor-optimizer' ),
				'',
				'',
				'resume'
			);
		}

		// Finished, but something happened that makes the result untrustworthy.
		if ( ! empty( $scan['incomplete'] ) ) {
			return self::step(
				'warning',
				__( 'Needs another look', 'smart-media-auditor-optimizer' ),
				__( 'The check could not finish properly', 'smart-media-auditor-optimizer' ),
				__( 'Part of your site could not be read, so these results are not complete enough to act on. Running it again usually sorts it out.', 'smart-media-auditor-optimizer' ),
				'command',
				__( 'Check again', 'smart-media-auditor-optimizer' ),
				'',
				'',
				'restart'
			);
		}

		if ( isset( $scan['epoch'] ) && get_option( 'smao_epoch', '' ) !== $scan['epoch'] ) {
			return self::step(
				'warning',
				__( 'Out of date', 'smart-media-auditor-optimizer' ),
				__( 'Your site changed since the last check', 'smart-media-auditor-optimizer' ),
				__( 'Content that may use a media file was added or edited since the last check, so these results may be out of date. Check again before removing anything.', 'smart-media-auditor-optimizer' ),
				'command',
				__( 'Check again', 'smart-media-auditor-optimizer' ),
				'',
				'',
				'restart'
			);
		}

		if ( time() - (int) ( $scan['finished'] ?? 0 ) > DAY_IN_SECONDS ) {
			return self::step(
				'warning',
				__( 'Out of date', 'smart-media-auditor-optimizer' ),
				__( 'This check is more than a day old', 'smart-media-auditor-optimizer' ),
				__( 'Your site has probably changed since then. Run it again so you are working from something current.', 'smart-media-auditor-optimizer' ),
				'command',
				__( 'Check again', 'smart-media-auditor-optimizer' ),
				'',
				'',
				'restart'
			);
		}

		if ( ! $settings['coverage_reviewed'] ) {
			return self::step(
				'action',
				__( 'One question first', 'smart-media-auditor-optimizer' ),
				__( 'One thing we cannot check for you', 'smart-media-auditor-optimizer' ),
				__( 'We can see everything stored in your site. We cannot see images used by custom code, or linked from somewhere else on the internet. Have a think about that, then confirm and we will finish the job.', 'smart-media-auditor-optimizer' ),
				'link',
				__( 'Answer and finish checking', 'smart-media-auditor-optimizer' ),
				'',
				admin_url( 'admin.php?page=smao-cleanup' ),
				''
			);
		}

		if ( is_multisite() ) {
			return self::step(
				'neutral',
				__( 'Report only', 'smart-media-auditor-optimizer' ),
				__( 'Your results are ready to look at', 'smart-media-auditor-optimizer' ),
				__( 'This is a multisite network, so removing files is switched off. You can still see everything that was found and export it.', 'smart-media-auditor-optimizer' ),
				'link',
				__( 'See what was found', 'smart-media-auditor-optimizer' ),
				'',
				admin_url( 'admin.php?page=smao-cleanup' ),
				''
			);
		}

		try {
			Vault::root( false );
		} catch ( \Throwable $e ) {
			return self::step(
				'action',
				__( 'Setup needed', 'smart-media-auditor-optimizer' ),
				__( 'Choose somewhere safe to keep removed files', 'smart-media-auditor-optimizer' ),
				__( 'Removed images are moved somewhere private rather than deleted, so you can always put them back. Tell us which folder to use and you are ready to go.', 'smart-media-auditor-optimizer' ),
				'link',
				__( 'Choose a folder', 'smart-media-auditor-optimizer' ),
				'',
				admin_url( 'admin.php?page=smao-advanced&tab=recovery' ),
				''
			);
		}

		if ( $summary['unused'] > 0 ) {
			return self::step(
				'action',
				__( 'Action needed', 'smart-media-auditor-optimizer' ),
				sprintf(
					/* translators: %s is a number of images. */
					_n( '%s image is not used anywhere', '%s images are not used anywhere', $summary['unused'], 'smart-media-auditor-optimizer' ),
					number_format_i18n( $summary['unused'] )
				),
				__( 'Nothing on your site links to these. You will see every one, and where we looked, before anything moves. You can put them back afterwards.', 'smart-media-auditor-optimizer' ),
				'link',
				sprintf(
					/* translators: %s is a number of images. */
					_n( 'Review %s image', 'Review %s images', $summary['unused'], 'smart-media-auditor-optimizer' ),
					number_format_i18n( $summary['unused'] )
				),
				__( 'Nothing moves until you confirm', 'smart-media-auditor-optimizer' ),
				admin_url( 'admin.php?page=smao-cleanup' ),
				''
			);
		}

		return self::step(
			'done',
			__( 'All clear', 'smart-media-auditor-optimizer' ),
			__( 'Your media is tidy', 'smart-media-auditor-optimizer' ),
			__( 'Nothing is sitting unused. We will keep your pages loading their main image as early as possible.', 'smart-media-auditor-optimizer' ),
			'command',
			__( 'Check again', 'smart-media-auditor-optimizer' ),
			'',
			'',
			'restart'
		);
	}

	/**
	 * Headline figures for the current scan, in terms people care about.
	 *
	 * @return array{unused:int,used:int,unsure:int,broken:int,removed:int,reclaimable:int,total:int,bytes:int}
	 */
	public static function summary(): array {
		global $wpdb;
		$scan  = Database::state();
		$id    = (string) ( $scan['id'] ?? '' );
		$table = Database::table( 'media' );

		$out = array(
			'unused'      => 0,
			'used'        => 0,
			'unsure'      => 0,
			'broken'      => 0,
			'removed'     => 0,
			'reclaimable' => 0,
			'total'       => 0,
			'bytes'       => 0,
		);
		if ( '' === $id ) {
			return $out;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status,COUNT(*) AS n,SUM(bytes) AS b FROM %i WHERE scan_id=%s GROUP BY status', $table, $id ),
			ARRAY_A
		);
		Database::check( $rows );

		foreach ( $rows as $row ) {
			$n = (int) $row['n'];
			/*
			 * Files already moved to safe storage are not part of the picture:
			 * counting them as "not sure about" made it look like the plugin
			 * had doubts about images the user had deliberately removed.
			 */
			if ( 'quarantined' === $row['status'] ) {
				$out['removed'] += $n;
				continue;
			}
			$out['total'] += $n;
			$out['bytes'] += (int) $row['b'];
			if ( 'unused' === $row['status'] ) {
				$out['unused']      = $n;
				$out['reclaimable'] = (int) $row['b'];
			} elseif ( in_array( $row['status'], array( 'used', 'download' ), true ) ) {
				$out['used'] += $n;
			} elseif ( 'broken' === $row['status'] ) {
				$out['broken'] += $n;
			} else {
				$out['unsure'] += $n;
			}
		}
		return $out;
	}

	/**
	 * Assemble one step description.
	 *
	 * @param string $tone    Visual tone.
	 * @param string $status  Short status chip.
	 * @param string $title   Headline.
	 * @param string $detail  Supporting sentence.
	 * @param string $action  Either "command" or "link".
	 * @param string $label   Button label.
	 * @param string $note    Reassurance beside the button.
	 * @param string $href    Destination for a link action.
	 * @param string $command Scan command for a command action.
	 * @return array
	 */
	private static function step( string $tone, string $status, string $title, string $detail, string $action, string $label, string $note, string $href, string $command ): array {
		return compact( 'tone', 'status', 'title', 'detail', 'action', 'label', 'note', 'href', 'command' ) + array( 'progress' => false );
	}

	/**
	 * A one-line reassurance appropriate to the current state.
	 *
	 * @param array $step Current step.
	 * @return array{0:string,1:string} Icon name and message.
	 */
	public static function reassurance( array $step ): array {
		if ( 'done' === $step['tone'] ) {
			return array( 'yes', __( 'Everything you have removed can still be put back.', 'smart-media-auditor-optimizer' ) );
		}
		if ( 'action' === $step['tone'] ) {
			return array( 'undo', __( 'Anything you remove can be restored later.', 'smart-media-auditor-optimizer' ) );
		}
		return array( 'shield', __( 'Nothing is ever deleted without you saying so.', 'smart-media-auditor-optimizer' ) );
	}
}
