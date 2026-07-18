<?php
/**
 * REST controller for album endpoints.
 *
 * Handles GET /albums (paginated list) and DELETE /albums/{id}.
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
 * Album REST controller.
 *
 * @extends \WP_REST_Controller
 */
class Album_Controller extends \WP_REST_Controller {

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
	protected $rest_base = 'albums';

	/**
	 * Register routes.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_routes(): void {
		// GET /albums.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_collection_args(),
				),
			)
		);

		// DELETE /albums/{id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_item_args(),
				),
			)
		);

		// POST /albums/{id}/reset — recover an aborted album.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reset',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_item_args(),
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
				__( 'Access denied.', 'photolab' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * GET /albums
	 *
	 * Returns a paginated list of albums from wp_Photolab_albums, with the
	 * photo count for each album joined from wp_Photolab_photos.
	 *
	 * @since 2.0.0
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->get_items_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of GET /albums. Wrapped by {@see self::get_items()} so the
	 * request-scoped logger context is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function get_items_inner( $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$context = array( 'source' => 'photolab-albums' );

		Logger::set_context( 'user_id', (int) get_current_user_id() );

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		Logger::info(
			sprintf( 'Album_Controller::get_items() — page: %d, per_page: %d', $page, $per_page ),
			$context
		);

		$albums_table = $wpdb->prefix . 'Photolab_albums';
		$photos_table = $wpdb->prefix . 'Photolab_photos';

		// Total count for pagination.
		// Table name is safe: built from $wpdb->prefix + hardcoded suffix. phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$albums_table}`" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				'Album_Controller::get_items() — errore COUNT(*) DB: ' . $wpdb->last_error,
				$context
			);
			return new \WP_Error(
				'photolab_db_error',
				__( 'Database error while counting albums.', 'photolab' ),
				array( 'status' => 500 )
			);
		}

		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;

		// Paginated albums with photo count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					a.id,
					a.album_name,
					a.status,
					a.expiration_date,
					a.created_at,
					COUNT(p.id) AS photo_count
				FROM {$albums_table} a
				LEFT JOIN {$photos_table} p ON p.album_id = a.id OR (p.album_id IS NULL AND p.album_name = a.album_name)
				GROUP BY a.id
				ORDER BY a.created_at DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		if ( $wpdb->last_error ) {
			Logger::error(
				'Album_Controller::get_items() — errore query DB: ' . $wpdb->last_error,
				$context
			);
			return new \WP_Error(
				'photolab_db_error',
				__( 'Database error while retrieving albums.', 'photolab' ),
				array( 'status' => 500 )
			);
		}

		$albums = array_map(
			static function ( array $row ): array {
				return array(
					'id'              => (int) $row['id'],
					'album_name'      => $row['album_name'],
					'photo_count'     => (int) $row['photo_count'],
					'expiration_date' => $row['expiration_date'] ? substr( $row['expiration_date'], 0, 10 ) : null,
					'status'          => $row['status'],
				);
			},
			$rows ?? array()
		);

		Logger::info(
			sprintf(
				'Album_Controller::get_items() — completato. Trovati %d album (totale: %d).',
				count( $albums ),
				$total
			),
			$context
		);

		return new \WP_REST_Response(
			array(
				'albums'      => $albums,
				'total'       => $total,
				'total_pages' => $total_pages,
			),
			200
		);
	}

	/**
	 * DELETE /albums/{id}
	 *
	 * Deletes an album and all associated photos, WC products, Media Library
	 * attachments, and physical files from disk. Only allowed when status = idle (RC-4).
	 *
	 * @since 2.0.0
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->delete_item_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of DELETE /albums/{id}. Wrapped by {@see self::delete_item()} so the
	 * request-scoped logger context is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function delete_item_inner( $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$context = array( 'source' => 'photolab-albums' );
		$user_id = (int) get_current_user_id();

		$id = (int) $request->get_param( 'id' );

		Logger::set_context( 'user_id', $user_id );
		Logger::set_context( 'album_id', $id );

		Logger::info(
			sprintf( 'Album_Controller::delete_item() — id=%d user=%d', $id, $user_id ),
			$context
		);

		$albums_table = $wpdb->prefix . 'Photolab_albums';
		$photos_table = $wpdb->prefix . 'Photolab_photos';

		$fsm   = new State_Machine();
		$album = $fsm->get_album( $id );

		if ( null === $album ) {
			Logger::warning(
				sprintf( 'Album_Controller::delete_item() — album id %d non trovato.', $id ),
				$context
			);
			return new \WP_Error(
				'photolab_not_found',
				__( 'Album not found.', 'photolab' ),
				array( 'status' => 404 )
			);
		}

		// Ownership guard — only the owner can delete (legacy rows with
		// user_id NULL are exempt because they predate the ownership column).
		if ( null !== $album->user_id && (int) $album->user_id !== $user_id ) {
			Logger::warning(
				sprintf(
					'Album_Controller::delete_item() — ownership mismatch album_id=%d expected_user_id=%d user_id=%d.',
					$id,
					(int) $album->user_id,
					$user_id
				),
				array( 'source' => 'photolab-ownership' )
			);
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to access this album.', 'photolab' ),
				array( 'status' => 403 )
			);
		}

		// CAS idle → deleting (preferred path).
		$transitioned = $fsm->transition_album(
			$id,
			State_Machine::ALBUM_IDLE,
			State_Machine::ALBUM_DELETING
		);

		// Fallback: aborted → deleting (recovered albums must be removable).
		if ( ! $transitioned ) {
			$transitioned = $fsm->transition_album(
				$id,
				State_Machine::ALBUM_ABORTED,
				State_Machine::ALBUM_DELETING
			);
			if ( $transitioned ) {
				Logger::info(
					sprintf( 'Album_Controller::delete_item() — aborted album removed album_id=%d.', $id ),
					$context
				);
			}
		}

		if ( ! $transitioned ) {
			$current = $fsm->get_album( $id );
			$status  = $current ? (string) $current->status : 'unknown';

			Logger::warning(
				sprintf( 'Album_Controller::delete_item() — CAS fallita id=%d status=%s.', $id, $status ),
				$context
			);

			return new \WP_Error(
				'photolab_conflict',
				sprintf(
					/* translators: %s = current album status */
					__( 'Album in stato "%s", impossibile eliminare adesso.', 'photolab' ),
					$status
				),
				array(
					'status'       => 409,
					'album_status' => $status,
				)
			);
		}

		$album_name       = (string) $album->album_name;
		$deleted_photos   = 0;
		$deleted_products = 0;

		// ponytail: process photos in batches to avoid timeout on 2000+ albums.
		$offset = 0;
		$limit  = 100;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$photos = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, attachment_id, wc_product_id FROM {$photos_table}
					 WHERE ( album_id = %d ) OR ( album_id IS NULL AND album_name = %s )
					 LIMIT %d OFFSET %d",
					$id,
					$album_name,
					$limit,
					$offset
				),
				ARRAY_A
			);

			if ( empty( $photos ) ) {
				break;
			}

			foreach ( $photos as $photo ) {
				// Extend execution time per photo — album deletes can span 2000+
				// rows and each wp_delete_attachment()/wp_delete_post() call does
				// its own internal queries; without this the request can hit
				// max_execution_time mid-loop, stranding the album in 'deleting'
				// (no FSM exit transition and no recovery cron cover that state).
				if ( function_exists( 'set_time_limit' ) ) {
					set_time_limit( 120 );
				}

				$attachment_id = (int) $photo['attachment_id'];
				$product_id    = (int) $photo['wc_product_id'];

				if ( $attachment_id > 0 ) {
					$result = wp_delete_attachment( $attachment_id, true );
					if ( false === $result || null === $result ) {
						Logger::warning(
							sprintf( 'Album_Controller::delete_item() — attachment %d non eliminato (potrebbe non esistere).', $attachment_id ),
							$context
						);
					}
				}

				if ( $product_id > 0 ) {
					$result = wp_delete_post( $product_id, true );
					if ( false === $result || null === $result ) {
						Logger::warning(
							sprintf( 'Album_Controller::delete_item() — prodotto WC %d non eliminato (potrebbe non esistere).', $product_id ),
							$context
						);
					} else {
						++$deleted_products;
					}
				}

				++$deleted_photos;
			}

			$photos_count = count( $photos );
			$offset      += $limit;
		} while ( $photos_count === $limit );

		Logger::info(
			sprintf(
				'Album_Controller::delete_item() — eliminati %d prodotti WC e %d attachment.',
				$deleted_products,
				$deleted_photos
			),
			$context
		);

		// Delete physical files.
		$this->delete_album_files( $album_name, $context );

		// Delete WooCommerce product_cat term.
		$term_id = (int) ( $album->term_id ?? 0 );
		if ( $term_id > 0 ) {
			$term_result = wp_delete_term( $term_id, 'product_cat' );
			if ( is_wp_error( $term_result ) ) {
				Logger::warning(
					sprintf(
						'Album_Controller::delete_item() — impossibile eliminare term %d: %s',
						$term_id,
						$term_result->get_error_message()
					),
					$context
				);
			} else {
				Logger::info(
					sprintf( 'Album_Controller::delete_item() — term product_cat %d eliminato.', $term_id ),
					$context
				);
			}
		}

		// Atomic transaction: DELETE must succeed on both tables or none.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$photos_ok = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$photos_table} WHERE ( album_id = %d ) OR ( album_id IS NULL AND album_name = %s )",
				$id,
				$album_name
			)
		);

		if ( false === $photos_ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );
			Logger::error(
				sprintf( 'Album_Controller::delete_item() — DELETE photos fallito: %s', $wpdb->last_error ),
				$context
			);
			return new \WP_Error(
				'photolab_db_error',
				__( 'Database error while deleting photos.', 'photolab' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$albums_ok = $wpdb->delete( $albums_table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $albums_ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );
			Logger::error(
				sprintf( 'Album_Controller::delete_item() — DELETE album fallito: %s', $wpdb->last_error ),
				$context
			);
			return new \WP_Error(
				'photolab_db_error',
				__( 'Database error while deleting album.', 'photolab' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );

		Logger::info(
			sprintf( 'Album_Controller::delete_item() — completato per album "%s" (id: %d).', $album_name, $id ),
			$context
		);

		return new \WP_REST_Response(
			array(
				'success'          => true,
				'deleted_photos'   => $deleted_photos,
				'deleted_products' => $deleted_products,
			),
			200
		);
	}

	/**
	 * POST /albums/{id}/reset
	 *
	 * Returns an `aborted` album back to `idle` so the user can retry the
	 * upload. CAS-guarded: noop if the album moved on (HTTP 409).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reset_item( $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->reset_item_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of POST /albums/{id}/reset. Wrapped by {@see self::reset_item()} so
	 * the request-scoped logger context is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function reset_item_inner( $request ): \WP_REST_Response|\WP_Error {
		$context = array( 'source' => 'photolab-fsm' );
		$user_id = (int) get_current_user_id();

		$id = (int) $request->get_param( 'id' );

		Logger::set_context( 'user_id', $user_id );
		Logger::set_context( 'album_id', $id );

		Logger::info(
			sprintf( 'Album_Controller::reset_item() — id=%d user=%d', $id, $user_id ),
			$context
		);

		$fsm   = new State_Machine();
		$album = $fsm->get_album( $id );

		if ( null === $album ) {
			Logger::warning(
				sprintf( 'Album_Controller::reset_item() — album id %d non trovato.', $id ),
				$context
			);
			return new \WP_Error(
				'photolab_not_found',
				__( 'Album not found.', 'photolab' ),
				array( 'status' => 404 )
			);
		}

		if ( null !== $album->user_id && (int) $album->user_id !== $user_id ) {
			Logger::warning(
				sprintf(
					'Album_Controller::reset_item() — ownership mismatch album_id=%d expected_user_id=%d user_id=%d.',
					$id,
					(int) $album->user_id,
					$user_id
				),
				array( 'source' => 'photolab-ownership' )
			);
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to access this album.', 'photolab' ),
				array( 'status' => 403 )
			);
		}

		$previous_status = (string) $album->status;

		$transitioned = $fsm->transition_album(
			$id,
			State_Machine::ALBUM_ABORTED,
			State_Machine::ALBUM_IDLE,
			array( 'aborted_at' => null )
		);

		// ponytail: also allow reset from watermarking (stuck mid-batch).
		if ( ! $transitioned ) {
			$transitioned = $fsm->transition_album(
				$id,
				State_Machine::ALBUM_WATERMARKING,
				State_Machine::ALBUM_IDLE,
				array( 'aborted_at' => null )
			);
		}

		if ( ! $transitioned ) {
			$current = $fsm->get_album( $id );
			$status  = $current ? (string) $current->status : 'unknown';

			Logger::warning(
				sprintf( 'Album_Controller::reset_item() — CAS aborted→idle failed album_id=%d status=%s.', $id, $status ),
				$context
			);

			return new \WP_Error(
				'photolab_conflict',
				sprintf(
					/* translators: %s = current album status */
					__( 'Album is in "%s" state, reset is not applicable.', 'photolab' ),
					$status
				),
				array(
					'status'       => 409,
					'album_status' => $status,
				)
			);
		}

		// When resetting from watermarking, delete any photos that were
		// never watermarked to avoid orphan WC products without images.
		if ( State_Machine::ALBUM_WATERMARKING === $previous_status ) {
			$this->cleanup_unwatermarked_photos( $id, (string) $album->album_name, $context );
		}

		Logger::info(
			sprintf(
				'Album_Controller::reset_item() — OK album_id=%d previous_status=%s new_status=%s.',
				$id,
				$previous_status,
				State_Machine::ALBUM_IDLE
			),
			$context
		);

		return new \WP_REST_Response(
			array(
				'ok'         => true,
				'new_status' => State_Machine::ALBUM_IDLE,
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Delete all physical files and directories for an album.
	 *
	 * Errors on individual files are logged as warnings; the process continues.
	 *
	 * @param string $album_name Album name (directory name on disk).
	 * @param array  $context    Logger context.
	 * @return void
	 */
	private function delete_album_files( string $album_name, array $context ): void {
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] ) . 'Photolab';
		$safe_name  = sanitize_file_name( $album_name );

		$dirs = array(
			trailingslashit( $base ) . 'photos/' . $safe_name,
			trailingslashit( $base ) . 'watermarked/' . $safe_name,
		);

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				Logger::info(
					sprintf( 'Album_Controller::delete_album_files() — directory non trovata, skip: %s', $dir ),
					$context
				);
				continue;
			}

			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $files as $file ) {
				$file_path = $file->getRealPath();

				if ( $file->isDir() ) {
					if ( ! rmdir( $file_path ) ) {
						Logger::warning(
							sprintf( 'Album_Controller::delete_album_files() — impossibile rimuovere dir: %s', $file_path ),
							$context
						);
					}
				} elseif ( ! unlink( $file_path ) ) {
						Logger::warning(
							sprintf( 'Album_Controller::delete_album_files() — impossibile eliminare file: %s', $file_path ),
							$context
						);
				}
			}

			// Remove the now-empty album directory.
			if ( ! rmdir( $dir ) ) {
				Logger::warning(
					sprintf( 'Album_Controller::delete_album_files() — impossibile rimuovere dir principale: %s', $dir ),
					$context
				);
			} else {
				Logger::info(
					sprintf( 'Album_Controller::delete_album_files() — directory eliminata: %s', $dir ),
					$context
				);
			}
		}
	}

	/**
	 * Delete WC products and photo rows for all non-watermarked album photos.
	 *
	 * Called when resetting from `watermarking` to `idle` to prevent orphan
	 * WC products that were created by chunks but never watermarked.
	 *
	 * @since 2.2.0
	 *
	 * @param int    $album_id   Album DB id.
	 * @param string $album_name Album name for fallback query.
	 * @param array  $context    Logger context.
	 * @return void
	 */
	private function cleanup_unwatermarked_photos( int $album_id, string $album_name, array $context ): void {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphans = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wc_product_id FROM {$photos_table}
				 WHERE album_id = %d
				   AND photo_status != %s",
				$album_id,
				State_Machine::PHOTO_WATERMARKED
			)
		);

		if ( $wpdb->last_error ) {
			Logger::error( sprintf( 'cleanup_unwatermarked_photos — SQL error: %s', $wpdb->last_error ), $context );
			return;
		}

		if ( empty( $orphans ) ) {
			return;
		}

		foreach ( $orphans as $row ) {
			$product_id = (int) $row->wc_product_id;
			if ( $product_id > 0 ) {
				wp_delete_post( $product_id, true );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $photos_table, array( 'id' => (int) $row->id ), array( '%d' ) );
		}

		Logger::info(
			sprintf( 'cleanup_unwatermarked_photos — album=%d eliminati %d foto non watermarked.', $album_id, count( $orphans ) ),
			$context
		);
	}

	// -------------------------------------------------------------------------
	// Argument schemas
	// -------------------------------------------------------------------------

	/**
	 * Arguments for GET /albums.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_collection_args(): array {
		return array(
			'page'     => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'Numero di pagina (1-based).',
			),
			'per_page' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'Album per pagina.',
			),
		);
	}

	/**
	 * Arguments for DELETE /albums/{id}.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_item_args(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'ID album in wp_Photolab_albums.',
			),
		);
	}
}


// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
