<?php
/**
 * Guards against substring ID matching.
 *
 * The pre-removal recheck used a bare "LIKE %<id>%", so attachment 27 matched
 * any row containing those two digits: 127, 275, a timestamp, a serialized
 * length. Every removal was refused. The recheck now confirms hits with this
 * tokenizer, so these rules are what keeps it honest.
 *
 * @package SMAO
 */

use PHPUnit\Framework\TestCase;
use SMAO\Matcher;

/**
 * Tokenizer precision around numeric identifiers.
 */
final class ReferenceMatchTest extends TestCase {

	/**
	 * Collect the ID tokens found in a string.
	 *
	 * @param string $text  Source text.
	 * @param string $field Field name.
	 * @return array
	 */
	private function ids( string $text, string $field = '' ): array {
		$ids = array();
		foreach ( Matcher::tokens( $text, $field ) as $token ) {
			if ( 'id' === $token['kind'] ) {
				$ids[] = (int) $token['token'];
			}
		}
		return $ids;
	}

	/**
	 * A longer number must never register as one of its own substrings.
	 *
	 * @return void
	 */
	public function test_longer_numbers_do_not_match_their_substrings(): void {
		$this->assertNotContains( 27, $this->ids( '127' ), '127 must not look like a reference to attachment 27' );
		$this->assertNotContains( 27, $this->ids( '275' ) );
		$this->assertNotContains( 27, $this->ids( 'Order 1275 shipped' ) );
		$this->assertContains( 127, $this->ids( '127' ) );
	}

	/**
	 * Decimal components must not be read as identifiers.
	 *
	 * @return void
	 */
	public function test_decimals_are_not_identifiers(): void {
		$this->assertNotContains( 27, $this->ids( '3.27' ) );
		$this->assertNotContains( 27, $this->ids( '27.5' ) );
	}

	/**
	 * An explicit WordPress image class is a strong reference.
	 *
	 * @return void
	 */
	public function test_explicit_image_class_is_a_strong_reference(): void {
		$strong = array();
		foreach ( Matcher::tokens( '<img class="wp-image-27">' ) as $token ) {
			if ( 'id' === $token['kind'] && 2 === $token['strength'] ) {
				$strong[] = (int) $token['token'];
			}
		}
		$this->assertContains( 27, $strong );
	}

	/**
	 * Filenames are matched by name and by path suffix.
	 *
	 * @return void
	 */
	public function test_filenames_are_matched(): void {
		$names = array();
		foreach ( Matcher::tokens( 'see https://example.test/wp-content/uploads/2026/09/hero.jpg here' ) as $token ) {
			if ( in_array( $token['kind'], array( 'name', 'path' ), true ) ) {
				$names[] = $token['token'];
			}
		}
		$this->assertContains( 'hero.jpg', $names );
	}
}
