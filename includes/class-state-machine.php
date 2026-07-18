<?php
/**
 * Finite State Machine (FSM) for Photolab albums and photos.
 *
 * Provides Compare-And-Swap (CAS) atomic state transitions backed by SQL
 * `UPDATE ... WHERE id = X AND status = $from` semantics. A transition
 * succeeds only when MySQL reports `affected_rows === 1`; otherwise another
 * process beat us and the caller must surface the conflict (HTTP 409).
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
 * Photolab\State_Machine — atomic state transitions for albums and photos.
 *
 * @since 2.0.0
 */
class State_Machine {

	// -------------------------------------------------------------------------
	// Album states
	// -------------------------------------------------------------------------

	/** Album is at rest and accepting new operations. */
	const ALBUM_IDLE = 'idle';

	/** Upload pipeline is currently writing photos to this album. */
	const ALBUM_UPLOADING = 'uploading';

	/** Watermark pipeline is currently processing photos for this album. */
	const ALBUM_WATERMARKING = 'watermarking';

	/** Delete pipeline is currently removing this album. */
	const ALBUM_DELETING = 'deleting';

	/** Recovery cron flagged this album as abandoned (client crash). */
	const ALBUM_ABORTED = 'aborted';

	// -------------------------------------------------------------------------
	// Photo states
	// -------------------------------------------------------------------------

	/** Original file moved to disk; awaits watermark. */
	const PHOTO_UPLOADED = 'uploaded';

	/** Watermark worker is currently compositing this photo. */
	const PHOTO_WATERMARKING = 'watermarking';

	/** Watermarked file written, WC product ready. */
	const PHOTO_WATERMARKED = 'watermarked';

	/** Watermark or product creation failed; eligible for retry. */
	const PHOTO_FAILED = 'failed';

	/** Tombstone state after deletion (kept for short audit window if needed). */
	const PHOTO_DELETED = 'deleted';

	/**
	 * Allowed transitions per entity.
	 *
	 * Keys are the source state; values are arrays of valid destination states.
	 *
	 * @var array<string, array<string, string[]>>
	 */
	private const ALLOWED_TRANSITIONS = array(
		'album' => array(
			self::ALBUM_IDLE         => array( self::ALBUM_UPLOADING, self::ALBUM_DELETING ),
			self::ALBUM_UPLOADING    => array( self::ALBUM_WATERMARKING, self::ALBUM_IDLE, self::ALBUM_ABORTED ),
			self::ALBUM_WATERMARKING => array( self::ALBUM_IDLE, self::ALBUM_ABORTED ),
			self::ALBUM_ABORTED      => array( self::ALBUM_IDLE, self::ALBUM_DELETING ),
			self::ALBUM_DELETING     => array(),
		),
		'photo' => array(
			self::PHOTO_UPLOADED     => array( self::PHOTO_WATERMARKING, self::PHOTO_FAILED, self::PHOTO_DELETED ),
			self::PHOTO_WATERMARKING => array( self::PHOTO_WATERMARKED, self::PHOTO_FAILED, self::PHOTO_DELETED ),
			self::PHOTO_WATERMARKED  => array( self::PHOTO_DELETED ),
			self::PHOTO_FAILED       => array( self::PHOTO_UPLOADED, self::PHOTO_WATERMARKING, self::PHOTO_DELETED ),
			self::PHOTO_DELETED      => array(),
		),
	);

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Atomically transition an album row from `$from` to `$to`.
	 *
	 * Issues `UPDATE ... SET status = $to[, ...$extra_data] WHERE id = $album_id
	 * AND status = $from`. Returns true only when exactly one row was changed.
	 *
	 * Allowed `$extra_data` keys for albums:
	 *   - upload_started_at, last_heartbeat, aborted_at, watermark_snapshot,
	 *     term_id, expiration_date.
	 * Unknown keys are silently ignored (defensive — they would otherwise be
	 * a SQL injection vector via column names).
	 *
	 * @since 2.0.0
	 *
	 * @param int    $album_id   Album row id (`wp_Photolab_albums.id`).
	 * @param string $from       Expected current status.
	 * @param string $to         Desired new status.
	 * @param array  $extra_data Optional extra columns to update atomically.
	 * @return bool True when affected_rows === 1; false otherwise.
	 */
	public function transition_album( int $album_id, string $from, string $to, array $extra_data = array() ): bool {
		global $wpdb;

		$context = array( 'source' => 'photolab-fsm' );

		if ( $album_id <= 0 ) {
			_doing_it_wrong( __METHOD__, 'album_id must be positive', '2.0.0' );
			return false;
		}

		if ( ! self::is_valid_transition( 'album', $from, $to ) ) {
			Logger::warning(
				sprintf(
					'State_Machine::transition_album — transizione non valida album=%d %s → %s.',
					$album_id,
					$from,
					$to
				),
				$context
			);
			return false;
		}

		$allowed_extra = array(
			'upload_started_at'  => '%s',
			'last_heartbeat'     => '%s',
			'aborted_at'         => '%s',
			'watermark_snapshot' => '%s',
			'term_id'            => '%d',
			'expiration_date'    => '%s',
			'user_id'            => '%d',
		);

		$table = $wpdb->prefix . 'Photolab_albums';

		try {
			[ $sql, $values ] = $this->build_transition_sql(
				$table,
				$album_id,
				$from,
				$to,
				$extra_data,
				$allowed_extra
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			if ( '' !== (string) $wpdb->last_error ) {
				Logger::error(
					sprintf(
						'State_Machine::transition_album — SQL error album=%d %s → %s: %s',
						$album_id,
						$from,
						$to,
						$wpdb->last_error
					),
					$context
				);
				return false;
			}

			$affected = (int) $result;

			if ( 1 === $affected ) {
				Logger::info(
					sprintf(
						'State_Machine::transition_album — OK album=%d %s → %s.',
						$album_id,
						$from,
						$to
					),
					$context
				);
				return true;
			}

			Logger::warning(
				sprintf(
					'State_Machine::transition_album — affected_rows=%d album=%d %s → %s (race or wrong state).',
					$affected,
					$album_id,
					$from,
					$to
				),
				$context
			);
			return false;
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf(
					'State_Machine::transition_album — eccezione album=%d %s → %s: %s',
					$album_id,
					$from,
					$to,
					$e->getMessage()
				),
				$context
			);
			return false;
		}
	}

