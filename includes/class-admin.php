<?php
/**
 * Admin page registration and asset enqueuing.
 *
 * Registers the Photolab menu page, enqueues assets, passes config to JS via
 * wp_localize_script, and verifies Pretty Permalinks are active.
 *
 * @package Photolab
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared


namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI manager.
 */
class Admin {

	/**
	 * Registers all WordPress hooks for the admin layer.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'notice_pretty_permalinks' ) );
		$this->detect_image_engine();
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	/**
	 * Register the Photolab top-level admin menu page.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function register_menu(): void {
		$icon = 'data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjIiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgdmlld0JveD0iMCAwIDQwIDQwIiB3aWR0aD0iNDAiIGhlaWdodD0iNDAiPgoJPHN0eWxlPgoJCS5zMCB7IGZpbGw6ICNmZWZlZmUgfSAKCTwvc3R5bGU+Cgk8ZyBpZD0iJmx0O0dyb3VwJmd0OyI+CgkJPHBhdGggaWQ9IiZsdDtQYXRoJmd0OyIgY2xhc3M9InMwIiBkPSJtMjQuOSAxMi45MnEwLjA2IDAuMDYgMC4xNCAwLjEyIDAuNTEgMC4zOCAxLjAxIDAuODcgMC40OSAwLjQ5IDAuODUgMC45OSAwLjA2IDAuMDggMC4xMiAwLjE0YzAuNTQgMC41NCAxLjQyIDAuNTQgMS45NiAwbDUuNzUtNS43NmMwLjMtMC4zIDAuNy0wLjQyIDEuMDktMC4zOS0wLjc4LTAuNTYtMS43My0wLjg5LTIuNzYtMC44OWgtMTMuMDh6Ii8+CgkJPHBhdGggaWQ9IiZsdDtQYXRoJmd0OyIgY2xhc3M9InMwIiBkPSJtMi41IDI4LjAybDIuMTItMi4xMyAwLjAyIDAuMDIgNC45My00Ljk0LTAuMDEtMC4wMSA0Ljk4LTQuOThxMS41OC0xLjU5IDIuNjItMi40OGMwLjYxLTAuNTMgMC42Ni0xLjQ2IDAuMDktMi4wM2wtMy40Ny0zLjQ3aC03LjAyYy0yLjYzIDAtNC43NiAyLjEzLTQuNzYgNC43NnYxNC4xNnEwIDAuNzggMC4yMyAxLjQ4IDAuMS0wLjIxIDAuMjctMC4zOHoiLz4KCQk8cGF0aCBpZD0iJmx0O1BhdGgmZ3Q7IiBjbGFzcz0iczAiIGQ9Im0yMy43NCAxOC43cS0wLjE2LTAuODctMC44NC0xLjU1LTAuODQtMC44NC0xLjg4LTAuODktMS4wMy0wLjA1LTEuOTIgMC41Ny0wLjY3IDAuNDUtMi4zNCAyLjEybC0wLjUyIDAuNTNjLTAuNTQgMC41NC0wLjU0IDEuNDEgMCAxLjk1bDIuNCAyLjQxYzAuNTQgMC41NCAxLjQyIDAuNTQgMS45NiAwbDAuNzItMC43M3ExLjgzLTEuODMgMi4yMS0yLjY5IDAuMzgtMC44NiAwLjIxLTEuNzJ6Ii8+CgkJPHBhdGggaWQ9IiZsdDtQYXRoJmd0OyIgY2xhc3M9InMwIiBkPSJtMTMuMTQgMjQuNTRjLTAuNTQtMC41NC0xLjQxLTAuNTQtMS45NSAwbC02LjA5IDYuMDhxLTAuMzQgMC4zMy0wLjc4IDAuMzljMC43MiAwLjQzIDEuNTUgMC42OCAyLjQ0IDAuNjhoMTMuNTN6Ii8+CgkJPHBhdGggaWQ9IiZsdDtQYXRoJmd0OyIgY2xhc3M9InMwIiBkPSJtMzcuNjIgMTEuNDJxLTAuMSAwLjI1LTAuMyAwLjQ1bC05LjgzIDkuODQtMC4wNi0wLjA2cS0wLjA5IDAuMTYtMC4xOCAwLjMxLTAuODkgMS4zMy0zIDMuNDRsLTEuMDQgMS4wNWMtMC41NCAwLjUzLTAuNTQgMS40MSAwIDEuOTVsMy4yOCAzLjI5aDYuNTdjMi42MyAwIDQuNzYtMi4xNCA0Ljc2LTQuNzd2LTE0LjE2cTAtMC43LTAuMi0xLjM0eiIvPgoJPC9nPgo8L3N2Zz4=';

		add_menu_page(
			__( 'Photolab', 'photolab' ),
			__( 'Photolab', 'photolab' ),
			'manage_options',
			'photolab',
			array( $this, 'render_page' ),
			$icon,
			40
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue scripts and styles only on the Photolab admin page.
	 *
	 * Uses vanilla JS (admin.js) with config passed via wp_localize_script.
	 * Avoids wp_enqueue_script_module / ES module import map entirely.
	 * Tailwind loaded from local build (assets/css/admin.css) — no CDN.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @since 2.0.0
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_photolab' !== $hook_suffix ) {
			return;
		}

		Logger::info( 'Admin::enqueue_assets() — caricamento asset pagina admin.', array( 'source' => 'photolab-admin' ) );

		// Tailwind CSS — local build (WP.org Guideline #8: no external CDN assets).
		wp_enqueue_style(
			'photolab-admin',
			PHOTOLAB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			PHOTOLAB_VERSION
		);

		// Vanilla JS admin controller — no ES module dependencies.
		wp_enqueue_script(
			'photolab-admin',
			PHOTOLAB_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			PHOTOLAB_VERSION,
			true
		);

		/**
		 * Filters the number of files processed per upload chunk.
		 *
		 * Reasonable range: 1–20. Default: 5.
		 *
		 * @param int $chunk_size Files per chunk.
		 */
		$chunk_size = max( 1, min( 20, (int) apply_filters( 'photolab_chunk_size', 5 ) ) );

