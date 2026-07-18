<?php
/**
 * Action Scheduler job that recovers crashed uploads.
 *
 * Scans `wp_Photolab_albums` every 5 minutes for rows stuck in `uploading`
 * whose heartbeat is stale (5+ minutes) or whose upload started 10+ minutes
 * ago without a single heartbeat. Marks them `aborted` so the UI can offer
 * Reset / Delete actions.
 *
 * Self-rescheduling single action: each run schedules the next via
 * `Cleanup_Scheduler::schedule_next()`.
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
 * Photolab\Recovery_Scheduler — recovers crashed upload jobs.
 *
 * Separate from `Cleanup_Scheduler` because it handles a different concern
 * (state recovery vs. retention) and runs on a different cadence.
 *
 * Uses self-rescheduling single actions: each run schedules its own follow-up
 * in the `finally` block so the next run is never created until the current
 * one completes.
 *
 * @since 2.0.0
 */
class Recovery_Scheduler {

	/**
	 * Action Scheduler hook name for the recovery scan.
	 *
	 * @var string
	 */
	const HOOK = 'photolab_recovery_scan';

	/**
	 * Recovery cadence in seconds (5 min).
	 *
	 * @var int
	 */
	const INTERVAL = 300;

	/**
	 * Maximum rows touched per run — protects against query storms even on
	 * pathological data.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 100;

	/**
	 * Heartbeat staleness threshold in minutes.
	 *
	 * @var int
	 */
	const HEARTBEAT_STALE_MIN = 5;

	/**
	 * No-heartbeat fallback threshold in minutes (legacy clients).
	 *
	 * @var int
	 */
	const NO_HEARTBEAT_GRACE_MIN = 10;

