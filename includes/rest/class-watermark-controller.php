<?php
/**
 * REST controller for watermark endpoints.
 *
 * Handles POST /watermark (upload/replace) and DELETE /watermark (remove).
 *
 * @package Photolab
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Watermark REST controller.
 *
 * @extends \WP_REST_Controller
 */
class Watermark_Controller extends \WP_REST_Controller {

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
	protected $rest_base = 'watermark';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'position' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => 'bottom_right',
							'enum'              => array( 'fullwidth', 'bottom_right' ),
							'validate_callback' => 'rest_validate_request_arg',
							'description'       => 'Watermark position: fullwidth or bottom_right.',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(),
				),
			)
		);

		// Dedicated route to update position without re-uploading the file.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/position',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_position' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'position' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => array( 'fullwidth', 'bottom_right' ),
							'validate_callback' => 'rest_validate_request_arg',
							'description'       => 'Watermark position: fullwidth or bottom_right.',
						),
					),
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
	 * POST /watermark
	 *
	 * Uploads or replaces assets/watermark.png.
	 * Expects multipart/form-data with a PNG file in $_FILES['watermark'].
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->upload_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of POST /watermark. Wrapped so the request-scoped logger context is
	 * always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function upload_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$context = array( 'source' => 'photolab-watermark' );

		Logger::set_context( 'user_id', (int) get_current_user_id() );

		Logger::info( 'Watermark_Controller::upload() — richiesta upload watermark.', $context );

		// Validate file presence.
		if ( empty( $_FILES['watermark'] ) || UPLOAD_ERR_OK !== $_FILES['watermark']['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			$error_code = isset( $_FILES['watermark']['error'] ) ? (int) $_FILES['watermark']['error'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			Logger::error( "Watermark_Controller::upload() — file mancante o errore upload (code $error_code).", $context );

			return new \WP_Error(
				'photolab_missing_file',
				__( 'File watermark mancante o corrotto.', 'photolab' ),
				array( 'status' => 400 )
			);
		}

		// Validate MIME type — must be image/png.
		$file_info = wp_check_filetype_and_ext(
			$_FILES['watermark']['tmp_name'], // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
			$_FILES['watermark']['name']      // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
		);

		if ( 'image/png' !== $file_info['type'] || 'png' !== $file_info['ext'] ) {
			Logger::error( 'Watermark_Controller::upload() — tipo file non valido: ' . ( $file_info['type'] ?? 'unknown' ), $context );

			return new \WP_Error(
				'photolab_invalid_type',
				__( 'Il watermark deve essere un file PNG.', 'photolab' ),
				array( 'status' => 415 )
			);
		}

		// Resolve destination path: wp-content/uploads/Photolab/assets/watermark.png.
		$upload_basedir = wp_upload_dir()['basedir'];
		$assets_dir     = trailingslashit( $upload_basedir ) . 'Photolab/assets';
		$dest_path      = $assets_dir . '/watermark.png';

		// Create assets/ directory if absent — no .htaccess block here (public for preview).
		if ( ! is_dir( $assets_dir ) ) {
			if ( ! wp_mkdir_p( $assets_dir ) ) {
				Logger::error( "Watermark_Controller::upload() — impossibile creare directory assets: $assets_dir.", $context );

				return new \WP_Error(
					'photolab_dir_error',
					__( 'Unable to create assets directory. Check filesystem permissions.', 'photolab' ),
					array( 'status' => 500 )
				);
			}

			Logger::info( "Watermark_Controller::upload() — creata directory assets: $assets_dir.", $context );
		}

		// Move uploaded file — overwrite existing watermark.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput,Generic.PHP.ForbiddenFunctions.Found
		if ( ! move_uploaded_file( $_FILES['watermark']['tmp_name'], $dest_path ) ) {
			Logger::error( "Watermark_Controller::upload() — impossibile spostare il file in $dest_path.", $context );

			return new \WP_Error(
				'photolab_move_failed',
				__( 'Impossibile salvare il file watermark.', 'photolab' ),
				array( 'status' => 500 )
			);
		}

		// Persist options — not needed on every admin page, so autoload=false.
		$watermark_url = trailingslashit( wp_upload_dir()['baseurl'] ) . 'Photolab/assets/watermark.png';
		update_option( 'photolab_watermark_path', $dest_path, false );
		update_option( 'photolab_watermark_url', $watermark_url, false );
		update_option( 'photolab_watermark_active', 1, false );

		// Save watermark position (fullwidth | bottom_right).
		$allowed_positions = array( 'fullwidth', 'bottom_right' );
		$position          = sanitize_key( (string) $request->get_param( 'position' ) );
		if ( ! in_array( $position, $allowed_positions, true ) ) {
			$position = 'bottom_right';
		}
		update_option( 'photolab_watermark_position', $position, false );

		Logger::info( "Watermark_Controller::upload() — watermark salvato in $dest_path.", $context );

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'watermark_url' => esc_url_raw( $watermark_url ),
			),
			200
		);
	}

	/**
	 * POST /watermark/position
	 *
	 * Updates the watermark position option without requiring a file re-upload.
	 * Used when a watermark already exists and the admin only changes its position.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_position( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$context  = array( 'source' => 'photolab-watermark' );
			$position = sanitize_key( (string) $request->get_param( 'position' ) );

			Logger::set_context( 'user_id', (int) get_current_user_id() );

			update_option( 'photolab_watermark_position', $position, false );

			Logger::info(
				sprintf( 'Watermark_Controller::update_position() — posizione aggiornata: %s.', $position ),
				$context
			);

			return new \WP_REST_Response(
				array(
					'success'  => true,
					'position' => $position,
				),
				200
			);
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * DELETE /watermark
	 *
	 * Removes assets/watermark.png and resets plugin options.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->delete_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of DELETE /watermark. Wrapped so the request-scoped logger context
	 * is always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function delete_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$context = array( 'source' => 'photolab-watermark' );

		Logger::set_context( 'user_id', (int) get_current_user_id() );

		Logger::info( 'Watermark_Controller::delete() — richiesta eliminazione watermark.', $context );

		$watermark_path = (string) get_option( 'photolab_watermark_path', '' );

		if ( '' !== $watermark_path && file_exists( $watermark_path ) ) {
			if ( ! wp_delete_file( $watermark_path ) && file_exists( $watermark_path ) ) {
				// wp_delete_file() silently swallows errors; fall back to unlink.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $watermark_path );
			}
			Logger::info( "Watermark_Controller::delete() — file eliminato: $watermark_path.", $context );
		} else {
			Logger::info( 'Watermark_Controller::delete() — nessun file fisico da eliminare.', $context );
		}

		delete_option( 'photolab_watermark_path' );
		delete_option( 'photolab_watermark_url' );
		update_option( 'photolab_watermark_active', 0, false );

		Logger::info( 'Watermark_Controller::delete() — opzioni ripristinate.', $context );

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}
}
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