	/**
	 * Atomically transition a photo row from `$from` to `$to`.
	 *
	 * Allowed `$extra_data` keys for photos: watermark_url, wc_product_id,
	 * attachment_id, retry_count, photo_name, file_url.
	 *
	 * @since 2.0.0
	 *
	 * @param int    $photo_id   Photo row id (`wp_Photolab_photos.id`).
	 * @param string $from       Expected current photo_status.
	 * @param string $to         Desired new photo_status.
	 * @param array  $extra_data Optional extra columns to update atomically.
	 * @return bool True when affected_rows === 1; false otherwise.
	 */
	public function transition_photo( int $photo_id, string $from, string $to, array $extra_data = array() ): bool {
		global $wpdb;

		$context = array( 'source' => 'photolab-fsm' );

		if ( $photo_id <= 0 ) {
			_doing_it_wrong( __METHOD__, 'photo_id must be positive', '2.0.0' );
			return false;
		}

		if ( ! self::is_valid_transition( 'photo', $from, $to ) ) {
			Logger::warning(
				sprintf(
					'State_Machine::transition_photo — transizione non valida photo=%d %s → %s.',
					$photo_id,
					$from,
					$to
				),
				$context
			);
			return false;
		}

		$allowed_extra = array(
			'watermark_url' => '%s',
			'wc_product_id' => '%d',
			'attachment_id' => '%d',
			'retry_count'   => '%d',
			'photo_name'    => '%s',
			'file_url'      => '%s',
		);

		$table = $wpdb->prefix . 'Photolab_photos';

		try {
			[ $sql, $values ] = $this->build_transition_sql(
				$table,
				$photo_id,
				$from,
				$to,
				$extra_data,
				$allowed_extra,
				'photo_status'
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

			if ( '' !== (string) $wpdb->last_error ) {
				Logger::error(
					sprintf(
						'State_Machine::transition_photo — SQL error photo=%d %s → %s: %s',
						$photo_id,
						$from,
						$to,
						$wpdb->last_error
					),
					$context
				);
				return false;
			}

			$affected = (int) $result;

			if ( 1 === $affected ) {
				Logger::info(
					sprintf(
						'State_Machine::transition_photo — OK photo=%d %s → %s.',
						$photo_id,
						$from,
						$to
					),
					$context
				);
				return true;
			}

			Logger::warning(
				sprintf(
					'State_Machine::transition_photo — affected_rows=%d photo=%d %s → %s.',
					$affected,
					$photo_id,
					$from,
					$to
				),
				$context
			);
			return false;
		} catch ( \Throwable $e ) {
			Logger::error(
				sprintf(
					'State_Machine::transition_photo — eccezione photo=%d %s → %s: %s',
					$photo_id,
					$from,
					$to,
					$e->getMessage()
				),
				$context
			);
			return false;
		}
	}

	/**
	 * Validate a transition against the static state graph.
	 *
	 * @since 2.0.0
	 *
	 * @param string $entity Entity type — 'album' or 'photo'.
	 * @param string $from   Source state.
	 * @param string $to     Destination state.
	 * @return bool True when (from → to) is in the entity's transition graph.
	 */
	public static function is_valid_transition( string $entity, string $from, string $to ): bool {
		if ( ! isset( self::ALLOWED_TRANSITIONS[ $entity ] ) ) {
			return false;
		}

		$graph = self::ALLOWED_TRANSITIONS[ $entity ];

		if ( ! isset( $graph[ $from ] ) ) {
			return false;
		}

		return in_array( $to, $graph[ $from ], true );
	}

	/**
	 * Fetch a single album row.
	 *
	 * @since 2.0.0
	 *
	 * @param int $album_id Album row id.
	 * @return object|null Album row as stdClass, or null when not found.
	 */
	public function get_album( int $album_id ): ?object {
		global $wpdb;

		if ( $album_id <= 0 ) {
			return null;
		}

		$table = $wpdb->prefix . 'Photolab_albums';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $album_id )
		);

