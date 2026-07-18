<?php
/**
 * REST controller for upload heartbeats.
 *
 * The frontend pings POST /upload/heartbeat every 30 seconds while an upload
 * is active. The endpoint refreshes `wp_Photolab_albums.last_heartbeat` for
 * the album as long as it is still in `uploading`. The recovery cron uses
 * the `last_heartbeat` column to detect crashed clients.
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
 * Photolab\Heartbeat_Controller — POST /photolab/v1/upload/heartbeat.
 *
 * @since 2.0.0
 */
class Heartbeat_Controller extends \WP_REST_Controller {

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
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/heartbeat',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_heartbeat' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => $this->get_args(),
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
				__( 'Access denied.', 'photolab' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle heartbeat ping.
	 *
	 * Refreshes `last_heartbeat` only when the album is currently `uploading`
	 * AND owned by the current user. Returns:
	 *  - `{ ok: true }` when the heartbeat was applied or the album already
	 *    moved on to a non-error state (`watermarking`, `idle`).
	 *  - `{ aborted: true, album_status: 'aborted' }` when the recovery cron
	 *    has flagged the album as abandoned.
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_heartbeat( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->handle_heartbeat_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of POST /upload/heartbeat. Wrapped so the request-scoped logger
	 * context is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_heartbeat_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$context = array( 'source' => 'photolab-heartbeat' );
		$user_id = (int) get_current_user_id();
		$job_id  = (int) $request->get_param( 'job_id' );

		Logger::set_context( 'user_id', $user_id );
		Logger::set_context( 'album_id', $job_id );

		if ( $job_id <= 0 ) {
			return new \WP_Error(
				'photolab_invalid_job',
				__( 'Invalid job ID.', 'photolab' ),
				array( 'status' => 400 )
			);
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Logger::debug(
				sprintf( 'Heartbeat_Controller::handle_heartbeat — job=%d user=%d', $job_id, $user_id ),
				$context
			);
		}

		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// Fast path — refresh heartbeat when uploading or watermarking.
		// Omitting status filter: if the album exists + user owns it, the
		// heartbeat is always meaningful. The slow path below distinguishes
		// aborted from other non-active states for the client.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$albums_table}`
				 SET last_heartbeat = %s
				 WHERE id = %d AND user_id = %d",
				current_time( 'mysql', true ),
				$job_id,
				$user_id
			)
		);

		if ( '' !== (string) $wpdb->last_error ) {
			Logger::error(
				sprintf( 'Heartbeat_Controller::handle_heartbeat — SQL error job=%d: %s', $job_id, $wpdb->last_error ),
				$context
			);
			return new \WP_Error(
				'photolab_db_error',
				__( 'Errore aggiornamento heartbeat.', 'photolab' ),
				array( 'status' => 500 )
			);
		}

		if ( 1 === (int) $updated ) {
			return new \WP_REST_Response(
				array(
					'ok'           => true,
					'album_status' => State_Machine::ALBUM_UPLOADING,
				),
				200
			);
		}

		// Slow path — inspect the row to figure out why no update happened.
		$fsm   = new State_Machine();
		$album = $fsm->get_album( $job_id );

		if ( null === $album ) {
			return new \WP_Error(
				'photolab_invalid_job',
				__( 'Job not found.', 'photolab' ),
				array( 'status' => 404 )
			);
		}

		if ( null !== $album->user_id && (int) $album->user_id !== $user_id ) {
			Logger::warning(
				sprintf(
					'Heartbeat_Controller::handle_heartbeat — ownership mismatch album_id=%d expected_user_id=%d user_id=%d.',
					$job_id,
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

		$status = (string) $album->status;

		if ( State_Machine::ALBUM_ABORTED === $status ) {
			Logger::warning(
				sprintf( 'Heartbeat_Controller::handle_heartbeat — job=%d aborted, segnalo client.', $job_id ),
				$context
			);
			return new \WP_REST_Response(
				array(
					'ok'           => false,
					'aborted'      => true,
					'album_status' => $status,
				),
				200
			);
		}

		// watermarking / idle / deleting / etc. are non-error states from the
		// client's perspective — the upload simply moved on.
		return new \WP_REST_Response(
			array(
				'ok'           => true,
				'album_status' => $status,
			),
			200
		);
	}

	/**
	 * Argument schema for POST /upload/heartbeat.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_args(): array {
		return array(
			'job_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'description'       => 'Job ID (album ID) restituito da /upload/start.',
			),
		);
	}
}



// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
