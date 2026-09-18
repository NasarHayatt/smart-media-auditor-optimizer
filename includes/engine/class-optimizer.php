<?php
/**
 * Optimizer component for conservative media operations.
 *
 * @package SMAO
 */

namespace SMAO;

defined( 'ABSPATH' ) || exit;

/**
 * Optimizer workflow and safety policy.
 */
final class Optimizer {
	/**
	 * Detect installed image editors and supported output codecs.
	 *
	 * @return array
	 */
	public static function capabilities(): array {
		return array(
			'gd'      => extension_loaded( 'gd' ),
			'imagick' => class_exists( '\Imagick' ),
			'jpeg'    => wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ),
			'png'     => wp_image_editor_supports( array( 'mime_type' => 'image/png' ) ),
			'webp'    => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
			'avif'    => wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ),
		);
	}

	/**
	 * Encode and validate an image without silently changing its output format.
	 *
	 * @param string $source Source.
	 * @param string $target Target.
	 * @param string $mime Mime.
	 * @param array  $settings Settings.
	 * @param bool   $resize Resize.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	private static function encode( string $source, string $target, string $mime, array $settings, bool $resize = false ): void {
		$info = wp_getimagesize( $source );
		if ( ! $info || ( $info[0] * $info[1] > 40000000 ) || filesize( $source ) > 64 * MB_IN_BYTES ) {
			throw new \RuntimeException( __( 'Image exceeds the conservative 40 MP / 64 MiB processing limit.', 'smart-media-auditor-optimizer' ) );
		}
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) {
			throw new \RuntimeException( __( 'Unsupported image format.', 'smart-media-auditor-optimizer' ) ); }
		$lossless = 'lossless' === $settings['compression'];
		if ( $lossless && ( $resize || 'image/jpeg' === $mime ) ) {
			throw new \RuntimeException( __( 'Lossless JPEG recompression and lossless resizing are unsupported. Choose lossy explicitly, or keep the original.', 'smart-media-auditor-optimizer' ) ); }
		if ( class_exists( '\Imagick' ) ) {
			$image = new \Imagick( $source );
			try {
				if ( $image->getNumberImages() !== 1 || $image->getImageColorspace() === \Imagick::COLORSPACE_CMYK ) {
					throw new \RuntimeException( __( 'Animated/multi-frame and CMYK images require specialist processing.', 'smart-media-auditor-optimizer' ) ); }
				if ( $settings['strip_exif'] && ! in_array( $image->getImageOrientation(), array( \Imagick::ORIENTATION_UNDEFINED, \Imagick::ORIENTATION_TOPLEFT ), true ) ) {
					throw new \RuntimeException( __( 'Orientation metadata is required; normalize orientation before stripping EXIF.', 'smart-media-auditor-optimizer' ) ); }
				$signature = $image->getImageSignature();
				if ( $settings['strip_exif'] ) {
					$icc = $image->getImageProfiles( 'icc', true );
					$image->stripImage();
					if ( isset( $icc['icc'] ) ) {
						$image->profileImage( 'icc', $icc['icc'] ); }
				}
				if ( $resize && ( $info[0] > $settings['max_width'] || $info[1] > $settings['max_height'] ) ) {
					$image->thumbnailImage( $settings['max_width'], $settings['max_height'], true );
				}
				$format = array(
					'image/jpeg' => 'jpeg',
					'image/png'  => 'png',
					'image/webp' => 'webp',
					'image/avif' => 'avif',
				)[ $mime ];
				$image->setImageFormat( $format );
				$image->setImageCompressionQuality( $lossless ? 100 : $settings['quality'] );
				if ( $lossless ) {
					$image->setOption( 'webp:lossless', 'true' );
					$image->setOption( 'heic:lossless', 'true' );
				}
				if ( ! $image->writeImage( $target ) ) {
					throw new \RuntimeException( __( 'Image encoder failed.', 'smart-media-auditor-optimizer' ) ); }
				if ( $lossless ) {
					$verify = new \Imagick( $target );
					try {
						if ( $verify->getImageSignature() !== $signature ) {
							throw new \RuntimeException( __( 'Encoder did not preserve decoded pixels; lossless output rejected.', 'smart-media-auditor-optimizer' ) ); }
					} finally {
						$verify->clear(); }
				}
			} finally {
				$image->clear(); }
		} else {
			if ( $lossless || ! $settings['strip_exif'] ) {
				throw new \RuntimeException( __( 'This mode requires ImageMagick. GD requires explicit lossy compression and metadata-removal consent.', 'smart-media-auditor-optimizer' ) ); }
			// GD can silently flatten animation and lose color profiles. Only JPEG/PNG
			// without orientation/profile requirements are eligible on this fallback.
			if ( ! in_array( $info['mime'], array( 'image/jpeg', 'image/png' ), true ) ) {
				throw new \RuntimeException( __( 'WebP/AVIF input requires ImageMagick for frame inspection.', 'smart-media-auditor-optimizer' ) ); }
			if ( 'image/jpeg' === $info['mime'] ) {
				if ( ! function_exists( 'exif_read_data' ) ) {
					throw new \RuntimeException( __( 'EXIF inspection is unavailable.', 'smart-media-auditor-optimizer' ) ); }
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Valid JPEGs without EXIF emit a warning; absent EXIF is an allowed inspected state.
				$exif = @exif_read_data( $source );
				if ( ! empty( $exif['Orientation'] ) && 1 !== (int) $exif['Orientation'] ) {
					throw new \RuntimeException( __( 'Orientation requires normalization before GD optimization.', 'smart-media-auditor-optimizer' ) ); }
			}
			$probe = file_get_contents( $source );
			if ( str_contains( $probe, 'ICC_PROFILE' ) || str_contains( $probe, 'iCCP' ) || str_contains( $probe, 'acTL' ) ) {
				throw new \RuntimeException( __( 'Color-profiled or animated media requires ImageMagick.', 'smart-media-auditor-optimizer' ) ); }
			unset( $probe );
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) ) {
				throw new \RuntimeException( $editor->get_error_message() ); }
			$result = $editor->set_quality( $settings['quality'] );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() ); }
			if ( $resize && ( $info[0] > $settings['max_width'] || $info[1] > $settings['max_height'] ) ) {
				$result = $editor->resize( $settings['max_width'], $settings['max_height'], false );
				if ( is_wp_error( $result ) ) {
					throw new \RuntimeException( $result->get_error_message() ); }
			}
			$result = $editor->save( $target, $mime );
			if ( is_wp_error( $result ) || empty( $result['path'] ) || wp_normalize_path( $result['path'] ) !== wp_normalize_path( $target ) ) {
				throw new \RuntimeException( __( 'Image editor returned an unexpected output path.', 'smart-media-auditor-optimizer' ) ); }
		}
		$output = wp_getimagesize( $target );
		if ( ! $output || $output['mime'] !== $mime ) {
			throw new \RuntimeException( __( 'Encoded image failed format validation.', 'smart-media-auditor-optimizer' ) ); }
	}

	/**
	 * Optimize a media group with a durable rollback journal.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function optimize( int $id ): void {
		if ( get_post_meta( $id, '_smao_optimized', true ) ) {
			throw new \RuntimeException( __( 'Already optimized. Restore originals before re-optimizing.', 'smart-media-auditor-optimizer' ) ); }
		$settings = Settings::get();
		$group    = Media::assert_local( $id );
		$mime     = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) {
			throw new \RuntimeException( __( 'Only JPEG, PNG, WebP and AVIF images are eligible.', 'smart-media-auditor-optimizer' ) ); }
		$record          = Vault::prepare( $id, 'optimize', $group );
		$record['state'] = 'optimizing';
		Vault::save( $record );
		$before     = 0;
		$after      = 0;
		$alternates = array();
		try {
			foreach ( $group['files'] as $relative ) {
				$path    = Media::path( $relative );
				$before += filesize( $path );
				$info    = wp_getimagesize( $path );
				if ( ! $info ) {
					throw new \RuntimeException( __( 'Invalid image in attachment group.', 'smart-media-auditor-optimizer' ) ); }
				$ext  = pathinfo( $relative, PATHINFO_EXTENSION );
				$temp = dirname( $path ) . '/.smao-' . bin2hex( random_bytes( 12 ) ) . '.' . $ext;
				try {
					self::encode( $path, $temp, $info['mime'], $settings, $settings['resize'] && $relative === $group['main'] );
					clearstatcache( true, $temp );
					if ( filesize( $temp ) < filesize( $path ) ) {
						$entry = &$record['manifest']['files'][ $relative ];
						if ( hash_file( 'sha256', $path ) !== $entry['before'] ) {
							throw new \RuntimeException( __( 'Original changed during optimization.', 'smart-media-auditor-optimizer' ) ); }
						$entry['after'] = hash_file( 'sha256', $temp );
						Vault::save( $record );
						if ( ! rename( $temp, $path ) ) {
							throw new \RuntimeException( __( 'Could not atomically replace optimized image.', 'smart-media-auditor-optimizer' ) ); }
						unset( $entry );
					}
				} finally {
					if ( is_file( $temp ) ) {
						unlink( $temp ); }
				}
				clearstatcache( true, $path );
				$after += filesize( $path );
				if ( 'off' !== $settings['alternate'] && 'image/' . $settings['alternate'] !== $info['mime'] ) {
					$alternate = $relative . '.smao-' . substr( hash_file( 'sha256', $path ), 0, 12 ) . '.' . $settings['alternate'];
					$alt_path  = Media::path( $alternate, false );
					if ( file_exists( $alt_path ) ) {
						throw new \RuntimeException( __( 'Alternate filename already exists; refusing to overwrite.', 'smart-media-auditor-optimizer' ) ); }
					$temp_alt = dirname( $alt_path ) . '/.smao-' . bin2hex( random_bytes( 12 ) ) . '.' . $settings['alternate'];
					try {
						self::encode( $path, $temp_alt, 'image/' . $settings['alternate'], $settings );
						if ( filesize( $temp_alt ) < filesize( $path ) ) {
							$record['manifest']['generated'][ $alternate ] = hash_file( 'sha256', $temp_alt );
							Vault::save( $record );
							if ( ! rename( $temp_alt, $alt_path ) ) {
								throw new \RuntimeException( __( 'Could not publish alternate image.', 'smart-media-auditor-optimizer' ) ); }
							$d                       = wp_getimagesize( $alt_path );
							$alternates[ $relative ] = array(
								'file'   => $alternate,
								'width'  => $d[0],
								'height' => $d[1],
								'mime'   => $d['mime'],
							);
						}
					} finally {
						if ( is_file( $temp_alt ) ) {
							unlink( $temp_alt ); }
					}
				}
			}
			$meta             = $group['metadata'];
			$main_info        = wp_getimagesize( Media::path( $group['main'] ) );
			$meta['width']    = $main_info[0];
			$meta['height']   = $main_info[1];
			$meta['filesize'] = filesize( Media::path( $group['main'] ) );
			foreach ( $meta['sizes'] ?? array() as $name => $size ) {
				$dir                                = dirname( $group['main'] );
				$file                               = ( '.' === $dir ? '' : $dir . '/' ) . $size['file'];
				$meta['sizes'][ $name ]['filesize'] = filesize( Media::path( $file ) );
			}
			if ( $settings['strip_exif'] ) {
				$meta['image_meta'] = array(); }
			wp_update_attachment_metadata( $id, $meta );
			if ( get_post_meta( $id, '_wp_attachment_metadata', true ) !== $meta ) {
				throw new \RuntimeException( __( 'Could not verify updated image metadata; restoring originals.', 'smart-media-auditor-optimizer' ) );
			}
			update_post_meta( $id, '_smao_alternates', $alternates );
			update_post_meta(
				$id,
				'_smao_optimized',
				array(
					'before' => $before,
					'after'  => $after,
					'saved'  => max( 0, $before - $after ),
					'time'   => time(),
				)
			);
			$record['state'] = 'optimized';
			global $wpdb;
			$live_bytes = $after;
			foreach ( $alternates as $alternate ) {
				$live_bytes += filesize( Media::path( $alternate['file'] ) ); }
			Database::check(
				$wpdb->update(
					Database::table( 'media' ),
					array(
						'bytes'     => $live_bytes,
						'width'     => $meta['width'],
						'height'    => $meta['height'],
						'optimized' => 1,
						'saved'     => max( 0, $before - $after ),
					),
					array( 'attachment_id' => $id )
				)
			);
			Vault::save( $record );
			Database::log( 'optimize', $id, sprintf( 'Live image bytes before: %d; after: %d. Recovery copies and alternates consume additional disk space.', $before, $after ) );
		} catch ( \Throwable $e ) {
			// Durable manifest also supports recovery if the process terminates here.
			try {
				Vault::restore( $id );
			} catch ( \Throwable $recovery ) {
				throw new \RuntimeException( $e->getMessage() . ' Recovery pending: ' . $recovery->getMessage() ); }
			throw $e;
		}
	}

	/**
	 * Generate missing registered image sizes without removing existing files.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws \RuntimeException When validation, storage or processing cannot safely continue.
	 */
	public static function thumbnails( int $id ): void {
		Media::assert_local( $id );
		if ( Vault::record( $id ) ) {
			throw new \RuntimeException( __( 'Restore the active recovery record before regenerating thumbnails.', 'smart-media-auditor-optimizer' ) ); }
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$result = wp_update_image_subsizes( $id );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); }
		Database::log( 'thumbnails', $id, 'WordPress generated missing registered image sizes; existing files retained.' );
	}
}
