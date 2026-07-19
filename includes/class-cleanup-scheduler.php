<?php
/**
 * Scheduled cleanup of expired photos via Action Scheduler.
 *
 * @package Photolab
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and runs the daily cleanup job that unpublishes expired WC products
 * and deletes their associated watermarked files.
 */
class Cleanup_Scheduler {

	/**
	 * Action Scheduler hook name used for the recurring cleanup job.
	 *
	 * @var string
	 */
	const HOOK = 'photolab_cleanup_expired_photos';

	/**
	 * Action Scheduler hook for the daily failsafe sweep introduced in FASE 6.
	 *
	 * Distinct from {@see self::HOOK} (expired-photos retention): different
	 * concern, different cadence, different log channel.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	const DAILY_HOOK = 'photolab_daily_cleanup';

	/**
	 * Action Scheduler hook fired by `Watermark_Job` when retries run out.
	 *
	 * Forwarded to {@see self::cleanup_expired_photos()}? — no. Defined here
	 * only for symmetry with `DAILY_HOOK`/{@see self::HOOK} naming.
	 *
	 * @var string
	 */
	const WATERMARK_HOOK = 'photolab_watermark_batch';

	/**
	 * Number of expired photos processed per job execution.
	 *
	 * Keeps individual AS tasks short under high-volume scenarios.
	 *
	 * @var int
	 */

	/**
	 * Per-photo watermark retry budget consumed by the daily sweep.
	 *
	 * Mirrors the `photolab_watermark_max_retries` filter applied by
	 * `Watermark_Job`. Centralised here so the daily cleanup honours the same
	 * ceiling.
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	const PHOTO_RETRY_LIMIT = 5;

	/**
	 * Stuck-state threshold in seconds (1 hour) for photos in `watermarking`
	 * and Action Scheduler watermark batch jobs in `in-progress`.
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	const STUCK_THRESHOLD_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Wire up the AS hook callbacks (retention sweep + daily failsafe).
	 *
	 * Called once during plugin bootstrap (plugins_loaded).
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'cleanup_expired_photos' ) );
		add_action( self::DAILY_HOOK, array( $this, 'run_daily_cleanup' ) );
	}

	/**
	 * Ensure a single-run action is scheduled for the given hook.
	 *
	 * Idempotent: skips if the action is already queued. Converts the old
	 * recurring-action pattern to a self-rescheduling single action so the
	 * callback always runs before the follow-up is created.
	 *
	 * @since 2.2.6
	 *
	 * @param string   $hook     Action Scheduler hook name.
	 * @param int      $interval Seconds between runs (used by schedule_next).
	 * @param int|null $first_run Optional UNIX timestamp for the first run.
	 *
	 * @return void
	 */
	public static function ensure_first_action( string $hook, int $interval, ?int $first_run = null ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( $hook, array(), 'photolab' ) ) {
			return;
		}

		$when      = $first_run ?? time();
		$action_id = as_schedule_single_action( $when, $hook, array(), 'photolab' );

		if ( ! $action_id ) {
			Logger::error(
				sprintf( 'Cleanup_Scheduler::ensure_first_action(%s) — as_schedule_single_action ha restituito 0.', $hook ),
				array( 'source' => 'photolab-cleanup' )
			);
			return;
		}

