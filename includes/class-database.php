<?php
/**
 * Database class: schema install, versioning, migrations.
 *
 * @package Photolab
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared


namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles creation and versioned updates of Photolab database tables.
 */
class Database {

	/**
	 * Current schema version.
	 *
	 * Increment when modifying table schemas to trigger `maybe_update()`.
	 *
	 * @var string
	 */
	const DATABASE_VERSION = '1.4.0';

	/**
	 * WordPress option key that stores the installed DB version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'photolab_db_version';

	/**
	 * Create or upgrade all Photolab tables using dbDelta.
	 *
	 * Idempotent: existing tables are altered to match the schema without data
	 * loss. Missing tables are created from scratch.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function install(): void {
		global $wpdb;

		Logger::info( 'Database::install() — avvio installazione tabelle.', array( 'source' => 'photolab-database' ) );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Table: wp_Photolab_photos.
		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$sql_photos   = "CREATE TABLE {$photos_table} (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			album_id mediumint(9) DEFAULT NULL,
			album_name varchar(255) NOT NULL,
			photo_name varchar(255) NOT NULL,
			photo_price decimal(10,2) NOT NULL,
			file_url varchar(500) NOT NULL,
			watermark_url varchar(500) DEFAULT NULL,
			file_hash varchar(64) NOT NULL,
			expiration_date datetime DEFAULT NULL,
			published tinyint(1) DEFAULT 1,
			photo_status varchar(20) NOT NULL DEFAULT 'uploaded',
			retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
			updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			wc_product_id bigint(20) DEFAULT NULL,
			attachment_id bigint(20) DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY file_hash_album (file_hash, album_name),
			KEY album_name (album_name),
			KEY album_id (album_id),
			KEY photo_status (photo_status),
			KEY published (published)
		) {$charset_collate};";

		// Table: wp_Photolab_albums.
		$albums_table = $wpdb->prefix . 'Photolab_albums';
		$sql_albums   = "CREATE TABLE {$albums_table} (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			album_name varchar(255) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			term_id bigint(20) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'idle',
			watermark_snapshot varchar(500) DEFAULT NULL,
			expiration_date datetime DEFAULT NULL,
			upload_started_at datetime DEFAULT NULL,
			last_heartbeat datetime DEFAULT NULL,
			aborted_at datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY album_name (album_name),
			KEY status (status),
			KEY user_id (user_id),
			KEY status_heartbeat (status, last_heartbeat)
		) {$charset_collate};";

		$results = dbDelta( array( $sql_photos, $sql_albums ) );

		foreach ( $results as $table => $message ) {
			Logger::info(
				sprintf( 'dbDelta — %s: %s', $table, $message ),
				array( 'source' => 'photolab-database' )
			);
		}

		// Migration 1.1.0 — replace single-column UNIQUE KEY file_hash with
		// composite UNIQUE KEY file_hash_album (file_hash, album_name).
		// dbDelta cannot drop existing indexes, so we do it explicitly.
		$this->migrate_1_1_0_file_hash_index( $photos_table );

		// Migration 1.2.0 — FSM (Finite State Machine) columns. Adds heartbeat,
		// recovery, ownership tracking. dbDelta cannot reliably ALTER ENUM, so
		// we widen albums.status to VARCHAR(20) explicitly when needed.
		$this->migrate_1_2_0_fsm( $albums_table, $photos_table );

		// Migration 1.3.0 — `updated_at` on photos for stuck-state detection
		// in the daily cleanup job (FASE 6).
		$this->migrate_1_3_0_photos_updated_at( $photos_table );

		// Migration 1.4.0 — composite indexes for recovery and cleanup queries.
		$this->migrate_1_4_0_photo_indexes( $photos_table );

		// Verify tables exist after dbDelta.
		$this->verify_table( $photos_table );
		$this->verify_table( $albums_table );

		$this->update_version( self::DATABASE_VERSION );

		Logger::info( 'Database::install() — completato.', array( 'source' => 'photolab-database' ) );
	}

	/**
	 * Return the currently installed DB schema version.
	 *
	 * @since 2.0.0
	 * @return string Installed version string, or empty string if not set.
	 */
	public function get_version(): string {
		return (string) get_option( self::VERSION_OPTION, '' );
	}

	/**
	 * Persist the given schema version to the database.
	 *
	 * @since 2.0.0
	 * @param string $version Version string to store.
	 * @return void
	 */
	public function update_version( string $version ): void {
		update_option( self::VERSION_OPTION, $version, false );
	}

	/**
	 * Run install() only when the stored schema version is outdated.
	 *
	 * Called on every `plugins_loaded` to pick up schema changes after plugin
	 * updates without requiring manual re-activation.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function maybe_update(): void {
		$installed = $this->get_version();

		if ( version_compare( $installed, self::DATABASE_VERSION, '<' ) ) {
			Logger::info(
				sprintf(
					'Database::maybe_update() — versione installata %s < richiesta %s. Esecuzione install().',
					$installed ?? 'nessuna',
					self::DATABASE_VERSION
				),
				array( 'source' => 'photolab-database' )
			);

			$this->install();
		}
	}

	/**
	 * Migration 1.1.0 — replace single-column UNIQUE KEY `file_hash` with
	 * composite UNIQUE KEY `file_hash_album` (file_hash, album_name).
	 *
	 * Allows the same photo (same SHA256) to exist in multiple albums while
	 * still blocking duplicates within the same album (RC-3).
	 *
	 * dbDelta cannot remove existing indexes, so this runs explicitly.
	 * Both ALTER TABLE statements are idempotent: DROP IF EXISTS + ADD only
	 * when the target index is absent.
	 *
	 * @param string $table Full table name including prefix.
	 * @return void
	 */
	private function migrate_1_1_0_file_hash_index( string $table ): void {
		global $wpdb;

		$context = array( 'source' => 'photolab-database' );

		// Check whether the old single-column index still exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$old_index = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE table_schema = DATABASE()
				   AND table_name   = %s
				   AND index_name   = 'file_hash'",
				$table
			)
		);

		if ( $old_index > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `file_hash`" );

			if ( $wpdb->last_error ) {
				Logger::error(
					sprintf( 'Database::migrate_1_1_0 — DROP INDEX file_hash fallito: %s', $wpdb->last_error ),
					$context
				);
				return;
			}

			Logger::info( 'Database::migrate_1_1_0 — DROP INDEX file_hash completato.', $context );
		}

		// Check whether the new composite index already exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$new_index = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE table_schema = DATABASE()
				   AND table_name   = %s
				   AND index_name   = 'file_hash_album'",
				$table
			)
		);

		if ( 0 === (int) $new_index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `file_hash_album` (`file_hash`, `album_name`)" );

			if ( $wpdb->last_error ) {
				Logger::error(
					sprintf( 'Database::migrate_1_1_0 — ADD UNIQUE KEY file_hash_album fallito: %s', $wpdb->last_error ),
					$context
				);
				return;
			}

			Logger::info( 'Database::migrate_1_1_0 — ADD UNIQUE KEY file_hash_album completato.', $context );
		} else {
			Logger::info( 'Database::migrate_1_1_0 — UNIQUE KEY file_hash_album già presente, skip.', $context );
		}
	}

	/**
	 * Migration 1.2.0 — Finite State Machine columns.
	 *
	 * Widens `albums.status` from ENUM to VARCHAR(20) so the new states
	 * `watermarking` and `aborted` fit without ALTER-ENUM problems on shared
	 * hosts. Adds ownership/heartbeat/recovery columns on both tables.
	 *
	 * Idempotent: every column/index addition checks information_schema first.
	 *
	 * @since 2.0.0
	 *
	 * @param string $albums_table Full albums table name.
	 * @param string $photos_table Full photos table name.
	 * @return void
	 */
	private function migrate_1_2_0_fsm( string $albums_table, string $photos_table ): void {
		global $wpdb;

		$context = array( 'source' => 'photolab-database' );

		// 1) Widen albums.status to VARCHAR(20) (was ENUM).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$status_type = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_TYPE FROM information_schema.COLUMNS
				 WHERE table_schema = DATABASE()
				   AND table_name   = %s
				   AND column_name  = 'status'",
				$albums_table
			)
		);

		if ( $status_type && stripos( (string) $status_type, 'enum' ) === 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$albums_table}` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'idle'" );

			if ( $wpdb->last_error ) {
				Logger::error(
					sprintf( 'Database::migrate_1_2_0 — MODIFY status fallito: %s', $wpdb->last_error ),
					$context
				);
			} else {
				Logger::info( 'Database::migrate_1_2_0 — albums.status widened to VARCHAR(20).', $context );
			}
		}

		// 2) Add new albums columns when missing.
		$album_cols = array(
			'user_id'           => 'BIGINT(20) UNSIGNED DEFAULT NULL',
			'upload_started_at' => 'DATETIME DEFAULT NULL',
			'last_heartbeat'    => 'DATETIME DEFAULT NULL',
			'aborted_at'        => 'DATETIME DEFAULT NULL',
		);

		foreach ( $album_cols as $col => $ddl ) {
			$this->add_column_if_missing( $albums_table, $col, $ddl, $context );
		}

		// 3) Add KEY user_id, KEY status_heartbeat when missing.
		$this->add_index_if_missing( $albums_table, 'user_id', '`user_id`', $context );
		$this->add_index_if_missing( $albums_table, 'status_heartbeat', '`status`, `last_heartbeat`', $context );

		// 4) Add new photos columns when missing.
		$photo_cols = array(
			'album_id'     => 'MEDIUMINT(9) DEFAULT NULL',
			'photo_status' => "VARCHAR(20) NOT NULL DEFAULT 'uploaded'",
			'retry_count'  => 'TINYINT(3) UNSIGNED NOT NULL DEFAULT 0',
		);

		foreach ( $photo_cols as $col => $ddl ) {
			$this->add_column_if_missing( $photos_table, $col, $ddl, $context );
		}

		// 5) Indexes on photos.
		$this->add_index_if_missing( $photos_table, 'album_id', '`album_id`', $context );
		$this->add_index_if_missing( $photos_table, 'photo_status', '`photo_status`', $context );

		// 6) Backfill photos.album_id from album_name when missing (legacy rows).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE `{$photos_table}` p
			 INNER JOIN `{$albums_table}` a ON a.album_name = p.album_name
			 SET p.album_id = a.id
			 WHERE p.album_id IS NULL"
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'Database::migrate_1_2_0 — backfill album_id fallito: %s', $wpdb->last_error ),
				$context
			);
		} else {
			Logger::info(
				sprintf( 'Database::migrate_1_2_0 — backfill album_id: %d righe aggiornate.', (int) $wpdb->rows_affected ),
				$context
			);
		}

		// 7) Backfill photos.photo_status='watermarked' for legacy rows that
		// already have a watermark_url (those existed before FSM).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE `{$photos_table}`
			 SET photo_status = 'watermarked'
			 WHERE photo_status = 'uploaded'
			   AND watermark_url IS NOT NULL
			   AND watermark_url <> ''"
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'Database::migrate_1_2_0 — backfill photo_status fallito: %s', $wpdb->last_error ),
				$context
			);
		} else {
			Logger::info(
				sprintf( 'Database::migrate_1_2_0 — backfill photo_status: %d righe.', (int) $wpdb->rows_affected ),
				$context
			);
		}
	}

	/**
	 * Migration 1.3.0 — add `updated_at` column on photos.
	 *
	 * The daily cleanup handler (`Cleanup_Scheduler::run_daily_cleanup()`) needs
	 * a row-level last-update timestamp to detect photos stuck in the
	 * `watermarking` state. MySQL keeps it fresh automatically via
	 * `ON UPDATE CURRENT_TIMESTAMP`, so application code does not have to set
	 * it on every CAS.
	 *
	 * Idempotent: skips when the column already exists.
	 *
	 * @since 2.0.0
	 *
	 * @param string $photos_table Full photos table name.
	 * @return void
	 */
	private function migrate_1_3_0_photos_updated_at( string $photos_table ): void {
		$context = array( 'source' => 'photolab-database' );

		$this->add_column_if_missing(
			$photos_table,
			'updated_at',
			'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
			$context
		);
	}

	/**
	 * Migration 1.4.0 — composite indexes for performance.
	 *
	 * Adds (album_id, photo_status) for album-settlement queries in
	 * Watermark_Job::maybe_finalise_album() and (published, expiration_date)
	 * for the daily cleanup sweep. Both are covering indexes that avoid
	 * table scans on large photo tables.
	 *
	 * @since 2.1.7
	 *
	 * @param string $photos_table Full photos table name.
	 * @return void
	 */
	private function migrate_1_4_0_photo_indexes( string $photos_table ): void {
		$context = array( 'source' => 'photolab-database' );

		$this->add_index_if_missing( $photos_table, 'album_id_photo_status', '`album_id`, `photo_status`', $context );
		$this->add_index_if_missing( $photos_table, 'published_expiration', '`published`, `expiration_date`', $context );

		Logger::info( 'Database::migrate_1_4_0 — composite indexes aggiunti.', $context );
	}

	/**
	 * Add a column to a table only when it doesn't already exist.
	 *
	 * @since 2.0.0
	 *
	 * @param string $table   Full table name.
	 * @param string $column  Column name.
	 * @param string $ddl     Column DDL fragment (type + default).
	 * @param array  $context Logger context.
	 * @return void
	 */
	private function add_column_if_missing( string $table, string $column, string $ddl, array $context ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE table_schema = DATABASE()
				   AND table_name   = %s
				   AND column_name  = %s',
				$table,
				$column
			)
		);

		if ( (int) $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$ddl}" );

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'Database::add_column_if_missing — %s.%s fallito: %s', $table, $column, $wpdb->last_error ),
				$context
			);
			return;
		}

		Logger::info(
			sprintf( 'Database::add_column_if_missing — %s.%s aggiunta.', $table, $column ),
			$context
		);
	}

	/**
	 * Add a non-unique KEY index on a table when missing.
	 *
	 * @since 2.0.0
	 *
	 * @param string $table     Full table name.
	 * @param string $index     Index name.
	 * @param string $columns   Backtick-quoted comma list of indexed columns.
	 * @param array  $context   Logger context.
	 * @return void
	 */
	private function add_index_if_missing( string $table, string $index, string $columns, array $context ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE table_schema = DATABASE()
				   AND table_name   = %s
				   AND index_name   = %s',
				$table,
				$index
			)
		);

		if ( (int) $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `{$index}` ({$columns})" );

		if ( $wpdb->last_error ) {
			Logger::error(
				sprintf( 'Database::add_index_if_missing — %s.%s fallito: %s', $table, $index, $wpdb->last_error ),
				$context
			);
			return;
		}

		Logger::info(
			sprintf( 'Database::add_index_if_missing — %s.%s aggiunto.', $table, $index ),
			$context
		);
	}

	/**
	 * Log an error and add an admin notice when a table is absent after dbDelta.
	 *
	 * @param string $table Full table name including prefix.
	 * @return void
	 */
	private function verify_table( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $exists !== $table ) {
			$message = sprintf( 'Tabella %s non trovata dopo dbDelta. Verifica i permessi DB.', $table );

			Logger::error(
				'Database::verify_table() — ' . $message,
				array( 'source' => 'photolab-database' )
			);

			Admin_Notices::add(
				'table-' . sanitize_key( $table ),
				$message,
				'error'
			);
		} else {
			Logger::info(
				sprintf( 'Database::verify_table() — tabella %s verificata.', $table ),
				array( 'source' => 'photolab-database' )
			);
		}
	}
}



// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared