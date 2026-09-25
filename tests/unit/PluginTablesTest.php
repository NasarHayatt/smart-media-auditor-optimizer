<?php
/**
 * Plugin-owned tables are scanned, and checked before any removal.
 *
 * Slider images referenced only in wp_revslider_slides were reported unused
 * and removed from a live site, blanking its header slider.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;

/**
 * Scan scope covers every table.
 */
final class PluginTablesTest extends TestCase {

	/**
	 * Both the scan and the pre-removal check read the full list.
	 *
	 * @return void
	 */
	public function test_scan_and_recheck_include_plugin_tables(): void {
		$code = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/engine/class-scanner.php' );
		$this->assertStringContainsString( 'return array_merge( self::core_sources(), self::plugin_sources() );', $code );
		$recheck = (string) strstr( $code, 'public static function assert_no_references' );
		$this->assertStringContainsString( 'self::sources()', $recheck );
		$this->assertStringContainsString( "SHOW TABLES LIKE %s", $code );
		$this->assertStringContainsString( "delete_transient( 'smao_plugin_sources' );", (string) strstr( $code, 'public static function start' ) );
	}

	/**
	 * Logs and sessions are skipped; content tables are not.
	 *
	 * @return void
	 */
	public function test_noise_filter(): void {
		$code  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/engine/class-scanner.php' );
		preg_match( '#\$noise\s*=\s*\'([^\']+)\'#', $code, $match );
		$this->assertNotEmpty( $match );
		$noise = $match[1];
		foreach ( array( 'actionscheduler_logs', 'wflogs', 'woocommerce_sessions', 'redirection_404', 'simple_history_log' ) as $table ) {
			$this->assertSame( 1, preg_match( $noise, $table ), $table . ' should be skipped' );
		}
		foreach ( array( 'revslider_slides', 'revslider_sliders', 'layerslider', 'nextend2_smartslider3_slides', 'wpforms_entries', 'yoast_indexable', 'e_submissions' ) as $table ) {
			$this->assertSame( 0, preg_match( $noise, $table ), $table . ' must be scanned' );
		}
	}
}