	/**
	 * Wire up the AS hook callback.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'scan_and_recover' ) );
	}

	/**
	 * Ensure the first single-run action is scheduled for the recovery scan.
	 *
	 * Converts the old recurring-action pattern to a self-rescheduling single
	 * action so the callback always runs before the follow-up is created.
	 * On first call, removes any lingering old recurring actions via a per-hook
	 * migration option.
	 *
	 * @since 2.2.6
	 *
	 * @return void
	 */
	public static function ensure_first_action(): void {
		$migrated_key = 'photolab_scheduler_migrated_' . self::HOOK;
		if ( ! get_option( $migrated_key ) ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, array(), 'photolab' );
				update_option( $migrated_key, true, false );
			}
		}

		Cleanup_Scheduler::ensure_first_action( self::HOOK, self::INTERVAL );
	}

	/**
	 * Cancel all scheduled scans (called from plugin deactivation).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, array(), 'photolab' );

		Logger::info(
			'Recovery_Scheduler::unschedule() — recovery scan rimosso.',
			array( 'source' => 'photolab-recovery' )
		);
	}

	/**
	 * Scan for stale `uploading` albums and flag them as `aborted`.
	 *
	 * Only `uploading` rows are touched. `watermarking` rows are recovered
	 * by the F6 recovery pipeline (`recover_stuck_watermarking_photos`).
	 *
	 * Photos already saved on disk are left intact — the album row simply
	 * moves from `uploading` to `aborted`. The user can then call
	 * `POST /albums/{id}/reset` to retry, or `DELETE /albums/{id}` to drop.
	 *
	 * On completion, schedules the next run via
	 * `Cleanup_Scheduler::schedule_next()` so the follow-up is only created
	 * after the current run finishes.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function scan_and_recover(): void {
		Logger::set_context( 'is_cron', true );
		Logger::set_context( 'cron_hook', self::HOOK );

		try {
			$this->scan_and_recover_inner();
		} finally {
			Cleanup_Scheduler::schedule_next( self::HOOK, self::INTERVAL );
			Logger::clear_context();
		}
	}

	/**
	 * Body of {@see self::scan_and_recover()}. Wrapped so the request-scoped
	 * logger context is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	private function scan_and_recover_inner(): void {
		global $wpdb;

		$context = array( 'source' => 'photolab-recovery' );

		Logger::info( 'Recovery_Scheduler::scan_and_recover — avvio.', $context );

		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// Pick stuck albums in two complementary buckets:
		// - have a heartbeat that's older than HEARTBEAT_STALE_MIN.
		// - never sent a heartbeat AND upload_started_at is older than
		// NO_HEARTBEAT_GRACE_MIN (legacy clients before the heartbeat
		// endpoint existed).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stuck = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, album_name, watermark_snapshot
				 FROM `{$albums_table}`
				 WHERE status = %s
				   AND (
				        ( last_heartbeat IS NOT NULL AND last_heartbeat < (UTC_TIMESTAMP() - INTERVAL %d MINUTE) )
				     OR ( last_heartbeat IS NULL     AND upload_started_at IS NOT NULL AND upload_started_at < (UTC_TIMESTAMP() - INTERVAL %d MINUTE) )
				   )
				 LIMIT %d",
				State_Machine::ALBUM_UPLOADING,
				self::HEARTBEAT_STALE_MIN,
				self::NO_HEARTBEAT_GRACE_MIN,
				self::BATCH_SIZE
			)
		);

		// G1 — Recover albums stuck in `deleting` for more than 1 hour.
		// uses created_at as proxy; CAS fails if delete still running.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stuck_deleting = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, album_name, watermark_snapshot
				 FROM `{$albums_table}`
				 WHERE status = %s
				   AND created_at < (UTC_TIMESTAMP() - INTERVAL 1 HOUR)
				 LIMIT %d",
				State_Machine::ALBUM_DELETING,
				self::BATCH_SIZE
			)
		);

		// Merge deleting into stuck for unified processing.
		if ( ! empty( $stuck_deleting ) ) {
			$stuck = array_merge( (array) $stuck, (array) $stuck_deleting );
		}

		if ( '' !== (string) $wpdb->last_error ) {
			Logger::info( 'Recovery_Scheduler::scan_and_recover — nessun album bloccato.', $context );
		} else {
			Logger::warning(
				sprintf( 'Recovery_Scheduler::scan_and_recover — %d album candidati ad abort.', count( $stuck ) ),
				$context
			);

			$fsm       = new State_Machine();
			$aborted   = 0;
			$now_mysql = current_time( 'mysql', true );

			foreach ( $stuck as $row ) {
				$album_id = (int) $row->id;

				$transitioned = $fsm->transition_album(
					$album_id,
					State_Machine::ALBUM_UPLOADING,
					State_Machine::ALBUM_ABORTED,
					array( 'aborted_at' => $now_mysql )
				);

				if ( ! $transitioned ) {
					// also recover albums stuck in `deleting`.
					$transitioned = $fsm->transition_album(
						$album_id,
						State_Machine::ALBUM_DELETING,
						State_Machine::ALBUM_ABORTED,
						array( 'aborted_at' => $now_mysql )
					);
				}

				if ( ! $transitioned ) {
					continue;
				}

				++$aborted;

				Logger::warning(
					sprintf(
						'Recovery_Scheduler — album abortito id=%d name="%s".',
						$album_id,
						(string) $row->album_name
					),
					$context
				);

				// Clean up stale price option (G3).
				delete_option( "photolab_album_{$album_id}_price" );

				$snapshot_path = (string) ( $row->watermark_snapshot ?? '' );
				if ( '' !== $snapshot_path ) {
					$this->remove_snapshot_safely( $snapshot_path, $album_id, $context );
				}
			}

			Logger::info(
				sprintf( 'Recovery_Scheduler::scan_and_recover — completato. Aborted: %d.', $aborted ),
				$context
			);
		}

		// §6.1.2 + §6.1.3 — Recover watermark-stuck photos every 5 min instead
		// of waiting 24h for the daily cleanup sweep.
		$cleanup = new Cleanup_Scheduler();
		$cleanup->recover_stuck_watermarking_photos( $context );
		$cleanup->retrigger_failed_photos( $context );

		// P1 — Re-enqueue watermark for uploaded photos on aborted albums.
		$cleanup->recover_uploaded_on_aborted( $context );

		// P2 — Auto-settle albums where all photos are terminal.
		$cleanup->auto_settle_albums( $context );

		// P4 — Delete stale failed photos on idle/aborted albums (>7 days).
		$cleanup->delete_stale_failed_photos( $context );
	}

	/**
	 * Unlink a watermark snapshot file when it lives inside the Photolab
	 * assets directory. Refuses to touch anything outside.
	 *
	 * @since 2.0.0
	 *
	 * @param string $snapshot_path Absolute path.
	 * @param int    $album_id      Album id (for logging).
	 * @param array  $context       Logger context.
	 * @return void
	 */
	private function remove_snapshot_safely( string $snapshot_path, int $album_id, array $context ): void {
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
			if ( ! unlink( $snapshot_path ) ) {
				Logger::warning(
					sprintf(
						'Recovery_Scheduler — unlink snapshot fallita album=%d (%s).',
						$album_id,
						$snapshot_path
					),
					$context
				);
			}
		}
	}
}



// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared