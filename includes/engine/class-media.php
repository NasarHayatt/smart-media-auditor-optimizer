<?php
/**
 * Media component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Media workflow and safety policy.
 */
final class Media {
	/**
	 * Validate a path relative to the uploads directory.
	 *
	 * @param string $path Path.
	 * @return string
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function relative( string $path ): string {
		$path = wp_normalize_path( $path );
		if ( '' === $path || str_contains( $path, "\0" ) || str_contains( $path, ':' ) || str_starts_with( $path, '/' ) || preg_match( '~(?:^|/)\.\.?(/|$)~', $path ) ) {
			throw new \RuntimeException( __( 'Unsafe media path.', 'smart-media-auditor-optimizer' ) );
		}
		return $path;
	}

	/**
	 * Resolve an uploads path while rejecting traversal and symlinks.
	 *
	 * @param string $relative Relative.
	 * @param bool   $exists Exists.
	 * @return string
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function path( string $relative, bool $exists = true ): string {
		$relative = self::relative( $relative );
		$uploads  = wp_upload_dir();
		$root     = realpath( $uploads['basedir'] );
		if ( ! $root || is_link( $uploads['basedir'] ) ) {
			throw new \RuntimeException( __( 'Uploads directory is unavailable or linked.', 'smart-media-auditor-optimizer' ) );
		}
		$path = $root;
		foreach ( explode( '/', $relative ) as $part ) {
			$path .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $path ) ) {
				throw new \RuntimeException( __( 'Symlinked media is protected.', 'smart-media-auditor-optimizer' ) );
			}
		}
		if ( $exists && ( ! is_file( $path ) || ! is_readable( $path ) ) ) {
			throw new \RuntimeException( __( 'A media group file is missing or unreadable.', 'smart-media-auditor-optimizer' ) );
		}
		$parent = realpath( dirname( $path ) );
		if ( ! $parent || ! self::within( $parent, $root ) ) {
			throw new \RuntimeException( __( 'Media path escapes uploads or has a missing parent.', 'smart-media-auditor-optimizer' ) );
		}
		return $path;
	}

	/**
	 * Check directory containment with path-component boundaries.
	 *
	 * @param string $path Path.
	 * @param string $root Root.
	 * @return bool
	 */
	public static function within( string $path, string $root ): bool {
		$path = rtrim( wp_normalize_path( $path ), '/' );
		$root = rtrim( wp_normalize_path( $root ), '/' );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
			$root = strtolower( $root );
		}
		return $path === $root || str_starts_with( $path, $root . '/' );
	}

	/**
	 * Collect all metadata-listed files belonging to an attachment.
	 *
	 * @param int $id Id.
	 * @return array
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function group( int $id ): array {
		$main   = self::relative( (string) get_post_meta( $id, '_wp_attached_file', true ) );
		$meta   = wp_get_attachment_metadata( $id );
		$meta   = is_array( $meta ) ? $meta : array();
		$dir    = dirname( $main );
		$prefix = '.' === $dir ? '' : $dir . '/';
		$files  = array( $main );
		if ( ! empty( $meta['file'] ) ) {
			$files[] = self::relative( $meta['file'] );
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = self::relative( $prefix . $meta['original_image'] );
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = self::relative( $prefix . $size['file'] );
			}
		}
		foreach ( (array) get_post_meta( $id, '_wp_attachment_backup_sizes', true ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = self::relative( $prefix . $size['file'] );
			}
		}
		foreach ( (array) get_post_meta( $id, '_smao_alternates', true ) as $alternate ) {
			if ( ! empty( $alternate['file'] ) ) {
				$files[] = self::relative( $alternate['file'] );
			}
		}
		$files = array_values( array_unique( $files ) );
		if ( count( $files ) > 250 ) {
			throw new \RuntimeException( __( 'Unusually large media group requires manual review.', 'smart-media-auditor-optimizer' ) );
		}
		return array(
			'main'     => $main,
			'files'    => $files,
			'metadata' => $meta,
		);
	}

	/**
	 * Detect unsupported remote or filtered storage.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function remote( int $id ): bool {
		$uploads       = wp_upload_dir();
		$url           = (string) wp_get_attachment_url( $id );
		$relative      = (string) get_post_meta( $id, '_wp_attached_file', true );
		$expected      = trailingslashit( $uploads['baseurl'] ) . $relative;
		$filtered_path = (string) get_attached_file( $id );
		$local_path    = trailingslashit( $uploads['basedir'] ) . $relative;
		$remote        = $url !== $expected || wp_normalize_path( $filtered_path ) !== wp_normalize_path( $local_path );
		$remote        = $remote || wp_parse_url( $uploads['baseurl'], PHP_URL_HOST ) !== wp_parse_url( site_url(), PHP_URL_HOST );
		foreach ( array( 'amazonS3_info', '_wp_as3cf_attachment_metadata', 'ilab_s3_info', '_cloudinary_public_id' ) as $key ) {
			$remote = $remote || metadata_exists( 'post', $id, $key );
		}
		// Adapters may add protection, never remove baseline safety.
		return $remote || (bool) apply_filters( 'smao_protect_remote_attachment', false, $id );
	}

	/**
	 * Explain why an attachment is protected from local mutation.
	 *
	 * @param int   $id Id.
	 * @param array $group Group.
	 * @return string
	 */
	public static function protected_reason( int $id, array $group ): string {
		if ( is_multisite() ) {
			return 'Multisite media may be shared across blogs; destructive local operations are disabled.';
		}
		if ( self::remote( $id ) ) {
			return 'CDN, filtered URL or external storage: filesystem operations are unsupported.';
		}
		$ids    = array( (int) get_option( 'site_icon' ), (int) get_theme_mod( 'custom_logo' ), (int) Settings::get()['preload_id'] );
		$header = get_theme_mod( 'header_image_data' );
		if ( is_object( $header ) && isset( $header->attachment_id ) ) {
			$ids[] = (int) $header->attachment_id;
		}
		$theme_urls = array( get_theme_mod( 'header_image' ), get_theme_mod( 'background_image' ) );
		if ( in_array( $id, $ids, true ) || in_array( wp_get_attachment_url( $id ), $theme_urls, true ) ) {
			return 'Site identity or theme asset is protected.';
		}
		foreach ( $group['files'] as $relative ) {
			if ( preg_match( '~(?:^|/)(?:themes|plugins|smao-private)(?:/|$)|\.(?:php[0-9]?|phtml|phar|htaccess|ini|js|css|svg)$~i', $relative ) ) {
				return 'Executable, theme, vector or system asset is protected.';
			}
			foreach ( preg_split( '/\R/', Settings::get()['exclusions'] ) as $rule ) {
				$rule = trim( $rule );
				if ( '' === $rule ) {
					continue;
				}
				if ( 'id:' . $id === $rule || 'type:' . get_post_mime_type( $id ) === $rule || ( str_starts_with( $rule, 'path:' ) && str_starts_with( $relative, substr( $rule, 5 ) ) ) || ( str_starts_with( $rule, 'name:' ) && wp_basename( $relative ) === substr( $rule, 5 ) ) ) {
					return 'Administrator exclusion rule.';
				}
			}
		}
		return (string) apply_filters( 'smao_protected_reason', '', $id, $group );
	}

	/**
	 * Require an unshared, supported local attachment group.
	 *
	 * @param int $id Id.
	 * @return array
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function assert_local( int $id ): array {
		global $wpdb;
		if ( 'attachment' !== get_post_type( $id ) ) {
			throw new \RuntimeException( __( 'Attachment does not exist.', 'smart-media-auditor-optimizer' ) );
		}
		$group  = self::group( $id );
		$reason = self::protected_reason( $id, $group );
		if ( $reason ) {
			throw new \RuntimeException( $reason );
		}
		// Strong safety check against current metadata, independent of a stale index.
		foreach ( $group['files'] as $relative ) {
			self::path( $relative );
			$like   = '%' . $wpdb->esc_like( wp_basename( $relative ) ) . '%';
			$shared = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_smao_alternates') AND meta_value LIKE %s LIMIT 1", $id, $like ) );
			Database::check( $shared );
			if ( $shared ) {
				throw new \RuntimeException( __( 'Filename may be shared with another attachment. Manual review required.', 'smart-media-auditor-optimizer' ) );
			}
		}
		return $group;
	}
}
