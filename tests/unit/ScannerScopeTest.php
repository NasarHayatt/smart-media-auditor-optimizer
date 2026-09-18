<?php
/**
 * Guards the reference-scan scope.
 *
 * Scanning WordPress internals for media references produced weak "evidence"
 * for files nothing used, which suppressed almost every unused result. These
 * tests pin down what must stay out of scope, and what must stay in.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Scanner;

/**
 * Option scoping rules.
 */
final class ScannerScopeTest extends TestCase {

	/**
	 * Machinery options can never hold a media reference.
	 *
	 * @return void
	 */
	public function test_internal_wordpress_options_are_excluded(): void {
		$ignored = Scanner::ignored_options();

		foreach ( array( 'rewrite_rules', 'cron', 'wp_user_roles', 'active_plugins', 'nonce_salt', 'sidebars_widgets' ) as $option ) {
			$this->assertContains(
				$option,
				$ignored,
				"$option is WordPress machinery and must not be scanned for media references"
			);
		}
	}

	/**
	 * Theme modifications legitimately hold media and must stay in scope.
	 *
	 * custom_logo and header_image live here, so excluding these options would
	 * cause a genuinely used file to be reported as unused.
	 *
	 * @return void
	 */
	public function test_theme_mods_are_not_excluded(): void {
		$ignored = Scanner::ignored_options();

		foreach ( $ignored as $option ) {
			$this->assertStringStartsNotWith(
				'theme_mods_',
				$option,
				'theme_mods_* holds custom_logo and header_image and must remain in scope'
			);
		}
		$this->assertNotContains( 'site_icon', $ignored );
		$this->assertNotContains( 'page_on_front', $ignored );
	}

	/**
	 * The exclusion list must contain no duplicates and no empty entries.
	 *
	 * @return void
	 */
	public function test_exclusion_list_is_clean(): void {
		$ignored = Scanner::ignored_options();

		$this->assertNotEmpty( $ignored );
		$this->assertSame( array_values( array_unique( $ignored ) ), $ignored, 'exclusion list should be de-duplicated' );
		foreach ( $ignored as $option ) {
			$this->assertIsString( $option );
			$this->assertNotSame( '', trim( $option ) );
		}
	}
}
