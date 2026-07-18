<?php
/**
 * Asynchronous watermark batch worker.
 *
 * `Watermark_Job::process_batch()` is the Action Scheduler hook target for
 * `photolab_watermark_batch`. It walks every photo id in the batch, applies
 * the watermark using the shared `Watermark_Processor`, inserts the resulting
 * file into the Media Library, attaches it as the WC product's featured
 * image, and advances the photo FSM `uploaded → watermarking → watermarked`.
 *
 * Failure modes:
 *  - Album row missing: log and bail without retry (the batch is permanently
 *    invalid; AS retries would not help).
 *  - Photo missing or already deleted: skip and continue.
 *  - Photo not in `uploaded` state: skip (idempotent — another worker has it).
 *  - Per-photo exception: best-effort CAS to `failed`, rethrow so AS retries
 *    the entire batch (with exponential backoff).
 *  - Retry budget exhausted (>5 attempts): email the site admin once, clear
 *    the counter, and exit cleanly so AS stops retrying.
 *
 * @package Photolab
 * @since   2.0.0
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photolab\Watermark_Job — async watermark batch processor.
 *
 * @since 2.0.0
 */
class Watermark_Job {

	/**
	 * Default retry ceiling for a single batch.
	 *
	 * Filterable via `photolab_watermark_max_retries`.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_RETRIES = 5;

	/**
	 * Process a watermark batch for a single album.
	 *
	 * Called by Action Scheduler when the `photolab_watermark_batch` hook
	 * fires. Order of operations per photo:
	 *
	 *   1. Confirm album row still exists (otherwise abort batch — no retry).
	 *   2. Load photo row, skip if missing or `deleted`.
	 *   3. CAS `uploaded → watermarking`. Skip on conflict.
	 *   4. Composite watermark via Watermark_Processor::apply().
	 *   5. wp_insert_attachment() — Media Library row, no metadata generation.
	 *   6. set_post_thumbnail() on the WC product.
	 *   7. CAS `watermarking → watermarked`, write watermark_url.
	 *
	 * Any exception in steps 3–7 attempts CAS to `failed` and rethrows so
	 * Action Scheduler reschedules the batch. After 5 failed attempts the
	 * worker mails the admin and stops retrying.
	 *
	 * Once every photo in the album has reached a terminal state
	 * (`watermarked` or `failed`), the album CAS-transitions
	 * `watermarking → idle`.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $album_id  Album row id (`wp_Photolab_albums.id`).
	 * @param array $photo_ids List of photo row ids to process in this batch.
	 * @return void
	 *
	 * @throws \Throwable Rethrown to signal AS to retry the batch.
	 */
	public static function process_batch( int $album_id, array $photo_ids ): void {
		Logger::set_context( 'album_id', (int) $album_id );
		Logger::set_context( 'is_async_job', true );

		try {
			self::process_batch_inner( $album_id, $photo_ids );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Inner watermark batch processor. Wrapped by {@see self::process_batch()}
	 * so the request-scoped logger context is always cleared on return —
	 * including the rethrow path that lets Action Scheduler retry the batch.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $album_id  Album row id.
	 * @param array $photo_ids Photo ids in the batch.
	 * @return void
	 *
	 * @throws \RuntimeException When a photo in the batch fails.
	 */
	private static function process_batch_inner( int $album_id, array $photo_ids ): void {
		global $wpdb;

		$context = array(
			'source'   => 'photolab-watermark-job',
			'album_id' => $album_id,
		);

		$album_id  = (int) $album_id;
		$photo_ids = array_values( array_filter( array_map( 'intval', $photo_ids ), static fn( $v ) => $v > 0 ) );

		if ( $album_id <= 0 || empty( $photo_ids ) ) {
			Logger::warning(
				sprintf( 'Watermark_Job::process_batch — payload non valido album_id=%d count=%d.', $album_id, count( $photo_ids ) ),
				$context
			);
			return;
		}

		$attempt_key = "photolab_watermark_attempt_{$album_id}";
		$attempt     = (int) get_option( $attempt_key, 0 ) + 1;
		update_option( $attempt_key, $attempt, false );

		/* @var int $max_retries */
		$max_retries = (int) apply_filters( 'photolab_watermark_max_retries', self::DEFAULT_MAX_RETRIES );
		if ( $max_retries < 1 ) {
			$max_retries = self::DEFAULT_MAX_RETRIES;
		}

		Logger::info(
			sprintf(
				'Watermark_Job::process_batch — start album=%d photos=%d attempt=%d/%d.',
				$album_id,
				count( $photo_ids ),
				$attempt,
				$max_retries
			),
			$context
		);

		if ( $attempt > $max_retries ) {
			Logger::error(
				sprintf(
					'Watermark_Job::process_batch — retry esaurite album=%d attempt=%d. Notifica admin.',
					$album_id,
					$attempt
				),
				$context
			);

			self::notify_admin_failure( $album_id, $photo_ids, $attempt );
			delete_option( $attempt_key );

			// Swallow — let AS mark this run successful so it stops retrying.
			return;
		}

		$fsm   = new State_Machine();
		$album = $fsm->get_album( $album_id );

		if ( null === $album ) {
			Logger::warning(
				sprintf( 'Watermark_Job::process_batch — album=%d non trovato, batch abort senza retry.', $album_id ),
				$context
			);
			delete_option( $attempt_key );
			return;
		}

		$watermark_path  = (string) ( $album->watermark_snapshot ?? '' );
		$album_name      = (string) $album->album_name;
		$safe_album      = sanitize_file_name( $album_name );
		$upload_dir      = wp_upload_dir();
		$base            = trailingslashit( $upload_dir['basedir'] ) . 'Photolab';
		$base_url        = trailingslashit( $upload_dir['baseurl'] ) . 'Photolab';
		$watermarked_dir = "{$base}/watermarked/{$safe_album}";

		wp_mkdir_p( $watermarked_dir );

		$photos_table   = $wpdb->prefix . 'Photolab_photos';
		$last_exception = null;

		// register shutdown handler that CAS watermarking→failed on
		// fatal errors (E_ERROR from memory_limit). try/catch cannot catch PHP
		// fatals; this is the only way to mark the current photo as failed and
		// avoid permanent stuck state. Raw SQL because State_Machine may not
		// survive memory exhaustion.
		$current_photo_id = 0;
		register_shutdown_function(
			function () use ( &$current_photo_id ) {
				$error = error_get_last();
				if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_USER_ERROR ), true ) || $current_photo_id <= 0 ) {
					return;
				}
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					$wpdb->prefix . 'Photolab_photos',
					array( 'photo_status' => 'failed' ),
					array(
						'id'           => $current_photo_id,
						'photo_status' => 'watermarking',
					),
					array( '%s' ),
					array( '%d', '%s' )
				);
			}
		);

		foreach ( $photo_ids as $photo_id ) {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 120 );
			}

			$row_context = $context + array( 'photo_id' => $photo_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$photo = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, photo_name, photo_status, file_url, wc_product_id, attachment_id
					 FROM `{$photos_table}`
					 WHERE id = %d
					   AND photo_status <> %s",
					$photo_id,
					State_Machine::PHOTO_DELETED
				)
			);

			if ( null === $photo ) {
				Logger::debug(
					sprintf( 'Watermark_Job::process_batch — photo=%d assente o deleted, skip.', $photo_id ),
					$row_context
				);
				continue;
			}

			// CAS uploaded → watermarking. Skip if already moved on.
			$claimed = $fsm->transition_photo(
				$photo_id,
				State_Machine::PHOTO_UPLOADED,
				State_Machine::PHOTO_WATERMARKING
			);

			if ( ! $claimed ) {
				Logger::debug(
					sprintf( 'Watermark_Job::process_batch — photo=%d già preso (status=%s), skip.', $photo_id, $photo->photo_status ),
					$row_context
				);
				continue;
			}

			// Track for shutdown handler — set AFTER CAS so we only try to
			// recover photos actually in `watermarking` state.
			$current_photo_id = $photo_id;

			try {
				$photo_name = (string) $photo->photo_name;
				$file_url   = (string) $photo->file_url;
				$product_id = (int) $photo->wc_product_id;

				// Resolve the original file's absolute path from its URL.
				$source_path = self::url_to_path( $file_url );
				if ( '' === $source_path || ! file_exists( $source_path ) ) {
					throw new \RuntimeException( sprintf( 'Original file non trovato: %s', $file_url ) );
				}

				$orig_basename = wp_basename( $source_path );
				$wm_filename   = wp_unique_filename( $watermarked_dir, $orig_basename );
				$wm_full_path  = trailingslashit( $watermarked_dir ) . $wm_filename;

				$wm_path_for_apply = ( '' !== $watermark_path && file_exists( $watermark_path ) ) ? $watermark_path : '';

				$apply_result = Watermark_Processor::apply( $source_path, $wm_path_for_apply, $wm_full_path, $row_context );
				if ( true !== $apply_result ) {
					throw new \RuntimeException( sprintf( 'Watermark_Processor failure: %s', (string) $apply_result ) );
				}

				$wm_url = trailingslashit( $base_url ) . 'watermarked/' . $safe_album . '/' . $wm_filename;

				// Insert into Media Library (no metadata generation — deliberate, ~150ms saved).
				$mime          = wp_check_filetype( $wm_full_path );
				$attachment_id = wp_insert_attachment(
					array(
						'post_title'     => pathinfo( $wm_filename, PATHINFO_FILENAME ),
						'post_mime_type' => $mime['type'] ?? 'image/jpeg',
						'post_status'    => 'inherit',
					),
					$wm_full_path
				);

				if ( is_wp_error( $attachment_id ) || 0 === (int) $attachment_id ) {
					$msg = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'wp_insert_attachment ha restituito 0.';
					throw new \RuntimeException( $msg );
				}

				if ( $product_id > 0 ) {
					set_post_thumbnail( $product_id, (int) $attachment_id );
				}

				// Generate only `woocommerce_thumbnail`. — the single intermediate
				// size WooCommerce actually needs for frontend shop/category
				// grids. The full-resolution watermarked file serves all other
				// contexts (single product page zoom, lightbox, downloads).
				// Skipping the other 8-10 sizes saves ~50s per photo.
				self::generate_thumbnail_meta( (int) $attachment_id, $wm_full_path );

				// clear Imagick global pixel cache without reloading the file.
				if ( class_exists( '\Imagick' ) && method_exists( '\Imagick', 'clearResources' ) ) {
					try {
						\Imagick::clearResources();
					} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// No action needed.
					}
				}
				gc_collect_cycles();

				// CAS watermarking → watermarked.
				$finalised = $fsm->transition_photo(
					$photo_id,
					State_Machine::PHOTO_WATERMARKING,
					State_Machine::PHOTO_WATERMARKED,
					array(
						'watermark_url' => $wm_url,
						'attachment_id' => (int) $attachment_id,
					)
				);

				if ( ! $finalised ) {
					throw new \RuntimeException( 'CAS watermarking→watermarked fallita.' );
				}

				// Successful terminal — drop per-photo retry counter and any
				// "admin notified" guard option set by Cleanup_Scheduler.
				delete_option( "photolab_watermark_retry_{$photo_id}" );
				delete_option( "photolab_watermark_notified_{$photo_id}" );

				Logger::info(
					sprintf(
						'Watermark_Job::process_batch — photo=%d OK product=%d attachment=%d.',
						$photo_id,
						$product_id,
						(int) $attachment_id
					),
					$row_context
				);

			} catch ( \Throwable $e ) {
				Logger::error(
					sprintf(
						'Watermark_Job::process_batch — photo=%d eccezione: %s | trace: %s',
						$photo_id,
						$e->getMessage(),
						$e->getTraceAsString()
					),
					$row_context
				);

				// Per-photo retry counter consumed by Cleanup_Scheduler (FASE 6).
				self::bump_photo_retry_counter( $photo_id, $row_context );

				// Best-effort failure mark; ignore CAS result (race-tolerant).
				$fsm->transition_photo(
					$photo_id,
					State_Machine::PHOTO_WATERMARKING,
					State_Machine::PHOTO_FAILED
				);

				// Don't rethrow here — a `throw` inside this loop iteration
				// would exit process_batch() immediately, skipping both the
				// remaining photo_ids in this batch and maybe_finalise_album()
				// below. That left albums stuck in 'watermarking' forever
				// whenever the last photo of a batch failed (confirmed live:
				// album stayed 'watermarking' with a permanently-failed AS
				// action and no pending retry). Collect it and rethrow once,
				// after every photo has had a chance to process and the album
				// has had a chance to settle.
				$last_exception = $e;
			}
		}

		// All photos in the batch terminal (watermarked or failed)? Try to
		// settle the album — runs regardless of whether any photo failed.
		self::maybe_finalise_album( $album_id, $context );

		if ( null !== $last_exception ) {
			// Rethrow so Action Scheduler retries the batch (it will skip
			// photos already terminal via the uploaded→watermarking CAS guard
			// above and only touch what's still eligible).
			throw $last_exception;
		}

		// Successful pass — clear retry counter.
		delete_option( $attempt_key );

		Logger::info(
			sprintf( 'Watermark_Job::process_batch — completato album=%d.', $album_id ),
			$context
		);
	}

	/**
	 * Best-effort album settlement.
	 *
	 * When every photo of the album is in a terminal state (`watermarked` or
	 * `failed`), CAS the album from `watermarking → idle`. A `false` return
	 * is non-fatal: another batch may still be in flight, or a recovery cron
	 * may have moved the album to `aborted`.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $album_id Album id.
	 * @param array $context  Logger context.
	 * @return void
	 */
	private static function maybe_finalise_album( int $album_id, array $context ): void {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$photos_table}`
				 WHERE album_id = %d
				   AND photo_status IN (%s, %s)",
				$album_id,
				State_Machine::PHOTO_UPLOADED,
				State_Machine::PHOTO_WATERMARKING
			)
		);

		if ( $pending > 0 ) {
			Logger::debug(
				sprintf( 'Watermark_Job::maybe_finalise_album — album=%d, %d foto ancora pending, skip.', $album_id, $pending ),
				$context
			);
			return;
		}

		$fsm           = new State_Machine();
		$album_row     = $fsm->get_album( $album_id );
		$snapshot_path = $album_row ? (string) ( $album_row->watermark_snapshot ?? '' ) : '';

		$ok = $fsm->transition_album(
			$album_id,
			State_Machine::ALBUM_WATERMARKING,
			State_Machine::ALBUM_IDLE,
			array( 'watermark_snapshot' => null )
		);

		if ( $ok ) {
			Logger::info(
				sprintf( 'Watermark_Job::maybe_finalise_album — album=%d watermarking→idle.', $album_id ),
				$context
			);
			self::cleanup_snapshot_file( $snapshot_path, $context );
			delete_option( "photolab_album_{$album_id}_price" );
		}
	}

	/**
	 * Remove the watermark snapshot file when it sits inside the Photolab
	 * `assets/` directory. Mirrors `Upload_Controller::cleanup_snapshot_file`.
	 *
	 * @since 2.0.0
	 *
	 * @param string $snapshot_path Absolute path to snapshot file.
	 * @param array  $context       Logger context.
	 * @return void
	 */
	private static function cleanup_snapshot_file( string $snapshot_path, array $context ): void {
		if ( '' === $snapshot_path ) {
			return;
		}

		$upload      = wp_upload_dir();
		$assets_dir  = trailingslashit( $upload['basedir'] ) . 'Photolab/assets/';
		$real_snap   = realpath( $snapshot_path );
		$real_assets = realpath( $assets_dir );

		if (
			false !== $real_snap &&
			false !== $real_assets &&
			str_starts_with( $real_snap, $real_assets ) &&
			file_exists( $snapshot_path )
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $snapshot_path );
			Logger::info(
				sprintf( 'Watermark_Job::cleanup_snapshot_file — eliminato %s', $snapshot_path ),
				$context
			);
		}
	}

	/**
	 * Increment the per-photo retry counter consumed by the daily cleanup job.
	 *
	 * Stored as the WP option `photolab_watermark_retry_{$photo_id}` so it
	 * survives across batches and AS retries. `Cleanup_Scheduler::run_daily_cleanup()`
	 * uses this value to decide whether a `failed` photo can be re-triggered
	 * (`< 5`) or has exhausted its retry budget (`>= 5`).
	 *
	 * Counter is also written to `retry_count` on the photo row (column already
	 * whitelisted by `State_Machine`) for at-a-glance visibility.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $photo_id Photo row id.
	 * @param array $context  Logger context.
	 * @return void
	 */
	private static function bump_photo_retry_counter( int $photo_id, array $context ): void {
		if ( $photo_id <= 0 ) {
			return;
		}

		$option_key = "photolab_watermark_retry_{$photo_id}";
		$current    = (int) get_option( $option_key, 0 );
		$next       = $current + 1;

		update_option( $option_key, $next, false );

		// Mirror to the row so admin queries see the value without inspecting options.
		global $wpdb;
		$photos_table = $wpdb->prefix . 'Photolab_photos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$photos_table,
			array( 'retry_count' => $next ),
			array( 'id' => $photo_id ),
			array( '%d' ),
			array( '%d' )
		);

		Logger::debug(
			sprintf( 'Watermark_Job::bump_photo_retry_counter — photo=%d retry_count=%d.', $photo_id, $next ),
			$context
		);
	}

	/**
	 * Notify the site admin when a watermark batch has exhausted its retries.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $album_id  Album id.
	 * @param array $photo_ids Photo ids in the failed batch.
	 * @param int   $attempt   Last attempt number reached.
	 * @return void
	 */
	private static function notify_admin_failure( int $album_id, array $photo_ids, int $attempt ): void {
		$to      = (string) get_option( 'admin_email' );
		$subject = sprintf( 'Photolab Watermark Failed — Album ID %d', $album_id );

		$body  = sprintf( "Album ID: %d\n", $album_id );
		$body .= sprintf( "Tentativi: %d\n", $attempt );
		$body .= sprintf( "Photo IDs (%d): %s\n", count( $photo_ids ), implode( ', ', $photo_ids ) );
		$body .= "\nIl job di watermark è stato disattivato. Verifica i log con source 'photolab-watermark-job'.\n";

		if ( '' !== $to ) {
			wp_mail( $to, $subject, $body );
		}
	}

	/**
	 * Convert an absolute upload URL back to its filesystem path.
	 *
	 * Used to recover the original file location for photos that were stored
	 * with `file_url` only.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url Absolute URL inside the WordPress uploads tree.
	 * @return string Absolute filesystem path, or empty string on failure.
	 */
	private static function url_to_path( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$upload  = wp_upload_dir();
		$baseurl = (string) ( $upload['baseurl'] ?? '' );
		$basedir = (string) ( $upload['basedir'] ?? '' );

		if ( '' === $baseurl || '' === $basedir ) {
			return '';
		}

		if ( ! str_starts_with( $url, $baseurl ) ) {
			return '';
		}

		return $basedir . substr( $url, strlen( $baseurl ) );
	}

	/**
	 * Generate attachment metadata with only the woocommerce_thumbnail size.
	 *
	 * Replaces the expensive wp_generate_attachment_metadata() call (which
	 * generates 8-10 intermediate sizes per photo, ~50s of CPU) with targeted
	 * woocommerce_thumbnail generation (~5s). Product thumbnails render
	 * correctly on frontend shop/category pages. Full-size and other Woo
	 * image sizes fall back to the original watermarked file.
	 *
	 * @since 2.2.0
	 *
	 * @param int    $attachment_id Media Library attachment ID.
	 * @param string $file_path     Absolute path to the watermarked file.
	 * @return array Empty array on failure, metadata array with 'sizes' on success.
	 */
	public static function generate_thumbnail_meta( int $attachment_id, string $file_path ): array {
		if ( ! file_exists( $file_path ) ) {
			return array();
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return array();
		}

		$size   = wc_get_image_size( 'woocommerce_thumbnail' );
		$width  = (int) ( $size['width'] ?? 300 );
		$height = (int) ( $size['height'] ?? 300 );
		$crop   = ! empty( $size['crop'] );

		$result = $editor->resize( $width, $height, $crop );
		if ( is_wp_error( $result ) || false === $result ) {
			return array();
		}

		$saved = $editor->save();
		if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
			return array();
		}

		$meta = array(
			'width'  => (int) ( $saved['width'] ?? $width ),
			'height' => (int) ( $saved['height'] ?? $height ),
			'file'   => wp_basename( $file_path ),
			'sizes'  => array(
				'woocommerce_thumbnail' => array(
					'file'      => wp_basename( $saved['file'] ),
					'width'     => (int) ( $saved['width'] ?? $width ),
					'height'    => (int) ( $saved['height'] ?? $height ),
					'mime-type' => $saved['mime'] ?? 'image/jpeg',
				),
			),
		);

		wp_update_attachment_metadata( $attachment_id, $meta );

		return $meta;
	}
}

// Hook registration — process_batch is the AS callback for photolab_watermark_batch.
add_action( 'photolab_watermark_batch', array( Watermark_Job::class, 'process_batch' ), 10, 2 );


// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared