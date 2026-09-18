<?php
/**
 * Matcher component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Matcher workflow and safety policy.
 */
final class Matcher {
	/**
	 * Normalize an encoded media reference for token lookup.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function normalize( string $value ): string {
		$value = rawurldecode( html_entity_decode( str_replace( '\\/', '/', $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$value = preg_replace( '~[?#].*$~', '', $value );
		return ltrim( str_replace( '\\', '/', $value ), '/' );
	}

	/**
	 * Extract conservative attachment-reference tokens without unserializing source data.
	 *
	 * @param string $text Text.
	 * @param string $field Field.
	 * @return array
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function tokens( string $text, string $field = '' ): array {
		$text   = html_entity_decode( str_replace( '\\/', '/', $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$result = array();
		$put    = static function ( string $token, string $kind, int $strength ) use ( &$result ): void {
			$key = $kind . ':' . $token;
			if ( ! isset( $result[ $key ] ) || $result[ $key ]['strength'] < $strength ) {
				$result[ $key ] = compact( 'token', 'kind', 'strength' );
			}
		};
		// Full upload paths, CSS URLs, srcset entries, serialized and JSON strings.
		// PHP 8.0's bundled PCRE JIT can silently miss JSON/CSS paths here.
		// Disable JIT for this expression only; leave server configuration unchanged.
		$matched = preg_match_all( '~(*NO_JIT)(?<![\pL\pN_.%+@\~:/-])(?:https?://[^\s<>"\'()]+|/?[\pL\pN_.%+@\~:/-]+\.[a-zA-Z0-9]{2,8})(?:\?[^\s<>"\'()]*)?~u', $text, $matches );
		if ( false === $matched ) {
			throw new \RuntimeException( __( 'Unparseable or overly complex source text; scan requires review.', 'smart-media-auditor-optimizer' ) ); }
		foreach ( $matches[0] as $value ) {
			$value = self::normalize( $value );
			$parts = explode( '/', $value );
			$put( (string) end( $parts ), 'name', 1 );
			// Index all path suffixes, including CDN hosts with an unchanged object key.
			while ( isset( $parts[1] ) ) {
				$put( implode( '/', $parts ), 'path', 2 );
				array_shift( $parts );
			}
		}
		preg_match_all( '/(?<![\d.])\b([1-9][0-9]{0,17})\b(?![\d.])/', $text, $numbers );
		$known = in_array( $field, array( '_thumbnail_id', '_product_image_gallery', 'thumbnail_id', 'site_icon', 'custom_logo', '_menu_item_object_id' ), true );
		foreach ( $numbers[1] as $number ) {
			$put( $number, 'id', $known ? 2 : 1 );
		}
		preg_match_all( '/(?:wp-image-|attachment_|"(?:id|mediaId|imageId)"\s*:\s*)([1-9][0-9]*)/', $text, $explicit );
		foreach ( $explicit[1] as $number ) {
			// Generic JSON id may name another object; only WP CSS IDs are strong.
			$put( $number, 'id', preg_match( '/(?:wp-image-|attachment_)' . preg_quote( $number, '/' ) . '\b/', $text ) ? 2 : 1 );
		}
		preg_match_all( '/\[(?:gallery|playlist)[^\]]*\b(?:ids|include)\s*=\s*["\']([0-9,\s]+)["\']/i', $text, $galleries );
		foreach ( $galleries[1] as $list ) {
			foreach ( preg_split( '/[\s,]+/', trim( $list ) ) as $number ) {
				if ( ctype_digit( $number ) && (int) $number > 0 ) {
					$put( $number, 'id', 2 );
				}
			}
		}
		return $result;
	}

	/**
	 * Neutralize formula-like spreadsheet cells.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv( mixed $value ): string {
		$value = (string) $value;
		return preg_match( '/^[\s\x00-\x1f]*[=+@-]/', $value ) ? "'" . $value : $value;
	}
}