		Logger::info(
			sprintf( 'Cleanup_Scheduler::ensure_first_action(%s) — schedulato (action_id=%d).', $hook, $action_id ),
			array( 'source' => 'photolab-cleanup' )
		);
	}

	/**
	 * Schedule the next run of a self-rescheduling action.
	 *
	 * Called from the finally block of each job callback so the next run
	 * is only created after the current one has completed.
	 *
	 * @since 2.2.6
	 *
	 * @param string $hook     Action Scheduler hook name.
	 * @param int    $interval Seconds from now for the next run.
	 *
	 * @return void
	 */
	public static function schedule_next( string $hook, int $interval ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$action_id = as_schedule_single_action( time() + $interval, $hook, array(), 'photolab' );

		if ( ! $action_id ) {
			Logger::error(
				sprintf( 'Cleanup_Scheduler::schedule_next(%s) — as_schedule_single_action ha restituito 0.', $hook ),
				array( 'source' => 'photolab-cleanup' )
			);
		}
	}

	/**
	 * Remove all scheduled instances of the cleanup job.
	 *
	 * Called on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, array(), 'photolab' );

		Logger::info(
			'Cleanup_Scheduler::unschedule() — job rimosso.',
			array( 'source' => 'photolab-cleanup' )
		);
	}

	/**
	 * Ensure the daily failsafe sweep is scheduled (single action).
	 *
	 * Idempotent: skips if already queued. First run defaults to tomorrow
	 * at 03:00 site time; falls back to +1 hour on strtotime failure.
	 *
	 * @since 2.2.6
	 *
	 * @return void
	 */
	public static function ensure_first_daily_action(): void {
		$first_run = (int) strtotime( 'tomorrow 03:00:00' );
		if ( $first_run <= 0 ) {
			$first_run = time() + HOUR_IN_SECONDS;
		}
		self::ensure_first_action( self::DAILY_HOOK, DAY_IN_SECONDS, $first_run );
	}

	/**
	 * Cancel all scheduled instances of the daily sweep.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function unschedule_daily(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::DAILY_HOOK, array(), 'photolab' );

		Logger::info(
			'Cleanup_Scheduler::unschedule_daily() — daily cleanup rimosso.',
			array( 'source' => 'photolab-cleanup' )
		);
	}

	/**
	 * Process a batch of expired photos.
	 *
	 * For each expired photo (and when all photos in an album are expired,
	 * for the album itself):
	 *  - Deletes the WC product (force delete, bypasses trash).
	 *  - Deletes the Media Library attachment (featured image).
	 *  - Deletes the watermarked file from disk.
	 *  - Removes the row from wp_Photolab_photos.
	 *  - If no photos remain for the album: deletes the product_cat term,
	 *    the physical album directories, and the wp_Photolab_albums row.
	 *
	 * @return void
	 */
	public function cleanup_expired_photos(): void {
		Logger::set_context( 'is_cron', true );
		Logger::set_context( 'cron_hook', self::HOOK );

		try {
			$this->cleanup_expired_photos_inner();
		} finally {
			self::schedule_next( self::HOOK, DAY_IN_SECONDS );
			Logger::clear_context();
		}
	}

	/**
	 * Body of {@see self::cleanup_expired_photos()}. Wrapped so the request-
	 * scoped logger context is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function cleanup_expired_photos_inner(): void {
		global $wpdb;

		$context = array( 'source' => 'photolab-cleanup' );

		Logger::info( 'Cleanup_Scheduler::cleanup_expired_photos() — avvio job cleanup.', $context );

		// ------------------------------------------------------------------
		// 1. Query expired, still-published photos (batched).
		// Skips photos whose album is currently uploading/watermarking/
		// deleting (RC-6). Joins on album_id (v2.0.0 FK) with a fallback to
		// album_name for legacy rows where album_id is still NULL. Albums
		// left in 'idle' OR 'aborted' are eligible — 'aborted' is a terminal
		// state with no active writer on the photo rows, so a stuck-then-
		// aborted album no longer blocks its expired photos from cleanup.
		// ------------------------------------------------------------------
		$table        = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// expiration_date is written in UTC (see Upload_Controller::start_inner()).
		// Compare against a PHP-supplied UTC timestamp rather than MySQL NOW(),
		// which reflects the DB server's own timezone and is not guaranteed UTC.
		$now_utc = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$photos = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.album_id, p.album_name, p.photo_name, p.wc_product_id, p.attachment_id, p.watermark_url
				 FROM `{$table}` p
				 WHERE p.expiration_date IS NOT NULL
				   AND p.expiration_date < %s
				   AND p.published = 1
				   AND EXISTS (
				       SELECT 1 FROM `{$albums_table}` a
				       WHERE ( ( p.album_id IS NOT NULL AND a.id = p.album_id )
				               OR ( p.album_id IS NULL AND a.album_name = p.album_name ) )
				         AND a.status IN ( 'idle', 'aborted' )
				   )",
				$now_utc
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				'Cleanup_Scheduler::cleanup_expired_photos() — errore query DB: ' . $wpdb->last_error,
				$context
			);
			return;
		}

		if ( empty( $photos ) ) {
			Logger::info( 'Cleanup_Scheduler::cleanup_expired_photos() — nessuna foto scaduta. Job terminato.', $context );
			return;
		}

		Logger::info(
			sprintf( 'Cleanup_Scheduler::cleanup_expired_photos() — %d foto scadute trovate.', count( $photos ) ),
			$context
		);

		$processed = 0;
		$errors    = 0;

		// ------------------------------------------------------------------
		// 2. Process each expired photo.
		// ------------------------------------------------------------------
		foreach ( $photos as $photo ) {
			try {
				$this->process_single_photo( $photo, $context );
				++$processed;
			} catch ( \Throwable $e ) {
				++$errors;
				Logger::error(
					sprintf(
						'Cleanup_Scheduler — eccezione foto ID %d: %s',
						(int) $photo->id,
						$e->getMessage()
					),
					$context
				);
			}
		}

		// ------------------------------------------------------------------
		// 3. Summary log.
		// ------------------------------------------------------------------
		Logger::info(
			sprintf(
				'Cleanup_Scheduler::cleanup_expired_photos() — completato. Processate: %d, Errori: %d.',
				$processed,
				$errors
			),
			$context
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Handle full deletion for a single expired photo row.
	 *
	 * Deletes WC product, Media Library attachment, watermarked file on disk,
	 * and the wp_Photolab_photos DB row. After deletion checks whether the
	 * parent album has no remaining photos; if so, deletes the product_cat term,
	 * physical album directories, and the wp_Photolab_albums row.
	 *
	 * @param object $photo   DB row from wp_Photolab_photos.
	 * @param array  $context Logger context array.
	 * @return void
	 * @throws \RuntimeException When critical DB delete fails.
	 */
	private function process_single_photo( object $photo, array $context ): void {
		global $wpdb;

		$photo_id      = (int) $photo->id;
		$product_id    = (int) $photo->wc_product_id;
		$attachment_id = (int) $photo->attachment_id;
		$album_name    = $photo->album_name;
		$album_id      = isset( $photo->album_id ) ? (int) $photo->album_id : 0;

		Logger::info(
			sprintf(
				'Cleanup_Scheduler — elaborazione foto ID %d (prodotto WC %d, attachment %d, album "%s").',
				$photo_id,
				$product_id,
				$attachment_id,
				$album_name
			),
			$context
		);

		// -- Step A: Force-delete WC product (bypass trash). ------------------
		if ( $product_id > 0 ) {
			$result = wp_delete_post( $product_id, true );
			if ( false === $result || null === $result ) {
				Logger::warning(
					sprintf( 'Cleanup_Scheduler — prodotto WC %d non eliminato (potrebbe non esistere).', $product_id ),
					$context
				);
			} else {
				Logger::info(
					sprintf( 'Cleanup_Scheduler — prodotto WC %d eliminato.', $product_id ),
					$context
				);
			}
		}

		// -- Step B: Delete Media Library attachment (featured image). --------
		if ( $attachment_id > 0 ) {
			$result = wp_delete_attachment( $attachment_id, true );
			if ( false === $result || null === $result ) {
				Logger::warning(
					sprintf( 'Cleanup_Scheduler — attachment %d non eliminato (potrebbe non esistere).', $attachment_id ),
					$context
				);
			} else {
				Logger::info(
					sprintf( 'Cleanup_Scheduler — attachment %d eliminato.', $attachment_id ),
					$context
				);
			}
		}

		// -- Step C: Delete watermarked file from disk. -----------------------
		$this->delete_watermarked_file( $photo->watermark_url, $photo_id, $context );

		// -- Step D: Delete wp_Photolab_photos row. ---------------------------
		$photos_table = $wpdb->prefix . 'Photolab_photos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete(
			$photos_table,
			array( 'id' => $photo_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			$msg = sprintf( 'Impossibile eliminare foto ID %d da DB: %s', $photo_id, $wpdb->last_error );
			throw new \RuntimeException( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		Logger::info(
			sprintf( 'Cleanup_Scheduler — foto ID %d rimossa da DB.', $photo_id ),
			$context
		);

		// -- Step E: If album now empty → delete term, directories, album row. --
		$this->maybe_delete_album( $album_id, $album_name, $context );
	}

	/**
	 * Delete the album if no photos remain.
	 *
	 * Removes the product_cat term, physical album directories (photos/ and
	 * watermarked/), and the wp_Photolab_albums row.
	 *
	 * @param int    $album_id   Album DB id (v2.0.0 FK), 0 when the photo row
	 *                           predates the album_id backfill.
	 * @param string $album_name Album name — used as fallback lookup key when
	 *                           $album_id is 0.
	 * @param array  $context    Logger context.
	 * @return void
	 */
	private function maybe_delete_album( int $album_id, string $album_name, array $context ): void {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = $album_id > 0
			? (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$photos_table}` WHERE album_id = %d",
					$album_id
				)
			)
			: (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$photos_table}` WHERE album_id IS NULL AND album_name = %s",
					$album_name
				)
			);

		if ( $remaining > 0 ) {
			Logger::info(
				sprintf(
					'Cleanup_Scheduler — album "%s": %d foto rimaste, album non eliminato.',
					$album_name,
					$remaining
				),
				$context
			);
			return;
		}

		Logger::info(
			sprintf( 'Cleanup_Scheduler — album "%s" vuoto, avvio eliminazione completa.', $album_name ),
			$context
		);

		// Fetch album row for term_id.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$album = $album_id > 0
			? $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, term_id FROM `{$albums_table}` WHERE id = %d",
					$album_id
				),
				ARRAY_A
			)
			: $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, term_id FROM `{$albums_table}` WHERE album_name = %s",
					$album_name
				),
				ARRAY_A
			);

		if ( ! $album ) {
			Logger::warning(
				sprintf( 'Cleanup_Scheduler — album "%s" non trovato in DB.', $album_name ),
				$context
			);
			return;
		}

		// Delete product_cat term.
		$term_id = (int) ( $album['term_id'] ?? 0 );
		if ( $term_id > 0 ) {
			$term_result = wp_delete_term( $term_id, 'product_cat' );
			if ( is_wp_error( $term_result ) ) {
				Logger::warning(
					sprintf(
						'Cleanup_Scheduler — impossibile eliminare term %d: %s',
						$term_id,
						$term_result->get_error_message()
					),
					$context
				);
			} else {
				Logger::info(
					sprintf( 'Cleanup_Scheduler — term product_cat %d eliminato.', $term_id ),
					$context
				);
			}
		}

		// Delete physical album directories.
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'Photolab';
		$safe_name  = sanitize_file_name( $album_name );
		$dirs       = array(
			trailingslashit( $base ) . 'photos/' . $safe_name,
			trailingslashit( $base ) . 'watermarked/' . $safe_name,
		);

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $files as $file ) {
				$file_path = $file->getRealPath();
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				rmdir( $file_path );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $file_path );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		if ( ! rmdir( $dir ) ) {
				Logger::warning(
					sprintf( 'Cleanup_Scheduler — impossibile rimuovere dir: %s', $dir ),
					$context
				);
			} else {
				Logger::info(
					sprintf( 'Cleanup_Scheduler — directory eliminata: %s', $dir ),
					$context
				);
			}
		}

		// Delete wp_Photolab_albums row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete(
			$albums_table,
			array( 'id' => (int) $album['id'] ),
			array( '%d' )
		);

		Logger::info(
			sprintf( 'Cleanup_Scheduler — album "%s" (id: %d) eliminato completamente.', $album_name, (int) $album['id'] ),
			$context
		);
	}

	/**
	 * Delete the watermarked image file from disk.
	 *
	 * Resolves the URL stored in watermark_url to an absolute filesystem path
	 * using wp_upload_dir() so the logic works regardless of WordPress install path.
	 *
	 * @param string|null $watermark_url Value from wp_Photolab_photos.watermark_url.
	 * @param int         $photo_id      Photo DB ID (for logging only).
	 * @param array       $context       Logger context.
	 * @return void
	 * @throws \RuntimeException When unlink fails.
	 */
	private function delete_watermarked_file( ?string $watermark_url, int $photo_id, array $context ): void {
		if ( empty( $watermark_url ) ) {
			Logger::info(
				sprintf( 'Cleanup_Scheduler — foto ID %d: nessun watermark_url, skip eliminazione file.', $photo_id ),
				$context
			);
			return;
		}

		// Convert URL → absolute path.
		$upload   = wp_upload_dir();
		$abs_path = str_replace(
			trailingslashit( $upload['baseurl'] ),
			trailingslashit( $upload['basedir'] ),
			$watermark_url
		);

		// Safety guard: never touch the active watermark or assets directory.
		$assets_dir = trailingslashit( $upload['basedir'] ) . 'Photolab/assets/';
		if ( str_starts_with( realpath( dirname( $abs_path ) ) . '/', realpath( $assets_dir ) . '/' ) ) {
			Logger::warning(
				sprintf( 'Cleanup_Scheduler — foto ID %d: path punta ad assets/, skip per sicurezza.', $photo_id ),
				$context
			);
			return;
		}

		if ( ! file_exists( $abs_path ) ) {
			Logger::warning(
				sprintf( 'Cleanup_Scheduler — foto ID %d: file watermark non trovato su disco (%s). Continuo.', $photo_id, $abs_path ),
				$context
			);
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! unlink( $abs_path ) ) {
			$msg = sprintf( 'Impossibile eliminare file watermark: %s', $abs_path );
			throw new \RuntimeException( $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		Logger::info(
			sprintf( 'Cleanup_Scheduler — foto ID %d: file watermark eliminato (%s).', $photo_id, $abs_path ),
			$context
		);
	}

	// =========================================================================
	// FASE 6 — Daily failsafe sweep
	// =========================================================================

	/**
	 * Daily failsafe sweep — orchestrator for the `photolab_daily_cleanup` job.
	 *
	 * Runs once every 24 hours and chains:
	 *
	 *   §6.1.1 — flag stuck `photolab_watermark_batch` AS jobs (>1h in-progress)
	 *   §6.1.4 — purge orphaned `_transient_photolab_idempotent_*` rows
	 *   §6.2   — log orphan files / orphan photo rows for review
	 *
	 * §6.1.2 (stuck watermarking photos) and §6.1.3 (failed photo retry) are
	 * handled by Recovery_Scheduler every 5 min — not duplicated here.
	 *
	 * Idempotent: every step checks current state with CAS or COUNT(*) before
	 * mutating data. Failures in one step do not block subsequent steps.
	 *
	 * Coexists with `photolab_recovery_scan` (5 min, FASE 2.5): the recovery
	 * scan only ever moves albums `uploading → aborted`; this job never touches
	 * `uploading` albums or `aborted` albums for state changes — only reads.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function run_daily_cleanup(): void {
		Logger::set_context( 'is_cron', true );
		Logger::set_context( 'cron_hook', self::DAILY_HOOK );

		try {
			$this->run_daily_cleanup_inner();
		} finally {
			self::schedule_next( self::DAILY_HOOK, DAY_IN_SECONDS );
			Logger::clear_context();
		}
	}

	/**
	 * Body of {@see self::run_daily_cleanup()}. Wrapped so the request-scoped
	 * logger context is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function run_daily_cleanup_inner(): void {
		$context = array( 'source' => 'photolab-cleanup' );
		$started = time();

		Logger::info(
			sprintf(
				'Cleanup_Scheduler::run_daily_cleanup() — start @ %s.',
				gmdate( 'Y-m-d H:i:s', $started )
			),
			$context
		);

		$summary = array(
			'stuck_jobs'        => 0,
			'transients_purged' => 0,
			'orphan_files'      => 0,
			'orphan_photos'     => 0,
			'logs_deleted'      => 0,
		);

		// foreach instead of 2 identical try/catch blocks.
		foreach ( array(
			array( 'find_stuck_watermark_jobs', 'stuck_jobs', '§6.1.1' ),
			array( 'cleanup_idempotency_transients', 'transients_purged', '§6.1.4' ),
		) as list( $method, $key, $label ) ) {
			try {
				$summary[ $key ] = $this->{$method}( $context );
			} catch ( \Throwable $e ) {
				Logger::error( sprintf( 'run_daily_cleanup — %s eccezione: %s', $label, $e->getMessage() ), $context );
			}
		}

		// §6.1.2 + §6.1.3 moved to Recovery_Scheduler (every 5 min).

		try {
			[ $orphan_files, $orphan_photos ] = $this->scan_orphans( $context );
			$summary['orphan_files']          = $orphan_files;
			$summary['orphan_photos']         = $orphan_photos;
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'run_daily_cleanup — §6.2 eccezione: %s', $e->getMessage() ),
				$context
			);
		}

		// FASE 9 — log retention. Last step before the summary so the line count
		// reflected here corresponds to entries written *during* this run.
		try {
			$summary['logs_deleted'] = Logger::cleanup_old_logs();
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf( 'run_daily_cleanup — log retention eccezione: %s', $e->getMessage() ),
				$context
			);
		}

		$elapsed = time() - $started;

		Logger::info(
			sprintf(
				'Cleanup_Scheduler::run_daily_cleanup() — end (%ds). Stuck AS jobs: %d. Transients purged: %d. Orphan files: %d. Orphan photos: %d. Logs deleted: %d.',
				$elapsed,
				$summary['stuck_jobs'],
				$summary['transients_purged'],
				$summary['orphan_files'],
				$summary['orphan_photos'],
				$summary['logs_deleted']
			),
			$context
		);
	}

	/**
	 * §6.1.1 — Detect Action Scheduler watermark batch jobs stuck in-progress.
	 *
	 * Reads from the standard Action Scheduler tables (`{$wpdb->prefix}actionscheduler_actions`).
	 * Stuck = `status='in-progress'` and `last_attempt_gmt` (or `scheduled_date_gmt`
	 * fallback) older than {@see self::STUCK_THRESHOLD_SECONDS}.
	 *
	 * Does **not** force-fail the job — Action Scheduler manages its own
	 * lifecycle, and a manual fail here would race with the worker. The log
	 * line is the actionable signal; if the same `action_id` is reported on
	 * consecutive runs, investigate.
	 *
	 * If `attempts >= 5`, escalate: error log + admin email.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Logger context.
	 * @return int Number of stuck rows detected.
	 */
	private function find_stuck_watermark_jobs( array $context ): int {
		global $wpdb;

		$as_actions = $wpdb->prefix . 'actionscheduler_actions';

		// Action Scheduler is optional; missing tables = nothing to scan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $as_actions )
		);

		if ( $exists !== $as_actions ) {
			Logger::debug(
				'find_stuck_watermark_jobs — tabella actionscheduler_actions assente, skip.',
				$context
			);
			return 0;
		}

		$threshold_sql = sprintf( 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)', self::STUCK_THRESHOLD_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action_id, args, attempts, last_attempt_gmt, scheduled_date_gmt
				 FROM `{$as_actions}`
				 WHERE hook   = %s
				   AND status = %s
				   AND COALESCE(last_attempt_gmt, scheduled_date_gmt) < {$threshold_sql}
				 LIMIT 200",
				self::WATERMARK_HOOK,
				'in-progress'
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'find_stuck_watermark_jobs — SQL error: %s', $wpdb->last_error ),
				$context
			);
			return 0;
		}

		if ( empty( $rows ) ) {
			Logger::debug( 'find_stuck_watermark_jobs — nessun job stuck.', $context );
			return 0;
		}

		$count = 0;

		foreach ( $rows as $row ) {
			$action_id = (int) $row->action_id;
			$attempts  = (int) $row->attempts;
			$args_blob = (string) $row->args;
			$album_id  = $this->extract_album_id_from_args( $args_blob );

			if ( $attempts >= self::PHOTO_RETRY_LIMIT ) {
				Logger::error(
					sprintf(
						'find_stuck_watermark_jobs — action_id=%d attempts=%d album_id=%d retry esaurite.',
						$action_id,
						$attempts,
						$album_id
					),
					$context
				);

				$this->notify_admin(
					sprintf( '[Photolab] Stuck Watermark Job — Album %d', $album_id ),
					sprintf(
						"Action Scheduler job %d (%s) stuck in-progress da oltre %d secondi.\n" .
						"Attempts: %d (max raggiunto).\nAlbum ID: %d.\n\n" .
						"Verifica i log con source 'photolab-watermark-job' e considera fail manuale via dashboard AS.",
						$action_id,
						self::WATERMARK_HOOK,
						self::STUCK_THRESHOLD_SECONDS,
						$attempts,
						$album_id
					)
				);
			} else {
				Logger::warning(
					sprintf(
						'find_stuck_watermark_jobs — action_id=%d attempts=%d album_id=%d (>1h in-progress).',
						$action_id,
						$attempts,
						$album_id
					),
					$context
				);
			}

			++$count;
		}

		return $count;
	}

	/**
	 * §6.1.2 — Recover photos stuck in `watermarking` for more than 1 hour.
	 *
	 * For each match: CAS `watermarking → failed`, then re-enqueue a fresh
	 * watermark batch when no AS job is already pending for that album.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Logger context.
	 * @return int Number of photos that were CAS-ed back to `failed`.
	 */
	public function recover_stuck_watermarking_photos( array $context ): int {
		global $wpdb;

		$photos_table  = $wpdb->prefix . 'Photolab_photos';
		$threshold_sql = sprintf( 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)', self::STUCK_THRESHOLD_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, album_id, wc_product_id
				 FROM `{$photos_table}`
				 WHERE photo_status = %s
				   AND updated_at   < {$threshold_sql}
				 LIMIT 500",
				State_Machine::PHOTO_WATERMARKING
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'recover_stuck_watermarking_photos — SQL error: %s', $wpdb->last_error ),
				$context
			);
			return 0;
		}

		if ( empty( $rows ) ) {
			Logger::debug( 'recover_stuck_watermarking_photos — nessuna foto stuck.', $context );
			// return 0; §6.1.2b must still perform for uploaded photos.
		}

		$fsm      = new State_Machine();
		$reset    = 0;
		$by_album = array();

		foreach ( $rows as $row ) {
			$photo_id   = (int) $row->id;
			$album_id   = (int) $row->album_id;
			$product_id = (int) $row->wc_product_id;

			Logger::warning(
				sprintf(
					'recover_stuck_watermarking_photos — photo=%d album=%d product=%d stuck >1h.',
					$photo_id,
					$album_id,
					$product_id
				),
				$context + array( 'photo_id' => $photo_id )
			);

			$ok = $fsm->transition_photo(
				$photo_id,
				State_Machine::PHOTO_WATERMARKING,
				State_Machine::PHOTO_FAILED
			);

			if ( ! $ok ) {
				continue;
			}

			++$reset;
			$by_album[ $album_id ][] = $photo_id;
		}

		// Re-enqueue per album, only when no batch is already pending for it.
		foreach ( $by_album as $album_id => $photo_ids ) {
			if ( $album_id <= 0 || empty( $photo_ids ) ) {
				continue;
			}

			if ( $this->is_album_job_pending( (int) $album_id ) ) {
				Logger::debug(
					sprintf(
						'recover_stuck_watermarking_photos — album=%d job already pending, no re-enqueue.',
						$album_id
					),
					$context
				);
				continue;
			}

			$this->enqueue_watermark_retry( (int) $album_id, array_map( 'intval', $photo_ids ), $context );
		}

		// §6.1.2b — Recover photos in `uploaded` on `watermarking` albums.
		// These were inserted by a chunk but never claimed by any watermark
		// batch (client crash, AS job lost, etc.). Re-enqueue a batch for
		// each album that has pending uploaded photos.
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$uploaded_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.album_id
				 FROM `{$photos_table}` p
				 INNER JOIN `{$albums_table}` a ON a.id = p.album_id
				 WHERE p.photo_status = %s
				   AND a.status       = %s
				 LIMIT 500",
				State_Machine::PHOTO_UPLOADED,
				State_Machine::ALBUM_WATERMARKING
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'recover_stuck_watermarking_photos — §6.1.2b SQL error: %s', $wpdb->last_error ),
				$context
			);
		} elseif ( ! empty( $uploaded_rows ) ) {
			$by_album = array();
			foreach ( $uploaded_rows as $row ) {
				$by_album[ (int) $row->album_id ][] = (int) $row->id;
			}
			foreach ( $by_album as $album_id => $photo_ids ) {
				$this->enqueue_watermark_retry( $album_id, $photo_ids, $context );
			}
		}

		return $reset;
	}

	/**
	 * §6.1.3 — Retry photos in `failed` whose album is still `watermarking`.
	 *
	 * For each photo: read `photolab_watermark_retry_{$photo_id}` option.
	 *
	 *   - retry_count <  PHOTO_RETRY_LIMIT  → CAS `failed → uploaded`. The
	 *     existing AS batch (or a freshly enqueued one when no batch is
	 *     pending) will pick it up.
	 *   - retry_count >= PHOTO_RETRY_LIMIT  → leave the row in `failed`, log
	 *     error, notify admin once.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Logger context.
	 * @return array{0:int,1:int} [retriggered, exhausted].
	 */
	public function retrigger_failed_photos( array $context ): array {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.album_id, p.wc_product_id
				 FROM `{$photos_table}` p
				 INNER JOIN `{$albums_table}` a ON a.id = p.album_id
				 WHERE p.photo_status = %s
				   AND a.status       = %s
				 LIMIT 500",
				State_Machine::PHOTO_FAILED,
				State_Machine::ALBUM_WATERMARKING
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'retrigger_failed_photos — SQL error: %s', $wpdb->last_error ),
				$context
			);
			return array( 0, 0 );
		}

		if ( empty( $rows ) ) {
			Logger::debug( 'retrigger_failed_photos — nessuna foto failed eleggibile.', $context );
			return array( 0, 0 );
		}

		$fsm         = new State_Machine();
		$retriggered = 0;
		$exhausted   = 0;
		$by_album    = array();

		foreach ( $rows as $row ) {
			$photo_id = (int) $row->id;
			$album_id = (int) $row->album_id;

			$retry_key   = "photolab_watermark_retry_{$photo_id}";
			$retry_count = (int) get_option( $retry_key, 0 );

			if ( $retry_count >= self::PHOTO_RETRY_LIMIT ) {
				Logger::error(
					sprintf(
						'retrigger_failed_photos — photo=%d album=%d retry esaurite (%d/%d).',
						$photo_id,
						$album_id,
						$retry_count,
						self::PHOTO_RETRY_LIMIT
					),
					$context + array( 'photo_id' => $photo_id )
				);

				$notified_key = "photolab_watermark_notified_{$photo_id}";

				if ( ! get_option( $notified_key ) ) {
					$this->notify_admin(
						sprintf( '[Photolab] Watermark Retry Exhausted — Album %d', $album_id ),
						sprintf(
							"Photo %d (product %d, album %d) ha esaurito i tentativi di watermark (%d/%d).\n\n" .
							"Per indagare consulta i log con source 'photolab-watermark-job'.\n" .
							"Per riprovare manualmente: cancella l'option 'photolab_watermark_retry_%d' e " .
							"resetta la riga a photo_status='uploaded'.",
							$photo_id,
							(int) $row->wc_product_id,
							$album_id,
							$retry_count,
							self::PHOTO_RETRY_LIMIT,
							$photo_id
						)
					);

					update_option( $notified_key, time(), false );
				}

				++$exhausted;
				continue;
			}

			$ok = $fsm->transition_photo(
				$photo_id,
				State_Machine::PHOTO_FAILED,
				State_Machine::PHOTO_UPLOADED
			);

			if ( ! $ok ) {
				continue;
			}

			Logger::info(
				sprintf(
					'retrigger_failed_photos — photo=%d album=%d retry=%d→%d, CAS failed→uploaded.',
					$photo_id,
					$album_id,
					$retry_count,
					$retry_count + 1
				),
				$context + array( 'photo_id' => $photo_id )
			);

			++$retriggered;
			$by_album[ $album_id ][] = $photo_id;
		}

		// Re-enqueue per album. A pending job will skip already-watermarked
		// photos via the CAS guard, so re-enqueueing is safe even when a
		// concurrent worker is running.
		foreach ( $by_album as $album_id => $photo_ids ) {
			if ( $album_id <= 0 || empty( $photo_ids ) ) {
				continue;
			}

			$this->enqueue_watermark_retry( (int) $album_id, array_map( 'intval', $photo_ids ), $context );
		}

		return array( $retriggered, $exhausted );
	}

	/**
	 * §6.1.4 — Purge orphaned `_transient_photolab_idempotent_*` rows.
	 *
	 * WordPress lazily removes expired transients only on access. After 24h
	 * the rows pile up in `wp_options`. This sweep finds every Photolab
	 * idempotency transient whose timeout sibling is in the past and
	 * `delete_transient()`s the original key (also clears the timeout).
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Logger context.
	 * @return int Number of expired transients removed.
	 */
	private function cleanup_idempotency_transients( array $context ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value
				 FROM `{$wpdb->options}`
				 WHERE option_name LIKE %s
				 LIMIT 5000",
				$wpdb->esc_like( '_transient_timeout_photolab_idempotent_' ) . '%'
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'cleanup_idempotency_transients — SQL error: %s', $wpdb->last_error ),
				$context
			);
			return 0;
		}

		if ( empty( $rows ) ) {
			Logger::debug( 'cleanup_idempotency_transients — nessun transient da pulire.', $context );
			return 0;
		}

		$now      = time();
		$cleaned  = 0;
		$prefix   = '_transient_timeout_';
		$prefix_n = strlen( $prefix );

		foreach ( $rows as $row ) {
			$timeout_name = (string) $row->option_name;
			$expires_at   = (int) $row->option_value;

			if ( $expires_at > $now ) {
				continue;
			}

			// Strip "_transient_timeout_" → "photolab_idempotent_<key>".
			$transient_key = substr( $timeout_name, $prefix_n );

			if ( '' === $transient_key || ! str_starts_with( $transient_key, 'photolab_idempotent_' ) ) {
				continue;
			}

			delete_transient( $transient_key );
			++$cleaned;
		}

		Logger::debug(
			sprintf( 'cleanup_idempotency_transients — purged=%d.', $cleaned ),
			$context
		);

		return $cleaned;
	}

	/**
	 * §6.2 — Scan disk and DB for orphans (log only, never delete).
	 *
	 * Two parallel checks:
	 *
	 *   1. For every `aborted` album, list watermarked files on disk and
	 *      compare against the photo rows that point at them via
	 *      `attachment_id`/`watermark_url`. Files with no matching row are
	 *      logged as orphans.
	 *
	 *   2. Photo rows whose `album_id` no longer maps to an `albums` row are
	 *      logged as orphan records.
	 *
	 * Deletion always requires a human review.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Logger context.
	 * @return array{0:int,1:int} [orphan_files, orphan_photo_rows].
	 */
	private function scan_orphans( array $context ): array {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		$orphan_files  = 0;
		$orphan_photos = 0;

		// --------- 1) Disk scan for aborted albums. ----------------------------
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$aborted = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, album_name FROM `{$albums_table}` WHERE status = %s LIMIT 500",
				State_Machine::ALBUM_ABORTED
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'scan_orphans — SQL error (aborted albums): %s', $wpdb->last_error ),
				$context
			);
		} elseif ( ! empty( $aborted ) ) {
			$upload = wp_upload_dir();
			$base   = trailingslashit( $upload['basedir'] ) . 'Photolab/watermarked/';

			foreach ( $aborted as $album ) {
				$album_id   = (int) $album->id;
				$album_name = (string) $album->album_name;
				$safe_name  = sanitize_file_name( $album_name );
				$dir        = $base . $safe_name;

				if ( ! is_dir( $dir ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$known_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT attachment_id, watermark_url
						 FROM `{$photos_table}`
						 WHERE album_id = %d AND watermark_url IS NOT NULL AND watermark_url <> ''",
						$album_id
					)
				);

				$known_basenames = array();

				if ( ! empty( $known_rows ) ) {
					foreach ( $known_rows as $kr ) {
						$url = (string) $kr->watermark_url;
						if ( '' !== $url ) {
							$known_basenames[ wp_basename( $url ) ] = true;
						}
					}
				}

				$entries = glob( trailingslashit( $dir ) . '*' );
				if ( ! is_array( $entries ) ) {
					continue;
				}

				foreach ( $entries as $file_path ) {
					if ( ! is_file( $file_path ) ) {
						continue;
					}

					$basename = wp_basename( $file_path );

					if ( isset( $known_basenames[ $basename ] ) ) {
						continue;
					}

					Logger::warning(
						sprintf( 'scan_orphans — orphan watermarked file: %s (album="%s").', $file_path, $album_name ),
						$context
					);

					++$orphan_files;
				}
			}
		}

		// --------- 2) Photo rows pointing at missing albums. -------------------
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$dangling = $wpdb->get_results(
			"SELECT p.id, p.wc_product_id, p.attachment_id, p.album_id
			 FROM `{$photos_table}` p
			 LEFT JOIN `{$albums_table}` a ON a.id = p.album_id
			 WHERE p.album_id IS NOT NULL
			   AND a.id IS NULL
			 LIMIT 500"
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'scan_orphans — SQL error (dangling photos): %s', $wpdb->last_error ),
				$context
			);
		} elseif ( ! empty( $dangling ) ) {
			foreach ( $dangling as $row ) {
				Logger::warning(
					sprintf(
						'scan_orphans — orphan photo record photo=%d product=%d attachment=%d album_id=%d (album mancante).',
						(int) $row->id,
						(int) $row->wc_product_id,
						(int) $row->attachment_id,
						(int) $row->album_id
					),
					$context
				);

				++$orphan_photos;
			}
		}

		return array( $orphan_files, $orphan_photos );
	}

	/**
	 * §6.3 — Send a one-shot admin notification email.
	 *
	 * Wrapper around `wp_mail()` that:
	 *   - resolves the recipient via `get_option('admin_email')`,
	 *   - bails silently when the address is empty,
	 *   - logs the attempt regardless of `wp_mail()` return value (since
	 *     transport failure is invisible at the call site).
	 *
	 * Subjects are expected to follow the convention `[Photolab] {kind} —
	 * Album {id}` so admins can filter inbox rules.
	 *
	 * @since 2.0.0
	 *
	 * @param string $subject Email subject (English, plain text).
	 * @param string $message Email body (English, plain text).
	 * @return void
	 */
	private function notify_admin( string $subject, string $message ): void {
		$context = array( 'source' => 'photolab-cleanup' );

		$to = (string) get_option( 'admin_email' );

		if ( '' === $to ) {
			Logger::warning(
				sprintf( 'notify_admin — admin_email vuoto, notifica saltata. subject="%s".', $subject ),
				$context
			);
			return;
		}

		wp_mail( $to, $subject, $message );

		Logger::info(
			sprintf( 'notify_admin — email inviata a %s. subject="%s".', $to, $subject ),
			$context
		);
	}

	/**
	 * Check whether an Action Scheduler watermark batch is already pending or
	 * in-progress for the given album.
	 *
	 * Pending = `as_next_scheduled_action()` returns a timestamp for the AS
	 * group `photolab_album_{id}` and hook `photolab_watermark_batch`.
	 *
	 * @since 2.0.0
	 *
	 * @param int $album_id Album row id.
	 * @return bool True when re-enqueuing would create a duplicate.
	 */
	private function is_album_job_pending( int $album_id ): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		$next = as_next_scheduled_action(
			self::WATERMARK_HOOK,
			null,
			"photolab_album_{$album_id}"
		);

		return false !== $next && null !== $next;
	}

	/**
	 * Schedule a fresh `photolab_watermark_batch` for the given photos.
	 *
	 * Uses `WC()->queue()->schedule_single()` to mirror the original enqueue
	 * path in `Upload_Controller::enqueue_watermark_batch()`.
	 *
	 * @since 2.0.0
	 *
	 * @param int   $album_id  Album id (also drives the AS group name).
	 * @param int[] $photo_ids Photo ids to process.
	 * @param array $context   Logger context.
	 * @return void
	 */
	private function enqueue_watermark_retry( int $album_id, array $photo_ids, array $context ): void {
		if ( $album_id <= 0 || empty( $photo_ids ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->queue() ) {
			Logger::error(
				sprintf( 'enqueue_watermark_retry — WC()->queue() non disponibile album=%d.', $album_id ),
				$context
			);
			return;
		}

		// batches of 2 to stay within AS 300s HTTP timeout.
		$batch_size = (int) apply_filters( 'photolab_watermark_batch_size', 2 );
		foreach ( array_chunk( $photo_ids, $batch_size ) as $chunk ) {
			$action_id = as_schedule_single_action(
				time(),
				self::WATERMARK_HOOK,
				array(
					'album_id'  => $album_id,
					'photo_ids' => array_values( $chunk ),
				),
				"photolab_album_{$album_id}",
				1,    // priority — claim before hostinger (priority 10).
				false // unique.
			);

			Logger::info(
				sprintf(
					'enqueue_watermark_retry — album=%d photos=%d action_id=%d.',
					$album_id,
					count( $chunk ),
					(int) $action_id
				),
				$context
			);
		}
	}

	/**
	 * Best-effort album-id extractor from the Action Scheduler `args` blob.
	 *
	 * `actionscheduler_actions.args` is JSON; the watermark batch payload
	 * carries `{ "album_id": N, "photo_ids": [...] }`. Returns 0 when parsing
	 * fails so callers can still log the action without a usable id.
	 *
	 * @since 2.0.0
	 *
	 * @param string $args_blob JSON-encoded args from AS.
	 * @return int Album id, or 0 on parse failure.
	 */
	private function extract_album_id_from_args( string $args_blob ): int {
		if ( '' === $args_blob ) {
			return 0;
		}

		$decoded = json_decode( $args_blob, true );

		if ( ! is_array( $decoded ) ) {
			return 0;
		}

		// AS sometimes wraps args as an indexed array of positional values.
		if ( isset( $decoded['album_id'] ) ) {
			return (int) $decoded['album_id'];
		}

		if ( isset( $decoded[0] ) && is_numeric( $decoded[0] ) ) {
			return (int) $decoded[0];
		}

		return 0;
	}

	// =========================================================================
	// P1 — Recover uploaded photos on aborted albums (continuous flow)
	// =========================================================================

	/**
	 * Re-enqueue watermark batches for uploaded photos on aborted albums.
	 *
	 * When Recovery_Scheduler aborts an album, photos already on disk and
	 * in the DB as WC products would otherwise remain unwatermarked forever.
	 * This method enqueues watermark batches for them so the Watermark_Job
	 * can finish the work even though the album was aborted.
	 *
	 * @since 2.2.0
	 *
	 * @param array $context Logger context.
	 * @return int Number of albums with re-enqueued batches.
	 */
	public function recover_uploaded_on_aborted( array $context ): int {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.album_id
				 FROM `{$photos_table}` p
				 INNER JOIN `{$albums_table}` a ON a.id = p.album_id
				 WHERE p.photo_status = %s
				   AND a.status       = %s
				 LIMIT 500",
				State_Machine::PHOTO_UPLOADED,
				State_Machine::ALBUM_ABORTED
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error( sprintf( 'recover_uploaded_on_aborted — SQL error: %s', $wpdb->last_error ), $context );
			return 0;
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$by_album = array();
		foreach ( $rows as $row ) {
			$by_album[ (int) $row->album_id ][] = (int) $row->id;
		}

		$count = 0;
		foreach ( $by_album as $album_id => $photo_ids ) {
			if ( $this->is_album_job_pending( $album_id ) ) {
				continue;
			}
			$this->enqueue_watermark_retry( $album_id, $photo_ids, $context );
			++$count;
		}

		Logger::info(
			sprintf( 'recover_uploaded_on_aborted — %d album con foto re-enqueued.', $count ),
			$context
		);

		return $count;
	}

	// =========================================================================
	// P2 — Auto-settle albums when all photos are terminal
	// =========================================================================

	/**
	 * Settle albums to idle when every photo is watermarked or failed.
	 *
	 * Covers both `watermarking` (after maybe_finalise_album misses a batch)
	 * and `aborted` (after P1 recovery finishes processing the photos).
	 *
	 * @since 2.2.0
	 *
	 * @param array $context Logger context.
	 * @return int Number of albums settled.
	 */
	public function auto_settle_albums( array $context ): int {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// Albums in watermarking or aborted with zero non-terminal photos.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.status, a.watermark_snapshot
				 FROM `{$albums_table}` a
				 WHERE a.status IN (%s, %s)
				   AND 0 = (
				       SELECT COUNT(*) FROM `{$photos_table}` p
				       WHERE p.album_id = a.id
				         AND p.photo_status IN (%s, %s)
				   )
				 LIMIT 100",
				State_Machine::ALBUM_WATERMARKING,
				State_Machine::ALBUM_ABORTED,
				State_Machine::PHOTO_UPLOADED,
				State_Machine::PHOTO_WATERMARKING
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error( sprintf( 'auto_settle_albums — SQL error: %s', $wpdb->last_error ), $context );
			return 0;
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$fsm     = new State_Machine();
		$settled = 0;

		foreach ( $rows as $row ) {
			$album_id = (int) $row->id;
			$from     = (string) $row->status;
			$snapshot = (string) ( $row->watermark_snapshot ?? '' );

			$ok = $fsm->transition_album(
				$album_id,
				$from,
				State_Machine::ALBUM_IDLE,
				'' !== $snapshot ? array( 'watermark_snapshot' => null ) : array()
			);
			if ( $ok ) {
				++$settled;
				// Clean up watermark snapshot file when settling.
				if ( '' !== $snapshot && file_exists( $snapshot ) ) {
					$upload      = wp_upload_dir();
					$assets_dir  = trailingslashit( $upload['basedir'] ) . 'Photolab/assets/';
					$real_snap   = realpath( $snapshot );
					$real_assets = realpath( $assets_dir );
					if ( false !== $real_snap && false !== $real_assets
						&& str_starts_with( $real_snap, $real_assets )
					) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						unlink( $snapshot );
					}
				}
				delete_option( "photolab_album_{$album_id}_price" );
				Logger::info(
					sprintf( 'auto_settle_albums — album=%d %s→idle.', $album_id, $from ),
					$context
				);
			}
		}

		if ( $settled > 0 ) {
			Logger::info( sprintf( 'auto_settle_albums — %d album setted.', $settled ), $context );
		}

		return $settled;
	}

	// =========================================================================
	// P4 — Dead letter: auto-delete failed photos on idle albums (7+ days)
	// =========================================================================

	/**
	 * Delete WC products and photo rows for photos stuck in failed status
	 * for more than 7 days on settled (idle/aborted) albums.
	 *
	 * These photos have exhausted their retry budget and will never recover.
	 * Keeping them just bloats the DB and leaves orphan published products.
	 *
	 * @since 2.2.0
	 *
	 * @param array $context Logger context.
	 * @return int Number of deleted photos.
	 */
	public function delete_stale_failed_photos( array $context ): int {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.wc_product_id
				 FROM `{$photos_table}` p
				 INNER JOIN `{$albums_table}` a ON a.id = p.album_id
				 WHERE p.photo_status = %s
				   AND a.status       IN (%s, %s)
				   AND p.updated_at   < (UTC_TIMESTAMP() - INTERVAL 7 DAY)
				 LIMIT 500",
				State_Machine::PHOTO_FAILED,
				State_Machine::ALBUM_IDLE,
				State_Machine::ALBUM_ABORTED
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error( sprintf( 'delete_stale_failed_photos — SQL error: %s', $wpdb->last_error ), $context );
			return 0;
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $rows as $row ) {
			$product_id = (int) $row->wc_product_id;
			if ( $product_id > 0 ) {
				wp_delete_post( $product_id, true );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $photos_table, array( 'id' => (int) $row->id ), array( '%d' ) );
			// Clean up orphan watermark retry counters (G2).
			delete_option( "photolab_watermark_retry_{$row->id}" );
			delete_option( "photolab_watermark_notified_{$row->id}" );
			++$deleted;
		}

		Logger::info(
			sprintf( 'delete_stale_failed_photos — %d foto eliminate.', $deleted ),
			$context
		);

		return $deleted;
	}
}


// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared