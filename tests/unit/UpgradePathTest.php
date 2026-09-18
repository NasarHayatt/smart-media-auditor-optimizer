<?php
/**
 * Guards the 1.x to 2.x upgrade path.
 *
 * 2.0 moved every class into includes/<module>/. A site running a stale 1.x
 * bootstrap, which an opcode cache can easily cause, then looked for classes at
 * the old flat paths and died with a fatal error. These tests pin down the
 * compatibility shims that make that survivable.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;

/**
 * Legacy path compatibility.
 */
final class UpgradePathTest extends TestCase {

	/**
	 * Classes the 1.x autoloader could ask for, and where they now live.
	 *
	 * @return array<string,string>
	 */
	private function moved(): array {
		return array(
			'plugin'    => 'core',
			'database'  => 'core',
			'settings'  => 'core',
			'scanner'   => 'engine',
			'matcher'   => 'engine',
			'media'     => 'engine',
			'vault'     => 'engine',
			'optimizer' => 'engine',
			'admin'     => 'admin',
			'live'      => 'admin',
		);
	}

	/**
	 * Every moved class keeps a shim at its 1.x path.
	 *
	 * @return void
	 */
	public function test_legacy_paths_still_resolve(): void {
		$root = dirname( __DIR__, 2 ) . '/includes';

		foreach ( $this->moved() as $name => $module ) {
			$shim   = "$root/class-$name.php";
			$target = "$root/$module/class-$name.php";

			$this->assertFileExists( $target, "class-$name.php should live in $module/" );
			$this->assertFileExists( $shim, "a 1.x install will look for includes/class-$name.php" );

			$contents = (string) file_get_contents( $shim );
			$this->assertStringContainsString(
				"/$module/class-$name.php",
				$contents,
				"the shim must forward to $module/class-$name.php"
			);
		}
	}

	/**
	 * Each shim installs the modern autoloader.
	 *
	 * Without this a stale 1.x autoloader cannot resolve names containing an
	 * underscore, such as SMAO\Report_Table, and the site still dies.
	 *
	 * @return void
	 */
	public function test_shims_install_the_modern_autoloader(): void {
		$root = dirname( __DIR__, 2 ) . '/includes';
		$this->assertFileExists( "$root/autoload.php" );

		foreach ( array_keys( $this->moved() ) as $name ) {
			$this->assertStringContainsString(
				"require_once __DIR__ . '/autoload.php';",
				(string) file_get_contents( "$root/class-$name.php" ),
				"class-$name.php must install the autoloader before forwarding"
			);
		}
	}

	/**
	 * The modern autoloader accepts underscored class names.
	 *
	 * @return void
	 */
	public function test_autoloader_maps_underscored_class_names(): void {
		$root = dirname( __DIR__, 2 ) . '/includes';
		$this->assertMatchesRegularExpression(
			'/A-Za-z0-9_/',
			(string) file_get_contents( "$root/autoload.php" ),
			'the autoloader pattern must allow underscores'
		);

		foreach ( array( 'Report_Table', 'Screen_Home', 'Next_Step' ) as $class ) {
			$file = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
			$this->assertFileExists( "$root/admin/$file", "$class should resolve to admin/$file" );
		}
	}

	/**
	 * The bootstrap guards its constants so a double load cannot warn.
	 *
	 * @return void
	 */
	public function test_bootstrap_constants_are_guarded(): void {
		$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/smart-media-auditor-optimizer.php' );
		$this->assertStringContainsString( "if ( ! defined( 'SMAO_FILE' ) )", $main );
		$this->assertStringContainsString( "if ( ! defined( 'SMAO_VERSION' ) )", $main );
	}
}