		// Pass config to JS via global variable.
		wp_localize_script(
			'photolab-admin',
			'photolabConfig',
			array(
				'restUrl'              => rest_url( 'photolab/v1' ),
				'nonce'                => wp_create_nonce( 'wp_rest' ),
				'maxUploadSize'        => wp_max_upload_size(),
				'chunkSize'            => $chunk_size,
				'watermarkActive'      => (bool) get_option( 'photolab_watermark_active', false ),
				'watermarkUrl'         => esc_url( (string) get_option( 'photolab_watermark_url', '' ) ),
				'maxConcurrentUploads' => 3,
				'userCanUpload'        => $this->user_can_upload(),
			)
		);

		// Trigger AS watermark batch processing — not blocking, no race.
		// Mirrors WooCommerce admin behavior since WC 3.9+.
		if ( class_exists( '\ActionScheduler_QueueRunner' ) ) {
			\ActionScheduler_QueueRunner::instance()->maybe_dispatch_async_request();
		}

		Logger::info( 'Admin::enqueue_assets() — asset caricati.', array( 'source' => 'photolab-admin' ) );
	}

	/**
	 * Compute whether the current user is below the concurrent-upload limit.
	 *
	 * Mirrors the rate limit enforced server-side in `Upload_Controller::start()`
	 * so the UI can disable the form pre-emptively. Maximum is 3 active jobs
	 * (status `uploading` or `watermarking`) per user.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True when the user can start a new upload.
	 */
	private function user_can_upload(): bool {
		global $wpdb;

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$albums_table = $wpdb->prefix . 'Photolab_albums';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$albums_table}`
				 WHERE user_id = %d
				   AND status IN ('uploading','watermarking')",
				$user_id
			)
		);

		return $active_count < 3;
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	/**
	 * Render the Photolab admin page.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'photolab' ) );
		}

		Logger::info( 'Admin::render_page() — rendering pagina admin.', array( 'source' => 'photolab-admin' ) );

		include PHOTOLAB_PLUGIN_DIR . 'templates/admin-page.php';
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Show a notice when Pretty Permalinks are not enabled.
	 *
	 * @return void
	 */
	public function notice_pretty_permalinks(): void {
		global $pagenow;

		if ( 'admin.php' !== $pagenow && 'plugins.php' !== $pagenow ) {
			return;
		}

		if ( '' !== get_option( 'permalink_structure' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' .
			esc_html__(
				'Photolab richiede i Pretty Permalinks attivi. Abilita una struttura permalink diversa da "Normale" in Impostazioni → Permalink.',
				'photolab'
			) .
			'</p></div>';
	}

	/**
	 * Detect the active image engine and store it for Settings API.
	 *
	 * The GD-only warning is surfaced once via Admin_Notices (dismissible)
	 * in photolab.php after activation — not repeated on every page load.
	 *
	 * @return void
	 */
	public function detect_image_engine(): void {
		$imagick_available = wp_image_editor_supports(
			array(
				'methods'   => array( 'resize' ),
				'mime_type' => 'image/png',
			)
		)
			&& class_exists( 'WP_Image_Editor_Imagick' )
			&& extension_loaded( 'imagick' );

		$engine = $imagick_available ? 'imagick' : 'gd';
		$stored = (string) get_option( 'photolab_image_engine', '' );

		if ( $stored !== $engine ) {
			update_option( 'photolab_image_engine', $engine, false );
		}

		Logger::info( "Admin::detect_image_engine() — image engine: $engine.", array( 'source' => 'photolab-admin' ) );
	}
}



// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared