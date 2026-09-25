<?php
/**
 * Scripts with inline code after them are never deferred.
 *
 * Deferring moment while its inline moment.updateLocale() ran in place threw
 * "moment is not defined" and broke the WordPress packages GiveWP relies on.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;

/**
 * Defer eligibility follows WordPress's own rule.
 */
final class ScriptOrderTest extends TestCase {

	/**
	 * The defer filter and the weight rule both consult the ordering rule.
	 *
	 * @return void
	 */
	public function test_defer_respects_inline_after_scripts(): void {
		$code  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/speed/class-scripts.php' );
		$defer = (string) strstr( $code, 'public static function defer(' );
		$this->assertStringContainsString( 'self::must_run_in_order()', substr( $defer, 0, 400 ) );
		$rule = (string) strstr( $code, 'private static function must_run_in_order(' );
		$this->assertStringContainsString( "get_data( \$handle, 'after' )", $rule );
		$this->assertStringContainsString( '->deps', $rule, 'dependencies of an in-place script must stay in place' );
		$blocking = (string) strstr( $code, 'public static function stays_blocking(' );
		$this->assertStringContainsString( 'self::must_run_in_order()', $blocking );
	}

	/**
	 * The Google check rejects any setting that changes the page's length.
	 *
	 * @return void
	 */
	public function test_google_check_rejects_layout_changes(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/psi.js' );
		$this->assertStringContainsString( 'fullPageScreenshot', $js );
		$this->assertStringContainsString( 'row.broken = true;', $js );
	}
}
