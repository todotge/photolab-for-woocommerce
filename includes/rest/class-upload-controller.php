<?php
/**
 * REST controller for upload endpoints.
 *
 * Handles /upload/start, /upload/chunk, /upload/status, /upload/complete.
 *
 * @package Photolab
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared


namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upload REST controller.
 *
 * @extends \WP_REST_Controller
 */
class Upload_Controller extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'photolab/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'upload';

	/**
	 * Register routes.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/start',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_start_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/chunk',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'chunk' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_chunk_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_status_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/complete',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'complete' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_complete_args(),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission
	// -------------------------------------------------------------------------

	/**
	 * Check that the current user is an admin.
	 *
	 * @since 2.0.0
	 * @param \WP_REST_Request $request Incoming request.
	 * @return bool|\WP_Error
	 */
	public function admin_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'photolab_forbidden',
				__( 'Access denied.', 'todot-photolab' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * POST /upload/start
	 *
	 * Creates a new album row in `uploading` state with ownership tracking,
	 * provisions the WC product_cat term, snapshots the active watermark.
	 *
	 * Concurrency control:
	 *  - Rate limit: ≤ 3 simultaneous uploads/watermark jobs per user (HTTP 429).
	 *  - Duplicate: blocks if same `album_name + user_id` already in flight (HTTP 409).
	 *  - Atomic CAS: re-using an existing idle row uses `State_Machine::transition_album`.
	 *
	 * @since 2.0.0 Now FSM-aware. Tracks ownership + heartbeat columns.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->start_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of POST /upload/start. Extracted so the public entry point can wrap
	 * the work in a try/finally that always clears the request-scoped logger
	 * context (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function start_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$context = array( 'source' => 'photolab-upload' );
		$user_id = (int) get_current_user_id();

		Logger::set_context( 'user_id', $user_id );

		$album_name      = sanitize_text_field( $request->get_param( 'album_name' ) );
		$price           = (float) $request->get_param( 'price' );
		$expiration_date = sanitize_text_field( (string) $request->get_param( 'expiration_date' ) );

		Logger::info(
			sprintf( 'Upload_Controller::start() — user=%d album="%s"', $user_id, $album_name ),
			$context
		);

		$albums_table = $wpdb->prefix . 'Photolab_albums';
		$fsm          = new State_Machine();

		// 1. Rate limit — max 3 active jobs per user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM `{$albums_table}`
				 WHERE user_id = %d
				   AND status IN ('uploading','watermarking')",
				$user_id
			)
		);

		if ( $active_count >= 3 ) {
			Logger::warning(
				sprintf(
					'Upload_Controller::start() — rate limit reached user=%d current_count=%d.',
					$user_id,
					$active_count
				),
				array( 'source' => 'photolab-rate-limit' )
			);
			return new \WP_Error(
				'too_many_uploads',
				__( 'Maximum 3 concurrent uploads. Please wait for one to complete before starting a new one.', 'todot-photolab' ),
				array( 'status' => 429 )
			);
		}

		// 2. Duplicate album in flight for this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$dup_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM `{$albums_table}`
				 WHERE album_name = %s
				   AND user_id    = %d
				   AND status IN ('uploading','watermarking')
				 LIMIT 1",
				$album_name,
				$user_id
			)
		);

		if ( $dup_id > 0 ) {
			Logger::debug(
				sprintf(
					'Upload_Controller::start() — duplicate album name blocked album_name="%s" user_id=%d existing_id=%d.',
					$album_name,
					$user_id,
					$dup_id
				),
				$context
			);
			return new \WP_Error(
				'album_uploading',
				__( 'An album with this name is already being uploaded. Please wait for it to complete or choose a different name.', 'todot-photolab' ),
				array( 'status' => 409 )
			);
		}

		// 3. Resolve expiration to MySQL DATETIME (UTC midnight) or null.
		// Explicit '00:00:00' + UTC timezone: createFromFormat() otherwise
		// fills the unspecified time fields with the current wall-clock
		// time in the PHP default timezone, which drifted from the UTC
		// values used elsewhere (current_time('mysql', true)) and made
		// expiry fire hours early/late depending on when /start was called.
		$expiration_dt    = \DateTime::createFromFormat( 'Y-m-d H:i:s', $expiration_date . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
		$expiration_value = ( '' !== $expiration_date && $expiration_dt instanceof \DateTime )
			? $expiration_dt->format( 'Y-m-d H:i:s' )
			: null;

		$now = current_time( 'mysql', true );

		// 4. Try existing row first — if there's an idle row with same name,
		// flip it to uploading via CAS (handles re-uploads to a previously
		// completed album owned by anyone — but pin user_id to current user).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, status, user_id FROM `{$albums_table}` WHERE album_name = %s",
				$album_name
			)
		);

		$album_id = 0;

		if ( null !== $existing ) {
			$album_id = (int) $existing->id;

			// Block cross-user takeover of an idle album that belongs to another user.
			$existing_owner = (int) $existing->user_id;
			if ( $existing_owner > 0 && $existing_owner !== $user_id ) {
				Logger::warning(
					sprintf(
						'Upload_Controller::start() — ownership mismatch album_id=%d expected_user_id=%d user_id=%d.',
						$album_id,
						$existing_owner,
						$user_id
					),
					array( 'source' => 'photolab-ownership' )
				);
				return new \WP_Error(
					'forbidden',
					__( 'You do not have permission to access this album.', 'todot-photolab' ),
					array( 'status' => 403 )
				);
			}

			$transitioned = $fsm->transition_album(
				$album_id,
				State_Machine::ALBUM_IDLE,
				State_Machine::ALBUM_UPLOADING,
				array(
					'upload_started_at' => $now,
					'last_heartbeat'    => $now,
					'aborted_at'        => null,
					'expiration_date'   => $expiration_value,
					'user_id'           => $user_id,
				)
			);

			if ( ! $transitioned ) {
				Logger::warning(
					sprintf( 'Upload_Controller::start() — CAS idle→uploading fallito album=%d. 409.', $album_id ),
					$context
				);
				return new \WP_Error(
					'album_uploading',
					__( 'Album is not in idle state, please try again later.', 'todot-photolab' ),
					array( 'status' => 409 )
				);
			}
		} else {
			// 5. New album → INSERT directly in uploading.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$albums_table,
				array(
					'album_name'        => $album_name,
					'user_id'           => $user_id,
					'status'            => State_Machine::ALBUM_UPLOADING,
					'expiration_date'   => $expiration_value,
					'upload_started_at' => $now,
					'last_heartbeat'    => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				Logger::error(
					sprintf( 'Upload_Controller::start() — INSERT fallito "%s": %s', $album_name, $wpdb->last_error ),
					$context
				);
				return new \WP_Error(
					'photolab_db_error',
					__( 'Database error creating album.', 'todot-photolab' ),
					array( 'status' => 500 )
				);
			}

			$album_id = (int) $wpdb->insert_id;
			Logger::info( sprintf( 'Upload_Controller::start() — album creato id=%d user=%d.', $album_id, $user_id ), $context );
		}

		Logger::set_context( 'album_id', $album_id );

		// 6. Create or retrieve the WC product_cat term.
		$term_exists = term_exists( $album_name, 'product_cat' );
		if ( $term_exists ) {
			$term_id = (int) ( is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists );
		} else {
			$term_result = wp_insert_term( $album_name, 'product_cat' );

			if ( is_wp_error( $term_result ) ) {
				Logger::error(
					sprintf( 'Upload_Controller::start() — errore term WC: %s. Rollback.', $term_result->get_error_message() ),
					$context
				);
				$this->rollback_album( $album_id, 0, '', $context );
				return new \WP_Error(
					'photolab_term_error',
					$term_result->get_error_message(),
					array( 'status' => 500 )
				);
			}

			$term_id = (int) $term_result['term_id'];
		}

		// 7. Persist term_id (no race here — album just locked into uploading).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$albums_table,
			array( 'term_id' => $term_id ),
			array( 'id' => $album_id ),
			array( '%d' ),
			array( '%d' )
		);

		// 8. Store price as transient meta (no extra column needed).
		update_option( "photolab_album_{$album_id}_price", $price, false );

		// 9. RC-2: snapshot the active watermark for the duration of the batch.
		$watermark_snapshot = '';
		$upload_dir         = wp_upload_dir();
		$watermark_src      = trailingslashit( $upload_dir['basedir'] ) . 'Photolab/assets/watermark.png';

		if ( file_exists( $watermark_src ) ) {
			$snapshot_path = trailingslashit( $upload_dir['basedir'] ) . 'Photolab/assets/watermark_' . time() . '.png';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( copy( $watermark_src, $snapshot_path ) ) {
				$watermark_snapshot = $snapshot_path;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update(
					$albums_table,
					array( 'watermark_snapshot' => $watermark_snapshot ),
					array( 'id' => $album_id ),
					array( '%s' ),
					array( '%d' )
				);

				Logger::info(
					sprintf( 'Upload_Controller::start() — snapshot watermark: %s', $watermark_snapshot ),
					$context
				);
			} else {
				Logger::error( 'Upload_Controller::start() — copy watermark snapshot fallita. Rollback.', $context );
				$this->rollback_album( $album_id, $term_id, '', $context );
				return new \WP_Error(
					'photolab_watermark_snapshot',
					__( 'Errore creazione snapshot watermark.', 'todot-photolab' ),
					array( 'status' => 500 )
				);
			}
		} else {
			Logger::info( 'Upload_Controller::start() — nessun watermark attivo, procedo senza.', $context );
		}

		Logger::info(
			sprintf( 'Upload_Controller::start() — OK job_id=%d term_id=%d.', $album_id, $term_id ),
			$context
		);

		return new \WP_REST_Response(
			array(
				'job_id'             => $album_id,
				'album_id'           => $album_id,
				'term_id'            => $term_id,
				'status'             => State_Machine::ALBUM_UPLOADING,
				'watermark_snapshot' => $watermark_snapshot,
			),
			200
		);
	}

	/**
	 * Roll back a partially-created album when /start fails after the row exists.
	 *
	 * Removes the album row, optional WC term, and snapshot file. Used when
	 * term creation or watermark snapshot fail mid-pipeline.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $album_id           Album DB id.
	 * @param int    $term_id            WC term to delete (0 to skip).
	 * @param string $watermark_snapshot Snapshot file path to unlink (empty to skip).
	 * @param array  $context            Logger context.
	 * @return void
	 */
	private function rollback_album( int $album_id, int $term_id, string $watermark_snapshot, array $context ): void {
		global $wpdb;

		Logger::warning(
			sprintf( 'Upload_Controller::rollback_album — album=%d term=%d.', $album_id, $term_id ),
			$context
		);

		if ( $album_id > 0 ) {
			$albums_table = $wpdb->prefix . 'Photolab_albums';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $albums_table, array( 'id' => $album_id ), array( '%d' ) );
		}

		if ( $term_id > 0 ) {
			$result = wp_delete_term( $term_id, 'product_cat' );
			if ( is_wp_error( $result ) ) {
				Logger::warning(
					sprintf( 'Upload_Controller::rollback_album — wp_delete_term fallito: %s', $result->get_error_message() ),
					$context
				);
			}
		}

		if ( '' !== $watermark_snapshot && file_exists( $watermark_snapshot ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $watermark_snapshot );
		}
	}

	/**
	 * POST /upload/chunk
	 *
	 * Processes one chunk of uploaded files: validates, deduplicates (RC-3),
	 * creates WC product, inserts DB record. Watermarking is enqueued as an
	 * async Action Scheduler job — it is NOT applied inline.
	 *
	 * Headers:
	 * - Idempotency-Key (optional, FASE 5): When provided, the successful
	 *   response is cached for 24 hours. Subsequent requests carrying the
	 *   same key return the cached payload without re-processing files —
	 *   safe retry after network timeouts. The cache is only written after
	 *   the chunk completes; partial failures fall through and are retried.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function chunk( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->chunk_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of POST /upload/chunk. The public {@see self::chunk()} entry point
	 * wraps this method so the request-scoped logger context (FASE 9) is
	 * always cleared, even on exception or early return.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function chunk_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$context = array( 'source' => 'photolab-upload' );

		// Idempotency-Key replay short-circuit (FASE 5).
		$idempotency_key = self::sanitise_idempotency_key( (string) $request->get_header( 'Idempotency-Key' ) );
		if ( '' !== $idempotency_key ) {
			$cached = self::get_idempotent_response( $idempotency_key );
			if ( null !== $cached ) {
				Logger::debug(
					sprintf(
						'Upload_Controller::chunk() — idempotent replay key=%s.',
						self::truncate_key_for_log( $idempotency_key )
					),
					array( 'source' => 'photolab-idempotency' )
				);
				return new \WP_REST_Response( $cached, 200 );
			}
		}

		$job_id  = (int) $request->get_param( 'job_id' );
		$term_id = (int) $request->get_param( 'term_id' );
		$user_id = (int) get_current_user_id();

		Logger::set_context( 'user_id', $user_id );
		Logger::set_context( 'album_id', $job_id );

		Logger::info(
			sprintf( 'Upload_Controller::chunk() — job_id=%d term_id=%d user=%d', $job_id, $term_id, $user_id ),
			$context
		);

		$fsm   = new State_Machine();
		$album = $fsm->get_album( $job_id );

		if ( null === $album ) {
			Logger::error(
				sprintf( 'Upload_Controller::chunk() — job_id=%d non trovato.', $job_id ),
				$context
			);
			return new \WP_Error(
				'photolab_invalid_job',
				__( 'Invalid job ID.', 'todot-photolab' ),
				array( 'status' => 404 )
			);
		}

		// Ownership guard. Legacy rows with user_id NULL are exempt.
		if ( null !== $album->user_id && (int) $album->user_id !== $user_id ) {
			Logger::warning(
				sprintf(
					'Upload_Controller::chunk() — ownership mismatch album_id=%d expected_user_id=%d user_id=%d.',
					$job_id,
					(int) $album->user_id,
					$user_id
				),
				array( 'source' => 'photolab-ownership' )
			);
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to access this album.', 'todot-photolab' ),
				array( 'status' => 403 )
			);
		}

		// Status guard — chunks accepted while the album is `uploading` or
		// `watermarking`. The first chunk flips the album to `watermarking`
		// before scheduling the AS batch; later chunks add more photos while
		// the worker drains earlier batches.
		if (
			State_Machine::ALBUM_UPLOADING !== $album->status &&
			State_Machine::ALBUM_WATERMARKING !== $album->status
		) {
			Logger::warning(
				sprintf(
					'Upload_Controller::chunk() — stato album non valido job=%d status=%s.',
					$job_id,
					$album->status
				),
				$context
			);
			return new \WP_Error(
				'photolab_invalid_state',
				sprintf(
					/* translators: %s = current status */
					__( 'Album in stato "%s", chunk rifiutato.', 'todot-photolab' ),
					$album->status
				),
				array(
					'status'       => 409,
					'album_status' => $album->status,
				)
			);
		}

		// Distributed lock (FASE 8) — opportunistic protection against two
		// `/upload/chunk` requests for the same album landing on different
		// app servers simultaneously. Behaviour:
		// - With external object cache (Redis): atomic SET NX, second
		// caller is rejected with HTTP 423 Locked.
		// - Without external cache: best-effort transient fallback.
		// - Disabled via filter: no-op, identical to pre-FASE-8.
		// Inline watermark (inside process_single_file) handles the photo-level
		// CAS guard, so any false negative here is harmless.
		$lock_key      = 'photolab_chunk_' . $job_id;
		$lock_acquired = Lock::acquire( $lock_key, 60 );
		if ( ! $lock_acquired ) {
			Logger::info(
				sprintf( 'Upload_Controller::chunk() — distributed lock busy album_id=%d (concurrent worker on another node).', $job_id ),
				array( 'source' => 'photolab-lock' )
			);
			return new \WP_Error(
				'album_locked',
				__( 'This album is currently being processed on another server. Please wait a moment and try again.', 'todot-photolab' ),
				array( 'status' => 423 )
			);
		}

		try {
			return $this->chunk_locked( $request, $album, $job_id, $term_id, $idempotency_key, $context );
		} finally {
			Lock::release( $lock_key );
		}
	}

	/**
	 * Body of POST /upload/chunk executed under the distributed lock.
	 *
	 * Extracted so the calling `chunk()` method can wrap the whole pipeline
	 * in a try/finally pair without leaking the lock on early returns or
	 * exceptions. All inputs are validated by the caller — this method
	 * trusts them and focuses on the upload pipeline itself.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request         Original request object.
	 * @param object           $album           Album row already loaded by
	 *                                          the caller.
	 * @param int              $job_id          Album id.
	 * @param int              $term_id         WC product_cat term id.
	 * @param string           $idempotency_key Already-sanitised key, '' when
	 *                                          unused.
	 * @param array            $context         Logger context.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function chunk_locked(
		\WP_REST_Request $request,
		object $album,
		int $job_id,
		int $term_id,
		string $idempotency_key,
		array $context
	): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$album_name      = (string) $album->album_name;
		$expiration_date = $album->expiration_date ?? null;
		$price           = (float) get_option( "photolab_album_{$job_id}_price", 0 );

		// Validate term_id matches the album's stored category term.
		if ( (int) $album->term_id !== $term_id ) {
			Logger::warning(
				sprintf( 'Upload_Controller::chunk_locked — term_id mismatch album=%d expected=%d got=%d.', $job_id, (int) $album->term_id, $term_id ),
				$context
			);
			return new \WP_Error(
				'photolab_term_mismatch',
				__( 'Category ID does not match the album.', 'todot-photolab' ),
				array( 'status' => 400 )
			);
		}

		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// Refresh heartbeat opportunistically — avoids stale aborts mid-batch.
		// Status guard already passed in chunk_inner() (uploading || watermarking).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$albums_table,
			array( 'last_heartbeat' => current_time( 'mysql', true ) ),
			array( 'id' => $job_id ),
			array( '%s' ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['files'] ) ) {
			Logger::error( 'Upload_Controller::chunk() — nessun file ricevuto nel chunk.', $context );
			return new \WP_Error(
				'photolab_no_files',
				__( 'No files received in chunk.', 'todot-photolab' ),
				array( 'status' => 400 )
			);
		}

		// Normalise $_FILES['files'] into an array of individual file arrays.
		$files        = $this->normalise_files( $_FILES['files'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$upload_dir   = wp_upload_dir();
		$base         = trailingslashit( $upload_dir['basedir'] ) . 'Photolab';
		$base_url     = trailingslashit( $upload_dir['baseurl'] ) . 'Photolab';

		$processed = 0;
		$errors    = array();

		$watermark_snapshot = (string) ( $album->watermark_snapshot ?? '' );

		// Ensure per-album originals directory exists.
		$safe_album = sanitize_file_name( $album_name );
		$photos_dir = "{$base}/photos/{$safe_album}";
		wp_mkdir_p( $photos_dir );

		// Disable slow third-party hooks during batch; restore after.
		global $wp_filter;
		// save/restore instead of permanent remove_all_filters.
		$saved_wc_hook = $wp_filter['woocommerce_after_product_object_save'] ?? null;
		remove_all_filters( 'woocommerce_after_product_object_save' );

		foreach ( $files as $file ) {
			// Extend execution time per file and log memory before processing.
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 120 );
			}
			Logger::debug(
				sprintf(
					'Upload_Controller::chunk() — memoria prima elaborazione file "%s": %s',
					sanitize_file_name( $file['name'] ),
					size_format( memory_get_usage( true ) )
				),
				$context
			);

			try {
				$result = $this->process_single_file(
					$file,
					$job_id,
					$album_name,
					$safe_album,
					$term_id,
					$price,
					$expiration_date,
					$photos_dir,
					$base,
					$base_url,
					$photos_table,
					$watermark_snapshot,
					$context
				);
			} catch ( \Throwable $e ) {
				$result = sprintf( 'Eccezione PHP per "%s": %s', sanitize_file_name( $file['name'] ), $e->getMessage() );
				Logger::error( 'Upload_Controller::chunk() — ' . $result, $context );
			}

			if ( is_int( $result ) && $result > 0 ) {
				++$processed;
			} elseif ( 'duplicate' !== $result ) {
				$errors[] = (string) $result;
			}
		}

		// Restore third-party WC hooks removed before the loop.
		if ( null !== $saved_wc_hook ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_filter['woocommerce_after_product_object_save'] = $saved_wc_hook;
		}

		Logger::info(
			sprintf(
				'Upload_Controller::chunk() — completato. Processati: %d, Errori: %d.',
				$processed,
				count( $errors )
			),
			$context
		);

		$response_data = array(
			'processed' => $processed,
			'total'     => count( $files ),
			'errors'    => $errors,
		);

		// Cache the successful response under the idempotency key so a client
		// retry after network timeout returns the same payload without
		// reprocessing the chunk (FASE 5).
		if ( '' !== $idempotency_key ) {
			self::store_idempotent_response( $idempotency_key, $response_data );
			Logger::debug(
				sprintf(
					'Upload_Controller::chunk() — idempotent response cached key=%s.',
					self::truncate_key_for_log( $idempotency_key )
				),
				array( 'source' => 'photolab-idempotency' )
			);
		}

		return new \WP_REST_Response( $response_data, 200 );
	}

	// -------------------------------------------------------------------------
	// Idempotency-Key helpers (FASE 5)
	// -------------------------------------------------------------------------

	/**
	 * Sanitise an Idempotency-Key header value.
	 *
	 * Allows alphanumerics, hyphen and underscore only; trims to 128 chars.
	 * Anything else is rejected (returns ''), so the transient name is always
	 * a safe option key. This is intentionally stricter than RFC 7240 — keys
	 * we cannot trust verbatim become equivalent to "no key supplied".
	 *
	 * @since 2.0.0
	 *
	 * @param string $raw Raw header value.
	 * @return string Sanitised key, or '' when not usable.
	 */
	private static function sanitise_idempotency_key( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z0-9_\-]{1,128}$/', $raw ) ) {
			return '';
		}

		return $raw;
	}

	/**
	 * Read a cached Idempotency-Key response, when present.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key Sanitised idempotency key.
	 * @return array<string, mixed>|null Cached response data, or null.
	 */
	private static function get_idempotent_response( string $key ): ?array {
		$cached = get_transient( 'photolab_idempotent_' . $key );
		if ( ! is_array( $cached ) ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Persist a successful chunk response under the supplied Idempotency-Key.
	 *
	 * TTL is 24 hours — long enough to absorb retries on long-lived upload
	 * sessions, short enough that the option table does not bloat. The
	 * `photolab_idempotent_*` prefix is also recognised by the daily cleanup
	 * scheduler when it runs to evict expired transients.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $key  Sanitised idempotency key.
	 * @param array<string, mixed> $data Response payload to cache.
	 * @return void
	 */
	private static function store_idempotent_response( string $key, array $data ): void {
		set_transient( 'photolab_idempotent_' . $key, $data, DAY_IN_SECONDS );
	}

	/**
	 * Produce a short, log-safe representation of an Idempotency-Key.
	 *
	 * Keys are user-supplied identifiers; they may be UUIDs, ULIDs, or
	 * arbitrary opaque strings. We log only the first 64 chars to keep log
	 * lines bounded and to avoid leaking very long client tokens.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key Sanitised idempotency key.
	 * @return string Truncated representation (max 64 chars).
	 */
	private static function truncate_key_for_log( string $key ): string {
		if ( strlen( $key ) <= 64 ) {
			return $key;
		}

		return substr( $key, 0, 61 ) . '...';
	}

	/**
	 * GET /upload/status
	 *
	 * Returns current upload progress for a given job.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->status_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of GET /upload/status. Wrapped by {@see self::status()} so the
	 * request-scoped logger context is always cleared on return.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function status_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$context = array( 'source' => 'photolab-upload' );
		$user_id = (int) get_current_user_id();

		$job_id = (int) $request->get_param( 'job_id' );

		Logger::set_context( 'user_id', $user_id );
		Logger::set_context( 'album_id', $job_id );

		Logger::debug( sprintf( 'Upload_Controller::status() — job_id=%d user=%d', $job_id, $user_id ), $context );

		$fsm   = new State_Machine();
		$album = $fsm->get_album( $job_id );

		if ( null === $album ) {
			Logger::warning(
				sprintf( 'Upload_Controller::status() — job_id=%d not found.', $job_id ),
				$context
			);
			return new \WP_Error(
				'photolab_not_found',
				__( 'Album not found.', 'todot-photolab' ),
				array( 'status' => 404 )
			);
		}

		// Ownership guard. Legacy rows with user_id NULL are exempt.
		if ( null !== $album->user_id && (int) $album->user_id !== $user_id ) {
			Logger::warning(
				sprintf(
					'Upload_Controller::status() — ownership mismatch album_id=%d expected_user_id=%d user_id=%d.',
					$job_id,
					(int) $album->user_id,
					$user_id
				),
				array( 'source' => 'photolab-ownership' )
			);
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to access this album.', 'todot-photolab' ),
				array( 'status' => 403 )
			);
		}

		$photos_table = $wpdb->prefix . 'Photolab_photos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$processed = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$photos_table} WHERE album_name = %s",
				$album->album_name
			)
		);

		Logger::debug(
			sprintf( 'Upload_Controller::status() — stato=%s, processati=%d.', $album->status, $processed ),
			$context
		);

		return new \WP_REST_Response(
			array(
				'job_id'    => $job_id,
				'status'    => $album->status,
				'processed' => $processed,
				'total'     => null,
			),
			200
		);
	}

	/**
	 * POST /upload/complete
	 *
	 * Sets album status back to idle and removes the watermark snapshot.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function complete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->complete_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of POST /upload/complete. Wrapped by {@see self::complete()} so the
	 * request-scoped logger context is always cleared on return.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function complete_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$context = array( 'source' => 'photolab-upload' );

		$job_id  = (int) $request->get_param( 'job_id' );
		$user_id = (int) get_current_user_id();

		Logger::set_context( 'user_id', $user_id );
		Logger::set_context( 'album_id', $job_id );

		Logger::info( sprintf( 'Upload_Controller::complete() — job_id=%d user=%d', $job_id, $user_id ), $context );

		$fsm   = new State_Machine();
		$album = $fsm->get_album( $job_id );

		if ( null === $album ) {
			Logger::error(
				sprintf( 'Upload_Controller::complete() — job_id=%d non trovato.', $job_id ),
				$context
			);
			return new \WP_Error(
				'photolab_invalid_job',
				__( 'Invalid job ID.', 'todot-photolab' ),
				array( 'status' => 404 )
			);
		}

		if ( null !== $album->user_id && (int) $album->user_id !== $user_id ) {
			Logger::warning(
				sprintf(
					'Upload_Controller::complete() — ownership mismatch album_id=%d expected_user_id=%d user_id=%d.',
					$job_id,
					(int) $album->user_id,
					$user_id
				),
				array( 'source' => 'photolab-ownership' )
			);
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to access this album.', 'todot-photolab' ),
				array( 'status' => 403 )
			);
		}

		$snapshot_path = (string) ( $album->watermark_snapshot ?? '' );

		// CAS uploading → idle. If it fails the album is already in
		// `watermarking` (FASE 3) or was aborted by recovery — both are
		// non-error outcomes from the client's perspective.
		$transitioned = $fsm->transition_album(
			$job_id,
			State_Machine::ALBUM_UPLOADING,
			State_Machine::ALBUM_IDLE,
			array( 'watermark_snapshot' => null )
		);

		if ( $transitioned ) {
			$this->cleanup_snapshot_file( $snapshot_path, $context );
			delete_option( "photolab_album_{$job_id}_price" );

			Logger::info( sprintf( 'Upload_Controller::complete() — OK job_id=%d.', $job_id ), $context );

			return new \WP_REST_Response(
				array(
					'success'      => true,
					'status'       => 'completed',
					'album_status' => State_Machine::ALBUM_IDLE,
				),
				200
			);
		}

		// Refresh status to inform caller of current state.
		$current = $fsm->get_album( $job_id );
		$status  = $current ? (string) $current->status : 'unknown';

		Logger::info(
			sprintf( 'Upload_Controller::complete() — transizione non eseguita, status corrente=%s.', $status ),
			$context
		);

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'status'       => 'noop',
				'album_status' => $status,
				'message'      => sprintf(
					/* translators: %s = current album status */
					__( 'Album already in "%s" state, complete is a no-op.', 'todot-photolab' ),
					$status
				),
			),
			200
		);
	}

	/**
	 * Safely unlink the watermark snapshot file when it lives inside
	 * `wp-content/uploads/Photolab/assets/`. No-op when path is empty,
	 * outside the assets directory, or the file is already gone.
	 *
	 * @since 2.0.0
	 *
	 * @param string $snapshot_path Absolute path to snapshot file.
	 * @param array  $context       Logger context.
	 * @return void
	 */
	private function cleanup_snapshot_file( string $snapshot_path, array $context ): void {
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
				sprintf( 'Upload_Controller::cleanup_snapshot_file — eliminato %s', $snapshot_path ),
				$context
			);
			return;
		}

		Logger::warning(
			sprintf( 'Upload_Controller::cleanup_snapshot_file — path non valido, skip: %s', $snapshot_path ),
			$context
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Process a single uploaded file through the full pipeline:
	 * MIME → SHA256 dedup → wp_handle_upload → WC product → inline watermark.
	 *
	 * @since 2.2.3 Inline watermark added.
	 *
	 * @param array       $file               Single file entry from normalised $_FILES.
	 * @param int         $album_id           Album DB id.
	 * @param string      $album_name         Album display name.
	 * @param string      $safe_album         Sanitized album name for filesystem use.
	 * @param int         $term_id            WC product_cat term ID.
	 * @param float       $price              Product price.
	 * @param string|null $expiration_date    Expiration datetime (Y-m-d H:i:s) or null.
	 * @param string      $photos_dir         Absolute path for original photos.
	 * @param string      $base               Base path for Photolab uploads.
	 * @param string      $base_url           Base URL for Photolab uploads.
	 * @param string      $photos_table       Full DB table name for photos.
	 * @param string      $watermark_snapshot Path to watermark snapshot ('' if none).
	 * @param array       $context            Logger context.
	 * @return int|string                     int = inserted photo_id, 'duplicate' = skipped, string = error message.
	 */
	private function process_single_file(
		array $file,
		int $album_id,
		string $album_name,
		string $safe_album,
		int $term_id,
		float $price,
		?string $expiration_date,
		string $photos_dir,
		string $base,
		string $base_url,
		string $photos_table,
		string $watermark_snapshot,
		array $context
	): int|string {
		global $wpdb;

		$tmp_path  = $file['tmp_name'];
		$orig_name = sanitize_file_name( $file['name'] );

		Logger::debug( sprintf( 'process_single_file() — file: %s', $orig_name ), $context );

		// Step a: MIME validation.
		$filetype = wp_check_filetype_and_ext( $tmp_path, $orig_name );
		if ( empty( $filetype['type'] ) || ! str_starts_with( $filetype['type'], 'image/' ) ) {
			$msg = sprintf( 'Tipo file non valido: %s', $orig_name );
			Logger::error( $msg, $context );
			return $msg;
		}

		// Step b: SHA256 deduplication scoped per album (RC-3).
		// Same file is allowed in different albums — blocked only within the same album.
		$hash = hash_file( 'sha256', $tmp_path );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$photos_table} WHERE file_hash = %s AND album_name = %s",
				$hash,
				$album_name
			)
		);

		if ( null !== $existing_id ) {
			Logger::info( sprintf( 'process_single_file() — duplicato SHA256 in stesso album, skip: %s', $orig_name ), $context );
			return 'duplicate';
		}

		// Step c: move original into photos/<Album>/ via wp_handle_upload.
		$upload_overrides = array(
			'test_form'  => false,
			'upload_dir' => static function () use ( $photos_dir, $base_url, $safe_album ) {
				return array(
					'path'    => $photos_dir,
					'url'     => trailingslashit( $base_url ) . 'photos/' . $safe_album,
					'subdir'  => '',
					'basedir' => $photos_dir,
					'baseurl' => trailingslashit( $base_url ) . 'photos/' . $safe_album,
					'error'   => false,
				);
			},
		);

		add_filter( 'upload_dir', $upload_overrides['upload_dir'] );
		$moved = wp_handle_upload( $file, array( 'test_form' => false ) );
		remove_filter( 'upload_dir', $upload_overrides['upload_dir'] );

		if ( isset( $moved['error'] ) ) {
			Logger::error( sprintf( 'process_single_file() — wp_handle_upload errore: %s', $moved['error'] ), $context );
			return $moved['error'];
		}

		$original_url  = $moved['url'];
		$original_file = $moved['file'];

		// Step d: create the WC product up-front (no featured image yet —
		// Watermark_Job sets the thumbnail once the watermarked copy lands).
		$photo_name = pathinfo( $orig_name, PATHINFO_FILENAME );
		$product    = new \WC_Product_Simple();
		$product->set_name( $photo_name );
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$product->set_downloads(
			array(
				array(
					'name' => $photo_name,
					'file' => $original_url,
					'id'   => 'pl_' . wp_hash( $original_url ),
				),
			)
		);
		$product->set_regular_price( (string) $price );
		$product->set_status( 'publish' );
		$product->set_category_ids( array( $term_id ) );

		$product_id = $product->save();

		if ( is_wp_error( $product_id ) || $product_id <= 0 ) {
			$msg = is_wp_error( $product_id ) ? $product_id->get_error_message() : 'WC_Product::save() ha restituito un ID non valido.';
			Logger::error( sprintf( 'process_single_file() — errore WC product: %s', $msg ), $context );
			return $msg;
		}

		// Step e: insert the photo row in `uploaded` state. Watermark_Job
		// will CAS uploaded → watermarking → watermarked and populate
		// watermark_url + attachment_id atomically per row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$photos_table,
			array(
				'album_id'        => $album_id,
				'album_name'      => $album_name,
				'photo_name'      => $photo_name,
				'photo_price'     => $price,
				'file_url'        => $original_url,
				'file_hash'       => $hash,
				'expiration_date' => $expiration_date,
				'published'       => 1,
				'photo_status'    => State_Machine::PHOTO_UPLOADED,
				'retry_count'     => 0,
				'wc_product_id'   => $product_id,
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%d', '%d' )
		);

		if ( false === $inserted ) {
			Logger::error(
				sprintf( 'process_single_file() — errore INSERT foto "%s": %s', $photo_name, $wpdb->last_error ),
				$context
			);
			// Remove orphan WC product (saved above) to prevent data incosistency.
			wp_delete_post( $product_id, true );
			return sprintf( 'Errore salvataggio DB per "%s".', $photo_name );
		}

		$photo_id = (int) $wpdb->insert_id;

		// Step f: inline watermark — apply, insert into Media Library, attach
		// to product, and advance photo to `watermarked`. Runs right inside the
		// chunk request so there's no AS dependency.
		// CAS guards cover all race conditions; no external queue needed.
		$watermarked_dir = "{$base}/watermarked/{$safe_album}";
		wp_mkdir_p( $watermarked_dir );

		$wm_filename  = wp_unique_filename( $watermarked_dir, wp_basename( $original_file ) );
		$wm_full_path = trailingslashit( $watermarked_dir ) . $wm_filename;

		$fsm = new State_Machine();

		// CAS uploaded → watermarking (idempotent — skips if another worker claimed it).
		if ( $fsm->transition_photo( $photo_id, State_Machine::PHOTO_UPLOADED, State_Machine::PHOTO_WATERMARKING ) ) {
			$apply_result = Watermark_Processor::apply( $original_file, $watermark_snapshot, $wm_full_path, $context );

			if ( true === $apply_result ) {
				$wm_url = trailingslashit( $base_url ) . 'watermarked/' . $safe_album . '/' . $wm_filename;

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
					$attachment_id = 0;
				}

				if ( $product_id > 0 && $attachment_id > 0 ) {
					set_post_thumbnail( $product_id, $attachment_id );
					Watermark_Job::generate_thumbnail_meta( $attachment_id, $wm_full_path );
				}

				// CAS watermarking → watermarked.
				$fsm->transition_photo(
					$photo_id,
					State_Machine::PHOTO_WATERMARKING,
					State_Machine::PHOTO_WATERMARKED,
					array(
						'watermark_url' => $wm_url,
						'attachment_id' => max( 0, (int) $attachment_id ),
					)
				);
			} else {
				$fsm->transition_photo( $photo_id, State_Machine::PHOTO_WATERMARKING, State_Machine::PHOTO_FAILED );
				Logger::error(
					sprintf( 'process_single_file() — watermark fallito: "%s".', $photo_name ),
					$context
				);
				return sprintf( 'Watermark fallito per "%s".', $photo_name );
			}
		}

		Logger::info(
			sprintf(
				'process_single_file() — OK: "%s" → photo_id=%d, product_id=%d.',
				$photo_name,
				$photo_id,
				$product_id
			),
			$context
		);

		return $photo_id;
	}

	/**
	 * Normalise the multi-file $_FILES['files'] structure into a flat array
	 * where each element is a single-file associative array.
	 *
	 * @param array $files_raw Raw $_FILES['files'] value.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalise_files( array $files_raw ): array {
		$normalised = array();

		if ( isset( $files_raw['name'] ) && is_array( $files_raw['name'] ) ) {
			// Multiple-file input: $_FILES['files']['name'][0..N].
			$count = count( $files_raw['name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( UPLOAD_ERR_OK !== $files_raw['error'][ $i ] ) {
					continue;
				}
				$normalised[] = array(
					'name'     => $files_raw['name'][ $i ],
					'type'     => $files_raw['type'][ $i ],
					'tmp_name' => $files_raw['tmp_name'][ $i ],
					'error'    => $files_raw['error'][ $i ],
					'size'     => $files_raw['size'][ $i ],
				);
			}
		} elseif ( isset( $files_raw['name'] ) && is_string( $files_raw['name'] ) ) {
			// Single file.
			if ( UPLOAD_ERR_OK === $files_raw['error'] ) {
				$normalised[] = $files_raw;
			}
		}

		return $normalised;
	}

	// -------------------------------------------------------------------------
	// Argument schemas
	// -------------------------------------------------------------------------

	/**
	 * Arguments for POST /upload/start.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_start_args(): array {
		return array(
			'album_name'      => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && '' !== trim( $value );
				},
				'description'       => 'Nome univoco dell\'album.',
			),
			'price'           => array(
				'required'          => true,
				'type'              => 'number',
				'minimum'           => 0,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'Prezzo unitario delle foto dell\'album.',
			),
			'expiration_date' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					if ( '' === $value || null === $value ) {
						return true;
					}
					return (bool) \DateTime::createFromFormat( 'Y-m-d', $value );
				},
				'description'       => 'Data di scadenza (YYYY-MM-DD) oppure vuota.',
			),
		);
	}

	/**
	 * Arguments for POST /upload/chunk.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_chunk_args(): array {
		return array(
			'job_id'  => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'Job ID (album ID) restituito da /upload/start.',
			),
			'term_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'Term ID della categoria WooCommerce dell\'album.',
			),
		);
	}

	/**
	 * Arguments for GET /upload/status.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_status_args(): array {
		return array(
			'job_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'Job ID da interrogare.',
			),
		);
	}

	/**
	 * Arguments for POST /upload/complete.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_complete_args(): array {
		return array(
			'job_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'Job ID da completare.',
			),
		);
	}
}



// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
