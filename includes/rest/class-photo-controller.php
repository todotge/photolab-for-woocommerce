<?php
/**
 * REST controller for photo-related read endpoints.
 *
 * Currently exposes a single endpoint:
 *   GET /photolab/v1/photos/watermark-status?album_id=X
 *
 * which is polled by the admin SPA after `/upload/complete` to surface the
 * progress of the asynchronous Watermark_Job batches.
 *
 * @package Photolab
 * @since   2.0.0
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared


namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photolab\Photo_Controller — read-only photo endpoints.
 *
 * @since 2.0.0
 */
class Photo_Controller extends \WP_REST_Controller {

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
	protected $rest_base = 'photos';

	/**
	 * Register routes.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/watermark-status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_watermark_status' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_watermark_status_args(),
				),
			)
		);
	}

	/**
	 * Permission callback — administrator only.
	 *
	 * @since 2.0.0
	 *
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

	/**
	 * GET /photos/watermark-status
	 *
	 * Returns the aggregate progress of asynchronous watermarking for a given
	 * album. The call is cached for 2 seconds via transient
	 * `photolab_wm_status_{album_id}` to absorb the 2-second admin polling
	 * cadence without hammering the DB.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_watermark_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->get_watermark_status_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of GET /photos/watermark-status. Wrapped so the request-scoped
	 * logger context is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function get_watermark_status_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$context  = array( 'source' => 'photolab-watermark-job' );
		$album_id = (int) $request->get_param( 'album_id' );
		$user_id  = (int) get_current_user_id();

		Logger::set_context( 'user_id', $user_id );
		Logger::set_context( 'album_id', $album_id );

		if ( $album_id <= 0 ) {
			return new \WP_Error(
				'photolab_invalid_album',
				__( 'Invalid album ID.', 'todot-photolab' ),
				array( 'status' => 400 )
			);
		}

		// Ownership check (legacy rows with user_id NULL are exempt).
		$fsm   = new State_Machine();
		$album = $fsm->get_album( $album_id );

		if ( null === $album ) {
			return new \WP_Error(
				'photolab_not_found',
				__( 'Album not found.', 'todot-photolab' ),
				array( 'status' => 404 )
			);
		}

		if ( null !== $album->user_id && (int) $album->user_id !== $user_id ) {
			Logger::warning(
				sprintf(
					'Photo_Controller::get_watermark_status — ownership mismatch album_id=%d expected_user_id=%d user_id=%d.',
					$album_id,
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

		$cache_key = "photolab_wm_status_{$album_id}";
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return new \WP_REST_Response( $cached, 200 );
		}

		$photos_table = $wpdb->prefix . 'Photolab_photos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(CASE WHEN photo_status IN (%s, %s) THEN 1 END) AS pending,
					COUNT(CASE WHEN photo_status = %s             THEN 1 END) AS completed,
					COUNT(CASE WHEN photo_status = %s             THEN 1 END) AS failed
				 FROM `{$photos_table}`
				 WHERE album_id = %d",
				State_Machine::PHOTO_UPLOADED,
				State_Machine::PHOTO_WATERMARKING,
				State_Machine::PHOTO_WATERMARKED,
				State_Machine::PHOTO_FAILED,
				$album_id
			),
			ARRAY_A
		);

		if ( '' !== (string) $wpdb->last_error ) {
			Logger::error(
				sprintf( 'Photo_Controller::get_watermark_status — SQL error album=%d: %s', $album_id, $wpdb->last_error ),
				$context
			);
			return new \WP_Error(
				'photolab_db_error',
				__( 'Errore database.', 'todot-photolab' ),
				array( 'status' => 500 )
			);
		}

		$pending   = (int) ( $row['pending'] ?? 0 );
		$completed = (int) ( $row['completed'] ?? 0 );
		$failed    = (int) ( $row['failed'] ?? 0 );

		$data = array(
			'album_id'  => $album_id,
			'pending'   => $pending,
			'completed' => $completed,
			'failed'    => $failed,
			'total'     => $pending + $completed + $failed,
		);

		set_transient( $cache_key, $data, 2 );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Argument schema for GET /photos/watermark-status.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_watermark_status_args(): array {
		return array(
			'album_id' => array(
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