		if ( '' !== (string) $wpdb->last_error ) {
			Logger::error(
				sprintf( 'State_Machine::get_album — SQL error id=%d: %s', $album_id, $wpdb->last_error ),
				array( 'source' => 'photolab-fsm' )
			);
			return null;
		}

		return $row instanceof \stdClass ? $row : null;
	}

	/**
	 * Fetch a single photo row.
	 *
	 * @since 2.0.0
	 *
	 * @param int $photo_id Photo row id.
	 * @return object|null Photo row as stdClass, or null when not found.
	 */
	public function get_photo( int $photo_id ): ?object {
		global $wpdb;

		if ( $photo_id <= 0 ) {
			return null;
		}

		$table = $wpdb->prefix . 'Photolab_photos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $photo_id )
		);

		if ( '' !== (string) $wpdb->last_error ) {
			Logger::error(
				sprintf( 'State_Machine::get_photo — SQL error id=%d: %s', $photo_id, $wpdb->last_error ),
				array( 'source' => 'photolab-fsm' )
			);
			return null;
		}

		return $row instanceof \stdClass ? $row : null;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the parameterised CAS UPDATE statement for a transition.
	 *
	 * Returns `[ $sql_with_placeholders, $values_array ]` ready to feed into
	 * `$wpdb->prepare( $sql, $values )`. Column names come exclusively from
	 * the caller-supplied whitelist `$allowed_extra` so SQL injection via
	 * column names is impossible.
	 *
	 * @since 2.0.0
	 *
	 * @param string $table          Full table name including prefix.
	 * @param int    $row_id         Primary key value.
	 * @param string $from           Expected current state.
	 * @param string $to             Desired new state.
	 * @param array  $extra_data     Optional additional columns.
	 * @param array  $allowed_extra  Whitelist of column => printf-format pairs.
	 * @param string $status_column  Status column name (default 'status').
	 * @return array{0:string,1:array} SQL + bound values.
	 */
	private function build_transition_sql(
		string $table,
		int $row_id,
		string $from,
		string $to,
		array $extra_data,
		array $allowed_extra,
		string $status_column = 'status'
	): array {
		$set_parts = array( "`{$status_column}` = %s" );
		$values    = array( $to );

		foreach ( $extra_data as $col => $val ) {
			if ( ! isset( $allowed_extra[ $col ] ) ) {
				// Silently skip unknown columns — do not let user data shape SQL.
				continue;
			}

			$placeholder = $allowed_extra[ $col ];

			if ( null === $val ) {
				$set_parts[] = "`{$col}` = NULL";
				continue;
			}

			$set_parts[] = "`{$col}` = {$placeholder}";
			$values[]    = ( '%d' === $placeholder ) ? (int) $val : (string) $val;
		}

		$sql = sprintf(
			"UPDATE `%s` SET %s WHERE id = %%d AND `{$status_column}` = %%s",
			$table,
			implode( ', ', $set_parts )
		);

		$values[] = $row_id;
		$values[] = $from;

		return array( $sql, $values );
	}
}



// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared