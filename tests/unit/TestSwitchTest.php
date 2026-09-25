<?php
/**
 * One-request test switches.
 *
 * The Google check measures each setting by loading the page with it
 * switched, so the switch must only ever touch speed settings.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;

/**
 * ?smao-test= parsing.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TestSwitchTest extends TestCase {

	/**
	 * Parse a test string in a fresh process.
	 *
	 * @param string $raw Query value.
	 * @return array
	 */
	private function parse( string $raw ): array {
		if ( ! function_exists( 'wp_unslash' ) ) {
			function wp_unslash( $value ) {
				return $value;
			}
		}
		if ( ! function_exists( 'is_admin' ) ) {
			function is_admin() {
				return false;
			}
		}
		$_GET['smao-test'] = $raw;
		require_once dirname( __DIR__, 2 ) . '/includes/core/class-settings.php';
		return \SMAO\Settings::test();
	}

	public function test_single_flags(): void {
		$this->assertSame( array( 'delay_all' => true ), $this->parse( 'delay_all.on' ) );
	}

	public function test_colon_form_still_works(): void {
		$this->assertSame( array( 'defer_js' => false ), $this->parse( 'defer_js:off' ) );
	}

	public function test_off_switches_every_speed_feature_off(): void {
		$result = $this->parse( 'off' );
		$this->assertFalse( $result['speed_enabled'] );
		$this->assertCount( count( \SMAO\Settings::TESTABLE ), $result );
		$this->assertNotContains( true, $result );
	}

	public function test_only_speed_switches_can_change(): void {
		$this->assertSame( array(), $this->parse( 'page_cache.off,coverage_reviewed.on,psi_key.on,evil.on,delay_all.maybe' ) );
	}
}
