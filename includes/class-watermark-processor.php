<?php
/**
 * Shared watermark compositing helper.
 *
 * Provides the single image-processing entry point used by both the
 * synchronous upload pipeline (legacy paths) and the asynchronous
 * Watermark_Job worker. Loads the source image directly with Imagick
 * (preferred) or GD (fallback), composites the watermark PNG either at
 * full width (top-left) or bottom-right (30% photo width, 2% padding),
 * and writes the result to disk.
 *
 * `WP_Image_Editor` is intentionally NOT used: its internal image
 * resource is `protected` and cannot be reached from outside the class,
 * making it impossible to composite a watermark layer with it.
 *
 * @package Photolab
 * @since   2.0.0
 */

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photolab\Watermark_Processor — image compositing utility.
 *
 * @since 2.0.0
 */
class Watermark_Processor {

	/**
	 * Composite the active watermark onto a source image and save the result.
	 *
	 * Behaviour:
	 *  - Imagick available → uses `new \Imagick()` directly. Position read from
	 *    the `photolab_watermark_position` option (`fullwidth` or `bottom_right`).
	 *  - Imagick unavailable → falls back to GD (`imagecreatefromjpeg/png/webp/gif`).
	 *  - `$watermark_path` empty or missing → source is copied verbatim to
	 *    destination (no watermark applied).
	 *
	 * Returns `true` on success or a human-readable error message string on
	 * failure (callers compare with `=== true`). Errors are also logged.
	 *
	 * @since 2.0.0
	 *
	 * @param string $source_path    Absolute path to the original image.
	 * @param string $watermark_path Absolute path to the watermark PNG, or empty
	 *                               string to skip compositing.
	 * @param string $dest_path      Absolute path where the watermarked copy
	 *                               must be written.
	 * @param array  $context        Logger context (typically `['source' => '...']`).
	 * @return bool|string True on success; error message on failure.
	 */
	public static function apply( string $source_path, string $watermark_path, string $dest_path, array $context = array() ): bool|string {
		// Imagick path.
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$imagick = new \Imagick( $source_path );

				// Resize to max 1200px wide — the watermarked image is only
				// displayed in a ~1200px box on the frontend. The original
				// full-resolution file is served as the downloadable product.
				$photo_w = $imagick->getImageWidth();
				if ( $photo_w > 1200 ) {
					$imagick->resizeImage( 1200, 0, \Imagick::FILTER_CATROM, 1 );
				}
				$imagick->setImageResolution( 75, 75 );

				if ( '' !== $watermark_path ) {
					$watermark = new \Imagick( $watermark_path );
					$position  = self::get_watermark_position();
					$photo_w   = $imagick->getImageWidth();

					if ( 'fullwidth' === $position ) {
						// Stretch to full photo width, height proportional, composite at top-left.
						$watermark->resizeImage( $photo_w, 0, \Imagick::FILTER_CATROM, 1 );
						$imagick->compositeImage( $watermark, \Imagick::COMPOSITE_OVER, 0, 0 );
					} else {
						// Bottom-right: 30% photo width, 2% padding.
						$wm_target = (int) ( $photo_w * 0.30 );
						$watermark->resizeImage( $wm_target, 0, \Imagick::FILTER_CATROM, 1 );
						$padding = (int) ( $photo_w * 0.02 );
						$wm_w    = $watermark->getImageWidth();
						$wm_h    = $watermark->getImageHeight();
						$x       = $imagick->getImageWidth() - $wm_w - $padding;
						$y       = $imagick->getImageHeight() - $wm_h - $padding;
						$imagick->compositeImage( $watermark, \Imagick::COMPOSITE_OVER, $x, $y );
					}

					$watermark->destroy();
				}

				// preserve original format (JPEG→lossy, PNG/GIF→lossless).
				$source_format = strtolower( $imagick->getImageFormat() );
				$is_lossy      = in_array( $source_format, array( 'jpeg', 'jpg', 'webp' ), true );

				$imagick->setImageFormat( $source_format );
				if ( $is_lossy ) {
					$imagick->setImageCompression( \Imagick::COMPRESSION_JPEG );
					$imagick->setImageCompressionQuality( 75 );
					$imagick->setInterlaceScheme( \Imagick::INTERLACE_NO );
				}
				$imagick->stripImage();
				$imagick->writeImage( $dest_path );
				$imagick->destroy();

				Logger::debug( 'Watermark_Processor::apply — Imagick OK.', $context );
				return true;

			} catch ( \ImagickException $e ) {
				Logger::error( sprintf( 'Watermark_Processor::apply — Imagick errore: %s', $e->getMessage() ), $context );
				return $e->getMessage();
			}
		}

		// GD fallback.
		if ( extension_loaded( 'gd' ) ) {
			try {
				$image_info = getimagesize( $source_path );
				if ( false === $image_info ) {
					return 'GD: impossibile leggere dimensioni immagine.';
				}

				$mime = $image_info['mime'];

				// Preventive memory guard — GD decodes the whole image into an
				// uncompressed RGBA bitmap (width × height × 4 bytes), plus PHP/GD
				// overhead. Estimate before allocating rather than after: by the
				// time imagecreatefrom*() itself has returned, the fatal OOM (if
				// any) already happened inside that call. A 36MP studio JPEG
				// (~7360×4912, common Nikon D810 output) needs ~217MB just to
				// decode — already tight against the 256MB minimum this plugin
				// declares. 1.6x is the commonly cited GD overhead factor for
				// true-color images with alpha.
				$estimated_bytes = (int) $image_info[0] * (int) $image_info[1] * 4 * 1.6;
				// wp_convert_hr_to_bytes() — WP core helper, handles '256M'/'1G'/
				// '-1' (unlimited) the same way PHP's own ini parser does.
				$memory_limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

				if ( $memory_limit > 0 ) {
					$available = $memory_limit - memory_get_usage( true );
					if ( $estimated_bytes > $available ) {
						return sprintf(
							'GD: immagine troppo grande per la memoria disponibile (~%s MB stimati, ~%s MB liberi). Serve Imagick per foto di questa risoluzione.',
							number_format( $estimated_bytes / 1048576, 1 ),
							number_format( $available / 1048576, 1 )
						);
					}
				}

				$base = match ( $mime ) {
					'image/jpeg' => imagecreatefromjpeg( $source_path ),
					'image/png'  => imagecreatefrompng( $source_path ),
					'image/webp' => imagecreatefromwebp( $source_path ),
					'image/gif'  => imagecreatefromgif( $source_path ),
					default      => false,
				};

				if ( false === $base ) {
					return 'GD: impossibile caricare immagine sorgente.';
				}

				// Resize to max 1200px wide before watermark composition (same
				// rationale as the Imagick path above).
				$photo_w   = imagesx( $base );
				$photo_h   = imagesy( $base );
				if ( $photo_w > 1200 ) {
					$new_w = 1200;
					$new_h = (int) ( $photo_h * ( 1200 / $photo_w ) );
					$resized = imagecreatetruecolor( $new_w, $new_h );
					if ( false !== $resized ) {
						imagecopyresampled( $resized, $base, 0, 0, 0, 0, $new_w, $new_h, $photo_w, $photo_h );
						imagedestroy( $base );
						$base    = $resized;
						$photo_w = $new_w;
						$photo_h = $new_h;
					}
				}

				if ( '' !== $watermark_path ) {
					$wm = imagecreatefrompng( $watermark_path );

					if ( false !== $wm ) {
						$wm_orig_w = imagesx( $wm );
						$wm_orig_h = imagesy( $wm );
						$position  = self::get_watermark_position();

						if ( 'fullwidth' === $position ) {
							// Stretch to full photo width, height proportional, composite at top-left.
							$wm_target_w = $photo_w;
							$wm_target_h = (int) ( $wm_orig_h * ( $wm_target_w / $wm_orig_w ) );
							$wm_scaled   = imagecreatetruecolor( $wm_target_w, $wm_target_h );
							imagealphablending( $wm_scaled, false );
							imagesavealpha( $wm_scaled, true );
							imagecopyresampled( $wm_scaled, $wm, 0, 0, 0, 0, $wm_target_w, $wm_target_h, $wm_orig_w, $wm_orig_h );
							imagedestroy( $wm );
							imagecopy( $base, $wm_scaled, 0, 0, 0, 0, $wm_target_w, $wm_target_h );
							imagedestroy( $wm_scaled );
						} else {
							// Bottom-right: 30% photo width, 2% padding.
							$wm_target_w = (int) ( $photo_w * 0.30 );
							$wm_target_h = (int) ( $wm_orig_h * ( $wm_target_w / $wm_orig_w ) );
							$wm_scaled   = imagecreatetruecolor( $wm_target_w, $wm_target_h );
							imagealphablending( $wm_scaled, false );
							imagesavealpha( $wm_scaled, true );
							imagecopyresampled( $wm_scaled, $wm, 0, 0, 0, 0, $wm_target_w, $wm_target_h, $wm_orig_w, $wm_orig_h );
							imagedestroy( $wm );
							$padding = (int) ( $photo_w * 0.02 );
							$dst_x   = $photo_w - $wm_target_w - $padding;
							$dst_y   = $photo_h - $wm_target_h - $padding;
							imagecopy( $base, $wm_scaled, $dst_x, $dst_y, 0, 0, $wm_target_w, $wm_target_h );
							imagedestroy( $wm_scaled );
						}
					}
				}

				// Dynamic output quality — larger photos get a slightly lower
				// JPEG/WebP quality to keep the watermarked file (and the disk/
				// bandwidth it costs to serve) proportional to megapixel count.
				// Doesn't reduce peak decode memory (that's the guard above),
				// only output size.
				$pixel_count = (int) $image_info[0] * (int) $image_info[1];
				$quality     = match ( true ) {
					$pixel_count > 6000000 => 85,
					$pixel_count > 2000000 => 80,
					default                 => 75,
				};

				$result = match ( $mime ) {
					'image/jpeg' => imagejpeg( $base, $dest_path, $quality ),
					'image/png'  => imagepng( $base, $dest_path ),
					'image/webp' => imagewebp( $base, $dest_path, $quality ),
					'image/gif'  => imagegif( $base, $dest_path ),
					default      => false,
				};

				imagedestroy( $base );

				if ( false === $result ) {
					return 'GD: impossibile salvare immagine watermarked.';
				}

				Logger::debug( 'Watermark_Processor::apply — GD OK.', $context );
				return true;

			} catch ( \Throwable $e ) {
				Logger::error( sprintf( 'Watermark_Processor::apply — GD errore: %s', $e->getMessage() ), $context );
				return $e->getMessage();
			}
		}

		return 'Nessun engine immagini disponibile (Imagick o GD richiesti).';
	}


	/**
	 * Read the watermark position with per-request static cache.
	 *
	 * Called three times per photo (Imagick, GD, CLI) but only one path
	 * fires. Static cache eliminates repeated SQL queries.
	 *
	 * @since 2.1.7
	 *
	 * @return string Either 'fullwidth' or 'bottom_right'.
	 */
	private static function get_watermark_position(): string {
		static $position = null;
		if ( null === $position ) {
			$position = (string) get_option( 'photolab_watermark_position', 'bottom_right' );
		}
		return $position;
	}
}
