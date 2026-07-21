<?php
/**
 * REST controller for settings endpoint.
 *
 * Handles GET /settings — returns global plugin options.
 *
 * @package Photolab
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings REST controller.
 *
 * @extends \WP_REST_Controller
 */
class Settings_Controller extends \WP_REST_Controller {

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
	protected $rest_base = 'settings';

	/**
	 * Register routes.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(),
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
	 * GET /settings
	 *
	 * Returns live plugin options including watermark state and image engine.
	 *
	 * @since 2.0.0
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return $this->get_settings_inner( $request );
		} finally {
			Logger::clear_context();
		}
	}

	/**
	 * Body of GET /settings. Wrapped so the request-scoped logger context is
	 * always cleared on return (FASE 9).
	 *
	 * @since 2.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function get_settings_inner( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$context = array( 'source' => 'photolab-settings' );

		Logger::set_context( 'user_id', (int) get_current_user_id() );

		Logger::info( 'Settings_Controller::get_settings() — richiesta impostazioni.', $context );

		// Derive watermark_url from the stored path (URL stored separately for speed).
		$watermark_url = (string) get_option( 'photolab_watermark_url', '' );

		$response = array(
			'watermark_active'   => (bool) get_option( 'photolab_watermark_active', false ),
			'watermark_url'      => esc_url_raw( $watermark_url ),
			'watermark_position' => (string) get_option( 'photolab_watermark_position', 'bottom_right' ),
			'image_engine'       => (string) get_option( 'photolab_image_engine', 'gd' ),
		);

		Logger::info(
			'Settings_Controller::get_settings() — completato. watermark_active=' .
			( $response['watermark_active'] ? '1' : '0' ),
			$context
		);

		return new \WP_REST_Response( $response, 200 );
	}
}
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
